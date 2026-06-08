
<div class="ocws-checkout-city-list-popup choose-shipping-popup" style="">
    <div class="white-overlay"></div>
    <div class="inner">

        <div class="inner-wrapper">
            <div class="pop-close" style="">
                <img src="<?php echo OCWS_ASSESTS_URL; ?>/images/cancel.svg" alt="">
            </div>

            <header>
                <h2 class="entry-title crossed-title"><?php echo esc_html( __('בחר עיר/יישוב למשלוח') ); ?></h2>
            </header>

            <?php
            $city_options = OCWS_Advanced_Shipping::get_all_locations_networkwide(true);
            ?>
            <div>
                <select name="selected-city" class="ocws-enhanced-select">
                    <option val=""><?php echo esc_html(__('City/Town', 'ocws')) ?></option>
                <?php foreach ($city_options as $code => $name) { ?>
                    <?php
                    $redirect = '';
                    $citycode = $code;
                    if (str_contains($code.'', ':::')) {
                        $bid = explode(':::', $code, 2);
                        $go_to_blog_id = intval($bid[0]);
                        $citycode = isset($bid[1])? $bid[1] : 0;
                        $redirect = ocws_convert_current_page_url($go_to_blog_id, ['ocws_from_store' => get_current_blog_id()]);
                    }
                    ?>
                    <option data-code="<?php echo esc_attr($code) ?>" data-name="<?php echo esc_attr($name) ?>" data-redirect="<?php echo esc_url($redirect); ?>" value="<?php echo esc_attr($code) ?>"><?php echo esc_html($name) ?></option>
                <?php } ?>
                </select>
            </div>

        </div><!--inner-wrapper-->
    </div><!--inner-->
</div><!--choose-shipping-popup-->