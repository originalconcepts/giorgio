<?php

defined( 'ABSPATH' ) || exit;

class OCWS_WC_Session_Handler {

    public function __construct() {

    }

    protected function get_table() {
        global $wpdb;
        return $wpdb->prefix . 'woocommerce_sessions';
    }

    /**
     * Returns the session.
     *
     * @param string $customer_id Customer ID.
     * @param mixed  $default Default session value.
     * @return string|array
     */
    protected function get_session( $customer_id, $default = false ) {
        global $wpdb;

        $value = $wpdb->get_row( $wpdb->prepare( "SELECT session_value FROM ".$this->get_table()." WHERE session_key = %s", $customer_id ) );

        //error_log($wpdb->prepare( "SELECT session_value FROM ".$this->get_table()." WHERE session_key = %s", $customer_id ));

        if ( is_null( $value ) ) {
            return $default;
        }

        //error_log( print_r($value, 1) );

        return maybe_unserialize( $value->session_value );
    }

    protected function get_customer_id() {
        return WC()->session->get_customer_unique_id();
    }

    public function get_session_for_blog_id( $blog_id, $default = false ) {

        if (!$blog_id) {
            return array();
        }

        switch_to_blog($blog_id);

        $data = $this->get_session($this->get_customer_id(), $default);

        restore_current_blog();

        return $data;
    }

    public static function get_by_key( $key, $data, $default = false ) {
        if (!is_array($data) || !isset($data[$key])) return $default;
        return maybe_unserialize($data[$key]);
    }

}