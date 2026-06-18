<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OC_StoreOS_Integration {
    const OPTION_GROUP   = 'oc_storeos_integration_options_group';
    const OPTION_NAME    = 'oc_storeos_integration_options';
    const META_SYNCED    = '_oc_storeos_synced';
    const META_LAST_ERR  = '_oc_storeos_last_error';
    const META_LAST_SYNC = '_oc_storeos_last_sync';

    /** @var string Uploads-relative directory for REST incoming log. */
    const REST_INCOMING_LOG_DIR = 'giorgio';

    /** @var string Log file name (under REST_INCOMING_LOG_DIR or WP_CONTENT_DIR fallback). */
    const REST_INCOMING_LOG_FILE = 'incoming-rest-orders.log';

    /**
     * Outgoing (WooCommerce → Giorgio/Giorgio) payload log file. Same directory as the incoming log.
     */
    const REST_OUTGOING_LOG_FILE = 'outgoing-rest-orders.log';

    /**
     * Transient: hash of last successful WooCommerce/Order JSON per order (duplicate POST guard).
     */
    const TRANSIENT_OUT_ORDER_HASH_PREFIX = 'oc_storeos_out_order_hash_';

    /** Seconds: ignore identical outgoing Order payload when repeated (double webhook / HTTP retry). */
    const OUT_ORDER_DEDUP_TTL = 45;

    /**
     * Payment-method row: "do not send order to Giorgio for this gateway" (per-method override).
     */
    const PAYMENT_METHOD_SEND_ORDER_STATUS_OFF = '__oc_storeos_send_order_off__';

    /**
     * Cardcom internal deal number stored on the order by the gateway (preferred transaction id for OrderPayment).
     */
    const META_CARDCOM_PAYMENT_ID = 'Cardcom Payment ID';

    /**
     * Cardcom internal deal number from woo-cardcom-payment-gateway (J5 / capture / token charge).
     */
    const META_CARDCOM_INTERNAL_DEAL_NUMBER = 'CardcomInternalDealNumber';

    /**
     * Saved Cardcom token expiry month/year on the order (woo-cardcom-payment-gateway).
     */
    const META_CARDCOM_TOKEN_EXPIRY_MONTH = 'CardcomToken_expiry_month';
    const META_CARDCOM_TOKEN_EXPIRY_YEAR  = 'CardcomToken_expiry_year';

    /**
     * Number of Cardcom installments on the order (woo-cardcom-payment-gateway).
     */
    const META_CARDCOM_NUM_OF_PAYMENTS = 'cardcom_NumOfPayments';

    /**
     * Masked card number / last digits from Cardcom (woo-cardcom-payment-gateway).
     */
    const META_CARDCOM_CC_NUMBER = 'cc_number';

    /**
     * WooCommerce payment method id for Cardcom (woo-cardcom-payment-gateway).
     */
    const GATEWAY_CARDCOM = 'cardcom';

    /**
     * WooCommerce logger source (WooCommerce → Status → Logs).
     */
    const WC_LOG_SOURCE = 'giorgio';

    /**
     * REST namespace for inbound Giorgio calls. The new brand namespace is primary; the legacy
     * namespace is still registered as an alias so the Giorgio backend keeps working until it is
     * updated to call the new one. The settings page shows the primary namespace only.
     */
    const REST_NAMESPACE        = 'giorgio/v1';
    const REST_NAMESPACE_LEGACY = 'oc-storeos/v1';

    /**
     * Preferred header name for the inbound shared secret (Giorgio → WooCommerce).
     * `X-Api-Key` and `Authorization: Bearer <token>` are also accepted for symmetry
     * with the outbound calls.
     */
    const HEADER_INCOMING_TOKEN = 'X-OC-Giorgio-Token';

    /**
     * Inbound auth enforcement modes (option `incoming_auth_mode`).
     * - off     : no checking (legacy behavior). Default, so updating never breaks a live site.
     * - log     : verify + log the result, but allow every request through (rollout / debugging).
     * - enforce : reject (HTTP 401) when the secret is configured and the request token is missing/wrong.
     */
    const INCOMING_AUTH_MODE_OFF     = 'off';
    const INCOMING_AUTH_MODE_LOG     = 'log';
    const INCOMING_AUTH_MODE_ENFORCE = 'enforce';

    /**
     * Guard against nested / duplicate dispatch in the same request (e.g. payment_complete + status completed).
     *
     * @var array<int, bool>
     */
    protected static $payment_webhook_v2_dispatching = array();

    /**
     * Same PHP request: skip sending an identical successful payload twice (payment_complete + completed).
     *
     * @var array<int, string> order_id => md5( json payload )
     */
    protected static $payment_webhook_v2_ok_payload_hash = array();

    /**
     * One outgoing Order sync per order per HTTP request (avoid double POST from status + REST echo).
     *
     * @var array<int, true>
     */
    protected static $outgoing_sync_after_creation_done = array();

    /**
     * Re-entrancy guard for the HPOS-safe `woocommerce_update_order` Cardcom trigger,
     * so meta saved by our own dispatch cannot recurse into another dispatch.
     *
     * @var array<int, true>
     */
    protected static $cardcom_order_save_trigger_running = array();

    /**
     * Singleton instance.
     *
     * @var OC_StoreOS_Integration|null
     */
    protected static $instance = null;

    /**
     * Get singleton instance.
     *
     * @return OC_StoreOS_Integration
     */
    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        // Admin settings.
        add_action( 'admin_menu', array( $this, 'register_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // "Settings" link on the Plugins list row.
        if ( defined( 'OC_STOREOS_INTEGRATION_PLUGIN_FILE' ) ) {
            add_filter(
                'plugin_action_links_' . plugin_basename( OC_STOREOS_INTEGRATION_PLUGIN_FILE ),
                array( $this, 'add_settings_action_link' )
            );
        }

        // Add optional percentage fee to the cart/checkout totals.
        add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_order_percentage_fee' ), 20, 1 );
        add_action( 'wp_head', array( $this, 'render_fee_tooltip_styles' ) );

        // REST API.
        add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
        add_filter( 'rest_pre_dispatch', array( $this, 'log_rest_orders_pre_dispatch' ), 5, 3 );

        // Outgoing order: sync when the order hits the effective WC status for that order's payment method (not on creation).
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_status_for_storeos_outgoing' ), 20, 4 );

        // Payment webhook (Woo → Giorgio OrderPayment), new format — late so Cardcom meta is saved first.
        add_action( 'woocommerce_payment_complete', array( $this, 'handle_payment_complete_webhook_v2' ), 99, 1 );
        add_action( 'woocommerce_order_status_changed', array( $this, 'handle_order_completed_payment_webhook_v2' ), 99, 4 );

        // Safety net: if an order is already completed and Cardcom transaction meta is saved later,
        // send OrderPayment v2 right after the meta is persisted.
        add_action( 'updated_post_meta', array( $this, 'maybe_send_payment_webhook_v2_after_cardcom_meta_saved' ), 20, 4 );
        add_action( 'added_post_meta', array( $this, 'maybe_send_payment_webhook_v2_after_cardcom_meta_saved' ), 20, 4 );

        // HPOS-safe complementary trigger: under custom order tables the postmeta hooks above do not
        // fire for order meta, so also re-check on order save (cheap no-op unless a Cardcom deal exists).
        add_action( 'woocommerce_update_order', array( $this, 'maybe_trigger_after_order_save_for_cardcom' ), 20, 2 );

        // Retry outgoing order shortly when waiting for Cardcom transaction meta.
        add_action( 'oc_storeos_retry_outgoing_order_after_cardcom_tx', array( $this, 'retry_outgoing_order_after_cardcom_tx' ), 10, 1 );

        // Cardcom מתחבר ל־woocommerce_order_status_completed בעדיפות 10; ריענון סכום ב־DB לפני כן (מתעלמים מתוסף Cardcom).
        add_action( 'woocommerce_order_status_completed', array( $this, 'maybe_refresh_order_totals_before_cardcom_capture' ), 5, 2 );

        // Make sure Giorgio-created orders have a nice, readable address
        // in the WooCommerce order screen preview, even when OC Woo Shipping
        // overrides the default formatting.
        add_filter( 'woocommerce_order_get_formatted_billing_address', array( $this, 'filter_formatted_billing_address' ), 20, 2 );
        add_filter( 'woocommerce_order_get_formatted_shipping_address', array( $this, 'filter_formatted_shipping_address' ), 20, 2 );
    }

    /**
     * When Cardcom transaction meta is saved after the order is already completed,
     * trigger OrderPayment v2 dispatch (same flow as handle_order_completed_payment_webhook_v2).
     *
     * @param int    $meta_id     Meta row ID.
     * @param int    $object_id   Post ID (order).
     * @param string $meta_key    Meta key.
     * @param mixed  $_meta_value Meta value.
     */
    public function maybe_send_payment_webhook_v2_after_cardcom_meta_saved( $meta_id, $object_id, $meta_key, $_meta_value ) {
        if ( self::META_CARDCOM_PAYMENT_ID !== (string) $meta_key && self::META_CARDCOM_INTERNAL_DEAL_NUMBER !== (string) $meta_key ) {
            return;
        }
        $v = is_scalar( $_meta_value ) ? trim( (string) $_meta_value ) : '';
        if ( '' === $v || '0' === $v ) {
            return;
        }
        if ( ! is_numeric( $object_id ) || (int) $object_id < 1 ) {
            return;
        }

        // NB: do not gate on get_post_type() === 'shop_order'. Under HPOS the order is not a post,
        // so that check would wrongly reject. wc_get_order() resolves the id in both storage modes;
        // the instanceof check below is the real guard.
        $order = wc_get_order( (int) $object_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $this->maybe_debug_log( sprintf( '[OC Giorgio] cardcom meta saved (postmeta hook). order_id=%d meta_key=%s', (int) $object_id, (string) $meta_key ) );
        $this->process_cardcom_meta_present_for_order( $order, 'postmeta_hook' );
    }

    /**
     * HPOS-safe complementary trigger. Under custom order tables the postmeta hooks do not fire for
     * order meta, so we also re-check whenever an order is saved. Cheap no-op unless a Cardcom deal
     * number is present on the order. Guarded against re-entrancy from our own meta saves.
     *
     * @param int            $order_id Order ID.
     * @param WC_Order|mixed $order    Order instance (passed by recent WooCommerce).
     */
    public function maybe_trigger_after_order_save_for_cardcom( $order_id, $order = null ) {
        $order_id = (int) $order_id;
        if ( $order_id < 1 ) {
            return;
        }
        if ( ! empty( self::$cardcom_order_save_trigger_running[ $order_id ] ) ) {
            return;
        }
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $deal = trim( (string) $order->get_meta( self::META_CARDCOM_PAYMENT_ID, true ) );
        if ( '' === $deal ) {
            $deal = trim( (string) $order->get_meta( self::META_CARDCOM_INTERNAL_DEAL_NUMBER, true ) );
        }
        if ( '' === $deal || '0' === $deal ) {
            return; // No Cardcom transaction yet — nothing to trigger.
        }

        self::$cardcom_order_save_trigger_running[ $order_id ] = true;
        try {
            $this->process_cardcom_meta_present_for_order( $order, 'order_save_hook' );
        } finally {
            unset( self::$cardcom_order_save_trigger_running[ $order_id ] );
        }
    }

    /**
     * Shared logic once a Cardcom transaction id is known to exist on the order:
     * (1) send the outgoing Order to Giorgio if the order sits at its trigger status and is not yet synced;
     * (2) send the OrderPayment v2 webhook when the order is completed.
     * Both downstream calls are independently de-duplicated, so invoking this from several hooks is safe.
     *
     * @param WC_Order $order  Order.
     * @param string   $source Label for logging (which hook called).
     */
    protected function process_cardcom_meta_present_for_order( WC_Order $order, $source = '' ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $order_id = $order->get_id();

        $trigger = $this->get_effective_send_order_to_storeos_status_for_order( $order );
        if ( '' !== (string) $trigger
            && self::PAYMENT_METHOD_SEND_ORDER_STATUS_OFF !== (string) $trigger
            && (string) $order->get_status() === (string) $trigger
            && (int) $order->get_meta( self::META_SYNCED, true ) !== 1
        ) {
            $this->oc_storeos_wc_log(
                'info',
                sprintf(
                    'Outgoing Order: Cardcom transaction present — triggering order sync. order_id=%d status=%s trigger=%s source=%s',
                    (int) $order_id,
                    (string) $order->get_status(),
                    (string) $trigger,
                    (string) $source
                ),
                array( 'order_id' => (int) $order_id )
            );
            $this->send_outgoing_when_order_enters( $order );
        }

        if ( 'completed' === (string) $order->get_status() ) {
            $this->oc_storeos_wc_log(
                'info',
                sprintf(
                    'OrderPayment v2: Cardcom transaction present on completed order — triggering dispatch. order_id=%d source=%s',
                    (int) $order_id,
                    (string) $source
                ),
                array( 'order_id' => (int) $order_id )
            );
            $this->maybe_send_order_payment_webhook_v2( $order );
        }
    }

    /**
     * Scheduled retry: after Cardcom transaction meta is expected to exist, re-attempt outgoing order sync.
     *
     * @param int $order_id Order ID.
     */
    public function retry_outgoing_order_after_cardcom_tx( $order_id ) {
        $order_id = (int) $order_id;
        if ( $order_id < 1 ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        if ( (int) $order->get_meta( self::META_SYNCED, true ) === 1 ) {
            return;
        }
        $this->maybe_debug_log( sprintf( '[OC Giorgio] Outgoing Order: scheduled retry running. order_id=%d status=%s', (int) $order_id, (string) $order->get_status() ) );
        $this->send_outgoing_when_order_enters( $order );
    }

    /**
     * Improve formatted billing address preview for Giorgio-created orders.
     *
     * @param string   $formatted The current formatted address string.
     * @param WC_Order $order     Order object.
     *
     * @return string
     */
    public function filter_formatted_billing_address( $formatted, $order ) {
        if ( ! $order instanceof WC_Order ) {
            return $formatted;
        }

        // Only touch orders that came from the external system.
        $external_id = $order->get_meta( '_oc_storeos_external_order_id', true );
        if ( '' === (string) $external_id ) {
            return $formatted;
        }

        $street  = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
        $city    = $order->get_billing_city();
        $zip     = $order->get_billing_postcode();

        $parts = array();
        if ( '' !== $street ) {
            $parts[] = $street;
        }
        if ( '' !== $city ) {
            $parts[] = $city;
        }
        if ( '' !== $zip ) {
            $parts[] = $zip;
        }

        if ( empty( $parts ) ) {
            return $formatted;
        }

        return implode( ', ', $parts );
    }

    /**
     * Improve formatted shipping address preview for Giorgio-created orders.
     *
     * @param string   $formatted The current formatted address string.
     * @param WC_Order $order     Order object.
     *
     * @return string
     */
    public function filter_formatted_shipping_address( $formatted, $order ) {
        if ( ! $order instanceof WC_Order ) {
            return $formatted;
        }

        $external_id = $order->get_meta( '_oc_storeos_external_order_id', true );
        if ( '' === (string) $external_id ) {
            return $formatted;
        }

        $street  = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
        $city    = $order->get_shipping_city();
        $zip     = $order->get_shipping_postcode();

        // Fallback to billing if shipping fields are empty.
        if ( '' === $street && '' === $city && '' === $zip ) {
            $street = trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() );
            $city   = $order->get_billing_city();
            $zip    = $order->get_billing_postcode();
        }

        $parts = array();
        if ( '' !== $street ) {
            $parts[] = $street;
        }
        if ( '' !== $city ) {
            $parts[] = $city;
        }
        if ( '' !== $zip ) {
            $parts[] = $zip;
        }

        if ( empty( $parts ) ) {
            return $formatted;
        }

        return implode( ', ', $parts );
    }

    /**
     * Register REST API routes.
     */
    public function register_rest_routes() {
        // Register the order endpoints under the new brand namespace AND the legacy namespace,
        // so Giorgio's existing calls keep working until its backend is pointed at the new one.
        foreach ( array( self::REST_NAMESPACE, self::REST_NAMESPACE_LEGACY ) as $ns ) {
            register_rest_route(
                $ns,
                '/orders',
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'rest_create_order' ),
                    'permission_callback' => array( $this, 'rest_check_incoming_auth' ),
                )
            );

            register_rest_route(
                $ns,
                '/default-closing-dates',
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'rest_update_default_closing_dates' ),
                    'permission_callback' => array( $this, 'rest_check_incoming_auth' ),
                    'args'                => array(),
                )
            );
        }

        // Giorgio product labels probe (used by external product sync).
        register_rest_route(
            'ed/v1',
            '/capabilities',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_ed_capabilities' ),
                'permission_callback' => array( $this, 'rest_check_incoming_auth' ),
            )
        );

        register_rest_route(
            'ed/v1',
            '/wolt',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'rest_ed_wolt' ),
                'permission_callback' => array( $this, 'rest_check_incoming_auth' ),
            )
        );
    }

    /**
     * Permission callback for every inbound Giorgio/Giorgio REST route.
     *
     * Compares a shared secret (option `incoming_api_token`) against the request token, read from
     * {@see HEADER_INCOMING_TOKEN}, `X-Api-Key`, or `Authorization: Bearer <token>` (first match wins).
     * Comparison is constant-time ({@see hash_equals}).
     *
     * Behavior is staged via option `incoming_auth_mode` so updating a live site never breaks the
     * integration before Giorgio starts sending the header:
     * - off     → always allow (legacy). No checking, no log.
     * - log     → always allow, but record the auth result so the rollout can be verified.
     * - enforce → reject with 401 when a secret is configured and the token is missing/wrong.
     *             If no secret is configured yet, allow + log a warning (fail-open while unconfigured,
     *             so enforce can never lock Giorgio out by accident).
     *
     * @param WP_REST_Request $request Request.
     * @return true|WP_Error True to allow; WP_Error (401) to block.
     */
    public function rest_check_incoming_auth( $request ) {
        $options   = $this->get_options();
        $mode      = isset( $options['incoming_auth_mode'] ) ? (string) $options['incoming_auth_mode'] : self::INCOMING_AUTH_MODE_OFF;
        $expected  = isset( $options['incoming_api_token'] ) ? (string) $options['incoming_api_token'] : '';
        if ( ! in_array( $mode, array( self::INCOMING_AUTH_MODE_OFF, self::INCOMING_AUTH_MODE_LOG, self::INCOMING_AUTH_MODE_ENFORCE ), true ) ) {
            $mode = self::INCOMING_AUTH_MODE_OFF;
        }

        // Escape hatch (e.g. to allow an external WAF/mTLS layer to own auth instead).
        if ( true === apply_filters( 'oc_storeos_bypass_incoming_auth', false, $request ) ) {
            return true;
        }

        // Mode "off": preserve legacy behavior exactly — no checking, no logging.
        if ( self::INCOMING_AUTH_MODE_OFF === $mode ) {
            return true;
        }

        $presented   = $this->get_presented_incoming_token( $request );
        $has_secret  = ( '' !== $expected );
        $has_token   = ( '' !== $presented );
        $matched     = ( $has_secret && $has_token && hash_equals( $expected, $presented ) );

        $route       = method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
        $remote_ip   = function_exists( 'rest_get_ip_address' )
            ? rest_get_ip_address()
            : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );

        $this->log_rest_incoming_order( array(
            'time_utc'   => gmdate( 'c' ),
            'remote_ip'  => $remote_ip,
            'result'     => 'auth',
            'route'      => $route,
            'mode'       => $mode,
            'has_secret' => $has_secret ? 'yes' : 'no',
            'has_token'  => $has_token ? 'yes' : 'no',
            'matched'    => $matched ? 'yes' : 'no',
        ) );

        // Mode "log": never block — only observe. Lets you confirm Giorgio sends the header correctly
        // before flipping to "enforce".
        if ( self::INCOMING_AUTH_MODE_LOG === $mode ) {
            return true;
        }

        // Mode "enforce".
        if ( ! $has_secret ) {
            // Fail-open while unconfigured so "enforce" can't accidentally lock the integration out.
            $this->oc_storeos_wc_log(
                'warning',
                sprintf( 'Inbound auth: enforce mode but no incoming_api_token configured — allowing request. route=%s ip=%s', $route, $remote_ip ),
                array( 'route' => $route )
            );
            return true;
        }

        if ( $matched ) {
            return true;
        }

        $this->oc_storeos_wc_log(
            'notice',
            sprintf( 'Inbound auth: rejected request (token missing/invalid). route=%s ip=%s has_token=%s', $route, $remote_ip, $has_token ? 'yes' : 'no' ),
            array( 'route' => $route )
        );

        return new WP_Error(
            'oc_storeos_unauthorized',
            __( 'Unauthorized.', 'oc-storeos-integration' ),
            array( 'status' => 401 )
        );
    }

    /**
     * Read the inbound shared secret from the request headers.
     * Order of precedence: {@see HEADER_INCOMING_TOKEN}, then `X-Api-Key`, then `Authorization: Bearer <token>`.
     *
     * @param WP_REST_Request $request Request.
     * @return string Token string, or '' when none is present.
     */
    protected function get_presented_incoming_token( $request ) {
        if ( ! ( $request instanceof WP_REST_Request ) ) {
            return '';
        }

        $token = (string) $request->get_header( self::HEADER_INCOMING_TOKEN );
        if ( '' !== trim( $token ) ) {
            return trim( $token );
        }

        $token = (string) $request->get_header( 'X-Api-Key' );
        if ( '' !== trim( $token ) ) {
            return trim( $token );
        }

        $auth = (string) $request->get_header( 'Authorization' );
        if ( '' !== trim( $auth ) && stripos( $auth, 'Bearer ' ) === 0 ) {
            return trim( substr( $auth, 7 ) );
        }

        return '';
    }

    /**
     * REST: capabilities probe endpoint for product labels.
     *
     * GET /wp-json/ed/v1/capabilities
     *
     * @return WP_REST_Response
     */
    public function rest_ed_capabilities() {
        return new WP_REST_Response(
            array(
                'product_labels' => (bool) $this->ed_product_labels_are_available(),
            ),
            200
        );
    }

    /**
     * Whether the site supports ACF-based product labels (any one field is enough).
     *
     * @return bool
     */
    protected function ed_product_labels_are_available() {
        if ( ! function_exists( 'acf_get_field' ) ) {
            return false;
        }
        return ( ! empty( acf_get_field( 'kosher_for_passover' ) ) || ! empty( acf_get_field( 'readytocook' ) ) );
    }

    /**
     * REST: Wolt plugin presence probe.
     *
     * GET /wp-json/ed/v1/wolt
     *
     * @return WP_REST_Response
     */
    public function rest_ed_wolt() {
        return new WP_REST_Response(
            array(
                'wolt' => class_exists( 'OCWS_Wolt' ),
            ),
            200
        );
    }

    /**
     * REST: set OC Woo Shipping "אל תכלול תאריכים" for default shipping or default local pickup.
     * - ocws_default_closing_dates — משלוח (קבוצה כללית).
     * - ocws_lp_default_closing_dates — איסוף עצמי (ברירת מחדל affiliate, טאב default-affiliate).
     * Stored as comma-separated d/m/Y strings, same as the admin multi-date picker.
     *
     * Body JSON:
     * { "type": "shipping", "dates": [ "09/04/2026" ] }
     * type/scope/delivery_type: shipping|delivery|general → משלוח; pickup|local_pickup|lp → איסוף (optional, default shipping).
     * Also accepts Y-m-d per date entry.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function rest_update_default_closing_dates( $request ) {
        $data = $request->get_json_params();
        if ( ! is_array( $data ) ) {
            return new WP_Error(
                'oc_storeos_invalid_body',
                __( 'Invalid JSON payload.', 'oc-storeos-integration' ),
                array( 'status' => 400 )
            );
        }

        $scope_raw = null;
        if ( isset( $data['type'] ) ) {
            $scope_raw = $data['type'];
        } elseif ( isset( $data['scope'] ) ) {
            $scope_raw = $data['scope'];
        } elseif ( isset( $data['delivery_type'] ) ) {
            $scope_raw = $data['delivery_type'];
        }

        $option_resolution = $this->resolve_default_closing_dates_option( $scope_raw );
        if ( is_wp_error( $option_resolution ) ) {
            return $option_resolution;
        }
        $option_name = $option_resolution['option'];
        $scope_key   = $option_resolution['scope'];

        $dates_in = array();
        if ( isset( $data['dates'] ) && is_array( $data['dates'] ) ) {
            $dates_in = $data['dates'];
        } elseif ( isset( $data['closing_dates'] ) && is_array( $data['closing_dates'] ) ) {
            $dates_in = $data['closing_dates'];
        } else {
            return new WP_Error(
                'oc_storeos_missing_dates',
                __( 'Missing "dates" array (or "closing_dates").', 'oc-storeos-integration' ),
                array( 'status' => 400 )
            );
        }

        $normalized = array();
        $invalid    = array();

        foreach ( $dates_in as $raw ) {
            if ( is_string( $raw ) || is_numeric( $raw ) ) {
                $parsed = $this->parse_incoming_closing_date_to_dmY( (string) $raw );
                if ( null !== $parsed ) {
                    $normalized[] = $parsed;
                } else {
                    $invalid[] = (string) $raw;
                }
            }
        }

        $normalized = array_values( array_unique( $normalized ) );

        if ( ! empty( $invalid ) ) {
            return new WP_Error(
                'oc_storeos_invalid_dates',
                __( 'One or more dates could not be parsed.', 'oc-storeos-integration' ),
                array(
                    'status'  => 400,
                    'invalid' => $invalid,
                )
            );
        }

        $stored = '';
        if ( function_exists( 'ocws_dates_array_to_string' ) ) {
            $stored = ocws_dates_array_to_string( $normalized );
        } else {
            $stored = implode( ',', $normalized );
        }

        update_option( $option_name, $stored );

        return new WP_REST_Response(
            array(
                'success' => true,
                'scope'   => $scope_key,
                'option'  => $option_name,
                'stored'  => $stored,
                'dates'   => $normalized,
            ),
            200
        );
    }

    /**
     * Map API scope to wp_option name for closing dates.
     *
     * @param mixed $scope_raw From JSON: type / scope / delivery_type.
     * @return array{ option: string, scope: string }|WP_Error
     */
    protected function resolve_default_closing_dates_option( $scope_raw ) {
        if ( null === $scope_raw || '' === trim( (string) $scope_raw ) ) {
            return array(
                'option' => 'ocws_default_closing_dates',
                'scope'  => 'shipping',
            );
        }

        $t = trim( (string) $scope_raw );
        if ( function_exists( 'mb_strtolower' ) ) {
            $s = mb_strtolower( $t, 'UTF-8' );
        } else {
            $s = strtolower( $t );
        }

        $shipping_aliases = array(
            'shipping',
            'delivery',
            'delivery_shipping',
            'general',
            'ship',
            'משלוח',
        );
        $pickup_aliases   = array(
            'pickup',
            'local_pickup',
            'local-pickup',
            'lp',
            'collection',
            'local_pickup_default',
            'איסוף',
            'איסוף עצמי',
        );

        if ( in_array( $s, $shipping_aliases, true ) ) {
            return array(
                'option' => 'ocws_default_closing_dates',
                'scope'  => 'shipping',
            );
        }
        if ( in_array( $s, $pickup_aliases, true ) ) {
            return array(
                'option' => 'ocws_lp_default_closing_dates',
                'scope'  => 'pickup',
            );
        }

        return new WP_Error(
            'oc_storeos_invalid_scope',
            __( 'Invalid type/scope for closing dates.', 'oc-storeos-integration' ),
            array(
                'status' => 400,
                'hint'   => 'Use type/scope/delivery_type: shipping (משלוח) or pickup (איסוף).',
            )
        );
    }

    /**
     * Parse a single date string to d/m/Y for closing-dates option storage.
     *
     * @param string $raw Raw date from API.
     * @return string|null Normalized d/m/Y or null.
     */
    protected function parse_incoming_closing_date_to_dmY( $raw ) {
        $raw = trim( (string) $raw );
        if ( '' === $raw ) {
            return null;
        }

        $formats = array( 'd/m/Y', 'd/m/y', 'Y-m-d' );

        if ( class_exists( '\Carbon\Carbon' ) && function_exists( 'ocws_get_timezone' ) ) {
            foreach ( $formats as $fmt ) {
                try {
                    $d = \Carbon\Carbon::createFromFormat( $fmt, $raw, ocws_get_timezone() );
                    return $d->format( 'd/m/Y' );
                } catch ( \InvalidArgumentException $e ) {
                    continue;
                }
            }
            return null;
        }

        $tz = wp_timezone();
        foreach ( $formats as $fmt ) {
            $dt = \DateTimeImmutable::createFromFormat( $fmt, $raw, $tz );
            if ( $dt instanceof \DateTimeImmutable ) {
                $errors = \DateTime::getLastErrors();
                if ( is_array( $errors ) && ( (int) $errors['error_count'] > 0 || (int) $errors['warning_count'] > 0 ) ) {
                    continue;
                }
                return $dt->format( 'd/m/Y' );
            }
        }

        return null;
    }

    /**
     * Log that WordPress reached REST dispatch for POST /orders (before callback).
     * If Postman shows logs but server-side calls show only this line (or nothing), the problem is before REST or after dispatch — compare with callback logs.
     *
     * @param mixed             $result  Short-circuit response or null.
     * @param WP_REST_Server    $server  Server.
     * @param WP_REST_Request   $request Request.
     * @return mixed
     */
    public function log_rest_orders_pre_dispatch( $result, $server, $request ) {
        if ( ! $request instanceof WP_REST_Request ) {
            return $result;
        }
        $route = $request->get_route();
        if ( false === strpos( (string) $route, 'oc-storeos/v1/orders' ) ) {
            return $result;
        }
        if ( 'POST' !== strtoupper( $request->get_method() ) ) {
            return $result;
        }
        $remote_ip = function_exists( 'rest_get_ip_address' ) ? rest_get_ip_address() : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
        $this->log_rest_incoming_order(
            array(
                'time_utc'    => gmdate( 'c' ),
                'result'      => 'rest_dispatch_reached',
                'route'       => $route,
                'remote_ip'   => $remote_ip,
                'user_agent'  => $request->get_header( 'user_agent' ),
                'auth_header' => $request->get_header( 'authorization' ) ? '(present)' : '(none)',
            )
        );
        return $result;
    }

    /**
     * הוספת שורת מוצר מ־REST Giorgio עם subtotal/total מה־payload כשקיימים (אחרת קטלוג × כמות).
     *
     * ברירת מחדל: lineTotal / unitPrice כולל מחיר נטו (ללא מע״מ), כמו ב־payload היוצא. פילטר:
     * `oc_storeos_rest_item_line_amount_includes_tax` — החזיר true כשהסכום מה־Giorgio כולל מע״מ.
     *
     * @param WC_Order   $order        Order.
     * @param WC_Product $product      מוצר שזוהה.
     * @param float      $quantity     כמות.
     * @param array      $payload_item איבר מתוך `items`.
     *
     * @return int|false מזהה שורת הזמנה מ־add_product או false.
     */
    protected function add_storeos_rest_order_line_from_payload( WC_Order $order, WC_Product $product, $quantity, array $payload_item ) {
        if ( ! $order instanceof WC_Order || ! $product instanceof WC_Product || $quantity <= 0 ) {
            return false;
        }

        $decimals = wc_get_price_decimals();
        $raw      = null;

        if ( isset( $payload_item['lineTotal'] ) && is_numeric( $payload_item['lineTotal'] ) ) {
            $raw = (float) $payload_item['lineTotal'];
        } elseif ( isset( $payload_item['line_total'] ) && is_numeric( $payload_item['line_total'] ) ) {
            $raw = (float) $payload_item['line_total'];
        } elseif ( isset( $payload_item['unitPrice'] ) && is_numeric( $payload_item['unitPrice'] ) ) {
            $raw = (float) $payload_item['unitPrice'] * (float) $quantity;
        } elseif ( isset( $payload_item['unit_price'] ) && is_numeric( $payload_item['unit_price'] ) ) {
            $raw = (float) $payload_item['unit_price'] * (float) $quantity;
        }

        if ( null === $raw || $raw < 0 ) {
            $line_id = $order->add_product(
                $product,
                $quantity,
                array(
                    'order' => $order,
                )
            );

            return $line_id ? (int) $line_id : false;
        }

        $amount = wc_format_decimal( $raw, $decimals );

        $includes_tax = (bool) apply_filters(
            'oc_storeos_rest_item_line_amount_includes_tax',
            false,
            $payload_item,
            $product,
            $order
        );

        $rates_kw = array(
            'country'   => $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country(),
            'state'     => $order->get_shipping_state() ? $order->get_shipping_state() : $order->get_billing_state(),
            'postcode'  => $order->get_shipping_postcode() ? $order->get_shipping_postcode() : $order->get_billing_postcode(),
            'city'      => $order->get_shipping_city() ? $order->get_shipping_city() : $order->get_billing_city(),
            'tax_class' => $product->get_tax_class(),
        );

        $tax_rates = class_exists( 'WC_Tax' ) ? WC_Tax::find_rates( $rates_kw ) : array();
        $net_line  = $amount;

        if ( $includes_tax && wc_tax_enabled() && $product->is_taxable() && ! empty( $tax_rates ) ) {
            $tax_parts = WC_Tax::calc_tax( $amount, $tax_rates, true );
            $net_line  = wc_format_decimal( $amount - array_sum( $tax_parts ), $decimals );
        }

        $args = array(
            'order'    => $order,
            'subtotal' => $net_line,
            'total'    => $net_line,
        );

        $line_id = $order->add_product( $product, $quantity, $args );

        return $line_id ? (int) $line_id : false;
    }

    /**
     * לפני חיוב טוקן Cardcom (priority 10): מאלץ calculate_totals + save כדי ש־initTerminal עם wc_get_order יראה סכום מעודכן.
     *
     * @param int               $order_id Order ID.
     * @param WC_Order|mixed    $order    Order instance.
     */
    public function maybe_refresh_order_totals_before_cardcom_capture( $order_id, $order ) {
        if ( ! apply_filters( 'oc_storeos_refresh_order_totals_before_cardcom_capture', true, $order_id, $order ) ) {
            return;
        }
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        if ( self::GATEWAY_CARDCOM !== $order->get_payment_method() ) {
            return;
        }
        if ( 'no' !== (string) $order->get_meta( 'cardcom_charge_captured', true ) ) {
            return;
        }

        $order->calculate_totals();
        $order->save();
        wp_cache_delete( 'order-' . $order->get_id(), 'orders' );
    }

    /**
     * Whether an incoming REST payload status slug should be applied to the WooCommerce order.
     * Default: skip `processing` (typically shown as "בטיפול" in Hebrew admin) so Giorgio does not overwrite local handling status.
     *
     * Filter: `oc_storeos_rest_incoming_status_updates_skip_slugs` — array of WC status slugs (no `wc-` prefix) to ignore.
     *
     * @param string $wc_status_slug Sanitized slug from payload `status`.
     * @return bool True to apply `set_status` / `wc_create_order( status )`.
     */
    protected function should_apply_incoming_rest_order_status( $wc_status_slug ) {
        $slug = sanitize_key( (string) $wc_status_slug );
        if ( '' === $slug ) {
            return false;
        }

        $skip = apply_filters(
            'oc_storeos_rest_incoming_status_updates_skip_slugs',
            array( 'processing' ),
            $wc_status_slug
        );

        if ( ! is_array( $skip ) ) {
            return true;
        }

        $skip = array_map( 'sanitize_key', $skip );

        return ! in_array( $slug, $skip, true );
    }

    /**
     * REST callback to create or update a WooCommerce order from external system.
     *
     * Response: 201 when a new order is created, 200 when an existing order is updated.
     * `orderOperation` is `created` or `updated`. `storeosSync.status` is `ok`, `skipped`, or `error`.
     *
     * Payment (Woo gateway slug): optional `paymentMethod`, `paymentMethodId`, or `wcPaymentMethod`
     * (e.g. `cardcom`). Applied before `status` on updates so completed + capture see the gateway.
     *
     * @param WP_REST_Request $request Request.
     * @return WP_REST_Response|WP_Error
     */
    public function rest_create_order( $request ) {
        $remote_ip = function_exists( 'rest_get_ip_address' ) ? rest_get_ip_address() : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );

        if ( ! class_exists( 'WC_Order' ) ) {
            $this->log_rest_incoming_order(
                array(
                    'time_utc'   => gmdate( 'c' ),
                    'remote_ip'  => $remote_ip,
                    'result'     => 'error',
                    'error_code' => 'no_woocommerce',
                )
            );
            return new WP_Error(
                'oc_storeos_no_woocommerce',
                __( 'WooCommerce is not available.', 'oc-storeos-integration' ),
                array( 'status' => 500 )
            );
        }

        $data = $request->get_json_params();
        if ( empty( $data ) || ! is_array( $data ) ) {
            $this->log_rest_incoming_order(
                array(
                    'time_utc'  => gmdate( 'c' ),
                    'remote_ip' => $remote_ip,
                    'result'    => 'error',
                    'error'     => 'invalid_json_body',
                )
            );
            return new WP_Error(
                'oc_storeos_invalid_body',
                __( 'Invalid JSON payload.', 'oc-storeos-integration' ),
                array( 'status' => 400 )
            );
        }

        // גוף הבקשה הגולמי לדיבוג (אותו קובץ לוג כמו תוצאות העיבוד). כיבוי: `oc_storeos_log_rest_incoming_payload` => false; עריכה/הסתרה: `oc_storeos_rest_incoming_payload_for_log`.
        if ( apply_filters( 'oc_storeos_log_rest_incoming_payload', true, $data, $remote_ip ) ) {
            $payload_for_log = apply_filters( 'oc_storeos_rest_incoming_payload_for_log', $data, $remote_ip );
            if ( is_array( $payload_for_log ) ) {
                $this->log_rest_incoming_order(
                    array(
                        'time_utc'  => gmdate( 'c' ),
                        'remote_ip' => $remote_ip,
                        'result'    => 'incoming_payload',
                        'payload'   => $payload_for_log,
                    )
                );
            }
        }

        try {
            $order_args             = array();
            $order                  = null;
            $updating_existing      = false;
            $items_eligible         = 0;
            $items_added            = 0;
            $items_unresolved_keys  = array();
            $deferred_wc_status     = null;

            // אם נשלח order_id / orderId / orderNumber – ננסה לעדכן הזמנה קיימת במקום ליצור חדשה.
            // (orderNumber — מפתח כמו ב-payload מול Giorgio; בפלגין היוצא orderNumber הוא get_id().)
            $incoming_order_id = 0;
            if ( ! empty( $data['order_id'] ) && is_numeric( $data['order_id'] ) ) {
                $incoming_order_id = (int) $data['order_id'];
            } elseif ( ! empty( $data['orderId'] ) && is_numeric( $data['orderId'] ) ) {
                $incoming_order_id = (int) $data['orderId'];
            } elseif ( ! empty( $data['orderNumber'] ) && is_numeric( $data['orderNumber'] ) ) {
                $incoming_order_id = (int) $data['orderNumber'];
            }

            if ( $incoming_order_id > 0 ) {
                $existing_order = wc_get_order( $incoming_order_id );
                if ( $existing_order instanceof WC_Order ) {
                    $order             = $existing_order;
                    $updating_existing = true;
                }
            }

            if ( ! $order instanceof WC_Order ) {
                if ( ! empty( $data['status'] ) && is_string( $data['status'] ) ) {
                    $incoming_st = sanitize_key( $data['status'] );
                    if ( $this->should_apply_incoming_rest_order_status( $incoming_st ) ) {
                        $order_args['status'] = $incoming_st;
                    }
                }

                $order = wc_create_order( $order_args );
            } else {
                // הזמנה קיימת — סטטוס נדחה לסוף (אחרי paymentMethod ופריטים) כדי ש־OrderPayment/פלאקארד יזהו את השער.
                if ( ! empty( $data['status'] ) && is_string( $data['status'] ) ) {
                    $incoming_st = sanitize_key( $data['status'] );
                    if ( $this->should_apply_incoming_rest_order_status( $incoming_st ) ) {
                        $deferred_wc_status = $incoming_st;
                    }
                }

                // ננקה פריטי מוצר קיימים לפני שנוסיף מה‑payload החדש.
                foreach ( $order->get_items() as $item_id => $item ) {
                    $order->remove_item( $item_id );
                }
                // משלוח: בעדכון הזמנה קיימת לא מסירים שורות משלוח — סנכרון Giorgio לא יכול להשאיר הזמנה בלי שיטת משלוח.
            }

            // 1. פרטי לקוח וחיוב
            if ( isset( $data['customer'] ) && is_array( $data['customer'] ) ) {
                $customer = $data['customer'];
                if ( ! empty( $customer['email'] ) ) $order->set_billing_email( sanitize_email( $customer['email'] ) );
                if ( ! empty( $customer['phone'] ) ) $order->set_billing_phone( sanitize_text_field( $customer['phone'] ) );
                if ( ! empty( $customer['name'] ) ) {
                    $name_parts = explode( ' ', $customer['name'], 2 );
                    $order->set_billing_first_name( sanitize_text_field( $name_parts[0] ) );
                    if ( isset( $name_parts[1] ) ) $order->set_billing_last_name( sanitize_text_field( $name_parts[1] ) );
                }
            }

            // 2. כתובת משלוח וטיפול ב-Meta נתונים
            if ( isset( $data['shippingAddress'] ) && is_array( $data['shippingAddress'] ) ) {
                $shipping = $data['shippingAddress'];

                if ( ! empty( $shipping['city'] ) ) {
                    $city_value = sanitize_text_field( $shipping['city'] );
                    $order->set_shipping_city( $city_value );
                    if ( ! $order->get_billing_city() ) {
                        $order->set_billing_city( $city_value );
                    }
                }
                if ( ! empty( $shipping['zip'] ) ) {
                    $zip_value = sanitize_text_field( $shipping['zip'] );
                    $order->set_shipping_postcode( $zip_value );
                    if ( ! $order->get_billing_postcode() ) {
                        $order->set_billing_postcode( $zip_value );
                    }
                }

                // עיבוד רחוב ומספר בית
                $street_full = isset( $shipping['street'] ) ? sanitize_text_field( $shipping['street'] ) : '';
                $street_name = $street_full;
                $house_num   = '';

                if ( preg_match( '/^(.*)\s+(\d+[A-Za-z]?)$/u', $street_full, $matches ) ) {
                    $street_name = trim( $matches[1] );
                    $house_num   = $matches[2];
                }

                if ( '' !== $street_name ) {
                    $order->set_billing_address_1( $street_name );
                    $order->set_shipping_address_1( $street_name );
                    $order->update_meta_data( '_shipping_street', $street_name );
                    $order->update_meta_data( '_billing_street', $street_name );
                }

                if ( '' !== $house_num ) {
                    $order->set_billing_address_2( $house_num );
                    $order->set_shipping_address_2( $house_num );
                    $order->update_meta_data( '_shipping_house_num', $house_num );
                    $order->update_meta_data( '_billing_house_num', $house_num );
                }

                if ( ! empty( $shipping['city'] ) ) {
                    $city_name = sanitize_text_field( $shipping['city'] );
                    $order->update_meta_data( '_shipping_city_name', $city_name );
                    $order->update_meta_data( '_billing_city_name', $city_name );
                }

                // אינטגרציה עם OC Woo Shipping
                if ( function_exists( 'ocws_save_full_address_to_order' ) ) {
                    ocws_save_full_address_to_order( $order );
                    // במקרה ש-ocws משנה את ה-meta מאחורי הקלעים, נרענן את האובייקט במידת הצורך
                }
            }

            // 3. הוספת מוצרים (+ ספירה ללוג והערות)
            if ( isset( $data['items'] ) && is_array( $data['items'] ) ) {
                foreach ( $data['items'] as $item ) {
                    if ( ! is_array( $item ) ) {
                        continue;
                    }
                    $identifier = ! empty( $item['sku'] ) ? (string) $item['sku'] : (string) ( $item['productId'] ?? '' );
                    $quantity   = isset( $item['quantity'] ) ? (float) $item['quantity'] : 0;
                    if ( $quantity <= 0 ) {
                        continue;
                    }
                    ++$items_eligible;

                    $product = is_numeric( $identifier ) ? wc_get_product( (int) $identifier ) : null;
                    if ( ! $product && function_exists( 'wc_get_product_id_by_sku' ) ) {
                        $pid = wc_get_product_id_by_sku( $identifier );
                        if ( $pid ) {
                            $product = wc_get_product( $pid );
                        }
                    }

                    if ( $product ) {
                        $line_id = $this->add_storeos_rest_order_line_from_payload( $order, $product, $quantity, $item );
                        if ( $line_id ) {
                            ++$items_added;
                        }
                    } else {
                        $items_unresolved_keys[] = $identifier;
                    }
                }
            }

            // 4. דמי משלוח (אם נשלחו) ויצירת שורת משלוח
            $shipping_total = 0;
            if ( isset( $data['shippingTotal'] ) && is_numeric( $data['shippingTotal'] ) ) {
                $shipping_total = (float) $data['shippingTotal'];
            }

            if ( $shipping_total > 0 ) {
                // כותרת שורת המשלוח בהתאם לסוג המשלוח (משלוח / איסוף עצמי).
                $shipping_label     = __( 'משלוח עד הבית', 'oc-storeos-integration' );
                $shipping_method_id = 'storeos_shipping';

                if ( isset( $data['shippingInfo']['type'] ) && is_string( $data['shippingInfo']['type'] ) ) {
                    $shipping_type = sanitize_key( $data['shippingInfo']['type'] );

                    if ( 'pickup' === $shipping_type ) {
                        $shipping_label     = __( 'איסוף עצמי', 'oc-storeos-integration' );
                        $shipping_method_id = 'storeos_pickup';
                    }
                }

                if ( $updating_existing ) {
                    $shipping_lines = $order->get_items( 'shipping' );
                    if ( ! empty( $shipping_lines ) ) {
                        $first_kept = false;
                        foreach ( $shipping_lines as $ship_item_id => $ship_item ) {
                            if ( ! $ship_item instanceof WC_Order_Item_Shipping ) {
                                continue;
                            }
                            if ( ! $first_kept ) {
                                $ship_item->set_total( $shipping_total );
                                $first_kept = true;
                            } else {
                                $order->remove_item( $ship_item_id );
                            }
                        }
                    } else {
                        $shipping_item = new WC_Order_Item_Shipping();
                        $shipping_item->set_method_title( $shipping_label );
                        $shipping_item->set_method_id( $shipping_method_id );
                        $shipping_item->set_total( $shipping_total );
                        $order->add_item( $shipping_item );
                    }
                } else {
                    $shipping_item = new WC_Order_Item_Shipping();
                    $shipping_item->set_method_title( $shipping_label );
                    $shipping_item->set_method_id( $shipping_method_id );
                    $shipping_item->set_total( $shipping_total );
                    $order->add_item( $shipping_item );
                }
            }

            // 5. מידע משלוח (תאריך / שעה / איסוף מסניף) ששוגר מהמערכת החיצונית
            if ( isset( $data['shippingInfo'] ) && is_array( $data['shippingInfo'] ) ) {
                $this->apply_shipping_info_from_payload( $order, $data['shippingInfo'] );
            }

            // 5. סיום ועדכון Meta חיצוני 
            if ( ! empty( $data['customerNotes'] ) ) {
                $order->set_customer_note( wp_kses_post( $data['customerNotes'] ) );
            }

            if ( ! empty( $data['externalOrderId'] ) ) {
                $order->update_meta_data( '_oc_storeos_external_order_id', sanitize_text_field( (string) $data['externalOrderId'] ) );
            }

            // שיטת תשלום מ־Giorgio (חובה לפלאקארד/קארדקום לפני מעבר ל־completed): paymentMethod | paymentMethodId | wcPaymentMethod
            $this->apply_payment_method_from_storeos_payload( $order, $data );

            if ( $updating_existing && null !== $deferred_wc_status && '' !== $deferred_wc_status ) {
                $order->set_status( $deferred_wc_status );
            }

            $order->calculate_totals();
            $order->save(); // כאן הכל נשמר ב-Database בפעם אחת

            // --- בדיקת התאמה: סכומים ופריטים -------------------------------------------
            // Giorgio שולח מחירים ברוטו (כולל מע"מ): orderTotal הוא הסכום שהלקוח משלם.
            // נשווה אותו מול הסכום ש-WooCommerce חישב אחרי השמירה. פער בדרך כלל אומר:
            // (א) הגדרת המע"מ של החנות הוסיפה/הורידה מע"מ מעל הסכומים שנשלחו, או
            // (ב) פריט נשמט בשקט כי ה-SKU/productId לא נפתר למוצר.
            // לא מתקנים אוטומטית את הסכום (זה היה מסתיר באג קונפיגורציה אמיתי) — רק חושפים בקול.
            $expected_total = ( isset( $data['orderTotal'] ) && is_numeric( $data['orderTotal'] ) ) ? round( (float) $data['orderTotal'], 2 ) : null;
            $actual_total   = round( (float) $order->get_total(), 2 );
            $totals_match   = ( null === $expected_total ) ? null : ( abs( $expected_total - $actual_total ) < 0.01 );

            // Item-count differences are normal business, NOT an error: the shop manager may remove an
            // unavailable item (after telling the customer) or add items that weren't in the original
            // order. Giorgio recomputes orderTotal to match, so the TOTAL is the source of truth — not
            // the item count. We therefore base "ok" purely on whether the totals reconcile; unresolved
            // items are reported for visibility but never raise an alert on their own.
            $reconciliation = array(
                'ok'              => ( false !== $totals_match ), // true unless the totals definitively diverge
                'expectedTotal'   => $expected_total,
                'actualTotal'     => $actual_total,
                'totalsMatch'     => $totals_match,
                'itemsExpected'   => $items_eligible,
                'itemsAdded'      => $items_added,
                'itemsUnresolved' => array_values( array_map( 'strval', $items_unresolved_keys ) ),
            );

            if ( ! $reconciliation['ok'] ) {
                $this->oc_storeos_wc_log(
                    'warning',
                    sprintf(
                        'Reconciliation mismatch on order %d: expectedTotal=%s actualTotal=%s itemsExpected=%d itemsAdded=%d unresolved=[%s]',
                        (int) $order->get_id(),
                        ( null === $expected_total ? 'n/a' : (string) $expected_total ),
                        (string) $actual_total,
                        (int) $items_eligible,
                        (int) $items_added,
                        implode( ',', array_map( 'strval', $items_unresolved_keys ) )
                    ),
                    array( 'order_id' => (int) $order->get_id() )
                );
                $order->add_order_note(
                    sprintf(
                        /* translators: 1: amount Giorgio sent, 2: amount WooCommerce computed, 3: unresolved skus. */
                        __( '⚠ אי-התאמה בסכום סנכרון Giorgio: סכום שנשלח %1$s ₪, סכום שחושב ב-Woo %2$s ₪. לבדוק הגדרת מע"מ בחנות%3$s.', 'oc-storeos-integration' ),
                        ( null === $expected_total ? '—' : number_format( (float) $expected_total, 2 ) ),
                        number_format( (float) $actual_total, 2 ),
                        ( empty( $items_unresolved_keys ) ? '' : sprintf( __( ' (פריטים שלא נמצאו בקטלוג: %s)', 'oc-storeos-integration' ), implode( ', ', array_map( 'strval', $items_unresolved_keys ) ) ) )
                    ),
                    false,
                    false
                );
            }

            // עדכון קיים שנדחף מ-Giorgio ל-Woo: לא לשגר שוב Order ל-Giorgio.
            // אחרת נוצר ping-pong (Woo → Giorgio → push שוב ל-Woo) והצפת הערות + שינויי סטטוס.
            if ( $updating_existing ) {
                $outgoing_sync = array(
                    'skipped' => true,
                    'reason'  => 'incoming_rest_update_no_outgoing_echo',
                );
            } else {
                $outgoing_sync = $this->send_outgoing_when_order_enters(
                    $order,
                    array( 'skip_status_gate' => true )
                );
            }

            // סיכום ללקוח API: נוצרה vs עודכנה, וסטטוס סנכרון ל-Giorgio (או דילוג/שגיאה).
            $storeos_sync_summary = array(
                'status' => 'skipped',
            );
            if ( is_array( $outgoing_sync ) ) {
                if ( ! empty( $outgoing_sync['skipped'] ) ) {
                    $storeos_sync_summary['status'] = 'skipped';
                    if ( ! empty( $outgoing_sync['reason'] ) ) {
                        $storeos_sync_summary['reason'] = $outgoing_sync['reason'];
                    }
                } elseif ( ! empty( $outgoing_sync['storeosHttpResponse'] ) && is_array( $outgoing_sync['storeosHttpResponse'] ) ) {
                    $r = $outgoing_sync['storeosHttpResponse'];
                    if ( ! empty( $r['success'] ) ) {
                        $storeos_sync_summary['status'] = 'ok';
                    } else {
                        $storeos_sync_summary['status'] = 'error';
                        if ( ! empty( $r['error'] ) ) {
                            $storeos_sync_summary['error'] = $r['error'];
                        } elseif ( is_array( $r['body'] ) && ! empty( $r['body']['errors'] ) && is_array( $r['body']['errors'] ) ) {
                            $storeos_sync_summary['error'] = $this->first_string_in_nested_lists( $r['body']['errors'] );
                        }
                        if ( empty( $storeos_sync_summary['error'] ) && isset( $r['http_status'] ) && $r['http_status'] > 0 ) {
                            /* translators: %d: HTTP status code. */
                            $storeos_sync_summary['error'] = sprintf( __( 'HTTP %d from Giorgio.', 'oc-storeos-integration' ), (int) $r['http_status'] );
                        }
                        if ( empty( $storeos_sync_summary['error'] ) ) {
                            $storeos_sync_summary['error'] = __( 'Giorgio request failed.', 'oc-storeos-integration' );
                        }
                    }
                }
            }

            $http_status = $updating_existing ? 200 : 201;

            $line_items_after = count( $order->get_items() );

            if ( $updating_existing ) {
                if (
                    'skipped' === $storeos_sync_summary['status']
                    && ! empty( $storeos_sync_summary['reason'] )
                    && 'incoming_rest_update_no_outgoing_echo' === $storeos_sync_summary['reason']
                ) {
                    $sync_note = __( 'לא נשלח חוזר ל-Giorgio (מניעת לולאת סנכרון)', 'oc-storeos-integration' );
                } else {
                    $sync_note = (string) $storeos_sync_summary['status'];
                    if ( 'error' === $storeos_sync_summary['status'] && ! empty( $storeos_sync_summary['error'] ) ) {
                        $sync_note .= ' — ' . $storeos_sync_summary['error'];
                    }
                }
                $order_note_text = sprintf(
                /* translators: 1: items with qty>0 in payload, 2: products added as line items, 3: line items on order after save, 4: Giorgio sync summary. */
                    __( 'עודכן דרך OC Giorgio REST API. פריטים בבקשה (כמות>0): %1$d, נוספו כמוצר מהקטלוג: %2$d, שורות מוצר בהזמנה אחרי שמירה: %3$d. סנכרון Giorgio: %4$s', 'oc-storeos-integration' ),
                    $items_eligible,
                    $items_added,
                    $line_items_after,
                    $sync_note
                );
                if ( $items_added < $items_eligible ) {
                    $order_note_text .= ' ' . sprintf(
                        /* translators: %s: comma-separated SKU/product ids not resolved. */
                            __( 'לא נמצא מוצר עבור: %s', 'oc-storeos-integration' ),
                            implode( ', ', array_map( 'strval', $items_unresolved_keys ) )
                        );
                }
                $order->add_order_note( $order_note_text, false, false );
            }

            $this->log_rest_incoming_order(
                array(
                    'time_utc'              => gmdate( 'c' ),
                    'remote_ip'             => $remote_ip,
                    'result'                => 'ok',
                    'operation'             => $updating_existing ? 'updated' : 'created',
                    'order_id'              => $order->get_id(),
                    'wc_status'             => $order->get_status(),
                    'items_eligible'        => $items_eligible,
                    'items_added'           => $items_added,
                    'line_items_after_save' => $line_items_after,
                    'items_all_saved'       => ( 0 === $items_eligible ) ? null : ( $items_added === $items_eligible ),
                    'items_unresolved'      => $items_unresolved_keys,
                    'reconciliation'        => $reconciliation,
                    'storeos_sync'          => $storeos_sync_summary,
                    'http_response'         => $http_status,
                )
            );

            return new WP_REST_Response(
                array(
                    'success'         => true,
                    'orderOperation'  => $updating_existing ? 'updated' : 'created',
                    'storeosSync'     => $storeos_sync_summary,
                    'reconciliation'  => $reconciliation,
                    'orderId'         => $order->get_id(),
                    'orderKey'        => $order->get_order_key(),
                    'status'          => $order->get_status(),
                    'orderDate'       => $order->get_date_created() ? $order->get_date_created()->date( 'c' ) : null,
                    'outgoingSync'    => $outgoing_sync,
                ),
                $http_status
            );
        } catch ( Exception $e ) {
            $this->log_rest_incoming_order(
                array(
                    'time_utc'  => gmdate( 'c' ),
                    'remote_ip' => $remote_ip,
                    'result'    => 'exception',
                    'message'   => $e->getMessage(),
                )
            );
            return new WP_Error( 'oc_storeos_order_error', $e->getMessage(), array( 'status' => 500 ) );
        }
    }

    /**
     * Set WC payment method from Giorgio REST body when a registered gateway id is sent.
     *
     * @param WC_Order $order Order.
     * @param array    $data  JSON body.
     */
    protected function apply_payment_method_from_storeos_payload( WC_Order $order, array $data ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $raw = null;
        if ( ! empty( $data['paymentMethod'] ) && is_string( $data['paymentMethod'] ) ) {
            $raw = $data['paymentMethod'];
        } elseif ( ! empty( $data['paymentMethodId'] ) && is_string( $data['paymentMethodId'] ) ) {
            $raw = $data['paymentMethodId'];
        } elseif ( ! empty( $data['wcPaymentMethod'] ) && is_string( $data['wcPaymentMethod'] ) ) {
            $raw = $data['wcPaymentMethod'];
        }

        if ( null === $raw || '' === $raw ) {
            return;
        }

        $gateway_id = sanitize_key( $raw );
        if ( '' === $gateway_id ) {
            return;
        }

        if ( ! $this->is_registered_wc_payment_gateway( $gateway_id ) ) {
            $this->oc_storeos_wc_log(
                'notice',
                sprintf(
                    'REST orders: unknown paymentMethod "%s" ignored (not a registered WC gateway). order_id=%d',
                    $gateway_id,
                    $order->get_id()
                ),
                array( 'order_id' => $order->get_id() )
            );
            return;
        }

        $order->set_payment_method( $gateway_id );

        if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
            $gateways = WC()->payment_gateways()->payment_gateways();
            if ( isset( $gateways[ $gateway_id ] ) && is_object( $gateways[ $gateway_id ] ) && ! empty( $gateways[ $gateway_id ]->title ) ) {
                $order->set_payment_method_title( (string) $gateways[ $gateway_id ]->title );
            }
        }

        $this->oc_storeos_wc_log(
            'info',
            sprintf( 'REST orders: set payment_method=%s order_id=%d', $gateway_id, $order->get_id() ),
            array( 'order_id' => $order->get_id() )
        );
    }

    /**
     * @param string $gateway_id WooCommerce gateway id.
     * @return bool
     */
    protected function is_registered_wc_payment_gateway( $gateway_id ) {
        if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
            return in_array( $gateway_id, array( self::GATEWAY_CARDCOM ), true );
        }

        $gateways = WC()->payment_gateways()->payment_gateways();

        return is_array( $gateways ) && isset( $gateways[ $gateway_id ] );
    }

    /**
     * Enrich OrderPayment v2 JSON with siteId, orderNumber, externalOrderId (Giorgio correlation).
     *
     * @param WC_Order             $order   Order.
     * @param array<string, mixed> $payload Partial payload.
     * @return array<string, mixed>
     */
    protected function apply_order_payment_webhook_v2_common_fields( WC_Order $order, array $payload ) {
        $options = $this->get_options();
        if ( ! empty( $options['site_id'] ) ) {
            $payload['siteId'] = (string) $options['site_id'];
        }

        $payload['orderNumber'] = (string) $order->get_order_number();

        $ext = trim( (string) $order->get_meta( '_oc_storeos_external_order_id', true ) );
        if ( '' !== $ext ) {
            $payload['externalOrderId'] = $ext;
        }

        return $payload;
    }

    /**
     * Absolute path to the incoming REST log file (uploads/oc-storeos-integration/ or wp-content fallback).
     *
     * @return string
     */
    protected function get_rest_incoming_log_path() {
        $upload = wp_upload_dir();
        if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
            $dir = trailingslashit( $upload['basedir'] ) . self::REST_INCOMING_LOG_DIR;
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            return trailingslashit( $dir ) . self::REST_INCOMING_LOG_FILE;
        }

        return trailingslashit( WP_CONTENT_DIR ) . self::REST_INCOMING_LOG_FILE;
    }

    /**
     * Absolute path to the outgoing (WooCommerce → Giorgio) payload log file.
     *
     * @return string
     */
    protected function get_rest_outgoing_log_path() {
        $upload = wp_upload_dir();
        if ( empty( $upload['error'] ) && ! empty( $upload['basedir'] ) ) {
            $dir = trailingslashit( $upload['basedir'] ) . self::REST_INCOMING_LOG_DIR;
            if ( ! is_dir( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            return trailingslashit( $dir ) . self::REST_OUTGOING_LOG_FILE;
        }

        return trailingslashit( WP_CONTENT_DIR ) . self::REST_OUTGOING_LOG_FILE;
    }

    /**
     * Append one JSON line to the outgoing payload log. Mirrors log_rest_incoming_order.
     * Enabled by default; disable via the `oc_storeos_log_rest_outgoing_payload` filter.
     *
     * @param array $fields Record to log.
     */
    protected function log_rest_outgoing_order( $fields ) {
        if ( ! apply_filters( 'oc_storeos_log_rest_outgoing_payload', true, $fields ) ) {
            return;
        }
        $path = $this->get_rest_outgoing_log_path();
        if ( ! $path ) {
            return;
        }
        $line = wp_json_encode( $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false === $line ) {
            $line = '{"time_utc":"' . gmdate( 'c' ) . '","result":"log_encode_error"}';
        }
        @file_put_contents( $path, $line . "\n", FILE_APPEND | LOCK_EX );
    }

    /**
     * Append one JSON line (or error text) to the incoming REST log.
     *
     * @param array $fields Key-value row to log.
     */
    protected function log_rest_incoming_order( $fields ) {
        $path = $this->get_rest_incoming_log_path();
        if ( ! $path ) {
            return;
        }
        $line = wp_json_encode( $fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        if ( false === $line ) {
            $line = '{"time_utc":"' . gmdate( 'c' ) . '","result":"log_encode_error"}';
        }
        @file_put_contents( $path, $line . "\n", FILE_APPEND | LOCK_EX );
    }

    /**
     * Apply incoming shipping info (delivery / pickup) onto the order as OC Giorgio meta.
     *
     * Expected payload structure:
     *  'shippingInfo' => [
     *      'type'                 => 'delivery' | 'pickup',
     *      'date'                 => 'YYYY-MM-DD',
     *      'slotStart'            => 'HH:MM' (optional),
     *      'slotEnd'              => 'HH:MM' (optional),
     *      'pickupAffiliateId'    => 123 (optional, for pickup),
     *      'pickupAffiliateName'  => 'Branch name' (optional, for pickup),
     *  ]
     *
     * @param WC_Order $order Order object.
     * @param array    $info  Shipping info payload.
     */
    protected function apply_shipping_info_from_payload( $order, $info ) {
        if ( ! $order instanceof WC_Order || ! is_array( $info ) ) {
            return;
        }
        $type       = '';
        $raw_date   = '';
        $slot_start = '';
        $slot_end   = '';
        $pickup_id  = '';
        $pickup_name= '';

        if ( ! empty( $info['type'] ) && is_string( $info['type'] ) ) {
            $type = sanitize_key( $info['type'] );
            $order->update_meta_data( '_oc_storeos_shipping_type', $type );
        }

        if ( ! empty( $info['date'] ) ) {
            $raw_date = sanitize_text_field( $info['date'] );
            $order->update_meta_data(
                '_oc_storeos_delivery_date',
                $raw_date
            );
        }

        if ( ! empty( $info['slotStart'] ) ) {
            $slot_start = sanitize_text_field( $info['slotStart'] );
            $order->update_meta_data(
                '_oc_storeos_delivery_slot_start',
                $slot_start
            );
        }

        if ( ! empty( $info['slotEnd'] ) ) {
            $slot_end = sanitize_text_field( $info['slotEnd'] );
            $order->update_meta_data(
                '_oc_storeos_delivery_slot_end',
                $slot_end
            );
        }

        // נתוני איסוף מסניף (רלוונטי כש-type הוא pickup, אבל לא נכריח).
        if ( ! empty( $info['pickupAffiliateId'] ) ) {
            $pickup_id = sanitize_text_field( (string) $info['pickupAffiliateId'] );
            $order->update_meta_data(
                '_oc_storeos_pickup_aff_id',
                $pickup_id
            );
        }

        if ( ! empty( $info['pickupAffiliateName'] ) ) {
            $pickup_name = sanitize_text_field( $info['pickupAffiliateName'] );
            $order->update_meta_data(
                '_oc_storeos_pickup_aff_name',
                $pickup_name
            );
        }

        /**
         * OC Woo Shipping compatibility:
         * ממלא גם את מטא המשלוחים שתוסף OCWS משתמש בהם להצגה ולטבלאות באדמין,
         * כדי שהזמנות שנוצרו דרך ה‑API יראו כמו הזמנות מה‑checkout.
         */
        if ( ! empty( $raw_date ) ) {
            $timestamp = strtotime( $raw_date );

            if ( $timestamp ) {
                $ocws_display_date  = date_i18n( 'd/m/Y', $timestamp );
                $ocws_sortable_date = date_i18n( 'Y/m/d', $timestamp );
            } else {
                // אם הפורמט לא צפוי, נשמור כמו שהוא.
                $ocws_display_date  = $raw_date;
                $ocws_sortable_date = $raw_date;
            }

            $order->update_meta_data( 'ocws_shipping_info_date', $ocws_display_date );
            $order->update_meta_data( 'ocws_shipping_info_date_sortable', $ocws_sortable_date );

            // Tag לפי סוג המשלוח כדי שעמודות OCWS יזהו את ההזמנה.
            if ( 'pickup' === $type ) {
                if ( class_exists( 'OCWS_LP_Local_Pickup' ) && defined( 'OCWS_LP_Local_Pickup::PICKUP_METHOD_TAG' ) ) {
                    $order->update_meta_data( 'ocws_shipping_tag', OCWS_LP_Local_Pickup::PICKUP_METHOD_TAG );
                } else {
                    $order->update_meta_data( 'ocws_shipping_tag', 'pickup' );
                }
            } else {
                if ( class_exists( 'OCWS_Advanced_Shipping' ) && defined( 'OCWS_Advanced_Shipping::SHIPPING_METHOD_TAG' ) ) {
                    $order->update_meta_data( 'ocws_shipping_tag', OCWS_Advanced_Shipping::SHIPPING_METHOD_TAG );
                } else {
                    $order->update_meta_data( 'ocws_shipping_tag', 'shipping' );
                }
            }
        }

        if ( '' !== $slot_start ) {
            $order->update_meta_data( 'ocws_shipping_info_slot_start', $slot_start );
        }

        if ( '' !== $slot_end ) {
            $order->update_meta_data( 'ocws_shipping_info_slot_end', $slot_end );
        }
        // תאימות ל-meta הישן של OCWS: ocws_shipping_info נשמר כמערך מסוּריאלז.
        if ( '' !== $raw_date || '' !== $slot_start || '' !== $slot_end ) {
            $legacy_shipping_info = array(
                'date'       => $raw_date,
                'slot_start' => $slot_start,
                'slot_end'   => $slot_end,
            );

            $serialized = serialize( $legacy_shipping_info );

            // נשמור גם על ההזמנה וגם על פריטי המשלוח עצמם (OCWS קורא מה-items).
            $order->update_meta_data( 'ocws_shipping_info', $serialized );

            foreach ( $order->get_items( 'shipping' ) as $item ) {
                if ( $item instanceof WC_Order_Item_Shipping ) {
                    $item->update_meta_data( 'ocws_shipping_info', $serialized );
                }
            }
        }

        // במצב איסוף, נמלא גם חלק מה‑META הייעודי של OCWS לפיקאפ (למי שמשתמש במסכים האלו).
        if ( 'pickup' === $type ) {
            if ( '' !== $pickup_id ) {
                $order->update_meta_data( 'ocws_lp_pickup_aff_id', $pickup_id );
            }
            if ( '' !== $pickup_name ) {
                $order->update_meta_data( 'ocws_lp_pickup_aff_name', $pickup_name );
            }
        }
    }

    /**
     * Add a "Settings" link to the plugin row on the Plugins screen.
     *
     * @param array $links Existing action links.
     * @return array
     */
    public function add_settings_action_link( $links ) {
        $url           = admin_url( 'admin.php?page=oc-storeos-integration' );
        $settings_link = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'הגדרות', 'oc-storeos-integration' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Register settings page under WooCommerce menu.
     */
    public function register_settings_page() {
        add_submenu_page(
            'woocommerce',
            __( 'OC Giorgio Integration', 'oc-storeos-integration' ),
            __( 'OC Giorgio Integration', 'oc-storeos-integration' ),
            'manage_woocommerce',
            'oc-storeos-integration',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        register_setting(
            self::OPTION_GROUP,
            self::OPTION_NAME,
            array( $this, 'sanitize_options' )
        );

        // Two tabs are rendered from two page slugs, but every field saves into the SAME option
        // group (register_setting above), so saving is unchanged.
        add_settings_section( 'oc_giorgio_conn', '', '__return_false', 'oc-giorgio-connection' );
        add_settings_section( 'oc_giorgio_conn_advanced', __( 'מתקדם', 'oc-storeos-integration' ), '__return_false', 'oc-giorgio-connection' );
        add_settings_section( 'oc_giorgio_orders', '', '__return_false', 'oc-giorgio-orders' );
        add_settings_section( 'oc_giorgio_orders_fee', __( 'תוספת עגלה', 'oc-storeos-integration' ), '__return_false', 'oc-giorgio-orders' );

        add_settings_field(
            'api_base_url',
            __( 'API Base URL', 'oc-storeos-integration' ),
            array( $this, 'render_field_api_base_url' ),
            'oc-giorgio-connection',
            'oc_giorgio_conn'
        );

        add_settings_field(
            'api_token',
            __( 'API Token / API Key', 'oc-storeos-integration' ),
            array( $this, 'render_field_api_token' ),
            'oc-giorgio-connection',
            'oc_giorgio_conn'
        );

        add_settings_field(
            'site_id',
            __( 'Site ID (optional)', 'oc-storeos-integration' ),
            array( $this, 'render_field_site_id' ),
            'oc-giorgio-connection',
            'oc_giorgio_conn'
        );

        add_settings_field(
            'github_token',
            __( 'GitHub Token (לעדכונים אוטומטיים מ-repo פרטי)', 'oc-storeos-integration' ),
            array( $this, 'render_field_github_token' ),
            'oc-giorgio-connection',
            'oc_giorgio_conn_advanced'
        );

        add_settings_field(
            'incoming_api_token',
            __( 'טוקן לאימות בקשות נכנסות (Giorgio → אתר)', 'oc-storeos-integration' ),
            array( $this, 'render_field_incoming_api_token' ),
            'oc-giorgio-connection',
            'oc_giorgio_conn'
        );

        add_settings_field(
            'incoming_auth_mode',
            __( 'מצב אכיפת אימות בכניסה', 'oc-storeos-integration' ),
            array( $this, 'render_field_incoming_auth_mode' ),
            'oc-giorgio-connection',
            'oc_giorgio_conn'
        );

        add_settings_field(
            'debug_logging',
            __( 'לוג דיבאג (PHP error log)', 'oc-storeos-integration' ),
            array( $this, 'render_field_debug_logging' ),
            'oc-giorgio-connection',
            'oc_giorgio_conn_advanced'
        );

        add_settings_field(
            'debug_outgoing_email',
            __( 'שליחת payload יוצא במייל (דיבאג)', 'oc-storeos-integration' ),
            array( $this, 'render_field_debug_outgoing_email' ),
            'oc-giorgio-connection',
            'oc_giorgio_conn_advanced'
        );

        add_settings_field(
            'send_order_to_storeos_status',
            __( 'ברירת מחדל: סטטוס לשליחת הזמנה ל־Giorgio', 'oc-storeos-integration' ),
            array( $this, 'render_field_send_order_to_storeos_status' ),
            'oc-giorgio-orders',
            'oc_giorgio_orders'
        );

        add_settings_field(
            'send_order_payment_webhook_on_charge',
            __( 'עדכון תשלום בעת חיוב', 'oc-storeos-integration' ),
            array( $this, 'render_field_send_order_payment_webhook_on_charge' ),
            'oc-giorgio-orders',
            'oc_giorgio_orders'
        );

        add_settings_field(
            'include_variation_in_line_title',
            __( 'הוסף שם הוריאציה לכותרת (בשליחה ל־Giorgio)', 'oc-storeos-integration' ),
            array( $this, 'render_field_include_variation_in_line_title' ),
            'oc-giorgio-orders',
            'oc_giorgio_orders'
        );

        add_settings_field(
            'order_total_fee_percent',
            __( 'תוספת באחוזים לסכום הזמנה', 'oc-storeos-integration' ),
            array( $this, 'render_field_order_total_fee_percent' ),
            'oc-giorgio-orders',
            'oc_giorgio_orders_fee'
        );

        add_settings_field(
            'order_total_fee_cart_text',
            __( 'טקסט לעגלה', 'oc-storeos-integration' ),
            array( $this, 'render_field_order_total_fee_cart_text' ),
            'oc-giorgio-orders',
            'oc_giorgio_orders_fee'
        );

        add_settings_field(
            'order_total_fee_tooltip',
            __( 'טקסט טולטיפ לתוספת', 'oc-storeos-integration' ),
            array( $this, 'render_field_order_total_fee_tooltip' ),
            'oc-giorgio-orders',
            'oc_giorgio_orders_fee'
        );

        add_settings_field(
            'shipping_method_label_map',
            __( 'מיפוי שיטות משלוח לשם חיצוני', 'oc-storeos-integration' ),
            array( $this, 'render_field_shipping_method_label_map' ),
            'oc-giorgio-orders',
            'oc_giorgio_orders'
        );

        add_settings_field(
            'payment_method_label_map',
            __( 'מיפוי שיטות תשלום (תווית + סטטוס לשליחת הזמנה)', 'oc-storeos-integration' ),
            array( $this, 'render_field_payment_method_label_map' ),
            'oc-giorgio-orders',
            'oc_giorgio_orders'
        );
    }

    /**
     * Sanitize options.
     *
     * @param array $input Raw input.
     *
     * @return array
     */
    public function sanitize_options( $input ) {
        $options = $this->get_options();

        if ( isset( $input['api_base_url'] ) ) {
            $options['api_base_url'] = trim( esc_url_raw( $input['api_base_url'] ) );
            $options['api_base_url'] = rtrim( $options['api_base_url'], '/' );
        }

        if ( isset( $input['api_token'] ) ) {
            $options['api_token'] = sanitize_text_field( $input['api_token'] );
        }

        if ( isset( $input['github_token'] ) ) {
            $options['github_token'] = sanitize_text_field( $input['github_token'] );
        }

        if ( isset( $input['site_id'] ) ) {
            $options['site_id'] = sanitize_text_field( $input['site_id'] );
        }

        if ( isset( $input['incoming_api_token'] ) ) {
            $options['incoming_api_token'] = sanitize_text_field( $input['incoming_api_token'] );
        }

        if ( isset( $input['incoming_auth_mode'] ) ) {
            $mode = sanitize_key( $input['incoming_auth_mode'] );
            $options['incoming_auth_mode'] = in_array(
                $mode,
                array( self::INCOMING_AUTH_MODE_OFF, self::INCOMING_AUTH_MODE_LOG, self::INCOMING_AUTH_MODE_ENFORCE ),
                true
            ) ? $mode : self::INCOMING_AUTH_MODE_OFF;
        }

        $options['debug_logging'] = isset( $input['debug_logging'] )
            && ( '1' === (string) $input['debug_logging'] );

        $options['debug_outgoing_email'] = isset( $input['debug_outgoing_email'] )
            && ( '1' === (string) $input['debug_outgoing_email'] );

        if ( isset( $input['debug_email_recipient'] ) ) {
            $email = sanitize_email( $input['debug_email_recipient'] );
            $options['debug_email_recipient'] = is_email( $email ) ? $email : '';
        }

        if ( isset( $input['send_order_to_storeos_status'] ) ) {
            $options['send_order_to_storeos_status'] = $this->sanitize_send_order_to_storeos_status_input( $input['send_order_to_storeos_status'] );
        }

        $options['send_order_payment_webhook_on_charge'] = isset( $input['send_order_payment_webhook_on_charge'] )
            && ( '1' === (string) $input['send_order_payment_webhook_on_charge'] );

        if ( isset( $input['order_total_fee_percent'] ) ) {
            $raw = is_string( $input['order_total_fee_percent'] ) || is_numeric( $input['order_total_fee_percent'] )
                ? (string) $input['order_total_fee_percent']
                : '';
            $raw = str_replace( ',', '.', $raw );
            $val = (float) $raw;
            if ( $val < 0 ) {
                $val = 0;
            }
            if ( $val > 100 ) {
                $val = 100;
            }
            $options['order_total_fee_percent'] = $val;
        }

        if ( isset( $input['order_total_fee_cart_text'] ) ) {
            $options['order_total_fee_cart_text'] = sanitize_text_field( $input['order_total_fee_cart_text'] );
        }

        if ( isset( $input['order_total_fee_tooltip'] ) ) {
            $options['order_total_fee_tooltip'] = sanitize_textarea_field( $input['order_total_fee_tooltip'] );
        }

        if ( isset( $input['shipping_method_label_map'] ) && is_array( $input['shipping_method_label_map'] ) ) {
            $raw = $input['shipping_method_label_map'];
            $map = array();

            foreach ( $raw as $method_id => $label ) {
                $method_id = sanitize_text_field( trim( (string) $method_id ) );
                $label     = sanitize_text_field( trim( (string) $label ) );

                if ( '' !== $method_id && '' !== $label ) {
                    $map[ $method_id ] = $label;
                }
            }

            $options['shipping_method_label_map'] = $map;
        } elseif ( isset( $input['shipping_method_label_map'] ) && is_string( $input['shipping_method_label_map'] ) ) {
            // Backward compatibility with old textarea format: method_id|label
            $raw_map = sanitize_textarea_field( $input['shipping_method_label_map'] );
            $lines   = preg_split( '/\r\n|\r|\n/', $raw_map );
            $map     = array();

            if ( is_array( $lines ) ) {
                foreach ( $lines as $line ) {
                    $line = trim( (string) $line );
                    if ( '' === $line ) {
                        continue;
                    }
                    $chunks = explode( '|', $line, 2 );
                    if ( count( $chunks ) < 2 ) {
                        continue;
                    }
                    $method_id = sanitize_text_field( trim( $chunks[0] ) );
                    $label     = sanitize_text_field( trim( $chunks[1] ) );
                    if ( '' !== $method_id && '' !== $label ) {
                        $map[ $method_id ] = $label;
                    }
                }
            }

            $options['shipping_method_label_map'] = $map;
        }

        if ( isset( $input['payment_method_send_order_status_map'] ) && is_array( $input['payment_method_send_order_status_map'] ) ) {
            $out = array();
            foreach ( $input['payment_method_send_order_status_map'] as $method_id => $raw ) {
                $method_id = sanitize_text_field( trim( (string) $method_id ) );
                if ( '' === $method_id ) {
                    continue;
                }
                $v = is_string( $raw ) ? trim( $raw ) : '';
                if ( '' === $v ) {
                    continue;
                }
                if ( self::PAYMENT_METHOD_SEND_ORDER_STATUS_OFF === $v ) {
                    $out[ $method_id ] = self::PAYMENT_METHOD_SEND_ORDER_STATUS_OFF;
                    continue;
                }
                $san = $this->sanitize_send_order_to_storeos_status_input( $v );
                if ( '' !== $san ) {
                    $out[ $method_id ] = $san;
                }
            }
            $options['payment_method_send_order_status_map'] = $out;
        }

        if ( isset( $input['payment_method_label_map'] ) && is_array( $input['payment_method_label_map'] ) ) {
            $raw = $input['payment_method_label_map'];
            $map = array();

            foreach ( $raw as $method_id => $label ) {
                $method_id = sanitize_text_field( trim( (string) $method_id ) );
                $label     = sanitize_text_field( trim( (string) $label ) );

                if ( '' !== $method_id && '' !== $label ) {
                    $map[ $method_id ] = $label;
                }
            }

            $options['payment_method_label_map'] = $map;
        } elseif ( isset( $input['payment_method_label_map'] ) && is_string( $input['payment_method_label_map'] ) ) {
            // Backward compatibility with old textarea format: method_id|label
            $raw_map = sanitize_textarea_field( $input['payment_method_label_map'] );
            $lines   = preg_split( '/\r\n|\r|\n/', $raw_map );
            $map     = array();

            if ( is_array( $lines ) ) {
                foreach ( $lines as $line ) {
                    $line = trim( (string) $line );
                    if ( '' === $line ) {
                        continue;
                    }
                    $chunks = explode( '|', $line, 2 );
                    if ( count( $chunks ) < 2 ) {
                        continue;
                    }
                    $method_id = sanitize_text_field( trim( $chunks[0] ) );
                    $label     = sanitize_text_field( trim( $chunks[1] ) );
                    if ( '' !== $method_id && '' !== $label ) {
                        $map[ $method_id ] = $label;
                    }
                }
            }

            $options['payment_method_label_map'] = $map;
        }

        if ( array_key_exists( 'include_variation_in_line_title', $input ) ) {
            // Store 0|1 — WordPress may drop boolean false from serialized options, breaking "unchecked".
            $options['include_variation_in_line_title'] = ( '1' === (string) $input['include_variation_in_line_title'] ) ? 1 : 0;
        }

        return $options;
    }

    /**
     * Get plugin options with defaults.
     *
     * @return array
     */
    public function get_options() {
        $defaults = array(
            'api_base_url'        => '',
            'api_token'           => '',
            'github_token'        => '',
            'site_id'             => '',
            'incoming_api_token'  => '',
            'incoming_auth_mode'  => self::INCOMING_AUTH_MODE_OFF,
            'debug_logging'         => false,
            'debug_outgoing_email'  => false,
            'debug_email_recipient' => '',
            'send_order_to_storeos_status' => 'processing',
            'send_order_payment_webhook_on_charge' => true,
            'order_total_fee_percent' => 0,
            'order_total_fee_cart_text' => '',
            'order_total_fee_tooltip' => 'תוספת זו מוסיפה Fee באחוז מסכום ההזמנה (למשל שינויי משקל בפועל מול מה שהלקוח סימן).',
            'shipping_method_label_map' => array(),
            'payment_method_label_map'            => array(),
            'payment_method_send_order_status_map' => array(),
            'include_variation_in_line_title'     => 1,
        );

        $raw     = get_option( self::OPTION_NAME, array() );
        $options = ! is_array( $raw ) ? array() : $raw;

        $merged = wp_parse_args( $options, $defaults );

        // Former "on creation" mode removed — treat legacy value as processing so sync runs on status change.
        if ( isset( $merged['send_order_to_storeos_status'] ) && '__creation__' === $merged['send_order_to_storeos_status'] ) {
            $merged['send_order_to_storeos_status'] = 'processing';
        }

        // Legacy: boolean was saved then stripped by update_option(false) — normalize to 0|1.
        if ( array_key_exists( 'include_variation_in_line_title', $merged ) ) {
            $v = $merged['include_variation_in_line_title'];
            if ( false === $v ) {
                $merged['include_variation_in_line_title'] = 0;
            } elseif ( true === $v ) {
                $merged['include_variation_in_line_title'] = 1;
            }
        }

        // Migrate legacy checkbox (send_order_to_storeos) when status was never saved.
        if ( ! array_key_exists( 'send_order_to_storeos_status', $options ) ) {
            if ( isset( $options['send_order_to_storeos'] ) && ! $options['send_order_to_storeos'] ) {
                $merged['send_order_to_storeos_status'] = '';
            } elseif ( isset( $options['send_order_to_storeos'] ) && $options['send_order_to_storeos'] ) {
                $merged['send_order_to_storeos_status'] = 'processing';
            }
        }

        return $merged;
    }

    /**
     * Valid WooCommerce order status slugs (no wc- prefix), for settings + sanitization.
     *
     * @return string[]
     */
    protected function get_order_status_slugs_for_settings() {
        if ( ! function_exists( 'wc_get_order_statuses' ) ) {
            return array();
        }

        $slugs = array();
        foreach ( array_keys( wc_get_order_statuses() ) as $key ) {
            $slugs[] = str_replace( 'wc-', '', (string) $key );
        }

        return array_values( array_unique( $slugs ) );
    }

    /**
     * Sanitize the "send on status" setting value.
     *
     * @param mixed $value Raw input.
     * @return string '' | status slug.
     */
    protected function sanitize_send_order_to_storeos_status_input( $value ) {
        $value = sanitize_text_field( (string) $value );
        if ( '' === $value || '__creation__' === $value ) {
            return '';
        }
        $valid = $this->get_order_status_slugs_for_settings();
        if ( in_array( $value, $valid, true ) ) {
            return $value;
        }

        return '';
    }

    /**
     * Effective WooCommerce order status (slug, no wc- prefix) that triggers the first Giorgio Order sync, or
     * PAYMENT_METHOD_SEND_ORDER_STATUS_OFF to never sync for this order, for empty string when sync is off globally
     * and this method has no override.
     *
     * @param WC_Order|null $order Order.
     * @return string
     */
    protected function get_effective_send_order_to_storeos_status_for_order( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return '';
        }

        $options = $this->get_options();
        $global  = isset( $options['send_order_to_storeos_status'] ) ? (string) $options['send_order_to_storeos_status'] : '';
        $map     = array();
        if ( isset( $options['payment_method_send_order_status_map'] ) && is_array( $options['payment_method_send_order_status_map'] ) ) {
            $map = $options['payment_method_send_order_status_map'];
        }

        $pm = trim( (string) $order->get_payment_method() );
        if ( '' === $pm ) {
            return $global;
        }

        if ( ! array_key_exists( $pm, $map ) ) {
            return $global;
        }

        $v = (string) $map[ $pm ];
        if ( self::PAYMENT_METHOD_SEND_ORDER_STATUS_OFF === $v ) {
            return self::PAYMENT_METHOD_SEND_ORDER_STATUS_OFF;
        }

        if ( '' === $v ) {
            return $global;
        }

        return $v;
    }

    /**
     * Render settings page.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $options           = $this->get_options();
        $endpoint          = '';
        $incoming_endpoint = rest_url( self::REST_NAMESPACE . '/orders' );
        $wc_keys_url       = admin_url( 'admin.php?page=wc-settings&tab=advanced&section=keys' );

        if ( ! empty( $options['api_base_url'] ) ) {
            $endpoint = trailingslashit( $options['api_base_url'] ) . 'api/orders';
        }

        ?>
        <div class="wrap oc-giorgio-settings">
            <h1><?php esc_html_e( 'OC Giorgio Integration', 'oc-storeos-integration' ); ?></h1>
            <style>
                .oc-giorgio-settings code { font-family: Menlo, Monaco, Consolas, "Courier New", monospace; }
                .oc-giorgio-settings pre { background:#f6f7f7; border:1px solid #dcdcde; padding:8px; max-height:260px; overflow:auto; font-size:12px; }
                .oc-giorgio-settings .oc-giorgio-panel { background:#fff; border:1px solid #ccd0d4; border-top:none; padding:6px 20px 2px; max-width:1000px; }
                .oc-giorgio-settings .oc-giorgio-panel h2 { font-size:13px; text-transform:uppercase; letter-spacing:.04em; color:#646970; border-top:1px solid #e6e6e6; padding-top:16px; margin:22px 0 0; }
                .oc-giorgio-settings details.oc-giorgio-details { margin:16px 0; background:#fbfbfc; border:1px solid #dcdcde; border-radius:4px; padding:10px 14px; max-width:1000px; }
                .oc-giorgio-settings details.oc-giorgio-details > summary { cursor:pointer; font-weight:600; color:#2271b1; }
                .oc-giorgio-settings details.oc-giorgio-details > .oc-giorgio-ref { margin-top:12px; }
                /* on/off fields shown as toggle switches (the underlying input is still a checkbox, so saving is unchanged) */
                .oc-giorgio-settings input[type="checkbox"].oc-toggle { -webkit-appearance:none; appearance:none; width:42px; height:22px; border-radius:22px; background:#c3c4c7; position:relative; cursor:pointer; transition:background .2s; vertical-align:middle; margin:0 4px; }
                .oc-giorgio-settings input[type="checkbox"].oc-toggle:checked { background:#00A07A; }
                .oc-giorgio-settings input[type="checkbox"].oc-toggle::before { content:""; position:absolute; top:2px; left:2px; width:18px; height:18px; border-radius:50%; background:#fff; transition:left .2s, right .2s; box-shadow:0 1px 2px rgba(0,0,0,.25); }
                .oc-giorgio-settings input[type="checkbox"].oc-toggle:checked::before { left:22px; }
                body.rtl .oc-giorgio-settings input[type="checkbox"].oc-toggle::before { left:auto; right:2px; }
                body.rtl .oc-giorgio-settings input[type="checkbox"].oc-toggle:checked::before { right:22px; }
            </style>

            <h2 class="nav-tab-wrapper">
                <a href="#oc-giorgio-panel-connection" class="nav-tab nav-tab-active oc-giorgio-tab" data-panel="oc-giorgio-panel-connection"><?php esc_html_e( 'חיבור', 'oc-storeos-integration' ); ?></a>
                <a href="#oc-giorgio-panel-orders" class="nav-tab oc-giorgio-tab" data-panel="oc-giorgio-panel-orders"><?php esc_html_e( 'הזמנות', 'oc-storeos-integration' ); ?></a>
            </h2>

            <form method="post" action="options.php">
                <?php settings_fields( self::OPTION_GROUP ); ?>

                <div id="oc-giorgio-panel-connection" class="oc-giorgio-panel">
                    <?php do_settings_sections( 'oc-giorgio-connection' ); ?>

                    <details class="oc-giorgio-details">
                        <summary><?php esc_html_e( 'כתובות ודוגמאות JSON (למפתחים)', 'oc-storeos-integration' ); ?></summary>
                        <div class="oc-giorgio-ref">
                            <p>
                                <?php esc_html_e( 'To create orders in WooCommerce, the external system should POST to:', 'oc-storeos-integration' ); ?>
                                <br /><code><?php echo esc_html( $incoming_endpoint ); ?></code>
                            </p>
                            <?php if ( ! empty( $endpoint ) ) : ?>
                                <p><?php esc_html_e( 'Outgoing orders are sent to:', 'oc-storeos-integration' ); ?><br /><code><?php echo esc_html( $endpoint ); ?></code></p>
                                <p><?php esc_html_e( 'Example outgoing JSON payload:', 'oc-storeos-integration' ); ?></p>
                                <pre><code><?php echo esc_html( wp_json_encode( array(
                                    'externalOrderId' => 12345,
                                    'orderNumber'     => '12345',
                                    'source'          => 'WooCommerce',
                                    'siteId'          => 'site_001',
                                    'status'          => 'on-hold',
                                    'customer'        => array( 'name' => 'John Doe', 'phone' => '0501234567', 'email' => 'john@example.com' ),
                                    'items'           => array( array( 'productId' => 123, 'name' => 'Product Name', 'quantity' => 2, 'unitPrice' => 50, 'lineTotal' => 100 ) ),
                                    'shippingTotal'   => 20,
                                    'orderTotal'      => 120,
                                ), JSON_PRETTY_PRINT ) ); ?></code></pre>
                            <?php endif; ?>
                            <p><?php esc_html_e( 'Example incoming JSON payload:', 'oc-storeos-integration' ); ?></p>
                            <pre><code><?php echo esc_html( wp_json_encode( array(
                                'status'          => 'on-hold',
                                'externalOrderId' => 'EXT-12345',
                                'customer'        => array( 'name' => 'John Doe', 'phone' => '0501234567', 'email' => 'john@example.com' ),
                                'items'           => array( array( 'sku' => 'ABC-123', 'quantity' => 1 ) ),
                            ), JSON_PRETTY_PRINT ) ); ?></code></pre>
                        </div>
                    </details>
                </div>

                <div id="oc-giorgio-panel-orders" class="oc-giorgio-panel" style="display:none;">
                    <?php do_settings_sections( 'oc-giorgio-orders' ); ?>
                </div>

                <?php submit_button(); ?>
            </form>
        </div>
        <script>
        (function(){
            var tabs = document.querySelectorAll('.oc-giorgio-tab');
            tabs.forEach(function(tab){
                tab.addEventListener('click', function(e){
                    e.preventDefault();
                    tabs.forEach(function(t){ t.classList.remove('nav-tab-active'); });
                    document.querySelectorAll('.oc-giorgio-panel').forEach(function(p){ p.style.display='none'; });
                    tab.classList.add('nav-tab-active');
                    var panel = document.getElementById(tab.getAttribute('data-panel'));
                    if (panel) { panel.style.display=''; }
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Render API Base URL field.
     */
    public function render_field_api_base_url() {
        $options = $this->get_options();
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_base_url]"
               value="<?php echo esc_attr( $options['api_base_url'] ); ?>"
               placeholder="https://example.com" />
        <p class="description">
            <?php esc_html_e( 'Base URL of the external API (without trailing slash).', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render API Token field.
     */
    public function render_field_api_token() {
        $options = $this->get_options();
        ?>
        <input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[api_token]"
               value="<?php echo esc_attr( $options['api_token'] ); ?>" autocomplete="off" />
        <p class="description">
            <?php esc_html_e( 'API token or key provided by the external system.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render GitHub Token field (used only for auto-updates from a private GitHub repo).
     */
    public function render_field_github_token() {
        $options    = $this->get_options();
        $via_const  = defined( 'OC_GIORGIO_GH_TOKEN' ) && OC_GIORGIO_GH_TOKEN;
        $value      = isset( $options['github_token'] ) ? (string) $options['github_token'] : '';
        ?>
        <input type="password" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[github_token]"
               value="<?php echo esc_attr( $value ); ?>" autocomplete="off" <?php disabled( $via_const ); ?> />
        <p class="description">
            <?php
            if ( $via_const ) {
                esc_html_e( 'מוגדר דרך קבוע OC_GIORGIO_GH_TOKEN ב-wp-config (גובר על השדה הזה).', 'oc-storeos-integration' );
            } else {
                esc_html_e( 'טוקן GitHub לקריאה בלבד (fine-grained, הרשאת Contents: Read על ה-repo בלבד). נחוץ רק כשה-repo פרטי, כדי שעדכונים אוטומטיים יעבדו. לא קשור ל-API של Giorgio.', 'oc-storeos-integration' );
            }
            ?>
        </p>
        <?php
    }

    /**
     * Render Site ID field.
     */
    public function render_field_site_id() {
        $options = $this->get_options();
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION_NAME ); ?>[site_id]"
               value="<?php echo esc_attr( $options['site_id'] ); ?>" />
        <p class="description">
            <?php esc_html_e( 'Optional site identifier in the external system.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render the inbound shared-secret field (used to authenticate Giorgio → site REST calls).
     */
    public function render_field_incoming_api_token() {
        $options = $this->get_options();
        $value   = isset( $options['incoming_api_token'] ) ? (string) $options['incoming_api_token'] : '';
        ?>
        <input type="password" class="regular-text" id="oc_storeos_incoming_api_token"
               name="<?php echo esc_attr( self::OPTION_NAME ); ?>[incoming_api_token]"
               value="<?php echo esc_attr( $value ); ?>" autocomplete="off" />
        <p class="description">
            <?php esc_html_e( 'סוד משותף ש-Giorgio חייב לשלוח בכל בקשה נכנסת, באחת מהכותרות: X-OC-Giorgio-Token, או X-Api-Key, או Authorization: Bearer <token>. השתמש במחרוזת אקראית ארוכה (32+ תווים). זהו טוקן נפרד מ-API Token היוצא.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render the inbound auth enforcement-mode selector (off / log / enforce).
     */
    public function render_field_incoming_auth_mode() {
        $options = $this->get_options();
        $current = isset( $options['incoming_auth_mode'] ) ? (string) $options['incoming_auth_mode'] : self::INCOMING_AUTH_MODE_OFF;
        $name    = self::OPTION_NAME . '[incoming_auth_mode]';
        $choices = array(
            self::INCOMING_AUTH_MODE_OFF     => __( 'כבוי — ללא בדיקה (התנהגות נוכחית)', 'oc-storeos-integration' ),
            self::INCOMING_AUTH_MODE_LOG     => __( 'לוג בלבד — בודק ומתעד, מעביר הכל (לבדיקה)', 'oc-storeos-integration' ),
            self::INCOMING_AUTH_MODE_ENFORCE => __( 'אכיפה — חוסם בקשות עם טוקן חסר/שגוי (401)', 'oc-storeos-integration' ),
        );
        ?>
        <select name="<?php echo esc_attr( $name ); ?>" id="oc_storeos_incoming_auth_mode">
            <?php foreach ( $choices as $val => $label ) : ?>
                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $current, $val ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e( 'סדר הטמעה מומלץ: (1) הזן טוקן למעלה. (2) תאם עם המפתח של Giorgio שיתחיל לשלוח את הכותרת. (3) עבור ל״לוג בלבד״ ובדוק ביומן (WooCommerce → סטטוס → יומנים, מקור oc-storeos-integration) ש-matched=yes. (4) רק אז עבור ל״אכיפה״. הערה: ב״אכיפה״ ללא טוקן מוגדר — הבקשות עדיין יעברו, כדי שלא ננעל בטעות.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render checkbox: verbose debug logging to the PHP error log.
     */
    public function render_field_debug_logging() {
        $options = $this->get_options();
        $on      = ! empty( $options['debug_logging'] );
        $name    = self::OPTION_NAME . '[debug_logging]';
        ?>
        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
        <label>
            <input type="checkbox" class="oc-toggle" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $on ); ?> />
            <?php esc_html_e( 'כתוב שורות דיבאג מפורטות ל-PHP error log. כבוי בפרודקשן. (נדלק אוטומטית גם כש-WP_DEBUG פעיל.)', 'oc-storeos-integration' ); ?>
        </label>
        <?php
    }

    /**
     * Render checkbox + recipient: email a copy of the outgoing payload for debugging.
     */
    public function render_field_debug_outgoing_email() {
        $options   = $this->get_options();
        $on        = ! empty( $options['debug_outgoing_email'] );
        $recipient = isset( $options['debug_email_recipient'] ) ? (string) $options['debug_email_recipient'] : '';
        $name      = self::OPTION_NAME . '[debug_outgoing_email]';
        $rcpt_name = self::OPTION_NAME . '[debug_email_recipient]';
        ?>
        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
        <label>
            <input type="checkbox" class="oc-toggle" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $on ); ?> />
            <?php esc_html_e( 'שלח עותק של ה-payload היוצא במייל (לצרכי דיבאג בלבד). נשלחים שני מיילים להזמנת אשראי: AUTHORIZATION (הרשאה/J5) כשההזמנה נכנסת, ו-CAPTURE (תפיסה/חיוב) — כולל תשובת Giorgio — כשההזמנה מחויבת.', 'oc-storeos-integration' ); ?>
        </label>
        <br /><br />
        <input type="email" class="regular-text" name="<?php echo esc_attr( $rcpt_name ); ?>"
               value="<?php echo esc_attr( $recipient ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
        <p class="description">
            <?php esc_html_e( 'כתובת לקבלת עותקי הדיבאג. אם ריק — נשלח לכתובת מנהל האתר. שים לב: ה-payload כולל פרטי לקוח (שם, טלפון, מייל, כתובת), אז להשאיר כבוי בפרודקשן.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render select: which WooCommerce order status triggers outgoing Order sync to Giorgio.
     */
    public function render_field_send_order_to_storeos_status() {
        $options  = $this->get_options();
        $current  = isset( $options['send_order_to_storeos_status'] ) ? (string) $options['send_order_to_storeos_status'] : '';
        $name     = self::OPTION_NAME . '[send_order_to_storeos_status]';
        $statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
        ?>
        <select name="<?php echo esc_attr( $name ); ?>" id="oc_storeos_send_order_to_storeos_status">
            <option value="" <?php selected( $current, '' ); ?>><?php esc_html_e( 'לא לשלוח', 'oc-storeos-integration' ); ?></option>
            <?php foreach ( $statuses as $wc_key => $label ) : ?>
                <?php
                $slug = str_replace( 'wc-', '', (string) $wc_key );
                ?>
                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>>
                    <?php echo esc_html( $label . ' (' . $slug . ')' ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description">
            <?php esc_html_e( 'בחירת סטטוס ברירת מחדל: ההזמנה תישלח ל־Giorgio בפעם הראשונה שההזמנה עוברת לסטטוס הזה (בהתאם לשיטת התשלום — ר׳ הטבלה ״מיפוי שיטות תשלום״, עמודת סטטוס). אם לשיטה אין עקיפה בטבלה, משתמשים בערך כאן. לא נשלח מיד ביצירת ההזמנה. עדכון תשלום (OrderPayment) נשאר בהגדרה נפרדת למטה.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render checkbox: send OrderPayment webhook when WooCommerce marks payment complete.
     */
    public function render_field_send_order_payment_webhook_on_charge() {
        $options = $this->get_options();
        $on      = ! empty( $options['send_order_payment_webhook_on_charge'] );
        $name    = self::OPTION_NAME . '[send_order_payment_webhook_on_charge]';
        ?>
        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
        <label>
            <input type="checkbox" class="oc-toggle" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $on ); ?> />
            <?php esc_html_e( 'שלח ל־Giorgio עדכון תשלום (OrderPayment) מיד כשהחיוב ב־Woo עובר (Payment complete).', 'oc-storeos-integration' ); ?>
        </label>
        <p class="description">
            <?php esc_html_e( 'כבוי = לא יישלח בעת החיוב בלבד. שינוי סטטוס ההזמנה ל״הושלמה״ עדיין ישלח עדכון תשלום ל־Giorgio.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render checkbox: include variation text in line item "name" sent to Giorgio.
     */
    public function render_field_include_variation_in_line_title() {
        $options = $this->get_options();
        // Do not use empty() — empty(false) is true and would show unchecked even when we mean "include".
        $on      = (int) $options['include_variation_in_line_title'] === 1;
        $name    = self::OPTION_NAME . '[include_variation_in_line_title]';
        ?>
        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
        <label>
            <input type="checkbox" class="oc-toggle" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $on ); ?> />
            <?php esc_html_e( 'מסומן: שם השורה כמו ב־WooCommerce (כולל וריאציה בכותרת). לא מסומן: רק שם מוצר הבסיס (להוריאציה — שם המוצר ההורה). פרטי הוריאציה נשארים בשדה variation.', 'oc-storeos-integration' ); ?>
        </label>
        <?php
    }

    /**
     * Render "order total percentage fee" field (adds a WooCommerce Fee).
     */
    public function render_field_order_total_fee_percent() {
        $options = $this->get_options();
        $percent = isset( $options['order_total_fee_percent'] ) ? (float) $options['order_total_fee_percent'] : 0;
        $tooltip = isset( $options['order_total_fee_tooltip'] ) ? (string) $options['order_total_fee_tooltip'] : '';
        $tooltip = '' !== $tooltip ? $tooltip : __( 'תוספת זו מוסיפה Fee באחוז מסכום ההזמנה.', 'oc-storeos-integration' );
        ?>
        <span class="oc-storeos-tooltip dashicons dashicons-info-outline" title="<?php echo esc_attr( $tooltip ); ?>"></span>
        <input
                type="number"
                step="0.01"
                min="0"
                max="100"
                class="small-text"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[order_total_fee_percent]"
                value="<?php echo esc_attr( $percent ); ?>"
        />
        <span>%</span>
        <p class="description">
            <?php esc_html_e( 'האחוז יחושב מסכום ההזמנה ויוסף כ‑Fee בעגלה/צ׳קאאוט. 0 = כבוי.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    protected function get_order_line_sale_units_parts( $item ) {
        $empty = array(
            'saleUnits'       => '',
            'saleTotalWeight' => '',
        );

        if ( ! $item instanceof WC_Order_Item_Product ) {
            return $empty;
        }

        if ( function_exists( 'ocwsu_order_item_quantity_summary' ) ) {
            $data = ocwsu_order_item_quantity_summary( $item );
            if ( ! is_array( $data ) || empty( $data['weighable'] ) ) {
                return $empty;
            }

            $units  = '';
            $weight = '';

            if ( isset( $data['units'] ) && '' !== trim( (string) $data['units'] ) ) {
                $units = trim( (string) $data['units'] ) . ' ' . __( 'units', 'ocwsu' );
            }
            if ( isset( $data['unit_weight'], $data['unit_weight_label'] ) && '' !== trim( (string) $data['unit_weight'] ) ) {
                $weight = trim( (string) $data['unit_weight'] ) . ' ' . (string) $data['unit_weight_label'];
            }

            return array(
                'saleUnits'       => $units,
                'saleTotalWeight' => $weight,
            );
        }

        return $this->get_order_line_sale_units_parts_fallback( $item );
    }

    /**
     * Fallback when oc-woo-sale-units helpers are unavailable.
     *
     * @param WC_Order_Item_Product|mixed $item Order line item.
     * @return array{saleUnits: string, saleTotalWeight: string}
     */
    protected function get_order_line_sale_units_parts_fallback( $item ) {
        return array(
            'saleUnits'       => '',
            'saleTotalWeight' => '',
        );
    }

    /**
     * Giorgio quantity semantics: quantityType (kg vs unit), optional unit count + unit weight for weighable sold-by-units.
     *
     * @param WC_Order_Item_Product|mixed $item Order line item.
     * @return array{quantityType: string, unit: float|null, unitWeight: float|null}
     */
    protected function get_order_line_storeos_quantity_fields( $item ) {
        $defaults = array(
            'quantityType' => 'unit',
            'unit'         => null,
            'unitWeight'   => null,
        );

        if ( ! $item instanceof WC_Order_Item_Product ) {
            return $defaults;
        }

        if ( ! function_exists( 'ocwsu_is_item_weighable' ) || ! ocwsu_is_item_weighable( $item ) ) {
            return $defaults;
        }

        $out = array(
            'quantityType' => 'kg',
            'unit'         => null,
            'unitWeight'   => null,
        );

        if ( ! $this->ocwsu_is_product_sold_by_units_for_item( $item ) ) {
            return $out;
        }

        $qty_units = wc_get_order_item_meta( $item->get_id(), '_ocwsu_quantity_in_units', true );
        $unit_w    = wc_get_order_item_meta( $item->get_id(), '_ocwsu_unit_weight', true );

        if ( '' !== (string) $qty_units && null !== $qty_units && false !== $qty_units ) {
            $out['unit'] = (float) $qty_units;
        }
        if ( '' !== (string) $unit_w && null !== $unit_w && false !== $unit_w ) {
            $out['unitWeight'] = $this->normalize_storeos_unit_weight_for_api( (float) $unit_w );
        }

        return $out;
    }

    /**
     * Order meta may store per-unit weight in grams (e.g. 500); Giorgio expects kg (0.5).
     * Values greater than 10 are treated as grams and divided by 1000; otherwise left as kg.
     *
     * @param float $raw Raw value from _ocwsu_unit_weight.
     * @return float
     */
    protected function normalize_storeos_unit_weight_for_api( $raw ) {
        $n = (float) $raw;
        if ( $n > 10.0 ) {
            $n = $n / 1000.0;
        }

        return round( $n, 6 );
    }

    /**
     * Whether product is sold by units (parent meta for variations), per oc-woo-sale-units.
     *
     * @param WC_Order_Item_Product|mixed $item Order line item.
     */
    protected function ocwsu_is_product_sold_by_units_for_item( $item ) {
        if ( ! $item instanceof WC_Order_Item_Product ) {
            return false;
        }
        $product = $item->get_product();
        if ( ! $product instanceof WC_Product ) {
            return false;
        }
        $product_id = $product->is_type( 'variation' ) ? (int) $product->get_parent_id() : (int) $product->get_id();

        return 'yes' === get_post_meta( $product_id, '_ocwsu_sold_by_units', true );
    }

    /**
     * Render cart line label for the percentage fee (appears in cart as fee name).
     */
    public function render_field_order_total_fee_cart_text() {
        $options   = $this->get_options();
        $cart_text = isset( $options['order_total_fee_cart_text'] ) ? (string) $options['order_total_fee_cart_text'] : '';
        ?>
        <input
                type="text"
                class="regular-text"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[order_total_fee_cart_text]"
                value="<?php echo esc_attr( $cart_text ); ?>"
                placeholder="<?php echo esc_attr( __( 'תוספת משקל', 'oc-storeos-integration' ) ); ?>"
        />
        <p class="description">
            <?php esc_html_e( 'הטקסט שיוצג בשורת העמלה בעגלה ובצ׳קאאוט. אם תשאירו ריק — יוצג "תוספת משקל".', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render editable tooltip text for the percentage fee field.
     */
    public function render_field_order_total_fee_tooltip() {
        $options = $this->get_options();
        $tooltip = isset( $options['order_total_fee_tooltip'] ) ? (string) $options['order_total_fee_tooltip'] : '';
        ?>
        <textarea
                class="large-text"
                rows="3"
                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[order_total_fee_tooltip]"
        ><?php echo esc_textarea( $tooltip ); ?></textarea>
        <p class="description">
            <?php esc_html_e( 'הטקסט שיופיע בטולטיפ ליד שדה האחוזים (ניתן לעריכה).', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render shipping method -> external shipping label map.
     * Left column: current label on site, right column: override label to send.
     */
    public function render_field_shipping_method_label_map() {
        $options = $this->get_options();
        $map     = isset( $options['shipping_method_label_map'] ) ? $options['shipping_method_label_map'] : array();
        if ( is_string( $map ) ) {
            // Backward compatibility with older saved format.
            $parsed = array();
            $lines  = preg_split( '/\r\n|\r|\n/', $map );
            if ( is_array( $lines ) ) {
                foreach ( $lines as $line ) {
                    $line = trim( (string) $line );
                    if ( '' === $line ) {
                        continue;
                    }
                    $chunks = explode( '|', $line, 2 );
                    if ( count( $chunks ) < 2 ) {
                        continue;
                    }
                    $method_id = trim( $chunks[0] );
                    $label     = trim( $chunks[1] );
                    if ( '' !== $method_id && '' !== $label ) {
                        $parsed[ $method_id ] = $label;
                    }
                }
            }
            $map = $parsed;
        }
        if ( ! is_array( $map ) ) {
            $map = array();
        }

        $available_methods = $this->get_available_shipping_methods_for_mapping();

        // Keep any manually saved mappings that are not currently detected on site.
        foreach ( $map as $saved_method_id => $saved_label ) {
            if ( ! isset( $available_methods[ $saved_method_id ] ) ) {
                $available_methods[ $saved_method_id ] = __( '(Method not currently detected on site)', 'oc-storeos-integration' );
            }
        }
        ?>
        <table class="widefat striped" style="max-width: 920px;">
            <thead>
            <tr>
                <th style="width:28%;"><?php esc_html_e( 'Method ID', 'oc-storeos-integration' ); ?></th>
                <th style="width:32%;"><?php esc_html_e( 'שם נוכחי באתר', 'oc-storeos-integration' ); ?></th>
                <th style="width:40%;"><?php esc_html_e( 'Label לשליחה למערכת (shippinglabel)', 'oc-storeos-integration' ); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ( $available_methods as $method_id => $current_label ) : ?>
                <tr>
                    <td>
                        <code><?php echo esc_html( $method_id ); ?></code>
                    </td>
                    <td>
                        <?php echo esc_html( $current_label ); ?>
                    </td>
                    <td>
                        <input
                                type="text"
                                class="regular-text"
                                style="width:100%;"
                                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[shipping_method_label_map][<?php echo esc_attr( $method_id ); ?>]"
                                value="<?php echo esc_attr( isset( $map[ $method_id ] ) ? (string) $map[ $method_id ] : '' ); ?>"
                                placeholder="<?php esc_attr_e( 'אם ריק - יישלח השם הנוכחי', 'oc-storeos-integration' ); ?>"
                        />
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">
            <?php esc_html_e( 'המערכת מזהה אוטומטית את שיטות המשלוח מהאתר. בכל שורה אפשר להגדיר תווית חלופית שתישלח ל-API. לדוגמה: flat_rate:5 עם שם נוכחי "משלוח עד הבית" אפשר למפות לתווית אחרת לשליחה.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Render payment method -> external payment label map.
     * Left column: current label on site, right column: override label to send.
     */
    public function render_field_payment_method_label_map() {
        $options   = $this->get_options();
        $map       = isset( $options['payment_method_label_map'] ) ? $options['payment_method_label_map'] : array();
        $status_map = isset( $options['payment_method_send_order_status_map'] ) && is_array( $options['payment_method_send_order_status_map'] )
            ? $options['payment_method_send_order_status_map'] : array();

        if ( is_string( $map ) ) {
            $parsed = array();
            $lines  = preg_split( '/\r\n|\r|\n/', $map );
            if ( is_array( $lines ) ) {
                foreach ( $lines as $line ) {
                    $line = trim( (string) $line );
                    if ( '' === $line ) {
                        continue;
                    }
                    $chunks = explode( '|', $line, 2 );
                    if ( count( $chunks ) < 2 ) {
                        continue;
                    }
                    $method_id = trim( $chunks[0] );
                    $label     = trim( $chunks[1] );
                    if ( '' !== $method_id && '' !== $label ) {
                        $parsed[ $method_id ] = $label;
                    }
                }
            }
            $map = $parsed;
        }

        if ( ! is_array( $map ) ) {
            $map = array();
        }

        $available_methods = $this->get_available_payment_methods_for_mapping();

        foreach ( $map as $saved_method_id => $saved_label ) {
            if ( ! isset( $available_methods[ $saved_method_id ] ) ) {
                $available_methods[ $saved_method_id ] = __( '(Method not currently detected on site)', 'oc-storeos-integration' );
            }
        }
        foreach ( $status_map as $saved_method_id => $saved_st ) {
            if ( ! isset( $available_methods[ $saved_method_id ] ) ) {
                $available_methods[ $saved_method_id ] = __( '(Method not currently detected on site)', 'oc-storeos-integration' );
            }
        }
        $wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
        ?>
        <table class="widefat striped" style="max-width: 1100px;">
            <thead>
            <tr>
                <th style="width:22%;"><?php esc_html_e( 'Payment Method ID', 'oc-storeos-integration' ); ?></th>
                <th style="width:24%;"><?php esc_html_e( 'שם נוכחי באתר', 'oc-storeos-integration' ); ?></th>
                <th style="width:28%;"><?php esc_html_e( 'Label לשליחה למערכת (paymentlabel)', 'oc-storeos-integration' ); ?></th>
                <th style="width:26%;"><?php esc_html_e( 'סטטוס Woo לשליחת הזמנה ל־Giorgio (פעם ראשונה)', 'oc-storeos-integration' ); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ( $available_methods as $method_id => $current_label ) : ?>
                <?php
                $st_sel = isset( $status_map[ $method_id ] ) ? (string) $status_map[ $method_id ] : '';
                ?>
                <tr>
                    <td><code><?php echo esc_html( $method_id ); ?></code></td>
                    <td><?php echo esc_html( $current_label ); ?></td>
                    <td>
                        <input
                                type="text"
                                class="regular-text"
                                style="width:100%;"
                                name="<?php echo esc_attr( self::OPTION_NAME ); ?>[payment_method_label_map][<?php echo esc_attr( $method_id ); ?>]"
                                value="<?php echo esc_attr( isset( $map[ $method_id ] ) ? (string) $map[ $method_id ] : '' ); ?>"
                                placeholder="<?php esc_attr_e( 'אם ריק - יישלח השם הנוכחי', 'oc-storeos-integration' ); ?>"
                        />
                    </td>
                    <td>
                        <select name="<?php echo esc_attr( self::OPTION_NAME ); ?>[payment_method_send_order_status_map][<?php echo esc_attr( $method_id ); ?>]" style="max-width:100%;">
                            <option value="" <?php selected( $st_sel, '' ); ?>><?php esc_html_e( 'ברירת מחדל (מההגדרה למעלה)', 'oc-storeos-integration' ); ?></option>
                            <option value="<?php echo esc_attr( self::PAYMENT_METHOD_SEND_ORDER_STATUS_OFF ); ?>" <?php selected( $st_sel, self::PAYMENT_METHOD_SEND_ORDER_STATUS_OFF ); ?>><?php esc_html_e( 'לא לשלוח הזמנה (שיטה זו)', 'oc-storeos-integration' ); ?></option>
                            <?php foreach ( $wc_statuses as $wc_key => $st_label ) : ?>
                                <?php
                                $slug = str_replace( 'wc-', '', (string) $wc_key );
                                ?>
                                <option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $st_sel, $slug ); ?>>
                                    <?php echo esc_html( $st_label . ' (' . $slug . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="description">
            <?php esc_html_e( 'מוצגות רק שיטות תשלום שמסומנות כפעילות ב־WooCommerce (הגדרות → תשלומים). בכל שורה: תווית לשדה paymentlabel, ואיזה סטטוס Woo יגרום לשליחת הזמנה מלאה ל־Giorgio בפעם הראשונה. אם מבחרים ״ברירת מחדל״ — נעשה שימוש בהגדרת ״ברירת מחדל: סטטוס לשליחת הזמנה״ למעלה.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    /**
     * Collect available shipping methods (instance ids from zones + generic methods).
     *
     * @return array method_id => current_label
     */
    protected function get_available_shipping_methods_for_mapping() {
        $methods = array();

        if ( class_exists( 'WC_Shipping_Zones' ) ) {
            $zones = WC_Shipping_Zones::get_zones();
            if ( is_array( $zones ) ) {
                foreach ( $zones as $zone_data ) {
                    if ( empty( $zone_data['shipping_methods'] ) || ! is_array( $zone_data['shipping_methods'] ) ) {
                        continue;
                    }
                    foreach ( $zone_data['shipping_methods'] as $method ) {
                        if ( ! is_object( $method ) || ! isset( $method->id ) ) {
                            continue;
                        }
                        $method_id   = (string) $method->id;
                        $instance_id = isset( $method->instance_id ) ? (string) $method->instance_id : '';
                        $full_id     = $method_id . ( '' !== $instance_id ? ':' . $instance_id : '' );
                        $title       = method_exists( $method, 'get_title' ) ? (string) $method->get_title() : '';
                        if ( '' === $title && isset( $method->method_title ) ) {
                            $title = (string) $method->method_title;
                        }
                        if ( '' === $title ) {
                            $title = $full_id;
                        }
                        $methods[ $full_id ] = $title;
                    }
                }
            }
        }

        // Fallback generic methods list.
        if ( function_exists( 'WC' ) && isset( WC()->shipping ) && method_exists( WC()->shipping, 'get_shipping_methods' ) ) {
            $generic = WC()->shipping->get_shipping_methods();
            if ( is_array( $generic ) ) {
                foreach ( $generic as $id => $method ) {
                    $id = (string) $id;
                    if ( isset( $methods[ $id ] ) ) {
                        continue;
                    }
                    $title = is_object( $method ) && isset( $method->method_title ) ? (string) $method->method_title : $id;
                    $methods[ $id ] = $title;
                }
            }
        }

        // Extra fallback: collect real method ids/titles from recent orders.
        $recent_orders = wc_get_orders(
            array(
                'limit'   => 200,
                'orderby' => 'date',
                'order'   => 'DESC',
                'return'  => 'objects',
            )
        );

        if ( is_array( $recent_orders ) ) {
            foreach ( $recent_orders as $order ) {
                if ( ! $order instanceof WC_Order ) {
                    continue;
                }

                foreach ( $order->get_shipping_methods() as $shipping_item ) {
                    if ( ! $shipping_item instanceof WC_Order_Item_Shipping ) {
                        continue;
                    }

                    $method_id   = (string) $shipping_item->get_method_id();
                    $instance_id = (string) $shipping_item->get_instance_id();
                    $full_id     = $method_id . ( '' !== $instance_id ? ':' . $instance_id : '' );

                    $title = trim( (string) $shipping_item->get_method_title() );
                    if ( '' === $title ) {
                        $title = trim( (string) $shipping_item->get_name() );
                    }
                    if ( '' === $title ) {
                        $title = $full_id;
                    }

                    if ( '' !== $full_id && ! isset( $methods[ $full_id ] ) ) {
                        $methods[ $full_id ] = $title;
                    }
                    if ( '' !== $method_id && ! isset( $methods[ $method_id ] ) ) {
                        $methods[ $method_id ] = $title;
                    }
                }
            }
        }

        ksort( $methods );

        return $methods;
    }

    /**
     * Whether a WC payment gateway is enabled in WooCommerce → Settings → Payments.
     *
     * @param object $gateway Payment gateway object.
     * @return bool
     */
    protected function is_wc_payment_gateway_enabled( $gateway ) {
        if ( ! is_object( $gateway ) || ! property_exists( $gateway, 'enabled' ) ) {
            return false;
        }
        if ( function_exists( 'wc_string_to_bool' ) ) {
            return wc_string_to_bool( $gateway->enabled );
        }
        $e = (string) $gateway->enabled;
        return in_array( $e, array( 'yes', '1', 'true' ), true );
    }

    /**
     * Collect available payment methods (enabled gateways only) for the mapping table.
     *
     * @return array payment_method_id => current_label
     */
    protected function get_available_payment_methods_for_mapping() {
        $methods = array();

        if ( function_exists( 'WC' ) && WC()->payment_gateways() ) {
            $pg = WC()->payment_gateways();
            if ( is_object( $pg ) && method_exists( $pg, 'payment_gateways' ) ) {
                $gateways = $pg->payment_gateways();
                if ( is_array( $gateways ) ) {
                    foreach ( $gateways as $g_key => $gateway ) {
                        if ( ! is_object( $gateway ) ) {
                            continue;
                        }
                        if ( ! $this->is_wc_payment_gateway_enabled( $gateway ) ) {
                            continue;
                        }
                        if ( ! isset( $gateway->id ) || '' === (string) $gateway->id ) {
                            if ( is_string( $g_key ) && '' !== $g_key && ! is_numeric( $g_key ) ) {
                                $id = (string) $g_key;
                            } else {
                                continue;
                            }
                        } else {
                            $id = (string) $gateway->id;
                        }
                        $title = isset( $gateway->title ) ? (string) $gateway->title : $id;
                        $methods[ $id ] = $title;
                        if ( (string) $g_key !== $id && '' !== (string) $g_key ) {
                            $methods[ (string) $g_key ] = $title;
                        }
                    }
                }
            }
        }

        ksort( $methods );

        return apply_filters( 'oc_storeos_available_payment_methods_for_mapping', $methods, $this );
    }

    /**
     * Add a percentage Fee to cart/checkout totals (for weight adjustments).
     *
     * @param WC_Cart $cart WooCommerce cart.
     */
    public function add_order_percentage_fee( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        if ( ! $cart || ! method_exists( $cart, 'add_fee' ) ) {
            return;
        }

        $options = $this->get_options();
        $percent = isset( $options['order_total_fee_percent'] ) ? (float) $options['order_total_fee_percent'] : 0;
        if ( $percent <= 0 ) {
            return;
        }

        // Base: cart contents + shipping (if already calculated). Fees are calculated before final totals.
        $base = 0.0;
        if ( method_exists( $cart, 'get_cart_contents_total' ) ) {
            $base += (float) $cart->get_cart_contents_total();
        }
        if ( method_exists( $cart, 'get_shipping_total' ) ) {
            $base += (float) $cart->get_shipping_total();
        }

        if ( $base <= 0 ) {
            return;
        }

        $amount = ( $base * $percent ) / 100;
        if ( function_exists( 'wc_get_price_decimals' ) ) {
            $amount = round( $amount, wc_get_price_decimals() );
        }

        if ( $amount <= 0 ) {
            return;
        }

        $label = $this->get_order_total_fee_cart_label();
        $cart->add_fee( $label, $amount, false );
    }

    /**
     * Label shown in cart/checkout for the configurable percentage fee (default: תוספת משקל).
     *
     * @return string
     */
    protected function get_order_total_fee_cart_label() {
        $options   = $this->get_options();
        $cart_text = isset( $options['order_total_fee_cart_text'] ) ? trim( (string) $options['order_total_fee_cart_text'] ) : '';

        return '' !== $cart_text ? $cart_text : __( 'תוספת משקל', 'oc-storeos-integration' );
    }

    /**
     * Info icon HTML for the weight fee, to print after the fee label (not next to the amount).
     *
     * @param object $fee WC_Cart_Fee or compatible (has get_name() or name).
     *
     * @return string Safe HTML fragment or empty string.
     */
    public function get_weight_fee_tooltip_icon_html( $fee ) {
        if ( empty( $fee ) || ! is_object( $fee ) ) {
            return '';
        }

        $our_label = $this->get_order_total_fee_cart_label();

        $name = '';
        if ( method_exists( $fee, 'get_name' ) ) {
            $name = (string) $fee->get_name();
        } elseif ( isset( $fee->name ) ) {
            $name = (string) $fee->name;
        }

        if ( $name !== $our_label ) {
            return '';
        }

        $options = $this->get_options();
        $tooltip = isset( $options['order_total_fee_tooltip'] ) ? (string) $options['order_total_fee_tooltip'] : '';
        $tooltip = '' !== $tooltip ? $tooltip : __( 'תוספת זו מוסיפה Fee באחוז מסכום ההזמנה (למשל שינויי משקל בפועל מול מה שהלקוח סימן).', 'oc-storeos-integration' );

        $html = '<span class="oc-storeos-fee-tooltip" tabindex="0" role="img" aria-label="' . esc_attr( $tooltip ) . '" data-tooltip="' . esc_attr( $tooltip ) . '">i</span>';

        return wp_kses(
            $html,
            array(
                'span' => array(
                    'class'        => true,
                    'tabindex'     => true,
                    'role'         => true,
                    'aria-label'   => true,
                    'data-tooltip' => true,
                ),
            )
        );
    }

    /**
     * Render frontend styles for the custom fee tooltip (cart, checkout, and mini/float cart on other pages).
     */
    public function render_fee_tooltip_styles() {
        if ( is_admin() ) {
            return;
        }
        if ( ! function_exists( 'WC' ) || ! WC() ) {
            return;
        }
        ?>
        <style id="oc-storeos-fee-tooltip-style">
            .oc-storeos-fee-tooltip {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                width: 18px;
                height: 18px;
                margin-inline-start: 6px;
                border-radius: 999px;
                border: 1px solid #c7ced4;
                background: #f8fafc;
                color: #2f3f4a;
                font-size: 12px;
                font-weight: 700;
                line-height: 1;
                cursor: help;
                position: relative;
                vertical-align: middle;
            }
            .oc-storeos-fee-tooltip::after {
                content: attr(data-tooltip);
                position: absolute;
                left: 50%;
                bottom: calc(100% + 10px);
                transform: translateX(-50%) translateY(4px);
                min-width: 220px;
                max-width: 320px;
                padding: 8px 10px;
                border-radius: 8px;
                background: #111827;
                color: #fff;
                font-size: 12px;
                font-weight: 400;
                line-height: 1.45;
                text-align: start;
                box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
                transition: opacity .15s ease, transform .15s ease, visibility .15s ease;
                z-index: 9999;
                white-space: normal;
            }
            .oc-storeos-fee-tooltip::before {
                content: '';
                position: absolute;
                left: 50%;
                bottom: calc(100% + 4px);
                transform: translateX(-50%);
                border: 6px solid transparent;
                border-top-color: #111827;
                opacity: 0;
                visibility: hidden;
                transition: opacity .15s ease, visibility .15s ease;
                z-index: 10000;
            }
            .oc-storeos-fee-tooltip:hover::after,
            .oc-storeos-fee-tooltip:hover::before,
            .oc-storeos-fee-tooltip:focus::after,
            .oc-storeos-fee-tooltip:focus::before {
                opacity: 1;
                visibility: visible;
                transform: translateX(-50%) translateY(0);
            }
        </style>
        <?php
    }


    /**
     * When the order reaches the configured WooCommerce status, try outgoing sync (status-based mode).
     *
     * @param int            $order_id   Order ID.
     * @param string         $old_status Previous status (no wc- prefix).
     * @param string         $new_status New status (no wc- prefix).
     * @param WC_Order|mixed $order      Order instance when available.
     */
    public function handle_order_status_for_storeos_outgoing( $order_id, $old_status, $new_status, $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order instanceof WC_Order ) {
            return;
        }
        $trigger = $this->get_effective_send_order_to_storeos_status_for_order( $order );
        if ( self::PAYMENT_METHOD_SEND_ORDER_STATUS_OFF === $trigger ) {
            return;
        }
        if ( '' === $trigger ) {
            return;
        }
        if ( (string) $new_status !== $trigger ) {
            return;
        }

        $this->send_outgoing_when_order_enters( $order );
    }

    /**
     * Single POST per order per request when the order matches the configured trigger (and has line items).
     *
     * @param WC_Order|null $order   Order.
     * @param array         $context Optional. `skip_status_gate` — for incoming REST echo (ignore WC status trigger).
     *
     * @return array|null Skipped flags, or outgoingPayload + storeosHttpResponse from Giorgio.
     */
    protected function send_outgoing_when_order_enters( $order, $context = array() ) {
        if ( ! $order instanceof WC_Order ) {
            return null;
        }

        $context = wp_parse_args(
            $context,
            array(
                'skip_status_gate' => false,
            )
        );

        $order_id = $order->get_id();
        if ( ! $order_id ) {
            return null;
        }

        if ( ! empty( self::$outgoing_sync_after_creation_done[ $order_id ] ) ) {
            return array(
                'skipped' => true,
                'reason'  => 'already_synced_this_request',
            );
        }

        $refreshed = wc_get_order( $order_id );
        if ( $refreshed instanceof WC_Order ) {
            $order = $refreshed;
        }
        $trigger = $this->get_effective_send_order_to_storeos_status_for_order( $order );

        if ( self::PAYMENT_METHOD_SEND_ORDER_STATUS_OFF === $trigger ) {
            return array(
                'skipped' => true,
                'reason'  => 'payment_method_send_order_storeos_off',
            );
        }

        if ( '' === $trigger ) {
            return array(
                'skipped' => true,
                'reason'  => 'send_order_to_storeos_disabled',
            );
        }

        if ( empty( $context['skip_status_gate'] ) ) {
            if ( '__creation__' === $trigger ) {
                return array(
                    'skipped' => true,
                    'reason'  => 'send_order_to_storeos_disabled_or_legacy_creation_mode',
                );
            }
            if ( $order->get_status() !== $trigger ) {
                return array(
                    'skipped' => true,
                    'reason'  => 'order_status_not_matching_trigger',
                );
            }
        }

        if ( count( $order->get_items() ) < 1 ) {
            return array(
                'skipped' => true,
                'reason'  => 'no_line_items',
            );
        }

        self::$outgoing_sync_after_creation_done[ $order_id ] = true;
        return $this->send_order_to_storeos( $order );
    }

    /**
     * POST order JSON to Giorgio when the "send order" option is enabled.
     *
     * @param WC_Order $order Order object.
     * 
     * @return array|null See send_outgoing_when_order_enters().
     */
    protected function send_order_to_storeos( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return null;
        }
        $options = $this->get_options();

        $trigger = $this->get_effective_send_order_to_storeos_status_for_order( $order );
        if ( self::PAYMENT_METHOD_SEND_ORDER_STATUS_OFF === $trigger ) {
            return array(
                'skipped' => true,
                'reason'  => 'payment_method_send_order_storeos_off',
            );
        }
        if ( '' === $trigger ) {
            return array(
                'skipped' => true,
                'reason'  => 'send_order_to_storeos_disabled',
            );
        }

        if ( (int) $order->get_meta( self::META_SYNCED, true ) === 1 ) {
            return array(
                'skipped' => true,
                'reason'  => 'already_synced_to_storeos',
            );
        }

        if ( empty( $options['api_base_url'] ) || empty( $options['api_token'] ) ) {
            return array(
                'skipped' => true,
                'reason'  => 'missing_api_credentials',
            );
        } 

        // Cardcom: do not send the order before transaction id exists (deal number saved by IPN).
        if ( self::GATEWAY_CARDCOM === (string) $order->get_payment_method() ) {
            $tx = trim( (string) $order->get_meta( self::META_CARDCOM_PAYMENT_ID, true ) );
            if ( '' === $tx ) {
                $tx = trim( (string) $order->get_meta( self::META_CARDCOM_INTERNAL_DEAL_NUMBER, true ) );
            }
            if ( '' === $tx || '0' === $tx ) {
                $this->maybe_debug_log( sprintf( '[OC Giorgio] Outgoing Order: waiting_for_cardcom_transaction. order_id=%d status=%s trigger=%s', (int) $order->get_id(), (string) $order->get_status(), (string) $trigger ) );
                // Don't block the current request; schedule a short retry to give Cardcom IPN time to persist meta.
                if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) ) {
                    $hook = 'oc_storeos_retry_outgoing_order_after_cardcom_tx';
                    if ( ! wp_next_scheduled( $hook, array( (int) $order->get_id() ) ) ) {
                        wp_schedule_single_event( time() + 15, $hook, array( (int) $order->get_id() ) );
                    }
                }
                return array(
                    'skipped' => true,
                    'reason'  => 'waiting_for_cardcom_transaction',
                );
            }
        }
        $this->maybe_debug_log( sprintf( '[OC Giorgio] Outgoing Order: build_order_payload start. order_id=%d status=%s', (int) $order->get_id(), (string) $order->get_status() ) );
        $payload = $this->build_order_payload( $order, $options );
        $payload_json = wp_json_encode( $payload ); 
        $this->oc_storeos_wc_log(
            'info',
            sprintf(
                'Outgoing Order: sending order to Giorgio. order_id=%d payload_keys=%s',
                (int) $order->get_id(),
                implode(',', array_keys($payload))
            ),
            array(
                'order_id' => (int) $order->get_id(),
                'payload'  => $payload,
            )
        );
   
        if ( false === $payload_json ) {
            $payload_json = '';
        }
        $payload_hash = md5( $payload_json );
        $dedup_key    = self::TRANSIENT_OUT_ORDER_HASH_PREFIX . (int) $order->get_id();

        // אותה בקשת עדכון פעמיים (Polly, שני handlers, ריטרי מול WAF) → לא לשגר שוב אותו גוף ל-Giorgio.
        if ( get_transient( $dedup_key ) === $payload_hash ) {
            return array(
                'skipped' => true,
                'reason'  => 'duplicate_order_payload_recent',
            );
        }

        $api_result = $this->send_order_to_api( $order, $payload, $options );
        $this->maybe_debug_log(
            sprintf(
                '[OC Giorgio] Outgoing Order: send_order_to_api done. order_id=%d success=%s http=%s err_prefix=%s',
                (int) $order->get_id(),
                ! empty( $api_result['success'] ) ? 'yes' : 'no',
                isset( $api_result['http_status'] ) ? (string) $api_result['http_status'] : '',
                isset( $api_result['error'] ) ? substr( (string) $api_result['error'], 0, 200 ) : ''
            )
        );

        if ( ! empty( $api_result['success'] ) ) {
            set_transient( $dedup_key, $payload_hash, self::OUT_ORDER_DEDUP_TTL );
        }

        return array(
            'outgoingPayload'     => $payload,
            'storeosHttpResponse' => $api_result,
        );
    }

    /**
     * Line item title for Giorgio "name" field, per settings.
     *
     * @param WC_Order_Item_Product $item    Order line item.
     * @param array                 $options Plugin options.
     *
     * @return string
     */
    protected function get_storeos_line_item_display_name( $item, $options ) {
        // 1 = full line name (default); 0 = parent/base title only. Persist 0|1 in DB — bool false was dropped by update_option().
        $include = (int) ( $options['include_variation_in_line_title'] ?? 1 ) === 1;
        if ( $include ) {
            return $item->get_name();
        }

        $product = $item->get_product();
        if ( ! $product instanceof WC_Product ) {
            return $item->get_name();
        }

        if ( $product instanceof WC_Product_Variation ) {
            $parent = wc_get_product( $product->get_parent_id() );
            if ( $parent instanceof WC_Product ) {
                return $parent->get_name();
            }
        }

        return $product->get_name();
    }

    /**
     * Whether a string looks like an OC Woo Shipping / Google location code, not a display city name.
     *
     * @param mixed $value Value to test.
     * @return bool
     */
    protected function is_ocws_raw_location_code( $value ) {
        if ( ! is_string( $value ) || '' === $value ) {
            return false;
        }
        // Google Maps place_id (typical prefix).
        if ( 0 === strpos( $value, 'ChIJ' ) ) {
            return true;
        }
        if ( function_exists( 'ocws_is_hash' ) && ocws_is_hash( $value ) ) {
            return true;
        }
        return is_numeric( $value );
    }

    /**
     * Resolve shipping city for Giorgio: WC city may hold a place_id; prefer name meta and billing fallback.
     *
     * @param WC_Order $order Order.
     * @return string
     */
    protected function resolve_shipping_city_for_storeos_payload( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return '';
        }

        $city = '';
        if ( function_exists( 'ocws_get_order_shipping_city_name' ) ) {
            $city = ocws_get_order_shipping_city_name( $order );
        }
        if ( '' === (string) $city ) {
            $city = (string) $order->get_shipping_city();
        }

        if ( ! $this->is_ocws_raw_location_code( $city ) ) {
            return $city;
        }

        $shipping_name = $order->get_meta( '_shipping_city_name', true );
        if ( is_string( $shipping_name ) && '' !== $shipping_name && ! $this->is_ocws_raw_location_code( $shipping_name ) ) {
            return $shipping_name;
        }

        $billing_name = $order->get_meta( '_billing_city_name', true );
        if ( is_string( $billing_name ) && '' !== $billing_name && ! $this->is_ocws_raw_location_code( $billing_name ) ) {
            return $billing_name;
        }

        if ( function_exists( 'ocws_get_order_billing_city_name' ) ) {
            $billing = ocws_get_order_billing_city_name( $order );
            if ( is_string( $billing ) && '' !== $billing && ! $this->is_ocws_raw_location_code( $billing ) ) {
                return $billing;
            }
        }

        if ( function_exists( 'ocws_get_city_title' ) ) {
            $resolved = ocws_get_city_title( $city );
            if ( is_string( $resolved ) && '' !== $resolved ) {
                return $resolved;
            }
        }

        return $city;
    }

    /**
     * Floor / apartment / entrance code: prefer shipping meta when present, else billing (OC Woo Shipping).
     *
     * @param WC_Order $order Order.
     * @param string   $shipping_meta_key Meta key with leading underscore, e.g. _shipping_floor.
     * @param string   $billing_meta_key  Meta key with leading underscore, e.g. _billing_floor.
     * @return string
     */
    protected function resolve_shipping_or_billing_meta( $order, $shipping_meta_key, $billing_meta_key ) {
        if ( ! $order instanceof WC_Order ) {
            return '';
        }
        $v = $order->get_meta( $shipping_meta_key, true );
        if ( is_string( $v ) && '' !== $v ) {
            return sanitize_text_field( $v );
        }
        $v = $order->get_meta( $billing_meta_key, true );
        return is_string( $v ) ? sanitize_text_field( $v ) : '';
    }

    /**
     * Whether the order uses pickup (איסוף) — Woo local_pickup or OC Woo local pickup.
     *
     * @param WC_Order $order Order.
     * @return bool
     */
    protected function order_is_pickup_for_storeos( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return false;
        }
        $tag = $order->get_meta( 'ocws_shipping_tag', true );
        if ( 'pickup' === (string) $tag ) {
            return true;
        }
        foreach ( $order->get_shipping_methods() as $shipping_method ) {
            if ( ! $shipping_method instanceof WC_Order_Item_Shipping ) {
                continue;
            }
            $method_id = (string) $shipping_method->get_method_id();
            if ( 'local_pickup' === $method_id || 0 === strpos( $method_id, 'local_pickup' ) ) {
                return true;
            }
            if ( function_exists( 'ocws_is_method_id_pickup' ) && ocws_is_method_id_pickup( $method_id ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Raw branch name as OC Woo Shipping stores it (meta + ocws_lp_pickup_info on shipping line + affiliates DB).
     *
     * @param WC_Order $order Order.
     * @return string
     */
    protected function resolve_pickup_affiliate_branch_name( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return '';
        }
        $name = $order->get_meta( 'ocws_lp_pickup_aff_name', true );
        if ( is_string( $name ) && '' !== $name ) {
            return $name;
        }
        $name = $order->get_meta( '_oc_storeos_pickup_aff_name', true );
        if ( is_string( $name ) && '' !== $name ) {
            return $name;
        }
        if ( class_exists( 'OCWS_LP_Pickup_Info' ) ) {
            $shipping_item = OCWS_LP_Pickup_Info::get_shipping_item( $order );
            if ( $shipping_item instanceof WC_Order_Item_Shipping ) {
                $pickup_raw = $shipping_item->get_meta( 'ocws_lp_pickup_info', true );
                if ( $pickup_raw ) {
                    $pickup_info = maybe_unserialize( $pickup_raw );
                    if ( is_array( $pickup_info ) ) {
                        $aff_name = isset( $pickup_info['aff_name'] ) ? $pickup_info['aff_name'] : '';
                        if ( is_string( $aff_name ) && '' !== $aff_name ) {
                            return $aff_name;
                        }
                        $aff_id = isset( $pickup_info['aff_id'] ) ? absint( $pickup_info['aff_id'] ) : 0;
                        if ( $aff_id && class_exists( 'OCWS_LP_Affiliates' ) ) {
                            $affs_ds = new OCWS_LP_Affiliates();
                            if ( method_exists( $affs_ds, 'get_affiliate_name' ) ) {
                                $resolved = $affs_ds->get_affiliate_name( $aff_id );
                                if ( is_string( $resolved ) && '' !== $resolved ) {
                                    return $resolved;
                                }
                            }
                        }
                    }
                }
            }
        }
        $aff_id = $order->get_meta( 'ocws_lp_pickup_aff_id', true );
        if ( $aff_id && class_exists( 'OCWS_LP_Affiliates' ) ) {
            $affs_ds = new OCWS_LP_Affiliates();
            if ( method_exists( $affs_ds, 'get_affiliate_name' ) ) {
                $resolved = $affs_ds->get_affiliate_name( absint( $aff_id ) );
                if ( is_string( $resolved ) && '' !== $resolved ) {
                    return $resolved;
                }
            }
        }
        return '';
    }

    /**
     * Branch display name only (no "Pickup branch" / סניף איסוף prefix).
     *
     * @param WC_Order $order Order.
     * @return string
     */
    protected function resolve_pickup_store_name_for_storeos( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return '';
        }
        $aff_name = $this->resolve_pickup_affiliate_branch_name( $order );
        return '' === $aff_name ? '' : sanitize_text_field( $aff_name );
    }

    /**
     * Line item "baker" / product note for outgoing Giorgio payload.
     * Theme saves cart `product_note` as order item meta with label {@see __('הערות לקצב', 'woocommerce')},
     * so the stored meta key is the translated string (not literal `product_note`).
     *
     * @param WC_Order_Item_Product $item Order line item.
     * @return string Plain text; empty if none.
     */
    protected function get_order_line_item_product_note_for_storeos( $item ) {
        if ( ! $item instanceof WC_Order_Item_Product ) {
            return '';
        }
        $keys = array(
            'product_note',
            __( 'הערות לקצב', 'woocommerce' ),
            __( 'הערות לקצב', 'deliz-short' ),
            'הערות לקצב',
            'הערות לקוח אודות ההזמנה:',
            'הערות לקוח אודות ההזמנה',
            __( 'הערות לקוח אודות ההזמנה:', 'woocommerce'),
            __( 'הערות לקוח אודות ההזמנה', 'woocommerce'),
            __( 'הערות לקוח אודות ההזמנה', 'deliz-short' ),
            __( 'הערות לקוח אודות ההזמנה:', 'deliz-short' ),
        );
        foreach ( array_unique( array_filter( $keys ) ) as $meta_key ) {
            $raw = $item->get_meta( $meta_key, true );
            if ( $raw === '' || $raw === null ) {
                continue;
            }
            $text = is_string( $raw ) ? $raw : '';
            if ( '' === trim( $text ) ) {
                continue;
            }
            return sanitize_textarea_field( wp_strip_all_tags( $text ) );
        }
        return '';
    }

    /**
     * Whether this line is considered "on promotion" for Giorgio: paid less than pre-discount line and/or below product regular price.
     *
     * @param WC_Order_Item_Product $item Order line item.
     * @return bool
     */
    protected function order_line_item_is_on_promotion( WC_Order_Item_Product $item ) {
        $line_total    = (float) $item->get_total();
        $line_subtotal = (float) $item->get_subtotal();
        $qty           = max( 0.0, (float) $item->get_quantity() );
        $eps           = 0.02;

        $reason = '';
        $on     = false;

        // Coupons / line discounts: line total below line subtotal (Woo baseline before those reductions).
        if ( $line_subtotal > $eps && $line_total + $eps < $line_subtotal ) {
            $on     = true;
            $reason = 'line_discount';
        }

        if ( ! $on && $qty > $eps ) {
            $product = $item->get_product();
            if ( $product instanceof WC_Product ) {
                $regular_raw = $product->get_regular_price();
                if ( $regular_raw !== '' && null !== $regular_raw ) {
                    $regular_unit = (float) wc_format_decimal( (string) $regular_raw );
                    if ( $regular_unit > $eps ) {
                        $subtotal_unit = $line_subtotal / $qty;
                        // Catalog sale / sale price: subtotal reflects what was charged before extra line discounts.
                        if ( $subtotal_unit + $eps < $regular_unit ) {
                            $on     = true;
                            $reason = 'below_regular_price';
                        }
                    }
                }
            }
        }

        return (bool) apply_filters( 'oc_storeos_order_line_on_promotion', $on, $item, $reason );
    }

    protected function detect_order_source( $order ) {
        $user_agent = $order->get_meta( '_customer_user_agent', true );

        if ( ! empty( $user_agent ) && false !== stripos( $user_agent, 'originalconcepts/1.0' ) ) {
            return 'OriginalConceptsApp';
        }

        return 'WooCommerce';
    }

    /**
     * Build order payload as JSON-ready array.
     *
     * @param WC_Order $order   Order object.
     * @param array    $options Plugin options.
     *
     * @return array
     */
    protected function build_order_payload( $order, $options ) {
        $order_id     = $order->get_id();
        $order_number = (string) $order->get_id(); // Use internal ID as orderNumber for stable external key.
        $status       = $order->get_status();
        $date_created = $order->get_date_created();

        $customer_name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $customer_phone = $order->get_billing_phone();
        $customer_email = $order->get_billing_email();

        // Prefer OC Woo Shipping human-readable city (not Google place_id in WC city field).
        $shipping_city = $this->resolve_shipping_city_for_storeos_payload( $order );

        $shipping_street_meta  = $order->get_meta( '_shipping_street', true );
        $shipping_house_meta   = $order->get_meta( '_shipping_house_num', true );
        $billing_street_meta   = $order->get_meta( '_billing_street', true );
        $billing_house_meta    = $order->get_meta( '_billing_house_num', true );

        $street_parts = array();

        if ( ! empty( $shipping_street_meta ) || ! empty( $shipping_house_meta ) ) {
            if ( ! empty( $shipping_street_meta ) ) {
                $street_parts[] = $shipping_street_meta;
            }
            if ( ! empty( $shipping_house_meta ) ) {
                $street_parts[] = $shipping_house_meta;
            }
        } elseif ( ! empty( $billing_street_meta ) || ! empty( $billing_house_meta ) ) {
            if ( ! empty( $billing_street_meta ) ) {
                $street_parts[] = $billing_street_meta;
            }
            if ( ! empty( $billing_house_meta ) ) {
                $street_parts[] = $billing_house_meta;
            }
        }

        $shipping_street = trim( implode( ' ', $street_parts ) );

        // Fallback to standard WooCommerce fields if OC Woo Shipping meta is not present.
        if ( '' === $shipping_street ) {
            $shipping_street = trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_address_2() );
        }

        $shipping_zip = $order->get_shipping_postcode();

        $shipping_floor      = $this->resolve_shipping_or_billing_meta( $order, '_shipping_floor', '_billing_floor' );
        $shipping_apartment  = $this->resolve_shipping_or_billing_meta( $order, '_shipping_apartment', '_billing_apartment' );
        $shipping_enter_code = $this->resolve_shipping_or_billing_meta( $order, '_shipping_enter_code', '_billing_enter_code' );

        $items_payload = array();
        foreach ( $order->get_items() as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }

            $product_id = $item->get_product_id();
            $line_name    = $this->get_storeos_line_item_display_name( $item, $options );
            $quantity   = (float) $item->get_quantity();
            $line_total = (float) $item->get_total();

            $unit_price = $quantity > 0 ? $line_total / $quantity : 0;

            $sku = '';
            $product = $item->get_product();
            if ( $product instanceof WC_Product ) {
                $sku = $product->get_sku();
            }

            // Variation attributes (selected properties) + extra item meta.
            // This is used for variable products (e.g. size/color).
            $variation_id = 0;
            if ( method_exists( $item, 'get_variation_id' ) ) {
                $variation_id = (int) $item->get_variation_id();
            }

            $variation_attributes = array();
            $item_meta_payload    = array();

            // Best source for variation attributes on WC_Order_Item_Product.
            if ( method_exists( $item, 'get_variation_attributes' ) ) {
                $raw_attrs = $item->get_variation_attributes();
                if ( is_array( $raw_attrs ) ) {
                    foreach ( $raw_attrs as $key => $value ) {
                        if ( ! is_string( $key ) ) {
                            continue;
                        }
                        if ( $value === '' || $value === null ) {
                            continue;
                        }

                        $attr_key = $key;
                        // Typically: attribute_pa_color => color
                        if ( 0 === strpos( $attr_key, 'attribute_' ) ) {
                            $attr_key = substr( $attr_key, strlen( 'attribute_' ) ); // pa_color
                        }
                        if ( 0 === strpos( $attr_key, 'pa_' ) ) {
                            $attr_key = substr( $attr_key, 3 ); // color
                        }

                        $variation_attributes[ $attr_key ] = is_scalar( $value ) ? (string) $value : $value;
                    }
                }
            }

            // Fallback + extra meta.
            // בתוך הלולאה של foreach ( $order->get_items() as $item )

            $variation_id = (int) $item->get_variation_id();
            $variation_attributes = array();

// 1. שליפת וריאציות בצורה נקייה (עברית וסלאגים)
            if ( $variation_id > 0 ) {
                $product_variation = $item->get_product();
                if ( $product_variation instanceof WC_Product_Variation ) {
                    $selection = $product_variation->get_attributes();
                    foreach ( $selection as $taxonomy => $slug ) {
                        $label = wc_attribute_label( $taxonomy, $product_variation );
                        $display_value = $slug;

                        if ( taxonomy_exists( $taxonomy ) ) {
                            $term = get_term_by( 'slug', $slug, $taxonomy );
                            if ( $term && ! is_wp_error( $term ) ) {
                                $display_value = $term->name;
                            }
                        } else {
                            $display_value = urldecode( $slug );
                        }
                        $variation_attributes[ $label ] = $display_value;
                    }
                }
            }

// 2. הערה לקצב (מטא שורה: product_note או תווית deliz/WC "הערות לקצב")
            $product_note = $this->get_order_line_item_product_note_for_storeos( $item );

// 3. בניית ה-Payload
            // Giorgio מצפה ל-Dictionary (JSON object); מערך PHP ריק נהפך ל-[] וגורם ל-400 ב-.NET.
            $variation_attrs_for_json = empty( $variation_attributes )
                ? new \stdClass()
                : $variation_attributes;


            $sale_units_parts = $this->get_order_line_sale_units_parts( $item );
            $sale_units_line  = implode(
                ', ',
                array_filter(
                    array( $sale_units_parts['saleUnits'], $sale_units_parts['saleTotalWeight'] ),
                    static function ( $v ) {
                        return '' !== trim( (string) $v );
                    }
                )
            );

            $storeos_qty = $this->get_order_line_storeos_quantity_fields( $item );

            $items_payload[] = array(
                'productId'   => $item->get_product_id(),
                'name'        => $line_name,
                'sku'         => $sku,
                'quantity'    => $quantity,
                'unitPrice'   => $unit_price,
                'lineTotal'   => $line_total,
                'onPromotion' => $this->order_line_item_is_on_promotion( $item ),
                'productNote' => $product_note,
                'quantityType'    => $storeos_qty['quantityType'],
                'unit'            => $storeos_qty['unit'],
                'unitWeight'      => $storeos_qty['unitWeight'],
                'saleUnits'       => $sale_units_parts['saleUnits'],
                'saleTotalWeight' => $sale_units_parts['saleTotalWeight'],
                'saleUnitsLine'   => $sale_units_line,
                'variation'  => array(
                    'variationId' => $variation_id ?: null,
                    'attributes'  => $variation_attrs_for_json,
                ),
            );
        }

        // Map WooCommerce status to external API expectations.
        $external_status = 'on-hold';
        switch ( $status ) {
            case 'completed':
                $external_status = 'completed';
                break;
            case 'cancelled':
            case 'canceled':
                $external_status = 'cancelled';
                break;
            default:
                $external_status = 'on-hold';
                break;
        }

        $payload = array(
            'externalOrderId' => (int) $order_id,
            'orderNumber'     => $order_number,
            'source'          => $this->detect_order_source( $order ),
            'siteId'          => ! empty( $options['site_id'] ) ? (string) $options['site_id'] : null,
            'status'          => $external_status,
            'orderDate'       => $date_created ? $date_created->date( 'c' ) : current_time( 'c' ),
            'customer'        => array(
                'name'  => $customer_name,
                'phone' => $customer_phone,
                'email' => $customer_email,
            ),
            'shippingAddress' => array(
                'street'    => $shipping_street,
                'city'      => $shipping_city,
                'zip'       => $shipping_zip,
                'floor'     => $shipping_floor,
                'apartment' => $shipping_apartment,
                'enterCode' => $shipping_enter_code,
            ),
            'items'           => $items_payload,
            'shippingTotal'   => (float) $order->get_shipping_total(),
            'orderTotal'      => (float) $order->get_total(),
            'customerNotes'   => $order->get_meta('_billing_notes'),
        );

        $shipping_label = $this->resolve_shipping_label_for_payload( $order, $options );
        if ( '' !== $shipping_label ) {
            $payload['shippinglabel'] = $shipping_label;
        }
        if ( $this->order_is_pickup_for_storeos( $order ) ) {
            $store_name = $this->resolve_pickup_store_name_for_storeos( $order );
            if ( '' !== $store_name ) {
                $payload['shippingstorename'] = $store_name;
            }
        }

        $payment_label = $this->resolve_payment_label_for_payload( $order, $options );
        if ( '' !== $payment_label ) {
            $payload['paymentlabel'] = $payment_label;
        }

        // Outgoing order: payment object (OrderPayment model) with required key names + stable key order.
        $payment_gateway_id = (string) $order->get_payment_method();
        $transaction_id     = trim( (string) $order->get_meta( self::META_CARDCOM_PAYMENT_ID, true ) );
        if ( '' === $transaction_id ) {
            $transaction_id = trim( (string) $order->get_meta( self::META_CARDCOM_INTERNAL_DEAL_NUMBER, true ) );
        }
        $invoice_no   = $this->get_cardcom_invoice_number_for_order_payment( $order );
        $cc_last_four = $this->get_cardcom_cc_last_four_for_payload( $order );
        $total_amount = (float) $order->get_total();

        $payment_block = array(
            'transactionId'     => ( '' !== $transaction_id && '0' !== $transaction_id ) ? $transaction_id : null,
            'paymentGateway'    => '' !== $payment_gateway_id ? $payment_gateway_id : null,
            'authorizedAmount'  => $total_amount,
            'amount'            => $total_amount,
            'invoiceNumber'     => '' !== $invoice_no ? $invoice_no : null,
            'last4Digits'       => '' !== $cc_last_four ? $cc_last_four : null,
            'cardLast4'         => '' !== $cc_last_four ? $cc_last_four : null,
            'cardBrand'         => null,
            'approvalNumber'    => null,
        );

        // Remove nulls while keeping insertion order for existing keys.
        foreach ( $payment_block as $k => $v ) {
            if ( null === $v ) {
                unset( $payment_block[ $k ] );
            }
        }
        if ( ! empty( $payment_block ) ) {
            $payload['payment'] = $payment_block;
        }

        $cardcom_fields = $this->get_cardcom_outgoing_order_fields_for_payload( $order );
        if ( ! empty( $cardcom_fields ) ) {
            $payload = array_merge( $payload, $cardcom_fields );
        }

        // הוספת מידע משלוח (אם קיים ב-Meta של ההזמנה).
        $shipping_info = $this->get_order_shipping_info_meta( $order );
        if ( ! empty( $shipping_info ) ) {
            $payload['shippingInfo'] = $shipping_info;
        }
        
        return $payload;
    }

    /**
     * Cardcom fields for outgoing Order payload (first sync to Giorgio).
     *
     * @param WC_Order $order Order.
     * @return array Keys: internalNumber, exp_mo, exp_year, numOfPayments, cc_number (only when meta is non-empty).
     */
    protected function get_cardcom_outgoing_order_fields_for_payload( WC_Order $order ) {
        if ( ! $order instanceof WC_Order ) {
            return array();
        }

        $fields = array();

        $internal_number = trim( (string) $order->get_meta( self::META_CARDCOM_INTERNAL_DEAL_NUMBER, true ) );
        if ( '' !== $internal_number && '0' !== $internal_number ) {
            $fields['internalNumber'] = $internal_number;
        }

        $exp_mo = trim( (string) $order->get_meta( self::META_CARDCOM_TOKEN_EXPIRY_MONTH, true ) );
        if ( '' !== $exp_mo ) {
            $fields['exp_mo'] = $exp_mo;
        }

        $exp_year = trim( (string) $order->get_meta( self::META_CARDCOM_TOKEN_EXPIRY_YEAR, true ) );
        if ( '' !== $exp_year ) {
            $fields['exp_year'] = $exp_year;
        }

        $num_of_payments = trim( (string) $order->get_meta( self::META_CARDCOM_NUM_OF_PAYMENTS, true ) );
        if ( '' !== $num_of_payments && '0' !== $num_of_payments ) {
            $fields['numOfPayments'] = is_numeric( $num_of_payments ) ? (int) $num_of_payments : $num_of_payments;
        }

        $cc_last_four = $this->get_cardcom_cc_last_four_for_payload( $order );
        if ( '' !== $cc_last_four ) {
            $fields['cc_number'] = $cc_last_four;
        }

        return (array) apply_filters( 'oc_storeos_cardcom_outgoing_order_fields', $fields, $order );
    }

    /**
     * Last 4 card digits from Cardcom order meta (ExtShvaParams_CardNumber5 or masked cc_number).
     *
     * @param WC_Order $order Order.
     * @return string Four digits, or ''.
     */
    protected function get_cardcom_cc_last_four_for_payload( WC_Order $order ) {
        if ( ! $order instanceof WC_Order ) {
            return '';
        }

        $raw = trim( (string) $order->get_meta( self::META_CARDCOM_CC_NUMBER, true ) );
        if ( '' === $raw ) {
            return '';
        }

        if ( preg_match( '/^\d{4}$/', $raw ) ) {
            return $raw;
        }

        if ( preg_match( '/(\d{4})\D*$/', $raw, $matches ) ) {
            return $matches[1];
        }

        $digits = preg_replace( '/\D/', '', $raw );
        if ( strlen( $digits ) >= 4 ) {
            return substr( $digits, -4 );
        }

        return '';
    }

    /**
     * Normalize a delivery/pickup date string for Giorgio API (YYYY-MM-DD).
     * OC Woo Shipping stores display dates as d/m/Y and sortable as Y/m/d.
     *
     * @param string $date Raw date from order meta or OCWS.
     * @return string Normalized date or original string if parsing fails.
     */
    protected function normalize_shipping_info_date_for_storeos_api( $date ) {
        $date = trim( (string) $date );
        if ( '' === $date ) {
            return '';
        }
        if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return $date;
        }
        if ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date, $m ) ) {
            return sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1] );
        }
        if ( preg_match( '/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $date, $m ) ) {
            return sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
        }
        $ts = strtotime( $date );
        if ( $ts ) {
            return gmdate( 'Y-m-d', $ts );
        }

        return $date;
    }

    /**
     * Read OC Woo Shipping slot / pickup data saved only on shipping line items (serialized blobs).
     *
     * @param WC_Order $order Order object.
     * @return array{has_pickup:bool,has_delivery:bool,date:string,slot_start:string,slot_end:string,pickup_id:string,pickup_name:string}
     */
    protected function extract_ocws_shipping_info_from_order_line_items( $order ) {
        $result = array(
            'has_pickup'   => false,
            'has_delivery' => false,
            'date'         => '',
            'slot_start'   => '',
            'slot_end'     => '',
            'pickup_id'    => '',
            'pickup_name'  => '',
        );
        if ( ! $order instanceof WC_Order ) {
            return $result;
        }
        foreach ( $order->get_items( 'shipping' ) as $item ) {
            if ( ! $item instanceof WC_Order_Item_Shipping ) {
                continue;
            }
            $pickup_raw = $item->get_meta( 'ocws_lp_pickup_info', true );
            if ( $pickup_raw ) {
                $pickup = is_array( $pickup_raw ) ? $pickup_raw : maybe_unserialize( (string) $pickup_raw );
                if ( is_array( $pickup ) ) {
                    $result['has_pickup'] = true;
                    if ( '' === $result['date'] && ! empty( $pickup['date'] ) ) {
                        $result['date'] = sanitize_text_field( (string) $pickup['date'] );
                    }
                    if ( '' === $result['slot_start'] && isset( $pickup['slot_start'] ) && '' !== (string) $pickup['slot_start'] ) {
                        $result['slot_start'] = sanitize_text_field( (string) $pickup['slot_start'] );
                    }
                    if ( '' === $result['slot_end'] && isset( $pickup['slot_end'] ) && '' !== (string) $pickup['slot_end'] ) {
                        $result['slot_end'] = sanitize_text_field( (string) $pickup['slot_end'] );
                    }
                    if ( '' === $result['pickup_id'] && isset( $pickup['aff_id'] ) && '' !== (string) $pickup['aff_id'] ) {
                        $result['pickup_id'] = sanitize_text_field( (string) $pickup['aff_id'] );
                    }
                    if ( '' === $result['pickup_name'] && ! empty( $pickup['aff_name'] ) ) {
                        $result['pickup_name'] = sanitize_text_field( (string) $pickup['aff_name'] );
                    }
                }
            }
            $ship_raw = $item->get_meta( 'ocws_shipping_info', true );
            if ( $ship_raw ) {
                $ship = is_array( $ship_raw ) ? $ship_raw : maybe_unserialize( (string) $ship_raw );
                if ( is_array( $ship ) && ! empty( $ship['date'] ) ) {
                    $result['has_delivery'] = true;
                    if ( '' === $result['date'] ) {
                        $result['date'] = sanitize_text_field( (string) $ship['date'] );
                    }
                    if ( '' === $result['slot_start'] && isset( $ship['slot_start'] ) && '' !== (string) $ship['slot_start'] ) {
                        $result['slot_start'] = sanitize_text_field( (string) $ship['slot_start'] );
                    }
                    if ( '' === $result['slot_end'] && isset( $ship['slot_end'] ) && '' !== (string) $ship['slot_end'] ) {
                        $result['slot_end'] = sanitize_text_field( (string) $ship['slot_end'] );
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Extract normalized shipping info from OC Giorgio meta on the order.
     *
     * @param WC_Order $order Order object.
     * @return array
     */
    protected function get_order_shipping_info_meta( $order ) {
        if ( ! $order instanceof WC_Order ) {
            return array();
        }
        $type         = $order->get_meta( '_oc_storeos_shipping_type', true );
        $date         = $order->get_meta( '_oc_storeos_delivery_date', true );
        $slot_start   = $order->get_meta( '_oc_storeos_delivery_slot_start', true );
        $slot_end     = $order->get_meta( '_oc_storeos_delivery_slot_end', true );
        $pickup_id    = $order->get_meta( '_oc_storeos_pickup_aff_id', true );
        $pickup_name  = $order->get_meta( '_oc_storeos_pickup_aff_name', true );
        // Fallback for regular site orders: use OC Woo Shipping meta.
        if ( '' === (string) $date ) {
            $date = $order->get_meta( 'ocws_shipping_info_date', true );
        }
        if ( '' === (string) $date ) {
            $date = $order->get_meta( 'ocws_lp_pickup_date', true );
        }
        if ( '' === (string) $slot_start ) {
            $slot_start = $order->get_meta( 'ocws_shipping_info_slot_start', true );
        }
        if ( '' === (string) $slot_start ) {
            $slot_start = $order->get_meta( 'ocws_lp_pickup_slot_start', true );
        }
        if ( '' === (string) $slot_end ) {
            $slot_end = $order->get_meta( 'ocws_shipping_info_slot_end', true );
        }
        if ( '' === (string) $slot_end ) {
            $slot_end = $order->get_meta( 'ocws_lp_pickup_slot_end', true );
        }

        if ( '' === (string) $type ) {
            $tag = $order->get_meta( 'ocws_shipping_tag', true );
            if ( class_exists( 'OCWS_LP_Local_Pickup' ) && defined( 'OCWS_LP_Local_Pickup::PICKUP_METHOD_TAG' ) && (string) $tag === OCWS_LP_Local_Pickup::PICKUP_METHOD_TAG ) {
                $type = 'pickup';
            } elseif ( class_exists( 'OCWS_Advanced_Shipping' ) && defined( 'OCWS_Advanced_Shipping::SHIPPING_METHOD_TAG' ) && (string) $tag === OCWS_Advanced_Shipping::SHIPPING_METHOD_TAG ) {
                $type = 'delivery';
            } elseif ( 'pickup' === (string) $tag ) {
                $type = 'pickup';
            } elseif ( 'shipping' === (string) $tag ) {
                $type = 'delivery';
            }
        }

        if ( '' === (string) $pickup_id ) {
            $pickup_id = $order->get_meta( 'ocws_lp_pickup_aff_id', true );
        }
        if ( '' === (string) $pickup_name ) {
            $pickup_name = $order->get_meta( 'ocws_lp_pickup_aff_name', true );
        }

        $from_lines = $this->extract_ocws_shipping_info_from_order_line_items( $order );
        if ( '' === (string) $date && '' !== (string) $from_lines['date'] ) {
            $date = $from_lines['date'];
        }
        if ( '' === (string) $slot_start && '' !== (string) $from_lines['slot_start'] ) {
            $slot_start = $from_lines['slot_start'];
        }
        if ( '' === (string) $slot_end && '' !== (string) $from_lines['slot_end'] ) {
            $slot_end = $from_lines['slot_end'];
        }
        if ( '' === (string) $pickup_id && '' !== (string) $from_lines['pickup_id'] ) {
            $pickup_id = $from_lines['pickup_id'];
        }
        if ( '' === (string) $pickup_name && '' !== (string) $from_lines['pickup_name'] ) {
            $pickup_name = $from_lines['pickup_name'];
        }
        if ( '' === (string) $type ) {
            if ( ! empty( $from_lines['has_pickup'] ) ) {
                $type = 'pickup';
            } elseif ( ! empty( $from_lines['has_delivery'] ) ) {
                $type = 'delivery';
            }
        }

        if (
            '' === (string) $type &&
            '' === (string) $date &&
            '' === (string) $slot_start &&
            '' === (string) $slot_end &&
            '' === (string) $pickup_id &&
            '' === (string) $pickup_name
        ) {
            return array();
        }

        $info = array();

        if ( '' !== (string) $type ) {
            $info['type'] = $type;
        }
        if ( '' !== (string) $date ) {
            $info['date'] = $this->normalize_shipping_info_date_for_storeos_api( $date );
        }

        if ( '' !== (string) $slot_start ) {
            $info['slotStart'] = $slot_start;
        }
        if ( '' !== (string) $slot_end ) {
            $info['slotEnd'] = $slot_end;
        }
        if ( '' !== (string) $pickup_id ) {
            $info['pickupAffiliateId'] = $pickup_id;
        }
        if ( '' !== (string) $pickup_name ) {
            $info['pickupAffiliateName'] = $pickup_name;
        }
        return $info;
    }

    /**
     * Resolve shipping label from admin mapping by shipping method ID.
     *
     * @param WC_Order $order   Order object.
     * @param array    $options Plugin options.
     *
     * @return string
     */
    protected function resolve_shipping_label_for_payload( $order, $options ) {
        if ( ! $order instanceof WC_Order ) {
            return '';
        }

        $raw_map = isset( $options['shipping_method_label_map'] ) ? $options['shipping_method_label_map'] : array();
        $map     = array();

        if ( is_array( $raw_map ) ) {
            foreach ( $raw_map as $method_id => $label ) {
                $method_id = trim( (string) $method_id );
                $label     = trim( (string) $label );
                if ( '' !== $method_id && '' !== $label ) {
                    $map[ $method_id ] = $label;
                }
            }
        } elseif ( is_string( $raw_map ) ) {
            // Backward compatibility for old text format.
            $lines = preg_split( '/\r\n|\r|\n/', $raw_map );
            if ( is_array( $lines ) ) {
                foreach ( $lines as $line ) {
                    $line = trim( (string) $line );
                    if ( '' === $line ) {
                        continue;
                    }
                    $chunks = explode( '|', $line, 2 );
                    if ( count( $chunks ) < 2 ) {
                        continue;
                    }
                    $method_id = trim( $chunks[0] );
                    $label     = trim( $chunks[1] );
                    if ( '' !== $method_id && '' !== $label ) {
                        $map[ $method_id ] = $label;
                    }
                }
            }
        }

        foreach ( $order->get_shipping_methods() as $shipping_item ) {
            if ( ! $shipping_item instanceof WC_Order_Item_Shipping ) {
                continue;
            }

            // Woo usually stores method_id + instance_id (e.g. flat_rate + 5).
            $method_id  = (string) $shipping_item->get_method_id();
            $instance_id = (string) $shipping_item->get_instance_id();
            $full_id    = $method_id . ( '' !== $instance_id ? ':' . $instance_id : '' );

            if ( isset( $map[ $full_id ] ) ) {
                return $map[ $full_id ];
            }
            if ( isset( $map[ $method_id ] ) ) {
                return $map[ $method_id ];
            }

            // Fallback: send the current shipping label from the order itself.
            $current_label = '';
            if ( method_exists( $shipping_item, 'get_method_title' ) ) {
                $current_label = trim( (string) $shipping_item->get_method_title() );
            }
            if ( '' === $current_label ) {
                $current_label = trim( (string) $shipping_item->get_name() );
            }
            if ( '' !== $current_label ) {
                return $current_label;
            }
        }

        return '';
    }

    /**
     * Resolve payment label from admin mapping by payment method ID.
     *
     * @param WC_Order $order   Order object.
     * @param array    $options Plugin options.
     *
     * @return string
     */
    protected function resolve_payment_label_for_payload( $order, $options ) {
        if ( ! $order instanceof WC_Order ) {
            return '';
        }

        $raw_map = isset( $options['payment_method_label_map'] ) ? $options['payment_method_label_map'] : array();
        $map     = array();

        if ( is_array( $raw_map ) ) {
            foreach ( $raw_map as $method_id => $label ) {
                $method_id = trim( (string) $method_id );
                $label     = trim( (string) $label );
                if ( '' !== $method_id && '' !== $label ) {
                    $map[ $method_id ] = $label;
                }
            }
        } elseif ( is_string( $raw_map ) ) {
            $lines = preg_split( '/\r\n|\r|\n/', $raw_map );
            if ( is_array( $lines ) ) {
                foreach ( $lines as $line ) {
                    $line = trim( (string) $line );
                    if ( '' === $line ) {
                        continue;
                    }
                    $chunks = explode( '|', $line, 2 );
                    if ( count( $chunks ) < 2 ) {
                        continue;
                    }
                    $method_id = trim( $chunks[0] );
                    $label     = trim( $chunks[1] );
                    if ( '' !== $method_id && '' !== $label ) {
                        $map[ $method_id ] = $label;
                    }
                }
            }
        }

        $method_id = trim( (string) $order->get_payment_method() );
        if ( '' !== $method_id && isset( $map[ $method_id ] ) ) {
            return $map[ $method_id ];
        }

        $current_label = trim( (string) $order->get_payment_method_title() );
        if ( '' !== $current_label ) {
            return $current_label;
        }

        return $method_id;
    }

    /**
     * Send order payload to external API (create/update order).
     *
     * @param WC_Order $order   Order object.
     * @param array    $payload Payload array.
     * @param array    $options Plugin options.
     *
     * @return array Keys: success, http_status, body, error.
     */
    protected function send_order_to_api( $order, $payload, $options ) {
        $endpoint = trailingslashit( $options['api_base_url'] ) . 'WooCommerce/Order';

        $this->maybe_debug_log( sprintf( '[OC Giorgio] Outgoing Order: send_order_to_api start. order_id=%d endpoint=%s', (int) $order->get_id(), $endpoint ) );

        // Log outgoing payload (trimmed) for debugging.
        $payload_json_for_log = wp_json_encode( $payload );
        if ( false === $payload_json_for_log ) {
            $payload_json_for_log = '';
        }
        $this->oc_storeos_wc_log(
            'info',
            sprintf(
                'Outgoing Order: POST %s order_id=%d payload_bytes=%d',
                $endpoint,
                (int) $order->get_id(),
                strlen( (string) $payload_json_for_log )
            ),
            array(
                'order_id'     => (int) $order->get_id(),
                'payload_json' => substr( (string) $payload_json_for_log, 0, 20000 ),
            )
        );

        // Full outgoing payload to the dedicated outgoing log (mirrors the incoming payload log).
        $this->log_rest_outgoing_order(
            array(
                'time_utc' => gmdate( 'c' ),
                'result'   => 'outgoing_payload',
                'endpoint' => $endpoint,
                'order_id' => (int) $order->get_id(),
                'payload'  => $payload,
            )
        );

        // Optional debug copy of the outgoing payload by email (OFF by default). This is the first of
        // two emails per credit order: AUTHORIZATION (J5 hold, sent here) and CAPTURE (the OrderPayment
        // call, sent from send_order_payment_webhook_v2_request).
        $this->send_outgoing_debug_email(
            sprintf( 'Giorgio — AUTHORIZATION (order %d)', (int) $order->get_id() ),
            "=== STAGE: AUTHORIZATION (J5 hold) — POST /WooCommerce/Order ===\n\n"
                . "PAYLOAD SENT:\n" . (string) $payload_json_for_log
        );

        $args = array(
            'method'      => 'POST',
            'timeout'     => 20,
            'headers'     => array(
                // Either header is accepted by the external API. We prefer X-Api-Key as per docs.
                'X-Api-Key'     => $options['api_token'],
                'Authorization' => 'Bearer ' . $options['api_token'],
                'Content-Type'  => 'application/json',
            ),
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
        );

        $this->maybe_debug_log( sprintf( '[OC Giorgio] Outgoing Order: wp_remote_post start. order_id=%d', (int) $order->get_id() ) );
        $response = wp_remote_post( $endpoint, $args );
        if ( is_wp_error( $response ) ) {
            $this->maybe_debug_log( sprintf( '[OC Giorgio] Outgoing Order: wp_remote_post WP_Error. order_id=%d err=%s', (int) $order->get_id(), $response->get_error_message() ) );
            $this->log_order_error( $order->get_id(), $response->get_error_message() );
            return array(
                'success'     => false,
                'http_status' => null,
                'body'        => null,
                'error'       => $response->get_error_message(),
            );
        }

        $code     = (int) wp_remote_retrieve_response_code( $response );
        $body_raw = wp_remote_retrieve_body( $response );
        $this->maybe_debug_log( sprintf( '[OC Giorgio] Outgoing Order: wp_remote_post response. order_id=%d http=%d body_prefix=%s', (int) $order->get_id(), (int) $code, substr( (string) $body_raw, 0, 250 ) ) );
        $decoded  = json_decode( $body_raw, true );
        $body     = ( JSON_ERROR_NONE === json_last_error() && null !== $decoded ) ? $decoded : $body_raw;

        $this->log_rest_outgoing_order(
            array(
                'time_utc'  => gmdate( 'c' ),
                'result'    => 'outgoing_response',
                'order_id'  => (int) $order->get_id(),
                'http'      => (int) $code,
                'response'  => is_array( $decoded ) ? $decoded : substr( (string) $body_raw, 0, 4000 ),
            )
        );

        $http_ok = ( $code >= 200 && $code < 300 );
        $success = $http_ok;

        // Prefer Giorgio logical success when the API returns it (common response shape: { isSuccessful, data: { id } }).
        if ( $http_ok && is_array( $decoded ) ) {
            if ( array_key_exists( 'isSuccessful', $decoded ) ) {
                $success = ( true === $decoded['isSuccessful'] );
            }

            // If Giorgio returned a data.id, treat missing/invalid id as not-synced.
            if ( $success && isset( $decoded['data'] ) && is_array( $decoded['data'] ) ) {
                if ( array_key_exists( 'id', $decoded['data'] ) ) {
                    $success = ( is_numeric( $decoded['data']['id'] ) && (int) $decoded['data']['id'] > 0 );
                }
            }
        }

        if ( $success ) {
            $this->mark_order_synced( $order->get_id(), $code, $body_raw );
        } else {
            $err_msg = 'HTTP ' . $code . ' - ' . $body_raw;
            if ( is_array( $decoded ) ) {
                if ( isset( $decoded['statusMessage'] ) && is_string( $decoded['statusMessage'] ) && '' !== trim( $decoded['statusMessage'] ) ) {
                    $err_msg = 'HTTP ' . $code . ' - ' . trim( $decoded['statusMessage'] );
                } elseif ( isset( $decoded['displayMessage'] ) && is_string( $decoded['displayMessage'] ) && '' !== trim( $decoded['displayMessage'] ) ) {
                    $err_msg = 'HTTP ' . $code . ' - ' . trim( $decoded['displayMessage'] );
                }
            }
            $this->log_order_error( $order->get_id(), $err_msg );
        }

        return array(
            'success'     => $success,
            'http_status' => $code,
            'body'        => $body,
            'error'       => null,
        );
    }

    /**
     * First non-empty string from ASP.NET-style validation errors (arrays of messages per field).
     *
     * @param array $errors Associative array of string lists.
     * @return string
     */
    protected function first_string_in_nested_lists( $errors ) {
        if ( ! is_array( $errors ) ) {
            return '';
        }
        foreach ( $errors as $messages ) {
            if ( ! is_array( $messages ) ) {
                continue;
            }
            foreach ( $messages as $msg ) {
                if ( is_string( $msg ) && '' !== $msg ) {
                    return $msg;
                }
            }
        }
        return '';
    }

    /**
     * Write to WooCommerce logger (wp-content/uploads/wc-logs/ when logging enabled).
     *
     * @param string               $level   WC_Log_Levels value, e.g. info, notice, warning, error, debug.
     * @param string               $message Human-readable message (no API secrets).
     * @param array<string, mixed> $context Extra fields merged into log context.
     */
    protected function oc_storeos_wc_log( $level, $message, array $context = array() ) {
        if ( ! function_exists( 'wc_get_logger' ) ) {
            return;
        }

        $logger = wc_get_logger();
        $ctx    = array_merge(
            array( 'source' => self::WC_LOG_SOURCE ),
            $context
        );
        $logger->log( $level, '[OC Giorgio] ' . $message, $ctx );
    }

    /**
     * Verbose debug line to the PHP error log — only when debugging is on.
     * Enabled by the `debug_logging` option, by WP_DEBUG, or via the
     * `oc_storeos_debug_logging` filter. Keeps production logs quiet by default.
     *
     * @param string $message Message to log.
     */
    protected function maybe_debug_log( $message ) {
        $options = $this->get_options();
        $on      = ! empty( $options['debug_logging'] )
            || ( defined( 'WP_DEBUG' ) && WP_DEBUG );
        $on      = (bool) apply_filters( 'oc_storeos_debug_logging', $on );
        if ( ! $on ) {
            return;
        }
        \error_log( (string) $message );
    }

    /**
     * Send a debug copy of an outgoing payload/response by email, if enabled.
     * OFF by default (option `debug_outgoing_email`); recipient is configurable and defaults to the
     * site admin email — never hardcoded. Body is plain text and size-capped.
     *
     * @param string $subject Email subject.
     * @param string $body    Plain-text body (payload and/or response).
     */
    protected function send_outgoing_debug_email( $subject, $body ) {
        $opts = $this->get_options();
        if ( empty( $opts['debug_outgoing_email'] ) || ! function_exists( 'wp_mail' ) ) {
            return;
        }
        $recipient = ( ! empty( $opts['debug_email_recipient'] ) && is_email( $opts['debug_email_recipient'] ) )
            ? (string) $opts['debug_email_recipient']
            : (string) get_option( 'admin_email' );
        if ( '' === $recipient || ! is_email( $recipient ) ) {
            return;
        }
        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );
        $ok      = wp_mail( $recipient, (string) $subject, substr( (string) $body, 0, 40000 ), $headers );
        $this->maybe_debug_log( sprintf( '[OC Giorgio] debug email "%s" sent=%s', (string) $subject, $ok ? 'yes' : 'no' ) );
    }

    /**
     * WooCommerce: after payment is completed — send OrderPayment webhook (v2) to Giorgio.
     *
     * @param int $order_id Order ID.
     */
    public function handle_payment_complete_webhook_v2( $order_id ) {
        $options = $this->get_options();
        if ( empty( $options['send_order_payment_webhook_on_charge'] ) ) {
            $this->oc_storeos_wc_log(
                'info',
                sprintf( 'OrderPayment v2: skipped (payment_complete) — option send_order_payment_webhook_on_charge is off. order_id=%d', (int) $order_id ),
                array( 'order_id' => (int) $order_id )
            );
            return;
        }

        $this->oc_storeos_wc_log(
            'info',
            sprintf( 'OrderPayment v2: hook payment_complete, order_id=%d', (int) $order_id ),
            array( 'order_id' => (int) $order_id )
        );

        $order = wc_get_order( $order_id );
        $this->maybe_send_order_payment_webhook_v2( $order );
    }

    /**
     * WooCommerce: when order becomes completed — send OrderPayment webhook (v2) to Giorgio.
     *
     * @param int        $order_id   Order ID.
     * @param string     $old_status Previous status.
     * @param string     $new_status New status.
     * @param WC_Order|mixed $order  Order instance when available.
     */
    public function handle_order_completed_payment_webhook_v2( $order_id, $old_status, $new_status, $order ) {
        if ( 'completed' !== $new_status ) {
            return;
        }

        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }

        $this->oc_storeos_wc_log(
            'info',
            sprintf(
                'OrderPayment v2: hook order_status_changed → completed. order_id=%d old=%s new=%s payment_method=%s needs_payment=%s',
                (int) $order_id,
                (string) $old_status,
                (string) $new_status,
                $order instanceof WC_Order ? (string) $order->get_payment_method() : '',
                $order instanceof WC_Order && method_exists( $order, 'needs_payment' ) ? ( $order->needs_payment() ? 'yes' : 'no' ) : ''
            ),
            array(
                'order_id' => (int) $order_id,
                'old_status' => (string) $old_status,
                'new_status' => (string) $new_status,
            )
        );

        $this->maybe_send_order_payment_webhook_v2( $order );
    }

    /**
     * Build payload and POST to WooCommerce/OrderPayment (new format).
     *
     * @param WC_Order|null $order Order.
     */
    protected function maybe_send_order_payment_webhook_v2( $order ) {
        if ( ! $order instanceof WC_Order ) {
            $this->oc_storeos_wc_log(
                'notice',
                'OrderPayment v2: aborted — invalid order object.',
                array()
            );
            return;
        }

        $order_id = $order->get_id();

        if ( ! empty( self::$payment_webhook_v2_dispatching[ $order_id ] ) ) {
            $this->oc_storeos_wc_log(
                'info',
                sprintf( 'OrderPayment v2: nested call skipped (re-entrancy guard). order_id=%d', (int) $order_id ),
                array( 'order_id' => (int) $order_id )
            );
            return;
        }

        self::$payment_webhook_v2_dispatching[ $order_id ] = true;

        try {
            $order = wc_get_order( $order_id );
            if ( ! $order instanceof WC_Order ) {
                $this->oc_storeos_wc_log(
                    'warning',
                    sprintf( 'OrderPayment v2: aborted — wc_get_order returned empty. order_id=%d', (int) $order_id ),
                    array( 'order_id' => (int) $order_id )
                );
                return;
            }

            $options = $this->get_options();
            if ( empty( $options['api_base_url'] ) || empty( $options['api_token'] ) ) {
                $this->oc_storeos_wc_log(
                    'warning',
                    sprintf(
                        'OrderPayment v2: not sending — missing api_base_url or api_token. order_id=%d has_base=%s has_token=%s',
                        (int) $order_id,
                        ! empty( $options['api_base_url'] ) ? 'yes' : 'no',
                        ! empty( $options['api_token'] ) ? 'yes' : 'no'
                    ),
                    array( 'order_id' => (int) $order_id )
                );
                return;
            }
            $profile      = $this->resolve_storeos_payment_gateway_profile( $order );
            $payload      = $this->build_order_payment_webhook_v2_payload( $order );
            $payload_hash = md5( wp_json_encode( $payload ) );

            $this->oc_storeos_wc_log(
                'info',
                sprintf(
                    'OrderPayment v2: ready to send. order_id=%d profile=%s payment=%s wc_txn=%s cardcom_meta=%s payload_status=%s',
                    (int) $order_id,
                    $profile,
                    $order->get_payment_method_title() . ' / ' . $order->get_payment_method(),
                    (string) $order->get_transaction_id(),
                    (string) $order->get_meta( self::META_CARDCOM_PAYMENT_ID, true ),
                    isset( $payload['status'] ) ? (string) $payload['status'] : ''
                ),
                array(
                    'order_id' => (int) $order_id,
                    'profile' => $profile,
                    'payload_keys' => implode( ',', array_keys( $payload ) ),
                )
            );

            if ( isset( self::$payment_webhook_v2_ok_payload_hash[ $order_id ] )
                && self::$payment_webhook_v2_ok_payload_hash[ $order_id ] === $payload_hash ) {
                $this->oc_storeos_wc_log(
                    'info',
                    sprintf( 'OrderPayment v2: skip duplicate payload hash (already sent OK this request). order_id=%d', (int) $order_id ),
                    array( 'order_id' => (int) $order_id )
                );
                return;
            }

            $this->send_order_payment_webhook_v2_request( $order, $payload, $options );
        } finally {
            unset( self::$payment_webhook_v2_dispatching[ $order_id ] );
        }
    }

    /**
     * Cardcom payment profile for Giorgio OrderPayment (gateway id and/or Cardcom deal meta on the order).
     *
     * @param WC_Order $order Order.
     * @return string 'cardcom'|'unknown'
     */
    protected function resolve_storeos_payment_gateway_profile( WC_Order $order ) {
        $method        = (string) $order->get_payment_method();
        $cardcom_deal  = trim( (string) $order->get_meta( self::META_CARDCOM_PAYMENT_ID, true ) );
        $cardcom_ready = class_exists( 'WC_Gateway_Cardcom' );

        if ( self::GATEWAY_CARDCOM === $method && $cardcom_ready ) {
            $profile = 'cardcom';
        } elseif ( '' !== $cardcom_deal ) {
            // Deal meta (label "Cardcom Payment ID") even if gateway class loads later or slug differs.
            $profile = 'cardcom';
        } else {
            $profile = 'unknown';
        }

        return (string) apply_filters( 'oc_storeos_payment_gateway_profile', $profile, $order );
    }

    /**
     * Cardcom invoice / document number from order meta (woo-cardcom-payment-gateway).
     *
     * @param WC_Order $order Order.
     * @return string Trimmed non-empty string, or ''.
     */
    protected function get_cardcom_invoice_number_for_order_payment( WC_Order $order ) {
        if ( ! $order instanceof WC_Order ) {
            return '';
        }
        $keys = array( 'initial_document_no', 'InvoiceNumber', '_invoice_number' );
        foreach ( $keys as $key ) {
            $raw = $order->get_meta( $key, true );
            if ( '' === $raw || null === $raw ) {
                continue;
            }
            $value = trim( (string) $raw );
            if ( '' === $value || '0' === $value ) {
                continue;
            }
            return $value;
        }
        return '';
    }

    /**
     * OrderPayment v2 body: Cardcom transaction id from order meta {@see META_CARDCOM_PAYMENT_ID};
     * optional {@see get_cardcom_invoice_number_for_order_payment} inside payment;
     * {@see resolve_payment_label_for_payload} as payment.paymentGateway (same as outgoing order paymentlabel).
     *
     * @param WC_Order $order Order.
     *
     * @return array
     */
    protected function build_order_payment_webhook_v2_payload( WC_Order $order ) {
        $profile         = $this->resolve_storeos_payment_gateway_profile( $order );
        $options         = $this->get_options();
        $payment_gateway = $this->resolve_payment_label_for_payload( $order, $options );

        if ( 'cardcom' === $profile ) {
            $transaction_id = trim( (string) $order->get_meta( self::META_CARDCOM_PAYMENT_ID, true ) );
            if ( '' === $transaction_id ) {
                $transaction_id = trim( (string) $order->get_meta( self::META_CARDCOM_INTERNAL_DEAL_NUMBER, true ) );
            }
            $status = ( '' !== $transaction_id ) ? 'success' : 'failed';

            $payload = array(
                'orderId' => (int) $order->get_id(),
                'status'  => $status,
            );

            if ( 'success' === $status ) {
                $total_amount = (float) $order->get_total();
                $invoice_no   = $this->get_cardcom_invoice_number_for_order_payment( $order );

                $payment_block = array(
                    'transactionId'    => $transaction_id,
                    'paymentGateway'   => 'cardcom',
                    'amount'           => $total_amount,
                    'invoiceNumber'    => '' !== $invoice_no ? $invoice_no : null,
                );

                foreach ( $payment_block as $k => $v ) {
                    if ( null === $v ) {
                        unset( $payment_block[ $k ] );
                    }
                }

                $payload['payment'] = $payment_block;
                $payload['gatewayPaymentStatus'] = 'authorized';
                $payload['gatewayIsFinished']    = 'false';
                // Giorgio: mark final charge (likiut) as finished outside the payment object.
                $payload['isFinished'] = ( 'completed' === (string) $order->get_status() ) ? 'true' : 'false';
            }

            return $this->apply_order_payment_webhook_v2_common_fields( $order, $payload );
        }

        $payload = array(
            'orderId' => (int) $order->get_id(),
            'status'  => 'failed',
        );
        if ( '' !== $payment_gateway ) {
            $payload['payment'] = array(
                'paymentGateway' => $payment_gateway,
            );
        }
        return $this->apply_order_payment_webhook_v2_common_fields( $order, $payload );
    } 

    /**
     * POST payment webhook v2 to Giorgio (does not use order sync meta / notes).
     *
     * @param WC_Order $order   Order.
     * @param array    $payload JSON body.
     * @param array    $options Plugin options.
     */
    protected function send_order_payment_webhook_v2_request( WC_Order $order, array $payload, array $options ) {
        $endpoint = trailingslashit( $options['api_base_url'] ) . 'WooCommerce/OrderPayment';
        $oid      = (int) $order->get_id();

        $payload_json = wp_json_encode( $payload ); 
        if ( false === $payload_json ) {
            $payload_json = ''; 
        }

        $this->oc_storeos_wc_log(
            'info',
            sprintf(
                'OrderPayment v2: POST %s order_id=%d payload_status=%s',
                $endpoint,
                $oid,
                isset( $payload['status'] ) ? (string) $payload['status'] : ''
            ),
            array(
                'orderId'     => $oid,
                'payloadJson' => $payload_json,
            )
        );

        // Full OrderPayment (capture-stage) payload to the dedicated outgoing log.
        $this->log_rest_outgoing_order(
            array(
                'time_utc' => gmdate( 'c' ),
                'result'   => 'outgoing_payment_payload',
                'endpoint' => $endpoint,
                'order_id' => $oid,
                'payload'  => $payload,
            )
        );

        $args = array(
            'method'      => 'POST',
            'timeout'     => 20,
            'headers'     => array(
                'Content-Type' => 'application/json',
                'X-Api-Key'    => $options['api_token'],
                // Some Giorgio endpoints require Bearer auth in addition to X-Api-Key.
            ),
            'body'        => $payload_json,
            'data_format' => 'body',
        );

        // Debug logs (plain PHP error_log): show request details with redacted API key.
        $headers_for_log = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
        if ( isset( $headers_for_log['X-Api-Key'] ) ) {
            $headers_for_log['X-Api-Key'] = '***redacted***';
        }
        if ( isset( $headers_for_log['Authorization'] ) ) {
            $headers_for_log['Authorization'] = '***redacted***';
        }
        $this->maybe_debug_log(
            sprintf(
                '[OC Giorgio] OrderPayment v2 request: order_id=%d endpoint=%s headers=%s body_prefix=%s',
                $oid,
                $endpoint,
                wp_json_encode( $headers_for_log ),
                substr( (string) $payload_json, 0, 2000 )
            )
        );

        $response = wp_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            $this->maybe_debug_log( sprintf( '[OC Giorgio] OrderPayment v2 response: WP_Error. order_id=%d err=%s', $oid, $response->get_error_message() ) );
            $this->oc_storeos_wc_log(
                'error',
                sprintf( 'OrderPayment v2: HTTP transport error. order_id=%d err=%s', $oid, $response->get_error_message() ),
                array( 'order_id' => $oid )
            );
            $this->send_outgoing_debug_email(
                sprintf( 'Giorgio — CAPTURE (order %d) — TRANSPORT ERROR', $oid ),
                "=== STAGE: CAPTURE — POST /WooCommerce/OrderPayment ===\n\n"
                    . "PAYLOAD SENT:\n" . (string) $payload_json . "\n\n"
                    . "TRANSPORT ERROR:\n" . $response->get_error_message()
            );
            $this->log_payment_webhook_v2_error( $order->get_id(), $response->get_error_message() );
            return;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $resp_body = wp_remote_retrieve_body( $response );
        $resp_decoded = json_decode( (string) $resp_body, true );
        $this->log_rest_outgoing_order(
            array(
                'time_utc' => gmdate( 'c' ),
                'result'   => 'outgoing_payment_response',
                'order_id' => $oid,
                'http'     => $code,
                'response' => is_array( $resp_decoded ) ? $resp_decoded : substr( (string) $resp_body, 0, 4000 ),
            )
        );
        // Second of the two debug emails per credit order: CAPTURE (payload + Giorgio's response).
        $this->send_outgoing_debug_email(
            sprintf( 'Giorgio — CAPTURE (order %d) — HTTP %d', $oid, $code ),
            "=== STAGE: CAPTURE — POST /WooCommerce/OrderPayment ===\n\n"
                . "PAYLOAD SENT:\n" . (string) $payload_json . "\n\n"
                . "GIORGIO RESPONSE (HTTP " . $code . "):\n" . (string) $resp_body
        );
        $resp_headers = function_exists( 'wp_remote_retrieve_headers' ) ? wp_remote_retrieve_headers( $response ) : null;
        $resp_headers_arr = is_object( $resp_headers ) && method_exists( $resp_headers, 'getAll' ) ? $resp_headers->getAll() : ( is_array( $resp_headers ) ? $resp_headers : array() );
        $this->maybe_debug_log(
            sprintf(
                '[OC Giorgio] OrderPayment v2 response: order_id=%d http=%d headers=%s body_prefix=%s',
                $oid,
                $code,
                wp_json_encode( $resp_headers_arr ),
                substr( (string) $resp_body, 0, 2000 )
            )
        );
        if ( $code >= 200 && $code < 300 ) {
            $this->oc_storeos_wc_log(
                'info',
                sprintf( 'OrderPayment v2: remote OK HTTP %d. order_id=%d', $code, $oid ),
                array( 'order_id' => $oid )
            );
            $this->mark_payment_webhook_v2_ok( $order->get_id() );
            self::$payment_webhook_v2_ok_payload_hash[ $order->get_id() ] = md5( wp_json_encode( $payload ) );

            $order_note = wc_get_order( $oid );
            if ( $order_note instanceof WC_Order ) {
                $reported = isset( $payload['status'] ) ? (string) $payload['status'] : '';
                $order_note->add_order_note(
                    sprintf(
                    /* translators: 1: HTTP status code, 2: payload status (e.g. success/failed). */
                        __( 'Giorgio: עדכון תשלום (OrderPayment) נשלח חזרה למערכת והתקבל בהצלחה (HTTP %1$d, סטטוס בדיווח: %2$s).', 'oc-storeos-integration' ),                        $code,
                        '' !== $reported ? $reported : '—'
                    ),
                    false,
                    false
                );
            }

            return;
        }

        $body = $resp_body;
        $this->oc_storeos_wc_log(
            'error',
            sprintf( 'OrderPayment v2: remote error HTTP %d. order_id=%d body=%s', $code, $oid, substr( $body, 0, 500 ) ),
            array( 'order_id' => $oid )
        );
        $this->log_payment_webhook_v2_error( $order->get_id(), 'HTTP ' . $code . ' — ' . $body );
    }

    /**
     * @param int    $order_id Order ID.
     * @param string $message  Error message.
     */
    protected function log_payment_webhook_v2_error( $order_id, $message ) {
        $this->set_order_meta_safe( $order_id, array(
            '_oc_storeos_payment_webhook_v2_error' => $message,
            '_oc_storeos_payment_webhook_v2_at'    => current_time( 'mysql' ),
        ) );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $this->maybe_debug_log( sprintf( 'OC Giorgio OrderPayment v2 webhook error (order %d): %s', $order_id, $message ) );
        }
    }

    /**
     * @param int $order_id Order ID.
     */
    protected function mark_payment_webhook_v2_ok( $order_id ) {
        $this->set_order_meta_safe( $order_id, array(
            '_oc_storeos_payment_webhook_v2_error' => '',
            '_oc_storeos_payment_webhook_v2_at'    => current_time( 'mysql' ),
        ) );
    }

    /**
     * HPOS-safe order meta write. Persists meta via the order object so it works under both legacy
     * post storage and custom order tables. Uses save_meta_data() (not save()) so it does not re-fire
     * order-save hooks — important to avoid recursion with the woocommerce_update_order trigger.
     *
     * @param int                  $order_id Order ID.
     * @param array<string, mixed> $meta     key => value pairs.
     * @return bool True when saved.
     */
    protected function set_order_meta_safe( $order_id, array $meta ) {
        $order = wc_get_order( (int) $order_id );
        if ( ! $order instanceof WC_Order ) {
            return false;
        }
        foreach ( $meta as $key => $value ) {
            $order->update_meta_data( (string) $key, $value );
        }
        $order->save_meta_data();
        return true;
    }

    /**
     * Handle payment complete event (API 2: OrderPayment).
     *
     * @param int $order_id Order ID.
     */
    public function handle_payment_complete( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $options = $this->get_options();
        if ( empty( $options['api_base_url'] ) || empty( $options['api_token'] ) ) {
            return;
        }

        $this->send_order_payment_to_api( $order, $options );
    }

    /**
     * Build and send payment payload to external API (OrderPayment).
     *
     * @param WC_Order $order   Order object.
     * @param array    $options Plugin options.
     */
    protected function send_order_payment_to_api( $order, $options ) {
        if ( ! $order instanceof WC_Order ) {
            return;
        }

        $order_number = (string) $order->get_id();

        // Try to infer amount and paidAt from order data.
        $amount  = (float) $order->get_total();
        $paid_at = $order->get_date_paid();

        $payload = array(
            'orderNumber'     => $order_number,
            'siteId'          => ! empty( $options['site_id'] ) ? (string) $options['site_id'] : null,
            'invoiceNumber'   => $order->get_meta( '_invoice_number', true ),
            'paymentReference'=> $order->get_transaction_id(),
            'clearanceNumber' => $order->get_meta( '_payment_clearance_number', true ),
            'amount'          => $amount,
            'paidAt'          => $paid_at ? $paid_at->date( 'c' ) : current_time( 'c' ),
        );

        $endpoint = trailingslashit( $options['api_base_url'] ) . 'WooCommerce/OrderPayment';

        $args = array(
            'method'      => 'POST',
            'timeout'     => 20,
            'headers'     => array(
                'X-Api-Key'     => $options['api_token'],
                'Authorization' => 'Bearer ' . $options['api_token'],
                'Content-Type'  => 'application/json',
            ),
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
        );

        $response = wp_remote_post( $endpoint, $args );

        if ( is_wp_error( $response ) ) {
            $this->log_order_error( $order->get_id(), 'Payment sync error: ' . $response->get_error_message() );
            return;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            $body = wp_remote_retrieve_body( $response );
            $this->log_order_error( $order->get_id(), 'Payment sync HTTP ' . $code . ' - ' . $body );
        }
    }

    /**
     * Mark order as synced.
     *
     * @param int $order_id Order ID.
     */
    protected function mark_order_synced( $order_id, $http_status = null, $response_body_raw = null ) {
        $synced_at = current_time( 'mysql' );
        $this->set_order_meta_safe( $order_id, array(
            self::META_SYNCED    => 1,
            self::META_LAST_ERR  => '',
            self::META_LAST_SYNC => $synced_at,
        ) );

        // Add an internal order note as a visible indicator in admin.
        $order = wc_get_order( $order_id );
        if ( $order instanceof WC_Order ) {
            $order->add_order_note(
                sprintf(
                /* translators: %s is a datetime in mysql format */
                    __( 'ההזמנה סונכרנה ל‑Giorgio בהצלחה (%s).', 'oc-storeos-integration' ),
                    $synced_at
                ),
                false,
                false
            );

            if ( null !== $http_status && null !== $response_body_raw ) {
                $resp = is_string( $response_body_raw ) ? $response_body_raw : wp_json_encode( $response_body_raw, JSON_UNESCAPED_UNICODE );
                $resp = trim( (string) $resp );
                if ( '' !== $resp ) {
                    // Keep order note bounded to avoid bloating wp_comments.
                    $max_len = 2000;
                    if ( strlen( $resp ) > $max_len ) {
                        $resp = substr( $resp, 0, $max_len ) . '…';
                    }

//                    $order->add_order_note(
//                        sprintf(
//                            /* translators: 1: HTTP status code, 2: response body (truncated). */
//                            __( 'Giorgio response (HTTP %1$d): %2$s', 'oc-storeos-integration' ),
//                            (int) $http_status,
//                            $resp
//                        ),
//                        false,
//                        false
//                    );
                }
            }
        }
    }

    /**
     * Log order sync error.
     *
     * @param int    $order_id Order ID.
     * @param string $message  Error message.
     */
    protected function log_order_error( $order_id, $message ) {
        $this->set_order_meta_safe( $order_id, array(
            self::META_SYNCED    => 0,
            self::META_LAST_ERR  => $message,
            self::META_LAST_SYNC => current_time( 'mysql' ),
        ) );

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $this->maybe_debug_log( sprintf( 'OC Giorgio Integration error for order %d: %s', $order_id, $message ) );
        }
    }

}

/**
 * Info icon HTML after the configurable percentage-fee cart label (empty for other fees).
 *
 * @param object $fee WC_Cart_Fee or compatible.
 *
 * @return string
 */
function oc_storeos_get_weight_fee_tooltip_icon_html( $fee ) {
    if ( ! class_exists( 'OC_StoreOS_Integration' ) ) {
        return '';
    }

    return OC_StoreOS_Integration::get_instance()->get_weight_fee_tooltip_icon_html( $fee );
}
