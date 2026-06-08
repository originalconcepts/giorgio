<?php

use Carbon\Carbon;

defined( 'ABSPATH' ) || exit;

class OCWS_Deli {

    public static $menus;

    public static $removed_cart_items = array();

    public static function init() {
        add_action( 'init', array( __CLASS__, 'register_taxonomies' ) );
        //add_action( 'ocws_deli_header', array( __CLASS__, 'ocws_render_header_delivery_settings'), 10, 0 );
        /* we can not add it to the widget template due to cache issues */
        //add_action( 'woocommerce_before_mini_cart', array( __CLASS__, 'render_mini_cart_delivery_settings'), 10, 0 );
        add_action( 'ocws_deli_before_mini_cart', array( __CLASS__, 'render_mini_cart_delivery_settings'), 10, 0 );
        add_action( 'ocws_deli_header_mini_cart', array( __CLASS__, 'show_chip_in_cart_icon'), 10, 0 );

        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ), 10, 0 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_scripts' ), 10, 0);

        //add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'calculate_product_availability' ), 10, 2 );
        //add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'calculate_product_availability' ), 10, 2 );

        add_action ( 'woocommerce_shop_loop', array( __CLASS__, 'init_current_product_in_loop'), 10, 0 );
        add_action( 'woocommerce_before_shop_loop_item', array( __CLASS__, 'manage_before_loop_item_add_to_cart_actions' ), 1, 0 );
        add_action( 'woocommerce_after_shop_loop_item', array( __CLASS__, 'manage_after_loop_item_add_to_cart_actions' ), 1, 40 );

        add_filter( 'woocommerce_product_data_store_cpt_get_products_query', array( __CLASS__, 'handle_custom_query_var' ), 10, 2 );

        add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'unavailable_cart_items_conditionally' ), 10, 1 );
        add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'validate_all_cart_contents' ), 10, 0 );
        //add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'validate_cart_order_review' ), 10, 1 );

        add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'filter_wc_add_to_cart_validation' ), 30, 3 );

        add_action( 'woocommerce_product_query', array( __CLASS__, 'limit_displayed_products' ) );
        add_action( 'pre_get_posts', array( __CLASS__, 'limit_displayed_posts' ) );

        add_filter( 'woocommerce_cart_redirect_after_error', array( __CLASS__, 'woocommerce_cart_redirect_after_error_filter' ), 30, 2 );

        add_filter( 'woocommerce_add_to_cart_fragments', array( __CLASS__, 'woocommerce_add_to_cart_fragments_filter' ), 10, 1);

        add_action( 'woocommerce_before_mini_cart', array( __CLASS__, 'show_chip_in_empty_cart' ), 10, 0 );
        add_action( 'oc_woo_minicart_bottom_before', array( __CLASS__, 'show_chip_in_not_empty_cart' ), 30, 0 );

        add_action( 'wp_footer', array( __CLASS__, 'show_dialog' ), 10, 0 );

        add_filter( 'body_class', function( $classes ) {
            $add_classes = array('ocws-deli-style');
            if (ocws_deli_style_checkout() && is_checkout()) {
                $add_classes[] = 'ocws-deli-style-checkout';
            }
            return array_merge( $classes, $add_classes );
        } );

        add_filter( 'woocommerce_post_class', function( $classes, $product ) {

            if (!$product) {
                return $classes;
            }
            if ( ! self::calculate_product_availability( $product->is_purchasable(), $product) ) {
                $classes[] = 'ocws-product-not-available';
            }
            return $classes;
        }, 10, 2 );

        add_action( 'ocws_delivery_data_deli_style', array( __CLASS__, 'show_chip_in_checkout'), 10, 0 );
        //add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'show_send_to_other_person_fields'), 5, 1 );

        add_filter( 'woocommerce_cart_needs_shipping_address', function( $needs ) {
            return false;
        } );

        add_filter( 'woocommerce_product_is_visible', function( $visible, $product_id ) {
            $data = self::get_current_delivery_data();
            $date = $data['delivery_date'];
            $menus = OCWS_Deli_Menus::instance();
            if ($product_id) {
                if ( !$menus->is_product_visible_on_date( $product_id, $date, $data['delivery_slot_start'] ) ) {
                    return false;
                }
            }
            return $visible;
        }, 30, 2 );

        add_action( 'woocommerce_before_add_to_cart_form', function() {
            global $product;
            if (! $product) {
                return;
            }
            $product_menus_message = '';
            $class = '';
            if ( !$product->is_purchasable() ) {
                $product_menus_message = __('Product not available', 'ocws');
                $class = 'ocws-not-available';
            }
            else if ( ! self::calculate_product_availability( $product->is_purchasable(), $product) ) {
                $menus = OCWS_Deli_Menus::instance();
                $product_dates = $menus->find_product_dates($product->get_id());
                $product_menus_message = self::generate_product_menus_message($product_dates['weekdays'], $product_dates['dates'], $product_dates['prep_days']);
                $class = 'ocws-not-available';
            }
            ?>

            <div class="ocws-availability-message <?php echo esc_attr($class) ?>">
                <?php //var_dump($product_dates, $product_purchasable); ?>
                <?php echo esc_html($product_menus_message); ?>
            </div>
            <?php
        } );

        /* $products = apply_filters('ocws_deli_available_products', $products); */
        add_filter( 'ocws_deli_available_products', array('OCWS_Deli', 'filter_available_products'), 10, 1);

    }

    public static function filter_available_products( $products ) {

        if (!class_exists('WC_Product')) {
            return array();
        }
        if (!is_array($products)) {
            $products = array($products);
        }
        $prods = array();
        foreach ($products as $item) {
            if ( is_numeric( $item ) ) {
                $item = wc_get_product( $item );
            }
            if ( ! is_a( $item, 'WC_Product' ) || ! $item->is_purchasable() ) {
                continue;
            }
            if ( self::calculate_product_availability( true, $item ) ) {
                $prods[] = $item;
            }
        }
        return $prods;
    }

    public static function enqueue_styles() {
        wp_enqueue_style( 'owl.carousel.css', OCWS_ASSESTS_URL . 'modules/deli/assets/lib/owl/assets/owl.carousel.css', array(), '1' );
        wp_enqueue_style( 'deli-public', OCWS_ASSESTS_URL . 'modules/deli/assets/css/deli-public.css', array(), null, 'all' );
        wp_enqueue_style('jquery-ui-dialog');
    }

    public static function enqueue_scripts() {
        wp_enqueue_script('owl.carousel.min', OCWS_ASSESTS_URL . 'modules/deli/assets/lib/owl/owl.carousel.min.js', 'jquery', '', false);

        $woo_ajax_url = '';
        $woo_wc_ajax_url = '';
        try {
            $woo_ajax_url = WC()->ajax_url();
            $woo_wc_ajax_url = WC_AJAX::get_endpoint( '%%endpoint%%' );
        }
        catch (Exception $e) {}

        wp_enqueue_script( 'deli-public-js', OCWS_ASSESTS_URL . 'modules/deli/assets/js/deli-public.js', array( 'jquery' ), null, true );
        wp_localize_script( 'deli-public-js', 'deli_public',
            array(
                'ajaxurl' => admin_url( 'admin-ajax.php' ),
                'localize' => array(
                    'loading' => '', //__('Loading', 'ocws'),
                    'back_to_cart' => _x('Back to cart', 'Mini-cart delivery settings popup', 'ocws'),
                    'back_to_checkout' => _x('Back to checkout', 'Mini-cart delivery settings popup', 'ocws'),
                    'continue_to_change' => _x('Continue to change', 'Mini-cart delivery settings popup', 'ocws')
                ),
                'woo_ajax_url'    => $woo_ajax_url,
                'woo_wc_ajax_url' => $woo_wc_ajax_url,
            ));
        wp_enqueue_script('jquery-ui-dialog');
        //wp_enqueue_script( 'wc-cart-fragments' );

        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_script( 'jquery-datepicker-he', OCWS_ASSESTS_URL . 'js/datepicker-he.js', array( 'jquery', 'jquery-ui-datepicker' ), null, false );
        wp_enqueue_style('jqueryui', 'https://ajax.googleapis.com/ajax/libs/jqueryui/1.11.4/themes/smoothness/jquery-ui.css', false, null);

        $maps_api_key = ocws_get_google_maps_api_key();
        if (ocws_use_google_cities_and_polygons()) {

            wp_register_script('ocws-google-maps-api', 'https://maps.googleapis.com/maps/api/js?key='.$maps_api_key.'&libraries=drawing,geometry,places&language=' . get_locale(), null, null, true);
            wp_enqueue_script( 'google-maps-init-deli-js', OCWS_ASSESTS_URL . 'modules/deli/assets/js/google-maps-init-deli.js', array( 'jquery', 'ocws-google-maps-api' ), null, true );
        }

    }

    public static function woocommerce_cart_redirect_after_error_filter( $url, $prod_id ) {
        return "javascript:deliDisplayCartWidget('add_to_cart_error', ".$prod_id.")";
    }

    /**
     * @param WP_Query $q
     */
    public static function limit_displayed_products( $q ) {

        if ( is_admin() /*|| !$q->is_search() || !$q->is_main_query()*/ ) {
            return;
        }

        $data = self::get_current_delivery_data();
        $tax_query = $q->get( 'tax_query' );
        $menus = OCWS_Deli_Menus::instance();
        $is_holiday = false;

        if ( empty($data['delivery_date']) ) {
            $visible_menus = $menus->find_visible_menus_on_empty_date();
        } else {
            $visible_menus = $menus->find_menus_visible_on_date($data['delivery_date'], $data['delivery_slot_start']);
            if ($menus->is_date_holiday($data['delivery_date'])) {
                $is_holiday = true;
            }
        }
        if ( $is_holiday ) {

            if ( ! empty($visible_menus) ) {
                $tax_query[] = array(
                    'taxonomy' => 'product_menu',
                    'field'    => 'term_id',
                    'terms'    => $visible_menus,
                    'operator' => 'IN',
                );
            }

        } else {

            if ( ! empty($visible_menus) ) {
                $tax_query[] = array(
                    'relation' => 'OR',
                    array(
                        'taxonomy' => 'product_menu',
                        'field'    => 'term_id',
                        'operator' => 'NOT EXISTS',
                    ),
                    array(
                        'taxonomy' => 'product_menu',
                        'field'    => 'term_id',
                        'terms'    => $visible_menus,
                        'operator' => 'IN',
                    )
                );
            } else {
                $tax_query[] = array(
                    'taxonomy' => 'product_menu',
                    'field'    => 'term_id',
                    'operator' => 'NOT EXISTS',
                );
            }
        }

        $q->set( 'tax_query', $tax_query );
    }

    /**
     * @param WP_Query $q
     */
    public static function limit_displayed_posts( $q ) {

        if ( is_admin() || $q->is_main_query() ) {
            return;
        }

        // only modify queries for 'product' post type
        if( isset($q->query_vars['post_type']) && $q->query_vars['post_type'] == 'product' ) {

            $data = self::get_current_delivery_data();
            $tax_query = $q->get( 'tax_query' );
            if (! is_array( $tax_query )) {
                $tax_query = array(
                    'relation' => 'AND',
                );
            }
            //error_log(print_r($tax_query, 1));
            $menus = OCWS_Deli_Menus::instance();
            $is_holiday = false;

            if ( empty($data['delivery_date']) ) {
                $visible_menus = $menus->find_visible_menus_on_empty_date();
            } else {
                $visible_menus = $menus->find_menus_visible_on_date($data['delivery_date'], $data['delivery_slot_start']);
                if ($menus->is_date_holiday($data['delivery_date'])) {
                    $is_holiday = true;
                }
            }
            if ( $is_holiday ) {

                if ( ! empty($visible_menus) ) {
                    $tax_query[] = array(
                        'taxonomy' => 'product_menu',
                        'field'    => 'term_id',
                        'terms'    => $visible_menus,
                        'operator' => 'IN',
                    );
                }

            } else {

                if ( ! empty($visible_menus) ) {
                    $tax_query[] = array(
                        'relation' => 'OR',
                        array(
                            'taxonomy' => 'product_menu',
                            'field'    => 'term_id',
                            'operator' => 'NOT EXISTS',
                        ),
                        array(
                            'taxonomy' => 'product_menu',
                            'field'    => 'term_id',
                            'terms'    => $visible_menus,
                            'operator' => 'IN',
                        )
                    );
                } else {
                    $tax_query[] = array(
                        'taxonomy' => 'product_menu',
                        'field'    => 'term_id',
                        'operator' => 'NOT EXISTS',
                    );
                }
            }
            $q->set( 'tax_query', $tax_query );
        }


    }

    public static function needs_shipping() {
        $pending_product_id = 0;
        if (isset(WC()->session)) {
            $pending_product_id = WC()->session->get('deli_add_to_cart_pending_product');
        }
        if ($pending_product_id) {
            return apply_filters('ocws_cart_needs_shipping', true) || apply_filters('ocws_product_needs_shipping', true, $pending_product_id);
        }
        else {
            return apply_filters('ocws_cart_needs_shipping', true);
        }
    }

    public static function filter_wc_add_to_cart_validation( $passed, $product_id, $quantity ) {

        // error_log('filter_wc_add_to_cart_validation: ' + $product_id);
        if ( class_exists( 'OCWS_Digitalp' ) && OCWS_Digitalp::is_product_digital($product_id) ) {
            // error_log('digital');
            return $passed;
        }
        // error_log('not digital');
        $data = self::get_current_delivery_data();
        //error_log(print_r($data, 1));
        if (empty($data['delivery_date']) || /*empty($data['delivery_location_code']) || */empty($data['chosen_shipping_method'])) {

            // error_log('Please, choose delivery date you prefer. Product: '. $product_id);
            if (isset(WC()->session)) {
                $product_id = absint( $product_id );
                $product = wc_get_product( $product_id );
                if ( is_a( $product, 'WC_Product_Variable' ) && isset($_POST['variation_id']) && !empty($_POST['variation_id']) ) {
                    WC()->session->set( 'deli_add_to_cart_pending_variation', $_POST['variation_id'] );
                    WC()->session->set( 'deli_add_to_cart_pending_product', $product_id );
                }
                else if ($product->get_type() == 'variation') {
                    WC()->session->set( 'deli_add_to_cart_pending_variation', $product_id );
                    WC()->session->set( 'deli_add_to_cart_pending_product', $product->get_parent_id() );
                }
                else {
                    WC()->session->set( 'deli_add_to_cart_pending_variation', '' );
                    WC()->session->set( 'deli_add_to_cart_pending_product', $product_id );
                }
                WC()->session->set( 'deli_add_to_cart_pending_quantity', $quantity );
                WC()->session->set( 'deli_add_to_cart_pending_product_post', $_POST );
                // error_log('Product: '. $product_id);
            }
            else {
                // error_log('no wc session');
            }
            wc_add_notice( __( 'Please, choose delivery date you prefer', 'ocws' ), 'error', array('hidden'=>true) );
            return false;
        }
        $menus = OCWS_Deli_Menus::instance();
        if ( ! $menus->is_product_available_on_date( $product_id, $data['delivery_date'], $data['delivery_slot_start'] ) ) {
            $product = wc_get_product( $product_id );
            wc_add_notice( sprintf(__( 'The product %s does not available on %s', 'ocws' ), $product->get_title(), $data['delivery_date']), 'error', array('hidden' => true) );
            if (isset(WC()->session)) {
                WC()->session->set( 'deli_add_to_cart_error_product', $product_id );
            }
            return false;
        }
        return $passed;
    }

    /**
     * @param WC_Cart $cart
     *
     * @return void
     */
    public static function unavailable_cart_items_conditionally( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        $data = self::get_current_delivery_data();
        $cart_items  = $cart->get_cart();

        if ( empty($data['delivery_date']) ) {

            foreach ( $cart_items as $cart_item_key => &$cart_item ) {
                if (isset($cart_item['ocws_deli_not_available'])) {
                    $cart_item['ocws_deli_not_available'] = '';
                }
            }
        } else {
            foreach ( $cart_items as $cart_item_key => &$cart_item ) {
                $product_id = $cart_item['product_id'];
                if ( ! isset($cart_item['ocws_deli_not_available']) ) {
                    $cart_item['ocws_deli_not_available'] = '';
                }
                $menus = OCWS_Deli_Menus::instance();
                if ( ! $menus->is_product_available_on_date( $product_id, $data['delivery_date'], $data['delivery_slot_start'] ) ) {
                    $cart_item['ocws_deli_not_available'] = 'yes';
                }
            }
        }
    }

    public static function validate_all_cart_contents() {

        $not_available_items = 0;
        $data = self::get_checkout_delivery_data();
        if (!empty( $data['delivery_date'] )) {
            foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

                $product_id = $cart_item['product_id'];
                $menus = OCWS_Deli_Menus::instance();
                if ( ! $menus->is_product_available_on_date( $product_id, $data['delivery_date'], $data['delivery_slot_start'] ) ) {
                    $not_available_items++;
                }
            }
        }

        if ($not_available_items) {
            if ($not_available_items == 1) {
                wc_add_notice( __( 'Hi there! Looks like your cart contains product that is not available on chosen date', 'ocws' ), 'error' );
            }
            else {
                wc_add_notice( sprintf( __('Hi there! Looks like your cart contains %s products that is not available on chosen date', 'ocws'), $not_available_items ), 'error' );
            }

        }
    }

    public static function validate_cart_order_review( $post_data ) {

        $not_available_items = 0;
        self::save_delivery_settings_data_from_checkout_form();
        $data = self::get_checkout_delivery_data();
        if (!empty( $data['delivery_date'] )) {
            foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {

                $product_id = $cart_item['product_id'];
                $menus = OCWS_Deli_Menus::instance();
                if ( ! $menus->is_product_available_on_date( $product_id, $data['delivery_date'], $data['delivery_slot_start'] ) ) {
                    $not_available_items++;
                }
            }
        }

        if ($not_available_items) {
            if ($not_available_items == 1) {
                //wc_add_notice( __( 'Hi there! Looks like your cart contains product that is not available on chosen date', 'ocws' ), 'success' );
            }
            else {
                //wc_add_notice( sprintf( __('Hi there! Looks like your cart contains %s products that is not available on chosen date', 'ocws'), $not_available_items ), 'success' );
            }

        }
    }

    public static function remove_unavailable_from_cart() {

        // error_log('remove_unavailable_from_cart()');
        $data = self::get_current_delivery_data();
        $cart_items = WC()->cart->get_cart();
        $menus = OCWS_Deli_Menus::instance();

        self::empty_saved_removed_cart_items();

        if ( empty($data['delivery_date']) ) {

            foreach ( $cart_items as $cart_item_key => $cart_item ) {
                WC()->cart->remove_cart_item( $cart_item_key );
                self::save_removed_cart_item( $cart_item_key );
            }
        } else {
            foreach ( $cart_items as $cart_item_key => $cart_item ) {
                $product_id = $cart_item['product_id'];
                // error_log('Is product available on '. $data['delivery_date'] .' ? Product ID: '.$product_id . ': '.($menus->is_product_available_on_date( $product_id, $data['delivery_date'], $data['delivery_slot_start'] )? 'true' : 'false'));

                if ( ! $menus->is_product_available_on_date( $product_id, $data['delivery_date'], $data['delivery_slot_start'] ) ) {
                    WC()->cart->remove_cart_item( $cart_item_key );
                    self::save_removed_cart_item( $cart_item_key );
                }
            }
        }
    }

    public static function save_removed_cart_item( $cart_item_key ) {
        if (!isset(WC()->session)) return;
        $removed = WC()->session->get( 'ocws_deli_removed_cart_contents', array() );
        if (!is_array($removed)) {
            $removed = array();
        }
        if ( isset( WC()->cart->removed_cart_contents[ $cart_item_key ] ) ) {
            $removed[ $cart_item_key ] = WC()->cart->removed_cart_contents[ $cart_item_key ];
        }
        WC()->session->set( 'ocws_deli_removed_cart_contents', $removed );
    }

    public static function get_removed_cart_items() {
        if (!isset(WC()->session)) return array();
        $removed = WC()->session->get( 'ocws_deli_removed_cart_contents', array() );

        if (!is_array($removed)) {
            $removed = array();
        }
        return $removed;
    }

    public static function empty_saved_removed_cart_items() {
        if (isset(WC()->session)) {
            WC()->session->set( 'ocws_deli_removed_cart_contents', null );
        }
    }

    public static function output_removed_cart_contents() {
        $removed_items = self::get_removed_cart_items();
        // error_log('-------------------get_removed_cart_items--------------------');
        // error_log(print_r($removed_items, 1));
        if (count($removed_items) == 0) return;

        if (isset(WC()->session) && !defined( 'DOING_AJAX' )) {
            $displayed = WC()->session->get( 'removed_cart_contents_displayed', false );
            if ($displayed) {
                //if (!defined( 'DOING_AJAX' )) {
                    WC()->session->set( 'removed_cart_contents_displayed', null );
                    self::empty_saved_removed_cart_items();
                    return;
                //}
            }
            WC()->session->set( 'removed_cart_contents_displayed', true );
        }
        ?>
        <div class="ocws-removed-items-chip">
        <div class="buttons-here"><div class="buttons"></div></div>
        <div class="title"><?php echo  esc_html(__('Products removed from the cart', 'ocws')); ?></div>
        <div class="sub-title"><?php echo  esc_html(__('These products are not available for delivery on the selected date.', 'ocws')); ?></div>
        <ul class="ocws-minicart-removed-items">
        <?php
        foreach ( $removed_items as $cart_item_key => $item ) {

            $product = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
            ?>
            <li>
            <a href="<?php echo esc_url( $product->get_permalink() ); ?>" rel="prev">
                <span class="ocws-item-title"><?php echo wp_kses_post( $product->get_name() ); ?></span>
            </a>
            </li>
            <?php
        }
        ?>
        </ul></div>
        <?php

    }

    /**
     * @param boolean $purchasable
     * @param WC_Product $product
     *
     * @return boolean
     */
    public static function calculate_product_availability( $purchasable, $product ) {
        $data = self::get_current_delivery_data();
        $date = $data['delivery_date'];
        $menus = OCWS_Deli_Menus::instance();
        if ($product && !empty($date)) {
            if ( !$menus->is_product_available_on_date( $product->get_id(), $date, $data['delivery_slot_start'] ) ) {
                return false;
            }
        }
        return $purchasable;
    }

    public static function get_current_delivery_data() {

        $data = array(
            'chosen_shipping_method' => '',
            'delivery_type' => '',
            'delivery_date' => '',
            'delivery_slot_start' => '',
            'delivery_slot_end' => '',
            'delivery_location_code' => '',
            'delivery_location_text' => '',
            'delivery_type_text' => ''
        );
        $chosen_shipping = '';
        if (isset(WC()->session)) {
            //error_log(print_r(WC()->session->get_customer_unique_id(), 1));
            $chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
            //error_log('------------------- catching chosen shipping from session ---------------------');
            //error_log(print_r($chosen_methods, 1));
            $chosen_shipping = isset($chosen_methods[0])? $chosen_methods[0] : '';
            //error_log(print_r($chosen_shipping, 1));
        }

        $data['chosen_shipping_method'] = $chosen_shipping;
        if ($chosen_shipping) {
            if ( ocws_is_method_id_shipping($chosen_shipping) ) {
                $slot = self::get_selected_shipping_slot();
                $data['delivery_date'] = $slot['date'];
                $data['delivery_slot_start'] = $slot['slot_start'];
                $data['delivery_slot_end'] = $slot['slot_end'];
                $data['delivery_type'] = 'shipping';
                $data['delivery_type_text'] = _x('shipping', 'Mini Cart Chosen Shipping Text', 'ocws');
                $data['delivery_location_code'] = self::get_chosen_location_code();
            }
            if ( ocws_is_method_id_pickup($chosen_shipping) ) {
                $slot = self::get_selected_pickup_slot();
                $data['delivery_date'] = $slot['date'];
                $data['delivery_slot_start'] = $slot['slot_start'];
                $data['delivery_slot_end'] = $slot['slot_end'];
                $data['delivery_type'] = 'pickup';
                $data['delivery_type_text'] = _x('pickup', 'Mini Cart Chosen Shipping Text', 'ocws');
                $data['delivery_location_code'] = self::get_chosen_pickup_branch();
            }
        }
        //error_log(print_r($data, 1));
        return $data;
    }

    public static function get_checkout_delivery_data() {

        if (! is_checkout()) {
            //return self::get_current_delivery_data();
        }
        $data = array(
            'chosen_shipping_method' => '',
            'delivery_type' => '',
            'delivery_date' => '',
            'delivery_slot_start' => '',
            'delivery_slot_end' => '',
            'delivery_location_code' => '',
            'delivery_location_text' => '',
            'delivery_type_text' => ''
        );
        $chosen_shipping = '';
        if (isset(WC()->session)) {
            $chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
            $chosen_shipping = isset($chosen_methods[0])? $chosen_methods[0] : '';
        }

        $data['chosen_shipping_method'] = $chosen_shipping;
        if ($chosen_shipping) {
            if ( ocws_is_method_id_shipping($chosen_shipping) ) {
                $using_polygons = ocws_use_google_cities_and_polygons();
                $fields = WC()->checkout()->get_checkout_fields('ocws');
                $post_data = self::get_posted_data();

                foreach ($fields as $key => $field) {
                    if ($key == 'order_expedition_date' || $key == 'order_expedition_slot_start' || $key == 'order_expedition_slot_end') {

                        $value = ( ocws_get_value($key, $post_data) ?: (isset($field['default']) ? $field['default'] : '') );
                        if ($key == 'order_expedition_date') {
                            $data['delivery_date'] = $value;
                        }
                        else if ($key == 'order_expedition_slot_start') {
                            $data['delivery_slot_start'] = $value;
                        }
                        else {
                            $data['delivery_slot_end'] = $value;
                        }
                    }
                }
                $data['delivery_type'] = 'shipping';
                $data['delivery_type_text'] = _x('shipping', 'Mini Cart Chosen Shipping Text', 'ocws');
                $data['delivery_location_code'] = ($using_polygons? WC()->checkout()->get_value('billing_city_code') : WC()->checkout()->get_value('billing_city'));
                $street = WC()->checkout()->get_value('billing_street');
                $house_num = WC()->checkout()->get_value('billing_house_num');
                $city_name = WC()->checkout()->get_value('billing_city_name');
                if ($city_name) {
                    if ($street && $house_num) {
                        $data['delivery_location_text'] = sprintf('%s %s, %s', $street, $house_num, $city_name);
                    }
                    else {
                        $data['delivery_location_text'] = $city_name;
                    }
                }
            }
            else if ( ocws_is_method_id_pickup($chosen_shipping) ) {

                $fields = WC()->checkout()->get_checkout_fields('ocws_lp');
                $post_data = self::get_posted_data();
                foreach ($fields as $key => $field) {
                    if ($key == 'ocws_lp_pickup_date' || $key == 'ocws_lp_pickup_slot_start' || $key == 'ocws_lp_pickup_slot_end') {

                        $value = ( ocws_get_value($key, $post_data) ?: (isset($field['default']) ? $field['default'] : '') );
                        if ($key == 'ocws_lp_pickup_date') {
                            $data['delivery_date'] = $value;
                        }
                        else if ($key == 'ocws_lp_pickup_slot_start') {
                            $data['delivery_slot_start'] = $value;
                        }
                        else {
                            $data['delivery_slot_end'] = $value;
                        }
                    }
                }
                $data['delivery_type'] = 'pickup';
                $data['delivery_type_text'] = _x('pickup', 'Mini Cart Chosen Shipping Text', 'ocws');
                $data['delivery_location_code'] = WC()->checkout()->get_value('ocws_lp_pickup_aff_id');
                $aff_name = '';
                if ($data['delivery_location_code']) {
                    $affs_ds = new OCWS_LP_Affiliates();
                    $aff_name = $affs_ds->get_affiliate_name(intval($data['delivery_location_code']));
                }
                $data['delivery_location_text'] = $aff_name;
            }
        }
        return $data;
    }

    public static function woocommerce_add_to_cart_fragments_filter( $fragments ) {

        ob_start();
        self::render_mini_cart_delivery_settings();
        $mini_cart = ob_get_clean();
        $fragments['div.cart-delivery-settings'] = $mini_cart;
        ob_start();
        self::show_min_total_notice(false);
        $notice = ob_get_clean();
        $fragments['div.ocws-cart-shipping-notes'] = $notice;
        return $fragments;
    }

    public static function show_dialog() {
        ?>
        <div id="dialog" title="" style="display: none;">
            <h3><?php echo esc_html(_x('Who ate my dish?', 'Mini-cart dialog title', 'ocws')) ?></h3>
            <p class="cds-dialog-title"><?php echo esc_html(_x('Please note, if you change the date, some of the dishes you added may not be available for the new date.', 'Mini-cart dialog sub title', 'ocws')) ?></p>
            <p class="cds-dialog-text"><?php echo esc_html(_x('Are you sure you want to continue?', 'Mini-cart dialog text', 'ocws')) ?></p>
        </div>
        <?php
    }

    public static function render_mini_cart_delivery_settings() {

        $needs_shipping = self::needs_shipping();
        if (is_checkout()) {
            //return;
        }
        $post_data = self::get_posted_data();
        $items_count = WC()->cart->get_cart_contents_count();
        $data = self::get_delivery_methods_data();
        $delivery_data = self::get_current_delivery_data();
        $show_delivery_form = ($delivery_data['delivery_type'] != 'pickup' && empty($delivery_data['delivery_date']) && $needs_shipping);
        $show_shipping_options = ($delivery_data['delivery_type'] == 'shipping');
        $show_pickup_options = ($delivery_data['delivery_type'] == 'pickup');
        $shipping_popup_description = get_option('ocws_common_shipping_popup_description');

        $shipping_location_code = ($delivery_data['delivery_type'] == 'pickup'? '' : $delivery_data['delivery_location_code']);
        $pickup_branch_id = ($delivery_data['delivery_type'] == 'shipping'? '' : $delivery_data['delivery_location_code']);
        $selected_shipping_date = ($delivery_data['delivery_type'] == 'pickup'? '' : $delivery_data['delivery_date']);
        $selected_shipping_slot_start = ($delivery_data['delivery_type'] == 'pickup'? '' : $delivery_data['delivery_slot_start']);
        $selected_shipping_slot_end = ($delivery_data['delivery_type'] == 'pickup'? '' : $delivery_data['delivery_slot_end']);
        $selected_pickup_date = ($delivery_data['delivery_type'] == 'shipping'? '' : $delivery_data['delivery_date']);
        $selected_pickup_slot_start = ($delivery_data['delivery_type'] == 'shipping'? '' : $delivery_data['delivery_slot_start']);
        $selected_pickup_slot_end = ($delivery_data['delivery_type'] == 'shipping'? '' : $delivery_data['delivery_slot_end']);

        $chosen_aff_id = $pickup_branch_id;

        if (count($data['pickup_branches']) <= 1) {
            foreach ($data['pickup_branches'] as $key => $val) {
                $chosen_aff_id = $key;
                break;
            }
        }

        ?>
        <div class="mini-cart-panel--container cart-delivery-settings <?php echo ($show_delivery_form? '' : 'cds-hidden') ?>" style="height:100%">
            <div class="title-block">
                <div class="title">
                    <span class="delivery-settings-top-span">
                        <a href="javascript:void(0)" class="change-delivery-method <?php echo (empty($delivery_data['delivery_type'])? 'cds-hidden' : '') ?>">
                            <?php echo esc_html(_x('Change delivery method', 'Button in mini-cart', 'ocws')); ?>
                        </a>
                        <!--<a href="javascript:void(0)" class="back-to-cart <?php /*echo (!empty($delivery_data['delivery_type'])? 'cds-hidden' : '') */?>">
                            <?php /*echo esc_html(_x('Back to cart', 'Button in mini-cart', 'ocws')); */?>
                        </a>-->
                    </span>
                </div>
                <button class="cds-mini-close"><svg xmlns="http://www.w3.org/2000/svg" class="Icon Icon--close" role="presentation" viewBox="0 0 16 14">
                        <path d="M15 0L1 14m14 0L1 0" stroke="currentColor" fill="none" fill-rule="evenodd"></path>
                    </svg></button>
            </div>
        <form id="cart-delivery-settings-form" class="">
            <div style="display: none" id="hidden-test-data"><?php //var_dump($delivery_data); ?></div>

            <input type="hidden" id="order_expedition_date" name="order_expedition_date" value="<?php echo esc_attr($selected_shipping_date) ?>">
            <input type="hidden" id="order_expedition_slot_start" name="order_expedition_slot_start" value="<?php echo esc_attr($selected_shipping_slot_start) ?>">
            <input type="hidden" id="order_expedition_slot_end" name="order_expedition_slot_end" value="<?php echo esc_attr($selected_shipping_slot_end) ?>">
            <input type="hidden" id="ocws_lp_pickup_date" name="ocws_lp_pickup_date" value="<?php echo esc_attr($selected_pickup_date) ?>">
            <input type="hidden" id="ocws_lp_pickup_slot_start" name="ocws_lp_pickup_slot_start" value="<?php echo esc_attr($selected_pickup_slot_start) ?>">
            <input type="hidden" id="ocws_lp_pickup_slot_end" name="ocws_lp_pickup_slot_end" value="<?php echo esc_attr($selected_pickup_slot_end) ?>">
            <input type="hidden" name="selected-city-name" value="<?php echo esc_attr(isset($data['shipping_locations'][$shipping_location_code])? $data['shipping_locations'][$shipping_location_code] : '') ?>">
            <input type="hidden" name="selected-branch-name" value="<?php echo esc_attr(isset($data['pickup_branches'][$chosen_aff_id])? $data['pickup_branches'][$chosen_aff_id] : '') ?>">

            <div class="delivery-settings-screen-1">

            <div class="delivery-settings-screen-1-2 <?php echo (empty($delivery_data['delivery_type'])? '' : 'cds-hidden') ?>">
                <h1 class="delivery-settings-heading"><?php echo esc_html(_x('Just before we start..', 'Heading in mini-cart', 'ocws')) ?></h1>
                <p class="delivery-settings-text"><?php echo esc_html(_x('Our dishes change from day to day. To display the relevant menu, select the shipping method and the delivery date.', 'Text in mini-cart', 'ocws')) ?></p>
                <?php self::output_shipping_methods($data); ?>
            </div>

        </div>
        <div class="delivery-settings-screen-2">

            <div id="minicart-shipping-options" class="minicart-shipping-options <?php echo ($show_shipping_options? '' : 'cds-hidden') ?>">

                <div class="minicart-shipping-address-label form-label"><span><?php echo esc_html(_x('Shipping address', 'Mini-cart shipping address label', 'ocws')) ?></span></div>
                <div class="minicart-chosenaddress" style="display: none!important;"><?php echo esc_html(self::get_chosen_address_text()) ?></div>
                <?php if (!ocws_use_google_cities_and_polygons()) { ?>
                    <?php if(isset($data['shipping_locations'])) { ?>

                        <div class="selected-city">
                            <select name="selected-city" class="ocws-enhanced-select">
                                <option value="" <?php echo ($shipping_location_code? '' : 'selected') ?>><?php echo esc_html(__('Select your distribution area', 'ocws')) ?></option>
                                <?php foreach ($data['shipping_locations'] as $code => $city_option):?>
                                    <option <?php echo ($shipping_location_code && isset($data['shipping_locations'][$shipping_location_code]) && $shipping_location_code == $code? 'selected' : '') ?> value="<?php echo $code?>"><?php echo $city_option?></option>
                                <?php endforeach;?>
                            </select>
                        </div>
                    <?php } ?>
                <?php } else { ?>
                    <div class="ocws-checkout-inputs-pp">

                        <?php
                        $show_saved_data = true;

                        if ($show_saved_data) {
                            /* Polygon saved data */
                            $billing_city = WC()->session->get('chosen_city_code', '' );
                            $billing_city_code = WC()->session->get('chosen_city_code', '' );
                            $billing_city_name = WC()->session->get('chosen_city_name', '' );
                            $billing_street = WC()->session->get('chosen_street', '' );
                            $billing_house_num = WC()->session->get('chosen_house_num', '' );
                            $billing_address_coords = WC()->session->get('chosen_address_coords', '' );

                            $autocomplete_text = '';
                            if ($billing_city_name && $billing_street && $billing_house_num) {
                                $autocomplete_text = $billing_street . ' ' . $billing_house_num . ' ' . $billing_city_name;
                            }

                            $shipping_location_code = OC_Woo_Shipping_Polygon::get_location_code_by_post_data(array(
                                'billing_city_code' => $billing_city_code,
                                'billing_address_coords' => $billing_address_coords
                            ));
                        }
                        else {
                            $billing_city = '';
                            $billing_city_code = '';
                            $billing_city_name = '';
                            $billing_street = '';
                            $billing_house_num = '';
                            $billing_address_coords = '';
                            $autocomplete_text = '';
                            $shipping_location_code = '';
                        }


                        //echo 'Shipping location code: '.$shipping_location_code;
                        ?>

                        <input type="text" class="ocws-input-text ocws-checkout-pac-input pac-target-input"
                               name="billing_google_autocomplete" id="billing_google_autocomplete_deli" placeholder="<?php echo esc_html(__('Enter address and house number', 'ocws')) ?>" value="<?php echo esc_attr($autocomplete_text) ?>" autocomplete="off">

                        <input type="hidden" name="billing_city" id="billing_city_pp" value="<?php echo esc_attr($billing_city) ?>">

                        <input type="hidden" name="billing_city_code" id="billing_city_code_pp" value="<?php echo esc_attr($billing_city_code) ?>">

                        <input type="hidden" name="billing_city_name" id="billing_city_name_pp" value="<?php echo esc_attr($billing_city_name) ?>">

                        <input type="hidden" name="billing_street" id="billing_street_pp" value="<?php echo esc_attr($billing_street) ?>">

                        <input type="hidden" name="billing_house_num" id="billing_house_num_pp" value="<?php echo esc_attr($billing_house_num) ?>">

                        <input type="hidden" name="billing_address_coords" id="billing_address_coords_pp" value="<?php echo esc_attr($billing_address_coords) ?>">

                    </div>
                <?php } ?>
            </div>

            <div id="minicart-shipping-form-messages" style="<?php echo $show_shipping_options? '' : 'display: none;' ?>"></div>

            <div id="minicart-pickup-options" class="minicart-pickup-options <?php echo ($show_pickup_options? '' : 'cds-hidden') ?>">
                <div class="minicart-chosenaddress"><?php echo esc_html(self::get_chosen_pickup_branch_text($chosen_aff_id)) ?></div>
                <?php

                ?>
                <div class="selected-pickup-branch" style="<?php echo (count($data['pickup_branches']) <= 1? 'display:none' : '') ?>">
                    <select name="ocws_lp_pickup_aff_id" class="ocws_lp_pickup_aff_id_select">
                        <option value=""><?php echo esc_html(__('Select a branch', 'ocws')) ?></option>
                        <?php foreach ($data['pickup_branches'] as $aff_id => $aff_name):?>
                            <option <?php echo ($chosen_aff_id == $aff_id || count($data['pickup_branches']) == 1? 'selected' : '') ?> value="<?php echo $aff_id?>"><?php echo esc_html($aff_name) ?></option>
                        <?php endforeach;?>
                    </select>
                </div>
            </div>

            <div id="minicart-pickup-form-messages" style="<?php echo $show_pickup_options? '' : 'display: none;' ?>"></div>

        </div>
        <div class="delivery-settings-screen-3">

            <div id="minicart-shipping-city-slots" class="minicart-shipping-city-slots <?php echo ($show_shipping_options? '' : 'cds-hidden') ?>">
                <?php
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
                            ?>
                            <div id="oc-woo-shipping-additional">
                                <div class="slot-message"><?php echo $oos_message; ?></div>
                            </div>
                            <?php
                            $shipping_location_code = 0;
                        }
                    }

                    if ($shipping_location_code && str_contains($shipping_location_code.'', ':::')) {
                        $bid = explode(':::', $shipping_location_code, 2);
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
                                WC()->session->set('popup_location_code', $shipping_location_code);
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
                                global $wp;
                                ?>
                                <div id="oc-woo-shipping-additional">
                                    <!--<div class="slot-message"><?php /*echo $oos_message; */?></div>-->
                                    <div class="slot-message"><input type="button" class="ocws-redirect-button cds-button-submit" data-href="<?php echo esc_url(ocws_convert_current_page_url($go_to_blog_id, ['ocws_from_store' => get_current_blog_id()])); ?>" value="<?php _e('Confirm and continue shopping' , 'ocws')?>"></div>
                                </div>
                                <?php
                                $shipping_location_code = 0;

                            }

                        }
                        else if (isset($bid[1])) {
                            $shipping_location_code = $bid[1];
                        }
                    }
                }

                if ($shipping_location_code  && ocws_is_location_enabled($shipping_location_code)) {
                    self::output_shipping_datepicker($shipping_location_code);
                }
                else if ($shipping_location_code) {
                    self::output_out_of_service_area_message();
                }
                ?>
            </div>

            <div id="minicart-pickup-branch-slots" class="minicart-pickup-branch-slots <?php echo ($show_pickup_options? '' : 'cds-hidden') ?>">
                <?php
                if (is_multisite()) {
                    if ($chosen_aff_id && str_contains($chosen_aff_id.'', ':::')) {
                        $bid = explode(':::', $chosen_aff_id, 2);
                        $blog_id = intval($bid[0]);

                        if (ocws_blog_exists($blog_id)) {
                            $blog_data = get_site($blog_id);

                            $blog_checkout_url = get_blogaddress_by_id(intval($blog_id));
                            switch_to_blog($blog_id);
                            $blog_checkout_url = wc_get_checkout_url();
                            restore_current_blog();

                            $tmp = WC()->session->get('shipping_for_package_0', false);
                            WC()->session->set('shipping_for_package_0', null);
                            WC()->session->set('popup_aff_id', $chosen_aff_id);
                            if ($tmp) {
                                //error_log(print_r($tmp, 1));
                                //WC()->session->set('shipping_for_package_0', $tmp);
                            }
                            WC()->session->save_data();

                            if (count($data['pickup_branches']) == 1) {
                                ?>
                                <!--<div class="slot-message"><span class="important-notice">
                                <?php /*echo esc_html(sprintf(__('Local pickup is available from %s only.', 'ocws'), $blog_data->blogname)); */?><br>
                                <a class="ocws-site-link" href="<?php /*echo esc_url(get_blogaddress_by_id(intval($blog_id))).'?ocws_from_store='.get_current_blog_id(); */?>"><?php /*echo esc_html(__('Go to the site.', 'ocws')); */?></a>
                            </span></div>-->

                                <div class="slot-message"><input type="button" class="ocws-redirect-button cds-button-submit" data-href="<?php echo esc_url(ocws_convert_current_page_url($blog_id, ['ocws_from_store' => get_current_blog_id()])); ?>" value="<?php _e('Confirm and continue shopping' , 'ocws')?>"></div>

                                <?php
                            }
                            else {
                                ?>
                                <!--<div class="slot-message"><span class="important-notice">
                                <?php /*echo esc_html(sprintf(__('You have chosen the branch %s for local pickup.', 'ocws'), $blog_data->blogname)); */?><br>
                                <a class="ocws-site-link" href="<?php /*echo esc_url(is_checkout()? $blog_checkout_url : get_blogaddress_by_id($blog_id)).'?ocws_from_store='.get_current_blog_id(); */?>"><?php /*echo esc_html(__('Go to the site.', 'ocws')); */?></a>
                            </span></div>-->

                                <div class="slot-message"><input type="button" class="ocws-redirect-button cds-button-submit" data-href="<?php echo esc_url(ocws_convert_current_page_url($blog_id, ['ocws_from_store' => get_current_blog_id()])); ?>" value="<?php _e('Confirm and continue shopping' , 'ocws')?>"></div>

                                <?php
                            }
                        }
                        else {
                            //
                        }
                        $chosen_aff_id = 0;
                        WC()->session->get_session_data();
                    }
                }

                if ($chosen_aff_id) {
                    self::output_pickup_datepicker($chosen_aff_id);
                }
                ?>
            </div>
        </div>

        <div id="minicart-form-messages" style=""></div>
        <div class="cart-delivery-settings-actions <?php echo (empty($delivery_data['delivery_date'])? 'cds-hidden' : '') ?>">
            <input type="submit" class="cds-button-submit" value="<?php _e('Confirm and continue shopping' , 'ocws')?>">
        </div>
        </form>
        </div>
        <?php
    }

    public static function output_out_of_service_area_message() {

        $oos_message = ocws_get_multilingual_option('ocws_common_out_of_service_area_message');
        if (empty($oos_message)) {
            $general_options_defaults = OC_Woo_Shipping_Group_Option::get_general_options_defaults();
            if (isset($general_options_defaults['out_of_service_area_message'])) {
                $oos_message = $general_options_defaults['out_of_service_area_message'];
            }
        }
        ?>
        <div id="oc-woo-shipping-additional">
            <div class="slot-message"><?php echo esc_html($oos_message); ?></div>
        </div>
        <?php
    }

    public static function ocws_render_header_delivery_settings() {


    ?>


    <div class="header-delivery-settings" style="display: inline-block;">
        <div class="dropdown">
            <a class="btn btn-secondary delivery-dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" fill="currentColor" class="bi bi-calendar3" viewBox="0 0 16 16">
                    <path d="M14 0H2a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V2a2 2 0 0 0-2-2M1 3.857C1 3.384 1.448 3 2 3h12c.552 0 1 .384 1 .857v10.286c0 .473-.448.857-1 .857H2c-.552 0-1-.384-1-.857z"/>
                    <path d="M6.5 7a1 1 0 1 0 0-2 1 1 0 0 0 0 2m3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2m3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2m-9 3a1 1 0 1 0 0-2 1 1 0 0 0 0 2m3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2m3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2m3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2m-9 3a1 1 0 1 0 0-2 1 1 0 0 0 0 2m3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2m3 0a1 1 0 1 0 0-2 1 1 0 0 0 0 2"/>
                </svg>
                <span style="margin: 0 20px 0 20px"><?php echo esc_html(__('Choose delivery date', 'ocws')); ?></span>

            </a>

            <div class="dropdown-menu">
                <div class="delivery-settings-screen-1">
                    <div class="ds-heading"><b><span><?php echo esc_html(__('Choose an order time to view the updated menu', 'ocws')); ?></span></b><br>
                        <span>
                        המנות שלנו טריות ומתחלפות יום-יום
                        </span>
                    </div>
                    <?php //self::output_shipping_datepicker(); ?>
                </div>
                <div class="delivery-settings-screen-2">
                    <?php self::output_shipping_methods(); ?>
                </div>
            </div>
        </div>
    </div>
    <style>

    </style>


    <?php
    }

    public static function get_chosen_address_text() {

        $city_name = WC()->session->get('chosen_city_name');
        $street = WC()->session->get('chosen_street');
        $house_num = WC()->session->get('chosen_house_num');
        if ($city_name) {
            if ($street && $house_num) {
                return sprintf(_x('%s %s, %s', 'Mini-cart shipping summary', 'ocws'), $street, $house_num, $city_name);
            }
            return sprintf(_x('%s', 'Mini-cart shipping summary', 'ocws'), $city_name);
        }
        return '';
    }

    public static function get_chosen_pickup_branch_text($default_aff_id = null) {

        $aff_id = WC()->session->get('chosen_pickup_aff');
        if (!$aff_id && $default_aff_id) {
            $aff_id = $default_aff_id;
        }
        $aff_name = '';
        if ($aff_id) {
            $affs_ds = new OCWS_LP_Affiliates();
            $aff_name = $affs_ds->get_affiliate_name(intval($aff_id));
        }
        if ($aff_name) {
            return sprintf(_x('Local pickup at %s', 'Mini-cart shipping summary', 'ocws').'.', $aff_name);
        }
        return '';
    }

    public static function get_chosen_location_code() {
        $post_data = self::get_posted_data();
        if (!isset($post_data['billing_city']) || empty($post_data['billing_city'])) {
            $post_data['billing_city'] = WC()->checkout->get_value('billing_city');
        }
        if (ocws_use_google_cities_and_polygons()) {

            $location_code = OC_Woo_Shipping_Polygon::get_location_code_by_post_data($post_data);
        }
        else {
            $location_code = $post_data['billing_city'];
        }
        return $location_code;
    }

    public static function save_chosen_location_data() {
        $formdata = self::get_posted_data();
        $city_id = 0;

        if (isset($formdata['selected-city'])) {
            $city_id = $formdata['selected-city'];
        }
        else if (isset($formdata['billing_city'])) {
            $city_id = $formdata['billing_city'];
        }

        if (isset($formdata['billing_address_coords'])) {
            WC()->session->set('chosen_address_coords', $formdata['billing_address_coords'] );
        }
        if (isset($formdata['billing_street'])) {
            WC()->session->set('chosen_street', $formdata['billing_street'] );
        }
        if (isset($formdata['billing_house_num'])) {
            WC()->session->set('chosen_house_num', $formdata['billing_house_num'] );
        }
        if (isset($formdata['billing_city_name'])) {
            WC()->session->set('chosen_city_name', $formdata['billing_city_name'] );
        }
        if (isset($formdata['billing_city_code'])) {
            WC()->session->set('chosen_city_code', $formdata['billing_city_code'] );
        }

        if ($city_id) {

            WC()->customer->set_billing_city($city_id);
            WC()->customer->set_shipping_city($city_id);

            WC()->session->set('chosen_shipping_city', $city_id );
        }
        else {
            WC()->session->set('chosen_shipping_city', null );
        }
    }

    public static function get_refreshed_fragments() {
        ob_start();

        woocommerce_mini_cart();

        $mini_cart = ob_get_clean();

        $data = array(
            'fragments' => apply_filters(
                'woocommerce_add_to_cart_fragments',
                array(
                    'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
                )
            ),
            'cart_hash' => WC()->cart->get_cart_hash(),
        );

        return $data;
    }

    public static function save_delivery_settings_data_from_cart_form() {

        // error_log('save_delivery_settings_data_from_cart_form ---------------------------------');
        parse_str($_POST['formData'], $formdata);
        $shipping = (isset($formdata['minicart-shipping-method'])? $formdata['minicart-shipping-method'] : '');
        $shipping = str_replace(':', '', $shipping);

        if (isset(WC()->session)) {
            WC()->session->set( 'deli_add_to_cart_error_product', null );
        }
        wc_clear_notices();
        $res = array(
            'formdata' => $formdata
        );
        $saved = false;
        if (ocws_is_method_id_shipping($shipping)) {
            // error_log('Saving shipping');
            self::save_shipping_data($formdata, $shipping);
            // error_log('Saved shipping');
            $saved = true;
        }
        else if (ocws_is_method_id_pickup($shipping)) {
            self::save_pickup_data($formdata, $shipping);
            $saved = true;
        }
        if ($saved) {
            // error_log('OK');
            self::remove_unavailable_from_cart();
            // error_log('OK');
            self::handle_pending_product();
            $fragments = self::get_refreshed_fragments();
            // error_log('OK');
            $res['fragments'] = $fragments['fragments'];
            // error_log('OK');
            $res['cart_hash'] = $fragments['cart_hash'];
            //error_log(print_r(WC()->session->get_session_data(), 1));
        }
        wp_send_json_success($res);
    }

    public static function save_delivery_settings_data_from_checkout_form() {

        parse_str($_POST['post_data'], $formdata);

        $chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods' );
        $posted_shipping_methods = isset( $_POST['shipping_method'] ) ? wc_clean( wp_unslash( $_POST['shipping_method'] ) ) : array();

        if ( is_array( $posted_shipping_methods ) ) {
            foreach ( $posted_shipping_methods as $i => $value ) {
                $chosen_shipping_methods[ $i ] = $value;
            }
        }
        $shipping = (isset($chosen_shipping_methods[0])? $chosen_shipping_methods[0] : '');
        $shipping = str_replace(':', '', $shipping);

        if (isset(WC()->session)) {
            WC()->session->set( 'deli_add_to_cart_error_product', null );
        }
        wc_clear_notices();
        $res = array(
            'formdata' => $formdata
        );
        $saved = false;
        if (ocws_is_method_id_shipping($shipping)) {
            self::save_shipping_data($formdata, $shipping);
            $saved = true;
        }
        else if (ocws_is_method_id_pickup($shipping)) {
            self::save_pickup_data($formdata, $shipping);
            $saved = true;
        }
        if ($saved) {
            self::remove_unavailable_from_cart();
            self::handle_pending_product();
            //$fragments = self::get_refreshed_fragments();
            //$res['fragments'] = $fragments['fragments'];
            //$res['cart_hash'] = $fragments['cart_hash'];
        }
        //wp_send_json_success($res);
    }

    public static function save_shipping_data($formdata, $shipping) {
        /*{
            "action": "oc_woo_shipping_set_shipping_city",
            "formData": "minicart-shipping-method=oc_woo_advanced_shipping_method%3A5&selected-city=187&order_expedition_date=&order_expedition_slot_start=&order_expedition_slot_end=&slots_state="
        }*/

        /* alt. formData for polygon
        minicart-shipping-method=oc_woo_advanced_shipping_method%3A5&
        billing_google_autocomplete=%D7%9E%D7%A9%D7%94%20%D7%A9%D7%A8%D7%AA%2064%2C%20%D7%A2%D7%A4%D7%95%D7%9C%D7%94%2C%20%D7%99%D7%A9%D7%A8%D7%90%D7%9C&
        billing_city=%D7%A2%D7%A4%D7%95%D7%9C%D7%94&
        billing_city_name=%D7%A2%D7%A4%D7%95%D7%9C%D7%94&
        billing_street=%D7%9E%D7%A9%D7%94%20%D7%A9%D7%A8%D7%AA&
        billing_house_num=64&
        billing_address_coords=(32.60592259999999%2C%2035.28192979999999)&
        order_expedition_date=28%2F11%2F2022&
        order_expedition_slot_start=10%3A00&
        order_expedition_slot_end=20%3A00&
        slots_state=
         * */

        $city_id = 0;

        if (isset($formdata['selected-city'])) {
            $city_id = $formdata['selected-city'];
        }
        else if (isset($formdata['billing_city_code'])) {
            $city_id = $formdata['billing_city_code'];
        }

        if (isset($formdata['billing_address_coords'])) {
            WC()->session->set('chosen_address_coords', $formdata['billing_address_coords'] );
            ocws_update_session_checkout_field('billing_address_coords', $formdata['billing_address_coords']);
        }
        if (isset($formdata['billing_street'])) {
            WC()->session->set('chosen_street', $formdata['billing_street'] );
            ocws_update_session_checkout_field('billing_street', $formdata['billing_street']);
            //WC()->customer->set
        }
        if (isset($formdata['billing_house_num'])) {
            WC()->session->set('chosen_house_num', $formdata['billing_house_num'] );
            ocws_update_session_checkout_field('billing_house_num', $formdata['billing_house_num']);
        }
        if (isset($formdata['billing_city_name'])) {
            WC()->session->set('chosen_city_name', $formdata['billing_city_name'] );
            ocws_update_session_checkout_field('billing_city_name', $formdata['billing_city_name']);
        }
        else if (isset($formdata['selected-city-name'])) {
            WC()->session->set('chosen_city_name', $formdata['selected-city-name'] );
        }
        if (isset($formdata['billing_city_code'])) {
            WC()->session->set('chosen_city_code', $formdata['billing_city_code'] );
            ocws_update_session_checkout_field('billing_city_code', $formdata['billing_city_code']);
        }

        $date = (isset($formdata['order_expedition_date']) ? $formdata['order_expedition_date'] : '');

        $slot_start = (isset($formdata['order_expedition_slot_start']) ? $formdata['order_expedition_slot_start'] : '');

        $slot_end = (isset($formdata['order_expedition_slot_end']) ? $formdata['order_expedition_slot_end'] : '');

        $session_shipping_info = array(
            'date' => '',
            'slot_start' => '',
            'slot_end' => ''
        );

        if ($city_id) {

            WC()->customer->set_billing_city($city_id);
            WC()->customer->set_shipping_city($city_id);

            WC()->session->set('chosen_shipping_city', $city_id );

            if ($date) {
                $session_shipping_info['date'] = $date;
                if ($slot_start && $slot_end) {
                    $session_shipping_info['slot_start'] = $slot_start;
                    $session_shipping_info['slot_end'] = $slot_end;
                }
                else {
                    $session_shipping_info['slot_start'] = '';
                    $session_shipping_info['slot_end'] = '';
                }
            }
            else {
                $session_shipping_info['date'] = '';
                $session_shipping_info['slot_start'] = '';
                $session_shipping_info['slot_end'] = '';
            }
        }
        else {
            WC()->session->set('chosen_shipping_city', null );
            $session_shipping_info['date'] = '';
            $session_shipping_info['slot_start'] = '';
            $session_shipping_info['slot_end'] = '';
        }

        WC()->session->set('chosen_shipping_methods', ($shipping? array( $shipping ) : null) );

        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();

        // error_log('Saved shipping info:');
        // error_log(print_r($session_shipping_info, 1));

        OC_Woo_Shipping_Info::save_shipping_info($session_shipping_info);
        ocws_update_session_checkout_field( 'order_expedition_date', $session_shipping_info['date'] );
        ocws_update_session_checkout_field( 'order_expedition_slot_start', $session_shipping_info['slot_start'] );
        ocws_update_session_checkout_field( 'order_expedition_slot_end', $session_shipping_info['slot_end'] );
    }

    public static function save_pickup_data($formdata, $shipping) {
        $aff_id = 0;
        $_POST['ocws_lp_popup'] = array();

        if (isset($formdata['ocws_lp_pickup_aff_id'])) {
            $aff_id = intval($formdata['ocws_lp_pickup_aff_id']);
            $_POST['ocws_lp_popup']['ocws_lp_pickup_aff_id'] = $aff_id; // to be used in 'woocommerce_cart_shipping_packages' filter for ['destination']['ocws_lp_pickup_aff_id'] of a package
        }

        $date = (isset($formdata['ocws_lp_pickup_date']) ? $formdata['ocws_lp_pickup_date'] : '');

        $slot_start = (isset($formdata['ocws_lp_pickup_slot_start']) ? $formdata['ocws_lp_pickup_slot_start'] : '');

        $slot_end = (isset($formdata['ocws_lp_pickup_slot_end']) ? $formdata['ocws_lp_pickup_slot_end'] : '');

        $session_shipping_info = array(
            'aff_id' => 0,
            'date' => '',
            'slot_start' => '',
            'slot_end' => ''
        );

        if ($aff_id) {

            WC()->session->set('chosen_pickup_aff', $aff_id );
            $session_shipping_info['aff_id'] = $aff_id;

            if ($date) {
                $session_shipping_info['date'] = $date;
                if ($slot_start && $slot_end) {
                    $session_shipping_info['slot_start'] = $slot_start;
                    $session_shipping_info['slot_end'] = $slot_end;
                }
                else {
                    $session_shipping_info['slot_start'] = '';
                    $session_shipping_info['slot_end'] = '';
                }
            }
            else {
                $session_shipping_info['date'] = '';
                $session_shipping_info['slot_start'] = '';
                $session_shipping_info['slot_end'] = '';
            }
        }
        else {
            WC()->session->set('chosen_pickup_aff', null );
            $session_shipping_info['date'] = '';
            $session_shipping_info['slot_start'] = '';
            $session_shipping_info['slot_end'] = '';
        }

        WC()->session->set('chosen_shipping_methods', ($shipping? array( $shipping ) : null) );

        WC()->cart->calculate_shipping();
        WC()->cart->calculate_totals();

        OCWS_LP_Pickup_Info::save_pickup_info($session_shipping_info);
        ocws_update_session_checkout_field( 'ocws_lp_pickup_aff_id', $session_shipping_info['aff_id'] );
        ocws_update_session_checkout_field( 'ocws_lp_pickup_date', $session_shipping_info['date'] );
        ocws_update_session_checkout_field( 'ocws_lp_pickup_slot_start', $session_shipping_info['slot_start'] );
        ocws_update_session_checkout_field( 'ocws_lp_pickup_slot_end', $session_shipping_info['slot_end'] );
    }

    public static function get_chosen_pickup_branch() {

        $post_data = self::get_posted_data();
        $chosen_aff_id = isset($post_data['ocws_lp_pickup_aff_id'])? $post_data['ocws_lp_pickup_aff_id'] : '';

        if (!$chosen_aff_id) {
            $selected_slot = self::get_selected_pickup_slot();
            if (isset($selected_slot['aff_id'])) {
                $chosen_aff_id = $selected_slot['aff_id'];
            }
        }

        return $chosen_aff_id;
    }

    public static function get_datepicker_date_selector($datepicker_id, $date) {
        $date_arr = explode('/', $date);
        if (is_array($date_arr) && count($date_arr == 3)) {
            return '#'.$datepicker_id.' td[data-month="'.$date_arr[1].'"][data-year="'.$date_arr[2].'"] a[data-date="'.$date_arr[0].'"]';
        }
        return '';
    }

    public static function output_shipping_datepicker($location_code) {

        $output = self::get_dates_output_for_shipping($location_code);
        if (count($output) == 0) {
            self::output_out_of_service_area_message();
            return;
        }
        $datepickerId = wp_rand();
        $begin_range = reset($output);
        $end_range = end($output);
        $available_dates = json_encode($output);
        $show_dates_only = get_option('ocws_common_show_dates_only', '') != 1 ? false : true;
        $menus = OCWS_Deli_Menus::instance();
        $holidays = json_encode( $menus->get_holidays() );

        $delivery_data = self::get_current_delivery_data();
        $default_date = ( !empty($delivery_data['delivery_date'])? $delivery_data['delivery_date'] : $begin_range['formatted_date'] );
        ?>
        <div class="minicart-shipping-slots-label form-label"><span><?php echo esc_html(_x('Shipping date', 'Mini-cart shipping date label', 'ocws')) ?></span></div>
        <div class="datepicker" id="<?php echo $datepickerId; ?>"></div>
        <?php
        self::show_pending_product_chip();
        ?>
        <div class="datepicker_slider_slots" style="display: none;">
            <div class="ocws-days-with-slots-list">

            </div>
        </div>
        <script>

            jQuery( function($) {

                const PICK_DATE_ONLY = <?php echo intval($show_dates_only); ?>;
                const VALIDATE_DATES = <?php echo $available_dates; ?>;
                const HOLIDAYS = <?php echo $holidays; ?>;
                var checkingProductAvailability = null;
                var submitButtonInitVal = "<?php _e('Confirm and continue shopping' , 'ocws')?>";

                function hide_submit_button() {
                    $('.cart-delivery-settings-actions').addClass('cds-hidden');
                }
                function show_submit_button() {
                    $('.cart-delivery-settings-actions').removeClass('cds-hidden');
                }

                function dateFormat(date) {
                    return date.getDate().toString().padStart(2, '0') + '/' +
                        (date.getMonth() + 1).toString().padStart(2, '0') + '/' +
                        date.getFullYear().toString();
                }

                $( "#<?php echo $datepickerId; ?>" ).datepicker({
                    minDate: "<?php echo $begin_range['formatted_date']; ?>",
                    maxDate: "<?php echo $end_range['formatted_date']; ?>",
                    dateFormat: "dd/mm/yy",
                    beforeShowDay: function (date) {
                        const container = $( "#<?php echo $datepickerId; ?>" ).closest('.cart-delivery-settings');
                        var selectedVal = container.find('#order_expedition_date').val();
                        const current = dateFormat(date);
                        const dates = VALIDATE_DATES.filter(function (item) {
                            return current === item.formatted_date;

                        });
                        const holiday_dates = HOLIDAYS.filter(function (item) {
                            return current === item.date;

                        });
                        var cls = '';
                        var title = '';
                        if (holiday_dates.length > 0) {
                            cls = 'holiday';
                            for (var i = 0; i < holiday_dates.length; i++) {
                                if (title == '') {
                                    title += holiday_dates[i].title;
                                }
                                else {
                                    title += ', ' + holiday_dates[i].title;
                                }
                            }
                        }
                        if (selectedVal != '' && selectedVal == current) {
                            cls += ' ocws-deli-selected-date';
                        }
                        if (dates.length > 0) {
                            return [true, cls, title];
                        }
                        return [false, cls, title];
                    },
                    onSelect: function (dateText, inst) {
                        const root = $( "#<?php echo $datepickerId; ?>" ).closest('.cart-delivery-settings');
                        const $slots = $('.datepicker_slider_slots');
                        const current = VALIDATE_DATES.filter(function (item) {
                            return item.formatted_date === dateText;
                        });
                        if (current.length === 0) {
                            throw new DOMException('Picked date disabled');
                        }
                        const slots = current[0]['slots'];
                        if (slots.length === 0) {
                            throw new DOMException('Picked date slots unavailable');
                        }

                        function reload() {
                            // choose-shipping-popup
                            const parent = $( "#<?php echo $datepickerId; ?>" ).parents('.cart-delivery-settings');
                            if (parent.length === 0) {
                                $('#oc-woo-shipping-additional').block({
                                    message: null,
                                    overlayCSS: {
                                        background: '#fff',
                                        opacity: 0.6
                                    }
                                });

                                $(document.body).trigger('update_checkout');
                            }
                        }

                        function checkProductAvailability(dateText) {

                            var elem = $('#ocws-pending-product-chip');
                            if (!elem.length || elem.data('product') == '') return;
                            elem.html('').hide();
                            $('#cart-delivery-settings-form input[type=submit]').val(submitButtonInitVal);
                            checkingProductAvailability = $.ajax({
                                type: 'POST',
                                dataType: 'json',
                                data: {action: "ocws_deli_product_available_on_day", date: dateText, product_id: elem.data('product')},
                                url: ocws.ajaxurl,
                                beforeSend : function() {
                                    //checking progress status and aborting pending request if any
                                    if(checkingProductAvailability != null) {
                                        checkingProductAvailability.abort();
                                    }
                                },
                                success: function(response) {
                                    if (response.data.resp != '') {
                                        $('#ocws-pending-product-chip').html(response.data.resp).show();
                                        $('#cart-delivery-settings-form input[type=submit]').val('<?php echo esc_html(__('Confirm and continue without adding a product', 'ocws')); ?>');
                                    }
                                    else {
                                        $('#cart-delivery-settings-form input[type=submit]').val(submitButtonInitVal);
                                    }
                                },
                                complete: function(){
                                    // after ajax xomplets progress set to null
                                    checkingProductAvailability = null;
                                }
                            });
                        }
                        $('#cart-delivery-settings-form input[type=submit]').val(submitButtonInitVal);
                        checkProductAvailability(dateText);
                        hide_submit_button();

                        if (root.find('.selected-date')) {
                            root.find('.selected-date').text(dateText);
                        }
                        if (root.find('#order_expedition_date')) {
                            if (root.find('#order_expedition_date').val() !== dateText) {
                                if (root.find('#order_expedition_slot_start')) {
                                    root.find('#order_expedition_slot_start').val(slots[0].start);
                                }
                                if (root.find('#order_expedition_slot_end')) {
                                    root.find('#order_expedition_slot_end').val(slots[0].end);
                                }
                                reload();
                            }
                            root.find('#order_expedition_date').val(dateText);
                        }

                        if (!PICK_DATE_ONLY && slots.length >= 1) {
                            $slots.find('.ocws-days-with-slots-list').html('');
                            $slots.show();
                            let $dayDataLabel = $(`<div class="minicart-shipping-slots-label form-label"><span>
                                <?php echo esc_html(__('At what time?', 'ocws')) ?></span>
                                </div>`);
                            let $dayData = $(`<div class="minicart-shipping-slots"></div>`);

                            for (const slot of current[0]['slots']) {
                                let selected = '';
                                if (slot['class'].includes('selected')) {
                                    selected = 'selected';
                                }
                                const $slot = $(`<span
                                data-date="${current[0].formatted_date}"
                                data-weekday="${current[0].weekday}"
                                data-slot-start="${slot['start']}"
                                data-slot-end="${slot['end']}"
                                ${selected}
                            class="slot slot-interval ${slot['class']}">${slot['start']} - ${slot['end']}</span>`);
                                $dayData.append($slot);
                            }
                            $dayData.on('click', 'span.slot', function (event) {
                                const $item = $(this);
                                $('.minicart-shipping-slots .slot').removeClass('selected');
                                $item.addClass('selected');
                                $('input[name="order_expedition_date"]').val($item.data('date'));
                                $('input[name="order_expedition_slot_start"]').val($item.data('slot-start'));
                                $('input[name="order_expedition_slot_end"]').val($item.data('slot-end'));
                                //reload();
                                show_submit_button();
                                $('#cart-delivery-settings-form').scrollTop(1000000);
                            });
                            $slots.find('.ocws-days-with-slots-list').append($dayDataLabel);
                            $slots.find('.ocws-days-with-slots-list').append($dayData);
                            $('#cart-delivery-settings-form').scrollTop(1000000);
                        }
                        else {
                            $slots.hide();
                        }
                        // reload();
                    }
                });
                <?php if ( !empty($delivery_data['delivery_date']) && $delivery_data['delivery_type'] == 'shipping' ) { ?>
                $( "#<?php echo $datepickerId; ?>" ).datepicker('setDate', '<?php echo $delivery_data['delivery_date']; ?>');
                $("#<?php echo $datepickerId; ?> .ui-datepicker-current-day").trigger('click');
                <?php /*} else if(!empty($default_date)) { */?>/*
                $( "#<?php /*echo $datepickerId; */?>" ).datepicker('setDate', '<?php /*echo $default_date; */?>');
                $("#<?php /*echo $datepickerId; */?> .ui-datepicker-current-day").trigger('click');
                <?php /*} else { */?>
                $( "#<?php /*echo $datepickerId; */?>" ).datepicker('setDate', 'today');*/
                <?php } ?>
            } );
        //# sourceURL=shipping-datepicker.js
        </script>
        <?php
    }

    public static function output_pickup_datepicker($aff_id) {

        $today = Carbon::now(ocws_get_timezone());
        $today_formatted = $today->format('d/m/Y G:i');

        $output = self::get_dates_output_for_pickup($aff_id);
        if (count($output) == 0) {
            self::output_out_of_service_area_message();
            return;
        }
        $datepickerId = wp_rand();
        $begin_range = reset($output);
        $end_range = end($output);
        $available_dates = json_encode($output);
        $show_dates_only = get_option('ocws_common_show_dates_only', '') != 1 ? false : true;
        $menus = OCWS_Deli_Menus::instance();
        $holidays = json_encode( $menus->get_holidays() );

        $delivery_data = self::get_current_delivery_data();
        $default_date = ( !empty($delivery_data['delivery_date'])? $delivery_data['delivery_date'] : $begin_range['formatted_date'] );
        ?>
        <div class="minicart-pickup-slots-label form-label"><span><?php echo esc_html(_x('When shall we meet?', 'Mini-cart pickup date label', 'ocws')) ?></span></div>
        <div class="datepicker" id="<?php echo $datepickerId; ?>"></div>
        <?php
        self::show_pending_product_chip();
        ?>
        <!--<div>Current time for testing: <?php /*echo $today_formatted */?></div>-->
        <div class="datepicker_slider_slots" style="display: none;">
            <div class="ocws-days-with-slots-list">

            </div>
        </div>
        <script>

            jQuery( function($) {

                const PICK_DATE_ONLY = <?php echo intval($show_dates_only); ?>;
                const VALIDATE_DATES = <?php echo $available_dates; ?>;
                const HOLIDAYS = <?php echo $holidays; ?>;
                var checkingProductAvailability = null;
                var submitButtonInitVal = "<?php _e('Confirm and continue shopping' , 'ocws')?>";

                function dateFormat(date) {
                    return date.getDate().toString().padStart(2, '0') + '/' +
                        (date.getMonth() + 1).toString().padStart(2, '0') + '/' +
                        date.getFullYear().toString();
                }

                function hide_submit_button() {
                    $('.cart-delivery-settings-actions').addClass('cds-hidden');
                }
                function show_submit_button() {
                    $('.cart-delivery-settings-actions').removeClass('cds-hidden');
                }

                $( "#<?php echo $datepickerId; ?>" ).datepicker({
                    minDate: "<?php echo $begin_range['formatted_date']; ?>",
                    maxDate: "<?php echo $end_range['formatted_date']; ?>",
                    dateFormat: "dd/mm/yy",
                    beforeShowDay: function (date) {
                        const container = $( "#<?php echo $datepickerId; ?>" ).closest('.cart-delivery-settings');
                        var selectedVal = container.find('#ocws_lp_pickup_date').val();
                        const current = dateFormat(date);
                        const dates = VALIDATE_DATES.filter(function (item) {
                            return current === item.formatted_date;

                        });
                        const holiday_dates = HOLIDAYS.filter(function (item) {
                            return current === item.date;

                        });
                        var cls = '';
                        var title = '';
                        if (holiday_dates.length > 0) {
                            cls = 'holiday';
                            for (var i = 0; i < holiday_dates.length; i++) {
                                if (title == '') {
                                    title += holiday_dates[i].title;
                                }
                                else {
                                    title += ', ' + holiday_dates[i].title;
                                }
                            }
                        }
                        if (selectedVal != '' && selectedVal == current) {
                            cls += ' ocws-deli-selected-date';
                        }
                        if (dates.length > 0) {
                            return [true, cls, title];
                        }
                        return [false, cls, title];
                    },
                    onSelect: function (dateText, inst) {
                        const root = $( "#<?php echo $datepickerId; ?>" ).closest('.cart-delivery-settings');
                        const $slots = $('.datepicker_slider_slots');
                        const current = VALIDATE_DATES.filter(function (item) {
                            return item.formatted_date === dateText;
                        });
                        if (current.length === 0) {
                            throw new DOMException('Picked date disabled');
                        }
                        const slots = current[0]['slots'];
                        if (slots.length === 0) {
                            throw new DOMException('Picked date slots unavailable');
                        }

                        function reload() {
                            // choose-shipping-popup
                            const parent = $( "#<?php echo $datepickerId; ?>" ).parents('.cart-delivery-settings');
                            if (parent.length === 0) {
                                $('#oc-woo-pickup-additional').block({
                                    message: null,
                                    overlayCSS: {
                                        background: '#fff',
                                        opacity: 0.6
                                    }
                                });

                                $(document.body).trigger('update_checkout');
                            }
                        }

                        function checkProductAvailability(dateText) {

                            var elem = $('#ocws-pending-product-chip');
                            if (!elem.length || elem.data('product') == '') return;
                            elem.html('').hide();
                            $('#cart-delivery-settings-form input[type=submit]').val(submitButtonInitVal);
                            checkingProductAvailability = $.ajax({
                                type: 'POST',
                                dataType: 'json',
                                data: {action: "ocws_deli_product_available_on_day", date: dateText, product_id: elem.data('product')},
                                url: ocws.ajaxurl,
                                beforeSend : function() {
                                    //checking progress status and aborting pending request if any
                                    if(checkingProductAvailability != null) {
                                        checkingProductAvailability.abort();
                                    }
                                },
                                success: function(response) {
                                    if (response.data.resp != '') {
                                        $('#ocws-pending-product-chip').html(response.data.resp).show();
                                        $('#cart-delivery-settings-form input[type=submit]').val('<?php echo esc_html(__('Confirm and continue without adding a product', 'ocws')); ?>');
                                    }
                                    else {
                                        $('#cart-delivery-settings-form input[type=submit]').val(submitButtonInitVal);
                                    }
                                },
                                complete: function(){
                                    // after ajax xomplets progress set to null
                                    checkingProductAvailability = null;
                                }
                            });
                        }
                        $('#cart-delivery-settings-form input[type=submit]').val(submitButtonInitVal);
                        checkProductAvailability(dateText);

                        hide_submit_button();

                        if (root.find('.selected-date')) {
                            root.find('.selected-date').text(dateText);
                        }
                        if (root.find('#ocws_lp_pickup_date')) {
                            if (root.find('#ocws_lp_pickup_date').val() !== dateText) {
                                if (root.find('#ocws_lp_pickup_slot_start')) {
                                    root.find('#ocws_lp_pickup_slot_start').val(slots[0].start);
                                }
                                if (root.find('#ocws_lp_pickup_slot_end')) {
                                    root.find('#ocws_lp_pickup_slot_end').val(slots[0].end);
                                }
                                reload();
                            }
                            root.find('#ocws_lp_pickup_date').val(dateText);
                        }

                        if (!PICK_DATE_ONLY && slots.length >= 1) {
                            $slots.find('.ocws-days-with-slots-list').html('');
                            $slots.show();
                            let $dayDataLabel = $(`<div class="minicart-pickup-slots-label form-label"><span>
                                <?php echo esc_html(__('At what time?', 'ocws')) ?></span>
                                </div>`);
                            let $dayData = $(`<div class="minicart-pickup-slots"></div>`);

                            for (const slot of current[0]['slots']) {
                                let selected = '';
                                if (slot['class'].includes('selected')) {
                                    selected = 'selected';
                                }
                                const $slot = $(`<span
                                data-date="${current[0].formatted_date}"
                                data-weekday="${current[0].weekday}"
                                data-slot-start="${slot['start']}"
                                data-slot-end="${slot['end']}"
                                ${selected}
                            class="slot slot-interval ${slot['class']}">${slot['start']} - ${slot['end']}</span>`);
                                $dayData.append($slot);
                            }
                            $dayData.on('click', 'span.slot', function (event) {
                                const $item = $(this);
                                $('.minicart-pickup-slots .slot').removeClass('selected');
                                $item.addClass('selected');
                                $('input[name="ocws_lp_pickup_date"]').val($item.data('date'));
                                $('input[name="ocws_lp_pickup_slot_start"]').val($item.data('slot-start'));
                                $('input[name="ocws_lp_pickup_slot_end"]').val($item.data('slot-end'));
                                //reload();
                                show_submit_button();
                                $('#cart-delivery-settings-form').scrollTop(1000000);
                            });
                            $slots.find('.ocws-days-with-slots-list').append($dayDataLabel);
                            $slots.find('.ocws-days-with-slots-list').append($dayData);
                            $('#cart-delivery-settings-form').scrollTop(1000000);
                        }
                        else {
                            $slots.hide();
                        }
                        // reload();
                    }
                });
                <?php if ( !empty($delivery_data['delivery_date']) && $delivery_data['delivery_type'] == 'pickup' ) { ?>
                $( "#<?php echo $datepickerId; ?>" ).datepicker('setDate', '<?php echo $delivery_data['delivery_date']; ?>');
                $("#<?php echo $datepickerId; ?> .ui-datepicker-current-day").trigger('click');
                <?php /*} else if(!empty($default_date)) { */?>/*
                $( "#<?php /*echo $datepickerId; */?>" ).datepicker('setDate', '<?php /*echo $default_date; */?>');
                $("#<?php /*echo $datepickerId; */?> .ui-datepicker-current-day").trigger('click');
                <?php /*} else { */?>
                $( "#<?php /*echo $datepickerId; */?>" ).datepicker('setDate', 'today');*/
                <?php } ?>
            } );
            //# sourceURL=pickup-datepicker.js
        </script>
        <?php
    }

    public static function get_all_dates_output_for_shipping() {

        $output = array();
        $days = self::get_all_slots_for_shipping();
        $weekdays = array(
            __('Sunday', 'ocws'),
            __('Monday', 'ocws'),
            __('Tuesday', 'ocws'),
            __('Wednesday', 'ocws'),
            __('Thursday', 'ocws'),
            __('Friday', 'ocws'),
            __('Saturday', 'ocws')
        );
        $selected_slot_arr = self::get_selected_shipping_slot();
        foreach ($days as $index => $day) {

            if (count($day['slots']) == 0) {
                continue;
            }
            $item = array();
            $item['formatted_date'] = $day['formatted_date'];
            $cmp_date = explode('/', $day['formatted_date'], 3);
            $item['timestamp'] = implode('', array_reverse($cmp_date));
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
        usort($output, function ($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });
        return $output;
    }

    public static function get_dates_output_for_shipping($location_code) {

        $output = array();
        $oc_slots = new OC_Woo_Shipping_Slots($location_code);
        $days = $oc_slots->calculate_slots_for_checkout();
        $weekdays = array(
            __('Sunday', 'ocws'),
            __('Monday', 'ocws'),
            __('Tuesday', 'ocws'),
            __('Wednesday', 'ocws'),
            __('Thursday', 'ocws'),
            __('Friday', 'ocws'),
            __('Saturday', 'ocws')
        );
        $selected_slot_arr = self::get_selected_shipping_slot();
        foreach ($days as $index => $day) {

            if (count($day['slots']) == 0) {
                continue;
            }
            $item = array();
            $item['class'] = '';
            $item['formatted_date'] = $day['formatted_date'];
            $cmp_date = explode('/', $day['formatted_date'], 3);
            $item['timestamp'] = implode('', array_reverse($cmp_date));
            $item['weekday'] = $weekdays[$day['day_of_week']];
            $item['day_of_week'] = $day['day_of_week'];
            $item['slots'] = array();
            foreach ($day['slots'] as $slot) {
                $slot['class'] = '';
                if ($selected_slot_arr['date'] == $day['formatted_date'] && $selected_slot_arr['slot_start'] == $slot['start'] && $selected_slot_arr['slot_end'] == $slot['end']) {
                    $slot['class'] = 'selected';
                    $item['class'] = 'selected';
                }
                $item['slots'][] = $slot;
            }
            $output[] = $item;
        }
        usort($output, function ($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });
        return $output;
    }

    public static function get_dates_output_for_pickup($branch_id) {

        $output = array();
        $oc_slots = new OCWS_LP_Pickup_Slots($branch_id);
        $days = $oc_slots->calculate_slots_for_checkout();
        $weekdays = array(
            __('Sunday', 'ocws'),
            __('Monday', 'ocws'),
            __('Tuesday', 'ocws'),
            __('Wednesday', 'ocws'),
            __('Thursday', 'ocws'),
            __('Friday', 'ocws'),
            __('Saturday', 'ocws')
        );
        $selected_slot_arr = self::get_selected_pickup_slot();
        foreach ($days as $index => $day) {

            if (count($day['slots']) == 0) {
                continue;
            }
            $item = array();
            $item['class'] = '';
            $item['formatted_date'] = $day['formatted_date'];
            $cmp_date = explode('/', $day['formatted_date'], 3);
            $item['timestamp'] = implode('', array_reverse($cmp_date));
            $item['weekday'] = $weekdays[$day['day_of_week']];
            $item['day_of_week'] = $day['day_of_week'];
            $item['slots'] = array();
            foreach ($day['slots'] as $slot) {
                $slot['class'] = '';
                if ($selected_slot_arr['date'] == $day['formatted_date'] && $selected_slot_arr['slot_start'] == $slot['start'] && $selected_slot_arr['slot_end'] == $slot['end']) {
                    $slot['class'] = 'selected';
                    $item['class'] = 'selected';
                }
                $item['slots'][] = $slot;
            }
            $output[] = $item;
        }
        usort($output, function ($a, $b) {
            return $a['timestamp'] - $b['timestamp'];
        });
        return $output;
    }

    public static function get_all_slots_for_shipping() {

        $days = array();
        $data_store = new OC_Woo_Shipping_Group_Data_Store();
        $raw_groups  = $data_store->get_groups();

        foreach ( $raw_groups as $raw_group ) {
            if ($raw_group->is_enabled != 1) {
                continue;
            }
            $slots = new OCWS_Deli_Shipping_Slots($raw_group->group_id);
            $d = $slots->calculate_slots_for_checkout();
            foreach ($d as $day) {
                $days[] = $day;
            }
        }

        return $days;
    }

    public static function get_all_slots_for_local_pickup() {

        $days = array();
        $branches = new OCWS_LP_Affiliates();
        $raw_branches = $branches->db_get_affiliates();

        foreach ($raw_branches as $raw_branch) {
            if ($raw_branch->is_enabled != 1) {
                continue;
            }
            $slots = new OCWS_LP_Pickup_Slots($raw_branch->aff_id);
            $d = $slots->calculate_slots_for_checkout();
            foreach ($d as $day) {
                $days[] = $day;
            }
        }

        return $days;
    }

    public static function get_selected_shipping_slot() {
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
        return $selected_slot_arr;
    }

    public static function get_selected_pickup_slot() {
        $selected_slot = OCWS_LP_Pickup_Info::get_pickup_info();
        $popup_shipping_info = OCWS_LP_Pickup_Info::get_pickup_info_from_session();

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
        return $selected_slot_arr;
    }

    public static function output_shipping_methods($data) {
        ?>

        <div class="minicart-shipping-methods">
        <?php foreach ($data['methods'] as $method) { ?>
            <div class="cart-shipping-method-wraper <?php echo $method['method_id'].':'.$method['method_instance_id'];?> <?php echo $method['is_chosen'] ? 'selected' : '' ?>"
                 style="<?php echo ($data['available_methods_number'] === 1? 'display: none;' : '') ?>">

                <input class="btn-check" data-title="<?php echo esc_attr($method['title']) ?>"
                       type="radio" <?php echo $method['is_chosen'] ? 'checked' : '' ?>
                       name="minicart-shipping-method"
                       value="<?php echo $method['method_id'].':'.$method['method_instance_id']?>"
                       id="<?php echo $method['method_id'].':'.$method['method_instance_id']?>"
                       autocomplete="off">

                <label class="btn btn-outline-secondary <?php echo $method['is_chosen'] ? 'btn-active' : '' ?>" style="margin: 0; width: 100%; cursor: pointer;"
                       for="<?php echo $method['method_id'].':'.$method['method_instance_id'];?>">
                    <?php if (strstr($method['method_id'], 'shipping')) { ?>
                        <svg width="50" height="50" viewBox="0 0 50 50" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <g clip-path="url(#clip0_33_1201)">
                                <path d="M40.2586 45.488C36.2683 45.4393 33.0731 42.1654 33.1218 38.1757C33.1705 34.186 36.4448 30.9911 40.4351 31.0399C44.4254 31.0887 47.6206 34.3625 47.5719 38.3522C47.5231 42.342 44.2489 45.5368 40.2586 45.488Z" fill="black"/>
                                <path d="M7.28642 45.4895C3.29613 45.4408 0.100887 42.1669 0.149634 38.1772C0.19838 34.1874 3.47266 30.9926 7.46294 31.0414C11.4532 31.0901 14.6485 34.364 14.5997 38.3537C14.551 42.3435 11.2767 45.5383 7.28642 45.4895Z" fill="black"/>
                                <path d="M0.0978279 29.8916L0.109547 29.8711C1.52066 27.369 3.66341 25.3585 6.25017 24.1094C6.62127 23.9297 6.99561 23.7669 7.37322 23.6211C7.4992 17.0899 15.0802 10.9893 15.0802 10.9893C13.048 11.1094 12.9767 9.08204 12.9767 9.08204C12.8527 6.62403 14.5158 4.65431 16.8117 4.51954C17.39 4.4886 17.9635 4.63881 18.4523 4.94923L19.7814 5.78419C20.2259 6.07021 20.6949 6.31629 21.1828 6.51954C21.5187 6.65555 21.8182 6.86833 22.0571 7.14084C22.296 7.41336 22.4678 7.73803 22.5588 8.08888C22.5588 8.08888 23.1701 8.27149 23.3947 8.4795C23.5535 8.61518 23.6697 8.79391 23.7293 8.99413C23.7889 9.19436 23.7893 9.40754 23.7304 9.60798C23.6716 9.80842 23.5561 9.98757 23.3977 10.1238C23.2394 10.2601 23.045 10.3477 22.8381 10.376C22.5386 10.4037 22.2367 10.3836 21.9435 10.3164C21.515 10.741 20.9374 10.9811 20.3342 10.9854L16.4191 25.0977C15.7121 27.6416 16.3449 30.3945 18.1603 32.3115C18.1603 32.3115 20.4924 34.8252 23.9845 34.8291C25.0857 34.8206 26.1388 34.3772 26.9144 33.5956C27.69 32.8139 28.1252 31.7574 28.1252 30.6563C28.1246 29.1316 27.954 27.6118 27.6164 26.125C27.1808 24.419 26.8967 23.8623 26.3752 22.792C24.6701 22.5586 24.0109 20.0498 25.2717 18.0068C26.5949 15.8584 29.4924 15.6113 32.9865 16.835C35.1437 17.5879 37.2121 17.626 39.5412 17.3643C38.8994 17.247 38.3139 16.9222 37.8746 16.4399C37.4353 15.9575 37.1665 15.3443 37.1095 14.6943C37.0334 13.8301 36.9777 12.6436 36.9777 11.0205C36.9777 9.39747 37.0334 8.21192 37.1095 7.34766C37.171 6.65091 37.4758 5.99803 37.9704 5.50344C38.465 5.00884 39.1179 4.70409 39.8146 4.64259C40.2433 4.6045 40.7502 4.57227 41.3498 4.54493C41.3498 4.54493 41.2824 5.39942 41.2951 6.3838C41.2951 6.69923 41.301 7.15431 41.3117 7.53224C41.3134 7.59864 41.3316 7.66358 41.3647 7.72119C41.3978 7.7788 41.4447 7.82728 41.5012 7.86225C41.5576 7.89722 41.6219 7.91759 41.6882 7.92152C41.7546 7.92546 41.8208 7.91283 41.881 7.88477L43.2707 7.24903C43.3234 7.22489 43.3807 7.21239 43.4386 7.21239C43.4966 7.21239 43.5539 7.22489 43.6066 7.24903L44.9953 7.88868C45.0555 7.91673 45.1218 7.92936 45.1881 7.92543C45.2544 7.9215 45.3187 7.90113 45.3752 7.86616C45.4316 7.83118 45.4785 7.78271 45.5116 7.7251C45.5447 7.66748 45.5629 7.60255 45.5646 7.53614C45.5754 7.15821 45.5812 6.70313 45.5812 6.3877C45.5812 5.46583 45.5256 4.54493 45.5256 4.54493C46.1701 4.56837 46.7111 4.60255 47.1633 4.64259C47.8601 4.7039 48.5131 5.0086 49.0077 5.50323C49.5023 5.99786 49.807 6.65084 49.8683 7.34766C49.9445 8.21192 50.0002 9.39845 50.0002 11.0205C50.0002 12.6426 49.9445 13.8301 49.8683 14.6943C49.807 15.3912 49.5023 16.0442 49.0077 16.5388C48.5131 17.0334 47.8601 17.3381 47.1633 17.3994L47.0959 17.4053C48.8859 19.1084 48.6183 22.8115 46.2287 22.8115H44.2482C46.5892 24.2061 48.491 26.2307 49.7365 28.6543C49.8793 28.9288 49.9665 29.2288 49.9932 29.537C50.0199 29.8453 49.9855 30.1558 49.8921 30.4508C49.7987 30.7457 49.648 31.0194 49.4487 31.2561C49.2494 31.4928 49.0054 31.6878 48.7306 31.8301C48.4684 31.9617 48.1716 32.0084 47.8816 31.9636C47.5916 31.9188 47.3228 31.7847 47.1125 31.5801C46.0228 30.4726 44.6767 29.6512 43.1935 29.1885C42.2686 28.913 41.3099 28.7668 40.3449 28.7539C37.8235 28.7568 35.4062 29.7596 33.6233 31.5423C31.8403 33.3251 30.8373 35.7423 30.8342 38.2637C30.8342 38.4629 30.8403 38.6602 30.8527 38.8555C30.857 38.9222 30.8475 38.9891 30.8249 39.0519C30.8023 39.1148 30.767 39.1724 30.7212 39.2211C30.6755 39.2698 30.6202 39.3086 30.5588 39.3351C30.4974 39.3616 30.4313 39.3751 30.3644 39.375H19.3293C18.7024 39.3575 18.1028 39.1148 17.6401 38.6914C17.1775 38.268 16.8827 37.6922 16.8097 37.0693C16.5799 35.2635 15.8372 33.5614 14.6695 32.1648C13.5019 30.7682 11.9584 29.7356 10.2219 29.1895C9.29688 28.914 8.33826 28.7677 7.37322 28.7549C5.17645 28.7526 3.04729 29.5146 1.35075 30.9102C0.659348 31.4766 -0.322094 30.6777 0.0978279 29.8916Z" fill="black"/>
                            </g>
                            <defs>
                                <clipPath id="clip0_33_1201">
                                    <rect width="50" height="50" fill="white" transform="matrix(-1 0 0 1 50 0)"/>
                                </clipPath>
                            </defs>
                        </svg>

                    <?php } else { ?>
                        <svg width="50" height="50" viewBox="0 0 50 50" fill="none" xmlns="http://www.w3.org/2000/svg">
                            <path d="M44.0334 13.0499C43.9619 12.2976 43.3311 11.724 42.5759 11.724H7.42444C6.66927 11.724 6.03849 12.2976 5.96692 13.0499L3.06624 43.5821C2.90035 45.2113 3.4367 46.8447 4.53802 48.0647C5.64939 49.2948 7.2356 49.9999 8.88908 49.9999H41.1112C42.7647 49.9999 44.3509 49.2947 45.4623 48.0647C46.5622 46.8462 47.0986 45.217 46.9341 43.5907L44.0334 13.0499ZM25.0002 38.0876C19.2517 38.0876 14.7477 29.7246 14.7477 19.0472C14.7477 18.2377 15.4027 17.5826 16.2123 17.5826C17.0219 17.5826 17.6769 18.2377 17.6769 19.0472C17.6769 28.5416 21.5359 35.1583 25.0002 35.1583C28.4644 35.1583 32.3234 28.5416 32.3234 19.0472C32.3234 18.2377 32.9785 17.5826 33.788 17.5826C34.5976 17.5826 35.2527 18.2377 35.2527 19.0472C35.2527 29.7245 30.7486 38.0876 25.0002 38.0876Z" fill="black"/>
                            <path d="M38.9415 2.31822C38.3221 0.996525 37.4196 0.443085 36.773 0.209914C33.2439 -1.04499 29.4604 3.51805 27.437 8.79468H39.3751C39.7746 6.21759 39.6795 3.89203 38.9415 2.31822Z" fill="black"/>
                            <path d="M19.1417 4.66069V4.40077C19.1417 3.59267 19.7982 2.93612 20.6063 2.93612C21.4159 2.93612 22.071 2.28104 22.071 1.47148C22.071 0.661922 21.4159 0.00683594 20.6063 0.00683594C18.1834 0.00683594 16.2124 1.97786 16.2124 4.40077V4.66069C12.3157 5.05693 10.9506 7.01857 10.5352 8.7947H24.819C24.4035 7.01857 23.0384 5.05693 19.1417 4.66069Z" fill="black"/>
                        </svg>

                    <?php } ?>
                    <?php echo '<span>' . esc_attr($method['title']) . '</span>'; ?></label>

            </div>
        <?php } ?>
        </div>

        <?php
    }

    protected static function get_delivery_methods_data() {

        $methods = array();

        $shipping_zones = WC_Shipping_Zones::get_zones();
        $chosen_methods = false;
        if (isset(WC()->session)) {
            $chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
        }
        $chosen_shipping            = isset($chosen_methods[0])? $chosen_methods[0] : '';
        $available_methods_number   = 0;
        $chosen_method_index        = 0;

        /*$affs_ds                    = new OCWS_LP_Affiliates();
        $branches_dropdown          = $affs_ds->get_affiliates_dropdown(true);*/
        $branches_dropdown = OCWS_LP_Local_Pickup::get_affiliates_dropdown_networkwide(true);

        /*$use_simple_cities          = !ocws_use_google_cities_and_polygons();
        $use_polygons               = ocws_use_google_cities_and_polygons();
        $use_google_cities          = ocws_use_google_cities();

        if (is_multisite()) {
            $city_options = OC_Woo_Shipping_Groups::get_all_locations_networkwide(true, $use_simple_cities, $use_polygons, $use_google_cities);
        }
        else {
            $city_options = OC_Woo_Shipping_Groups::get_all_locations(true, $use_simple_cities, $use_polygons, $use_google_cities);
        }*/
        $city_options = OCWS_Advanced_Shipping::get_all_locations_networkwide(true);

        if ($shipping_zones && is_array($shipping_zones)) {
            $count      = 0;
            $cart_total = WC()->cart->cart_contents_total;
            foreach ($shipping_zones as $shipping_zone) {
                $shipping_methods = $shipping_zone['shipping_methods'];
                foreach ($shipping_methods as $shipping_method) {

                    if ( !isset( $shipping_method->enabled ) || 'yes' !== $shipping_method->enabled ) {
                        continue; // not available
                    }

                    // exclude free shipping if cart sum < min shipping min amount
                    if ( $shipping_method->id == 'free_shipping' && $shipping_method->min_amount != 0 ){
                        if ( $cart_total < $shipping_method->min_amount ){
                            continue;
                        }
                    }
                    if (
                        $shipping_method->id == OCWS_LP_Local_Pickup::PICKUP_METHOD_ID && count($branches_dropdown) == 0 ||
                        $shipping_method->id == OCWS_Advanced_Shipping::SHIPPING_METHOD_ID && count($city_options) == 0
                    ) {
                        continue; // considered not available
                    }
                    $is_chosen = ($chosen_shipping && ($chosen_shipping == $shipping_method->id.''.$shipping_method->instance_id));
                    $methods[] = array(
                        'method_id' => $shipping_method->id,
                        'method_instance_id' => $shipping_method->instance_id,
                        'type' => ($shipping_method->id == OCWS_LP_Local_Pickup::PICKUP_METHOD_ID? 'pickup' : 'shipping'),
                        'is_chosen' => $is_chosen,
                        'title' => ocws_translate_shipping_method_title( $shipping_method->title, $shipping_method->id.':'.$shipping_method->instance_id )
                    );
                    if ($is_chosen) {
                        $chosen_method_index = $count;
                    }
                    $count++;
                    $available_methods_number++;
                }
            }
        }

        /*if (!$chosen_method_index && count($methods) > 0) {
            $methods[0]['is_chosen'] = true;
        }*/

        $var = array(
            'available_methods_number' => $available_methods_number,
            'chosen_method_index' => $chosen_method_index,
            'methods' => $methods,
            'pickup_branches' => $branches_dropdown,
            'shipping_locations' => $city_options
        );

        return $var;
    }

    public static function register_taxonomies() {

        register_taxonomy(
            'product_menu',
            array( 'product' ),
            array(
                'hierarchical'          => true,
                'label'                 => __( 'Menus', 'ocws' ),
                'labels'                => array(
                    'name'                  => __( 'Product menus', 'ocws' ),
                    'singular_name'         => __( 'Product menu', 'ocws' ),
                    'menu_name'             => _x( 'Menus', 'Admin menu name', 'ocws' ),
                    'search_items'          => __( 'Search menus', 'ocws' ),
                    'all_items'             => __( 'All menus', 'ocws' ),
                    'parent_item'           => __( 'Parent menu', 'ocws' ),
                    'parent_item_colon'     => __( 'Parent menu:', 'ocws' ),
                    'edit_item'             => __( 'Edit menu', 'ocws' ),
                    'update_item'           => __( 'Update menu', 'ocws' ),
                    'add_new_item'          => __( 'Add new menu', 'ocws' ),
                    'new_item_name'         => __( 'New menu name', 'ocws' ),
                    'not_found'             => __( 'No menus found', 'ocws' ),
                    'item_link'             => __( 'Product Menu Link', 'ocws' ),
                    'item_link_description' => __( 'A link to a product menu.', 'ocws' ),
                ),
                'show_ui'               => true,
                'show_in_quick_edit'    => true,
                'show_in_nav_menus'     => false,
                'query_var'             => is_admin(),
                'capabilities'          => array(
                    'manage_terms' => 'manage_product_terms',
                    'edit_terms'   => 'edit_product_terms',
                    'delete_terms' => 'delete_product_terms',
                    'assign_terms' => 'assign_product_terms',
                ),
                'rewrite'               => false,
            )
        );
    }

    public static function manage_before_loop_item_add_to_cart_actions() {

        /* @var WC_Product $product */
        global $product;
        if (!$product) {
            return;
        }
        if ( ! self::calculate_product_availability( $product->is_purchasable(), $product) ) {
            if (has_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart' )) {
                remove_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart' );
                add_action( 'ocws_hidden_action_woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart' );
            }
            if (has_action( 'woocommerce_after_shop_loop_item', 'oc_woo_add_to_cart_quantity' )) {
                remove_action( 'woocommerce_after_shop_loop_item', 'oc_woo_add_to_cart_quantity' );
                add_action( 'ocws_hidden_action_woocommerce_after_shop_loop_item', 'oc_woo_add_to_cart_quantity' );
            }
            if (has_action( 'woocommerce_after_shop_loop_item', 'oc_add_to_cart_on_hover' )) {
                remove_action( 'woocommerce_after_shop_loop_item', 'oc_add_to_cart_on_hover' );
                add_action( 'ocws_hidden_action_woocommerce_after_shop_loop_item', 'oc_add_to_cart_on_hover' );
            }
        }
    }

    public static function manage_after_loop_item_add_to_cart_actions() {

        /* @var WC_Product $product */
        global $product;
        if (!$product) {
            return;
        }
        if (has_action( 'ocws_hidden_action_woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart' )) {
            remove_action( 'ocws_hidden_action_woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart' );
            add_action( 'woocommerce_after_shop_loop_item', 'woocommerce_template_loop_add_to_cart' );
        }
        if (has_action( 'ocws_hidden_action_woocommerce_after_shop_loop_item', 'oc_woo_add_to_cart_quantity' )) {
            remove_action( 'ocws_hidden_action_woocommerce_after_shop_loop_item', 'oc_woo_add_to_cart_quantity' );
            add_action( 'woocommerce_after_shop_loop_item', 'oc_woo_add_to_cart_quantity' );
        }
        if (has_action( 'ocws_hidden_action_woocommerce_after_shop_loop_item', 'oc_add_to_cart_on_hover' )) {
            remove_action( 'ocws_hidden_action_woocommerce_after_shop_loop_item', 'oc_add_to_cart_on_hover' );
            add_action( 'ocws_hidden_action_ocws_hidden_action_woocommerce_after_shop_loop_item', 'oc_add_to_cart_on_hover' );
        }
        $product_menus_message = '';
        $class = '';
        if ( !$product->is_purchasable() ) {
            $product_menus_message = __('Product not available', 'ocws');
            $class = 'ocws-not-available';
        }
        else if ( ! self::calculate_product_availability( $product->is_purchasable(), $product) ) {
            $menus = OCWS_Deli_Menus::instance();
            $product_dates = $menus->find_product_dates($product->get_id());
            $product_menus_message = self::generate_product_menus_message($product_dates['weekdays'], $product_dates['dates'], $product_dates['prep_days']);
            $class = 'ocws-not-available';
        }
        ?>

        <div class="ocws-availability-message <?php echo esc_attr($class) ?>">
            <?php //var_dump($product_dates, $product_purchasable); ?>
            <?php echo esc_html($product_menus_message); ?>
        </div>
    <?php
    }

    public static function init_current_product_in_loop() {

        /* @var WC_Product $product */
        global $product;
        if (!$product) {
            return;
        }
    }

    public static function generate_product_menus_message( $weekdays, $dates, $prep_days = 0 ) {
        $message = '';
        if (empty($weekdays) && empty($dates)) {
            return $message;
        }
        $wd = array();
        $translated_weekdays = array(
            __('Sunday', 'ocws'),
            __('Monday', 'ocws'),
            __('Tuesday', 'ocws'),
            __('Wednesday', 'ocws'),
            __('Thursday', 'ocws'),
            __('Friday', 'ocws'),
            __('Saturday', 'ocws')
        );
        foreach ($weekdays as $day_number) {
            try {
                $wd[] = $translated_weekdays[intval($day_number)];
            }
            catch (Exception $e) {
                //echo $e->getMessage();
                $wd[] = $day_number;
            }
        }
        foreach ($dates as $dt) {
            $wd[] = $dt;
        }
        $m = __('Can be ordered at ', 'ocws');
        if (count($wd) > 0) {
            $message .= $m . implode(' ', $wd) . ' ';
        }
        if ($prep_days) {
            $message .= sprintf(_n('This product requires %s preparation day', 'This product requires %s preparation days.', $prep_days, 'ocws'), $prep_days);
        }
        return $message;
    }

    protected static function get_posted_data() {

        if (isset($_POST['post_data'])) {

            parse_str($_POST['post_data'], $post_data);

        } else {

            $post_data = $_POST; // fallback for final checkout (non-ajax)

        }
        return $post_data;
    }

    public static function flush_cart_widget_cache() {

        //global $wp_widget_factory;
        //$widget_object = $wp_widget_factory->get_widget_object( $widget_base_id );
        foreach ( array( 'https', 'http' ) as $scheme ) {
            wp_cache_delete( 'woocommerce_widget_cart-'.$scheme, 'widget' );
        }

    }

    public static function handle_custom_query_var( $query_args, $query_vars ) {
        if ( ! empty( $query_vars['product_menu_id'] ) ) {
            $query_args['tax_query'][] = array(
                'taxonomy'          => 'product_menu',
                'field'             => 'id',
                'terms'             => $query_vars['product_menu_id'],
            );
        }
        return $query_args;
    }

    public static function get_products_by_category_ids($product_term_ids) {
        $product_term_args = array(
            'taxonomy' => 'product_cat',
            'include' => $product_term_ids,
            'orderby'  => 'include'
        );
        $product_terms = get_terms($product_term_args);

        $product_term_slugs = [];
        foreach ($product_terms as $product_term) {
            $product_term_slugs[] = $product_term->slug;
        }

        //error_log('$product_term_slugs:');
        //error_log(print_r($product_term_slugs, 1));

        $product_args = array(
            'post_status' => 'publish',
            'limit' => -1,
            'category' => $product_term_slugs,
            //more options according to wc_get_products() docs
        );
        return wc_get_products($product_args);
    }

    public static function show_chip_in_empty_cart() {
        if ( WC()->cart->is_empty() ) {
            self::show_chip_in_cart();
        }
    }

    public static function show_chip_in_not_empty_cart() {
        if ( ! WC()->cart->is_empty() ) {
            self::show_chip_in_cart();
        }
    }

    public static function show_chip_in_checkout() {
        if ( ! self::needs_shipping()) {
            return;
        }
        if (!ocws_deli_style_checkout()) {
            return;
        }
        $data = self::get_checkout_delivery_data();
        $weekday_str = '';
        $translated_weekdays = array(
            __('Sunday', 'ocws'),
            __('Monday', 'ocws'),
            __('Tuesday', 'ocws'),
            __('Wednesday', 'ocws'),
            __('Thursday', 'ocws'),
            __('Friday', 'ocws'),
            __('Saturday', 'ocws')
        );
        $date_str = $data['delivery_date'];
        if (!empty($data['delivery_date'])) {
            try {
                $dt = Carbon::createFromFormat('d/m/Y', $data['delivery_date'], ocws_get_timezone());
                $date_str = $dt->format('d.m');
                $weekday_str = isset($translated_weekdays[$dt->dayOfWeek])? $translated_weekdays[$dt->dayOfWeek] : 'ט';
            }
            catch (InvalidArgumentException $e) {}
        }

    ?>
        <div class="checkout-delivery-data-chip">
            <div class="cds-data">
                <span class="cds-method"><?php echo esc_html($data['delivery_type_text']) ?></span> <span class="cds-h-divider">|</span>
                <span class="cds-address"><?php echo esc_html($data['delivery_location_text']) ?></span> <span class="cds-h-divider">|</span>
                <span class="cds-date"><?php echo esc_html($weekday_str) ?> <?php echo esc_html($date_str) ?><?php //echo esc_html($data['delivery_date']) ?></span> <span class="cds-h-divider">|</span>
                <span class="cds-time"><?php echo esc_html($data['delivery_slot_start']) ?> - <?php echo esc_html($data['delivery_slot_end']) ?></span>
            </div>
            <a class="cds-button-change" data-href="<?php echo esc_url(home_url('#change-shipping')) ?>"><?php echo esc_html(_x('Change', 'Mini-cart shipping settings summary', 'ocws')) ?></a>
        </div>
    <?php
    }

    public static function show_chip_in_cart() {

        if ( ocws_use_deli_for_regular_products() ) {
            return;
        }
        $data = self::get_current_delivery_data();
        $weekday_str = '';
        $translated_weekdays = array(
            __('Sunday', 'ocws'),
            __('Monday', 'ocws'),
            __('Tuesday', 'ocws'),
            __('Wednesday', 'ocws'),
            __('Thursday', 'ocws'),
            __('Friday', 'ocws'),
            __('Saturday', 'ocws')
        );
        /*
            array(7) {
  ["chosen_shipping_method"]=&gt;
  string(32) "oc_woo_advanced_shipping_method8"
  ["delivery_type"]=&gt;
  string(8) "shipping"
  ["delivery_date"]=&gt;
  string(10) "12/03/2024"
  ["delivery_slot_start"]=&gt;
  string(5) "08:00"
  ["delivery_slot_end"]=&gt;
  string(5) "12:00"
  ["delivery_location_code"]=&gt;
  string(27) "ChIJH3w7GaZMHRURkD-WwKJy-8E"
  ["delivery_type_text"]=&gt;
  string(10) "משלוח"
}
*/
    ?>

        <?php self::output_removed_cart_contents(); ?>

        <div class="product-error-notice">
            <?php
            if (isset(WC()->session)) {
                $error_product = WC()->session->get( 'deli_add_to_cart_error_product' );

                if ($error_product) {//echo 'error product: '.$error_product;
                    $product = wc_get_product(intval($error_product));
                    if ($product) {
                        ?>
                        <span class="cds-notice"><?php echo esc_html(sprintf(_x('The product %s is not available on the chosen date.', 'Mini-cart delivery settings notice', 'ocws'), $product->get_title())) ?></span>
                        <?php
                        //WC()->session->set( 'deli_add_to_cart_error_product', null );
                    }
                }
            }
            else {
                echo 'no session';
            }
            $date_str = $data['delivery_date'];
            if (!empty($data['delivery_date'])) {
                try {
                    $dt = Carbon::createFromFormat('d/m/Y', $data['delivery_date'], ocws_get_timezone());
                    $date_str = $dt->format('d.m');
                    $weekday_str = isset($translated_weekdays[$dt->dayOfWeek])? $translated_weekdays[$dt->dayOfWeek] : 'ט';
                }
                catch (InvalidArgumentException $e) {}
            }
            ?>
        </div>
        <?php
        if ( ! $date_str) {
            ?>
            <div class="delivery-data-chip">
                <div class="cds-data">
                    <span class="cds-address"><?php echo esc_html($data['delivery_type']=='shipping'? self::get_chosen_address_text() : self::get_chosen_pickup_branch_text()) ?></span>
                </div>
                <input class="cds-button-change <?php echo esc_attr(WC()->cart->is_empty()? 'empty-cart' : 'not-empty-cart') ?>" type="button" value="<?php echo esc_attr(_x('Change', 'Mini-cart shipping settings summary', 'ocws')) ?>"/>
            </div>
            <?php
            return;
        }
        ?>
        <div class="delivery-data-chip">
            <div class="cds-data">
                <span class="cds-method"><?php echo esc_html($data['delivery_type_text']) ?></span> <span class="cds-h-divider">|</span>
                <span class="cds-address"><?php echo esc_html($data['delivery_type']=='shipping'? self::get_chosen_address_text() : self::get_chosen_pickup_branch_text()) ?></span> <span class="cds-h-divider">|</span>
                <span class="cds-date"><?php echo esc_html($weekday_str) ?> <?php echo esc_html($date_str) ?></span> <span class="cds-h-divider">|</span>
                <span class="cds-time"><?php echo esc_html($data['delivery_slot_start']) ?> - <?php echo esc_html($data['delivery_slot_end']) ?></span>
            </div>
            <input class="cds-button-change <?php echo esc_attr(WC()->cart->is_empty()? 'empty-cart' : 'not-empty-cart') ?>" type="button" value="<?php echo esc_attr(_x('Change', 'Mini-cart shipping settings summary', 'ocws')) ?>"/>
        </div>
    <?php
        //echo $data['delivery_location_code'];
        if ($data['delivery_type'] == 'shipping') {
            if (ocws_use_google_cities_and_polygons()) {
                $location_code = WC()->session->get('chosen_city_code', '' );            }
            else {
                $location_code = WC()->session->get('chosen_shipping_city', '' );;
            }
            self::show_min_total_notice($location_code);
        }
    }

    public static function show_min_total_notice( $location_code ) {
        if (!$location_code) {
            $data = self::get_current_delivery_data();
            if ($data['delivery_type'] == 'shipping') {
                if (ocws_use_google_cities_and_polygons()) {
                    $location_code = WC()->session->get('chosen_city_code', '' );            }
                else {
                    $location_code = WC()->session->get('chosen_shipping_city', '' );;
                }
            }
        }
        if ($location_code) {
            $data_store = new OC_Woo_Shipping_Group_Data_Store();
            $group_id = $data_store->get_group_by_location( $location_code );
            $location_enabled = $data_store->is_location_enabled( $location_code );
            $group_enabled = $data_store->is_group_enabled( $group_id );
            if (!$group_id || !$location_enabled || !$group_enabled) {
                return;
            }
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

                if ( $total < $min_total_to_enable ) {
                    $message = sprintf(__('Minimum order for shipping is %s NIS, you need %s NIS more', 'ocws'), $min_total_to_enable, ($min_total_to_enable - $total));
                    ?>
                    <div class="ocws-cart-shipping-notes">
		                <div class="ocws-notice-notice">
                        <span class="important-notice not_passed_min_total"><?php echo esc_html($message) ?></span>
                        </div>
                    </div>
                    <?php
                }
            }
        }

    }

    public static function show_chip_in_cart_icon() {
        /*if ( ! self::needs_shipping()) {
            return;
        }*/
        $data = self::get_current_delivery_data();
        $date_str = '';
        $weekday_str = '';
        $translated_weekdays = array(
            __('Sunday', 'ocws'),
            __('Monday', 'ocws'),
            __('Tuesday', 'ocws'),
            __('Wednesday', 'ocws'),
            __('Thursday', 'ocws'),
            __('Friday', 'ocws'),
            __('Saturday', 'ocws')
        );
        if (!empty($data['delivery_date'])) {
            try {
                $dt = Carbon::createFromFormat('d/m/Y', $data['delivery_date'], ocws_get_timezone());
                $date_str = $dt->format('d.m');
                $weekday_str = isset($translated_weekdays[$dt->dayOfWeek])? $translated_weekdays[$dt->dayOfWeek] : '';
            }
            catch (InvalidArgumentException $e) {}
        }

        $peddingProductAdded = false;
        if (isset(WC()->session)) {
            $peddingProductAdded = WC()->session->get( 'deli_add_to_cart_success', false );
            WC()->session->set( 'deli_add_to_cart_success', null );
        }
        ?>
        <span class="ocws-minicart-chip" data-addedtocart="<?php echo ($peddingProductAdded? $peddingProductAdded : '') ?>">
            <span class="ocws-chip-dayofweek"><?php echo esc_html($weekday_str) ?></span>
            <span class="ocws-chip-date"><?php echo esc_html($date_str) ?></span>
        </span>
        <?php
    }

    public static function show_pending_product_chip() {
        $product_id = '';
        $quantity = 0;
        if (isset(WC()->session)) {
            $product_id = WC()->session->get( 'deli_add_to_cart_pending_product' );
            $quantity = WC()->session->get( 'deli_add_to_cart_pending_quantity' );
        }
        if ($product_id && $quantity) { ?>
            <span id="ocws-pending-product-chip" class="ocws-pending-product-chip" style="display: none;" data-product="<?php echo esc_attr($product_id) ?>">

            </span>
        <?php }
    }

    public static function handle_pending_product() {
        // error_log('handle_pending_product');
        $product_id = '';
        $variation_id = '';
        $quantity = 0;
        $pending_product_post = array();
        $chosen_shipping_methods = array();
        if (isset(WC()->session)) {
            $chosen_shipping_methods = WC()->session->get( 'chosen_shipping_methods', array() );
            $product_id = WC()->session->get( 'deli_add_to_cart_pending_product' );
            $variation_id = WC()->session->get( 'deli_add_to_cart_pending_variation', '' );
            $quantity = WC()->session->get( 'deli_add_to_cart_pending_quantity' );
            $pending_product_post = WC()->session->get( 'deli_add_to_cart_pending_product_post', array() );
            if (!is_array($pending_product_post)) {
                $pending_product_post = array();
            }
            WC()->session->set( 'deli_add_to_cart_pending_product', null );
            WC()->session->set( 'deli_add_to_cart_pending_variation', null );
            WC()->session->set( 'deli_add_to_cart_pending_quantity', null );
            WC()->session->set( 'deli_add_to_cart_pending_product_post', null );
            // error_log('Pending product '.$product_id);
            // error_log('Pending quantity '.$quantity);
        }
        if ($product_id && $quantity) {
            $product_id        = apply_filters( 'woocommerce_add_to_cart_product_id', absint( $product_id ) );
            $product           = wc_get_product( $product_id );
            $quantity          = empty( $quantity ) ? 1 : wc_stock_amount( wp_unslash( $quantity ) );
            $passed_validation = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity );
            $product_status    = get_post_status( $product_id );
            $variation         = array();

            if ($product) {
                if ( is_a( $product, 'WC_Product_Variable' ) && !empty($variation_id) ) {
                    $product_variation = wc_get_product($variation_id);
                    if ($product_variation) {
                        $variation = $product_variation->get_variation_attributes();
                    }
                }
                else if ($product->get_type() == 'variation') {
                    $variation_id = $product_id;
                    $product_id   = $product->get_parent_id();
                    $variation    = $product->get_variation_attributes();
                }
                else {
                    $variation_id = 0;
                }
            }

            $tmp_post_data = $_POST;
            $_POST = $pending_product_post;
            if ( $passed_validation && false !== WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variation ) && 'publish' === $product_status ) {

                if (isset(WC()->session)) {
                    WC()->session->set( 'deli_add_to_cart_success', $product_id );
                }

            } else {

                if (isset(WC()->session)) {
                    WC()->session->set( 'deli_add_to_cart_error_product', $product_id );
                }
            }
            $_POST = $tmp_post_data;
        }
        if (isset(WC()->session) && !empty($chosen_shipping_methods)) {
            WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );
        }
    }

    public static function show_send_to_other_person_fields($checkout) {
        do_action('ocws_send_to_other_person_fields');
    }



}