<?php

/**
 * Load `ocws` translations from this plugin’s languages/ folder only (MO).
 *
 * Workflow: ocws.pot → ocws-he_IL.po → ocws-he_IL.mo (wp i18n or Loco Compile).
 *
 * @package Oc_Woo_Shipping
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register and load text domain for `ocws`.
 */
class Oc_Woo_Shipping_i18n {

	/**
	 * Register load hooks (early + again on init for WP 6.x compatibility).
	 */
	public function register_hooks( $loader ): void {
		$loader->add_action( 'plugins_loaded', $this, 'load_plugin_textdomain', 10 );
		$loader->add_action( 'init', $this, 'load_plugin_textdomain', 20 );
	}

	/**
	 * Load `languages/ocws-{locale}.mo` into WordPress.
	 */
	public function load_plugin_textdomain(): void {
		if ( ! defined( 'OCWS_PATH_FILE' ) ) {
			return;
		}

		$domain = 'ocws';
		$locale = determine_locale();
		$mofile = plugin_dir_path( OCWS_PATH_FILE ) . 'languages/' . $domain . '-' . $locale . '.mo';

		if ( ! is_readable( $mofile ) ) {
			return;
		}

		// Allow reload (false blocked re-load on WP 6.5+ and left NOOP_Translations).
		if ( function_exists( 'unload_textdomain' ) ) {
			unload_textdomain( $domain, true );
		}

		$loaded = load_textdomain( $domain, $mofile, $locale );

		if ( ! $loaded || ! $this->is_domain_active( $domain ) ) {
			$this->import_mo_into_l10n( $domain, $mofile );
		}
	}

	/**
	 * Whether translations for the domain are actually usable (not NOOP).
	 */
	private function is_domain_active( string $domain ): bool {
		if ( function_exists( 'is_textdomain_loaded' ) && is_textdomain_loaded( $domain ) ) {
			return true;
		}

		global $l10n;
		if ( ! isset( $l10n[ $domain ] ) ) {
			return false;
		}

		return ! ( $l10n[ $domain ] instanceof NOOP_Translations );
	}

	/**
	 * Direct MO import fallback when load_textdomain did not stick (WP 6.x + unload quirks).
	 */
	private function import_mo_into_l10n( string $domain, string $mofile ): bool {
		if ( ! class_exists( 'MO', false ) ) {
			require_once ABSPATH . WPINC . '/pomo/mo.php';
		}

		$mo = new MO();
		if ( ! $mo->import_from_file( $mofile ) ) {
			return false;
		}

		global $l10n;
		$l10n[ $domain ] = $mo;

		return true;
	}
}
