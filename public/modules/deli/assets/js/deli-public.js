(function( $, ocws, deli_public ) {

    'use strict';

    $(function() {

        var c = $('.ocws-minicart-chip');
        if (c.length) {
            if (c.data('addedtocart') != '') {
                displayCartSidebar();
            }
        }

        $('ul.ocws-minicart-removed-items').slick({
            dots: false,
            infinite: false,
            speed: 300,
            slidesToShow: 1,
            centerMode: false,
            variableWidth: true,
            rtl: true,
            appendArrows: $('ul.ocws-minicart-removed-items').parent().find('.buttons-here .buttons')
        });

        const dropdownElementList = document.querySelectorAll('.delivery-dropdown-toggle');
        const dropdownList = [...dropdownElementList].map(dropdownToggleEl => new bootstrap.Dropdown(dropdownToggleEl));

        $.datepicker.setDefaults($.datepicker.regional['he']);

        var locationHash = location.hash.substring( 1 );
        if (locationHash == 'change-shipping') {
            remove_hash_from_url();
            displayCartSidebar();
            onDeliverySettingsChangeRequest();
        }

        function remove_hash_from_url() {
            var uri = window.location.toString();

            if (uri.indexOf("#") > 0) {
                var clean_uri = uri.substring(0,
                    uri.indexOf("#"));

                window.history.replaceState({},
                    document.title, clean_uri);
            }
        }

        var fetchingSlots = false;

        $( document.body ).on('click', '.checkout-delivery-data-chip .cds-button-change', function () {

            if ($('form.checkout').length) {
                $( "#dialog" ).dialog({
                    resizable: false,
                    height: "auto",
                    width: 500,
                    modal: true,
                    buttons: [
                        {
                            text: deli_public.localize.continue_to_change,
                            click: function() {
                                $( this ).dialog( "close" );
                                onDeliverySettingsChangeRequest();
                            }
                        },
                        {
                            text: deli_public.localize.back_to_checkout,
                            click: function() {
                                $( this ).dialog( "close" );
                            }
                        },
                    ]
                });
            }
            //else {
            //    onDeliverySettingsChangeRequest();
            //}

        });

        $( document ).on('click', '.ocws-deli-style .delivery-data-chip .cds-button-change', function () {

            if ($(this).hasClass('regular-products')) {
                onDeliverySettingsChangeRequest();
            }
            else {
                if ($(this).hasClass('not-empty-cart')) {
                    $( "#dialog" ).dialog({
                        resizable: false,
                        height: "auto",
                        width: 500,
                        modal: true,
                        buttons: [
                            {
                                text: deli_public.localize.continue_to_change,
                                click: function() {
                                    $( this ).dialog( "close" );
                                    onDeliverySettingsChangeRequest();
                                }
                            },
                            {
                                text: deli_public.localize.back_to_cart,
                                click: function() {
                                    $( this ).dialog( "close" );
                                }
                            },
                        ]
                    });
                }
                else {
                    onDeliverySettingsChangeRequest();
                }
            }



        });

        $( document.body ).on('click', '#cart-delivery-settings-form input[name="minicart-shipping-method"]', function(){

            if (fetchingSlots) return;
            var form = $(this).closest('#cart-delivery-settings-form');

            update_selected_method_wrapper($(this));
            if($(this).val().substr(0, ('oc_woo_advanced_shipping_method').length) == 'oc_woo_advanced_shipping_method') {
                show_shipping();
                hide_pickup(); 
                show_change_method();
                hide_methods();
                hide_submit_button();
                var city = form.find('select[name="selected-city"] option:selected');
                if (city.attr('selected') != null && city.val()) {
                    city.trigger('change');
                }
            } else if ($(this).val().substr(0, ('oc_woo_local_pickup_method').length) == 'oc_woo_local_pickup_method') {
                show_pickup();
                hide_shipping();
                show_change_method();
                hide_methods();
                hide_submit_button();
                var branch = form.find('select[name="ocws_lp_pickup_aff_id"] option:selected');
                if (branch.attr('selected') != null && branch.val()) {
                    branch.trigger('change');
                }
            } else {
                hide_shipping();
                hide_pickup();
            }
        });

        function hide_submit_button() {
            $('.cart-delivery-settings-actions').addClass('cds-hidden');
        }

        function show_submit_form() {
            $('.cart-delivery-settings').removeClass('cds-hidden');
        }

        function hide_submit_form() {
            $('.cart-delivery-settings').addClass('cds-hidden');
        }

        function update_selected_method_wrapper(input) {
            var wrapper = $(input).closest('.cart-shipping-method-wraper');
            $('#cart-delivery-settings-form .cart-shipping-method-wraper').removeClass('selected');
            wrapper.addClass('selected');
        }

        function show_shipping() {
            $('#minicart-shipping-options').removeClass('cds-hidden');
            $('#minicart-shipping-form-messages').removeClass('cds-hidden');
            $('#minicart-shipping-city-slots').removeClass('cds-hidden');
        }

        function hide_shipping() {
            $('#minicart-shipping-options').addClass('cds-hidden');
            $('#minicart-shipping-form-messages').addClass('cds-hidden');
            $('#minicart-shipping-city-slots').addClass('cds-hidden');
        }

        function show_pickup() {
            $('#minicart-pickup-options').removeClass('cds-hidden');
            $('#minicart-pickup-form-messages').removeClass('cds-hidden');
            $('#minicart-pickup-branch-slots').removeClass('cds-hidden');
        }

        function hide_pickup() {
            $('#minicart-pickup-options').addClass('cds-hidden');
            $('#minicart-pickup-form-messages').addClass('cds-hidden');
            $('#minicart-pickup-branch-slots').addClass('cds-hidden');
        }

        function show_change_method() {
            $('a.change-delivery-method').removeClass('cds-hidden');
        }

        function hide_change_method() {
            $('a.change-delivery-method').addClass('cds-hidden');
        }

        function show_back_to_cart() {
            /*$('a.back-to-cart').removeClass('cds-hidden');*/
        }

        function hide_back_to_cart() {
            /*$('a.back-to-cart').addClass('cds-hidden');*/
        }

        function show_methods() {
            $('.delivery-settings-screen-1-2').removeClass('cds-hidden');
        }

        function hide_methods() {
            $('.delivery-settings-screen-1-2').addClass('cds-hidden');
        }

        function showShippingRedirectDialog(sitename, sitelinkelem) {
            var dialog = $('#shipping-redirect-dialog');
            if (dialog.length) {
                dialog.dialog({
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
                                window.location.replace($(sitelinkelem).attr('href'));
                            }
                        },
                        {
                            text: ocws.localize.back_to_checkout,
                            click: function() {
                                $( this ).dialog( "close" );
                            }
                        },
                    ]
                });
            }
        }

        function showPickupRedirectDialog(sitename, sitelinkelem) {
            var dialog = $('#pickup-redirect-dialog');
            if (dialog.length) {
                dialog.dialog({
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
                                window.location.replace($(sitelinkelem).attr('href'));
                            }
                        },
                        {
                            text: ocws.localize.back_to_checkout,
                            click: function() {
                                $( this ).dialog( "close" );
                            }
                        },
                    ]
                });
            }
        }

        $( document.body ).on('change', '#cart-delivery-settings-form select[name="selected-city"]', function(){
            if($(this).val()) { //console.log('billing city: ' + $(this).val());
                $(this).removeClass('invalid');

                var form = $(this).closest('form');
                var chosenMethod = form.find('input[name="minicart-shipping-method"]:checked').val();
                $('#minicart-shipping-city-slots').html('');
                fetchingSlots = true;

                $.ajax({
                    method: "POST",
                    url: ocws.ajaxurl,
                    data: {action: "ocws_deli_fetch_slots_for_city", billing_city: $(this).val(), shipping_method: chosenMethod, show_as_slider: true},
                    beforeSend: function() {
                        $('#minicart-shipping-form-messages').html('<span class="loader">'+deli_public.localize.loading+'...</span>');
                    },
                    success: function(response) {
                        console.log(response);
                        $('#minicart-shipping-form-messages').html('');

                        var resp = $(response.data.resp);
                        var sitelinkelem = resp.find('.ocws-site-link');

                        $('#popup-shipping-city-slots').html(response.data.resp);

                        if (sitelinkelem.length) {
                            showShippingRedirectDialog('', sitelinkelem);
                        }

                        $('#minicart-shipping-city-slots').html(response.data.resp);

                        // oc-woo-shipping-additional--message
                        let block  		=  $('#minicart-shipping-city-slots').find('#oc-woo-shipping-additional--message');
                        if ( block.length ){
                            block.show();
                        }
 
                        $('#minicart-shipping-city-slots .ocws-day-cards-slider').owlCarousel({
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

                        $('#minicart-shipping-city-slots .ocws-dates-only-list-slider').owlCarousel({
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

                        fetchingSlots = false;
                        // todo: define different handlers for on slot click event on checkout page and on the minicart
                    }
                });

            } else {
                $(this).addClass('invalid');
            }
        });

        $( document.body ).on('change', '#cart-delivery-settings-form input[name="billing_address_coords"]', function(){
            if($(this).val()) { //console.log('billing city: ' + $(this).val());
                $(this).removeClass('invalid');

                var form = $(this).closest('form');
                var chosenMethod = form.find('input[name="minicart-shipping-method"]:checked').val();
                var cityCode = form.find('input[name="billing_city_code"]').val();
                $('#minicart-shipping-city-slots').html('');
                fetchingSlots = true;

                $.ajax({
                    method: "POST",
                    url: ocws.ajaxurl,
                    data: {
                        action: "ocws_deli_fetch_slots_for_coords",
                        billing_address_coords: $(this).val(),
                        billing_city_code: cityCode,
                        shipping_method: chosenMethod,
                        show_as_slider: true},
                    beforeSend: function() {
                        $('#minicart-shipping-form-messages').html('<span class="loader">'+deli_public.localize.loading+'...</span>');
                    },
                    success: function(response) {
                        console.log(response);
                        $('#minicart-shipping-form-messages').html('');

                        var resp = $(response.data.resp);
                        var sitelinkelem = resp.find('.ocws-site-link');

                        $('#popup-shipping-city-slots').html(response.data.resp);

                        if (sitelinkelem.length) {
                            showShippingRedirectDialog('', sitelinkelem);
                        }

                        $('#minicart-shipping-city-slots').html(response.data.resp);

                        // oc-woo-shipping-additional--message
                        let block  		=  $('#minicart-shipping-city-slots').find('#oc-woo-shipping-additional--message');
                        if ( block.length ){
                            block.show();
                        }

                        $('#minicart-shipping-city-slots .ocws-day-cards-slider').owlCarousel({
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

                        $('#minicart-shipping-city-slots .ocws-dates-only-list-slider').owlCarousel({
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

                        fetchingSlots = false;
                        // todo: define different handlers for on slot click event on checkout page and on the minicart
                    }
                });

            } else {
                $(this).addClass('invalid');
            }
        });

        $( document.body ).on('change', '#cart-delivery-settings-form select[name="ocws_lp_pickup_aff_id"]', function(){
            if($(this).val()) { //console.log('billing city: ' + $(this).val());
                $(this).removeClass('invalid');

                var form = $(this).closest('form');
                var chosenMethod = form.find('input[name="minicart-shipping-method"]:checked').val();
                $('#minicart-pickup-branch-slots').html('');
                fetchingSlots = true;

                $.ajax({
                    method: "POST",
                    url: ocws.ajaxurl,
                    data: {action: "ocws_deli_fetch_slots_for_aff", ocws_lp_pickup_aff_id: $(this).val(), shipping_method: chosenMethod, show_as_slider: true},
                    beforeSend: function() {
                        $('#minicart-pickup-form-messages').html('<span class="loader">'+deli_public.localize.loading+'...</span>');
                    },
                    success: function(response) {
                        console.log(response);
                        $('#minicart-pickup-form-messages').html('');

                        var resp = $(response.data.resp);
                        var sitelinkelem = resp.find('.ocws-site-link');

                        $('#popup-pickup-options').html(response.data.resp);

                        if (sitelinkelem.length) {
                            showPickupRedirectDialog('', sitelinkelem);
                        }

                        $('#minicart-pickup-branch-slots').html(response.data.resp);

                        $('#minicart-pickup-branch-slots .ocws-day-cards-slider').owlCarousel({
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

                        $('#minicart-pickup-branch-slots .ocws-dates-only-list-slider').owlCarousel({
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

                        fetchingSlots = false;
                        // todo: define different handlers for on slot click event on checkout page and on the minicart
                    }
                });

            } else {
                $(this).addClass('invalid');
            }
        });

        $(document).on('submit', '#cart-delivery-settings-form' , function(e) {
            e.stopPropagation();
            e.preventDefault();

            if (fetchingSlots) return;

            var form = $(this);

            var delivery_option = $(this).find('input[id^="oc_woo_advanced_shipping_method"]');
            var pickup_option = $(this).find('input[id^="oc_woo_local_pickup_method"]');

            if ($(delivery_option).is(':checked')) {

                var city_option_element = $(this).find('select[name="selected-city"] option:selected');
                var city_option = city_option_element.val();

                if(city_option == '') {
                    $('#choose-shipping').find('select[name="selected-city"]').addClass('invalid');
                    $('#minicart-shipping-form-messages').html('<span class="error">נא לבחור כתובת למשלוח</span>');
                    return;
                }

                $(this).find('input[name="selected-city-name"]').val(city_option_element.text());

                var formData = $(form).serialize();

                $.ajax({
                    method: "POST",
                    url: ocws.ajaxurl,
                    data: {action: "ocws_deli_save_delivery_settings_data_from_cart_form", formData: formData},
                    beforeSend: function() {
                        $('#minicart-shipping-form-messages').html('<span class="loader">'+deli_public.localize.loading+'...</span>');
                    },
                    success: function(response) {
                        console.log(response);
                        $('#minicart-shipping-form-messages').html('');
                        if (typeof wc_cart_fragments_params !== 'undefined') {
                            sessionStorage.removeItem( wc_cart_fragments_params.fragment_name );
                        }
                        location.reload();
                        //$('.choose-shipping-popup').removeClass('shown');
                        //jQuery('body').css({ overflow: 'auto' });
                    }
                });
            }
            else if ($(pickup_option).is(':checked')) {

                var aff_option_element = $(this).find('select[name="ocws_lp_pickup_aff_id"] option:selected');
                var aff_option = $(this).find('select[name="ocws_lp_pickup_aff_id"] option:selected').val();

                if(aff_option == '') {
                    $('#choose-shipping').find('select[name="aff_option"]').addClass('invalid');
                    $('#minicart-pickup-form-messages').html('<span class="error">נא לבחור סניף לאיסוף</span>');
                    return;
                }

                $(this).find('input[name="selected-branch-name"]').val(aff_option_element.text());

                var formData = $(form).serialize();

                $.ajax({
                    method: "POST",
                    url: ocws.ajaxurl,
                    data: {action: "ocws_deli_save_delivery_settings_data_from_cart_form", formData: formData},
                    beforeSend: function() {
                        $('#minicart-pickup-form-messages').html('<span class="loader">'+deli_public.localize.loading+'...</span>');
                    },
                    success: function(response) {
                        console.log(response);
                        $('#minicart-pickup-form-messages').html('');
                        if (typeof wc_cart_fragments_params !== 'undefined') {
                            sessionStorage.removeItem( wc_cart_fragments_params.fragment_name );
                        }
                        location.reload();
                        //$('.choose-shipping-popup').removeClass('shown');
                        //jQuery('body').css({ overflow: 'auto' });
                    }
                });
            }
            else if(!$("input[name='minicart-shipping-method']:checked").val()){
                $('#minicart-form-messages').html('<span class="error">נא לבחור שיטת משלוח</span>');
            }
        });

        function displayCartSidebar() {
            $('.site-header-minicart .mini-cart-icon').addClass('active');
            $('#cart-panel').attr( 'aria-hidden', false );
            $('.page-overlay').addClass('is-visible');
        }

        function hideCartSidebar() {
            $('#cart-panel').attr( 'aria-hidden', true );
            $('.site-header-minicart .mini-cart-icon').removeClass('active');
            $('.page-overlay').removeClass('is-visible');
        }

        $( document.body ).on( 'added_to_cart', function( fragments, cart_hash, $thisbutton ){
            displayCartSidebar();
        });

        $( document.body ).on( 'deli_cart_fragment_refresh', function() {
            refresh_cart_fragment();
        });

        $( document.body ).on( 'wc_add_to_cart_error', function( event ){
            console.log(event);
            //displayCartSidebar();
            if (typeof wc_cart_fragments_params !== 'undefined') {
                sessionStorage.removeItem( wc_cart_fragments_params.fragment_name );
            }
            $( document.body ).trigger( 'deli_cart_fragment_refresh' );
        });

        $( document.body ).on( 'deli_cart_fragments_refreshed', function(e) {
            e.stopPropagation();
            displayCartSidebar();
        } );

        $ ( document.body ).on( 'click', 'a.change-delivery-method', function () {
            backToMethodChoiseDialog();
        });

        function backToMethodChoiseDialog() {
            $('.delivery-settings-screen-1-2').removeClass('cds-hidden');
            $('.minicart-shipping-options').addClass('cds-hidden');
            $('.minicart-pickup-options').addClass('cds-hidden');
            $('.minicart-shipping-city-slots').addClass('cds-hidden');
            $('.minicart-pickup-branch-slots').addClass('cds-hidden');
            hide_change_method();
            show_back_to_cart();
            hide_submit_button();
            show_submit_form();
            if ($('form.checkout').length) {
                displayCartSidebar();
            }
        }

        function onDeliverySettingsChangeRequest() {
            /* if the method is already chosen, continue from that point */
            var chosenMethodElem = $('.minicart-shipping-methods .cart-shipping-method-wraper.selected');
            var chosenMethod = '';
            if (chosenMethodElem.length) {
                var input = chosenMethodElem.find('input[name=minicart-shipping-method]');
                if (input.length) {
                    if (input.prop('id').indexOf('pickup') != -1) {
                        chosenMethod = 'pickup';
                    }
                    else {
                        chosenMethod = 'shipping';
                    }
                }
            }
            if (chosenMethod == 'shipping') {
                $('.delivery-settings-screen-1-2').addClass('cds-hidden');
                $('.minicart-shipping-options').removeClass('cds-hidden');
                $('.minicart-pickup-options').addClass('cds-hidden');
                $('.minicart-shipping-city-slots').removeClass('cds-hidden');
                $('.minicart-pickup-branch-slots').addClass('cds-hidden');
                show_change_method();
                hide_back_to_cart();
            }
            else if (chosenMethod == 'pickup') {
                $('.delivery-settings-screen-1-2').addClass('cds-hidden');
                $('.minicart-shipping-options').addClass('cds-hidden');
                $('.minicart-pickup-options').removeClass('cds-hidden');
                $('.minicart-shipping-city-slots').addClass('cds-hidden');
                $('.minicart-pickup-branch-slots').removeClass('cds-hidden');
                show_change_method();
                hide_back_to_cart();
            }
            else {
                $('.delivery-settings-screen-1-2').removeClass('cds-hidden');
                $('.minicart-shipping-options').addClass('cds-hidden');
                $('.minicart-pickup-options').addClass('cds-hidden');
                $('.minicart-shipping-city-slots').addClass('cds-hidden');
                $('.minicart-pickup-branch-slots').addClass('cds-hidden');
                hide_change_method();
                show_back_to_cart();
            }
            hide_submit_button();
            show_submit_form();
            if ($('form.checkout').length) {
                displayCartSidebar();
            }
        }

        $ ( document.body ).on( 'click', '.cart-delivery-settings .cds-mini-close', function () {
            if ($('.cds-button-change.empty-cart').length) {
                hideCartSidebar();
            }
            else {
                hide_submit_form();
            }
        });

        var $fragment_refresh = {
            url: deli_public.woo_wc_ajax_url.toString().replace( '%%endpoint%%', 'get_refreshed_fragments' )+'&rel=deli',
            type: 'POST',
            data: {
                time: new Date().getTime()
            },
            //timeout: 500,
            success: function( data ) {
                if ( data && data.fragments ) {

                    $.each( data.fragments, function( key, value ) {
                        $( key ).replaceWith( value );
                    });

                    $( document.body ).trigger( 'deli_cart_fragments_refreshed' );
                }
            },
            error: function() {
                $( document.body ).trigger( 'deli_cart_fragments_ajax_error' );
            }
        };

        /* Named callback for refreshing cart fragment */
        function refresh_cart_fragment() {
            $.ajax( $fragment_refresh );
        }

        $('#billing_google_autocomplete_deli').on('input', function() {
            if($(this).val() != '') {
                $('#oc-woo-shipping-additional .slot-message').addClass('cds-hidden');
            } else {
                $('#oc-woo-shipping-additional .slot-message').removeClass('cds-hidden');
            }
        });

        $( document.body ).on('click', '.ocws-redirect-button', function () {
            if ($(this).data('href')) {

                ocwsAutoGenerateCookie();
                ocwsSaveSiteCookie();

                window.location.replace($(this).data('href'));
            }
        });

        $( document ).on( "ajaxComplete", function( event, xhr, settings ) {
            if ( 'undefined' !== typeof settings.data) {
                if (settings.data.indexOf('minicart_product_set_quantity') != -1) {
                    refresh_cart_fragment();
                }
            }
        } );

    });

}) (jQuery, ocws, deli_public);

 function deliDisplayCartWidget(action, productId) {
     console.log(action + ', ' + productId);
     if (typeof wc_cart_fragments_params !== 'undefined') {
         sessionStorage.removeItem( wc_cart_fragments_params.fragment_name );
     }
     jQuery( document.body ).trigger( 'deli_cart_fragment_refresh' );
 }