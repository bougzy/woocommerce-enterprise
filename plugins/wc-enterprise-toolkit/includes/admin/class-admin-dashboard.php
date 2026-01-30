<?php
/**
 * Enterprise Store Admin Dashboard.
 *
 * Provides a comprehensive admin dashboard for store performance overview,
 * revenue summaries, order management, stock alerts, and quick actions.
 *
 * @package    WC_Enterprise_Toolkit
 * @subpackage Admin
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCET_Admin_Dashboard
 *
 * Singleton class that creates and manages the Enterprise Store admin dashboard.
 *
 * @since 1.0.0
 */
class WCET_Admin_Dashboard {

	/**
	 * Single instance of this class.
	 *
	 * @var WCET_Admin_Dashboard|null
	 */
	private static $instance = null;

	/**
	 * Cache group for dashboard data.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'wcet_dashboard';

	/**
	 * Cache TTL in seconds (5 minutes).
	 *
	 * @var int
	 */
	const CACHE_TTL = 300;

	/**
	 * Low stock threshold default.
	 *
	 * @var int
	 */
	const LOW_STOCK_THRESHOLD = 5;

	/**
	 * Returns the single instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCET_Admin_Dashboard
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
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'wp_ajax_wcet_export_orders_csv', array( $this, 'ajax_export_orders_csv' ) );
		add_action( 'wp_ajax_wcet_clear_dashboard_cache', array( $this, 'ajax_clear_cache' ) );
		add_action( 'wp_ajax_wcet_run_reindex', array( $this, 'ajax_run_reindex' ) );
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
	 * Register the top-level admin menu and dashboard page.
	 *
	 * @since 1.0.0
	 */
	public function register_menu() {
		add_menu_page(
			__( 'Enterprise Store', 'wc-enterprise-toolkit' ),
			__( 'Enterprise Store', 'wc-enterprise-toolkit' ),
			'manage_woocommerce',
			'wcet-dashboard',
			array( $this, 'render_dashboard_page' ),
			'dashicons-store',
			3
		);

		add_submenu_page(
			'wcet-dashboard',
			__( 'Dashboard', 'wc-enterprise-toolkit' ),
			__( 'Dashboard', 'wc-enterprise-toolkit' ),
			'manage_woocommerce',
			'wcet-dashboard',
			array( $this, 'render_dashboard_page' )
		);
	}

	/**
	 * Enqueue admin assets for the dashboard page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_wcet-dashboard' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wcet-dashboard',
			WCET_PLUGIN_URL . 'assets/css/admin-dashboard.css',
			array(),
			WCET_VERSION
		);

		wp_enqueue_script(
			'wcet-dashboard',
			WCET_PLUGIN_URL . 'assets/js/admin-dashboard.js',
			array( 'jquery', 'wp-util' ),
			WCET_VERSION,
			true
		);

		wp_localize_script( 'wcet-dashboard', 'wcetDashboard', array(
			'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
			'exportNonce'      => wp_create_nonce( 'wcet_export_orders' ),
			'clearCacheNonce'  => wp_create_nonce( 'wcet_clear_cache' ),
			'reindexNonce'     => wp_create_nonce( 'wcet_run_reindex' ),
			'orderStatusChart' => $this->get_order_status_distribution(),
		) );
	}

	/**
	 * Render the main dashboard page.
	 *
	 * @since 1.0.0
	 */
	public function render_dashboard_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-enterprise-toolkit' ) );
		}

		$revenue_data      = $this->get_revenue_summary();
		$orders_summary    = $this->get_orders_summary();
		$top_products      = $this->get_top_selling_products();
		$recent_orders     = $this->get_recent_orders();
		$low_stock_alerts  = $this->get_low_stock_alerts();
		$status_chart_data = $this->get_order_status_distribution();
		$cache_health      = $this->get_cache_health_status();

		?>
		<div class="wrap wcet-dashboard-wrap">
			<h1><?php esc_html_e( 'Enterprise Store Dashboard', 'wc-enterprise-toolkit' ); ?></h1>
			<p class="description">
				<?php
				printf(
					/* translators: %s: last updated time */
					esc_html__( 'Dashboard data cached for 5 minutes. Last refreshed: %s', 'wc-enterprise-toolkit' ),
					esc_html( current_time( 'H:i:s' ) )
				);
				?>
			</p>

			<?php $this->render_revenue_cards( $revenue_data ); ?>
			<?php $this->render_orders_summary( $orders_summary ); ?>
			<?php $this->render_cache_health( $cache_health ); ?>

			<div class="wcet-dashboard-columns">
				<div class="wcet-column wcet-column-wide">
					<?php $this->render_top_products_table( $top_products ); ?>
					<?php $this->render_recent_orders_table( $recent_orders ); ?>
				</div>
				<div class="wcet-column wcet-column-narrow">
					<?php $this->render_order_status_chart( $status_chart_data ); ?>
					<?php $this->render_low_stock_alerts( $low_stock_alerts ); ?>
					<?php $this->render_quick_actions(); ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render revenue summary cards.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Revenue data with current and previous period values.
	 */
	private function render_revenue_cards( $data ) {
		?>
		<div class="wcet-revenue-cards">
			<?php
			$periods = array(
				'today'      => __( 'Today', 'wc-enterprise-toolkit' ),
				'this_week'  => __( 'This Week', 'wc-enterprise-toolkit' ),
				'this_month' => __( 'This Month', 'wc-enterprise-toolkit' ),
				'ytd'        => __( 'Year to Date', 'wc-enterprise-toolkit' ),
			);

			foreach ( $periods as $key => $label ) :
				$current  = isset( $data[ $key ]['current'] ) ? (float) $data[ $key ]['current'] : 0;
				$previous = isset( $data[ $key ]['previous'] ) ? (float) $data[ $key ]['previous'] : 0;
				$change   = $this->calculate_percentage_change( $current, $previous );
				$trend    = $change >= 0 ? 'up' : 'down';
				?>
				<div class="wcet-card wcet-revenue-card">
					<h3 class="wcet-card-title"><?php echo esc_html( $label ); ?></h3>
					<div class="wcet-card-value">
						<?php echo wp_kses_post( wc_price( $current ) ); ?>
					</div>
					<div class="wcet-card-change wcet-trend-<?php echo esc_attr( $trend ); ?>">
						<span class="dashicons dashicons-arrow-<?php echo esc_attr( $trend ); ?>-alt"></span>
						<?php
						printf(
							/* translators: %s: percentage change */
							esc_html__( '%s%% vs previous period', 'wc-enterprise-toolkit' ),
							esc_html( number_format( abs( $change ), 1 ) )
						);
						?>
					</div>
					<div class="wcet-card-previous">
						<?php
						printf(
							/* translators: %s: previous period revenue */
							esc_html__( 'Previous: %s', 'wc-enterprise-toolkit' ),
							wp_kses_post( wc_price( $previous ) )
						);
						?>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render orders summary section.
	 *
	 * @since 1.0.0
	 *
	 * @param array $data Orders summary data by status.
	 */
	private function render_orders_summary( $data ) {
		?>
		<div class="wcet-orders-summary">
			<h2><?php esc_html_e( 'Orders Summary', 'wc-enterprise-toolkit' ); ?></h2>
			<div class="wcet-status-cards">
				<?php foreach ( $data as $status => $count ) : ?>
					<div class="wcet-card wcet-status-card wcet-status-<?php echo esc_attr( sanitize_html_class( $status ) ); ?>">
						<span class="wcet-status-label"><?php echo esc_html( ucwords( str_replace( '-', ' ', $status ) ) ); ?></span>
						<span class="wcet-status-count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render cache health status section.
	 *
	 * @since 1.0.0
	 *
	 * @param array $health Cache health data.
	 */
	private function render_cache_health( $health ) {
		$status_class = 'healthy';
		if ( isset( $health['hit_ratio'] ) && $health['hit_ratio'] < 50 ) {
			$status_class = 'warning';
		}
		if ( isset( $health['hit_ratio'] ) && $health['hit_ratio'] < 20 ) {
			$status_class = 'critical';
		}
		?>
		<div class="wcet-cache-health wcet-cache-<?php echo esc_attr( $status_class ); ?>">
			<h2><?php esc_html_e( 'Cache Health', 'wc-enterprise-toolkit' ); ?></h2>
			<div class="wcet-cache-stats">
				<span class="wcet-cache-stat">
					<strong><?php esc_html_e( 'Backend:', 'wc-enterprise-toolkit' ); ?></strong>
					<?php echo esc_html( isset( $health['backend'] ) ? $health['backend'] : __( 'Unknown', 'wc-enterprise-toolkit' ) ); ?>
				</span>
				<span class="wcet-cache-stat">
					<strong><?php esc_html_e( 'Hit Ratio:', 'wc-enterprise-toolkit' ); ?></strong>
					<?php echo esc_html( isset( $health['hit_ratio'] ) ? number_format( $health['hit_ratio'], 1 ) . '%' : __( 'N/A', 'wc-enterprise-toolkit' ) ); ?>
				</span>
				<span class="wcet-cache-stat">
					<strong><?php esc_html_e( 'Hits:', 'wc-enterprise-toolkit' ); ?></strong>
					<?php echo esc_html( isset( $health['hits'] ) ? number_format_i18n( $health['hits'] ) : '0' ); ?>
				</span>
				<span class="wcet-cache-stat">
					<strong><?php esc_html_e( 'Misses:', 'wc-enterprise-toolkit' ); ?></strong>
					<?php echo esc_html( isset( $health['misses'] ) ? number_format_i18n( $health['misses'] ) : '0' ); ?>
				</span>
			</div>
		</div>
		<?php
	}

	/**
	 * Render top selling products table.
	 *
	 * @since 1.0.0
	 *
	 * @param array $products Top selling products data.
	 */
	private function render_top_products_table( $products ) {
		?>
		<div class="wcet-card wcet-top-products">
			<h2><?php esc_html_e( 'Top Selling Products (Last 30 Days)', 'wc-enterprise-toolkit' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" class="column-product"><?php esc_html_e( 'Product', 'wc-enterprise-toolkit' ); ?></th>
						<th scope="col" class="column-sku"><?php esc_html_e( 'SKU', 'wc-enterprise-toolkit' ); ?></th>
						<th scope="col" class="column-quantity"><?php esc_html_e( 'Qty Sold', 'wc-enterprise-toolkit' ); ?></th>
						<th scope="col" class="column-revenue"><?php esc_html_e( 'Revenue', 'wc-enterprise-toolkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $products ) ) : ?>
						<tr>
							<td colspan="4"><?php esc_html_e( 'No sales data available for this period.', 'wc-enterprise-toolkit' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $products as $product ) : ?>
							<tr>
								<td class="column-product">
									<?php if ( ! empty( $product['edit_url'] ) ) : ?>
										<a href="<?php echo esc_url( $product['edit_url'] ); ?>">
											<?php echo esc_html( $product['name'] ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( $product['name'] ); ?>
									<?php endif; ?>
								</td>
								<td class="column-sku"><?php echo esc_html( $product['sku'] ); ?></td>
								<td class="column-quantity"><?php echo esc_html( number_format_i18n( $product['quantity'] ) ); ?></td>
								<td class="column-revenue"><?php echo wp_kses_post( wc_price( $product['revenue'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render recent orders table.
	 *
	 * @since 1.0.0
	 *
	 * @param array $orders Recent orders data.
	 */
	private function render_recent_orders_table( $orders ) {
		?>
		<div class="wcet-card wcet-recent-orders">
			<h2><?php esc_html_e( 'Recent Orders', 'wc-enterprise-toolkit' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th scope="col" class="column-order"><?php esc_html_e( 'Order', 'wc-enterprise-toolkit' ); ?></th>
						<th scope="col" class="column-date"><?php esc_html_e( 'Date', 'wc-enterprise-toolkit' ); ?></th>
						<th scope="col" class="column-status"><?php esc_html_e( 'Status', 'wc-enterprise-toolkit' ); ?></th>
						<th scope="col" class="column-customer"><?php esc_html_e( 'Customer', 'wc-enterprise-toolkit' ); ?></th>
						<th scope="col" class="column-total"><?php esc_html_e( 'Total', 'wc-enterprise-toolkit' ); ?></th>
						<th scope="col" class="column-payment"><?php esc_html_e( 'Payment', 'wc-enterprise-toolkit' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $orders ) ) : ?>
						<tr>
							<td colspan="6"><?php esc_html_e( 'No recent orders found.', 'wc-enterprise-toolkit' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $orders as $order ) : ?>
							<tr>
								<td class="column-order">
									<a href="<?php echo esc_url( $order['edit_url'] ); ?>">
										<?php
										printf(
											/* translators: %s: order number */
											esc_html__( '#%s', 'wc-enterprise-toolkit' ),
											esc_html( $order['number'] )
										);
										?>
									</a>
								</td>
								<td class="column-date"><?php echo esc_html( $order['date'] ); ?></td>
								<td class="column-status">
									<mark class="order-status status-<?php echo esc_attr( sanitize_html_class( $order['status'] ) ); ?>">
										<span><?php echo esc_html( $order['status_label'] ); ?></span>
									</mark>
								</td>
								<td class="column-customer"><?php echo esc_html( $order['customer'] ); ?></td>
								<td class="column-total"><?php echo wp_kses_post( $order['total'] ); ?></td>
								<td class="column-payment"><?php echo esc_html( $order['payment_method'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Render order status distribution chart data container.
	 *
	 * Outputs chart data as JSON for JavaScript rendering.
	 *
	 * @since 1.0.0
	 *
	 * @param array $chart_data Order status distribution data.
	 */
	private function render_order_status_chart( $chart_data ) {
		?>
		<div class="wcet-card wcet-status-chart">
			<h2><?php esc_html_e( 'Order Status Distribution', 'wc-enterprise-toolkit' ); ?></h2>
			<div id="wcet-order-status-chart" data-chart="<?php echo esc_attr( wp_json_encode( $chart_data ) ); ?>">
				<p class="description"><?php esc_html_e( 'Chart requires JavaScript to render.', 'wc-enterprise-toolkit' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render low stock alerts section.
	 *
	 * @since 1.0.0
	 *
	 * @param array $alerts Low stock product alerts.
	 */
	private function render_low_stock_alerts( $alerts ) {
		?>
		<div class="wcet-card wcet-low-stock-alerts">
			<h2>
				<?php esc_html_e( 'Low Stock Alerts', 'wc-enterprise-toolkit' ); ?>
				<?php if ( ! empty( $alerts ) ) : ?>
					<span class="wcet-alert-count"><?php echo esc_html( count( $alerts ) ); ?></span>
				<?php endif; ?>
			</h2>
			<?php if ( empty( $alerts ) ) : ?>
				<p class="wcet-no-alerts"><?php esc_html_e( 'All products are sufficiently stocked.', 'wc-enterprise-toolkit' ); ?></p>
			<?php else : ?>
				<ul class="wcet-alert-list">
					<?php foreach ( $alerts as $alert ) : ?>
						<li class="wcet-alert-item <?php echo ( 0 === (int) $alert['stock'] ) ? 'wcet-out-of-stock' : 'wcet-low-stock'; ?>">
							<a href="<?php echo esc_url( $alert['edit_url'] ); ?>">
								<?php echo esc_html( $alert['name'] ); ?>
							</a>
							<span class="wcet-stock-qty">
								<?php
								printf(
									/* translators: %d: stock quantity */
									esc_html__( 'Stock: %d', 'wc-enterprise-toolkit' ),
									(int) $alert['stock']
								);
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render quick action buttons.
	 *
	 * @since 1.0.0
	 */
	private function render_quick_actions() {
		?>
		<div class="wcet-card wcet-quick-actions">
			<h2><?php esc_html_e( 'Quick Actions', 'wc-enterprise-toolkit' ); ?></h2>
			<div class="wcet-action-buttons">
				<button type="button" class="button button-primary wcet-action-btn" id="wcet-export-orders" data-action="wcet_export_orders_csv">
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Export Orders CSV', 'wc-enterprise-toolkit' ); ?>
				</button>
				<button type="button" class="button wcet-action-btn" id="wcet-clear-cache" data-action="wcet_clear_dashboard_cache">
					<span class="dashicons dashicons-trash"></span>
					<?php esc_html_e( 'Clear Cache', 'wc-enterprise-toolkit' ); ?>
				</button>
				<button type="button" class="button wcet-action-btn" id="wcet-run-reindex" data-action="wcet_run_reindex">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Run Reindex', 'wc-enterprise-toolkit' ); ?>
				</button>
			</div>
			<div id="wcet-action-feedback" class="hidden"></div>
		</div>
		<?php
	}

	/**
	 * Get revenue summary for all periods.
	 *
	 * @since 1.0.0
	 *
	 * @return array Revenue data with current and previous period values.
	 */
	private function get_revenue_summary() {
		$cache_key = 'wcet_revenue_summary';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$now             = current_time( 'mysql' );
		$today_start     = current_time( 'Y-m-d' ) . ' 00:00:00';
		$yesterday_start = gmdate( 'Y-m-d', strtotime( '-1 day', current_time( 'timestamp' ) ) ) . ' 00:00:00';
		$yesterday_end   = gmdate( 'Y-m-d', strtotime( '-1 day', current_time( 'timestamp' ) ) ) . ' 23:59:59';

		$week_start      = gmdate( 'Y-m-d', strtotime( 'monday this week', current_time( 'timestamp' ) ) ) . ' 00:00:00';
		$prev_week_start = gmdate( 'Y-m-d', strtotime( 'monday last week', current_time( 'timestamp' ) ) ) . ' 00:00:00';
		$prev_week_end   = gmdate( 'Y-m-d', strtotime( 'sunday last week', current_time( 'timestamp' ) ) ) . ' 23:59:59';

		$month_start      = current_time( 'Y-m' ) . '-01 00:00:00';
		$prev_month_start = gmdate( 'Y-m', strtotime( 'first day of last month', current_time( 'timestamp' ) ) ) . '-01 00:00:00';
		$prev_month_end   = gmdate( 'Y-m-t', strtotime( 'last month', current_time( 'timestamp' ) ) ) . ' 23:59:59';

		$year_start      = current_time( 'Y' ) . '-01-01 00:00:00';
		$prev_year_start = ( (int) current_time( 'Y' ) - 1 ) . '-01-01 00:00:00';
		$prev_year_end   = ( (int) current_time( 'Y' ) - 1 ) . '-12-31 23:59:59';

		$orders_table = $wpdb->prefix . 'wc_orders';
		$meta_table   = $wpdb->prefix . 'wc_orders_meta';

		// Check if HPOS tables exist; fall back to posts if not.
		$use_hpos = $this->is_hpos_enabled();

		$data = array(
			'today'      => array(
				'current'  => $this->query_revenue( $today_start, $now, $use_hpos ),
				'previous' => $this->query_revenue( $yesterday_start, $yesterday_end, $use_hpos ),
			),
			'this_week'  => array(
				'current'  => $this->query_revenue( $week_start, $now, $use_hpos ),
				'previous' => $this->query_revenue( $prev_week_start, $prev_week_end, $use_hpos ),
			),
			'this_month' => array(
				'current'  => $this->query_revenue( $month_start, $now, $use_hpos ),
				'previous' => $this->query_revenue( $prev_month_start, $prev_month_end, $use_hpos ),
			),
			'ytd'        => array(
				'current'  => $this->query_revenue( $year_start, $now, $use_hpos ),
				'previous' => $this->query_revenue( $prev_year_start, $prev_year_end, $use_hpos ),
			),
		);

		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Query total revenue for a date range.
	 *
	 * @since 1.0.0
	 *
	 * @param string $start    Start datetime string.
	 * @param string $end      End datetime string.
	 * @param bool   $use_hpos Whether to use HPOS tables.
	 * @return float Total revenue.
	 */
	private function query_revenue( $start, $end, $use_hpos ) {
		global $wpdb;

		if ( $use_hpos ) {
			$table = $wpdb->prefix . 'wc_orders';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(total_amount), 0) FROM {$table}
				WHERE type = 'shop_order'
				AND status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
				AND date_created_gmt BETWEEN %s AND %s",
				$start,
				$end
			) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$result = $wpdb->get_var( $wpdb->prepare(
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

		return (float) $result;
	}

	/**
	 * Check if WooCommerce HPOS (High-Performance Order Storage) is enabled.
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
	 * Get orders summary by status.
	 *
	 * @since 1.0.0
	 *
	 * @return array Associative array of status => count.
	 */
	private function get_orders_summary() {
		$cache_key = 'wcet_orders_summary';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$use_hpos = $this->is_hpos_enabled();

		if ( $use_hpos ) {
			$table = $wpdb->prefix . 'wc_orders';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$results = $wpdb->get_results(
				"SELECT status, COUNT(*) as count
				FROM {$table}
				WHERE type = 'shop_order'
				GROUP BY status
				ORDER BY count DESC",
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$results = $wpdb->get_results(
				"SELECT post_status as status, COUNT(*) as count
				FROM {$wpdb->posts}
				WHERE post_type = 'shop_order'
				GROUP BY post_status
				ORDER BY count DESC",
				ARRAY_A
			);
		}

		$summary = array();
		$statuses = wc_get_order_statuses();

		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$status_key   = $row['status'];
				$display_name = isset( $statuses[ $status_key ] ) ? $statuses[ $status_key ] : str_replace( 'wc-', '', $status_key );
				$clean_key    = str_replace( 'wc-', '', $status_key );
				$summary[ $clean_key ] = (int) $row['count'];
			}
		}

		// Ensure core statuses are always present.
		$core_statuses = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
		foreach ( $core_statuses as $status ) {
			if ( ! isset( $summary[ $status ] ) ) {
				$summary[ $status ] = 0;
			}
		}

		wp_cache_set( $cache_key, $summary, self::CACHE_GROUP, self::CACHE_TTL );

		return $summary;
	}

	/**
	 * Get top selling products for the last 30 days.
	 *
	 * @since 1.0.0
	 *
	 * @return array Top 10 selling products.
	 */
	private function get_top_selling_products() {
		$cache_key = 'wcet_top_products_30d';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$thirty_days_ago = gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) );
		$use_hpos        = $this->is_hpos_enabled();

		$order_items_table     = $wpdb->prefix . 'woocommerce_order_items';
		$order_itemmeta_table  = $wpdb->prefix . 'woocommerce_order_itemmeta';

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
				AND o.date_created_gmt >= %s
				AND oi.order_item_type = 'line_item'
				GROUP BY oim_product.meta_value
				ORDER BY total_quantity DESC
				LIMIT 10",
				$thirty_days_ago
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
				AND p.post_date >= %s
				AND oi.order_item_type = 'line_item'
				GROUP BY oim_product.meta_value
				ORDER BY total_quantity DESC
				LIMIT 10",
				$thirty_days_ago
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
					'edit_url' => $product ? get_edit_post_link( $product_id, 'raw' ) : '',
				);
			}
		}

		wp_cache_set( $cache_key, $products, self::CACHE_GROUP, self::CACHE_TTL );

		return $products;
	}

	/**
	 * Get the 20 most recent orders.
	 *
	 * @since 1.0.0
	 *
	 * @return array Recent orders data.
	 */
	private function get_recent_orders() {
		$cache_key = 'wcet_recent_orders_20';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$orders = wc_get_orders( array(
			'limit'   => 20,
			'orderby' => 'date',
			'order'   => 'DESC',
			'type'    => 'shop_order',
		) );

		$data = array();

		foreach ( $orders as $order ) {
			$data[] = array(
				'id'             => $order->get_id(),
				'number'         => $order->get_order_number(),
				'date'           => $order->get_date_created() ? $order->get_date_created()->date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '',
				'status'         => $order->get_status(),
				'status_label'   => wc_get_order_status_name( $order->get_status() ),
				'customer'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'total'          => $order->get_formatted_order_total(),
				'payment_method' => $order->get_payment_method_title(),
				'edit_url'       => $order->get_edit_order_url(),
			);
		}

		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, self::CACHE_TTL );

		return $data;
	}

	/**
	 * Get low stock alerts.
	 *
	 * @since 1.0.0
	 *
	 * @return array Products with stock below threshold.
	 */
	private function get_low_stock_alerts() {
		$cache_key = 'wcet_low_stock_alerts';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$threshold = (int) apply_filters( 'wcet_low_stock_threshold', self::LOW_STOCK_THRESHOLD );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$results = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title,
				pm_stock.meta_value AS stock_quantity,
				pm_sku.meta_value AS sku
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm_stock ON p.ID = pm_stock.post_id
				AND pm_stock.meta_key = '_stock'
			LEFT JOIN {$wpdb->postmeta} pm_sku ON p.ID = pm_sku.post_id
				AND pm_sku.meta_key = '_sku'
			INNER JOIN {$wpdb->postmeta} pm_manage ON p.ID = pm_manage.post_id
				AND pm_manage.meta_key = '_manage_stock'
				AND pm_manage.meta_value = 'yes'
			WHERE p.post_type IN ('product', 'product_variation')
			AND p.post_status = 'publish'
			AND CAST(pm_stock.meta_value AS SIGNED) <= %d
			AND CAST(pm_stock.meta_value AS SIGNED) >= 0
			ORDER BY CAST(pm_stock.meta_value AS SIGNED) ASC
			LIMIT 50",
			$threshold
		), ARRAY_A );

		$alerts = array();

		if ( ! empty( $results ) ) {
			foreach ( $results as $row ) {
				$alerts[] = array(
					'id'       => (int) $row['ID'],
					'name'     => $row['post_title'],
					'sku'      => $row['sku'],
					'stock'    => (int) $row['stock_quantity'],
					'edit_url' => get_edit_post_link( (int) $row['ID'], 'raw' ),
				);
			}
		}

		wp_cache_set( $cache_key, $alerts, self::CACHE_GROUP, self::CACHE_TTL );

		return $alerts;
	}

	/**
	 * Get order status distribution for chart rendering.
	 *
	 * @since 1.0.0
	 *
	 * @return array Chart-ready data with labels, counts, and colors.
	 */
	private function get_order_status_distribution() {
		$cache_key = 'wcet_order_status_chart';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$summary = $this->get_orders_summary();

		$status_colors = array(
			'pending'    => '#f0ad4e',
			'processing' => '#5bc0de',
			'on-hold'    => '#777777',
			'completed'  => '#5cb85c',
			'cancelled'  => '#d9534f',
			'refunded'   => '#999999',
			'failed'     => '#d9534f',
		);

		$chart_data = array(
			'labels'  => array(),
			'data'    => array(),
			'colors'  => array(),
		);

		foreach ( $summary as $status => $count ) {
			if ( 0 === $count ) {
				continue;
			}
			$chart_data['labels'][] = ucwords( str_replace( '-', ' ', $status ) );
			$chart_data['data'][]   = $count;
			$chart_data['colors'][] = isset( $status_colors[ $status ] ) ? $status_colors[ $status ] : '#cccccc';
		}

		wp_cache_set( $cache_key, $chart_data, self::CACHE_GROUP, self::CACHE_TTL );

		return $chart_data;
	}

	/**
	 * Get cache health status.
	 *
	 * @since 1.0.0
	 *
	 * @return array Cache health metrics.
	 */
	private function get_cache_health_status() {
		$health = array(
			'backend'   => __( 'Default', 'wc-enterprise-toolkit' ),
			'hit_ratio' => 0,
			'hits'      => 0,
			'misses'    => 0,
		);

		// Try to get stats from WCET_Object_Cache if available.
		if ( class_exists( 'WCET_Object_Cache' ) && method_exists( 'WCET_Object_Cache', 'get_instance' ) ) {
			$object_cache = WCET_Object_Cache::get_instance();

			if ( method_exists( $object_cache, 'get_stats' ) ) {
				$stats = $object_cache->get_stats();

				$health['hits']    = isset( $stats['hits'] ) ? (int) $stats['hits'] : 0;
				$health['misses']  = isset( $stats['misses'] ) ? (int) $stats['misses'] : 0;
				$health['backend'] = isset( $stats['backend'] ) ? $stats['backend'] : __( 'Default', 'wc-enterprise-toolkit' );

				$total = $health['hits'] + $health['misses'];
				if ( $total > 0 ) {
					$health['hit_ratio'] = ( $health['hits'] / $total ) * 100;
				}
			}
		} else {
			// Fallback: detect object cache backend from global.
			global $wp_object_cache;

			if ( isset( $wp_object_cache->cache_hits ) && isset( $wp_object_cache->cache_misses ) ) {
				$health['hits']   = (int) $wp_object_cache->cache_hits;
				$health['misses'] = (int) $wp_object_cache->cache_misses;

				$total = $health['hits'] + $health['misses'];
				if ( $total > 0 ) {
					$health['hit_ratio'] = ( $health['hits'] / $total ) * 100;
				}
			}

			if ( wp_using_ext_object_cache() ) {
				if ( class_exists( 'Redis' ) ) {
					$health['backend'] = 'Redis';
				} elseif ( class_exists( 'Memcached' ) ) {
					$health['backend'] = 'Memcached';
				} else {
					$health['backend'] = __( 'External', 'wc-enterprise-toolkit' );
				}
			}
		}

		return $health;
	}

	/**
	 * Calculate the percentage change between two values.
	 *
	 * @since 1.0.0
	 *
	 * @param float $current  Current period value.
	 * @param float $previous Previous period value.
	 * @return float Percentage change.
	 */
	private function calculate_percentage_change( $current, $previous ) {
		if ( 0.0 === (float) $previous ) {
			return ( $current > 0 ) ? 100.0 : 0.0;
		}
		return ( ( $current - $previous ) / $previous ) * 100;
	}

	/**
	 * AJAX handler: Export orders as CSV.
	 *
	 * @since 1.0.0
	 */
	public function ajax_export_orders_csv() {
		check_ajax_referer( 'wcet_export_orders', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-enterprise-toolkit' ) ) );
		}

		$days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 30;
		$days = min( $days, 365 ); // Cap at one year.

		global $wpdb;

		$use_hpos = $this->is_hpos_enabled();
		$since    = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		if ( $use_hpos ) {
			$table = $wpdb->prefix . 'wc_orders';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$table}
				WHERE type = 'shop_order'
				AND date_created_gmt >= %s
				ORDER BY date_created_gmt DESC",
				$since
			) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$order_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'shop_order'
				AND post_date >= %s
				ORDER BY post_date DESC",
				$since
			) );
		}

		$rows   = array();
		$header = array(
			'Order ID',
			'Date',
			'Status',
			'Customer',
			'Email',
			'Total',
			'Payment Method',
			'Items Count',
		);

		$rows[] = $header;

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order ) {
				continue;
			}

			$rows[] = array(
				$order->get_order_number(),
				$order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
				$order->get_status(),
				trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				$order->get_billing_email(),
				$order->get_total(),
				$order->get_payment_method_title(),
				$order->get_item_count(),
			);
		}

		$csv_content = '';
		foreach ( $rows as $row ) {
			$csv_content .= $this->csv_encode_row( $row ) . "\n";
		}

		wp_send_json_success( array(
			'filename' => 'orders-export-' . gmdate( 'Y-m-d-His' ) . '.csv',
			'content'  => $csv_content,
			'count'    => count( $order_ids ),
		) );
	}

	/**
	 * AJAX handler: Clear dashboard cache.
	 *
	 * @since 1.0.0
	 */
	public function ajax_clear_cache() {
		check_ajax_referer( 'wcet_clear_cache', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-enterprise-toolkit' ) ) );
		}

		$cache_keys = array(
			'wcet_revenue_summary',
			'wcet_orders_summary',
			'wcet_top_products_30d',
			'wcet_recent_orders_20',
			'wcet_low_stock_alerts',
			'wcet_order_status_chart',
		);

		foreach ( $cache_keys as $key ) {
			wp_cache_delete( $key, self::CACHE_GROUP );
		}

		// Also clear WCET_Object_Cache if available.
		if ( class_exists( 'WCET_Object_Cache' ) && method_exists( 'WCET_Object_Cache', 'get_instance' ) ) {
			$object_cache = WCET_Object_Cache::get_instance();
			if ( method_exists( $object_cache, 'flush_group' ) ) {
				$object_cache->flush_group( self::CACHE_GROUP );
			}
		}

		wp_send_json_success( array(
			'message' => __( 'Dashboard cache cleared successfully.', 'wc-enterprise-toolkit' ),
		) );
	}

	/**
	 * AJAX handler: Run product reindex.
	 *
	 * @since 1.0.0
	 */
	public function ajax_run_reindex() {
		check_ajax_referer( 'wcet_run_reindex', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-enterprise-toolkit' ) ) );
		}

		// Trigger WooCommerce product lookup table regeneration.
		if ( function_exists( 'wc_update_product_lookup_tables_is_running' ) ) {
			if ( ! wc_update_product_lookup_tables_is_running() ) {
				wc_update_product_lookup_tables();
			}
		}

		// Trigger WooCommerce order count update.
		$statuses = wc_get_order_statuses();
		foreach ( array_keys( $statuses ) as $status ) {
			$count = wc_orders_count( str_replace( 'wc-', '', $status ) );
			update_option( "woocommerce_{$status}_count", $count );
		}

		/**
		 * Fires after the WCET reindex operation completes.
		 *
		 * @since 1.0.0
		 */
		do_action( 'wcet_after_reindex' );

		wp_send_json_success( array(
			'message' => __( 'Reindex operation completed successfully.', 'wc-enterprise-toolkit' ),
		) );
	}

	/**
	 * Encode a row of data as a CSV string.
	 *
	 * @since 1.0.0
	 *
	 * @param array $row Array of column values.
	 * @return string CSV-encoded row.
	 */
	private function csv_encode_row( $row ) {
		$encoded = array();
		foreach ( $row as $field ) {
			$field     = str_replace( '"', '""', (string) $field );
			$encoded[] = '"' . $field . '"';
		}
		return implode( ',', $encoded );
	}
}
