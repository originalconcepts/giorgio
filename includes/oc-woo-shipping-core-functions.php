<?php

use Carbon\Carbon;

defined('ABSPATH') || exit;

/**
 * Return the html selected attribute if stringified $value is found in array of stringified $options
 * or if stringified $value is the same as scalar stringified $options.
 *
 * @param string|int $value Value to find within options.
 * @param string|int|array $options Options to go through when looking for value.
 * @return string
 */

add_action('woocommerce_checkout_update_order_review', 'ocws_checkout_update_refresh_shipping_methods', 10, 1);
function ocws_checkout_update_refresh_shipping_methods($post_data=null)
{
    $packages = WC()->cart->get_shipping_packages();
    foreach ($packages as $package_key => $package) {
        WC()->session->set('shipping_for_package_' . $package_key, false); // Or true
    }
}

function ocws_is_admin_order_screen()
{

    global $pagenow;
    if (($pagenow == 'post.php') && (get_post_type() == 'shop_order')) {

        return true;

    }

    return false;
}

function ocws_selected($value, $options)
{
    if (is_array($options)) {
        $options = array_map('strval', $options);
        return selected(in_array((string)$value, $options, true), true, false);
    }

    return selected($value, $options, false);
}


function ocws_disabled($value, $options)
{
    if (is_array($options)) {
        $options = array_map('strval', $options);
        return disabled(in_array((string)$value, $options, true), true, false);
    }

    return disabled($value, $options, false);
}

/**
 * ערכי כתובת לפופאב בחירת משלוח: קודם WC checkout (כולל checkout_data מהסשן), אחר כך מפתחות chosen_* של OCWS.
 *
 * @param string $field_name מפתח שדה billing_*.
 * @return string
 */
function ocws_popup_get_billing_field_value( $field_name ) {
    if ( ! function_exists( 'WC' ) || ! WC()->checkout() ) {
        return '';
    }
    $v = WC()->checkout()->get_value( $field_name );
    if ( $v !== '' && $v !== null ) {
        return is_string( $v ) ? $v : (string) $v;
    }
    if ( ! isset( WC()->session ) ) {
        return '';
    }
    $session_map = array(
        'billing_address_coords' => 'chosen_address_coords',
        'billing_city_code'      => 'chosen_city_code',
        'billing_city_name'      => 'chosen_city_name',
        'billing_street'         => 'chosen_street',
        'billing_house_num'      => 'chosen_house_num',
        'billing_city'           => 'chosen_shipping_city',
    );
    if ( isset( $session_map[ $field_name ] ) ) {
        $s = WC()->session->get( $session_map[ $field_name ], '' );
        return ( $s !== null && $s !== '' ) ? (string) $s : '';
    }
    return '';
}

/**
 * Clean variables using sanitize_text_field. Arrays are cleaned recursively.
 * Non-scalar values are ignored.
 *
 * @param string|array $var Data to sanitize.
 * @return string|array
 */
function ocws_clean($var)
{
    if (is_array($var)) {
        return array_map('ocws_clean', $var);
    } else {
        return is_scalar($var) ? sanitize_text_field($var) : $var;
    }
}

function ocws_add_shipping_method($methods)
{
    $methods['oc_woo_advanced_shipping_method'] = 'OC_Woo_Advanced_Shipping_Method';
    $methods['oc_woo_local_pickup_method'] = 'OC_Woo_Local_Pickup_Method';
    return $methods;
}

function ocws_shipping_method_init()
{
    require_once plugin_dir_path(dirname(__FILE__)) . 'includes/class-oc-woo-advanced-shipping-method.php';
    require_once plugin_dir_path(dirname(__FILE__)) . 'includes/local-pickup/class-oc-woo-local-pickup-method.php';
}

function ocws_dates_array_to_string($dates)
{
    return implode(',', $dates);
}

function ocws_dates_list_to_array($list, $remove_past_dates=false, $date_format = 'd/m/Y') {
    $datesArr = explode(',', trim($list));

    $trimmed = array_filter(array_map('trim', $datesArr), function ($item) use ($remove_past_dates, $date_format) {

        if (empty($item)) return false;
        try {
            $d = Carbon::createFromFormat($date_format, $item, ocws_get_timezone());
        }
        catch (InvalidArgumentException $e) {
            // not valid date
            return false;
        }
        if ($remove_past_dates) {
            return !(Carbon::now()->startOfDay()->gte($d));
        }
        return true;
    });
    $ret = array();
    foreach ($trimmed as $v) {
        $ret[] = $v;
    }
    return $ret;
}

function ocws_numbers_list_to_array($list)
{
    $datesArr = explode(',', trim($list));
    $trimmed = array_filter(array_map('trim', $datesArr), function ($item) {
        return !empty($item) || $item == 0;
    });
    return $trimmed;
}

function ocws_kses_notice($message)
{
    $allowed_tags = array_replace_recursive(
        wp_kses_allowed_html('post'),
        array(
            'a' => array(
                'tabindex' => true,
            ),
        )
    );

    return wp_kses($message, $allowed_tags);
}

/**
 * Render all registered `ocws` checkout fields (slot hiddens + merged floor / apartment / entry code).
 * Shared by the full slots block and early `no-location` exits so address rows still appear in the popup.
 *
 * @param array $post_data Parsed checkout POST (or `post_data` from AJAX `update_order_review`).
 */
function ocws_render_ocws_checkout_fields_inner( $post_data ) {
    if ( ! function_exists( 'WC' ) || ! WC()->checkout() ) {
        return;
    }
    $checkout = WC()->checkout();
    $fields   = $checkout->get_checkout_fields( 'ocws' );
    if ( empty( $fields ) || ! is_array( $fields ) ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            error_log( '[OCWS] ocws_render_ocws_checkout_fields_inner: no ocws checkout fields.' );
        }
        return;
    }
    $skip_address_extras = ! empty( $GLOBALS['ocws_ocws_inner_skip_address_extras'] );
    $skip_keys           = (array) apply_filters(
        'ocws_checkout_inner_skip_address_fields',
        array( 'billing_floor', 'billing_apartment', 'billing_enter_code' )
    );
    foreach ( $fields as $key => $field ) {
        if ( ! is_array( $field ) ) {
            continue;
        }
        if ( $skip_address_extras && in_array( $key, $skip_keys, true ) ) {
            continue;
        }
        $value = ocws_get_value( $key, $post_data );
        if ( '' === $value ) {
            $value = $checkout->get_value( $key );
        }
        if ( '' === $value && isset( $field['default'] ) ) {
            $value = $field['default'];
        }
        woocommerce_form_field( $key, $field, $value );
    }
}

/**
 * Floor / apartment / entry code — rendered after address inputs in #choose-shipping (not inside AJAX slot fragment).
 *
 * @param array $post_data Parsed POST or empty on first paint.
 */
function ocws_render_address_extra_fields_for_popup( $post_data = array() ) {
    if ( ! function_exists( 'WC' ) || ! WC()->checkout() ) {
        return;
    }
    $checkout       = WC()->checkout();
    $keys           = apply_filters(
        'ocws_address_extra_popup_fields',
        array( 'billing_floor', 'billing_apartment', 'billing_enter_code' )
    );
    $billing_fields = $checkout->get_checkout_fields( 'billing' );
    ob_start();
    foreach ( (array) $keys as $key ) {
        if ( empty( $billing_fields[ $key ] ) || ! is_array( $billing_fields[ $key ] ) ) {
            continue;
        }
        $field = $billing_fields[ $key ];
        if ( isset( $field['class'] ) && is_array( $field['class'] ) ) {
            $field['class'] = array_values( array_diff( $field['class'], array( 'ocws-hidden-form-field' ) ) );
        }
        if ( isset( $field['input_class'] ) && is_array( $field['input_class'] ) ) {
            $field['input_class'] = array_values( array_diff( $field['input_class'], array( 'ocws-hidden-form-field-input' ) ) );
        }
        if ( isset( $field['type'] ) && 'hidden' === $field['type'] ) {
            $field['type'] = 'text';
        }
        if ( isset( $field['custom_attributes']['readonly'] ) ) {
            unset( $field['custom_attributes']['readonly'] );
        }
        $value = ocws_get_value( $key, $post_data );
        if ( '' === $value ) {
            $value = $checkout->get_value( $key );
        }
        woocommerce_form_field( $key, $field, $value );
    }
    $inner = trim( (string) ob_get_clean() );
    if ( '' === $inner ) {
        return;
    }
	echo '<div class="ocws-checkout-address-extras-pp">' . $inner . '</div>';
}

/**
 * לוג ממוקד ל- WooCommerce: Status → Logs → קבצי ocws-render-shipping-*
 * הפעלה: define( 'OCWS_LOG_RENDER_SHIPPING', true ); ב-wp-config.php
 * או: WP_DEBUG + WP_DEBUG_LOG, או: add_filter( 'ocws_enabled_log_render_shipping', '__return_true' );
 *
 * @param string               $message הודעה.
 * @param array<string, mixed> $context הקשר (יוצב ל-JSON בגוף הלוג).
 */
function ocws_log_render_shipping( $message, $context = array() ) {
	$on = ( defined( 'OCWS_LOG_RENDER_SHIPPING' ) && OCWS_LOG_RENDER_SHIPPING );
	$on = $on || (bool) apply_filters( 'ocws_enabled_log_render_shipping', false );
	$on = $on || ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG );
	if ( ! $on || ! function_exists( 'wc_get_logger' ) ) {
		return;
	}
	$line = (string) $message;
	if ( ! empty( $context ) && is_array( $context ) ) {
		$line .= ' | ' . wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}
	wc_get_logger()->info( $line, array( 'source' => 'ocws-render-shipping' ) );
}

function ocws_render_shipping_additional_fields()
{
    $chosen_methods = WC()->session->get('chosen_shipping_methods', array());
    $checkout = WC()->checkout();

    if (isset($_POST['post_data'])) {

        parse_str($_POST['post_data'], $post_data);

    } else {

        $post_data = $_POST; // fallback for final checkout (non-ajax)

    }

    if (empty($chosen_methods)) {
        if (isset($post_data['shipping_method']) && is_array($post_data['shipping_method'])) {
            $chosen_methods = $post_data['shipping_method'];
        }
    }

    if (!isset($post_data['billing_city']) || empty($post_data['billing_city'])) {
        $post_data['billing_city'] = WC()->checkout->get_value('billing_city');
    }

    ocws_log_render_shipping(
        'ocws_render_shipping: start',
        array(
			'ajax'              => ( defined( 'DOING_AJAX' ) && DOING_AJAX ),
			'wc_ajax'           => isset( $_REQUEST['wc-ajax'] ) ? (string) $_REQUEST['wc-ajax'] : '',
			'action'            => isset( $_REQUEST['action'] ) ? (string) $_REQUEST['action'] : '',
			'chosen_methods'     => $chosen_methods,
			'google_polygons'  => (bool) ocws_use_google_cities_and_polygons(),
			'has_post_data_key' => isset( $_POST['post_data'] ),
			'billing_city'      => isset( $post_data['billing_city'] ) ? (string) $post_data['billing_city'] : '',
			'billing_city_code' => isset( $post_data['billing_city_code'] ) ? (string) $post_data['billing_city_code'] : '',
			'billing_coords'    => isset( $post_data['billing_address_coords'] ) ? (string) $post_data['billing_address_coords'] : '',
        )
    );

    $location_code = 0;
    if (ocws_use_google_cities_and_polygons()) {

        if (!isset($post_data['billing_city_code']) || empty($post_data['billing_city_code'])) {
            $post_data['billing_city_code'] = WC()->session->get('chosen_city_code', '' );
        }
        if (!isset($post_data['billing_address_coords']) || empty($post_data['billing_address_coords'])) {
            $post_data['billing_address_coords'] = WC()->session->get('chosen_address_coords', '' );
        }
        $location_code = OC_Woo_Shipping_Polygon::get_location_code_by_post_data_network($post_data);
        ocws_log_render_shipping(
			'after get_location_code_by_post_data_network (session may have filled code/coords)',
			array(
				'location_code'     => (string) ( $location_code ? $location_code : '' ),
				'billing_city_code' => (string) ( $post_data['billing_city_code'] ?? '' ),
				'billing_coords'    => (string) ( $post_data['billing_address_coords'] ?? '' ),
			)
		);
    }
    else {
        $location_code = $post_data['billing_city'];
		ocws_log_render_shipping(
			'non-Google mode: location from billing_city',
			array( 'location_code' => (string) ( $location_code ? $location_code : '' ) )
		);
    }
    // show( $location_code, 'location_code BEGINNIG' );

    if (empty($chosen_methods)) {
		ocws_log_render_shipping( 'exit: empty chosen_shipping_methods', array( 'location_code' => (string) ( $location_code ? $location_code : '' ) ) );
        ?>
        <div id="oc-woo-shipping-additional" class="no-methods"></div>
        <?php
        return;
    }

    $is_ocws = false;

    foreach ($chosen_methods as $shippingMethod) {
        if (substr($shippingMethod, 0, strlen('oc_woo_advanced_shipping_method')) == 'oc_woo_advanced_shipping_method') {
            $is_ocws = true;
            break;
        }
    }
    if (!$is_ocws) {
		ocws_log_render_shipping( 'exit: not oc_woo_advanced_shipping_method', array( 'chosen_methods' => $chosen_methods, 'location_code' => (string) ( $location_code ? $location_code : '' ) ) );
        ?>
        <div id="oc-woo-shipping-additional" class="no-ocws"></div>
        <?php
        return;
    }

    global $wpdb;
    if (is_multisite()) {
		ocws_log_render_shipping( 'multisite branch: location before network logic', array( 'location_code' => (string) ( $location_code ? $location_code : '' ) ) );
        $enabled_blog_locations_count = ocws_enabled_shipping_locations_count_blog();
        $enabled_network_locations_count = ocws_enabled_shipping_locations_count_networkwide();
        if ($enabled_blog_locations_count == 0 && $enabled_network_locations_count == 1) {
			ocws_log_render_shipping( 'multisite: single network site with locations', array( 'location_code' => (string) ( $location_code ? $location_code : '' ) ) );
            $go_to_blog_id = 0;
            $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );
            foreach ( $blog_ids as $blog_id ) {
                if (ocws_enabled_shipping_locations_count_blog($blog_id) > 0) {
                    $go_to_blog_id = $blog_id;
                    break;
                }
            }
            if ($go_to_blog_id && ocws_blog_exists($go_to_blog_id)) {
                switch_to_blog($go_to_blog_id);
                $blogdata = get_blog_details();
                $blog_name = $blogdata->blogname;
                restore_current_blog();
                $redirect_url = esc_url(ocws_convert_current_page_url($go_to_blog_id, ['ocws_from_store' => get_current_blog_id()]));

                $oos_message = '<span class="important-notice">'.
                    esc_html(sprintf(__('Shipping is available from %s only.', 'ocws'), $blog_name)).
                    '</span><br><a class="ocws-site-link" href="'.esc_url($redirect_url).'">'.esc_html(__('Go to the site.', 'ocws')).'</a>';
                ?>
                <div id="oc-woo-shipping-additional" class="need-redirect">
                    <div class="slot-message" style="display: none;"><?php echo $oos_message; ?></div>
                </div>
                <?php

                if (is_checkout()) {
                    WC()->session->set('chosen_address_coords', null );
                    ocws_update_session_checkout_field('billing_address_coords', null);
                    WC()->session->set('chosen_street', null );
                    ocws_update_session_checkout_field('billing_street', null);
                    WC()->session->set('chosen_house_num', null );
                    ocws_update_session_checkout_field('billing_house_num', null);
                    WC()->session->set('chosen_city_name', null );
                    ocws_update_session_checkout_field('billing_city_name', null);
                    WC()->session->set('chosen_city_code', null );
                    ocws_update_session_checkout_field('billing_city_code', null);
                    WC()->session->set('chosen_shipping_city', null );
                    ocws_update_session_checkout_field('billing_city', null);
                }

                return;
            }
        }
        else if ($enabled_blog_locations_count == 1 && $enabled_network_locations_count == 1 && is_checkout()) {
			ocws_log_render_shipping( 'multisite: forcing single blog location', array( 'location_code_before' => (string) ( $location_code ? $location_code : '' ) ) );
            $locs = OCWS_Advanced_Shipping::get_all_locations_blog(true);
            $location_name = reset($locs);
            $location_code = key($locs);
			ocws_log_render_shipping( 'multisite: location_code after key(locs)', array( 'location_code' => (string) $location_code, 'location_name' => (string) $location_name ) );
        }

        if ($location_code && str_contains($location_code.'', ':::')) {
			ocws_log_render_shipping( 'multisite: cross-blog location_code', array( 'location_code' => (string) $location_code ) );
            $bid = explode(':::', $location_code, 2);
            $go_to_blog_id = intval($bid[0]);
            if ($go_to_blog_id != get_current_blog_id() ) {

                if (ocws_blog_exists($go_to_blog_id)) {

                    switch_to_blog($go_to_blog_id);
                    $blogdata = get_blog_details();
                    $blog_name = $blogdata->blogname;
                    $blog_checkout_url = wc_get_checkout_url();
                    restore_current_blog();

                    $tmp = WC()->session->get('shipping_for_package_0', false);
                    WC()->session->set('shipping_for_package_0', null);
                    WC()->session->set('popup_location_code', $location_code);
                    if ($tmp) {
                        //error_log(print_r($tmp, 1));
                        //WC()->session->set('shipping_for_package_0', $tmp);
                    }
                    WC()->session->save_data();
                    //error_log(print_r(WC()->session->get_session_data(),1));
                    $redirect_url = esc_url(ocws_convert_current_page_url($go_to_blog_id, ['ocws_from_store' => get_current_blog_id()]));
                    $oos_message = '<span class="important-notice">'.
                        esc_html(sprintf(__('You have chosen the branch %s for shipping.', 'ocws'), $blog_name)).
                        '</span><br><a class="ocws-site-link" href="'.esc_url($redirect_url).'">'.esc_html(__('Go to the site.', 'ocws')).'</a>';
                    ?>
                    <div id="oc-woo-shipping-additional" class="need-redirect">
                        <div class="slot-message" style="display: none;"><?php echo $oos_message; ?></div>
                    </div>
                    <?php
					ocws_log_render_shipping( 'multisite: exit need-redirect other blog', array( 'location_code' => (string) $location_code ) );
                    return;

                }

            }
            else if (isset($bid[1])) {
                $location_code = $bid[1];
				ocws_log_render_shipping( 'multisite: stripped blog prefix from location_code', array( 'location_code' => (string) $location_code ) );
            }
        }
    }



    if (empty($location_code)) {
		ocws_log_render_shipping(
			'exit: empty location_code (OOS: no gm city match, no polygon, or no billing_city in simple mode)',
			array(
				'google_mode'   => (bool) ocws_use_google_cities_and_polygons(),
				'city_code'     => (string) ( $post_data['billing_city_code'] ?? '' ),
				'coords'        => (string) ( $post_data['billing_address_coords'] ?? '' ),
				'billing_city'  => (string) ( $post_data['billing_city'] ?? '' ),
			)
		);
        ?>
        <div id="oc-woo-shipping-additional" class="no-location">
            <?php ocws_render_ocws_checkout_fields_inner( $post_data ); ?>
            <?php
            if (ocws_use_google_cities_and_polygons() && isset($post_data['billing_address_coords']) && !empty($post_data['billing_address_coords'])) {
                $oos_message = ocws_get_multilingual_option('ocws_common_out_of_service_area_message');
                if (empty($oos_message)) {
                    $general_options_defaults = OC_Woo_Shipping_Group_Option::get_general_options_defaults();
                    if (isset($general_options_defaults['out_of_service_area_message'])) {
                        $oos_message = $general_options_defaults['out_of_service_area_message'];

                        ?> <div class="slot-message oos-message" style="display:none;"><?php echo esc_html($oos_message); ?></div> <?php
                    }
                }
            }
            ?>
        </div>
        <?php
        return;
    }

    if (!ocws_is_location_enabled($location_code)) {
		$ds_debug = new OC_Woo_Shipping_Group_Data_Store();
		$gid_debug = $ds_debug->get_group_by_location( $location_code );
		$loc_en    = $ds_debug->is_location_enabled( $location_code );
		$grp_en    = $gid_debug ? $ds_debug->is_group_enabled( (int) $gid_debug ) : null;
		ocws_log_render_shipping(
			'exit: location not enabled (OOS: disabled location or group)',
			array(
				'location_code'      => (string) $location_code,
				'group_id'          => (string) ( $gid_debug ? $gid_debug : '' ),
				'location_is_en'   => (string) ( null !== $loc_en ? (int) (bool) $loc_en : -1 ),
				'group_is_en'      => (string) ( null !== $grp_en ? (int) (bool) $grp_en : -1 ),
			)
		);

        $oos_message = ocws_get_multilingual_option('ocws_common_out_of_service_area_message');
        if (empty($oos_message)) {
            $general_options_defaults = OC_Woo_Shipping_Group_Option::get_general_options_defaults();
            if (isset($general_options_defaults['out_of_service_area_message'])) {
                $oos_message = $general_options_defaults['out_of_service_area_message'];
            }
        }
        ?>
        <div id="oc-woo-shipping-additional" class="no-location">
            <?php ocws_render_ocws_checkout_fields_inner( $post_data ); ?>
            <div class="slot-message oos-message" style="display:none;"><?php echo esc_html($oos_message); ?></div>
        </div>
        <?php
        return;
    }

    // Additional if sum cart content total less than $min_total for enabling shipping ||| DO I NEED THIS ???
    $cart_total             = WC()->cart->cart_contents_total;
    $data_store             = new OC_Woo_Shipping_Group_Data_Store();
    $group_id               = $data_store->get_group_by_location( $location_code );

    $data_min_total         = OC_Woo_Shipping_Group_Option::get_location_option($location_code, $group_id, 'min_total', false);
    $min_total_to_enable    = floatval( $data_min_total['option_value']) ;
    $ar_message             = OC_Woo_Shipping_Group_Option::get_location_option( $location_code, $group_id, 'min_total_message_no', true );
    $message                = $ar_message['option_value'];
    if ( !empty( $message ) ) {
        if (strstr( $message, '[X]')) {
            $message = str_replace('[X]', $min_total_to_enable, $message);
        }
    }
    // $loc_title = ocws_use_google_cities_and_polygons()? $this->get_shipping_package_address($package) : ocws_get_city_title($location_code);
    $loc_title = ocws_get_city_title($location_code);
    if ( strstr( $message, '[Y]') ) {
        $message = str_replace('[Y]', $loc_title, $message);
    }

    // You need [x] more title
    $hebrew_title           = 'חסר לך [X] לביצוע משלוח';
    $price_for_shipping     = wc_price( $min_total_to_enable - $cart_total );
    if ( strstr( $hebrew_title, '[X]') ) {
        $hebrew_title = str_replace('[X]', $price_for_shipping, $hebrew_title);
    }

    if ( $location_code && $cart_total < $min_total_to_enable ){
        ?>
        <div id="oc-woo-shipping-additional--message" style="display:none;">
            <div class="first">*<?php echo $message ?></div><div class="second"><?php echo $hebrew_title; ?></div>
        </div>
        <?php
        // return;
    }

    $show_as_slider = true; //isset($post_data['show_as_slider']);

    $show_dates_only = get_option('ocws_common_show_dates_only', '') != 1 ? false : true;

    $oc_slots = new OC_Woo_Shipping_Slots($location_code);
    $days = $oc_slots->calculate_slots_for_checkout();
	ocws_log_render_shipping(
		'slots: calculate_slots_for_checkout',
		array(
			'location_code'  => (string) $location_code,
			'group_id'      => (string) ( $group_id ? $group_id : '' ),
			'cart_total'    => (string) $cart_total,
			'min_total'     => (string) $min_total_to_enable,
			'days_count'   => is_array( $days ) ? count( $days ) : 0,
		)
	);
    //print_r($days);
    $weekdays = array(
        __('Sunday', 'ocws'),
        __('Monday', 'ocws'),
        __('Tuesday', 'ocws'),
        __('Wednesday', 'ocws'),
        __('Thursday', 'ocws'),
        __('Friday', 'ocws'),
        __('Saturday', 'ocws')
    );

    // $state = (isset($_POST['ocws_shipping_info']['state']) && in_array($_POST['ocws_shipping_info']['state'], array('less', 'more')))? $_POST['ocws_shipping_info']['state'] : 'less';
    $state = 'more';

    $selected_slot = OC_Woo_Shipping_Info::get_shipping_info();
    $popup_shipping_info = OC_Woo_Shipping_Info::get_shipping_info_from_session();

    $selected_slot_arr = array(
        'date' => '',
        'slot_start' => '',
        'slot_end' => ''
    );
    if (null !== $selected_slot) {
        if (isset($selected_slot['date']) && $selected_slot['date']) {
            $selected_slot_arr['date'] = $selected_slot['date'];
        } else if ($popup_shipping_info['date']) {
            $selected_slot_arr['date'] = $popup_shipping_info['date'];
        }
        if (isset($selected_slot['slot_start']) && $selected_slot['slot_start']) {
            $selected_slot_arr['slot_start'] = $selected_slot['slot_start'];
        } else if ($popup_shipping_info['slot_start']) {
            $selected_slot_arr['slot_start'] = $popup_shipping_info['slot_start'];
        }
        if (isset($selected_slot['slot_end']) && $selected_slot['slot_end']) {
            $selected_slot_arr['slot_end'] = $selected_slot['slot_end'];
        } else if ($popup_shipping_info['slot_end']) {
            $selected_slot_arr['slot_end'] = $popup_shipping_info['slot_end'];
        }
    }

    $output = array();
    foreach ($days as $index => $day) {

        if (count($day['slots']) == 0) {
            continue;
        }
        $item = array();
        $item['formatted_date'] = $day['formatted_date'];
        $item['weekday'] = $weekdays[$day['day_of_week']];
        $item['day_of_week'] = $day['day_of_week'];
        $item['slots'] = array();
        foreach ($day['slots'] as $slot) {
            $slot['class'] = '';
            if ($selected_slot_arr['date'] == $day['formatted_date'] && $selected_slot_arr['slot_start'] == $slot['start'] && $selected_slot_arr['slot_end'] == $slot['end']) {
                $slot['class'] = 'selected';
            }
            $item['slots'][] = $slot;
        }
        $output[] = $item;
    }

	ocws_log_render_shipping(
		'output: days with at least one slot',
		array(
			'location_code'    => (string) $location_code,
			'output_days'   => count( $output ),
			'has_slot_blocks' => count( $output ) > 0,
			'loc_title'     => (string) ocws_get_city_title( $location_code ),
		)
	);

    ?>

    <div id="oc-woo-shipping-additional">

        <?php ocws_render_ocws_checkout_fields_inner( $post_data ); ?>

        <?php if (count($output) > 0) { ?>
            <?php
            $general_options_defaults = OC_Woo_Shipping_Group_Option::get_general_options_defaults();
            $slots_block_title = ocws_get_multilingual_option('ocws_common_checkout_slots_title');
            if (empty($slots_block_title)) {
                if (isset($general_options_defaults['checkout_slots_title'])) {
                    $slots_block_title = $general_options_defaults['checkout_slots_title'];
                }
            }
            $slots_block_descr = ocws_get_multilingual_option('ocws_common_checkout_slots_description');
            if (empty($slots_block_descr)) {
                if (isset($general_options_defaults['checkout_slots_description'])) {
                    $slots_block_descr = $general_options_defaults['checkout_slots_description'];
                }
            }
            ?>
            <!--            <div class="shipping-settings-title">--><?php //echo esc_html($slots_block_title); ?><!--</div>-->
            <!--            <div class="slot-message">--><?php //echo esc_html($slots_block_descr); ?><!--</div>-->
            <!--            --><?php //if ($selected_slot_arr['date']) { ?>
            <!--                <div class="slot-message chosen-slot">-->
            <!--                    --><?php //echo __('בחרת תאריך למשלוח ', 'ocws') ?>
            <!--                    <span class="selected-date">--><?php //echo esc_html($selected_slot_arr['date']) ?><!--</span>-->
            <!--                    --><?php //if (!$show_dates_only && $selected_slot_arr['slot_start'] && $selected_slot_arr['slot_end']) { ?>
            <!--                        <span class="selected-time">--><?php //echo esc_html($selected_slot_arr['slot_start']) . ' - ' . esc_html($selected_slot_arr['slot_end']) ?><!--</span>-->
            <!--                    --><?php //} ?>
            <!--                </div>-->
            <!--            --><?php //} ?>
            <div class="slot-list-container">
                <?php $slot_index = 0; ?>

                <?php
                // show_dates_only no longer switches to ocws-dates-onl y-list-slider — same day-card + Owl structure as pickup (one owl-item per day).
                if ($show_as_slider) { ?>

                    <div class="ocws-days-with-slots-list-label">בחר זמן הגעה</div>

                    <div class="ocws-days-with-slots-list">
                        <div class="ocws-day-cards-slider owl-carousel">
                            <?php foreach ($output as $day) { ?>
                                <div class="day-card day-data<?php echo $selected_slot_arr['date'] == $day['formatted_date'] ? ' active' : ''; ?><?php echo $show_dates_only ? ' without-days' : ''; ?>"                                     data-id="<?php echo esc_attr($day['formatted_date']) ?>"
                                     data-rel-id="<?php echo esc_attr($day['formatted_date']) ?>">
                                    <div class="day-card__header">
                                        <a href="javascript:void(0)" class="day-first-column">
                                            <span class="slot-weekday"><?php echo esc_html( ocws_slot_weekday_display( $day['formatted_date'], $day['weekday'] ) ); ?></span>
                                            <span class="slot-date"><?php echo esc_html($day['formatted_date']) ?></span>
                                        </a>
                                    </div>
                                    <div class="day-card__slots" <?php echo $show_dates_only ? 'style="display:none;"' : ''; ?>>
                                        <?php foreach ($day['slots'] as $slot) { ?>
                                            <a class="slot slot-interval <?php echo $slot['class'] ?>"
                                               href="javascript:void(0)"
                                               data-date="<?php echo esc_attr($day['formatted_date']) ?>"
                                               data-weekday="<?php echo esc_attr($day['day_of_week']) ?>"
                                               data-slot-start="<?php echo esc_attr($slot['start']) ?>"
                                               data-slot-end="<?php echo esc_attr($slot['end']) ?>"
                                            >
                                                <span class="slot-range"><?php echo esc_html($slot['start'] . ' - ' . $slot['end']) ?></span>
                                            </a>
                                        <?php } ?>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>

                <?php } else { ?>

                    <?php foreach ($output as $day) { ?>

                        <div style="<?php echo ($slot_index > 2 && $state == 'less') ? 'display:none;' : '' ?>"
                             class="day-data <?php echo ($slot_index > 2) ? 'day-data-hidden' : '' ?>">
                            <a href="javascript:void(0)" class="day-first-column">
                                <span class="slot-weekday"><?php echo esc_html( ocws_slot_weekday_display( $day['formatted_date'], $day['weekday'] ) ); ?></span>
                                <span class="slot-date"><?php echo esc_html($day['formatted_date']) ?></span>
                            </a>
                            <?php foreach ($day['slots'] as $slot) { ?>
                                <a class="slot slot-interval <?php echo $slot['class'] ?>"
                                   href="javascript:void(0)"
                                   data-date="<?php echo esc_attr($day['formatted_date']) ?>"
                                   data-weekday="<?php echo esc_attr($day['day_of_week']) ?>"
                                   data-slot-start="<?php echo esc_attr($slot['start']) ?>"
                                   data-slot-end="<?php echo esc_attr($slot['end']) ?>"
                                >
                                    <?php echo esc_html($slot['start'] . ' - ' . $slot['end']) ?>
                                </a>
                            <?php } ?>

                        </div>
                        <?php $slot_index++; ?>
                    <?php } ?>

                <?php } ?>

            </div>
            <?php if ($slot_index > 3 && !$show_as_slider) { ?>
                <div class="slot-list-buttons">
                    <button style="<?php echo ($state == 'more') ? 'display:none;' : '' ?>" type="button"
                            id="slot-list-button-show-all"><?php echo esc_html(__('Show all', 'ocws')) ?></button>
                    <button style="<?php echo ($state == 'less') ? 'display:none;' : '' ?>" type="button"
                            id="slot-list-button-show-less"><?php echo esc_html(__('Show less', 'ocws')) ?></button>
                </div>
            <?php } ?>
        <?php } else {
			ocws_log_render_shipping(
				'ui: no slot HTML (empty $output) — location ok but no available delivery windows in range',
				array(
					'location_code' => (string) $location_code,
					'loc_title'  => (string) ocws_get_city_title( $location_code ),
					'raw_days'  => is_array( $days ) ? count( $days ) : 0,
				)
			);
        } ?>

    </div>
    <?php
}

/**
 * @param \WC_Order $order
 * @return string
 */
function ocws_render_shipping_date_info($order)  // TODO : the same for pickup, forse show slot atart only + hide slot
{
    $force_hide_slot = (OC_Woo_Shipping_Group_Option::get_common_option('hide_slot_in_admin_mail', '') != 1 ? false : true);
    return OC_Woo_Shipping_Info::render_formatted_shipping_info($order, $force_hide_slot);
}


function ocws_get_city_title($city_id)
{
    $city_name = OCWS()->locations->get_city_name($city_id);
    if (!$city_name) {
        $group = OC_Woo_Shipping_Groups::get_group_by('location_code', $city_id);
        if (false === $group) return '';
        return $group->get_location_name_by_code($city_id);
    }
    return $city_name;
}

function ocws_get_city_title_translated($city_code, $city_name)
{
    return OCWS()->locations->translate_name($city_code, $city_name);
}

function ocws_get_group_id_by_city($city_id)
{
    $group = OC_Woo_Shipping_Groups::get_group_by('location_code', $city_id);
    if (false === $group) return 0;
    return $group->get_id();
}

function ocws_is_location_enabled($location_code)
{
    $data_store = new OC_Woo_Shipping_Group_Data_Store();

    $group_id = $data_store->get_group_by_location($location_code);
    $location_enabled = $data_store->is_location_enabled($location_code);
    $group_enabled = $data_store->is_group_enabled($group_id);

    return ($location_enabled && $group_enabled);
}

function ocws_is_affiliate_enabled($aff_id) {
    $affs_ds = new OCWS_LP_Affiliates();
    $aff = $affs_ds->db_get_affiliate($aff_id);
    if (!$aff) return false;
    return ($aff->is_enabled == 1);
}

function ocws_my_account_my_address_filter($address_arr, $customer_id, $address_type)
{

    if (isset($address_arr['city'])) {
        if (is_numeric($address_arr['city']) || ocws_is_hash($address_arr['city'])) {
            $address_arr['city'] = ocws_get_city_title($address_arr['city']);
        }
    }
    //error_log('ocws_my_account_my_address_filter: '.print_r($address_arr, 1));
    return $address_arr;
}

function ocws_get_acf_label($key, $post_id)
{
    if (!function_exists('get_field')) {
        return '';
    }
    $field = get_field($key, $post_id);
    if (!is_array($field)) {
        return $field;
    }
    if (!isset($field['label'])) {
        return '';
    }
    return $field['label'];
}

function ocws_get_acf_value($key, $post_id)
{
    if (!function_exists('get_field')) {
        return '';
    }
    $field = get_field($key, $post_id);
    if (!is_array($field)) {
        return $field;
    }
    if (!isset($field['value'])) {
        return '';
    }
    return $field['value'];
}


function ocws_get_template_part($file_name, $name = null)
{
    // Execute code for this part
    do_action('get_template_part_' . $file_name, $file_name, $name);

    // Setup possible parts
    $templates = array();
    $templates[] = $file_name;

    // Allow template parts to be filtered
    $templates = apply_filters('ocws_get_template_part', $templates, $file_name, $name);

    // Return the part that is found
    return ocws_locate_template($templates);
}

function ocws_locate_template($template_names)
{
    // No file found yet
    $located = false;

    // Try to find a template file
    foreach ((array)$template_names as $template_name) {

        // Continue if template is empty
        if (empty($template_name)) {
            continue;
        }

        // Trim off any slashes from the template name
        $template_name = ltrim($template_name, '/');
        // Check child theme first
        if (file_exists(trailingslashit(get_stylesheet_directory()) . 'ocws/' . $template_name)) {
            $located = trailingslashit(get_stylesheet_directory()) . 'ocws/' . $template_name;
            break;

            // Check parent theme next
        } else if (file_exists(trailingslashit(get_template_directory()) . 'ocws/' . $template_name)) {
            $located = trailingslashit(get_template_directory()) . 'ocws/' . $template_name;
            break;

            // Check theme compatibility last
        } else if (file_exists(trailingslashit(ocws_get_templates_dir()) . $template_name)) {
            $located = trailingslashit(ocws_get_templates_dir()) . $template_name;
            break;
        }
    }

    return $located;
}

function ocws_get_templates_dir()
{
    return OCWS_PATH . '/template-parts';
}

function ocws_get_value($key, $data)
{
    if (isset($data[$key]) && !empty($data[$key])) { // WPCS: input var ok, CSRF OK.
        return wc_clean(wp_unslash($data[$key])); // WPCS: input var ok, CSRF OK.
    }

    return '';
}

function ocws_get_languages()
{

    $languages = apply_filters('wpml_active_languages', NULL);
    $ret = array();

    if (!empty($languages)) {
        foreach ($languages as $l) {
            $ret[] = $l['language_code'];
        }
    }
    return $ret;
}

function ocws_get_multilingual_option($opt_name, $default = '')
{
    $l = ocws_get_languages();
    $locale = get_locale();

    if (!empty($l)) {
        if ($locale) {
            $curr_language = (strlen($locale) > 2) ? substr($locale, 0, 2) : $locale;
            return get_option($opt_name . '_' . $curr_language, $default);
        }
    }
    return get_option($opt_name, $default);
}

function ocws_translate_shipping_method_title($title, $shipping_id, $language = false)
{

    global $sitepress;

    if (has_filter('wpml_translate_single_string')) {

        $shipping_id = str_replace(':', '', $shipping_id);

        $translated_title = apply_filters(
            'wpml_translate_single_string',
            $title,
            'admin_texts_woocommerce_shipping',
            $shipping_id . '_shipping_method_title',
            $language ? $language : ($sitepress ? $sitepress->get_current_language() : false)
        );

        return $translated_title ?: $title;
    }
    return $title;
}

function ocws_is_advanced_shipping( $order ) {

    if (!$order || !is_a($order, 'WC_Order')) return false;
    $shipping_method_id = '';
    foreach ( $order->get_shipping_methods() as $shipping_method ) {
        $shipping_method_id = $shipping_method->get_method_id();
    }
    return (substr($shipping_method_id, 0, strlen(OCWS_Advanced_Shipping::SHIPPING_METHOD_ID)) == OCWS_Advanced_Shipping::SHIPPING_METHOD_ID);
}

function ocws_is_local_pickup( $order ) {

    if (!$order || !is_a($order, 'WC_Order')) return false;
    $shipping_method_id = '';
    foreach ( $order->get_shipping_methods() as $shipping_method ) {
        $shipping_method_id = $shipping_method->get_method_id();
    }
    return (substr($shipping_method_id, 0, strlen(OCWS_LP_Local_Pickup::PICKUP_METHOD_ID)) == OCWS_LP_Local_Pickup::PICKUP_METHOD_ID);
}

function ocws_get_shipping_method_tag( $method_id ) {

    if (substr($method_id, 0, strlen(OCWS_LP_Local_Pickup::PICKUP_METHOD_ID)) == OCWS_LP_Local_Pickup::PICKUP_METHOD_ID) {
        return OCWS_LP_Local_Pickup::PICKUP_METHOD_TAG;
    }
    if (substr(method_id, 0, strlen(OCWS_Advanced_Shipping::SHIPPING_METHOD_ID)) == OCWS_Advanced_Shipping::SHIPPING_METHOD_ID) {
        return OCWS_Advanced_Shipping::SHIPPING_METHOD_TAG;
    }
    return '';
}

function ocws_is_method_id_pickup( $method_id ) {

    return (substr($method_id, 0, strlen(OCWS_LP_Local_Pickup::PICKUP_METHOD_ID)) == OCWS_LP_Local_Pickup::PICKUP_METHOD_ID);
}

function ocws_is_method_id_shipping( $method_id ) {

    return (substr($method_id, 0, strlen(OCWS_Advanced_Shipping::SHIPPING_METHOD_ID)) == OCWS_Advanced_Shipping::SHIPPING_METHOD_ID);
}

function ocws_get_google_maps_api_key()
{

    return (is_multisite()? get_site_option('ocws_common_google_maps_api_key') : get_option('ocws_common_google_maps_api_key'));
}

function ocws_use_google_cities() {

    $use_cities = (is_multisite()? get_site_option('ocws_common_use_google_cities') : get_option('ocws_common_use_google_cities'));
    return ($use_cities === '1' && ocws_get_google_maps_api_key());

}

function ocws_use_google_cities_and_polygons() {

    $use_polygons = (is_multisite()? get_site_option('ocws_common_use_google_cities_and_polygons') : get_option('ocws_common_use_google_cities_and_polygons'));
    return ($use_polygons === '1' && ocws_get_google_maps_api_key());

}

function ocws_use_deli_for_regular_products() {

    return (get_option('ocws_common_use_deli_for_regular_products') === '1');

}

function ocws_use_deli_style() {

    return (get_option('ocws_common_use_deli_style') === '1');

}

function ocws_deli_style_checkout() {

    return (get_option('ocws_common_deli_style_checkout') === '1');

}


function ocws_is_hash($hash) {

    return (strlen($hash) === 32 && ctype_xdigit($hash));

}


function ocws_woocommerce_rest_prepare_shop_order_object_filter($response, $object, $request) {

    if (empty($response->data))

        return $response;

    $order_data = $response->get_data();
    $order_id = $order_data['id'];

    $billing_city_name_meta = get_post_meta($order_id, '_billing_city_name', true);
    $shipping_city_name_meta = get_post_meta($order_id, '_billing_city_name', true);

    if ($billing_city_name_meta && isset($order_data['billing']) && isset($order_data['billing']['city'])) {

        $order_data['billing']['city'] = $billing_city_name_meta;

    }

    if ($shipping_city_name_meta && isset($order_data['shipping']) && isset($order_data['shipping']['city'])) {

        $order_data['shipping']['city'] = $shipping_city_name_meta;

    }

    if ($billing_city_name_meta && isset($order_data['billing_address']) && isset($order_data['billing_address']['city'])) {

        $order_data['billing_address']['city'] = $billing_city_name_meta;

    }

    if ($shipping_city_name_meta && isset($order_data['shipping_address']) && isset($order_data['shipping_address']['city'])) {

        $order_data['shipping_address']['city'] = $shipping_city_name_meta;

    }

    $response->data = $order_data;

    return $response;

}


function ocws_woocommerce_api_order_response_filter($order_data, $order) {

    $order_id = $order_data['id'];
    $billing_city_name_meta = get_post_meta($order_id, '_billing_city_name', true);
    $shipping_city_name_meta = get_post_meta($order_id, '_billing_city_name', true);

    if ($billing_city_name_meta && isset($order_data['billing']) && isset($order_data['billing']['city'])) {

        $order_data['billing']['city'] = $billing_city_name_meta;

    }

    if ($shipping_city_name_meta && isset($order_data['shipping']) && isset($order_data['shipping']['city'])) {

        $order_data['shipping']['city'] = $shipping_city_name_meta;

    }

    if ($billing_city_name_meta && isset($order_data['billing_address']) && isset($order_data['billing_address']['city'])) {

        $order_data['billing_address']['city'] = $billing_city_name_meta;

    }

    if ($shipping_city_name_meta && isset($order_data['shipping_address']) && isset($order_data['shipping_address']['city'])) {

        $order_data['shipping_address']['city'] = $shipping_city_name_meta;

    }

    return $order_data;
}

function ocws_get_formatted_address($street, $house_number, $apartment, $floor, $entercode) {

    //return $street . ' ' . $house_number . ', ' . ($apartment? __('Apartment', 'ocws') . ' ' . $apartment . ', ' : '') . ($floor? __('Floor', 'ocws') . ' ' . $floor . ', ' : '') . ($entercode? __('Enter code', 'ocws') . ' ' . $entercode : '');
    return $street . ' ' . $house_number; // . ', ' . ($apartment? __('Apartment', 'ocws') . ' ' . $apartment . ', ' : '') . ($floor? __('Floor', 'ocws') . ' ' . $floor . ', ' : '') . ($entercode? __('Enter code', 'ocws') . ' ' . $entercode : '');
}

/**
 * @param \WC_Order $order
 */
function ocws_save_full_address_to_order($order)	{

    $street = get_post_meta($order->get_id(), '_billing_street', true);
    $house_number = get_post_meta($order->get_id(), '_billing_house_num', true);
    $apartment = get_post_meta($order->get_id(), '_billing_apartment', true);
    $floor = get_post_meta($order->get_id(), '_billing_floor', true);
    $entercode = get_post_meta($order->get_id(), '_billing_enter_code', true);

    $address = ocws_get_formatted_address($street, $house_number, $apartment, $floor, $entercode);
    update_post_meta($order->get_id(), '_billing_address_1', $address);

    $street = get_post_meta($order->get_id(), '_shipping_street', true);
    $house_number = get_post_meta($order->get_id(), '_shipping_house_num', true);
    $apartment = get_post_meta($order->get_id(), '_shipping_apartment', true);
    $floor = get_post_meta($order->get_id(), '_shipping_floor', true);
    $entercode = get_post_meta($order->get_id(), '_shipping_enter_code', true);

    $address = ocws_get_formatted_address($street, $house_number, $apartment, $floor, $entercode);
    update_post_meta($order->get_id(), '_shipping_address_1', $address);
}

/**
 * @param string $billing_city
 * @param \WC_Order $order
 *
 * @return string
 */
function ocws_cardcom_parameter_billing_city_filter($billing_city, $order) {

    //error_log('---------------------- ocws_cardcom_parameter_billing_city_filter ----------------------------');
    //error_log('city to convert: "'.$billing_city.'"');
    if (!$order || !is_a($order, 'WC_Order')) {
        //error_log('no order');
        return $billing_city;
    }
    $city = '';
    if (is_numeric($billing_city) || ocws_is_hash($billing_city)) {
        $city = get_post_meta( $order->get_id(), '_billing_city_name', true);
        if (!$city) {
            $city = ocws_get_city_title($billing_city);
        }
    }
    //error_log('city: '.$city);
    //error_log('---------------------------------------------------------');
    return ($city? $city : $billing_city);
}

/**
 * @param string $billing_city
 * @param \WC_Order $order
 *
 * @return string
 */
function ocws_order_billing_city_filter($billing_city, $order) {

    if (!$order || !is_a($order, 'WC_Order')) {
        return $billing_city;
    }
    $city = '';
    if (is_numeric($billing_city) || ocws_is_hash($billing_city)) {
        $city = get_post_meta( $order->get_id(), '_billing_city_name', true);
        if (!$city) {
            $city = ocws_get_city_title($billing_city);
        }
    }
    return ($city? $city : $billing_city);
}

/**
 * @param string $shipping_city
 * @param \WC_Order $order
 *
 * @return string
 */
function ocws_order_shipping_city_filter($shipping_city, $order) {

    if (!$order || !is_a($order, 'WC_Order')) {
        return $shipping_city;
    }
    $city = '';
    if (is_numeric($shipping_city) || ocws_is_hash($shipping_city)) {
        $city = get_post_meta( $order->get_id(), '_shipping_city_name', true);
        if (!$city) {
            $city = ocws_get_city_title($shipping_city);
        }
    }
    return ($city? $city : $shipping_city);
}

function ocws_get_order_shipping_city_name($order) {
    if (!$order) {
        return '';
    }
    if (! is_a($order, 'WC_Order')) {
        if (!is_numeric($order)) {
            return '';
        }
        $order = wc_get_order(absint($order));
        if (!$order) {
            return '';
        }
    }
    $city_name = ocws_get_order_meta( $order, '_shipping_city_name');
    if (!empty($city_name)) {
        return $city_name;
    }
    $city_code = ocws_get_order_meta( $order, '_shipping_city_code');
    if (empty($city_code)) {
        $city_code = ocws_get_order_meta( $order, '_shipping_city');
    }
    $city_name = ocws_get_city_title($city_code);
    return ($city_name? $city_name : $city_code);
}

function ocws_get_order_billing_city_name($order) {
    if (!$order) {
        return '';
    }
    if (! is_a($order, 'WC_Order')) {
        if (!is_numeric($order)) {
            return '';
        }
        $order = wc_get_order(absint($order));
        if (!$order) {
            return '';
        }
    }
    $city_name = ocws_get_order_meta( $order, '_billing_city_name');
    if (!empty($city_name)) {
        return $city_name;
    }
    $city_code = ocws_get_order_meta( $order, '_billing_city_code');
    if (empty($city_code)) {
        $city_code = ocws_get_order_meta( $order, '_billing_city');
    }
    $city_name = ocws_get_city_title($city_code);
    return ($city_name? $city_name : $city_code);
}

function ocws_get_order_meta($order, $meta_name) {
    if (!$order || ! is_a($order, 'WC_Order')) {
        return '';
    }
    if (is_string($meta_name) && $meta_name !== '') {
        if (strpos($meta_name, '_billing_') === 0) {
            $field = substr($meta_name, strlen('_billing_'));
            $method = 'get_billing_' . $field;
            if (is_callable(array($order, $method))) {
                return (string) call_user_func(array($order, $method));
            }
        } elseif (strpos($meta_name, '_shipping_') === 0) {
            $field = substr($meta_name, strlen('_shipping_'));
            $method = 'get_shipping_' . $field;
            if (is_callable(array($order, $method))) {
                return (string) call_user_func(array($order, $method));
            }
        }
    }
    $val = $order->get_meta($meta_name, true);
    if (!empty($val)) {
        return $val;
    }
    return get_post_meta($order->get_id(), $meta_name, true);
}

function ocws_get_day_of_week($date_str, $date_format = 'd/m/Y') {

    try {
        $dt = Carbon::createFromFormat($date_format, $date_str, ocws_get_timezone());
    }
    catch (InvalidArgumentException $e) {
        return '';
    }
    $weekdays = array(
        __('Sunday', 'ocws'),
        __('Monday', 'ocws'),
        __('Tuesday', 'ocws'),
        __('Wednesday', 'ocws'),
        __('Thursday', 'ocws'),
        __('Friday', 'ocws'),
        __('Saturday', 'ocws')
    );
    if (isset($weekdays[$dt->dayOfWeek])) {
        return $weekdays[$dt->dayOfWeek];
    }
    return '';
}

/**
 * Label for .slot-weekday: "היום" / "מחר" when the slot date is today/tomorrow (site timezone), else weekday name.
 *
 * @param string $formatted_date Same format as slot rows (typically d/m/Y).
 * @param string $weekday_fallback Localized weekday from schedule (e.g. שלישי).
 * @param string $date_format     Date format for $formatted_date.
 * @return string
 */
function ocws_slot_weekday_display( $formatted_date, $weekday_fallback, $date_format = 'd/m/Y' ) {
    if ( '' === $formatted_date || null === $formatted_date ) {
        return $weekday_fallback;
    }
    try {
        $tz     = ocws_get_timezone();
        $day_dt = Carbon::createFromFormat( $date_format, $formatted_date, $tz )->startOfDay();
        $today  = Carbon::now( $tz )->startOfDay();
        $tomorrow = $today->copy()->addDay();
        if ( $day_dt->equalTo( $today ) ) {
            return __( 'Today', 'ocws' );
        }
        if ( $day_dt->equalTo( $tomorrow ) ) {
            return __( 'Tomorrow', 'ocws' );
        }
    } catch ( InvalidArgumentException $e ) {
        return $weekday_fallback;
    }
    return $weekday_fallback;
}

function ocws_get_orders_count_report() {
    $totals = wp_count_posts( 'shop_order' );
    $data   = array();

    foreach ( wc_get_order_statuses() as $slug => $name ) {
        if ( ! isset( $totals->$slug ) ) {
            continue;
        }

        $data[] = array(
            'slug'  => str_replace( 'wc-', '', $slug ),
            'name'  => $name,
            'total' => (int) $totals->$slug,
        );
    }

    return $data;
}

function ocws_get_orders_count() {
    $totals = wp_count_posts( 'shop_order' );
    $count = 0;

    foreach ( wc_get_order_statuses() as $slug => $name ) {
        if ( ! isset( $totals->$slug ) ) {
            continue;
        }
        $count += (int) $totals->$slug;
    }

    return $count;
}

function ocws_b2bking_get_customer_group($user_id) {

    // first check if subaccount. If subaccount, user is equivalent with parent
    $account_type = get_user_meta($user_id, 'b2bking_account_type', true);
    if ($account_type === 'subaccount'){
        // get parent
        $is_subaccount = 'yes';
        $parent_account_id = get_user_meta ($user_id, 'b2bking_account_parent', true);
        $user_id = $parent_account_id;
    } else {
        $is_subaccount = 'no';
    }

    $user_is_b2b = get_the_author_meta( 'b2bking_b2buser', $user_id );
    if ($user_is_b2b === 'yes'){
        // do nothing
    } else {
        $user_is_b2b = 'no';
    }
    if ($user_is_b2b === 'yes'){
        if ($is_subaccount === 'yes'){
            return esc_html__('Subaccount of ','b2bking').esc_html(get_the_title(get_the_author_meta( 'b2bking_customergroup', $user_id )));
        } else {
            return esc_html(get_the_title(get_the_author_meta( 'b2bking_customergroup', $user_id )));
        }
    } else {
        return esc_html__('B2C Users', 'b2bking');
    }
}

function ocws_order_shipping_data_to_session($order_id) {

    $tag = get_post_meta( $order_id, 'ocws_shipping_tag', true );

    if ($tag == 'shipping') {

        $shipping_date = get_post_meta( $order_id, 'ocws_shipping_info_date', true );
        $slot_start = get_post_meta( $order_id, 'ocws_shipping_info_slot_start', true );
        $slot_end = get_post_meta( $order_id, 'ocws_shipping_info_slot_end', true );
        $city_id = get_post_meta( $order_id, '_billing_city', true);
        WC()->session->set('chosen_shipping_city', $city_id );
        $shipping_info = array(
            'date' => $shipping_date,
            'slot_start' => ($slot_start? : ''),
            'slot_end' => ($slot_end? : '')
        );
        WC()->session->set('ocws_shipping_info', serialize($shipping_info));
    }
    else if ($tag == 'pickup') {

        $pickup_date = get_post_meta( $order_id, 'ocws_shipping_info_date', true );
        if ( ! $pickup_date ) {
            $pickup_date = get_post_meta( $order_id, 'ocws_lp_pickup_date', true );
        }
        $slot_start = get_post_meta( $order_id, 'ocws_shipping_info_slot_start', true );
        if ( ! $slot_start ) {
            $slot_start = get_post_meta( $order_id, 'ocws_lp_pickup_slot_start', true );
        }
        $slot_end = get_post_meta( $order_id, 'ocws_shipping_info_slot_end', true );
        if ( ! $slot_end ) {
            $slot_end = get_post_meta( $order_id, 'ocws_lp_pickup_slot_end', true );
        }
        $aff_id = get_post_meta( $order_id, 'ocws_lp_pickup_aff_id', true);

        WC()->session->set('chosen_pickup_aff', $aff_id );
        $aff_name = get_post_meta( $order_id, 'ocws_lp_pickup_aff_name', true );
        if ( ! $aff_name && $aff_id && class_exists( 'OCWS_LP_Affiliates' ) ) {
            $ads = new OCWS_LP_Affiliates();
            $aff_name = $ads->get_affiliate_name( absint( $aff_id ) );
        }
        $pickup_info = array(
            'aff_id' => $aff_id,
            'aff_name' => ( $aff_name ? $aff_name : '' ),
            'date' => $pickup_date,
            'slot_start' => ($slot_start? : ''),
            'slot_end' => ($slot_end? : '')
        );
        if ( class_exists( 'OCWS_LP_Pickup_Info' ) ) {
            OCWS_LP_Pickup_Info::save_pickup_info( $pickup_info );
        } else {
            WC()->session->set('ocws_lp_pickup_info', serialize($pickup_info));
        }
        if ( function_exists( 'ocws_update_session_checkout_field' ) ) {
            if ( $pickup_date ) {
                ocws_update_session_checkout_field( 'ocws_lp_pickup_date', $pickup_date );
            }
            if ( $slot_start ) {
                ocws_update_session_checkout_field( 'ocws_lp_pickup_slot_start', $slot_start );
            }
            if ( $slot_end ) {
                ocws_update_session_checkout_field( 'ocws_lp_pickup_slot_end', $slot_end );
            }
            if ( $aff_id ) {
                ocws_update_session_checkout_field( 'ocws_lp_pickup_aff_id', $aff_id );
            }
            if ( $aff_name ) {
                ocws_update_session_checkout_field( 'ocws_lp_pickup_aff_name', $aff_name );
            }
        }

    }
}

function ocws_get_timezone() {
    //error_log(wp_timezone_string());
    return wp_timezone_string();
}

function ocws_enabled_pickup_branches_exist($networkwide=true) {

    global $wpdb;
    if (! is_multisite() || ! $networkwide) {
        $count = OCWS_LP_Affiliates::db_get_enabled_affiliates_count();
        return ($count > 0);
    }
    $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );

    $count = 0;
    foreach ( $blog_ids as $blog_id ) {
        switch_to_blog( $blog_id );

        $count = OCWS_LP_Affiliates::db_get_enabled_affiliates_count();
        if ($count > 0) {
            restore_current_blog();
            break;
        }

        restore_current_blog();
    }
    return ($count > 0);
}

function ocws_enabled_shipping_locations_exist($networkwide=true) {

    global $wpdb;
    if (! is_multisite() || ! $networkwide) {
        $count = OC_Woo_Shipping_Groups::db_get_enabled_locations_count();
        return ($count > 0);
    }
    $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );

    $count = 0;
    foreach ( $blog_ids as $blog_id ) {
        switch_to_blog( $blog_id );

        $count = OC_Woo_Shipping_Groups::db_get_enabled_locations_count();
        if ($count > 0) {
            restore_current_blog();
            break;
        }

        restore_current_blog();
    }
    return ($count > 0);
}

function ocws_enabled_shipping_locations_count_networkwide() {
    global $wpdb;
    if (! is_multisite()) {
        return OC_Woo_Shipping_Groups::db_get_enabled_locations_count();
    }
    $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );

    $count = 0;
    foreach ( $blog_ids as $blog_id ) {
        switch_to_blog( $blog_id );

        $count += OC_Woo_Shipping_Groups::db_get_enabled_locations_count();

        restore_current_blog();
    }
    return $count;
}

function ocws_enabled_shipping_locations_count_blog($blog_id=0) {
    if (is_multisite() && $blog_id) {

        $count = 0;
        switch_to_blog( $blog_id );

        $count += OC_Woo_Shipping_Groups::db_get_enabled_locations_count();

        restore_current_blog();
        return $count;
    }
    return OC_Woo_Shipping_Groups::db_get_enabled_locations_count();
}

function ocws_include_template_part($file_name, $name = null, $var = null, $return = false) {

    $located_path = ocws_get_template_part($file_name, $name);

    if ($located_path) {
        if ( $var && is_array( $var ) ) {
            extract( $var );
        }

        if( $return ) {
            ob_start();
        }

        // include file located
        include( $located_path );

        if( $return ) {
            return ob_get_clean();
        }
    }

    if ($return) {
        return '';
    }
}

function ocws_get_session_checkout_field( $field_name ) {

    if (!isset(WC()->session)) {
        return '';
    }
    $data = WC()->session->get( 'checkout_data' );
    if ( $data && isset($data[$field_name]) && !empty( $data[$field_name] ) ) {
        return is_bool( $data[$field_name] ) ? (int) $data[$field_name] : $data[$field_name];
    }
    return '';
}

function ocws_update_session_checkout_field ( $field_name, $field_value ) {

    if (!isset(WC()->session)) {
        return;
    }
    $data = WC()->session->get( 'checkout_data' );
    if ( ! is_array( $data ) ) {
        $data = array();
    }
    $data[ $field_name ] = $field_value;
    WC()->session->set( 'checkout_data', $data );
}

function ocws_blog_exists($blog_id) {
    if (!$blog_id) return false;
    if (!is_multisite()) {
        // In non-multisite, only the current site exists
        return (intval($blog_id) === 1 || intval($blog_id) === get_current_blog_id());
    }
    $blog_id = intval($blog_id);
    foreach( get_sites() as $subsite ) {
        $subsite_id = get_object_vars($subsite)["blog_id"];
        if ($subsite_id == $blog_id) {
            return true;
        }
    }
    return false;
}

function ocws_get_blog_path($blog_id) {
    if (!is_multisite()) {
        // In non-multisite, return the site path
        return '/';
    }
    foreach( get_sites() as $subsite ) {
        $subsite_data = get_object_vars($subsite);
        if ($subsite_data['blog_id'] == $blog_id) {
            return $subsite_data['path'];
        }
    }
    return '';
}

function ocws_get_referer() {
    if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
        return wp_unslash( $_SERVER['HTTP_REFERER'] );
    }
    return false;
}

function ocws_convert_current_page_url($blog_id, $query_vars=array()) {
    global $wp;
    $uri = '';
    if (defined('DOING_AJAX') && DOING_AJAX) {
        $uri = ocws_get_referer();
        error_log('ocws_convert_current_page_url: '.$uri);
    }
    else {
        $uri = ocws_filter_uri($_SERVER['REQUEST_URI']);
    }
    if (!$uri) {
        return get_site_url($blog_id);
    }
    $path = parse_url($uri, PHP_URL_PATH);
    $url_query_vars = [];
    parse_str(parse_url($uri, PHP_URL_QUERY), $url_query_vars);
    if (is_array($query_vars)) {
        if (array_key_exists('ocws_from_store', $url_query_vars)) {
            unset($url_query_vars['ocws_from_store']);
        }
        $url_query_vars += $query_vars;
    }
    $query = http_build_query($url_query_vars);

    // In non-multisite, just return the current URL with query vars
    if (!is_multisite()) {
        return get_site_url($blog_id, $path) . ($query? '?'.$query : '');
    }

    return get_site_url($blog_id, str_ireplace(ltrim(ocws_get_blog_path(get_current_blog_id()), '/'), '', $path)) . ($query? '?'.$query : '');
}

function ocws_filter_uri($input, $strip=true) {
    $input = trim($input);
    if ($input == '/') {
        return $input;
    }
    // add more chars if needed
    $input = str_ireplace(["\0", '%00', "\x0a", '%0a', "\x1a", '%1a'], '',
        rawurldecode($input));

    // remove markup stuff
    if ($strip) {
        $input = strip_tags($input);
    }

    // or any encoding you use instead of utf-8
    $input = htmlspecialchars($input, ENT_QUOTES, 'utf-8');

    return $input;
}
