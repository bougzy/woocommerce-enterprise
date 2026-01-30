<?php
/**
 * Custom Checkout Flow
 *
 * Multi-step checkout, optimized session handling, field management,
 * and checkout validation with fraud scoring integration.
 */

defined( 'ABSPATH' ) || exit;

class WCET_Custom_Checkout {

    private static ?self $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Streamline checkout fields.
        add_filter( 'woocommerce_checkout_fields', [ $this, 'optimize_checkout_fields' ] );
        add_filter( 'woocommerce_default_address_fields', [ $this, 'optimize_address_fields' ] );

        // Multi-step checkout support.
        add_action( 'woocommerce_before_checkout_form', [ $this, 'render_step_indicator' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_checkout_assets' ] );

        // Optimized session handling.
        add_filter( 'woocommerce_session_handler', [ $this, 'custom_session_handler' ] );
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_custom_checkout_data' ] );

        // Checkout validation.
        add_action( 'woocommerce_after_checkout_validation', [ $this, 'validate_checkout' ], 10, 2 );

        // Express checkout support.
        add_action( 'woocommerce_before_checkout_form', [ $this, 'render_express_checkout' ], 5 );

        // Cart fragment optimization — reduce unnecessary AJAX calls.
        add_filter( 'woocommerce_add_to_cart_fragments', [ $this, 'optimize_cart_fragments' ] );

        // Remove unnecessary checkout scripts.
        add_action( 'wp_enqueue_scripts', [ $this, 'dequeue_unnecessary_scripts' ], 100 );
    }

    /**
     * Remove unnecessary fields and reorder for conversion optimization.
     */
    public function optimize_checkout_fields( array $fields ): array {
        // Move email to the top for guest checkout identification.
        if ( isset( $fields['billing']['billing_email'] ) ) {
            $fields['billing']['billing_email']['priority'] = 5;
        }

        // Remove company field by default (can be re-enabled via filter).
        if ( apply_filters( 'wcet_remove_company_field', true ) ) {
            unset( $fields['billing']['billing_company'] );
            unset( $fields['shipping']['shipping_company'] );
        }

        // Remove order comments by default.
        if ( apply_filters( 'wcet_remove_order_notes', true ) ) {
            unset( $fields['order']['order_comments'] );
        }

        // Add phone validation pattern.
        if ( isset( $fields['billing']['billing_phone'] ) ) {
            $fields['billing']['billing_phone']['custom_attributes'] = [
                'pattern' => '[0-9+\-\(\)\s]+',
            ];
        }

        return $fields;
    }

    public function optimize_address_fields( array $fields ): array {
        // Reorder for logical flow.
        $priority_order = [
            'first_name' => 10,
            'last_name'  => 20,
            'country'    => 30,
            'address_1'  => 40,
            'address_2'  => 50,
            'city'       => 60,
            'state'      => 70,
            'postcode'   => 80,
        ];

        foreach ( $priority_order as $key => $priority ) {
            if ( isset( $fields[ $key ] ) ) {
                $fields[ $key ]['priority'] = $priority;
            }
        }

        return $fields;
    }

    /**
     * Render multi-step checkout progress indicator.
     */
    public function render_step_indicator(): void {
        if ( ! is_checkout() || is_order_received_page() ) {
            return;
        }

        $steps = apply_filters( 'wcet_checkout_steps', [
            'details'  => __( 'Details', 'wc-enterprise' ),
            'shipping' => __( 'Shipping', 'wc-enterprise' ),
            'payment'  => __( 'Payment', 'wc-enterprise' ),
            'review'   => __( 'Review', 'wc-enterprise' ),
        ] );

        echo '<div class="wcet-checkout-steps" role="navigation" aria-label="Checkout steps">';
        $i = 1;
        foreach ( $steps as $key => $label ) {
            $active = 1 === $i ? ' active' : '';
            printf(
                '<div class="wcet-step%s" data-step="%s"><span class="step-number">%d</span><span class="step-label">%s</span></div>',
                esc_attr( $active ),
                esc_attr( $key ),
                $i,
                esc_html( $label )
            );
            $i++;
        }
        echo '</div>';
    }

    /**
     * Express checkout section (Apple Pay, Google Pay placeholders).
     */
    public function render_express_checkout(): void {
        if ( ! is_checkout() || is_order_received_page() ) {
            return;
        }

        echo '<div class="wcet-express-checkout">';
        echo '<h3>' . esc_html__( 'Express Checkout', 'wc-enterprise' ) . '</h3>';
        echo '<div class="wcet-express-buttons">';
        do_action( 'wcet_express_checkout_buttons' );
        echo '</div>';
        echo '<div class="wcet-divider"><span>' . esc_html__( 'or continue below', 'wc-enterprise' ) . '</span></div>';
        echo '</div>';
    }

    public function enqueue_checkout_assets(): void {
        if ( ! is_checkout() ) {
            return;
        }

        wp_enqueue_style(
            'wcet-checkout',
            WCET_PLUGIN_URL . 'assets/css/checkout.css',
            [],
            WCET_VERSION
        );
        wp_enqueue_script(
            'wcet-checkout',
            WCET_PLUGIN_URL . 'assets/js/checkout.js',
            [ 'jquery' ],
            WCET_VERSION,
            true
        );
        wp_localize_script( 'wcet-checkout', 'wcetCheckout', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'wcet-checkout' ),
            'i18n'    => [
                'next'     => __( 'Continue', 'wc-enterprise' ),
                'previous' => __( 'Back', 'wc-enterprise' ),
                'place'    => __( 'Place Order', 'wc-enterprise' ),
            ],
        ] );
    }

    public function custom_session_handler(): string {
        // Use the default but with reduced session lifetime for performance.
        add_filter( 'wc_session_expiring', fn() => 60 * 60 * 2 );      // 2 hours
        add_filter( 'wc_session_expiration', fn() => 60 * 60 * 24 );    // 24 hours (down from 48h)
        return 'WC_Session_Handler';
    }

    public function save_custom_checkout_data( int $order_id ): void {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        // Store checkout metadata for analytics.
        $order->update_meta_data( '_checkout_source', sanitize_text_field( wp_unslash( $_POST['checkout_source'] ?? 'standard' ) ) );
        $order->update_meta_data( '_checkout_duration', absint( $_POST['checkout_duration'] ?? 0 ) );
        $order->save();
    }

    /**
     * Extended checkout validation.
     */
    public function validate_checkout( array $data, WP_Error $errors ): void {
        // Validate email domain is not disposable.
        $email = $data['billing_email'] ?? '';
        if ( $email && $this->is_disposable_email( $email ) ) {
            $errors->add( 'disposable_email', __( 'Please use a permanent email address.', 'wc-enterprise' ) );
        }

        // Velocity check — limit orders per IP.
        $ip = WC_Geolocation::get_ip_address();
        if ( $this->exceeds_order_velocity( $ip ) ) {
            $errors->add( 'velocity_limit', __( 'Too many orders from your location. Please try again later.', 'wc-enterprise' ) );
        }
    }

    private function is_disposable_email( string $email ): bool {
        $domain     = strtolower( substr( strrchr( $email, '@' ), 1 ) );
        $disposable = apply_filters( 'wcet_disposable_email_domains', [
            'tempmail.com', 'throwaway.email', 'guerrillamail.com',
            'mailinator.com', 'yopmail.com', 'sharklasers.com',
        ] );
        return in_array( $domain, $disposable, true );
    }

    private function exceeds_order_velocity( string $ip ): bool {
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders
             WHERE ip_address = %s AND date_created_gmt > %s",
            $ip,
            gmdate( 'Y-m-d H:i:s', strtotime( '-1 hour' ) )
        ) );
        return $count >= (int) apply_filters( 'wcet_max_orders_per_hour', 5 );
    }

    public function optimize_cart_fragments( array $fragments ): array {
        // Only update the cart count, not the entire cart widget.
        $count = WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
        $fragments['.wcet-cart-count'] = '<span class="wcet-cart-count">' . esc_html( $count ) . '</span>';
        return $fragments;
    }

    public function dequeue_unnecessary_scripts(): void {
        if ( ! is_checkout() ) {
            return;
        }
        wp_dequeue_script( 'selectWoo' );
        wp_dequeue_style( 'select2' );
    }
}

WCET_Custom_Checkout::instance();
