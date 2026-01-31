<?php
/**
 * WC Performance Theme — Functions
 *
 * Minimal, performance-first theme for WooCommerce.
 * Eliminates unnecessary bloat and optimizes asset loading.
 */

defined( 'ABSPATH' ) || exit;

define( 'WCPT_VERSION', '1.0.0' );
define( 'WCPT_DIR', get_template_directory() );
define( 'WCPT_URI', get_template_directory_uri() );

// --- Theme Setup ---
add_action( 'after_setup_theme', function () {
    add_theme_support( 'woocommerce' );
    add_theme_support( 'wc-product-gallery-zoom' );
    add_theme_support( 'wc-product-gallery-lightbox' );
    add_theme_support( 'wc-product-gallery-slider' );
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'html5', [ 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption' ] );
    add_theme_support( 'responsive-embeds' );

    register_nav_menus( [
        'primary'   => __( 'Primary Navigation', 'wc-performance' ),
        'footer'    => __( 'Footer Navigation', 'wc-performance' ),
    ] );

    // Optimized image sizes.
    add_image_size( 'product-card', 400, 400, true );
    add_image_size( 'product-hero', 800, 800, false );
} );

// --- Performance: Asset Loading ---
add_action( 'wp_enqueue_scripts', function () {
    // Theme stylesheet — lightweight.
    wp_enqueue_style( 'wcpt-style', get_stylesheet_uri(), [], WCPT_VERSION );

    // Minimal JS — no jQuery dependency on frontend.
    wp_enqueue_script( 'wcpt-main', WCPT_URI . '/assets/js/main.js', [], WCPT_VERSION, true );

    // Remove block library CSS on the frontend (saves ~30KB).
    wp_dequeue_style( 'wp-block-library' );
    wp_dequeue_style( 'wp-block-library-theme' );
    wp_dequeue_style( 'wc-blocks-style' );
    wp_dequeue_style( 'global-styles' );

    // Remove emoji scripts.
    remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
    remove_action( 'wp_print_styles', 'print_emoji_styles' );
}, 20 );

// --- Performance: Clean Head ---
add_action( 'init', function () {
    remove_action( 'wp_head', 'wp_generator' );
    remove_action( 'wp_head', 'wlwmanifest_link' );
    remove_action( 'wp_head', 'rsd_link' );
    remove_action( 'wp_head', 'wp_shortlink_wp_head' );
    remove_action( 'wp_head', 'rest_output_link_wp_head' );
    remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
    remove_action( 'wp_head', 'feed_links_extra', 3 );
} );

// --- Performance: Preload Critical Assets ---
add_action( 'wp_head', function () {
    // Preload the main stylesheet.
    echo '<link rel="preload" href="' . esc_url( get_stylesheet_uri() ) . '" as="style">' . "\n";

    // DNS prefetch for external resources.
    $prefetch_domains = [ 'fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr.net' ];
    foreach ( $prefetch_domains as $domain ) {
        echo '<link rel="dns-prefetch" href="//' . esc_attr( $domain ) . '">' . "\n";
    }
}, 1 );

// --- Performance: Lazy Load Images ---
add_filter( 'wp_get_attachment_image_attributes', function ( array $attr ) {
    if ( ! is_admin() ) {
        $attr['loading'] = 'lazy';
        $attr['decoding'] = 'async';
    }
    return $attr;
} );

// --- Performance: Disable Heartbeat on frontend ---
add_action( 'init', function () {
    if ( ! is_admin() ) {
        wp_deregister_script( 'heartbeat' );
    }
} );

// --- WooCommerce Overrides ---

// Reduce products per page (pagination is faster than loading 100+).
add_filter( 'loop_shop_per_page', fn() => 24 );

// Optimize thumbnail sizes.
add_filter( 'woocommerce_get_image_size_thumbnail', fn() => [
    'width'  => 400,
    'height' => 400,
    'crop'   => 1,
] );

// Remove default WooCommerce sidebar.
remove_action( 'woocommerce_sidebar', 'woocommerce_get_sidebar', 10 );

// --- Widget Areas ---
add_action( 'widgets_init', function () {
    register_sidebar( [
        'name'          => __( 'Shop Sidebar', 'wc-performance' ),
        'id'            => 'shop-sidebar',
        'before_widget' => '<div class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="widget-title">',
        'after_title'   => '</h3>',
    ] );

    register_sidebar( [
        'name'          => __( 'Footer Widgets', 'wc-performance' ),
        'id'            => 'footer-widgets',
        'before_widget' => '<div class="widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="widget-title">',
        'after_title'   => '</h3>',
    ] );
} );

// --- Include Theme Modules ---
require_once WCPT_DIR . '/inc/template-hooks.php';
require_once WCPT_DIR . '/inc/performance.php';
