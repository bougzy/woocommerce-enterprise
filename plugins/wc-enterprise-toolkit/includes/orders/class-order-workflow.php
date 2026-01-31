<?php
/**
 * Custom Order Statuses & Workflow Engine
 *
 * Adds enterprise-level order statuses: awaiting-verification, preparing,
 * quality-check, ready-to-ship, partially-shipped, returned.
 * Includes automatic status transitions and admin UI enhancements.
 */

defined( 'ABSPATH' ) || exit;

class WCET_Order_Workflow {

    private static $instance = null;

    /** @var array<string, array{label: string, label_count: string, next: string[], color: string}> */
    private $custom_statuses = [];

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->define_statuses();

        add_action( 'init', [ $this, 'register_statuses' ] );
        add_filter( 'wc_order_statuses', [ $this, 'add_statuses_to_wc' ] );
        add_filter( 'woocommerce_valid_order_statuses_for_payment', [ $this, 'valid_payment_statuses' ] );
        add_filter( 'woocommerce_valid_order_statuses_for_cancel', [ $this, 'valid_cancel_statuses' ] );
        add_action( 'woocommerce_order_status_changed', [ $this, 'on_status_change' ], 10, 4 );
        add_filter( 'bulk_actions-edit-shop_order', [ $this, 'add_bulk_actions' ] );

        // Admin column coloring.
        add_action( 'admin_head', [ $this, 'admin_status_colors' ] );

        // Auto-transition engine.
        add_action( 'wcet_auto_transition_orders', [ $this, 'process_auto_transitions' ] );
        if ( ! wp_next_scheduled( 'wcet_auto_transition_orders' ) ) {
            wp_schedule_event( time(), 'hourly', 'wcet_auto_transition_orders' );
        }
    }

    private function define_statuses() {
        $this->custom_statuses = [
            'wc-awaiting-verify' => [
                'label'       => _x( 'Awaiting Verification', 'Order status', 'wc-enterprise' ),
                'label_count' => _n_noop( 'Awaiting Verification <span class="count">(%s)</span>', 'Awaiting Verification <span class="count">(%s)</span>', 'wc-enterprise' ),
                'next'        => [ 'processing', 'cancelled' ],
                'color'       => '#f0ad4e',
            ],
            'wc-preparing' => [
                'label'       => _x( 'Preparing', 'Order status', 'wc-enterprise' ),
                'label_count' => _n_noop( 'Preparing <span class="count">(%s)</span>', 'Preparing <span class="count">(%s)</span>', 'wc-enterprise' ),
                'next'        => [ 'quality-check', 'on-hold' ],
                'color'       => '#5bc0de',
            ],
            'wc-quality-check' => [
                'label'       => _x( 'Quality Check', 'Order status', 'wc-enterprise' ),
                'label_count' => _n_noop( 'Quality Check <span class="count">(%s)</span>', 'Quality Check <span class="count">(%s)</span>', 'wc-enterprise' ),
                'next'        => [ 'ready-to-ship', 'preparing' ],
                'color'       => '#0275d8',
            ],
            'wc-ready-to-ship' => [
                'label'       => _x( 'Ready to Ship', 'Order status', 'wc-enterprise' ),
                'label_count' => _n_noop( 'Ready to Ship <span class="count">(%s)</span>', 'Ready to Ship <span class="count">(%s)</span>', 'wc-enterprise' ),
                'next'        => [ 'completed', 'partially-shipped' ],
                'color'       => '#5cb85c',
            ],
            'wc-partial-ship' => [
                'label'       => _x( 'Partially Shipped', 'Order status', 'wc-enterprise' ),
                'label_count' => _n_noop( 'Partially Shipped <span class="count">(%s)</span>', 'Partially Shipped <span class="count">(%s)</span>', 'wc-enterprise' ),
                'next'        => [ 'completed' ],
                'color'       => '#f7c948',
            ],
            'wc-returned' => [
                'label'       => _x( 'Returned', 'Order status', 'wc-enterprise' ),
                'label_count' => _n_noop( 'Returned <span class="count">(%s)</span>', 'Returned <span class="count">(%s)</span>', 'wc-enterprise' ),
                'next'        => [ 'refunded' ],
                'color'       => '#d9534f',
            ],
        ];
    }

    public function register_statuses() {
        foreach ( $this->custom_statuses as $slug => $status ) {
            register_post_status( $slug, [
                'label'                     => $status['label'],
                'label_count'               => $status['label_count'],
                'public'                    => true,
                'exclude_from_search'       => false,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
            ] );
        }
    }

    public function add_statuses_to_wc( array $statuses ) {
        $new = [];
        foreach ( $statuses as $key => $label ) {
            $new[ $key ] = $label;
            // Insert custom statuses after "processing".
            if ( 'wc-processing' === $key ) {
                foreach ( $this->custom_statuses as $slug => $status ) {
                    $new[ $slug ] = $status['label'];
                }
            }
        }
        return $new;
    }

    public function valid_payment_statuses( array $statuses ) {
        $statuses[] = 'awaiting-verify';
        return $statuses;
    }

    public function valid_cancel_statuses( array $statuses ) {
        $statuses[] = 'awaiting-verify';
        $statuses[] = 'preparing';
        return $statuses;
    }

    /**
     * Handle status transitions — send notifications, log events, trigger next steps.
     */
    public function on_status_change( int $order_id, string $from, string $to, WC_Order $order ) {
        // Log transition for the order lifecycle timeline.
        $order->add_order_note(
            sprintf(
                /* translators: 1: old status 2: new status */
                __( 'Status changed from %1$s to %2$s', 'wc-enterprise' ),
                wc_get_order_status_name( $from ),
                wc_get_order_status_name( $to )
            )
        );

        // Send customer notification for key transitions.
        $notify_transitions = [
            'preparing'      => __( 'Your order is being prepared.', 'wc-enterprise' ),
            'ready-to-ship'  => __( 'Your order is packed and ready to ship!', 'wc-enterprise' ),
            'partial-ship'   => __( 'Part of your order has been shipped.', 'wc-enterprise' ),
        ];

        if ( isset( $notify_transitions[ $to ] ) ) {
            do_action( 'wcet_order_status_notification', $order, $to, $notify_transitions[ $to ] );
        }

        // Auto-advance: processing → preparing after payment verification.
        if ( 'processing' === $to && $this->is_payment_verified( $order ) ) {
            $order->update_status( 'preparing', __( 'Auto-advanced: payment verified.', 'wc-enterprise' ) );
        }

        // Record analytics event.
        do_action( 'wcet_analytics_event', 'order_status_change', [
            'order_id' => $order_id,
            'from'     => $from,
            'to'       => $to,
        ] );
    }

    private function is_payment_verified( WC_Order $order ) {
        return in_array( $order->get_payment_method(), [ 'stripe', 'paystack' ], true )
            && $order->get_transaction_id();
    }

    public function add_bulk_actions( array $actions ) {
        foreach ( $this->custom_statuses as $slug => $status ) {
            $key             = 'mark_' . str_replace( 'wc-', '', $slug );
            $actions[ $key ] = sprintf( __( 'Change status to %s', 'wc-enterprise' ), $status['label'] );
        }
        return $actions;
    }

    public function admin_status_colors() {
        $screen = get_current_screen();
        if ( ! $screen || 'edit-shop_order' !== $screen->id ) {
            return;
        }
        echo '<style>';
        foreach ( $this->custom_statuses as $slug => $status ) {
            $class = str_replace( 'wc-', '', $slug );
            printf(
                '.order-status.status-%s { background: %s; color: #fff; }',
                esc_attr( $class ),
                esc_attr( $status['color'] )
            );
        }
        echo '</style>';
    }

    /**
     * Process automatic order transitions based on age / conditions.
     */
    public function process_auto_transitions() {
        $rules = apply_filters( 'wcet_auto_transition_rules', [
            [
                'from'      => 'quality-check',
                'to'        => 'ready-to-ship',
                'after_hours' => 2,
                'condition' => function( $o ) { return ! $o->get_meta( '_requires_manual_qc' ); },
            ],
        ] );

        foreach ( $rules as $rule ) {
            $orders = wc_get_orders( [
                'status'       => $rule['from'],
                'date_created' => '<' . ( time() - $rule['after_hours'] * HOUR_IN_SECONDS ),
                'limit'        => 50,
            ] );

            foreach ( $orders as $order ) {
                if ( isset( $rule['condition'] ) && ! ( $rule['condition'] )( $order ) ) {
                    continue;
                }
                $order->update_status(
                    $rule['to'],
                    __( 'Auto-transitioned by workflow engine.', 'wc-enterprise' )
                );
            }
        }
    }

    /**
     * Get allowed next statuses for a given order (used by the admin UI and API).
     */
    public function get_allowed_transitions( WC_Order $order ) {
        $current = 'wc-' . $order->get_status();
        return $this->custom_statuses[ $current ]['next'] ?? [];
    }
}

WCET_Order_Workflow::instance();
