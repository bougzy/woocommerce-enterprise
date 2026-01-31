<?php
/**
 * Webhook Handler
 *
 * Processes incoming webhooks from Stripe and Paystack, verifies signatures,
 * updates order statuses, and provides idempotent event handling.
 *
 * @package WCET\Payments
 */

defined( 'ABSPATH' ) || exit;

class WCET_Webhook_Handler {

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
	 * Constructor — register webhook endpoints.
	 */
	private function __construct() {
		add_action( 'woocommerce_api_wcet_stripe_webhook', [ $this, 'handle_stripe_webhook' ] );
		add_action( 'woocommerce_api_wcet_paystack_webhook', [ $this, 'handle_paystack_webhook' ] );
	}

	/* ------------------------------------------------------------------
	 * Stripe Webhooks
	 * ----------------------------------------------------------------*/

	/**
	 * Handle incoming Stripe webhook requests.
	 */
	public function handle_stripe_webhook() {
		$payload   = file_get_contents( 'php://input' );
		$signature = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) : '';

		if ( empty( $payload ) || empty( $signature ) ) {
			$this->log( 'error', 'Stripe webhook: empty payload or missing signature.' );
			$this->send_response( 400, 'Missing payload or signature.' );
			return;
		}

		// Get the gateway instance to retrieve the webhook secret.
		$gateway = $this->get_stripe_gateway();

		if ( ! $gateway ) {
			$this->log( 'error', 'Stripe webhook: gateway not available.' );
			$this->send_response( 500, 'Gateway not configured.' );
			return;
		}

		// Verify the webhook signature.
		$webhook_secret = $gateway->get_webhook_secret();

		if ( empty( $webhook_secret ) ) {
			$this->log( 'error', 'Stripe webhook: webhook secret not configured.' );
			$this->send_response( 500, 'Webhook secret not configured.' );
			return;
		}

		if ( ! $this->verify_stripe_signature( $payload, $signature, $webhook_secret ) ) {
			$this->log( 'error', 'Stripe webhook: invalid signature.' );
			$this->send_response( 400, 'Invalid signature.' );
			return;
		}

		$event = json_decode( $payload, true );

		if ( ! is_array( $event ) || empty( $event['type'] ) || empty( $event['data']['object'] ) ) {
			$this->log( 'error', 'Stripe webhook: malformed event payload.' );
			$this->send_response( 400, 'Invalid event payload.' );
			return;
		}

		$event_type = sanitize_text_field( $event['type'] );
		$event_id   = sanitize_text_field( $event['id'] ?? '' );
		$object     = $event['data']['object'];

		$this->log( 'info', sprintf( 'Stripe webhook received: %s (event: %s)', $event_type, $event_id ) );

		// Idempotency check: see if we have already processed this event.
		if ( $this->is_event_processed( 'stripe', $event_id ) ) {
			$this->log( 'info', sprintf( 'Stripe webhook: event %s already processed. Skipping.', $event_id ) );
			$this->send_response( 200, 'Event already processed.' );
			return;
		}

		// Route to the appropriate handler.
		switch ( $event_type ) {
			case 'payment_intent.succeeded':
				$this->handle_stripe_payment_succeeded( $object );
				break;

			case 'payment_intent.payment_failed':
				$this->handle_stripe_payment_failed( $object );
				break;

			case 'charge.refunded':
				$this->handle_stripe_charge_refunded( $object );
				break;

			default:
				$this->log( 'debug', sprintf( 'Stripe webhook: unhandled event type "%s".', $event_type ) );
				break;
		}

		// Mark the event as processed.
		$this->mark_event_processed( 'stripe', $event_id );

		$this->send_response( 200, 'Webhook processed.' );
	}

	/**
	 * Verify a Stripe webhook signature.
	 *
	 * @param string $payload        Raw request body.
	 * @param string $sig_header     Stripe-Signature header value.
	 * @param string $webhook_secret Webhook signing secret.
	 * @return bool
	 */
	private function verify_stripe_signature( string $payload, string $sig_header, string $webhook_secret ) {
		// Parse the signature header.
		$elements = [];
		foreach ( explode( ',', $sig_header ) as $part ) {
			$pair = explode( '=', trim( $part ), 2 );
			if ( 2 === count( $pair ) ) {
				$elements[ $pair[0] ][] = $pair[1];
			}
		}

		if ( empty( $elements['t'] ) || empty( $elements['v1'] ) ) {
			return false;
		}

		$timestamp  = $elements['t'][0];
		$signatures = $elements['v1'];

		// Reject events with a timestamp older than 5 minutes (tolerance for clock skew).
		$tolerance = 300;
		if ( ( time() - (int) $timestamp ) > $tolerance ) {
			$this->log( 'warning', 'Stripe webhook: timestamp outside tolerance.' );
			return false;
		}

		// Compute the expected signature.
		$signed_payload    = $timestamp . '.' . $payload;
		$expected_signature = hash_hmac( 'sha256', $signed_payload, $webhook_secret );

		// Check against all v1 signatures (Stripe may rotate secrets).
		foreach ( $signatures as $sig ) {
			if ( hash_equals( $expected_signature, $sig ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Handle payment_intent.succeeded event.
	 *
	 * @param array $intent PaymentIntent data from the webhook event.
	 */
	private function handle_stripe_payment_succeeded( array $intent ) {
		$intent_id = sanitize_text_field( $intent['id'] ?? '' );
		$charge_id = sanitize_text_field( $intent['latest_charge'] ?? '' );

		$order = $this->get_order_by_meta( '_wcet_stripe_intent_id', $intent_id );

		if ( ! $order ) {
			$this->log( 'warning', 'Stripe payment_intent.succeeded: order not found.', [ 'intent_id' => $intent_id ] );
			return;
		}

		// Idempotency: do not process if already paid.
		if ( $order->is_paid() ) {
			$this->log( 'info', 'Stripe payment_intent.succeeded: order already paid.', [ 'order_id' => $order->get_id() ] );
			return;
		}

		$order->payment_complete( $charge_id );
		$order->update_meta_data( '_wcet_stripe_status', 'succeeded' );
		$order->update_meta_data( '_wcet_stripe_charge_id', $charge_id );
		$order->add_order_note(
			sprintf(
				/* translators: 1: Stripe charge ID */
				__( 'Stripe webhook: Payment confirmed. Charge ID: %s', 'wc-enterprise' ),
				$charge_id
			)
		);
		$order->save();

		$this->log( 'info', 'Stripe payment confirmed via webhook.', [
			'order_id'  => $order->get_id(),
			'charge_id' => $charge_id,
		] );
	}

	/**
	 * Handle payment_intent.payment_failed event.
	 *
	 * @param array $intent PaymentIntent data from the webhook event.
	 */
	private function handle_stripe_payment_failed( array $intent ) {
		$intent_id = sanitize_text_field( $intent['id'] ?? '' );

		$order = $this->get_order_by_meta( '_wcet_stripe_intent_id', $intent_id );

		if ( ! $order ) {
			$this->log( 'warning', 'Stripe payment_intent.payment_failed: order not found.', [ 'intent_id' => $intent_id ] );
			return;
		}

		// Do not override a completed order.
		if ( $order->is_paid() ) {
			return;
		}

		$failure_message = $intent['last_payment_error']['message'] ?? __( 'Unknown error', 'wc-enterprise' );
		$failure_code    = $intent['last_payment_error']['code'] ?? '';

		$order->update_status( 'failed',
			sprintf(
				/* translators: 1: error message 2: error code */
				__( 'Stripe webhook: Payment failed. %1$s (code: %2$s)', 'wc-enterprise' ),
				$failure_message,
				$failure_code
			)
		);
		$order->update_meta_data( '_wcet_stripe_status', 'failed' );
		$order->save();

		$this->log( 'warning', 'Stripe payment failed via webhook.', [
			'order_id' => $order->get_id(),
			'error'    => $failure_message,
			'code'     => $failure_code,
		] );
	}

	/**
	 * Handle charge.refunded event.
	 *
	 * @param array $charge Charge data from the webhook event.
	 */
	private function handle_stripe_charge_refunded( array $charge ) {
		$charge_id = sanitize_text_field( $charge['id'] ?? '' );

		$order = $this->get_order_by_meta( '_wcet_stripe_charge_id', $charge_id );

		if ( ! $order ) {
			// Attempt to find by intent ID as fallback.
			$intent_id = sanitize_text_field( $charge['payment_intent'] ?? '' );
			if ( $intent_id ) {
				$order = $this->get_order_by_meta( '_wcet_stripe_intent_id', $intent_id );
			}
		}

		if ( ! $order ) {
			$this->log( 'warning', 'Stripe charge.refunded: order not found.', [ 'charge_id' => $charge_id ] );
			return;
		}

		$refunded_amount = isset( $charge['amount_refunded'] ) ? (int) $charge['amount_refunded'] : 0;
		$total_amount    = isset( $charge['amount'] ) ? (int) $charge['amount'] : 0;
		$currency        = strtoupper( $charge['currency'] ?? 'USD' );

		// Determine refund display amounts.
		$zero_decimal = in_array( $currency, [ 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' ], true );
		$divisor      = $zero_decimal ? 1 : 100;

		$is_full_refund = $refunded_amount >= $total_amount;

		if ( $is_full_refund ) {
			$order->update_status( 'refunded',
				sprintf(
					/* translators: %s: refunded amount */
					__( 'Stripe webhook: Full refund of %s processed.', 'wc-enterprise' ),
					wc_price( $refunded_amount / $divisor, [ 'currency' => $currency ] )
				)
			);
		} else {
			$order->add_order_note(
				sprintf(
					/* translators: %s: refunded amount */
					__( 'Stripe webhook: Partial refund of %s processed.', 'wc-enterprise' ),
					wc_price( $refunded_amount / $divisor, [ 'currency' => $currency ] )
				)
			);
		}

		$order->update_meta_data( '_wcet_stripe_amount_refunded', $refunded_amount );
		$order->save();

		$this->log( 'info', 'Stripe refund processed via webhook.', [
			'order_id'   => $order->get_id(),
			'charge_id'  => $charge_id,
			'refunded'   => $refunded_amount,
			'full'       => $is_full_refund,
		] );
	}

	/* ------------------------------------------------------------------
	 * Paystack Webhooks
	 * ----------------------------------------------------------------*/

	/**
	 * Handle incoming Paystack webhook requests.
	 */
	public function handle_paystack_webhook() {
		$payload   = file_get_contents( 'php://input' );
		$signature = isset( $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ) ) : '';

		if ( empty( $payload ) ) {
			$this->log( 'error', 'Paystack webhook: empty payload.' );
			$this->send_response( 400, 'Missing payload.' );
			return;
		}

		// Get the gateway instance.
		$gateway = $this->get_paystack_gateway();

		if ( ! $gateway ) {
			$this->log( 'error', 'Paystack webhook: gateway not available.' );
			$this->send_response( 500, 'Gateway not configured.' );
			return;
		}

		// Verify the webhook signature using HMAC SHA-512.
		$secret_key = $gateway->get_secret_key();

		if ( ! $this->verify_paystack_signature( $payload, $signature, $secret_key ) ) {
			$this->log( 'error', 'Paystack webhook: invalid signature.' );
			$this->send_response( 400, 'Invalid signature.' );
			return;
		}

		$event = json_decode( $payload, true );

		if ( ! is_array( $event ) || empty( $event['event'] ) || empty( $event['data'] ) ) {
			$this->log( 'error', 'Paystack webhook: malformed event payload.' );
			$this->send_response( 400, 'Invalid event payload.' );
			return;
		}

		$event_type = sanitize_text_field( $event['event'] );
		$event_data = $event['data'];
		$reference  = sanitize_text_field( $event_data['reference'] ?? '' );

		$this->log( 'info', sprintf( 'Paystack webhook received: %s (ref: %s)', $event_type, $reference ) );

		// Idempotency check.
		$idempotency_key = 'paystack_' . $reference . '_' . $event_type;
		if ( $this->is_event_processed( 'paystack', $idempotency_key ) ) {
			$this->log( 'info', sprintf( 'Paystack webhook: event %s already processed. Skipping.', $idempotency_key ) );
			$this->send_response( 200, 'Event already processed.' );
			return;
		}

		// Route to the appropriate handler.
		switch ( $event_type ) {
			case 'charge.success':
				$this->handle_paystack_charge_success( $event_data );
				break;

			default:
				$this->log( 'debug', sprintf( 'Paystack webhook: unhandled event type "%s".', $event_type ) );
				break;
		}

		// Mark the event as processed.
		$this->mark_event_processed( 'paystack', $idempotency_key );

		$this->send_response( 200, 'Webhook processed.' );
	}

	/**
	 * Verify a Paystack webhook signature using HMAC SHA-512.
	 *
	 * @param string $payload    Raw request body.
	 * @param string $signature  X-Paystack-Signature header value.
	 * @param string $secret_key Paystack secret key.
	 * @return bool
	 */
	private function verify_paystack_signature( string $payload, string $signature, string $secret_key ) {
		if ( empty( $signature ) || empty( $secret_key ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha512', $payload, $secret_key );

		return hash_equals( $expected, $signature );
	}

	/**
	 * Handle Paystack charge.success event.
	 *
	 * @param array $data Charge data from the webhook event.
	 */
	private function handle_paystack_charge_success( array $data ) {
		$reference = sanitize_text_field( $data['reference'] ?? '' );

		if ( empty( $reference ) ) {
			$this->log( 'error', 'Paystack charge.success: missing reference.' );
			return;
		}

		// Find the order by reference.
		$gateway = $this->get_paystack_gateway();

		if ( ! $gateway ) {
			$this->log( 'error', 'Paystack charge.success: gateway not available.' );
			return;
		}

		$order = $gateway->get_order_by_reference( $reference );

		if ( ! $order ) {
			$this->log( 'warning', 'Paystack charge.success: order not found.', [ 'reference' => $reference ] );
			return;
		}

		// Idempotency: do not process if already paid.
		if ( $order->is_paid() ) {
			$this->log( 'info', 'Paystack charge.success: order already paid.', [ 'order_id' => $order->get_id() ] );
			return;
		}

		// Verify the transaction with a direct API call for added security.
		$verification = $gateway->verify_transaction( $reference );

		if ( is_wp_error( $verification ) ) {
			$this->log( 'error', 'Paystack charge.success: verification failed.', [
				'order_id'  => $order->get_id(),
				'reference' => $reference,
				'error'     => $verification->get_error_message(),
			] );
			return;
		}

		$gateway->process_verified_transaction( $order, $verification['data'] );

		$this->log( 'info', 'Paystack payment confirmed via webhook.', [
			'order_id'  => $order->get_id(),
			'reference' => $reference,
		] );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ----------------------------------------------------------------*/

	/**
	 * Retrieve the Stripe gateway instance.
	 *
	 * @return WCET_Stripe_Gateway|null
	 */
	private function get_stripe_gateway(): ?WCET_Stripe_Gateway {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways['wcet_stripe'] ) && $gateways['wcet_stripe'] instanceof WCET_Stripe_Gateway
			? $gateways['wcet_stripe']
			: null;
	}

	/**
	 * Retrieve the Paystack gateway instance.
	 *
	 * @return WCET_Paystack_Gateway|null
	 */
	private function get_paystack_gateway(): ?WCET_Paystack_Gateway {
		$gateways = WC()->payment_gateways()->payment_gateways();
		return isset( $gateways['wcet_paystack'] ) && $gateways['wcet_paystack'] instanceof WCET_Paystack_Gateway
			? $gateways['wcet_paystack']
			: null;
	}

	/**
	 * Find an order by a meta key/value pair.
	 *
	 * @param string $meta_key   Meta key.
	 * @param string $meta_value Meta value.
	 * @return WC_Order|null
	 */
	private function get_order_by_meta( string $meta_key, string $meta_value ): ?WC_Order {
		if ( empty( $meta_value ) ) {
			return null;
		}

		$orders = wc_get_orders( [
			'meta_key'   => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value' => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'limit'      => 1,
		] );

		return ! empty( $orders ) ? $orders[0] : null;
	}

	/**
	 * Check whether a webhook event has already been processed (idempotency).
	 *
	 * Uses a WordPress transient to track processed events for 48 hours.
	 *
	 * @param string $provider  Payment provider (stripe, paystack).
	 * @param string $event_id  Unique event identifier.
	 * @return bool
	 */
	private function is_event_processed( string $provider, string $event_id ) {
		if ( empty( $event_id ) ) {
			return false;
		}

		$transient_key = 'wcet_wh_' . $provider . '_' . md5( $event_id );

		return false !== get_transient( $transient_key );
	}

	/**
	 * Mark a webhook event as processed.
	 *
	 * @param string $provider Payment provider (stripe, paystack).
	 * @param string $event_id Unique event identifier.
	 */
	private function mark_event_processed( string $provider, string $event_id ) {
		if ( empty( $event_id ) ) {
			return;
		}

		$transient_key = 'wcet_wh_' . $provider . '_' . md5( $event_id );

		// Store for 48 hours to account for delayed retries.
		set_transient( $transient_key, time(), 48 * HOUR_IN_SECONDS );
	}

	/**
	 * Send an HTTP response and terminate.
	 *
	 * @param int    $status_code HTTP status code.
	 * @param string $message     Response message.
	 */
	private function send_response( int $status_code, string $message ) {
		status_header( $status_code );
		header( 'Content-Type: application/json' );

		echo wp_json_encode( [
			'status'  => $status_code,
			'message' => $message,
		] );
		exit;
	}

	/**
	 * Log a message to the WC logger.
	 *
	 * @param string $level   Log level (debug, info, warning, error).
	 * @param string $message Log message.
	 * @param array  $context Additional context data.
	 */
	private function log( string $level, string $message, array $context = [] ) {
		if ( null === $this->logger ) {
			$this->logger = wc_get_logger();
		}

		$this->logger->log( $level, $message . ( $context ? ' | ' . wc_print_r( $context, true ) : '' ), [ 'source' => 'wcet-webhooks' ] );
	}
}

WCET_Webhook_Handler::instance();
