<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Keeps the storefront sort order of global attribute terms in step with the order Giorgio pushes over REST.
 *
 * WooCommerce's REST API (products/attributes/{id}/terms, field menu_order) stores the value in term meta
 * "order_pa_<slug>", while the storefront ("Custom ordering" attributes: wc_get_product_terms, variation
 * dropdowns, swatches) sorts by term meta "order". Every released WooCommerce up to 10.5 has this mismatch
 * (fixed only in trunk), so a Giorgio drag-and-drop order lands in the wrong key and the site keeps showing
 * the old order.
 *
 * - Hook: after every REST create/update of a pa_* term that carries menu_order, mirror "order_pa_<slug>"
 *   into "order". The REST hook fires even when the value is unchanged (a plain meta hook would not), so a
 *   re-push from Giorgio always lands.
 * - Migration: once, on the first load of this plugin version, copy every existing "order_pa_<slug>" meta
 *   into "order" so the orders pushed before the fix show up without a re-push.
 */
class OC_Giorgio_Attribute_Term_Order {
    /** Option flag: the one-time migration ran. */
    const MIGRATED_OPTION = 'oc_storeos_term_order_mirrored';
    /** Short lock so concurrent requests don't run the migration twice. */
    const MIGRATING_TRANSIENT = 'oc_storeos_term_order_migrating';
    /** Prefix WooCommerce uses for global attribute taxonomies. */
    const TAXONOMY_PREFIX = 'pa_';

    public function __construct() {
        // Attribute taxonomies are registered by WooCommerce on init (priority 5); the REST hook name carries the taxonomy.
        add_action( 'init', array( $this, 'register_rest_hooks' ), 20 );
        add_action( 'init', array( $this, 'maybe_migrate' ), 30 );
    }

    /**
     * woocommerce_rest_insert_{taxonomy} fires from the REST terms controller after the term (and its meta) is saved,
     * for single create/update and for every item of terms/batch.
     */
    public function register_rest_hooks() {
        if ( ! function_exists( 'wc_get_attribute_taxonomy_names' ) ) {
            return;
        }
        foreach ( wc_get_attribute_taxonomy_names() as $taxonomy ) {
            add_action( 'woocommerce_rest_insert_' . $taxonomy, array( $this, 'mirror_rest_term_order' ), 10, 3 );
        }
    }

    /**
     * @param WP_Term|mixed        $term     Saved term.
     * @param WP_REST_Request|mixed $request  The REST request (per item for batch).
     * @param bool                  $creating True on create.
     */
    public function mirror_rest_term_order( $term, $request, $creating ) {
        if ( ! ( $term instanceof WP_Term ) ) {
            return;
        }
        // WooCommerce writes order_<taxonomy> only when menu_order was sent; a rename without menu_order must not
        // drag a stale REST value over an order set by hand in wp-admin.
        if ( $request instanceof WP_REST_Request && ! isset( $request['menu_order'] ) ) {
            return;
        }
        self::mirror_term( $term->term_id, $term->taxonomy );
    }

    /**
     * Copies the REST meta ("order_<taxonomy>") of one term into the storefront meta ("order").
     *
     * @return bool True when a value was mirrored.
     */
    public static function mirror_term( $term_id, $taxonomy ) {
        $rest_value = get_term_meta( (int) $term_id, 'order_' . $taxonomy, true );
        if ( '' === $rest_value || null === $rest_value || false === $rest_value ) {
            return false;
        }
        update_term_meta( (int) $term_id, 'order', (int) $rest_value );
        return true;
    }

    /**
     * One-time: mirror every existing REST order into the storefront meta. Cheap guard (one autoloaded option) on
     * every request so it also runs after an automatic plugin update, not only when someone opens wp-admin.
     */
    public function maybe_migrate() {
        if ( '1' === get_option( self::MIGRATED_OPTION ) ) {
            return;
        }
        if ( ! function_exists( 'wc_get_attribute_taxonomy_names' ) ) {
            return;
        }
        if ( get_transient( self::MIGRATING_TRANSIENT ) ) {
            return;
        }
        set_transient( self::MIGRATING_TRANSIENT, 1, 5 * MINUTE_IN_SECONDS );

        $mirrored = 0;
        foreach ( wc_get_attribute_taxonomy_names() as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $term_ids = get_terms(
                array(
                    'taxonomy'   => $taxonomy,
                    'hide_empty' => false,
                    'fields'     => 'ids',
                    'meta_key'   => 'order_' . $taxonomy, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
                )
            );
            if ( is_wp_error( $term_ids ) || empty( $term_ids ) ) {
                continue;
            }
            foreach ( $term_ids as $term_id ) {
                if ( self::mirror_term( $term_id, $taxonomy ) ) {
                    $mirrored++;
                }
            }
        }

        update_option( self::MIGRATED_OPTION, '1' );
        delete_transient( self::MIGRATING_TRANSIENT );

        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->info(
                sprintf( 'Giorgio: mirrored the REST attribute term order (order_pa_*) into the storefront meta "order" for %d term(s).', $mirrored ),
                array( 'source' => 'oc-storeos-integration' )
            );
        }
    }
}
