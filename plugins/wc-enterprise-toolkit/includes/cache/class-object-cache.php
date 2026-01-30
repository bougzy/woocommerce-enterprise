<?php
/**
 * Object Caching Layer
 *
 * Wraps WordPress object cache with WooCommerce-specific cache groups,
 * cache warming, and smart invalidation. Designed for Redis/Memcached backends
 * but degrades gracefully to the default WordPress object cache.
 */

defined( 'ABSPATH' ) || exit;

class WCET_Object_Cache {

    private static ?self $instance = null;

    /** Cache group prefix */
    private const GROUP = 'wcet';

    /** Default TTLs in seconds */
    private const TTL_SHORT  = 300;    // 5 min
    private const TTL_MEDIUM = 1800;   // 30 min
    private const TTL_LONG   = 86400;  // 24 hours

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Invalidation hooks.
        add_action( 'woocommerce_update_product', [ $this, 'invalidate_product' ] );
        add_action( 'woocommerce_delete_product', [ $this, 'invalidate_product' ] );
        add_action( 'woocommerce_new_order', [ $this, 'invalidate_order_caches' ] );
        add_action( 'woocommerce_order_status_changed', [ $this, 'invalidate_order_caches' ] );
        add_action( 'woocommerce_product_set_stock', [ $this, 'invalidate_product_on_stock_change' ] );

        // Cache warming on cron.
        add_action( 'wcet_warm_cache', [ $this, 'warm_product_cache' ] );
        if ( ! wp_next_scheduled( 'wcet_warm_cache' ) ) {
            wp_schedule_event( time(), 'hourly', 'wcet_warm_cache' );
        }

        // Page cache headers for logged-out users.
        add_action( 'template_redirect', [ $this, 'set_cache_headers' ] );

        // Fragment caching for expensive template parts.
        add_action( 'init', [ $this, 'register_fragment_cache' ] );
    }

    /**
     * Get a cached value or compute + store it.
     */
    public function remember( string $key, callable $callback, int $ttl = self::TTL_MEDIUM, string $group = self::GROUP ) {
        $cached = wp_cache_get( $key, $group );
        if ( false !== $cached ) {
            return $cached;
        }

        $value = $callback();
        wp_cache_set( $key, $value, $group, $ttl );
        return $value;
    }

    public function get( string $key, string $group = self::GROUP ) {
        return wp_cache_get( $key, $group );
    }

    public function set( string $key, $value, int $ttl = self::TTL_MEDIUM, string $group = self::GROUP ): bool {
        return wp_cache_set( $key, $value, $group, $ttl );
    }

    public function delete( string $key, string $group = self::GROUP ): bool {
        return wp_cache_delete( $key, $group );
    }

    public function flush_group( string $group = self::GROUP ): void {
        if ( function_exists( 'wp_cache_flush_group' ) ) {
            wp_cache_flush_group( $group );
        } else {
            wp_cache_flush();
        }
    }

    // --- Cached Queries ---

    /**
     * Get product data with caching.
     */
    public function get_product_data( int $product_id ): ?array {
        return $this->remember(
            "product_{$product_id}",
            function () use ( $product_id ) {
                $product = wc_get_product( $product_id );
                if ( ! $product ) {
                    return null;
                }
                return [
                    'id'        => $product->get_id(),
                    'name'      => $product->get_name(),
                    'price'     => $product->get_price(),
                    'stock'     => $product->get_stock_quantity(),
                    'status'    => $product->get_status(),
                    'type'      => $product->get_type(),
                    'sku'       => $product->get_sku(),
                    'permalink' => $product->get_permalink(),
                ];
            },
            self::TTL_MEDIUM
        );
    }

    /**
     * Cached product count by status.
     */
    public function get_product_counts(): array {
        return $this->remember( 'product_counts', function () {
            $counts = wp_count_posts( 'product' );
            return (array) $counts;
        }, self::TTL_LONG );
    }

    /**
     * Cached category tree.
     */
    public function get_category_tree(): array {
        return $this->remember( 'category_tree', function () {
            $terms = get_terms( [
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'orderby'    => 'name',
            ] );
            if ( is_wp_error( $terms ) ) {
                return [];
            }
            return array_map( fn( $t ) => [
                'id'     => $t->term_id,
                'name'   => $t->name,
                'slug'   => $t->slug,
                'parent' => $t->parent,
                'count'  => $t->count,
            ], $terms );
        }, self::TTL_LONG );
    }

    // --- Invalidation ---

    public function invalidate_product( int $product_id ): void {
        $this->delete( "product_{$product_id}" );
        $this->delete( 'product_counts' );
        $this->delete( 'category_tree' );

        do_action( 'wcet_cache_invalidated', 'product', $product_id );
    }

    public function invalidate_product_on_stock_change( WC_Product $product ): void {
        $this->invalidate_product( $product->get_id() );
    }

    public function invalidate_order_caches( int $order_id = 0 ): void {
        $this->delete( 'dashboard_stats' );
        $this->delete( 'revenue_chart' );
        do_action( 'wcet_cache_invalidated', 'order', $order_id );
    }

    // --- Cache Warming ---

    public function warm_product_cache(): void {
        $product_ids = wc_get_products( [
            'status'  => 'publish',
            'limit'   => 200,
            'return'  => 'ids',
            'orderby' => 'date',
            'order'   => 'DESC',
        ] );

        foreach ( $product_ids as $id ) {
            $this->get_product_data( $id );
        }

        $this->get_category_tree();
        $this->get_product_counts();
    }

    // --- HTTP Cache Headers ---

    public function set_cache_headers(): void {
        if ( is_user_logged_in() || is_cart() || is_checkout() || is_account_page() ) {
            nocache_headers();
            return;
        }

        if ( is_product() ) {
            $this->send_cache_header( 600 ); // 10 min for products.
        } elseif ( is_product_category() || is_product_tag() || is_shop() ) {
            $this->send_cache_header( 900 ); // 15 min for archives.
        } elseif ( is_front_page() ) {
            $this->send_cache_header( 300 ); // 5 min for homepage.
        }
    }

    private function send_cache_header( int $max_age ): void {
        header( "Cache-Control: public, max-age={$max_age}, s-maxage=" . ( $max_age * 2 ) );
        header( 'Vary: Accept-Encoding, Cookie' );
    }

    // --- Fragment Caching ---

    public function register_fragment_cache(): void {
        add_filter( 'wcet_fragment_cache', [ $this, 'get_fragment' ], 10, 3 );
    }

    /**
     * Cache a template fragment.
     *
     * Usage: echo apply_filters('wcet_fragment_cache', '', 'sidebar_categories', fn() => get_template_part('parts/categories'));
     */
    public function get_fragment( string $output, string $key, ?callable $render = null ): string {
        $cached = $this->get( "fragment_{$key}" );
        if ( false !== $cached ) {
            return $cached;
        }

        if ( $render ) {
            ob_start();
            $render();
            $output = ob_get_clean();
            $this->set( "fragment_{$key}", $output, self::TTL_SHORT );
        }

        return $output;
    }

    // --- Diagnostics ---

    public function get_cache_stats(): array {
        global $wp_object_cache;

        $stats = [
            'backend'    => $this->detect_cache_backend(),
            'is_persistent' => wp_using_ext_object_cache(),
        ];

        if ( method_exists( $wp_object_cache, 'stats' ) ) {
            ob_start();
            $wp_object_cache->stats();
            $stats['raw'] = ob_get_clean();
        }

        return $stats;
    }

    private function detect_cache_backend(): string {
        if ( class_exists( 'Redis' ) && wp_using_ext_object_cache() ) {
            return 'Redis';
        }
        if ( class_exists( 'Memcached' ) && wp_using_ext_object_cache() ) {
            return 'Memcached';
        }
        if ( wp_using_ext_object_cache() ) {
            return 'External (unknown)';
        }
        return 'WordPress default (non-persistent)';
    }
}

WCET_Object_Cache::instance();
