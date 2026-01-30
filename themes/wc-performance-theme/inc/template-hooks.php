<?php
/**
 * Template hooks — rearrange and optimize WooCommerce template output.
 */

defined( 'ABSPATH' ) || exit;

// Remove default WooCommerce breadcrumbs (add a cleaner version).
remove_action( 'woocommerce_before_main_content', 'woocommerce_breadcrumb', 20 );
add_action( 'woocommerce_before_main_content', function () {
    if ( is_shop() || is_product_category() || is_product_tag() || is_product() ) {
        woocommerce_breadcrumb( [
            'wrap_before' => '<nav class="woocommerce-breadcrumb" aria-label="Breadcrumb">',
            'wrap_after'  => '</nav>',
            'delimiter'   => ' <span aria-hidden="true">/</span> ',
        ] );
    }
}, 20 );

// Remove default WooCommerce wrappers.
remove_action( 'woocommerce_before_main_content', 'woocommerce_output_content_wrapper', 10 );
remove_action( 'woocommerce_after_main_content', 'woocommerce_output_content_wrapper_end', 10 );

// Add result count and ordering in a flex container.
remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
add_action( 'woocommerce_before_shop_loop', function () {
    echo '<div class="wcpt-shop-controls" style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;">';
    woocommerce_result_count();
    woocommerce_catalog_ordering();
    echo '</div>';
}, 20 );

// Remove related products on single product page (performance optimization).
// Re-add with a lower count.
remove_action( 'woocommerce_after_single_product_summary', 'woocommerce_output_related_products', 20 );
add_action( 'woocommerce_after_single_product_summary', function () {
    woocommerce_related_products( [
        'posts_per_page' => 4,
        'columns'        => 4,
    ] );
}, 20 );

// Optimize checkout field output.
add_filter( 'woocommerce_form_field_args', function ( array $args, string $key ): array {
    // Add autocomplete attributes for faster checkout.
    $autocomplete_map = [
        'billing_first_name' => 'given-name',
        'billing_last_name'  => 'family-name',
        'billing_email'      => 'email',
        'billing_phone'      => 'tel',
        'billing_address_1'  => 'address-line1',
        'billing_address_2'  => 'address-line2',
        'billing_city'       => 'address-level2',
        'billing_postcode'   => 'postal-code',
        'billing_country'    => 'country',
    ];

    if ( isset( $autocomplete_map[ $key ] ) ) {
        $args['autocomplete'] = $autocomplete_map[ $key ];
    }

    return $args;
}, 10, 2 );
