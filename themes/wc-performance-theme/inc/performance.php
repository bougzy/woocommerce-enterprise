<?php
/**
 * Theme-level performance optimizations.
 */

defined( 'ABSPATH' ) || exit;

// --- Preconnect to CDN / external resources ---
add_filter( 'wp_resource_hints', function ( array $urls, string $relation ) {
    if ( 'preconnect' === $relation ) {
        $urls[] = [ 'href' => 'https://fonts.gstatic.com', 'crossorigin' => true ];
    }
    return $urls;
}, 10, 2 );

// --- Disable WP embed script ---
add_action( 'wp_footer', function () {
    wp_deregister_script( 'wp-embed' );
} );

// --- Optimize jQuery loading ---
add_action( 'wp_enqueue_scripts', function () {
    if ( ! is_admin() && ! is_cart() && ! is_checkout() && ! is_account_page() && ! is_woocommerce() ) {
        wp_deregister_script( 'jquery' );
        wp_register_script( 'jquery', '', [], '', true );
    }
}, 100 );

// --- Add defer/async to non-critical scripts ---
add_filter( 'script_loader_tag', function ( string $tag, string $handle ) {
    $defer_handles = [ 'wcpt-main', 'comment-reply' ];
    if ( in_array( $handle, $defer_handles, true ) ) {
        return str_replace( ' src', ' defer src', $tag );
    }
    return $tag;
}, 10, 2 );

// --- Limit post revisions ---
if ( ! defined( 'WP_POST_REVISIONS' ) ) {
    define( 'WP_POST_REVISIONS', 5 );
}

// --- Disable self-pingbacks ---
add_action( 'pre_ping', function ( array &$links ) {
    $home = get_option( 'home' );
    foreach ( $links as $i => $link ) {
        if ( strpos( $link, $home ) === 0 ) {
            unset( $links[ $i ] );
        }
    }
} );

// --- Optimize WooCommerce image loading ---
add_filter( 'woocommerce_product_get_image', function ( string $image ) {
    // Ensure loading="lazy" and decoding="async" on product images.
    if ( strpos( $image, 'loading=' ) === false ) {
        $image = str_replace( '<img', '<img loading="lazy" decoding="async"', $image );
    }
    return $image;
} );

// --- Critical CSS inline (above-the-fold styles) ---
add_action( 'wp_head', function () {
    if ( is_shop() || is_front_page() ) {
        echo '<style id="critical-css">';
        echo 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1e293b;line-height:1.6}';
        echo '.site-header{background:#fff;border-bottom:1px solid #e2e8f0;padding:1rem 0;position:sticky;top:0;z-index:100}';
        echo '.site-container{max-width:1200px;margin:0 auto;padding:0 1rem}';
        echo '.site-header .site-container{display:flex;align-items:center;justify-content:space-between}';
        echo '</style>';
    }
}, 2 );

// --- Reduce DNS lookups with resource hints ---
add_action( 'wp_head', function () {
    if ( is_checkout() ) {
        // Preconnect to payment processor APIs.
        echo '<link rel="preconnect" href="https://api.stripe.com">' . "\n";
        echo '<link rel="preconnect" href="https://js.stripe.com">' . "\n";
    }
}, 3 );
