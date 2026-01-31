<?php
/**
 * Hook Optimizer
 *
 * Removes or replaces heavy WooCommerce hooks that degrade performance.
 * Disables unnecessary features on high-traffic pages.
 */

defined( 'ABSPATH' ) || exit;

class WCET_Hook_Optimizer {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_loaded', [ $this, 'optimize_hooks' ], 999 );
        add_action( 'wp_enqueue_scripts', [ $this, 'optimize_assets' ], 999 );
        add_action( 'init', [ $this, 'disable_unnecessary_features' ], 20 );
        add_filter( 'option_woocommerce_enable_ajax_add_to_cart', [ $this, 'conditional_ajax_cart' ] );
    }

    /**
     * Remove expensive hooks that are unnecessary for frontend performance.
     */
    public function optimize_hooks() {
        if ( is_admin() ) {
            return;
        }

        // Remove password strength meter from non-account pages.
        if ( ! is_account_page() ) {
            wp_dequeue_script( 'wc-password-strength-meter' );
            wp_deregister_script( 'wc-password-strength-meter' );
        }

        // Disable WooCommerce widgets on non-shop pages.
        if ( ! is_woocommerce() && ! is_cart() && ! is_checkout() ) {
            remove_action( 'wp_head', [ 'WC_Frontend_Scripts', 'localize_printed_scripts' ], 5 );
        }

        // Remove cart fragments on pages that don't need them.
        if ( ! $this->page_needs_cart() ) {
            wp_dequeue_script( 'wc-cart-fragments' );
        }

        // Remove unnecessary meta tags.
        remove_action( 'wp_head', 'wc_generator_tag' );

        // Reduce header bloat.
        remove_action( 'wp_head', 'wp_generator' );
        remove_action( 'wp_head', 'wlwmanifest_link' );
        remove_action( 'wp_head', 'rsd_link' );
    }

    /**
     * Remove WooCommerce assets from pages where they're not needed.
     */
    public function optimize_assets() {
        if ( is_woocommerce() || is_cart() || is_checkout() || is_account_page() ) {
            return;
        }

        // Dequeue WooCommerce styles on non-WC pages.
        $styles_to_remove = [
            'woocommerce-general',
            'woocommerce-layout',
            'woocommerce-smallscreen',
            'wc-blocks-style',
        ];

        foreach ( $styles_to_remove as $handle ) {
            wp_dequeue_style( $handle );
        }

        // Dequeue WooCommerce scripts on non-WC pages.
        $scripts_to_remove = [
            'wc-add-to-cart',
            'wc-cart-fragments',
            'woocommerce',
            'wc-add-to-cart-variation',
            'wc-single-product',
        ];

        foreach ( $scripts_to_remove as $handle ) {
            wp_dequeue_script( $handle );
        }
    }

    /**
     * Disable WooCommerce features that are unnecessary for high-performance stores.
     */
    public function disable_unnecessary_features() {
        // Disable WooCommerce marketing hub.
        add_filter( 'woocommerce_admin_features', function ( array $features ) {
            return array_values( array_diff( $features, [
                'marketing',
                'remote-inbox-notifications',
                'remote-free-extensions',
            ] ) );
        } );

        // Disable Action Scheduler's excessive admin runner.
        if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
            remove_action( 'action_scheduler_run_queue', [ ActionScheduler::runner(), 'run' ] );
        }

        // Disable WooCommerce status dashboard widget.
        add_action( 'wp_dashboard_setup', function () {
            remove_meta_box( 'woocommerce_dashboard_status', 'dashboard', 'normal' );
            remove_meta_box( 'woocommerce_dashboard_recent_reviews', 'dashboard', 'normal' );
        }, 40 );

        // Disable background image regeneration on the frontend.
        if ( ! is_admin() ) {
            add_filter( 'woocommerce_background_image_regeneration', '__return_false' );
        }

        // Reduce transient bloat.
        add_filter( 'woocommerce_delete_version_transients_limit', function() { return 100; } );
    }

    /**
     * Only enable AJAX add-to-cart on archive pages.
     */
    public function conditional_ajax_cart( $value ) {
        if ( is_product() ) {
            return 'no';
        }
        return $value;
    }

    /**
     * Determine if the current page needs cart functionality.
     */
    private function page_needs_cart() {
        return is_woocommerce() || is_cart() || is_checkout() || is_account_page()
            || apply_filters( 'wcet_page_needs_cart', false );
    }

    /**
     * Get optimization report for the admin dashboard.
     */
    public function get_optimization_report() {
        return [
            'hooks_removed'    => $this->count_removed_hooks(),
            'scripts_dequeued' => $this->count_dequeued_scripts(),
            'features_disabled' => [
                'marketing_hub' => true,
                'admin_runner'  => true,
                'image_regen'   => true,
                'dashboard_widgets' => true,
            ],
        ];
    }

    private function count_removed_hooks() {
        // Approximate count of hooks removed by this optimizer.
        return 8;
    }

    private function count_dequeued_scripts() {
        return 9;
    }
}

WCET_Hook_Optimizer::instance();
