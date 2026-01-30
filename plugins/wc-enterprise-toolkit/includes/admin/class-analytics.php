<?php
/**
 * Sales Analytics Engine.
 *
 * Records and aggregates analytics events including revenue, order metrics,
 * conversion rates, customer acquisition data, and product performance.
 *
 * @package    WC_Enterprise_Toolkit
 * @subpackage Admin
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCET_Analytics
 *
 * Singleton class that provides comprehensive sales analytics and reporting.
 *
 * @since 1.0.0
 */
class WCET_Analytics {

	/**
	 * Single instance of this class.
	 *
	 * @var WCET_Analytics|null
	 */
	private static $instance = null;

	/**
	 * Analytics table name (without prefix).
	 *
	 * @var string
	 */
	const TABLE_SUFFIX = 'wcet_analytics';

	/**
	 * Cache group for analytics data.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'wcet_analytics';

	/**
	 * Cache TTL in seconds (10 minutes).
	 *
	 * @var int
	 */
	const CACHE_TTL = 600;

	/**
	 * Cron hook name for daily aggregation.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wcet_analytics_aggregate';

	/**
	 * Returns the single instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCET_Analytics
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Registers hooks.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		// WooCommerce order hooks.
		add_action( 'woocommerce_new_order', array( $this, 'record_order_analytics' ), 10, 2 );
		add_action( 'woocommerce_cart_updated', array( $this, 'track_cart_event' ) );

		// Cron aggregation.
		add_action( self::CRON_HOOK, array( $this, 'run_daily_aggregation' ) );
		add_action( 'init', array( $this, 'schedule_cron' ) );

		// Admin menu and AJAX.
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );
		add_action( 'wp_ajax_wcet_analytics_export_csv', array( $this, 'ajax_export_csv' ) );
		add_action( 'wp_ajax_wcet_analytics_data', array( $this, 'ajax_get_analytics_data' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Prevent cloning.
	 *
	 * @since 1.0.0
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @since 1.0.0
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize singleton.' );
	}

	/**
	 * Get the fully prefixed analytics table name.
	 *
	 * @since  1.0.0
	 * @return string Table name.
	 */
	private function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Schedule the daily aggregation cron event if not already scheduled.
	 *
	 * @since 1.0.0
	 */
	public function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 02:00:00' ), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Register the analytics admin submenu page.
	 *
	 * @since 1.0.0
	 */
	public function register_admin_page() {
		add_submenu_page(
			'wcet-dashboard',
			__( 'Sales Analytics', 'wc-enterprise-toolkit' ),
			__( 'Analytics', 'wc-enterprise-toolkit' ),
			'manage_woocommerce',
			'wcet-analytics',
			array( $this, 'render_analytics_page' )
		);
	}

	/**
	 * Enqueue admin assets for the analytics page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'enterprise-store_page_wcet-analytics' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'jquery-ui-datepicker-style', 'https://code.jquery.com/ui/1.13.2/themes/base/jquery-ui.css', array(), '1.13.2' );
		wp_enqueue_script( 'jquery-ui-datepicker' );

		wp_enqueue_style(
			'wcet-analytics',
			WCET_PLUGIN_URL . 'assets/css/admin-analytics.css',
			array(),
			WCET_VERSION
		);

		wp_enqueue_script(
			'wcet-analytics',
			WCET_PLUGIN_URL . 'assets/js/admin-analytics.js',
			array( 'jquery', 'jquery-ui-datepicker', 'wp-util' ),
			WCET_VERSION,
			true
		);

		wp_localize_script( 'wcet-analytics', 'wcetAnalytics', array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'dataNonce'  => wp_create_nonce( 'wcet_analytics_data' ),
			'exportNonce' => wp_create_nonce( 'wcet_analytics_export' ),
		) );
	}

	/**
	 * Record analytics data when a new order is created.
	 *
	 * @since 1.0.0
	 *
	 * @param int      $order_id The order ID.
	 * @param WC_Order $order    The order object (optional, loaded if not provided).
	 */
	public function record_order_analytics( $order_id, $order = null ) {
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}

		if ( ! $order || ! ( $order instanceof WC_Order ) ) {
			return;
		}

		$total         = (float) $order->get_total();
		$items_count   = $order->get_item_count();
		$payment       = $order->get_payment_method();
		$customer_id   = $order->get_customer_id();
		$is_new        = $this->is_new_customer( $customer_id, $order_id );

		// Record revenue.
		$this->record_metric( 'revenue', $total, array(
			'order_id'       => $order_id,
			'payment_method' => $payment,
			'customer_type'  => $is_new ? 'new' : 'returning',
		) );

		// Record order count.
		$this->record_metric( 'orders_count', 1, array(
			'order_id' => $order_id,
			'status'   => $order->get_status(),
		) );

		// Record average order value.
		$this->record_metric( 'average_order_value', $total, array(
			'order_id'    => $order_id,
			'items_count' => $items_count,
		) );

		// Record top products.
		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$qty        = $item->get_quantity();
			$line_total = (float) $item->get_total();
			$product    = $item->get_product();
			$categories = array();

			if ( $product ) {
				$term_ids = $product->get_category_ids();
				foreach ( $term_ids as $term_id ) {
					$term = get_term( $term_id, 'product_cat' );
					if ( $term && ! is_wp_error( $term ) ) {
						$categories[] = $term->name;
					}
				}
			}

			$this->record_metric( 'top_products', $qty, array(
				'order_id'   => $order_id,
				'product_id' => $product_id,
				'line_total' => $line_total,
				'categories' => $categories,
			) );
		}

		// Record top categories.
		$category_totals = array();
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( $product ) {
				$term_ids = $product->get_category_ids();
				foreach ( $term_ids as $term_id ) {
					if ( ! isset( $category_totals[ $term_id ] ) ) {
						$category_totals[ $term_id ] = 0;
					}
					$category_totals[ $term_id ] += (float) $item->get_total();
				}
			}
		}

		foreach ( $category_totals as $term_id => $cat_total ) {
			$term = get_term( $term_id, 'product_cat' );
			$this->record_metric( 'top_categories', $cat_total, array(
				'order_id'    => $order_id,
				'category_id' => $term_id,
				'category'    => ( $term && ! is_wp_error( $term ) ) ? $term->name : '',
			) );
		}

		// Record customer acquisition metric.
		$this->record_metric( 'customer_acquisition', 1, array(
			'order_id'      => $order_id,
			'customer_id'   => $customer_id,
			'customer_type' => $is_new ? 'new' : 'returning',
		) );

		// Invalidate analytics cache.
		$this->flush_analytics_cache();
	}

	/**
	 * Track cart update events for abandonment tracking.
	 *
	 * @since 1.0.0
	 */
	public function track_cart_event() {
		if ( is_admin() || ! WC()->cart ) {
			return;
		}

		$cart       = WC()->cart;
		$cart_total = (float) $cart->get_total( 'edit' );
		$item_count = $cart->get_cart_contents_count();
		$session_id = WC()->session ? WC()->session->get_customer_id() : '';

		if ( $item_count > 0 && ! empty( $session_id ) ) {
			$this->record_metric( 'cart_activity', $cart_total, array(
				'session_id' => $session_id,
				'item_count' => $item_count,
				'event'      => 'cart_updated',
			) );
		}
	}

	/**
	 * Record a metric to the analytics table.
	 *
	 * @since 1.0.0
	 *
	 * @param string $metric_name  Name of the metric.
	 * @param float  $metric_value Numeric value of the metric.
	 * @param array  $dimensions   Additional dimensions as key-value pairs.
	 * @return int|false The number of rows inserted, or false on error.
	 */
	public function record_metric( $metric_name, $metric_value, $dimensions = array() ) {
		global $wpdb;

		$table = $this->get_table_name();

		return $wpdb->insert(
			$table,
			array(
				'metric_name'  => sanitize_text_field( $metric_name ),
				'metric_value' => (float) $metric_value,
				'dimensions'   => wp_json_encode( $dimensions ),
				'recorded_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%f', '%s', '%s' )
		);
	}

	/**
	 * Run daily aggregation of analytics data.
	 *
	 * Computes summary metrics for the previous day and stores them.
	 *
	 * @since 1.0.0
	 */
	public function run_daily_aggregation() {
		$yesterday_start = gmdate( 'Y-m-d', strtotime( '-1 day' ) ) . ' 00:00:00';
		$yesterday_end   = gmdate( 'Y-m-d', strtotime( '-1 day' ) ) . ' 23:59:59';

		// Aggregate total revenue for yesterday.
		$revenue = $this->get_revenue_by_period( $yesterday_start, $yesterday_end );
		$this->record_metric( 'daily_revenue_aggregate', $revenue, array(
			'date'  => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
			'type'  => 'daily_aggregate',
		) );

		// Aggregate order count for yesterday.
		$orders = $this->get_orders_by_status( 'yesterday' );
		$total_orders = 0;
		foreach ( $orders as $count ) {
			$total_orders += $count;
		}
		$this->record_metric( 'daily_orders_aggregate', $total_orders, array(
			'date'     => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
			'type'     => 'daily_aggregate',
			'statuses' => $orders,
		) );

		// Compute average order value.
		$aov = ( $total_orders > 0 ) ? $revenue / $total_orders : 0;
		$this->record_metric( 'daily_aov_aggregate', $aov, array(
			'date' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
			'type' => 'daily_aggregate',
		) );

		// Compute cart abandonment rate.
		$abandonment_rate = $this->compute_cart_abandonment_rate( $yesterday_start, $yesterday_end );
		$this->record_metric( 'cart_abandonment_rate', $abandonment_rate, array(
			'date' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
			'type' => 'daily_aggregate',
		) );

		// Compute conversion rate.
		$conversion_rate = $this->compute_conversion_rate( $yesterday_start, $yesterday_end );
		$this->record_metric( 'conversion_rate', $conversion_rate, array(
			'date' => gmdate( 'Y-m-d', strtotime( '-1 day' ) ),
			'type' => 'daily_aggregate',
		) );

		// Flush cache after aggregation.
		$this->flush_analytics_cache();

		/**
		 * Fires after daily analytics aggregation completes.
		 *
		 * @since 1.0.0
		 *
		 * @param string $date The aggregation date (yesterday).
		 */
		do_action( 'wcet_analytics_aggregation_complete', gmdate( 'Y-m-d', strtotime( '-1 day' ) ) );
	}

	/**
	 * Get total revenue for a date range.
	 *
	 * @since 1.0.0
	 *
	 * @param string $start Start datetime (Y-m-d H:i:s).
	 * @param string $end   End datetime (Y-m-d H:i:s).
	 * @return float Total revenue.
	 */
	public function get_revenue_by_period( $start, $end ) {
		$cache_key = 'wcet_revenue_' . md5( $start . $end );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return (float) $cached;
		}

		global $wpdb;

		$use_hpos = $this->is_hpos_enabled();

		if ( $use_hpos ) {
			$table = $wpdb->prefix . 'wc_orders';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$revenue = $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(total_amount), 0)
				FROM {$table}
				WHERE type = 'shop_order'
				AND status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
				AND date_created_gmt BETWEEN %s AND %s",
				$start,
				$end
			) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$revenue = $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(pm.meta_value), 0)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
				AND pm.meta_key = '_order_total'
				AND p.post_date BETWEEN %s AND %s",
				$start,
				$end
			) );
		}

		$result = (float) $revenue;

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Get orders grouped by status for a given period.
	 *
	 * @since 1.0.0
	 *
	 * @param string $period Period identifier: 'today', 'yesterday', 'this_week', 'this_month', 'last_30_days', 'ytd'.
	 * @return array Associative array of status => count.
	 */
	public function get_orders_by_status( $period ) {
		$dates = $this->resolve_period_dates( $period );
		$start = $dates['start'];
		$end   = $dates['end'];

		$cache_key = 'wcet_orders_status_' . md5( $start . $end );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$use_hpos = $this->is_hpos_enabled();

		if ( $use_hpos ) {
			$table = $wpdb->prefix . 'wc_orders';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT status, COUNT(*) as count
				FROM {$table}
				WHERE type = 'shop_order'
				AND date_created_gmt BETWEEN %s AND %s
				GROUP BY status",
				$start,
				$end
			), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT post_status as status, COUNT(*) as count
				FROM {$wpdb->posts}
				WHERE post_type = 'shop_order'
				AND post_date BETWEEN %s AND %s
				GROUP BY post_status",
				$start,
				$end
			), ARRAY_A );
		}

		$data = array();
		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$clean_status          = str_replace( 'wc-', '', $row['status'] );
				$data[ $clean_status ] = (int) $row['count'];
			}
		}

		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Get top selling products for a period.
	 *
	 * @since 1.0.0
	 *
	 * @param int    $limit  Number of products to return.
	 * @param string $period Period identifier.
	 * @return array Top products with id, name, quantity, and revenue.
	 */
	public function get_top_products( $limit = 10, $period = 'last_30_days' ) {
		$dates = $this->resolve_period_dates( $period );
		$start = $dates['start'];
		$end   = $dates['end'];
		$limit = absint( $limit );

		$cache_key = 'wcet_top_products_' . md5( $start . $end . $limit );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$order_items_table    = $wpdb->prefix . 'woocommerce_order_items';
		$order_itemmeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';
		$use_hpos             = $this->is_hpos_enabled();

		if ( $use_hpos ) {
			$orders_table = $wpdb->prefix . 'wc_orders';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT
					oim_product.meta_value AS product_id,
					SUM(oim_qty.meta_value) AS total_quantity,
					SUM(oim_total.meta_value) AS total_revenue
				FROM {$order_items_table} oi
				INNER JOIN {$orders_table} o ON oi.order_id = o.id
				INNER JOIN {$order_itemmeta_table} oim_product ON oi.order_item_id = oim_product.order_item_id
					AND oim_product.meta_key = '_product_id'
				INNER JOIN {$order_itemmeta_table} oim_qty ON oi.order_item_id = oim_qty.order_item_id
					AND oim_qty.meta_key = '_qty'
				INNER JOIN {$order_itemmeta_table} oim_total ON oi.order_item_id = oim_total.order_item_id
					AND oim_total.meta_key = '_line_total'
				WHERE o.type = 'shop_order'
				AND o.status IN ('wc-completed', 'wc-processing')
				AND o.date_created_gmt BETWEEN %s AND %s
				AND oi.order_item_type = 'line_item'
				GROUP BY oim_product.meta_value
				ORDER BY total_quantity DESC
				LIMIT %d",
				$start,
				$end,
				$limit
			), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT
					oim_product.meta_value AS product_id,
					SUM(oim_qty.meta_value) AS total_quantity,
					SUM(oim_total.meta_value) AS total_revenue
				FROM {$order_items_table} oi
				INNER JOIN {$wpdb->posts} p ON oi.order_id = p.ID
				INNER JOIN {$order_itemmeta_table} oim_product ON oi.order_item_id = oim_product.order_item_id
					AND oim_product.meta_key = '_product_id'
				INNER JOIN {$order_itemmeta_table} oim_qty ON oi.order_item_id = oim_qty.order_item_id
					AND oim_qty.meta_key = '_qty'
				INNER JOIN {$order_itemmeta_table} oim_total ON oi.order_item_id = oim_total.order_item_id
					AND oim_total.meta_key = '_line_total'
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-completed', 'wc-processing')
				AND p.post_date BETWEEN %s AND %s
				AND oi.order_item_type = 'line_item'
				GROUP BY oim_product.meta_value
				ORDER BY total_quantity DESC
				LIMIT %d",
				$start,
				$end,
				$limit
			), ARRAY_A );
		}

		$products = array();
		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$product_id = (int) $row['product_id'];
				$product    = wc_get_product( $product_id );

				$products[] = array(
					'id'       => $product_id,
					'name'     => $product ? $product->get_name() : __( '(Deleted Product)', 'wc-enterprise-toolkit' ),
					'sku'      => $product ? $product->get_sku() : '',
					'quantity' => (int) $row['total_quantity'],
					'revenue'  => (float) $row['total_revenue'],
				);
			}
		}

		wp_cache_set( $cache_key, $products, self::CACHE_GROUP, self::CACHE_TTL );

		return $products;
	}

	/**
	 * Get customer statistics for a period.
	 *
	 * @since 1.0.0
	 *
	 * @param string $period Period identifier.
	 * @return array Customer stats including new, returning, and totals.
	 */
	public function get_customer_stats( $period ) {
		$dates = $this->resolve_period_dates( $period );
		$start = $dates['start'];
		$end   = $dates['end'];

		$cache_key = 'wcet_customer_stats_' . md5( $start . $end );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = $this->get_table_name();

		// Count new vs returning customers from our analytics table.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new_customers = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$table}
			WHERE metric_name = 'customer_acquisition'
			AND recorded_at BETWEEN %s AND %s
			AND dimensions LIKE %s",
			$start,
			$end,
			'%"customer_type":"new"%'
		) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$returning_customers = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$table}
			WHERE metric_name = 'customer_acquisition'
			AND recorded_at BETWEEN %s AND %s
			AND dimensions LIKE %s",
			$start,
			$end,
			'%"customer_type":"returning"%'
		) );

		// Get average order value for the period.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$avg_order_value = $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(AVG(metric_value), 0)
			FROM {$table}
			WHERE metric_name = 'average_order_value'
			AND recorded_at BETWEEN %s AND %s",
			$start,
			$end
		) );

		// Get total unique customers (by WC orders).
		$use_hpos = $this->is_hpos_enabled();

		if ( $use_hpos ) {
			$orders_table = $wpdb->prefix . 'wc_orders';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total_unique = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT customer_id)
				FROM {$orders_table}
				WHERE type = 'shop_order'
				AND status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
				AND date_created_gmt BETWEEN %s AND %s
				AND customer_id > 0",
				$start,
				$end
			) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total_unique = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(DISTINCT pm.meta_value)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
				AND pm.meta_key = '_customer_user'
				AND pm.meta_value > 0
				AND p.post_date BETWEEN %s AND %s",
				$start,
				$end
			) );
		}

		$stats = array(
			'new_customers'       => (int) $new_customers,
			'returning_customers' => (int) $returning_customers,
			'total_unique'        => (int) $total_unique,
			'average_order_value' => (float) $avg_order_value,
			'acquisition_rate'    => 0,
		);

		$total_customers = $stats['new_customers'] + $stats['returning_customers'];
		if ( $total_customers > 0 ) {
			$stats['acquisition_rate'] = ( $stats['new_customers'] / $total_customers ) * 100;
		}

		wp_cache_set( $cache_key, $stats, self::CACHE_GROUP, self::CACHE_TTL );

		return $stats;
	}

	/**
	 * Get conversion funnel data for a period.
	 *
	 * @since 1.0.0
	 *
	 * @param string $period Period identifier.
	 * @return array Funnel stages with counts and conversion rates.
	 */
	public function get_conversion_funnel( $period ) {
		$dates = $this->resolve_period_dates( $period );
		$start = $dates['start'];
		$end   = $dates['end'];

		$cache_key = 'wcet_conversion_funnel_' . md5( $start . $end );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table = $this->get_table_name();

		// Cart activity (add to cart events).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cart_sessions = $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT
				JSON_UNQUOTE(JSON_EXTRACT(dimensions, '$.session_id'))
			)
			FROM {$table}
			WHERE metric_name = 'cart_activity'
			AND recorded_at BETWEEN %s AND %s",
			$start,
			$end
		) );

		// Completed orders.
		$use_hpos     = $this->is_hpos_enabled();
		$order_states = array( 'wc-completed', 'wc-processing', 'wc-on-hold' );

		if ( $use_hpos ) {
			$orders_table = $wpdb->prefix . 'wc_orders';
			$placeholders = implode( ', ', array_fill( 0, count( $order_states ), '%s' ) );

			$params   = array_merge( array( $start, $end ), $order_states );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$completed_orders = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$orders_table}
				WHERE type = 'shop_order'
				AND date_created_gmt BETWEEN %s AND %s
				AND status IN ({$placeholders})",
				$params
			) );
		} else {
			$placeholders = implode( ', ', array_fill( 0, count( $order_states ), '%s' ) );
			$params       = array_merge( array( $start, $end ), $order_states );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$completed_orders = $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->posts}
				WHERE post_type = 'shop_order'
				AND post_date BETWEEN %s AND %s
				AND post_status IN ({$placeholders})",
				$params
			) );
		}

		$cart_sessions    = max( 1, (int) $cart_sessions );
		$completed_orders = (int) $completed_orders;

		$funnel = array(
			'cart_sessions'      => $cart_sessions,
			'completed_orders'   => $completed_orders,
			'conversion_rate'    => ( $cart_sessions > 0 ) ? round( ( $completed_orders / $cart_sessions ) * 100, 2 ) : 0,
			'abandonment_rate'   => ( $cart_sessions > 0 ) ? round( ( ( $cart_sessions - $completed_orders ) / $cart_sessions ) * 100, 2 ) : 0,
		);

		wp_cache_set( $cache_key, $funnel, self::CACHE_GROUP, self::CACHE_TTL );

		return $funnel;
	}

	/**
	 * Get revenue breakdown by payment method for a period.
	 *
	 * @since 1.0.0
	 *
	 * @param string $start Start datetime.
	 * @param string $end   End datetime.
	 * @return array Payment methods with revenue totals.
	 */
	public function get_revenue_by_payment_method( $start, $end ) {
		$cache_key = 'wcet_revenue_payment_' . md5( $start . $end );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$use_hpos = $this->is_hpos_enabled();

		if ( $use_hpos ) {
			$orders_table = $wpdb->prefix . 'wc_orders';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT payment_method, payment_method_title,
					COUNT(*) as order_count,
					COALESCE(SUM(total_amount), 0) as total_revenue
				FROM {$orders_table}
				WHERE type = 'shop_order'
				AND status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
				AND date_created_gmt BETWEEN %s AND %s
				GROUP BY payment_method, payment_method_title
				ORDER BY total_revenue DESC",
				$start,
				$end
			), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT
					pm_method.meta_value as payment_method,
					pm_title.meta_value as payment_method_title,
					COUNT(*) as order_count,
					COALESCE(SUM(pm_total.meta_value), 0) as total_revenue
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm_method ON p.ID = pm_method.post_id
					AND pm_method.meta_key = '_payment_method'
				LEFT JOIN {$wpdb->postmeta} pm_title ON p.ID = pm_title.post_id
					AND pm_title.meta_key = '_payment_method_title'
				INNER JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id
					AND pm_total.meta_key = '_order_total'
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
				AND p.post_date BETWEEN %s AND %s
				GROUP BY pm_method.meta_value, pm_title.meta_value
				ORDER BY total_revenue DESC",
				$start,
				$end
			), ARRAY_A );
		}

		$methods = array();
		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$methods[] = array(
					'method'       => $row['payment_method'],
					'title'        => ! empty( $row['payment_method_title'] ) ? $row['payment_method_title'] : $row['payment_method'],
					'order_count'  => (int) $row['order_count'],
					'total_revenue' => (float) $row['total_revenue'],
				);
			}
		}

		wp_cache_set( $cache_key, $methods, self::CACHE_GROUP, self::CACHE_TTL );

		return $methods;
	}

	/**
	 * Render the analytics admin page.
	 *
	 * @since 1.0.0
	 */
	public function render_analytics_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-enterprise-toolkit' ) );
		}

		$default_start = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$default_end   = gmdate( 'Y-m-d' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$start = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : $default_start;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$end   = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : $default_end;

		// Validate date format.
		if ( ! $this->validate_date_format( $start ) ) {
			$start = $default_start;
		}
		if ( ! $this->validate_date_format( $end ) ) {
			$end = $default_end;
		}

		$start_dt = $start . ' 00:00:00';
		$end_dt   = $end . ' 23:59:59';

		$revenue          = $this->get_revenue_by_period( $start_dt, $end_dt );
		$orders_by_status = $this->get_orders_by_status( 'custom' );
		$top_products     = $this->get_top_products( 10, 'custom' );
		$customer_stats   = $this->get_customer_stats( 'custom' );
		$conversion       = $this->get_conversion_funnel( 'custom' );
		$payment_methods  = $this->get_revenue_by_payment_method( $start_dt, $end_dt );

		// Prepare chart data as JSON for JS rendering.
		$chart_data = array(
			'revenue'         => array(
				'total'   => $revenue,
				'daily'   => $this->get_daily_revenue_series( $start_dt, $end_dt ),
			),
			'orders'          => $orders_by_status,
			'top_products'    => $top_products,
			'customers'       => $customer_stats,
			'conversion'      => $conversion,
			'payment_methods' => $payment_methods,
		);

		?>
		<div class="wrap wcet-analytics-wrap">
			<h1><?php esc_html_e( 'Sales Analytics', 'wc-enterprise-toolkit' ); ?></h1>

			<div class="wcet-analytics-filters">
				<form method="get" action="">
					<input type="hidden" name="page" value="wcet-analytics" />
					<label for="wcet-start-date"><?php esc_html_e( 'From:', 'wc-enterprise-toolkit' ); ?></label>
					<input type="text" id="wcet-start-date" name="start_date" class="wcet-datepicker"
						value="<?php echo esc_attr( $start ); ?>" autocomplete="off" />

					<label for="wcet-end-date"><?php esc_html_e( 'To:', 'wc-enterprise-toolkit' ); ?></label>
					<input type="text" id="wcet-end-date" name="end_date" class="wcet-datepicker"
						value="<?php echo esc_attr( $end ); ?>" autocomplete="off" />

					<?php submit_button( __( 'Filter', 'wc-enterprise-toolkit' ), 'primary', 'filter', false ); ?>

					<button type="button" class="button" id="wcet-analytics-export" data-start="<?php echo esc_attr( $start ); ?>" data-end="<?php echo esc_attr( $end ); ?>">
						<span class="dashicons dashicons-download"></span>
						<?php esc_html_e( 'Export CSV', 'wc-enterprise-toolkit' ); ?>
					</button>
				</form>
			</div>

			<!-- Revenue Overview -->
			<div class="wcet-card">
				<h2><?php esc_html_e( 'Revenue Overview', 'wc-enterprise-toolkit' ); ?></h2>
				<div class="wcet-revenue-total">
					<span class="wcet-big-number"><?php echo wp_kses_post( wc_price( $revenue ) ); ?></span>
					<span class="description"><?php esc_html_e( 'Total Revenue', 'wc-enterprise-toolkit' ); ?></span>
				</div>
				<div id="wcet-revenue-chart" data-chart="<?php echo esc_attr( wp_json_encode( $chart_data['revenue'] ) ); ?>">
					<p class="description"><?php esc_html_e( 'Revenue chart data loaded. JavaScript required for rendering.', 'wc-enterprise-toolkit' ); ?></p>
				</div>
			</div>

			<!-- Payment Methods Breakdown -->
			<div class="wcet-card">
				<h2><?php esc_html_e( 'Revenue by Payment Method', 'wc-enterprise-toolkit' ); ?></h2>
				<?php if ( empty( $payment_methods ) ) : ?>
					<p><?php esc_html_e( 'No payment data available for this period.', 'wc-enterprise-toolkit' ); ?></p>
				<?php else : ?>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Payment Method', 'wc-enterprise-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Orders', 'wc-enterprise-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Revenue', 'wc-enterprise-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Share', 'wc-enterprise-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $payment_methods as $method ) : ?>
								<tr>
									<td><?php echo esc_html( $method['title'] ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $method['order_count'] ) ); ?></td>
									<td><?php echo wp_kses_post( wc_price( $method['total_revenue'] ) ); ?></td>
									<td>
										<?php
										$share = ( $revenue > 0 ) ? ( $method['total_revenue'] / $revenue ) * 100 : 0;
										echo esc_html( number_format( $share, 1 ) . '%' );
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<div class="wcet-analytics-grid">
				<!-- Top Products -->
				<div class="wcet-card">
					<h2><?php esc_html_e( 'Top Products', 'wc-enterprise-toolkit' ); ?></h2>
					<div id="wcet-products-chart" data-chart="<?php echo esc_attr( wp_json_encode( $chart_data['top_products'] ) ); ?>"></div>
					<?php if ( ! empty( $top_products ) ) : ?>
						<table class="wp-list-table widefat fixed striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Product', 'wc-enterprise-toolkit' ); ?></th>
									<th><?php esc_html_e( 'Qty', 'wc-enterprise-toolkit' ); ?></th>
									<th><?php esc_html_e( 'Revenue', 'wc-enterprise-toolkit' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $top_products as $product ) : ?>
									<tr>
										<td><?php echo esc_html( $product['name'] ); ?></td>
										<td><?php echo esc_html( number_format_i18n( $product['quantity'] ) ); ?></td>
										<td><?php echo wp_kses_post( wc_price( $product['revenue'] ) ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php else : ?>
						<p><?php esc_html_e( 'No product data available.', 'wc-enterprise-toolkit' ); ?></p>
					<?php endif; ?>
				</div>

				<!-- Customer Stats -->
				<div class="wcet-card">
					<h2><?php esc_html_e( 'Customer Acquisition', 'wc-enterprise-toolkit' ); ?></h2>
					<div id="wcet-customers-chart" data-chart="<?php echo esc_attr( wp_json_encode( $chart_data['customers'] ) ); ?>"></div>
					<table class="wp-list-table widefat fixed striped">
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'New Customers', 'wc-enterprise-toolkit' ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $customer_stats['new_customers'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Returning Customers', 'wc-enterprise-toolkit' ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $customer_stats['returning_customers'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Unique Customers', 'wc-enterprise-toolkit' ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $customer_stats['total_unique'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Average Order Value', 'wc-enterprise-toolkit' ); ?></strong></td>
								<td><?php echo wp_kses_post( wc_price( $customer_stats['average_order_value'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'New Customer Rate', 'wc-enterprise-toolkit' ); ?></strong></td>
								<td><?php echo esc_html( number_format( $customer_stats['acquisition_rate'], 1 ) . '%' ); ?></td>
							</tr>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Conversion Funnel -->
			<div class="wcet-card">
				<h2><?php esc_html_e( 'Conversion Funnel', 'wc-enterprise-toolkit' ); ?></h2>
				<div id="wcet-funnel-chart" data-chart="<?php echo esc_attr( wp_json_encode( $chart_data['conversion'] ) ); ?>"></div>
				<div class="wcet-funnel-stats">
					<div class="wcet-funnel-stat">
						<span class="wcet-funnel-label"><?php esc_html_e( 'Cart Sessions', 'wc-enterprise-toolkit' ); ?></span>
						<span class="wcet-funnel-value"><?php echo esc_html( number_format_i18n( $conversion['cart_sessions'] ) ); ?></span>
					</div>
					<div class="wcet-funnel-stat">
						<span class="wcet-funnel-label"><?php esc_html_e( 'Completed Orders', 'wc-enterprise-toolkit' ); ?></span>
						<span class="wcet-funnel-value"><?php echo esc_html( number_format_i18n( $conversion['completed_orders'] ) ); ?></span>
					</div>
					<div class="wcet-funnel-stat">
						<span class="wcet-funnel-label"><?php esc_html_e( 'Conversion Rate', 'wc-enterprise-toolkit' ); ?></span>
						<span class="wcet-funnel-value"><?php echo esc_html( $conversion['conversion_rate'] . '%' ); ?></span>
					</div>
					<div class="wcet-funnel-stat">
						<span class="wcet-funnel-label"><?php esc_html_e( 'Cart Abandonment', 'wc-enterprise-toolkit' ); ?></span>
						<span class="wcet-funnel-value"><?php echo esc_html( $conversion['abandonment_rate'] . '%' ); ?></span>
					</div>
				</div>
			</div>

			<!-- All chart data as JSON for JS consumption -->
			<script type="application/json" id="wcet-analytics-chart-data">
				<?php echo wp_json_encode( $chart_data ); ?>
			</script>
		</div>
		<?php
	}

	/**
	 * AJAX handler: Export analytics data as CSV.
	 *
	 * @since 1.0.0
	 */
	public function ajax_export_csv() {
		check_ajax_referer( 'wcet_analytics_export', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-enterprise-toolkit' ) ) );
		}

		$start = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$end   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : gmdate( 'Y-m-d' );

		if ( ! $this->validate_date_format( $start ) || ! $this->validate_date_format( $end ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date format.', 'wc-enterprise-toolkit' ) ) );
		}

		$start_dt = $start . ' 00:00:00';
		$end_dt   = $end . ' 23:59:59';

		$daily_revenue    = $this->get_daily_revenue_series( $start_dt, $end_dt );
		$top_products     = $this->get_top_products( 50, 'custom' );
		$customer_stats   = $this->get_customer_stats( 'custom' );
		$payment_methods  = $this->get_revenue_by_payment_method( $start_dt, $end_dt );

		$csv = '';

		// Revenue by day.
		$csv .= "\"Daily Revenue\"\n";
		$csv .= "\"Date\",\"Revenue\"\n";
		foreach ( $daily_revenue as $day ) {
			$csv .= '"' . $day['date'] . '","' . $day['revenue'] . "\"\n";
		}

		$csv .= "\n\"Top Products\"\n";
		$csv .= "\"Product\",\"SKU\",\"Quantity\",\"Revenue\"\n";
		foreach ( $top_products as $product ) {
			$csv .= '"' . str_replace( '"', '""', $product['name'] ) . '","'
				. str_replace( '"', '""', $product['sku'] ) . '","'
				. $product['quantity'] . '","'
				. $product['revenue'] . "\"\n";
		}

		$csv .= "\n\"Customer Stats\"\n";
		$csv .= "\"Metric\",\"Value\"\n";
		$csv .= "\"New Customers\",\"" . $customer_stats['new_customers'] . "\"\n";
		$csv .= "\"Returning Customers\",\"" . $customer_stats['returning_customers'] . "\"\n";
		$csv .= "\"Unique Customers\",\"" . $customer_stats['total_unique'] . "\"\n";
		$csv .= "\"Average Order Value\",\"" . $customer_stats['average_order_value'] . "\"\n";

		$csv .= "\n\"Payment Methods\"\n";
		$csv .= "\"Method\",\"Orders\",\"Revenue\"\n";
		foreach ( $payment_methods as $method ) {
			$csv .= '"' . str_replace( '"', '""', $method['title'] ) . '","'
				. $method['order_count'] . '","'
				. $method['total_revenue'] . "\"\n";
		}

		wp_send_json_success( array(
			'filename' => 'analytics-' . $start . '-to-' . $end . '.csv',
			'content'  => $csv,
		) );
	}

	/**
	 * AJAX handler: Get analytics data as JSON.
	 *
	 * @since 1.0.0
	 */
	public function ajax_get_analytics_data() {
		check_ajax_referer( 'wcet_analytics_data', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-enterprise-toolkit' ) ) );
		}

		$start = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );
		$end   = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) : gmdate( 'Y-m-d' );

		if ( ! $this->validate_date_format( $start ) || ! $this->validate_date_format( $end ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid date format.', 'wc-enterprise-toolkit' ) ) );
		}

		$start_dt = $start . ' 00:00:00';
		$end_dt   = $end . ' 23:59:59';

		wp_send_json_success( array(
			'revenue'         => array(
				'total' => $this->get_revenue_by_period( $start_dt, $end_dt ),
				'daily' => $this->get_daily_revenue_series( $start_dt, $end_dt ),
			),
			'orders'          => $this->get_orders_by_status( 'custom' ),
			'top_products'    => $this->get_top_products( 10, 'custom' ),
			'customers'       => $this->get_customer_stats( 'custom' ),
			'conversion'      => $this->get_conversion_funnel( 'custom' ),
			'payment_methods' => $this->get_revenue_by_payment_method( $start_dt, $end_dt ),
		) );
	}

	/**
	 * Get daily revenue as a time series for charting.
	 *
	 * @since 1.0.0
	 *
	 * @param string $start Start datetime.
	 * @param string $end   End datetime.
	 * @return array Daily revenue data points.
	 */
	private function get_daily_revenue_series( $start, $end ) {
		$cache_key = 'wcet_daily_revenue_' . md5( $start . $end );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$use_hpos = $this->is_hpos_enabled();

		if ( $use_hpos ) {
			$table = $wpdb->prefix . 'wc_orders';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE(date_created_gmt) as order_date,
					COALESCE(SUM(total_amount), 0) as daily_revenue
				FROM {$table}
				WHERE type = 'shop_order'
				AND status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
				AND date_created_gmt BETWEEN %s AND %s
				GROUP BY DATE(date_created_gmt)
				ORDER BY order_date ASC",
				$start,
				$end
			), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$results = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE(p.post_date) as order_date,
					COALESCE(SUM(pm.meta_value), 0) as daily_revenue
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
				WHERE p.post_type = 'shop_order'
				AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
				AND pm.meta_key = '_order_total'
				AND p.post_date BETWEEN %s AND %s
				GROUP BY DATE(p.post_date)
				ORDER BY order_date ASC",
				$start,
				$end
			), ARRAY_A );
		}

		// Fill in missing dates with zero revenue.
		$revenue_by_date = array();
		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$revenue_by_date[ $row['order_date'] ] = (float) $row['daily_revenue'];
			}
		}

		$series     = array();
		$start_date = new DateTime( $start );
		$end_date   = new DateTime( $end );
		$interval   = new DateInterval( 'P1D' );
		$period     = new DatePeriod( $start_date, $interval, $end_date->modify( '+1 day' ) );

		foreach ( $period as $date ) {
			$date_str = $date->format( 'Y-m-d' );
			$series[] = array(
				'date'    => $date_str,
				'revenue' => isset( $revenue_by_date[ $date_str ] ) ? $revenue_by_date[ $date_str ] : 0,
			);
		}

		wp_cache_set( $cache_key, $series, self::CACHE_GROUP, self::CACHE_TTL );

		return $series;
	}

	/**
	 * Compute cart abandonment rate for a date range.
	 *
	 * @since 1.0.0
	 *
	 * @param string $start Start datetime.
	 * @param string $end   End datetime.
	 * @return float Abandonment rate as a percentage.
	 */
	private function compute_cart_abandonment_rate( $start, $end ) {
		global $wpdb;

		$table = $this->get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cart_sessions = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT
				JSON_UNQUOTE(JSON_EXTRACT(dimensions, '$.session_id'))
			)
			FROM {$table}
			WHERE metric_name = 'cart_activity'
			AND recorded_at BETWEEN %s AND %s",
			$start,
			$end
		) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$completed = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$table}
			WHERE metric_name = 'orders_count'
			AND recorded_at BETWEEN %s AND %s",
			$start,
			$end
		) );

		if ( $cart_sessions <= 0 ) {
			return 0.0;
		}

		$abandoned = max( 0, $cart_sessions - $completed );
		return round( ( $abandoned / $cart_sessions ) * 100, 2 );
	}

	/**
	 * Compute conversion rate for a date range.
	 *
	 * @since 1.0.0
	 *
	 * @param string $start Start datetime.
	 * @param string $end   End datetime.
	 * @return float Conversion rate as a percentage.
	 */
	private function compute_conversion_rate( $start, $end ) {
		global $wpdb;

		$table = $this->get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cart_sessions = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(DISTINCT
				JSON_UNQUOTE(JSON_EXTRACT(dimensions, '$.session_id'))
			)
			FROM {$table}
			WHERE metric_name = 'cart_activity'
			AND recorded_at BETWEEN %s AND %s",
			$start,
			$end
		) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$completed = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*)
			FROM {$table}
			WHERE metric_name = 'orders_count'
			AND recorded_at BETWEEN %s AND %s",
			$start,
			$end
		) );

		if ( $cart_sessions <= 0 ) {
			return 0.0;
		}

		return round( ( $completed / $cart_sessions ) * 100, 2 );
	}

	/**
	 * Check if a customer is new (first order).
	 *
	 * @since 1.0.0
	 *
	 * @param int $customer_id  The customer user ID.
	 * @param int $order_id     The current order ID to exclude from the check.
	 * @return bool True if this is the customer's first order.
	 */
	private function is_new_customer( $customer_id, $order_id ) {
		if ( 0 === $customer_id ) {
			return true; // Guest customer, treat as new.
		}

		$previous_orders = wc_get_orders( array(
			'customer_id' => $customer_id,
			'limit'       => 1,
			'exclude'     => array( $order_id ),
			'status'      => array( 'wc-completed', 'wc-processing', 'wc-on-hold' ),
			'return'      => 'ids',
		) );

		return empty( $previous_orders );
	}

	/**
	 * Resolve period identifier to start and end date strings.
	 *
	 * @since 1.0.0
	 *
	 * @param string $period Period identifier or 'custom'.
	 * @return array Associative array with 'start' and 'end' datetime strings.
	 */
	private function resolve_period_dates( $period ) {
		$now = current_time( 'timestamp' );

		switch ( $period ) {
			case 'today':
				return array(
					'start' => current_time( 'Y-m-d' ) . ' 00:00:00',
					'end'   => current_time( 'Y-m-d H:i:s' ),
				);

			case 'yesterday':
				$yesterday = gmdate( 'Y-m-d', strtotime( '-1 day', $now ) );
				return array(
					'start' => $yesterday . ' 00:00:00',
					'end'   => $yesterday . ' 23:59:59',
				);

			case 'this_week':
				return array(
					'start' => gmdate( 'Y-m-d', strtotime( 'monday this week', $now ) ) . ' 00:00:00',
					'end'   => current_time( 'Y-m-d H:i:s' ),
				);

			case 'this_month':
				return array(
					'start' => current_time( 'Y-m' ) . '-01 00:00:00',
					'end'   => current_time( 'Y-m-d H:i:s' ),
				);

			case 'last_30_days':
				return array(
					'start' => gmdate( 'Y-m-d', strtotime( '-30 days', $now ) ) . ' 00:00:00',
					'end'   => current_time( 'Y-m-d H:i:s' ),
				);

			case 'ytd':
				return array(
					'start' => current_time( 'Y' ) . '-01-01 00:00:00',
					'end'   => current_time( 'Y-m-d H:i:s' ),
				);

			case 'custom':
			default:
				// For 'custom', use GET params or default to last 30 days.
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$start = isset( $_GET['start_date'] ) ? sanitize_text_field( wp_unslash( $_GET['start_date'] ) ) : gmdate( 'Y-m-d', strtotime( '-30 days', $now ) );
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$end   = isset( $_GET['end_date'] ) ? sanitize_text_field( wp_unslash( $_GET['end_date'] ) ) : gmdate( 'Y-m-d', $now );

				if ( ! $this->validate_date_format( $start ) ) {
					$start = gmdate( 'Y-m-d', strtotime( '-30 days', $now ) );
				}
				if ( ! $this->validate_date_format( $end ) ) {
					$end = gmdate( 'Y-m-d', $now );
				}

				return array(
					'start' => $start . ' 00:00:00',
					'end'   => $end . ' 23:59:59',
				);
		}
	}

	/**
	 * Validate a date string is in Y-m-d format.
	 *
	 * @since 1.0.0
	 *
	 * @param string $date Date string to validate.
	 * @return bool True if valid.
	 */
	private function validate_date_format( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Check if WooCommerce HPOS is enabled.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if HPOS is enabled.
	 */
	private function is_hpos_enabled() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}
		return false;
	}

	/**
	 * Flush all analytics cache entries.
	 *
	 * @since 1.0.0
	 */
	private function flush_analytics_cache() {
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		}
	}
}
