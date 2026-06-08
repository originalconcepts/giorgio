<?php

use Carbon\Carbon;

/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://originalconcepts.co.il/
 * @since      1.0.0
 *
 * @package    Oc_Woo_Shipping
 * @subpackage Oc_Woo_Shipping/public 
 */ 

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Oc_Woo_Shipping
 * @subpackage Oc_Woo_Shipping/public
 * @author     Milla Shub <milla@originalconcepts.co.il>
 */
class Oc_Woo_Shipping_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of the plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Oc_Woo_Shipping_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Oc_Woo_Shipping_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( 'select2', plugin_dir_url( __FILE__ ) . 'css/select2.css', array(), null, 'all' );

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/oc-woo-shipping-public.css', array(), $this->version, 'all' );

		wp_enqueue_style( 'owl.carousel.css', OCWS_ASSESTS_URL . 'modules/deli/assets/lib/owl/assets/owl.carousel.css', array(), '1' );
		//wp_enqueue_style( 'owl.theme.default.css', OCWS_ASSESTS_URL . 'modules/deli/assets/lib/owl/assets/owl.theme.default.css', array(), '1' );

		wp_enqueue_style('jquery-ui-dialog');

	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Oc_Woo_Shipping_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Oc_Woo_Shipping_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */
		//error_log('---------------------------- enqueue_scripts ----------------------------------');

		$woo_ajax_url = '';
		$woo_wc_ajax_url = '';
		try {
			$woo_ajax_url = WC()->ajax_url();
			$woo_wc_ajax_url = WC_AJAX::get_endpoint( '%%endpoint%%' );
		}
		catch (Exception $e) {}

		wp_enqueue_script( 'select2', plugin_dir_url( __FILE__ ) . 'js/select2/select2.min.js', array( 'jquery' ), $this->version, true );

        if (!is_checkout()) {
            wp_enqueue_script('jquery-ui-datepicker');
            wp_enqueue_style('jqueryui', 'https://ajax.googleapis.com/ajax/libs/jqueryui/1.11.4/themes/smoothness/jquery-ui.css', false, null);
        }
		wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/oc-woo-shipping-public.js', array( 'jquery' ), $this->version, true );

		$polygons = array();

		if (ocws_use_google_cities_and_polygons()) {

			$data_store = new OC_Woo_Shipping_Group_Data_Store();
			$polygons_raw = $data_store->read_all_polygons();

			foreach ($polygons_raw as $l) {
				$polygon_data = '';
				$polygon_data = @unserialize($l->gm_shapes);

				if (false === $polygon_data) {
					$polygon_data = '';
				}
				if (is_array($polygon_data)) {
					$polygons[] = array(
						'location_code' => $l->location_code,
						'location_name' => $l->location_name,
						'is_enabled' => $l->is_enabled,
						'gm_shapes' => $polygon_data,
					);
				}
			}
		}

		wp_localize_script( $this->plugin_name, 'ocws',
			array(
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'home_url' => esc_url( home_url( '/' ) ),
				'woo_ajax_url'    => $woo_ajax_url,
				'woo_wc_ajax_url' => $woo_wc_ajax_url,
				'cart_is_empty' => (WC()->cart->is_empty()? 'yes' : 'no'),
				'localize' => array(
					'loading' => '', //__('Loading', 'ocws'),
					'understood' => __('Got it, thanks', 'ocws'),
					'back_to_site' => _x('Back to site', 'Redirect shipping popup', 'ocws'),
					'back_to_checkout' => _x('Back to checkout', 'Redirect shipping popup', 'ocws'),
					'continue_to_change' => _x('Continue to change', 'Redirect shipping popup', 'ocws'),
					'select2' => array(
						'errorLoading' => __('The results could not be loaded', 'ocws'),
						'inputTooLong' => __('Input too long', 'ocws'),
						'inputTooShort' => __('Input too short', 'ocws'),
						'loadingMore' => __('Loading more results…', 'ocws'),
						'noResults' => __('No results found', 'ocws'),
						'searching' => __('Searching…', 'ocws')
					),
					'messages' => array(
						'noHouseNumberInAddress' => 'נא להזין כתובת מלאה הכוללת רחוב ומספר בית.',
						'addMoreProductsContinue' =>  'הוסף עוד מוצרים', 'ocws' ,
					),
				),
				'polygons' => $polygons
			));

		$maps_api_key = ocws_get_google_maps_api_key();

		if (ocws_use_google_cities_and_polygons()) {

			wp_register_script('ocws-google-maps-api', 'https://maps.googleapis.com/maps/api/js?key='.$maps_api_key.'&libraries=geometry,places&language=' . get_locale(), null, null, true);
			wp_enqueue_script('ocws-google-maps-init', plugin_dir_url(__FILE__) . 'js/google-maps-init.js', array('jquery', 'ocws-google-maps-api'), null, true);
		}

		wp_enqueue_script('owl.carousel.min', OCWS_ASSESTS_URL . 'modules/deli/assets/lib/owl/owl.carousel.min.js', 'jquery', '', false);
		wp_enqueue_script('jquery-ui-dialog');

		wp_enqueue_script('ocws-cookie', plugin_dir_url(__FILE__) . 'js/ocws-cookie.js', array('jquery'), null, true);
	}

	/**
	 * Change the checkout city field to a dropdown field.
	 */
	public function change_city_to_dropdown( $fields ) {

		/*$use_simple_cities = !ocws_use_google_cities_and_polygons();
		$use_polygons = ocws_use_google_cities_and_polygons();
		$use_google_cities = ocws_use_google_cities();

		if (is_multisite()) {
			$city_options = OC_Woo_Shipping_Groups::get_all_locations_networkwide(true, $use_simple_cities, $use_polygons, $use_google_cities);
		}
		else {
			$city_options = OC_Woo_Shipping_Groups::get_all_locations(true, $use_simple_cities, $use_polygons, $use_google_cities);
		}*/
		if (!is_checkout()) {
			$city_options = OCWS_Advanced_Shipping::get_all_locations_networkwide(true);
			$description = '';
		}
		else {
			$city_options = OCWS_Advanced_Shipping::get_all_locations_blog(true);
			$description = __('Didn\'t find your city?', 'ocws').' <a class="ocws-all-cities-link">'.__('Full list', 'ocws').' &gt;</a>';
		}


		$city_args = wp_parse_args( array(
			'type' => 'select',
			'options' => count($city_options) == 1 ? $city_options : (['' => ''] + $city_options),
			'input_class' => array(
				'ocws-enhanced-select',
			),
			'placeholder' => '',
			'description' => $description,
		), $fields['shipping']['shipping_city'] );

		$fields['shipping']['shipping_city'] = $city_args;



		$city_args = wp_parse_args( array(
			'type' => 'select',
			'options' => count($city_options) == 1 ? $city_options : (['' => ''] + $city_options),
			'input_class' => array(
				'ocws-enhanced-select',
			),
			'placeholder' => '',
			'description' => $description,
		), $fields['billing']['billing_city'] );

		//error_log('change_city_to_dropdown ---------------------->');
		//error_log(print_r($city_args['options'], 1));

		$fields['billing']['billing_city'] = $city_args; // Also change for billing field

		wc_enqueue_js( "
	jQuery( ':input.ocws-enhanced-select' ).filter( ':not(.enhanced)' ).each( function() {
		var select2_args = { minimumResultsForSearch: 5 };
		jQuery( this ).select2( select2_args ).addClass( 'enhanced' );
	});" );

		return $fields;

	}

	/**
	 * Change the checkout address fields if there is at least one active polygon.
	 */
	public function change_checkout_address_fields_if_polygon( $fields ) {

		if (!ocws_use_google_cities_and_polygons()) return $fields;

		$hide_city_and_street = true;

		if ( isset( $_POST['post_data'] ) ) {
			parse_str( $_POST['post_data'], $post_data );
		} else {
			$post_data = $_POST; // fallback for final checkout (non-ajax)
		}

		$raw_address_coords = '';
		if (isset($post_data['billing_address_coords']) && $post_data['billing_address_coords']) {
			$raw_address_coords = $post_data['billing_address_coords'];
		} else if (isset(WC()->session)) {
			$raw_address_coords = WC()->session->get('chosen_address_coords', '');
		}
		$raw_street = '';
		if (isset($post_data['billing_street']) && $post_data['billing_street']) {
			$raw_street = $post_data['billing_street'];
		} else if (isset(WC()->session)) {
			$raw_street = WC()->session->get('chosen_street', '');
		}
		$raw_house_num = '';
		if (isset($post_data['billing_house_num']) && $post_data['billing_house_num']) {
			$raw_house_num = $post_data['billing_house_num'];
		} else if (isset(WC()->session)) {
			$raw_house_num = WC()->session->get('chosen_house_num', '');
		}
		$raw_city_name = '';
		if (isset($post_data['billing_city_name']) && $post_data['billing_city_name']) {
			$raw_city_name = $post_data['billing_city_name'];
		} else if (isset(WC()->session)) {
			$raw_city_name = WC()->session->get('chosen_city_name', '');
		}
		$raw_city_code = '';
		if (isset($post_data['billing_city_code']) && $post_data['billing_city_code']) {
			$raw_city_code = $post_data['billing_city_code'];
		} else if (isset(WC()->session)) {
			$raw_city_code = WC()->session->get('chosen_city_code', '');
		}

		if ($raw_address_coords) {
			$raw_address_coords = wc_clean( wp_unslash( $raw_address_coords ) );
		}

		if ($raw_street) {
			$raw_street = wc_clean( wp_unslash( $raw_street ) );
		}

		if ($raw_house_num) {
			$raw_house_num = wc_clean( wp_unslash( $raw_house_num ) );
		}

		if ($raw_city_name) {
			$raw_city_name = wc_clean( wp_unslash( $raw_city_name ) );
		}

		if ($raw_city_code) {
			$raw_city_code = wc_clean( wp_unslash( $raw_city_code ) );
		}

		$autocomplete_args = wp_parse_args( array(
			'label' => __('Type your address here', 'ocws'),
			'placeholder' => '',
			'required' => false,
			'input_class' => array(
				'ocws-google-address-autocomplete',
			),
			'type' => 'text',
			'class' => array( 'form-row', 'address-autocomplete-field' )
		), $fields['billing']['billing_city'] );

		$fields['billing']['billing_google_autocomplete'] = array (
			'label' => __('Type your address here', 'ocws'),
			'placeholder' => '',
			'required' => false,
			'input_class' => array(
				'ocws-google-address-autocomplete',
			),
			'type' => 'text',
			'class' => array( 'form-row', 'address-autocomplete-field', 'validate-required' ),
			'priority' => 1
		);

		$fields_to_rewrite = array(
			'city', 'street', 'house_num'
		);

		foreach ($fields_to_rewrite as $addr_field) {

			if (isset($fields['billing']['billing_' . $addr_field])) {

				$input_class = array();
				$class = array();
				if (
					isset($fields['billing']['billing_' . $addr_field]['input_class']) &&
					is_array($fields['billing']['billing_' . $addr_field]['input_class'])
				) {
					$input_class = array_filter($fields['billing']['billing_' . $addr_field]['input_class'], function ($v) {
						return !strstr($v, 'ocws-enhanced-select');
					});
				}
				if (
					isset($fields['billing']['billing_' . $addr_field]['class']) &&
					is_array($fields['billing']['billing_' . $addr_field]['class'])
				) {
					$class = array_filter($fields['billing']['billing_' . $addr_field]['class'], function ($v) {
						return ($v != 'validate-required');
					});
					//$class = $fields['billing']['billing_' . $addr_field]['class'];
				}
				$input_class[] = 'ocws-readonly-form-field-input';
				$class[] = 'ocws-readonly-form-field';
				$class[] = 'ocws-polygon-related';

				if ($addr_field !== 'city') {

					$args = wp_parse_args( array(
						'class' => $class,
						'type' => 'text',
						'input_class' => $input_class,
						'custom_attributes' => array('readonly' => 'readonly')
					), $fields['billing']['billing_' . $addr_field] );

					$fields['billing']['billing_' . $addr_field] = $args;
				}
				else {
					$args = wp_parse_args( array(
						'class' => $class,
						'type' => 'text',
						'input_class' => $input_class,
						'placeholder' => __('City', 'ocws'),
						'custom_attributes' => array('readonly' => 'readonly'),
						'priority' => 2
					), $fields['billing']['billing_' . $addr_field] );

					$fields['billing']['billing_' . $addr_field . '_name'] = $args;

					$input_class[] = 'ocws-hidden-form-field-input';
					$class[] = 'ocws-hidden-form-field';

					$args = wp_parse_args( array(
						'class' => $class,
						'type' => 'hidden',
						'input_class' => $input_class
					), $fields['billing']['billing_' . $addr_field] );

					$fields['billing']['billing_' . $addr_field] = $args;
				}
			}
		}

		$address_coords_args = array(
			'type' => 'hidden',
			'class' => array('ocws-hidden-form-field'),
			'priority' => 100,
			'default' => $raw_address_coords
		);

		$fields['billing']['billing_address_coords'] = $address_coords_args;

		$city_code_args = array(
			'type' => 'hidden',
			'class' => array('ocws-hidden-form-field'),
			'priority' => 100,
			'default' => $raw_city_code
		);

		$fields['billing']['billing_city_code'] = $city_code_args;

		$polygon_code_args = array(
			'type' => 'hidden',
			'class' => array('ocws-hidden-form-field'),
			'priority' => 100
		);

		$fields['billing']['billing_polygon_code'] = $polygon_code_args;

		if (isset($fields['billing']['billing_city'])) {
			$fields['billing']['billing_city']['default'] = $raw_city_name;
		}
		if (isset($fields['billing']['billing_city_name'])) {
			$fields['billing']['billing_city_name']['default'] = $raw_city_name;
			if ($hide_city_and_street) {
				if (isset($fields['billing']['billing_city_name']['class'])) {
					$fields['billing']['billing_city_name']['class'][] = 'ocws-hidden-form-field';
				}
				if (isset($fields['billing']['billing_city_name']['input_class'])) {
					$fields['billing']['billing_city_name']['input_class'][] = 'ocws-hidden-form-field-input';
				}
				$fields['billing']['billing_city_name']['type'] = 'hidden';
			}
		}
		if (isset($fields['billing']['billing_house_num'])) {
			$fields['billing']['billing_house_num']['default'] = $raw_house_num;
		}
		else {
			$house_num_args = array(
				'type' => 'hidden',
				'class' => array('ocws-hidden-form-field'),
				'priority' => 100,
				'default' => $raw_house_num
			);

			$fields['billing']['billing_house_num'] = $house_num_args;
		}
		if (isset($fields['billing']['billing_street'])) {
			$fields['billing']['billing_street']['default'] = $raw_street;
			if ($hide_city_and_street) {
				if (isset($fields['billing']['billing_street']['class'])) {
					$fields['billing']['billing_street']['class'][] = 'ocws-hidden-form-field';
				}
				if (isset($fields['billing']['billing_street']['input_class'])) {
					$fields['billing']['billing_street']['input_class'][] = 'ocws-hidden-form-field-input';
				}
				$fields['billing']['billing_street']['type'] = 'hidden';
			}
		}
		if (isset($fields['billing']['billing_google_autocomplete'])) {
			if (isset($fields['billing']['billing_street']) && isset($fields['billing']['billing_city_name'])) {
				$fields['billing']['billing_google_autocomplete']['default'] = (!empty($raw_street) && !empty($raw_city_name)) ? $raw_street . ', ' . $raw_city_name : '';
				$street_value = WC()->checkout()->get_value( 'billing_street' );
				$city_value = WC()->checkout()->get_value( 'billing_city_name' );
				if ($street_value && $city_value) {
					$fields['billing']['billing_google_autocomplete']['value'] = $street_value . ', ' . $city_value;
				}
			}
		}

		return $fields;

	}

	public function change_checkout_billing_google_autocomplete_field( $field_value, $field_name ) {
		if ($field_name == 'billing_google_autocomplete') {
			$street_value = WC()->checkout()->get_value( 'billing_street' );
			$city_value = WC()->checkout()->get_value( 'billing_city_name' );
			if ($street_value && $city_value) {
				return $street_value . ', ' . $city_value;
			}
		}
		return '';
	}

	public function woocommerce_cart_shipping_packages_filter( $packages ) {

		if (isset($packages[0]) && isset($packages[0]['destination'])) {

			//error_log('Destination packages:');

			if ( isset( $_POST['post_data'] ) ) {

				parse_str( $_POST['post_data'], $post_data );

			} else {

				$post_data = $_POST; // fallback for final checkout (non-ajax)

			}

			$aff_id = false;
			if (isset($_POST['ocws_lp_popup']['ocws_lp_pickup_aff_id']) && $_POST['ocws_lp_popup']['ocws_lp_pickup_aff_id']) {
				$aff_id = !str_contains($_POST['ocws_lp_popup']['ocws_lp_pickup_aff_id'] . '', ':::') ? intval($_POST['ocws_lp_popup']['ocws_lp_pickup_aff_id']) : $_POST['ocws_lp_popup']['ocws_lp_pickup_aff_id'];
			}
			else if (isset($post_data['ocws_lp_pickup_aff_id']) && $post_data['ocws_lp_pickup_aff_id']) {
				$aff_id = !str_contains($post_data['ocws_lp_pickup_aff_id'] . '', ':::') ? intval($post_data['ocws_lp_pickup_aff_id']) : $post_data['ocws_lp_pickup_aff_id'];
			}
			if ($aff_id) {
				$packages[0]['destination']['ocws_lp_pickup_aff_id'] = $aff_id;
			}

			if (ocws_use_google_cities_and_polygons()) {

				$raw_address_coords = '';
				if (isset($post_data['billing_address_coords']) && $post_data['billing_address_coords']) {
					$raw_address_coords = $post_data['billing_address_coords'];
				} else if (isset(WC()->session)) {
					$raw_address_coords = WC()->session->get('chosen_address_coords', '');
				}
				$raw_street = '';
				if (isset($post_data['billing_street']) && $post_data['billing_street']) {
					$raw_street = $post_data['billing_street'];
				} else if (isset(WC()->session)) {
					$raw_street = WC()->session->get('chosen_street', '');
				}
				$raw_house_num = '';
				if (isset($post_data['billing_house_num']) && $post_data['billing_house_num']) {
					$raw_house_num = $post_data['billing_house_num'];
				} else if (isset(WC()->session)) {
					$raw_house_num = WC()->session->get('chosen_house_num', '');
				}
				$raw_city_name = '';
				if (isset($post_data['billing_city_name']) && $post_data['billing_city_name']) {
					$raw_city_name = $post_data['billing_city_name'];
				} else if (isset(WC()->session)) {
					$raw_city_name = WC()->session->get('chosen_city_name', '');
				}
				$raw_city_code = '';
				if (isset($post_data['billing_city_code']) && $post_data['billing_city_code']) {
					$raw_city_code = $post_data['billing_city_code'];
				} else if (isset(WC()->session)) {
					$raw_city_code = WC()->session->get('chosen_city_code', '');
				}
				$address_coords = '';
				$street = '';
				$house_num = '';
				$city_name = '';
				$city_code = '';

				if ($raw_address_coords) {
					$coords = wc_clean( wp_unslash( $raw_address_coords ) );
					$coords = str_replace(array('(', ')', ' '), '', $coords);
					$coords = explode(',', $coords, 2);
					if (isset($coords[0]) && isset($coords[1])) {
						$address_coords = array();
						$address_coords['lat'] = $coords[0];
						$address_coords['lng'] = $coords[1];
					}
				}

				if ($raw_street) {
					$street = wc_clean( wp_unslash( $raw_street ) );
				}

				if ($raw_house_num) {
					$house_num = wc_clean( wp_unslash( $raw_house_num ) );
				}

				if ($raw_city_name) {
					$city_name = wc_clean( wp_unslash( $raw_city_name ) );
				}

				if ($raw_city_code) {
					$city_code = wc_clean( wp_unslash( $raw_city_code ) );
				}

				$packages[0]['destination']['address_coords'] = maybe_serialize($address_coords);
				$packages[0]['destination']['street'] = $street;
				$packages[0]['destination']['house_num'] = $house_num;
				$packages[0]['destination']['city_name'] = $city_name;
				$packages[0]['destination']['city_code'] = $city_code;
				$packages[0]['destination']['city'] = $city_name;
			}

			//error_log(print_r($packages[0]['destination'], 1));
			//error_log( print_r(WC()->session->get_session_data(), 1) );
		}

		return $packages;
	}

	/**
	 * Change the checkout street (billing_address_1) field to a dropdown field.
	 */
	public function change_street_to_dropdown( $fields ) {

		$chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
		$checkout = WC()->checkout();

		if ( isset( $_POST['post_data'] ) ) {

			parse_str( $_POST['post_data'], $post_data );

		} else {

			$post_data = $_POST; // fallback for final checkout (non-ajax)

		}

		if (empty($chosen_methods)) {
			if (isset($post_data['shipping_method']) && is_array($post_data['shipping_method'])) {
				$chosen_methods = $post_data['shipping_method'];
			}
		}

		if (!isset($post_data['billing_city']) || empty($post_data['billing_city'])) {
			$post_data['billing_city'] = WC()->checkout->get_value('billing_city');
		}

		if (!$post_data['billing_city']) {
			return $fields;
		}

		$data_store = new OC_Woo_Shipping_Group_Data_Store();
		$city_data = $data_store->read_location_data($post_data['billing_city']);

		if (false === $city_data) {
			return $fields;
		}

		$streets_data = @unserialize($city_data->gm_streets);

		if (false === $streets_data || !is_array($streets_data)) {
			return $fields;
		}

		// the city is restricted for some streets

		$street_args = wp_parse_args( array(
			'type' => 'select',
			'options' => ['' => ''],
			'input_class' => array(
				'ocws-enhanced-select-ajax-streets',
			),
			'placeholder' => __('Start typing a street name', 'ocws')
		), $fields['shipping']['shipping_street'] );

		$fields['shipping']['shipping_street'] = $street_args;

		$street_args = wp_parse_args( array(
			'type' => 'select',
			'options' => ['' => ''],
			'input_class' => array(
				'ocws-enhanced-select-ajax-streets',
			),
			'placeholder' => __('Start typing a street name', 'ocws')
		), $fields['billing']['billing_street'] );

		$fields['billing']['billing_street'] = $street_args; // Also change for billing field

		/*wc_enqueue_js( "
	jQuery( ':input.ocws-enhanced-select-ajax-streets' ).filter( ':not(.enhanced)' ).each( function() {
		var select2_args = { minimumResultsForSearch: 5 };
		jQuery( this ).select2( select2_args ).addClass( 'enhanced' );
	});" );*/

		return $fields;

	}

	/**
	 * Change the default city field to google places autocomplete if using polygon.
	 */
	public function change_default_city_if_polygon( $fields ) {

		if (!ocws_use_google_cities_and_polygons() || !is_account_page()) return $fields;

		$fields['google_autocomplete'] = array (
			'label' => __('Type your address here', 'ocws'),
			'placeholder' => '',
			'required' => false,
			'input_class' => array(
				'ocws-google-address-autocomplete',
			),
			'type' => 'text',
			'class' => array( 'form-row', 'address-autocomplete-field' ),
			'priority' => 8
		);

		$fields_to_rewrite = array(
			'city', 'street', 'house_num'
		);

		foreach ($fields_to_rewrite as $addr_field) {
			if (isset($fields[$addr_field])) {
				$input_class = array();
				$class = array();
				if (
					isset($fields[$addr_field]['input_class']) &&
					is_array($fields[$addr_field]['input_class'])
				) {
					$input_class = array_filter($fields[$addr_field]['input_class'], function ($v) {
						return !strstr($v, 'ocws-enhanced-select');
					});
				}
				if (
					isset($fields[$addr_field]['class']) &&
					is_array($fields[$addr_field]['class'])
				) {
					$class = $fields[$addr_field]['class'];
				}
				$input_class[] = 'ocws-readonly-form-field-input';
				$class[] = 'ocws-readonly-form-field';
				$class[] = 'ocws-polygon-related';
				if ($addr_field == 'city' || $addr_field == 'street') {
					$input_class[] = 'ocws-hidden-form-field-input';
					$class[] = 'ocws-hidden-form-field';
				}
				if ($addr_field == 'city') {
					$args = wp_parse_args( array(
						'class' => $class,
						'type' => 'hidden',
						'input_class' => $input_class,
						'placeholder' => __('City', 'ocws')
					), $fields['billing']['billing_' . $addr_field] );

					$fields['city'] = $args;
				}
				else {
					$args = wp_parse_args( array(
						'class' => $class,
						'type' => ($addr_field == 'street'? 'hidden' : 'text'),
						'input_class' => $input_class,
						'custom_attributes' => array('readonly' => 'readonly')
					), $fields[$addr_field] );

					$fields[$addr_field] = $args;
				}
			}
		}
		$address_coords_args = array(
			'type' => 'hidden',
			'class' => array('ocws-hidden-form-field'),
			'priority' => 100,
			//'default' => $raw_address_coords
		);

		$fields['address_coords'] = $address_coords_args;

		$city_code_args = array(
			'type' => 'hidden',
			'class' => array('ocws-hidden-form-field'),
			'priority' => 100,
		);

		$fields['city_code'] = $city_code_args;

		$polygon_code_args = array(
			'type' => 'hidden',
			'class' => array('ocws-hidden-form-field'),
			'priority' => 100
		);

		$fields['polygon_code'] = $polygon_code_args;

		return $fields;

	}

	/**
	 * Change the default city field to a dropdown field.
	 */
	public function change_default_city_to_dropdown( $fields ) {

		/*$use_simple_cities = !ocws_use_google_cities_and_polygons();
		$use_polygons = ocws_use_google_cities_and_polygons();
		$use_google_cities = ocws_use_google_cities();

		if (is_multisite()) {
			$city_options = OC_Woo_Shipping_Groups::get_all_locations_networkwide(false, $use_simple_cities, $use_polygons, $use_google_cities);
		}
		else {
			$city_options = OC_Woo_Shipping_Groups::get_all_locations(false, $use_simple_cities, $use_polygons, $use_google_cities);
		}*/
		$city_options = OCWS_Advanced_Shipping::get_all_locations_networkwide(false);

		$city_args = wp_parse_args( array(
			'type' => 'select',
			'options' => ['' => ''] + $city_options,
			'input_class' => array(
				'ocws-enhanced-select',
			),
			'placeholder' => __('Locality', 'ocws')
		), $fields['city'] );

		$fields['city'] = $city_args;

		wc_enqueue_js( "
	jQuery( ':input.ocws-enhanced-select' ).filter( ':not(.enhanced)' ).each( function() {
		var select2_args = { minimumResultsForSearch: 5 };
		jQuery( this ).select2( select2_args ).addClass( 'enhanced' );
	});" );

		return $fields;

	}

	public function trigger_update_checkout_on_change( $fields ) {

		// TODO: change fields if polygon

		$field_names = array(
			'billing_first_name',
			'billing_last_name',
			'billing_email',
			'billing_phone',
			'billing_company',
			'billing_company_num',
			'billing_street',
			'billing_address_1',
			'billing_house_num',
			'billing_apartment',
			'billing_floor',
			'billing_enter_code',
			'billing_notes'
		);

		foreach ($field_names as $field_name) {
			if (isset($fields['billing'][$field_name])) {
				$fields['billing'][$field_name]['class'][] = 'ocws_update_checkout_on_change';
			}
		}

		return $fields;
	}

	public function change_default_guest_billing_fields( $fields ) {

		if (is_user_logged_in()) {
			return $fields;
		}

		if ( isset( $_POST['post_data'] ) ) {

			parse_str( $_POST['post_data'], $post_data );

		} else {

			$post_data = $_POST; // fallback for final checkout (non-ajax)

		}

		$field_names = array(
			'billing_first_name',
			'billing_last_name',
			'billing_email',
			'billing_phone',
			'billing_company',
			'billing_company_num',
			'billing_street',
			'billing_address_1',
			'billing_house_num',
			'billing_apartment',
			'billing_floor',
			'billing_enter_code',
			'billing_notes'
		);

		foreach ($field_names as $field_name) {
			if (isset($fields['billing'][$field_name])) {
				$fields['billing'][$field_name]['default'] = isset($post_data[$field_name])? $post_data[$field_name] : ( isset( $fields['billing'][$field_name]['default'] )? $fields['billing'][$field_name]['default'] : '' );
			}
		}

		return $fields;
	}

	public function woocommerce_checkout_get_value_from_session( $field_value, $field_name ) {

		if ( isset( $_POST['post_data'] ) ) {

			parse_str( $_POST['post_data'], $post_data );

		} else {

			$post_data = $_POST; // fallback for final checkout (non-ajax)

		}

		if ( ! empty( $post_data[ $field_name ] ) ) {
			return wc_clean( wp_unslash( $post_data[ $field_name ] ) );
		}

		if ( ! isset( WC()->session ) ) {
			return $field_value;
		}

		$checkout_data = WC()->session->get( 'checkout_data', array() );

		if ( $field_name == 'billing_street' ) {

			if (isset($checkout_data['billing_street'])) {
				$field_value = $checkout_data['billing_street'];
			}
			if (empty($field_value)) {
				$field_value = WC()->session->get('chosen_street', '');
			}

		} else if ( $field_name == 'billing_house_num' ) {

			if (isset($checkout_data['billing_house_num'])) {
				$field_value = $checkout_data['billing_house_num'];
			}
			if (empty($field_value)) {
				$field_value = WC()->session->get('chosen_house_num', '');
			}

		} else if ( $field_name == 'billing_city_code' ) {

			if (isset($checkout_data['billing_city_code'])) {
				$field_value = $checkout_data['billing_city_code'];
			}
			if (empty($field_value)) {
				$field_value = WC()->session->get('chosen_city_code', '');
			}

		} else if ( $field_name == 'billing_city_name' ) {

			if (isset($checkout_data['billing_city_name'])) {
				$field_value = $checkout_data['billing_city_name'];
			}
			if (empty($field_value)) {
				$field_value = WC()->session->get('chosen_city_name', '');
			}

		} else if ( $field_name == 'billing_city' ) {

			// billing_city is the WooCommerce "city" string; billing_city_code holds Google place_id / location code.
			// Session/cart sync historically stored the place_id in billing_city — resolve to a display name.
			$city_name = '';
			if (isset($checkout_data['billing_city_name']) && $checkout_data['billing_city_name']) {
				$city_name = $checkout_data['billing_city_name'];
			}
			if ($city_name === '' || $city_name === null) {
				$city_name = WC()->session->get('chosen_city_name', '');
			}

			$code = '';
			if (isset($checkout_data['billing_city']) && $checkout_data['billing_city']) {
				$code = $checkout_data['billing_city'];
			}
			if ($code === '' || $code === null) {
				$code = WC()->session->get('chosen_shipping_city', '');
			}

			if ($city_name) {
				$field_value = $city_name;
			} elseif ($code) {
				$resolved = function_exists('ocws_get_city_title') ? ocws_get_city_title($code) : '';
				$field_value = $resolved ? $resolved : $code;
			} else {
				$field_value = '';
			}

		} else if ( $field_name == 'ocws_lp_pickup_aff_id' ) {

			if (isset($checkout_data['ocws_lp_pickup_aff_id'])) {
				$field_value = $checkout_data['ocws_lp_pickup_aff_id'];
			}
			if (empty($field_value)) {
				$field_value = WC()->session->get('chosen_pickup_aff', '');
			}

		} else if ( $field_name == 'billing_address_coords' ) {

			if (isset($checkout_data['billing_address_coords'])) {
				$field_value = $checkout_data['billing_address_coords'];
			}
			if (empty($field_value)) {
				$field_value = WC()->session->get('chosen_address_coords', '');
			}

		} else if ( $field_name == 'billing_enter_code' ) {

			if (isset($checkout_data['billing_enter_code'])) {
				$field_value = $checkout_data['billing_enter_code'];
			}

		} else if ( $field_name == 'billing_google_autocomplete' ) {

			$city_name = '';
			$street = '';
			$num = '';
			if (isset($checkout_data['billing_city_name'])) {
				$city_name = $checkout_data['billing_city_name'];
			}
			if (isset($checkout_data['billing_street'])) {
				$street = $checkout_data['billing_street'];
			}
			if (isset($checkout_data['billing_house_num'])) {
				$num = $checkout_data['billing_house_num'];
			}
			if (!$city_name || !$street || !$num) {

				$city_name = WC()->session->get('chosen_city_name', '');
				$street = WC()->session->get('chosen_street', '');
				$num = WC()->session->get('chosen_house_num', '');
			}
			$field_value = '';
			if ($city_name && $street && $num) {
				$field_value = sprintf('%s %s, %s', $street, $num, $city_name);
			}

		} else {
			$data = WC()->session->get( 'checkout_data' );
			if ( $data && isset($data[$field_name]) && !empty( $data[$field_name] ) ) {
				return is_bool( $data[$field_name] ) ? (int) $data[$field_name] : $data[$field_name];
			}
		}
		return wc_clean( wp_unslash( $field_value ) );
	}

	public function change_checkout_user_billing_field( $field_value, $field_name ) {

		return $field_value;
	}

	public function save_checkout_data_to_session( $posted_data ) {

		if ( ! isset( WC()->session ) ) return;

		parse_str( $posted_data, $output );

		WC()->customer->set_props(
			array(
				'billing_street'   => isset( $output['billing_street'] ) ? wc_clean( wp_unslash( $output['billing_street'] ) ) : null,
				'billing_house_num'   => isset( $output['billing_house_num'] ) ? wc_clean( wp_unslash( $output['billing_house_num'] ) ) : null,
				'billing_city_code'   => isset( $output['billing_city_code'] ) ? wc_clean( wp_unslash( $output['billing_city_code'] ) ) : null,
				'billing_city_name'   => isset( $output['billing_city_name'] ) ? wc_clean( wp_unslash( $output['billing_city_name'] ) ) : null,
				'billing_city'   => isset( $output['billing_city'] ) ? wc_clean( wp_unslash( $output['billing_city'] ) ) : null,
				'billing_address_coords'   => isset( $output['billing_address_coords'] ) ? wc_clean( wp_unslash( $output['billing_address_coords'] ) ) : null,
				'billing_enter_code'   => isset( $output['billing_enter_code'] ) ? wc_clean( wp_unslash( $output['billing_enter_code'] ) ) : null,
				'billing_floor'   => isset( $output['billing_floor'] ) ? wc_clean( wp_unslash( $output['billing_floor'] ) ) : null,
				'billing_apartment'   => isset( $output['billing_apartment'] ) ? wc_clean( wp_unslash( $output['billing_apartment'] ) ) : null,
			)
		);

		if ( isset($output['billing_street']) ) {

			WC()->session->set('chosen_street', $output['billing_street']);

		}
		if ( isset($output['billing_house_num'] ) ) {

			WC()->session->set('chosen_house_num', $output['billing_house_num']);

		}
		if ( isset($output['billing_city_code'] ) ) {

			WC()->session->set('chosen_city_code', $output['billing_city_code']);

		}
		if ( isset($output['billing_city_name'] ) ) {

			WC()->session->set('chosen_city_name', $output['billing_city_name']);

		}
		// Location code for shipping (place_id / internal id). billing_city is the display name when using Google polygons.
		if ( isset($output['billing_city_code'] ) && $output['billing_city_code'] !== '' && $output['billing_city_code'] !== null ) {

			WC()->session->set('chosen_shipping_city', $output['billing_city_code']);

		} else if ( isset($output['billing_city'] ) ) {

			WC()->session->set('chosen_shipping_city', $output['billing_city']);

		}
		if ( isset($output['ocws_lp_pickup_aff_id'] ) ) {

			WC()->session->set('chosen_pickup_aff', $output['ocws_lp_pickup_aff_id']);

		}
		if ( isset($output['billing_address_coords'] ) ) {

			WC()->session->set('chosen_address_coords', $output['billing_address_coords']);

		}
		WC()->session->set( 'checkout_data', $output );
	}

	public function checkout_add_slot_date_time_fields( $fields ) {

		if ( isset( $_POST['post_data'] ) ) {

			parse_str( $_POST['post_data'], $post_data );

		} else {

			$post_data = $_POST; // fallback for final checkout (non-ajax)

		}

		$selected_slot = OC_Woo_Shipping_Info::get_shipping_info();
		$popup_shipping_info = OC_Woo_Shipping_Info::get_shipping_info_from_session();

		$selected_slot_arr = array(
			'date' => '',
			'slot_start' => '',
			'slot_end' => ''
		);
		if (null !== $selected_slot) {
			if (isset($selected_slot['date']) && $selected_slot['date']) {
				$selected_slot_arr['date'] = $selected_slot['date'];
			}
			else if ($popup_shipping_info['date']) {
				$selected_slot_arr['date'] = $popup_shipping_info['date'];
			}
			if (isset($selected_slot['slot_start']) && $selected_slot['slot_start']) {
				$selected_slot_arr['slot_start'] = $selected_slot['slot_start'];
			}
			else if ($popup_shipping_info['slot_start']) {
				$selected_slot_arr['slot_start'] = $popup_shipping_info['slot_start'];
			}
			if (isset($selected_slot['slot_end']) && $selected_slot['slot_end']) {
				$selected_slot_arr['slot_end'] = $selected_slot['slot_end'];
			}
			else if ($popup_shipping_info['slot_end']) {
				$selected_slot_arr['slot_end'] = $popup_shipping_info['slot_end'];
			}
		}

		$field_names = array(
			'order_expedition_date' => $selected_slot_arr['date'],
			'order_expedition_slot_start' => $selected_slot_arr['slot_start'],
			'order_expedition_slot_end' => $selected_slot_arr['slot_end'],
			'slots_state' => ''
		);

		foreach ($field_names as $field_name => $field_value) {

			$fields['ocws'][$field_name] = array(
				'required' => false,
				'type' => 'hidden',
				'default' => $field_value
			);
		}

		return $fields;
	}

	/**
	 * Copy floor / apartment / entry code from billing into the `ocws` field group so they render
	 * inside #oc-woo-shipping-additional (popup + AJAX slots). Without this, ocws only had slot hiddens.
	 */
	public function checkout_ocws_merge_address_extras_into_ocws_group( $fields ) {

		if ( ! is_array( $fields ) || ! isset( $fields['billing'] ) ) {
			return $fields;
		}

		$keys = apply_filters(
			'ocws_merge_into_ocws_group_fields',
			array( 'billing_floor', 'billing_apartment', 'billing_enter_code' )
		);

		$extras = array();
		foreach ( $keys as $key ) {
			if ( empty( $fields['billing'][ $key ] ) || ! is_array( $fields['billing'][ $key ] ) ) {
				continue;
			}
			$field = $fields['billing'][ $key ];
			if ( isset( $field['class'] ) && is_array( $field['class'] ) ) {
				$field['class'] = array_values( array_diff( $field['class'], array( 'ocws-hidden-form-field' ) ) );
			}
			if ( isset( $field['input_class'] ) && is_array( $field['input_class'] ) ) {
				$field['input_class'] = array_values( array_diff( $field['input_class'], array( 'ocws-hidden-form-field-input' ) ) );
			}
			if ( isset( $field['type'] ) && 'hidden' === $field['type'] ) {
				$field['type'] = 'text';
			}
			if ( isset( $field['custom_attributes']['readonly'] ) ) {
				unset( $field['custom_attributes']['readonly'] );
			}
			$extras[ $key ] = $field;
		}

		if ( empty( $extras ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				$missing = array();
				foreach ( $keys as $k ) {
					if ( empty( $fields['billing'][ $k ] ) || ! is_array( $fields['billing'][ $k ] ) ) {
						$missing[] = $k;
					}
				}
				if ( ! empty( $missing ) ) {
					error_log( '[OCWS] merge_address_extras_into_ocws_group: billing fields not merged: ' . implode( ', ', $missing ) );
				}
			}
			return $fields;
		}

		if ( ! isset( $fields['ocws'] ) || ! is_array( $fields['ocws'] ) ) {
			$fields['ocws'] = array();
		}

		$fields['ocws'] = array_merge( $extras, $fields['ocws'] );

		return $fields;
	}

	/**
	 * Render shipping method additional fields
	 * @return void
	 */
	public function render_shipping_additional_fields( $checkout=null )
	{
		ocws_render_shipping_additional_fields();
	}

	/**
	 * @param array $metaKeys
	 * @return array
	 */
    public function hidden_order_itemmeta($metaKeys)
	{
		$metaKeys[] = 'ocws_shipping_info';
		$metaKeys[] = 'ocws_lp_pickup_info';
		$metaKeys[] = 'ocws_shipping_info_date';
		$metaKeys[] = 'ocws_shipping_info_date_ts';
		$metaKeys[] = 'ocws_shipping_info_slot_start';
		$metaKeys[] = 'ocws_shipping_info_slot_end';

		$metaKeys[] = 'ocws_leave_at_the_door';
		$metaKeys[] = 'ocws_other_products';

		return $metaKeys;
	}

	/**
	 * @param string $text
	 * @param \WC_Order $order
	 * @return string
	 */
	public function email_shipping_info($text, $order)
	{
		// only in customer emails
		$html = OC_Woo_Shipping_Info::render_formatted_shipping_info( $order );
		//$html = '';
		return ($text . $html);
	}

	/**
	 * @param \WC_Order $order
	 * @return void
	 */
	public function order_details_after_order_table($order)
	{
		$chck1 = get_option('ocws_common_enable_at_the_door_checkbox', '');
		$chck2 = get_option('ocws_common_enable_other_products_checkbox', '');

		$ocws_leave_at_the_door = get_post_meta( $order->get_id(), 'ocws_leave_at_the_door', true );
		if( $ocws_leave_at_the_door == 1 )
			echo '<p><strong>' . esc_html(__($chck1, 'ocws')) . ': </strong> <span style="color:#22c646;">' . esc_html(__('enabled', 'ocws')) . '</span></p>';

		$ocws_other_products = get_post_meta( $order->get_id(), 'ocws_other_products', true );
		if( $ocws_other_products == 1 )
			echo '<p><strong>' . esc_html(__($chck2, 'ocws')) . ': </strong> <span style="color:#22c646;">' . esc_html(__('enabled', 'ocws')) . '</span></p>';
	}

	/**
	 * @param int $orderId
	 * @param array $data
	 * @param \WC_Order $order
	 */
	public function save_shipping_to_order($orderId, $data, $order)
	{
		OC_Woo_Shipping_Info::save_to_order($order);
	}

	public function validate_shipping_info()
	{
		$message = '<ul class="woocommerce-error" role="alert"><li>%s</li></ul>';
		$response = array(
			'messages'  => '',
			'refresh'   => false,
			'reload'    => false,
			'result'    => 'failure'
		);

		if (!isset($_POST['shipping_method'])) {
			return;
		}

		$shipping_methods = $_POST['shipping_method'];
		$is_ocws = false;

		foreach ($shipping_methods as $shipping_method) {
			if (substr($shipping_method, 0, strlen('oc_woo_advanced_shipping_method')) == 'oc_woo_advanced_shipping_method') {
				$is_ocws = true;
				break;
			}
		}

		if ($is_ocws) {
			$shipping_info = OC_Woo_Shipping_Info::get_shipping_info();
			if (!$shipping_info || !$shipping_info['date'] || !$shipping_info['slot_start'] || !$shipping_info['slot_end'] ) {
				$response['messages'] = sprintf($message, __('Please choose time slot', 'ocws'));
				header('Content-type: application/json');
				echo json_encode($response);
				exit;
			}
		}
	}

	public function custom_checkout_field() {

		$chck1 = get_option('ocws_common_enable_at_the_door_checkbox', '');
		$chck2 = get_option('ocws_common_enable_other_products_checkbox', '');

		if (!empty($chck1)) {
			echo '<div id="ocws_leave_at_the_door">';

			woocommerce_form_field( 'ocws_leave_at_the_door', array(
				'type'      => 'checkbox',
				'class'     => array('input-checkbox'),
				'label'     => __($chck1, 'ocws'),
			),  WC()->checkout->get_value( 'ocws_leave_at_the_door' ) );
			echo '</div>';
		}

		if (!empty($chck2)) {
			echo '<div id="ocws_custom_checkout_field">';

			woocommerce_form_field( 'ocws_other_products', array(
				'type'      => 'checkbox',
				'class'     => array('input-checkbox'),
				'label'     => __($chck2, 'ocws'),
			),  WC()->checkout->get_value( 'ocws_other_products' ) );
			echo '</div>';
		}
	}

	public function custom_checkout_field_update_order_meta( $order_id ) {

		if ( ! empty( $_POST['ocws_leave_at_the_door'] ) )
			update_post_meta( $order_id, 'ocws_leave_at_the_door', $_POST['ocws_leave_at_the_door'] );

		if ( ! empty( $_POST['ocws_other_products'] ) )
			update_post_meta( $order_id, 'ocws_other_products', $_POST['ocws_other_products'] );

	}

	public function print_shipping_notices() {
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
		$chosen_shipping = $chosen_methods[0];
		$show_shipping_notes = false;
		$show_pickup_notes = false;
		if (ocws_is_method_id_shipping($chosen_shipping)) {
			$show_shipping_notes = true;
		}
		else if (ocws_is_method_id_pickup($chosen_shipping)) {
			$show_pickup_notes = true;
		}
		?>
		<div class="ocws-shipping-notices">
		<?php OC_Woo_Shipping_Notices::print_notices(false, 'ocws_notices', (!$show_shipping_notes? array('ocws_hide') : array())); ?>
		<?php OC_Woo_Shipping_Notices::print_notices(false, 'ocws_lp_notices', (!$show_pickup_notes? array('ocws_hide') : array())); ?>
		</div>
		<?php
	}

	public function add_checkout_shipping_methods_fragment( $arr ) {
		global $woocommerce;
		$html = '';

        $disabled = false;
        $items = $woocommerce->cart->get_cart();
        foreach($items as $item => $values) {
            $parent = $values['data']->get_parent_id();
            $current = $values['data']->get_id();

            if (get_post_meta($parent == 0 ? $current : $parent, '_ocws_pickup_only', 'no') == 'yes') {
                $disabled = true;
                break;
            }
        }
        if ($disabled) {

			$message = get_option('ocws_common_pickup_only_message');
			if (empty($message)) {
				$message = __( 'Sorry, your cart contains pickup only products', 'ocws' );
			}
            if (!OC_Woo_Shipping_Notices::has_notice( $message, 'permanent-notice' )) {
                OC_Woo_Shipping_Notices::add_notice( $message, 'permanent-notice' );
            }
        }

		ob_start();

		?>

		<div class="header-shipping-methods">
			<?php if ( WC()->cart->needs_shipping() && WC()->cart->show_shipping() ) { ?>

				<?php do_action( 'woocommerce_review_order_before_shipping' ); ?>
				<div class="ship-title"><?php _e('Shipping methods' , 'ocws');?></div>
				<?php wc_cart_totals_shipping_html(); ?>

				<?php do_action( 'woocommerce_review_order_after_shipping' ); ?>

			<?php } else { ?>

				<div>
					<?php //echo "WC()->cart->needs_shipping() - " . (WC()->cart->needs_shipping()? 'yes' : 'no') ?>
				</div>
				<div>
					<?php //echo "wc_shipping_enabled() - " . (wc_shipping_enabled()? 'yes' : 'no') ?>
				</div>
				<div>
					<?php //echo "wc_get_shipping_method_count( true ) - " . (wc_get_shipping_method_count( true )) ?>
				</div>
				<div>
					<?php //echo "WC()->cart->show_shipping() - " . (WC()->cart->show_shipping()? 'yes' : 'no') ?>
				</div>

			<?php } ?>
		</div>

		<?php

		$html = ob_get_clean();

		$arr['.header-shipping-methods'] = $html;

		ob_start();

		$this->render_shipping_additional_fields();

		$html = ob_get_clean();

		$arr['#oc-woo-shipping-additional'] = $html;

		ob_start();

		OCWS_LP_Local_Pickup::render_pickup_additional_fields();

		$html = ob_get_clean();

		$arr['#oc-woo-pickup-additional'] = $html;

		ob_start();

		WC()->checkout()->checkout_form_billing();

		$billing_form = ob_get_clean();

		$html = str_get_html($billing_form);

		if ($html) {
			$ret = $html->find('div.woocommerce-billing-fields', 0);
			$arr['.woocommerce-billing-fields'] = $ret->outertext;
		}
		else {
			$arr['.woocommerce-billing-fields'] = 'MAX_FILE_SIZE: '.MAX_FILE_SIZE.', strlen: '.strlen($billing_form);
		}

		return $arr;
	}

	public function add_redirect_popup() {
		?>
		<div id="redirect-dialog" class="redirect-dialog-popup ocws-popup">
			<div class="white-overlay"></div>
			<div class="inner">
				<div class="inner-wrapper ui-dialog">
					<div class="ui-dialog-content ui-widget-content" title="" style="">
						<p class="cds-dialog-title"><?php echo esc_html(_x('You requested to change the delivery destination', 'Redirect dialog title', 'ocws')) ?></p>
						<p class="cds-dialog-text"><?php echo esc_html(_x('You are being redirected to the branch that serves the requested destination. Your cart will be updated according to product availability at the new branch.', 'Redirect dialog sub title', 'ocws')) ?></p>
					</div>
					<div class="ui-dialog-buttonpane ui-widget-content ui-helper-clearfix">
						<div class="ui-dialog-buttonset">
							<button type="button" class="ui-button ui-corner-all ui-widget"><?php echo esc_html(__('Continue to change', 'ocws')) ?></button>
							<button type="button" class="ui-button ui-corner-all ui-widget"><?php echo esc_html(__('Back to checkout', 'ocws')) ?></button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function add_shipping_redirect_popup() {
		?>
		<div id="shipping-redirect-dialog" class="redirect-dialog-popup ocws-popup">
			<div class="white-overlay"></div>
			<div class="inner">
				<div class="inner-wrapper ui-dialog">
					<div class="ui-dialog-content ui-widget-content" title="" style="">
						<p data-template="<?php echo esc_html(_x('You requested to change the delivery destination to', 'Shipping redirect dialog title', 'ocws').'[CITYNAME]'.'.') ?>" class="cds-dialog-title"></p>
						<p class="cds-dialog-text"><?php echo esc_html(_x('You are being redirected to the branch that serves the requested destination. Your cart will be updated according to product availability at the new branch.', 'Shipping redirect dialog sub title', 'ocws')) ?></p>
					</div>
					<div class="ui-dialog-buttonpane ui-widget-content ui-helper-clearfix">
						<div class="ui-dialog-buttonset">
							<button type="button" class="ui-button ui-corner-all ui-widget"><?php echo esc_html(__('Continue to change', 'ocws')) ?></button>
							<button type="button" class="ui-button ui-corner-all ui-widget"><?php echo esc_html(__('Back to checkout', 'ocws')) ?></button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function add_pickup_redirect_popup() {
		?>
		<div id="pickup-redirect-dialog" class="redirect-dialog-popup ocws-popup">
			<div class="white-overlay"></div>
			<div class="inner">
				<div class="inner-wrapper ui-dialog">
					<div class="ui-dialog-content ui-widget-content" title="" style="">
						<p data-template="<?php echo esc_html(_x('You requested to change the delivery destination to', 'Shipping redirect dialog title', 'ocws').'[CITYNAME]'.'.') ?>" class="cds-dialog-title"></p>
						<p class="cds-dialog-text"><?php echo esc_html(_x('You are being redirected to the branch that serves the requested destination. Your cart will be updated according to product availability at the new branch.', 'Shipping redirect dialog sub title', 'ocws')) ?></p>
					</div>
					<div class="ui-dialog-buttonpane ui-widget-content ui-helper-clearfix">
						<div class="ui-dialog-buttonset">
							<button type="button" class="ui-button ui-corner-all ui-widget"><?php echo esc_html(__('Continue to change', 'ocws')) ?></button>
							<button type="button" class="ui-button ui-corner-all ui-widget"><?php echo esc_html(__('Back to checkout', 'ocws')) ?></button>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function add_shipping_popup() {

		echo '<div id="popup_test" style="display: none;"></div>';

		if (is_checkout() || wp_doing_ajax()) return;

		$forse_use_popup = true;

		// added checkbox in general settings
		$use_popup 	= get_option('ocws_common_use_popup');
		$deli_loaded = ocws_use_deli_style();
		if ($deli_loaded) {
			return;
		}
		if ( !$use_popup && !$forse_use_popup ){
			return;
		}

		$show_popup = false;
		do_action( 'ocws_maybe_fix_shipping_method' );
		$chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
		if (empty($chosen_methods)) {
			$show_popup = true;
		}
		else {
			$is_ocws = false;

			foreach ($chosen_methods as $shippingMethod) {
				if (substr($shippingMethod, 0, strlen('oc_woo_advanced_shipping_method')) == 'oc_woo_advanced_shipping_method') {
					$is_ocws = true;
					break;
				}
			}
			if ($is_ocws) {
				$chosen_city = WC()->checkout->get_value('billing_city');
				if (!$chosen_city || !ocws_is_location_enabled($chosen_city)) {
					$show_popup = true;
				}
				else {
					$popup_shipping_info = OC_Woo_Shipping_Info::get_shipping_info_from_session();
					if (!$popup_shipping_info['date']) {
						$show_popup = true;
					}
				}
			}
		}

		if (!$show_popup) {
			//return;
		}
		?>

		<?php

		/*$template = ocws_get_template_part('public/popup.php');
		if (!empty($template) && file_exists($template)) {
			include($template);
		}*/
		OCWS_Popup::output_shipping_popup();

		?>



		<?php

		wc_enqueue_js( "
	jQuery( ':input.ocws-enhanced-select' ).filter( ':not(.enhanced)' ).each( function() {
		var select2_args = { minimumResultsForSearch: 5 };
		jQuery( this ).select2( select2_args ).addClass( 'enhanced' );
	});" );
	}

	public function add_checkout_choose_city_popup() {

		if (!is_checkout() || wp_doing_ajax()) return;

		$template = ocws_get_template_part('public/checkout-popup.php');
		if (!empty($template) && file_exists($template)) {
			include($template);
		}

		wc_enqueue_js( "
	jQuery( ':input.ocws-enhanced-select' ).filter( ':not(.enhanced)' ).each( function() {
		var select2_args = { minimumResultsForSearch: 5 };
		jQuery( this ).select2( select2_args ).addClass( 'enhanced' );
	});" );
	}

	public function add_city_list_popup() {

		if (!is_checkout() || wp_doing_ajax()) return;

		$template = ocws_get_template_part('public/checkout-city-list-popup.php');
		if (!empty($template) && file_exists($template)) {
			include($template);
		}
		wc_enqueue_js( "
	jQuery( ':input.ocws-enhanced-select' ).filter( ':not(.enhanced)' ).each( function() {
		var select2_args = { minimumResultsForSearch: 5 };
		jQuery( this ).select2( select2_args ).addClass( 'enhanced' );
	});" );
	}

	public function add_branch_list_popup() {

		if (!is_checkout() || wp_doing_ajax()) return;

		$template = ocws_get_template_part('public/checkout-branch-list-popup.php');
		if (!empty($template) && file_exists($template)) {
			include($template);
		}
	}

	/**
	 * Filter the cart template path to use our cart.php template instead of the theme's
	 */
	public function locate_woo_template( $template, $template_name, $template_path ) {
		$basename = basename( $template );
		/*if( $basename == 'cart-shipping.php' ) {
			$template = trailingslashit( plugin_dir_path( dirname( __FILE__ ) ) ) . 'templates/cart-shipping.php';
		}*/
		if( is_multisite() && $basename == 'form-billing.php' ) {
			$template = trailingslashit( plugin_dir_path( dirname( __FILE__ ) ) ) . 'templates/form-billing.php';
		}
		return $template;
	}


	//add_filter( 'ocws_send_to_other_person_fields', 'ocws_send_to_other_person_fields' );
	public function ocws_send_to_other_person_fields() {
		if (isset($_POST['post_data'])) {

			parse_str($_POST['post_data'], $post_data);

		} else {

			$post_data = $_POST; // fallback for final checkout (non-ajax)

		}
		$chosen_methods = WC()->session->get('chosen_shipping_methods');
		$chosen_shipping = isset( $chosen_methods[0] ) ? $chosen_methods[0] : '';
		$local_pickup_chosen = ( $chosen_shipping && strstr( $chosen_shipping, 'local_pickup' ) );
		// אפשר להחזיר את ההתנהגות הישנה (הסתרה באיסוף עצמי) עם: add_filter( 'ocws_hide_send_to_other_for_local_pickup', '__return_true' );
		if ( $local_pickup_chosen && apply_filters( 'ocws_hide_send_to_other_for_local_pickup', false ) ) {
			return;
		}
		$send_to_other_person_default = get_option('ocws_common_enable_send_to_other_checked_by_default', '') != 1 ? 0 : 1;

		$send_to_other_person_hidden = (isset($post_data['ocws_other_recipient_hidden']) && in_array($post_data['ocws_other_recipient_hidden'], array('yes', 'no'))) ? $post_data['ocws_other_recipient_hidden'] : '';

		if ($send_to_other_person_hidden == '') {
			$send_to_other_person_hidden = ocws_get_session_checkout_field('ocws_other_recipient_hidden');
		}
		if ($send_to_other_person_hidden == '') {
			$send_to_other_person = $send_to_other_person_default;
		}
		else {
			if ($send_to_other_person_hidden == 'yes' && (isset($post_data['ocws_other_recipient']) || ocws_get_session_checkout_field('ocws_other_recipient'))) {
				$send_to_other_person = 1;
			}
			else {
				$send_to_other_person = 0;
			}
		}

		//echo '<div id="ocws_other_recipient_container">';

		$l = get_option('ocws_common_checkout_send_to_other_checkbox_label');

		if (empty($l)) {
			$general_options_defaults = OC_Woo_Shipping_Group_Option::get_general_options_defaults();
			if (isset($general_options_defaults['checkout_send_to_other_checkbox_label'])) {
				$l = $general_options_defaults['checkout_send_to_other_checkbox_label'];
			}
		}

		woocommerce_form_field( 'ocws_other_recipient', array(
			'type'      => 'checkbox',
			'class'     => array('form-row-wide', 'other-recipient-field', 'checkbox', WC()->checkout->get_value( 'ocws_other_recipient' ), 'ocws_update_checkout_on_change'),
			'label'     => (empty($l)? __('Send to other person', 'ocws') : $l),
			'clear'		=> false,
		),  $send_to_other_person );
		//echo '</div>';

		woocommerce_form_field( 'ocws_other_recipient_hidden', array(
			'type'      => 'hidden',
			'class' => array('ocws_update_checkout_on_change')
		),  $send_to_other_person_hidden );

		if (!$send_to_other_person) return;

		echo '<h4 class="checkout-recipient-details-heading">' . esc_html__( 'Recipient details', 'ocws' ) . '</h4>';

		//echo '<p class="form-row form-row-first other-recipient-field" style="'.($send_to_other_person? '' : 'display: none;').'">';

		$ocws_recipient_firstname = ocws_get_value( 'ocws_recipient_firstname', $post_data );
		if (empty($ocws_recipient_firstname)) {
			$ocws_recipient_firstname = ocws_get_session_checkout_field('ocws_recipient_firstname');
		}
		woocommerce_form_field( 'ocws_recipient_firstname', array(
			'type'      => 'text',
			'class'     => array('form-row-wide', 'other-recipient-field', 'other-recipient-field-toggle', 'ocws_update_checkout_on_change', 'label-on'),
			'label'     => __('Recipient first name', 'ocws'),
			'clear'		=> false,
          	'placeholder' => __('Recipient first name', 'ocws') . ' *',
			'required'	=> !!$send_to_other_person,
		),  $ocws_recipient_firstname );
		//echo '</p>';

		//echo '<p class="form-row form-row-last other-recipient-field" style="'.($send_to_other_person? '' : 'display: none;').'">';

		$ocws_recipient_lastname = ocws_get_value( 'ocws_recipient_lastname', $post_data );
		if (empty($ocws_recipient_lastname)) {
			$ocws_recipient_lastname = ocws_get_session_checkout_field('ocws_recipient_lastname');
		}
		woocommerce_form_field( 'ocws_recipient_lastname', array(
			'type'      => 'text',
			'class'     => array('form-row-wide', 'other-recipient-field', 'other-recipient-field-toggle', 'ocws_update_checkout_on_change', 'label-on'),
			'label'     => __('Recipient last name', 'ocws'),
			'clear'		=> false,
          	'placeholder' => __('Recipient last name', 'ocws') . ' *',
			'required'	=> !!$send_to_other_person,
		),  $ocws_recipient_lastname );
		//echo '</p>';

		//echo '<p class="form-row form-row-wide other-recipient-field" style="'.($send_to_other_person? '' : 'display: none;').'">';

		$ocws_recipient_phone = ocws_get_value( 'ocws_recipient_phone', $post_data );
		if (empty($ocws_recipient_phone)) {
			$ocws_recipient_phone = ocws_get_session_checkout_field('ocws_recipient_phone');
		}
		woocommerce_form_field( 'ocws_recipient_phone', array(
			'type'      => 'text',
			'class'     => array('form-row-wide', 'other-recipient-field', 'other-recipient-field-toggle', 'ocws_update_checkout_on_change', 'label-on'),
			'label'     => __('Recipient phone', 'ocws'),
			'clear'		=> false,
          	'placeholder' => __('Recipient phone', 'ocws') . ' *',
			'required'	=> !!$send_to_other_person,
		),  $ocws_recipient_phone );
		//echo '</p>';

		$ocws_recipient_phone2 = ocws_get_value( 'ocws_recipient_phone2', $post_data );
		if (empty($ocws_recipient_phone2)) {
			$ocws_recipient_phone2 = ocws_get_session_checkout_field('ocws_recipient_phone2');
		}
		woocommerce_form_field( 'ocws_recipient_phone2', array(
			'type'      => 'text',
			'class'     => array('form-row-wide', 'other-recipient-field', 'other-recipient-field-toggle', 'ocws_update_checkout_on_change', 'label-on'),
			'label'     => __('Additional phone', 'ocws'),
			'clear'		=> false,
			'placeholder' => __('Additional phone', 'ocws'),
			'required'	=> false,
		),  $ocws_recipient_phone2 );

	}


	//add_filter( 'ocws_send_to_other_person_greeting', 'ocws_send_to_other_person_greeting' );
	public function ocws_send_to_other_person_greeting() {

		if ( isset( $_POST['post_data'] ) ) {

			parse_str( $_POST['post_data'], $post_data );

		} else {

			$post_data = $_POST; // fallback for final checkout (non-ajax)

		}

		$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
		$chosen_shipping = isset( $chosen_methods[0] ) ? $chosen_methods[0] : '';
		$local_pickup_chosen = ( $chosen_shipping && ( strstr( $chosen_shipping, 'local_pickup' ) ) );

		if ( $local_pickup_chosen && apply_filters( 'ocws_hide_send_to_other_for_local_pickup', false ) ) {
			return;
		}

		$send_to_other_person_default = get_option('ocws_common_enable_send_to_other_checked_by_default', '') != 1 ? 0 : 1;

		$send_to_other_person_hidden = ( isset($post_data['ocws_other_recipient_hidden']) && in_array( $post_data['ocws_other_recipient_hidden'], array('yes', 'no') ) )? $post_data['ocws_other_recipient_hidden'] : '';

		if ($send_to_other_person_hidden == '') {
			$send_to_other_person = $send_to_other_person_default;
		}
		else {
			if ($send_to_other_person_hidden == 'yes' && isset($post_data['ocws_other_recipient'])) {
				$send_to_other_person = 1;
			}
			else {
				$send_to_other_person = 0;
			}
		}

		if (!$send_to_other_person) return;

		$enable_greeting = get_option('ocws_common_enable_greeting_field', '') != 1 ? 0 : 1;

		if (!$enable_greeting) return;

		$ocws_recipient_greeting = ocws_get_value( 'ocws_recipient_greeting', $post_data );
		if (empty($ocws_recipient_greeting)) {
			$ocws_recipient_greeting = ocws_get_session_checkout_field('ocws_recipient_greeting');
		}
		woocommerce_form_field( 'ocws_recipient_greeting', array(
			'type'      => 'textarea',
			'class'     => array('form-row-wide', 'other-recipient-field', 'other-recipient-field-toggle', 'ocws_update_checkout_on_change'),
			'label'     => __('Greeting', 'ocws'),
			'clear'		=> false,
			'placeholder' => __('Type your greeting here', 'ocws'),
			'required'	=> false,
		),  $ocws_recipient_greeting);

	}


    public function woo_checkout_order_processed( $order_id, $data, $order ) {
        // $order לפעמים לא מגיע / לא WC_Order - נגן
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) {
            return;
        }

        $send_to_other_person = ! empty( $_POST['ocws_other_recipient'] );

        if ( ! $send_to_other_person ) {
            return;
        }

        // custom meta (עדיף דרך order)
        $order->update_meta_data( 'ocws_other_recipient', 1 );

        if ( ! empty( $_POST['ocws_recipient_firstname'] ) ) {
            $first = sanitize_text_field( wp_unslash( $_POST['ocws_recipient_firstname'] ) );

            $order->update_meta_data( 'ocws_recipient_firstname', $first );
            $order->set_shipping_first_name( $first ); // ✅ במקום _shipping_first_name
        }

        if ( ! empty( $_POST['ocws_recipient_lastname'] ) ) {
            $last = sanitize_text_field( wp_unslash( $_POST['ocws_recipient_lastname'] ) );

            $order->update_meta_data( 'ocws_recipient_lastname', $last );
            $order->set_shipping_last_name( $last ); // ✅ במקום _shipping_last_name
        }

        if ( ! empty( $_POST['ocws_recipient_phone'] ) ) {
            $phone = sanitize_text_field( wp_unslash( $_POST['ocws_recipient_phone'] ) );

            $order->update_meta_data( 'ocws_recipient_phone', $phone );
            $order->set_shipping_phone( $phone ); // ✅ במקום _shipping_phone
        }

        if ( ! empty( $_POST['ocws_recipient_phone2'] ) ) {
            $phone2 = sanitize_text_field( wp_unslash( $_POST['ocws_recipient_phone2'] ) );
            $order->update_meta_data( 'ocws_recipient_phone2', $phone2 );
        }

        $order->save();
    }

	public function woo_checkout_add_shipping_phone( $fields ) {

		$fields['shipping']['shipping_phone'] = array(
			'label' => 'Phone',
			'required' => false,
			'class' => array( 'form-row-wide' ),
			'priority' => 25,
		);
		return $fields;

	}

	/* 'woocommerce_checkout_fields' filter*/
	public function woo_checkout_add_billing_street( $fields ) {

		$street_args = wp_parse_args( array(
			'label'        => __( 'Street', 'ocws' ),
			'placeholder'  => esc_attr__( 'Street', 'ocws' ),
			'required'     => true,
		), $fields['billing']['billing_address_1'] );

		$fields['billing']['billing_street'] = $street_args;

		$fields['billing']['billing_address_1']['required'] = false;
		$fields['billing']['billing_address_1']['class'][] = 'ocws-hidden-form-field';
		return $fields;

	}

	/* 'woocommerce_checkout_fields' filter*/
	public function woo_checkout_add_billing_house_num( $fields ) {

		if ( isset( $fields['billing']['billing_address_2'] ) && !isset( $fields['billing']['house_num'] ) ) {

			$house_num_args = wp_parse_args( array(
				'label'        => __( 'House', 'ocws' ),
				'placeholder'  => esc_attr__( 'House', 'ocws' ),
				'required'     => true,
			), $fields['billing']['billing_address_2'] );

			$fields['billing']['billing_house_num'] = $house_num_args;

			$fields['billing']['billing_address_2']['required'] = false;
			$fields['billing']['billing_address_2']['class'][] = 'ocws-hidden-form-field';
		}
		else if ( !isset($fields['billing']['house_num']) ) {
			$fields['house_num'] = array(
				'label'     => __('בית', 'woocommerce'),
				'placeholder'   => _x('בית', 'placeholder', 'woocommerce'),
				'required'  => true,
				'class'     => array('form-row'),
				'clear'     => false
			);
		}

		return $fields;
	}

	public function add_default_billing_street( $fields ) {

		if ( !isset( $fields['street'] ) ) {
			$street_args = array(
				'label' => __('Street', 'ocws'),
				'placeholder' => esc_attr__('Street', 'ocws'),
				'required' => true,
				'class' => array(
					'form-row-wide',
					'address-field'
				),
				'autocomplete' => '',
				'priority' => 50,
				'type' => 'text'
			);

			$fields['street'] = $street_args;

			if ( isset( $fields['address_1'] ) ) {

				$fields['address_1']['required'] = false;
				$fields['address_1']['class'][] = 'ocws-hidden-form-field';
			}
		}
		return $fields;
	}


	public function add_default_billing_house_num( $fields ) {

		if ( !isset( $fields['house_num'] ) ) {
			$house_num_args = array(
				'label' => __('House number', 'ocws'),
				'placeholder' => esc_attr__('House number', 'ocws'),
				'required' => true,
				'class' => array(
					'form-row-wide',
					'address-field'
				),
				'autocomplete' => '',
				'priority' => 60,
				'type' => 'text'
			);

			$fields['house_num'] = $house_num_args;

			if ( isset( $fields['address_2'] ) ) {

				$fields['address_2']['required'] = false;
				$fields['address_2']['class'][] = 'ocws-hidden-form-field';
			}
		}
		return $fields;

	}

	public function add_default_billing_enter_code( $fields ) {

		if ( !isset( $fields['enter_code'] ) ) {
			$enter_code_args = array(
				'label' => __('Entry code', 'ocws'),
				'placeholder' => esc_attr__('Entry code', 'ocws'),
				'required' => false,
				'class' => array(
					'form-row-first',
					'address-field'
				),
				'autocomplete' => '',
				'priority' => 6,
				'type' => 'text'
			);

			$fields['enter_code'] = $enter_code_args;

		}
		return $fields;

	}

	/**
	 * @param int $orderId
	 * @param array $data
	 * @param \WC_Order $order
	 */
	public function save_full_address_to_order($orderId, $data, $order)	{

		ocws_save_full_address_to_order($order);
	}

	public function woocommerce_customer_save_address_action($user_id, $load_address) {

		if ($load_address == 'billing') {

			update_user_meta($user_id, 'billing_address_1', get_user_meta($user_id, 'billing_street', true) . ' ' . get_user_meta($user_id, 'billing_house_num', true));
		}
		else if ($load_address == 'shipping') {

			update_user_meta($user_id, 'shipping_address_1', get_user_meta($user_id, 'shipping_street', true) . ' ' . get_user_meta($user_id, 'shipping_house_num', true));
		}
	}

	public function process_checkout_field_billing_city($value) {

		$chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );

		if ( isset( $_POST['post_data'] ) ) {
			parse_str( $_POST['post_data'], $post_data );
		} else {
			$post_data = $_POST; // fallback for final checkout (non-ajax)
		}

		if (empty($chosen_methods)) {
			if (isset($post_data['shipping_method']) && is_array($post_data['shipping_method'])) {
				$chosen_methods = $post_data['shipping_method'];
			}
		}
		$is_ocws = false;

		foreach ($chosen_methods as $shippingMethod) {
			if (substr($shippingMethod, 0, strlen('oc_woo_advanced_shipping_method')) == 'oc_woo_advanced_shipping_method') {
				$is_ocws = true;
				break;
			}
		}
		if ($is_ocws && ocws_use_google_cities_and_polygons() && isset($post_data['billing_city_name'])) {
			return $post_data['billing_city_name'];
		}
		return $value;
	}

	public function clear_checkout_session() {

		$keys = array(
			'deli_add_to_cart_pending_product',
			'deli_add_to_cart_pending_quantity',
			'deli_add_to_cart_error_product',
			'chosen_address_coords',
			'chosen_street',
			'chosen_house_num',
			'chosen_enter_code',
			'chosen_city_name',
			'chosen_city_code',
			'chosen_shipping_city',
			'chosen_shipping_city',
			'chosen_pickup_aff',
			'checkout_data',
		);

		if (isset(WC()->session)) {
			foreach ($keys as $key) {
				WC()->session->set($key, null);
			}
		}

		OC_Woo_Shipping_Info::clear_shipping_info();
		OCWS_LP_Pickup_Info::clear_pickup_info();

		if ( isset( WC()->session ) ) {
			WC()->session->set( 'ocws_delivery_prefs_backup', null );
		}
		if ( isset( WC()->session ) ) {
			WC()->session->set( 'ocws_pending_shipping_realign', null );
		}
		if ( isset( WC()->session ) ) {
			WC()->session->set( 'ocws_realign_shipping_after_totals', null );
		}
	}

	/**
	 * When the user logs out, drop WooCommerce session data so the browser cookie does not keep
	 * the previous customer's checkout address (OCWS session keys + WC customer session).
	 *
	 * @param int $user_id User ID that was logged out (WordPress passes this on wp_logout).
	 */
	public function clear_wc_session_on_logout( $user_id = 0 ) {
		if ( ! function_exists( 'WC' ) ) {
			return;
		}

		$this->clear_checkout_session();

		if ( isset( WC()->session ) && method_exists( WC()->session, 'destroy_session' ) ) {
			WC()->session->destroy_session();
		}

		if ( isset( WC()->cart ) ) {
			WC()->cart->empty_cart( true );
		}
	}

	/**
	 * After explicit popup submit: clear one-shot relink when the cart is not empty; keep when empty for next add.
	 */
	public static function sync_ocws_pending_shipping_realign_after_popup_save() {
		if ( ! isset( WC()->session ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		if ( WC()->cart->is_empty() ) {
			WC()->session->set( 'ocws_pending_shipping_realign', 1 );
			self::log_realign_debug( 'popup_save_set_pending_for_empty_cart' );
		} else {
			WC()->session->set( 'ocws_pending_shipping_realign', null );
			self::log_realign_debug( 'popup_save_cleared_pending_cart_has_items' );
		}
	}

	/**
	 * Session keys to preserve when the cart becomes empty (WooCommerce may clear shipping session data).
	 *
	 * @return string[]
	 */
	public static function ocws_delivery_prefs_persistence_keys() {
		$keys = array(
			'chosen_shipping_methods',
			'sync_chosen_shipping_methods',
			'chosen_address_coords',
			'chosen_street',
			'chosen_house_num',
			'chosen_enter_code',
			'chosen_city_name',
			'chosen_city_code',
			'chosen_shipping_city',
			'chosen_pickup_aff',
			'checkout_data',
			'ocws_shipping_popup_confirmed',
			'ocws_shipping_info',
			'ocws_lp_pickup_info',
		);

		return apply_filters( 'ocws_delivery_prefs_persistence_keys', $keys );
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function collect_ocws_delivery_prefs_snapshot() {
		$out = array();
		if ( ! isset( WC()->session ) ) {
			return $out;
		}
		foreach ( self::ocws_delivery_prefs_persistence_keys() as $key ) {
			$out[ $key ] = WC()->session->get( $key );
		}

		return $out;
	}

	/**
	 * @param array<string, mixed> $data Snapshot from collect_ocws_delivery_prefs_snapshot().
	 */
	public static function apply_ocws_delivery_prefs_snapshot( $data ) {
		if ( ! isset( WC()->session ) || ! is_array( $data ) ) {
			return;
		}
		$data = self::ocws_sanitize_sync_matches_chosen_in_data( $data );
		foreach ( self::ocws_delivery_prefs_persistence_keys() as $key ) {
			if ( array_key_exists( $key, $data ) ) {
				WC()->session->set( $key, $data[ $key ] );
			}
		}
	}

	/**
	 * `sync_chosen_shipping_methods` (multisite / legacy) can stay on home while `chosen` is pickup — then backups and relinks re-apply the wrong method.
	 * Trust `chosen_shipping_methods` and mirror it onto sync when the two disagree on pickup vs home (OCWS).
	 *
	 * @param array<string, mixed> $data Snapshot; not modified by reference, returns new array.
	 * @return array<string, mixed>
	 */
	public static function ocws_sanitize_sync_matches_chosen_in_data( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$ch = isset( $data['chosen_shipping_methods'] ) && is_array( $data['chosen_shipping_methods'] ) ? $data['chosen_shipping_methods'] : array();
		$sy = isset( $data['sync_chosen_shipping_methods'] ) && is_array( $data['sync_chosen_shipping_methods'] ) ? $data['sync_chosen_shipping_methods'] : array();
		if ( empty( $ch[0] ) ) {
			return $data;
		}
		if ( empty( $sy[0] ) ) {
			$data['sync_chosen_shipping_methods'] = $ch;
			return $data;
		}
		$k_ch = self::ocws_chosen_shipping_rate_kind( (string) $ch[0] );
		$k_sy = self::ocws_chosen_shipping_rate_kind( (string) $sy[0] );
		// "other" (e.g. flat_rate) vs OCWS: still align to chosen to avoid a stale home rate after pickup.
		if ( ( 'pickup' === $k_ch || 'shipping' === $k_ch ) && $k_ch !== $k_sy ) {
			$data['sync_chosen_shipping_methods'] = $ch;
		}
		return $data;
	}

	/**
	 * Fix live session if sync drifted (call after set_shipping / set_pickup AJAX).
	 */
	public static function session_align_sync_to_chosen_shipping() {
		if ( ! isset( WC()->session ) || ! function_exists( 'WC' ) ) {
			return;
		}
		$ch = WC()->session->get( 'chosen_shipping_methods', array() );
		if ( ! is_array( $ch ) || empty( $ch[0] ) ) {
			return;
		}
		$sy   = WC()->session->get( 'sync_chosen_shipping_methods', array() );
		$data = self::ocws_sanitize_sync_matches_chosen_in_data(
			array(
				'chosen_shipping_methods'            => $ch,
				'sync_chosen_shipping_methods'        => is_array( $sy ) ? $sy : array(),
			)
		);
		if ( isset( $data['sync_chosen_shipping_methods'] ) ) {
			WC()->session->set( 'sync_chosen_shipping_methods', $data['sync_chosen_shipping_methods'] );
		}
	}

	/**
	 * @param array<string, mixed> $data Snapshot data.
	 */
	public static function ocws_delivery_prefs_backup_is_meaningful( $data ) {
		if ( ! is_array( $data ) ) {
			return false;
		}
		if ( ! empty( $data['ocws_shipping_popup_confirmed'] ) ) {
			return true;
		}
		$m = isset( $data['chosen_shipping_methods'] ) ? $data['chosen_shipping_methods'] : array();
		if ( is_array( $m ) && ! empty( $m[0] ) ) {
			return true;
		}
		$aff = isset( $data['chosen_pickup_aff'] ) ? $data['chosen_pickup_aff'] : null;
		if ( null !== $aff && '' !== $aff && false !== $aff ) {
			return true;
		}
		$cd = isset( $data['checkout_data'] ) ? $data['checkout_data'] : array();
		if ( is_array( $cd ) && ( ! empty( $cd['billing_city'] ) || ! empty( $cd['billing_city_code'] ) || ! empty( $cd['ocws_lp_pickup_aff_id'] ) ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Keep backup in sync after popup AJAX saves (empty cart can still update delivery).
	 */
	public static function refresh_ocws_delivery_prefs_backup_from_session() {
		if ( ! isset( WC()->session ) ) {
			return;
		}
		$data = self::collect_ocws_delivery_prefs_snapshot();
		$data = self::ocws_sanitize_sync_matches_chosen_in_data( $data );
		if ( self::ocws_delivery_prefs_backup_is_meaningful( $data ) ) {
			WC()->session->set( 'ocws_delivery_prefs_backup', $data );
		}
	}

	/**
	 * Log to WooCommerce status logs (file under wp-content/uploads/wc-logs/…).
	 * Enable: in wp-config.php: define( 'OCWS_SHIPPING_REALIGN_LOG', true );
	 * Or: add_filter( 'ocws_shipping_realign_log_enabled', '__return_true' );
	 *
	 * @param string                    $event Short label in English.
	 * @param array<string, mixed>|null $extra Optional fields (kept small).
	 */
	public static function log_realign_debug( $event, $extra = null ) {
		if ( ! apply_filters( 'ocws_shipping_realign_log_enabled', defined( 'OCWS_SHIPPING_REALIGN_LOG' ) && constant( 'OCWS_SHIPPING_REALIGN_LOG' ) ) ) {
			return;
		}
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}
		$ctx = array( 'event' => (string) $event );
		if ( isset( WC()->session ) ) {
			$ctx['session_chosen_shipping_0'] = null;
			$m = WC()->session->get( 'chosen_shipping_methods' );
			if ( is_array( $m ) && ! empty( $m[0] ) ) {
				$ctx['session_chosen_shipping_0'] = (string) $m[0];
			}
			$ctx['session_sync_0'] = null;
			$s = WC()->session->get( 'sync_chosen_shipping_methods' );
			if ( is_array( $s ) && ! empty( $s[0] ) ) {
				$ctx['session_sync_0'] = (string) $s[0];
			}
			$ctx['chosen_pickup_aff']        = WC()->session->get( 'chosen_pickup_aff' );
			$ctx['ocws_popup_confirmed']     = (bool) WC()->session->get( 'ocws_shipping_popup_confirmed' );
			$ctx['ocws_pending_shipping']      = WC()->session->get( 'ocws_pending_shipping_realign' );
		}
		if ( is_array( $extra ) && ! empty( $extra ) ) {
			$ctx = array_merge( $ctx, $extra );
		}
		wc_get_logger()->debug( wp_json_encode( $ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), array( 'source' => 'ocws-shipping-realign' ) );
	}

	/**
	 * WooCommerce may clear `chosen_shipping_methods` in session after the last line is removed but before we snapshot
	 * on {@see 'woocommerce_cart_item_removed'}. Merge rate ids from the previous backup if the new snapshot lost them.
	 *
	 * @param array<string, mixed>      $data     Fresh snapshot.
	 * @param array<string, mixed>|null $previous Last `ocws_delivery_prefs_backup` (or any prior snapshot).
	 * @return array<string, mixed>
	 */
	private static function ocws_merge_snapshot_with_previous_if_lost_shipping( $data, $previous ) {
		$data  = is_array( $data ) ? $data : array();
		$prev  = is_array( $previous ) ? $previous : array();
		$cur_m = isset( $data['chosen_shipping_methods'] ) && is_array( $data['chosen_shipping_methods'] ) ? $data['chosen_shipping_methods'] : array();
		$pr_m  = isset( $prev['chosen_shipping_methods'] ) && is_array( $prev['chosen_shipping_methods'] ) ? $prev['chosen_shipping_methods'] : array();
		$cur_has = ! empty( $cur_m[0] );
		$pr_has  = ! empty( $pr_m[0] );
		if ( $pr_has && ! $cur_has ) {
			$data['chosen_shipping_methods'] = $pr_m;
			$psync                           = isset( $prev['sync_chosen_shipping_methods'] ) && is_array( $prev['sync_chosen_shipping_methods'] ) ? $prev['sync_chosen_shipping_methods'] : $pr_m;
			if ( ! empty( $psync[0] ) ) {
				$data['sync_chosen_shipping_methods'] = $psync;
			}
			self::log_realign_debug(
				'merge_lost_chosen_shipping',
				array(
					'prev_chosen_0' => (string) $pr_m[0],
				)
			);
		}
		return self::ocws_sanitize_sync_matches_chosen_in_data( $data );
	}

	/**
	 * @param array<string, mixed> $data Meaningful delivery snapshot.
	 */
	public static function persist_ocws_delivery_backup_and_pending( $data ) {
		if ( ! isset( WC()->session ) || ! is_array( $data ) || ! self::ocws_delivery_prefs_backup_is_meaningful( $data ) ) {
			return;
		}
		$data = self::ocws_sanitize_sync_matches_chosen_in_data( $data );
		$bk0  = ( isset( $data['chosen_shipping_methods'] ) && is_array( $data['chosen_shipping_methods'] ) && ! empty( $data['chosen_shipping_methods'][0] ) )
			? (string) $data['chosen_shipping_methods'][0] : '';
		self::log_realign_debug(
			'persist_backup',
			array(
				'backup_chosen_0' => $bk0,
			)
		);
		WC()->session->set( 'ocws_delivery_prefs_backup', $data );
		WC()->session->set( 'ocws_pending_shipping_realign', 1 );
	}

	/**
	 * Runs **before** the line is unset — session still has chosen rates while 1 line remains. Captures a reliable backup
	 * for “remove last product then add again” (see `woocommerce_remove_cart_item` in WC_Cart::remove_cart_item).
	 *
	 * @param string   $cart_item_key Key being removed.
	 * @param \WC_Cart $cart         Cart.
	 */
	public function maybe_snapshot_ocws_delivery_prefs_on_remove_cart_item( $cart_item_key, $cart ) {
		if ( ! isset( WC()->session ) || ! $cart || ! is_a( $cart, 'WC_Cart' ) ) {
			return;
		}
		$contents = $cart->get_cart();
		if ( 1 !== count( $contents ) || ! isset( $contents[ $cart_item_key ] ) ) {
			return;
		}
		self::log_realign_debug( 'remove_cart_item_last_line', array( 'cart_item_key' => (string) $cart_item_key ) );
		$prev = WC()->session->get( 'ocws_delivery_prefs_backup' );
		$data = self::collect_ocws_delivery_prefs_snapshot();
		$data = self::ocws_merge_snapshot_with_previous_if_lost_shipping( $data, is_array( $prev ) ? $prev : null );
		self::persist_ocws_delivery_backup_and_pending( $data );
	}

	/**
	 * Snapshot delivery prefs before WooCommerce clears them on full cart empty.
	 *
	 * @param bool $clear_persistent_cart WC core arg.
	 */
	public function maybe_backup_ocws_delivery_prefs_before_cart_emptied( $clear_persistent_cart = true ) {
		if ( ! isset( WC()->session ) ) {
			return;
		}
		$prev = WC()->session->get( 'ocws_delivery_prefs_backup' );
		$data = self::collect_ocws_delivery_prefs_snapshot();
		$data = self::ocws_merge_snapshot_with_previous_if_lost_shipping( $data, is_array( $prev ) ? $prev : null );
		if ( ! self::ocws_delivery_prefs_backup_is_meaningful( $data ) ) {
			return;
		}
		self::persist_ocws_delivery_backup_and_pending( $data );
	}

	/**
	 * Snapshot when the last line item is removed (cart just became empty). Session may already have lost chosen rates; merge
	 * with backup taken on {@see 'woocommerce_remove_cart_item'} or a prior backup.
	 *
	 * @param string   $cart_item_key Key.
	 * @param \WC_Cart $cart         Cart.
	 */
	public function maybe_backup_ocws_delivery_prefs_last_item_removed( $cart_item_key, $cart ) {
		if ( ! isset( WC()->session ) || ! $cart || ! $cart->is_empty() ) {
			return;
		}
		$prev = WC()->session->get( 'ocws_delivery_prefs_backup' );
		$data = self::collect_ocws_delivery_prefs_snapshot();
		$data = self::ocws_merge_snapshot_with_previous_if_lost_shipping( $data, is_array( $prev ) ? $prev : null );
		if ( ! self::ocws_delivery_prefs_backup_is_meaningful( $data ) ) {
			return;
		}
		self::persist_ocws_delivery_backup_and_pending( $data );
	}

	/**
	 * Restore OCWS session keys if WC cleared shipping while cart is empty.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public function maybe_restore_ocws_delivery_prefs_after_empty_cart( $cart ) {
		if ( ! isset( WC()->session ) || ! $cart || ! $cart->is_empty() ) {
			return;
		}
		$backup = WC()->session->get( 'ocws_delivery_prefs_backup' );
		if ( ! is_array( $backup ) || ! self::ocws_delivery_prefs_backup_is_meaningful( $backup ) ) {
			return;
		}
		$current = WC()->session->get( 'chosen_shipping_methods', array() );
		$bak_m   = isset( $backup['chosen_shipping_methods'] ) && is_array( $backup['chosen_shipping_methods'] ) ? $backup['chosen_shipping_methods'] : array();
		$lost_methods = ! empty( $bak_m[0] ) && ( ! is_array( $current ) || empty( $current[0] ) );
		$lost_pickup  = false;
		if ( ! empty( $backup['chosen_pickup_aff'] ) ) {
			$now_aff = WC()->session->get( 'chosen_pickup_aff' );
			$lost_pickup = ( null === $now_aff || '' === $now_aff || false === $now_aff );
		}
		$lost_confirm = ! empty( $backup['ocws_shipping_popup_confirmed'] ) && ! (bool) WC()->session->get( 'ocws_shipping_popup_confirmed' );

		if ( ! $lost_methods && ! $lost_pickup && ! $lost_confirm ) {
			return;
		}

		self::log_realign_debug(
			'restore_empty_cart_apply',
			array(
				'lost_methods'  => (bool) $lost_methods,
				'lost_pickup'   => (bool) $lost_pickup,
				'lost_confirm'  => (bool) $lost_confirm,
				'backup_m0'     => ! empty( $bak_m[0] ) ? (string) $bak_m[0] : '',
			)
		);
		self::apply_ocws_delivery_prefs_snapshot( $backup );
		WC()->session->save_data();
	}

	/**
	 * Same restore after session is loaded from storage (e.g. next page view with empty cart).
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public function maybe_restore_ocws_delivery_prefs_cart_loaded( $cart ) {
		$this->maybe_restore_ocws_delivery_prefs_after_empty_cart( $cart );
	}

	/**
	 * Classify OCWS / core rate id for pickup vs home delivery.
	 *
	 * @param string $rate_id Session rate (may omit ":").
	 * @return string pickup|shipping|other|''
	 */
	private static function ocws_chosen_shipping_rate_kind( $rate_id ) {
		if ( ! is_string( $rate_id ) || $rate_id === '' ) {
			return '';
		}
		if ( function_exists( 'ocws_is_method_id_pickup' ) && ocws_is_method_id_pickup( $rate_id ) ) {
			return 'pickup';
		}
		if ( function_exists( 'ocws_is_method_id_shipping' ) && ocws_is_method_id_shipping( $rate_id ) ) {
			return 'shipping';
		}
		if ( false !== strpos( $rate_id, 'local_pickup' ) ) {
			return 'pickup';
		}
		return 'other';
	}

	/**
	 * Session still reflects a confirmed local-pickup choice (branch / popup) but backup may lack a rate id.
	 *
	 * @return bool
	 */
	private static function ocws_session_indicates_confirmed_pickup() {
		if ( ! isset( WC()->session ) ) {
			return false;
		}
		$aff = WC()->session->get( 'chosen_pickup_aff' );
		if ( null === $aff || false === $aff || '' === $aff || '0' === (string) $aff ) {
			return false;
		}
		if ( ! (bool) WC()->session->get( 'ocws_shipping_popup_confirmed' ) ) {
			return false;
		}
		$raw = WC()->session->get( 'ocws_lp_pickup_info' );
		$un  = $raw;
		if ( is_string( $raw ) && $raw !== '' ) {
			$un = @unserialize( $raw, array( 'allowed_classes' => false ) );
		}
		if ( is_array( $un ) && ! empty( $un['aff_id'] ) ) {
			return true;
		}
		// Branch + confirmed is enough; slot may be cleared in edge cases.
		return true;
	}

	/**
	 * First enabled OCWS local pickup rate id (method_id:instance_id), or empty string.
	 *
	 * @return string
	 */
	private static function get_first_available_ocws_pickup_rate_id() {
		if ( ! class_exists( 'OCWS_LP_Local_Pickup' ) || ! class_exists( 'OCWS_Advanced_Shipping' ) ) {
			return '';
		}
		$branches_dropdown = OCWS_LP_Local_Pickup::get_affiliates_dropdown_networkwide( true );
		if ( count( $branches_dropdown ) === 0 ) {
			return '';
		}
		$cart_total     = WC()->cart ? (float) WC()->cart->get_cart_contents_total() : 0;
		$shipping_zones = WC_Shipping_Zones::get_zones();
		if ( $shipping_zones && is_array( $shipping_zones ) ) {
			foreach ( $shipping_zones as $shipping_zone ) {
				if ( empty( $shipping_zone['shipping_methods'] ) || ! is_array( $shipping_zone['shipping_methods'] ) ) {
					continue;
				}
				foreach ( $shipping_zone['shipping_methods'] as $shipping_method ) {
					if ( ! isset( $shipping_method->enabled ) || 'yes' !== $shipping_method->enabled ) {
						continue;
					}
					if ( 'free_shipping' === $shipping_method->id && isset( $shipping_method->min_amount ) && 0 !== (float) $shipping_method->min_amount ) {
						if ( $cart_total < (float) $shipping_method->min_amount ) {
							continue;
						}
					}
					if ( OCWS_LP_Local_Pickup::PICKUP_METHOD_ID !== $shipping_method->id ) {
						continue;
					}
					return $shipping_method->id . ':' . $shipping_method->instance_id;
				}
			}
		}
		return '';
	}

	/**
	 * When the cart is refilled after being empty, WooCommerce often picks the default (home) rate.
	 * Restore the last OCWS choice from backup/session before {@see \WC_Cart_Totals} / shipping.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public function maybe_realign_ocws_chosen_shipping_before_totals( $cart ) {
		if ( ! isset( WC()->session ) || ! $cart || $cart->is_empty() ) {
			return;
		}
		// One-shot: only after a cart was emptied, so switching method while items are in the cart is not clobbered.
		if ( ! (bool) WC()->session->get( 'ocws_pending_shipping_realign' ) ) {
			return;
		}
		if ( is_object( $cart ) && is_a( $cart, 'WC_Cart' ) ) {
			$line_count = is_array( $cart->get_cart() ) ? count( $cart->get_cart() ) : 0;
		} else {
			$line_count = 0;
		}
		self::log_realign_debug(
			'realign_enter',
			array(
				'wc_cart_is_empty'   => ( is_object( $cart ) && method_exists( $cart, 'is_empty' ) ) ? (bool) $cart->is_empty() : null,
				'wc_line_count'     => (int) $line_count,
				'wc_contents_count'  => ( is_object( $cart ) && method_exists( $cart, 'get_cart_contents_count' ) ) ? (int) $cart->get_cart_contents_count() : 0,
			)
		);
		if ( ! $cart->needs_shipping() ) {
			self::log_realign_debug( 'realign_skip_no_needs_shipping' );
			WC()->session->set( 'ocws_pending_shipping_realign', null );
			return;
		}

		$backup = WC()->session->get( 'ocws_delivery_prefs_backup' );
		if ( ! is_array( $backup ) ) {
			$backup = array();
		}

		$cur_m = WC()->session->get( 'chosen_shipping_methods', array() );
		$cur0  = ( is_array( $cur_m ) && ! empty( $cur_m[0] ) ) ? (string) $cur_m[0] : '';
		$cur_k = self::ocws_chosen_shipping_rate_kind( $cur0 );

		$bak_m = isset( $backup['chosen_shipping_methods'] ) && is_array( $backup['chosen_shipping_methods'] ) ? $backup['chosen_shipping_methods'] : array();
		$bak0  = ( ! empty( $bak_m[0] ) ) ? (string) $bak_m[0] : '';
		$bak_k = self::ocws_chosen_shipping_rate_kind( $bak0 );

		$restore = null;
		$sync    = null;

		$bak_is_oc = ( 'pickup' === $bak_k || 'shipping' === $bak_k );
		// Re-apply the last explicit OCWS method from backup when session kind diverges (e.g. home vs pickup after refill).
		if ( $bak0 && $bak_is_oc && $bak_k !== $cur_k ) {
			$restore = $bak_m;
			$sync    = ( isset( $backup['sync_chosen_shipping_methods'] ) && is_array( $backup['sync_chosen_shipping_methods'] ) && ! empty( $backup['sync_chosen_shipping_methods'][0] ) )
				? $backup['sync_chosen_shipping_methods']
				: $bak_m;
		} elseif ( null === $restore && ( ! $bak0 || 'other' === $bak_k || '' === $bak_k ) && ( 'shipping' === $cur_k || '' === $cur_k ) && self::ocws_session_indicates_confirmed_pickup() ) {
			$rate = self::get_first_available_ocws_pickup_rate_id();
			if ( $rate ) {
				$restore = array( $rate );
				$sync    = $restore;
			}
		}
		if ( is_array( $restore ) && ! empty( $restore[0] ) && is_array( $sync ) && ! empty( $sync[0] ) ) {
			$pair    = self::ocws_sanitize_sync_matches_chosen_in_data(
				array(
					'chosen_shipping_methods'         => $restore,
					'sync_chosen_shipping_methods'   => $sync,
				)
			);
			$restore = isset( $pair['chosen_shipping_methods'] ) && is_array( $pair['chosen_shipping_methods'] ) ? $pair['chosen_shipping_methods'] : $restore;
			$sync    = isset( $pair['sync_chosen_shipping_methods'] ) && is_array( $pair['sync_chosen_shipping_methods'] ) ? $pair['sync_chosen_shipping_methods'] : $sync;
		}
		$which = null;
		if ( is_array( $restore ) && ! empty( $restore[0] ) ) {
			$which = ( $bak0 && $bak_is_oc && $bak_k !== $cur_k ) ? 'from_backup' : ( ( is_array( $sync ) && ! empty( $sync[0] ) && false !== strpos( (string) $sync[0], 'oc_woo_local_pickup' ) ) ? 'from_fallback_pickup' : 'unknown' );
		}

		if ( ! is_array( $restore ) || empty( $restore[0] ) || ! is_array( $sync ) || empty( $sync[0] ) ) {
			self::log_realign_debug(
				'realign_noop_clear_pending',
				array(
					'cur_k'          => $cur_k,
					'cur0'           => $cur0,
					'bak_k'          => $bak_k,
					'bak0'           => $bak0,
					'pickup_fallback'=> self::ocws_session_indicates_confirmed_pickup(),
					'first_pickup_id'=> self::get_first_available_ocws_pickup_rate_id(),
				)
			);
			WC()->session->set( 'ocws_pending_shipping_realign', null );
			return;
		}

		self::log_realign_debug(
			'realign_apply',
			array(
				'which'     => $which,
				'restore_0' => (string) $restore[0],
			)
		);
		WC()->session->set( 'chosen_shipping_methods', $restore );
		WC()->session->set( 'sync_chosen_shipping_methods', $sync );
		WC()->session->set( 'ocws_pending_shipping_realign', null );

		// When line(s) exist but get_cart_contents_count() is still 0, WC can overwrite chosen_shipping_methods later
		// in the same request — schedule a second pass in {@see maybe_realign_ocws_chosen_shipping_after_totals()}.
		if ( is_object( $cart ) && is_a( $cart, 'WC_Cart' ) && ! $cart->is_empty() ) {
			$line_count   = is_array( $cart->get_cart() ) ? count( $cart->get_cart() ) : 0;
			$contents_cnt = (int) $cart->get_cart_contents_count();
			if ( $line_count > 0 && 0 === $contents_cnt ) {
				WC()->session->set( 'ocws_realign_shipping_after_totals', 1 );
				self::log_realign_debug(
					'realign_schedule_after_totals_pass',
					array(
						'line_count'     => $line_count,
						'contents_count' => $contents_cnt,
					)
				);
			}
		}
	}

	/**
	 * Runs after WC_Cart_Totals: re-assert backup pickup if WC overwrote session during 0-quantity / transitional cart state.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public function maybe_realign_ocws_chosen_shipping_after_totals( $cart ) {
		if ( ! isset( WC()->session ) || ! (bool) WC()->session->get( 'ocws_realign_shipping_after_totals' ) ) {
			return;
		}
		WC()->session->set( 'ocws_realign_shipping_after_totals', null );
		if ( ! $cart || $cart->is_empty() || ! $cart->needs_shipping() ) {
			return;
		}
		$backup = WC()->session->get( 'ocws_delivery_prefs_backup' );
		if ( ! is_array( $backup ) ) {
			return;
		}
		$bak_m = isset( $backup['chosen_shipping_methods'] ) && is_array( $backup['chosen_shipping_methods'] ) ? $backup['chosen_shipping_methods'] : array();
		if ( empty( $bak_m[0] ) ) {
			return;
		}
		$bak0  = (string) $bak_m[0];
		$bak_k = self::ocws_chosen_shipping_rate_kind( $bak0 );
		if ( 'pickup' !== $bak_k ) {
			return;
		}
		$cur_m = WC()->session->get( 'chosen_shipping_methods', array() );
		$cur0  = ( is_array( $cur_m ) && ! empty( $cur_m[0] ) ) ? (string) $cur_m[0] : '';
		if ( $cur0 && str_replace( ':', '', $cur0 ) === str_replace( ':', '', $bak0 ) ) {
			return;
		}
		$ck = $cur0 ? self::ocws_chosen_shipping_rate_kind( $cur0 ) : '';
		if ( 'pickup' === $ck && $cur0 && str_replace( ':', '', $cur0 ) !== str_replace( ':', '', $bak0 ) ) {
			// Different pickup instance — do not override without user action
			return;
		}
		$sync = ( isset( $backup['sync_chosen_shipping_methods'] ) && is_array( $backup['sync_chosen_shipping_methods'] ) && ! empty( $backup['sync_chosen_shipping_methods'][0] ) )
			? $backup['sync_chosen_shipping_methods']
			: $bak_m;
		$pair = self::ocws_sanitize_sync_matches_chosen_in_data(
			array(
				'chosen_shipping_methods'       => $bak_m,
				'sync_chosen_shipping_methods'  => $sync,
			)
		);
		if ( ! isset( $pair['chosen_shipping_methods'][0] ) ) {
			return;
		}
		WC()->session->set( 'chosen_shipping_methods', $pair['chosen_shipping_methods'] );
		WC()->session->set( 'sync_chosen_shipping_methods', $pair['sync_chosen_shipping_methods'] );
		self::log_realign_debug(
			'realign_after_totals_second_pass',
			array(
				'cur_before'  => $cur0,
				'restore_0'   => (string) $pair['chosen_shipping_methods'][0],
			)
		);
	}

	public static function show_chip_in_empty_cart() {
		if ( WC()->cart->is_empty() ) {
			self::show_chip_in_cart();
		}
	}

	public static function show_chip_in_not_empty_cart() {
		if ( ! WC()->cart->is_empty() ) {
			self::show_chip_in_cart();
		}
	}

	/**
	 * קוד אזור לפי oc_woo_shipping_locations: בפוליגונים/גוגל `billing_city_code` הוא לעיתים מזהה מקום (GM) ולא קוד האזור האמיתי —
	 * כמו ב־ocws_render_shipping_additional_fields מזהים דרך OC_Woo_Shipping_Polygon::get_location_code_by_post_data_network.
	 *
	 * @param array $delivery_data תוצאת get_checkout_delivery_data().
	 * @return string קוד אזור ריק כשנכשל / לא רלוונטי
	 */
	public static function resolve_float_cart_shipping_location_code( $delivery_data ) {
		if ( empty( $delivery_data['delivery_type'] ) || 'shipping' !== $delivery_data['delivery_type'] ) {
			return '';
		}
		if ( function_exists( 'ocws_use_google_cities_and_polygons' ) && ocws_use_google_cities_and_polygons() && class_exists( 'OC_Woo_Shipping_Polygon' ) ) {
			$post_data = array();
			if ( function_exists( 'WC' ) && WC()->checkout() ) {
				$checkout                            = WC()->checkout();
				$post_data['billing_city_code']     = $checkout->get_value( 'billing_city_code' );
				$post_data['billing_address_coords'] = $checkout->get_value( 'billing_address_coords' );
			}
			if ( isset( WC()->session ) ) {
				if ( ! isset( $post_data['billing_city_code'] ) || '' === $post_data['billing_city_code'] ) {
					$post_data['billing_city_code'] = WC()->session->get( 'chosen_city_code', '' );
				}
				if ( ! isset( $post_data['billing_address_coords'] ) || '' === $post_data['billing_address_coords'] ) {
					$post_data['billing_address_coords'] = WC()->session->get( 'chosen_address_coords', '' );
				}
			}
			$location_code = OC_Woo_Shipping_Polygon::get_location_code_by_post_data_network( $post_data );
			if ( ! $location_code ) {
				return '';
			}
			$location_code = (string) $location_code;
			if ( is_multisite() && false !== strpos( $location_code, ':::' ) ) {
				$bid = explode( ':::', $location_code, 2 );
				if ( isset( $bid[0], $bid[1] ) && absint( $bid[0] ) === get_current_blog_id() ) {
					$location_code = $bid[1];
				} elseif ( isset( $bid[0], $bid[1] ) && absint( $bid[0] ) !== get_current_blog_id() ) {
					// אזור שייך לאתר אחר ברשת — לא משווים לטבלה המקומית
					return '';
				}
			}
			return $location_code;
		}
		$raw = isset( $delivery_data['delivery_location_code'] ) ? $delivery_data['delivery_location_code'] : '';

		return $raw ? (string) $raw : '';
	}

	/**
	 * הודעת מינימום הזמנה למשלוח בצ'יפ המיני־קארט (מתחת ל־cds-data), רק כשסכום העגלה נמוך ממינימום לפי אזור המשלוח.
	 * 
	 * @param array $delivery_data תוצאת get_checkout_delivery_data().
	 * @return string HTML ריק או בלוק .cds-min-order-notice
	 */
	public static function get_float_cart_min_order_notice_html( $delivery_data ) {
		if ( empty( $delivery_data['delivery_type'] ) || 'shipping' !== $delivery_data['delivery_type'] ) {
			return '';
		}
		$location_code = self::resolve_float_cart_shipping_location_code( $delivery_data );
		if ( '' === $location_code || ! function_exists( 'ocws_is_location_enabled' ) || ! ocws_is_location_enabled( $location_code ) ) {
			return '';
		}

		$cart = WC()->cart;
		if ( ! $cart ) {
			return '';
		}

		$cart_total = (float) $cart->get_cart_contents_total();
		if ( ! class_exists( 'OC_Woo_Shipping_Group_Data_Store' ) || ! class_exists( 'OC_Woo_Shipping_Group_Option' ) ) {
			return '';
		}

		$data_store = new OC_Woo_Shipping_Group_Data_Store();
		$group_id   = $data_store->get_group_by_location( $location_code );
		if ( ! $group_id ) {
			return '';
		}

		$data_min_total = OC_Woo_Shipping_Group_Option::get_location_option( $location_code, $group_id, 'min_total', false );
		$min_total      = isset( $data_min_total['option_value'] ) ? floatval( $data_min_total['option_value'] ) : 0;
		if ( $min_total <= 0 ) {
			return '';
		}
		if ( $cart_total + 0.0001 >= $min_total ) {
			return '';
		}
		$short = max( 0, $min_total - $cart_total );
		$line_min = sprintf(
			/* translators: %s: formatted minimum order amount HTML */
			__( 'המינימום למשלוח הוא %s.' ),
			wc_price( $min_total )
		);
		$line_short = sprintf(
			/* translators: %s: formatted amount still needed HTML */
			__( 'חסר לך עוד %s.' ),
			wc_price( $short )
		);
		return '<div class="cds-min-order-notice"><span class="first">' . wp_kses_post( $line_min ) . '</span> <span class="second">' . wp_kses_post( $line_short ) . '</span></div>';
	}

	/**
	 * True when min-order notice applies: floating cart checkout must stay disabled (same rules as get_float_cart_min_order_notice_html).
	 *
	 * @return bool
	 */
	public static function is_float_cart_checkout_disabled_for_min_order() {
		return '' !== self::get_float_cart_min_order_notice_html( self::get_checkout_delivery_data() );
	}

	public static function show_chip_in_cart() {

		if ( ocws_use_deli_style() && ! ocws_use_deli_for_regular_products() ) {
			return;
		}
		do_action( 'ocws_maybe_fix_shipping_method' );
		$data = self::get_checkout_delivery_data();

		if ( self::checkout_delivery_location_is_empty( $data['delivery_location_code'] ?? null ) ) {
			?>
			<div id="ocws-delivery-data-chip" class="delivery-data-chip">
				<button type="button" class="cds-button-change cds-chip-supply-prompt regular-products <?php echo esc_attr( WC()->cart->is_empty() ? 'empty-cart' : 'not-empty-cart' ); ?>">
					<?php echo esc_html( __( 'Select delivery method to check costs and order minimum', 'ocws' ) ); ?>
				</button>
			</div>
			<?php
			return;
		}
		?>

		<div id="ocws-delivery-data-chip" class="delivery-data-chip">
			<div style="display: none;"><?php echo is_multisite() ? get_blogaddress_by_id(get_current_blog_id()) : get_site_url(); ?></div>
			<?php
			$chip_line_primary = '';
			$chip_line_address = '';

			if ( 'shipping' === $data['delivery_type'] ) {
				$chip_line_primary = self::get_float_cart_delivery_headline( $data['delivery_date'], 'shipping' );
				$chip_line_address = self::get_float_cart_address_second_line();
			} elseif ( 'pickup' === $data['delivery_type'] && ! empty( $data['delivery_location_name'] ) ) {
				$chip_line_primary = self::get_float_cart_delivery_headline( $data['delivery_date'], 'pickup' );
				$chip_line_address = $data['delivery_location_name'];
			} else {
				$chip_line_primary = isset( $data['delivery_location_text'] ) ? $data['delivery_location_text'] : '';
			}
			$cds_classes = 'cds-data';
			if ( $chip_line_address ) {
				$cds_classes .= ' cds-data--stacked';
			}

			$min_order_notice_html = self::get_float_cart_min_order_notice_html( $data );
			$show_float_cart_shipping_price = false;
			$float_cart_shipping_html        = '';
			$cart                            = WC()->cart;
			if ( $cart && ! $cart->is_empty() && $cart->needs_shipping() && $cart->show_shipping() ) {
				if ( ! function_exists( 'deliz_short_float_cart_show_shipping_row' ) || deliz_short_float_cart_show_shipping_row() ) {
					$cart->calculate_totals();
					$show_float_cart_shipping_price = true;
					$float_cart_shipping_html       = $cart->get_cart_shipping_total();
				}
			}
			?>
			<div class="cds-primary-block">
				<div class="<?php echo esc_attr( $cds_classes ); ?>">
					<span class="cds-method" style="display: none;"><?php echo esc_html( $data['delivery_type_text'] ); ?></span> <span style="display: none;" class="cds-h-divider">|</span>
					<div class="cds-row cds-row--headline">
						<span class="cds-address"><?php echo esc_html( $chip_line_primary ); ?></span>
						<input class="cds-button-change cds-button-change--inline regular-products <?php echo esc_attr(WC()->cart->is_empty()? 'empty-cart' : 'not-empty-cart') ?>" type="button" value="<?php echo esc_attr(_x('Change', 'Mini-cart shipping settings summary', 'ocws')) ?>"/>
					</div>
					<?php if ( $chip_line_address ) : ?>
						<span class="cds-address-line2"><?php echo esc_html( $chip_line_address ); ?></span>
					<?php endif; ?>
				</div>
				<?php echo $min_order_notice_html; ?>
			</div>
			<?php if ( $show_float_cart_shipping_price && '' !== $float_cart_shipping_html ) : ?>
				<div class="cds-shipping-price"><?php echo wp_kses_post( $float_cart_shipping_html ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * שורה ראשונה ב־chip: משלוח להיום / משלוח למחר / משלוח ל־d.m.Y (או איסוף בהתאמה).
	 *
	 * @param string $date_str תאריך בפורמט d/m/Y או ריק.
	 * @param string $mode     shipping|pickup.
	 * @return string
	 */
	public static function get_float_cart_delivery_headline( $date_str, $mode = 'shipping' ) {
		$pickup = ( 'pickup' === $mode );
		$prefix = $pickup ? 'איסוף' : 'משלוח';
		if ( ! $date_str ) {
			return $prefix;
		}
		try {
			$tz = ocws_get_timezone();
			$dt = Carbon::createFromFormat( 'd/m/Y', $date_str, $tz )->startOfDay();
		} catch ( \InvalidArgumentException $e ) {
			return $prefix;
		}
		$today    = Carbon::now( $tz )->startOfDay();
		$tomorrow = $today->copy()->addDay();
		if ( $dt->isSameDay( $today ) ) {
			return $prefix . ' להיום';
		}
		if ( $dt->isSameDay( $tomorrow ) ) {
			return $prefix . ' למחר';
		}
		return $prefix . ' ל־' . $dt->format( 'd/m' );
	}

	/**
	 * שורה שנייה: רחוב + מספר + עיר, או רק עיר אם אין רחוב.
	 *
	 * @return string
	 */
	public static function get_float_cart_address_second_line() {
		$city_name = WC()->session ? WC()->session->get( 'chosen_city_name' ) : '';
		$street    = WC()->session ? WC()->session->get( 'chosen_street' ) : '';
		$house_num = WC()->session ? WC()->session->get( 'chosen_house_num' ) : '';
		if ( $city_name && $street && $house_num ) {
			return sprintf( '%s %s, %s', $street, $house_num, $city_name );
		}
		if ( $city_name ) {
			return $city_name;
		}
		return '';
	}

	/**
	 * תאריך משלוח/איסוף: קודם הערך מ־session המסולסל (כמו אחרי oc_woo_shipping_set_shipping_city),
	 * ואז get_shipping_info / get_pickup_info — כדי לא להיצמד ל־post_data ישן או checkout_data שלא עודכן.
	 *
	 * @param string $mode shipping|pickup
	 * @return string d/m/Y או ריק
	 */
	public static function resolve_delivery_date_for_float_cart( $mode = 'shipping' ) {
		if ( 'pickup' === $mode ) {
			if ( ! class_exists( 'OCWS_LP_Pickup_Info' ) ) {
				return '';
			}
			$from_blob = OCWS_LP_Pickup_Info::get_pickup_info_from_session();
			if ( ! empty( $from_blob['date'] ) ) {
				return $from_blob['date'];
			}
			$merged = OCWS_LP_Pickup_Info::get_pickup_info();
			if ( ! empty( $merged['date'] ) ) {
				return $merged['date'];
			}
			if ( function_exists( 'ocws_get_session_checkout_field' ) ) {
				$cd_date = ocws_get_session_checkout_field( 'ocws_lp_pickup_date' );
				if ( '' !== trim( (string) $cd_date ) ) {
					return (string) $cd_date;
				}
			}
			return '';
		}
		if ( ! class_exists( 'OC_Woo_Shipping_Info' ) ) {
			return '';
		}
		$from_blob = OC_Woo_Shipping_Info::get_shipping_info_from_session();
		if ( ! empty( $from_blob['date'] ) ) {
			return $from_blob['date'];
		}
		$merged = OC_Woo_Shipping_Info::get_shipping_info();
		return ! empty( $merged['date'] ) ? $merged['date'] : '';
	}

	public static function get_chosen_address_text($checkout_data=false) {

		if (is_array($checkout_data)) {
			if ($checkout_data['delivery_location_code'] ) {
				//$city_name = ocws
			}
		}
		$city_name = WC()->session->get('chosen_city_name');
		$street = WC()->session->get('chosen_street');
		$house_num = WC()->session->get('chosen_house_num');
		if ($city_name) {
			if ($street && $house_num) {
				return sprintf(_x('Shipping to %s %s, %s', 'Mini-cart shipping summary', 'ocws'), $street, $house_num, $city_name);
			}
			return sprintf(_x('Shipping to %s', 'Mini-cart shipping summary', 'ocws'), $city_name);
		}
		return '';
	}

	public static function get_chosen_pickup_branch_text($default_aff_id = null) {

		$aff_id = WC()->session->get('chosen_pickup_aff');
		//var_dump(WC()->session->get_session_data());
		if (!$aff_id && $default_aff_id) {
			$aff_id = $default_aff_id;
		}
		$aff_name = '';
		if ($aff_id) {
			$affs_ds = new OCWS_LP_Affiliates();
			$aff_name = $affs_ds->get_affiliate_name(intval($aff_id));
		}
		if ($aff_name) {
			return sprintf(_x('Local pickup at %s', 'Mini-cart shipping summary', 'ocws').'.', $aff_name);
		}
		return '';
	}

	/**
	 * True when mini-cart chip should treat delivery location as missing (avoid loose ! $code / empty() on "0").
	 *
	 * @param mixed $code delivery_location_code.
	 * @return bool
	 */
	private static function checkout_delivery_location_is_empty( $code ) {
		if ( null === $code || false === $code ) {
			return true;
		}
		if ( is_string( $code ) ) {
			return '' === trim( $code );
		}
		if ( is_int( $code ) || is_float( $code ) ) {
			return false;
		}
		if ( is_array( $code ) || is_object( $code ) ) {
			return true;
		}
		return empty( $code );
	}

	/**
	 * OCWS pickup rate or WooCommerce core "local_pickup:…" rate.
	 *
	 * @param mixed $chosen_shipping chosen_shipping_methods[0].
	 * @return bool
	 */
	private static function checkout_rate_is_pickup( $chosen_shipping ) {
		if ( ! is_string( $chosen_shipping ) || '' === $chosen_shipping ) {
			return false;
		}
		if ( ocws_is_method_id_pickup( $chosen_shipping ) ) {
			return true;
		}
		return false !== strstr( $chosen_shipping, 'local_pickup' );
	}

	public static function get_checkout_delivery_data() {

		$data = array(
			'chosen_shipping_method' => '',
			'delivery_type' => '',
			'delivery_location_code' => '',
			'delivery_location_name' => '',
			'delivery_location_text' => '',
			'delivery_type_text' => '',
			'delivery_date' => '',
		);
		$chosen_shipping = false;
		if ( isset( WC()->session ) ) {
			$chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );
			if ( ! is_array( $chosen_methods ) ) { 
				$chosen_methods = array();
			}
			$chosen_shipping = ! empty( $chosen_methods[0] ) ? $chosen_methods[0] : false;

			if ( ! $chosen_shipping ) {
				$sync = WC()->session->get( 'sync_chosen_shipping_methods', array() );
				if ( is_array( $sync ) && ! empty( $sync[0] ) ) {
					$chosen_shipping = $sync[0];
				}
			}

			if ( ! $chosen_shipping ) {
				$checkout_data = WC()->session->get( 'checkout_data', array() );
				if ( is_array( $checkout_data ) && ! empty( $checkout_data['shipping_method'] ) ) {
					$sm = $checkout_data['shipping_method'];
					if ( is_array( $sm ) && ! empty( $sm[0] ) ) {
						$chosen_shipping = $sm[0];
					} elseif ( is_string( $sm ) && '' !== $sm ) {
						$chosen_shipping = $sm;
					}
				}
			}

			// WC sometimes clears chosen_shipping_methods during totals while pickup choice remains in session.
			if ( ! $chosen_shipping && class_exists( 'OCWS_LP_Local_Pickup' ) ) {
				$pickup_aff = WC()->session->get( 'chosen_pickup_aff', '' );
				$cd_for_aff = WC()->session->get( 'checkout_data', array() );
				if ( ( '' === $pickup_aff || null === $pickup_aff ) && is_array( $cd_for_aff ) && ! empty( $cd_for_aff['ocws_lp_pickup_aff_id'] ) ) {
					$pickup_aff = $cd_for_aff['ocws_lp_pickup_aff_id'];
				}
				$confirmed      = (bool) WC()->session->get( 'ocws_shipping_popup_confirmed' );
				$pickup_info_ok = ! empty( WC()->session->get( 'ocws_lp_pickup_info' ) );
				if ( null !== $pickup_aff && '' !== $pickup_aff && ( $confirmed || $pickup_info_ok ) ) {
					$chosen_shipping = OCWS_LP_Local_Pickup::PICKUP_METHOD_ID . ':0';
				}
			}
		}

		$data['chosen_shipping_method'] = $chosen_shipping;
		if ($chosen_shipping) {
			if ( ocws_is_method_id_shipping($chosen_shipping) ) {
				$using_polygons = ocws_use_google_cities_and_polygons();
				$data['delivery_type'] = 'shipping';
				$data['delivery_type_text'] = _x('shipping', 'Mini Cart Chosen Shipping Text', 'ocws');
				$data['delivery_location_code'] = ($using_polygons? WC()->checkout()->get_value('billing_city_code') : WC()->checkout()->get_value('billing_city'));
				if ($data['delivery_location_code']) {
					$data['delivery_location_name'] = ($using_polygons? WC()->checkout()->get_value('billing_city_name') : ocws_get_city_title($data['delivery_location_code']));
				}
				if ($data['delivery_location_name']) {
					$data['delivery_location_text'] = sprintf(_x('Shipping to %s', 'Mini-cart shipping summary', 'ocws'), $data['delivery_location_name']);
				}
				$data['delivery_date'] = self::resolve_delivery_date_for_float_cart( 'shipping' );
			}
			else if ( self::checkout_rate_is_pickup( $chosen_shipping ) ) {

				$data['delivery_type'] = 'pickup';
				$data['delivery_type_text'] = _x('pickup', 'Mini Cart Chosen Shipping Text', 'ocws');
				$data['delivery_location_code'] = WC()->checkout()->get_value('ocws_lp_pickup_aff_id');
				if ( self::checkout_delivery_location_is_empty( $data['delivery_location_code'] ) && isset( WC()->session ) ) {
					$data['delivery_location_code'] = WC()->session->get( 'chosen_pickup_aff', '' );
				}
				if ( self::checkout_delivery_location_is_empty( $data['delivery_location_code'] ) && isset( WC()->session ) ) {
					$checkout_data = WC()->session->get( 'checkout_data', array() );
					if ( is_array( $checkout_data ) && ! empty( $checkout_data['ocws_lp_pickup_aff_id'] ) ) {
						$data['delivery_location_code'] = $checkout_data['ocws_lp_pickup_aff_id'];
					}
				}
				$aff_name = '';
				if ( ! self::checkout_delivery_location_is_empty( $data['delivery_location_code'] ) ) {
					$affs_ds = new OCWS_LP_Affiliates();
					$aff_name = $affs_ds->get_affiliate_name(intval($data['delivery_location_code']));
				}
				$data['delivery_location_name'] = $aff_name;
				$data['delivery_location_text'] = sprintf(_x('Local pickup at %s', 'Mini-cart shipping summary', 'ocws'), $aff_name);
				$data['delivery_date'] = self::resolve_delivery_date_for_float_cart( 'pickup' );
			}
		}

		// Rate id may be a format ocws_is_method_id_pickup() does not recognize; session still has OCWS pickup.
		if ( self::checkout_delivery_location_is_empty( $data['delivery_location_code'] ) && isset( WC()->session ) && class_exists( 'OCWS_LP_Local_Pickup' ) ) {
			$aff            = WC()->session->get( 'chosen_pickup_aff' );
			$checkout_row   = WC()->session->get( 'checkout_data', array() );
			if ( ( null === $aff || '' === $aff || false === $aff ) && is_array( $checkout_row ) && ! empty( $checkout_row['ocws_lp_pickup_aff_id'] ) ) {
				$aff = $checkout_row['ocws_lp_pickup_aff_id'];
			}
			$confirmed       = (bool) WC()->session->get( 'ocws_shipping_popup_confirmed' );
			$pickup_info_ok  = ! empty( WC()->session->get( 'ocws_lp_pickup_info' ) );
			$has_aff         = ( null !== $aff && false !== $aff && '' !== trim( (string) $aff ) );
			if ( $has_aff && ( $confirmed || $pickup_info_ok ) ) {
				if ( ! $chosen_shipping ) {
					$data['chosen_shipping_method'] = OCWS_LP_Local_Pickup::PICKUP_METHOD_ID . ':0';
				}
				$data['delivery_type']           = 'pickup';
				$data['delivery_type_text']      = _x( 'pickup', 'Mini Cart Chosen Shipping Text', 'ocws' );
				$data['delivery_location_code']  = $aff;
				$affs_ds                         = new OCWS_LP_Affiliates();
				$data['delivery_location_name']  = $affs_ds->get_affiliate_name( intval( $aff ) );
				$data['delivery_location_text']  = sprintf( _x( 'Local pickup at %s', 'Mini-cart shipping summary', 'ocws' ), $data['delivery_location_name'] );
				$data['delivery_date']           = self::resolve_delivery_date_for_float_cart( 'pickup' );
			}
		}

		return $data;
	}

	public static function woocommerce_add_to_cart_fragments_filter( $fragments ) {

		ob_start();
		self::show_chip_in_cart();
		$mini_cart = ob_get_clean();
		$fragments['div#ocws-delivery-data-chip'] = $mini_cart;
		return $fragments;
	}

	public static function oc_compat_add_checkout_fragments( $fragments ) {

		if ( isset( $_POST['post_data'] ) ) {

			parse_str( $_POST['post_data'], $post_data );

		} else {

			$post_data = $_POST; // fallback for final checkout (non-ajax)

		}
		$chosen_methods 	= WC()->session->get( 'chosen_shipping_methods' );
		$chosen_shipping 	= $chosen_methods[0];
		$local_pickup_chosen = ($chosen_shipping && strstr($chosen_shipping, 'local_pickup'));
		ob_start();
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
			'billing_apartment'
		);
		?>

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
					if ( isset($_POST['post_data']) ) {
						$post_data['billing_enter_code'] = '';
						$post_data['billing_house_num'] = '';
						$post_data['billing_apartment'] = '';
						$post_data['billing_floor'] = '';
						$_POST['post_data'] = build_query($post_data);
					}
					WC()->session->set('checkout_data', $checkout_session_data);
					WC()->session->save_data();
				}
			}
		}*/
		?>
		<div class="woocommerce-billing-fields__field-wrapper woocommerce-billing-fields-part-2 billing-fields-shipping-data-1 <?php if ( $local_pickup_chosen == 1  ){ echo 'hidden'; } ?>">
			<?php
			$fields = WC()->checkout->get_checkout_fields( 'billing' );
			foreach ( $fields as $key => $field ) {
				if ( in_array( $key, $ar_billing_fields_first ) ){
					woocommerce_form_field( $key, $field, WC()->checkout->get_value( $key ) );
				}
			}
			?>

		</div>
		<?php
		$billing_fields_2 = ob_get_clean();
		$fragments['.woocommerce-billing-fields-part-2'] = $billing_fields_2;

		ob_start();
		?>
		<div class="other-recipient-fields">
			<?php do_action('ocws_send_to_other_person_fields'); ?>
		</div>
		<?php
		$other_recipient_fields = ob_get_clean();
		$fragments['.other-recipient-fields'] = $other_recipient_fields;
		return $fragments;
	}

	/*public function process_checkout_field_billing_google_autocomplete($value) {

		$chosen_methods = WC()->session->get( 'chosen_shipping_methods', array() );

		if ( isset( $_POST['post_data'] ) ) {
			parse_str( $_POST['post_data'], $post_data );
		} else {
			$post_data = $_POST; // fallback for final checkout (non-ajax)
		}

		if (empty($chosen_methods)) {
			if (isset($post_data['shipping_method']) && is_array($post_data['shipping_method'])) {
				$chosen_methods = $post_data['shipping_method'];
			}
		}
		$is_ocws = false;

		foreach ($chosen_methods as $shippingMethod) {
			if (substr($shippingMethod, 0, strlen('oc_woo_advanced_shipping_method')) == 'oc_woo_advanced_shipping_method') {
				$is_ocws = true;
				break;
			}
		}
		if ($is_ocws && ocws_use_google_cities_and_polygons() && isset($post_data['billing_city_name']) && isset($post_data['billing_street'])) {
			return $post_data['billing_city_name'] . ', ' . $post_data['billing_street'];
		}
		return $value;
	}*/

}
