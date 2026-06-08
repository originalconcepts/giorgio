<?php

defined( 'ABSPATH' ) || exit;

require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-ocws-wc-session-handler.php';

class OCWS_Cart_Syncronizer {

    public static function init() {
        if (! is_multisite()) return;
        add_filter( 'woocommerce_add_cart_item_data', array(__CLASS__, 'woocommerce_add_cart_item_data_filter'), 20, 4 );
        add_action( 'woocommerce_cart_loaded_from_session', array(__CLASS__, 'cart_loaded_from_session'), 10, 1 );
        add_action( 'woocommerce_load_cart_from_session', array(__CLASS__, 'maybe_populate_cart_from_other_blog'), 30, 0 );
        add_action( 'woocommerce_init', array(__CLASS__, 'ocws_before_form_billing'), 100, 0 );
        add_action( 'init', array(__CLASS__, 'maybe_redirect_to_right_blog'), 10, 0 );
        add_action( 'ocws_maybe_fix_shipping_method', array(__CLASS__, 'maybe_fix_shipping_method'), 10, 0 );
    }

    public static function maybe_fix_shipping_method() {
        if (!isset(WC()->session)) return;
        if (!isset($_GET['ocws_from_store']) || ! ocws_blog_exists($_GET['ocws_from_store'])) {
            return;
        }
        $current_chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
        $sync_chosen_methods = WC()->session->get( 'sync_chosen_shipping_methods', array() );
        $current_chosen_method = $sync_chosen_method = '';
        if (is_array($current_chosen_methods) && isset($current_chosen_methods[0])) {
            $current_chosen_method = $current_chosen_methods[0];
        }
        if (is_array($sync_chosen_methods) && isset($sync_chosen_methods[0])) {
            $sync_chosen_method = $sync_chosen_methods[0];
        }
        if ($sync_chosen_method && $sync_chosen_method !== $current_chosen_method) {
            WC()->session->set( 'chosen_shipping_methods', $sync_chosen_methods );
        }
    }

    public static function woocommerce_add_cart_item_data_filter( $cart_item_data, $product_id, $variation_id, $quantity ){

        if (!isset($cart_item_data['product_sku']) || !isset($cart_item_data['variation_sku'])) {

            if ($product_id) {
                $p = wc_get_product($product_id);
                if ($p) {
                    $product_name = $p->get_name();
                    $variation_name = '';
                    $product_sku = $p->get_sku();
                    $variation_sku = '';
                    if ($variation_id) {
                        $v = wc_get_product($variation_id);
                        if ($v) {
                            $variation_name = $v->get_name();
                            $variation_sku = $v->get_sku();
                        }
                    }
                    $cart_item_data['product_name'] = $product_name;
                    $cart_item_data['variation_name'] = $variation_name;
                    $cart_item_data['product_sku'] = $product_sku;
                    $cart_item_data['variation_sku'] = $variation_sku;
                }
            }
        }

        if (isset($cart_item_data['posted_data'])) {
            return $cart_item_data;
        }

        if (isset($_POST) && is_array($_POST) && !empty($_POST)) {
            $cart_item_data['posted_data'] = $_POST;
        }
        else {
            $cart_item_data['posted_data'] = array();
        }

        return $cart_item_data;
    }

    public static function maybe_redirect_to_right_blog() {

        if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            return;
        }
        if (!isset(WC()->session)) return;
        if (isset($_GET['ocws_from_store']) && ocws_blog_exists($_GET['ocws_from_store'])) {
            return;
        }
        /* Cookie data */
        if (isset($_COOKIE['myblog'])) {
            $cookie_blog_id = intval($_COOKIE['myblog']);
            //error_log('========================= myblog cookie ============================');
            //error_log('cookie_blog_id: '.$cookie_blog_id);
            if ($cookie_blog_id && ($cookie_blog_id !== get_current_blog_id())) {
                $url = ocws_convert_current_page_url($cookie_blog_id);
                error_log('url: '.$url);
                if ($url) {
                    wp_redirect($url);
                    exit;
                }
            }
        }
    }

    public static function maybe_populate_cart_from_other_blog() {
        if (!isset(WC()->session)) return;
        if (!isset($_GET['ocws_from_store']) || ! ocws_blog_exists($_GET['ocws_from_store'])) {
            return;
        }
        $referrer_store = (int)$_GET['ocws_from_store'];
        $deleted_items_names = array();

        $session_handler = new OCWS_WC_Session_Handler();
        $s_d = (array) $session_handler->get_session_for_blog_id( $referrer_store, array() );
        error_log('----------------------------------- session from the previous blog ----------------------------------');
        error_log(print_r($s_d, 1));
        $cart = (isset($s_d['cart'])? maybe_unserialize($s_d['cart']) : null);

        $checkout_data = (isset($s_d['checkout_data'])? maybe_unserialize($s_d['checkout_data']) : array());
        WC()->session->get_session_data();

        WC()->session->set( 'cart', null );
        WC()->session->set( 'cart_totals', null );
        WC()->session->set( 'applied_coupons', null );
        WC()->session->set( 'coupon_discount_totals', null );
        WC()->session->set( 'coupon_discount_tax_totals', null );
        WC()->session->set( 'removed_cart_contents', null );
        WC()->session->set( 'order_awaiting_payment', null );
        $destination_cart_items = array();
        if (!is_null($cart) && is_array($cart)) {
            foreach ( $cart as $key => $values ) {
                $prod_name = isset($values['product_name'])? $values['product_name'] : '';
                $var_name = isset($values['variation_name'])? $values['variation_name'] : '';
                $name_to_refer = $var_name? $var_name : ($prod_name? $prod_name : '');
                $prod_sku = isset($values['product_sku'])? $values['product_sku'] : '';
                $var_sku = isset($values['variation_sku'])? $values['variation_sku'] : '';
                // error_log('Product SKU: '.$prod_sku);
                if (! $prod_sku) {
                    if ($name_to_refer) {
                        $deleted_items_names[] = $name_to_refer;
                    }
                    continue;
                }
                $product_id = self::get_product_id_by_sku( $prod_sku . '' );
                // error_log('Product ID: '.$product_id);
                $variation_id = 0;
                if ($var_sku) {
                    $variation_id = self::get_product_id_by_sku( $var_sku );
                }
                if (!$variation_id && !$product_id) {
                    if ($name_to_refer) {
                        $deleted_items_names[] = $name_to_refer;
                    }
                    continue;
                }
                $product = wc_get_product( $variation_id ? $variation_id : $product_id );

                if ( empty( $product ) || ! $product->exists() || 0 >= $values['quantity'] ) {
                    if ($name_to_refer) {
                        //$deleted_items_names[] = $name_to_refer;
                    }
                    continue;
                }

                if ($product && $product->is_type('variation')) {
                    $product_id = $product->get_parent_id();
                }

                $values['product_id'] = $product_id;
                $values['variation_id'] = $variation_id;
                $values['data'] = $product;
                if (isset($values['posted_data'])) {
                    unset($values['posted_data']);
                }
                if ( ! $product->is_in_stock() || ! $product->has_enough_stock( $values['quantity'] )) {
                    if ($name_to_refer) {
                        //$deleted_items_names[] = $name_to_refer;
                    }
                    continue;
                }
                if ( ! apply_filters( 'woocommerce_cart_item_is_purchasable', $product->is_purchasable(), $key, $values, $product ) ) {
                    if ($name_to_refer) {
                        //$deleted_items_names[] = $name_to_refer;
                    }
                    continue;
                }
                if ( ! apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $values['quantity'], $variation_id, $values['variation'], $values ) ) {
                    if ($name_to_refer) {
                        //$deleted_items_names[] = $name_to_refer;
                    }
                    continue;
                }
                // Add to cart directly.
                $cart_id  = WC()->cart->generate_cart_id( $product_id, $variation_id, $values['variation'], $values );

                $destination_cart_items[ $cart_id ] = apply_filters(
                    'woocommerce_add_cart_item',
                    array_merge(
                        $values,
                        array(
                            'key'          => $cart_id,
                            'product_id'   => $product_id,
                            'variation_id' => $variation_id,
                            'variation'    => $values['variation'],
                            'quantity'     => $values['quantity'],
                            'data'         => $values['data'],
                            'data_hash'    => wc_get_cart_item_data_hash( $product ),
                        )
                    ),
                    $cart_id
                );
            }
            // error_log('------------------- $destination_cart_items ---------------------------');
            // error_log(print_r($destination_cart_items, 1));
            $cart_session = array();

            foreach ( $destination_cart_items as $key => $values ) {
                $cart_session[ $key ] = $values;
                unset( $cart_session[ $key ]['data'] ); // Unset product object.
            }

            WC()->session->set( 'cart', $cart_session );
            //WC()->cart->calculate_totals();

            if (count($deleted_items_names)>0) {
                foreach ($deleted_items_names as $key => $n) {
                    $deleted_items_names[$key] = '<span class="ocws-deleted-product">'.esc_html($n).'</span>';
                }
                $str = implode(', ', $deleted_items_names);
                add_action( 'wp_footer', function(){
                    ?>
                    <div id="shipping-redirect-result-dialog" title="" style="display: none;">
                        <p class="cds-dialog-title"><?php echo (__('The following products were not added to the cart', 'ocws')); ?></p>
                        <p class="cds-dialog-text"></p>
                    </div>
                    <?php
                } );

                $script = "
                (function( $ ) {
                    'use strict';

                    $(function() {

                        var deletedItems = '".$str."';
                        showRedirectResultDialog(deletedItems);
                        function showRedirectResultDialog(deletedItems) {
                            var dialog = $('#shipping-redirect-result-dialog');
                            var textElem = dialog.find('.cds-dialog-text');
                            textElem.html(deletedItems);
                            if (dialog.length) {
                                dialog.dialog({
                                    resizable: false,
                                    height: 'auto',
                                    width: 500,
                                    modal: false,
                                    buttons: [
                                        {
                                            text: ocws.localize.understood,
                                            click: function() {
                                                $( this ).dialog( 'close' );
                                            }
                                        }
                                    ]
                                });
                            }
                        }

                    });

                })( jQuery );
                ";

                wc_enqueue_js($script);
            }
        }
        if (is_array($checkout_data)) {
            //WC()->session->set( 'checkout_data', $checkout_data );
        }
    }

    public static function cart_loaded_from_session($cart) {

        if (!isset(WC()->session)) return;
        if (!isset($_GET['ocws_from_store']) || ! ocws_blog_exists($_GET['ocws_from_store'])) {
            return;
        }
        /*$cart_session = array();

        foreach ( $cart->get_cart() as $key => $values ) {
            $cart_session[ $key ] = $values;
            unset( $cart_session[ $key ]['data'] );
        }
        WC()->session->set( 'cart', $cart_session );*/
        $cart->calculate_totals();
    }

    public static function ocws_before_form_billing() {
        if (!isset(WC()->session)) return;
        if (!isset($_GET['ocws_from_store']) || ! ocws_blog_exists($_GET['ocws_from_store'])) {
            return;
        }
        setcookie('myblog', get_current_blog_id(), time() + 60 * 60 * 24 * 90, '/', COOKIE_DOMAIN, is_ssl(), false);
        //error_log('-------------------------------------- ocws_before_form_billing ------------------------------------------------');
        self::empty_sync_session_data();
        $chosen_methods = array();
        $chosen_city = '';
        $chosen_branch = '';
        $chosen_polygon = array();
        $checkout_data = array();

        /* Cookie data */
        if (isset($_COOKIE['ocws'])) {
            $cookie_data = json_decode(wp_unslash($_COOKIE['ocws']), true);
            //error_log('-------------------------------------- cookie ------------------------------------------------');
            //error_log($_COOKIE['ocws']);
            //error_log(print_r($cookie_data, 1));
            if (null !== $cookie_data) {
                if (isset($cookie_data['method']) && in_array($cookie_data['method'], [OCWS_LP_Local_Pickup::PICKUP_METHOD_ID, OCWS_Advanced_Shipping::SHIPPING_METHOD_ID])) {
                    $chosen_methods = array($cookie_data['method']);
                }
                if (isset($cookie_data['city']) && !empty($cookie_data['city'])) {
                    $chosen_city = $cookie_data['city'];
                }
                if (isset($cookie_data['branch']) && !empty($cookie_data['branch'])) {
                    $chosen_branch = $cookie_data['branch'];
                }
                if (isset($cookie_data['polygon']) && !empty($cookie_data['polygon'])) {
                    if (
                        isset($cookie_data['polygon']['coords']) && !empty($cookie_data['polygon']['coords']) &&
                        isset($cookie_data['polygon']['street']) && !empty($cookie_data['polygon']['street']) &&
                        isset($cookie_data['polygon']['house_num']) && !empty($cookie_data['polygon']['house_num']) &&
                        isset($cookie_data['polygon']['city_name']) && !empty($cookie_data['polygon']['city_name']) &&
                        isset($cookie_data['polygon']['city_code']) && !empty($cookie_data['polygon']['city_code'])
                    ) {


                        $location_code = OC_Woo_Shipping_Polygon::find_matching_gm_city($cookie_data['polygon']['city_code']);

                        if (!$location_code) {

                            $coords = wc_clean( wp_unslash( $cookie_data['polygon']['coords'] ) );
                            $coords = str_replace(array('(', ')', ' '), '', $coords);
                            $coords = explode(',', $coords, 2);
                            if (isset($coords[0]) && isset($coords[1])) {
                                $address_coords = array();
                                $address_coords['lat'] = $coords[0];
                                $address_coords['lng'] = $coords[1];
                                $location_code = OC_Woo_Shipping_Polygon::find_matching_polygon(
                                    $address_coords['lat'], $address_coords['lng']);
                            }
                        }
                        if ($location_code) {
                            $chosen_polygon = array(
                                'coords' => $cookie_data['polygon']['coords'],
                                'street' => $cookie_data['polygon']['street'],
                                'house_num' => $cookie_data['polygon']['house_num'],
                                'city_name' => $cookie_data['polygon']['city_name'],
                                'city_code' => $cookie_data['polygon']['city_code']
                            );
                        }
                    }
                }
            }
        }
        if (is_array($chosen_methods) && isset($chosen_methods[0])) {

            $pickup = (substr($chosen_methods[0], 0, strlen(OCWS_LP_Local_Pickup::PICKUP_METHOD_ID)) === OCWS_LP_Local_Pickup::PICKUP_METHOD_ID);
            $shipping = (substr($chosen_methods[0], 0, strlen(OCWS_Advanced_Shipping::SHIPPING_METHOD_ID)) === OCWS_Advanced_Shipping::SHIPPING_METHOD_ID);

            $shipping_zones = WC_Shipping_Zones::get_zones();
            $method_set = false;
            if ($shipping_zones && is_array($shipping_zones)) {
                foreach ($shipping_zones as $shipping_zone) {
                    $shipping_methods = $shipping_zone['shipping_methods'];
                    foreach ($shipping_methods as $shipping_method) {
                        if ( !isset( $shipping_method->enabled ) || 'yes' !== $shipping_method->enabled ) {
                            continue; // not available
                        }
                        if ($shipping_method->id == OCWS_LP_Local_Pickup::PICKUP_METHOD_ID && $pickup) {
                            WC()->session->set( 'chosen_shipping_methods', [$shipping_method->id . $shipping_method->instance_id] );
                            WC()->session->set( 'sync_chosen_shipping_methods', [$shipping_method->id . $shipping_method->instance_id] );
                            //error_log('Set session shipping method: '.$shipping_method->id . $shipping_method->instance_id);
                            if ($chosen_branch) {

                                $chosen_branch_id = 0;
                                if (str_contains($chosen_branch.'', ':::')) {
                                    //error_log('code 1');
                                    $bid = explode(':::', $chosen_branch, 2);
                                    $blog_id = intval($bid[0]);
                                    $branch_id = isset($bid[1])? intval($bid[1]) : 0;
                                    //error_log('code 2: '.$branch_id);
                                    if ($blog_id == get_current_blog_id() && $branch_id) {
                                        $chosen_branch_id = $branch_id;
                                    }
                                }
                                /*$checkout_data = WC()->session->get( 'checkout_data' );
                                if ( !$checkout_data || ! is_array( $checkout_data )) {
                                    $checkout_data = array();
                                    $checkout_data['ocws_lp_pickup_aff_id'] = $chosen_branch_id;
                                }
                                WC()->session->set( 'checkout_data', $checkout_data );*/
                                //error_log('code 3: '.$chosen_branch_id);
                                WC()->session->set('chosen_pickup_aff', $chosen_branch_id );
                                $checkout_data['ocws_lp_pickup_aff_id'] = $chosen_branch_id;
                                WC()->session->set('checkout_data', $checkout_data );
                                WC()->session->save_data();
                                //error_log('-------------------------- syncronizer - saved to session ----------------------------');
                                //error_log(print_r(WC()->session->get_session_data(), 1));
                            }
                            $method_set = true;
                            break;
                        }
                        else if ($shipping_method->id == OCWS_Advanced_Shipping::SHIPPING_METHOD_ID && $shipping) {
                            WC()->session->set( 'chosen_shipping_methods', [$shipping_method->id . $shipping_method->instance_id] );
                            WC()->session->set( 'sync_chosen_shipping_methods', [$shipping_method->id . $shipping_method->instance_id] );
                            //error_log('Set session shipping method: '.$shipping_method->id . $shipping_method->instance_id);
                            if ($chosen_city) {

                                $chosen_billing_city = 0;
                                if (str_contains($chosen_city, ':::')) {
                                    //error_log('code 1');
                                    $bid = explode(':::', $chosen_city, 2);
                                    $blog_id = intval($bid[0]);
                                    $billing_city = isset($bid[1])? $bid[1] : '';
                                    //error_log('code 2: '.$billing_city);
                                    if ($blog_id == get_current_blog_id() && $billing_city) {
                                        $chosen_billing_city = $billing_city;
                                    }
                                }
                                /*$checkout_data = WC()->session->get( 'checkout_data' );
                                if ( !$checkout_data || ! is_array( $checkout_data )) {
                                    $checkout_data = array();
                                    $checkout_data['billing_city'] = $chosen_billing_city;
                                }
                                WC()->session->set( 'checkout_data', $checkout_data );*/

                                WC()->session->set('chosen_city_code', $chosen_billing_city );
                                $checkout_data['billing_city_code'] = $chosen_billing_city;
                                WC()->session->set('chosen_shipping_city', $chosen_billing_city );
                                $city_title = function_exists('ocws_get_city_title') ? ocws_get_city_title($chosen_billing_city) : '';
                                if ($city_title) {
                                    WC()->session->set('chosen_city_name', $city_title);
                                    $checkout_data['billing_city_name'] = $city_title;
                                    $checkout_data['billing_city'] = $city_title;
                                    WC()->customer->set_billing_city($city_title);
                                } else {
                                    $checkout_data['billing_city'] = $chosen_billing_city;
                                    WC()->customer->set_billing_city($chosen_billing_city);
                                }
                                WC()->customer->set_shipping_city($chosen_billing_city);
                                WC()->session->set('checkout_data', $checkout_data );

                                WC()->session->save_data();

                                //error_log('Set billing_city: '.$chosen_billing_city);

                            }
                            else if (!empty($chosen_polygon)) {
                                WC()->session->set('chosen_address_coords', $chosen_polygon['coords'] );
                                $checkout_data['billing_address_coords'] = $chosen_polygon['coords'];
                                WC()->session->set('chosen_street', $chosen_polygon['street'] );
                                $checkout_data['billing_street'] = $chosen_polygon['street'];
                                WC()->session->set('chosen_house_num', $chosen_polygon['house_num'] );
                                $checkout_data['billing_house_num'] = $chosen_polygon['house_num'];
                                WC()->session->set('chosen_city_name', $chosen_polygon['city_name'] );
                                $checkout_data['billing_city_name'] = $chosen_polygon['city_name'];
                                WC()->session->set('chosen_city_code', $chosen_polygon['city_code'] );
                                $checkout_data['billing_city_code'] = $chosen_polygon['city_code'];
                                WC()->session->set('checkout_data', $checkout_data );

                                WC()->session->save_data();
                            }
                            $method_set = true;
                            break;
                        }
                    }
                    if ($method_set) {
                        if (isset(WC()->cart)) {
                            ocws_checkout_update_refresh_shipping_methods();
                        }
                        break;
                    }
                }
            }
        }
    }

    public static function empty_sync_session_data() {

        WC()->session->set('chosen_address_coords', null );
        WC()->session->set('chosen_street', null );
        WC()->session->set('chosen_house_num', null );
        WC()->session->set('chosen_city_name', null );
        WC()->session->set('chosen_city_code', null );
        WC()->session->set('chosen_shipping_city', null );
        WC()->session->set('chosen_pickup_aff', null );
        WC()->session->set('chosen_shipping_methods', null );
        WC()->session->set('sync_chosen_shipping_methods', null );
        WC()->session->set('checkout_data', null );
    }

    public static function get_product_id_by_sku( $sku ) {
        global $wpdb;

        $id = $wpdb->get_var(
            $wpdb->prepare(
                "
				SELECT posts.ID
				FROM {$wpdb->posts} as posts
				INNER JOIN {$wpdb->postmeta} AS postmeta ON posts.ID = postmeta.post_id
				WHERE
				posts.post_type IN ( 'product', 'product_variation' )
				AND posts.post_status != 'trash'
				AND postmeta.meta_key = '_sku'
				AND postmeta.meta_value = %s
				LIMIT 1
				",
                $sku
            )
        );

        return (int) apply_filters( 'woocommerce_get_product_id_by_sku', $id, $sku );
    }
}