(function( $ ) {
	'use strict';

	$(document).ready(function() {

		var from = $('input[name="ocws_filter_shipping_date_start"]'),
			to = $('input[name="ocws_filter_shipping_date_end"]'),
			selectFilter = $('select[name="ocws_order_shipping_date_filter"]'),
			method = $('select[name="ocws_order_method"]'),
			from_modal = $('#ocwsbox-export-modal-inside input[name="ocws_filter_shipping_date_start_modal"]'),
			to_modal = $('#ocwsbox-export-modal-inside input[name="ocws_filter_shipping_date_end_modal"]'),
			from_modal2 = $('#ocwsbox-export-sales-modal-inside input[name="ocws_filter_shipping_date_start_modal"]'),
			to_modal2 = $('#ocwsbox-export-sales-modal-inside input[name="ocws_filter_shipping_date_end_modal"]'),
			selectFilter_modal = $('#ocwsbox-export-modal-inside select[name="ocws_order_shipping_date_filter_modal"]'),
			selectFilter_modal2 = $('#ocwsbox-export-sales-modal-inside select[name="ocws_order_shipping_date_filter_modal"]'),
			method_modal1 = $('#ocwsbox-export-modal-inside select[name="ocws_order_method_modal"]'),
			method_modal2 = $('#ocwsbox-export-sales-modal-inside select[name="ocws_order_method_modal"]'),
			order_date_from = $('input[name="ocws_filter_order_date_start"]'),
			order_date_to = $('input[name="ocws_filter_order_date_end"]'),
			order_completed_date_from = $('input[name="ocws_filter_order_cdate_start"]'),
			order_completed_date_to = $('input[name="ocws_filter_order_cdate_end"]'),
			order_date_from_modal = $('input[name="ocws_filter_order_date_start_modal"]'),
			order_date_to_modal = $('input[name="ocws_filter_order_date_end_modal"]'),
			order_completed_date_from_modal = $('input[name="ocws_filter_order_cdate_start_modal"]'),
			order_completed_date_to_modal = $('input[name="ocws_filter_order_cdate_end_modal"]');

		$( '#ocwsbox-export-modal-inside input[name="ocws_filter_shipping_date_start_modal"], #ocwsbox-export-modal-inside input[name="ocws_filter_shipping_date_end_modal"]' ).datepicker( {dateFormat: "dd/mm/yy", altFormat: "yy/mm/dd"} );
		$( '#ocwsbox-export-sales-modal-inside input[name="ocws_filter_shipping_date_start_modal"], #ocwsbox-export-sales-modal-inside input[name="ocws_filter_shipping_date_end_modal"]' ).datepicker( {dateFormat: "dd/mm/yy", altFormat: "yy/mm/dd"} );
		// by default, the dates look like this "April 3, 2017"

		// the rest part of the script prevents from choosing incorrect date interval
		from_modal.on( 'change', function() {
			to_modal.datepicker( 'option', 'minDate', from_modal.val() );
		});

		to_modal.on( 'change', function() {
			from_modal.datepicker( 'option', 'maxDate', to_modal.val() );
		});

		selectFilter_modal.on('change', function () {
			if ($(this).val() == 'from_to') {
				from_modal.parent().show();
				to_modal.parent().show();
			}
			else {
				from_modal.parent().hide();
				to_modal.parent().hide();
			}
		});

		from_modal2.on( 'change', function() {
			to_modal2.datepicker( 'option', 'minDate', from_modal2.val() );
		});

		to_modal2.on( 'change', function() {
			from_modal2.datepicker( 'option', 'maxDate', to_modal2.val() );
		});

		selectFilter_modal2.on('change', function () {
			if ($(this).val() == 'from_to') {
				from_modal2.parent().show();
				to_modal2.parent().show();
			}
			else {
				from_modal2.parent().hide();
				to_modal2.parent().hide();
			}
		});

		$('.ocws_export_button, .ocws_export_for_production_button, .ocws_export_for_packaging_button').on('click', function (event) {

			var $previewButton = $(this);
			var name = $previewButton.attr('name')+'_modal';
			$('#ocwsbox-export-modal-inside .ocwsbox-actions input').hide();
			$('#ocwsbox-export-modal-inside .ocwsbox-actions input[name="'+name+'"]').show();

			from_modal.val(from.val());
			to_modal.val(to.val());
			selectFilter_modal.val(selectFilter.val());
			method_modal1.val(method.val());
			if (selectFilter_modal.val() == 'from_to') {
				from_modal.parent().show();
				to_modal.parent().show();
			}
			else {
				from_modal.parent().hide();
				to_modal.parent().hide();
			}
			tb_click.bind(this)(event);
		});
		
		$('.ocws_export_sales_report_button, .ocws_export_orders_report_button').on('click', function (event) {

			var $previewButton = $(this);
			if ($previewButton.attr('name') == 'ocws_export_sales_report_action') {
				selectFilter_modal2.parent().parent().hide();
			}
			else {
				selectFilter_modal2.parent().parent().show();
			}
			var name = $previewButton.attr('name')+'_modal';
			$('#ocwsbox-export-sales-modal-inside .ocwsbox-actions input').hide();
			$('#ocwsbox-export-sales-modal-inside .ocwsbox-actions input[name="'+name+'"]').show();

			order_date_from_modal.val(order_date_from.val());
			order_date_to_modal.val(order_date_to.val());
			order_completed_date_from_modal.val(order_completed_date_from.val());
			order_completed_date_to_modal.val(order_completed_date_to.val());
			method_modal2.val(method.val());

			from_modal2.val(from.val());
			to_modal2.val(to.val());
			selectFilter_modal2.val(selectFilter.val());

			if (selectFilter_modal2.val() == 'from_to') {
				from_modal2.parent().show();
				to_modal2.parent().show();
			}
			else {
				from_modal2.parent().hide();
				to_modal2.parent().hide();
			}

			tb_click.bind(this)(event);
		});


		$( 'input[name="ocws_filter_shipping_date_start"], input[name="ocws_filter_shipping_date_end"]' ).datepicker( {dateFormat: "dd/mm/yy", altFormat: "yy/mm/dd"} );
		// by default, the dates look like this "April 3, 2017"

		// the rest part of the script prevents from choosing incorrect date interval
		from.on( 'change', function() {
			to.datepicker( 'option', 'minDate', from.val() );
		});

		to.on( 'change', function() {
			from.datepicker( 'option', 'maxDate', to.val() );
		});

		selectFilter.on('change', function () {
			if ($(this).val() == 'from_to') {
				from.parent().show();
				to.parent().show();
			}
			else {
				from.parent().hide();
				to.parent().hide();
			}
		});

		function get_modal_values() {

			var data = {from: '', to: '', type: '', method: ''};
			var method_filter_value = method_modal1.val();
			var method = 'all';
			if (method_filter_value) {
				method_filter_value = method_filter_value.split(':');
				method = method_filter_value[0];
			}

			if (selectFilter_modal.val() == 'from_to') {
				data.from = from_modal.val();
				data.to = to_modal.val();
				if (!data.from || !data.to) {
					return false;
				}
				data.type = 'from_to';
				data.method = method;
			}
			else if (selectFilter_modal.val() == 'today') {
				data.type = 'today';
				data.from = '';
				data.to = '';
				data.method = method;
			}
			else {
				return false;
			}
			return data;
		}

		function get_modal_values2() {

			var data = {from: '', to: '', type: '', method: ''};
			var method_filter_value = method_modal2.val();
			var method = 'all';
			if (method_filter_value) {
				method_filter_value = method_filter_value.split(':');
				method = method_filter_value[0];
			}

			if (selectFilter_modal2.val() == 'from_to') {
				data.from = from_modal2.val();
				data.to = to_modal2.val();
				if (!data.from || !data.to) {
					return false;
				}
				data.type = 'from_to';
				data.method = method;
			}
			else if (selectFilter_modal2.val() == 'today') {
				data.type = 'today';
				data.from = '';
				data.to = '';
				data.method = method;
			}
			else {
				return false;
			}
			return data;
		}

		$('.ocws_export_button_modal').on('click', function () {

			var $self = $(this);
			var $loader = $self.closest('.ocwsbox-actions').find('.loader-container');
			var data = get_modal_values();
			if (false === data) return;

			$self.block();
			$self.hide();
			$loader.show();
			$.post( ajaxurl + ( ajaxurl.indexOf( '?' ) > 0 ? '&' : '?' ) + 'action=oc_woo_shipping_export_orders', {
				from : data.from,
				to: data.to,
				type: data.type,
				method: data.method
			}, function (response, textStatus) {
				$self.unblock();
				$self.show();
				$loader.hide();
				if (response.success) {
					if (response.data && response.data.file) {
						var file_path = response.data.file.u;
						var a = document.createElement('A');
						a.href = file_path;
						a.download = file_path.substr(file_path.lastIndexOf('/') + 1);
						document.body.appendChild(a);
						a.click();
						document.body.removeChild(a);
					}
				}
			}, 'json' );
		});

		$('.ocws_export_for_production_button_modal').on('click', function () {

			var $self = $(this);
			var $loader = $self.closest('.ocwsbox-actions').find('.loader-container');
			var data = get_modal_values();
			if (false === data) return;
			$self.block();
			$self.hide();
			$loader.show();
			$.post( ajaxurl + ( ajaxurl.indexOf( '?' ) > 0 ? '&' : '?' ) + 'action=oc_woo_shipping_export_orders_for_production', {
				from : data.from,
				to: data.to,
				type: data.type,
				method: data.method
			}, function (response, textStatus) {
				$self.unblock();
				$self.show();
				$loader.hide();
				if (response.success) {
					if (response.data && response.data.file) {
						var file_path = response.data.file.u;
						var a = document.createElement('A');
						a.href = file_path;
						a.download = file_path.substr(file_path.lastIndexOf('/') + 1);
						document.body.appendChild(a);
						a.click();
						document.body.removeChild(a);
					}
				}
			}, 'json' );
		});

		$('.ocws_export_for_packaging_button_modal').on('click', function () {

			var $self = $(this);
			var $loader = $self.closest('.ocwsbox-actions').find('.loader-container');
			var data = get_modal_values();
			if (false === data) return;
			$self.block();
			$self.hide();
			$loader.show();
			$.post( ajaxurl + ( ajaxurl.indexOf( '?' ) > 0 ? '&' : '?' ) + 'action=oc_woo_shipping_export_orders_for_packaging', {
				from : data.from,
				to: data.to,
				type: data.type,
				method: data.method
			}, function (response, textStatus) {
				$self.unblock();
				$self.show();
				$loader.hide();
				if (response.success) {
					if (response.data && response.data.file) {
						var file_path = response.data.file.u;
						var a = document.createElement('A');
						a.href = file_path;
						a.download = file_path.substr(file_path.lastIndexOf('/') + 1);
						document.body.appendChild(a);
						a.click();
						document.body.removeChild(a);
					}
				}
			}, 'json' );
		});

		// sales

		$('.ocws_export_sales_report_button_modal, .ocws_export_orders_report_button_modal').on('click', function () {

			var $self = $(this);
			var $loader = $self.closest('.ocwsbox-actions').find('.loader-container');
			var action = ($self.hasClass('ocws_export_sales_report_button_modal')? 'oc_woo_shipping_export_sales' : 'oc_woo_shipping_export_orders_report')

			var data = {
				method: '',
				order_date_from: '',
				order_date_to: '',
				order_completed_date_from: '',
				order_completed_date_to: '',
				delivery_date_from: '',
				delivery_date_to: '',
				delivery_date_type: ''
			};

			if (action == 'oc_woo_shipping_export_orders_report') {
				var deliverytypeopt = selectFilter_modal.find('option:selected');
				if (deliverytypeopt.attr('selected') != null && deliverytypeopt.val() != 'today') {
					if ( (!order_date_from_modal.val() || !order_date_to_modal.val()) && (!from_modal2.val() || !to_modal2.val()) ) {
						alert('Please, choose order creation dates range or order delivery date range.');
						return;
					}
				}
			} else if (!order_date_from_modal.val() || !order_date_to_modal.val()) {
				alert('Please, choose order creation dates range.');
				return;
			}

			var modal_delivery_data = get_modal_values2();
			if (false !== modal_delivery_data) {
				data.delivery_date_from = modal_delivery_data.from;
				data.delivery_date_to = modal_delivery_data.to;
				data.delivery_date_type = modal_delivery_data.type;
			}
			// TODO ???
			var parent = $self.closest('.ocwsbox-modal-inside');
			var method_filter_value = parent.find($('select[name="ocws_order_method_modal"]')).val();
			var method = 'all';
			if (method_filter_value) {
				method_filter_value = method_filter_value.split(':');
				method = method_filter_value[0];
				data.method = method;
			}

			if (order_date_from_modal.val()) {
				data.order_date_from = order_date_from_modal.val();
			}
			if (order_date_to_modal.val()) {
				data.order_date_to = order_date_to_modal.val();
			}
			if (order_completed_date_from_modal.val()) {
				data.order_completed_date_from = order_completed_date_from_modal.val();
			}
			if (order_completed_date_to_modal.val()) {
				data.order_completed_date_to = order_completed_date_to_modal.val();
			}

			$self.block();
			$self.hide();
			$loader.show();
			$.post( ajaxurl + ( ajaxurl.indexOf( '?' ) > 0 ? '&' : '?' ) + 'action=' + action, data, function (response, textStatus) {
				$self.unblock();
				$self.show();
				$loader.hide();
				if (response.success) {
					if (response.data && response.data.file) {
						var file_path = response.data.file.u;
						var a = document.createElement('A');
						a.href = file_path;
						a.download = file_path.substr(file_path.lastIndexOf('/') + 1);
						document.body.appendChild(a);
						a.click();
						document.body.removeChild(a);
					}
				}
			}, 'json' );
		});

	});


})( jQuery );


