<?php
/**
 * Fraud Detection & Prevention
 *
 * Real-time risk scoring system (0-100 scale) that evaluates orders at checkout
 * for potential fraud using multiple heuristic signals. Logs assessments to the
 * wcet_fraud_log table and displays results on admin order detail pages.
 *
 * @package WCET\Payments
 */

defined( 'ABSPATH' ) || exit;

class WCET_Fraud_Prevention {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * WC Logger instance.
	 *
	 * @var WC_Logger|null
	 */
	private $logger = null;

	/**
	 * Default risk thresholds.
	 *
	 * @var array{review: int, block: int}
	 */
	private $thresholds = [
		'review' => 30,
		'block'  => 60,
	];

	/**
	 * Get the singleton instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — register hooks.
	 */
	private function __construct() {
		// Load configurable thresholds.
		$this->thresholds['review'] = (int) get_option( 'wcet_fraud_review_threshold', 30 );
		$this->thresholds['block']  = (int) get_option( 'wcet_fraud_block_threshold', 60 );

		// Evaluate orders at checkout.
		add_action( 'woocommerce_checkout_order_processed', [ $this, 'evaluate_order' ], 10, 3 );

		// Admin UI: show fraud assessment on order detail page.
		add_action( 'woocommerce_admin_order_data_after_billing_address', [ $this, 'display_fraud_assessment' ] );

		// Admin settings.
		add_filter( 'woocommerce_settings_tabs_array', [ $this, 'add_settings_tab' ], 50 );
		add_action( 'woocommerce_settings_tabs_wcet_fraud', [ $this, 'render_settings_page' ] );
		add_action( 'woocommerce_update_options_wcet_fraud', [ $this, 'save_settings' ] );

		// Admin meta box for detailed fraud info.
		add_action( 'add_meta_boxes', [ $this, 'add_fraud_meta_box' ] );
	}

	/* ------------------------------------------------------------------
	 * Order Evaluation
	 * ----------------------------------------------------------------*/

	/**
	 * Evaluate an order for fraud risk immediately after checkout processing.
	 *
	 * @param int      $order_id    WooCommerce order ID.
	 * @param array    $posted_data Posted checkout data.
	 * @param WC_Order $order       WooCommerce order object.
	 */
	public function evaluate_order( int $order_id, array $posted_data, WC_Order $order ) {
		$risk_factors = [];
		$total_score  = 0;

		// 1. IP geolocation mismatch.
		$ip_score = $this->check_ip_geolocation_mismatch( $order );
		if ( $ip_score > 0 ) {
			$risk_factors[] = [
				'factor' => 'ip_geolocation_mismatch',
				'score'  => $ip_score,
				'detail' => __( 'IP address geolocation does not match billing country.', 'wc-enterprise' ),
			];
			$total_score += $ip_score;
		}

		// 2. Velocity checks.
		$velocity_score = $this->check_velocity( $order );
		if ( $velocity_score > 0 ) {
			$risk_factors[] = [
				'factor' => 'velocity',
				'score'  => $velocity_score,
				'detail' => __( 'Unusual number of orders from this IP or email address.', 'wc-enterprise' ),
			];
			$total_score += $velocity_score;
		}

		// 3. Email domain analysis.
		$email_score = $this->check_email_domain( $order );
		if ( $email_score > 0 ) {
			$risk_factors[] = [
				'factor' => 'email_domain',
				'score'  => $email_score,
				'detail' => __( 'Email address uses a high-risk or disposable domain.', 'wc-enterprise' ),
			];
			$total_score += $email_score;
		}

		// 4. Order amount anomaly.
		$amount_score = $this->check_amount_anomaly( $order );
		if ( $amount_score > 0 ) {
			$risk_factors[] = [
				'factor' => 'amount_anomaly',
				'score'  => $amount_score,
				'detail' => __( 'Order amount is significantly higher than the store average.', 'wc-enterprise' ),
			];
			$total_score += $amount_score;
		}

		// 5. BIN country mismatch.
		$bin_score = $this->check_bin_country_mismatch( $order );
		if ( $bin_score > 0 ) {
			$risk_factors[] = [
				'factor' => 'bin_country_mismatch',
				'score'  => $bin_score,
				'detail' => __( 'Card BIN country does not match billing country.', 'wc-enterprise' ),
			];
			$total_score += $bin_score;
		}

		// 6. New customer + high value.
		$new_customer_score = $this->check_new_customer_high_value( $order );
		if ( $new_customer_score > 0 ) {
			$risk_factors[] = [
				'factor' => 'new_customer_high_value',
				'score'  => $new_customer_score,
				'detail' => __( 'First-time customer placing a high-value order.', 'wc-enterprise' ),
			];
			$total_score += $new_customer_score;
		}

		// 7. IP reputation check.
		$ip_rep_score = $this->check_ip_reputation( $order );
		if ( $ip_rep_score > 0 ) {
			$risk_factors[] = [
				'factor' => 'ip_reputation',
				'score'  => $ip_rep_score,
				'detail' => __( 'IP address is associated with known proxies, VPNs, or data centers.', 'wc-enterprise' ),
			];
			$total_score += $ip_rep_score;
		}

		// Cap score at 100.
		$total_score = min( 100, $total_score );

		// Allow third-party filtering.
		$total_score  = (int) apply_filters( 'wcet_fraud_risk_score', $total_score, $risk_factors, $order );
		$risk_factors = apply_filters( 'wcet_fraud_risk_factors', $risk_factors, $total_score, $order );

		// Determine action.
		$action = $this->determine_action( $total_score );

		// Log to database.
		$this->log_fraud_assessment( $order_id, $total_score, $risk_factors, $action );

		// Store on the order for quick access.
		$order->update_meta_data( '_wcet_fraud_score', $total_score );
		$order->update_meta_data( '_wcet_fraud_action', $action );
		$order->save();

		// Apply the action.
		$this->apply_action( $order, $action, $total_score, $risk_factors );

		$this->log( 'info', sprintf( 'Fraud assessment complete: score=%d, action=%s', $total_score, $action ), [
			'order_id' => $order_id,
			'factors'  => count( $risk_factors ),
		] );
	}

	/**
	 * Determine the action to take based on the risk score.
	 *
	 * @param int $score Risk score (0-100).
	 * @return string One of 'allow', 'review', 'block'.
	 */
	private function determine_action( int $score ) {
		if ( $score >= $this->thresholds['block'] ) {
			return 'block';
		}

		if ( $score >= $this->thresholds['review'] ) {
			return 'review';
		}

		return 'allow';
	}

	/**
	 * Apply the fraud prevention action to the order.
	 *
	 * @param WC_Order $order        WooCommerce order.
	 * @param string   $action       Action to apply (allow, review, block).
	 * @param int      $score        Risk score.
	 * @param array    $risk_factors Array of risk factor details.
	 */
	private function apply_action( WC_Order $order, string $action, int $score, array $risk_factors ) {
		switch ( $action ) {
			case 'review':
				$order->update_status( 'awaiting-verify',
					sprintf(
						/* translators: %d: risk score */
						__( 'Fraud prevention: flagged for review (risk score: %d). Order requires manual verification.', 'wc-enterprise' ),
						$score
					)
				);
				do_action( 'wcet_fraud_order_flagged', $order, $score, $risk_factors );
				break;

			case 'block':
				$order->update_status( 'failed',
					sprintf(
						/* translators: %d: risk score */
						__( 'Fraud prevention: order blocked (risk score: %d).', 'wc-enterprise' ),
						$score
					)
				);
				do_action( 'wcet_fraud_order_blocked', $order, $score, $risk_factors );
				break;

			case 'allow':
			default:
				$order->add_order_note(
					sprintf(
						/* translators: %d: risk score */
						__( 'Fraud prevention: order allowed (risk score: %d).', 'wc-enterprise' ),
						$score
					)
				);
				break;
		}
	}

	/* ------------------------------------------------------------------
	 * Risk Factor Checks
	 * ----------------------------------------------------------------*/

	/**
	 * Check for IP geolocation mismatch with billing country.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return int Risk score contribution (0-25).
	 */
	private function check_ip_geolocation_mismatch( WC_Order $order ) {
		$billing_country = $order->get_billing_country();
		$ip_address      = $order->get_customer_ip_address();

		if ( empty( $billing_country ) || empty( $ip_address ) ) {
			return 0;
		}

		// Use WooCommerce's built-in geolocation.
		$geo_data   = WC_Geolocation::geolocate_ip( $ip_address );
		$ip_country = $geo_data['country'] ?? '';

		if ( empty( $ip_country ) ) {
			return 0;
		}

		if ( strtoupper( $ip_country ) !== strtoupper( $billing_country ) ) {
			// Check if shipping country matches (international shipping is normal).
			$shipping_country = $order->get_shipping_country();
			if ( ! empty( $shipping_country ) && strtoupper( $ip_country ) === strtoupper( $shipping_country ) ) {
				return 10; // Lower score: IP matches shipping but not billing.
			}
			return 20; // Higher score: full mismatch.
		}

		return 0;
	}

	/**
	 * Check for unusual order velocity from the same IP or email.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return int Risk score contribution (0-25).
	 */
	private function check_velocity( WC_Order $order ) {
		global $wpdb;

		$score      = 0;
		$ip_address = $order->get_customer_ip_address();
		$email      = $order->get_billing_email();
		$one_hour   = gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) );
		$one_day    = gmdate( 'Y-m-d H:i:s', strtotime( '-24 hours' ) );

		// IP velocity: orders from the same IP in the last hour.
		if ( ! empty( $ip_address ) ) {
			$ip_count_1h = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders WHERE ip_address = %s AND date_created_gmt > %s",
				$ip_address,
				$one_hour
			) );

			$ip_threshold = (int) apply_filters( 'wcet_fraud_ip_velocity_threshold', 3 );
			if ( $ip_count_1h > $ip_threshold ) {
				$score += min( 25, ( $ip_count_1h - $ip_threshold ) * 8 );
			}
		}

		// Email velocity: orders from the same email in the last 24 hours.
		if ( ! empty( $email ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$email_count_24h = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders AS o
				 INNER JOIN {$wpdb->prefix}wc_order_addresses AS a ON o.id = a.order_id AND a.address_type = 'billing'
				 WHERE a.email = %s AND o.date_created_gmt > %s",
				$email,
				$one_day
			) );

			$email_threshold = (int) apply_filters( 'wcet_fraud_email_velocity_threshold', 5 );
			if ( $email_count_24h > $email_threshold ) {
				$score += min( 20, ( $email_count_24h - $email_threshold ) * 5 );
			}
		}

		return min( 25, $score );
	}

	/**
	 * Analyze the billing email domain for risk signals.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return int Risk score contribution (0-20).
	 */
	private function check_email_domain( WC_Order $order ) {
		$email = strtolower( $order->get_billing_email() );

		if ( empty( $email ) || ! is_email( $email ) ) {
			return 5; // Missing or invalid email is suspicious.
		}

		$domain = substr( strrchr( $email, '@' ), 1 );

		if ( empty( $domain ) ) {
			return 5;
		}

		// Disposable email domains.
		$disposable_domains = apply_filters( 'wcet_fraud_disposable_domains', [
			'tempmail.com',
			'throwaway.email',
			'guerrillamail.com',
			'mailinator.com',
			'yopmail.com',
			'sharklasers.com',
			'guerrillamailblock.com',
			'grr.la',
			'dispostable.com',
			'trashmail.com',
			'fakeinbox.com',
			'tempail.com',
			'maildrop.cc',
			'discard.email',
			'tempr.email',
			'temp-mail.org',
		] );

		if ( in_array( $domain, $disposable_domains, true ) ) {
			return 20;
		}

		// Recently registered or suspicious TLDs.
		$risky_tlds = apply_filters( 'wcet_fraud_risky_tlds', [
			'.xyz', '.top', '.click', '.buzz', '.gq', '.ml', '.cf', '.tk', '.ga',
		] );

		foreach ( $risky_tlds as $tld ) {
			if ( substr( $domain, -strlen( $tld ) ) === $tld ) {
				return 10;
			}
		}

		// Check if the domain has MX records (legitimate domains should have them).
		if ( function_exists( 'checkdnsrr' ) && ! checkdnsrr( $domain, 'MX' ) ) {
			return 15;
		}

		return 0;
	}

	/**
	 * Check whether the order amount is anomalously high for this store.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return int Risk score contribution (0-15).
	 */
	private function check_amount_anomaly( WC_Order $order ) {
		global $wpdb;

		$order_total = (float) $order->get_total();

		if ( $order_total <= 0 ) {
			return 0;
		}

		// Calculate the average order value over the last 90 days.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$avg_total = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT AVG(total_amount) FROM {$wpdb->prefix}wc_orders
			 WHERE status IN ('wc-completed', 'wc-processing')
			   AND date_created_gmt > %s",
			gmdate( 'Y-m-d H:i:s', strtotime( '-90 days' ) )
		) );

		// If not enough data, use a default high-value threshold.
		if ( $avg_total <= 0 ) {
			$high_value_threshold = (float) apply_filters( 'wcet_fraud_default_high_value', 500.00 );
			return $order_total > $high_value_threshold ? 10 : 0;
		}

		// Score based on multiples of the average.
		$multiplier = $order_total / $avg_total;

		if ( $multiplier > 10 ) {
			return 15;
		}

		if ( $multiplier > 5 ) {
			return 10;
		}

		if ( $multiplier > 3 ) {
			return 5;
		}

		return 0;
	}

	/**
	 * Check for BIN (Bank Identification Number) country mismatch.
	 *
	 * This relies on metadata stored by the payment gateway during processing.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return int Risk score contribution (0-15).
	 */
	private function check_bin_country_mismatch( WC_Order $order ) {
		$billing_country = strtoupper( $order->get_billing_country() );
		$card_country    = strtoupper( $order->get_meta( '_wcet_card_country' ) );

		if ( empty( $billing_country ) || empty( $card_country ) ) {
			return 0; // Cannot determine without card country data.
		}

		if ( $card_country !== $billing_country ) {
			return 15;
		}

		return 0;
	}

	/**
	 * Check for first-time customers placing high-value orders.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return int Risk score contribution (0-20).
	 */
	private function check_new_customer_high_value( WC_Order $order ) {
		$customer_id = $order->get_customer_id();
		$order_total = (float) $order->get_total();

		// Define "high value" — configurable.
		$high_value_threshold = (float) apply_filters( 'wcet_fraud_new_customer_high_value_threshold', 300.00 );

		if ( $order_total < $high_value_threshold ) {
			return 0;
		}

		$is_new_customer = false;

		if ( $customer_id > 0 ) {
			// Registered user: check if they have any previous completed orders.
			$previous_orders = wc_get_orders( [
				'customer_id' => $customer_id,
				'status'      => [ 'completed', 'processing' ],
				'limit'       => 1,
				'exclude'     => [ $order->get_id() ],
			] );
			$is_new_customer = empty( $previous_orders );
		} else {
			// Guest checkout: check by email.
			$email = $order->get_billing_email();
			if ( ! empty( $email ) ) {
				$previous_orders = wc_get_orders( [
					'billing_email' => $email,
					'status'        => [ 'completed', 'processing' ],
					'limit'         => 1,
					'exclude'       => [ $order->get_id() ],
				] );
				$is_new_customer = empty( $previous_orders );
			} else {
				$is_new_customer = true;
			}
		}

		if ( ! $is_new_customer ) {
			return 0;
		}

		// Scale score based on how far above threshold.
		$multiplier = $order_total / $high_value_threshold;

		if ( $multiplier > 5 ) {
			return 20;
		}

		if ( $multiplier > 3 ) {
			return 15;
		}

		return 10;
	}

	/**
	 * Check the IP address against known proxy/VPN/data center ranges.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return int Risk score contribution (0-15).
	 */
	private function check_ip_reputation( WC_Order $order ) {
		$ip_address = $order->get_customer_ip_address();

		if ( empty( $ip_address ) ) {
			return 5; // No IP is suspicious.
		}

		// Check against a local blocklist first (fast).
		$blocked_ranges = apply_filters( 'wcet_fraud_blocked_ip_ranges', [] );

		foreach ( $blocked_ranges as $range ) {
			if ( $this->ip_in_range( $ip_address, $range ) ) {
				return 15;
			}
		}

		// Check common data center / VPN indicators via reverse DNS.
		$hostname = gethostbyaddr( $ip_address );

		if ( $hostname && $hostname !== $ip_address ) {
			$hostname_lower = strtolower( $hostname );

			$suspicious_patterns = apply_filters( 'wcet_fraud_suspicious_hostnames', [
				'vpn',
				'proxy',
				'tor-exit',
				'datacenter',
				'cloud',
				'vps',
				'server',
				'hosting',
				'dedicated',
				'colocation',
			] );

			foreach ( $suspicious_patterns as $pattern ) {
				if ( strpos( $hostname_lower, $pattern ) !== false ) {
					return 12;
				}
			}
		}

		// Optional: external IP reputation API check.
		$external_score = $this->check_external_ip_reputation( $ip_address );
		if ( $external_score > 0 ) {
			return $external_score;
		}

		return 0;
	}

	/**
	 * Query an external IP reputation service (if configured).
	 *
	 * @param string $ip_address IP address to check.
	 * @return int Risk score contribution (0-15).
	 */
	private function check_external_ip_reputation( string $ip_address ) {
		$api_key = get_option( 'wcet_fraud_ip_api_key', '' );

		if ( empty( $api_key ) ) {
			return 0;
		}

		$api_url = apply_filters(
			'wcet_fraud_ip_reputation_api_url',
			'https://vpnapi.io/api/' . rawurlencode( $ip_address ) . '?key=' . rawurlencode( $api_key )
		);

		// Use a transient cache to avoid repeated calls for the same IP.
		$cache_key = 'wcet_ip_rep_' . md5( $ip_address );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$response = wp_remote_get( $api_url, [
			'timeout' => 5,
			'headers' => [ 'Accept' => 'application/json' ],
		] );

		if ( is_wp_error( $response ) ) {
			$this->log( 'warning', 'IP reputation API failed: ' . $response->get_error_message(), [ 'ip' => $ip_address ] );
			return 0;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$score = 0;

		if ( is_array( $body ) && ! empty( $body['security'] ) ) {
			if ( ! empty( $body['security']['vpn'] ) ) {
				$score += 8;
			}
			if ( ! empty( $body['security']['proxy'] ) ) {
				$score += 10;
			}
			if ( ! empty( $body['security']['tor'] ) ) {
				$score += 15;
			}
			if ( ! empty( $body['security']['relay'] ) ) {
				$score += 5;
			}
		}

		$score = min( 15, $score );

		// Cache result for 1 hour.
		set_transient( $cache_key, $score, HOUR_IN_SECONDS );

		return $score;
	}

	/**
	 * Check whether an IP address falls within a CIDR range.
	 *
	 * @param string $ip    IP address to check.
	 * @param string $range CIDR range (e.g. "192.168.1.0/24").
	 * @return bool
	 */
	private function ip_in_range( string $ip, string $range ) {
		if ( strpos( $range, '/' ) === false ) {
			return $ip === $range;
		}

		list( $subnet, $bits ) = explode( '/', $range, 2 );
		$bits = (int) $bits;

		$ip_long     = ip2long( $ip );
		$subnet_long = ip2long( $subnet );

		if ( false === $ip_long || false === $subnet_long ) {
			return false;
		}

		$mask = -1 << ( 32 - $bits );

		return ( $ip_long & $mask ) === ( $subnet_long & $mask );
	}

	/* ------------------------------------------------------------------
	 * Database Logging
	 * ----------------------------------------------------------------*/

	/**
	 * Log a fraud assessment to the wcet_fraud_log table.
	 *
	 * @param int    $order_id     WooCommerce order ID.
	 * @param int    $risk_score   Calculated risk score (0-100).
	 * @param array  $risk_factors Array of risk factor details.
	 * @param string $action       Action taken (allow, review, block).
	 */
	private function log_fraud_assessment( int $order_id, int $risk_score, array $risk_factors, string $action ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$wpdb->prefix . 'wcet_fraud_log',
			[
				'order_id'     => $order_id,
				'risk_score'   => $risk_score,
				'risk_factors' => wp_json_encode( $risk_factors ),
				'action_taken' => $action,
				'created_at'   => current_time( 'mysql' ),
			],
			[ '%d', '%f', '%s', '%s', '%s' ]
		);
	}

	/* ------------------------------------------------------------------
	 * Admin UI
	 * ----------------------------------------------------------------*/

	/**
	 * Display a fraud assessment summary on the order edit page.
	 *
	 * @param WC_Order $order WooCommerce order.
	 */
	public function display_fraud_assessment( WC_Order $order ) {
		$score  = (int) $order->get_meta( '_wcet_fraud_score' );
		$action = $order->get_meta( '_wcet_fraud_action' );

		if ( empty( $action ) ) {
			return;
		}

		$color = '#46b450'; // Green: allow.
		if ( 'review' === $action ) {
			$color = '#f0ad4e';
		} elseif ( 'block' === $action ) {
			$color = '#d32f2f';
		}

		echo '<div class="wcet-fraud-summary" style="margin-top:16px;padding:10px;border-left:4px solid ' . esc_attr( $color ) . ';background:#f9f9f9;">';
		echo '<h4 style="margin:0 0 6px;">' . esc_html__( 'Fraud Assessment', 'wc-enterprise' ) . '</h4>';
		printf(
			'<p style="margin:0;"><strong>%s:</strong> %d/100 &mdash; <span style="color:%s;font-weight:bold;">%s</span></p>',
			esc_html__( 'Risk Score', 'wc-enterprise' ),
			$score,
			esc_attr( $color ),
			esc_html( ucfirst( $action ) )
		);
		echo '</div>';
	}

	/**
	 * Register the fraud details meta box on the order edit screen.
	 */
	public function add_fraud_meta_box() {
		$screen = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'wcet-fraud-details',
			__( 'Fraud Prevention Details', 'wc-enterprise' ),
			[ $this, 'render_fraud_meta_box' ],
			$screen,
			'side',
			'default'
		);
	}

	/**
	 * Render the fraud details meta box content.
	 *
	 * @param WP_Post|WC_Order $post_or_order Post object or order object (HPOS).
	 */
	public function render_fraud_meta_box( $post_or_order ) {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID );

		if ( ! $order ) {
			echo '<p>' . esc_html__( 'Order not found.', 'wc-enterprise' ) . '</p>';
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$log = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}wcet_fraud_log WHERE order_id = %d ORDER BY id DESC LIMIT 1",
			$order->get_id()
		) );

		if ( ! $log ) {
			echo '<p>' . esc_html__( 'No fraud assessment found for this order.', 'wc-enterprise' ) . '</p>';
			return;
		}

		$risk_factors = json_decode( $log->risk_factors, true );
		$score        = (float) $log->risk_score;
		$action       = $log->action_taken;

		// Score indicator.
		$color = '#46b450';
		if ( $score >= $this->thresholds['block'] ) {
			$color = '#d32f2f';
		} elseif ( $score >= $this->thresholds['review'] ) {
			$color = '#f0ad4e';
		}

		echo '<div style="text-align:center;margin-bottom:12px;">';
		printf(
			'<div style="display:inline-block;width:60px;height:60px;line-height:60px;border-radius:50%%;background:%s;color:#fff;font-size:18px;font-weight:bold;">%d</div>',
			esc_attr( $color ),
			(int) $score
		);
		printf(
			'<p style="margin:6px 0 0;font-weight:bold;text-transform:uppercase;color:%s;">%s</p>',
			esc_attr( $color ),
			esc_html( $action )
		);
		echo '</div>';

		// Risk factors table.
		if ( ! empty( $risk_factors ) && is_array( $risk_factors ) ) {
			echo '<table class="widefat fixed" style="margin-top:8px;">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Factor', 'wc-enterprise' ) . '</th>';
			echo '<th style="width:50px;text-align:center;">' . esc_html__( 'Score', 'wc-enterprise' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $risk_factors as $factor ) {
				echo '<tr>';
				echo '<td>' . esc_html( $factor['detail'] ?? $factor['factor'] ?? '' ) . '</td>';
				echo '<td style="text-align:center;font-weight:bold;">' . esc_html( $factor['score'] ?? 0 ) . '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
		} else {
			echo '<p style="color:#999;">' . esc_html__( 'No risk factors detected.', 'wc-enterprise' ) . '</p>';
		}

		// Timestamp.
		echo '<p style="margin-top:8px;font-size:11px;color:#999;">';
		printf(
			/* translators: %s: assessment date/time */
			esc_html__( 'Assessed: %s', 'wc-enterprise' ),
			esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $log->created_at ) ) )
		);
		echo '</p>';
	}

	/* ------------------------------------------------------------------
	 * Admin Settings
	 * ----------------------------------------------------------------*/

	/**
	 * Add the Fraud Prevention settings tab.
	 *
	 * @param array $tabs Existing WooCommerce settings tabs.
	 * @return array
	 */
	public function add_settings_tab( array $tabs ) {
		$tabs['wcet_fraud'] = __( 'Fraud Prevention', 'wc-enterprise' );
		return $tabs;
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		woocommerce_admin_fields( $this->get_settings() );
	}

	/**
	 * Save the settings.
	 */
	public function save_settings() {
		woocommerce_update_options( $this->get_settings() );

		// Reload thresholds.
		$this->thresholds['review'] = (int) get_option( 'wcet_fraud_review_threshold', 30 );
		$this->thresholds['block']  = (int) get_option( 'wcet_fraud_block_threshold', 60 );
	}

	/**
	 * Get the fraud prevention settings fields.
	 *
	 * @return array WooCommerce settings field definitions.
	 */
	private function get_settings() {
		return [
			[
				'title' => __( 'Fraud Prevention Settings', 'wc-enterprise' ),
				'type'  => 'title',
				'id'    => 'wcet_fraud_settings',
				'desc'  => __( 'Configure risk score thresholds and fraud detection behavior.', 'wc-enterprise' ),
			],
			[
				'title'    => __( 'Enable Fraud Prevention', 'wc-enterprise' ),
				'id'       => 'wcet_fraud_enabled',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'desc'     => __( 'Enable real-time fraud risk scoring for all checkout orders.', 'wc-enterprise' ),
			],
			[
				'title'             => __( 'Review Threshold', 'wc-enterprise' ),
				'id'                => 'wcet_fraud_review_threshold',
				'type'              => 'number',
				'default'           => 30,
				'desc'              => __( 'Orders with a risk score at or above this value will be flagged for manual review (set to Awaiting Verification status).', 'wc-enterprise' ),
				'desc_tip'          => true,
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 99,
					'step' => 1,
				],
			],
			[
				'title'             => __( 'Block Threshold', 'wc-enterprise' ),
				'id'                => 'wcet_fraud_block_threshold',
				'type'              => 'number',
				'default'           => 60,
				'desc'              => __( 'Orders with a risk score at or above this value will be automatically blocked (set to Failed status).', 'wc-enterprise' ),
				'desc_tip'          => true,
				'custom_attributes' => [
					'min'  => 1,
					'max'  => 100,
					'step' => 1,
				],
			],
			[
				'title'             => __( 'High Value Threshold', 'wc-enterprise' ),
				'id'                => 'wcet_fraud_new_customer_high_value_threshold',
				'type'              => 'number',
				'default'           => 300,
				'desc'              => __( 'Orders above this amount from first-time customers receive additional scrutiny.', 'wc-enterprise' ),
				'desc_tip'          => true,
				'custom_attributes' => [
					'min'  => 0,
					'step' => 1,
				],
			],
			[
				'title'    => __( 'IP Reputation API Key', 'wc-enterprise' ),
				'id'       => 'wcet_fraud_ip_api_key',
				'type'     => 'password',
				'default'  => '',
				'desc'     => __( 'Optional API key for external IP reputation checking (e.g. vpnapi.io).', 'wc-enterprise' ),
				'desc_tip' => true,
			],
			[
				'title'    => __( 'Debug Logging', 'wc-enterprise' ),
				'id'       => 'wcet_fraud_logging',
				'type'     => 'checkbox',
				'default'  => 'no',
				'desc'     => __( 'Log fraud prevention details. Find logs at WooCommerce > Status > Logs.', 'wc-enterprise' ),
			],
			[
				'type' => 'sectionend',
				'id'   => 'wcet_fraud_settings',
			],
		];
	}

	/* ------------------------------------------------------------------
	 * Utilities
	 * ----------------------------------------------------------------*/

	/**
	 * Log a message to the WC logger.
	 *
	 * @param string $level   Log level (debug, info, warning, error).
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	private function log( string $level, string $message, array $context = [] ) {
		if ( 'yes' !== get_option( 'wcet_fraud_logging', 'no' ) ) {
			return;
		}

		if ( null === $this->logger ) {
			$this->logger = wc_get_logger();
		}

		$this->logger->log( $level, $message . ( $context ? ' | ' . wc_print_r( $context, true ) : '' ), [ 'source' => 'wcet-fraud' ] );
	}
}

WCET_Fraud_Prevention::instance();
