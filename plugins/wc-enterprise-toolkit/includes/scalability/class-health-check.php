<?php
/**
 * Health Check & Failover Monitoring
 *
 * Monitors system health, provides a health endpoint for load balancers,
 * and implements basic failover strategies for external services.
 */

defined( 'ABSPATH' ) || exit;

class WCET_Health_Check {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Health check REST endpoint for load balancers.
        add_action( 'rest_api_init', [ $this, 'register_health_endpoint' ] );

        // Scheduled health monitoring.
        add_action( 'wcet_health_monitor', [ $this, 'run_health_checks' ] );
        if ( ! wp_next_scheduled( 'wcet_health_monitor' ) ) {
            wp_schedule_event( time(), 'every_minute', 'wcet_health_monitor' );
        }

        // Admin bar health indicator.
        add_action( 'admin_bar_menu', [ $this, 'admin_bar_health' ], 999 );
    }

    /**
     * Register /wcet/v1/health endpoint (unauthenticated for load balancer probes).
     */
    public function register_health_endpoint(): void {
        register_rest_route( 'wcet/v1', '/health', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'health_endpoint' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( 'wcet/v1', '/health/detailed', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'detailed_health_endpoint' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_woocommerce' );
            },
        ] );
    }

    /**
     * Simple health probe — returns 200 if the system is operational.
     */
    public function health_endpoint(): WP_REST_Response {
        $status = $this->get_overall_status();

        $code = match ( $status ) {
            'healthy'  => 200,
            'degraded' => 200, // Still serve traffic.
            'unhealthy' => 503,
            default     => 500,
        };

        return new WP_REST_Response( [
            'status'    => $status,
            'timestamp' => gmdate( 'c' ),
            'version'   => WCET_VERSION,
        ], $code );
    }

    /**
     * Detailed health check (authenticated).
     */
    public function detailed_health_endpoint(): WP_REST_Response {
        $checks = $this->run_all_checks();

        return new WP_REST_Response( [
            'status'    => $this->get_overall_status(),
            'timestamp' => gmdate( 'c' ),
            'version'   => WCET_VERSION,
            'checks'    => $checks,
            'system'    => $this->get_system_info(),
        ], 200 );
    }

    /**
     * Run all health checks.
     */
    public function run_all_checks(): array {
        return [
            'database'      => $this->check_database(),
            'object_cache'  => $this->check_object_cache(),
            'woocommerce'   => $this->check_woocommerce(),
            'disk_space'    => $this->check_disk_space(),
            'memory'        => $this->check_memory(),
            'cron'          => $this->check_cron(),
            'search_engine' => $this->check_search_engine(),
            'ssl'           => $this->check_ssl(),
        ];
    }

    private function check_database(): array {
        global $wpdb;
        $start  = microtime( true );
        $result = $wpdb->get_var( 'SELECT 1' );
        $time   = round( ( microtime( true ) - $start ) * 1000, 2 );

        return [
            'status'       => '1' === $result ? 'pass' : 'fail',
            'response_ms'  => $time,
            'version'      => $wpdb->db_version(),
        ];
    }

    private function check_object_cache(): array {
        $test_key   = 'wcet_health_' . time();
        $test_value = wp_generate_password( 12, false );

        wp_cache_set( $test_key, $test_value, 'wcet_health', 30 );
        $retrieved = wp_cache_get( $test_key, 'wcet_health' );
        wp_cache_delete( $test_key, 'wcet_health' );

        return [
            'status'     => $retrieved === $test_value ? 'pass' : 'warn',
            'persistent' => wp_using_ext_object_cache(),
            'backend'    => wp_using_ext_object_cache() ? 'external' : 'default',
        ];
    }

    private function check_woocommerce(): array {
        $active = class_exists( 'WooCommerce' );
        $version = $active ? WC()->version : 'N/A';

        return [
            'status'  => $active ? 'pass' : 'fail',
            'version' => $version,
            'hpos'    => get_option( 'woocommerce_custom_orders_table_enabled' ) === 'yes',
        ];
    }

    private function check_disk_space(): array {
        $free  = @disk_free_space( ABSPATH );
        $total = @disk_total_space( ABSPATH );

        if ( false === $free || false === $total ) {
            return [ 'status' => 'warn', 'message' => 'Unable to check disk space' ];
        }

        $percent_free = round( ( $free / $total ) * 100, 1 );
        $status       = $percent_free > 10 ? 'pass' : ( $percent_free > 5 ? 'warn' : 'fail' );

        return [
            'status'       => $status,
            'free_gb'      => round( $free / 1073741824, 2 ),
            'total_gb'     => round( $total / 1073741824, 2 ),
            'percent_free' => $percent_free,
        ];
    }

    private function check_memory(): array {
        $limit   = ini_get( 'memory_limit' );
        $used    = memory_get_usage( true );
        $peak    = memory_get_peak_usage( true );
        $limit_b = wp_convert_hr_to_bytes( $limit );

        $percent_used = $limit_b > 0 ? round( ( $used / $limit_b ) * 100, 1 ) : 0;
        $status       = $percent_used < 70 ? 'pass' : ( $percent_used < 90 ? 'warn' : 'fail' );

        return [
            'status'       => $status,
            'limit'        => $limit,
            'used_mb'      => round( $used / 1048576, 2 ),
            'peak_mb'      => round( $peak / 1048576, 2 ),
            'percent_used' => $percent_used,
        ];
    }

    private function check_cron(): array {
        $next_scheduled = wp_next_scheduled( 'wcet_process_job_queue' );
        $overdue        = $next_scheduled && $next_scheduled < ( time() - 300 );

        return [
            'status'     => $overdue ? 'warn' : 'pass',
            'next_run'   => $next_scheduled ? gmdate( 'c', $next_scheduled ) : 'not scheduled',
            'is_overdue' => $overdue,
            'disabled'   => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
        ];
    }

    private function check_search_engine(): array {
        $engine = get_option( 'wcet_search_engine', 'default' );

        if ( 'default' === $engine ) {
            return [ 'status' => 'pass', 'engine' => 'WordPress default' ];
        }

        if ( 'meilisearch' === $engine ) {
            $host     = get_option( 'wcet_meilisearch_host', '' );
            $response = wp_remote_get( $host . '/health', [ 'timeout' => 3 ] );

            if ( is_wp_error( $response ) ) {
                return [ 'status' => 'fail', 'engine' => 'Meilisearch', 'error' => $response->get_error_message() ];
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            return [
                'status' => ( $body['status'] ?? '' ) === 'available' ? 'pass' : 'warn',
                'engine' => 'Meilisearch',
            ];
        }

        return [ 'status' => 'pass', 'engine' => $engine ];
    }

    private function check_ssl(): array {
        return [
            'status'    => is_ssl() ? 'pass' : 'warn',
            'is_ssl'    => is_ssl(),
            'site_url'  => str_starts_with( get_site_url(), 'https' ),
        ];
    }

    /**
     * Determine overall system status.
     */
    public function get_overall_status(): string {
        $checks   = $this->run_all_checks();
        $statuses = array_column( $checks, 'status' );

        if ( in_array( 'fail', $statuses, true ) ) {
            return 'unhealthy';
        }
        if ( in_array( 'warn', $statuses, true ) ) {
            return 'degraded';
        }
        return 'healthy';
    }

    private function get_system_info(): array {
        return [
            'php_version'  => PHP_VERSION,
            'wp_version'   => get_bloginfo( 'version' ),
            'wc_version'   => defined( 'WC_VERSION' ) ? WC_VERSION : 'N/A',
            'plugin_version' => WCET_VERSION,
            'php_sapi'     => PHP_SAPI,
            'max_execution_time' => ini_get( 'max_execution_time' ),
            'upload_max'   => ini_get( 'upload_max_filesize' ),
        ];
    }

    /**
     * Run health checks and store results (called by cron).
     */
    public function run_health_checks(): void {
        $checks = $this->run_all_checks();
        $status = $this->get_overall_status();

        // Store snapshot.
        update_option( 'wcet_last_health_check', [
            'status'    => $status,
            'checks'    => $checks,
            'timestamp' => current_time( 'mysql' ),
        ] );

        // Alert if unhealthy.
        if ( 'unhealthy' === $status ) {
            $admin_email = get_option( 'admin_email' );
            $failed      = array_filter( $checks, fn( $c ) => ( $c['status'] ?? '' ) === 'fail' );

            wp_mail(
                $admin_email,
                '[WCET] System Health Alert — Unhealthy',
                sprintf(
                    "The WC Enterprise Toolkit health check has detected failures:\n\n%s\n\nCheck the admin dashboard for details.",
                    wp_json_encode( $failed, JSON_PRETTY_PRINT )
                )
            );
        }
    }

    /**
     * Admin bar health indicator.
     */
    public function admin_bar_health( WP_Admin_Bar $admin_bar ): void {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $last_check = get_option( 'wcet_last_health_check', [] );
        $status     = $last_check['status'] ?? 'unknown';

        $colors = [
            'healthy'   => '#46b450',
            'degraded'  => '#f0ad4e',
            'unhealthy' => '#dc3232',
            'unknown'   => '#999',
        ];

        $admin_bar->add_node( [
            'id'    => 'wcet-health',
            'title' => sprintf(
                '<span style="color:%s;">&#9679;</span> Store: %s',
                $colors[ $status ] ?? '#999',
                ucfirst( $status )
            ),
            'href'  => admin_url( 'admin.php?page=wcet-dashboard' ),
        ] );
    }
}

WCET_Health_Check::instance();
