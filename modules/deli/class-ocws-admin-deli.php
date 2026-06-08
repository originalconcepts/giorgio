<?php

defined( 'ABSPATH' ) || exit;

class OCWS_Admin_Deli {

    public static function init() {

        add_action( 'admin_enqueue_scripts', array(__CLASS__, 'enqueue_styles'), 10, 0 );
        add_action( 'admin_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'), 99, 0 );

        add_action( 'restrict_manage_posts', array( __CLASS__, 'restrict_manage_posts' ) );
        // Add form.
        add_action( 'product_menu_add_form_fields', array( __CLASS__, 'add_menu_fields' ) );
        add_action( 'product_menu_edit_form_fields', array( __CLASS__, 'edit_menu_fields' ), 30 );
        add_action( 'created_term', array( __CLASS__, 'save_menu_fields' ), 10, 3 );
        add_action( 'edit_term', array( __CLASS__, 'save_menu_fields' ), 10, 3 );

        add_action (
            'save_post',
            function ($post_id, \WP_Post $post, $update) {

                if( get_post_type( $post_id ) === 'product' || get_post_status( $post_id ) === 'publish' ) {

                    if (!$product = wc_get_product( $post )) {
                        return;
                    }
                    $menus = OCWS_Deli_Menus::instance();
                    $menus->handle_saved_product( $product );
                }
            },
            10,
            3
        );

    }

    public static function enqueue_styles() {
        global $pagenow;
        if (($pagenow == 'edit-tags.php' || $pagenow == 'term.php') && (isset($_GET['taxonomy']) && $_GET['taxonomy'] == 'product_menu')) {
            //wp_enqueue_style('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css', array(), null);
        }
        wp_enqueue_style( 'deli-admin', OCWS_ADMIN_ASSESTS_URL . 'modules/deli/assets/css/deli-admin.css', array(), null, 'all' );
    }

    public static function enqueue_scripts() {
        global $pagenow;
        if (($pagenow == 'edit-tags.php' || $pagenow == 'term.php') && (isset($_GET['taxonomy']) && $_GET['taxonomy'] == 'product_menu')) {
            //wp_enqueue_script( 'bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@4.6.0/dist/js/bootstrap.bundle.min.js', array ( 'jquery' ), null, true);
            wp_enqueue_script( 'ocws-admin-jquery-ui', OCWS_ADMIN_ASSESTS_URL . 'js/jquery-ui.js', array( 'jquery' ), null, true );
            wp_enqueue_script( 'jquery-multidatespicker', OCWS_ADMIN_ASSESTS_URL . 'js/jquery-ui.multidatespicker' . '' . '.js', array( 'jquery', 'jquery-ui-datepicker' ), null, true );
            wp_enqueue_script( 'select2', OCWS_ADMIN_ASSESTS_URL . 'js/select2/select2.min.js', array( 'jquery' ), null, true );
        }
        wp_register_script( "deli-admin-js", OCWS_ADMIN_ASSESTS_URL . 'modules/deli/assets/js/deli-admin.js', array('jquery'), null, true );
        wp_localize_script( 'deli-admin-js', 'ajax_deli', array( 'ajaxurl' => admin_url( 'admin-ajax.php' )));

        wp_enqueue_script( 'deli-admin-js' );
    }

    public static function restrict_manage_posts() {
        global $typenow;

        if ( 'product' === $typenow ) {

            $menu_count = (int) wp_count_terms( 'product_menu' );

            if ( $menu_count <= 100 ) {
                self::product_dropdown_menus(
                    array(
                        'option_select_text' => __( 'Filter by menu', 'ocws' ),
                        'hide_empty'         => 0,
                    )
                );
            } else {
                //
            }
        }
    }

    public static function product_dropdown_menus( $args = array() ) {
        global $wp_query;

        $args = wp_parse_args(
            $args,
            array(
                'pad_counts'         => 1,
                'show_count'         => 0,
                'hierarchical'       => 1,
                'hide_empty'         => 1,
                'show_uncategorized' => 1,
                'orderby'            => 'name',
                'selected'           => isset( $wp_query->query_vars['product_menu'] ) ? $wp_query->query_vars['product_menu'] : '',
                'show_option_none'   => __( 'Select a menu', 'ocws' ),
                'option_none_value'  => '',
                'value_field'        => 'slug',
                'taxonomy'           => 'product_menu',
                'name'               => 'product_menu',
                'class'              => 'dropdown_product_menu',
            )
        );

        if ( 'order' === $args['orderby'] ) {
            $args['orderby']  = 'meta_value_num';
            $args['meta_key'] = 'order'; // phpcs:ignore
        }

        wp_dropdown_categories( $args );
    }

    public static function add_menu_fields() {
        ?>
        <div class="form-field term-availability-type-wrap">
            <label for="availability_type"><?php esc_html_e( 'Availability type', 'ocws' ); ?></label>
            <select id="availability_type" name="availability_type" class="postform">
                <option value="weekdays" selected><?php esc_html_e( 'Week days', 'ocws' ); ?></option>
                <option value="dates"><?php esc_html_e( 'Specific dates', 'ocws' ); ?></option>
            </select>
        </div>
        <div class="form-field term-prep-days-wrap">
            <label for="prep_days"><?php esc_html_e( 'Preparation time in days', 'ocws' ); ?></label>
            <select id="prep_days" name="prep_days" class="postform">
                <option value="0" selected><?php esc_html_e( 'Irrelevant', 'ocws' ); ?></option>
                <option value="1"><?php esc_html_e( '1 day', 'ocws' ); ?></option>
                <option value="2"><?php esc_html_e( '2 days', 'ocws' ); ?></option>
                <option value="3"><?php esc_html_e( '3 days', 'ocws' ); ?></option>
                <option value="4"><?php esc_html_e( '4 days', 'ocws' ); ?></option>
                <option value="5"><?php esc_html_e( '5 days', 'ocws' ); ?></option>
            </select>
        </div>
        <div id="term-weekdays-wrap" class="form-field term-weekdays-wrap">
            <label><?php esc_html_e( 'Week days', 'ocws' ); ?></label>
            <input type="hidden" id="weekdays-hidden" name="weekdays" value="">
            <div id="weekdays">
                <label>
                    <input type="checkbox" id="weekdays-0" data-weekday="0"
                           value="1"><?php echo __('Sunday', 'ocws') ?>
                </label>
                <label>
                    <input type="checkbox" id="weekdays-1" data-weekday="1"
                           value="1"><?php echo __('Monday', 'ocws') ?>
                </label>
                <label>
                    <input type="checkbox" id="weekdays-2" data-weekday="2"
                           value="1"><?php echo __('Tuesday', 'ocws') ?>
                </label>
                <label>
                    <input type="checkbox" id="weekdays-3" data-weekday="3"
                           value="1"><?php echo __('Wednesday', 'ocws') ?>
                </label>
                <label>
                    <input type="checkbox" id="weekdays-4" data-weekday="4"
                           value="1"><?php echo __('Thursday', 'ocws') ?>
                </label>
                <label>
                    <input type="checkbox" id="weekdays-5" data-weekday="5"
                           value="1"><?php echo __('Friday', 'ocws') ?>
                </label>
                <label>
                    <input type="checkbox" id="weekdays-6" data-weekday="6"
                           value="1"><?php echo __('Saturday', 'ocws') ?>
                </label>
            </div>

            <div class="clear"></div>
        </div>
        <div id="term-dates-wrap" class="form-field term-dates-wrap" style="display:none;">
            <label><?php esc_html_e( 'Specific dates', 'ocws' ); ?></label>
            <div id="dates">
                <input name="dates" style="" type="text" id="deli-term-dates-datepicker" readonly="readonly"
                       value="">
            </div>

            <div class="clear"></div>
        </div>
        <?php
    }

    public static function edit_menu_fields( $term ) {

        $availability_type = get_term_meta( $term->term_id, 'availability_type', true );
        $weekdays = get_term_meta( $term->term_id, 'weekdays', true );
        $dates = get_term_meta( $term->term_id, 'dates', true );
        $categories = get_term_meta( $term->term_id, 'categories', true );
        $cat_products = get_term_meta( $term->term_id, 'products', true );
        /*if (!$dates) {
            $dates = array();
        }
        else {
            $dates = ocws_dates_list_to_array( $dates, true );
        }*/
        if (!$weekdays && $weekdays != 0) {
            $weekdays = array();
        }
        else {
            $weekdays = ocws_numbers_list_to_array( $weekdays );
        }
        if (!$dates) {
            $dates = array();
        }
        else {
            $dates = ocws_dates_list_to_array( $dates, true );
        }
        if (!$categories) {
            $categories = array();
        }
        else {
            $categories = ocws_numbers_list_to_array( $categories );
        }
        $products = array();
        if ($cat_products) {
            $products_raw = explode(';', $cat_products);
            foreach ($products_raw as $catprods) {
                if (!empty($catprods)) {
                    $res = explode(':', $catprods, 2);
                    if (count($res) == 2) {
                        $products[intval($res[0])] = ocws_numbers_list_to_array($res[1]);
                    }
                }
            }
        }
        $products_hidden_value = array();
        foreach ($products as $cat_id => $prod_list) {
            $products_hidden_value[] = $cat_id . ':' . implode(',', $prod_list);
        }
        $products_hidden_value = implode(';', $products_hidden_value);

        $prep_days = get_term_meta( $term->term_id, 'prep_days', true );
        if (is_numeric($prep_days)) {
            $prep_days = intval($prep_days);
        }
        else {
            $prep_days = 0;
        }

        ?>
        <tr class="form-field term-availability-type-wrap">
            <th scope="row" valign="top"><label for="availability_type"><?php esc_html_e( 'Availability type', 'ocws' ); ?></label></th>
            <td>
                <select id="availability_type" name="availability_type" class="postform">
                    <option value="weekdays" <?php selected( 'weekdays', $availability_type ); ?>><?php esc_html_e( 'Week days', 'ocws' ); ?></option>
                    <option value="dates" <?php selected( 'dates', $availability_type ); ?>><?php esc_html_e( 'Specific dates', 'ocws' ); ?></option>
                </select>
            </td>
        </tr>
        <tr class="form-field term-prep-days-wrap">
            <th scope="row" valign="top"><label for="prep_days"><?php esc_html_e( 'Preparation time in days', 'ocws' ); ?></label></th>
            <td>
                <select id="prep_days" name="prep_days" class="postform">
                    <option value="0" <?php selected( 0, $prep_days ); ?>><?php esc_html_e( 'Irrelevant', 'ocws' ); ?></option>
                    <option value="1" <?php selected( 1, $prep_days ); ?>><?php esc_html_e( '1 day', 'ocws' ); ?></option>
                    <option value="2" <?php selected( 2, $prep_days ); ?>><?php esc_html_e( '2 days', 'ocws' ); ?></option>
                    <option value="3" <?php selected( 3, $prep_days ); ?>><?php esc_html_e( '3 days', 'ocws' ); ?></option>
                    <option value="4" <?php selected( 4, $prep_days ); ?>><?php esc_html_e( '4 days', 'ocws' ); ?></option>
                    <option value="5" <?php selected( 5, $prep_days ); ?>><?php esc_html_e( '5 days', 'ocws' ); ?></option>
                </select>
            </td>
        </tr>
        <tr id="term-weekdays-wrap" class="form-field term-weekdays-wrap" style="<?php echo ($availability_type === 'weekdays' || $availability_type === ''? '' : 'display: none;') ?>">
            <th scope="row" valign="top"><label><?php esc_html_e( 'Week days', 'ocws' ); ?></label></th>
            <td>
                <input type="hidden" id="weekdays-hidden" name="weekdays" value="<?php echo esc_attr(implode(',', $weekdays)); ?>">
                <div id="weekdays">
                    <label>
                        <input type="checkbox" id="weekdays-0" data-weekday="0"
                               value="1" <?php if (in_array('0', $weekdays)) { ?> checked="checked"<?php } ?>><?php echo __('Sunday', 'ocws') ?>
                    </label>
                    <label>
                        <input type="checkbox" id="weekdays-1" data-weekday="1"
                               value="1" <?php if (in_array('1', $weekdays)) { ?> checked="checked"<?php } ?>><?php echo __('Monday', 'ocws') ?>
                    </label>
                    <label>
                        <input type="checkbox" id="weekdays-2" data-weekday="2"
                               value="1" <?php if (in_array('2', $weekdays)) { ?> checked="checked"<?php } ?>><?php echo __('Tuesday', 'ocws') ?>
                    </label>
                    <label>
                        <input type="checkbox" id="weekdays-3" data-weekday="3"
                               value="1" <?php if (in_array('3', $weekdays)) { ?> checked="checked"<?php } ?>><?php echo __('Wednesday', 'ocws') ?>
                    </label>
                    <label>
                        <input type="checkbox" id="weekdays-4" data-weekday="4"
                               value="1" <?php if (in_array('4', $weekdays)) { ?> checked="checked"<?php } ?>><?php echo __('Thursday', 'ocws') ?>
                    </label>
                    <label>
                        <input type="checkbox" id="weekdays-5" data-weekday="5"
                               value="1" <?php if (in_array('5', $weekdays)) { ?> checked="checked"<?php } ?>><?php echo __('Friday', 'ocws') ?>
                    </label>
                    <label>
                        <input type="checkbox" id="weekdays-6" data-weekday="6"
                               value="1" <?php if (in_array('6', $weekdays)) { ?> checked="checked"<?php } ?>><?php echo __('Saturday', 'ocws') ?>
                    </label>
                </div>

                <div class="clear"></div>
            </td>
        </tr>
        <tr id="term-dates-wrap" class="form-field term-dates-wrap" style="<?php echo ($availability_type === 'dates'? '' : 'display: none;') ?>">
            <th scope="row" valign="top"><label><?php esc_html_e( 'Specific dates', 'ocws' ); ?></label></th>
            <td>
                <div id="dates">
                    <input name="dates" style="" type="text" id="deli-term-dates-datepicker" readonly="readonly"
                           value="" data-value="<?php echo esc_attr(implode(', ', $dates)); ?>">
                </div>

                <div class="clear"></div>
            </td>
        </tr>
        <tr id="term-categories-wrap" class="form-field term-categories-wrap">
            <th scope="row" valign="top"><label><?php esc_html_e( 'Assign categories to the menu', 'ocws' ); ?></label></th>
            <td>
                <input id="hidden-cat-ids" type="hidden" name="categories" value="<?php echo esc_attr(implode(',', $categories)); ?>">
                <input id="hidden-cat-prods" type="hidden" name="products" value="<?php echo esc_attr($products_hidden_value); ?>">

                <div id="categories">
                    <?php self::output_categories_dropdown(); ?>

                    <input id="menu-add-cat-btn" type="button" class="button button-primary" value="<?php echo esc_attr(__('Add category', 'ocws')); ?>">
                </div>

                <div id="menu-categories-list" class="list-group list-group-radio d-grid gap-2 border-0">

                    <?php foreach ($categories as $category_id) { ?>
                    <?php
                        $term = get_term_by( 'id', $category_id, 'product_cat', 'ARRAY_A' );
                        $category_name = $term['name'];
                        $excluded = array();
                        if (isset($products[$category_id])) {
                            $excluded = $products[$category_id];
                        }
                    ?>
                    <div class="position-relative">
                        <a href="javascript:void(0)" data-cat-id="<?php echo esc_attr($category_id); ?>" class="delete-category-from-menu position-absolute end-0 me-3 fs-5" style="z-index: 2; top: 10px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-x-circle" viewBox="0 0 16 16">
                                    <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/>
                                    <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708"/>
                                </svg>
                        </a>
                        <label data-cat-id="<?php echo esc_attr($category_id); ?>" class="list-group-item py-3 pe-5">
                            <div class="">
                                <span class="category-name fw-semibold"><?php echo esc_html($category_name); ?></span>
                                <span class="exclude-product-cat-button" data-cat-id="<?php echo esc_attr($category_id); ?>" style=""><?php echo esc_html(__('Exclude product(s)', 'ocws')); ?>
                                    <svg aria-hidden="true" role="img" focusable="false" class="deli-svg-icon-chevron-down" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><g><path fill="none" d="M0,0h24v24H0V0z"></path></g><g><path d="M7.41,8.59L12,13.17l4.59-4.58L18,10l-6,6l-6-6L7.41,8.59z"></path></g></svg>
                                </span>
                            </div>

                            <div class="exclude-prod-div" data-cat-id="<?php echo esc_attr($category_id); ?>">
                                <select style="width: 300px;" name="menu-exclude-product-select2" class="menu-exclude-product-select2" value=""><option></option></select>

                                <input type="button" class="menu-ex-prod-btn button button-primary" value="<?php echo esc_attr(__('Exclude product', 'ocws')); ?>" disabled>
                            </div>
                            <div class="exclude-product-cat-list d-flex gap-2 justify-content-start py-3">
                                <?php foreach ($excluded as $prod_id) { ?>
                                    <?php
                                    $product = wc_get_product($prod_id);
                                    $prod_name = $product? $product->get_name() : $prod_id;
                                    ?>
                                <span data-prod-id="<?php echo esc_attr($prod_id); ?>" class="badge d-flex align-items-center p-1 pe-2 text-success-emphasis bg-success-subtle border border-success-subtle rounded-pill">
                                    <?php echo esc_html($prod_name); ?>
                                    <span class="vr mx-2"></span>
                                    <a data-prod-id="<?php echo esc_attr($prod_id); ?>" href="javascript:void(0)">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-x-circle-fill" viewBox="0 0 16 16">
                                            <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646a.5.5 0 0 0-.708-.708L8 7.293z"/>
                                        </svg>
                                    </a>
                                </span>
                                <?php } ?>
                            </div>
                        </label>
                    </div>
                    <?php } ?>

                </div>
                <script type="text/html" id="tmpl-categories-template">
                    <div class="position-relative">
                        <a href="javascript:void(0)" data-cat-id="{{{ data.cat_id }}}" class="delete-category-from-menu position-absolute end-0 me-3 fs-5" style="z-index: 2; top: 10px;">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-x-circle" viewBox="0 0 16 16">
                                <path d="M8 15A7 7 0 1 1 8 1a7 7 0 0 1 0 14m0 1A8 8 0 1 0 8 0a8 8 0 0 0 0 16"/>
                                <path d="M4.646 4.646a.5.5 0 0 1 .708 0L8 7.293l2.646-2.647a.5.5 0 0 1 .708.708L8.707 8l2.647 2.646a.5.5 0 0 1-.708.708L8 8.707l-2.646 2.647a.5.5 0 0 1-.708-.708L7.293 8 4.646 5.354a.5.5 0 0 1 0-.708"/>
                            </svg>
                        </a>
                        <label data-cat-id="{{{ data.cat_id }}}" class="list-group-item py-3 pe-5">
                            <div class="">
                                <span class="category-name fw-semibold">{{{ data.cat_name }}}</span>
                                <span class="exclude-product-cat-button" data-cat-id="{{{ data.cat_id }}}" style=""><?php echo esc_html(__('Exclude product(s)', 'ocws')); ?>
                                    <svg aria-hidden="true" role="img" focusable="false" class="deli-svg-icon-chevron-down" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="currentColor"><g><path fill="none" d="M0,0h24v24H0V0z"></path></g><g><path d="M7.41,8.59L12,13.17l4.59-4.58L18,10l-6,6l-6-6L7.41,8.59z"></path></g></svg>
                                </span>
                            </div>

                            <div class="exclude-prod-div" data-cat-id="{{{ data.cat_id }}}">
                                <select style="width: 300px;" name="menu-exclude-product-select2" class="menu-exclude-product-select2" value=""><option></option></select>

                                <input type="button" class="menu-ex-prod-btn button button-primary" value="<?php echo esc_attr(__('Exclude product', 'ocws')); ?>" disabled>
                            </div>
                            <div class="exclude-product-cat-list d-flex gap-2 justify-content-start py-3">

                            </div>
                        </label>
                    </div>
                </script>

                <script type="text/html" id="tmpl-excluded-products-template">
                    <span data-prod-id="{{{ data.prod_id }}}" class="badge d-flex align-items-center p-1 pe-2 text-success-emphasis bg-success-subtle border border-success-subtle rounded-pill">
                                    {{{ data.prod_name }}}
                        <span class="vr mx-2"></span>
                        <a data-prod-id="{{{ data.prod_id }}}" href="javascript:void(0)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-x-circle-fill" viewBox="0 0 16 16">
                                <path d="M16 8A8 8 0 1 1 0 8a8 8 0 0 1 16 0M5.354 4.646a.5.5 0 1 0-.708.708L7.293 8l-2.647 2.646a.5.5 0 0 0 .708.708L8 8.707l2.646 2.647a.5.5 0 0 0 .708-.708L8.707 8l2.647-2.646a.5.5 0 0 0-.708-.708L8 7.293z"/>
                            </svg>
                        </a>
                    </span>
                </script>

                <div class="clear"></div>
            </td>
        </tr>
        <?php
    }

    /**
     * Save menu fields
     *
     * @param mixed  $term_id Term ID being saved.
     * @param mixed  $tt_id Term taxonomy ID.
     * @param string $taxonomy Taxonomy slug.
     */
    public static function save_menu_fields( $term_id, $tt_id = '', $taxonomy = '' ) {
        if ( isset( $_POST['availability_type'] ) && 'product_menu' === $taxonomy ) {
            update_term_meta( $term_id, 'availability_type', esc_attr( $_POST['availability_type'] ) );
        }
        if ( isset( $_POST['prep_days'] ) && 'product_menu' === $taxonomy ) {
            update_term_meta( $term_id, 'prep_days', esc_attr( $_POST['prep_days'] ) );
        }
        if ( isset( $_POST['weekdays'] ) && 'product_menu' === $taxonomy ) {
            update_term_meta( $term_id, 'weekdays', esc_attr( $_POST['weekdays'] ) );
        }
        if ( isset( $_POST['dates'] ) && 'product_menu' === $taxonomy ) {
            update_term_meta( $term_id, 'dates', esc_attr( $_POST['dates'] ) );
        }
        if ( isset( $_POST['categories'] ) && 'product_menu' === $taxonomy ) {
            update_term_meta( $term_id, 'categories', esc_attr( $_POST['categories'] ) );
        }
        if ( isset( $_POST['products'] ) && 'product_menu' === $taxonomy ) {
            update_term_meta( $term_id, 'products', esc_attr( $_POST['products'] ) );
        }
        $categories = get_term_meta( $term_id, 'categories', true );
        $cat_products = get_term_meta( $term_id, 'products', true );
        if (!$categories) {
            $categories = array();
        }
        else {
            $categories = ocws_numbers_list_to_array( $categories );
        }
        $products = array();
        if ($cat_products) {
            $products_raw = explode(';', $cat_products);
            foreach ($products_raw as $catprods) {
                if (!empty($catprods)) {
                    $res = explode(':', $catprods, 2);
                    if (count($res) == 2) {
                        $prods = ocws_numbers_list_to_array($res[1]);
                        foreach ($prods as $prod_id) {
                            $products[] = $prod_id;
                        }
                    }
                }
            }
        }
        $products_in_menu = wc_get_products( array(
            'status' => 'publish',
            'limit' => -1,
            'product_menu_id' => array($term_id)
        ) );
        foreach ($products_in_menu as $product) {
            if ($product) {
                /* @var WC_Product $product */
                wp_remove_object_terms($product->get_id(), $term_id, 'product_menu');
            }
        }
        if (count($categories)) {
            $products_by_cats = OCWS_Deli::get_products_by_category_ids($categories);
            foreach ($products_by_cats as $product) {
                if ($product) {
                    if (!in_array($product->get_id(), $products)) {
                        wp_set_object_terms($product->get_id(), $term_id, 'product_menu');
                    }
                }
            }
        }

    }

    public static function output_categories_dropdown() {

        $args = array(
            'pad_counts'         => 1,
            'show_count'         => 0,
            'hierarchical'       => 1,
            'hide_empty'         => 1,
            'show_uncategorized' => 1,
            'orderby'            => 'name',
            'selected'           => '',
            'show_option_none'   => __( 'Select a category', 'woocommerce' ),
            'option_none_value'  => '',
            'value_field'        => 'id', //'slug',
            'taxonomy'           => 'product_cat',
            'name'               => 'product_cat',
            'class'              => 'dropdown_product_cat',
        );

        wp_dropdown_categories( $args );
    }
}