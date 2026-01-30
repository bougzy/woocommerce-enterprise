/**
 * WC Enterprise Toolkit — AJAX Product Filters
 */
(function ($) {
    'use strict';

    if (typeof wcetFilters === 'undefined') return;

    var $container = $('.wcet-product-filters');
    if (!$container.length) return;

    var nonce = $container.data('nonce');

    function collectFilters() {
        var filters = {};

        var priceMin = $container.find('[name="price_min"]').val();
        var priceMax = $container.find('[name="price_max"]').val();
        if (priceMin) filters.price_min = priceMin;
        if (priceMax) filters.price_max = priceMax;

        var cats = [];
        $container.find('[name="categories[]"]:checked').each(function () { cats.push($(this).val()); });
        if (cats.length) filters.categories = cats;

        var attrs = {};
        $container.find('[name^="attributes["]').filter(':checked').each(function () {
            var name = $(this).attr('name').match(/attributes\[(.+)\]/);
            if (name && name[1]) {
                var tax = name[1].replace('[]', '');
                if (!attrs[tax]) attrs[tax] = [];
                attrs[tax].push($(this).val());
            }
        });
        if (Object.keys(attrs).length) filters.attributes = attrs;

        var rating = $container.find('[name="min_rating"]').val();
        if (rating) filters.min_rating = rating;

        var stock = $container.find('[name="stock_status"]:checked').val();
        if (stock) filters.stock_status = stock;

        var sort = $('.woocommerce-ordering select').val();
        if (sort) filters.sort = sort;

        return filters;
    }

    function applyFilters(page) {
        var filters = collectFilters();
        var $products = $('ul.products');

        $products.css('opacity', '0.5');

        $.post(wcetFilters.ajaxUrl, {
            action: 'wcet_filter_products',
            nonce: nonce,
            filters: filters,
            page: page || 1
        }, function (response) {
            if (response.success && response.data) {
                renderProducts(response.data.products);
                renderPagination(response.data);
            }
            $products.css('opacity', '1');
        }).fail(function () {
            $products.css('opacity', '1');
        });
    }

    function renderProducts(products) {
        var $grid = $('ul.products');
        $grid.empty();

        if (!products.length) {
            $grid.html('<li class="no-products" style="grid-column:1/-1;text-align:center;padding:3rem;">No products found matching your criteria.</li>');
            return;
        }

        products.forEach(function (p) {
            var sale = p.on_sale ? '<span class="onsale">Sale!</span>' : '';
            var html = '<li class="product">' +
                sale +
                '<a href="' + p.permalink + '">' +
                '<img src="' + (p.image || '') + '" alt="' + p.name + '" loading="lazy" decoding="async">' +
                '<h2 class="woocommerce-loop-product__title">' + p.name + '</h2>' +
                '</a>' +
                '<span class="price">' + p.price_html + '</span>' +
                '<a href="' + p.permalink + '" class="button">View Product</a>' +
                '</li>';
            $grid.append(html);
        });
    }

    function renderPagination(data) {
        var $pag = $('.woocommerce-pagination');
        if (!$pag.length) {
            $pag = $('<nav class="woocommerce-pagination"></nav>');
            $('ul.products').after($pag);
        }

        if (data.pages <= 1) {
            $pag.empty();
            return;
        }

        var links = '';
        for (var i = 1; i <= data.pages; i++) {
            var active = i === data.current_page ? ' style="font-weight:bold;color:#2563eb;"' : '';
            links += '<a href="#" class="page-numbers wcet-page" data-page="' + i + '"' + active + '>' + i + '</a> ';
        }
        $pag.html(links);
    }

    // Event handlers.
    $container.on('click', '.wcet-apply-filters', function () { applyFilters(1); });
    $container.on('click', '.wcet-clear-filters', function () {
        $container.find('input, select').val('').prop('checked', false);
        applyFilters(1);
    });
    $(document).on('click', '.wcet-page', function (e) {
        e.preventDefault();
        applyFilters($(this).data('page'));
    });

})(jQuery);
