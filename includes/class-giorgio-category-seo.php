<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Category SEO pushed from Giorgio (12.11.2026).
 *
 * WooCommerce's products/categories REST endpoint knows name / slug / description only, so Giorgio sends the SEO
 * title and meta description of a category as one extra field, "giorgio_seo" => { title, description }, and this
 * class makes the store honour it:
 *
 * - REST: register_rest_field( 'product_cat', 'giorgio_seo' ) - readable in GET, written on POST/PUT (WooCommerce calls
 *   update_additional_fields_for_object after the term is saved, for single and batch requests alike).
 * - Storage: the values are kept in term meta (_giorgio_seo_title / _giorgio_seo_description) and mirrored into the
 *   SEO plugin that is active on the store - Yoast SEO (its taxonomy-meta option + indexable), Rank Math and SEOPress
 *   term meta - so the plugin's own title/description templates pick them up.
 * - Fallback: without any of those plugins the category archive gets the title through document_title_parts and a
 *   <meta name="description"> in wp_head.
 *
 * An older plugin that lacks this class simply ignores the unknown field, so Giorgio can send it unconditionally.
 */
class OC_Giorgio_Category_SEO {
    const REST_FIELD = 'giorgio_seo';
    const TAXONOMY   = 'product_cat';
    const META_TITLE = '_giorgio_seo_title';
    const META_DESC  = '_giorgio_seo_description';

    public function __construct() {
        add_action( 'rest_api_init', array( $this, 'register_rest_field' ) );
        // Storefront fallback (no SEO plugin): late priority so the theme's own title filters run first.
        add_filter( 'document_title_parts', array( $this, 'filter_document_title_parts' ), 50 );
        add_action( 'wp_head', array( $this, 'render_meta_description' ), 1 );
    }

    /**
     * The field is attached to the object type WooCommerce's category controller reports (its schema title, "product_cat").
     */
    public function register_rest_field() {
        register_rest_field(
            self::TAXONOMY,
            self::REST_FIELD,
            array(
                'get_callback'    => array( $this, 'rest_get' ),
                'update_callback' => array( $this, 'rest_update' ),
                'schema'          => array(
                    'description' => 'SEO title and meta description of the category page (Giorgio).',
                    'type'        => 'object',
                    'context'     => array( 'view', 'edit' ),
                    'properties'  => array(
                        'title'       => array( 'type' => 'string' ),
                        'description' => array( 'type' => 'string' ),
                    ),
                ),
            )
        );
    }

    /**
     * @param array $term_data The prepared term array (has "id").
     * @return array{title:string,description:string}
     */
    public function rest_get( $term_data ) {
        $term_id = isset( $term_data['id'] ) ? (int) $term_data['id'] : 0;
        return array(
            'title'       => $term_id ? (string) get_term_meta( $term_id, self::META_TITLE, true ) : '',
            'description' => $term_id ? (string) get_term_meta( $term_id, self::META_DESC, true ) : '',
        );
    }

    /**
     * @param mixed         $value The "giorgio_seo" request value.
     * @param WP_Term|mixed $term  The saved term (WooCommerce passes the WP_Term).
     */
    public function rest_update( $value, $term ) {
        if ( ! ( $term instanceof WP_Term ) || ! is_array( $value ) ) {
            return;
        }
        $title = isset( $value['title'] ) ? sanitize_text_field( wp_unslash( (string) $value['title'] ) ) : '';
        $desc  = isset( $value['description'] ) ? sanitize_textarea_field( wp_unslash( (string) $value['description'] ) ) : '';
        self::save( (int) $term->term_id, (string) $term->taxonomy, $title, $desc );
    }

    /**
     * Writes the values to our term meta and to whichever SEO plugin is active. Empty strings clear the value.
     */
    public static function save( $term_id, $taxonomy, $title, $desc ) {
        $title = trim( (string) $title );
        $desc  = trim( (string) $desc );

        self::set_meta( $term_id, self::META_TITLE, $title );
        self::set_meta( $term_id, self::META_DESC, $desc );

        // Yoast SEO keeps taxonomy meta in the wpseo_taxonomy_meta option (WPSEO_Taxonomy_Meta) and serves the front end
        // from its indexable table; the indexable was already (re)built on edited_term before this callback ran, so
        // update it here as well.
        if ( class_exists( 'WPSEO_Taxonomy_Meta' ) && method_exists( 'WPSEO_Taxonomy_Meta', 'set_values' ) ) {
            try {
                WPSEO_Taxonomy_Meta::set_values(
                    $term_id,
                    $taxonomy,
                    array(
                        'wpseo_title' => $title,
                        'wpseo_desc'  => $desc,
                    )
                );
            } catch ( \Throwable $e ) {
                // The SEO plugin's internals changed; our own meta still carries the values.
            }
            self::update_yoast_indexable( $term_id, $title, $desc );
        }

        // Rank Math and SEOPress read plain term meta.
        if ( defined( 'RANK_MATH_VERSION' ) ) {
            self::set_meta( $term_id, 'rank_math_title', $title );
            self::set_meta( $term_id, 'rank_math_description', $desc );
        }
        if ( defined( 'SEOPRESS_VERSION' ) ) {
            self::set_meta( $term_id, '_seopress_titles_title', $title );
            self::set_meta( $term_id, '_seopress_titles_desc', $desc );
        }

        clean_term_cache( $term_id, $taxonomy );
    }

    private static function set_meta( $term_id, $key, $value ) {
        if ( '' === $value ) {
            delete_term_meta( $term_id, $key );
        } else {
            update_term_meta( $term_id, $key, $value );
        }
    }

    private static function update_yoast_indexable( $term_id, $title, $desc ) {
        if ( ! function_exists( 'YoastSEO' ) ) {
            return;
        }
        try {
            $repository = YoastSEO()->classes->get( 'Yoast\WP\SEO\Repositories\Indexable_Repository' );
            if ( ! $repository || ! method_exists( $repository, 'find_by_id_and_type' ) ) {
                return;
            }
            $indexable = $repository->find_by_id_and_type( $term_id, 'term', false );
            if ( ! $indexable ) {
                return;
            }
            $indexable->title       = '' === $title ? null : $title;
            $indexable->description = '' === $desc ? null : $desc;
            $indexable->save();
        } catch ( \Throwable $e ) {
            // Yoast will rebuild the indexable from WPSEO_Taxonomy_Meta on the next term save / reindex.
        }
    }

    /** True when a plugin that renders its own title / meta description is active. */
    private static function seo_plugin_active() {
        return defined( 'WPSEO_VERSION' ) || defined( 'RANK_MATH_VERSION' ) || defined( 'SEOPRESS_VERSION' ) || defined( 'AIOSEO_VERSION' );
    }

    /** The queried product category on its archive page, or null. */
    private static function current_category() {
        if ( is_admin() || ! is_tax( self::TAXONOMY ) ) {
            return null;
        }
        $term = get_queried_object();
        return ( $term instanceof WP_Term ) ? $term : null;
    }

    /**
     * No SEO plugin: the SEO title set in Giorgio replaces the whole <title> of the category archive.
     *
     * @param array $parts
     * @return array
     */
    public function filter_document_title_parts( $parts ) {
        if ( self::seo_plugin_active() ) {
            return $parts;
        }
        $term = self::current_category();
        if ( ! $term ) {
            return $parts;
        }
        $title = (string) get_term_meta( $term->term_id, self::META_TITLE, true );
        if ( '' === $title ) {
            return $parts;
        }
        return array( 'title' => $title );
    }

    /** No SEO plugin: print the meta description of the category archive. */
    public function render_meta_description() {
        if ( self::seo_plugin_active() ) {
            return;
        }
        $term = self::current_category();
        if ( ! $term ) {
            return;
        }
        $desc = (string) get_term_meta( $term->term_id, self::META_DESC, true );
        if ( '' === $desc ) {
            return;
        }
        echo '<meta name="description" content="' . esc_attr( $desc ) . '" />' . "\n";
    }
}
