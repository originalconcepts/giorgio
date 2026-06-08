<?php

defined( 'ABSPATH' ) || exit;

class OCWS_Advanced_Shipping {

    const SHIPPING_METHOD_ID = 'oc_woo_advanced_shipping_method';

    const SHIPPING_METHOD_TAG = 'shipping';

    public static function init() {

        add_action( 'woocommerce_after_checkout_validation', array('OC_Woo_Shipping_Info', 'validate_checkout_posted_data'), 20, 2 );
        add_action( 'woocommerce_load_cart_from_session', array('OC_Woo_Shipping_Info', 'validate_session_data'), 20, 0 );
    }

    public static function get_all_locations_networkwide($enabled_only=false) {

        $use_simple_cities = !ocws_use_google_cities_and_polygons();
        $use_polygons = ocws_use_google_cities_and_polygons();
        $use_google_cities = ocws_use_google_cities();
        if (is_multisite()) {
            return OC_Woo_Shipping_Groups::get_all_locations_networkwide($enabled_only, $use_simple_cities, $use_polygons, $use_google_cities);
        }
        return OC_Woo_Shipping_Groups::get_all_locations($enabled_only, $use_simple_cities, $use_polygons, $use_google_cities);
    }

    public static function get_all_locations_blog($enabled_only=false) {

        $use_simple_cities = !ocws_use_google_cities_and_polygons();
        $use_polygons = ocws_use_google_cities_and_polygons();
        $use_google_cities = ocws_use_google_cities();
        return OC_Woo_Shipping_Groups::get_all_locations($enabled_only, $use_simple_cities, $use_polygons, $use_google_cities);
    }
}