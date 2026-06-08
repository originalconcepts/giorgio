
var ocwsCookie = {
	method: '',
	city: '',
	branch: '',
	polygon: {
		coords: '',
		street: '',
		house_num: '',
		city_name: '',
		city_code: ''
	}
};

function resetSiteCookie() {
	ocwsCookie = {
		method: '',
		city: '',
		branch: '',
		polygon: {
			coords: '',
			street: '',
			house_num: '',
			city_name: '',
			city_code: ''
		}
	};
}

function readSiteCookie(name) {
	var nameEQ = name + "=";
	var ca = document.cookie.split(';');
	for(var i=0;i < ca.length;i++) {
		var c = ca[i];
		while (c.charAt(0)==' ') c = c.substring(1,c.length);
		if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length,c.length);
	}
	return null;
}

function addSiteCookie(name, data) {
	var now = new Date();
	var time = now.getTime();
	time += 24 * 3600 * 1000;
	now.setTime(time);
	var value = (typeof data === 'string' || data instanceof String)? data : JSON.stringify(data);
	document.cookie =
		name + '=' + value +
		'; expires=' + now.toUTCString() +
		'; path=/';
}

function addMethodToSiteCookie(method) {
	ocwsCookie.method = method;
}
function addCityToSiteCookie(city) {
	ocwsCookie.city = city;
}
function addBranchToSiteCookie(branchId) {
	ocwsCookie.branch = branchId;
}
function addPolygonToSiteCookie(coords, street, house_num, city_name, city_code) {
	ocwsCookie.polygon.coords = coords;
	ocwsCookie.polygon.street = street;
	ocwsCookie.polygon.house_num = house_num;
	ocwsCookie.polygon.city_name = city_name;
	ocwsCookie.polygon.city_code = city_code;
}

function ocwsSaveSiteCookie() {
	if (ocwsCookie.method == '') {
		ocwsCookie.method = ocwsGetShippingMethod();
	}
	addSiteCookie('ocws', ocwsCookie);
}

function ocwsGetShippingMethod() {
	var checkoutForm = jQuery('form.checkout');
	if (checkoutForm.length) {
		var delivery_option = checkoutForm.find('input[value^="oc_woo_advanced_shipping_method"]');
		var pickup_option = checkoutForm.find('input[value^="oc_woo_local_pickup_method"]');
		return (jQuery(delivery_option).is(':checked')? 'oc_woo_advanced_shipping_method' : (jQuery(pickup_option).is(':checked')? 'oc_woo_local_pickup_method' : ''));
	}
	var chooseShippingForm = jQuery('form#choose-shipping');
	if (chooseShippingForm.length) {
		var delivery_option = chooseShippingForm.find('input[id^="oc_woo_advanced_shipping_method"]');
		var pickup_option = chooseShippingForm.find('input[id^="oc_woo_local_pickup_method"]');
		return (jQuery(delivery_option).is(':checked')? 'oc_woo_advanced_shipping_method' : (jQuery(pickup_option).is(':checked')? 'oc_woo_local_pickup_method' : ''));
	}
	var minicartShippingForm = jQuery('form#cart-delivery-settings-form');
	if (minicartShippingForm.length) {
		var delivery_option = minicartShippingForm.find('input[value^="oc_woo_advanced_shipping_method"]');
		var pickup_option = minicartShippingForm.find('input[value^="oc_woo_local_pickup_method"]');
		return (jQuery(delivery_option).is(':checked')? 'oc_woo_advanced_shipping_method' : (jQuery(pickup_option).is(':checked')? 'oc_woo_local_pickup_method' : ''));
	}
	return '';
}

function ocwsAutoGenerateCookie() {
	resetSiteCookie();
	var checkoutForm = jQuery('form.checkout');
	if (checkoutForm.length) {
		var delivery_option = checkoutForm.find('input[value^="oc_woo_advanced_shipping_method"]');
		var pickup_option = checkoutForm.find('input[value^="oc_woo_local_pickup_method"]');
		if (jQuery(delivery_option).is(':checked')) {
			addMethodToSiteCookie('oc_woo_advanced_shipping_method');
			addCityToSiteCookie(checkoutForm.find('select[name="billing-city"] option:selected').val());
		}
		else if (jQuery(pickup_option).is(':checked')) {
			addMethodToSiteCookie('oc_woo_local_pickup_method');
			addBranchToSiteCookie(checkoutForm.find('select[name="ocws_lp_pickup_aff_id"] option:selected').val());
		}
		ocwsGeneratePolygonCookie(checkoutForm);
		return;
	}
	var minicartShippingForm = jQuery('form#cart-delivery-settings-form');
	if (minicartShippingForm.length) {
		var delivery_option = minicartShippingForm.find('input[value^="oc_woo_advanced_shipping_method"]');
		var pickup_option = minicartShippingForm.find('input[value^="oc_woo_local_pickup_method"]');
		if (jQuery(delivery_option).is(':checked')) {
			addMethodToSiteCookie('oc_woo_advanced_shipping_method');
			addCityToSiteCookie(minicartShippingForm.find('select[name="selected-city"] option:selected').val());
		}
		else if (jQuery(pickup_option).is(':checked')) {
			addMethodToSiteCookie('oc_woo_local_pickup_method');
			addBranchToSiteCookie(minicartShippingForm.find('select[name="ocws_lp_pickup_aff_id"] option:selected').val());
		}
		ocwsGeneratePolygonCookie(minicartShippingForm);
		return;
	}
	var chooseShippingForm = jQuery('form#choose-shipping');
	if (chooseShippingForm.length) {
		var delivery_option = chooseShippingForm.find('input[id^="oc_woo_advanced_shipping_method"]');
		var pickup_option = chooseShippingForm.find('input[id^="oc_woo_local_pickup_method"]');
		if (jQuery(delivery_option).is(':checked')) {
			addMethodToSiteCookie('oc_woo_advanced_shipping_method');
			addCityToSiteCookie(chooseShippingForm.find('select[name="selected-city"] option:selected').val());
		}
		else if (jQuery(pickup_option).is(':checked')) {
			addMethodToSiteCookie('oc_woo_local_pickup_method');
			addBranchToSiteCookie(chooseShippingForm.find('select[name="ocws_lp_pickup_aff_id"] option:selected').val());
		}
		ocwsGeneratePolygonCookie(chooseShippingForm);
		return;
	}
}

function ocwsGeneratePolygonCookie(form) {
	var coordsElem = form.find('input[name="billing_address_coords"]');
	var coords = '',
		street = '',
		house_num = '',
		city_name = '',
		city_code = '';
	if (coordsElem.length) {
		coords = coordsElem.val();
		street = form.find('input[name="billing_street"]').val();
		house_num = form.find('input[name="billing_house_num"]').val();
		city_name = form.find('input[name="billing_city_name"]').val();
		city_code = form.find('input[name="billing_city_code"]').val();
	}
	addPolygonToSiteCookie(coords, street, house_num, city_name, city_code);
}
