<?php


class OC_Woo_Advanced_Shipping_Method extends WC_Shipping_Method{

    const METHOD_ID = 'oc_woo_advanced_shipping_method';

    const NOTICE_TYPE = 'ocws_shipping_notice';

    public function __construct( $instance_id = 0 ) {
        $this->id = self::METHOD_ID;
        $this->instance_id = absint( $instance_id );
        $this->method_title = __( 'OC Advanced Shipping Method', 'ocws' );

        $this->supports = array(
            'shipping-zones',
            'instance-settings',
        );
        // Load the settings.
        $this->init_form_fields();
        $this->init_settings();
        // Define user set variables
        $this->enabled  = $this->get_option( 'enabled' );
        $this->title     = $this->get_option( 'title' );


        add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
    }

    public function init_form_fields() {

        $this->instance_form_fields = array(
            'enabled' => array(
                'title'     => __( 'Enable/Disable', 'ocws' ),
                'type'       => 'checkbox',
                'label'     => __( 'Enable OC Advanced Delivery Shipping', 'ocws' ),
                'default'     => 'yes'
            ),
            'title' => array(
                'title'     => __( 'Method Title', 'ocws' ),
                'type'       => 'text',
                'description'   => __( 'This controls the title which the user sees during checkout.', 'ocws' ),
                'default'    => __( 'Advanced Delivery Shipping', 'ocws' ),

            )
        );
    }

    public function is_available( $package ) {
        $is_available = (('yes' === $this->enabled) && ocws_enabled_shipping_locations_exist());
        if (!$is_available) {
            $this->clear_notices();
            return false;
        }
        $res = $this->is_applicable( $package );
        //error_log('blog id: '.get_current_blog_id().', is shipping available: '. ($res? 'true' : 'false'));
        return $res;
    }

    public function is_applicable( $package, &$errors = null ) {
        //error_log('inside is_applicable()');
        //error_log(print_r($package, 1));

        $add_validate_order_errors = false;
        if ($errors && ($errors instanceof WP_Error)) {
            $add_validate_order_errors = true;
        }

        $need_min_total_check = false;
        $passed_min_total = false;
        $passed_no_pickup_products = false;
        $passed_slots_available = false;

        $this->clear_notices();

        if (!isset($package['destination']) || !isset($package['destination']['city']) || !$package['destination']['city']) {

            if (!ocws_use_google_cities_and_polygons()) {

                $message = '<div class="show-shipping-block ocws-no-destination-city" style="display: none;"><span class="important-notice">'.esc_attr(__('Sorry, this shipping method is not available at your location', 'ocws')).'</span>';

                $billing_fields = WC()->checkout()->get_checkout_fields('billing');

                foreach ( $billing_fields as $key => $field ) {
                    if ($key == 'billing_city') {
                        $city2 = $field;
                        $city2['class'] = array('select', 'ocws-enhanced-select');
                        $city2['id'] = 'other_city';
                        $city2['name'] = 'other_city';
                        $city2['placeholder'] = __('choose city', 'ocws');
                        $message .= '<div class="show-shipping-location" style="">';
                        ob_start();
                        woocommerce_form_field('other_city', $city2, WC()->checkout()->get_value( $key ));
                        $message .= ob_get_clean();
                        $message .= '</div>';
                    }
                }

                $message .= '</div>';

                $this->add_notice( $message, 'permanent-notice' );
            }

            $this->add_notice( 'not_passed_city', 'permanent-hidden');
            if ($add_validate_order_errors) {
                $errors->add('shipping', __('Sorry, this shipping method is not available at your location', 'ocws'));
            }

            //$this->add_notice(sprintf(__('Please, choose a city to view %s options', 'ocws'), $this->title), 'notice');
            /*
             * Changing to true: the customer gets notices, but the shipping method remains active.
             * To revert to the previous behaviour : return false;
             * */
            return true;
        }

        $location_code = 0;
        $group_id = 0;
        $location_enabled = false;
        $group_enabled = false;

        global $wpdb;

        // TODO: multisite + using polygons and multisite + not using polygons
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
                    $loc_name = isset($package['destination']['city_name'])? $package['destination']['city_name'] : $package['destination']['city'];
                    $redirect_url = esc_url(ocws_convert_current_page_url($go_to_blog_id, ['ocws_from_store' => get_current_blog_id()]));
                    $message = '<div class="show-shipping-block ocws-go-to-blog" style="display: none;"><span class="important-notice">'.
                        esc_html(sprintf(__('To order shipping to %s', 'ocws'), $loc_name)).
                        '</span><br><a class="ocws-site-link" href="'.esc_url($redirect_url).'">'.esc_html(__('Click here >', 'ocws')).'</a></div>';
                    $this->add_notice( $message, 'permanent-notice' );
                    $this->add_notice( 'no_blog_location_available', 'permanent-hidden');
                    if ($add_validate_order_errors) {
                        $errors->add('shipping', '<span class="important-notice">'.
                            esc_html(sprintf(__('To order shipping to %s', 'ocws'), $loc_name)).
                            '</span> <a class="ocws-site-link" href="'.esc_url($redirect_url).'">'.esc_html(__('Click here >', 'ocws')).'</a>');
                    }
                    //error_log('no blog location available');
                    return true;
                }
            }
            if (ocws_use_google_cities_and_polygons()) {
                $location_code = self::get_location_code_if_polygon($package);
                if (!$location_code) {
                    $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );
                    foreach ( $blog_ids as $blog_id ) {
                        if ($blog_id == get_current_blog_id()) {
                            continue;
                        }
                        switch_to_blog($blog_id);
                        $location_code = self::get_location_code_if_polygon($package);
                        $blogdata = get_blog_details();
                        $blog_name = $blogdata->blogname;
                        restore_current_blog();
                        if ($location_code) {
                            $loc_name = isset($package['destination']['city_name'])? $package['destination']['city_name'] : $package['destination']['city'];
                            $redirect_url = esc_url(ocws_convert_current_page_url($blog_id, ['ocws_from_store' => get_current_blog_id()]));
                            $message = '<div class="show-shipping-block ocws-go-to-blog" style="display: none;"><span class="important-notice">'.
                                esc_html(sprintf(__('To order shipping to %s', 'ocws'), $loc_name)).
                                '</span><br><a class="ocws-site-link" href="'.esc_url($redirect_url).'">'.esc_html(__('Click here >', 'ocws')).'</a></div>';
                            $this->add_notice( $message, 'permanent-notice' );
                            $this->add_notice( 'other_blog_location_available', 'permanent-hidden');
                            if ($add_validate_order_errors) {
                                $errors->add('shipping', '<span class="important-notice">'.
                                    esc_html(sprintf(__('To order shipping to %s', 'ocws'), $loc_name)).
                                    '</span> <a class="ocws-site-link" href="'.esc_url($redirect_url).'">'.esc_html(__('Click here >', 'ocws')).'</a>');
                            }
                            //error_log('other blog location is available');
                            return true;
                        }
                    }
                }
                if (!$location_code) {
                    $this->add_notice( 'not_passed_city', 'permanent-hidden');
                    if ($add_validate_order_errors) {
                        $errors->add('shipping', __('Sorry, this shipping method is not available at your location', 'ocws'));
                    }
                    return true;
                }
            }
            else {
                $blog_locations = OC_Woo_Shipping_Groups::get_all_locations(true);
                $network_locations = OC_Woo_Shipping_Groups::get_all_locations_networkwide(true);
                /*if (count($blog_locations) == 0) {
                    if (count($network_locations) == 1) {
                        error_log('External shipping cities only');
                        $blog_name = reset($network_locations);
                        $blog_id = 0;
                        $bid = explode(':::', key($network_branches), 2);
                        if (isset($bid[1])) {
                            $blog_id = intval($bid[1]);
                        }
                        if (!$blog_id || !ocws_blog_exists($blog_id)) {
                            //return false;
                        }
                        $message = '<div class="show-shipping-block ocws-no-blog-location-available" style="display: none;"><span class="important-notice">'.
                            esc_html(sprintf(__('Shipping is available from %s only.', 'ocws'), $blog_name)).
                            '</span><br><a class="ocws-site-link" href="'.esc_url(get_blogaddress_by_id($blog_id)).'?ocws_from_store='.get_current_blog_id().'">'.esc_html(__('Go to the site.', 'ocws')).'</a></div>';
                        $this->add_notice( $message, 'permanent-notice' );
                        $this->add_notice( 'no_blog_location_available', 'permanent-hidden');
                        if ($add_validate_order_errors) {
                            $errors->add('shipping', __('Sorry, there are no available locations on this site', 'ocws'));
                        }
                        error_log('no blog location available');
                        return true;
                    }
                }*/
                $l_data = self::get_multisite_location_code( $package['destination']['city'] );
                $blog_id = $l_data[0];
                if ($blog_id == get_current_blog_id()) {
                    $package['destination']['city'] = $l_data[1];
                }
                else if (ocws_blog_exists($blog_id)) {
                    switch_to_blog($blog_id);
                    $blogdata = get_blog_details();
                    $blog_name = $blogdata->blogname;
                    restore_current_blog();

                    $loc_name = isset($package['destination']['city_name'])? $package['destination']['city_name'] : $package['destination']['city'];
                    $redirect_url = esc_url(ocws_convert_current_page_url($blog_id, ['ocws_from_store' => get_current_blog_id()]));
                    $message = '<div class="show-shipping-block ocws-go-to-blog" style="display: none;"><span class="important-notice">'.
                        esc_html(sprintf(__('To order shipping to %s', 'ocws'), $loc_name)).
                        '</span><br><a class="ocws-site-link" href="'.esc_url($redirect_url).'">'.esc_html(__('Click here >', 'ocws')).'</a></div>';
                    $this->add_notice( $message, 'permanent-notice' );
                    $this->add_notice( 'other_blog_location_available', 'permanent-hidden');
                    if ($add_validate_order_errors) {
                        $errors->add('shipping', '<span class="important-notice">'.
                            esc_html(sprintf(__('To order shipping to %s', 'ocws'), $loc_name)).
                            '</span> <a class="ocws-site-link" href="'.esc_url($redirect_url).'">'.esc_html(__('Click here >', 'ocws')).'</a>');
                    }
                    //error_log('other blog location is available');
                    return true;
                }

            }
        }


        $data_store = new OC_Woo_Shipping_Group_Data_Store();

        if (ocws_use_google_cities_and_polygons()) {

            /*if (isset($package['destination']['city_code'])) {
                $location_code = OC_Woo_Shipping_Polygon::find_matching_gm_city($package['destination']['city_code']);
            }
            if (!$location_code) {
                if (isset($package['destination']['address_coords']) && is_array($package['destination']['address_coords'])) {

                    $location_code = OC_Woo_Shipping_Polygon::find_matching_polygon(
                        $package['destination']['address_coords']['lat'], $package['destination']['address_coords']['lng']);
                }

                else if (
                    isset($package['destination']['street']) && $package['destination']['street'] &&
                    isset($package['destination']['house_num']) && $package['destination']['house_num']
                ) {
                    $coords = OC_Woo_Shipping_Polygon::get_address_coordinates(
                        $package['destination']['city'],
                        $package['destination']['street'],
                        $package['destination']['house_num']);
                    if (false !== $coords) {
                        $location_code = OC_Woo_Shipping_Polygon::find_matching_polygon($coords['lat'], $coords['lng']);
                    }
                }
            }*/
            $location_code = self::get_location_code_if_polygon($package);
        }
        else {
            $location_code = $package['destination']['city'];

        }

        if ($location_code) {
            $group_id = $data_store->get_group_by_location( $location_code );
            $location_enabled = $data_store->is_location_enabled( $location_code );
            $group_enabled = $data_store->is_group_enabled( $group_id );
        }

        if (null !== $group_id && null !== $location_enabled && $location_enabled && null !== $group_enabled && $group_enabled) {


            if (!$this->has_pickup_only_products( $package )) {

                $passed_no_pickup_products = true;
            }
            else {
                $message = OC_Woo_Shipping_Group_Option::get_option($group_id, 'pickup_only_message', '');
                $message = $message['option_value'];
                $message = get_option('ocws_common_pickup_only_message');
                if (empty($message)) {
                    $message = __( 'Sorry, your cart contains pickup only products', 'ocws' );
                }

                $this->add_notice( $message, 'permanent-notice' );
                if ($add_validate_order_errors) {
                    $errors->add('shipping', __('Sorry, your cart contains pickup only products', 'ocws'));
                }

                /*
                * Changing to true: the customer gets notices, but the shipping method remains active.
                * To revert to the previous behaviour : return false;
                * */
                return true;
            }

            //$data = OC_Woo_Shipping_Group_Option::get_option($group_id, 'min_total', false);
            $data = OC_Woo_Shipping_Group_Option::get_location_option($location_code, $group_id, 'min_total', false);

            if (!empty($data['option_value'])) {

                $need_min_total_check = true;

                $min_total_to_enable = floatval($data['option_value']);

                $total = WC()->cart->get_displayed_subtotal();

                if ( WC()->cart->display_prices_including_tax() ) {
                    $total = $total - WC()->cart->get_discount_tax();
                }
                $total = $total - WC()->cart->get_discount_total();
                $total = round( $total, wc_get_price_decimals() );

                if ( $total >= $min_total_to_enable ) {

                    $passed_min_total = true;

                    $message = OC_Woo_Shipping_Group_Option::get_option($group_id, 'min_total_message_yes', '');
                    $message = $message['option_value'];
                    if (!empty($message)) {
                        if (strstr( $message, '[X]')) {
                            $message = str_replace('[X]', $min_total_to_enable, $message);
                        }
                        $this->add_notice( $message, 'permanent-success' );
                    }
                }
                else {
                    $message = OC_Woo_Shipping_Group_Option::get_option($group_id, 'min_total_message_no', '');
                    $message = $message['option_value'];
                    if (empty($message)) {
                        $message = sprintf(__('Minimum order for shipping is %s NIS, you need %s NIS more', 'ocws'), $min_total_to_enable, ($min_total_to_enable - $total));
                    }
                    if (!empty($message)) {
                        if (strstr( $message, '[X]')) {
                            $message = str_replace('[X]', $min_total_to_enable, $message);
                        }
                        //get_shipping_package_address
                        $loc_title = ocws_use_google_cities_and_polygons()? $this->get_shipping_package_address($package) : ocws_get_city_title($location_code);

                        if (strstr( $message, '[Y]')) {
                            $message = str_replace('[Y]', $loc_title, $message);
                        }
                        $message_str = $message;
                        $message = '<div class="show-shipping-block"><span class="important-notice">'.$message.'</span>';

                        //$message .= '<br><a href="javascript:void(0)" class="notice-button show-shipping-location-button'.(ocws_use_google_cities_and_polygons()? '-polygon' : '').'">'.__('לשינוי עיר/יישוב לחץ כאן', 'ocws').'</a>';

                        if (!ocws_use_google_cities_and_polygons()) {

                            $billing_fields = WC()->checkout()->get_checkout_fields('billing');

                            foreach ( $billing_fields as $key => $field ) {
                                if ($key == 'billing_city') {
                                    $city2 = $field;
                                    $city2['class'] = array('select', 'ocws-enhanced-select');
                                    $city2['id'] = 'other_city';
                                    $city2['name'] = 'other_city';
                                    $message .= '<div class="show-shipping-location" style="display: none">';
                                    ob_start();
                                    woocommerce_form_field('other_city', $city2, WC()->checkout()->get_value( $key ));
                                    $message .= ob_get_clean();
                                    $message .= '</div>';
                                }
                            }
                        }
                        $message .= '</div>';

                        $this->add_notice( $message, 'permanent-notice' );
                        $this->add_notice( 'not_passed_min_total', 'permanent-hidden');
                        if ($add_validate_order_errors) {
                            $errors->add('shipping', $message_str);
                        }
                    }
                    /*
                     * Changing to true: the customer gets notices, but the shipping method remains active.
                     * To revert to the previous behaviour : return false;
                     * */
                    return true;
                }
            }

            $oc_slots = new OC_Woo_Shipping_Slots($location_code);
            $days = $oc_slots->calculate_slots_for_checkout();
            if (count($days) > 0) {

                $passed_slots_available = true;
            }
            else {

                $loc_title = ocws_use_google_cities_and_polygons()? $this->get_shipping_package_address($package) : ocws_get_city_title($location_code);

                $message = sprintf( __( 'Sorry, there are no available shipping dates for %s', 'ocws' ), $loc_title );

                $message_str = $message;

                $message = '<div class="show-shipping-block"><span class="important-notice">'.$message.'</span>';

                //$message .= '<br><a href="javascript:void(0)" class="notice-button show-shipping-location-button'.(ocws_use_google_cities_and_polygons()? '-polygon' : '').'">'.__('לשינוי עיר/יישוב לחץ כאן', 'ocws').'</a>';

                if (!ocws_use_google_cities_and_polygons()) {

                    $billing_fields = WC()->checkout()->get_checkout_fields('billing');

                    foreach ( $billing_fields as $key => $field ) {
                        if ($key == 'billing_city') {
                            $city2 = $field;
                            $city2['class'] = array('select', 'ocws-enhanced-select');
                            $city2['id'] = 'other_city';
                            $city2['name'] = 'other_city';
                            $message .= '<div class="show-shipping-location" style="display: none">';
                            ob_start();
                            woocommerce_form_field('other_city', $city2, WC()->checkout()->get_value( $key ));
                            $message .= ob_get_clean();
                            $message .= '</div>';
                        }
                    }
                }
                $message .= '</div>';

                $this->add_notice( $message, 'permanent-notice' );
                $this->add_notice( 'not_passed_slots_available', 'permanent-hidden');
                if ($add_validate_order_errors) {
                    $errors->add('shipping', $message_str);
                }
                /*
                 * Changing to true: the customer gets notices, but the shipping method remains active.
                 * To revert to the previous behaviour : return false;
                 * */
                return true;

            }



            return true;

        }



        $message = '<div class="show-shipping-block ocws-no-destination-city" style="display: none"><span class="important-notice">'.esc_attr(__('Sorry, this shipping method is not available at your location', 'ocws')).'</span>';

        //$message .= '<br><a href="javascript:void(0)" class="notice-button show-shipping-location-button'.(ocws_use_google_cities_and_polygons()? '-polygon' : '').'">'.__('לשינוי עיר/יישוב לחץ כאן', 'ocws').'</a>';

        if (!ocws_use_google_cities_and_polygons()) {

            $billing_fields = WC()->checkout()->get_checkout_fields('billing');

            foreach ( $billing_fields as $key => $field ) {
                if ($key == 'billing_city') {
                    $city2 = $field;
                    $city2['class'] = array('select', 'ocws-enhanced-select');
                    $city2['id'] = 'other_city';
                    $city2['name'] = 'other_city';
                    $city2['placeholder'] = __('choose city', 'ocws');
                    $message .= '<div class="show-shipping-location" style="">';
                    ob_start();
                    woocommerce_form_field('other_city', $city2, WC()->checkout()->get_value( $key ));
                    $message .= ob_get_clean();
                    $message .= '</div>';
                }
            }
        }
        $message .= '</div>';

        $this->add_notice( $message, 'permanent-notice' );
        $this->add_notice( 'not_passed_city', 'permanent-hidden');
        if ($add_validate_order_errors) {
            $errors->add('shipping', __('Sorry, this shipping method is not available at your location', 'ocws'));
        }

        //error_log('not passed city');
        /*
         * Changing to true: the customer gets notices, but the shipping method remains active.
         * To revert to the previous behaviour : return false;
         * */
        return true;
    }

    public function calculate_shipping( $package = array() ) {

        //error_log('blog id: '.get_current_blog_id().', calculate_shipping:');
        $default_price = get_option('ocws_default_shipping_price', 0);

        if (isset($package['destination']['address_coords']) && !is_null($package['destination']['address_coords'])) {
            $package['destination']['address_coords'] = maybe_unserialize($package['destination']['address_coords']);
        }

        $location_code = 0;

        if (ocws_use_google_cities_and_polygons()) {

            if (isset($package['destination']['city_code'])) {
                $location_code = OC_Woo_Shipping_Polygon::find_matching_gm_city($package['destination']['city_code']);
            }
            if (!$location_code) {
                if (isset($package['destination']['address_coords']) && is_array($package['destination']['address_coords'])) {

                    $location_code = OC_Woo_Shipping_Polygon::find_matching_polygon(
                        $package['destination']['address_coords']['lat'], $package['destination']['address_coords']['lng']);
                }

                else if (
                    isset($package['destination']['street']) && $package['destination']['street'] &&
                    isset($package['destination']['house_num']) && $package['destination']['house_num']
                ) {
                    $coords = OC_Woo_Shipping_Polygon::get_address_coordinates(
                        $package['destination']['city'],
                        $package['destination']['street'],
                        $package['destination']['house_num']);
                    if (false !== $coords) {
                        $location_code = OC_Woo_Shipping_Polygon::find_matching_polygon($coords['lat'], $coords['lng']);
                    }
                }
            }
        }
        else {
            $location_code = $package['destination']['city'];
        }

        if (!$location_code) {
            $message = sprintf( __( 'Please select a location to calculate shipping cost', 'ocws' ), $this->title );
            //$this->add_notice( $message, 'notice' );
            //error_log('blog id: '.get_current_blog_id().'calculate_shipping: no destination location');
            $this->add_rate( array(
                'id'    => $this->id . $this->instance_id,
                'label' => $this->title,
                'cost'  => $default_price,
            ) );
            return;
        }

        // is free shipping coupon applied
        try {
            foreach ( WC()->cart->get_applied_coupons() as $code ) {
                $coupon = new WC_Coupon( $code );
                if ( ! $coupon->get_free_shipping() ) {
                    continue;
                }
                // yes free shipping
                $this->add_rate( array(
                    'id'    => $this->id . $this->instance_id,
                    'label' => $this->title,
                    'cost'  => 0,
                ) );
                //error_log(print_r(array('coupon' => $code, 'cost' => 0), 1));
                return;
            }
        }
        catch (Exception $e) {
            //error_log($e->getMessage());
        }

        $total = WC()->cart->get_displayed_subtotal();

        if ( WC()->cart->display_prices_including_tax() ) {
            $total = $total - WC()->cart->get_discount_tax();
        }
        $total = $total - WC()->cart->get_discount_total();
        $total = round( $total, wc_get_price_decimals() );

        $data_store = new OC_Woo_Shipping_Group_Data_Store();
        $group_id = $data_store->get_group_by_location( $location_code );
        if (null !== $group_id) {

            $data = OC_Woo_Shipping_Group_Option::get_option($group_id, 'min_total_for_free_shipping', false);
            if (!empty($data['option_value'])) {
                $min_total = floatval($data['option_value']);


                //error_log(print_r(array('total' => $total, 'min_total' => $min_total), 1));
                if ( $total >= $min_total ) {
                    $this->add_rate( array(
                        'id'    => $this->id . $this->instance_id,
                        'label' => $this->title,
                        'cost'  => 0,
                    ) );
                    //error_log(print_r(array('total' => $total, 'min_total' => $min_total, 'cost' => 0), 1));
                    return;
                }
            }

            $price_depending = OC_Woo_Shipping_Group_Option::get_option($group_id, 'price_depending', '');
            if (!empty($price_depending['option_value']) && $price_schema = json_decode($price_depending['option_value'], true)) {
                if ($price_schema['active']) {
                    $price_rules = $price_schema['rules'];
                    $price_rules = array_filter($price_rules, function ($item) {
                        return $item['shipping_price'] != 0 || $item['cart_value'] != 0;
                    });
                    usort($price_rules, function ($a, $b) {
                        return $a['shipping_price'] - $b['shipping_price'];
                    });

                    foreach ($price_rules as $price_rule) {
                        if ($total >= $price_rule['cart_value']) {
                            $this->add_rate( array(
                                'id'    => $this->id . $this->instance_id,
                                'label' => $this->title,
                                'cost'  => $price_rule['shipping_price']
                            ) );
                            //error_log(print_r(array('total' => $total, 'rule_by' => 'group level', 'group_id' => $group_id, 'cost' => $price_rule['shipping_price']), 1));
                            return;
                        }
                    }
                }
            }

            $price_depending = OC_Woo_Shipping_Group_Option::get_location_option($location_code, $group_id, 'price_depending', '');
            if (!empty($price_depending['option_value']) && $price_schema = json_decode($price_depending['option_value'], true)) {
                if ($price_schema['active']) {
                    $price_rules = $price_schema['rules'];
                    $price_rules = array_filter($price_rules, function ($item) {
                        return $item['shipping_price'] != 0 || $item['cart_value'] != 0;
                    });
                    usort($price_rules, function ($a, $b) {
                        return $a['shipping_price'] - $b['shipping_price'];
                    });

                    foreach ($price_rules as $price_rule) {
                        if ($total >= $price_rule['cart_value']) {
                            $this->add_rate( array(
                                'id'    => $this->id . $this->instance_id,
                                'label' => $this->title,
                                'cost'  => $price_rule['shipping_price']
                            ) );
                            //error_log(print_r(array('total' => $total, 'rule_by' => 'city level', 'city_id' => $location_code, 'cost' => $price_rule['shipping_price']), 1));
                            return;
                        }
                    }
                }
            }


            $data = OC_Woo_Shipping_Group_Option::get_location_option($location_code, $group_id, 'shipping_price', 0);
            //error_log(print_r($data, 1));
            $opt_price = trim($data['option_value']);
            if ($opt_price != '') {
                $shipping_price = round( $opt_price, wc_get_price_decimals() );
                $this->add_rate( array(
                    'id'    => $this->id . $this->instance_id,
                    'label' => $this->title,
                    'cost'  => $shipping_price,
                ) );

                /*error_log(print_r(array(
                    'id'    => $this->id . $this->instance_id,
                    'label' => $this->title,
                    'cost'  => $shipping_price,
                ), 1));*/
            }
        }
        else {
            $message = sprintf( __( 'Please select a location to calculate shipping cost', 'ocws' ), $this->title );
            //$this->add_notice( $message, 'notice' );
            //error_log('blog id: '.get_current_blog_id().'calculate_shipping: no destination location');
            $this->add_rate( array(
                'id'    => $this->id . $this->instance_id,
                'label' => $this->title,
                'cost'  => $default_price,
            ) );
            return;
        }
    }

    /**
     * @param string $method
     * @return bool
     */
    public static function is_ocws($method) {
        return substr($method, 0, strlen(self::METHOD_ID)) == self::METHOD_ID;
    }

    public static function get_multisite_location_code($location_code) {
        if (!str_contains($location_code.'', ':::')) {
            return array('blog_id' => get_current_blog_id(), 'location_code' => $location_code);
        }
        $bid = explode(':::', $location_code, 2);
        if ($bid[0]) {
            $blog_id = intval($bid[0]);
            $loc_code = '';
            if (isset($bid[1])) {
                $loc_code = $bid[1];
            }
            return array('blog_id' => $blog_id, 'location_code' => $loc_code);
        }
        return array('blog_id' => 0, 'location_code' => $location_code);
    }

    public static function get_location_code_if_polygon($package) {

        if (isset($package['destination']['address_coords'])) {
            $package['destination']['address_coords'] = maybe_unserialize($package['destination']['address_coords']);
        }

        $location_code = false;
        if (isset($package['destination']['city_code'])) {
            $location_code = OC_Woo_Shipping_Polygon::find_matching_gm_city($package['destination']['city_code']);
        }
        if (!$location_code) {
            if (isset($package['destination']['address_coords']) && is_array($package['destination']['address_coords'])) {

                $location_code = OC_Woo_Shipping_Polygon::find_matching_polygon(
                    $package['destination']['address_coords']['lat'], $package['destination']['address_coords']['lng']);
            }

            else if (
                isset($package['destination']['street']) && $package['destination']['street'] &&
                isset($package['destination']['house_num']) && $package['destination']['house_num']
            ) {
                $coords = OC_Woo_Shipping_Polygon::get_address_coordinates(
                    $package['destination']['city'],
                    $package['destination']['street'],
                    $package['destination']['house_num']);
                if (false !== $coords) {
                    $location_code = OC_Woo_Shipping_Polygon::find_matching_polygon($coords['lat'], $coords['lng']);
                }
            }
        }
        return $location_code;
    }

    public function has_pickup_only_products( $package ) {
        //$pickup_products = array();

        foreach ($package['contents'] as $key => $item) {

            /* @var WC_Product $product */
            $product = $item['data'];
            $parent = $product->get_parent_id();
            $current = $product->get_id();
            $pkup = get_post_meta($parent == 0 ? $current : $parent, '_ocws_pickup_only', 'no');
            //if ($pkup == 'yes') {
            //    $pickup_products[] = $product->get_id();
            //}
            if ($pkup == 'yes') {
                return true;
            }
        }
        return false;
    }

    /**
     * Validates that there are no errors in the checkout.
     *
     * @param  array    $posted   An array of posted data.
     * @param  WP_Error $errors Validation errors.
     */
    public static function validate_order( $posted, $errors ) {

        //error_log('validate order errors:');
        //error_log(print_r($posted, 1));
        $packages = WC()->shipping->get_packages();
        if (!isset($posted['shipping_method'])) return;
        $chosen_methods = $posted['shipping_method'];

        if (!$errors) return;
        if (ocws_use_google_cities_and_polygons()) {
            $error_codes = $errors->get_error_codes();
            if (
                in_array('billing_city_required', $error_codes) ||
                in_array('billing_city_name_required', $error_codes) ||
                in_array('billing_street_required', $error_codes) ||
                in_array('billing_house_num_required', $error_codes)
            )
            {
                $errors->remove('billing_city_required');
                $errors->remove('billing_city_name_required');
                $errors->remove('billing_street_required');
                $errors->remove('billing_house_num_required');
                $errors->add('shipping', __('Please enter a shipping address', 'ocws'));
                return;
            }
        }

        if( is_array( $chosen_methods ) ) {

            foreach ( $packages as $i => $package ) {
                if ( !isset($chosen_methods[ $i ]) || !self::is_ocws($chosen_methods[ $i ]) ) {

                    //error_log('validate order errors:');
                    //error_log('$i: '. $i . ', chosen method: ' . $chosen_methods[ $i ]);
                    continue;

                }
                $shipping_method = new OC_Woo_Advanced_Shipping_Method();
                $shipping_method->is_applicable( $package, $errors );
                //error_log('validate order errors:');
                //error_log(print_r($errors, 1));

            }
        }
    }

    public function add_notice( $message, $notice_type ) {
        if (!OC_Woo_Shipping_Notices::has_notice( $message, $notice_type )) {
            OC_Woo_Shipping_Notices::add_notice( $message, $notice_type );
        }
        //error_log(print_r( WC()->session->get( 'ocws_notices', array() ), 1));
    }

    public function clear_notices() {

        OC_Woo_Shipping_Notices::clear_notices( true );
    }

    public function get_shipping_package_address($package) {
        $house_number = $package['destination']['house_num'] ?? '';
        $street = $package['destination']['street'] ?? '';
        $city = $package['destination']['city'] ?? '';
        $address = ($street? $street . ($house_number? ' ' . $house_number : '') : '');
        return ($city? ($address? $address . ' ' : '') . $city : '');
    }
}