<?php

/**
 * Fired when the plugin is uninstalled.
 *
 * When populating this file, consider the following flow
 * of control:
 *
 * - This method should be static
 * - Check if the $_REQUEST content actually is the plugin name
 * - Run an admin referrer check to make sure it goes through authentication
 * - Verify the output of $_GET makes sense
 * - Repeat with other user roles. Best directly by using the links/query string parameters.
 * - Repeat things for multisite. Once for a single site in the network, once sitewide.
 *
 * This file may be updated more in future version of the Boilerplate; however, this is the
 * general skeleton and outline for how the file should work.
 *
 * For more information, see the following discussion:
 * https://github.com/tommcfarlin/WordPress-Plugin-Boilerplate/pull/123#issuecomment-28541913
 *
 * @link       https://originalconcepts.co.il/
 * @since      1.0.0
 *
 * @package    Oc_Woo_Shipping
 */

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'includes/local-pickup/class-ocws-lp-activator.php';

global $wpdb;

if ( is_multisite() ) {

	$blog_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs" );

	foreach ( $blog_ids as $blog_id ) {

		switch_to_blog( $blog_id );

		ocws_uninstall_plugin();

		restore_current_blog();
	}

} else {

	ocws_uninstall_plugin();

}


function ocws_uninstall_plugin() {

	global $wpdb;

	foreach ( wp_load_alloptions() as $option => $value ) {
		if ( strpos( $option, 'ocws_' ) === 0 ) {
			// for site options in Multisite
			delete_option($option);
		}
	};

	// Delete options.
	//$wpdb->query( "DELETE FROM $wpdb->options WHERE option_name LIKE 'ocws\_%';" );

	$tables = array(
		"{$wpdb->prefix}oc_woo_shipping_locations",
		"{$wpdb->prefix}oc_woo_shipping_groups",
		"{$wpdb->prefix}oc_woo_shipping_companies",
		"{$wpdb->prefix}oc_woo_shipping_cities_base",
	);

	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	OCWS_LP_Activator::uninstall();
}
