(function( $, ocws ) {
	'use strict';

	// checkout-blocks can fire `updated_checkout` in a jQuery ready that runs *before* this file’s
	// `$(function(){...})` finishes, so the real `ocwsSyncCheckoutBlockShippingPopupConfirm` is not
	// on `window` yet. Keep an eager function + run queued reasons once the impl exists.
	/** @type {Function|null} */
	window.__ocwsSyncShippingPopupImpl = null;
	/** @type {string[]} */
	window.__ocwsPendingMinBtnReasons = window.__ocwsPendingMinBtnReasons || [];
	window.ocwsSyncCheckoutBlockShippingPopupConfirm = function( reason ) {
		if ( typeof window.__ocwsSyncShippingPopupImpl === 'function' ) {
			return window.__ocwsSyncShippingPopupImpl( reason );
		}
		if ( reason ) {
			try {
				window.__ocwsPendingMinBtnReasons.push( String( reason ) );
			} catch (e) { /* ignore */ }
		}
	};

	/**
	 * All of the code for your public-facing JavaScript source
	 * should reside in this file.
	 *
	 * Note: It has been assumed you will write jQuery code here, so the
	 * $ function reference has been prepared for usage within the scope
	 * of this function.
	 *
	 * This enables you to define handlers, for when the DOM is ready:
	 *
	 * $(function() {
	 *
	 * });
	 *
	 * When the window is loaded:
	 *
	 * $( window ).load(function() {
	 *
	 * });
	 *
	 * ...and/or other possibilities.
	 *
	 * Ideally, it is not considered best practise to attach more than a
	 * single DOM-ready or window-load handler for a particular page.
	 * Although scripts in the WordPress core, Plugins and Themes may be
	 * practising this, we should strive to set a better example in our own work.
	 */
	$(function() {

		/**
		 * Debug: checkout-block shipping min / confirm label (#checkout-block-popup--shipping).
		 * Enable with one of: URL ?ocws_debug_min=1  |  localStorage ocws_debug_min=1  |  window.ocwsDebugCheckoutMinBtn = true
		 * Disable: window.ocwsDebugCheckoutMinBtn = false
		 */
		function ocwsIsMinBtnDebug() {
			if ( window.ocwsDebugCheckoutMinBtn === true ) {
				return true;
			}
			if ( window.ocwsDebugCheckoutMinBtn === false ) {
				return false;
			}
			try {
				if ( window.localStorage && window.localStorage.getItem( 'ocws_debug_min' ) === '1' ) {
					return true;
				}
			} catch (e) { /* ignore */ }
			return /[?&]ocws_debug_min=1(?:&|$)/.test( String( window.location.search || '' ) );
		}

		/**
		 * Auto-pick nearest/first available day+slot in shipping / pickup popup (Deliz + OCWS UX).
		 */
		function ocwsTryAutoSelectFirstSchedule($root) {
			if (!$root || !$root.length) {
				$root = $('#choose-shipping');
			}
			if (!$root.length) {
				return;
			}
			setTimeout(function () {
				var $dateOnly = $root.find('.ocws-dates-only-list-slider .slot').first();
				if ($dateOnly.length) {
					$dateOnly.trigger('click');
					return;
				}
				var $firstSlot = $root.find('.ocws-day-cards-slider .slot').first();
				if ($firstSlot.length) {
					$firstSlot.trigger('click');
					return;
				}
				// Legacy markup: day row + hidden hour rows (server may still render this)
				var $firstDay = $root.find('.ocws-days-list-slider .day-data').first();
				if ($firstDay.length) {
					$firstDay.trigger('click');
					setTimeout(function () {
						var $slot = $root.find('.ocws-days-with-slots-list .day-data').filter(function () {
							return $(this).css('display') !== 'none';
						}).find('.slot').first();
						if (!$slot.length) {
							$slot = $root.find('.ocws-days-with-slots-list .slot').first();
						}
						if ($slot.length) {
							$slot.trigger('click');
						}
					}, 280);
				}
			}, 80);
		}

		/**
		 * After AJAX replaces #popup-pickup-options, restore the previous date/slot if still valid, else auto-first.
		 * @param {JQuery} form #choose-shipping
		 * @param {JQuery} $container #popup-pickup-options
		 * @param {string} prevDate
		 * @param {string} prevStart
		 * @param {string} prevEnd
		 */
		function ocwsRestorePopupPickupSchedule(form, $container, prevDate, prevStart, prevEnd) {
			prevDate = (prevDate || '').trim();
			prevStart = (prevStart || '').trim();
			prevEnd = (prevEnd || '').trim();
			if (!$container || !$container.length || !$container.find('#oc-woo-pickup-additional').length) {
				return;
			}
			var datesOnly = $container.find('.ocws-dates-only-list-slider').length > 0;

			if (prevDate) {
				form.find('input[name="ocws_lp_pickup_date"]').val(prevDate);
			}
			if (datesOnly) {
				if (prevDate) {
					var $dslot = $container.find('.ocws-dates-only-list-slider .slot').filter(function () {
						return String($(this).data('date')) === String(prevDate);
					}).first();
					if ($dslot.length) {
						$dslot.trigger('click');
						return;
					}
				}
			} else if (prevDate && prevStart && prevEnd) {
				form.find('input[name="ocws_lp_pickup_slot_start"]').val(prevStart);
				form.find('input[name="ocws_lp_pickup_slot_end"]').val(prevEnd);
				var $m = $container.find('.slot').filter(function () {
					var $el = $(this);
					return String($el.data('date')) === String(prevDate) &&
						String($el.data('slot-start')) === String(prevStart) &&
						String($el.data('slot-end')) === String(prevEnd);
				}).first();
				if ($m.length) {
					$m.trigger('click');
					return;
				}
			}
			ocwsTryAutoSelectFirstSchedule($container);
		}

		/**
		 * After AJAX replaces #popup-shipping-city-slots, restore expedition date/slot if still valid, else auto-first.
		 * @param {JQuery} form #choose-shipping
		 * @param {JQuery} $container #popup-shipping-city-slots
		 * @param {string} prevDate
		 * @param {string} prevStart
		 * @param {string} prevEnd
		 */
		function ocwsRestorePopupShippingSchedule(form, $container, prevDate, prevStart, prevEnd) {
			prevDate = (prevDate || '').trim();
			prevStart = (prevStart || '').trim();
			prevEnd = (prevEnd || '').trim();
			if (!$container || !$container.length || !$container.find('#oc-woo-shipping-additional').length) {
				return;
			}
			var datesOnly = $container.find('.ocws-dates-only-list-slider').length > 0;

			if (prevDate) {
				form.find('input[name="order_expedition_date"]').val(prevDate);
			}
			if (datesOnly) {
				if (prevDate) {
					var $dslot = $container.find('.ocws-dates-only-list-slider .slot').filter(function () {
						return String($(this).data('date')) === String(prevDate);
					}).first();
					if ($dslot.length) {
						$dslot.trigger('click');
						return;
					}
				}
			} else if (prevDate && prevStart && prevEnd) {
				form.find('input[name="order_expedition_slot_start"]').val(prevStart);
				form.find('input[name="order_expedition_slot_end"]').val(prevEnd);
				var $m = $container.find('.slot').filter(function () {
					var $el = $(this);
					return String($el.data('date')) === String(prevDate) &&
						String($el.data('slot-start')) === String(prevStart) &&
						String($el.data('slot-end')) === String(prevEnd);
				}).first();
				if ($m.length) {
					$m.trigger('click');
					return;
				}
			}
			ocwsTryAutoSelectFirstSchedule($container);
		}

		/**
		 * Remember shipping date/slot on the popup form (survives switching to pickup / DOM quirks).
		 * @param {JQuery} form #choose-shipping
		 */
		function ocwsCachePopupShippingSchedule(form) {
			if (!form || !form.length) {
				return;
			}
			form.data('ocws_popup_shipping_schedule', {
				date: (form.find('input[name="order_expedition_date"]').val() || '').trim(),
				start: (form.find('input[name="order_expedition_slot_start"]').val() || '').trim(),
				end: (form.find('input[name="order_expedition_slot_end"]').val() || '').trim()
			});
		}

		/**
		 * Two-slider markup (ocws-days-list-slider + ocws-days-with-slots-list) — still returned by some caches / old deploys.
		 */
		function ocwsInitLegacySlotSliders($wrap) {
			if (!$wrap || !$wrap.length) {
				return;
			}
			var opts = {
				margin: 10,
				loop: false,
				autoHeight: true,
				stagePadding: 10,
				items: 4,
				rtl: ($(document.body).hasClass('rtl')),
				nav: true,
				dots: false,
				pagination: false,
				responsiveClass:true,
				responsive:{
					0:{
						items:2
					},
					600:{
						items:3
					},
					1000:{
						items:3
					}
				}
			};
			$wrap.find('.ocws-days-list-slider').each(function () {
				var $el = $(this);
				if ($el.hasClass('owl-loaded')) {
					$el.trigger('destroy.owl.carousel');
				}
				$el.css('visibility', 'hidden');
				$el.owlCarousel(opts);
				$el.css('visibility', 'visible');
			});
			// Legacy rows only: new unified markup uses .day-card.day-data inside .ocws-day-cards-slider — do not Owl each card.
			$wrap.find('.ocws-days-with-slots-list .day-data').not('.day-card').each(function () {
				var $el = $(this);
				if ($el.hasClass('owl-loaded')) {
					$el.trigger('destroy.owl.carousel');
				}
				$el.css('visibility', 'hidden');
				$el.owlCarousel(opts);
				$el.css('visibility', 'visible');
			});
		}

		/** Avoid infinite loop: auto slot click → update_checkout → initCheckoutSliders → auto again. */
		var ocwsCheckoutAutoScheduleSkipNext = false;
		var ocwsCheckoutAutoScheduleLastTs = 0;

		/**
		 * Checkout block / choose-shipping popups: defer update_checkout until confirm (checkout-blocks.js).
		 *
		 * @param {JQuery} $el Slot or schedule root.
		 * @return {boolean}
		 */
		function ocwsDeferCheckoutUpdateUntilPopupConfirm($el) {
			return $el.closest('.checkout-block-popup, #choose-shipping, .choose-shipping-popup').length > 0;
		}

		$( document.body ).on('change', '#shipping_method input', function() {
			ocwsCheckoutAutoScheduleSkipNext = false;
			ocwsCheckoutAutoScheduleLastTs = 0;
		});

		/**
		 * @param {JQuery} $root #oc-woo-shipping-additional or #oc-woo-pickup-additional
		 * @return {boolean}
		 */
		function ocwsCheckoutScheduleNeedsAutoSelect($root) {
			if (!$root || !$root.length) {
				return false;
			}
			var $form = $('form.checkout');
			if (!$form.length) {
				return false;
			}
			var datesOnly = $root.find('.ocws-dates-only-list-slider').length > 0;
			if (datesOnly) {
				if ($root.find('.ocws-dates-only-list-slider .slot.selected').length) {
					return false;
				}
			} else if ($root.find('.ocws-days-with-slots-list .slot.selected').length) {
				return false;
			}

			if ($root.is('#oc-woo-shipping-additional')) {
				var shipDate = String($form.find('input[name="order_expedition_date"]').val() || '').trim();
				var shipStart = String($form.find('input[name="order_expedition_slot_start"]').val() || '').trim();
				var shipEnd = String($form.find('input[name="order_expedition_slot_end"]').val() || '').trim();
				if (shipDate && (datesOnly || (shipStart && shipEnd))) {
					return false;
				}
			} else if ($root.is('#oc-woo-pickup-additional')) {
				var pDate = String($form.find('input[name="ocws_lp_pickup_date"]').val() || '').trim();
				var pStart = String($form.find('input[name="ocws_lp_pickup_slot_start"]').val() || '').trim();
				var pEnd = String($form.find('input[name="ocws_lp_pickup_slot_end"]').val() || '').trim();
				if (pDate && (datesOnly || (pStart && pEnd))) {
					return false;
				}
			}
			return true;
		}

		/**
		 * @param {JQuery} form #choose-shipping
		 * @return {string} Error message or empty if OK
		 */
		function ocwsGetPopupScheduleError(form) {
			if (!form || !form.length) {
				return '';
			}
			var delivery_option = form.find('input[id^="oc_woo_advanced_shipping_method"]');
			var pickup_option = form.find('input[id^="oc_woo_local_pickup_method"]');

			if (delivery_option.is(':checked')) {
				var $z = form.find('#popup-shipping-city-slots');
				if ($z.find('#oc-woo-shipping-additional').length) {
					var shipDate = form.find('input[name="order_expedition_date"]').val();
					var shipStart = form.find('input[name="order_expedition_slot_start"]').val();
					var shipEnd = form.find('input[name="order_expedition_slot_end"]').val();
					var shipDatesOnly = $z.find('.ocws-dates-only-list-slider').length > 0;
					if (!shipDate || (!shipDatesOnly && (!shipStart || !shipEnd))) {
						return 'נא לבחור תאריך ושעת משלוח';
					}
				}
			}
			if (pickup_option.is(':checked')) {
				var $pz = form.find('#popup-pickup-options');
				if ($pz.find('#oc-woo-pickup-additional').length) {
					var pDate = form.find('input[name="ocws_lp_pickup_date"]').val();
					var pStart = form.find('input[name="ocws_lp_pickup_slot_start"]').val();
					var pEnd = form.find('input[name="ocws_lp_pickup_slot_end"]').val();
					var pDatesOnly = $pz.find('.ocws-dates-only-list-slider').length > 0;
					if (!pDate || (!pDatesOnly && (!pStart || !pEnd))) {
						return 'נא לבחור תאריך ושעת איסוף';
					}
				}
			}
			return '';
		}

		/**
		 * Whether the shipping popup "continue" submit may be used (location + date/slot when required).
		 * @param {JQuery} form #choose-shipping
		 * @return {boolean}
		 */
		function ocwsIsPopupDeliverySelected(form) {
			if (!form || !form.length) {
				return false;
			}
			var delivery_option = form.find('input[id^="oc_woo_advanced_shipping_method"]');
			var pickup_option = form.find('input[id^="oc_woo_local_pickup_method"]');
			var deliveryChecked = delivery_option.is(':checked');
			var pickupChecked = pickup_option.is(':checked');
			if (!deliveryChecked && !pickupChecked) {
				var $checked = form.find('input[name="popup-shipping-method"]:checked');
				if ($checked.length) {
					var v = $checked.val() || '';
					if (v.indexOf('oc_woo_advanced_shipping_method') === 0) {
						deliveryChecked = true;
					}
				}
			}
			return deliveryChecked;
		}

		/**
		 * When cart is below group min_total for the chosen location, show min message next to continue
		 * and label the submit as "הוסף עוד מוצרים" (same submit action as usual).
		 */
		function ocwsSyncMinTotalContinueUi($form) {
			if (!$form || !$form.length) {
				return;
			}
			var $btn = $form.find('#ocws-popup-continue-submit');
			var $notice = $form.find('#ocws-popup-min-total-notice');
			var $continueRow = $form.find('.ocws-popup-continue-row');
			if (!$btn.length) {
				window.ocwsMinTotalBelowMin = false;
				if ($continueRow.length) {
					$continueRow.removeClass('ocws-popup-continue-row--below-min');
				}
				$(document.body).trigger('ocws_min_total_continue_sync');
				return;
			}
			if ($btn.data('ocwsDefaultContinueLabel') === undefined) {
				$btn.data('ocwsDefaultContinueLabel', $btn.attr('value'));
			}
			var defaultLabel = $btn.data('ocwsDefaultContinueLabel');
			var addMoreLabel = (ocws.localize && ocws.localize.messages && ocws.localize.messages.addMoreProductsContinue) ? ocws.localize.messages.addMoreProductsContinue : 'הוסף עוד מוצרים';
			var $slots = $form.find('#popup-shipping-city-slots');
			var $minMsg = $slots.find('#oc-woo-shipping-additional--message');
			var showMinUi = ocwsIsPopupDeliverySelected($form) && $minMsg.length > 0;
			if (showMinUi) {
				$minMsg.hide();
				if ($notice.length) {
					$notice.empty()
						.append($minMsg.find('.first').clone())
						.append($minMsg.find('.second').clone());
					$notice.prop('hidden', false);
				}
				$btn.attr('value', addMoreLabel);
			} else {
				$minMsg.hide();
				if ($notice.length) {
					$notice.empty().prop('hidden', true);
				}
				$btn.attr('value', defaultLabel);
			}
			if ($continueRow.length) {
				$continueRow.toggleClass('ocws-popup-continue-row--below-min', !!showMinUi);
			}
			window.ocwsMinTotalBelowMin = !!showMinUi;
			$(document.body).trigger('ocws_min_total_continue_sync');
		}

		window.ocwsSyncMinTotalContinueUi = ocwsSyncMinTotalContinueUi;

		/**
		 * Deliz "checkout block" UI: shipping is edited inside #checkout-block-popup--shipping with a
		 * <button class="checkout-block-popup__confirm"> (text node), not #checkout-popup-submit-btn / #choose-shipping.
		 * Below min is rendered as #oc-woo-shipping-additional--message in checkout (see oc-woo-shipping-core-functions.php).
		 */
		function ocwsSyncCheckoutBlockShippingPopupConfirm( reason ) {
			var dbg = ocwsIsMinBtnDebug() && window.console && console.log;
			// Do not use only #checkout-block-popup--shipping: duplicate IDs in DOM = jQuery returns the first node only; user may see another instance.
			var $blocks = $( '.checkout-block--shipping' );
			if ( !$blocks.length ) {
				if ( dbg ) {
					console.log( '[OCWS min-btn] ocwsSyncCheckoutBlockShippingPopupConfirm', String( reason || '' ), { skip: 'no .checkout-block--shipping' } );
				}
				return;
			}
			var $btns = $blocks.find( '.checkout-block-popup .checkout-block-popup__confirm' );
			if ( !$btns.length ) {
				if ( dbg ) {
					console.log( '[OCWS min-btn] ocwsSyncCheckoutBlockShippingPopupConfirm', String( reason || '' ), { skip: 'no .checkout-block-popup__confirm under shipping block', blockCount: $blocks.length } );
				}
				return;
			}
			var rawAdd = (ocws.localize && ocws.localize.messages && ocws.localize.messages.addMoreProductsContinue) ? String(ocws.localize.messages.addMoreProductsContinue).trim() : '';
			var addMoreLabel = rawAdd || 'הוסף עוד מוצרים';
			// Bad translation / filter can make this identical to the default "אישור" confirm string — avoid no-op.
			if (addMoreLabel === 'אישור') {
				addMoreLabel = 'הוסף עוד מוצרים';
			}
			var $form = $( 'form.checkout' );
			if ( !$form.length ) {
				$btns.each( function() {
					var $btn = $( this );
					if ( $btn.data( 'ocwsDefaultConfirmLabel' ) === undefined ) {
						$btn.data( 'ocwsDefaultConfirmLabel', $.trim( $btn.text() ) );
					}
					$btn.text( $btn.data( 'ocwsDefaultConfirmLabel' ) );
					$btn.removeClass( 'ocws-checkout-confirm--below-min' );
				} );
				$blocks.find( '.checkout-block-popup__actions' ).removeClass( 'ocws-popup-continue-row--below-min' );
				$blocks.find( '#ocws-checkout-block-min-notice' ).empty().prop( 'hidden', true );
				if ( dbg ) {
					console.log( '[OCWS min-btn] ocwsSyncCheckoutBlockShippingPopupConfirm', String( reason || '' ), { skip: 'no form.checkout' } );
				}
				return;
			}
			var chosen = String( $form.find( 'input[name^="shipping_method"]:checked' ).val() || '' );
			var chosenGlobal = String( $( '#shipping_method input[name^="shipping_method"]:checked' ).val() || '' );
			var isAdvanced = chosen.indexOf( 'oc_woo_advanced_shipping_method' ) !== -1;
			var $minMsg = $form.find( '#oc-woo-shipping-additional--message' );
			var belowMin = isAdvanced && $minMsg.length > 0;
			$btns.each( function() {
				var $btn = $( this );
				var el = this;
				if ( $btn.data( 'ocwsDefaultConfirmLabel' ) === undefined ) {
					$btn.data( 'ocwsDefaultConfirmLabel', $.trim( $btn.text() ) );
				}
				var defaultLabel = $btn.data( 'ocwsDefaultConfirmLabel' );
				var nextText = belowMin ? addMoreLabel : defaultLabel;
				if (el) {
					el.textContent = nextText;
				}
				$btn.text( nextText );
				$btn.attr( 'aria-label', nextText );
				$btn.toggleClass( 'ocws-checkout-confirm--below-min', belowMin );
			} );
			var $notice = $blocks.find( '#ocws-checkout-block-min-notice' );
			if ( belowMin && $minMsg.length && $notice.length ) {
				$minMsg.hide();
				$notice.empty()
					.append( $minMsg.find( '.first' ).clone() )
					.append( $minMsg.find( '.second' ).clone() );
				$notice.prop( 'hidden', false );
			} else {
				$minMsg.hide();
				$notice.empty().prop( 'hidden', true );
			}
			$blocks.find( '.checkout-block-popup__actions' ).toggleClass( 'ocws-popup-continue-row--below-min', belowMin );
			if ( dbg ) {
				var firstBtn = $btns.first();
				var el0 = firstBtn[0];
				console.log( '[OCWS min-btn] ocwsSyncCheckoutBlockShippingPopupConfirm', String( reason || '' ), {
					confirmButtonCount: $btns.length,
					shippingBlockCount: $blocks.length,
					byIdCount: $( '#checkout-block-popup--shipping' ).length,
					chosenInForm: chosen,
					chosenInGlobal: chosenGlobal,
					mismatch: chosen !== chosenGlobal,
					isAdvanced: isAdvanced,
					minMsgInForm: $minMsg.length,
					minMsgGlobal: $( '#oc-woo-shipping-additional--message' ).length,
					belowMin: belowMin,
					addMoreLabelResolved: addMoreLabel,
					addMoreFromPhp: (ocws.localize && ocws.localize.messages) ? ocws.localize.messages.addMoreProductsContinue : undefined,
					firstButtonTextAfter: $.trim( firstBtn.text() ),
					firstButtonTextContent: el0 ? String(el0.textContent || '') : ''
				} );
			}
		}

		window.__ocwsSyncShippingPopupImpl = ocwsSyncCheckoutBlockShippingPopupConfirm;
		(function() {
			var _pending = window.__ocwsPendingMinBtnReasons;
			if ( _pending && _pending.length ) {
				_pending.forEach( function( r ) {
					ocwsSyncCheckoutBlockShippingPopupConfirm( r );
				} );
			}
			window.__ocwsPendingMinBtnReasons = [];
		})();

		function ocwsPopupContinueIsReady(form) {
			if (!form || !form.length) {
				return false;
			}
			if (fetchingSlots) {
				return false;
			}
			var delivery_option = form.find('input[id^="oc_woo_advanced_shipping_method"]');
			var pickup_option = form.find('input[id^="oc_woo_local_pickup_method"]');
			var using_polygon = form.find('input[name="billing_address_coords"]').length > 0;

			var deliveryChecked = delivery_option.is(':checked');
			var pickupChecked = pickup_option.is(':checked');

			if (!deliveryChecked && !pickupChecked) {
				var $checked = form.find('input[name="popup-shipping-method"]:checked');
				if ($checked.length) {
					var v = $checked.val() || '';
					if (v.indexOf('oc_woo_advanced_shipping_method') === 0) {
						deliveryChecked = true;
					} else if (v.indexOf('oc_woo_local_pickup_method') === 0) {
						pickupChecked = true;
					}
				}
			}

			if (deliveryChecked) {
				if (using_polygon) {
					if (!(String(form.find('input[name="billing_city_code"]').val() || '').trim())) {
						return false;
					}
				} else if (!(String(form.find('select[name="selected-city"] option:selected').val() || '').trim())) {
					return false;
				}
				var $slots = form.find('#popup-shipping-city-slots');
				if ($slots.find('#oc-woo-shipping-additional').length) {
					if (ocwsGetPopupScheduleError(form)) {
						return false;
					}
				} else if ($slots.is(':visible') && !$slots.children().length) {
					return false;
				}
				return true;
			}

			if (pickupChecked) {
				if (!(String(form.find('select[name="ocws_lp_pickup_aff_id"] option:selected').val() || '').trim())) {
					return false;
				}
				var $pz = form.find('#popup-pickup-options');
				if ($pz.find('#oc-woo-pickup-additional').length) {
					return !ocwsGetPopupScheduleError(form);
				}
				if ($pz.is(':visible') && !$pz.children().length) {
					return false;
				}
				return true;
			}

			return false;
		}

		function ocwsRefreshPopupContinueState() {
			var $form = $('#choose-shipping');
			if (!$form.length) {
				return;
			}
			var $btn = $form.find('#ocws-popup-continue-submit');
			if (!$btn.length) {
				$btn = $form.find('input[type="submit"]');
			}
			var ready = ocwsPopupContinueIsReady($form);
			$btn.prop('disabled', !ready);
			$btn.toggleClass('ocws-popup-continue--disabled', !ready);
			if (ready) {
				$btn.removeAttr('aria-disabled');
			} else {
				$btn.attr('aria-disabled', 'true');
			}
			ocwsSyncMinTotalContinueUi($form);
		}

		window.ocwsRefreshPopupContinueState = ocwsRefreshPopupContinueState;

		$( document.body ).on('shipping_popup_loaded', function () {
			setTimeout(function () { ocwsRefreshPopupContinueState(); }, 200);
		});

		// Checkout: clicking a day-card should pick the first slot (even when slots are hidden in "dates only" mode).
		$( document.body ).on('click', 'form.checkout .slot-list-container .ocws-day-cards-slider .day-card.day-data', function(event) {
			if ($(event.target).closest('a.slot').length) {
				return;
			}
			event.preventDefault();
			var $card = $(this);
			var $firstSlot = $card.find('.day-card__slots a.slot').first();
			if ($firstSlot.length) {
				$firstSlot.trigger('click');
				if (window.console && console.log) {
					console.log('[OCWS checkout] day-card select first slot', {
						date: $firstSlot.data('date'),
						start: $firstSlot.data('slot-start'),
						end: $firstSlot.data('slot-end')
					});
				}
			} else if (window.console && console.log) {
				console.log('[OCWS checkout] day-card has no slots', {
					date: $card.data('id') || $card.data('rel-id') || ''
				});
			}
		});

		$( document ).on('input', '#choose-shipping input[name="billing_google_autocomplete"]', function () {
			setTimeout(function () { ocwsRefreshPopupContinueState(); }, 0);
		});

		// Show shipping popup only when adding first product to cart (not on page load)
		function checkAndShowFirstVisitPopup() {
			if (!readCookie('popupdisplayed') && !$( document.body ).hasClass('ocws-deli-style')) {
				loadShippingPopupHtml();
				showShippingDialog();
				$('body').css({ overflow: 'hidden' });
				addCookie();
			}
		}

		$( document.body ).on('click', 'form.checkout .slot-list-container a.slot', function(event) {

			event.preventDefault();
			var data = {};
			data.date = $(this).data('date');
			data.slot_start = $(this).data('slot-start');
			data.slot_end = $(this).data('slot-end');
			if ($(this).hasClass('slot-interval')) {
				$(this).closest('.ocws-days-with-slots-list').find('a.slot-interval').removeClass('selected');
				$(this).addClass('selected');
			}
			var btnShowMore = $('#slot-list-button-show-all');
			var btnShowLess = $('#slot-list-button-show-less');
			if (btnShowMore.length && btnShowLess.length) {
				if (btnShowMore.css('display') != 'none') {
					data.state = 'less';
				}
				else if (btnShowLess.css('display') != 'none') {
					data.state = 'more';
				}
			}

			var self = $(this);

			var shippingParent = $(this).closest('#oc-woo-shipping-additional');
			var pickupParent = $(this).closest('#oc-woo-pickup-additional');

			var deferCheckoutUpdate = ocwsDeferCheckoutUpdateUntilPopupConfirm($(this));

			if (shippingParent.length) {

				$('input[name="order_expedition_date"]').val(data.date);
				$('input[name="order_expedition_slot_start"]').val(data.slot_start);
				$('input[name="order_expedition_slot_end"]').val(data.slot_end);
				$('input[name="slots_state"]').val(data.state);

				if (!deferCheckoutUpdate) {
					$('#oc-woo-shipping-additional').block({
						message: null,
						overlayCSS: {
							background: '#fff',
							opacity: 0.6
						}
					});

					$( document.body ).trigger( 'update_checkout' );
				}
			}
			else if (pickupParent.length) {

				$('input[name="ocws_lp_pickup_date"]').val(data.date);
				$('input[name="ocws_lp_pickup_slot_start"]').val(data.slot_start);
				$('input[name="ocws_lp_pickup_slot_end"]').val(data.slot_end);
				$('input[name="slots_state"]').val(data.state);

				if (!deferCheckoutUpdate) {
					$('#oc-woo-pickup-additional').block({
						message: null,
						overlayCSS: {
							background: '#fff',
							opacity: 0.6
						}
					});

					$( document.body ).trigger( 'update_checkout' );
				}
			}

		});

		$( document.body ).on('click', '#slot-list-button-show-all', function (event) {
			$('.slot-list-container .day-data-hidden').show();
			$(this).hide();
			$('#slot-list-button-show-less').show();
		});

		$( document.body ).on('click', '#slot-list-button-show-less', function (event) {
			$('.slot-list-container .day-data-hidden').hide();
			$(this).hide();
			$('#slot-list-button-show-all').show();
		});

		$( document.body ).on('change', '.ocws-enhanced-select[name="billing_city"], .ocws-enhanced-select[name="shipping_city"]', function(event) {

			event.preventDefault();
			//$('#oc-woo-shipping-additional .slot').hide();
			$('.woocommerce-billing-fields').block({
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			});
			$( document.body ).trigger( 'update_checkout' );
		});

		$( document.body ).on('change', '.ocws-enhanced-select[name="other_city"]', function(event) {

			event.preventDefault();
			$('.ocws-enhanced-select[name="billing_city"]').val(this.value);
			$('.woocommerce-billing-fields').block({
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			});
			$( document.body ).trigger( 'update_checkout' );
		});

		$( document.body ).on('focusout', '.ocws_update_checkout_on_change', function(event) {

			//event.preventDefault();
			//$( document.body ).trigger( 'update_checkout' );
		});

		$( document.body ).on('change', 'input[name="ocws_other_recipient"]', function(event) {

			event.preventDefault();

			$('input[name="ocws_other_recipient_hidden"]').val($(this).prop('checked')? 'yes' : 'no');

			$('.woocommerce-billing-fields').block({
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			});
			$( document.body ).trigger( 'update_checkout' );
		});

		$( document.body ).on( 'updated_checkout', function() {
			$('.ocws-readonly-form-field.ocws-polygon-related').removeClass('validate-required');
			$('#oc-woo-shipping-additional').show();
			initCheckoutSliders();
			$('.woocommerce-billing-fields').unblock();

			$( ':input.ocws-enhanced-select' ).filter( ':not(.enhanced)' ).each( function() {
				var select2_args = { minimumResultsForSearch: 5 };
				$( this ).select2( select2_args ).addClass( 'enhanced' );
			});

			$( ':input.ocws-enhanced-select-ajax-streets' ).filter( ':not(.enhanced)' ).each( function() {

				var elem = $(this);

				var language = {
					errorLoading: function () {
						return ocws.localize.select2.errorLoading;
					},
					inputTooLong: function (args) {
						return ocws.localize.select2.inputTooLong;
					},
					inputTooShort: function (args) {
						return ocws.localize.select2.inputTooShort;
					},
					loadingMore: function () {
						return ocws.localize.select2.loadingMore;
					},
					noResults: function () {
						return ocws.localize.select2.noResults;
					},
					searching: function () {
						return ocws.localize.select2.searching;
					}  
				};

				var select2_args = {
					//minimumResultsForSearch: 5,
					//multiple: true,
					//maximumSelectionSize: 1,
					language: language, 
					ajax: { 
						url: ocws.ajaxurl,
						dataType: 'json',
						delay: 150,
						data: function (data) {

							var city_code = '';
							if (elem.attr('name') == 'billing_address_1') {
								city_code = $('#billing_city').val();
							}
							else if (elem.attr('name') == 'shipping_address_1') {
								city_code = $('#shipping_city').val();
							}

							return {
								search_term: data.term, // search term
								action: "oc_woo_shipping_get_streets",
								city_code: city_code
							};
						},
						processResults: function (response) {
							return {
								results:response.data.results 
							};
						},
						cache: false
					}
				};
				$( this ).select2( select2_args ).addClass( 'enhanced' );
			});
		});

		$( ':input.ocws-enhanced-select-ajax-streets' ).filter( ':not(.enhanced)' ).each( function() {

			var elem = $(this);

			var select2_args = {
				//minimumResultsForSearch: Infinity,
				//multiple: true,
				//maximumSelectionSize: 1,
				ajax: {
					url: ocws.ajaxurl,
					dataType: 'json',
					delay: 150,
					data: function (data) {

						var city_code = '';
						if (elem.attr('name') == 'billing_address_1') {
							city_code = $('#billing_city').val();
						}
						else if (elem.attr('name') == 'shipping_address_1') {
							city_code = $('#shipping_city').val();
						}

						return {
							search_term: data.term, // search term
							action: "oc_woo_shipping_get_streets",
							city_code: city_code
						};
					},
					processResults: function (response) {
						return {
							results: response.data.results
						};
					},
					cache: false
				}
			};
			$( this ).select2( select2_args ).addClass( 'enhanced' );
		});

		$( document.body ).on('click', 'form.checkout .slot-list-container .ocws-days-list-slider .day-data', function(event) {

			event.preventDefault();

			var dataId = $(this).data('id');

			var form = $(this).closest('form.checkout');

			if (form.length) {

				form.find('.ocws-days-with-slots-list .day-data').not('.day-card').css('display', 'none');
				form.find('.ocws-days-with-slots-list .day-data').not('.day-card').removeClass('active');

				var daySlots = form.find('.ocws-days-with-slots-list .day-data[data-rel-id="'+dataId+'"]').not('.day-card');
				daySlots.css('display', '');
				if (daySlots.length) {
					form.find('.ocws-days-with-slots-list-label').css('display', '');
				}
				else {
					form.find('.ocws-days-with-slots-list-label').css('display', 'none');
				}
			}

			$('form.checkout .slot-list-container .ocws-days-list-slider .day-data').removeClass('active');
			$(this).addClass('active');

		});

		$( document.body ).on('click', 'form.checkout .slot-list-container .ocws-days-with-slots-list .day-data', function(event) {

			event.preventDefault();

			var form = $(this).closest('form.checkout');

			if (form.length) {

				form.find('.ocws-days-with-slots-list .day-data').removeClass('active');

			}

			$(this).addClass('active');

		});


		// setTimeout(function(){
		// }, 1000);
		// console.clear();

		$( document.body ).on( 'update_checkout', function(response) {
			/*$('#oc-woo-shipping-additional').hide();*/
		});

		// In case cart total under shipping required sum - it shows message, in this case if selected advanced shipping method > show checkout popup with additional buttons
		$( document.body ).on( 'updated_checkout', function(response) {
			let chosenShipping 	= $('#shipping_method input:checked').val();
			if (ocwsIsMinBtnDebug() && window.console && console.log) {
				console.log( '[OCWS min-btn] updated_checkout:head', {
					chosenFromShippingMethod: chosenShipping,
					alt: $('form.checkout #shipping_method input:checked').val()
				} );
			}
			if (!chosenShipping) {
				ocwsSyncCheckoutBlockShippingPopupConfirm( 'updated_checkout:no_shipping' );
				// After all updated_checkout handlers (e.g. deliz checkout-blocks re-init) — re-sync confirm label.
				setTimeout( function() {
					ocwsSyncCheckoutBlockShippingPopupConfirm( 'updated_checkout:no_shipping:deferred' );
				}, 0 );
				setTimeout( function() {
					ocwsSyncCheckoutBlockShippingPopupConfirm( 'updated_checkout:no_shipping:deferred+50ms' );
				}, 50 );
				return;
			}
			// Min message exists in DOM only when cart < group min_total (see oc-woo-shipping-core-functions.php);
			// do not depend on .show-shipping-block .important-notice (missing on checkout "block" layout).

			// console.group( 'UPDATED CHECKOUT| without check notice' );
			// console.groupEnd(); 
			$('.ocws-checkout-choose-city-popup .inner-wrapper').unblock();

			const $checkoutPopup = $('.ocws-checkout-choose-city-popup');
			const $checkoutBtn = $('#checkout-popup-submit-btn');
			if ($checkoutBtn.length && $checkoutBtn.data('ocwsDefaultContinueLabel') === undefined) {
				$checkoutBtn.data('ocwsDefaultContinueLabel', $checkoutBtn.attr('value'));
			}
			const defaultLabel = $checkoutBtn.length ? ($checkoutBtn.data('ocwsDefaultContinueLabel') || $checkoutBtn.attr('value')) : '';
			const addMoreLabel = (ocws.localize && ocws.localize.messages && ocws.localize.messages.addMoreProductsContinue) ? ocws.localize.messages.addMoreProductsContinue : 'הוסף עוד מוצרים';

			const isAdvanced = chosenShipping.indexOf('oc_woo_advanced_shipping_method') !== -1;
			const $message = $('form.checkout #oc-woo-shipping-additional--message');
			const belowMin = !!(isAdvanced && $message.length);

			if (ocwsIsMinBtnDebug() && window.console && console.log) {
				console.log( '[OCWS min-btn] updated_checkout:min-state', { isAdvanced: isAdvanced, minMsgInCheckoutForm: $message.length, belowMin: belowMin } );
			}

			// Sync button label on legacy ocws "choose city" checkout popup (like #choose-shipping continue).
			if ($checkoutBtn.length) {
				$checkoutBtn.attr('value', belowMin ? addMoreLabel : defaultLabel);
			}

			// Theme checkout blocks: #checkout-block-popup--shipping confirm button.
			ocwsSyncCheckoutBlockShippingPopupConfirm( 'updated_checkout' );
			// deliz theme checkout-blocks.js runs setInitialBlockStates + initCheckoutBlocks on the same event after many plugins;
			// that can replace/clone nodes or re-bind before our label sticks — run again after the task queue drains.
			setTimeout( function() {
				ocwsSyncCheckoutBlockShippingPopupConfirm( 'updated_checkout:deferred' );
			}, 0 );
			setTimeout( function() {
				ocwsSyncCheckoutBlockShippingPopupConfirm( 'updated_checkout:deferred+50ms' );
			}, 50 );

			if (isAdvanced && belowMin) {
				let messagePopup = $message.html();
				$checkoutPopup.find('.ajax-message').html(messagePopup);
				$checkoutPopup.addClass('shown');
				$checkoutBtn.prop('disabled', false);
				$checkoutPopup.removeClass('active-city-form');
				$checkoutPopup.addClass('hide-cross');
			}

			// Close min-order city popup: pickup or no min message in DOM (cart reached min).
			if (!isAdvanced || !$message.length) {
				$checkoutPopup.removeClass('shown');
				$checkoutPopup.removeClass('hide-cross');
			}
		});

		$( document ).on( 'change', 'form.checkout input.shipping_method', function() {
			var v = $(this).val();
			if (ocwsIsMinBtnDebug() && window.console && console.log) {
				console.log( '[OCWS min-btn] change shipping_method', { value: v } );
			}
			setTimeout(function () {
				ocwsSyncCheckoutBlockShippingPopupConfirm( 'change:shipping_method' );
			}, 0);
		});

		$( document.body ).on( 'updated_checkout', function(response) {
			var shipping_redirect_link = $('#oc-woo-shipping-additional .ocws-site-link');
			var pickup_redirect_link = $('#oc-woo-pickup-additional .ocws-site-link');
			if (shipping_redirect_link.length) {
				showShippingRedirectDialog($('form.checkout input[name="billing_city_name"]').val(), shipping_redirect_link);
			}
			else if (pickup_redirect_link.length) {
				showPickupRedirectDialog('', pickup_redirect_link);
			}
		});

		$( document.body ).on( 'updated_checkout', function(response) {
			var out_of_service_m = $('#oc-woo-shipping-additional .oos-message');
			var billing_google_autocomplete = $('#billing_google_autocomplete');
			var billing_google_autocomplete_err = billing_google_autocomplete.parent('.woocommerce-input-wrapper').next('span.error');
			if (out_of_service_m.length && billing_google_autocomplete.length) {
				if (billing_google_autocomplete_err.length) {
					billing_google_autocomplete_err.html(out_of_service_m.text());
				}
				else {
					billing_google_autocomplete.parent('.woocommerce-input-wrapper').after('<span class="error">'+out_of_service_m.text()+'</span>');
				}
			}
		});


		$( document.body ).on( 'click', '.show-shipping-location-button', function(e) {
			e.preventDefault();
			$('.show-shipping-location').show();
		});

		$( document.body ).on( 'click', 'input.ocws-disabled-shipping-method-input, .show-shipping-location-button-polygon', function(e) {

			e.preventDefault();
			$('.ocws-checkout-choose-city-popup #form-messages').html('');
			$('.ocws-checkout-choose-city-popup').addClass('shown');

		});

		$( document.body ).on( 'submit', '#ocws-checkout-choose-city-form', function(e) {
			e.preventDefault();
			var selectedCity = $(this).find('select[name="selected-city"]').val();

			if ( typeof selectedCity === null || selectedCity === null  ){
				alert('בחירת עיר/ישוב');
				return
			}

			// $('.ocws-checkout-choose-city-popup').removeClass('shown');
			$('.ocws-checkout-choose-city-popup .inner-wrapper').block({
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			});

			if (selectedCity) {
				var otherCity = $('.ocws-enhanced-select[name="other_city"]');
				if (otherCity.length) {
					otherCity.val(selectedCity);
					otherCity.trigger('change');
				} else {

					$('.ocws-enhanced-select[name="billing_city"]').val(selectedCity);
					$('.woocommerce-billing-fields').block({
						message: null,
						overlayCSS: {
							background: '#fff',
							opacity: 0.6
						}
					});
					$( document.body ).trigger( 'update_checkout' );
				}
			} else {
				var autocompleteInput = $(this).find('input[name="billing_google_autocomplete"]');
				if (autocompleteInput.length) {
					var $checkoutCityForm = $(this);
					var usingPolygonCheckout = $checkoutCityForm.find('input[name="billing_address_coords"]').length;
					if (usingPolygonCheckout) {
						var cityCodeCk = $checkoutCityForm.find('input[name="billing_city_code"]').val();
						if (!cityCodeCk || String(cityCodeCk).trim() === '') {
							$('.ocws-checkout-choose-city-popup .inner-wrapper').unblock();
							$('.ocws-checkout-choose-city-popup #form-messages').html('<span class="error">יש לבחור את אזור חלוקת המשלוח</span>');
							return;
						}
						var streetCk = $.trim($checkoutCityForm.find('input[name="billing_street"]').val() || '');
						var houseCk = $.trim($checkoutCityForm.find('input[name="billing_house_num"]').val() || '');
						if (!streetCk || !houseCk) {
							var msgNoHouseCk = (typeof ocws !== 'undefined' && ocws.localize && ocws.localize.messages && ocws.localize.messages.noHouseNumberInAddress)
								? ocws.localize.messages.noHouseNumberInAddress
								: 'נא להזין כתובת מלאה הכוללת רחוב ומספר בית.';
							$('.ocws-checkout-choose-city-popup .inner-wrapper').unblock();
							$('.ocws-checkout-choose-city-popup #form-messages').html('<span class="error">' + msgNoHouseCk + '</span>');
							return;
						}
					}
					var billingCity = $(this).find('input[name="billing_city"]').val();
					var billingCityName = $(this).find('input[name="billing_city_name"]').val();
					var billingCityCode = $(this).find('input[name="billing_city_code"]').val();
					var billingAddress = $(this).find('input[name="billing_street"]').val();
					var billingHouseNum = $(this).find('input[name="billing_house_num"]').val();
					var billingAddressCoords = $(this).find('input[name="billing_address_coords"]').val();

					$('form.checkout input[name="billing_google_autocomplete"]').val(autocompleteInput.val());
					$('form.checkout input[name="billing_street"]').val(billingAddress);
					$('form.checkout input[name="billing_city"]').val(billingCityName);
					$('form.checkout input[name="billing_city_code"]').val(billingCityCode);
					$('form.checkout input[name="billing_city_name"]').val(billingCityName);
					$('form.checkout input[name="billing_house_num"]').val(billingHouseNum);
					$('form.checkout input[name="billing_address_coords"]').val(billingAddressCoords);

					$('.woocommerce-billing-fields').block({
						message: null,
						overlayCSS: {
							background: '#fff',
							opacity: 0.6
						}
					});
					autocompleteInput.val('');
					$( document.body ).trigger( 'update_checkout' );

				}
			}
			return false;

		});


		// TODO: find more common way to figure out if cart is empty
		$( document.body ).on( 'adding_to_cart', function() {
			var hasCookie = !!readCookie('popupdisplayed');
			var miniCartItems = $('li.woocommerce-mini-cart-item').length;
			var hasDeliStyle = $( document.body ).hasClass('ocws-deli-style');
			if (
				!hasCookie && !miniCartItems
			) {
				if (!hasDeliStyle) {
					loadShippingPopupHtml();
					showShippingDialog();
					$('body').css({ overflow: 'hidden' });
					addCookie();
				} else {
				}
			} else {
			}
		});

		$( document.body ).on( 'orak_adding_to_cart', function() {
			var hasCookie = !!readCookie('popupdisplayed');
			var miniCartItems = $('li.woocommerce-mini-cart-item').length;
			var hasDeliStyle = $( document.body ).hasClass('ocws-deli-style');
			if (
				!hasCookie && !miniCartItems
			) {
				if (!hasDeliStyle) {
					loadShippingPopupHtml();
					showShippingDialog();
					$('body').css({ overflow: 'hidden' });
					addCookie();
				} else {
				}
			} else {
			}
		});

		function addCookie() {
			var now = new Date();
			var time = now.getTime();
			time += 24 * 3600 * 1000;
			now.setTime(time);
			document.cookie =
				'popupdisplayed=' + '1' +
				'; expires=' + now.toUTCString() +
				'; path=/';
		}

		function readCookie(name) {
			var nameEQ = name + "=";
			var ca = document.cookie.split(';');
			for(var i=0;i < ca.length;i++) {
				var c = ca[i];
				while (c.charAt(0)==' ') c = c.substring(1,c.length);
				if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length,c.length);
			}
			return null;
		}

		function addRedirectCookie(data) {
			var now = new Date();
			var time = now.getTime();
			time += 24 * 3600 * 1000;
			now.setTime(time);
			var value = JSON.stringify(data);
			document.cookie =
				'ocws=' + value +
				'; expires=' + now.toUTCString() +
				'; path=/';
		}

		function showShippingDialog() {
			var dialog = $('#choose-shipping-dialog');
			if (dialog.length) {
				dialog.dialog({
					resizable: false,
					height: "auto",
					width: 500,
					modal: false
				});
			}
			else {
				// רק הפופאב הראשי (public/popup.php) — לא ocws-checkout-* שגם נושאים .choose-shipping-popup
				$('.choose-shipping-popup.ocws-popup').addClass('shown');
			}
		}

		function hideShippingDialog() {
			var dialog = $('#choose-shipping-dialog');
			if (dialog.length) {
				dialog.dialog('close');
			}
			else {
				$('.choose-shipping-popup.ocws-popup').removeClass('shown');
			}
		}

		function showRedirectDialog(sitename, sitelinkelem) {
			var dialog = $('#redirect-dialog');
			var link = (typeof sitelinkelem === 'string' || sitelinkelem instanceof String)? sitelinkelem : $(sitelinkelem).attr('href');

			if (dialog.length) {
				dialog.find('button').off();
				dialog.find('button').each(function(index, element){

					if (index == 0) {
						$(element).text(ocws.localize.continue_to_change);
						$(element).on('click', function() {
							dialog.removeClass('shown');
							window.location.replace(link);
						});
					}
					else if (index == 1) {
						$(element).text(ocws.localize.back_to_checkout);
						$(element).on('click', function() {
							dialog.removeClass('shown');
						});
					}
				});
				dialog.addClass('shown');
				/*dialog.dialog({
					resizable: false,
					height: "auto",
					width: 500,
					modal: false,
					buttons: [
						{
							text: ocws.localize.continue_to_change,
							click: function() {
								$( this ).dialog( "close" );
								window.location.replace(link);
							}
						},
						{
							text: ocws.localize.back_to_checkout,
							click: function() {
								$( this ).dialog( "close" );
							}
						},
					]
				});*/
			}
		}

		function showShippingRedirectDialog(sitename, sitelinkelem, city_code=false) {
			var dialog = $('#shipping-redirect-dialog');
			var titleElem = dialog.find('.cds-dialog-title');
			var link = (typeof sitelinkelem === 'string' || sitelinkelem instanceof String)? sitelinkelem : $(sitelinkelem).attr('href');
			titleElem.text(titleElem.data('template').replace('[CITYNAME]', sitename));
			if (dialog.length) {

				dialog.find('button').off();
				dialog.find('button').each(function(index, element){

					if (index == 0) {
						$(element).text(ocws.localize.continue_to_change);
						$(element).on('click', function() {
							dialog.removeClass('shown');
							ocwsAutoGenerateCookie();
							if (city_code) {
								addCityToSiteCookie(city_code);
							}
							ocwsSaveSiteCookie();

							window.location.replace(link);
						});
					}
					else if (index == 1) {
						$(element).text(ocws.localize.back_to_checkout);
						$(element).on('click', function() {
							if ($('form.checkout').length) {
								$('form.checkout input[name="billing_google_autocomplete"]').val('');
								$('form.checkout input[name="billing_city"]').val('');
								$('form.checkout input[name="billing_city_code"]').val('');
								$('form.checkout input[name="billing_city_name"]').val('');
								$('form.checkout input[name="billing_street"]').val('');
								$('form.checkout input[name="billing_house_num"]').val('');
								$('form.checkout input[name="billing_address_coords"]').val('');
								$('form.checkout input[name="billing_address_coords"]').trigger('change');
							}
							dialog.removeClass('shown');
						});
					}
				});
				dialog.addClass('shown');
				/*dialog.dialog({
					resizable: false,
					height: "auto",
					width: 500,
					modal: false,
					buttons: [
						{
							text: ocws.localize.continue_to_change,
							click: function() {
								$( this ).dialog( "close" );
								//$(sitelinkelem).trigger('click');

								ocwsAutoGenerateCookie();
								if (city_code) {
									addCityToSiteCookie(city_code);
								}
								ocwsSaveSiteCookie();

								window.location.replace(link);
							}
						},
						{
							text: ocws.localize.back_to_checkout,
							click: function() {
								$( this ).dialog( "close" );
							}
						},
					]
				});*/
			}
		}

		function showPickupRedirectDialog(sitename, sitelinkelem, branch_code=false) {
			var dialog = $('#pickup-redirect-dialog');
			var titleElem = dialog.find('.cds-dialog-title');
			var link = (typeof sitelinkelem === 'string' || sitelinkelem instanceof String)? sitelinkelem : $(sitelinkelem).attr('href');
			titleElem.text(titleElem.data('template').replace('[CITYNAME]', sitename));
			if (dialog.length) {

				dialog.find('button').off();
				dialog.find('button').each(function(index, element){

					if (index == 0) {
						$(element).text(ocws.localize.continue_to_change);
						$(element).on('click', function() {
							dialog.removeClass('shown');
							ocwsAutoGenerateCookie();
							if (branch_code) {
								addBranchToSiteCookie(branch_code);
							}
							ocwsSaveSiteCookie();

							window.location.replace(link);
						});
					}
					else if (index == 1) {
						$(element).text(ocws.localize.back_to_checkout);
						$(element).on('click', function() {
							if ($('form.checkout').length) {
								$('form.checkout select[name="ocws_lp_pickup_aff_id"]').val('');
								$('form.checkout select[name="ocws_lp_pickup_aff_id"]').trigger('change');
							}
							dialog.removeClass('shown');
						});
					}
				});
				dialog.addClass('shown');
				/*dialog.dialog({
					resizable: false,
					height: "auto",
					width: 500,
					modal: false,
					buttons: [
						{
							text: ocws.localize.continue_to_change,
							click: function() {
								$( this ).dialog( "close" );
								//$(sitelinkelem).trigger('click');

								ocwsAutoGenerateCookie();
								if (branch_code) {
									addBranchToSiteCookie(branch_code);
								}
								ocwsSaveSiteCookie();

								window.location.replace(link);
							}
						},
						{
							text: ocws.localize.back_to_checkout,
							click: function() {
								$( this ).dialog( "close" );
							}
						},
					]
				});*/
			}
		}

		$(document).on('click', function (e) {
			/*if (!$(e.target).closest('#shipping-redirect-dialog').length) {
				try {
					$('#shipping-redirect-dialog').dialog('close');
				}
				catch (e) {}
			}*/
		});

		$(document).on('click', function (e) {
			/*if (!$(e.target).closest('#pickup-redirect-dialog').length) {
				try {
					$('#pickup-redirect-dialog').dialog('close');
				}
				catch (e) {}
			}*/
		});

		$(document).on('submit', '#choose-shipping' , function(e) {
			e.stopPropagation();
			e.preventDefault();

			if (fetchingSlots) return;

			var form = $(this);

			var delivery_option = $(this).find('input[id^="oc_woo_advanced_shipping_method"]');
			var pickup_option = $(this).find('input[id^="oc_woo_local_pickup_method"]');
			var using_polygon = $(this).find('input[name="billing_address_coords"]').length;

			var city_name = '';

			if (form.data('redirect')) {

				if ($(delivery_option).is(':checked')) {

					if (using_polygon) {
						var city_code = $(this).find('input[name="billing_city_code"]').val();
						city_name = $(this).find('input[name="billing_city_name"]').val();
						if(city_code == '') {
							$('#popup-shipping-form-messages').html('<span class="error">יש לבחור את אזור חלוקת המשלוח</span>');
							return;
						}
						var streetPolyRd = $.trim(form.find('input[name="billing_street"]').val() || '');
						var housePolyRd = $.trim(form.find('input[name="billing_house_num"]').val() || '');
						if (!streetPolyRd || !housePolyRd) {
							var msgNoHouseRd = (typeof ocws !== 'undefined' && ocws.localize && ocws.localize.messages && ocws.localize.messages.noHouseNumberInAddress)
								? ocws.localize.messages.noHouseNumberInAddress
								: 'נא להזין כתובת מלאה הכוללת רחוב ומספר בית.';
							$('#popup-shipping-form-messages').html('<span class="error">' + msgNoHouseRd + '</span>');
							return;
						}
					}
					else {
						var city_option = $(this).find('select[name="selected-city"] option:selected').val();
						city_name = $(this).find('select[name="selected-city"] option:selected').text();
						if(city_option == '') {
							$('#choose-shipping').find('select[name="selected-city"]').addClass('invalid');
							$('#popup-shipping-form-messages').html('<span class="error">יש לבחור את אזור חלוקת המשלוח</span>');
							return;
						}
					}
				}

				if ($(pickup_option).is(':checked')) {
					var aff_option = $(this).find('select[name="ocws_lp_pickup_aff_id"] option:selected').val();
					city_name = $(this).find('select[name="ocws_lp_pickup_aff_id"] option:selected').text();
					if(aff_option == '') {
						$('#choose-shipping').find('select[name="aff_option"]').addClass('invalid');
						$('#popup-pickup-form-messages').html('<span class="error">יש לבחור את אזור חלוקת המשלוח</span>');
						return;
					}
				}

				var schedErrRedirect = ocwsGetPopupScheduleError(form);
				if (schedErrRedirect) {
					$('#popup-form-messages').html('<span class="error">' + schedErrRedirect + '</span>');
					return;
				}

				ocwsAutoGenerateCookie();
				ocwsSaveSiteCookie();

				var needDialog = $('li.woocommerce-mini-cart-item').length;
				/*if (ocws.cart_is_empty == 'no') {
					needDialog = true;
				}*/

				if (needDialog) {
					if (ocwsCookie.method == 'oc_woo_advanced_shipping_method' || ocwsCookie.method == 'oc_woo_local_pickup_method') {
						hideShippingDialog();
						jQuery('body').css({ overflow: 'auto' });
						showRedirectDialog(city_name, form.data('redirect'));
						return;
					}
				}

				window.location.replace(form.data('redirect'));
				return;
			}



			if ($(delivery_option).is(':checked')) {

				if (using_polygon) {
					var city_code = $(this).find('input[name="billing_city_code"]').val();
					if(city_code == '') {
						$('#popup-shipping-form-messages').html('<span class="error">יש לבחור את אזור חלוקת המשלוח</span>');
						return;
					}
					var streetPoly = $.trim(form.find('input[name="billing_street"]').val() || '');
					var housePoly = $.trim(form.find('input[name="billing_house_num"]').val() || '');
					if (!streetPoly || !housePoly) {
						var msgNoHouse = (typeof ocws !== 'undefined' && ocws.localize && ocws.localize.messages && ocws.localize.messages.noHouseNumberInAddress)
							? ocws.localize.messages.noHouseNumberInAddress
							: 'נא להזין כתובת מלאה הכוללת רחוב ומספר בית.';
						$('#popup-shipping-form-messages').html('<span class="error">' + msgNoHouse + '</span>');
						return;
					}
				}
				else {
					var city_option = $(this).find('select[name="selected-city"] option:selected').val();
					if(city_option == '') {
						$('#choose-shipping').find('select[name="selected-city"]').addClass('invalid');
						$('#popup-shipping-form-messages').html('<span class="error">יש לבחור את אזור חלוקת המשלוח</span>');
						return;
					}
				}

				var schedErrShip = ocwsGetPopupScheduleError(form);
				if (schedErrShip) {
					$('#popup-shipping-form-messages').html('<span class="error">' + schedErrShip + '</span>');
					return;
				}

				var formData = $(form).serialize();

				$.ajax({
					method: "POST",
					url: ocws.ajaxurl,
					data: {action: "oc_woo_shipping_set_shipping_city", formData: formData},
					beforeSend: function() {
						$('#popup-form-messages').html('<span class="loading">'+ocws.localize.loading+'...</span>');
					},
					success: function(response) {
						$('#popup-shipping-form-messages').html('');
						/*$('.choose-shipping-popup').removeClass('shown');*/
						hideShippingDialog();
						jQuery('body').css({ overflow: 'auto' });
						// Refresh delivery chip after session is updated (fixes empty-cart chip update)
						setTimeout(function() { $( document.body ).trigger( 'ocws_cart_fragment_refresh' ); }, 150);
						if (!$('li.woocommerce-mini-cart-item').length) {
							setTimeout(function() { $( document.body ).trigger( 'ocws_cart_fragment_refresh' ); }, 400);
						}
					},
					complete: function() {
						$('#popup-form-messages').html('');
					}
				});
			}
			else if ($(pickup_option).is(':checked')) {

				var aff_option = $(this).find('select[name="ocws_lp_pickup_aff_id"] option:selected').val();

				if(aff_option == '') {
					$('#choose-shipping').find('select[name="aff_option"]').addClass('invalid');
					$('#popup-pickup-form-messages').html('<span class="error">יש לבחור את אזור חלוקת המשלוח</span>');
					return;
				}

				var schedErrPu = ocwsGetPopupScheduleError(form);
				if (schedErrPu) {
					$('#popup-pickup-form-messages').html('<span class="error">' + schedErrPu + '</span>');
					return;
				}

				var formData = $(form).serialize();

				$.ajax({
					method: "POST",
					url: ocws.ajaxurl,
					data: {action: "oc_woo_shipping_set_pickup_branch", formData: formData},
					beforeSend: function() {
						$('#popup-form-messages').html('<span class="loading">'+ocws.localize.loading+'...</span>');
					},
					success: function(response) {
						$('#popup-pickup-form-messages').html('');
						/*$('.choose-shipping-popup').removeClass('shown');*/
						hideShippingDialog();
						jQuery('body').css({ overflow: 'auto' });
						// Refresh delivery chip after session is updated (fixes empty-cart chip update)
						setTimeout(function() { $( document.body ).trigger( 'ocws_cart_fragment_refresh' ); }, 150);
						if (!$('li.woocommerce-mini-cart-item').length) {
							setTimeout(function() { $( document.body ).trigger( 'ocws_cart_fragment_refresh' ); }, 400);
						}
					},
					complete: function() {
						$('#popup-form-messages').html('');
					}
				});
			}
			else if(!$("input[name='popup-shipping-method']:checked").val()){
				$('#popup-form-messages').html('<span class="error">יש לבחור אפשרות משלוח</span>');
				return;
			}
		});

		var fetchingSlots = false;
		window.ocwsGetFetchingPopupSlots = function () {
			return !!fetchingSlots;
		};
		/**
		 * SMS wizard calls $('#choose-shipping').trigger('submit') while slot HTML may still be loading;
		 * the submit handler returns immediately when fetchingSlots is true (silent no-op).
		 */
		window.ocwsSubmitChooseShippingWhenReady = function () {
			var tries = 0;
			var maxTries = 200;
			var delayMs = 50;
			function tick() {
				tries++;
				if (!window.ocwsGetFetchingPopupSlots()) {
					$('#choose-shipping').trigger('submit');
					return;
				}
				if (tries >= maxTries) {
					$('#choose-shipping').trigger('submit');
					return;
				}
				setTimeout(tick, delayMs);
			}
			tick();
		};
		var fetchingState = false;
		$('#checkout-popup-submit-btn').prop('disabled', true);

		function ocwsGetPopupChosenShippingMethod($form) {
			var $checked = $form.find('input[name="popup-shipping-method"]:checked');
			if ($checked.length) {
				return $checked.val();
			}
			var $all = $form.find('input[name="popup-shipping-method"]');
			if ($all.length === 1) {
				return $all.val();
			}
			return '';
		}

		//$('#choose-shipping').find('select[name="selected-city"]').on('change', function(){
		$(document).on('change', '#choose-shipping select[name="selected-city"]', function(){
			if($(this).val()) {
				$(this).removeClass('invalid');

				var form = $(this).closest('form');
				var chosenMethod = ocwsGetPopupChosenShippingMethod(form);
				form.removeData('ocws_popup_shipping_schedule');
				var prevShipDate = form.find('input[name="order_expedition_date"]').val() || '';
				var prevShipStart = form.find('input[name="order_expedition_slot_start"]').val() || '';
				var prevShipEnd = form.find('input[name="order_expedition_slot_end"]').val() || '';
				$('#popup-shipping-city-slots').html('');
				fetchingSlots = true;
				ocwsRefreshPopupContinueState();

				$.ajax({
					method: "POST",
					url: ocws.ajaxurl,
					data: {action: "oc_woo_shipping_fetch_slots_for_city", billing_city: $(this).val(), shipping_method: chosenMethod, show_as_slider: true},
					beforeSend: function() {
						$('#popup-form-messages').html('<span class="loading">'+ocws.localize.loading+'...</span>');
					},
					success: function(response) {
						if ('undefined' !== typeof response.data.cart_is_empty) {
							ocws.cart_is_empty = response.data.cart_is_empty;
						}
						$('#popup-shipping-form-messages').html('');
						var resp = $(response.data.resp);
						var sitelinkelem = resp.find('.ocws-site-link');

						if (sitelinkelem.length) {
							//showShippingRedirectDialog('', sitelinkelem);
							form.data('redirect', $(sitelinkelem).attr('href'));
						}
						else {
							form.data('redirect', '');
						}
						$('#popup-shipping-city-slots').html(response.data.resp);

						/* Min-total copy + button label: ocwsSyncMinTotalContinueUi (via ocwsRefreshPopupContinueState). */

						$('#popup-shipping-city-slots .ocws-day-cards-slider').owlCarousel({
							margin: 10,
							loop: false,
							autoHeight: true,
							stagePadding: 10,
							items: 4,
							rtl: ($(document.body).hasClass('rtl')),
							nav: true,
							dots: false,
							pagination: false,
							responsiveClass:true,
							responsive:{
								0:{
									items:2
								},
								600:{
									items:3
								},
								1000:{
									items:3
								}
							}
						});

						$('#popup-shipping-city-slots .ocws-dates-only-list-slider').owlCarousel({
							margin: 10,
							loop: false,
							autoHeight: true,
							stagePadding: 10,
							items: 4,
							rtl: ($(document.body).hasClass('rtl')),
							nav: true,
							dots: false,
							pagination: false,
							responsiveClass:true,
							responsive:{
								0:{
									items:2
								},
								600:{
									items:3
								},
								1000:{
									items:3
								}
							}
						});

						ocwsInitLegacySlotSliders($('#popup-shipping-city-slots'));

						fetchingSlots = false;
						$('#popup-form-messages').html('');
						if ($('#popup-shipping-city-slots').find('#oc-woo-shipping-additional').length && !$('#popup-shipping-form-messages .error').length) {
							ocwsRestorePopupShippingSchedule(form, $('#popup-shipping-city-slots'), prevShipDate, prevShipStart, prevShipEnd);
							ocwsCachePopupShippingSchedule(form);
						}
						setTimeout(function () { ocwsRefreshPopupContinueState(); }, 350);
						// todo: define different handlers for on slot click event on checkout page and on the popup
					}
				});

			} else {
				$(this).addClass('invalid');
				ocwsRefreshPopupContinueState();
			}
		});

		// additional popup for choosing action
		// $(document).on( 'click', '.additional-choose-shipping-popup--control button', function(e){
		$(document).on( 'click', 'button.popup-shipping-controll', function(e){

			let val = $(this).val();
			if ( val == 'close' ){
				// $('.additional-choose-shipping-popup').removeClass('shown');
			} else if ( val == 'back' ){
				// $('.choose-shipping-popup').removeClass('shown');
				$('.ocws-checkout-choose-city-popup').removeClass('shown');
			} else if ( val == 'localpickup' ){
				// loop instead selector
				$('#shipping_method li').each(function(i, el){
					let attrClass = $(el).attr('class');
					if ( attrClass.indexOf( 'oc_woo_advanced_shipping_method' ) == -1 ){
						$(el).find('.shipping_method').trigger( 'click' );
					}
				});
				// $('.additional-choose-shipping-popup').removeClass('shown');
				$('.ocws-checkout-choose-city-popup').removeClass('shown');
			} else if ( val == 'choose-city' ){
				$('.ocws-checkout-choose-city-popup').addClass( 'active-city-form' );
				// $('.additional-choose-shipping-popup').removeClass('shown');
				// $('.ocws-checkout-choose-city-popup').addClass('shown');
			}
		});

		//
		$(document).on( 'click', 'button.back-to-main-popup', function(){
			$('.ocws-checkout-choose-city-popup').removeClass( 'active-city-form' );
		})

		//$('#choose-shipping').find('input[name="billing_address_coords"]').on('change', function(){
		$(document).on('change', '#choose-shipping input[name="billing_address_coords"]', function(){
			if($(this).val()) {

				var form = $(this).closest('form');
				var chosenMethod = ocwsGetPopupChosenShippingMethod(form);
				var cityCode = form.find('input[name="billing_city_code"]').val();
				form.removeData('ocws_popup_shipping_schedule');
				var prevShipDateCoords = form.find('input[name="order_expedition_date"]').val() || '';
				var prevShipStartCoords = form.find('input[name="order_expedition_slot_start"]').val() || '';
				var prevShipEndCoords = form.find('input[name="order_expedition_slot_end"]').val() || '';
				$('#popup-shipping-city-slots').html('');
				fetchingSlots = true;
				ocwsRefreshPopupContinueState();

				$.ajax({
					method: "POST",
					url: ocws.ajaxurl,
					data: {
						action: "oc_woo_shipping_fetch_slots_for_coords",
						billing_address_coords: $(this).val(),
						billing_city_code: cityCode,
						shipping_method: chosenMethod,
						show_as_slider: true},
					beforeSend: function() {
						$('#popup-form-messages').html('<span class="loading">'+ocws.localize.loading+'...</span>');
					},
					success: function(response) {
						if ('undefined' !== typeof response.data.cart_is_empty) {
							ocws.cart_is_empty = response.data.cart_is_empty;
						}
						$('#popup-shipping-form-messages').html('');
						var resp = $(response.data.resp);
						var oos_message = resp.find('.oos-message');
						if (oos_message.length) {
							$('#popup-shipping-form-messages').html('<span class="error">'+oos_message.text()+'</span>');
						}
						var sitelinkelem = resp.find('.ocws-site-link');

						if (sitelinkelem.length) {
							//showShippingRedirectDialog('', sitelinkelem);
							form.data('redirect', $(sitelinkelem).attr('href'));
						}
						else {
							form.data('redirect', '');
						}
						$('#popup-shipping-city-slots').html(response.data.resp);

						$('#popup-shipping-city-slots .ocws-day-cards-slider').owlCarousel({
							margin: 10,
							loop: false,
							autoHeight: true,
							stagePadding: 10,
							items: 4,
							rtl: ($(document.body).hasClass('rtl')),
							nav: true,
							dots: false,
							pagination: false,
							responsiveClass:true,
							responsive:{
								0:{
									items:2
								},
								600:{
									items:3
								},
								1000:{
									items:3
								}
							}
						});

						$('#popup-shipping-city-slots .ocws-dates-only-list-slider').owlCarousel({
							margin: 10,
							loop: false,
							autoHeight: true,
							stagePadding: 10,
							items: 4,
							rtl: ($(document.body).hasClass('rtl')),
							nav: true,
							dots: false,
							pagination: false,
							responsiveClass:true,
							responsive:{
								0:{
									items:2
								},
								600:{
									items:3
								},
								1000:{
									items:3
								}
							}
						});

						ocwsInitLegacySlotSliders($('#popup-shipping-city-slots'));

						$('#popup-shipping-city-slots').css('visibility', 'visible');

						fetchingSlots = false;
						$('#popup-form-messages').html('');
						if ($('#popup-shipping-city-slots').find('#oc-woo-shipping-additional').length && !$('#popup-shipping-form-messages .error').length) {
							ocwsRestorePopupShippingSchedule(form, $('#popup-shipping-city-slots'), prevShipDateCoords, prevShipStartCoords, prevShipEndCoords);
							ocwsCachePopupShippingSchedule(form);
						}
						setTimeout(function () { ocwsRefreshPopupContinueState(); }, 350);

						// todo: define different handlers for on slot click event on checkout page and on the popup
					}
				});

			} else {
				$(this).addClass('invalid');
				ocwsRefreshPopupContinueState();
			}
		});

		$('#ocws-checkout-choose-city-form').find('input[name="billing_address_coords"]').on('change', function(){
			if($(this).val()) {

				var form = $(this).closest('form');
				$('#popup-shipping-city-slots').html('');
				var cityCode = form.find('input[name="billing_city_code"]').val();
				fetchingState = true;
				$('#checkout-popup-submit-btn').prop('disabled', true);

				$.ajax({
					method: "POST",
					url: ocws.ajaxurl,
					data: {
						action: "oc_woo_shipping_fetch_state_for_coords",
						billing_address_coords: $(this).val(),
						billing_city_code: cityCode
					},
					beforeSend: function() {
						$('#form-messages').html('<span class="loading">'+ocws.localize.loading+'...</span>');
					},
					success: function(response) {
						$('#form-messages').html(response.data.resp);

						fetchingState = false;
						$('#checkout-popup-submit-btn').prop('disabled', false);
						$('#popup-form-messages').html('');
					}
				});

			} else {
				$(this).addClass('invalid');
			}
		});

		function loadPickupData(chosenMethod) {

			//chosenMethod = 'oc_woo_local_pickup_method';
			var form = $('#choose-shipping');
			var chosenMethod = $('#choose-shipping input[id^="oc_woo_local_pickup_method"]').val();
			var aff_id_input = $('#choose-shipping select[name="ocws_lp_pickup_aff_id"]');
			var date_input = $('#choose-shipping input[name="ocws_lp_pickup_date"]');
			var aff_id = (aff_id_input.length? aff_id_input.val() : '');
			var date_value = (date_input.length? date_input.val() : '');
			//$('#popup-pickup-options').html('');
			if (!aff_id) {
				form.data('redirect', '');
				ocwsRefreshPopupContinueState();
				return;
			}
			fetchingSlots = true;
			ocwsRefreshPopupContinueState();

			$.ajax({
				method: "POST",
				url: ocws.ajaxurl,
				data: {action: "oc_woo_shipping_fetch_slots_for_aff", ocws_lp_pickup_aff_id: aff_id, ocws_lp_pickup_date: date_value, shipping_method: chosenMethod, show_as_slider: true},
				beforeSend: function() {
					$('#popup-form-messages').html('<span class="loading">'+ocws.localize.loading+'...</span>');
				},
				success: function(response) {
					if ('undefined' !== typeof response.data.cart_is_empty) {
						ocws.cart_is_empty = response.data.cart_is_empty;
					}
					$('#popup-pickup-form-messages').html('');
					var resp = $(response.data.resp);
					var sitelinkelem = resp.find('.ocws-site-link');

					if (sitelinkelem.length) {
						//showShippingRedirectDialog('', sitelinkelem);
						form.data('redirect', $(sitelinkelem).attr('href'));
					}
					else {
						form.data('redirect', '');
					}
					var prevPickupDate = form.find('input[name="ocws_lp_pickup_date"]').val() || '';
					var prevPickupStart = form.find('input[name="ocws_lp_pickup_slot_start"]').val() || '';
					var prevPickupEnd = form.find('input[name="ocws_lp_pickup_slot_end"]').val() || '';
					$('#popup-pickup-options').css('visibility', 'hidden').css('opacity', '0');
					$('#popup-pickup-options').html(response.data.resp);

					$('#popup-pickup-options .ocws-day-cards-slider').owlCarousel({
						margin: 10,
						loop: false,
						autoHeight: true,
						stagePadding: 10,
						items: 4,
						rtl: ($(document.body).hasClass('rtl')),
						nav: true,
						dots: false,
						pagination: false,
						responsiveClass:true,
						responsive:{
							0:{
								items:2
							},
							600:{
								items:3
							},
							1000:{
								items:3
							}
						}
					});

					$('#popup-pickup-options .ocws-dates-only-list-slider').owlCarousel({
						margin: 10,
						loop: false,
						autoHeight: true,
						stagePadding: 10,
						items: 4,
						rtl: ($(document.body).hasClass('rtl')),
						nav: true,
						dots: false,
						pagination: false,
						responsiveClass:true,
						responsive:{
							0:{
								items:2
							},
							600:{
								items:3
							},
							1000:{
								items:3
							}
						}
					});

					ocwsInitLegacySlotSliders($('#popup-pickup-options'));

					$('#popup-pickup-options').css('visibility', 'visible').css('opacity', '1');

					fetchingSlots = false;
					$('#popup-form-messages').html('');
					ocwsRestorePopupPickupSchedule(form, $('#popup-pickup-options'), prevPickupDate, prevPickupStart, prevPickupEnd);
					setTimeout(function () { ocwsRefreshPopupContinueState(); }, 350);
					// todo: define different handlers for on slot click event on checkout page and on the popup
				}
			});
		}

		$( document.body ).on('change', '#choose-shipping select[name="ocws_lp_pickup_aff_id"]', function() {

			if($(this).val()) {
				$(this).removeClass('invalid');

				var form = $(this).closest('form');
				loadPickupData(ocwsGetPopupChosenShippingMethod(form));

			} else {
				$(this).addClass('invalid');
				ocwsRefreshPopupContinueState();
			}
		});

		$( document.body ).on('change', '#choose-shipping input[name="ocws_lp_pickup_date"]', function() {

			if($(this).val()) {
				$(this).removeClass('invalid');

				var form = $(this).closest('form');
				loadPickupData(ocwsGetPopupChosenShippingMethod(form));

			} else {
				$(this).addClass('invalid');
				ocwsRefreshPopupContinueState();
			}
		});

		$( document.body ).on('change', '#choose-shipping input[name="ocws_lp_pickup_slot_start"]', function() {


		});

		$( document.body ).on('change', '#choose-shipping input[name="ocws_lp_pickup_slot_end"]', function() {


		});

		$( document.body ).on('click', '#choose-shipping input[name="popup-shipping-method"]', function(){

			if (fetchingSlots) return;
			var form = $(this).closest('#choose-shipping');

			function ocwsSyncPopupAddressFromCheckoutIfMissing($popupForm) {
				// When switching between pickup/shipping inside the popup, the address might already exist
				// on the checkout form (session), but the popup won't refetch slots unless coords change.
				var $checkout = $('form.checkout');
				if (!$checkout.length) return;

				var fields = [
					'billing_google_autocomplete',
					'billing_city',
					'billing_city_code',
					'billing_city_name',
					'billing_street',
					'billing_house_num',
					'billing_address_coords'
				];

				fields.forEach(function(name) {
					var $popupField = $popupForm.find('[name="' + name + '"]');
					if (!$popupField.length) return;

					// Prefer popup value if it's already set.
					var popupVal = ($popupField.val() || '').toString().trim();
					if (popupVal !== '') return;

					var $checkoutField = $checkout.find('[name="' + name + '"]').first();
					if (!$checkoutField.length) return;

					var checkoutVal = ($checkoutField.val() || '').toString().trim();
					if (checkoutVal === '') return;

					$popupField.val(checkoutVal);
				});
			}

			if($(this).val().substr(0, ('oc_woo_advanced_shipping_method').length) == 'oc_woo_advanced_shipping_method') {
				show_shipping();
				hide_pickup();

				// If using polygons/Google address flow, trigger slots fetch when coords already exist.
				var coordsInput = form.find('input[name="billing_address_coords"]');
				if (coordsInput.length) {
					// First try to sync from checkout/session-backed fields, then trigger change.
					ocwsSyncPopupAddressFromCheckoutIfMissing(form);
					if (coordsInput.val()) {
						coordsInput.trigger('change');
					}
				} else {
					// Simple city dropdown flow.
					var city = form.find('select[name="selected-city"] option:selected');
					if (city.val()) {
						city.trigger('change');
					}
				}

				$(this).closest('#choose-shipping').find('label.shipping-method-label').removeClass('active');
				$(this).closest('label.shipping-method-label').addClass('active');
			} else if ($(this).val().substr(0, ('oc_woo_local_pickup_method').length) == 'oc_woo_local_pickup_method') {
				show_pickup();
				hide_shipping();
				loadPickupData($(this).val());
				$(this).closest('#choose-shipping').find('label.shipping-method-label').removeClass('active');
				$(this).closest('label.shipping-method-label').addClass('active');
			} else {
				hide_shipping();
				hide_pickup();
			}
		})

		function show_shipping() {
			$('#popup-shipping-options').css('display', 'block');
			$('#popup-shipping-form-messages').css('display', 'block');
			$('#popup-shipping-city-slots').css('display', 'block');
		}

		function hide_shipping() {
			$('#popup-shipping-options').css('display', 'none');
			$('#popup-shipping-form-messages').css('display', 'none');
			$('#popup-shipping-city-slots').css('display', 'none');
		}

		function show_pickup() {
			$('#popup-pickup-options').css('display', 'block');
			$('#popup-pickup-form-messages').css('display', 'block');
		}

		function hide_pickup() {
			$('#popup-pickup-options').css('display', 'none');
			$('#popup-pickup-form-messages').css('display', 'none');
		}

		$( document.body ).on('click', '#choose-shipping .ocws-days-list-slider .day-data', function(event) {

			event.preventDefault();

			var dataId = $(this).data('id');

			var popup = $(this).closest('#choose-shipping');

			if (popup.length) {

				popup.find('.ocws-days-with-slots-list .day-data').not('.day-card').css('display', 'none');
				popup.find('.ocws-days-with-slots-list .day-data').not('.day-card').removeClass('active');

				var daySlots = popup.find('.ocws-days-with-slots-list .day-data[data-rel-id="'+dataId+'"]').not('.day-card');
				daySlots.css('display', '');
				if (daySlots.length) {
					popup.find('.ocws-days-with-slots-list-label').css('display', '');
				}
				else {
					popup.find('.ocws-days-with-slots-list-label').css('display', 'none');
				}
			}

			$('#choose-shipping .ocws-days-list-slider .day-data').removeClass('active');
			$(this).addClass('active');

		});

		$( document.body ).on('click', '#choose-shipping .ocws-day-cards-slider .day-card.day-data', function(event) {
			if ($(event.target).closest('.slot').length) {
				return;
			}
			event.preventDefault();
			var $card = $(this);
			var popup = $card.closest('#choose-shipping');
			var beforeDate = popup.find('input[name="order_expedition_date"]').val() || '';

			var $firstSlot = $card.find('.day-card__slots .slot').first();
			if ($firstSlot.length) {
				$firstSlot.trigger('click');
			}

			// Fallback: in "dates only" mode slots may be hidden; ensure the hidden inputs update even if click handlers don't fire.
			setTimeout(function () {
				var afterDate = popup.find('input[name="order_expedition_date"]').val() || '';
				if (afterDate && String(afterDate) !== String(beforeDate)) {
					return;
				}
				if (!$firstSlot || !$firstSlot.length) {
					return;
				}
				if (!popup.length) {
					return;
				}

				var shippingParent = $firstSlot.closest('#oc-woo-shipping-additional');
				var pickupParent = $firstSlot.closest('#oc-woo-pickup-additional');
				var parentDayData = $firstSlot.closest('.day-card, .day-data');

				if (shippingParent.length) {
					$('#choose-shipping .ocws-day-cards-slider .slot, #choose-shipping .ocws-days-with-slots-list .day-data .slot').removeClass('selected');
					$firstSlot.addClass('selected');
					$(parentDayData).addClass('active');
					popup.find('input[name="order_expedition_slot_start"]').val($firstSlot.data('slot-start'));
					popup.find('input[name="order_expedition_slot_end"]').val($firstSlot.data('slot-end'));
					popup.find('input[name="order_expedition_date"]').val($firstSlot.data('date'));
					ocwsCachePopupShippingSchedule(popup);
					if (window.console && console.log) {
						console.log('[OCWS popup] fallback day-card select', {
							date: $firstSlot.data('date'),
							start: $firstSlot.data('slot-start'),
							end: $firstSlot.data('slot-end')
						});
					}
				}
				else if (pickupParent.length) {
					$('#choose-shipping .ocws-day-cards-slider .slot, #choose-shipping .ocws-days-with-slots-list .day-data .slot').removeClass('selected');
					$firstSlot.addClass('selected');
					$(parentDayData).addClass('active');
					popup.find('input[name="ocws_lp_pickup_slot_start"]').val($firstSlot.data('slot-start'));
					popup.find('input[name="ocws_lp_pickup_slot_end"]').val($firstSlot.data('slot-end'));
					popup.find('input[name="ocws_lp_pickup_date"]').val($firstSlot.data('date'));
					if (window.console && console.log) {
						console.log('[OCWS popup] fallback pickup day-card select', {
							date: $firstSlot.data('date'),
							start: $firstSlot.data('slot-start'),
							end: $firstSlot.data('slot-end')
						});
					}
				}

				ocwsRefreshPopupContinueState();
			}, 0);
		});

		$( document.body ).on('click', '#choose-shipping .ocws-day-cards-slider .slot, #choose-shipping .ocws-days-with-slots-list .day-data .slot', function(event) {

			event.preventDefault();

			var popup = $(this).closest('#choose-shipping');
			var shippingParent = $(this).closest('#oc-woo-shipping-additional');
			var pickupParent = $(this).closest('#oc-woo-pickup-additional');
			var parentDayData = $(this).closest('.day-card, .day-data');

			if (popup.length) {
				popup.find('.ocws-day-cards-slider .day-card').removeClass('active');
				popup.find('.ocws-days-with-slots-list .day-data').removeClass('active');
			}

			if (shippingParent.length) {

				$('#choose-shipping .ocws-day-cards-slider .slot, #choose-shipping .ocws-days-with-slots-list .day-data .slot').removeClass('selected');
				$(this).addClass('selected');
				$(parentDayData).addClass('active');
				popup.find('input[name="order_expedition_slot_start"]').val($(this).data('slot-start'));
				popup.find('input[name="order_expedition_slot_end"]').val($(this).data('slot-end'));
				popup.find('input[name="order_expedition_date"]').val($(this).data('date'));
				ocwsCachePopupShippingSchedule(popup);
			}
			else if (pickupParent.length) {

				$('#choose-shipping .ocws-day-cards-slider .slot, #choose-shipping .ocws-days-with-slots-list .day-data .slot').removeClass('selected');
				$(this).addClass('selected');
				$(parentDayData).addClass('active');
				popup.find('input[name="ocws_lp_pickup_slot_start"]').val($(this).data('slot-start'));
				popup.find('input[name="ocws_lp_pickup_slot_end"]').val($(this).data('slot-end'));
				popup.find('input[name="ocws_lp_pickup_date"]').val($(this).data('date'));
			}

			ocwsRefreshPopupContinueState();

		});

		$( document.body ).on('click', '#choose-shipping .ocws-dates-only-list-slider .slot', function(event) {
			$('#choose-shipping input[type="submit"]').addClass('sActive');
			event.preventDefault();

			var popup = $(this).closest('#choose-shipping');
			var shippingParent = $(this).closest('#oc-woo-shipping-additional');
			var pickupParent = $(this).closest('#oc-woo-pickup-additional');

			if (shippingParent.length) {

				$('#choose-shipping .ocws-dates-only-list-slider .slot').removeClass('selected');
				$(this).addClass('selected');

				popup.find('input[name="order_expedition_slot_start"]').val($(this).data('slot-start'));
				popup.find('input[name="order_expedition_slot_end"]').val($(this).data('slot-end'));
				popup.find('input[name="order_expedition_date"]').val($(this).data('date'));
				ocwsCachePopupShippingSchedule(popup);
			}
			else if (pickupParent.length) {

				$('#choose-shipping .ocws-dates-only-list-slider .slot').removeClass('selected');
				$(this).addClass('selected');

				popup.find('input[name="ocws_lp_pickup_slot_start"]').val($(this).data('slot-start'));
				popup.find('input[name="ocws_lp_pickup_slot_end"]').val($(this).data('slot-end'));
				popup.find('input[name="ocws_lp_pickup_date"]').val($(this).data('date'));
			}

			ocwsRefreshPopupContinueState();

		});

		function initCheckoutSliders() {
			var $slotContainer = $('form.checkout .slot-list-container');
			$slotContainer.css('visibility', 'hidden').css('opacity', '0');
			$('form.checkout .slot-list-container .ocws-day-cards-slider').css('visibility', 'hidden');
			$('form.checkout .slot-list-container .ocws-day-cards-slider').owlCarousel({
				margin: 10,
				loop: false,
				autoHeight: true,
				stagePadding: 10,
				items: 4,
				rtl: ($(document.body).hasClass('rtl')),
				nav: true,
				dots: false,
				pagination: false,
				responsiveClass:true,
				responsive:{
					0:{
						items:2
					},
					600:{
						items:3
					},
					1000:{
						items:3
					}
				}
			});
			$('form.checkout .slot-list-container .ocws-day-cards-slider').css('visibility', 'visible');

			$('form.checkout .slot-list-container .ocws-dates-only-list-slider').css('visibility', 'hidden');
			$('form.checkout .slot-list-container .ocws-dates-only-list-slider').owlCarousel({
				margin: 10,
				loop: false,
				autoHeight: true,
				stagePadding: 10,
				items: 4,
				rtl: ($(document.body).hasClass('rtl')),
				nav: true,
				dots: false,
				pagination: false,
				responsiveClass:true,
				responsive:{
					0:{
						items:2
					},
					600:{
						items:3
					},
					1000:{
						items:3
					}
				}
			});
			$('form.checkout .slot-list-container .ocws-dates-only-list-slider').css('visibility', 'visible');
			ocwsInitLegacySlotSliders($slotContainer);
			$slotContainer.css('visibility', 'visible').css('opacity', '1');

			// Match #choose-shipping popup: auto-select first day + slot once (slot click triggers update_checkout — guard against loops).
			var $checkoutForm = $('form.checkout');
			if ($checkoutForm.length) {
				if (ocwsCheckoutAutoScheduleSkipNext) {
					ocwsCheckoutAutoScheduleSkipNext = false;
				} else {
					var chosenShipping = $('#shipping_method input:checked').val() || '';
					var now = Date.now();
					var cooldownMs = 1500;
					var allowAfterCooldown = !ocwsCheckoutAutoScheduleLastTs || (now - ocwsCheckoutAutoScheduleLastTs >= cooldownMs);
					if (chosenShipping.indexOf('oc_woo_advanced_shipping_method') !== -1) {
						var $shipAdd = $checkoutForm.find('#oc-woo-shipping-additional');
						if ($shipAdd.length && $shipAdd.find('.slot-list-container').length &&
							ocwsCheckoutScheduleNeedsAutoSelect($shipAdd) && allowAfterCooldown) {
							ocwsCheckoutAutoScheduleLastTs = now;
							ocwsCheckoutAutoScheduleSkipNext = true;
							ocwsTryAutoSelectFirstSchedule($shipAdd);
						}
					} else if (chosenShipping.indexOf('oc_woo_local_pickup_method') !== -1) {
						var $pickupAdd = $checkoutForm.find('#oc-woo-pickup-additional');
						if ($pickupAdd.length && $pickupAdd.find('.slot-list-container').length &&
							ocwsCheckoutScheduleNeedsAutoSelect($pickupAdd) && allowAfterCooldown) {
							ocwsCheckoutAutoScheduleLastTs = now;
							ocwsCheckoutAutoScheduleSkipNext = true;
							ocwsTryAutoSelectFirstSchedule($pickupAdd);
						}
					}
				}
			}
		}

		initCheckoutSliders();

		// shipping popup close — רק הפופאב הראשי (לא רשימת ערים/סניפים שגם .choose-shipping-popup + inner-wrapper)
		$(document).on('click', '.choose-shipping-popup.ocws-popup .inner-wrapper .pop-close', function (e) {

			hideShippingDialog();
			$('body').css({ overflow: 'auto' });
		});

		$(document).on('click', '.choose-shipping-popup.ocws-popup .ocws-popup-dismiss__later', function (e) {
			e.preventDefault();
			hideShippingDialog();
			$('body').css({ overflow: 'auto' });
		});

		$(document).on('click', '.ocws-checkout-choose-city-popup .inner-wrapper .pop-close', function () {

			$('.ocws-checkout-choose-city-popup').removeClass('shown');
			$('body').css({ overflow: 'auto' });
		});

		$(document).on('click', '.ocws-checkout-city-list-popup .inner-wrapper .pop-close', function () {

			$('.ocws-checkout-city-list-popup').removeClass('shown');
			$('body').css({ overflow: 'auto' });
		});

		$(document).on('click', '.ocws-checkout-branch-list-popup .inner-wrapper .pop-close', function () {

			$('.ocws-checkout-branch-list-popup').removeClass('shown');
			$('body').css({ overflow: 'auto' });
		});

		$(document).on('click', 'a.ocws-all-cities-link', function (e) {

			e.preventDefault();
			$('.ocws-checkout-city-list-popup').addClass('shown');
			$('body').css({ overflow: 'hidden' });
		});

		$(document).on('click', 'a.ocws-all-branches-link', function (e) {

			e.preventDefault();
			$('.ocws-checkout-branch-list-popup').addClass('shown');
			$('body').css({ overflow: 'hidden' });
		});

		$(document).on('change', '.ocws-checkout-city-list-popup select[name="selected-city"]', function (e) {

			e.preventDefault();
			e.stopPropagation();
			$('.ocws-checkout-city-list-popup').removeClass('shown');
			$('body').css({ overflow: 'auto' });
			var cityOption = $(this).find('option:selected');
			var redirect = $(cityOption).data('redirect');
			var name = $(cityOption).data('name');
			var code = $(cityOption).data('code');
			if (redirect == '') {
				$('.ocws-enhanced-select[name="billing_city"]').val(code).trigger('change');
			}
			else {
				// TODO popup
				showShippingRedirectDialog(name, redirect, code);
			}
		});

		$(document).on('click', '.ocws-checkout-branch-list-popup .city-option a', function (e) {

			e.preventDefault();
			e.stopPropagation();
			$('.ocws-checkout-branch-list-popup').removeClass('shown');
			$('body').css({ overflow: 'auto' });
			var redirect = $(this).data('redirect');
			var name = $(this).data('name');
			var code = $(this).data('code');
			if (redirect == '') {
				$('select[name="ocws_lp_pickup_aff_id"]').val(code).trigger('change');
			}
			else {
				// TODO popup
				showPickupRedirectDialog(name, redirect, code);
			}
		});

		$( document.body ).on('click', '#ocws-delivery-data-chip.delivery-data-chip .cds-button-change', function () {

			if (!$( document.body ).hasClass('ocws-deli-style')) {
				loadShippingPopupHtml();
				showShippingDialog();
				$('body').css({ overflow: 'hidden' });
				addCookie();
			}
		});

		function loadShippingPopupHtml() {
			/////////
			$.ajax({
				method: "POST",
				url: ocws.ajaxurl,
				data: {action: "oc_woo_shipping_popup_html"},
				beforeSend: function() {
					$('#choose-shipping').html('<span class="loading">'+ocws.localize.loading+'...</span>');
				},
				success: function(response) {
					var resp = $(response.data.resp);
					$('#choose-shipping').html(resp.find('#choose-shipping').html());
					//$('#choose-shipping input[name="popup-shipping-method"]').trigger('click');

					$( '#choose-shipping :input.ocws-enhanced-select' ).filter( ':not(.enhanced)' ).each( function() {
						var select2_args = { minimumResultsForSearch: 5 };
						$( this ).select2( select2_args ).addClass( 'enhanced' );
					});

					$('#popup-shipping-city-slots .ocws-day-cards-slider').css('visibility', 'hidden');
					$('#popup-shipping-city-slots .ocws-day-cards-slider').owlCarousel({
						margin: 10,
						loop: false,
						autoHeight: true,
						stagePadding: 10,
						items: 4,
						rtl: ($(document.body).hasClass('rtl')),
						nav: true,
						dots: false,
						pagination: false,
						responsiveClass:true,
						responsive:{
							0:{
								items:2
							},
							600:{
								items:3
							},
							1000:{
								items:3
							}
						}
					});
					$('#popup-shipping-city-slots .ocws-day-cards-slider').css('visibility', 'visible');

					$('#popup-shipping-city-slots .ocws-dates-only-list-slider').css('visibility', 'hidden');
					$('#popup-shipping-city-slots .ocws-dates-only-list-slider').owlCarousel({
						margin: 10,
						loop: false,
						autoHeight: true,
						stagePadding: 10,
						items: 4,
						rtl: ($(document.body).hasClass('rtl')),
						nav: true,
						dots: false,
						pagination: false,
						responsiveClass:true,
						responsive:{
							0:{
								items:2
							},
							600:{
								items:3
							},
							1000:{
								items:3
							}
						}
					});
					$('#popup-shipping-city-slots .ocws-dates-only-list-slider').css('visibility', 'visible');

					ocwsInitLegacySlotSliders($('#popup-shipping-city-slots'));

					$('#popup-pickup-options .ocws-day-cards-slider').css('visibility', 'hidden');
					$('#popup-pickup-options .ocws-day-cards-slider').owlCarousel({
						margin: 10,
						loop: false,
						autoHeight: true,
						stagePadding: 10,
						items: 4,
						rtl: ($(document.body).hasClass('rtl')),
						nav: true,
						dots: false,
						pagination: false,
						responsiveClass:true,
						responsive:{
							0:{
								items:2
							},
							600:{
								items:3
							},
							1000:{
								items:3
							}
						}
					});
					$('#popup-pickup-options .ocws-day-cards-slider').css('visibility', 'visible');

					$('#popup-pickup-options .ocws-dates-only-list-slider').css('visibility', 'hidden');
					$('#popup-pickup-options .ocws-dates-only-list-slider').owlCarousel({
						margin: 10,
						loop: false,
						autoHeight: true,
						stagePadding: 10,
						items: 4,
						rtl: ($(document.body).hasClass('rtl')),
						nav: true,
						dots: false,
						pagination: false,
						responsiveClass:true,
						responsive:{
							0:{
								items:2
							},
							600:{
								items:3
							},
							1000:{
								items:3
							}
						}
					});
					$('#popup-pickup-options .ocws-dates-only-list-slider').css('visibility', 'visible');

					ocwsInitLegacySlotSliders($('#popup-pickup-options'));

					$('#popup-form-messages').html('');
					$(document.body).trigger('shipping_popup_loaded');
					setTimeout(function () {
						var $form = $('#choose-shipping');
						if (!$form.length) {
							return;
						}
						var $slots = $form.find('#popup-shipping-city-slots');
						var rd = ($form.find('input[name="order_expedition_date"]').val() || '').trim();
						var rs = ($form.find('input[name="order_expedition_slot_start"]').val() || '').trim();
						var re = ($form.find('input[name="order_expedition_slot_end"]').val() || '').trim();
						if ($slots.length && $slots.find('#oc-woo-shipping-additional').length && (rd || (rs && re))) {
							ocwsRestorePopupShippingSchedule($form, $slots, rd, rs, re);
							ocwsCachePopupShippingSchedule($form);
						}
						ocwsRefreshPopupContinueState();
					}, 450);
				}
			});
		}

		$( document.body ).on( 'change', 'form.checkout input[name="billing_address_coords"]', function(e) {

			e.preventDefault();

			$('.woocommerce-billing-fields').block({
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			});
			$( document.body ).trigger( 'update_checkout' );

			// TODO check polygons
			/*var coords = $(this).val();
			if (coords && ocws.polygons) {

				coords = coords.replace('(', '{"lat":');
				coords = coords.replace(', ', ',"lng":');
				coords = coords.replace(')', '}');
				coords = JSON.parse(coords);

				$('.woocommerce-billing-fields').block({
					message: null,
					overlayCSS: {
						background: '#fff',
						opacity: 0.6
					}
				});

				var foundPolygon = '';

				for (var i = 0; i < ocws.polygons.length; i++) {
					var polygon = ocws.polygons[i];
					if (polygon.is_enabled == 1) {
						var paths = JSON.parse(JSON.stringify(polygon.gm_shapes.gm_shapes).replace(/"([+-]?\d+(\.\d+)?)"/g, '$1'));
						var gmpolygon = new google.maps.Polygon({paths: paths});
						var result = google.maps.geometry.poly.containsLocation(
							new google.maps.LatLng(coords.lat, coords.lng),
							gmpolygon
						);
						if (true === result) {
							$('form.checkout input[name="billing_polygon_code"]').val(polygon.location_code);
							foundPolygon = polygon.location_code;
							break;
						}
					}
				}

				if (foundPolygon === '') {
					$('form.checkout input[name="billing_polygon_code"]').val('');
				}

				var cityName = $('form.checkout input[name="billing_city_name"]').val();

				if (cityName) {

					const geocoder = new google.maps.Geocoder();
					geocoder
						.geocode({ address: cityName, componentRestrictions: { country: 'IL' } })
						.then(({results}) => {
							if (results.length && results[0] && results[0].place_id) {
								$('form.checkout input[name="billing_city"]').val(results[0].place_id);
								$('.woocommerce-billing-fields').unblock();
								$( document.body ).trigger( 'update_checkout' );
							}
						})
						.catch((e) =>
							null
					);
				}

				$('.woocommerce-billing-fields').unblock();
			}*/
		});

		if ( $('body').hasClass('woocommerce-checkout') ){

			let localStorageName 	= 'ocws_chekout_fields';
			var checkoutFields 		= get_checkout_storage_object();

			// on billing | shipping form inputs change - fire
			$( document.body ).on( 'change', '.ocws_update_checkout_on_change', save_field_value_on_change );
			// on checkout page init
			/*$( document.body ).on( 'init_checkout', retrieve_checkout_field_values );*/

			// Save field value on checkout field change
			function save_field_value_on_change(e){
				let $t 		= $(this);
				let parent 	= $t.find('.woocommerce-input-wrapper');
				let field 	= parent.find( 'input' );
				if ( !parent.length ){
					field 	= parent.find( 'select' );
				}
				if ( !parent.length ){
					field 	= parent.find( 'textarea' );
				}
				let fieldName 	= field.attr('name');

				checkoutFields[ fieldName ] = field.val();
				localStorage.setItem( localStorageName, JSON.stringify( checkoutFields ) );
			}

			// get object from local_storage or create new
			function get_checkout_storage_object(e){
				checkoutFields = get_local_storage_checkout_obj();
				if ( !checkoutFields ){
					checkoutFields = init_local_storage_checkout_obj();
				}
				return checkoutFields;
			}

			function get_local_storage_checkout_obj(){
				let checkoutFields = localStorage.getItem( localStorageName );
				// object exist
				if ( checkoutFields !== null && checkoutFields !== undefined ) {
					return JSON.parse( checkoutFields );
				} else {
					//  doesn`t exist
					return false;
				}
			}

			// init local storage  object , get values from field | based on special class added to input wrapper
			function init_local_storage_checkout_obj(){
				let selectorFields = $('.ocws_update_checkout_on_change .woocommerce-input-wrapper');
				// init empty object
				checkoutFields 	= {}
				var len 		= selectorFields.length;
				while ( len-- ) {
					// regular input
					let currField = $( selectorFields[len] );
					let field 	  = currField.find( 'input' );
					// city select
					if ( !field.length ){
						field = currField.find( 'select' );
					}
					// notes
					if ( !field.length ){
						field = currField.find( 'textarea' );
					}
					let name = field.attr('name');
					let val 	= field.val();
					checkoutFields[ name ] = val;
				}

				localStorage.setItem( localStorageName, JSON.stringify( checkoutFields ) );
				return checkoutFields;
			}

			// retrive data from localstorage and set value to field
			function retrieve_checkout_field_values(){
				if ( checkoutFields !== null  ){
					for ( const field in checkoutFields ) {
						let fieldSelector = $('#' + field)
						// get field by id 
						if ( fieldSelector.val() == '' ){
							// retrieve value from local storage
							fieldSelector.val( checkoutFields[field] )
						}
					}
				}
			}
		}

		$( document.body ).on( 'ocws_cart_fragment_refresh', function() {
			ocws_refresh_cart_fragment();
		});

		var $fragment_refresh = {
			url: ocws.woo_wc_ajax_url.toString().replace( '%%endpoint%%', 'get_refreshed_fragments' )+'&rel=ocws',
			type: 'POST',
			data: {
				time: new Date().getTime()
			},
			success: function( data ) {
				if ( data && data.fragments ) {

					$.each( data.fragments, function( key, value ) {
						$( key ).replaceWith( value );
					});

					$( document.body ).trigger( 'ocws_cart_fragments_refreshed' );
				}
			},
			error: function() {
				$( document.body ).trigger( 'ocws_cart_fragments_ajax_error' );
			}
		};

		/* Named callback for refreshing cart fragment */
		function ocws_refresh_cart_fragment() {
			$.ajax( $fragment_refresh );
		}

		// Theme / checkout-blocks: open popup without delivery chip (e.g. checkout page) — same as chip click.
		window.ocwsLoadShippingPopupHtml = loadShippingPopupHtml;
		window.ocwsShowShippingDialog = showShippingDialog;

		setTimeout(function () { ocwsRefreshPopupContinueState(); }, 0);

	});


})( jQuery, ocws );
