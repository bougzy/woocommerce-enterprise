<?php
/**
 * Subscription Product Type — recurring billing support with configurable
 * billing intervals, trial periods, and signup fees.
 */

defined( 'ABSPATH' ) || exit;

class WCET_Subscription_Product extends WC_Product {

    protected $product_type = 'subscription';

    public function get_type() {
        return 'subscription';
    }

    public function get_billing_period() {
        return $this->get_meta( '_billing_period', true ) ?: 'month';
    }

    public function get_billing_interval() {
        return max( 1, (int) $this->get_meta( '_billing_interval', true ) );
    }

    public function get_trial_days() {
        return max( 0, (int) $this->get_meta( '_trial_days', true ) );
    }

    public function get_signup_fee() {
        return max( 0.0, (float) $this->get_meta( '_signup_fee', true ) );
    }

    public function get_max_renewals() {
        $max = $this->get_meta( '_max_renewals', true );
        return '' === $max ? 0 : max( 0, (int) $max ); // 0 = unlimited
    }

    /**
     * Calculate the total cost for first payment (signup fee + first period).
     */
    public function get_first_payment_amount() {
        $price = (float) $this->get_price();
        if ( $this->get_trial_days() > 0 ) {
            return $this->get_signup_fee(); // Only signup fee during trial.
        }
        return round( $price + $this->get_signup_fee(), wc_get_price_decimals() );
    }

    public function get_recurring_amount() {
        return round( (float) $this->get_price(), wc_get_price_decimals() );
    }

    public function get_price_string() {
        $price    = wc_price( $this->get_recurring_amount() );
        $interval = $this->get_billing_interval();
        $period   = $this->get_billing_period();

        $formatted = $interval > 1
            ? sprintf( '%s every %d %ss', $price, $interval, $period )
            : sprintf( '%s / %s', $price, $period );

        if ( $this->get_signup_fee() > 0 ) {
            $formatted .= sprintf( ' + %s signup fee', wc_price( $this->get_signup_fee() ) );
        }
        if ( $this->get_trial_days() > 0 ) {
            $formatted .= sprintf( ' with %d-day free trial', $this->get_trial_days() );
        }

        return $formatted;
    }
}

// Register product type.
add_filter( 'product_type_selector', function ( array $types ) {
    $types['subscription'] = __( 'Subscription Product', 'wc-enterprise' );
    return $types;
} );

add_filter( 'woocommerce_product_class', function ( string $class, string $type ) {
    if ( 'subscription' === $type ) {
        return 'WCET_Subscription_Product';
    }
    return $class;
}, 10, 2 );

// Admin product data panel.
add_action( 'woocommerce_product_data_panels', function () {
    global $post;
    $product = wc_get_product( $post->ID );
    if ( ! $product || 'subscription' !== $product->get_type() ) {
        return;
    }

    echo '<div id="subscription_product_data" class="panel woocommerce_options_panel">';

    woocommerce_wp_select( [
        'id'      => '_billing_period',
        'label'   => __( 'Billing Period', 'wc-enterprise' ),
        'value'   => $product->get_billing_period(),
        'options' => [
            'day'   => __( 'Day', 'wc-enterprise' ),
            'week'  => __( 'Week', 'wc-enterprise' ),
            'month' => __( 'Month', 'wc-enterprise' ),
            'year'  => __( 'Year', 'wc-enterprise' ),
        ],
    ] );
    woocommerce_wp_text_input( [
        'id'    => '_billing_interval',
        'label' => __( 'Billing Interval', 'wc-enterprise' ),
        'type'  => 'number',
        'value' => $product->get_billing_interval(),
        'custom_attributes' => [ 'min' => '1', 'max' => '365' ],
    ] );
    woocommerce_wp_text_input( [
        'id'    => '_trial_days',
        'label' => __( 'Trial Period (days)', 'wc-enterprise' ),
        'type'  => 'number',
        'value' => $product->get_trial_days(),
        'custom_attributes' => [ 'min' => '0' ],
    ] );
    woocommerce_wp_text_input( [
        'id'    => '_signup_fee',
        'label' => __( 'Signup Fee', 'wc-enterprise' ),
        'type'  => 'number',
        'value' => $product->get_signup_fee(),
        'custom_attributes' => [ 'step' => '0.01', 'min' => '0' ],
    ] );
    woocommerce_wp_text_input( [
        'id'          => '_max_renewals',
        'label'       => __( 'Max Renewals (0 = unlimited)', 'wc-enterprise' ),
        'type'        => 'number',
        'value'       => $product->get_max_renewals(),
        'custom_attributes' => [ 'min' => '0' ],
    ] );

    echo '</div>';
} );

add_action( 'woocommerce_process_product_meta', function ( int $post_id ) {
    $product = wc_get_product( $post_id );
    if ( ! $product || 'subscription' !== $product->get_type() ) {
        return;
    }

    $fields = [ '_billing_period', '_billing_interval', '_trial_days', '_signup_fee', '_max_renewals' ];
    foreach ( $fields as $field ) {
        if ( isset( $_POST[ $field ] ) ) {
            $product->update_meta_data( $field, sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) );
        }
    }
    $product->save();
} );
