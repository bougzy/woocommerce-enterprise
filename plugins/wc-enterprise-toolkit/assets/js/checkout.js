/**
 * WC Enterprise Toolkit — Multi-step Checkout
 */
(function ($) {
    'use strict';

    if (typeof wcetCheckout === 'undefined') return;

    var currentStep = 0;
    var steps = $('.wcet-step');
    var sections = [
        '.woocommerce-billing-fields',
        '.woocommerce-shipping-fields',
        '#payment',
        '.woocommerce-checkout-review-order'
    ];

    function showStep(index) {
        sections.forEach(function (sel, i) {
            $(sel).toggle(i === index);
        });
        steps.removeClass('active');
        steps.eq(index).addClass('active');
        currentStep = index;

        // Update navigation buttons.
        updateNav();
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function updateNav() {
        var $nav = $('.wcet-checkout-nav');
        if (!$nav.length) {
            $nav = $('<div class="wcet-checkout-nav" style="display:flex;justify-content:space-between;margin-top:1.5rem;"></div>');
            $nav.insertAfter('#order_review');
        }

        var prevBtn = currentStep > 0
            ? '<button type="button" class="button wcet-prev">' + wcetCheckout.i18n.previous + '</button>'
            : '<span></span>';
        var nextBtn = currentStep < steps.length - 1
            ? '<button type="button" class="button alt wcet-next">' + wcetCheckout.i18n.next + '</button>'
            : '';

        $nav.html(prevBtn + nextBtn);

        // On last step, show the place order button.
        $('#place_order').toggle(currentStep === steps.length - 1);
    }

    // Only initialize multi-step if steps exist.
    if (steps.length > 1) {
        showStep(0);

        $(document).on('click', '.wcet-next', function () {
            if (currentStep < steps.length - 1) {
                showStep(currentStep + 1);
            }
        });

        $(document).on('click', '.wcet-prev', function () {
            if (currentStep > 0) {
                showStep(currentStep - 1);
            }
        });
    }

    // Track checkout duration for analytics.
    var checkoutStart = Date.now();
    $('form.checkout').on('submit', function () {
        var duration = Math.round((Date.now() - checkoutStart) / 1000);
        $('<input>').attr({ type: 'hidden', name: 'checkout_duration', value: duration }).appendTo(this);
    });

})(jQuery);
