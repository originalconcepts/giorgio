<?php


class OC_Woo_Local_Pickup_Method extends WC_Shipping_Method {

    const METHOD_ID = 'oc_woo_local_pickup_method';

    const NOTICE_TYPE = 'ocws_lp_notice';

    public function __construct( $instance_id = 0 ) {
        $this->id = self::METHOD_ID;
        $this->instance_id = absint( $instance_id );
        $this->method_title = __( 'OC Local Pickup Method', 'ocws' );

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
                'label'     => __( 'Enable OC Local Pickup', 'ocws' ),
                'default'     => 'yes'
            ),
            'title' => array(
                'title'     => __( 'Method Title', 'ocws' ),
                'type'       => 'text',
                'description'   => __( 'Local Pickup.', 'ocws' ),
                'default'    => __( 'Local Pickup', 'ocws' ),

            )
        );
    }

    public function is_available( $package ) {
        //error_log('start pickup is_available, blog id: '. get_current_blog_id());
        $is_available = (('yes' === $this->enabled) && ocws_enabled_pickup_branches_exist());
        if (!$is_available) {
            $this->clear_notices();
            return false;
        }
        //error_log('is pickup available: '. ($is_available? 'true' : 'false'));
        //error_log('inside pickup is_applicable()'.'----------- current blog : '.get_current_blog_id() . '----------');
        return $this->is_applicable( $package );
    }

    public function is_applicable( $package, &$errors = null ) {
        //error_log('start pickup is_applicable, blog id: '. get_current_blog_id());
        //error_log(print_r($package, 1));

        $add_validate_order_errors = false;
        if ($errors && ($errors instanceof WP_Error)) {
            $add_validate_order_errors = true;
        }

        $this->clear_notices();

        $aff_id = 0;
        $blog_id = get_current_blog_id();
        if (isset($package['destination']['ocws_lp_pickup_aff_id']) && !empty($package['destination']['ocws_lp_pickup_aff_id'])) {
            $aff_id = $package['destination']['ocws_lp_pickup_aff_id'];
            if (!str_contains($package['destination']['ocws_lp_pickup_aff_id'].'', ':::')) {
                $aff_id = intval($aff_id);
            }
            else {
                $bid = explode(':::', $aff_id, 2);
                if ($bid[0]) {
                    $blog_id = intval($bid[0]);
                    if (isset($bid[1])) {
                        $aff_id = intval($bid[1]);
                    }
                }
            }
        }
        //error_log(print_r($GLOBALS['_wp_switched_stack'], 1));
        //error_log('--------------------- current blog : '.get_current_blog_id() . '------------------------------------------');
        $blog_branches = OCWS_LP_Local_Pickup::get_affiliates_dropdown_blog(true);
        //error_log('--------------------------------- blog branches ------------------------------------');
        //error_log(print_r($blog_branches, 1));
        if (!is_multisite()) {
            if ($aff_id && !array_key_exists($aff_id, $blog_branches)) {
                $message = '<div class="show-shipping-block ocws-no-chosen-branch" style="display: none;"><span class="important-notice">'.esc_attr(__('Sorry, this branch is not available', 'ocws')).'</span></div>';
                $this->add_notice( $message, 'permanent-notice' );
                $this->add_notice( 'not_passed_branch', 'permanent-hidden');
                if ($add_validate_order_errors) {
                    $errors->add('shipping', __('Sorry, this branch is not available', 'ocws'));
                }
                //error_log('not passed branch');
            }
            return true;
        }
        $network_branches = OCWS_LP_Local_Pickup::get_affiliates_dropdown_networkwide(true);
        //error_log('--------------------------------- network branches ------------------------------------');
        //error_log(print_r($network_branches, 1));
        if (count($blog_branches) == 0) {
            if (count($network_branches) == 1) {
                //error_log('External pickup branches only');
                $blog_name = reset($network_branches);
                $blog_id = 0;
                $bid = explode(':::', key($network_branches), 2);
                if (isset($bid[1])) {
                    $blog_id = intval($bid[1]);
                }
                if (!$blog_id || !ocws_blog_exists($blog_id)) {
                    //return false;
                }
                $redirect_url = esc_url(ocws_convert_current_page_url($blog_id, ['ocws_from_store' => get_current_blog_id()]));
                $message = '<div class="show-shipping-block ocws-go-to-blog" style="display: none;"><span class="important-notice">'.
                    esc_html(sprintf(__('For pickup from branch %s.', 'ocws'), $blog_name)).
                    '</span><br><a class="ocws-site-link" href="'.esc_url($redirect_url).'">'.esc_html(__('Click here >', 'ocws')).'</a></div>';
                $this->add_notice( $message, 'permanent-notice' );
                $this->add_notice( 'no_blog_branch_available', 'permanent-hidden');
                if ($add_validate_order_errors) {
                    $errors->add('shipping', '<span class="important-notice">'.
                        esc_html(sprintf(__('To order shipping to %s.', 'ocws'), $blog_name)).
                        '</span> <a class="ocws-site-link" href="'.esc_url($redirect_url).'">'.esc_html(__('Click here >', 'ocws')).'</a>');
                }
                //error_log('no blog branch available');
            }
            else if (count($network_branches) > 1) {

                if ($aff_id && $blog_id != get_current_blog_id() && ocws_blog_exists($blog_id)) {

                    $blog_name = '';
                    if (isset($network_branches[$aff_id.':::'.$blog_id])) {
                        $blog_name = $network_branches[$aff_id.':::'.$blog_id];
                    }

                    $redirect_url = esc_url(ocws_convert_current_page_url($blog_id, ['ocws_from_store' => get_current_blog_id()]));
                    $message = '<div class="show-shipping-block ocws-go-to-blog" style="display: none;"><span class="important-notice">'.
                        esc_html(sprintf(__('For pickup from branch %s.', 'ocws'), $blog_name)).
                        '</span><br><a class="ocws-site-link" href="'.esc_url($redirect_url).'">'.esc_html(__('Click here >', 'ocws')).'</a></div>';
                    $this->add_notice( $message, 'permanent-notice' );
                    $this->add_notice( 'no_blog_branch_available', 'permanent-hidden');
                    if ($add_validate_order_errors) {
                        $errors->add('shipping', '<span class="important-notice">'.
                            esc_html(sprintf(__('To order shipping to %s.', 'ocws'), $blog_name)).
                            '</span> <a class="ocws-site-link" href="'.esc_url($redirect_url).'">'.esc_html(__('Click here >', 'ocws')).'</a>');
                    }
                    //error_log('no blog branch available');
                }
            }
            else {
                //return false;
            }
        }
        else {
            if ($aff_id && $blog_id == get_current_blog_id()) {
                if (!array_key_exists($aff_id, $blog_branches)) {
                    $message = '<div class="show-shipping-block ocws-no-chosen-branch" style="display: none;"><span class="important-notice">'.esc_attr(__('Sorry, this branch is not available', 'ocws')).'</span></div>';
                    $this->add_notice( $message, 'permanent-notice' );
                    $this->add_notice( 'not_passed_branch', 'permanent-hidden');
                    if ($add_validate_order_errors) {
                        $errors->add('shipping', __('Sorry, this branch is not available', 'ocws'));
                    }
                    //error_log('not passed branch');
                }
            }
        }
        /*$affiliates_ds = new OCWS_LP_Affiliates();
        $affiliates = $affiliates_ds->get_affiliates();

        $enabled_affiliates = false;

        if (is_array($affiliates)) {

            foreach ($affiliates as $affiliate) {
                if ($affiliate['is_enabled'] == '1') {
                    $enabled_affiliates = true;
                    break;
                }
            }
        }
        return $enabled_affiliates;*/
        return true;
    }

    public function calculate_shipping( $package = array() ) {

        //error_log('pickup calculate_shipping:');
        $default_price = OCWS_LP_Affiliate_Option::get_default_option('pickup_price', 0);
        //error_log( print_r( $package, 1 ) );
        if (!isset($package['destination']) || !isset($package['destination']['ocws_lp_affiliate_id'])) {
            $message = sprintf( __( 'Please select a pickup location to calculate shipping cost', 'ocws' ), $this->title );
            //$this->add_notice( $message, 'notice' );
            //error_log('calculate_shipping: no pickup affiliate');
            $this->add_rate( array(
                'id'    => $this->id . $this->instance_id,
                'label' => $this->title,
                'cost'  => $default_price->option_value,
            ) );
            return;
        }

        $aff_id = intval( $package['destination']['ocws_lp_affiliate_id'] );

        $aff_ds = new OCWS_LP_Affiliates();
        $aff = $aff_ds->db_get_affiliate($aff_id);
        if ( ! $aff ) {
            $message = sprintf( __( 'Please select a pickup location to calculate shipping cost', 'ocws' ), $this->title );
            //$this->add_notice( $message, 'notice' );
            //error_log('calculate_shipping: no pickup affiliate');
            $this->add_rate( array(
                'id'    => $this->id . $this->instance_id,
                'label' => $this->title,
                'cost'  => $default_price->option_value,
            ) );
            return;
        }

        $aff_opt_model = OCWS_LP_Affiliate_Option::get_option($aff_id, 'pickup_price', 0);
        $opt_price = trim($aff_opt_model->option_value);

        $pickup_price = round( $opt_price, wc_get_price_decimals() );
        $this->add_rate( array(
            'id'    => $this->id . $this->instance_id,
            'label' => $this->title,
            'cost'  => $pickup_price,
        ) );

    }

    /**
     * @param string $method
     * @return bool
     */
    public static function is_ocws_lp($method) {
        return substr($method, 0, strlen(self::METHOD_ID)) == self::METHOD_ID;
    }



    public static function validate_order( $posted, $errors ) {

        /*$packages = WC()->shipping->get_packages();
        $chosen_methods = WC()->session->get( 'chosen_shipping_methods' );

        if( is_array( $chosen_methods ) && in_array( self::METHOD_ID, $chosen_methods ) ) {

            foreach ( $packages as $i => $package ) {
                if ( $chosen_methods[ $i ] != self::METHOD_ID ) {

                    continue;

                }
                $shipping_method = new OC_Woo_Local_Pickup_Method();
                $shipping_method->is_applicable( $package );

            }
        }*/
        //error_log('validate order errors:');
        //error_log(print_r($posted, 1));
        $packages = WC()->shipping->get_packages();
        if (!isset($posted['shipping_method'])) return;
        $chosen_methods = $posted['shipping_method'];
        //error_log('packages');
        //error_log(print_r($packages, 1));
        if( is_array( $chosen_methods ) ) {

            foreach ( $packages as $i => $package ) {
                if ( !isset($chosen_methods[ $i ]) || !self::is_ocws_lp($chosen_methods[ $i ]) ) {

                    //error_log('validate order errors:');
                    //error_log('$i: '. $i . ', chosen method: ' . $chosen_methods[ $i ]);
                    continue;

                }
                $shipping_method = new OC_Woo_Local_Pickup_Method();
                $shipping_method->is_applicable( $package, $errors );
                //error_log('validate order errors:');
                //error_log(print_r($errors, 1));

            }
        }
    }

    public function add_notice( $message, $notice_type ) {
        if (!OC_Woo_Shipping_Notices::has_notice( $message, $notice_type, 'ocws_lp_notices' )) {
            OC_Woo_Shipping_Notices::add_notice( $message, $notice_type, 'ocws_lp_notices' );
        }
        //error_log(print_r( WC()->session->get( 'ocws_lp_notices', array() ), 1));
    }

    public function clear_notices() {

        OC_Woo_Shipping_Notices::clear_notices( true, 'ocws_lp_notices' );
    }
}