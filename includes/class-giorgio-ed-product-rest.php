<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Giorgio-facing ED product metadata REST endpoints.
 */
class OC_Giorgio_ED_Product_REST {
    const NAMESPACE = 'ed/v1';

    /** @var OC_StoreOS_Integration */
    protected $integration;

    /**
     * @param OC_StoreOS_Integration $integration Main plugin integration.
     */
    public function __construct( OC_StoreOS_Integration $integration ) {
        $this->integration = $integration;

        // Register late and override matching theme routes, so older theme versions
        // cannot leave these external sync endpoints public.
        add_action( 'rest_api_init', array( $this, 'register_routes' ), 1000 );
    }

    /**
     * Register StoreOS product metadata REST routes.
     */
    public function register_routes() {
        register_rest_route(
            self::NAMESPACE,
            '/product-label',
            array(
                'methods'             => array( WP_REST_Server::READABLE, WP_REST_Server::CREATABLE ),
                'permission_callback' => array( $this, 'permission_callback' ),
                'callback'            => array( $this, 'logged_product_label_dispatch' ),
                'args'                => array_merge(
                    $this->product_id_args(),
                    array(
                        'field_key' => array(
                            'description'       => __( 'ACF product label field key/name.', 'oc-storeos-integration' ),
                            'type'              => 'string',
                            'required'          => false,
                            'sanitize_callback' => 'sanitize_key',
                        ),
                    )
                ),
            ),
            true
        );

        register_rest_route(
            self::NAMESPACE,
            '/product-note-label',
            array(
                'methods'             => array( WP_REST_Server::READABLE, WP_REST_Server::CREATABLE ),
                'permission_callback' => array( $this, 'permission_callback' ),
                'callback'            => array( $this, 'logged_product_note_label_dispatch' ),
            ),
            true
        );

        register_rest_route(
            self::NAMESPACE,
            '/product-ocwsu-fixed-unit-price-display',
            array(
                'methods'             => array( WP_REST_Server::READABLE, WP_REST_Server::CREATABLE ),
                'permission_callback' => array( $this, 'permission_callback' ),
                'callback'            => array( $this, 'logged_ocwsu_fixed_unit_price_display_dispatch' ),
                'args'                => $this->product_id_args(),
            ),
            true
        );

        foreach ( $this->acf_product_checkbox_routes() as $route_suffix => $acf_field_name ) {
            register_rest_route(
                self::NAMESPACE,
                '/' . $route_suffix,
                array(
                    'methods'             => array( WP_REST_Server::READABLE, WP_REST_Server::CREATABLE ),
                    'permission_callback' => array( $this, 'permission_callback' ),
                    'callback'            => function ( $request ) use ( $route_suffix, $acf_field_name ) {
                        return $this->with_logging(
                            $request,
                            function () use ( $route_suffix, $acf_field_name ) {
                                return $this->legacy_product_label_dispatch( $route_suffix, $acf_field_name );
                            }
                        );
                    },
                    'args'                => $this->product_id_args(),
                ),
                true
            );
        }
    }

    /**
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function logged_product_label_dispatch( $request ) {
        return $this->with_logging(
            $request,
            function () use ( $request ) {
                return $this->product_label_dispatch( $request );
            }
        );
    }

    /**
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function logged_product_note_label_dispatch( $request ) {
        return $this->with_logging(
            $request,
            function () use ( $request ) {
                return $this->product_note_label_dispatch( $request );
            }
        );
    }

    /**
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function logged_ocwsu_fixed_unit_price_display_dispatch( $request ) {
        return $this->with_logging(
            $request,
            function () use ( $request ) {
                return $this->ocwsu_fixed_unit_price_display_dispatch( $request );
            }
        );
    }

    /**
     * Legacy per-label routes are intentionally disabled. Use /ed/v1/product-label instead.
     *
     * @param string $route_suffix   Legacy route suffix.
     * @param string $acf_field_name ACF field name formerly controlled by the route.
     * @return WP_Error
     */
    public function legacy_product_label_dispatch( $route_suffix, $acf_field_name ) {
        return new WP_Error(
            'ed_rest_legacy_product_label_endpoint_disabled',
            __( 'This product label endpoint is disabled. Use /wp-json/ed/v1/product-label instead.', 'oc-storeos-integration' ),
            array(
                'status'         => 410,
                'route_suffix'   => $route_suffix,
                'acf_field_name' => $acf_field_name,
            )
        );
    }

    /**
     * Log sanitized inbound and outbound payloads for these StoreOS REST endpoints.
     *
     * @param WP_REST_Request $request Request.
     * @param callable        $callback Endpoint handler.
     * @return mixed
     */
    protected function with_logging( $request, $callback ) {
        $this->log_endpoint_event(
            'info',
            'ED product REST request received.',
            array(
                'method'      => $request->get_method(),
                'route'       => $request->get_route(),
                'query'       => $request->get_query_params(),
                'json'        => $this->get_json_params_for_log( $request ),
                'has_api_key' => '' !== trim( (string) $request->get_header( 'x-api-key' ) ),
                'has_bearer'  => preg_match( '/^Bearer\s+/i', trim( (string) $request->get_header( 'authorization' ) ) ) ? true : false,
            )
        );

        $response = call_user_func( $callback );

        $log_data = array(
            'method' => $request->get_method(),
            'route'  => $request->get_route(),
        );

        if ( $response instanceof WP_Error ) {
            $log_data['status']  = $this->get_error_status( $response );
            $log_data['error']   = $response->get_error_code();
            $log_data['message'] = $response->get_error_message();
        } elseif ( $response instanceof WP_REST_Response ) {
            $log_data['status'] = $response->get_status();
            $log_data['body']   = $response->get_data();
        } else {
            $log_data['body'] = $response;
        }

        $this->log_endpoint_event(
            $response instanceof WP_Error ? 'warning' : 'info',
            'ED product REST response sent.',
            $log_data
        );

        return $response;
    }

    /**
     * @param WP_REST_Request $request Request.
     * @return array|null
     */
    protected function get_json_params_for_log( $request ) {
        $params = $request->get_json_params();
        return is_array( $params ) ? $params : null;
    }

    /**
     * @param WP_Error $error Error.
     * @return int
     */
    protected function get_error_status( $error ) {
        $data = $error->get_error_data();
        if ( is_array( $data ) && ! empty( $data['status'] ) ) {
            return (int) $data['status'];
        }

        return 500;
    }

    /**
     * @param string $level   Log level.
     * @param string $message Message.
     * @param array  $context Context.
     */
    protected function log_endpoint_event( $level, $message, array $context ) {
        $context = array_merge(
            array( 'source' => 'giorgio' ),
            $context
        );

        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->log( $level, $message . ' ' . wp_json_encode( $context ), array( 'source' => 'giorgio' ) );
            return;
        }

        error_log( '[OC Giorgio] ' . $message . ' ' . wp_json_encode( $context ) );
    }

    /**
     * Require the configured Giorgio API token for these external sync endpoints.
     *
     * Supports:
     * - Authorization: Bearer <token>
     * - X-Api-Key: <token>
     *
     * @param WP_REST_Request $request Request.
     * @return true|WP_Error
     */
    public function permission_callback( $request ) {
        $options        = $this->integration->get_options();
        $expected_token = isset( $options['api_token'] ) ? trim( (string) $options['api_token'] ) : '';

        if ( '' === $expected_token ) {
            $this->log_auth_failure( $request, 'token_not_configured', 503 );

            return new WP_Error(
                'oc_storeos_rest_token_not_configured',
                __( 'Giorgio API token is not configured.', 'oc-storeos-integration' ),
                array( 'status' => 503 )
            );
        }

        $provided_token = $this->get_request_token( $request );

        if ( '' !== $provided_token && hash_equals( $expected_token, $provided_token ) ) {
            return true;
        }

        $this->log_auth_failure( $request, 'invalid_or_missing_token', 401 );

        return new WP_Error(
            'oc_storeos_rest_forbidden',
            __( 'Invalid or missing Giorgio API token.', 'oc-storeos-integration' ),
            array( 'status' => 401 )
        );
    }

    /**
     * @param WP_REST_Request $request Request.
     * @return string
     */
    protected function get_request_token( $request ) {
        $api_key = trim( (string) $request->get_header( 'x-api-key' ) );
        if ( '' !== $api_key ) {
            return $api_key;
        }

        $authorization = trim( (string) $request->get_header( 'authorization' ) );
        if ( preg_match( '/^Bearer\s+(.+)$/i', $authorization, $matches ) ) {
            return trim( (string) $matches[1] );
        }

        return '';
    }

    /**
     * @param WP_REST_Request $request Request.
     * @param string          $reason  Failure reason.
     * @param int             $status  HTTP status.
     */
    protected function log_auth_failure( $request, $reason, $status ) {
        $this->log_endpoint_event(
            'warning',
            'ED product REST authentication failed.',
            array(
                'method'      => $request->get_method(),
                'route'       => $request->get_route(),
                'status'      => (int) $status,
                'reason'      => $reason,
                'query'       => $request->get_query_params(),
                'json'        => $this->get_json_params_for_log( $request ),
                'has_api_key' => '' !== trim( (string) $request->get_header( 'x-api-key' ) ),
                'has_bearer'  => preg_match( '/^Bearer\s+/i', trim( (string) $request->get_header( 'authorization' ) ) ) ? true : false,
            )
        );
    }

    /**
     * Generic GET/POST handler for any existing ACF boolean-like product label.
     *
     * GET: ?product_id=123&field_key=gluten_free
     * POST single: { "product_id": 123, "field_key": "gluten_free", "value": true }
     * POST batch: { "product_id": 123, "labels": { "gluten_free": true, "frozen": false } }
     * Batch updates valid labels and reports per-label errors for invalid ones.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function product_label_dispatch( $request ) {
        if ( ! function_exists( 'get_field' ) || ! function_exists( 'update_field' ) ) {
            return new WP_Error(
                'ed_rest_acf_missing',
                __( 'ACF is not available.', 'oc-storeos-integration' ),
                array( 'status' => 503 )
            );
        }

        $product_id = (int) $request->get_param( 'product_id' );
        $field_key  = sanitize_key( (string) $request->get_param( 'field_key' ) );
        $params     = array();

        if ( 'POST' === $request->get_method() ) {
            $params = $request->get_json_params();
            if ( ! is_array( $params ) ) {
                return new WP_Error(
                    'ed_rest_invalid_json',
                    __( 'Invalid JSON body.', 'oc-storeos-integration' ),
                    array( 'status' => 400 )
                );
            }
            if ( ! empty( $params['product_id'] ) ) {
                $product_id = absint( $params['product_id'] );
            }
            if ( ! empty( $params['field_key'] ) ) {
                $field_key = sanitize_key( (string) $params['field_key'] );
            }
        }

        if ( $product_id <= 0 ) {
            return new WP_Error(
                'ed_rest_missing_product_id',
                __( 'Missing or invalid product_id (query or JSON body).', 'oc-storeos-integration' ),
                array( 'status' => 400 )
            );
        }

        if ( get_post_type( $product_id ) !== 'product' ) {
            return new WP_Error(
                'ed_rest_invalid_product',
                __( 'Invalid product_id.', 'oc-storeos-integration' ),
                array( 'status' => 404 )
            );
        }

        if ( 'POST' === $request->get_method() ) {
            $is_batch = array_key_exists( 'labels', $params );
            $labels   = $this->get_product_label_updates_from_request( $params, $field_key );
            if ( $labels instanceof WP_Error ) {
                return $labels;
            }

            if ( $is_batch ) {
                $result = $this->apply_product_label_batch_updates( $product_id, $labels );

                return new WP_REST_Response(
                    array(
                        'success'    => empty( $result['errors'] ),
                        'product_id' => $product_id,
                        'labels'     => $result['labels'],
                        'errors'     => $result['errors'],
                    ),
                    200
                );
            }

            foreach ( $labels as $label_field_key => $normalized ) {
                $field = $this->get_acf_field_for_product_label( $label_field_key );
                if ( $field instanceof WP_Error ) {
                    return $field;
                }
                update_field( $label_field_key, $normalized ? 1 : 0, $product_id );
            }

            return new WP_REST_Response(
                array(
                    'success'    => true,
                    'product_id' => $product_id,
                    'labels'     => $this->get_product_label_values( $product_id, array_keys( $labels ) ),
                ),
                200
            );
        }

        $field = $this->get_acf_field_for_product_label( $field_key );
        if ( $field instanceof WP_Error ) {
            return $field;
        }

        return new WP_REST_Response(
            array(
                'product_id' => $product_id,
                'field_key'  => $field_key,
                'value'      => $this->acf_checkbox_truthy( get_field( $field_key, $product_id ) ),
            ),
            200
        );
    }

    /**
     * @param array  $params    JSON body.
     * @param string $field_key Single field key fallback.
     * @return array<string, bool>|WP_Error
     */
    protected function get_product_label_updates_from_request( array $params, $field_key ) {
        if ( array_key_exists( 'labels', $params ) ) {
            if ( ! is_array( $params['labels'] ) || empty( $params['labels'] ) ) {
                return new WP_Error(
                    'ed_rest_invalid_labels',
                    __( 'Field "labels" must be a non-empty object of field_key => boolean.', 'oc-storeos-integration' ),
                    array( 'status' => 400 )
                );
            }

            $labels = array();
            foreach ( $params['labels'] as $raw_key => $raw_value ) {
                $label_field_key = sanitize_key( (string) $raw_key );
                if ( '' === $label_field_key ) {
                    return new WP_Error(
                        'ed_rest_invalid_field_key',
                        __( 'Label field keys must be non-empty strings.', 'oc-storeos-integration' ),
                        array( 'status' => 400 )
                    );
                }

                $normalized = $this->normalize_json_boolean( $raw_value );
                if ( null === $normalized ) {
                    return new WP_Error(
                        'ed_rest_invalid_boolean',
                        sprintf(
                            __( 'Label "%s" must be a boolean or yes/no.', 'oc-storeos-integration' ),
                            $label_field_key
                        ),
                        array( 'status' => 400 )
                    );
                }

                $labels[ $label_field_key ] = $normalized;
            }

            return $labels;
        }

        if ( '' === $field_key ) {
            return new WP_Error(
                'ed_rest_missing_field_key',
                __( 'Missing field_key.', 'oc-storeos-integration' ),
                array( 'status' => 400 )
            );
        }

        if ( ! array_key_exists( 'value', $params ) ) {
            return new WP_Error(
                'ed_rest_missing_field',
                __( 'Missing "value" in JSON body.', 'oc-storeos-integration' ),
                array( 'status' => 400 )
            );
        }

        $normalized = $this->normalize_json_boolean( $params['value'] );
        if ( null === $normalized ) {
            return new WP_Error(
                'ed_rest_invalid_boolean',
                __( 'Field "value" must be a boolean or yes/no.', 'oc-storeos-integration' ),
                array( 'status' => 400 )
            );
        }

        return array( $field_key => $normalized );
    }

    /**
     * @param int      $product_id Product ID.
     * @param string[] $field_keys ACF field names.
     * @return array<string, bool>
     */
    protected function get_product_label_values( $product_id, array $field_keys ) {
        $labels = array();
        foreach ( $field_keys as $field_key ) {
            $labels[ $field_key ] = $this->acf_checkbox_truthy( get_field( $field_key, $product_id ) );
        }

        return $labels;
    }

    /**
     * Apply batch updates with per-label errors.
     *
     * @param int                 $product_id Product ID.
     * @param array<string, bool> $labels     field_key => normalized boolean.
     * @return array{labels: array<string, bool>, errors: array<string, array<string, string>>}
     */
    protected function apply_product_label_batch_updates( $product_id, array $labels ) {
        $updated = array();
        $errors  = array();

        foreach ( $labels as $label_field_key => $normalized ) {
            $field = $this->get_acf_field_for_product_label( $label_field_key );
            if ( $field instanceof WP_Error ) {
                $errors[ $label_field_key ] = array(
                    'code'    => $field->get_error_code(),
                    'message' => $field->get_error_message(),
                );
                continue;
            }

            update_field( $label_field_key, $normalized ? 1 : 0, $product_id );
            $updated[ $label_field_key ] = $this->acf_checkbox_truthy( get_field( $label_field_key, $product_id ) );
        }

        return array(
            'labels' => $updated,
            'errors' => $errors,
        );
    }

    /**
     * @param string $field_key ACF field key/name.
     * @return array|WP_Error
     */
    protected function get_acf_field_for_product_label( $field_key ) {
        if ( '' === $field_key ) {
            return new WP_Error(
                'ed_rest_missing_field_key',
                __( 'Missing field_key.', 'oc-storeos-integration' ),
                array( 'status' => 400 )
            );
        }

        if ( ! function_exists( 'acf_get_field' ) ) {
            return new WP_Error(
                'ed_rest_acf_missing',
                __( 'ACF is not available.', 'oc-storeos-integration' ),
                array( 'status' => 503 )
            );
        }

        $field = acf_get_field( $field_key );
        if ( empty( $field ) || ! is_array( $field ) ) {
            return new WP_Error(
                'ed_rest_acf_field_not_found',
                sprintf(
                    __( 'ACF field "%s" does not exist.', 'oc-storeos-integration' ),
                    $field_key
                ),
                array( 'status' => 404 )
            );
        }

        $field_type = isset( $field['type'] ) ? (string) $field['type'] : '';
        if ( ! in_array( $field_type, array( 'true_false', 'checkbox' ), true ) ) {
            return new WP_Error(
                'ed_rest_acf_field_type_not_supported',
                sprintf(
                    __( 'ACF field "%s" must be true_false or checkbox.', 'oc-storeos-integration' ),
                    $field_key
                ),
                array(
                    'status'     => 400,
                    'field_type' => $field_type,
                )
            );
        }

        return $field;
    }

    /**
     * @return array
     */
    protected function product_id_args() {
        return array(
            'product_id' => array(
                'description'       => __( 'WooCommerce product ID.', 'oc-storeos-integration' ),
                'type'              => 'integer',
                'required'          => false,
                'sanitize_callback' => 'absint',
            ),
        );
    }

    /**
     * @return array<string, string>
     */
    protected function acf_product_checkbox_routes() {
        return array(
            'product-new'                 => 'new',
            'product-bestseller'          => 'bestseller',
            'product-low-availability'    => 'low_availability',
            'product-readytocook'         => 'readytocook',
            'product-natural'             => 'natural',
            'product-sugarfree'           => 'sugarfree',
            'product-gluten-free'         => 'gluten_free',
            'product-lactosefree'         => 'lactosefree',
            'product-frozen'              => 'frozen',
            'product-kosher-for-passover' => 'kosher_for_passover',
            'product-not-kosher'          => 'not_kosher',
        );
    }

    /**
     * @return WP_REST_Response
     */
    public function get_product_note_label() {
        if ( function_exists( 'deliz_short_get_product_note_label' ) ) {
            $label = deliz_short_get_product_note_label();
        } else {
            $label = __( 'Note to butcher', 'oc-storeos-integration' );
        }

        return new WP_REST_Response(
            array(
                'product_note_label' => $label,
            ),
            200
        );
    }

    /**
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function product_note_label_dispatch( $request ) {
        if ( 'POST' !== $request->get_method() ) {
            return $this->get_product_note_label();
        }

        $params = $request->get_json_params();
        if ( ! is_array( $params ) ) {
            return new WP_Error(
                'ed_rest_invalid_json',
                __( 'Invalid JSON body.', 'oc-storeos-integration' ),
                array( 'status' => 400 )
            );
        }

        if ( ! array_key_exists( 'product_note_label', $params ) ) {
            return new WP_Error(
                'ed_rest_missing_field',
                __( 'Missing product_note_label in body.', 'oc-storeos-integration' ),
                array( 'status' => 400 )
            );
        }

        $raw = $params['product_note_label'];
        if ( is_array( $raw ) || is_object( $raw ) ) {
            return new WP_Error(
                'ed_rest_invalid_type',
                __( 'product_note_label must be a string.', 'oc-storeos-integration' ),
                array( 'status' => 400 )
            );
        }

        if ( ! function_exists( 'update_field' ) ) {
            return new WP_Error(
                'ed_rest_acf_missing',
                __( 'ACF is not available.', 'oc-storeos-integration' ),
                array( 'status' => 503 )
            );
        }

        $label = sanitize_text_field( wp_unslash( (string) $raw ) );
        update_field( 'product_note_label', $label, 'option' );

        $out = function_exists( 'deliz_short_get_product_note_label' )
            ? deliz_short_get_product_note_label()
            : $label;

        return new WP_REST_Response(
            array(
                'success'            => true,
                'product_note_label' => $out,
            ),
            200
        );
    }

    /**
     * @param mixed $raw Raw input.
     * @return string|null
     */
    protected function normalize_yes_no_meta( $raw ) {
        if ( null === $raw ) {
            return null;
        }
        if ( is_bool( $raw ) ) {
            return $raw ? 'yes' : '';
        }
        if ( is_numeric( $raw ) ) {
            return (int) $raw ? 'yes' : '';
        }

        $value = strtolower( trim( wp_unslash( (string) $raw ) ) );
        if ( in_array( $value, array( 'yes', '1', 'true', 'on' ), true ) ) {
            return 'yes';
        }
        if ( in_array( $value, array( 'no', '0', 'false', 'off', '' ), true ) ) {
            return '';
        }

        return null;
    }

    /**
     * @param int $product_id Product ID.
     * @return WP_REST_Response|WP_Error
     */
    protected function ocwsu_fixed_unit_price_display_response( $product_id ) {
        $product_id = absint( $product_id );
        if ( ! $product_id || get_post_type( $product_id ) !== 'product' ) {
            return new WP_Error(
                'ed_rest_invalid_product',
                __( 'Invalid product_id.', 'oc-storeos-integration' ),
                array( 'status' => 404 )
            );
        }

        $enabled = get_post_meta( $product_id, '_ocwsu_display_price_per_fixed_unit', true ) === 'yes';
        $label   = get_post_meta( $product_id, '_ocwsu_display_price_per_fixed_unit_label', true );
        if ( ! is_string( $label ) ) {
            $label = '';
        }

        $opts = function_exists( 'ocwsu_get_fixed_unit_price_display_label_options' )
            ? ocwsu_get_fixed_unit_price_display_label_options()
            : array();

        if ( '' === $label || ! isset( $opts[ $label ] ) ) {
            $label = 'piece';
        }

        return new WP_REST_Response(
            array(
                'product_id'                         => $product_id,
                'display_price_per_fixed_unit'       => $enabled,
                'display_price_per_fixed_unit_label' => $label,
                'label_options'                      => $opts,
                'label_option_keys'                  => array_keys( $opts ),
            ),
            200
        );
    }

    /**
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function ocwsu_fixed_unit_price_display_dispatch( $request ) {
        $product_id = (int) $request->get_param( 'product_id' );

        if ( 'POST' === $request->get_method() ) {
            $params = $request->get_json_params();
            if ( ! is_array( $params ) ) {
                return new WP_Error(
                    'ed_rest_invalid_json',
                    __( 'Invalid JSON body.', 'oc-storeos-integration' ),
                    array( 'status' => 400 )
                );
            }
            if ( ! empty( $params['product_id'] ) ) {
                $product_id = absint( $params['product_id'] );
            }
        }

        if ( $product_id <= 0 ) {
            return new WP_Error(
                'ed_rest_missing_product_id',
                __( 'Missing or invalid product_id (query or JSON body).', 'oc-storeos-integration' ),
                array( 'status' => 400 )
            );
        }

        if ( get_post_type( $product_id ) !== 'product' ) {
            return new WP_Error(
                'ed_rest_invalid_product',
                __( 'Invalid product_id.', 'oc-storeos-integration' ),
                array( 'status' => 404 )
            );
        }

        if ( 'POST' !== $request->get_method() ) {
            return $this->ocwsu_fixed_unit_price_display_response( $product_id );
        }

        $params = $request->get_json_params();
        $opts   = function_exists( 'ocwsu_get_fixed_unit_price_display_label_options' )
            ? ocwsu_get_fixed_unit_price_display_label_options()
            : array();

        if ( array_key_exists( 'display_price_per_fixed_unit', $params ) ) {
            $norm = $this->normalize_yes_no_meta( $params['display_price_per_fixed_unit'] );
            if ( null === $norm ) {
                return new WP_Error(
                    'ed_rest_invalid_boolean',
                    __( 'display_price_per_fixed_unit must be a boolean or yes/no.', 'oc-storeos-integration' ),
                    array( 'status' => 400 )
                );
            }

            if ( 'yes' === $norm ) {
                update_post_meta( $product_id, '_ocwsu_display_price_per_fixed_unit', 'yes' );
            } else {
                delete_post_meta( $product_id, '_ocwsu_display_price_per_fixed_unit' );
            }
        }

        if ( array_key_exists( 'display_price_per_fixed_unit_label', $params ) ) {
            $label_key = sanitize_key( wp_unslash( (string) $params['display_price_per_fixed_unit_label'] ) );
            if ( ! isset( $opts[ $label_key ] ) ) {
                return new WP_Error(
                    'ed_rest_invalid_label',
                    sprintf(
                        __( 'display_price_per_fixed_unit_label must be one of: %s', 'oc-storeos-integration' ),
                        implode( ', ', array_keys( $opts ) )
                    ),
                    array( 'status' => 400 )
                );
            }
            update_post_meta( $product_id, '_ocwsu_display_price_per_fixed_unit_label', $label_key );
        }

        $response = $this->ocwsu_fixed_unit_price_display_response( $product_id );
        if ( $response instanceof WP_Error ) {
            return $response;
        }

        $data            = $response->get_data();
        $data['success'] = true;

        return new WP_REST_Response( $data, 200 );
    }

    /**
     * @param mixed $raw Raw JSON value.
     * @return bool|null
     */
    protected function normalize_json_boolean( $raw ) {
        if ( null === $raw ) {
            return null;
        }
        if ( is_bool( $raw ) ) {
            return $raw;
        }
        if ( is_numeric( $raw ) ) {
            return (int) $raw !== 0;
        }

        $value = strtolower( trim( wp_unslash( (string) $raw ) ) );
        if ( in_array( $value, array( 'yes', '1', 'true', 'on' ), true ) ) {
            return true;
        }
        if ( in_array( $value, array( 'no', '0', 'false', 'off', '' ), true ) ) {
            return false;
        }

        return null;
    }

    /**
     * @param mixed $value ACF value.
     * @return bool
     */
    protected function acf_checkbox_truthy( $value ) {
        if ( null === $value || '' === $value || false === $value ) {
            return false;
        }
        if ( true === $value || 1 === $value || '1' === $value ) {
            return true;
        }
        if ( is_array( $value ) ) {
            return count( $value ) > 0;
        }

        return (bool) $value;
    }

    /**
     * @param WP_REST_Request $request        Request.
     * @param string          $acf_field_name ACF product field name.
     * @return WP_REST_Response|WP_Error
     */
    public function acf_product_checkbox_dispatch( $request, $acf_field_name ) {
        if ( ! function_exists( 'get_field' ) || ! function_exists( 'update_field' ) ) {
            return new WP_Error(
                'ed_rest_acf_missing',
                __( 'ACF is not available.', 'oc-storeos-integration' ),
                array( 'status' => 503 )
            );
        }

        if ( ! in_array( $acf_field_name, $this->acf_product_checkbox_routes(), true ) ) {
            return new WP_Error(
                'ed_rest_invalid_acf_field',
                __( 'Invalid ACF field.', 'oc-storeos-integration' ),
                array( 'status' => 400 )
            );
        }

        $product_id = (int) $request->get_param( 'product_id' );

        if ( 'POST' === $request->get_method() ) {
            $params = $request->get_json_params();
            if ( ! is_array( $params ) ) {
                return new WP_Error(
                    'ed_rest_invalid_json',
                    __( 'Invalid JSON body.', 'oc-storeos-integration' ),
                    array( 'status' => 400 )
                );
            }
            if ( ! empty( $params['product_id'] ) ) {
                $product_id = absint( $params['product_id'] );
            }
        }

        if ( $product_id <= 0 ) {
            return new WP_Error(
                'ed_rest_missing_product_id',
                __( 'Missing or invalid product_id (query or JSON body).', 'oc-storeos-integration' ),
                array( 'status' => 400 )
            );
        }

        if ( get_post_type( $product_id ) !== 'product' ) {
            return new WP_Error(
                'ed_rest_invalid_product',
                __( 'Invalid product_id.', 'oc-storeos-integration' ),
                array( 'status' => 404 )
            );
        }

        if ( 'POST' === $request->get_method() ) {
            $params = $request->get_json_params();

            if ( ! array_key_exists( $acf_field_name, $params ) ) {
                return new WP_Error(
                    'ed_rest_missing_field',
                    sprintf(
                        __( 'Missing "%s" in JSON body.', 'oc-storeos-integration' ),
                        $acf_field_name
                    ),
                    array( 'status' => 400 )
                );
            }

            $normalized = $this->normalize_json_boolean( $params[ $acf_field_name ] );
            if ( null === $normalized ) {
                return new WP_Error(
                    'ed_rest_invalid_boolean',
                    sprintf(
                        __( 'Field "%s" must be a boolean or yes/no.', 'oc-storeos-integration' ),
                        $acf_field_name
                    ),
                    array( 'status' => 400 )
                );
            }

            update_field( $acf_field_name, $normalized ? 1 : 0, $product_id );
        }

        $stored = get_field( $acf_field_name, $product_id );
        $data   = array(
            'product_id'    => $product_id,
            $acf_field_name => $this->acf_checkbox_truthy( $stored ),
        );

        if ( 'POST' === $request->get_method() ) {
            $data['success'] = true;
        }

        return new WP_REST_Response( $data, 200 );
    }
}
