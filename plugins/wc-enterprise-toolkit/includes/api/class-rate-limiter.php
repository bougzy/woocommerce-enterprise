<?php
/**
 * API Rate Limiter
 *
 * Enforces request rate limits on the WCET REST API. Identifies callers
 * by API token or IP address. Stores counters in the wcet_rate_limits
 * database table and returns standard rate-limit headers.
 *
 * Default: 60 requests per minute, configurable per endpoint.
 * Hooks into rest_pre_dispatch to intercept requests before processing.
 * Includes a WP-Cron job to clean up expired rate limit entries.
 *
 * @package WCET
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class WCET_Rate_Limiter {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Database table name (without prefix).
	 *
	 * @var string
	 */
	private const TABLE_NAME = 'wcet_rate_limits';

	/**
	 * Default rate limit: requests per window.
	 *
	 * @var int
	 */
	private const DEFAULT_LIMIT = 60;

	/**
	 * Default rate limit window in seconds (1 minute).
	 *
	 * @var int
	 */
	private const DEFAULT_WINDOW = 60;

	/**
	 * Cron hook name for cleanup.
	 *
	 * @var string
	 */
	private const CLEANUP_HOOK = 'wcet_rate_limit_cleanup';

	/**
	 * Per-endpoint rate limit overrides.
	 *
	 * @var array<string, array{limit: int, window: int}>
	 */
	private array $endpoint_limits = [];

	/**
	 * Get singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Intercept REST requests before dispatch.
		add_filter( 'rest_pre_dispatch', [ $this, 'check_rate_limit' ], 10, 3 );

		// Add rate limit headers to all REST responses.
		add_filter( 'rest_post_dispatch', [ $this, 'add_rate_limit_headers' ], 10, 3 );

		// Database table creation.
		add_action( 'admin_init', [ $this, 'maybe_create_table' ] );

		// Cron cleanup.
		add_action( self::CLEANUP_HOOK, [ $this, 'cleanup_expired_entries' ] );
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time(), 'hourly', self::CLEANUP_HOOK );
		}

		// Load endpoint-specific limits from options.
		$this->load_endpoint_limits();
	}

	/**
	 * Create the rate limits table if it does not exist.
	 *
	 * @return void
	 */
	public function maybe_create_table(): void {
		$db_version_key  = 'wcet_rate_limits_db_version';
		$current_version = '1.0.0';

		if ( get_option( $db_version_key ) === $current_version ) {
			return;
		}

		global $wpdb;
		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			identifier varchar(255) NOT NULL,
			endpoint varchar(255) NOT NULL DEFAULT '',
			request_count int unsigned NOT NULL DEFAULT 1,
			window_start datetime NOT NULL,
			window_end datetime NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY identifier_endpoint_window (identifier, endpoint, window_start),
			KEY window_end (window_end)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( $db_version_key, $current_version );
	}

	/**
	 * Load per-endpoint rate limit overrides from wp_options.
	 *
	 * Expected format:
	 * [
	 *     '/wcet/v1/products' => ['limit' => 120, 'window' => 60],
	 *     '/wcet/v1/orders'   => ['limit' => 30,  'window' => 60],
	 * ]
	 *
	 * @return void
	 */
	private function load_endpoint_limits(): void {
		$overrides = get_option( 'wcet_rate_limit_overrides', [] );

		if ( is_array( $overrides ) ) {
			$this->endpoint_limits = $overrides;
		}

		/**
		 * Filter per-endpoint rate limit configuration.
		 *
		 * @param array $endpoint_limits Endpoint => limit config array.
		 */
		$this->endpoint_limits = apply_filters( 'wcet_rate_limit_endpoints', $this->endpoint_limits );
	}

	/**
	 * Check the rate limit for the current request.
	 *
	 * Hooks into rest_pre_dispatch. Returns WP_Error with 429 status if the
	 * rate limit is exceeded, otherwise passes through.
	 *
	 * @param mixed           $result  Pre-dispatch result (usually null).
	 * @param WP_REST_Server  $server  REST server instance.
	 * @param WP_REST_Request $request The incoming request.
	 * @return mixed|WP_Error
	 */
	public function check_rate_limit( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		// Only rate-limit WCET API endpoints.
		$route = $request->get_route();
		if ( ! str_starts_with( $route, '/wcet/' ) ) {
			return $result;
		}

		// Allow admins to bypass rate limiting if configured.
		if ( current_user_can( 'manage_options' ) && apply_filters( 'wcet_rate_limit_bypass_admins', false ) ) {
			return $result;
		}

		$identifier = $this->get_request_identifier();
		$endpoint   = $this->normalize_endpoint( $route );
		$limits     = $this->get_limits_for_endpoint( $endpoint );
		$limit      = $limits['limit'];
		$window     = $limits['window'];

		$rate_data = $this->get_or_create_window( $identifier, $endpoint, $window );

		if ( $rate_data['request_count'] > $limit ) {
			$reset_time = strtotime( $rate_data['window_end'] );
			$retry_after = max( 1, $reset_time - time() );

			$error = new WP_Error(
				'wcet_rate_limit_exceeded',
				sprintf(
					/* translators: 1: rate limit 2: window in seconds */
					__( 'Rate limit exceeded. Maximum %1$d requests per %2$d seconds.', 'wc-enterprise' ),
					$limit,
					$window
				),
				[ 'status' => 429 ]
			);

			// Add rate limit headers to the error response.
			add_filter( 'rest_post_dispatch', function ( $response ) use ( $limit, $reset_time, $retry_after ) {
				if ( is_a( $response, 'WP_REST_Response' ) ) {
					$response->header( 'X-RateLimit-Limit', $limit );
					$response->header( 'X-RateLimit-Remaining', 0 );
					$response->header( 'X-RateLimit-Reset', $reset_time );
					$response->header( 'Retry-After', $retry_after );
				}
				return $response;
			}, 999 );

			/**
			 * Fires when a rate limit is exceeded.
			 *
			 * @param string $identifier The client identifier (token hash or IP).
			 * @param string $endpoint   The normalized endpoint.
			 * @param int    $limit      The rate limit.
			 */
			do_action( 'wcet_rate_limit_exceeded', $identifier, $endpoint, $limit );

			return $error;
		}

		// Store data for the post-dispatch header filter.
		$request->set_param( '_wcet_rate_limit', $limit );
		$request->set_param( '_wcet_rate_remaining', max( 0, $limit - $rate_data['request_count'] ) );
		$request->set_param( '_wcet_rate_reset', strtotime( $rate_data['window_end'] ) );

		return $result;
	}

	/**
	 * Add rate limit headers to REST API responses.
	 *
	 * @param WP_REST_Response $response The response.
	 * @param WP_REST_Server   $server   The REST server.
	 * @param WP_REST_Request  $request  The request.
	 * @return WP_REST_Response
	 */
	public function add_rate_limit_headers( WP_REST_Response $response, WP_REST_Server $server, WP_REST_Request $request ): WP_REST_Response {
		$limit     = $request->get_param( '_wcet_rate_limit' );
		$remaining = $request->get_param( '_wcet_rate_remaining' );
		$reset     = $request->get_param( '_wcet_rate_reset' );

		if ( null !== $limit ) {
			$response->header( 'X-RateLimit-Limit', (int) $limit );
			$response->header( 'X-RateLimit-Remaining', (int) $remaining );
			$response->header( 'X-RateLimit-Reset', (int) $reset );
		}

		return $response;
	}

	/**
	 * Get or create the current rate limit window for an identifier/endpoint.
	 *
	 * Atomically increments the request count using INSERT ... ON DUPLICATE KEY UPDATE.
	 *
	 * @param string $identifier The client identifier.
	 * @param string $endpoint   The normalized endpoint.
	 * @param int    $window     The window duration in seconds.
	 * @return array{request_count: int, window_end: string}
	 */
	private function get_or_create_window( string $identifier, string $endpoint, int $window ): array {
		global $wpdb;

		$table        = $wpdb->prefix . self::TABLE_NAME;
		$now          = time();
		$window_start = gmdate( 'Y-m-d H:i:s', $now - ( $now % $window ) );
		$window_end   = gmdate( 'Y-m-d H:i:s', strtotime( $window_start ) + $window );

		// Attempt atomic upsert.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (identifier, endpoint, request_count, window_start, window_end)
				 VALUES (%s, %s, 1, %s, %s)
				 ON DUPLICATE KEY UPDATE request_count = request_count + 1",
				$identifier,
				$endpoint,
				$window_start,
				$window_end
			)
		);

		// Retrieve the current count.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT request_count, window_end FROM {$table}
				 WHERE identifier = %s AND endpoint = %s AND window_start = %s
				 LIMIT 1",
				$identifier,
				$endpoint,
				$window_start
			),
			ARRAY_A
		);

		if ( $row ) {
			return [
				'request_count' => (int) $row['request_count'],
				'window_end'    => $row['window_end'],
			];
		}

		// Fallback if something went wrong.
		return [
			'request_count' => 1,
			'window_end'    => $window_end,
		];
	}

	/**
	 * Get the rate limit configuration for a specific endpoint.
	 *
	 * @param string $endpoint The normalized endpoint path.
	 * @return array{limit: int, window: int}
	 */
	private function get_limits_for_endpoint( string $endpoint ): array {
		if ( isset( $this->endpoint_limits[ $endpoint ] ) ) {
			return wp_parse_args( $this->endpoint_limits[ $endpoint ], [
				'limit'  => self::DEFAULT_LIMIT,
				'window' => self::DEFAULT_WINDOW,
			] );
		}

		/**
		 * Filter the default rate limit.
		 *
		 * @param int $limit The default number of requests per window.
		 */
		$limit = apply_filters( 'wcet_rate_limit_default', self::DEFAULT_LIMIT );

		/**
		 * Filter the default rate limit window.
		 *
		 * @param int $window The default window duration in seconds.
		 */
		$window_seconds = apply_filters( 'wcet_rate_limit_window', self::DEFAULT_WINDOW );

		return [
			'limit'  => (int) $limit,
			'window' => (int) $window_seconds,
		];
	}

	/**
	 * Get a unique identifier for the current request.
	 *
	 * Uses the API token hash if available, otherwise falls back to IP address.
	 *
	 * @return string
	 */
	private function get_request_identifier(): string {
		// If authenticated via WCET token, use the token ID.
		if ( class_exists( 'WCET_Token_Auth' ) ) {
			$token_auth  = WCET_Token_Auth::instance();
			$token_data  = $token_auth->get_current_token();

			if ( $token_data && ! empty( $token_data['id'] ) ) {
				return 'token_' . (int) $token_data['id'];
			}
		}

		// Fall back to authenticated user ID.
		$user_id = get_current_user_id();
		if ( $user_id > 0 ) {
			return 'user_' . $user_id;
		}

		// Fall back to IP address.
		return 'ip_' . $this->get_client_ip();
	}

	/**
	 * Normalize an endpoint route path for rate limit grouping.
	 *
	 * Strips numeric IDs so that /wcet/v1/products/123 and /wcet/v1/products/456
	 * share the same rate limit bucket.
	 *
	 * @param string $route The full route path.
	 * @return string
	 */
	private function normalize_endpoint( string $route ): string {
		// Replace numeric path segments with a placeholder.
		$normalized = preg_replace( '#/\d+(?=/|$)#', '/{id}', $route );
		return $normalized ?: $route;
	}

	/**
	 * Get the client IP address.
	 *
	 * @return string
	 */
	private function get_client_ip(): string {
		$headers = [
			'HTTP_CF_CONNECTING_IP',
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		];

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				if ( str_contains( $ip, ',' ) ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}

	/**
	 * Clean up expired rate limit entries.
	 *
	 * Runs via WP-Cron hourly. Deletes entries whose window has closed.
	 *
	 * @return int Number of rows deleted.
	 */
	public function cleanup_expired_entries(): int {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;
		$now   = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE window_end < %s",
				$now
			)
		);

		if ( $deleted > 0 ) {
			/**
			 * Fires after expired rate limit entries are cleaned up.
			 *
			 * @param int $deleted Number of rows deleted.
			 */
			do_action( 'wcet_rate_limit_cleanup_completed', $deleted );
		}

		return (int) $deleted;
	}

	/**
	 * Get the current rate limit status for an identifier and endpoint.
	 *
	 * Useful for diagnostics and admin UI.
	 *
	 * @param string $identifier The client identifier.
	 * @param string $endpoint   The endpoint path.
	 * @return array{limit: int, remaining: int, reset: int}|null
	 */
	public function get_rate_status( string $identifier, string $endpoint ): ?array {
		global $wpdb;

		$table   = $wpdb->prefix . self::TABLE_NAME;
		$limits  = $this->get_limits_for_endpoint( $endpoint );
		$window  = $limits['window'];
		$now     = time();
		$window_start = gmdate( 'Y-m-d H:i:s', $now - ( $now % $window ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT request_count, window_end FROM {$table}
				 WHERE identifier = %s AND endpoint = %s AND window_start = %s
				 LIMIT 1",
				$identifier,
				$endpoint,
				$window_start
			),
			ARRAY_A
		);

		if ( ! $row ) {
			return [
				'limit'     => $limits['limit'],
				'remaining' => $limits['limit'],
				'reset'     => $now + $window,
			];
		}

		return [
			'limit'     => $limits['limit'],
			'remaining' => max( 0, $limits['limit'] - (int) $row['request_count'] ),
			'reset'     => strtotime( $row['window_end'] ),
		];
	}

	/**
	 * Set a custom rate limit for a specific endpoint.
	 *
	 * @param string $endpoint The endpoint path (e.g., '/wcet/v1/products').
	 * @param int    $limit    Maximum requests per window.
	 * @param int    $window   Window duration in seconds.
	 * @return void
	 */
	public function set_endpoint_limit( string $endpoint, int $limit, int $window = self::DEFAULT_WINDOW ): void {
		$this->endpoint_limits[ $endpoint ] = [
			'limit'  => max( 1, $limit ),
			'window' => max( 1, $window ),
		];

		update_option( 'wcet_rate_limit_overrides', $this->endpoint_limits );
	}

	/**
	 * Remove a custom rate limit for a specific endpoint.
	 *
	 * @param string $endpoint The endpoint path.
	 * @return void
	 */
	public function remove_endpoint_limit( string $endpoint ): void {
		unset( $this->endpoint_limits[ $endpoint ] );
		update_option( 'wcet_rate_limit_overrides', $this->endpoint_limits );
	}

	/**
	 * Reset rate limit counters for a specific identifier.
	 *
	 * @param string $identifier The client identifier.
	 * @return bool
	 */
	public function reset_identifier( string $identifier ): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table,
			[ 'identifier' => $identifier ],
			[ '%s' ]
		);

		return false !== $result;
	}

	/**
	 * Deactivation cleanup: unschedule the cron event.
	 *
	 * Call this from the plugin's deactivation hook.
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		$timestamp = wp_next_scheduled( self::CLEANUP_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CLEANUP_HOOK );
		}
	}
}

WCET_Rate_Limiter::instance();
