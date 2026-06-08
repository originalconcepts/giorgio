var billingGoogleAutocomplete = false;
var autocompleteInput = false;

( function( $, ocws ) {
    $( function() {
        var cityIdInputChooseShippingPopup;
        var cityInputChooseShippingPopup;
        var cityNameInputChooseShippingPopup;
        var addressInputChooseShippingPopup;
        var houseNumInputChooseShippingPopup;
        var cityNameAutocompleteInputChooseShippingPopup;
        var addressCoordsInputChooseShippingPopup;
        var autocompleteChooseShippingPopup;

        var isCheckout = !!($('form.checkout').length);
        var isCheckoutDeliStyle = $(document.body).hasClass('ocws-deli-style-checkout');
        var isAccount = !!($('.woocommerce-MyAccount-content').length);
        var usingChooseShippingPopup = !!($('#choose-shipping').length);

        const geocoder = new google.maps.Geocoder();

        function ocwsInitChooseShippingPopupAutocomplete() {

            $("#cart-delivery-settings-form").on("keypress keyup", function (event) {
                cityNameAutocompleteInputChooseShippingPopup.next('span.error').text('');
                cityNameAutocompleteInputChooseShippingPopup.next('span.error').hide();
                var keyPressed = event.keyCode || event.which;
                if (keyPressed === 13) {
                    event.preventDefault();
                    return false;
                }
            });

            autocompleteInput = cityNameAutocompleteInputChooseShippingPopup = $('#cart-delivery-settings-form input[name="billing_google_autocomplete"]');
            cityNameInputChooseShippingPopup = $('#cart-delivery-settings-form input[name="billing_city_name"]');
            cityInputChooseShippingPopup = $('#cart-delivery-settings-form input[name="billing_city"]');
            cityIdInputChooseShippingPopup = $('#cart-delivery-settings-form input[name="billing_city_code"]');
            addressInputChooseShippingPopup = $('#cart-delivery-settings-form input[name="billing_street"]');
            houseNumInputChooseShippingPopup = $('#cart-delivery-settings-form input[name="billing_house_num"]');
            addressCoordsInputChooseShippingPopup = $('#cart-delivery-settings-form input[name="billing_address_coords"]');

            var data = cityNameAutocompleteInputChooseShippingPopup.data('chooseFirstOnEnter');
            if (!data) {
                selectFirstOnEnter(cityNameAutocompleteInputChooseShippingPopup[0]);
                cityNameAutocompleteInputChooseShippingPopup.data('chooseFirstOnEnter', true);
                cityNameAutocompleteInputChooseShippingPopup.after('<span class="error"></span>');
            }

            autocompleteChooseShippingPopup = new google.maps.places.Autocomplete(cityNameAutocompleteInputChooseShippingPopup[0], {
                componentRestrictions: { country: ["il"] },
                fields: ["address_components", "geometry", "place_id", "name"],
                types: ["address"]
            });
            billingGoogleAutocomplete = autocompleteChooseShippingPopup;
            // When the user selects an address from the drop-down, populate the
            // address fields in the form.
            autocompleteChooseShippingPopup.addListener("place_changed", ocwsFillInAddressChooseShippingPopup);
        }

        function ocwsFillInAddressChooseShippingPopup() {
            const place = autocompleteChooseShippingPopup.getPlace();
            let street = "";
            let city = "";
            let house = "";

            console.log(place);

            if (!place.hasOwnProperty("address_components")) return;

            // לולאה שעוברת על כל הרכיבים ובודקת לפי includes
            for (const component of place.address_components) {
                if (component.types.includes("street_number")) {
                    house = component.long_name;
                }
                else if (component.types.includes("route")) {
                    street = component.long_name;
                }
                else if (component.types.includes("locality")) {
                    city = component.long_name;
                }
            }

            // אם אין מספר בית, ננסה לחלץ אותו מה-name
            if (!house || house === "") {
                if (place.hasOwnProperty("name")) {
                    const regexp = new RegExp(place.name + "\\s([0-9]+)", "i");
                    const matches = regexp.exec(cityNameAutocompleteInputChooseShippingPopup.val());
                    if (matches && matches.length > 1) {
                        house = matches[1];
                    }
                }
            }

            // אם עדיין חסר משהו – נציג שגיאה
            if (city === "" || street === "" || house === "") {
                cityNameAutocompleteInputChooseShippingPopup
                    .next("span.error")
                    .text(ocws.localize.messages.noHouseNumberInAddress)
                    .show();
                return;
            }

            cityNameAutocompleteInputChooseShippingPopup.next("span.error").text("").hide();

            if (city) {
                $("#choose-shipping input[type='submit']").prop("disabled", true);

                geocoder
                    .geocode({ address: city, componentRestrictions: { country: "IL" } })
                    .then(({ results }) => {
                        console.log(results);
                        if (results.length && results[0] && results[0].place_id) {
                            addressInputChooseShippingPopup.val(street);
                            cityNameInputChooseShippingPopup.val(city);
                            cityInputChooseShippingPopup.val(city);
                            cityIdInputChooseShippingPopup.val(results[0].place_id);
                            houseNumInputChooseShippingPopup.val(house);

                            if (place.geometry && place.geometry.location) {
                                addressCoordsInputChooseShippingPopup.val(
                                    JSON.stringify({
                                        lat: place.geometry.location.lat(),
                                        lng: place.geometry.location.lng(),
                                    })
                                );
                                addressCoordsInputChooseShippingPopup.trigger("change");
                            }
                        }
                        $("#choose-shipping input[type='submit']").prop("disabled", false);
                    })
                    .catch((e) =>
                        console.log("Geocode was not successful for the following reason: " + e)
                    );
            }
        }

        if ((!isCheckout || (isCheckout && isCheckoutDeliStyle)) && !isAccount && !usingChooseShippingPopup) {
            ocwsInitChooseShippingPopupAutocomplete();
        }

        $(document.body).on("deli_cart_fragments_refreshed", function () {
            if ((!isCheckout || (isCheckout && isCheckoutDeliStyle)) && !isAccount && !usingChooseShippingPopup) {
                ocwsInitChooseShippingPopupAutocomplete();
            }
        });

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

