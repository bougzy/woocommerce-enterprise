<?php
/**
 * Plugin Name: WC Enterprise Toolkit
 * Plugin URI: https://github.com/yourusername/woocommerce-enterprise
 * Description: Enterprise-grade WooCommerce optimization — custom product types, advanced pricing engine, performance caching, search integration, custom REST API, payment gateways, security hardening, and admin dashboards.
 * Version: 1.0.0
 * Author: Enterprise Dev Team
 * Author URI: https://github.com/yourusername
 * Text Domain: wc-enterprise
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.1
 * WC requires at least: 7.0
 * WC tested up to: 8.5
 * License: GPLv2 or later
 */

defined( 'ABSPATH' ) || exit;

define( 'WCET_VERSION', '1.0.0' );
define( 'WCET_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCET_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCET_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class — singleton.
 */
final class WC_Enterprise_Toolkit {

    /** @var self|null */
    private static $instance = null;

    /** @return self */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        if ( ! $this->check_dependencies() ) {
            return; // Stop — WooCommerce is not active.
        }
        $this->includes();
        $this->init_hooks();
    }

    /**
     * @return bool True if all dependencies are met.
     */
    private function check_dependencies() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', function () {
                echo '<div class="error"><p><strong>WC Enterprise Toolkit</strong> requires WooCommerce 7.0+ to be installed and activated.</p></div>';
            } );
            return false;
        }
        return true;
    }

    private function includes() {
        // Core modules.
        require_once WCET_PLUGIN_DIR . 'includes/product-types/class-bundle-product.php';
        require_once WCET_PLUGIN_DIR . 'includes/product-types/class-subscription-product.php';
        require_once WCET_PLUGIN_DIR . 'includes/pricing/class-pricing-engine.php';
        require_once WCET_PLUGIN_DIR . 'includes/checkout/class-custom-checkout.php';
        require_once WCET_PLUGIN_DIR . 'includes/orders/class-order-workflow.php';

        // Performance.
        require_once WCET_PLUGIN_DIR . 'includes/cache/class-object-cache.php';
        require_once WCET_PLUGIN_DIR . 'includes/cache/class-query-optimizer.php';
        require_once WCET_PLUGIN_DIR . 'includes/cache/class-hook-optimizer.php';

        // Search.
        require_once WCET_PLUGIN_DIR . 'includes/search/class-search-engine.php';
        require_once WCET_PLUGIN_DIR . 'includes/search/class-product-filter.php';

        // API.
        require_once WCET_PLUGIN_DIR . 'includes/api/class-rest-products.php';
        require_once WCET_PLUGIN_DIR . 'includes/api/class-rest-orders.php';
        require_once WCET_PLUGIN_DIR . 'includes/api/class-token-auth.php';
        require_once WCET_PLUGIN_DIR . 'includes/api/class-rate-limiter.php';

        // Payments.
        require_once WCET_PLUGIN_DIR . 'includes/payments/class-stripe-gateway.php';
        require_once WCET_PLUGIN_DIR . 'includes/payments/class-paystack-gateway.php';
        require_once WCET_PLUGIN_DIR . 'includes/payments/class-webhook-handler.php';
        require_once WCET_PLUGIN_DIR . 'includes/payments/class-fraud-prevention.php';

        // Security.
        require_once WCET_PLUGIN_DIR . 'includes/security/class-security-hardener.php';
        require_once WCET_PLUGIN_DIR . 'includes/security/class-capability-manager.php';

        // Admin.
        require_once WCET_PLUGIN_DIR . 'includes/admin/class-admin-dashboard.php';
        require_once WCET_PLUGIN_DIR . 'includes/admin/class-analytics.php';
        require_once WCET_PLUGIN_DIR . 'includes/admin/class-performance-monitor.php';

        // Scalability.
        require_once WCET_PLUGIN_DIR . 'includes/scalability/class-background-jobs.php';
        require_once WCET_PLUGIN_DIR . 'includes/scalability/class-health-check.php';
    }

    private function init_hooks() {
        add_action( 'init', [ $this, 'load_textdomain' ] );
        add_action( 'before_woocommerce_init', [ $this, 'declare_hpos_compatibility' ] );
        register_activation_hook( __FILE__, [ $this, 'activate' ] );
        register_deactivation_hook( __FILE__, [ $this, 'deactivate' ] );
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'wc-enterprise', false, dirname( WCET_PLUGIN_BASENAME ) . '/languages/' );
    }

    public function declare_hpos_compatibility() {
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
        }
    }

    public function activate() {
        // Create custom tables.
        $this->create_tables();
        flush_rewrite_rules();
        update_option( 'wcet_version', WCET_VERSION );
    }

    public function deactivate() {
        flush_rewrite_rules();
        wp_clear_scheduled_hook( 'wcet_background_cleanup' );
        wp_clear_scheduled_hook( 'wcet_analytics_aggregate' );
    }

    private function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $sql = "
        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wcet_pricing_rules (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            type ENUM('percentage','fixed','bogo','tiered','role_based') NOT NULL DEFAULT 'percentage',
            conditions LONGTEXT NOT NULL,
            discount_value DECIMAL(10,4) NOT NULL DEFAULT 0,
            priority INT NOT NULL DEFAULT 10,
            starts_at DATETIME NULL,
            ends_at DATETIME NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_active_priority (is_active, priority),
            KEY idx_dates (starts_at, ends_at)
        ) $charset;

        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wcet_api_tokens (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            permissions TEXT NOT NULL,
            last_used DATETIME NULL,
            expires_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_token (token_hash),
            KEY idx_user (user_id)
        ) $charset;

        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wcet_rate_limits (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            identifier VARCHAR(255) NOT NULL,
            endpoint VARCHAR(255) NOT NULL,
            hits INT UNSIGNED NOT NULL DEFAULT 1,
            window_start DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY idx_identifier_endpoint (identifier, endpoint),
            KEY idx_window (window_start)
        ) $charset;

        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wcet_analytics (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            metric_name VARCHAR(100) NOT NULL,
            metric_value DECIMAL(15,4) NOT NULL DEFAULT 0,
            dimensions JSON NULL,
            recorded_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_metric_date (metric_name, recorded_at)
        ) $charset;

        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wcet_fraud_log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id BIGINT UNSIGNED NOT NULL,
            risk_score DECIMAL(5,2) NOT NULL DEFAULT 0,
            risk_factors JSON NULL,
            action_taken VARCHAR(50) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_order (order_id),
            KEY idx_risk (risk_score)
        ) $charset;

        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wcet_background_jobs (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_type VARCHAR(100) NOT NULL,
            payload LONGTEXT NOT NULL,
            status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 3,
            scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            error_message TEXT NULL,
            PRIMARY KEY (id),
            KEY idx_status_scheduled (status, scheduled_at),
            KEY idx_type (job_type)
        ) $charset;
        ";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}

// Boot.
add_action( 'plugins_loaded', function () {
    WC_Enterprise_Toolkit::instance();
}, 10 );
