<?php

defined( 'ABSPATH' ) || exit;

class OCWS_Admin_Digitalp {

    public static function init() {

        add_action( 'admin_enqueue_scripts', array(__CLASS__, 'enqueue_styles'), 10, 0 );
        add_action( 'admin_enqueue_scripts', array(__CLASS__, 'enqueue_scripts'), 99, 0 );
        add_action( 'admin_init', array( __CLASS__, 'setup_settings' ), 10, 0 );
        add_action( 'ocws_custom_module_common_settings', array( __CLASS__, 'output_settings' ), 10, 0 );

    }

    public static function enqueue_styles() {
        wp_enqueue_style( 'digitalp-admin', OCWS_ADMIN_ASSESTS_URL . 'modules/deli/assets/css/digitalp-admin.css', array(), null, 'all' );
    }

    public static function enqueue_scripts() {

        wp_register_script( "digitalp-admin-js", OCWS_ADMIN_ASSESTS_URL . 'modules/digitalp/assets/js/digitalp-admin.js', array('jquery'), null, true );
        wp_localize_script( 'digitalp-admin-js', 'ajax_digitalp', array( 'ajaxurl' => admin_url( 'admin-ajax.php' )));

        wp_enqueue_script( 'digitalp-admin-js' );
    }

    public static function setup_settings() {
        $general_options = array(
            'digital_products_ids',
        );
        $general_options_defaults = array(
            'digital_products_ids' => ''
        );
        foreach ($general_options as $option_name) {
            OC_Woo_Shipping_Group_Option::register_common_option( $option_name, '' );
        }
    }

    public static function output_settings() {
        $list = ocws_numbers_list_to_array(get_option( 'ocws_common_digital_products_ids', '' ));

        ?>
            <tr valign="top">
                <th scope="row"><?php echo __('Products that do not require a shipping method', 'ocws') ?></th>
                <td>
                    <div><input placeholder="<?php echo __('For example: 123,456,789', 'ocws') ?>" type="text" name="ocws_common_digital_products_ids" value="<?php echo esc_attr( implode(',', $list) ); ?>" /></div>
                    <div><?php echo __('Enter the product IDs separated by commas', 'ocws') ?></div>
                </td>
            </tr>
        <?php
    }
}