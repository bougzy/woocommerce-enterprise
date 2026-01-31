<?php
/**
 * Paystack Payment Gateway
 *
 * Custom Paystack integration supporting NGN, GHS, ZAR, and USD currencies.
 * Redirects customers to Paystack checkout and verifies transactions on return.
 *
 * @package WCET\Payments
 */

defined( 'ABSPATH' ) || exit;

class WCET_Paystack_Gateway extends WC_Payment_Gateway {

	/**
	 * Paystack API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://api.paystack.co';

	/**
	 * Currencies supported by Paystack.
	 *
	 * @var string[]
	 */
	private const SUPPORTED_CURRENCIES = [ 'NGN', 'GHS', 'ZAR', 'USD' ];

	/**
	 * WC Logger instance.
	 *
	 * @var WC_Logger|null
	 */
	private $logger = null;

	/**
	 * Constructor — set up gateway properties and hooks.
	 */
	public function __construct() {
		$this->id                 = 'wcet_paystack';
		$this->icon               = apply_filters( 'wcet_paystack_icon', WCET_PLUGIN_URL . 'assets/images/paystack.svg' );
		$this->has_fields         = false;
		$this->method_title       = __( 'Paystack (Enterprise)', 'wc-enterprise' );
		$this->method_description = __( 'Accept payments via Paystack — supports NGN, GHS, ZAR, and USD.', 'wc-enterprise' );
		$this->supports           = [
			'products',
		];

		// Load settings.
		$this->init_form_fields();
		$this->init_settings();

		// User-facing properties.
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		// API credentials.
		$this->public_key = $this->is_test_mode()
			? $this->get_option( 'test_public_key' )
			: $this->get_option( 'live_public_key' );

		$this->secret_key = $this->is_test_mode()
			? $this->get_option( 'test_secret_key' )
			: $this->get_option( 'live_secret_key' );

		$this->webhook_secret = $this->is_test_mode()
			? $this->get_option( 'test_webhook_secret' )
			: $this->get_option( 'live_webhook_secret' );

		// Hooks.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_api_wcet_paystack_callback', [ $this, 'handle_callback' ] );
		add_action( 'woocommerce_receipt_' . $this->id, [ $this, 'receipt_page' ] );
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ----------------------------------------------------------------*/

	/**
	 * Define gateway settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = [
			'enabled'             => [
				'title'   => __( 'Enable/Disable', 'wc-enterprise' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Paystack Payments', 'wc-enterprise' ),
				'default' => 'no',
			],
			'title'               => [
				'title'       => __( 'Title', 'wc-enterprise' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to customers during checkout.', 'wc-enterprise' ),
				'default'     => __( 'Pay with Paystack', 'wc-enterprise' ),
				'desc_tip'    => true,
			],
			'description'         => [
				'title'       => __( 'Description', 'wc-enterprise' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown to customers during checkout.', 'wc-enterprise' ),
				'default'     => __( 'Pay securely using Paystack. Accepts cards, bank transfers, USSD, and mobile money.', 'wc-enterprise' ),
				'desc_tip'    => true,
			],
			'test_mode'           => [
				'title'       => __( 'Test Mode', 'wc-enterprise' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable test mode', 'wc-enterprise' ),
				'description' => __( 'Use Paystack test API keys for development and testing.', 'wc-enterprise' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			],
			'test_public_key'     => [
				'title'       => __( 'Test Public Key', 'wc-enterprise' ),
				'type'        => 'text',
				'description' => __( 'Your Paystack test public key (starts with pk_test_).', 'wc-enterprise' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'test_secret_key'     => [
				'title'       => __( 'Test Secret Key', 'wc-enterprise' ),
				'type'        => 'password',
				'description' => __( 'Your Paystack test secret key (starts with sk_test_).', 'wc-enterprise' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'test_webhook_secret' => [
				'title'       => __( 'Test Webhook Secret', 'wc-enterprise' ),
				'type'        => 'password',
				'description' => __( 'Your Paystack test webhook secret for verifying webhook signatures.', 'wc-enterprise' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'live_public_key'     => [
				'title'       => __( 'Live Public Key', 'wc-enterprise' ),
				'type'        => 'text',
				'description' => __( 'Your Paystack live public key (starts with pk_live_).', 'wc-enterprise' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'live_secret_key'     => [
				'title'       => __( 'Live Secret Key', 'wc-enterprise' ),
				'type'        => 'password',
				'description' => __( 'Your Paystack live secret key (starts with sk_live_).', 'wc-enterprise' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'live_webhook_secret' => [
				'title'       => __( 'Live Webhook Secret', 'wc-enterprise' ),
				'type'        => 'password',
				'description' => __( 'Your Paystack live webhook secret for verifying webhook signatures.', 'wc-enterprise' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'logging'             => [
				'title'       => __( 'Logging', 'wc-enterprise' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable debug logging', 'wc-enterprise' ),
				'description' => __( 'Logs Paystack API interactions. Find logs at WooCommerce > Status > Logs.', 'wc-enterprise' ),
				'default'     => 'no',
				'desc_tip'    => true,
			],
		];
	}

	/* ------------------------------------------------------------------
	 * Availability
	 * ----------------------------------------------------------------*/

	/**
	 * Check whether the gateway is available for use.
	 *
	 * @return bool
	 */
	public function is_available() {
		if ( 'yes' !== $this->enabled ) {
			return false;
		}

		if ( empty( $this->public_key ) || empty( $this->secret_key ) ) {
			return false;
		}

		// Validate that the store currency is supported.
		if ( ! in_array( get_woocommerce_currency(), self::SUPPORTED_CURRENCIES, true ) ) {
			return false;
		}

		return true;
	}

	/* ------------------------------------------------------------------
	 * Front-end
	 * ----------------------------------------------------------------*/

	/**
	 * Render payment fields on the checkout form.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		if ( $this->is_test_mode() ) {
			echo '<p class="wcet-paystack-test-notice" style="color:#ff6d00;font-size:0.85em;margin:8px 0;">';
			echo esc_html__( 'TEST MODE ENABLED — No real charges will be made.', 'wc-enterprise' );
			echo '</p>';
		}
	}

	/**
	 * Display a message on the receipt/order-pay page before redirecting to Paystack.
	 *
	 * @param int $order_id WooCommerce order ID.
	 */
	public function receipt_page( int $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		echo '<p>' . esc_html__( 'Thank you for your order. You will be redirected to Paystack to complete your payment.', 'wc-enterprise' ) . '</p>';
		echo '<p><a id="wcet-paystack-redirect" class="button alt" href="#">' . esc_html__( 'Pay Now', 'wc-enterprise' ) . '</a></p>';

		// Output inline script to auto-redirect.
		$redirect_url = $order->get_meta( '_wcet_paystack_authorization_url' );

		if ( $redirect_url ) {
			echo '<script type="text/javascript">';
			echo 'document.getElementById("wcet-paystack-redirect").href = ' . wp_json_encode( esc_url( $redirect_url ) ) . ';';
			echo 'setTimeout(function(){window.location.href=' . wp_json_encode( esc_url( $redirect_url ) ) . ';}, 2000);';
			echo '</script>';
		}
	}

	/* ------------------------------------------------------------------
	 * Payment processing
	 * ----------------------------------------------------------------*/

	/**
	 * Process the payment — initialize a Paystack transaction and redirect.
	 *
	 * @param int $order_id WooCommerce order ID.
	 * @return array{result: string, redirect?: string}
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			wc_add_notice( __( 'Order not found. Please try again.', 'wc-enterprise' ), 'error' );
			return [ 'result' => 'fail' ];
		}

		try {
			$transaction = $this->initialize_transaction( $order );

			if ( is_wp_error( $transaction ) ) {
				$this->log( 'error', 'Transaction initialization failed: ' . $transaction->get_error_message(), [ 'order_id' => $order_id ] );
				wc_add_notice( $transaction->get_error_message(), 'error' );
				return [ 'result' => 'fail' ];
			}

			// Store the reference and authorization URL.
			$order->update_meta_data( '_wcet_paystack_reference', sanitize_text_field( $transaction['data']['reference'] ) );
			$order->update_meta_data( '_wcet_paystack_authorization_url', esc_url_raw( $transaction['data']['authorization_url'] ) );
			$order->update_meta_data( '_wcet_paystack_access_code', sanitize_text_field( $transaction['data']['access_code'] ) );
			$order->add_order_note(
				sprintf(
					/* translators: %s: Paystack transaction reference */
					__( 'Paystack transaction initialized. Reference: %s', 'wc-enterprise' ),
					$transaction['data']['reference']
				)
			);
			$order->save();

			$this->log( 'info', 'Transaction initialized.', [
				'order_id'  => $order_id,
				'reference' => $transaction['data']['reference'],
			] );

			// Redirect to Paystack checkout.
			return [
				'result'   => 'success',
				'redirect' => $transaction['data']['authorization_url'],
			];

		} catch ( \Exception $e ) {
			$this->log( 'error', 'Payment exception: ' . $e->getMessage(), [ 'order_id' => $order_id ] );
			wc_add_notice( __( 'Payment processing failed. Please try again.', 'wc-enterprise' ), 'error' );
			return [ 'result' => 'fail' ];
		}
	}

	/**
	 * Handle the callback from Paystack after payment.
	 */
	public function handle_callback() {
		$reference = isset( $_GET['reference'] ) ? sanitize_text_field( wp_unslash( $_GET['reference'] ) ) : '';
		$trxref    = isset( $_GET['trxref'] ) ? sanitize_text_field( wp_unslash( $_GET['trxref'] ) ) : '';

		// Use trxref as fallback if reference is not provided.
		$reference = $reference ?: $trxref;

		if ( empty( $reference ) ) {
			$this->log( 'error', 'Callback received without a reference.' );
			wc_add_notice( __( 'Payment verification failed: missing reference.', 'wc-enterprise' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// Find the order by reference.
		$order = $this->get_order_by_reference( $reference );

		if ( ! $order ) {
			$this->log( 'error', 'Order not found for reference.', [ 'reference' => $reference ] );
			wc_add_notice( __( 'Order not found. Please contact support.', 'wc-enterprise' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// Do not verify if already paid — idempotency.
		if ( $order->is_paid() ) {
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		// Verify the transaction with Paystack.
		$verification = $this->verify_transaction( $reference );

		if ( is_wp_error( $verification ) ) {
			$this->log( 'error', 'Transaction verification failed: ' . $verification->get_error_message(), [
				'order_id'  => $order->get_id(),
				'reference' => $reference,
			] );
			$order->update_status( 'failed', __( 'Paystack: Transaction verification failed.', 'wc-enterprise' ) );
			wc_add_notice( __( 'Payment verification failed. Please try again or contact support.', 'wc-enterprise' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$this->process_verified_transaction( $order, $verification['data'] );
		wp_safe_redirect( $this->get_return_url( $order ) );
		exit;
	}

	/**
	 * Process a verified transaction and update the order.
	 *
	 * @param WC_Order $order            WooCommerce order.
	 * @param array    $transaction_data Verified transaction data from Paystack.
	 */
	public function process_verified_transaction( WC_Order $order, array $transaction_data ) {
		// Idempotency: do not process twice.
		if ( $order->is_paid() ) {
			return;
		}

		$status    = $transaction_data['status'] ?? '';
		$reference = $transaction_data['reference'] ?? '';
		$amount    = isset( $transaction_data['amount'] ) ? (int) $transaction_data['amount'] : 0;
		$currency  = strtoupper( $transaction_data['currency'] ?? '' );
		$channel   = $transaction_data['channel'] ?? '';

		// Verify the amount matches.
		$expected_amount = $this->get_paystack_amount( $order->get_total(), $order->get_currency() );

		if ( $amount < $expected_amount ) {
			$order->update_status(
				'on-hold',
				sprintf(
					/* translators: 1: paid amount 2: expected amount */
					__( 'Paystack: Partial payment received. Paid %1$s, expected %2$s.', 'wc-enterprise' ),
					$amount / 100,
					$expected_amount / 100
				)
			);
			$this->log( 'warning', 'Amount mismatch.', [
				'order_id' => $order->get_id(),
				'paid'     => $amount,
				'expected' => $expected_amount,
			] );
			return;
		}

		if ( 'success' === $status ) {
			$order->payment_complete( $reference );
			$order->update_meta_data( '_wcet_paystack_channel', sanitize_text_field( $channel ) );
			$order->update_meta_data( '_wcet_paystack_currency', sanitize_text_field( $currency ) );
			$order->update_meta_data( '_wcet_paystack_status', 'success' );

			// Store authorization data for potential future charges.
			if ( ! empty( $transaction_data['authorization'] ) ) {
				$order->update_meta_data( '_wcet_paystack_auth_code', sanitize_text_field( $transaction_data['authorization']['authorization_code'] ?? '' ) );
				$order->update_meta_data( '_wcet_paystack_card_last4', sanitize_text_field( $transaction_data['authorization']['last4'] ?? '' ) );
				$order->update_meta_data( '_wcet_paystack_card_brand', sanitize_text_field( $transaction_data['authorization']['brand'] ?? '' ) );
			}

			$order->add_order_note(
				sprintf(
					/* translators: 1: Paystack reference 2: payment channel */
					__( 'Paystack payment successful. Reference: %1$s. Channel: %2$s.', 'wc-enterprise' ),
					$reference,
					$channel
				)
			);
			$order->save();

			WC()->cart->empty_cart();

			$this->log( 'info', 'Payment completed.', [
				'order_id'  => $order->get_id(),
				'reference' => $reference,
				'channel'   => $channel,
			] );
		} else {
			$order->update_status( 'failed',
				sprintf(
					/* translators: 1: Paystack transaction status 2: gateway response */
					__( 'Paystack: Payment %1$s. Gateway response: %2$s', 'wc-enterprise' ),
					$status,
					$transaction_data['gateway_response'] ?? __( 'N/A', 'wc-enterprise' )
				)
			);
			$this->log( 'warning', 'Payment not successful.', [
				'order_id' => $order->get_id(),
				'status'   => $status,
			] );
		}
	}

	/* ------------------------------------------------------------------
	 * Paystack API helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Initialize a transaction via the Paystack API.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array|WP_Error
	 */
	private function initialize_transaction( WC_Order $order ) {
		$amount   = $this->get_paystack_amount( $order->get_total(), $order->get_currency() );
		$currency = strtoupper( $order->get_currency() );

		$body = [
			'email'        => $order->get_billing_email(),
			'amount'       => $amount,
			'currency'     => $currency,
			'reference'    => $this->generate_reference( $order ),
			'callback_url' => WC()->api_request_url( 'wcet_paystack_callback' ),
			'metadata'     => wp_json_encode( [
				'order_id'       => $order->get_id(),
				'order_number'   => $order->get_order_number(),
				'customer_name'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
				'customer_phone' => $order->get_billing_phone(),
				'site_url'       => get_site_url(),
				'custom_fields'  => [
					[
						'display_name'  => 'Order Number',
						'variable_name' => 'order_number',
						'value'         => $order->get_order_number(),
					],
					[
						'display_name'  => 'Customer Name',
						'variable_name' => 'customer_name',
						'value'         => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
					],
				],
			] ),
		];

		$body = apply_filters( 'wcet_paystack_initialize_args', $body, $order );

		return $this->api_request( '/transaction/initialize', $body );
	}

	/**
	 * Verify a transaction via the Paystack API.
	 *
	 * @param string $reference Transaction reference.
	 * @return array|WP_Error
	 */
	public function verify_transaction( string $reference ) {
		return $this->api_request( '/transaction/verify/' . rawurlencode( $reference ), [], 'GET' );
	}

	/**
	 * Make an authenticated request to the Paystack API.
	 *
	 * @param string $endpoint API endpoint (relative to base URL).
	 * @param array  $body     Request body.
	 * @param string $method   HTTP method.
	 * @return array|WP_Error
	 */
	private function api_request( string $endpoint, array $body = [], string $method = 'POST' ) {
		$url = self::API_BASE . $endpoint;

		$args = [
			'method'  => $method,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->secret_key,
				'Content-Type'  => 'application/json',
				'Cache-Control' => 'no-cache',
			],
			'timeout' => 60,
		];

		if ( 'POST' === $method && ! empty( $body ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$this->log( 'debug', sprintf( 'API request: %s %s', $method, $endpoint ) );

		$response = ( 'GET' === $method )
			? wp_remote_get( $url, $args )
			: wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wcet_paystack_api_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Paystack API request failed: %s', 'wc-enterprise' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return new WP_Error(
				'wcet_paystack_invalid_response',
				__( 'Invalid response received from Paystack.', 'wc-enterprise' )
			);
		}

		if ( $code >= 400 || empty( $data['status'] ) ) {
			$message = $data['message'] ?? __( 'Unknown Paystack API error.', 'wc-enterprise' );

			$this->log( 'error', sprintf( 'API error (%d): %s', $code, $message ) );

			return new WP_Error( 'wcet_paystack_api_error', $message );
		}

		return $data;
	}

	/* ------------------------------------------------------------------
	 * Utilities
	 * ----------------------------------------------------------------*/

	/**
	 * Check whether test mode is enabled.
	 *
	 * @return bool
	 */
	public function is_test_mode() {
		return 'yes' === $this->get_option( 'test_mode', 'yes' );
	}

	/**
	 * Convert an order amount to kobo/pesewas (smallest unit) for Paystack.
	 *
	 * @param float  $amount   Amount in standard currency units.
	 * @param string $currency ISO 4217 currency code.
	 * @return int Amount in smallest currency unit.
	 */
	private function get_paystack_amount( float $amount, string $currency ) {
		// All Paystack currencies use 2 decimal places.
		return absint( round( $amount * 100 ) );
	}

	/**
	 * Generate a unique transaction reference.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return string
	 */
	private function generate_reference( WC_Order $order ) {
		return 'wcet_' . $order->get_id() . '_' . bin2hex( random_bytes( 8 ) );
	}

	/**
	 * Find an order by its Paystack reference.
	 *
	 * @param string $reference Paystack transaction reference.
	 * @return WC_Order|null
	 */
	public function get_order_by_reference( string $reference ): ?WC_Order {
		$orders = wc_get_orders( [
			'meta_key'   => '_wcet_paystack_reference', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => sanitize_text_field( $reference ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'limit'      => 1,
		] );

		return ! empty( $orders ) ? $orders[0] : null;
	}

	/**
	 * Get the webhook secret for the current mode.
	 *
	 * @return string
	 */
	public function get_webhook_secret() {
		return $this->webhook_secret;
	}

	/**
	 * Get the secret key for the current mode.
	 *
	 * @return string
	 */
	public function get_secret_key() {
		return $this->secret_key;
	}

	/**
	 * Log a message if logging is enabled.
	 *
	 * @param string $level   Log level (debug, info, warning, error).
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	private function log( string $level, string $message, array $context = [] ) {
		if ( 'yes' !== $this->get_option( 'logging', 'no' ) ) {
			return;
		}

		if ( null === $this->logger ) {
			$this->logger = wc_get_logger();
		}

		$this->logger->log( $level, $message . ( $context ? ' | ' . wc_print_r( $context, true ) : '' ), [ 'source' => 'wcet-paystack' ] );
	}
}

// Register the gateway with WooCommerce.
add_filter( 'woocommerce_payment_gateways', function ( array $gateways ) {
	$gateways[] = 'WCET_Paystack_Gateway';
	return $gateways;
} );
