( function( $, ocws ) {
    $( function() {

        var cityIdInput;
        var cityInput;
        var cityNameInput;
        var addressInput;
        var houseNumInput;
        var addressCoordsInput;
        var cityNameAutocompleteInput;
        var autocomplete;

        var cityIdInputPopup;
        var cityInputPopup;
        var cityNameInputPopup;
        var addressInputPopup;
        var houseNumInputPopup;
        var cityNameAutocompleteInputPopup;
        var addressCoordsInputPopup;
        var autocompletePopup;

        var cityIdInputChooseShippingPopup;
        var cityInputChooseShippingPopup;
        var cityNameInputChooseShippingPopup;
        var addressInputChooseShippingPopup;
        var houseNumInputChooseShippingPopup;
        var cityNameAutocompleteInputChooseShippingPopup;
        var addressCoordsInputChooseShippingPopup;
        var autocompleteChooseShippingPopup;
        /** מונע יצירת Autocomplete כפול על אותו input — כל instance משאיר .pac-container ב-body */
        var chooseShippingAutocompleteBoundInput = null;

        var accountCityIdInput;
        var accountCityInput;
        var accountCityNameInput;
        var accountAddressInput;
        var accountHouseNumInput;
        var accountAddressCoordsInput;
        var accountCityNameAutocompleteInput;
        var accountAutocomplete;

        var isCheckout = !!($('form.checkout').length);
        var isCheckoutDeliStyle = $(document.body).hasClass('ocws-deli-style-checkout');
        var isAccount = !!($('.woocommerce-MyAccount-content').length);
        var usingChooseShippingPopup = !!($('#choose-shipping').length);

        const geocoder = new google.maps.Geocoder();

        /** PHP (polygon) parses billing_address_coords as lat,lng after stripping spaces/parens */
        function ocwsFormatLatLngForInput(loc) {
            if (!loc) {
                return '';
            }
            var lat = typeof loc.lat === 'function' ? loc.lat() : loc.lat;
            var lng = typeof loc.lng === 'function' ? loc.lng() : loc.lng;
            if (lat === undefined || lng === undefined) {
                return '';
            }
            return lat + ',' + lng;
        }

        function ocwsParsePlaceAddressComponents( place, options ) {
            options = options || {};
            var street = '';
            var city = '';
            var house = '';
            if ( ! place || ! place.address_components || ! place.address_components.length ) {
                return { street: street, city: city, house: house };
            }
            var components = place.address_components;
            var i, c, types, j, comp, tt, typeName, t, k;
            for ( i = 0; i < components.length; i++ ) {
                c = components[ i ];
                types = c.types || [];
                if ( types.indexOf( 'street_number' ) !== -1 ) {
                    house = c.long_name;
                } else if ( types.indexOf( 'route' ) !== -1 ) {
                    street = c.long_name;
                }
            }
            function valueForTypes( typeNames ) {
                for ( t = 0; t < typeNames.length; t++ ) {
                    typeName = typeNames[ t ];
                    for ( j = 0; j < components.length; j++ ) {
                        comp = components[ j ];
                        tt = comp.types || [];
                        if ( tt.indexOf( typeName ) !== -1 ) {
                            return comp.long_name;
                        }
                    }
                }
                return '';
            }
            city = valueForTypes( [ 'locality', 'postal_town', 'administrative_area_level_2' ] );
            if ( ! city ) {
                city = valueForTypes( [ 'sublocality_level_1', 'sublocality' ] );
            }
            if ( city && house && ! street ) {
                street = city;
            }
            if ( ! house || house === '' ) {
                var placeName = ( place.name || '' );
                k = placeName.match( /\d+/ );
                if ( k ) {
                    house = k[ 0 ];
                }
            }
            if ( ( ! house || house === '' ) && options.fallbackInputString ) {
                var fn = ( place.name || '' );
                if ( fn ) {
                    try {
                        var re = new RegExp( fn.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ) + '\\s([0-9]+)', 'i' );
                        var matches = re.exec( options.fallbackInputString );
                        if ( matches && matches.length > 1 ) {
                            house = matches[ 1 ];
                        }
                    } catch ( e1 ) { /* empty */ }
                }
            }
            return { street: street, city: city, house: house };
        }

        function ocwsSetCityPlaceIdForGmCities( city, $cityIdInput ) {
            if ( ! city || ! $cityIdInput || ! $cityIdInput.length ) {
                return Promise.resolve();
            }
            return geocoder
                .geocode( { address: city, componentRestrictions: { country: 'IL' } } )
                .then( function( res ) {
                    var results = ( res && res.results ) ? res.results : [];
                    if ( results.length && results[ 0 ] && results[ 0 ].place_id ) {
                        $cityIdInput.val( results[ 0 ].place_id );
                    }
                } );
        }

        $(document.body).on('shipping_popup_loaded', function() {
            var usingChooseShippingPopup = !!($('#choose-shipping').length);
            if (usingChooseShippingPopup) {
                ocwsInitChooseShippingPopupAutocomplete();
            }
        });

        function ocwsInitChooseShippingPopupAutocomplete() {

            cityNameAutocompleteInputChooseShippingPopup = $('#choose-shipping input[name="billing_google_autocomplete"]');
            cityNameInputChooseShippingPopup = $('#choose-shipping input[name="billing_city_name"]');
            cityInputChooseShippingPopup = $('#choose-shipping input[name="billing_city"]');
            cityIdInputChooseShippingPopup = $('#choose-shipping input[name="billing_city_code"]');
            addressInputChooseShippingPopup = $('#choose-shipping input[name="billing_street"]');
            houseNumInputChooseShippingPopup = $('#choose-shipping input[name="billing_house_num"]');
            addressCoordsInputChooseShippingPopup = $('#choose-shipping input[name="billing_address_coords"]');

            if (!cityNameAutocompleteInputChooseShippingPopup.length) {
                return;
            }

            var chooseShippingInputEl = cityNameAutocompleteInputChooseShippingPopup[0];
            if ( autocompleteChooseShippingPopup && chooseShippingAutocompleteBoundInput === chooseShippingInputEl ) {
                ocwsSyncChooseShippingPopupAddressDisplay();
                return;
            }

            $("#choose-shipping").off("keypress.ocwsacsp keyup.ocwsacsp").on("keypress.ocwsacsp keyup.ocwsacsp", function (event) {
                if (!cityNameAutocompleteInputChooseShippingPopup || !cityNameAutocompleteInputChooseShippingPopup.length) {
                    return;
                }
                cityNameAutocompleteInputChooseShippingPopup.next('span.error').text('');
                cityNameAutocompleteInputChooseShippingPopup.next('span.error').hide();
                var keyPressed = event.keyCode || event.which;
                if (keyPressed === 13) {
                    event.preventDefault();
                    return false;
                }
            });

            if (autocompleteChooseShippingPopup && typeof google !== 'undefined' && google.maps && google.maps.event) {
                google.maps.event.clearInstanceListeners(autocompleteChooseShippingPopup);
                autocompleteChooseShippingPopup = null;
            }

            var data = cityNameAutocompleteInputChooseShippingPopup.data('chooseFirstOnEnter');
            if (!data) {
                selectFirstOnEnter(cityNameAutocompleteInputChooseShippingPopup[0]);
                cityNameAutocompleteInputChooseShippingPopup.data('chooseFirstOnEnter', true);
                var errspan = cityNameAutocompleteInputChooseShippingPopup.parent().find('span.error');
                if (errspan.length == 0) {
                    cityNameAutocompleteInputChooseShippingPopup.after('<span class="error"></span>');
                }
            }

            try {
                autocompleteChooseShippingPopup = new google.maps.places.Autocomplete(cityNameAutocompleteInputChooseShippingPopup[0], {
                    componentRestrictions: { country: ["il"] },
                    fields: ["address_components", "geometry", "place_id", "name", "formatted_address"],
                    types: ["address"]
                });
            } catch ( err ) {
                if ( window.console && console.error ) {
                    console.error( '[OCWS Places] יצירת Autocomplete נכשלה:', err );
                }
                return;
            }
            chooseShippingAutocompleteBoundInput = chooseShippingInputEl;
            // When the user selects an address from the drop-down, populate the
            // address fields in the form.
            autocompleteChooseShippingPopup.addListener("place_changed", ocwsFillInAddressChooseShippingPopup);

            ocwsSyncChooseShippingPopupAddressDisplay();

            ocwsLogPlacesInitDebug( 'Autocomplete widget נוצר והאזין ל-place_changed', cityNameAutocompleteInputChooseShippingPopup );
            ocwsAttachPlacesSuggestionDebugLogging( cityNameAutocompleteInputChooseShippingPopup );
            ocwsRunPlacesAutocompleteServiceSanityCheck();
        }

        /**
         * לוג מצב שדה + הורה (z-index / overflow) — עוזר לזהות pac מוסתר מתחת לפופאפ SMS.
         */
        function ocwsLogPlacesInitDebug( label, $input ) {
            if ( typeof window === 'undefined' || window.ocwsDebugPlacesAutocomplete === false ) {
                return;
            }
            if ( !$input || !$input.length ) {
                if ( window.console && console.warn ) {
                    console.warn( '[OCWS Places]', label, '— אין שדה' );
                }
                return;
            }
            var el = $input[0];
            var r = el.getBoundingClientRect();
            var cs = window.getComputedStyle( el );
            var chain = [];
            var p = el;
            for ( var d = 0; d < 8 && p; d++ ) {
                if ( p.nodeType !== 1 ) {
                    break;
                }
                var pcs = window.getComputedStyle( p );
                chain.push( {
                    tag: p.tagName + ( p.id ? '#' + p.id : '' ) + ( p.className && typeof p.className === 'string' ? '.' + p.className.split( ' ' ).slice( 0, 2 ).join( '.' ) : '' ),
                    z: pcs.zIndex,
                    ov: pcs.overflow,
                    dis: pcs.display
                } );
                p = p.parentElement;
            }
            if ( window.console && console.log ) {
                console.log( '[OCWS Places] ' + label, {
                    rect: { w: Math.round( r.width ), h: Math.round( r.height ), top: Math.round( r.top ), left: Math.round( r.left ) },
                    display: cs.display,
                    visibility: cs.visibility,
                    opacity: cs.opacity,
                    parentChain: chain
                } );
            }
        }

        /**
         * בדיקה שאינה תלויה ב-widget: אם כאן 0 תוצאות / REQUEST_DENIED — הבעיה במפתח/חיוב/API, לא ב־CSS.
         */
        function ocwsRunPlacesAutocompleteServiceSanityCheck() {
            if ( typeof window === 'undefined' || window.ocwsDebugPlacesAutocomplete === false ) {
                return;
            }
            if ( typeof google === 'undefined' || !google.maps || !google.maps.places ) {
                if ( window.console && console.warn ) {
                    console.warn( '[OCWS Places] AutocompleteService sanity — אין google.maps.places' );
                }
                return;
            }
            try {
                var svc = new google.maps.places.AutocompleteService();
                svc.getPlacePredictions(
                    { input: 'תל אביב אלנבי', componentRestrictions: { country: 'il' } },
                    function( predictions, status ) {
                        if ( !window.console || !console.log ) {
                            return;
                        }
                        var n = predictions ? predictions.length : 0;
                        var first = n && predictions[0] ? predictions[0].description : '';
                        console.log( '[OCWS Places] AutocompleteService (בדיקת API בלי widget): status=', status, '| תוצאות=', n, first ? '| ראשון: ' + first : '' );
                        if ( status !== 'OK' && status !== 'ZERO_RESULTS' ) {
                            console.warn( '[OCWS Places] סטטוס לא תקין — בדוק מפתח Maps, Places API, חיוב, הגבלות דומיין' );
                        }
                    }
                );
            } catch ( e ) {
                if ( window.console && console.warn ) {
                    console.warn( '[OCWS Places] AutocompleteService נכשל:', e );
                }
            }
        }

        /**
         * לוג לקונסול כשמופיעות הצעות Places (בודק אם .pac-container מתמלא — אולי רק מוסתר מתחת לשכבות).
         * כבה: window.ocwsDebugPlacesAutocomplete = false
         */
        function ocwsAttachPlacesSuggestionDebugLogging( $input ) {
            if ( typeof window === 'undefined' || window.ocwsDebugPlacesAutocomplete === false ) {
                return;
            }
            if ( !$input || !$input.length ) {
                return;
            }
            var el = $input[0];
            var pacLogTimer = null;
            function logAllPac( reason ) {
                if ( !window.console || !console.log ) {
                    return;
                }
                var pacs = document.querySelectorAll( '.pac-container' );
                console.log( '[OCWS Places] ' + reason + ' — מספר .pac-container בדף:', pacs.length );
                pacs.forEach( function( pac, idx ) {
                    var items = pac.querySelectorAll( '.pac-item' );
                    var pcs = window.getComputedStyle( pac );
                    var pr = pac.getBoundingClientRect();
                    console.log( '[OCWS Places] pac[' + idx + '] items=' + items.length, 'z=' + pcs.zIndex, 'opacity=' + pcs.opacity, 'vis=' + pcs.visibility, 'display=' + pcs.display, 'rect=', { w: Math.round( pr.width ), h: Math.round( pr.height ), t: Math.round( pr.top ), l: Math.round( pr.left ) } );
                } );
            }
            $input.off( 'input.ocwsplacesdbg' ).on( 'input.ocwsplacesdbg', function() {
                var val = $( this ).val();
                if ( window.console && console.log ) {
                    console.log( '[OCWS Places] הקלדה בשדה כתובת:', val );
                }
                clearTimeout( pacLogTimer );
                pacLogTimer = setTimeout( function() {
                    logAllPac( '300ms אחרי הקלדה (בדוק אם pac נוצר עם 0 פריטים)' );
                }, 350 );
            } );
            if ( window.__ocwsPacObserver ) {
                try {
                    window.__ocwsPacObserver.disconnect();
                } catch ( e ) {}
            }
            var __ocwsPacMutLast = 0;
            window.__ocwsPacObserver = new MutationObserver( function() {
                var now = Date.now();
                if ( now - __ocwsPacMutLast < 450 ) {
                    return;
                }
                __ocwsPacMutLast = now;
                var pacs = document.querySelectorAll( '.pac-container' );
                if ( !pacs.length ) {
                    return;
                }
                pacs.forEach( function( pac ) {
                    var items = pac.querySelectorAll( '.pac-item' );
                    var vis = pac.offsetParent !== null && $( pac ).is( ':visible' );
                    if ( window.console && console.log ) {
                        console.log( '[OCWS Places] שינוי ב-DOM ליד pac — פריטים:', items.length, '| pac visible:', vis, items.length ? '| ראשון: ' + items[0].textContent.trim() : '(אין .pac-item — ה-widget לא הציע או חסום)' );
                    }
                } );
            } );
            window.__ocwsPacObserver.observe( document.body, { childList: true, subtree: true, attributes: true, attributeFilter: [ 'style', 'class' ] } );
        }

        function ocwsSyncChooseShippingPopupAddressDisplay() {
            var $form = $('#choose-shipping');
            if (!$form.length) {
                return;
            }
            var $inp = $form.find('input[name="billing_google_autocomplete"]');
            if (!$inp.length) {
                return;
            }
            var street = ($form.find('input[name="billing_street"]').val() || '').trim();
            var house = ($form.find('input[name="billing_house_num"]').val() || '').trim();
            var city = ($form.find('input[name="billing_city_name"]').val() || '').trim();
            var coords = ($form.find('input[name="billing_address_coords"]').val() || '').trim();
            if (!coords || !city) {
                return;
            }
            var line = [street, house].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();
            if (!line) {
                return;
            }
            var display = line + ', ' + city;
            if (!$inp.val() || String($inp.val()).trim() === '') {
                $inp.val(display);
            }
        }

        function ocwsFillInAddressChooseShippingPopup() {
            const billingAddressPlaceChooseShippingPopup = autocompleteChooseShippingPopup.getPlace();

            console.log("Place Selected:", billingAddressPlaceChooseShippingPopup);

            if (!billingAddressPlaceChooseShippingPopup.hasOwnProperty('address_components')) return;

            var parts = ocwsParsePlaceAddressComponents( billingAddressPlaceChooseShippingPopup, { fallbackInputString: ( cityNameAutocompleteInputChooseShippingPopup.val() || '' ) } );
            var street = parts.street;
            var city = parts.city;
            var house = parts.house;

            var noHouseMsg = (typeof ocws !== 'undefined' && ocws.localize && ocws.localize.messages && ocws.localize.messages.noHouseNumberInAddress)
                ? ocws.localize.messages.noHouseNumberInAddress
                : 'נא להזין כתובת מלאה הכוללת רחוב ומספר בית.';

            function ocwsShowChooseShippingAutocompleteError(msg) {
                var $in = cityNameAutocompleteInputChooseShippingPopup;
                if (!$in || !$in.length) {
                    return;
                }
                var $err = $in.next('span.error');
                if (!$err.length) {
                    $in.after('<span class="error"></span>');
                    $err = $in.next('span.error');
                }
                $err.text(msg).show();
            }

            // כמו ב־ocwsFillInAddressPopup: חובה עיר + רחוב + מספר בית (לא מספיק רחוב בלי מספר)
            if (city === '' || street === '' || house === '') {
                ocwsShowChooseShippingAutocompleteError(noHouseMsg);
                return;
            }

            // הסרת הודעת השגיאה במידה והכל תקין
            (function () {
                var $in = cityNameAutocompleteInputChooseShippingPopup;
                if ($in && $in.length) {
                    var $err = $in.next('span.error');
                    if ($err.length) {
                        $err.text('').hide();
                    }
                }
            })();

            var displayAddr = billingAddressPlaceChooseShippingPopup.formatted_address || '';
            if (!displayAddr) {
                var lineParts = [street, house].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();
                if (lineParts && city) {
                    displayAddr = lineParts + ', ' + city;
                }
            }
            if (displayAddr) {
                cityNameAutocompleteInputChooseShippingPopup.val(displayAddr);
            }

            if (city) {
                $('#choose-shipping input[type="submit"]').prop('disabled', true);

                // יצירת כתובת מלאה ל-Geocoding לדיוק מירבי
                const fullAddress = street + " " + house + ", " + city + ", Israel";

                geocoder
                    .geocode({ address: fullAddress })
                    .then(function (geoRes) {
                        var results = ( geoRes && geoRes.results ) ? geoRes.results : [];
                        if (results.length && results[0]) {
                            addressInputChooseShippingPopup.val(street);
                            cityNameInputChooseShippingPopup.val(city);
                            cityInputChooseShippingPopup.val(city);
                            houseNumInputChooseShippingPopup.val(house);

                            if (billingAddressPlaceChooseShippingPopup.geometry && billingAddressPlaceChooseShippingPopup.geometry.location) {
                                addressCoordsInputChooseShippingPopup.val(ocwsFormatLatLngForInput(billingAddressPlaceChooseShippingPopup.geometry.location));
                            }
                        }
                        return ocwsSetCityPlaceIdForGmCities( city, cityIdInputChooseShippingPopup );
                    })
                    .then(function () {
                        if (addressCoordsInputChooseShippingPopup && addressCoordsInputChooseShippingPopup.length && String( addressCoordsInputChooseShippingPopup.val() || '' ).trim() !== '') {
                            addressCoordsInputChooseShippingPopup.trigger('change');
                        }
                        if (typeof window.ocwsRefreshPopupContinueState === 'function') {
                            window.ocwsRefreshPopupContinueState();
                        }
                    })
                    .catch((e) => {
                        console.log("Geocode error: " + e);
                        if (typeof window.ocwsRefreshPopupContinueState === 'function') {
                            window.ocwsRefreshPopupContinueState();
                        }
                    });
            }
        }

        function ocwsInitPopupCheckoutAutocomplete() {

            var $popupForm = $('#ocws-checkout-choose-city-form');
            if (!$popupForm.length) {
                return;
            }

            $popupForm.off('keypress.ocwsPopAc keyup.ocwsPopAc').on('keypress.ocwsPopAc keyup.ocwsPopAc', function (event) {
                var keyPressed = event.keyCode || event.which;
                if (keyPressed === 13) {
                    event.preventDefault();
                    return false;
                }
            });

            cityNameAutocompleteInputPopup = $popupForm.find('input[name="billing_google_autocomplete"]');
            cityNameInputPopup = $popupForm.find('input[name="billing_city_name"]');
            cityInputPopup = $popupForm.find('input[name="billing_city"]');
            cityIdInputPopup = $popupForm.find('input[name="billing_city_code"]');
            addressInputPopup = $popupForm.find('input[name="billing_street"]');
            houseNumInputPopup = $popupForm.find('input[name="billing_house_num"]');
            addressCoordsInputPopup = $popupForm.find('input[name="billing_address_coords"]');

            var popupAcEl = cityNameAutocompleteInputPopup.length ? cityNameAutocompleteInputPopup[0] : null;
            if (!popupAcEl || popupAcEl.nodeName !== 'INPUT') {
                return;
            }
            if (cityNameAutocompleteInputPopup.data('ocwsPlacesAutocompleteInit')) {
                return;
            }

            var data = cityNameAutocompleteInputPopup.data('chooseFirstOnEnter');
            if (!data) {
                selectFirstOnEnter(popupAcEl);
                cityNameAutocompleteInputPopup.data('chooseFirstOnEnter', true);
                var errspan = cityNameAutocompleteInputPopup.parent().find('span.error');
                if (errspan.length == 0) {
                    cityNameAutocompleteInputPopup.after('<span class="error"></span>');
                }
            }

            autocompletePopup = new google.maps.places.Autocomplete(popupAcEl, {
                componentRestrictions: { country: ["il"] },
                fields: ["address_components", "geometry", "place_id", "name", "formatted_address"],
                types: ["address"]
            });
            // When the user selects an address from the drop-down, populate the
            // address fields in the form.
            autocompletePopup.addListener("place_changed", ocwsFillInAddressPopup);
            cityNameAutocompleteInputPopup.data('ocwsPlacesAutocompleteInit', true);
        }

        function ocwsFillInAddressPopup() {

            const billingAddressPlacePopup = autocompletePopup.getPlace();
            console.log(billingAddressPlacePopup);

            if (!billingAddressPlacePopup.hasOwnProperty('address_components')) return;

            var partsP = ocwsParsePlaceAddressComponents( billingAddressPlacePopup, { fallbackInputString: ( cityNameAutocompleteInputPopup.val() || '' ) } );
            var street = partsP.street;
            var city = partsP.city;
            var house = partsP.house;

            if (city == '' || street == '' || house == '') {
                cityNameAutocompleteInputPopup.parent().find('span.error').text('');
                cityNameAutocompleteInputPopup.next('span.error').text(ocws.localize.messages.noHouseNumberInAddress);
                cityNameAutocompleteInputPopup.next('span.error').show();
                return;
            }
            cityNameAutocompleteInputPopup.parent().find('span.error').text('');
            cityNameAutocompleteInputPopup.next('span.error').hide();

            if (city) {

                geocoder
                    .geocode({ address: city, componentRestrictions: { country: 'IL' } })
                    .then(({results}) => {
                        console.log(results);
                        if (results.length && results[0] && results[0].place_id) {
                            addressInputPopup.val(street);
                            cityNameInputPopup.val(city);
                            cityInputPopup.val(city);
                            cityIdInputPopup.val(results[0].place_id);
                            houseNumInputPopup.val(house);

                            if (billingAddressPlacePopup.geometry && billingAddressPlacePopup.geometry.location) {

                                addressCoordsInputPopup.val(ocwsFormatLatLngForInput(billingAddressPlacePopup.geometry.location));
                                addressCoordsInputPopup.trigger('change');
                            }
                        }
                    })
                    .catch((e) =>
                        console.log("Geocode was not successful for the following reason: " + e)
                );
            }
        }

        function ocwsInitCheckoutAutocomplete() {

            cityNameAutocompleteInput = $('form.checkout input[name="billing_google_autocomplete"]');
            cityNameInput = $('form.checkout input[name="billing_city_name"]');
            cityInput = $('form.checkout input[name="billing_city"]');
            cityIdInput = $('form.checkout input[name="billing_city_code"]');
            addressInput = $('form.checkout input[name="billing_street"]');
            houseNumInput = $('form.checkout input[name="billing_house_num"]');
            addressCoordsInput = $('form.checkout input[name="billing_address_coords"]');

            var checkoutAcEl = cityNameAutocompleteInput.length ? cityNameAutocompleteInput[0] : null;
            if (!checkoutAcEl || checkoutAcEl.nodeName !== 'INPUT') {
                return;
            }
            if (cityNameAutocompleteInput.data('ocwsPlacesAutocompleteInit')) {
                return;
            }

            $("form.checkout").off('keypress.ocwsChkAc keyup.ocwsChkAc').on('keypress.ocwsChkAc keyup.ocwsChkAc', function (event) {
                $('#billing_google_autocomplete_field').parent().find('span.error').text('');
                $('#billing_google_autocomplete_field').parent().find('span.error').hide();
                var keyPressed = event.keyCode || event.which;
                if (keyPressed === 13) {
                    event.preventDefault();
                    return false;
                }
            });

            var data = cityNameAutocompleteInput.data('chooseFirstOnEnter');
            if (!data) {
                selectFirstOnEnter(checkoutAcEl);
                cityNameAutocompleteInput.data('chooseFirstOnEnter', true);
                var errspan = cityNameAutocompleteInput.parent('.woocommerce-input-wrapper').parent().find('span.error');
                if (errspan.length == 0) {
                    cityNameAutocompleteInput.parent('.woocommerce-input-wrapper').after('<span class="error"></span>');
                }
            }

            autocomplete = new google.maps.places.Autocomplete(checkoutAcEl, {
                componentRestrictions: { country: ["il"] },
                fields: ["address_components", "geometry", "place_id", "name"],
                types: ["address"]
            });
            // When the user selects an address from the drop-down, populate the
            // address fields in the form.
            autocomplete.addListener("place_changed", ocwsFillInAddress);
            cityNameAutocompleteInput.data('ocwsPlacesAutocompleteInit', true);
        }

        function ocwsFillInAddress() {
            const billingAddressPlace = autocomplete.getPlace();
            if (!billingAddressPlace.hasOwnProperty('address_components')) return;

            var partsChk = ocwsParsePlaceAddressComponents( billingAddressPlace, { fallbackInputString: ( cityNameAutocompleteInput.val() || '' ) } );
            var street = partsChk.street;
            var city = partsChk.city;
            var house = partsChk.house;

            // כמו בפופאב המשלוח: חובה עיר + רחוב + מספר בית (רחוב בלי מספר — לא מספיק)
            var noHouseMsgCheckout = (typeof ocws !== 'undefined' && ocws.localize && ocws.localize.messages && ocws.localize.messages.noHouseNumberInAddress)
                ? ocws.localize.messages.noHouseNumberInAddress
                : 'נא להזין כתובת מלאה הכוללת רחוב ומספר בית.';
            if (city === '' || street === '' || house === '') {
                console.log('Missing critical data:', {city, street, house});
                const errorSpan = cityNameAutocompleteInput.parent('.woocommerce-input-wrapper').next('span.error');
                errorSpan.text(noHouseMsgCheckout).show();
                return;
            }

            // ניקוי שגיאות
            cityNameAutocompleteInput.parent('.woocommerce-input-wrapper').next('span.error').hide();

            // ביצוע Geocoding עם הנתונים שאספנו
            if (city) {
                const fullAddress = `${street} ${house}, ${city}, Israel`;

                geocoder
                    .geocode({ address: fullAddress })
                    .then(function (geoRes) {
                        var results = ( geoRes && geoRes.results ) ? geoRes.results : [];
                        if (results.length && results[0]) {
                            // עדכון השדות בטופס
                            addressInput.val(street);
                            cityNameInput.val(city);
                            cityInput.val(city);
                            houseNumInput.val(house);

                            if (billingAddressPlace.geometry && billingAddressPlace.geometry.location) {
                                addressCoordsInput.val(ocwsFormatLatLngForInput(billingAddressPlace.geometry.location));
                            }
                        }
                        return ocwsSetCityPlaceIdForGmCities( city, cityIdInput );
                    })
                    .then(function () {
                        if (addressCoordsInput && addressCoordsInput.length && String( addressCoordsInput.val() || '' ).trim() !== '') {
                            addressCoordsInput.trigger('change');
                        }
                    })
                    .catch((e) => console.error("Geocode error:", e));
            }
        }
        function ocwsInitAccountAutocomplete(type) {

            if (type !== 'billing' && type !== 'shipping') {
                return;
            }
            $(".woocommerce-MyAccount-content form").on("keypress keyup", function (event) {
                var keyPressed = event.keyCode || event.which;
                if (keyPressed === 13) {
                    event.preventDefault();
                    return false;
                }
            });

            accountCityNameAutocompleteInput = $('.woocommerce-MyAccount-content form input[name="'+type+'_google_autocomplete"]');
            accountCityNameInput = $('.woocommerce-MyAccount-content form input[name="'+type+'_city_name"]');
            accountCityInput = $('.woocommerce-MyAccount-content form input[name="'+type+'_city"]');
            accountCityIdInput = $('.woocommerce-MyAccount-content form input[name="'+type+'_city_code"]');
            accountAddressInput = $('.woocommerce-MyAccount-content form input[name="'+type+'_street"]');
            accountHouseNumInput = $('.woocommerce-MyAccount-content form input[name="'+type+'_house_num"]');
            accountAddressCoordsInput = $('.woocommerce-MyAccount-content form input[name="'+type+'_address_coords"]');

            var data = accountCityNameAutocompleteInput.data('chooseFirstOnEnter');
            if (!data) {
                selectFirstOnEnter(accountCityNameAutocompleteInput[0]);
                accountCityNameAutocompleteInput.data('chooseFirstOnEnter', true);
                var errspan = accountCityNameAutocompleteInput.parent('.woocommerce-input-wrapper').parent().find('span.error');
                if (errspan.length == 0) {
                    accountCityNameAutocompleteInput.parent('.woocommerce-input-wrapper').after('<span class="error"></span>');
                }
            }

            autocomplete = new google.maps.places.Autocomplete(accountCityNameAutocompleteInput[0], {
                componentRestrictions: { country: ["il"] },
                fields: ["address_components", "geometry", "place_id", "name"],
                types: ["address"]
            });
            // When the user selects an address from the drop-down, populate the
            // address fields in the form.
            autocomplete.addListener("place_changed", ocwsFillInAccountAddress);
        }

        function ocwsFillInAccountAddress() {

            const addressPlace = autocomplete.getPlace();
            console.log(addressPlace);

            if (!addressPlace.hasOwnProperty('address_components')) return;

            var partsAcc = ocwsParsePlaceAddressComponents( addressPlace, { fallbackInputString: ( accountCityNameAutocompleteInput.val() || '' ) } );
            var street = partsAcc.street;
            var city = partsAcc.city;
            var house = partsAcc.house;

            if (city == '' || street == '' || house == '') {
                accountCityNameAutocompleteInput.parent('.woocommerce-input-wrapper').parent().find('span.error').text('');
                accountCityNameAutocompleteInput.parent('.woocommerce-input-wrapper').next('span.error').text(ocws.localize.messages.noHouseNumberInAddress);
                accountCityNameAutocompleteInput.parent('.woocommerce-input-wrapper').next('span.error').show();
                return;
            }
            accountCityNameAutocompleteInput.parent('.woocommerce-input-wrapper').parent().find('span.error').text('');
            accountCityNameAutocompleteInput.parent('.woocommerce-input-wrapper').next('span.error').hide();

            if (city) {

                geocoder
                    .geocode({ address: city, componentRestrictions: { country: 'IL' } })
                    .then(({results}) => {
                        console.log(results);
                        if (results.length && results[0] && results[0].place_id) {
                            accountAddressInput.val(street);
                            accountCityNameInput.val(city);
                            accountCityInput.val(city);
                            accountCityIdInput.val(results[0].place_id);
                            accountHouseNumInput.val(house);

                            if (addressPlace.geometry && addressPlace.geometry.location) {

                                accountAddressCoordsInput.val(ocwsFormatLatLngForInput(addressPlace.geometry.location));
                                accountAddressCoordsInput.trigger('change');
                            }
                        }
                    })
                    .catch((e) =>
                        console.log("Geocode was not successful for the following reason: " + e)
                );
            }
        }

        if (isCheckout && !isCheckoutDeliStyle) {
            var autocompleteInputContainer = $('#billing_google_autocomplete_field');
            if (autocompleteInputContainer.length && autocompleteInputContainer.hasClass('ocws-hidden-form-field')) {
                ocwsInitPopupCheckoutAutocomplete();
            }
            else {
                ocwsInitCheckoutAutocomplete();
            }
        } else if (isAccount) {
            ocwsInitAccountAutocomplete(($('.woocommerce-MyAccount-content form input[name="shipping_city"]').length? 'shipping' : 'billing'));
        } else if (usingChooseShippingPopup) {
            ocwsInitChooseShippingPopupAutocomplete();
        }



        $( document.body).on( 'updated_checkout', function () {
            var autocompleteInputContainer = $('#billing_google_autocomplete_field');
            if (autocompleteInputContainer.length && autocompleteInputContainer.hasClass('ocws-hidden-form-field')) {
                ocwsInitPopupCheckoutAutocomplete();
            }
            else {
                ocwsInitCheckoutAutocomplete();
            }
        } );
 
        /* Checkout: choose-shipping exists but init runs only for non-checkout above — theme (SMS embed) calls this when visible. */
        if (typeof window !== 'undefined') {
            window.ocwsInitChooseShippingPopupAutocomplete = ocwsInitChooseShippingPopupAutocomplete;
        }

        function ocwsGeocodeAddress(address) {

            var coords = false;
            geocoder
                .geocode({ address: address })
                .then(({ results }) => {
                    coords = results[0].geometry.location;
                })
                .catch((e) =>
                    console.log("Geocode was not successful for the following reason: " + e)
                );
            return coords;
        }

        function selectFirstOnEnter(input) {  // store the original event binding function
            if (!input) {
                return;
            }
            var _addEventListener = (input.addEventListener) ? input.addEventListener : input.attachEvent;
            function addEventListenerWrapper(type, listener) {  // Simulate a 'down arrow' keypress on hitting 'return' when no pac suggestion is selected, and then trigger the original listener.
                if (type == "keydown") {
                    var orig_listener = listener;
                    listener = function(event) {
                        var suggestion_selected = $(".pac-item-selected").length > 0;
                        if (event.which == 13 && !suggestion_selected) {
                            var simulated_downarrow = $.Event("keydown", {keyCode: 40, which: 40});
                            orig_listener.apply(input, [simulated_downarrow]);
                        }
                        orig_listener.apply(input, [event]);
                    };
                }
                _addEventListener.apply(input, [type, listener]); // add the modified listener
            }
            if (input.addEventListener) {
                input.addEventListener = addEventListenerWrapper;
            } else if (input.attachEvent) {
                input.attachEvent = addEventListenerWrapper;
            }
        }
    });
})( jQuery, ocws );

