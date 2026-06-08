<?php

defined( 'ABSPATH' ) || exit;

class OC_Woo_Shipping_Product {

    public static function init() {

        // Display Fields
        // add_action('woocommerce_product_options_general_product_data', array('OC_Woo_Shipping_Product', 'woocommerce_product_custom_fields'));
        add_action('woocommerce_product_data_panels', array('OC_Woo_Shipping_Product', 'woocommerce_product_custom_fields'));
        // Save Fields
        add_action('woocommerce_process_product_meta', array('OC_Woo_Shipping_Product', 'woocommerce_product_custom_fields_save'));

    }

    public static function woocommerce_product_custom_fields()
    {
        global $woocommerce, $post;
        echo '<div class="woocommerce_options_panel">';
        echo '<div class="product_custom_field">';
        // echo '<div style="display: none;">'.print_r(['post_id' => $post->ID, '_ocws_pickup_only' => get_post_meta( $post->ID, '_ocws_pickup_only', true)], 1).'</div>';
        // Custom Product Text Field
        woocommerce_wp_checkbox(
            array(
                'id' => '_ocws_pickup_only',
                'label' => __('Local pickup only'), // Text in Label
                'class' => '',
                'style' => '',
                'wrapper_class' => '',
                'value' => get_post_meta( $post->ID, '_ocws_pickup_only', true), // if empty, retrieved from post meta where id is the meta_key
                'name' => '_ocws_pickup_only', //name will set from id if empty
                'cbvalue' => 'yes',
                'desc_tip' => '',
                'custom_attributes' => '', // array of attributes
                'description' => ''
            )
        );

        echo '</div>';
        echo '</div>';
    }

    public static function woocommerce_product_custom_fields_save( $post_id )
    {
        $custom_field_value = isset( $_POST['_ocws_pickup_only'] ) ? 'yes' : 'no';

        $product = wc_get_product( $post_id );
        //$product->update_meta_data( '_ocws_pickup_only', $custom_field_value );
        update_post_meta( $product->get_id(), '_ocws_pickup_only', $custom_field_value );
        //$product->save();
    }

}