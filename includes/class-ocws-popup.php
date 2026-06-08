<?php

defined( 'ABSPATH' ) || exit;

class OCWS_Popup {

	/**
	 * Align popup radio selection with {@see Oc_Woo_Shipping_Public::get_checkout_delivery_data()} (same as mini-cart).
	 * When the cart is empty, WC may clear `chosen_shipping_methods` while pickup branch / session still identifies pickup.
	 *
	 * @param string               $session_rate Rate id from session or ''.
	 * @param bool                 $popup_shipping_confirmed Whether the customer already confirmed the popup once.
	 * @param array<int, array<string, mixed>> $method_rows Built method rows (type, rate_id, …).
	 * @return string Resolved Woo rate id `method_id:instance_id` or ''.
	 */
	private static function resolve_popup_chosen_shipping_rate_id( $session_rate, $popup_shipping_confirmed, $method_rows ) {
		if ( ! $popup_shipping_confirmed || empty( $method_rows ) ) {
			return is_string( $session_rate ) ? $session_rate : '';
		}
		if ( ! class_exists( 'Oc_Woo_Shipping_Public' ) ) {
			return is_string( $session_rate ) ? $session_rate : '';
		}

		$dd    = Oc_Woo_Shipping_Public::get_checkout_delivery_data();
		$dtype = isset( $dd['delivery_type'] ) ? $dd['delivery_type'] : '';

		if ( 'pickup' === $dtype ) {
			foreach ( $method_rows as $row ) {
				if ( isset( $row['type'] ) && 'pickup' === $row['type'] ) {
					return (string) $row['rate_id'];
				}
			}
			return is_string( $session_rate ) ? $session_rate : '';
		}

		if ( 'shipping' === $dtype ) {
			if ( $session_rate && self::popup_session_rate_matches_row_type( $session_rate, $method_rows, 'shipping' ) ) {
				return (string) $session_rate;
			}
			foreach ( $method_rows as $row ) {
				if ( isset( $row['type'] ) && 'shipping' === $row['type'] ) {
					return (string) $row['rate_id'];
				}
			}
		}

		return is_string( $session_rate ) ? $session_rate : '';
	}

	/**
	 * @param string               $rate_id Rate id with colon.
	 * @param array<int, array<string, mixed>> $method_rows Rows.
	 * @param string               $want_type shipping|pickup.
	 */
	private static function popup_session_rate_matches_row_type( $rate_id, $method_rows, $want_type ) {
		$norm = str_replace( ':', '', (string) $rate_id );
		foreach ( $method_rows as $row ) {
			if ( empty( $row['type'] ) || $row['type'] !== $want_type ) {
				continue;
			}
			$rid = $row['method_id'] . $row['method_instance_id'];
			if ( $norm === $rid ) {
				return true;
			}
		}
		return false;
	}

	public static function output_shipping_popup() {

		$methods = array();

		$shipping_zones = WC_Shipping_Zones::get_zones();
		$chosen_methods = false;
		if ( isset( WC()->session ) ) {
			$chosen_methods = WC()->session->get( 'chosen_shipping_methods' );
		}
		// Until the user submits #choose-shipping at least once, do not mirror WC's auto-chosen rate
		// (otherwise the popup opens with "משלוח עד הבית" already selected).
		$popup_shipping_confirmed = false;
		if ( isset( WC()->session ) ) {
			$popup_shipping_confirmed = (bool) WC()->session->get( 'ocws_shipping_popup_confirmed' );
		}
		$session_chosen_rate = ( $popup_shipping_confirmed && is_array( $chosen_methods ) && ! empty( $chosen_methods[0] ) )
			? $chosen_methods[0]
			: '';
		$available_methods_number = 0;
		$chosen_method_index        = -1;

		$branches_dropdown = OCWS_LP_Local_Pickup::get_affiliates_dropdown_networkwide( true );

		$city_options = OCWS_Advanced_Shipping::get_all_locations_networkwide( true );

		$method_rows = array();

		if ( $shipping_zones && is_array( $shipping_zones ) ) {
			$cart_total = WC()->cart->cart_contents_total;
			foreach ( $shipping_zones as $shipping_zone ) {
				$shipping_methods = $shipping_zone['shipping_methods'];
				foreach ( $shipping_methods as $shipping_method ) {

					if ( ! isset( $shipping_method->enabled ) || 'yes' !== $shipping_method->enabled ) {
						continue; // not available
					}

					// exclude free shipping if cart sum < min shipping min amount
					if ( $shipping_method->id == 'free_shipping' && $shipping_method->min_amount != 0 ) {
						if ( $cart_total < $shipping_method->min_amount ) {
							continue;
						}
					}
					if (
						$shipping_method->id == OCWS_LP_Local_Pickup::PICKUP_METHOD_ID && count( $branches_dropdown ) == 0 ||
						$shipping_method->id == OCWS_Advanced_Shipping::SHIPPING_METHOD_ID && count( $city_options ) == 0
					) {
						continue; // considered not available
					}

					$rate_id = $shipping_method->id . ':' . $shipping_method->instance_id;
					$type    = ( $shipping_method->id == OCWS_LP_Local_Pickup::PICKUP_METHOD_ID ? 'pickup' : 'shipping' );

					$method_rows[] = array(
						'method_id'          => $shipping_method->id,
						'method_instance_id' => $shipping_method->instance_id,
						'type'               => $type,
						'rate_id'            => $rate_id,
						'title'              => ocws_translate_shipping_method_title( $shipping_method->title, $rate_id ),
					);
				}
			}
		}

		$chosen_shipping = self::resolve_popup_chosen_shipping_rate_id( $session_chosen_rate, $popup_shipping_confirmed, $method_rows );

		// Keep WC session in sync when integrated delivery data says pickup/shipping but the rate id was cleared (e.g. empty cart).
		if ( isset( WC()->session ) && $popup_shipping_confirmed && $chosen_shipping !== '' && $chosen_shipping !== $session_chosen_rate ) {
			WC()->session->set( 'chosen_shipping_methods', array( $chosen_shipping ) );
		}

		$count = 0;
		foreach ( $method_rows as $row ) {
			$is_chosen = ( $chosen_shipping && ( str_replace( ':', '', $chosen_shipping ) == $row['method_id'] . $row['method_instance_id'] ) );
			$methods[]       = array(
				'method_id'          => $row['method_id'],
				'method_instance_id' => $row['method_instance_id'],
				'type'               => $row['type'],
				'is_chosen'          => $is_chosen,
				'title'              => $row['title'],
			);
			if ( $is_chosen ) {
				$chosen_method_index = $count;
			}
			$count++;
			$available_methods_number++;
		}

		// When nothing is selected in session yet, pre-select the first method so the popup always has a clickable choice (RTL: first item is often the rightmost).
		if ( $chosen_method_index < 0 && count( $methods ) > 0 ) {
			$methods[0]['is_chosen'] = true;
			$chosen_method_index     = 0;
		}

		$var = array(
			'available_methods_number' => $available_methods_number,
			'chosen_method_index' => $chosen_method_index,
			'methods' => $methods,
			'pickup_branches' => $branches_dropdown,
			'shipping_locations' => $city_options
		);

		ocws_include_template_part( 'public/popup.php', null, $var );
	}
}
