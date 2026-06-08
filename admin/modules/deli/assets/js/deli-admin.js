(function( $, wp ) {

    'use strict';

    $(function() {

        let startSelect2OnExcludeCatProducts = function (elem) {
            $(elem).select2({
                theme: "classic",
                selectionCssClass: 'shoplist-product-select',
                ajax: {
                    url: ajax_deli.ajaxurl,
                    dataType: 'json',
                    type: 'POST',
                    quietMillis: 50,
                    data: function (params) {
                        var query = {
                            s: params.term,
                            action: 'ocws_deli_products_ajax_query',
                            cat: $(this).parent().data('cat-id'),
                            nonce: ''
                        };

                        // Query parameters will be ?search=[term]&page=[page]
                        return query;
                    },
                    processResults: function (data) {
                        return {
                            results: $.map(data.data.resp.results, function (item) {
                                return {
                                    text: item.title,
                                    id: item.ID,
                                    type: item.type
                                }
                            })
                        };
                    }
                }
            });
        };

        let getExcludedProductsByCat = function () {
            let excluded = $('#hidden-cat-prods').val().split(';');
            let excluded_list = {};
            $(excluded).each(function (i, catprods) {
                console.log(catprods);
                let res = catprods.split(':', 2);
                if (res.length == 2) {
                    excluded_list[res[0]] = res[1].split(',');
                }
            });
            return excluded_list;
        };

        let removeExcludedProductFromList = function (list, cat, prod) {

            if (list.hasOwnProperty(cat)) {
                let index = list[cat].indexOf(prod+'');
                if (index !== -1) {
                    list[cat].splice(index, 1);
                }
                if (list[cat].length == 0) {
                    delete list[cat];
                }
            }
            return list;
        };

        let assembleExcludedInputValue = function (list) {
            let res = [];
            for (let catId in list) {
                res.push(catId + ':' + list[catId].join(','));
            }
            return res.join(';');
        };

        let removeCategoryFromExcluded = function (cat) {
            let list = getExcludedProductsByCat();
            if (list.hasOwnProperty(cat)) {
                delete list[cat];
            }
            $('#hidden-cat-prods').val(assembleExcludedInputValue(list));
        };

        let addExcludedProductToList = function (list, cat, prod) {
            if (!list.hasOwnProperty(cat)) {
                list[cat] = [];
            }
            let index = list[cat].indexOf(prod);
            if (index === -1) {
                list[cat].push(prod);
            }
            return list;
        };

        let getProductBadgeById = function (prod_id, cat_id) {
            return $('#menu-categories-list label[data-cat-id="'+cat_id+'"] .badge[data-prod-id="'+prod_id+'"]');
        };

        let addCategoryRow = function(cat_id) {
            let template = wp.template('categories-template');
            let option = $('#product_cat option[value="'+cat_id+'"]');
            if (option.length) {
                let elem = $(template( { cat_id: cat_id, cat_name: option.text() } ) );
                $('#menu-categories-list').append( elem );
                startSelect2OnExcludeCatProducts(elem.find('.menu-exclude-product-select2'));
            }
        };

        let getExcludedProductBadge = function(prod_id, prod_name) {
            let template = wp.template('excluded-products-template');
            let elem = $(template( { prod_id: prod_id, prod_name: prod_name } ) );
            return elem;
        };

        $('#availability_type').on('change', function() {
            var type = $(this).find(':selected').val();
            if (type == 'weekdays') {
                $('#term-dates-wrap').hide();
                $('#term-weekdays-wrap').show();
            }
            else {
                $('#term-weekdays-wrap').hide();
                $('#term-dates-wrap').show();
            }
        });

        $('.term-weekdays-wrap input[type=checkbox]').on('change', function() {
            var hidden = $("#weekdays-hidden");
            var values = [];
            $('.term-weekdays-wrap input[type=checkbox]:checked').each(function (index, value) {
                values.push($(value).data('weekday'));
            });
            hidden.val(values.join(','));
        });

        let dp = $("#deli-term-dates-datepicker");
        let dpDates = dp.data('value');
        let dpArgs = {

            dateFormat: 'dd/mm/yy',
            minDate: 0,
            maxPicks: 100,
            onSelect: function() {}
        };

        if (dpDates) {
            dpArgs.addDates = dpDates.split(', ');
        }

        if (dp.length) {
            dp.multiDatesPicker(dpArgs);
        }

        $('#menu-add-cat-btn').on('click', function() {
            let catId = $('#product_cat').find(':selected').val();
            let hiddenInput = $('#hidden-cat-ids');
            if (catId) {
                catId = parseInt(catId);
                let val = hiddenInput.val();
                let catIds = [];
                if (val) {
                    catIds = val.split(',').map(function (num) {
                        return parseInt(num);
                    });
                }
                if (catIds.indexOf(catId) == -1) {
                    catIds.push(parseInt(catId));
                    hiddenInput.val(catIds.join(','));
                    addCategoryRow(catId);
                }
            }
        });

        $( document.body ).on('click', 'a.delete-category-from-menu', function(event) {
            event.preventDefault();
            let catId = $(this).data('cat-id');
            let hiddenInput = $('#hidden-cat-ids');
            if (catId) {
                let val = hiddenInput.val();
                let catIds = [];
                if (val) {
                    catIds = val.split(',').map(function (num) {
                        return parseInt(num);
                    });
                }
                let ind = catIds.indexOf(catId);
                if (ind !== -1) {
                    catIds.splice(ind, 1);
                    hiddenInput.val(catIds.join(','));
                    $(this).parent().remove();
                }
                removeCategoryFromExcluded(catId);
            }
        });

        $( document.body ).on('click', '.exclude-product-cat-button', function () {

            $(this).parent().next('.exclude-prod-div').toggle();
        });

        $( document.body ).on('change', '.menu-exclude-product-select2', function () {

            var value = $(this).val();
            if (value) {
                $(this).parent().find('.menu-ex-prod-btn').prop('disabled', false);
            }
        });

        $('.menu-exclude-product-select2').select2({
            theme: "classic",
            selectionCssClass: 'shoplist-product-select',
            ajax: {
                url: ajax_deli.ajaxurl,
                dataType: 'json',
                type: 'POST',
                quietMillis: 50,
                data: function (params) {
                    var query = {
                        s: params.term,
                        action: 'ocws_deli_products_ajax_query',
                        cat: $(this).parent().data('cat-id'),
                        nonce: ''
                    };

                    // Query parameters will be ?search=[term]&page=[page]
                    return query;
                },
                processResults: function (data) {
                    return {
                        results: $.map(data.data.resp.results, function (item) {
                            return {
                                text: item.title,
                                id: item.ID,
                                type: item.type
                            }
                        })
                    };
                }
            }
        });

        startSelect2OnExcludeCatProducts('.menu-exclude-product-select2');

        $( document.body ).on('click', '.menu-ex-prod-btn', function () {

            let prodSelect = $(this).parent().find('.menu-exclude-product-select2');
            console.log(prodSelect.val());
            console.log(prodSelect.find(':selected').text());
            let cat = $(this).parent().data('cat-id');
            let excluded_products = getExcludedProductsByCat();
            excluded_products = addExcludedProductToList(excluded_products, cat, prodSelect.val());
            $('#hidden-cat-prods').val(assembleExcludedInputValue(excluded_products));

            let badge = getProductBadgeById(prodSelect.val(), cat);
            if (badge.length == 0) {
                let parent = $(this).parent().parent().find('.exclude-product-cat-list');
                parent.append(getExcludedProductBadge(prodSelect.val(), prodSelect.find(':selected').text()));
            }
        });

        $( document.body ).on('click', '.exclude-product-cat-list .badge a', function (event) {
            event.preventDefault();
            let prod_id = $(this).data('prod-id');
            let cat_id = $(this).closest('label.list-group-item').data('cat-id');
            let excluded_products = getExcludedProductsByCat();
            excluded_products = removeExcludedProductFromList(excluded_products, cat_id, prod_id);
            $('#hidden-cat-prods').val(assembleExcludedInputValue(excluded_products));

            $(this).closest('.badge').remove();
        });

    });

}) (jQuery, wp);