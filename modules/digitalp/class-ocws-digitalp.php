<?php

defined( 'ABSPATH' ) || exit;

class OCWS_Digitalp {

    public static function init() {

        add_action( 'wp_enqueue_scripts', array(__CLASS__, 'enqueue_styles'), 10, 0 );
        add_action( 'wp_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'), 99, 0 );
        add_filter( 'ocws_cart_needs_shipping', array(__CLASS__, 'cart_needs_shipping') );
        add_filter( 'ocws_product_needs_shipping', array(__CLASS__, 'product_needs_shipping'), 10, 2 );
        add_filter( 'woocommerce_checkout_fields', array(__CLASS__, 'change_checkout_address_fields_if_digital'), 510 );
    }

    public static function enqueue_styles() {
        wp_enqueue_style( 'digitalp-public', OCWS_ASSESTS_URL . 'modules/digitalp/assets/css/digitalp-public.css', array(), null, 'all' );
    }

    public static function enqueue_scripts() {

        wp_enqueue_script( 'digitalp-public-js', OCWS_ASSESTS_URL . 'modules/digitalp/assets/js/digitalp-public.js', array( 'jquery' ), null, true );
        wp_localize_script( 'digitalp-public-js', 'ajax_digitalp', array( 'ajaxurl' => admin_url( 'admin-ajax.php' )));

        wp_enqueue_script( 'digitalp-public-js' );
    }

    public static function get_digital_products() {
        return ocws_numbers_list_to_array(get_option( 'ocws_common_digital_products_ids', '' ));
    }

    public static function is_product_digital( $product_id ) {
        if (!$product_id) return false;
        return in_array( $product_id, self::get_digital_products() );
    }

    public static function cart_needs_shipping( $needs_shipping ) {

        //error_log('--------------- cart_needs_shipping filter ------------');
        foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

            $product_id = $cart_item['product_id'];
            if (! self::is_product_digital( $product_id )) {
                return $needs_shipping;
            }
        }
        // digital only
        return false;
    }

    public static function product_needs_shipping($needs_shipping, $product_id) {
        return (! self::is_product_digital( $product_id ));
    }

    public static function change_checkout_address_fields_if_digital( $fields ) {

        if (self::cart_needs_shipping(true)) return $fields;

        $fields_to_rewrite = array(
            'billing_google_autocomplete',
            'billing_address_1',
            'billing_city',
            'billing_postcode',
            'billing_country',
            'billing_company',
            'billing_address_1',
            'billing_address_2',
            'billing_street',
            'billing_house_num',
            'billing_enter_code',
            'billing_floor',
            'billing_apartment'
        );
        foreach ($fields_to_rewrite as $addr_field) {

            if (isset($fields['billing'][$addr_field])) {

                $fields['billing'][$addr_field]['required'] = false;
                $fields['billing'][$addr_field]['class'] = array('ocws-hidden-form-field');

            }
        }
        return $fields;
    }
}