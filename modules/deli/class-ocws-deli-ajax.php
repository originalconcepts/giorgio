<?php
/**
 * OC_Woo_Ajax. AJAX Event Handlers.
 *
 */

use Carbon\Carbon;

defined( 'ABSPATH' ) || exit;

/**
 * OC_Woo_AJAX class.
 */
class OCWS_Deli_Ajax extends OC_Woo_Ajax {

    /**
     * Hook in ajax handlers.
     */
    public static function init() {
        self::add_ajax_events();
    }

    /**
     * Hook in methods - uses WordPress ajax handlers (admin-ajax).
     */
    public static function add_ajax_events() {
        $ajax_events_nopriv = array(
            'fetch_slots_for_everything',
            'fetch_slots_for_city',
            'fetch_slots_for_coords',
            'fetch_slots_for_aff',
            'save_delivery_settings_data_from_cart_form',
            'product_available_on_day'
        );

        foreach ( $ajax_events_nopriv as $ajax_event ) {
            add_action( 'wp_ajax_ocws_deli_' . $ajax_event, array( __CLASS__, $ajax_event ) );
            add_action( 'wp_ajax_nopriv_ocws_deli_' . $ajax_event, array( __CLASS__, $ajax_event ) );
        }

        $ajax_events = array(
            'products_ajax_query'
        );

        foreach ( $ajax_events as $ajax_event ) {
            add_action( 'wp_ajax_ocws_deli_' . $ajax_event, array( __CLASS__, $ajax_event ) );
        }

    }

    public static function product_available_on_day() {

        $date = isset($_POST['date'])? $_POST['date'] : '';
        $product_id = isset($_POST['product_id'])? $_POST['product_id'] : '';

        if (empty($date) || empty($product_id)) {
            wp_send_json_success(
                array(
                    'resp' => __('This product is not available on the selected day', 'ocws')
                )
            );
        }
        $menus = OCWS_Deli_Menus::instance();
        if ( $menus->is_product_available_on_date( $product_id, $date, '' ) ) {
            wp_send_json_success(
                array(
                    'resp' => '',
                )
            );
            return;
        }
        wp_send_json_success(
            array(
                'resp' => __('This product is not available on the selected day', 'ocws'),
            )
        );
    }

    public static function fetch_slots_for_city() {
        $resp = '';

        $shipping = isset($_POST['shipping_method'])? $_POST['shipping_method'] : '';
        $shipping = str_replace(':', '', $shipping);

        if (empty($shipping)) {
            wp_send_json_success(
                array(
                    'resp' => $resp,
                )
            );
        }

        //WC()->session->set('chosen_shipping_methods', array( $shipping ) );

        //OCWS_Deli::save_chosen_location_data();

        //OCWS_Deli::flush_cart_widget_cache();

        $location_code = OCWS_Deli::get_chosen_location_code();

        global $wpdb;
        if (is_multisite()) {
            $enabled_blog_locations_count = ocws_enabled_shipping_locations_count_blog();
            $enabled_network_locations_count = ocws_enabled_shipping_locations_count_networkwide();
            if ($enabled_blog_locations_count == 0 && $enabled_network_locations_count == 1) {
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

                    ob_start();
                    ?>
                    <div id="oc-woo-shipping-additional">
                        <div class="slot-message"><?php echo $oos_message; ?></div>
                    </div>
                    <?php
                    $resp = ob_get_clean();
                    wp_send_json_success(
                        array(
                            'resp' => $resp,
                        )
                    );
                    return;
                }
            }

            if ($location_code && str_contains($location_code.'', ':::')) {
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
                        WC()->session->set('chosen_shipping_methods', array( $shipping ) );
                        WC()->session->save_data();
                        //error_log(print_r(WC()->session->get_session_data(),1));
                        $redirect_url = esc_url(ocws_convert_current_page_url($go_to_blog_id, ['ocws_from_store' => get_current_blog_id()]));

                        $oos_message = '<span class="important-notice">'.
                            esc_html(sprintf(__('You have chosen the branch %s for shipping.', 'ocws'), $blog_name)).
                            '</span><br><a class="ocws-site-link" href="'.esc_url($redirect_url).'">'.esc_html(__('Go to the site.', 'ocws')).'</a>';

                        ob_start();
                        global $wp;
                        ?>
                        <div id="oc-woo-shipping-additional">
                            <!--<div class="slot-message"><?php /*echo $oos_message; */?></div>-->
                            <div class="slot-message"><input type="button" class="ocws-redirect-button cds-button-submit" data-href="<?php echo esc_url(ocws_convert_current_page_url($go_to_blog_id, ['ocws_from_store' => get_current_blog_id()])); ?>" value="<?php _e('Confirm and continue shopping' , 'ocws')?>"></div>
                        </div>
                        <?php
                        $resp = ob_get_clean();
                        wp_send_json_success(
                            array(
                                'resp' => $resp,
                            )
                        );
                        return;
                    }

                }
                else if (isset($bid[1])) {
                    $location_code = $bid[1];
                }
            }
        }

        if ($location_code && ocws_is_location_enabled($location_code)) {
            ob_start();
            OCWS_Deli::output_shipping_datepicker($location_code);
            $resp = ob_get_clean();
        }
        else {
            ob_start();
            OCWS_Deli::output_out_of_service_area_message();
            $resp = ob_get_clean();
        }

        wp_send_json_success(
            array(
                'resp' => $resp,
            )
        );
    }

    public static function fetch_slots_for_coords() {

        $resp = '';

        $shipping = isset($_POST['shipping_method'])? $_POST['shipping_method'] : '';
        $shipping = str_replace(':', '', $shipping);

        if (empty($shipping)) {
            wp_send_json_success(
                array(
                    'resp' => $resp,
                )
            );
        }

        //WC()->session->set('chosen_shipping_methods', array( $shipping ) );

        //OCWS_Deli::save_chosen_location_data();

        //OCWS_Deli::flush_cart_widget_cache();

        $location_code = OCWS_Deli::get_chosen_location_code();

        global $wpdb;
        if (is_multisite()) {
            $enabled_blog_locations_count = ocws_enabled_shipping_locations_count_blog();
            $enabled_network_locations_count = ocws_enabled_shipping_locations_count_networkwide();
            if ($enabled_blog_locations_count == 0 && $enabled_network_locations_count == 1) {
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

                    ob_start();
                    ?>
                    <div id="oc-woo-shipping-additional">
                        <div class="slot-message"><?php echo $oos_message; ?></div>
                    </div>
                    <?php
                    $resp = ob_get_clean();
                    wp_send_json_success(
                        array(
                            'resp' => $resp,
                        )
                    );
                    return;
                }
            }

            if ($location_code && str_contains($location_code.'', ':::')) {
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
                        WC()->session->set('chosen_shipping_methods', array( $shipping ) );
                        WC()->session->save_data();
                        //error_log(print_r(WC()->session->get_session_data(),1));

                        $redirect_url = esc_url(ocws_convert_current_page_url($go_to_blog_id, ['ocws_from_store' => get_current_blog_id()]));

                        $oos_message = '<span class="important-notice">'.
                            esc_html(sprintf(__('You have chosen the branch %s for shipping.', 'ocws'), $blog_name)).
                            '</span><br><a class="ocws-site-link" href="'.esc_url($redirect_url).'">'.esc_html(__('Go to the site.', 'ocws')).'</a>';

                        ob_start();
                        global $wp;
                        ?>
                        <div id="oc-woo-shipping-additional">
                            <!--<div class="slot-message"><?php /*echo $oos_message; */?></div>-->
                            <div class="slot-message"><input type="button" class="ocws-redirect-button cds-button-submit" data-href="<?php echo esc_url(ocws_convert_current_page_url($go_to_blog_id, ['ocws_from_store' => get_current_blog_id()])); ?>" value="<?php _e('Confirm and continue shopping' , 'ocws')?>"></div>
                        </div>
                        <?php
                        $resp = ob_get_clean();
                        wp_send_json_success(
                            array(
                                'resp' => $resp,
                            )
                        );
                        return;
                    }

                }
                else if (isset($bid[1])) {
                    $location_code = $bid[1];
                }
            }
        }

        if ($location_code && ocws_is_location_enabled($location_code)) {
            ob_start();
            OCWS_Deli::output_shipping_datepicker($location_code);
            $resp = ob_get_clean();
        }
        else {
            ob_start();
            OCWS_Deli::output_out_of_service_area_message();
            $resp = ob_get_clean();
        }

        wp_send_json_success(
            array(
                'resp' => $resp,
            )
        );
    }

    public static function fetch_slots_for_aff() {

        $resp = '';

        $shipping = isset($_POST['shipping_method'])? $_POST['shipping_method'] : '';
        $shipping = str_replace(':', '', $shipping);

        if (empty($shipping)) {
            wp_send_json_success(
                array(
                    'resp' => $resp,
                )
            );
        }

        $aff_id = OCWS_Deli::get_chosen_pickup_branch();

        $affiliates_dropdown = OCWS_LP_Local_Pickup::get_affiliates_dropdown_networkwide(true);
        if (count($affiliates_dropdown) <= 1) {
            foreach ($affiliates_dropdown as $key => $val) {
                $aff_id = $key;
                break;
            }
        }

        if (is_multisite()) {
            if ($aff_id && str_contains($aff_id.'', ':::')) {
                $bid = explode(':::', $aff_id, 2);
                $blog_id = intval($bid[0]);

                if (ocws_blog_exists($blog_id)) {
                    $blog_data = get_site($blog_id);

                    $blog_checkout_url = get_blogaddress_by_id(intval($blog_id));
                    switch_to_blog($blog_id);
                    $blog_checkout_url = wc_get_checkout_url();
                    restore_current_blog();

                    $tmp = WC()->session->get('shipping_for_package_0', false);
                    WC()->session->set('shipping_for_package_0', null);
                    WC()->session->set('popup_aff_id', $aff_id);
                    if ($tmp) {
                        //error_log(print_r($tmp, 1));
                        //WC()->session->set('shipping_for_package_0', $tmp);
                    }
                    WC()->session->set('chosen_shipping_methods', array( $shipping ) );
                    WC()->session->save_data();

                    ob_start();
                    if (count($affiliates_dropdown) == 1) {
                        ?>
                        <!--<div class="slot-message"><span class="important-notice">
                                <?php /*echo esc_html(sprintf(__('Local pickup is available from %s only.', 'ocws'), $blog_data->blogname)); */?><br>
                                <a class="ocws-site-link" href="<?php /*echo esc_url(ocws_convert_current_page_url($blog_id, ['ocws_from_store' => get_current_blog_id()])); */?>"><?php /*echo esc_html(__('Go to the site.', 'ocws')); */?></a>
                            </span></div>-->

                        <div class="slot-message"><input type="button" class="ocws-redirect-button cds-button-submit" data-href="<?php echo esc_url(ocws_convert_current_page_url($blog_id, ['ocws_from_store' => get_current_blog_id()])); ?>" value="<?php _e('Confirm and continue shopping' , 'ocws')?>"></div>

                        <?php
                    }
                    else {
                        ?>
                        <!--<div class="slot-message"><span class="important-notice">
                                <?php /*echo esc_html(sprintf(__('You have chosen the branch %s for local pickup.', 'ocws'), $blog_data->blogname)); */?><br>
                                <a class="ocws-site-link" href="<?php /*echo esc_url(ocws_convert_current_page_url($blog_id, ['ocws_from_store' => get_current_blog_id()])); */?>"><?php /*echo esc_html(__('Go to the site.', 'ocws')); */?></a>
                            </span></div>-->

                        <div class="slot-message"><input type="button" class="ocws-redirect-button cds-button-submit" data-href="<?php echo esc_url(ocws_convert_current_page_url($blog_id, ['ocws_from_store' => get_current_blog_id()])); ?>" value="<?php _e('Confirm and continue shopping' , 'ocws')?>"></div>

                        <?php
                    }
                }
                else {
                    //
                }
                WC()->session->get_session_data();
                $resp = ob_get_clean();
                wp_send_json_success(
                    array(
                        'resp' => $resp,
                    )
                );
            }
        }

        if ($aff_id) {
            ob_start();
            OCWS_Deli::output_pickup_datepicker($aff_id);
            $resp = ob_get_clean();
        }

        wp_send_json_success(
            array(
                'resp' => $resp,
            )
        );
    }

    public static function fetch_slots_for_everything() {

        $resp = array();

        $dates = $resp; //OCWS_Deli::get_slots_for_everything();

        wp_send_json_success(
            array(
                'resp' => $dates,
            )
        );
    }

    public static function products_ajax_query() {

        $res = array();
        if (empty($_REQUEST['s'])) {
            wp_send_json_success(
                array(
                    'resp' => $res,
                )
            );
        }
        $search = wc_clean( wp_unslash( $_REQUEST['s'] ) );
        $cat = '';
        if (isset($_REQUEST['cat']) && !empty($_REQUEST['cat'])) {
            $cat = ocws_numbers_list_to_array($_REQUEST['cat']);
        }
        $args = array(
            'post_id'		=> 0,
            's'				=> $search,
            'paged'			=> 1,
            'posts_per_page' => 20,
            'post_type'		=> array('product'),
        );
        if ($cat) {
            $tax_query = array(
                array(
                    'taxonomy' => 'product_cat',   // taxonomy name
                    'field' => 'term_id',           // term_id, slug or name
                    'terms' => $cat,                  // term id, term slug or term name
                )
            );
            $args['tax_query'] = $tax_query;
        }
        $posts = get_posts($args);

        $results = array();

        foreach ($posts as $p) {
            $post_name = $p->post_title;
            $results[] = array(
                'ID' => $p->ID,
                'title' => $post_name,
                'type' => $p->post_type
            );
        }

        // vars
        $response = array(
            'results'	=> $results,
            'limit'		=> $args['posts_per_page']
        );

        wp_send_json_success(
            array(
                'resp' => $response,
            )
        );
    }

    public static function save_delivery_settings_data_from_cart_form() {
        OCWS_Deli::save_delivery_settings_data_from_cart_form();
    }
}

OCWS_Deli_Ajax::init();
