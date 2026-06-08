<?php

defined( 'ABSPATH' ) || exit;

class OCWS_Shipping_Methods_Helper {

    protected $methods = array();

    public function __construct($enabled_only = true) {
        $this->methods = $this->ocws_get_all_shipping_methods($enabled_only);
    }

    public function ocws_get_all_shipping_methods($enabled_only=true) {

        $blog_id = 0;
        if (is_multisite() && $this->doing_ajax()) {
            $blog_id = $this->ajax_request_blog_id();
        }
        return $this->blog_shipping_methods($enabled_only, $blog_id);
    }

    protected function blog_shipping_methods($enabled_only=true, $blog_id=0) {

        $methods = array();
        if (!class_exists('WC_Shipping_Zones')) {
            return $methods;
        }
        if ($blog_id) {
            switch_to_blog( $blog_id );
        }
        try {
            /* @var WC_Shipping_Zone[] $shipping_zones*/
            $shipping_zones = $this->blog_shipping_zones();
            if ($shipping_zones && is_array($shipping_zones)) {
                foreach ($shipping_zones as $zone) {
                    $zone_shipping_methods = $zone->get_shipping_methods($enabled_only);
                    foreach ($zone_shipping_methods as $index => $method) {
                        $methods[] = $method->id;
                    }
                }
            }
        }
        catch (Exception $e) {
            //error_log($e->getMessage());
        }
        if ($blog_id) {
            restore_current_blog();
        }
        return $methods;
    }

    /**
     * @return WC_Shipping_Zone[]
     */
    protected function blog_shipping_zones($blog_id=0) {
        $zones = array();
        if (!class_exists('WC_Data_Store')) {
            return $zones;
        }
        if ($blog_id) {
            switch_to_blog( $blog_id );
        }
        try {
            $data_store = WC_Data_Store::load( 'shipping-zone' );
            $raw_zones = $data_store->get_zones();
            foreach ( $raw_zones as $raw_zone ) {
                $zones[] = new WC_Shipping_Zone( $raw_zone );
            }
            $zones[] = new WC_Shipping_Zone( 0 ); // ADD ZONE "0" MANUALLY
        }
        catch (Exception $e) {
            //error_log($e->getMessage());
        }
        if ($blog_id) {
            restore_current_blog();
        }
        return $zones;
    }

    public function ocws_shipping_method_enabled() {
        return in_array(OCWS_Advanced_Shipping::SHIPPING_METHOD_ID, $this->methods);
    }

    public function ocws_shipping_method_available() {

        $count = OC_Woo_Shipping_Groups::db_get_enabled_locations_count();
        return ($count > 0);
    }

    public function ocws_pickup_method_enabled() {
        return in_array(OCWS_LP_Local_Pickup::PICKUP_METHOD_ID, $this->methods);
    }

    public function ocws_pickup_method_available() {

        $count = OCWS_LP_Affiliates::db_get_enabled_affiliates_count();
        return ($count > 0);
    }

    public function log_methods() {
        error_log(is_admin()?'Is admin':'Is not admin');
        error_log(defined('DOING_AJAX')? 'Is AJAX' : 'Is not AJAX');
        $referer = wp_get_referer();
        error_log('Referer: '.$referer);
        if ($referer) {
            $parsed_referer = wp_parse_url(wp_get_referer());
            if ($parsed_referer) {
                error_log(print_r($parsed_referer, 1));
            }
        }
        error_log('Request:');
        error_log("//{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}");
        error_log(print_r($_REQUEST, 1));
        error_log('---------------------- Enabled shipping methods --------------------------');
        error_log(print_r($this->methods, 1));
    }

    protected function doing_ajax() {
        return defined('DOING_AJAX') && DOING_AJAX;
    }

    protected function ajax_request_blog_id() {
        $blog_id = 0;
        $referer = wp_get_referer();
        if ($referer) {
            $parsed_referer = wp_parse_url(wp_get_referer());
            if ($parsed_referer && isset($parsed_referer['host']) && isset($parsed_referer['path'])) {
                $blog_id = get_blog_id_from_url($parsed_referer['host'], $parsed_referer['path']);
                //error_log('Found blog ID: '.$blog_id);
            }
        }
        return $blog_id;
    }

    public static function ocws_shipping_missing_notice() {
        $class = 'notice notice-error';
        $message = __( 'Please add and activate Original Concepts WooCommerce Advanced Shipping method, otherwise the plugin would not work on frontend.', 'ocws' );

        printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }

    public static function ocws_pickup_missing_notice() {
        $class = 'notice notice-error';
        $message = __( 'Please add and activate Original Concepts WooCommerce Advanced Pickup method, otherwise the pickup would not work on frontend.', 'ocws' );

        printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }

    public static function ocws_shipping_not_available_notice() {
        $class = 'notice notice-error';
        $message = __( 'Please consider adding and activating shipping groups and locations to Original Concepts WooCommerce Advanced Shipping method, otherwise the plugin would not work on frontend.', 'ocws' );

        printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }

    public static function ocws_pickup_not_available_notice() {
        $class = 'notice notice-error';
        $message = __( 'Please consider adding and activating pickup locations to Original Concepts WooCommerce Advanced Pickup method, otherwise the pickup would not work on frontend.', 'ocws' );

        printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
    }
}