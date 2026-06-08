<?php

defined( 'ABSPATH' ) || exit;

class OCWS_LP_Affiliates {

    public function db_get_affiliates() {
        global $wpdb;
        return $wpdb->get_results( "SELECT aff_id, aff_name, aff_address, aff_descr, aff_order, is_enabled FROM {$wpdb->prefix}oc_woo_shipping_affiliates order by aff_order ASC, aff_id ASC;" );
    }

    public static function db_get_enabled_affiliates_count() {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}oc_woo_shipping_affiliates WHERE is_enabled = %d", 1 ) );
    }

    public function get_affiliates() {

        $raw_affs = $this->db_get_affiliates();
        $affs = array();

        foreach ( $raw_affs as $raw_aff ) {
            $aff = new OCWS_LP_Affiliate( $raw_aff );
            $affs[ $aff->get_id() ] = $aff->get_data();
            $affs[ $aff->get_id() ]['aff_id'] = $aff->get_id();
            $affs[ $aff->get_id() ]['is_enabled'] = $aff->get_is_enabled();
        }

        return $affs;
    }

    public function get_affiliates_dropdown($enabled_only = false) {

        //error_log('------------------------------ get_affiliates_dropdown ------------------------------');
        $raw_affs = $this->db_get_affiliates();
        $affs = array();

        foreach ( $raw_affs as $raw_aff ) {
            if ($enabled_only && $raw_aff->is_enabled != 1) {
                continue;
            }
            $aff = new OCWS_LP_Affiliate( $raw_aff );
            $affs[ $aff->get_id() ] = $aff->get_aff_name();
        }

        return $affs;
    }

    public function get_affiliates_dropdown_networkwide($enabled_only = false) {

        //error_log('------------------------------ get_affiliates_dropdown_networkwide ------------------------------');
        global $wpdb;
        if (! is_multisite()) {
            return $this->get_affiliates_dropdown($enabled_only);
        }
        $branches = array();
        $current_blog_id = get_current_blog_id();
        $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );

        //error_log('Current blog ID: '.$current_blog_id);

        foreach ( $blog_ids as $blog_id ) {
            switch_to_blog( $blog_id );
            //error_log('Switched to blog: '.$blog_id);
            $blog_branches = $this->get_affiliates_dropdown($enabled_only);
            //error_log('Blog branches:');
            //error_log(print_r($blog_branches, 1));
            foreach ($blog_branches as $id => $name) {
                if ($blog_id != $current_blog_id) {
                    $branches[$blog_id . ':::' . $id] = $name;
                }
                else {
                    $branches[$id] = $name;
                }
            }

            restore_current_blog();
            //error_log('Restored to blog: '.get_current_blog_id());
        }
        //error_log(print_r($branches, 1));
        return $branches;
    }

    public function get_affiliate_name( $aff_id ) {

        $aff = $this->db_get_affiliate($aff_id);

        if ($aff) {
            return $aff->aff_name;
        }
        return '';
    }

    public function db_get_affiliate( $aff_id ) {
        global $wpdb;

        $aff_data = false;
        $id = intval($aff_id);

        if ( $id > 0 ) {
            $aff_data = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT aff_id, aff_name, aff_address, aff_descr, aff_order, is_enabled FROM {$wpdb->prefix}oc_woo_shipping_affiliates WHERE aff_id = %d LIMIT 1",
                    $id
                )
            );
        }

        if ( $aff_data ) {
            return $aff_data;
        }

        return null;
    }

    public function db_create_affiliate( OCWS_LP_Affiliate $aff ) {

        global $wpdb;
        $rows_inserted = $wpdb->insert(
            $wpdb->prefix . 'oc_woo_shipping_affiliates',
            array(
                'aff_name'  => $aff->get_aff_name(),
                'aff_address'  => $aff->get_aff_address(),
                'aff_descr'  => $aff->get_aff_descr(),
                'aff_order' => $aff->get_aff_order(),
                'is_enabled' => $aff->get_is_enabled()
            )
        );
        if ($rows_inserted) {
            return $wpdb->insert_id;
        }
        return false;
    }

    public function db_update_affiliate( OCWS_LP_Affiliate &$aff ) {

        global $wpdb;

        if ( !$aff || !$aff->get_id() ) {
            return false;
        }

        $rows_affected = $wpdb->update(
            $wpdb->prefix . 'oc_woo_shipping_affiliates',
            array(
                'aff_name'  => $aff->get_aff_name(),
                'aff_address'  => $aff->get_aff_address(),
                'aff_descr'  => $aff->get_aff_descr(),
                'aff_order' => $aff->get_aff_order(),
                'is_enabled' => $aff->get_is_enabled()
            ),
            array( 'aff_id' => $aff->get_id() )
        );

        return !!$rows_affected;
    }

    public function db_delete_affiliate( OCWS_LP_Affiliate &$aff ) {

        $aff_id = $aff->get_id();

        if ( $aff_id ) {

            $this->db_delete_affiliate_by_id($aff_id);
            $aff->set_id( 0 );
        }
    }

    public function db_delete_affiliate_by_id($aff_id) {

        global $wpdb;

        // Delete affiliate
        $wpdb->delete( $wpdb->prefix . 'oc_woo_shipping_affiliates', array( 'aff_id' => $aff_id ) );

        // delete affiliate options
        $aff_option_prefix = OCWS_LP_Affiliate_Option::get_affiliate_option_prefix($aff_id);
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM $wpdb->options WHERE option_name LIKE %s", str_replace('_', '\_', $aff_option_prefix).'%' ));

        wp_cache_flush();
    }
}