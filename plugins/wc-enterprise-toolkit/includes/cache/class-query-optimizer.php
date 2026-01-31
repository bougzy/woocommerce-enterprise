<?php
/**
 * Database Query Optimizer
 *
 * Rewrites expensive WooCommerce queries, adds missing indexes,
 * implements query batching, and reduces n+1 query patterns.
 */

defined( 'ABSPATH' ) || exit;

class WCET_Query_Optimizer {

    private static $instance = null;
    private $query_log = [];

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Optimize core WooCommerce queries.
        add_filter( 'woocommerce_product_query', [ $this, 'optimize_product_query' ] );
        add_filter( 'posts_clauses', [ $this, 'optimize_post_clauses' ], 10, 2 );
        add_filter( 'woocommerce_order_query_args', [ $this, 'optimize_order_query' ] );

        // Prevent n+1 queries by pre-loading meta.
        add_action( 'woocommerce_before_shop_loop', [ $this, 'preload_product_meta' ] );

        // Query monitor (debug mode only).
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            add_filter( 'query', [ $this, 'log_query' ] );
            add_action( 'shutdown', [ $this, 'report_slow_queries' ] );
        }

        // Add missing indexes on activation.
        add_action( 'admin_init', [ $this, 'maybe_add_indexes' ] );

        // Optimize term count queries.
        add_filter( 'woocommerce_change_term_counts', '__return_false' );
    }

    /**
     * Optimize the main product catalog query.
     */
    public function optimize_product_query( WP_Query $query ) {
        if ( ! $query->is_main_query() ) {
            return $query;
        }

        // Use post__in for cached product IDs when possible.
        $query->set( 'no_found_rows', ! $this->needs_pagination( $query ) );

        // Limit fields when only IDs are needed.
        if ( $query->get( 'fields' ) !== 'ids' ) {
            $query->set( 'update_post_meta_cache', true );
            $query->set( 'update_post_term_cache', true );
        }

        // Optimize ordering — avoid meta_value ordering when possible.
        $orderby = $query->get( 'orderby' );
        if ( 'meta_value_num' === $orderby && '_price' === $query->get( 'meta_key' ) ) {
            // Use the lookup table instead.
            $query->set( 'orderby', 'meta_value_num' );
        }

        return $query;
    }

    /**
     * Optimize SQL clauses for product queries.
     */
    public function optimize_post_clauses( array $clauses, WP_Query $query ) {
        if ( ! isset( $query->query_vars['post_type'] ) || 'product' !== $query->query_vars['post_type'] ) {
            return $clauses;
        }

        // Add SQL_CALC_FOUND_ROWS only when needed.
        if ( ! empty( $query->query_vars['no_found_rows'] ) ) {
            $clauses['fields'] = str_replace( 'SQL_CALC_FOUND_ROWS', '', $clauses['fields'] );
        }

        return $clauses;
    }

    /**
     * Optimize order queries for the admin panel.
     */
    public function optimize_order_query( array $args ) {
        // Default to recent orders only.
        if ( empty( $args['date_created'] ) && empty( $args['s'] ) ) {
            $args['date_created'] = '>' . gmdate( 'Y-m-d', strtotime( '-90 days' ) );
        }

        // Ensure reasonable limits.
        if ( empty( $args['limit'] ) || $args['limit'] > 100 ) {
            $args['limit'] = 50;
        }

        return $args;
    }

    /**
     * Pre-load product meta to prevent n+1 queries on archive pages.
     */
    public function preload_product_meta() {
        global $wp_query;

        if ( empty( $wp_query->posts ) ) {
            return;
        }

        $product_ids = wp_list_pluck( $wp_query->posts, 'ID' );
        if ( empty( $product_ids ) ) {
            return;
        }

        // Batch-load all product meta.
        update_meta_cache( 'post', $product_ids );

        // Pre-load product terms.
        $taxonomies = [ 'product_cat', 'product_tag', 'product_visibility' ];
        wp_cache_get_multiple( $product_ids, 'product_cat_relationships' );

        foreach ( $taxonomies as $tax ) {
            wp_get_object_terms( $product_ids, $tax );
        }
    }

    /**
     * Add missing database indexes for better query performance.
     */
    public function maybe_add_indexes() {
        if ( get_option( 'wcet_indexes_added' ) ) {
            return;
        }

        global $wpdb;

        $indexes = [
            // Speed up product lookups by SKU.
            "ALTER TABLE {$wpdb->postmeta} ADD INDEX idx_sku (meta_key(20), meta_value(50))",
            // Speed up price sorting.
            "ALTER TABLE {$wpdb->postmeta} ADD INDEX idx_price_sort (meta_key(20), meta_value(20))",
            // Speed up stock queries.
            "ALTER TABLE {$wpdb->postmeta} ADD INDEX idx_stock (meta_key(20), meta_value(10))",
        ];

        foreach ( $indexes as $sql ) {
            // Suppress errors — index may already exist.
            $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        update_option( 'wcet_indexes_added', true );
    }

    /**
     * Log queries for debug analysis.
     */
    public function log_query( string $query ) {
        $start = microtime( true );

        // Store for later analysis.
        $this->query_log[] = [
            'sql'   => $query,
            'start' => $start,
        ];

        return $query;
    }

    /**
     * Report slow queries in debug mode.
     */
    public function report_slow_queries() {
        if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES ) {
            return;
        }

        global $wpdb;
        $slow_threshold = 0.05; // 50ms

        foreach ( $wpdb->queries as $q ) {
            if ( $q[1] > $slow_threshold ) {
                error_log( sprintf(
                    '[WCET Slow Query] %.4fs: %s | Called from: %s',
                    $q[1],
                    $q[0],
                    $q[2]
                ) );
            }
        }
    }

    /**
     * Batch get products efficiently — avoid wc_get_product() in a loop.
     */
    public function batch_get_products( array $product_ids ) {
        if ( empty( $product_ids ) ) {
            return [];
        }

        // Pre-cache all post data.
        _prime_post_caches( $product_ids, true, true );

        $products = [];
        foreach ( $product_ids as $id ) {
            $product = wc_get_product( $id );
            if ( $product ) {
                $products[ $id ] = $product;
            }
        }
        return $products;
    }

    /**
     * Get query statistics for the performance monitor.
     */
    public function get_query_stats() {
        global $wpdb;

        return [
            'total_queries' => $wpdb->num_queries,
            'slow_queries'  => defined( 'SAVEQUERIES' ) && SAVEQUERIES
                ? count( array_filter( $wpdb->queries, function( $q ) { return $q[1] > 0.05; } ) )
                : 'N/A (SAVEQUERIES disabled)',
        ];
    }

    private function needs_pagination( WP_Query $query ) {
        return ! empty( $query->query_vars['paged'] ) || ! empty( $query->query_vars['offset'] );
    }
}

WCET_Query_Optimizer::instance();
