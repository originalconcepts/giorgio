<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://originalconcepts.co.il/
 * @since             1.0.0
 * @package           Oc_Woo_Shipping
 *
 * @wordpress-plugin
 * Plugin Name:       Original Concepts WooCommerce Advanced Shipping
 * Plugin URI:        https://originalconcepts.co.il/
 * Description:       This is a short description of what the plugin does. It's displayed in the WordPress admin area.
 * Version:           2.2.4
 * Author:            Original Concepts
 * Author URI:        https://originalconcepts.co.il/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       ocws
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

$autoloader = __DIR__ . '/vendor/autoload.php';
if ( is_readable( $autoloader ) ) {
	require $autoloader;
} else {
	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log(  // phpcs:ignore
			sprintf(
			/* translators: 1: composer command. 2: plugin directory */
				esc_html__( 'Your installation of the OC Advanced Shipping plugin is incomplete. Please run %1$s within the %2$s directory.', 'ocws' ),
				'`composer install`',
				'`' . esc_html( str_replace( ABSPATH, '', __DIR__ ) ) . '`'
			)
		);
	}
	/**
	 * Outputs an admin notice if composer install has not been ran.
	 */
	add_action(
		'admin_notices',
		function() {
			?>
			<div class="notice notice-error">
				<p>
					<?php
					printf(
					/* translators: 1: composer command. 2: plugin directory */
						esc_html__( 'Your installation of the OC Advanced Shipping plugin is incomplete. Please run %1$s within the %2$s directory.', 'ocws' ),
						'<code>composer install</code>',
						'<code>' . esc_html( str_replace( ABSPATH, '', __DIR__ ) ) . '</code>'
					);
					?>
				</p>
			</div>
			<?php
		}
	);
	return;
}

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'OC_WOO_SHIPPING_VERSION', '2.1.2' );

define('OCWS_PATH_FILE', __FILE__);
define('OCWS_PATH', dirname(OCWS_PATH_FILE));

if ( ! defined( 'OCWS_ASSESTS_URL' ) ) {

	define('OCWS_ASSESTS_URL', plugin_dir_url(__FILE__).'public/');

}

if ( ! defined( 'OCWS_ADMIN_ASSESTS_URL' ) ) {

	define('OCWS_ADMIN_ASSESTS_URL', plugin_dir_url(__FILE__).'admin/');

}

define( 'OC_WOO_USE_COMPANIES', false ); 
define( 'OC_WOO_USE_OPENSEA_STYLE_EXPORT', false );
define( 'OC_WOO_USE_OLD_STYLE_POPUP', false );

/**
 * GitHub-based updater for the oc-woo-shipping plugin.
 *
 * Hooks into WordPress' native plugin-update flow and announces new releases
 * published on GitHub. WordPress compares the latest GitHub release tag to the
 * installed plugin Version header and offers an update when newer.
 *
 * Repo: https://github.com/omerelias/oc-woo-shipping (public)
 */
class OC_Woo_Shipping_Updater {

	const GITHUB_USER = 'omerelias';
	const GITHUB_REPO = 'oc-woo-shipping';

	const CACHE_KEY   = 'oc_woo_shipping_gh_release';
	const CACHE_TTL   = 6 * HOUR_IN_SECONDS; // be nice to GitHub's rate limit

	/**
	 * Must match the folder/file in wp-content/plugins/.
	 */
	const PLUGIN_BASENAME = 'oc-woo-shipping/oc-woo-shipping.php';

	/**
	 * Folder name WordPress expects after extracting the zip.
	 */
	const PLUGIN_DIRNAME = 'oc-woo-shipping';

	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ $this, 'flush_cache' ], 10, 2 );
		add_action( 'admin_post_oc_woo_shipping_force_check', [ $this, 'handle_force_check' ] );
		add_action( 'admin_notices', [ $this, 'render_check_button' ] );
	}

	/**
	 * Inject our update row into the WP plugin update transient.
	 *
	 * @param object $transient Update plugins transient.
	 * @return object
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}

		$remote = $this->get_remote_release();
		if ( ! $remote ) {
			return $transient;
		}

		$installed_ver = OC_WOO_SHIPPING_VERSION;
		$remote_ver    = ltrim( $remote['tag'], 'vV' );

		if ( version_compare( $remote_ver, $installed_ver, '>' ) ) {
			$transient->response[ self::PLUGIN_BASENAME ] = (object) [
				'slug'        => self::GITHUB_REPO,
				'plugin'      => self::PLUGIN_BASENAME,
				'new_version' => $remote_ver,
				'url'         => 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO,
				'package'     => $remote['zip'],
			];
		} else {
			$transient->no_update[ self::PLUGIN_BASENAME ] = (object) [
				'slug'        => self::GITHUB_REPO,
				'plugin'      => self::PLUGIN_BASENAME,
				'new_version' => $remote_ver,
				'url'         => 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO,
				'package'     => '',
			];
		}

		return $transient;
	}

	/**
	 * Fetch the latest GitHub release (cached).
	 *
	 * @return array|false ['tag' => string, 'zip' => string]
	 */
	private function get_remote_release() {
		$force = ! empty( $_GET['force-check'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $force ) {
			$cached = get_site_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$api = sprintf(
			'https://api.github.com/repos/%s/%s/releases/latest',
			self::GITHUB_USER,
			self::GITHUB_REPO
		);

		$res = wp_remote_get(
			$api,
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
				],
			]
		);

		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( is_wp_error( $res ) || $code !== 200 ) {
			$fallback = $this->get_remote_tag_from_tags_api();
			if ( $fallback ) {
				set_site_transient( self::CACHE_KEY, $fallback, self::CACHE_TTL );
				return $fallback;
			}

			set_site_transient( self::CACHE_KEY, false, 15 * MINUTE_IN_SECONDS );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['tag_name'] ) ) {
			$fallback = $this->get_remote_tag_from_tags_api();
			if ( $fallback ) {
				set_site_transient( self::CACHE_KEY, $fallback, self::CACHE_TTL );
				return $fallback;
			}

			set_site_transient( self::CACHE_KEY, false, 15 * MINUTE_IN_SECONDS );
			return false;
		}

		$zip = $body['zipball_url'];
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! empty( $asset['browser_download_url'] )
					&& ! empty( $asset['name'] )
					&& substr( $asset['name'], -4 ) === '.zip' ) {
					$zip = $asset['browser_download_url'];
					break;
				}
			}
		}

		$data = [
			'tag' => $body['tag_name'],
			'zip' => $zip,
		];

		set_site_transient( self::CACHE_KEY, $data, self::CACHE_TTL );
		return $data;
	}

	/**
	 * Fallback for repos that do not publish GitHub Releases.
	 *
	 * @return array|false ['tag' => string, 'zip' => string]
	 */
	private function get_remote_tag_from_tags_api() {
		$api = sprintf(
			'https://api.github.com/repos/%s/%s/tags?per_page=1',
			self::GITHUB_USER,
			self::GITHUB_REPO
		);

		$res = wp_remote_get(
			$api,
			[
				'timeout' => 10,
				'headers' => [
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
				],
			]
		);

		if ( is_wp_error( $res ) || (int) wp_remote_retrieve_response_code( $res ) !== 200 ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( empty( $body[0]['name'] ) || empty( $body[0]['zipball_url'] ) ) {
			return false;
		}

		return [
			'tag' => $body[0]['name'],
			'zip' => $body[0]['zipball_url'],
		];
	}

	/**
	 * Rename extracted folder so it matches the installed plugin directory name.
	 *
	 * @param string      $source        Path to the extracted / downloaded package.
	 * @param string      $remote_source Remote file location.
	 * @param \WP_Upgrader $upgrader     Upgrader instance.
	 * @param array       $args          Extra arg passed to {@see \Plugin_Upgrader::install()}.
	 * @return string
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $args = [] ) {
		global $wp_filesystem;

		if ( ! is_object( $upgrader ) || empty( $args['plugin'] ) ) {
			return $source;
		}
		if ( $args['plugin'] !== self::PLUGIN_BASENAME ) {
			return $source;
		}
		if ( ! $wp_filesystem ) {
			return $source;
		}

		$corrected = trailingslashit( $remote_source ) . self::PLUGIN_DIRNAME . '/';
		if ( trailingslashit( $source ) === $corrected ) {
			return $source;
		}

		if ( $wp_filesystem->move( untrailingslashit( $source ), untrailingslashit( $corrected ) ) ) {
			return $corrected;
		}

		return $source;
	}

	/**
	 * Clear the release cache after a successful update.
	 *
	 * @param \WP_Upgrader $upgrader Upgrader instance.
	 * @param array        $data     Extra data.
	 */
	public function flush_cache( $upgrader, $data ) {
		if ( empty( $data['type'] ) || $data['type'] !== 'plugin' ) {
			return;
		}
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * "Check now" handler: clear cache and plugin update transient, redirect back.
	 */
	public function handle_force_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'ocws' ) );
		}
		check_admin_referer( 'oc_woo_shipping_force_check' );

		delete_site_transient( self::CACHE_KEY );
		delete_site_transient( 'update_plugins' );

		wp_safe_redirect(
			add_query_arg(
				[ 'oc_woo_shipping_checked' => '1' ],
				self_admin_url( 'update-core.php?force-check=1' )
			)
		);
		exit;
	}

	/**
	 * Notice with "Check updates" on Dashboard → Updates.
	 */
	public function render_check_button() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'update-core' ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$installed_ver = OC_WOO_SHIPPING_VERSION;
		$cached        = get_site_transient( self::CACHE_KEY );
		$remote_ver    = ( is_array( $cached ) && ! empty( $cached['tag'] ) )
			? ltrim( $cached['tag'], 'vV' )
			: __( 'Not checked yet', 'ocws' );

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=oc_woo_shipping_force_check' ),
			'oc_woo_shipping_force_check'
		);

		$just_checked = ! empty( $_GET['oc_woo_shipping_checked'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="notice notice-info" style="padding:14px 18px;">
			<h3 style="margin:0 0 6px;"><?php esc_html_e( 'OC Advanced Shipping - Check updates from GitHub', 'ocws' ); ?></h3>
			<p style="margin:4px 0;">
				<strong><?php esc_html_e( 'Installed version:', 'ocws' ); ?></strong> <?php echo esc_html( $installed_ver ); ?>
				&nbsp;|&nbsp;
				<strong><?php esc_html_e( 'Latest version on GitHub (from cache):', 'ocws' ); ?></strong> <?php echo esc_html( $remote_ver ); ?>
			</p>
			<p style="margin:10px 0 0;">
				<a href="<?php echo esc_url( $url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Check for updates now', 'ocws' ); ?>
				</a>
				<?php if ( $just_checked ) : ?>
					<span style="color:#1d7c2a;margin-inline-start:10px;"><?php esc_html_e( '✓ Checked and cache cleared. A newer version, if any, will appear below.', 'ocws' ); ?></span>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}

new OC_Woo_Shipping_Updater();

/* Define max file size for simple html DOM library */
defined( 'MAX_FILE_SIZE' ) || define( 'MAX_FILE_SIZE', 1000000 );

if (!function_exists('is_plugin_active_for_network')) {
	require_once(ABSPATH . '/wp-admin/includes/plugin.php');
}

/**
 * Check for the existence of WooCommerce and any other requirements
 */
if (!function_exists('oc_woo_check_requirements')) {
function oc_woo_check_requirements() {
	// multisite
	if ( is_multisite() ) {
		// this plugin is network activated - Woo must be network activated
		if ( is_plugin_active_for_network( plugin_basename(__FILE__) ) ) {
			$need = is_plugin_active_for_network('woocommerce/woocommerce.php') ? false : true;
			// this plugin is locally activated - Woo can be network or locally activated
		} else {
			$need = is_plugin_active( 'woocommerce/woocommerce.php')  ? false : true;
		}
		// this plugin runs on a single site
	} else {
		$need = is_plugin_active( 'woocommerce/woocommerce.php') ? false : true;
	}

	if ($need === true) {
		add_action( 'admin_notices', 'oc_woo_missing_wc_notice' );
		return false;
	}
	return true;
}}

/**
 * Display a message advising WooCommerce is required
 */
if (!function_exists('oc_woo_missing_wc_notice')) {
function oc_woo_missing_wc_notice() {
	$class = 'notice notice-error';
	$message = __( 'WooCommerce Advanced Shipping requires WooCommerce to be installed and active.', 'ocws' );

	printf( '<div class="%1$s"><p>%2$s</p></div>', esc_attr( $class ), esc_html( $message ) );
}}

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-oc-woo-shipping-activator.php
 */
function activate_oc_woo_shipping( $network_wide ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-oc-woo-shipping-activator.php';
	Oc_Woo_Shipping_Activator::activate( $network_wide );

	require_once plugin_dir_path( __FILE__ ) . 'includes/local-pickup/class-ocws-lp-activator.php';
	OCWS_LP_Activator::activate( $network_wide );
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-oc-woo-shipping-deactivator.php
 */
function deactivate_oc_woo_shipping( $network_wide ) {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-oc-woo-shipping-deactivator.php';
	Oc_Woo_Shipping_Deactivator::deactivate( $network_wide );

	require_once plugin_dir_path( __FILE__ ) . 'includes/local-pickup/class-ocws-lp-activator.php';
	OCWS_LP_Activator::deactivate( $network_wide );
}

register_activation_hook( __FILE__, 'activate_oc_woo_shipping' );
register_deactivation_hook( __FILE__, 'deactivate_oc_woo_shipping' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-oc-woo-shipping.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_oc_woo_shipping() {

	if (oc_woo_check_requirements()) {
		$plugin = OCWS(); //new Oc_Woo_Shipping();
		$plugin->run();
	}
}
//error_log('running plugin');
run_oc_woo_shipping();

/**
 * Returns the main instance of OCWS.
 *
 */
function OCWS() {
	return OC_Woo_Shipping::instance();
}

// Global for backwards compatibility.
$GLOBALS['OCWS'] = OCWS();
