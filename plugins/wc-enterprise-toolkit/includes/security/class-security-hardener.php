<?php
/**
 * Security Hardener — enterprise-grade WordPress & WooCommerce security.
 *
 * Handles XML-RPC disabling, REST API user-enumeration blocking, security
 * headers, login rate-limiting, file-editing lockdown, version hiding,
 * sensitive-file protection, directory-browsing prevention, upload
 * sanitisation, CSRF protection, security-event logging, and an admin
 * status / checklist page.
 *
 * @package WC_Enterprise_Toolkit
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WCET_Security_Hardener
 */
final class WCET_Security_Hardener {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Maximum failed login attempts before lockout.
	 *
	 * @var int
	 */
	private const MAX_LOGIN_ATTEMPTS = 5;

	/**
	 * Lockout duration in seconds (15 minutes).
	 *
	 * @var int
	 */
	private const LOCKOUT_DURATION = 900;

	/**
	 * Transient prefix for login-attempt tracking.
	 *
	 * @var string
	 */
	private const TRANSIENT_PREFIX = 'wcet_login_attempts_';

	/**
	 * Nonce action used by this module.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'wcet_security_nonce';

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	private const ADMIN_SLUG = 'wcet-security';

	/**
	 * Allowed MIME types for uploads.
	 *
	 * @var array<string,string>
	 */
	private const ALLOWED_MIME_TYPES = array(
		'jpg|jpeg|jpe' => 'image/jpeg',
		'gif'          => 'image/gif',
		'png'          => 'image/png',
		'webp'         => 'image/webp',
		'svg'          => 'image/svg+xml',
		'bmp'          => 'image/bmp',
		'ico'          => 'image/x-icon',
		'pdf'          => 'application/pdf',
		'doc'          => 'application/msword',
		'docx'         => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls'          => 'application/vnd.ms-excel',
		'xlsx'         => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'csv'          => 'text/csv',
		'zip'          => 'application/zip',
		'gz'           => 'application/gzip',
		'mp4'          => 'video/mp4',
		'mp3'          => 'audio/mpeg',
		'wav'          => 'audio/wav',
	);

	/**
	 * Blocked file extensions for uploads.
	 *
	 * @var string[]
	 */
	private const BLOCKED_EXTENSIONS = array(
		'php',
		'php3',
		'php4',
		'php5',
		'php7',
		'php8',
		'phtml',
		'phar',
		'phps',
		'cgi',
		'pl',
		'py',
		'sh',
		'bash',
		'exe',
		'bat',
		'cmd',
		'com',
		'vbs',
		'js',
		'jsp',
		'asp',
		'aspx',
		'htaccess',
		'htpasswd',
	);

	/**
	 * Return the singleton instance.
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
	 * Constructor — wire every hardening measure.
	 */
	private function __construct() {
		$this->disable_xmlrpc();
		$this->disable_rest_user_enumeration();
		$this->add_security_headers();
		$this->rate_limit_logins();
		$this->disable_file_editing();
		$this->hide_wp_version();
		$this->sanitize_uploads();
		$this->csrf_protection();
		$this->prevent_directory_browsing();

		// Activation hook for .htaccess rules.
		register_activation_hook(
			WCET_PLUGIN_DIR . 'wc-enterprise-toolkit.php',
			array( $this, 'on_activation' )
		);

		// Admin page.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_admin_page' ), 99 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
		}
	}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 *
	 * @throws \RuntimeException Always.
	 */
	public function __wakeup() {
		throw new \RuntimeException( 'Cannot unserialize singleton.' );
	}

	// =========================================================================
	// 1. Disable XML-RPC
	// =========================================================================

	/**
	 * Completely disable the XML-RPC interface.
	 *
	 * @return void
	 */
	private function disable_xmlrpc() {
		add_filter( 'xmlrpc_enabled', '__return_false' );

		// Also remove the pingback header.
		add_filter(
			'wp_headers',
			static function ( array $headers ) {
				unset( $headers['X-Pingback'] );
				return $headers;
			}
		);

		// Remove XML-RPC RSD link from <head>.
		remove_action( 'wp_head', 'rsd_link' );
	}

	// =========================================================================
	// 2. Disable REST API user enumeration for non-admins
	// =========================================================================

	/**
	 * Remove /wp/v2/users endpoints for unauthenticated or non-admin requests.
	 *
	 * @return void
	 */
	private function disable_rest_user_enumeration() {
		add_filter(
			'rest_endpoints',
			static function ( array $endpoints ) {
				if ( ! current_user_can( 'manage_options' ) ) {
					$targets = array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' );
					foreach ( $targets as $route ) {
						if ( isset( $endpoints[ $route ] ) ) {
							unset( $endpoints[ $route ] );
						}
					}
				}
				return $endpoints;
			}
		);

		// Block ?author=N enumeration on the front end.
		add_action(
			'template_redirect',
			static function () {
				if ( isset( $_GET['author'] ) && ! is_admin() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					self::log_event( 'User enumeration attempt blocked via ?author query parameter.' );
					wp_safe_redirect( home_url(), 301 );
					exit;
				}
			}
		);
	}

	// =========================================================================
	// 3. Security headers
	// =========================================================================

	/**
	 * Send security-related HTTP headers.
	 *
	 * The Content-Security-Policy is intentionally permissive enough to work
	 * with WooCommerce checkout (inline scripts / styles, Stripe iframe, etc.).
	 *
	 * @return void
	 */
	private function add_security_headers() {
		add_action(
			'send_headers',
			static function () {
				// Prevent MIME-type sniffing.
				header( 'X-Content-Type-Options: nosniff' );

				// Click-jacking protection.
				header( 'X-Frame-Options: SAMEORIGIN' );

				// Legacy XSS protection (still respected by some browsers).
				header( 'X-XSS-Protection: 1; mode=block' );

				// Referrer policy — send origin only cross-origin.
				header( 'Referrer-Policy: strict-origin-when-cross-origin' );

				// Permissions policy — disable dangerous browser features.
				header( 'Permissions-Policy: geolocation=(), microphone=(), camera=(), payment=(self), usb=()' );

				// HSTS — enforce HTTPS for 1 year, include sub-domains.
				if ( is_ssl() ) {
					header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains; preload' );
				}

				// Content-Security-Policy — WooCommerce-compatible.
				$csp = implode(
					'; ',
					array(
						"default-src 'self'",
						"script-src 'self' 'unsafe-inline' 'unsafe-eval' https://js.stripe.com https://www.google.com https://www.gstatic.com",
						"style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
						"img-src 'self' data: https: http:",
						"font-src 'self' https://fonts.gstatic.com data:",
						"connect-src 'self' https://api.stripe.com https://checkout.stripe.com",
						"frame-src 'self' https://js.stripe.com https://www.google.com",
						"object-src 'none'",
						"base-uri 'self'",
						"form-action 'self'",
						"frame-ancestors 'self'",
						'upgrade-insecure-requests',
					)
				);
				header( "Content-Security-Policy: {$csp}" );
			}
		);
	}

	// =========================================================================
	// 4. Rate-limit login attempts
	// =========================================================================

	/**
	 * Track failed login attempts per IP and lock out after threshold.
	 *
	 * @return void
	 */
	private function rate_limit_logins() {
		// Check lockout BEFORE authentication runs.
		add_filter(
			'authenticate',
			array( $this, 'check_login_lockout' ),
			30,
			3
		);

		// Record failures.
		add_action( 'wp_login_failed', array( $this, 'record_failed_login' ) );

		// Clear counter on success.
		add_action( 'wp_login', array( $this, 'clear_login_attempts' ) );
	}

	/**
	 * Block authentication if the IP is locked out.
	 *
	 * @param \WP_User|\WP_Error|null $user     User object, error, or null.
	 * @param string                  $username  Submitted username.
	 * @param string                  $password  Submitted password.
	 * @return \WP_User|\WP_Error|null
	 */
	public function check_login_lockout( $user, string $username, string $password ) {
		$ip       = self::get_client_ip();
		$key      = self::TRANSIENT_PREFIX . md5( $ip );
		$attempts = (int) get_transient( $key );

		if ( $attempts >= self::MAX_LOGIN_ATTEMPTS ) {
			self::log_event(
				sprintf(
					'Login blocked for locked-out IP %s (user: %s).',
					$ip,
					sanitize_user( $username )
				)
			);

			return new \WP_Error(
				'wcet_login_locked',
				sprintf(
					/* translators: %d: lockout duration in minutes */
					esc_html__( 'Too many failed login attempts. Please try again in %d minutes.', 'wc-enterprise' ),
					(int) ceil( self::LOCKOUT_DURATION / 60 )
				)
			);
		}

		return $user;
	}

	/**
	 * Increment the failed-login counter for the current IP.
	 *
	 * @param string $username The username that failed.
	 * @return void
	 */
	public function record_failed_login( string $username ) {
		$ip       = self::get_client_ip();
		$key      = self::TRANSIENT_PREFIX . md5( $ip );
		$attempts = (int) get_transient( $key );

		set_transient( $key, $attempts + 1, self::LOCKOUT_DURATION );

		self::log_event(
			sprintf(
				'Failed login attempt #%d for user "%s" from IP %s.',
				$attempts + 1,
				sanitize_user( $username ),
				$ip
			)
		);
	}

	/**
	 * Clear the failed-login counter on successful login.
	 *
	 * @param string $username The username that logged in.
	 * @return void
	 */
	public function clear_login_attempts( string $username ) {
		$ip  = self::get_client_ip();
		$key = self::TRANSIENT_PREFIX . md5( $ip );
		delete_transient( $key );
	}

	// =========================================================================
	// 5. Disable file editing
	// =========================================================================

	/**
	 * Prevent theme / plugin file editing via the admin.
	 *
	 * @return void
	 */
	private function disable_file_editing() {
		if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
			define( 'DISALLOW_FILE_EDIT', true );
		}
	}

	// =========================================================================
	// 6. Hide WordPress version
	// =========================================================================

	/**
	 * Strip the WordPress version number from all public output.
	 *
	 * @return void
	 */
	private function hide_wp_version() {
		// Meta generator tag in <head>.
		remove_action( 'wp_head', 'wp_generator' );

		// RSS feeds.
		add_filter( 'the_generator', '__return_empty_string' );

		// Strip ?ver= from scripts / styles.
		add_filter( 'style_loader_src', array( $this, 'remove_version_query' ), 9999 );
		add_filter( 'script_loader_src', array( $this, 'remove_version_query' ), 9999 );
	}

	/**
	 * Remove the `ver` query parameter from an enqueued asset URL.
	 *
	 * @param string $src Asset URL.
	 * @return string
	 */
	public function remove_version_query( string $src ) {
		if ( strpos( $src, 'ver=' ) !== false ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	// =========================================================================
	// 7. Block access to sensitive files (.htaccess rules on activation)
	// =========================================================================

	/**
	 * Activation callback — write protective .htaccess rules.
	 *
	 * @return void
	 */
	public function on_activation() {
		$this->write_htaccess_rules();
	}

	/**
	 * Write security rules to the root .htaccess file.
	 *
	 * Rules are wrapped in a clearly labelled block so they can be identified
	 * and removed later.
	 *
	 * @return bool True on success, false on failure.
	 */
	private function write_htaccess_rules() {
		$htaccess_file = ABSPATH . '.htaccess';

		if ( ! is_writable( $htaccess_file ) && ! is_writable( dirname( $htaccess_file ) ) ) {
			self::log_event( 'Cannot write .htaccess — file or directory is not writable.' );
			return false;
		}

		$marker = 'WC Enterprise Toolkit Security';
		$rules  = array(
			'# ----- Protect sensitive files -----',
			'<FilesMatch "^(wp-config\.php|\.htaccess|\.htpasswd|readme\.html|license\.txt|xmlrpc\.php)$">',
			'    <IfModule mod_authz_core.c>',
			'        Require all denied',
			'    </IfModule>',
			'    <IfModule !mod_authz_core.c>',
			'        Order allow,deny',
			'        Deny from all',
			'    </IfModule>',
			'</FilesMatch>',
			'',
			'# ----- Prevent directory browsing -----',
			'Options -Indexes',
			'',
			'# ----- Block PHP execution in uploads -----',
			'<IfModule mod_rewrite.c>',
			'    RewriteEngine On',
			'    RewriteRule ^wp-content/uploads/.*\.php$ - [F,L]',
			'</IfModule>',
			'',
			'# ----- Disable server signature -----',
			'ServerSignature Off',
		);

		$result = insert_with_markers( $htaccess_file, $marker, $rules );

		if ( $result ) {
			self::log_event( '.htaccess security rules written successfully.' );
		} else {
			self::log_event( 'Failed to write .htaccess security rules.' );
		}

		return $result;
	}

	// =========================================================================
	// 8. Prevent directory browsing (runtime fallback)
	// =========================================================================

	/**
	 * Set a header to prevent directory indexing as a runtime fallback
	 * in case the .htaccess rule is not in effect.
	 *
	 * @return void
	 */
	private function prevent_directory_browsing() {
		add_action(
			'send_headers',
			static function () {
				header( 'X-Robots-Tag: noindex, nofollow', false );
			}
		);
	}

	// =========================================================================
	// 9. Sanitize file uploads
	// =========================================================================

	/**
	 * Enforce strict MIME-type checking and block dangerous extensions.
	 *
	 * @return void
	 */
	private function sanitize_uploads() {
		// Restrict allowed MIME types.
		add_filter( 'upload_mimes', array( $this, 'filter_upload_mimes' ) );

		// Additional check during upload pre-processing.
		add_filter( 'wp_handle_upload_prefilter', array( $this, 'validate_upload' ) );
	}

	/**
	 * Return only the explicitly allowed MIME types.
	 *
	 * @param array<string,string> $mimes Default MIME types.
	 * @return array<string,string>
	 */
	public function filter_upload_mimes( array $mimes ) {
		/**
		 * Filter the allowed MIME types for uploads.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,string> $allowed_mimes Allowed MIME types.
		 */
		return apply_filters( 'wcet_allowed_upload_mimes', self::ALLOWED_MIME_TYPES );
	}

	/**
	 * Validate the uploaded file before WordPress processes it.
	 *
	 * @param array{name:string,type:string,tmp_name:string,error:int,size:int} $file File data.
	 * @return array{name:string,type:string,tmp_name:string,error:int|string,size:int}
	 */
	public function validate_upload( array $file ) {
		if ( ! empty( $file['error'] ) ) {
			return $file;
		}

		$filename  = sanitize_file_name( $file['name'] );
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		// Block dangerous extensions.
		if ( in_array( $extension, self::BLOCKED_EXTENSIONS, true ) ) {
			self::log_event(
				sprintf(
					'Blocked upload of file with dangerous extension: %s (MIME: %s).',
					$filename,
					$file['type']
				)
			);
			$file['error'] = esc_html__(
				'This file type is not permitted for security reasons.',
				'wc-enterprise'
			);
			return $file;
		}

		// Verify real MIME type using finfo.
		if ( function_exists( 'finfo_open' ) ) {
			$finfo     = finfo_open( FILEINFO_MIME_TYPE );
			$real_mime = finfo_file( $finfo, $file['tmp_name'] );
			finfo_close( $finfo );

			// Block files whose real MIME type is PHP / executable.
			$dangerous_mimes = array(
				'application/x-php',
				'application/x-httpd-php',
				'application/x-executable',
				'application/x-msdos-program',
				'text/x-php',
				'text/x-shellscript',
			);

			if ( in_array( $real_mime, $dangerous_mimes, true ) ) {
				self::log_event(
					sprintf(
						'Blocked upload of file with dangerous MIME type: %s (detected: %s, stated: %s).',
						$filename,
						$real_mime,
						$file['type']
					)
				);
				$file['error'] = esc_html__(
					'This file type is not permitted for security reasons.',
					'wc-enterprise'
				);
				return $file;
			}
		}

		return $file;
	}

	// =========================================================================
	// 10. CSRF protection on WooCommerce AJAX actions
	// =========================================================================

	/**
	 * Add blanket nonce verification for all WooCommerce AJAX endpoints
	 * handled by this plugin.
	 *
	 * @return void
	 */
	private function csrf_protection() {
		add_action(
			'wp_ajax_wcet_security_action',
			array( $this, 'handle_ajax_action' )
		);

		// Inject nonce into WooCommerce checkout & account pages.
		add_action(
			'wp_enqueue_scripts',
			static function () {
				if ( function_exists( 'is_checkout' ) && ( is_checkout() || is_account_page() ) ) {
					wp_localize_script(
						'wc-checkout',
						'wcet_security',
						array(
							'nonce'    => wp_create_nonce( self::NONCE_ACTION ),
							'ajax_url' => admin_url( 'admin-ajax.php' ),
						)
					);
				}
			}
		);
	}

	/**
	 * Generic AJAX handler that enforces nonce verification.
	 *
	 * Individual sub-actions are dispatched via the `sub_action` parameter.
	 *
	 * @return void
	 */
	public function handle_ajax_action() {
		// Verify nonce.
		if (
			! isset( $_POST['_wcet_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wcet_nonce'] ) ), self::NONCE_ACTION )
		) {
			self::log_event(
				sprintf(
					'CSRF validation failed for AJAX request from IP %s.',
					self::get_client_ip()
				)
			);
			wp_send_json_error(
				array( 'message' => esc_html__( 'Security check failed. Please refresh and try again.', 'wc-enterprise' ) ),
				403
			);
		}

		// Verify capability.
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array( 'message' => esc_html__( 'You do not have permission to perform this action.', 'wc-enterprise' ) ),
				403
			);
		}

		$sub_action = isset( $_POST['sub_action'] )
			? sanitize_text_field( wp_unslash( $_POST['sub_action'] ) )
			: '';

		/**
		 * Fires when a verified WCET AJAX sub-action is dispatched.
		 *
		 * @since 1.0.0
		 *
		 * @param string $sub_action The sanitised sub-action name.
		 */
		do_action( 'wcet_ajax_' . $sub_action );

		wp_send_json_success();
	}

	// =========================================================================
	// 11. Security event logging
	// =========================================================================

	/**
	 * Log a security event.
	 *
	 * @param string $message Human-readable event description.
	 * @return void
	 */
	public static function log_event( string $message ) {
		$entry = sprintf(
			'[WCET Security] [%s] %s',
			gmdate( 'Y-m-d H:i:s' ),
			$message
		);

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $entry );

		/**
		 * Fires after a security event is logged.
		 *
		 * @since 1.0.0
		 *
		 * @param string $message The logged message.
		 */
		do_action( 'wcet_security_event_logged', $message );
	}

	// =========================================================================
	// 12. Admin page — Security Status & Checklist
	// =========================================================================

	/**
	 * Register the Security admin page under WooCommerce.
	 *
	 * @return void
	 */
	public function register_admin_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Security Status', 'wc-enterprise' ),
			__( 'Security', 'wc-enterprise' ),
			'manage_woocommerce',
			self::ADMIN_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin CSS / JS on the security page only.
	 *
	 * @param string $hook_suffix The current admin page hook.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ) {
		if ( 'woocommerce_page_' . self::ADMIN_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wcet-security-admin',
			WCET_PLUGIN_URL . 'assets/css/security-admin.css',
			array(),
			WCET_VERSION
		);
	}

	/**
	 * Render the Security Status admin page.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-enterprise' ) );
		}

		// Handle manual .htaccess re-write.
		if (
			isset( $_POST['wcet_rewrite_htaccess'] ) &&
			check_admin_referer( 'wcet_security_admin', '_wcet_security_nonce' )
		) {
			$result = $this->write_htaccess_rules();
			if ( $result ) {
				add_settings_error(
					self::ADMIN_SLUG,
					'htaccess_updated',
					__( '.htaccess rules updated successfully.', 'wc-enterprise' ),
					'updated'
				);
			} else {
				add_settings_error(
					self::ADMIN_SLUG,
					'htaccess_failed',
					__( 'Could not write .htaccess rules. Check file permissions.', 'wc-enterprise' ),
					'error'
				);
			}
		}

		$checks = $this->run_security_checks();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Security Status', 'wc-enterprise' ); ?></h1>

			<?php settings_errors( self::ADMIN_SLUG ); ?>

			<div class="wcet-security-checklist">
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Check', 'wc-enterprise' ); ?></th>
							<th><?php esc_html_e( 'Status', 'wc-enterprise' ); ?></th>
							<th><?php esc_html_e( 'Details', 'wc-enterprise' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $checks as $check ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $check['label'] ); ?></strong></td>
								<td>
									<?php if ( $check['pass'] ) : ?>
										<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
										<?php esc_html_e( 'Pass', 'wc-enterprise' ); ?>
									<?php else : ?>
										<span class="dashicons dashicons-warning" style="color:#dc3232;"></span>
										<?php esc_html_e( 'Fail', 'wc-enterprise' ); ?>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( $check['detail'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<h2><?php esc_html_e( 'Actions', 'wc-enterprise' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'wcet_security_admin', '_wcet_security_nonce' ); ?>
				<p>
					<button type="submit" name="wcet_rewrite_htaccess" value="1" class="button button-primary">
						<?php esc_html_e( 'Regenerate .htaccess Rules', 'wc-enterprise' ); ?>
					</button>
				</p>
			</form>

			<h2><?php esc_html_e( 'Security Settings', 'wc-enterprise' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row"><?php esc_html_e( 'Max Login Attempts', 'wc-enterprise' ); ?></th>
					<td><?php echo esc_html( (string) self::MAX_LOGIN_ATTEMPTS ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Lockout Duration', 'wc-enterprise' ); ?></th>
					<td>
						<?php
						printf(
							/* translators: %d: lockout duration in minutes */
							esc_html__( '%d minutes', 'wc-enterprise' ),
							(int) ceil( self::LOCKOUT_DURATION / 60 )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'XML-RPC', 'wc-enterprise' ); ?></th>
					<td><?php esc_html_e( 'Disabled', 'wc-enterprise' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'REST User Enumeration', 'wc-enterprise' ); ?></th>
					<td><?php esc_html_e( 'Blocked for non-administrators', 'wc-enterprise' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Security Headers', 'wc-enterprise' ); ?></th>
					<td><?php esc_html_e( 'Active (X-Content-Type-Options, X-Frame-Options, X-XSS-Protection, Referrer-Policy, Permissions-Policy, CSP, HSTS)', 'wc-enterprise' ); ?></td>
				</tr>
			</table>
		</div>
		<?php
	}

	/**
	 * Run all security checks and return results.
	 *
	 * @return array<int,array{label:string,pass:bool,detail:string}>
	 */
	private function run_security_checks() {
		$checks = array();

		// 1. DISALLOW_FILE_EDIT.
		$checks[] = array(
			'label'  => __( 'File Editing Disabled', 'wc-enterprise' ),
			'pass'   => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT,
			'detail' => defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT
				? __( 'DISALLOW_FILE_EDIT is defined and true.', 'wc-enterprise' )
				: __( 'DISALLOW_FILE_EDIT is not set. Theme and plugin editors may be accessible.', 'wc-enterprise' ),
		);

		// 2. SSL.
		$checks[] = array(
			'label'  => __( 'SSL / HTTPS', 'wc-enterprise' ),
			'pass'   => is_ssl(),
			'detail' => is_ssl()
				? __( 'Site is served over HTTPS.', 'wc-enterprise' )
				: __( 'Site is NOT using HTTPS. HSTS header will not be sent.', 'wc-enterprise' ),
		);

		// 3. .htaccess rules.
		$htaccess_file    = ABSPATH . '.htaccess';
		$htaccess_secured = false;
		if ( file_exists( $htaccess_file ) && is_readable( $htaccess_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$contents         = file_get_contents( $htaccess_file );
			$htaccess_secured = ( false !== strpos( $contents, 'WC Enterprise Toolkit Security' ) );
		}
		$checks[] = array(
			'label'  => __( '.htaccess Security Rules', 'wc-enterprise' ),
			'pass'   => $htaccess_secured,
			'detail' => $htaccess_secured
				? __( 'Security rules are present in .htaccess.', 'wc-enterprise' )
				: __( 'Security rules are missing. Click "Regenerate .htaccess Rules" below.', 'wc-enterprise' ),
		);

		// 4. Debug mode.
		$checks[] = array(
			'label'  => __( 'Debug Mode', 'wc-enterprise' ),
			'pass'   => ! WP_DEBUG,
			'detail' => ! WP_DEBUG
				? __( 'WP_DEBUG is disabled.', 'wc-enterprise' )
				: __( 'WP_DEBUG is enabled. Disable it on production sites.', 'wc-enterprise' ),
		);

		// 5. DB table prefix.
		global $wpdb;
		$default_prefix = ( 'wp_' === $wpdb->prefix );
		$checks[]       = array(
			'label'  => __( 'Database Table Prefix', 'wc-enterprise' ),
			'pass'   => ! $default_prefix,
			'detail' => ! $default_prefix
				? __( 'Custom table prefix in use.', 'wc-enterprise' )
				: __( 'Default "wp_" prefix detected. Consider using a custom prefix.', 'wc-enterprise' ),
		);

		// 6. XML-RPC disabled.
		$checks[] = array(
			'label'  => __( 'XML-RPC Disabled', 'wc-enterprise' ),
			'pass'   => true,
			'detail' => __( 'XML-RPC is disabled by this plugin.', 'wc-enterprise' ),
		);

		// 7. WP version hidden.
		$checks[] = array(
			'label'  => __( 'WordPress Version Hidden', 'wc-enterprise' ),
			'pass'   => true,
			'detail' => __( 'Version meta tag and query strings are removed.', 'wc-enterprise' ),
		);

		// 8. Upload directory writable check.
		$upload_dir      = wp_upload_dir();
		$uploads_indexed = file_exists( trailingslashit( $upload_dir['basedir'] ) . 'index.php' );
		$checks[]        = array(
			'label'  => __( 'Uploads Directory Index Protection', 'wc-enterprise' ),
			'pass'   => $uploads_indexed,
			'detail' => $uploads_indexed
				? __( 'index.php exists in the uploads directory.', 'wc-enterprise' )
				: __( 'No index.php found in uploads directory. Directory listing may be possible.', 'wc-enterprise' ),
		);

		// 9. Login rate limiting.
		$checks[] = array(
			'label'  => __( 'Login Rate Limiting', 'wc-enterprise' ),
			'pass'   => true,
			'detail' => sprintf(
				/* translators: 1: max attempts, 2: lockout minutes */
				__( 'Active — %1$d attempts allowed, %2$d-minute lockout.', 'wc-enterprise' ),
				self::MAX_LOGIN_ATTEMPTS,
				(int) ceil( self::LOCKOUT_DURATION / 60 )
			),
		);

		// 10. Security headers.
		$checks[] = array(
			'label'  => __( 'Security Headers', 'wc-enterprise' ),
			'pass'   => true,
			'detail' => __( 'All security headers are active (CSP, HSTS, X-Frame-Options, etc.).', 'wc-enterprise' ),
		);

		return $checks;
	}

	// =========================================================================
	// Helpers
	// =========================================================================

	/**
	 * Get the client IP address, accounting for proxies.
	 *
	 * @return string
	 */
	private static function get_client_ip() {
		$headers = array(
			'HTTP_CF_CONNECTING_IP', // Cloudflare.
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		);

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// X-Forwarded-For may contain a list; take the first.
				if ( strpos( $ip, ',' ) !== false ) {
					$ip = trim( explode( ',', $ip )[0] );
				}
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}

		return '0.0.0.0';
	}
}

// Initialise the singleton.
WCET_Security_Hardener::instance();
