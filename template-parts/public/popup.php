<?php
defined( 'ABSPATH' ) || exit;

/**
 * @var int $available_methods_number
 * @var int $chosen_method_index
 * @var array $methods assoc array with keys 'method_id', 'method_instance_id', 'type' ('pickup', 'shipping'), 'is_chosen', 'title'
 * @var array $pickup_branches
 * @var array $shipping_locations
 *
 */

$show_shipping_options      = false;
$show_pickup_options        = false;
$shipping_popup_description = get_option('ocws_common_shipping_popup_description');
foreach ($methods as $method) {
    if ($method['is_chosen']) {
        if ($method['type'] == 'shipping') {
            $show_shipping_options = true;
        }
        else if ($method['type'] == 'pickup') {
            $show_pickup_options = true;
        }
    }
}
?>

<div class="choose-shipping-popup ocws-popup">
    <div style="display: none">
        <?php
        //var_dump($available_methods_number);
        ?>
    </div>
    <div class="white-overlay"></div>
    <div class="inner">

        <div class="inner-wrapper">
            <header>
                <!--<button type="button" class="close" aria-label="Close">
                    <span aria-hidden="true">&times;*****************</span>
                </button>-->
                <?php if(get_field('shipping_popup_icon' , 'option')):?>
                    <div class="icon">
                        <img src="<?php the_field('shipping_popup_icon' , 'option')?>">
                    </div>
                <?php endif;?>
                <h2 class="entry-title crossed-title"><?php echo esc_attr( ocws_get_multilingual_option('ocws_common_popup_title') ); ?></h2>
            </header>

            <form id="choose-shipping" class="choose-shipping<?php if (is_multisite()) { echo ' ocws-multisite'; } ?>" action="" method="post">

                <div id="popup-form-messages" style=""></div>

                <div class="ship-choose">
                    <?php foreach ($methods as $method) { ?>
                        <div class="shipping-method-wraper <?php echo $method['method_id'].':'.$method['method_instance_id'];?>"
                             style="<?php echo ($available_methods_number === 1? 'display: none;' : '') ?>">

                            <label class="shipping-method-label <?php echo $method['is_chosen'] ? 'active' : '' ?>" for="<?php echo $method['method_id'].':'.$method['method_instance_id'];?>">
                                <div class="radio-wrapper">
                                    <input data-title="<?php echo esc_attr($method['title']) ?>"
                                           type="radio" <?php echo $method['is_chosen'] ? 'checked' : '' ?>
                                           name="popup-shipping-method"
                                           value="<?php echo $method['method_id'].':'.$method['method_instance_id']?>"
                                           id="<?php echo $method['method_id'].':'.$method['method_instance_id']?>">
                                    <span class="radiocheck"></span>
                                </div>
                                <span class="label"><?php echo esc_attr($method['title']); ?></span>
                            </label>

                        </div>
                    <?php } ?>
                </div>
                <div id="popup-shipping-options" style="<?php echo $show_shipping_options? '' : 'display: none;' ?>">
                <?php if (!ocws_use_google_cities_and_polygons()) { ?>
                    <?php if(isset($shipping_locations)) { ?>

                        <div class="shipping-description">
                            <?php do_action( 'ocws_shipping_popup_decription'); ?>
                            <?php if ($shipping_popup_description) { ?>
                                <div><?php echo $shipping_popup_description; ?></div>
                            <?php } ?>
                        </div>
                        <?php if ($available_methods_number === 1) { ?>
                            <label for="selected-city" class=""><?php echo esc_html(__('Choose shipping location', 'ocws'))?>&nbsp;<abbr class="required" title="<?php echo esc_html(__('Required', 'ocws'))?>">*</abbr></label>
                        <?php } ?>
                        <?php $shipping_location_code = function_exists( 'ocws_popup_get_billing_field_value' ) ? ocws_popup_get_billing_field_value( 'billing_city' ) : WC()->checkout()->get_value( 'billing_city' ); ?>
                        <div class="selected-city">
                            <select name="selected-city" class="ocws-enhanced-select">
                                <option value=""><?php echo esc_html(__('Select your distribution area', 'ocws')) ?></option>
                                <?php foreach ($shipping_locations as $code => $city_option):?>
                                    <option <?php echo ($shipping_location_code && isset($shipping_locations[$shipping_location_code]) && $shipping_location_code == $code? 'selected' : '') ?> value="<?php echo $code?>"><?php echo $city_option?></option>
                                <?php endforeach;?>
                            </select>
                        </div>
						<?php
						if ( $show_shipping_options ) {
							ocws_render_address_extra_fields_for_popup( array() );
						}
						?>
                    <?php } ?>
                <?php } else { ?>

                    <div class="shipping-description">
                        <?php do_action( 'ocws_shipping_popup_decription'); ?>
                        <div><?php echo $shipping_popup_description; ?></div>
                    </div>
                    <div class="ocws-checkout-inputs-pp">

                        <?php
                        $street_value    = function_exists( 'ocws_popup_get_billing_field_value' ) ? ocws_popup_get_billing_field_value( 'billing_street' ) : WC()->checkout()->get_value( 'billing_street' );
                        $city_value      = function_exists( 'ocws_popup_get_billing_field_value' ) ? ocws_popup_get_billing_field_value( 'billing_city_name' ) : WC()->checkout()->get_value( 'billing_city_name' );
                        $house_num_value = function_exists( 'ocws_popup_get_billing_field_value' ) ? ocws_popup_get_billing_field_value( 'billing_house_num' ) : WC()->checkout()->get_value( 'billing_house_num' );
                        $autocomplete    = '';
                        if ( $street_value && $city_value && $house_num_value ) {
                            $autocomplete = $street_value . ' ' . $house_num_value . ', ' . $city_value;
                        }
                        $pp_city        = function_exists( 'ocws_popup_get_billing_field_value' ) ? ocws_popup_get_billing_field_value( 'billing_city' ) : WC()->checkout()->get_value( 'billing_city' );
                        $pp_city_code   = function_exists( 'ocws_popup_get_billing_field_value' ) ? ocws_popup_get_billing_field_value( 'billing_city_code' ) : WC()->checkout()->get_value( 'billing_city_code' );
                        $pp_city_name   = function_exists( 'ocws_popup_get_billing_field_value' ) ? ocws_popup_get_billing_field_value( 'billing_city_name' ) : WC()->checkout()->get_value( 'billing_city_name' );
                        $pp_street      = function_exists( 'ocws_popup_get_billing_field_value' ) ? ocws_popup_get_billing_field_value( 'billing_street' ) : WC()->checkout()->get_value( 'billing_street' );
                        $pp_house       = function_exists( 'ocws_popup_get_billing_field_value' ) ? ocws_popup_get_billing_field_value( 'billing_house_num' ) : WC()->checkout()->get_value( 'billing_house_num' );
                        $pp_coords      = function_exists( 'ocws_popup_get_billing_field_value' ) ? ocws_popup_get_billing_field_value( 'billing_address_coords' ) : WC()->checkout()->get_value( 'billing_address_coords' );
                        ?>

                        <input type="text" class="input-text ocws-checkout-pac-input pac-target-input"
                               name="billing_google_autocomplete" id="billing_google_autocomplete_p" placeholder="<?php echo esc_html(__('Enter your address here', 'ocws')) ?>" value="<?php echo esc_attr($autocomplete) ?>" autocomplete="off">

                        <input type="hidden" name="billing_city" id="billing_city_pp" value="<?php echo esc_attr( $pp_city ); ?>">

                        <input type="hidden" name="billing_city_code" id="billing_city_code_pp" value="<?php echo esc_attr( $pp_city_code ); ?>">

                        <input type="hidden" name="billing_city_name" id="billing_city_name_pp" value="<?php echo esc_attr( $pp_city_name ); ?>">

                        <input type="hidden" name="billing_street" id="billing_street_pp" value="<?php echo esc_attr( $pp_street ); ?>">

                        <input type="hidden" name="billing_house_num" id="billing_house_num_pp" value="<?php echo esc_attr( $pp_house ); ?>">

                        <input type="hidden" name="billing_address_coords" id="billing_address_coords_pp" value="<?php echo esc_attr( $pp_coords ); ?>">

                    </div>
					<?php
					if ( $show_shipping_options ) {
						ocws_render_address_extra_fields_for_popup( array() );
					}
					?>
                <?php } ?>
                </div>
                <div id="popup-shipping-form-messages" style="<?php echo $show_shipping_options? '' : 'display: none;' ?>"></div>

                <div id="popup-shipping-city-slots" style="<?php echo $show_shipping_options? '' : 'display: none;' ?>">
                    <?php
                    if ( ! isset( $shipping_location_code ) ) {
                        $shipping_location_code = '';
                    }
                    // מצב פוליגונים: בשורה 90 מוגדר רק במצב ערים קלאסי; כאן מחשבים קוד אזור מקואורדינט ות (סשן/צ'קאאוט) כדי להציג קומה/דירה/זמנים גם בטעינה ראשונית
                    if ( ! $shipping_location_code && ocws_use_google_cities_and_polygons() ) {
                        $coords_for_code = function_exists( 'ocws_popup_get_billing_field_value' )
                            ? ocws_popup_get_billing_field_value( 'billing_address_coords' )
                            : WC()->checkout()->get_value( 'billing_address_coords' );
                        if ( empty( $coords_for_code ) && isset( WC()->session ) ) {
                            $coords_for_code = WC()->session->get( 'chosen_address_coords' );
                        }
                        if ( ! empty( $coords_for_code ) ) {
                            $ccode = function_exists( 'ocws_popup_get_billing_field_value' )
                                ? ocws_popup_get_billing_field_value( 'billing_city_code' )
                                : WC()->checkout()->get_value( 'billing_city_code' );
                            $post_data = array(
                                'billing_address_coords' => $coords_for_code,
                                'billing_city_code'      => $ccode ? $ccode : (string) WC()->session->get( 'chosen_city_code', '' ),
                            );
                            $shipping_location_code = OC_Woo_Shipping_Polygon::get_location_code_by_post_data_network( $post_data );
                        }
                    }
                    if ( $shipping_location_code ) {
						$GLOBALS['ocws_ocws_inner_skip_address_extras'] = true;
						ocws_render_shipping_additional_fields();
						unset( $GLOBALS['ocws_ocws_inner_skip_address_extras'] );
                    }
                    ?>
                </div>

                <div id="popup-pickup-options" style="<?php echo $show_pickup_options? '' : 'display: none;' ?>">
                    <?php OCWS_LP_Local_Pickup::render_pickup_additional_fields(); ?>
                </div>
                <div id="popup-pickup-form-messages" style="<?php echo $show_pickup_options? '' : 'display: none;' ?>"></div>

                <div class="ocws-popup-continue-row">
                    <div class="ocws-popup-min-total-notice" id="ocws-popup-min-total-notice" hidden></div>
                    <input type="submit" id="ocws-popup-continue-submit" class="button green ocws-popup-continue-submit" value="<?php _e('Continue' , 'ocws')?>" disabled="disabled" aria-disabled="true">
                </div>
            </form>
            <div class="ocws-popup-dismiss">
                <hr class="ocws-popup-dismiss__rule" />
                <button type="button" class="ocws-popup-dismiss__later"><?php esc_html_e( 'Choose later', 'ocws' ); ?></button>
            </div>
        </div><!--inner-wrapper-->
    </div><!--inner-->
</div><!--choose-shipping-popup-->
