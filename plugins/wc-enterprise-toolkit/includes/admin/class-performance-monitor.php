<?php
/**
 * Performance Monitoring.
 *
 * Tracks page load times, database queries, memory usage, cache hit ratios,
 * and provides a comprehensive performance reporting dashboard with scoring.
 *
 * @package    WC_Enterprise_Toolkit
 * @subpackage Admin
 * @since      1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WCET_Performance_Monitor
 *
 * Singleton class that tracks performance metrics and exposes a monitoring dashboard.
 *
 * @since 1.0.0
 */
class WCET_Performance_Monitor {

	/**
	 * Single instance of this class.
	 *
	 * @var WCET_Performance_Monitor|null
	 */
	private static $instance = null;

	/**
	 * Timestamp when request processing began.
	 *
	 * @var float
	 */
	private $request_start_time = 0;

	/**
	 * Cache group for performance data.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'wcet_performance';

	/**
	 * Cache TTL in seconds (5 minutes).
	 *
	 * @var int
	 */
	const CACHE_TTL = 300;

	/**
	 * Analytics table suffix.
	 *
	 * @var string
	 */
	const TABLE_SUFFIX = 'wcet_analytics';

	/**
	 * Returns the single instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCET_Performance_Monitor
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
		$this->request_start_time = defined( 'WCET_REQUEST_START' )
			? WCET_REQUEST_START
			: ( isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : microtime( true ) );

		// Frontend timing capture.
		add_action( 'wp_footer', array( $this, 'capture_page_load_timing' ), 9999 );

		// Admin menu.
		add_action( 'admin_menu', array( $this, 'register_admin_page' ) );

		// Snapshot recording on shutdown.
		add_action( 'shutdown', array( $this, 'record_performance_snapshot' ) );

		// Admin bar indicator.
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_indicator' ), 999 );
		add_action( 'wp_head', array( $this, 'admin_bar_styles' ) );
		add_action( 'admin_head', array( $this, 'admin_bar_styles' ) );

		// AJAX endpoint for running a performance test.
		add_action( 'wp_ajax_wcet_run_performance_test', array( $this, 'ajax_run_performance_test' ) );

		// Admin assets.
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
	 * Get the analytics table name.
	 *
	 * @since  1.0.0
	 * @return string Full table name with prefix.
	 */
	private function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Register the performance monitor admin submenu page.
	 *
	 * @since 1.0.0
	 */
	public function register_admin_page() {
		add_submenu_page(
			'wcet-dashboard',
			__( 'Performance Monitor', 'wc-enterprise-toolkit' ),
			__( 'Performance', 'wc-enterprise-toolkit' ),
			'manage_woocommerce',
			'wcet-performance',
			array( $this, 'render_performance_page' )
		);
	}

	/**
	 * Enqueue admin assets for the performance page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 */
	public function enqueue_assets( $hook_suffix ) {
		if ( 'enterprise-store_page_wcet-performance' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wcet-performance',
			WCET_PLUGIN_URL . 'assets/css/admin-performance.css',
			array(),
			WCET_VERSION
		);

		wp_enqueue_script(
			'wcet-performance',
			WCET_PLUGIN_URL . 'assets/js/admin-performance.js',
			array( 'jquery', 'wp-util' ),
			WCET_VERSION,
			true
		);

		wp_localize_script( 'wcet-performance', 'wcetPerformance', array(
			'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
			'testNonce' => wp_create_nonce( 'wcet_performance_test' ),
		) );
	}

	/**
	 * Output JavaScript timing capture in wp_footer.
	 *
	 * Records server-side page generation time as a data attribute and sets up
	 * a beacon to record full page load time client-side.
	 *
	 * @since 1.0.0
	 */
	public function capture_page_load_timing() {
		if ( is_admin() ) {
			return;
		}

		$server_time = $this->get_elapsed_time();
		?>
		<script type="text/javascript">
			(function() {
				var serverTime = <?php echo esc_js( number_format( $server_time, 4 ) ); ?>;
				if ( window.performance && window.performance.timing ) {
					window.addEventListener('load', function() {
						setTimeout(function() {
							var timing = window.performance.timing;
							var pageLoad = (timing.loadEventEnd - timing.navigationStart) / 1000;
							var domReady = (timing.domContentLoadedEventEnd - timing.navigationStart) / 1000;

							document.documentElement.setAttribute('data-wcet-server-time', serverTime);
							document.documentElement.setAttribute('data-wcet-page-load', pageLoad);
							document.documentElement.setAttribute('data-wcet-dom-ready', domReady);
						}, 0);
					});
				}
			})();
		</script>
		<?php
	}

	/**
	 * Record a performance snapshot to the analytics table on shutdown.
	 *
	 * Only records for a sampled percentage of requests to reduce overhead.
	 *
	 * @since 1.0.0
	 */
	public function record_performance_snapshot() {
		// Sample 10% of requests to avoid excessive writes.
		if ( wp_rand( 1, 10 ) > 1 ) {
			return;
		}

		// Do not record for AJAX, cron, or CLI.
		if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}

		$metrics = $this->collect_current_metrics();

		global $wpdb;

		$table = $this->get_table_name();

		$wpdb->insert(
			$table,
			array(
				'metric_name'  => 'performance',
				'metric_value' => $metrics['page_load_time'],
				'dimensions'   => wp_json_encode( $metrics ),
				'recorded_at'  => current_time( 'mysql' ),
			),
			array( '%s', '%f', '%s', '%s' )
		);
	}

	/**
	 * Collect current performance metrics.
	 *
	 * @since 1.0.0
	 *
	 * @return array Performance metrics.
	 */
	private function collect_current_metrics() {
		global $wpdb;

		$elapsed = $this->get_elapsed_time();

		return array(
			'page_load_time'   => round( $elapsed, 4 ),
			'db_query_count'   => $this->get_db_query_count(),
			'memory_usage'     => memory_get_usage( true ),
			'memory_peak'      => memory_get_peak_usage( true ),
			'cache_hit_ratio'  => $this->get_cache_hit_ratio(),
			'active_hooks'     => $this->count_active_hooks(),
			'request_uri'      => isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '',
			'request_method'   => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '',
			'is_admin'         => is_admin() ? 1 : 0,
		);
	}

	/**
	 * Get the elapsed time since request start.
	 *
	 * @since 1.0.0
	 *
	 * @return float Elapsed time in seconds.
	 */
	private function get_elapsed_time() {
		return microtime( true ) - $this->request_start_time;
	}

	/**
	 * Get the number of database queries executed.
	 *
	 * @since 1.0.0
	 *
	 * @return int Query count.
	 */
	private function get_db_query_count() {
		global $wpdb;
		return (int) $wpdb->num_queries;
	}

	/**
	 * Get the cache hit ratio as a percentage.
	 *
	 * @since 1.0.0
	 *
	 * @return float Cache hit ratio.
	 */
	private function get_cache_hit_ratio() {
		global $wp_object_cache;

		$hits   = 0;
		$misses = 0;

		// Try WCET_Object_Cache first.
		if ( class_exists( 'WCET_Object_Cache' ) && method_exists( 'WCET_Object_Cache', 'get_instance' ) ) {
			$cache = WCET_Object_Cache::get_instance();
			if ( method_exists( $cache, 'get_stats' ) ) {
				$stats  = $cache->get_stats();
				$hits   = isset( $stats['hits'] ) ? (int) $stats['hits'] : 0;
				$misses = isset( $stats['misses'] ) ? (int) $stats['misses'] : 0;
			}
		} elseif ( isset( $wp_object_cache->cache_hits, $wp_object_cache->cache_misses ) ) {
			$hits   = (int) $wp_object_cache->cache_hits;
			$misses = (int) $wp_object_cache->cache_misses;
		}

		$total = $hits + $misses;
		if ( $total <= 0 ) {
			return 0.0;
		}

		return round( ( $hits / $total ) * 100, 2 );
	}

	/**
	 * Count the number of active hooks (filters + actions).
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of registered hooks.
	 */
	private function count_active_hooks() {
		global $wp_filter;

		if ( ! is_array( $wp_filter ) ) {
			return 0;
		}

		return count( $wp_filter );
	}

	/**
	 * Render the performance monitoring admin page.
	 *
	 * @since 1.0.0
	 */
	public function render_performance_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-enterprise-toolkit' ) );
		}

		$current_metrics  = $this->collect_current_metrics();
		$avg_metrics      = $this->get_average_metrics();
		$db_stats         = $this->get_database_stats();
		$cache_stats      = $this->get_detailed_cache_stats();
		$hook_report      = $this->get_hook_optimization_report();
		$env_info         = $this->get_environment_info();
		$performance_score = $this->calculate_performance_score( $current_metrics, $avg_metrics );
		$recommendations  = $this->get_recommendations( $current_metrics, $avg_metrics, $env_info );

		?>
		<div class="wrap wcet-performance-wrap">
			<h1><?php esc_html_e( 'Performance Monitor', 'wc-enterprise-toolkit' ); ?></h1>

			<!-- Performance Score -->
			<?php $this->render_performance_score( $performance_score ); ?>

			<!-- Current Metrics -->
			<div class="wcet-perf-section">
				<h2><?php esc_html_e( 'Current Performance Metrics', 'wc-enterprise-toolkit' ); ?></h2>
				<div class="wcet-metrics-grid">
					<div class="wcet-metric-card">
						<span class="wcet-metric-label"><?php esc_html_e( 'Page Load Time', 'wc-enterprise-toolkit' ); ?></span>
						<span class="wcet-metric-value"><?php echo esc_html( number_format( $current_metrics['page_load_time'], 3 ) ); ?>s</span>
					</div>
					<div class="wcet-metric-card">
						<span class="wcet-metric-label"><?php esc_html_e( 'Database Queries', 'wc-enterprise-toolkit' ); ?></span>
						<span class="wcet-metric-value"><?php echo esc_html( number_format_i18n( $current_metrics['db_query_count'] ) ); ?></span>
					</div>
					<div class="wcet-metric-card">
						<span class="wcet-metric-label"><?php esc_html_e( 'Memory Usage', 'wc-enterprise-toolkit' ); ?></span>
						<span class="wcet-metric-value"><?php echo esc_html( size_format( $current_metrics['memory_usage'] ) ); ?></span>
					</div>
					<div class="wcet-metric-card">
						<span class="wcet-metric-label"><?php esc_html_e( 'Peak Memory', 'wc-enterprise-toolkit' ); ?></span>
						<span class="wcet-metric-value"><?php echo esc_html( size_format( $current_metrics['memory_peak'] ) ); ?></span>
					</div>
					<div class="wcet-metric-card">
						<span class="wcet-metric-label"><?php esc_html_e( 'Cache Hit Ratio', 'wc-enterprise-toolkit' ); ?></span>
						<span class="wcet-metric-value"><?php echo esc_html( number_format( $current_metrics['cache_hit_ratio'], 1 ) ); ?>%</span>
					</div>
					<div class="wcet-metric-card">
						<span class="wcet-metric-label"><?php esc_html_e( 'Active Hooks', 'wc-enterprise-toolkit' ); ?></span>
						<span class="wcet-metric-value"><?php echo esc_html( number_format_i18n( $current_metrics['active_hooks'] ) ); ?></span>
					</div>
				</div>
			</div>

			<!-- Average Page Load Times -->
			<div class="wcet-perf-section">
				<h2><?php esc_html_e( 'Average Page Load Times', 'wc-enterprise-toolkit' ); ?></h2>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Period', 'wc-enterprise-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Avg Load Time', 'wc-enterprise-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Avg Queries', 'wc-enterprise-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Avg Memory', 'wc-enterprise-toolkit' ); ?></th>
							<th><?php esc_html_e( 'Samples', 'wc-enterprise-toolkit' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$periods = array(
							'24h' => __( 'Last 24 Hours', 'wc-enterprise-toolkit' ),
							'7d'  => __( 'Last 7 Days', 'wc-enterprise-toolkit' ),
							'30d' => __( 'Last 30 Days', 'wc-enterprise-toolkit' ),
						);
						foreach ( $periods as $key => $label ) :
							$data = isset( $avg_metrics[ $key ] ) ? $avg_metrics[ $key ] : array();
							?>
							<tr>
								<td><?php echo esc_html( $label ); ?></td>
								<td>
									<?php
									echo esc_html(
										isset( $data['avg_load_time'] )
											? number_format( $data['avg_load_time'], 3 ) . 's'
											: __( 'N/A', 'wc-enterprise-toolkit' )
									);
									?>
								</td>
								<td>
									<?php
									echo esc_html(
										isset( $data['avg_queries'] )
											? number_format( $data['avg_queries'], 0 )
											: __( 'N/A', 'wc-enterprise-toolkit' )
									);
									?>
								</td>
								<td>
									<?php
									echo esc_html(
										isset( $data['avg_memory'] )
											? size_format( $data['avg_memory'] )
											: __( 'N/A', 'wc-enterprise-toolkit' )
									);
									?>
								</td>
								<td>
									<?php
									echo esc_html(
										isset( $data['sample_count'] )
											? number_format_i18n( $data['sample_count'] )
											: '0'
									);
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="wcet-perf-columns">
				<!-- Database Query Stats -->
				<div class="wcet-perf-section">
					<h2><?php esc_html_e( 'Database Query Stats', 'wc-enterprise-toolkit' ); ?></h2>
					<table class="wp-list-table widefat fixed striped">
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'Total Queries (this request)', 'wc-enterprise-toolkit' ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $db_stats['total_queries'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Slow Queries Detected', 'wc-enterprise-toolkit' ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $db_stats['slow_queries'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Duplicate Queries', 'wc-enterprise-toolkit' ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $db_stats['duplicate_queries'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Total Query Time', 'wc-enterprise-toolkit' ); ?></strong></td>
								<td><?php echo esc_html( number_format( $db_stats['total_query_time'], 4 ) . 's' ); ?></td>
							</tr>
							<?php if ( ! empty( $db_stats['optimizer_status'] ) ) : ?>
								<tr>
									<td><strong><?php esc_html_e( 'Query Optimizer', 'wc-enterprise-toolkit' ); ?></strong></td>
									<td><?php echo esc_html( $db_stats['optimizer_status'] ); ?></td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>

				<!-- Cache Stats -->
				<div class="wcet-perf-section">
					<h2><?php esc_html_e( 'Cache Statistics', 'wc-enterprise-toolkit' ); ?></h2>
					<table class="wp-list-table widefat fixed striped">
						<tbody>
							<tr>
								<td><strong><?php esc_html_e( 'Backend', 'wc-enterprise-toolkit' ); ?></strong></td>
								<td><?php echo esc_html( $cache_stats['backend'] ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Persistent Cache', 'wc-enterprise-toolkit' ); ?></strong></td>
								<td>
									<?php
									echo esc_html(
										$cache_stats['is_persistent']
											? __( 'Yes', 'wc-enterprise-toolkit' )
											: __( 'No', 'wc-enterprise-toolkit' )
									);
									?>
								</td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Hits', 'wc-enterprise-toolkit' ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $cache_stats['hits'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Misses', 'wc-enterprise-toolkit' ); ?></strong></td>
								<td><?php echo esc_html( number_format_i18n( $cache_stats['misses'] ) ); ?></td>
							</tr>
							<tr>
								<td><strong><?php esc_html_e( 'Hit Ratio', 'wc-enterprise-toolkit' ); ?></strong></td>
								<td><?php echo esc_html( number_format( $cache_stats['hit_ratio'], 1 ) . '%' ); ?></td>
							</tr>
							<?php if ( ! empty( $cache_stats['wcet_cache_status'] ) ) : ?>
								<tr>
									<td><strong><?php esc_html_e( 'WCET Cache Status', 'wc-enterprise-toolkit' ); ?></strong></td>
									<td><?php echo esc_html( $cache_stats['wcet_cache_status'] ); ?></td>
								</tr>
							<?php endif; ?>
						</tbody>
					</table>
				</div>
			</div>

			<!-- Hook Optimization Report -->
			<div class="wcet-perf-section">
				<h2><?php esc_html_e( 'Hook Optimization Report', 'wc-enterprise-toolkit' ); ?></h2>
				<table class="wp-list-table widefat fixed striped">
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'Total Registered Hooks', 'wc-enterprise-toolkit' ); ?></strong></td>
							<td><?php echo esc_html( number_format_i18n( $hook_report['total_hooks'] ) ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Hooks with Callbacks', 'wc-enterprise-toolkit' ); ?></strong></td>
							<td><?php echo esc_html( number_format_i18n( $hook_report['hooks_with_callbacks'] ) ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Total Callbacks', 'wc-enterprise-toolkit' ); ?></strong></td>
							<td><?php echo esc_html( number_format_i18n( $hook_report['total_callbacks'] ) ); ?></td>
						</tr>
						<?php if ( ! empty( $hook_report['optimizer_status'] ) ) : ?>
							<tr>
								<td><strong><?php esc_html_e( 'Hook Optimizer', 'wc-enterprise-toolkit' ); ?></strong></td>
								<td><?php echo esc_html( $hook_report['optimizer_status'] ); ?></td>
							</tr>
						<?php endif; ?>
					</tbody>
				</table>
				<?php if ( ! empty( $hook_report['heaviest_hooks'] ) ) : ?>
					<h3><?php esc_html_e( 'Hooks with Most Callbacks', 'wc-enterprise-toolkit' ); ?></h3>
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Hook Name', 'wc-enterprise-toolkit' ); ?></th>
								<th><?php esc_html_e( 'Callbacks', 'wc-enterprise-toolkit' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $hook_report['heaviest_hooks'] as $hook_name => $count ) : ?>
								<tr>
									<td><code><?php echo esc_html( $hook_name ); ?></code></td>
									<td><?php echo esc_html( number_format_i18n( $count ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>

			<!-- Environment Information -->
			<div class="wcet-perf-section">
				<h2><?php esc_html_e( 'Environment Information', 'wc-enterprise-toolkit' ); ?></h2>
				<table class="wp-list-table widefat fixed striped">
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'PHP Version', 'wc-enterprise-toolkit' ); ?></strong></td>
							<td><?php echo esc_html( $env_info['php_version'] ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'MySQL Version', 'wc-enterprise-toolkit' ); ?></strong></td>
							<td><?php echo esc_html( $env_info['mysql_version'] ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'WordPress Version', 'wc-enterprise-toolkit' ); ?></strong></td>
							<td><?php echo esc_html( $env_info['wp_version'] ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'WooCommerce Version', 'wc-enterprise-toolkit' ); ?></strong></td>
							<td><?php echo esc_html( $env_info['wc_version'] ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'WCET Version', 'wc-enterprise-toolkit' ); ?></strong></td>
							<td><?php echo esc_html( $env_info['wcet_version'] ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'PHP Memory Limit', 'wc-enterprise-toolkit' ); ?></strong></td>
							<td><?php echo esc_html( $env_info['php_memory_limit'] ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'PHP Max Execution Time', 'wc-enterprise-toolkit' ); ?></strong></td>
							<td><?php echo esc_html( $env_info['php_max_execution_time'] . 's' ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'WP Memory Limit', 'wc-enterprise-toolkit' ); ?></strong></td>
							<td><?php echo esc_html( $env_info['wp_memory_limit'] ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Object Cache Backend', 'wc-enterprise-toolkit' ); ?></strong></td>
							<td><?php echo esc_html( $env_info['object_cache_backend'] ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'External Object Cache', 'wc-enterprise-toolkit' ); ?></strong></td>
							<td>
								<?php
								echo esc_html(
									$env_info['using_ext_object_cache']
										? __( 'Yes', 'wc-enterprise-toolkit' )
										: __( 'No', 'wc-enterprise-toolkit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'WP Debug Mode', 'wc-enterprise-toolkit' ); ?></strong></td>
							<td>
								<?php
								echo esc_html(
									$env_info['wp_debug']
										? __( 'Enabled', 'wc-enterprise-toolkit' )
										: __( 'Disabled', 'wc-enterprise-toolkit' )
								);
								?>
							</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'HPOS Enabled', 'wc-enterprise-toolkit' ); ?></strong></td>
							<td>
								<?php
								echo esc_html(
									$env_info['hpos_enabled']
										? __( 'Yes', 'wc-enterprise-toolkit' )
										: __( 'No', 'wc-enterprise-toolkit' )
								);
								?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<!-- Recommended Optimizations -->
			<div class="wcet-perf-section">
				<h2><?php esc_html_e( 'Recommended Optimizations', 'wc-enterprise-toolkit' ); ?></h2>
				<?php if ( empty( $recommendations ) ) : ?>
					<p class="wcet-all-good">
						<span class="dashicons dashicons-yes-alt"></span>
						<?php esc_html_e( 'No critical optimizations needed. Your store is performing well.', 'wc-enterprise-toolkit' ); ?>
					</p>
				<?php else : ?>
					<ul class="wcet-recommendations-list">
						<?php foreach ( $recommendations as $rec ) : ?>
							<li class="wcet-rec-item wcet-rec-<?php echo esc_attr( $rec['severity'] ); ?>">
								<span class="dashicons dashicons-<?php echo esc_attr( $rec['icon'] ); ?>"></span>
								<div class="wcet-rec-content">
									<strong><?php echo esc_html( $rec['title'] ); ?></strong>
									<p><?php echo esc_html( $rec['description'] ); ?></p>
								</div>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>

			<!-- Performance Test Button -->
			<div class="wcet-perf-section">
				<h2><?php esc_html_e( 'Performance Test', 'wc-enterprise-toolkit' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Run a comprehensive performance test that measures common WooCommerce operations.', 'wc-enterprise-toolkit' ); ?>
				</p>
				<button type="button" class="button button-primary" id="wcet-run-perf-test">
					<span class="dashicons dashicons-performance"></span>
					<?php esc_html_e( 'Run Performance Test', 'wc-enterprise-toolkit' ); ?>
				</button>
				<div id="wcet-perf-test-results" class="hidden">
					<h3><?php esc_html_e( 'Test Results', 'wc-enterprise-toolkit' ); ?></h3>
					<div id="wcet-perf-test-output"></div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the performance score gauge.
	 *
	 * @since 1.0.0
	 *
	 * @param int $score Performance score (0-100).
	 */
	private function render_performance_score( $score ) {
		$score = max( 0, min( 100, $score ) );

		if ( $score >= 80 ) {
			$grade = 'A';
			$color = '#5cb85c';
			$label = __( 'Excellent', 'wc-enterprise-toolkit' );
		} elseif ( $score >= 60 ) {
			$grade = 'B';
			$color = '#5bc0de';
			$label = __( 'Good', 'wc-enterprise-toolkit' );
		} elseif ( $score >= 40 ) {
			$grade = 'C';
			$color = '#f0ad4e';
			$label = __( 'Needs Improvement', 'wc-enterprise-toolkit' );
		} elseif ( $score >= 20 ) {
			$grade = 'D';
			$color = '#d9534f';
			$label = __( 'Poor', 'wc-enterprise-toolkit' );
		} else {
			$grade = 'F';
			$color = '#d9534f';
			$label = __( 'Critical', 'wc-enterprise-toolkit' );
		}
		?>
		<div class="wcet-score-section" style="border-left: 5px solid <?php echo esc_attr( $color ); ?>;">
			<div class="wcet-score-gauge">
				<span class="wcet-score-number" style="color: <?php echo esc_attr( $color ); ?>;">
					<?php echo esc_html( $score ); ?>
				</span>
				<span class="wcet-score-max">/100</span>
			</div>
			<div class="wcet-score-details">
				<span class="wcet-score-grade" style="background-color: <?php echo esc_attr( $color ); ?>;">
					<?php echo esc_html( $grade ); ?>
				</span>
				<span class="wcet-score-label"><?php echo esc_html( $label ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * Get average performance metrics for different time periods.
	 *
	 * @since 1.0.0
	 *
	 * @return array Average metrics keyed by period ('24h', '7d', '30d').
	 */
	private function get_average_metrics() {
		$cache_key = 'wcet_avg_metrics';
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$table   = $this->get_table_name();
		$periods = array(
			'24h' => gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) ),
			'7d'  => gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) ),
			'30d' => gmdate( 'Y-m-d H:i:s', strtotime( '-30 days' ) ),
		);

		$result = array();

		foreach ( $periods as $key => $since ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT
					AVG(metric_value) as avg_load_time,
					AVG(
						CAST(JSON_UNQUOTE(JSON_EXTRACT(dimensions, '$.db_query_count')) AS UNSIGNED)
					) as avg_queries,
					AVG(
						CAST(JSON_UNQUOTE(JSON_EXTRACT(dimensions, '$.memory_usage')) AS UNSIGNED)
					) as avg_memory,
					COUNT(*) as sample_count
				FROM {$table}
				WHERE metric_name = 'performance'
				AND recorded_at >= %s",
				$since
			), ARRAY_A );

			$result[ $key ] = array(
				'avg_load_time' => $row ? (float) $row['avg_load_time'] : null,
				'avg_queries'   => $row ? (float) $row['avg_queries'] : null,
				'avg_memory'    => $row ? (float) $row['avg_memory'] : null,
				'sample_count'  => $row ? (int) $row['sample_count'] : 0,
			);
		}

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, self::CACHE_TTL );

		return $result;
	}

	/**
	 * Get database query statistics.
	 *
	 * @since 1.0.0
	 *
	 * @return array Database stats.
	 */
	private function get_database_stats() {
		global $wpdb;

		$stats = array(
			'total_queries'      => (int) $wpdb->num_queries,
			'slow_queries'       => 0,
			'duplicate_queries'  => 0,
			'total_query_time'   => 0,
			'optimizer_status'   => '',
		);

		// Analyze queries if SAVEQUERIES is enabled.
		if ( defined( 'SAVEQUERIES' ) && SAVEQUERIES && ! empty( $wpdb->queries ) ) {
			$query_strings = array();

			foreach ( $wpdb->queries as $query_data ) {
				$query_sql  = $query_data[0];
				$query_time = (float) $query_data[1];

				$stats['total_query_time'] += $query_time;

				// Slow query threshold: 0.05 seconds.
				if ( $query_time > 0.05 ) {
					$stats['slow_queries']++;
				}

				// Detect duplicates.
				$normalized = preg_replace( '/\s+/', ' ', trim( $query_sql ) );
				if ( isset( $query_strings[ $normalized ] ) ) {
					$stats['duplicate_queries']++;
				}
				$query_strings[ $normalized ] = true;
			}
		}

		// Try to get stats from WCET_Query_Optimizer if available.
		if ( class_exists( 'WCET_Query_Optimizer' ) && method_exists( 'WCET_Query_Optimizer', 'get_instance' ) ) {
			$optimizer = WCET_Query_Optimizer::get_instance();
			if ( method_exists( $optimizer, 'get_stats' ) ) {
				$opt_stats = $optimizer->get_stats();
				$stats['optimizer_status'] = isset( $opt_stats['status'] ) ? $opt_stats['status'] : __( 'Active', 'wc-enterprise-toolkit' );

				if ( isset( $opt_stats['slow_queries'] ) ) {
					$stats['slow_queries'] = (int) $opt_stats['slow_queries'];
				}
				if ( isset( $opt_stats['duplicate_queries'] ) ) {
					$stats['duplicate_queries'] = (int) $opt_stats['duplicate_queries'];
				}
			}
		}

		return $stats;
	}

	/**
	 * Get detailed cache statistics.
	 *
	 * @since 1.0.0
	 *
	 * @return array Cache statistics.
	 */
	private function get_detailed_cache_stats() {
		global $wp_object_cache;

		$stats = array(
			'backend'           => __( 'WordPress Default', 'wc-enterprise-toolkit' ),
			'is_persistent'     => wp_using_ext_object_cache(),
			'hits'              => 0,
			'misses'            => 0,
			'hit_ratio'         => 0,
			'wcet_cache_status' => '',
		);

		// Detect backend.
		if ( wp_using_ext_object_cache() ) {
			if ( class_exists( 'Redis' ) ) {
				$stats['backend'] = 'Redis';
			} elseif ( class_exists( 'Memcached' ) ) {
				$stats['backend'] = 'Memcached';
			} elseif ( class_exists( 'Memcache' ) ) {
				$stats['backend'] = 'Memcache';
			} else {
				$stats['backend'] = __( 'External (Unknown)', 'wc-enterprise-toolkit' );
			}
		}

		// Get hit/miss stats from global cache.
		if ( isset( $wp_object_cache->cache_hits, $wp_object_cache->cache_misses ) ) {
			$stats['hits']   = (int) $wp_object_cache->cache_hits;
			$stats['misses'] = (int) $wp_object_cache->cache_misses;
		}

		$total = $stats['hits'] + $stats['misses'];
		if ( $total > 0 ) {
			$stats['hit_ratio'] = ( $stats['hits'] / $total ) * 100;
		}

		// Get WCET Object Cache status.
		if ( class_exists( 'WCET_Object_Cache' ) && method_exists( 'WCET_Object_Cache', 'get_instance' ) ) {
			$wcet_cache = WCET_Object_Cache::get_instance();
			if ( method_exists( $wcet_cache, 'get_stats' ) ) {
				$wcet_stats = $wcet_cache->get_stats();
				$stats['wcet_cache_status'] = isset( $wcet_stats['status'] ) ? $wcet_stats['status'] : __( 'Active', 'wc-enterprise-toolkit' );

				// Override with WCET-specific data if available.
				if ( isset( $wcet_stats['hits'] ) ) {
					$stats['hits'] = (int) $wcet_stats['hits'];
				}
				if ( isset( $wcet_stats['misses'] ) ) {
					$stats['misses'] = (int) $wcet_stats['misses'];
				}
				if ( isset( $wcet_stats['backend'] ) ) {
					$stats['backend'] = $wcet_stats['backend'];
				}

				$total = $stats['hits'] + $stats['misses'];
				if ( $total > 0 ) {
					$stats['hit_ratio'] = ( $stats['hits'] / $total ) * 100;
				}
			}
		}

		return $stats;
	}

	/**
	 * Get hook optimization report.
	 *
	 * @since 1.0.0
	 *
	 * @return array Hook report data.
	 */
	private function get_hook_optimization_report() {
		global $wp_filter;

		$report = array(
			'total_hooks'          => 0,
			'hooks_with_callbacks' => 0,
			'total_callbacks'      => 0,
			'heaviest_hooks'       => array(),
			'optimizer_status'     => '',
		);

		if ( is_array( $wp_filter ) ) {
			$report['total_hooks'] = count( $wp_filter );

			$hook_callback_counts = array();

			foreach ( $wp_filter as $tag => $hook_obj ) {
				if ( $hook_obj instanceof WP_Hook ) {
					$callbacks = $hook_obj->callbacks;
				} else {
					$callbacks = (array) $hook_obj;
				}

				$count = 0;
				foreach ( $callbacks as $priority_callbacks ) {
					$count += count( $priority_callbacks );
				}

				if ( $count > 0 ) {
					$report['hooks_with_callbacks']++;
					$report['total_callbacks'] += $count;
					$hook_callback_counts[ $tag ] = $count;
				}
			}

			// Get top 15 hooks by callback count.
			arsort( $hook_callback_counts );
			$report['heaviest_hooks'] = array_slice( $hook_callback_counts, 0, 15, true );
		}

		// Try WCET_Hook_Optimizer if available.
		if ( class_exists( 'WCET_Hook_Optimizer' ) && method_exists( 'WCET_Hook_Optimizer', 'get_instance' ) ) {
			$optimizer = WCET_Hook_Optimizer::get_instance();
			if ( method_exists( $optimizer, 'get_report' ) ) {
				$opt_report = $optimizer->get_report();
				$report['optimizer_status'] = isset( $opt_report['status'] ) ? $opt_report['status'] : __( 'Active', 'wc-enterprise-toolkit' );
			}
		}

		return $report;
	}

	/**
	 * Get environment information.
	 *
	 * @since 1.0.0
	 *
	 * @return array Environment details.
	 */
	private function get_environment_info() {
		global $wpdb;

		$mysql_version = $wpdb->get_var( 'SELECT VERSION()' );

		$wc_version = __( 'Not Installed', 'wc-enterprise-toolkit' );
		if ( defined( 'WC_VERSION' ) ) {
			$wc_version = WC_VERSION;
		}

		$object_cache_backend = __( 'WordPress Default', 'wc-enterprise-toolkit' );
		if ( wp_using_ext_object_cache() ) {
			if ( class_exists( 'Redis' ) ) {
				$object_cache_backend = 'Redis';
			} elseif ( class_exists( 'Memcached' ) ) {
				$object_cache_backend = 'Memcached';
			} else {
				$object_cache_backend = __( 'External', 'wc-enterprise-toolkit' );
			}
		}

		$hpos_enabled = false;
		if ( class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' ) ) {
			$hpos_enabled = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
		}

		return array(
			'php_version'            => phpversion(),
			'mysql_version'          => $mysql_version ? $mysql_version : __( 'Unknown', 'wc-enterprise-toolkit' ),
			'wp_version'             => get_bloginfo( 'version' ),
			'wc_version'             => $wc_version,
			'wcet_version'           => defined( 'WCET_VERSION' ) ? WCET_VERSION : __( 'Unknown', 'wc-enterprise-toolkit' ),
			'php_memory_limit'       => ini_get( 'memory_limit' ),
			'php_max_execution_time' => ini_get( 'max_execution_time' ),
			'wp_memory_limit'        => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : __( 'Default', 'wc-enterprise-toolkit' ),
			'object_cache_backend'   => $object_cache_backend,
			'using_ext_object_cache' => wp_using_ext_object_cache(),
			'wp_debug'               => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'hpos_enabled'           => $hpos_enabled,
		);
	}

	/**
	 * Calculate a performance score from 0 to 100.
	 *
	 * The score is based on page load time, query count, memory usage,
	 * cache hit ratio, and hook count.
	 *
	 * @since 1.0.0
	 *
	 * @param array $current    Current metrics.
	 * @param array $averages   Average metrics by period.
	 * @return int Performance score (0-100).
	 */
	private function calculate_performance_score( $current, $averages ) {
		$score = 100;

		// Page load time scoring (0-30 points).
		$load_time = $current['page_load_time'];
		if ( $load_time <= 0.5 ) {
			$load_score = 30;
		} elseif ( $load_time <= 1.0 ) {
			$load_score = 25;
		} elseif ( $load_time <= 2.0 ) {
			$load_score = 20;
		} elseif ( $load_time <= 3.0 ) {
			$load_score = 10;
		} else {
			$load_score = max( 0, 30 - (int) ( $load_time * 5 ) );
		}

		// Database queries scoring (0-25 points).
		$queries = $current['db_query_count'];
		if ( $queries <= 50 ) {
			$query_score = 25;
		} elseif ( $queries <= 100 ) {
			$query_score = 20;
		} elseif ( $queries <= 200 ) {
			$query_score = 15;
		} elseif ( $queries <= 500 ) {
			$query_score = 10;
		} else {
			$query_score = max( 0, 25 - (int) ( $queries / 100 ) );
		}

		// Memory usage scoring (0-20 points).
		$memory_mb = $current['memory_usage'] / ( 1024 * 1024 );
		if ( $memory_mb <= 40 ) {
			$memory_score = 20;
		} elseif ( $memory_mb <= 64 ) {
			$memory_score = 15;
		} elseif ( $memory_mb <= 128 ) {
			$memory_score = 10;
		} elseif ( $memory_mb <= 256 ) {
			$memory_score = 5;
		} else {
			$memory_score = 0;
		}

		// Cache hit ratio scoring (0-15 points).
		$hit_ratio = $current['cache_hit_ratio'];
		if ( $hit_ratio >= 90 ) {
			$cache_score = 15;
		} elseif ( $hit_ratio >= 70 ) {
			$cache_score = 12;
		} elseif ( $hit_ratio >= 50 ) {
			$cache_score = 8;
		} elseif ( $hit_ratio >= 30 ) {
			$cache_score = 4;
		} else {
			$cache_score = 0;
		}

		// Hooks scoring (0-10 points).
		$hooks = $current['active_hooks'];
		if ( $hooks <= 500 ) {
			$hook_score = 10;
		} elseif ( $hooks <= 1000 ) {
			$hook_score = 7;
		} elseif ( $hooks <= 2000 ) {
			$hook_score = 4;
		} else {
			$hook_score = 2;
		}

		$score = $load_score + $query_score + $memory_score + $cache_score + $hook_score;

		return max( 0, min( 100, $score ) );
	}

	/**
	 * Generate optimization recommendations based on metrics.
	 *
	 * @since 1.0.0
	 *
	 * @param array $current  Current metrics.
	 * @param array $averages Average metrics.
	 * @param array $env      Environment info.
	 * @return array Recommendations with title, description, severity, and icon.
	 */
	private function get_recommendations( $current, $averages, $env ) {
		$recommendations = array();

		// Object cache check.
		if ( ! $env['using_ext_object_cache'] ) {
			$recommendations[] = array(
				'title'       => __( 'Install a Persistent Object Cache', 'wc-enterprise-toolkit' ),
				'description' => __( 'Your site is using the default WordPress object cache. Install Redis or Memcached for significantly better performance, especially under high traffic.', 'wc-enterprise-toolkit' ),
				'severity'    => 'warning',
				'icon'        => 'warning',
			);
		}

		// High page load time.
		if ( $current['page_load_time'] > 3.0 ) {
			$recommendations[] = array(
				'title'       => __( 'High Page Load Time', 'wc-enterprise-toolkit' ),
				'description' => sprintf(
					/* translators: %s: current page load time */
					__( 'Current page load time is %ss. Consider enabling page caching, optimizing database queries, and reducing active plugins.', 'wc-enterprise-toolkit' ),
					number_format( $current['page_load_time'], 2 )
				),
				'severity'    => 'critical',
				'icon'        => 'dismiss',
			);
		} elseif ( $current['page_load_time'] > 1.5 ) {
			$recommendations[] = array(
				'title'       => __( 'Page Load Time Could Be Improved', 'wc-enterprise-toolkit' ),
				'description' => sprintf(
					/* translators: %s: current page load time */
					__( 'Current page load time is %ss. Look for slow queries and consider implementing full-page caching.', 'wc-enterprise-toolkit' ),
					number_format( $current['page_load_time'], 2 )
				),
				'severity'    => 'warning',
				'icon'        => 'info-outline',
			);
		}

		// High query count.
		if ( $current['db_query_count'] > 200 ) {
			$recommendations[] = array(
				'title'       => __( 'Excessive Database Queries', 'wc-enterprise-toolkit' ),
				'description' => sprintf(
					/* translators: %d: query count */
					__( 'This page executed %d database queries. Review plugins for N+1 query patterns, enable persistent object caching, and consider using the WCET Query Optimizer.', 'wc-enterprise-toolkit' ),
					$current['db_query_count']
				),
				'severity'    => 'critical',
				'icon'        => 'database',
			);
		} elseif ( $current['db_query_count'] > 100 ) {
			$recommendations[] = array(
				'title'       => __( 'High Database Query Count', 'wc-enterprise-toolkit' ),
				'description' => sprintf(
					/* translators: %d: query count */
					__( 'This page executed %d database queries. Consider caching frequently queried data and enabling SAVEQUERIES to identify slow queries.', 'wc-enterprise-toolkit' ),
					$current['db_query_count']
				),
				'severity'    => 'warning',
				'icon'        => 'info-outline',
			);
		}

		// High memory usage.
		$memory_mb = $current['memory_usage'] / ( 1024 * 1024 );
		if ( $memory_mb > 128 ) {
			$recommendations[] = array(
				'title'       => __( 'High Memory Usage', 'wc-enterprise-toolkit' ),
				'description' => sprintf(
					/* translators: %s: memory usage */
					__( 'Current memory usage is %s. Review active plugins, optimize autoloaded options, and ensure PHP memory limit is adequate.', 'wc-enterprise-toolkit' ),
					size_format( $current['memory_usage'] )
				),
				'severity'    => 'warning',
				'icon'        => 'warning',
			);
		}

		// Low cache hit ratio.
		if ( $current['cache_hit_ratio'] < 50 && $current['cache_hit_ratio'] > 0 ) {
			$recommendations[] = array(
				'title'       => __( 'Low Cache Hit Ratio', 'wc-enterprise-toolkit' ),
				'description' => sprintf(
					/* translators: %s: cache hit ratio */
					__( 'Cache hit ratio is only %s%%. This indicates that cache entries are expiring too quickly or not being utilized. Review cache TTL settings and caching strategies.', 'wc-enterprise-toolkit' ),
					number_format( $current['cache_hit_ratio'], 1 )
				),
				'severity'    => 'warning',
				'icon'        => 'info-outline',
			);
		}

		// PHP version check.
		if ( version_compare( $env['php_version'], '8.0', '<' ) ) {
			$recommendations[] = array(
				'title'       => __( 'Upgrade PHP Version', 'wc-enterprise-toolkit' ),
				'description' => sprintf(
					/* translators: %s: current PHP version */
					__( 'Your PHP version (%s) is outdated. PHP 8.0+ offers significant performance improvements and security fixes.', 'wc-enterprise-toolkit' ),
					$env['php_version']
				),
				'severity'    => 'warning',
				'icon'        => 'update',
			);
		}

		// WP Debug in production.
		if ( $env['wp_debug'] ) {
			$recommendations[] = array(
				'title'       => __( 'Disable WP_DEBUG in Production', 'wc-enterprise-toolkit' ),
				'description' => __( 'WP_DEBUG is currently enabled. This adds overhead and should be disabled in production environments for optimal performance and security.', 'wc-enterprise-toolkit' ),
				'severity'    => 'warning',
				'icon'        => 'visibility',
			);
		}

		// HPOS check.
		if ( ! $env['hpos_enabled'] && defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '8.0', '>=' ) ) {
			$recommendations[] = array(
				'title'       => __( 'Enable High-Performance Order Storage (HPOS)', 'wc-enterprise-toolkit' ),
				'description' => __( 'WooCommerce HPOS is not enabled. Enabling it can significantly improve order query performance for stores with large order volumes.', 'wc-enterprise-toolkit' ),
				'severity'    => 'info',
				'icon'        => 'performance',
			);
		}

		// High hook count.
		if ( $current['active_hooks'] > 2000 ) {
			$recommendations[] = array(
				'title'       => __( 'Large Number of Registered Hooks', 'wc-enterprise-toolkit' ),
				'description' => sprintf(
					/* translators: %d: hook count */
					__( 'There are %d registered hooks. Consider auditing installed plugins and removing unused ones to reduce overhead.', 'wc-enterprise-toolkit' ),
					$current['active_hooks']
				),
				'severity'    => 'info',
				'icon'        => 'admin-plugins',
			);
		}

		return $recommendations;
	}

	/**
	 * Add performance indicator to the admin bar.
	 *
	 * Displays current page load time and query count for administrators.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_Admin_Bar $admin_bar The admin bar object.
	 */
	public function add_admin_bar_indicator( $admin_bar ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$elapsed = $this->get_elapsed_time();
		$queries = $this->get_db_query_count();

		$color = '#5cb85c'; // Green.
		if ( $elapsed > 1.5 ) {
			$color = '#f0ad4e'; // Yellow.
		}
		if ( $elapsed > 3.0 ) {
			$color = '#d9534f'; // Red.
		}

		$title = sprintf(
			'<span class="wcet-admin-bar-perf" style="color: %s;">%ss | %dQ</span>',
			esc_attr( $color ),
			esc_html( number_format( $elapsed, 2 ) ),
			esc_html( $queries )
		);

		$admin_bar->add_node( array(
			'id'    => 'wcet-performance',
			'title' => $title,
			'href'  => admin_url( 'admin.php?page=wcet-performance' ),
			'meta'  => array(
				'title' => sprintf(
					/* translators: 1: page load time, 2: query count, 3: memory usage */
					__( 'Page: %1$ss | Queries: %2$d | Memory: %3$s', 'wc-enterprise-toolkit' ),
					number_format( $elapsed, 3 ),
					$queries,
					size_format( memory_get_usage( true ) )
				),
			),
		) );

		$admin_bar->add_node( array(
			'id'     => 'wcet-perf-details',
			'parent' => 'wcet-performance',
			'title'  => sprintf(
				/* translators: %s: page load time */
				__( 'Load Time: %ss', 'wc-enterprise-toolkit' ),
				number_format( $elapsed, 3 )
			),
		) );

		$admin_bar->add_node( array(
			'id'     => 'wcet-perf-queries',
			'parent' => 'wcet-performance',
			'title'  => sprintf(
				/* translators: %d: query count */
				__( 'DB Queries: %d', 'wc-enterprise-toolkit' ),
				$queries
			),
		) );

		$admin_bar->add_node( array(
			'id'     => 'wcet-perf-memory',
			'parent' => 'wcet-performance',
			'title'  => sprintf(
				/* translators: %s: memory usage */
				__( 'Memory: %s', 'wc-enterprise-toolkit' ),
				size_format( memory_get_usage( true ) )
			),
		) );

		$admin_bar->add_node( array(
			'id'     => 'wcet-perf-cache',
			'parent' => 'wcet-performance',
			'title'  => sprintf(
				/* translators: %s: cache hit ratio */
				__( 'Cache Hit Ratio: %s%%', 'wc-enterprise-toolkit' ),
				number_format( $this->get_cache_hit_ratio(), 1 )
			),
		) );

		$admin_bar->add_node( array(
			'id'     => 'wcet-perf-dashboard',
			'parent' => 'wcet-performance',
			'title'  => __( 'View Full Report', 'wc-enterprise-toolkit' ),
			'href'   => admin_url( 'admin.php?page=wcet-performance' ),
		) );
	}

	/**
	 * Output admin bar styles for the performance indicator.
	 *
	 * @since 1.0.0
	 */
	public function admin_bar_styles() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! is_admin_bar_showing() ) {
			return;
		}
		?>
		<style>
			#wpadminbar .wcet-admin-bar-perf {
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
				font-size: 12px;
				font-weight: 600;
				letter-spacing: 0.3px;
			}
		</style>
		<?php
	}

	/**
	 * AJAX handler: Run a comprehensive performance test.
	 *
	 * Measures execution times for common WooCommerce operations.
	 *
	 * @since 1.0.0
	 */
	public function ajax_run_performance_test() {
		check_ajax_referer( 'wcet_performance_test', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'wc-enterprise-toolkit' ) ) );
		}

		$results = array();

		// Test 1: Product query performance.
		$start = microtime( true );
		$products = wc_get_products( array(
			'limit'  => 100,
			'status' => 'publish',
		) );
		$results['product_query'] = array(
			'label'    => __( 'Product Query (100 products)', 'wc-enterprise-toolkit' ),
			'time'     => round( microtime( true ) - $start, 4 ),
			'count'    => count( $products ),
		);

		// Test 2: Order query performance.
		$start = microtime( true );
		$orders = wc_get_orders( array(
			'limit'   => 100,
			'orderby' => 'date',
			'order'   => 'DESC',
		) );
		$results['order_query'] = array(
			'label'    => __( 'Order Query (100 orders)', 'wc-enterprise-toolkit' ),
			'time'     => round( microtime( true ) - $start, 4 ),
			'count'    => count( $orders ),
		);

		// Test 3: WP Option reads.
		$start = microtime( true );
		for ( $i = 0; $i < 100; $i++ ) {
			get_option( 'blogname' );
			get_option( 'siteurl' );
			get_option( 'woocommerce_currency' );
		}
		$results['option_read'] = array(
			'label' => __( 'Option Read (300 reads)', 'wc-enterprise-toolkit' ),
			'time'  => round( microtime( true ) - $start, 4 ),
		);

		// Test 4: Object cache performance.
		$start = microtime( true );
		for ( $i = 0; $i < 500; $i++ ) {
			wp_cache_set( 'wcet_perf_test_' . $i, 'test_value_' . $i, 'wcet_perf_test' );
		}
		for ( $i = 0; $i < 500; $i++ ) {
			wp_cache_get( 'wcet_perf_test_' . $i, 'wcet_perf_test' );
		}
		for ( $i = 0; $i < 500; $i++ ) {
			wp_cache_delete( 'wcet_perf_test_' . $i, 'wcet_perf_test' );
		}
		$results['cache_operations'] = array(
			'label' => __( 'Cache Ops (500 set + 500 get + 500 delete)', 'wc-enterprise-toolkit' ),
			'time'  => round( microtime( true ) - $start, 4 ),
		);

		// Test 5: Taxonomy query (product categories).
		$start = microtime( true );
		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		) );
		$results['taxonomy_query'] = array(
			'label' => __( 'Taxonomy Query (all product categories)', 'wc-enterprise-toolkit' ),
			'time'  => round( microtime( true ) - $start, 4 ),
			'count' => is_array( $terms ) ? count( $terms ) : 0,
		);

		// Test 6: Direct database query.
		global $wpdb;
		$start = microtime( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_results( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'" );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->get_results( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'shop_order'" );
		$results['db_direct'] = array(
			'label' => __( 'Direct DB Queries (2 COUNT queries)', 'wc-enterprise-toolkit' ),
			'time'  => round( microtime( true ) - $start, 4 ),
		);

		// Test 7: Transient operations.
		$start = microtime( true );
		for ( $i = 0; $i < 50; $i++ ) {
			set_transient( 'wcet_perf_test_' . $i, 'test_value', 60 );
		}
		for ( $i = 0; $i < 50; $i++ ) {
			get_transient( 'wcet_perf_test_' . $i );
		}
		for ( $i = 0; $i < 50; $i++ ) {
			delete_transient( 'wcet_perf_test_' . $i );
		}
		$results['transient_ops'] = array(
			'label' => __( 'Transient Ops (50 set + 50 get + 50 delete)', 'wc-enterprise-toolkit' ),
			'time'  => round( microtime( true ) - $start, 4 ),
		);

		// Calculate totals.
		$total_time = 0;
		foreach ( $results as $test ) {
			$total_time += $test['time'];
		}

		$results['total'] = array(
			'label' => __( 'Total Test Time', 'wc-enterprise-toolkit' ),
			'time'  => round( $total_time, 4 ),
		);

		// Overall assessment.
		if ( $total_time < 1.0 ) {
			$assessment = __( 'Excellent - Your store operations are running very fast.', 'wc-enterprise-toolkit' );
		} elseif ( $total_time < 2.0 ) {
			$assessment = __( 'Good - Performance is acceptable but could be optimized.', 'wc-enterprise-toolkit' );
		} elseif ( $total_time < 5.0 ) {
			$assessment = __( 'Fair - Consider implementing caching and query optimization.', 'wc-enterprise-toolkit' );
		} else {
			$assessment = __( 'Poor - Significant optimization is needed. Review database indexing, caching, and server resources.', 'wc-enterprise-toolkit' );
		}

		wp_send_json_success( array(
			'results'    => $results,
			'assessment' => $assessment,
			'timestamp'  => current_time( 'mysql' ),
			'environment' => array(
				'php'            => phpversion(),
				'memory_used'    => size_format( memory_get_usage( true ) ),
				'memory_peak'    => size_format( memory_get_peak_usage( true ) ),
				'query_count'    => $wpdb->num_queries,
				'ext_cache'      => wp_using_ext_object_cache(),
			),
		) );
	}
}
