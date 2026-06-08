<?php

defined( 'ABSPATH' ) || exit;

class OCWS_LP_Activator {

    public static $ocws_pickup_db_version = '1.2';

    public static function activate( $network_wide ) {

        global $wpdb;
        $collate = '';
        if ( $wpdb->has_cap( 'collation' ) ) {
            $collate = $wpdb->get_charset_collate();
        }
        /*
         * Indexes have a maximum size of 767 bytes. Historically, we haven't need to be concerned about that.
         * As of WP 4.2, however, they moved to utf8mb4, which uses 4 bytes per character. This means that an index which
         * used to have room for floor(767/3) = 255 characters, now only has room for floor(767/4) = 191 characters.
         */
        $max_index_length = 191;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        if ( is_multisite() &&  $network_wide ) {
            $blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );

            foreach ( $blog_ids as $blog_id ) {
                switch_to_blog( $blog_id );

                self::create_tables($collate);

                restore_current_blog();
            }
        }
        else {
            self::create_tables($collate);
        }
    }

    public static function create_tables($collate) {

        global $wpdb;
        $installed_ver = get_option( 'ocws_pickup_db_version' );

        if ( empty($installed_ver) || $installed_ver != self::$ocws_pickup_db_version ) {

            $tables = "
CREATE TABLE {$wpdb->prefix}oc_woo_shipping_affiliates (
  aff_id BIGINT UNSIGNED NOT NULL auto_increment,
  aff_name varchar(200) NOT NULL,
  aff_address TEXT,
  aff_descr TEXT,
  aff_order BIGINT UNSIGNED NOT NULL,
  is_enabled tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY  (aff_id)
) $collate;
		";

            //error_log('OCWS_LP_Activator before dbDelta');
            dbDelta( $tables );
            //error_log('OCWS_LP_Activator after dbDelta');
            update_option( 'ocws_shipping_db_version', self::$ocws_pickup_db_version);
        }
    }

    public static function add_blog( $params ) {

        global $wpdb;

        if ( is_plugin_active_for_network( 'oc-woo-shipping/oc-woo-shipping.php' ) ) {

            switch_to_blog( $params->blog_id );

            $collate = '';
            if ( $wpdb->has_cap( 'collation' ) ) {
                $collate = $wpdb->get_charset_collate();
            }
            self::create_tables($collate);

            restore_current_blog();

        }
    }

    public static function deactivate() {

    }

    public static function remove_blog( $params ) {

        global $wpdb;
        switch_to_blog( $params->blog_id );

        // options and cron events are removed automatically on site deletion
        // but we also need to delete our custom table, let's drop it
        $tables = array(
            "{$wpdb->prefix}oc_woo_shipping_affiliates",
        );

        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        }

        restore_current_blog();

    }

    public static function uninstall() {

        global $wpdb;

        $tables = array(
            "{$wpdb->prefix}oc_woo_shipping_affiliates",
        );

        foreach ( $tables as $table ) {
            $wpdb->query( "DROP TABLE IF EXISTS {$table}" );
        }
    }
}