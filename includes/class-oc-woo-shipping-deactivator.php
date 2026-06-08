<?php

/**
 * Fired during plugin deactivation
 *
 * @link       https://originalconcepts.co.il/
 * @since      1.0.0
 *
 * @package    Oc_Woo_Shipping
 * @subpackage Oc_Woo_Shipping/includes
 */

/**
 * Fired during plugin deactivation.
 *
 * This class defines all code necessary to run during the plugin's deactivation.
 *
 * @since      1.0.0
 * @package    Oc_Woo_Shipping
 * @subpackage Oc_Woo_Shipping/includes
 * @author     Milla Shub <milla@originalconcepts.co.il>
 */
class Oc_Woo_Shipping_Deactivator {

	/**
	 * Short Description. (use period)
	 *
	 * Long Description.
	 *
	 * @since    1.0.0
	 */
	public static function deactivate() {

	}

	public static function remove_blog( $params ) {

		global $wpdb;
		switch_to_blog( $params->blog_id );

		// options and cron events are removed automatically on site deletion
		// but we also need to delete our custom table, let's drop it
		$tables = array(
			"{$wpdb->prefix}oc_woo_shipping_locations",
			"{$wpdb->prefix}oc_woo_shipping_groups",
			"{$wpdb->prefix}oc_woo_shipping_companies",
			"{$wpdb->prefix}oc_woo_shipping_cities_base",
		);

		foreach ( $tables as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		restore_current_blog();

	}

}
