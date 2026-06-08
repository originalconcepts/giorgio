<?php

use yidas\googleMaps\Client;
use yidas\googleMaps\Geocoding;

defined( 'ABSPATH' ) || exit;

class OC_Woo_Shipping_Polygon {

    public static function get_shipping_polygons() {

        $polygons = array();
        $data_store = new OC_Woo_Shipping_Group_Data_Store();
        $polygons_raw = $data_store->read_all_polygons();

        foreach ($polygons_raw as $l) {
            $polygon_data = '';
            $polygon_data = @unserialize($l->gm_shapes);

            if (false === $polygon_data) {
                $polygon_data = '';
            }
            if (is_array($polygon_data)) {
                $polygons[] = array(
                    'location_code' => $l->location_code,
                    'location_name' => $l->location_name,
                    'is_enabled' => $l->is_enabled,
                    'gm_shapes' => $polygon_data,
                );
            }
        }
        return $polygons;
    }

    public static function get_shipping_gm_cities() {

        $cities = array();
        $data_store = new OC_Woo_Shipping_Group_Data_Store();
        $cities_raw = $data_store->read_all_gm_cities();

        foreach ($cities_raw as $l) {

            $cities[] = array(
                'location_code' => $l->location_code,
                'location_name' => $l->location_name,
                'is_enabled' => $l->is_enabled,
            );
        }
        return $cities;
    }

    public static function is_inside_polygon($lat, $lng, $polygon) {
        return \GeometryLibrary\PolyUtil::containsLocation(['lat' => $lat, 'lng' => $lng], $polygon);
    }

    public static function get_address_coordinates($city, $street, $house_num) {
        $key = ocws_get_google_maps_api_key();
        if (!$key) return false;
        $gmaps = new \yidas\googleMaps\Client(['key'=>'Your API Key', 'language'=>get_locale()]);
        $geocodeResult = $gmaps->geocode($street.' '.$house_num.' '.$city.', Israel');
        //error_log(print_r($geocodeResult, 1));
        if (!isset($geocodeResult['geometry']) || !isset($geocodeResult['geometry']['location'])) {
            return false;
        }
        if (
            !is_array($geocodeResult['geometry']['location']) ||
            !isset($geocodeResult['geometry']['location']['lat']) ||
            !isset($geocodeResult['geometry']['location']['lng'])
        ) {
            return false;
        }
        return $geocodeResult['geometry']['location'];
    }

    public static function find_matching_polygon($lat, $lng) {
        $polygons = self::get_shipping_polygons();

        foreach ($polygons as $polygon) {
            if (!$polygon['is_enabled']) continue;
            $shapes = $polygon['gm_shapes'];
            if (isset($shapes['gm_shapes'])) {
                $shapes = $shapes['gm_shapes'];
            }
            foreach ($shapes as $shape) {
                if (self::is_shape_valid($shape)) {
                    if (self::is_inside_polygon($lat, $lng, $shape)) {
                        return $polygon['location_code'];
                    }
                }
            }
        }
        return false;
    }

    public static function find_matching_gm_city($city_id) {
        if ($city_id === null || $city_id === '') {
            return false;
        }
        $data_store = new OC_Woo_Shipping_Group_Data_Store();
        $direct = $data_store->find_enabled_city_by_place_id_or_code((string) $city_id);
        if ($direct) {
            return $direct;
        }
        $cities = self::get_shipping_gm_cities();
        foreach ($cities as $city) {
            if (!$city['is_enabled']) {
                continue;
            }
            if ((string) $city_id === (string) $city['location_code']) {
                return $city['location_code'];
            }
        }
        return false;
    }

    /**
     * מזהה place_id של locality מקואורדינטות (Geocoding reverse) — גיבוי כש-billing_city_code חסר ב-AJAX.
     *
     * @param string|float $lat
     * @param string|float $lng
     * @return string|false
     */
    public static function get_locality_place_id_from_coordinates( $lat, $lng ) {
        $key = ocws_get_google_maps_api_key();
        if ( ! $key ) {
            return false;
        }
        try {
            $client  = new Client( array( 'key' => $key, 'language' => get_locale() ) );
            $results = Geocoding::reverseGeocode(
                $client,
                array( $lat, $lng ),
                array( 'result_type' => 'locality' )
            );
            if ( isset( $results['error_message'] ) ) {
                return false;
            }
            if ( is_array( $results ) && ! empty( $results[0]['place_id'] ) ) {
                return $results[0]['place_id'];
            }
            $results2 = Geocoding::reverseGeocode( $client, array( $lat, $lng ), array() );
            if ( isset( $results2['error_message'] ) ) {
                return false;
            }
            if ( ! is_array( $results2 ) ) {
                return false;
            }
            foreach ( $results2 as $row ) {
                if ( ! empty( $row['place_id'] ) && ! empty( $row['types'] ) && in_array( 'locality', (array) $row['types'], true ) ) {
                    return $row['place_id'];
                }
            }
        } catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
            return false;
        }
        return false;
    }

    public static function is_shape_valid($shape) {
        if (!is_array($shape)) return false;
        foreach ($shape as $point) {
            if (!isset($point['lat']) || !isset($point['lng'])) {
                return false;
            }
        }
        return true;
    }

    public static function get_location_code_by_post_data($post_data) {
        //error_log('get_location_code_by_post_data-------------------------------------------------------');
        //error_log('Post data:');
        //error_log(print_r($post_data, 1));
        $location_code = 0;
        $address_coords = array();
        $gm_city_id = '';
        if (isset($post_data['billing_city_code'])) {
            $gm_city_id = ( $post_data[ 'billing_city_code' ] );
        }
        $location_code = OC_Woo_Shipping_Polygon::find_matching_gm_city($gm_city_id);

        if ($location_code) {
            return $location_code;
        }
        if (isset($post_data['billing_address_coords'])) {
            $coords = wc_clean( wp_unslash( $post_data[ 'billing_address_coords' ] ) );
            $coords = str_replace(array('(', ')', ' '), '', $coords);
            $coords = explode(',', $coords, 2);
            if (isset($coords[0]) && isset($coords[1])) {

                $address_coords['lat'] = $coords[0];
                $address_coords['lng'] = $coords[1];
            }

        }
        if ( count( $address_coords ) > 0 ) {
            $location_code = OC_Woo_Shipping_Polygon::find_matching_polygon( $address_coords['lat'], $address_coords['lng'] );
        }
        if ( ! $location_code && count( $address_coords ) > 0 && apply_filters( 'ocws_reverse_geocode_coords_for_city_match', true ) ) {
            $place_id = self::get_locality_place_id_from_coordinates( $address_coords['lat'], $address_coords['lng'] );
            if ( $place_id ) {
                $location_code = OC_Woo_Shipping_Polygon::find_matching_gm_city( $place_id );
            }
        }
        return $location_code;
    }

    public static function get_location_code_by_post_data_network($post_data) {
        global $wpdb;
        if (!is_multisite()) {
            return OC_Woo_Shipping_Polygon::get_location_code_by_post_data($post_data);
        }

        $location_code = OC_Woo_Shipping_Polygon::get_location_code_by_post_data($post_data);
        if ($location_code) {
            return $location_code;
        }

        $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );
        foreach ( $blog_ids as $blog_id ) {
            if ($blog_id == get_current_blog_id()) {
                continue;
            }
            switch_to_blog($blog_id);
            $location_code = OC_Woo_Shipping_Polygon::get_location_code_by_post_data($post_data);
            restore_current_blog();
            if ($location_code) {
                return $blog_id.':::'.$location_code;
            }
        }
        return false;
    }
}