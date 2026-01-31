<?php
/**
 * Background Job Processing Engine
 *
 * Custom job queue with retry logic, priority handling, and failure recovery.
 * Uses the wcet_background_jobs table for persistent job storage.
 * Designed for horizontal scaling — multiple workers can process the queue.
 */

defined( 'ABSPATH' ) || exit;

class WCET_Background_Jobs {

    private static $instance = null;

    /** Maximum jobs to process per cron run */
    private const BATCH_SIZE = 20;

    /** Lock timeout in seconds to prevent duplicate processing */
    private const LOCK_TIMEOUT = 300;

    /** Registered job handlers: type => callable */
    private $handlers = [];

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->register_default_handlers();

        // Process queue via cron.
        add_action( 'wcet_process_job_queue', [ $this, 'process_queue' ] );
        if ( ! wp_next_scheduled( 'wcet_process_job_queue' ) ) {
            wp_schedule_event( time(), 'every_minute', 'wcet_process_job_queue' );
        }

        // Register custom cron interval.
        add_filter( 'cron_schedules', [ $this, 'add_cron_interval' ] );

        // Cleanup completed jobs.
        add_action( 'wcet_background_cleanup', [ $this, 'cleanup_completed_jobs' ] );
        if ( ! wp_next_scheduled( 'wcet_background_cleanup' ) ) {
            wp_schedule_event( time(), 'daily', 'wcet_background_cleanup' );
        }

        // Admin page.
        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
        add_action( 'wp_ajax_wcet_retry_job', [ $this, 'ajax_retry_job' ] );
        add_action( 'wp_ajax_wcet_cancel_job', [ $this, 'ajax_cancel_job' ] );
    }

    public function add_cron_interval( array $schedules ) {
        $schedules['every_minute'] = [
            'interval' => 60,
            'display'  => __( 'Every Minute', 'wc-enterprise' ),
        ];
        return $schedules;
    }

    /**
     * Register a job handler.
     *
     * @param string   $type     Job type identifier.
     * @param callable $handler  Receives the decoded payload array. Must return true on success.
     */
    public function register_handler( string $type, callable $handler ) {
        $this->handlers[ $type ] = $handler;
    }

    /**
     * Dispatch a new background job.
     */
    public function dispatch( string $type, array $payload = [], ?string $scheduled_at = null, int $priority = 10 ) {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'wcet_background_jobs', [
            'job_type'     => $type,
            'payload'      => wp_json_encode( $payload ),
            'status'       => 'pending',
            'attempts'     => 0,
            'max_attempts' => 3,
            'scheduled_at' => $scheduled_at ?? current_time( 'mysql' ),
        ] );

        $job_id = (int) $wpdb->insert_id;

        do_action( 'wcet_job_dispatched', $job_id, $type, $payload );

        return $job_id;
    }

    /**
     * Dispatch a job to run after a delay.
     */
    public function dispatch_delayed( string $type, array $payload, int $delay_seconds ) {
        $scheduled = gmdate( 'Y-m-d H:i:s', time() + $delay_seconds );
        return $this->dispatch( $type, $payload, $scheduled );
    }

    /**
     * Process the job queue.
     */
    public function process_queue() {
        global $wpdb;

        // Acquire a processing lock to prevent concurrent execution.
        $lock_key = 'wcet_job_queue_lock';
        $lock     = get_transient( $lock_key );
        if ( $lock ) {
            return; // Another process is handling the queue.
        }
        set_transient( $lock_key, time(), self::LOCK_TIMEOUT );

        try {
            $jobs = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wcet_background_jobs
                 WHERE status IN ('pending', 'failed')
                   AND attempts < max_attempts
                   AND scheduled_at <= %s
                 ORDER BY scheduled_at ASC
                 LIMIT %d",
                current_time( 'mysql' ),
                self::BATCH_SIZE
            ) );

            foreach ( $jobs as $job ) {
                $this->process_job( $job );
            }
        } finally {
            delete_transient( $lock_key );
        }
    }

    /**
     * Process a single job.
     */
    private function process_job( object $job ) {
        global $wpdb;

        $table = $wpdb->prefix . 'wcet_background_jobs';

        // Mark as processing.
        $wpdb->update( $table, [
            'status'     => 'processing',
            'started_at' => current_time( 'mysql' ),
            'attempts'   => $job->attempts + 1,
        ], [ 'id' => $job->id ] );

        $handler = $this->handlers[ $job->job_type ] ?? null;
        if ( ! $handler ) {
            $wpdb->update( $table, [
                'status'        => 'failed',
                'error_message' => "No handler registered for job type: {$job->job_type}",
            ], [ 'id' => $job->id ] );
            return;
        }

        try {
            $payload = json_decode( $job->payload, true ) ?: [];
            $result  = call_user_func( $handler, $payload, $job );

            if ( true === $result ) {
                $wpdb->update( $table, [
                    'status'       => 'completed',
                    'completed_at' => current_time( 'mysql' ),
                ], [ 'id' => $job->id ] );

                do_action( 'wcet_job_completed', $job->id, $job->job_type );
            } else {
                throw new \RuntimeException( is_string( $result ) ? $result : 'Job returned non-true result' );
            }
        } catch ( \Throwable $e ) {
            $attempts = $job->attempts + 1;
            $status   = $attempts >= $job->max_attempts ? 'failed' : 'pending';

            $wpdb->update( $table, [
                'status'        => $status,
                'error_message' => substr( $e->getMessage(), 0, 500 ),
                // Exponential backoff for retries.
                'scheduled_at'  => $status === 'pending'
                    ? gmdate( 'Y-m-d H:i:s', time() + ( pow( 2, $attempts ) * 60 ) )
                    : $job->scheduled_at,
            ], [ 'id' => $job->id ] );

            do_action( 'wcet_job_failed', $job->id, $job->job_type, $e->getMessage() );

            error_log( sprintf(
                '[WCET Job Failed] ID: %d, Type: %s, Attempt: %d/%d, Error: %s',
                $job->id,
                $job->job_type,
                $attempts,
                $job->max_attempts,
                $e->getMessage()
            ) );
        }
    }

    /**
     * Register built-in job handlers.
     */
    private function register_default_handlers() {
        // Search reindex job.
        $this->register_handler( 'reindex_product', function ( array $payload ) {
            $product_id = $payload['product_id'] ?? 0;
            if ( $product_id && class_exists( 'WCET_Search_Engine' ) ) {
                WCET_Search_Engine::instance()->index_product( $product_id );
            }
            return true;
        } );

        // Bulk email notification job.
        $this->register_handler( 'send_notification', function ( array $payload ) {
            $to      = $payload['to'] ?? '';
            $subject = $payload['subject'] ?? '';
            $body    = $payload['body'] ?? '';
            if ( $to && $subject ) {
                return wp_mail( $to, $subject, $body );
            }
            return false;
        } );

        // Analytics aggregation job.
        $this->register_handler( 'aggregate_analytics', function ( array $payload ) {
            if ( class_exists( 'WCET_Analytics' ) ) {
                WCET_Analytics::instance()->run_aggregation();
            }
            return true;
        } );

        // Cache warming job.
        $this->register_handler( 'warm_cache', function ( array $payload ) {
            if ( class_exists( 'WCET_Object_Cache' ) ) {
                WCET_Object_Cache::instance()->warm_product_cache();
            }
            return true;
        } );

        // Order export job.
        $this->register_handler( 'export_orders', function ( array $payload ) {
            $date_from = $payload['date_from'] ?? '';
            $date_to   = $payload['date_to'] ?? '';
            // Generate CSV and store as attachment.
            $orders = wc_get_orders( [
                'date_created' => $date_from . '...' . $date_to,
                'limit'        => -1,
            ] );

            $upload_dir = wp_upload_dir();
            $file       = $upload_dir['basedir'] . '/wcet-exports/orders-' . time() . '.csv';
            wp_mkdir_p( dirname( $file ) );

            $fp = fopen( $file, 'w' );
            fputcsv( $fp, [ 'ID', 'Status', 'Total', 'Customer', 'Date' ] );
            foreach ( $orders as $order ) {
                fputcsv( $fp, [
                    $order->get_id(),
                    $order->get_status(),
                    $order->get_total(),
                    $order->get_billing_email(),
                    $order->get_date_created()->format( 'Y-m-d H:i:s' ),
                ] );
            }
            fclose( $fp );

            return true;
        } );
    }

    /**
     * Remove completed jobs older than 7 days.
     */
    public function cleanup_completed_jobs() {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}wcet_background_jobs
             WHERE status = 'completed' AND completed_at < %s",
            gmdate( 'Y-m-d H:i:s', strtotime( '-7 days' ) )
        ) );
    }

    /**
     * Get job queue statistics.
     */
    public function get_stats() {
        global $wpdb;
        $table = $wpdb->prefix . 'wcet_background_jobs';

        return [
            'pending'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'pending'" ),
            'processing' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'processing'" ),
            'completed'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'completed'" ),
            'failed'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE status = 'failed'" ),
        ];
    }

    // --- Admin ---

    public function register_admin_page() {
        add_submenu_page(
            'woocommerce',
            __( 'Background Jobs', 'wc-enterprise' ),
            __( 'Background Jobs', 'wc-enterprise' ),
            'manage_woocommerce',
            'wcet-jobs',
            [ $this, 'render_admin_page' ]
        );
    }

    public function render_admin_page() {
        global $wpdb;
        $stats = $this->get_stats();
        $jobs  = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wcet_background_jobs ORDER BY id DESC LIMIT 50"
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Background Jobs', 'wc-enterprise' ); ?></h1>

            <div class="wcet-stats-cards" style="display:flex;gap:16px;margin-bottom:20px;">
                <?php foreach ( $stats as $label => $count ) : ?>
                <div style="background:#fff;padding:16px 24px;border:1px solid #ccd0d4;border-radius:4px;">
                    <h3 style="margin:0;color:#666;"><?php echo esc_html( ucfirst( $label ) ); ?></h3>
                    <p style="font-size:28px;margin:8px 0 0;font-weight:600;"><?php echo esc_html( $count ); ?></p>
                </div>
                <?php endforeach; ?>
            </div>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th><?php esc_html_e( 'Type', 'wc-enterprise' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'wc-enterprise' ); ?></th>
                        <th><?php esc_html_e( 'Attempts', 'wc-enterprise' ); ?></th>
                        <th><?php esc_html_e( 'Scheduled', 'wc-enterprise' ); ?></th>
                        <th><?php esc_html_e( 'Completed', 'wc-enterprise' ); ?></th>
                        <th><?php esc_html_e( 'Error', 'wc-enterprise' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'wc-enterprise' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $jobs as $job ) : ?>
                    <tr>
                        <td><?php echo esc_html( $job->id ); ?></td>
                        <td><code><?php echo esc_html( $job->job_type ); ?></code></td>
                        <td><span class="wcet-status-<?php echo esc_attr( $job->status ); ?>"><?php echo esc_html( $job->status ); ?></span></td>
                        <td><?php echo esc_html( $job->attempts . '/' . $job->max_attempts ); ?></td>
                        <td><?php echo esc_html( $job->scheduled_at ); ?></td>
                        <td><?php echo esc_html( $job->completed_at ?: '—' ); ?></td>
                        <td title="<?php echo esc_attr( $job->error_message ); ?>"><?php echo esc_html( wp_trim_words( $job->error_message ?: '—', 10 ) ); ?></td>
                        <td>
                            <?php if ( 'failed' === $job->status ) : ?>
                                <button class="button button-small wcet-retry-job" data-id="<?php echo esc_attr( $job->id ); ?>"><?php esc_html_e( 'Retry', 'wc-enterprise' ); ?></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <script>
        jQuery('.wcet-retry-job').on('click', function() {
            var id = jQuery(this).data('id');
            jQuery.post(ajaxurl, { action: 'wcet_retry_job', job_id: id, _wpnonce: '<?php echo wp_create_nonce( 'wcet_jobs' ); ?>' }, function() { location.reload(); });
        });
        </script>
        <?php
    }

    public function ajax_retry_job() {
        check_ajax_referer( 'wcet_jobs', '_wpnonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $wpdb->update( $wpdb->prefix . 'wcet_background_jobs', [
            'status'        => 'pending',
            'attempts'      => 0,
            'error_message' => null,
            'scheduled_at'  => current_time( 'mysql' ),
        ], [ 'id' => absint( $_POST['job_id'] ?? 0 ) ] );

        wp_send_json_success();
    }

    public function ajax_cancel_job() {
        check_ajax_referer( 'wcet_jobs', '_wpnonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'wcet_background_jobs',
            [ 'id' => absint( $_POST['job_id'] ?? 0 ) ]
        );

        wp_send_json_success();
    }
}

WCET_Background_Jobs::instance();
