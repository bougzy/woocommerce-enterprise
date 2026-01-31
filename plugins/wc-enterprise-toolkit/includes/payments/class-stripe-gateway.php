<?php
/**
 * Stripe Payment Gateway
 *
 * Custom Stripe integration using the Payment Intents API with
 * 3D Secure / SCA support, refund handling, and proper error management.
 *
 * @package WCET\Payments
 */

defined( 'ABSPATH' ) || exit;

class WCET_Stripe_Gateway extends WC_Payment_Gateway {

	/**
	 * Stripe API base URL.
	 *
	 * @var string
	 */
	private const API_BASE = 'https://api.stripe.com/v1';

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
		$this->id                 = 'wcet_stripe';
		$this->icon               = apply_filters( 'wcet_stripe_icon', WCET_PLUGIN_URL . 'assets/images/stripe.svg' );
		$this->has_fields         = true;
		$this->method_title       = __( 'Stripe (Enterprise)', 'wc-enterprise' );
		$this->method_description = __( 'Accept credit and debit card payments via Stripe Payment Intents with SCA/3D Secure support.', 'wc-enterprise' );
		$this->supports           = [
			'products',
			'refunds',
		];

		// Load settings form fields and saved settings.
		$this->init_form_fields();
		$this->init_settings();

		// User-facing properties.
		$this->title       = $this->get_option( 'title' );
		$this->description = $this->get_option( 'description' );
		$this->enabled     = $this->get_option( 'enabled' );

		// API credentials.
		$this->publishable_key = $this->is_test_mode()
			? $this->get_option( 'test_publishable_key' )
			: $this->get_option( 'live_publishable_key' );

		$this->secret_key = $this->is_test_mode()
			? $this->get_option( 'test_secret_key' )
			: $this->get_option( 'live_secret_key' );

		$this->webhook_secret = $this->is_test_mode()
			? $this->get_option( 'test_webhook_secret' )
			: $this->get_option( 'live_webhook_secret' );

		// Hooks.
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		add_action( 'woocommerce_api_wcet_stripe_return', [ $this, 'handle_redirect_return' ] );
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * ----------------------------------------------------------------*/

	/**
	 * Define gateway settings fields.
	 */
	public function init_form_fields() {
		$this->form_fields = [
			'enabled'              => [
				'title'   => __( 'Enable/Disable', 'wc-enterprise' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable Stripe Payments', 'wc-enterprise' ),
				'default' => 'no',
			],
			'title'                => [
				'title'       => __( 'Title', 'wc-enterprise' ),
				'type'        => 'text',
				'description' => __( 'Payment method title shown to customers during checkout.', 'wc-enterprise' ),
				'default'     => __( 'Credit / Debit Card', 'wc-enterprise' ),
				'desc_tip'    => true,
			],
			'description'          => [
				'title'       => __( 'Description', 'wc-enterprise' ),
				'type'        => 'textarea',
				'description' => __( 'Payment method description shown to customers during checkout.', 'wc-enterprise' ),
				'default'     => __( 'Pay securely using your credit or debit card.', 'wc-enterprise' ),
				'desc_tip'    => true,
			],
			'test_mode'            => [
				'title'       => __( 'Test Mode', 'wc-enterprise' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable test mode', 'wc-enterprise' ),
				'description' => __( 'Use Stripe test API keys for development and testing.', 'wc-enterprise' ),
				'default'     => 'yes',
				'desc_tip'    => true,
			],
			'test_publishable_key' => [
				'title'       => __( 'Test Publishable Key', 'wc-enterprise' ),
				'type'        => 'text',
				'description' => __( 'Your Stripe test publishable key (starts with pk_test_).', 'wc-enterprise' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'test_secret_key'      => [
				'title'       => __( 'Test Secret Key', 'wc-enterprise' ),
				'type'        => 'password',
				'description' => __( 'Your Stripe test secret key (starts with sk_test_).', 'wc-enterprise' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'test_webhook_secret'  => [
				'title'       => __( 'Test Webhook Secret', 'wc-enterprise' ),
				'type'        => 'password',
				'description' => __( 'Your Stripe test webhook signing secret (starts with whsec_).', 'wc-enterprise' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'live_publishable_key' => [
				'title'       => __( 'Live Publishable Key', 'wc-enterprise' ),
				'type'        => 'text',
				'description' => __( 'Your Stripe live publishable key (starts with pk_live_).', 'wc-enterprise' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'live_secret_key'      => [
				'title'       => __( 'Live Secret Key', 'wc-enterprise' ),
				'type'        => 'password',
				'description' => __( 'Your Stripe live secret key (starts with sk_live_).', 'wc-enterprise' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'live_webhook_secret'  => [
				'title'       => __( 'Live Webhook Secret', 'wc-enterprise' ),
				'type'        => 'password',
				'description' => __( 'Your Stripe live webhook signing secret (starts with whsec_).', 'wc-enterprise' ),
				'default'     => '',
				'desc_tip'    => true,
			],
			'logging'              => [
				'title'       => __( 'Logging', 'wc-enterprise' ),
				'type'        => 'checkbox',
				'label'       => __( 'Enable debug logging', 'wc-enterprise' ),
				'description' => __( 'Logs Stripe API interactions. Find logs at WooCommerce > Status > Logs.', 'wc-enterprise' ),
				'default'     => 'no',
				'desc_tip'    => true,
			],
		];
	}

	/* ------------------------------------------------------------------
	 * Front-end
	 * ----------------------------------------------------------------*/

	/**
	 * Enqueue Stripe.js and our checkout handler on the checkout page.
	 */
	public function enqueue_scripts() {
		if ( ! is_checkout() || ! $this->is_available() ) {
			return;
		}

		wp_enqueue_script(
			'stripe-js',
			'https://js.stripe.com/v3/',
			[],
			null, // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- external CDN
			true
		);

		wp_enqueue_script(
			'wcet-stripe-checkout',
			WCET_PLUGIN_URL . 'assets/js/stripe-checkout.js',
			[ 'jquery', 'stripe-js' ],
			WCET_VERSION,
			true
		);

		wp_localize_script( 'wcet-stripe-checkout', 'wcetStripeParams', [
			'publishableKey' => $this->publishable_key,
			'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
			'nonce'          => wp_create_nonce( 'wcet_stripe_nonce' ),
			'returnUrl'      => WC()->api_request_url( 'wcet_stripe_return' ),
			'i18n'           => [
				'error' => __( 'An error occurred. Please try again.', 'wc-enterprise' ),
			],
		] );
	}

	/**
	 * Render payment fields on the checkout form.
	 */
	public function payment_fields() {
		if ( $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}

		if ( $this->is_test_mode() ) {
			echo '<p class="wcet-stripe-test-notice" style="color:#ff6d00;font-size:0.85em;margin:8px 0;">';
			echo esc_html__( 'TEST MODE ENABLED — No real charges will be made.', 'wc-enterprise' );
			echo '</p>';
		}

		// Stripe Elements mount point.
		echo '<div id="wcet-stripe-card-element" style="padding:12px;border:1px solid #ddd;border-radius:4px;margin-top:10px;"></div>';
		echo '<div id="wcet-stripe-card-errors" role="alert" style="color:#d32f2f;font-size:0.85em;margin-top:6px;"></div>';

		// Hidden field to store the PaymentIntent client secret.
		echo '<input type="hidden" id="wcet_stripe_payment_intent" name="wcet_stripe_payment_intent" value="" />';
	}

	/* ------------------------------------------------------------------
	 * Payment processing
	 * ----------------------------------------------------------------*/

	/**
	 * Process the payment — create a PaymentIntent and confirm it.
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
			// Check for a pre-existing intent from a previous attempt.
			$existing_intent_id = $order->get_meta( '_wcet_stripe_intent_id' );
			if ( $existing_intent_id ) {
				$intent = $this->retrieve_payment_intent( $existing_intent_id );
				if ( ! is_wp_error( $intent ) && in_array( $intent['status'], [ 'requires_payment_method', 'requires_confirmation' ], true ) ) {
					// Re-confirm the existing intent.
					$intent = $this->confirm_payment_intent( $existing_intent_id );
				} elseif ( is_wp_error( $intent ) ) {
					// Previous intent is invalid; create a new one.
					$intent = $this->create_payment_intent( $order );
				}
			} else {
				$intent = $this->create_payment_intent( $order );
			}

			if ( is_wp_error( $intent ) ) {
				$this->log( 'error', 'PaymentIntent creation failed: ' . $intent->get_error_message(), [ 'order_id' => $order_id ] );
				wc_add_notice( $intent->get_error_message(), 'error' );
				return [ 'result' => 'fail' ];
			}

			// Store intent ID on the order.
			$order->update_meta_data( '_wcet_stripe_intent_id', sanitize_text_field( $intent['id'] ) );
			$order->save();

			// Handle the response based on status.
			return $this->handle_intent_status( $intent, $order );

		} catch ( \Exception $e ) {
			$this->log( 'error', 'Payment exception: ' . $e->getMessage(), [ 'order_id' => $order_id ] );
			wc_add_notice( __( 'Payment processing failed. Please try again.', 'wc-enterprise' ), 'error' );
			return [ 'result' => 'fail' ];
		}
	}

	/**
	 * Handle the PaymentIntent status and return the appropriate response.
	 *
	 * @param array    $intent Payment intent data from Stripe.
	 * @param WC_Order $order  WooCommerce order.
	 * @return array{result: string, redirect?: string}
	 */
	private function handle_intent_status( array $intent, WC_Order $order ) {
		switch ( $intent['status'] ) {
			case 'succeeded':
				return $this->complete_payment( $order, $intent );

			case 'requires_action':
			case 'requires_source_action':
				// 3D Secure / SCA — redirect the customer for authentication.
				$redirect_url = $intent['next_action']['redirect_to_url']['url'] ?? '';
				if ( empty( $redirect_url ) ) {
					// For client-side confirmation, return the client secret.
					return [
						'result'              => 'success',
						'redirect'            => $this->get_return_url( $order ),
						'intent_client_secret' => $intent['client_secret'],
						'requires_action'     => true,
					];
				}
				return [
					'result'   => 'success',
					'redirect' => $redirect_url,
				];

			case 'requires_payment_method':
				wc_add_notice( __( 'Your card was declined. Please try a different payment method.', 'wc-enterprise' ), 'error' );
				$order->update_status( 'failed', __( 'Stripe: Card was declined.', 'wc-enterprise' ) );
				return [ 'result' => 'fail' ];

			case 'processing':
				// The payment is asynchronous; mark the order as on-hold.
				$order->update_status( 'on-hold', __( 'Stripe payment is processing asynchronously.', 'wc-enterprise' ) );
				$order->update_meta_data( '_wcet_stripe_status', 'processing' );
				$order->save();
				WC()->cart->empty_cart();
				return [
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				];

			default:
				$this->log( 'error', 'Unexpected PaymentIntent status: ' . $intent['status'], [ 'order_id' => $order->get_id() ] );
				wc_add_notice( __( 'An unexpected error occurred. Please try again.', 'wc-enterprise' ), 'error' );
				return [ 'result' => 'fail' ];
		}
	}

	/**
	 * Complete a successful payment.
	 *
	 * @param WC_Order $order  WooCommerce order.
	 * @param array    $intent Stripe PaymentIntent data.
	 * @return array{result: string, redirect: string}
	 */
	private function complete_payment( WC_Order $order, array $intent ) {
		// Prevent double-processing.
		if ( $order->is_paid() ) {
			return [
				'result'   => 'success',
				'redirect' => $this->get_return_url( $order ),
			];
		}

		$charge_id = $intent['latest_charge'] ?? '';

		$order->payment_complete( $charge_id );
		$order->update_meta_data( '_wcet_stripe_status', 'succeeded' );
		$order->update_meta_data( '_wcet_stripe_charge_id', sanitize_text_field( $charge_id ) );
		$order->add_order_note(
			sprintf(
				/* translators: 1: Stripe charge ID */
				__( 'Stripe payment complete. Charge ID: %s', 'wc-enterprise' ),
				$charge_id
			)
		);
		$order->save();

		WC()->cart->empty_cart();

		$this->log( 'info', 'Payment completed successfully.', [
			'order_id'  => $order->get_id(),
			'charge_id' => $charge_id,
		] );

		return [
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		];
	}

	/**
	 * Handle the redirect return from 3D Secure authentication.
	 */
	public function handle_redirect_return() {
		$intent_id = isset( $_GET['payment_intent'] ) ? sanitize_text_field( wp_unslash( $_GET['payment_intent'] ) ) : '';

		if ( empty( $intent_id ) ) {
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$intent = $this->retrieve_payment_intent( $intent_id );

		if ( is_wp_error( $intent ) ) {
			wc_add_notice( __( 'Unable to verify payment status. Please contact support.', 'wc-enterprise' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		// Find the order by intent ID.
		$orders = wc_get_orders( [
			'meta_key'   => '_wcet_stripe_intent_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $intent_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'limit'      => 1,
		] );

		if ( empty( $orders ) ) {
			wc_add_notice( __( 'Order not found. Please contact support.', 'wc-enterprise' ), 'error' );
			wp_safe_redirect( wc_get_checkout_url() );
			exit;
		}

		$order = $orders[0];

		if ( 'succeeded' === $intent['status'] ) {
			$this->complete_payment( $order, $intent );
			wp_safe_redirect( $this->get_return_url( $order ) );
			exit;
		}

		// Payment failed after 3D Secure.
		$order->update_status( 'failed', __( 'Stripe: Payment failed after 3D Secure authentication.', 'wc-enterprise' ) );
		wc_add_notice( __( 'Payment authentication failed. Please try again.', 'wc-enterprise' ), 'error' );
		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Refunds
	 * ----------------------------------------------------------------*/

	/**
	 * Process a refund via Stripe.
	 *
	 * @param int        $order_id Order ID.
	 * @param float|null $amount   Refund amount.
	 * @param string     $reason   Refund reason.
	 * @return bool|WP_Error
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order     = wc_get_order( $order_id );
		$charge_id = $order ? $order->get_meta( '_wcet_stripe_charge_id' ) : '';

		if ( ! $order || empty( $charge_id ) ) {
			return new WP_Error( 'wcet_stripe_refund_error', __( 'Cannot process refund: missing charge ID.', 'wc-enterprise' ) );
		}

		$body = [
			'charge' => $charge_id,
		];

		if ( null !== $amount ) {
			$body['amount'] = $this->get_stripe_amount( $amount, $order->get_currency() );
		}

		if ( ! empty( $reason ) ) {
			$body['metadata'] = [
				'reason' => sanitize_text_field( $reason ),
			];
		}

		$response = $this->api_request( 'refunds', $body );

		if ( is_wp_error( $response ) ) {
			$this->log( 'error', 'Refund failed: ' . $response->get_error_message(), [ 'order_id' => $order_id ] );
			return $response;
		}

		$order->add_order_note(
			sprintf(
				/* translators: 1: refund amount 2: Stripe refund ID 3: reason */
				__( 'Stripe refund of %1$s processed. Refund ID: %2$s. Reason: %3$s', 'wc-enterprise' ),
				wc_price( $amount, [ 'currency' => $order->get_currency() ] ),
				$response['id'],
				$reason ?: __( 'N/A', 'wc-enterprise' )
			)
		);

		$this->log( 'info', 'Refund processed.', [
			'order_id'  => $order_id,
			'refund_id' => $response['id'],
			'amount'    => $amount,
		] );

		return true;
	}

	/* ------------------------------------------------------------------
	 * Stripe API helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Create a PaymentIntent via the Stripe API.
	 *
	 * @param WC_Order $order WooCommerce order.
	 * @return array|WP_Error
	 */
	private function create_payment_intent( WC_Order $order ) {
		$amount   = $this->get_stripe_amount( $order->get_total(), $order->get_currency() );
		$currency = strtolower( $order->get_currency() );

		$body = [
			'amount'               => $amount,
			'currency'             => $currency,
			'payment_method_types' => [ 'card' ],
			'description'          => sprintf(
				/* translators: 1: blog name 2: order number */
				__( '%1$s — Order #%2$s', 'wc-enterprise' ),
				get_bloginfo( 'name' ),
				$order->get_order_number()
			),
			'metadata'             => [
				'order_id'     => $order->get_id(),
				'order_number' => $order->get_order_number(),
				'customer_email' => $order->get_billing_email(),
				'site_url'     => get_site_url(),
			],
			'receipt_email'        => $order->get_billing_email(),
			'confirm'              => 'true',
			'return_url'           => add_query_arg(
				[
					'order_id' => $order->get_id(),
					'nonce'    => wp_create_nonce( 'wcet_stripe_return_' . $order->get_id() ),
				],
				WC()->api_request_url( 'wcet_stripe_return' )
			),
		];

		// Add shipping details if available.
		if ( $order->has_shipping_address() ) {
			$body['shipping'] = [
				'name'    => $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name(),
				'address' => [
					'line1'       => $order->get_shipping_address_1(),
					'line2'       => $order->get_shipping_address_2(),
					'city'        => $order->get_shipping_city(),
					'state'       => $order->get_shipping_state(),
					'postal_code' => $order->get_shipping_postcode(),
					'country'     => $order->get_shipping_country(),
				],
			];
		}

		// Pass the payment method from the checkout form.
		$payment_method = isset( $_POST['wcet_stripe_payment_method'] ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			? sanitize_text_field( wp_unslash( $_POST['wcet_stripe_payment_method'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Missing
			: '';

		if ( ! empty( $payment_method ) ) {
			$body['payment_method'] = $payment_method;
		}

		$body = apply_filters( 'wcet_stripe_payment_intent_args', $body, $order );

		$response = $this->api_request( 'payment_intents', $body );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$this->log( 'info', 'PaymentIntent created.', [
			'order_id'  => $order->get_id(),
			'intent_id' => $response['id'],
			'status'    => $response['status'],
		] );

		return $response;
	}

	/**
	 * Retrieve a PaymentIntent from Stripe.
	 *
	 * @param string $intent_id Stripe PaymentIntent ID.
	 * @return array|WP_Error
	 */
	private function retrieve_payment_intent( string $intent_id ) {
		return $this->api_request( 'payment_intents/' . sanitize_text_field( $intent_id ), [], 'GET' );
	}

	/**
	 * Confirm a PaymentIntent.
	 *
	 * @param string $intent_id Stripe PaymentIntent ID.
	 * @return array|WP_Error
	 */
	private function confirm_payment_intent( string $intent_id ) {
		return $this->api_request( 'payment_intents/' . sanitize_text_field( $intent_id ) . '/confirm', [] );
	}

	/**
	 * Make an authenticated request to the Stripe API.
	 *
	 * @param string $endpoint API endpoint (relative to base URL).
	 * @param array  $body     Request body.
	 * @param string $method   HTTP method.
	 * @return array|WP_Error
	 */
	private function api_request( string $endpoint, array $body = [], string $method = 'POST' ) {
		$url = self::API_BASE . '/' . ltrim( $endpoint, '/' );

		$args = [
			'method'  => $method,
			'headers' => [
				'Authorization' => 'Bearer ' . $this->secret_key,
				'Stripe-Version' => '2023-10-16',
				'Content-Type'  => 'application/x-www-form-urlencoded',
			],
			'timeout' => 60,
		];

		if ( 'POST' === $method && ! empty( $body ) ) {
			$args['body'] = $this->flatten_params( $body );
		}

		$this->log( 'debug', sprintf( 'API request: %s %s', $method, $endpoint ) );

		$response = ( 'GET' === $method )
			? wp_remote_get( $url, $args )
			: wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wcet_stripe_api_error',
				sprintf(
					/* translators: %s: error message */
					__( 'Stripe API request failed: %s', 'wc-enterprise' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 ) {
			$message = $data['error']['message'] ?? __( 'Unknown Stripe API error.', 'wc-enterprise' );
			$type    = $data['error']['type'] ?? 'api_error';

			$this->log( 'error', sprintf( 'API error (%d): %s [%s]', $code, $message, $type ) );

			return new WP_Error( 'wcet_stripe_' . $type, $message );
		}

		return $data;
	}

	/**
	 * Flatten nested arrays for Stripe's application/x-www-form-urlencoded format.
	 *
	 * @param array  $params Array of parameters.
	 * @param string $prefix Key prefix for nested arrays.
	 * @return array Flat key => value pairs.
	 */
	private function flatten_params( array $params, string $prefix = '' ) {
		$flat = [];

		foreach ( $params as $key => $value ) {
			$full_key = '' === $prefix ? $key : $prefix . '[' . $key . ']';

			if ( is_array( $value ) ) {
				// Check for sequential array (list of values).
				if ( array_values( $value ) === $value ) {
					foreach ( $value as $i => $v ) {
						$flat[ $full_key . '[' . $i . ']' ] = $v;
					}
				} else {
					$flat = array_merge( $flat, $this->flatten_params( $value, $full_key ) );
				}
			} else {
				$flat[ $full_key ] = $value;
			}
		}

		return $flat;
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
	 * Convert an order amount to the smallest currency unit for Stripe.
	 *
	 * @param float  $amount   Amount in standard currency units.
	 * @param string $currency ISO 4217 currency code.
	 * @return int Amount in smallest currency unit.
	 */
	private function get_stripe_amount( float $amount, string $currency ) {
		$zero_decimal_currencies = [
			'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW',
			'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF',
			'XOF', 'XPF',
		];

		if ( in_array( strtoupper( $currency ), $zero_decimal_currencies, true ) ) {
			return absint( $amount );
		}

		return absint( round( $amount * 100 ) );
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

		$context['source'] = 'wcet-stripe';

		$this->logger->log( $level, $message . ( $context ? ' | ' . wc_print_r( $context, true ) : '' ), [ 'source' => 'wcet-stripe' ] );
	}
}

// Register the gateway with WooCommerce.
add_filter( 'woocommerce_payment_gateways', function ( array $gateways ) {
	$gateways[] = 'WCET_Stripe_Gateway';
	return $gateways;
} );
