<?php
/**
 * Checkout billing information form
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/checkout/form-billing.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see     https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce\Templates
 * @version 3.6.0
 * @global WC_Checkout $checkout
 */

defined( 'ABSPATH' ) || exit;

$checkout_style = apply_filters('ocws_checkout_page_style', 'regular');

$ar_billing_fields_first = array(
	'billing_google_autocomplete',
	'billing_address_1',
	'billing_city',
	'billing_postcode',
	'billing_country',
	'billing_company',
	'billing_address_1',
	'billing_address_2',
	'billing_street',
	'billing_house_num',
	'billing_enter_code',
	'billing_floor',
	'billing_apartment',
);

do_action( 'ocws_maybe_fix_shipping_method' );

$chosen_methods 	= WC()->session->get( 'chosen_shipping_methods' );
$chosen_shipping 	= $chosen_methods[0];
$local_pickup_chosen = ($chosen_shipping && strstr($chosen_shipping, 'local_pickup'));


$is_shipping_to_other_address = 0;
if ( isset( $_COOKIE['oc_shipping_to_other_address'] ) && $_COOKIE['oc_shipping_to_other_address'] != 0 ){
	$is_shipping_to_other_address = $_COOKIE['oc_shipping_to_other_address'];
}
?>

<div class="woocommerce-billing-fields">
	<h1>פרטי הזמנה</h1>
	<?php if ( wc_ship_to_billing_address_only() && WC()->cart->needs_shipping() ) : ?>

		<h2 class="col-title"><?php esc_html_e( 'Billing &amp; Shipping', 'deliz-short' ); ?></h2>

	<?php else : ?>

		<h2 class="col-title"><?php esc_html_e( 'Billing details', 'deliz-short' ); ?></h2>

	<?php endif; ?>
	<?php if ( ! is_user_logged_in() ) : ?>
		<div class="checkout-login">
			<span><?php _e( 'Was here previous?', 'deliz-short' ) ?> </span>
			<a class="my-account-link login-panel" href="#" rel="nofollow">
				<strong><?php _e( 'Click for sign in', 'deliz-short' );?></strong>
			</a>
		</div>
	<?php endif; ?>
	<?php do_action( 'woocommerce_before_checkout_billing_form', $checkout ); ?>

	<?php
	/*if (isset(WC()->session) && ocws_use_google_cities_and_polygons()) {
		$checkout_session_data = WC()->session->get('checkout_data', array());
		$city_code = WC()->checkout->get_value( 'billing_city_code' );
		$coords = WC()->checkout->get_value( 'billing_address_coords' );
		if (empty($city_code) || empty($coords)) {
			if (!empty($checkout_session_data)) {
				$checkout_session_data['billing_google_autocomplete'] = '';
				$checkout_session_data['billing_city'] = '';
				$checkout_session_data['billing_address_1'] = '';
				$checkout_session_data['billing_address_2'] = '';
				$checkout_session_data['billing_floor'] = '';
				$checkout_session_data['billing_apartment'] = '';
				$checkout_session_data['billing_enter_code'] = '';
				$checkout_session_data['billing_street'] = '';
				$checkout_session_data['billing_house_num'] = '';
				$checkout_session_data['billing_postcode'] = '';
				WC()->session->set('checkout_data', $checkout_session_data);
				WC()->session->save_data();
			}
		}
	}*/
	?>

	<div class="woocommerce-billing-fields__field-wrapper woocommerce-billing-fields-part-1">
		<?php
		$fields = $checkout->get_checkout_fields( 'billing' );

		foreach ( $fields as $key => $field ) {
			if ( !in_array( $key, $ar_billing_fields_first ) ){
				woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
			}
		}
		?>
	</div>

	<?php if ($checkout_style == 'deli') { ?>
		<div class="other-recipient-fields">
			<?php do_action('ocws_send_to_other_person_fields'); ?>
		</div>
	<?php } ?>

	<?php //do_action( 'woocommerce_after_checkout_billing_form', $checkout ); ?>
</div>

<div class="ship-method">

	<?php if ( ! is_user_logged_in() && $checkout->is_registration_enabled() ) : ?>
		<div class="woocommerce-account-fields">
			<?php if ( ! $checkout->is_registration_required() ) : ?>

				<p class="form-row form-row-wide create-account">
					<span class="open-account-text"><?php _e( 'Open account for quick order next time', 'deliz-short' ); ?></span>
					<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
						<input class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" id="createaccount" <?php checked( ( true === $checkout->get_value( 'createaccount' ) || ( true === apply_filters( 'woocommerce_create_account_default_checked', false ) ) ), true ); ?> type="checkbox" name="createaccount" value="1" /> <span><?php esc_html_e( 'Create an account?', 'woocommerce' ); ?></span>
					</label>
				</p>

			<?php endif; ?>

			<?php do_action( 'woocommerce_before_checkout_registration_form', $checkout ); ?>

			<?php if ( $checkout->get_checkout_fields( 'account' ) ) : ?>

				<div class="create-account">
					<?php foreach ( $checkout->get_checkout_fields( 'account' ) as $key => $field ) : ?>
						<?php woocommerce_form_field( $key, $field, $checkout->get_value( $key ) ); ?>
					<?php endforeach; ?>
					<div class="clear"></div>
				</div>

			<?php endif; ?>

			<?php do_action( 'woocommerce_after_checkout_registration_form', $checkout ); ?>
		</div>
	<?php endif;?>
<?php /*if ( ! is_user_logged_in() ) : ?>
	<?php //if ( $checkout->is_registration_enabled() ){
	?>
		<div class="open-registration-panel">
			<a href="#" rel="nofollow" class="open-registration btn-empty"><?php _e( 'Open account for quick order next time', 'deliz-short' ) ?></a>
		</div>
	<?php //} ?>
<?php endif; */?>

	<!-- only for displaying  -->
	<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) : ?>
		<?php wc_cart_totals_shipping_html(); ?>
	<?php endif; ?>
</div>

<div class="diff-address-shipping <?php if ( $local_pickup_chosen == 1  ){ echo 'hidden'; } ?>">
	<h2 id="ship-to-different-address">
		<span><?php esc_html_e( 'Ship to a different address?', 'deliz-short' ); ?></span>
	</h2>
	<h3>
		<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
			<input id="ship-to-different-address-checkbox" style="display:none" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" type="checkbox" name="ship_to_different_address" value="1" <?php checked( '1', $is_shipping_to_other_address ) ?> />
			<span class="custom-checkbox shipping-to-different-address"></span>
			<span><?php _e( 'Ship to someone other', 'deliz-short' ) ?></span>
			<input type="hidden" class="hidden shipping-to-other-address" id="shipping-to-other-address" name="oc_theme_ship_to_different_address" value="<?php echo $is_shipping_to_other_address ?>"  />
		</label>
	</h3>
</div>

<?php if ($checkout_style == 'deli') { ?>
		<?php do_action('ocws_delivery_data_deli_style'); ?>
<?php } ?>

<?php if ($checkout_style == 'regular') { ?>
	<div class="other-recipient-fields">
		<?php do_action('ocws_send_to_other_person_fields'); ?>
	</div>
<?php } ?>
<div dir="ltr" id="session-debug" style="display: none; text-align: left;"><pre>
	<?php //var_dump(WC()->session->get('checkout_data', array())) ?>
	<?php
	$keys = array(
		'chosen_shipping_methods',
		'chosen_address_coords',
		'chosen_street',
		'chosen_house_num',
		'chosen_city_name',
		'chosen_city_code',
		'chosen_shipping_city',
		'chosen_shipping_city',
		'chosen_pickup_aff',
	);

	foreach ($keys as $key) {
		echo $key.':<br>';
		var_dump(WC()->session->get($key, ''));
	}

	//$fields = $checkout->get_checkout_fields( 'billing' );
	//echo 'Fields: <br>';
	//var_dump($fields);
	//echo 'Customer: <br>';
	//var_dump(WC()->customer->get_meta_data());
	var_dump($checkout->get_value( 'billing_enter_code' ));
	?>

</pre></div>
<div class="woocommerce-billing-fields__field-wrapper woocommerce-billing-fields-part-2 billing-fields-shipping-data-1 <?php if ( $local_pickup_chosen == 1  ){ echo 'hidden'; } ?>">
	<?php
	//$fields = $checkout->get_checkout_fields( 'billing' );
	foreach ( $fields as $key => $field ) {
		if ( in_array( $key, $ar_billing_fields_first ) ){
			woocommerce_form_field( $key, $field, $checkout->get_value( $key ) );
		}
	}
	?>

</div>

<?php do_action( 'woocommerce_after_checkout_billing_form', $checkout ); ?>

<div>
	<?php //var_dump($fields); ?>
</div>
