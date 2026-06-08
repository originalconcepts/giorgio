
<div class="ocws-checkout-branch-list-popup choose-shipping-popup" style="">
    <div class="white-overlay"></div>
    <div class="inner">

        <div class="inner-wrapper">
            <div class="pop-close" style="">
                <img src="<?php echo OCWS_ASSESTS_URL; ?>/images/cancel.svg" alt="">
            </div>

            <header>
                <h2 class="entry-title crossed-title"><?php echo esc_html( __('בחר סניף מהרשימה') ); ?></h2>
            </header>

            <?php
            $city_options = OCWS_LP_Local_Pickup::get_affiliates_dropdown_networkwide(true);
            ?>

            <div>
                <?php foreach ($city_options as $code => $name) { ?>
                    <?php
                    $redirect = '';
                    if (str_contains($code.'', ':::')) {
                        $bid = explode(':::', $code, 2);
                        $go_to_blog_id = intval($bid[0]);
                        $redirect = ocws_convert_current_page_url($go_to_blog_id, ['ocws_from_store' => get_current_blog_id()]);
                    }
                    ?>
                    <div class="city-option">
                        <a data-code="<?php echo esc_attr($code) ?>" data-name="<?php echo esc_attr($name) ?>" data-redirect="<?php echo esc_url($redirect); ?>"><?php echo esc_html($name) ?></a>
                    </div>
                <?php } ?>
            </div>

        </div><!--inner-wrapper-->
    </div><!--inner-->
</div><!--choose-shipping-popup-->