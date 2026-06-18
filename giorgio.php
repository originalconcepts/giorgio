<?php
/**
 * Plugin Name: OC Giorgio Integration
 * Plugin URI: https://onlinestore.co.il
 * Description: Two-way order sync between WooCommerce and the Giorgio platform.
 * Version: 1.7.5
 * Author: Original Concepts
 * Author URI: https://onlinestore.co.il
 * Text Domain: oc-storeos-integration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'OC_STOREOS_INTEGRATION_VERSION', '1.7.3' );
define( 'OC_STOREOS_INTEGRATION_PLUGIN_FILE', __FILE__ );
define( 'OC_STOREOS_INTEGRATION_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

/**
 * GitHub-based updater for the oc-storeos-integration plugin.
 *
 * Hooks into WordPress' native plugin-update flow and announces new releases
 * published on GitHub. WordPress compares the latest GitHub release tag to the
 * installed plugin Version header and offers an update when newer.
 *
 * Repo: https://github.com/originalconcepts/giorgio (set GITHUB_USER/GITHUB_REPO below to match the real repo)
 */
class OC_StoreOS_Integration_Updater {

	const GITHUB_USER = 'originalconcepts'; // TODO: set to the exact GitHub account/org the repo lives under
	const GITHUB_REPO = 'giorgio';

	const CACHE_KEY = 'oc_storeos_integration_gh_release';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS; // be nice to GitHub's rate limit

	/**
	 * Must match the folder/file in wp-content/plugins/.
	 *
	 * Example: 'my-plugin/my-plugin.php'
	 */
	const PLUGIN_BASENAME = 'giorgio/giorgio.php';

	/**
	 * Folder name WordPress expects after extracting the zip.
	 */
	const PLUGIN_DIRNAME = 'giorgio';

	public function __construct() {
		add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_for_update' ] );
		add_filter( 'upgrader_source_selection', [ $this, 'fix_source_dir' ], 10, 4 );
		add_action( 'upgrader_process_complete', [ $this, 'flush_cache' ], 10, 2 );
		add_action( 'admin_post_oc_storeos_integration_force_check', [ $this, 'handle_force_check' ] );
		add_action( 'admin_notices', [ $this, 'render_check_button' ] );
		add_filter( 'auto_update_plugin', [ $this, 'enable_auto_update' ], 10, 2 );
		// Authenticate GitHub API + asset requests so updates work from a PRIVATE repo.
		add_filter( 'http_request_args', [ $this, 'authorize_github_request' ], 10, 2 );
	}

	/**
	 * Access token for a private GitHub repo. Read from (in order):
	 *   1. constant OC_GIORGIO_GH_TOKEN (define in wp-config.php — most secure), or
	 *   2. the plugin setting `github_token`.
	 * Empty string = public repo (no auth). A fine-grained, read-only "Contents" token
	 * scoped to just this repo is recommended.
	 *
	 * @return string
	 */
	private function get_token() {
		if ( defined( 'OC_GIORGIO_GH_TOKEN' ) && OC_GIORGIO_GH_TOKEN ) {
			return (string) OC_GIORGIO_GH_TOKEN;
		}
		$opts = get_option( 'oc_storeos_integration_options', [] );
		return ( is_array( $opts ) && ! empty( $opts['github_token'] ) ) ? (string) $opts['github_token'] : '';
	}

	/**
	 * Add the Authorization header to requests aimed at THIS repo's GitHub API, so a private
	 * repo's release info and asset/zipball can be fetched. For the binary download endpoints
	 * (asset / zipball / tarball) we also ask for raw bytes.
	 *
	 * @param array  $args HTTP args.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public function authorize_github_request( $args, $url ) {
		$token = $this->get_token();
		if ( '' === $token ) {
			return $args;
		}
		$base = 'https://api.github.com/repos/' . self::GITHUB_USER . '/' . self::GITHUB_REPO;
		if ( strpos( (string) $url, $base ) !== 0 ) {
			return $args;
		}
		if ( empty( $args['headers'] ) || ! is_array( $args['headers'] ) ) {
			$args['headers'] = [];
		}
		$args['headers']['Authorization'] = 'Bearer ' . $token;
		if ( empty( $args['headers']['User-Agent'] ) ) {
			$args['headers']['User-Agent'] = 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url();
		}
		// Binary endpoints: request the raw archive, not JSON metadata.
		if ( false !== strpos( $url, '/releases/assets/' )
			|| false !== strpos( $url, '/zipball' )
			|| false !== strpos( $url, '/tarball' ) ) {
			$args['headers']['Accept'] = 'application/octet-stream';
		}
		return $args;
	}

	/**
	 * Enable WordPress background auto-updates for this plugin by default, so a new GitHub release
	 * is installed automatically without anyone clicking "update". A site can opt out via the
	 * `oc_giorgio_enable_auto_update` filter (return false).
	 *
	 * @param bool|null $update Whether to auto-update.
	 * @param object    $item   The update offer object.
	 * @return bool|null
	 */
	public function enable_auto_update( $update, $item ) {
		if ( is_object( $item ) && ! empty( $item->plugin ) && self::PLUGIN_BASENAME === $item->plugin ) {
			return (bool) apply_filters( 'oc_giorgio_enable_auto_update', true );
		}
		return $update;
	}

	/**
	 * Inject our update row into the WP plugin update transient.
	 */
	public function check_for_update( $transient ) {
		if ( empty( $transient ) || ! is_object( $transient ) ) {
			return $transient;
		}

		$remote = $this->get_remote_release();
		if ( ! $remote ) {
			return $transient;
		}

		$installed_ver = OC_STOREOS_INTEGRATION_VERSION;
		$remote_ver    = ltrim( $remote['tag'], 'vV' );

		if ( version_compare( $remote_ver, $installed_ver, '>' ) ) {
			// Plugin updates expect an object (stdClass). WP later reads `$update->package`.
			$transient->response[ self::PLUGIN_BASENAME ] = (object) [
				'slug'        => self::PLUGIN_DIRNAME,
				'plugin'      => self::PLUGIN_BASENAME,
				'new_version' => $remote_ver,
				'url'         => 'https://github.com/' . self::GITHUB_USER . '/' . self::GITHUB_REPO,
				'package'     => $remote['zip'],
			];
		} else {
			$transient->no_update[ self::PLUGIN_BASENAME ] = (object) [
				'slug'        => self::PLUGIN_DIRNAME,
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
		// When WP's native "Check Again" button is clicked (or our custom button),
		// bypass our local cache so the admin gets a truly fresh answer.
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

		$res = wp_remote_get( $api, [
			'timeout' => 10,
			'headers' => [
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			],
		] );

		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		if ( is_wp_error( $res ) || $code !== 200 ) {
			// Many repos use tags without publishing GitHub Releases.
			// If "latest release" isn't available (commonly 404), fall back to tags.
			$fallback = $this->get_remote_tag_from_tags_api();
			if ( $fallback ) {
				set_site_transient( self::CACHE_KEY, $fallback, self::CACHE_TTL );
				return $fallback;
			}

			// short negative cache so a GitHub outage doesn't hammer every admin load
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

		// Prefer an uploaded .zip asset (clean build) if one exists.
		// Otherwise fall back to GitHub's auto-generated source zipball.
		// For a PRIVATE repo we must download via the API URL (asset['url'] / zipball_url) so the
		// Authorization header is honoured; the public browser_download_url won't authenticate.
		$has_token = ( '' !== $this->get_token() );
		$zip = $body['zipball_url'];
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! empty( $asset['name'] ) && substr( $asset['name'], -4 ) === '.zip' ) {
					if ( $has_token && ! empty( $asset['url'] ) ) {
						$zip = $asset['url']; // api.github.com/.../releases/assets/{id}
					} elseif ( ! empty( $asset['browser_download_url'] ) ) {
						$zip = $asset['browser_download_url'];
					}
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
	 * Uses the most recent tag (as returned by the API) and its zipball.
	 *
	 * @return array|false ['tag' => string, 'zip' => string]
	 */
	private function get_remote_tag_from_tags_api() {
		$api = sprintf(
			'https://api.github.com/repos/%s/%s/tags?per_page=1',
			self::GITHUB_USER,
			self::GITHUB_REPO
		);

		$res = wp_remote_get( $api, [
			'timeout' => 10,
			'headers' => [
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
			],
		] );

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
	 * GitHub zips extract to something like `originalconcepts-giorgio-abc1234/`.
	 * WordPress expects the folder to be `oc-storeos-integration/`, otherwise it treats
	 * the upgrade as a brand-new plugin and the site may lose activation state.
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
	 * Clear the release cache after a successful update so a re-check picks up
	 * the new state immediately (no stale "update available" row).
	 */
	public function flush_cache( $upgrader, $data ) {
		if ( empty( $data['type'] ) || $data['type'] !== 'plugin' ) {
			return;
		}
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * Handle the dedicated "Check now" button: clear our cache + the WP
	 * plugin-update transient, then bounce back to the updates page where
	 * WP will immediately re-run the check and show the result.
	 */
	public function handle_force_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'oc-storeos-integration' ) );
		}
		check_admin_referer( 'oc_storeos_integration_force_check' );

		delete_site_transient( self::CACHE_KEY );
		delete_site_transient( 'update_plugins' );

		wp_safe_redirect( add_query_arg(
			[ 'oc_storeos_integration_checked' => '1' ],
			self_admin_url( 'update-core.php?force-check=1' )
		) );
		exit;
	}

	/**
	 * Show a notice with a "Check OC Giorgio Integration updates now" button on the
	 * updates screen only. Also shows installed vs. latest-seen versions.
	 */
	public function render_check_button() {

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== 'update-core' ) {
			return;
		}
		if ( ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$installed_ver = OC_STOREOS_INTEGRATION_VERSION;
		$cached        = get_site_transient( self::CACHE_KEY );
		$remote_ver    = ( is_array( $cached ) && ! empty( $cached['tag'] ) )
			? ltrim( $cached['tag'], 'vV' )
			: __( 'לא נבדק עדיין', 'oc-storeos-integration' );

		$url = wp_nonce_url(
			admin_url( 'admin-post.php?action=oc_storeos_integration_force_check' ),
			'oc_storeos_integration_force_check'
		);

		$just_checked = ! empty( $_GET['oc_storeos_integration_checked'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="notice notice-info" style="padding:14px 18px;">
			<h3 style="margin:0 0 6px;">תוסף OC Giorgio Integration - בדיקת עדכונים מ-GitHub</h3>
			<p style="margin:4px 0;">
				<strong>גרסה מותקנת:</strong> <?php echo esc_html( $installed_ver ); ?>
				&nbsp;|&nbsp;
				<strong>גרסה אחרונה ב-GitHub (מה-cache):</strong> <?php echo esc_html( $remote_ver ); ?>
			</p>
			<p style="margin:10px 0 0;">
				<a href="<?php echo esc_url( $url ); ?>" class="button button-primary">
					בדוק עדכונים עכשיו
				</a>
				<?php if ( $just_checked ) : ?>
					<span style="color:#1d7c2a;margin-inline-start:10px;">✓ נבדק ונוקה cache. אם יש גרסה חדשה היא תופיע מתחת.</span>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}
}

new OC_StoreOS_Integration_Updater();

require_once OC_STOREOS_INTEGRATION_PLUGIN_DIR . 'includes/class-giorgio-integration.php';
require_once OC_STOREOS_INTEGRATION_PLUGIN_DIR . 'includes/class-giorgio-ed-product-rest.php';

// Declare compatibility with WooCommerce High-Performance Order Storage (custom order tables).
add_action(
    'before_woocommerce_init',
    static function () {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                OC_STOREOS_INTEGRATION_PLUGIN_FILE,
                true
            );
        }
    }
);

add_action(
    'plugins_loaded',
    static function () {
        if ( class_exists( 'WooCommerce' ) ) {
            $integration = OC_StoreOS_Integration::get_instance();
            new OC_Giorgio_ED_Product_REST( $integration );
        }
    }
);
