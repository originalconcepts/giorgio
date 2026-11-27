<?php
/**
 * "אני חבר מועדון" — a toggle at checkout that tells Giorgio the customer says they are a club member.
 *
 * - Off by default (setting "חבר מועדון" on the plugin's Orders tab); the toggle itself is never pre-checked.
 * - Shown under the payment methods, before the terms / newsletter boxes (woocommerce_review_order_before_submit, 10).
 * - Checked → order meta _giorgio_club_member = yes + _giorgio_club_member_text (the text as shown at that moment).
 * - Sent to Giorgio as customer.clubMember = true and as the FIRST line of customerNotes (see
 *   OC_StoreOS_Integration::build_storeos_order_payload). The order's own notes field is not changed.
 * - Shown on the admin order screen and on the customer's order view.
 *
 * Moved here from the deliz theme (19–21/11/2026) so it works on every site that runs Giorgio, whatever the theme.
 * Orders saved by the earlier theme version (_deliz_club_member / _deliz_club_member_text) are still read.
 *
 * @package OC_StoreOS_Integration
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class OC_Giorgio_Club_Member {

    const OPTION       = 'oc_giorgio_club';
    const FIELD        = 'oc_giorgio_club_member';
    const META         = '_giorgio_club_member';
    const META_TEXT    = '_giorgio_club_member_text';
    const DEFAULT_TEXT = 'אני חבר מועדון';

    public function __construct() {
        add_action( 'admin_init', array( $this, 'register_settings' ), 20 );

        add_action( 'woocommerce_review_order_before_submit', array( $this, 'render_toggle' ), 10 );
        add_action( 'woocommerce_checkout_create_order', array( $this, 'save_on_order' ), 30, 1 );

        add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'render_admin_line' ), 5, 1 );
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render_customer_line' ), 5, 1 );

        // The earlier theme version rendered its own toggle; if both are present, only this one stays.
        add_action( 'wp_loaded', array( $this, 'remove_theme_toggle' ) );
    }

    /* ---------- settings ---------- */

    /** @return array{enabled:int,text:string} */
    public static function get_settings() {
        $raw = get_option( self::OPTION, array() );
        $raw = is_array( $raw ) ? $raw : array();
        $text = isset( $raw['text'] ) ? trim( (string) $raw['text'] ) : '';
        return array(
            'enabled' => ! empty( $raw['enabled'] ) ? 1 : 0,
            'text'    => '' !== $text ? $text : self::DEFAULT_TEXT,
        );
    }

    public static function is_enabled() {
        $s = self::get_settings();
        return (bool) apply_filters( 'oc_giorgio_club_member_enabled', 1 === $s['enabled'] );
    }

    public function register_settings() {
        // Same option group as the rest of the plugin, so the existing Save button stores it too.
        register_setting( OC_StoreOS_Integration::OPTION_GROUP, self::OPTION, array( $this, 'sanitize' ) );

        add_settings_section( 'oc_giorgio_orders_club', __( 'חבר מועדון', 'oc-storeos-integration' ), '__return_false', 'oc-giorgio-orders' );
        add_settings_field( 'oc_giorgio_club_enabled', __( 'טוגל "חבר מועדון" בקופה', 'oc-storeos-integration' ), array( $this, 'render_field_enabled' ), 'oc-giorgio-orders', 'oc_giorgio_orders_club' );
        add_settings_field( 'oc_giorgio_club_text', __( 'הטקסט', 'oc-storeos-integration' ), array( $this, 'render_field_text' ), 'oc-giorgio-orders', 'oc_giorgio_orders_club' );
    }

    public function sanitize( $input ) {
        $input = is_array( $input ) ? $input : array();
        $text  = isset( $input['text'] ) ? sanitize_text_field( wp_unslash( $input['text'] ) ) : '';
        return array(
            'enabled' => ( isset( $input['enabled'] ) && '1' === (string) $input['enabled'] ) ? 1 : 0,
            'text'    => '' !== trim( $text ) ? $text : self::DEFAULT_TEXT,
        );
    }

    public function render_field_enabled() {
        $s    = self::get_settings();
        $name = self::OPTION . '[enabled]';
        ?>
        <input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="0" />
        <label>
            <input type="checkbox" class="oc-toggle" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( 1 === $s['enabled'] ); ?> />
            <?php esc_html_e( 'הצג בקופה, מתחת לאמצעי התשלום.', 'oc-storeos-integration' ); ?>
        </label>
        <p class="description">
            <?php esc_html_e( 'הטוגל תמיד לא מסומן, הלקוח מסמן בעצמו. כשמסומן, הטקסט נשלח ל־Giorgio בשורה הראשונה של הערות ההזמנה (customerNotes) ובשדה clubMember, ומוצג בהזמנה.', 'oc-storeos-integration' ); ?>
        </p>
        <?php
    }

    public function render_field_text() {
        $s = self::get_settings();
        ?>
        <input type="text" class="regular-text" name="<?php echo esc_attr( self::OPTION ); ?>[text]" value="<?php echo esc_attr( $s['text'] ); ?>" />
        <p class="description"><?php esc_html_e( 'מוצג ליד הטוגל, ונשלח כמו שהוא.', 'oc-storeos-integration' ); ?></p>
        <?php
    }

    /* ---------- checkout ---------- */

    public function remove_theme_toggle() {
        if ( function_exists( 'dcc_render_club_toggle' ) ) {
            remove_action( 'woocommerce_review_order_before_submit', 'dcc_render_club_toggle', 10 );
        }
    }

    /**
     * Checked state across WooCommerce's AJAX refresh of the payment box (the toggle lives inside it).
     * First render → unchecked; after that, whatever the customer last chose.
     */
    protected function posted_checked() {
        if ( ! isset( $_POST['post_data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only UI state.
            return false;
        }
        $pd = array();
        parse_str( wp_unslash( $_POST['post_data'] ), $pd ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Missing
        return ! empty( $pd[ self::FIELD ] );
    }

    public function render_toggle() {
        if ( ! self::is_enabled() ) {
            return;
        }
        $s  = self::get_settings();
        $on = $this->posted_checked();
        static $css = false;
        if ( ! $css ) {
            $css = true;
            echo '<style id="ocg-club-css">
.ocg-club{margin:14px 0 10px!important}
.ocg-club label{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:12px 14px;border:1px solid #e6e3de;border-radius:10px;cursor:pointer;font-weight:600}
.ocg-club input{position:absolute;opacity:0;width:1px;height:1px}
.ocg-club__sw{position:relative;flex:0 0 46px;width:46px;height:26px;border-radius:999px;background:#d6d2cb;transition:background .2s}
.ocg-club__sw::after{content:"";position:absolute;top:3px;inset-inline-start:3px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.25);transition:transform .2s}
.ocg-club input:checked+.ocg-club__sw{background:var(--color-primary,#1c1c1c)}
.ocg-club input:checked+.ocg-club__sw::after{transform:translateX(20px)}
.rtl .ocg-club input:checked+.ocg-club__sw::after,[dir=rtl] .ocg-club input:checked+.ocg-club__sw::after{transform:translateX(-20px)}
.ocg-club input:focus-visible+.ocg-club__sw{outline:2px solid var(--color-primary,#1c1c1c);outline-offset:2px}
@media (prefers-reduced-motion:reduce){.ocg-club__sw,.ocg-club__sw::after{transition:none}}
</style>';
        }
        echo '<p class="form-row ocg-club"><label><span>' . esc_html( $s['text'] ) . '</span>'
            . '<input type="checkbox" role="switch" name="' . esc_attr( self::FIELD ) . '" id="' . esc_attr( self::FIELD ) . '" value="1"' . checked( $on, true, false ) . ' />'
            . '<span class="ocg-club__sw" aria-hidden="true"></span></label></p>';
    }

    /** @param WC_Order $order */
    public function save_on_order( $order ) {
        if ( ! self::is_enabled() || empty( $_POST[ self::FIELD ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce checked the checkout nonce.
            return;
        }
        $s = self::get_settings();
        $order->update_meta_data( self::META, 'yes' );
        $order->update_meta_data( self::META_TEXT, $s['text'] );
    }

    /* ---------- reading (payload + display) ---------- */

    /**
     * The club text for an order, or '' when the customer did not tick the toggle.
     * Reads the earlier theme meta too (_deliz_club_member*).
     *
     * @param WC_Order $order
     * @return string
     */
    public static function order_club_text( $order ) {
        if ( ! $order || ! is_callable( array( $order, 'get_meta' ) ) ) {
            return '';
        }
        foreach ( array( array( self::META, self::META_TEXT ), array( '_deliz_club_member', '_deliz_club_member_text' ) ) as $k ) {
            if ( 'yes' === (string) $order->get_meta( $k[0] ) ) {
                $t = trim( (string) $order->get_meta( $k[1] ) );
                return '' !== $t ? $t : self::DEFAULT_TEXT;
            }
        }
        return '';
    }

    public static function order_is_club_member( $order ) {
        return '' !== self::order_club_text( $order );
    }

    public function render_admin_line( $order ) {
        $t = self::order_club_text( $order );
        if ( '' !== $t ) {
            echo '<p style="margin:4px 0;"><strong>' . esc_html__( 'חבר מועדון:', 'oc-storeos-integration' ) . '</strong> <span style="color:#1d7c2a;">✓</span> <small style="color:#888;">(' . esc_html( $t ) . ')</small></p>';
        }
    }

    public function render_customer_line( $order ) {
        $t = self::order_club_text( $order );
        if ( '' !== $t ) {
            echo '<p class="ocg-club-order"><strong>✓ ' . esc_html( $t ) . '</strong></p>';
        }
    }
}
