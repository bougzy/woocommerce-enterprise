<?php
/**
 * Token-Based API Authentication
 *
 * Provides API token generation, validation, and management for the WCET
 * REST API. Tokens are stored hashed (SHA-256) in a dedicated database table.
 * Supports permission levels (read, write, admin) and optional expiry dates.
 *
 * Hooks into WordPress REST authentication to validate Bearer tokens.
 * Includes an admin UI page under WooCommerce for token management.
 *
 * @package WCET
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class WCET_Token_Auth {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Token prefix.
	 *
	 * @var string
	 */
	private const TOKEN_PREFIX = 'wcet_';

	/**
	 * Token hex string length (excluding prefix).
	 *
	 * @var int
	 */
	private const TOKEN_LENGTH = 40;

	/**
	 * Database table name (without prefix).
	 *
	 * @var string
	 */
	private const TABLE_NAME = 'wcet_api_tokens';

	/**
	 * Valid permission levels.
	 *
	 * @var string[]
	 */
	private const VALID_PERMISSIONS = [ 'read', 'write', 'admin' ];

	/**
	 * The authenticated token record for the current request, if any.
	 *
	 * @var array|null
	 */
	private $current_token = null;

	/**
	 * Get singleton instance.
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
	 * Constructor.
	 */
	private function __construct() {
		// REST authentication hook.
		add_filter( 'rest_authentication_errors', [ $this, 'authenticate' ], 10 );

		// Set authenticated user capabilities based on token permissions.
		add_filter( 'rest_pre_dispatch', [ $this, 'set_token_user' ], 5, 3 );

		// Admin UI.
		add_action( 'admin_menu', [ $this, 'register_admin_page' ] );

		// AJAX handlers for token management.
		add_action( 'wp_ajax_wcet_generate_token', [ $this, 'ajax_generate_token' ] );
		add_action( 'wp_ajax_wcet_revoke_token', [ $this, 'ajax_revoke_token' ] );

		// Database table creation on activation.
		add_action( 'admin_init', [ $this, 'maybe_create_table' ] );
	}

	/**
	 * Create the tokens table if it does not exist.
	 *
	 * @return void
	 */
	public function maybe_create_table() {
		$db_version_key = 'wcet_api_tokens_db_version';
		$current_version = '1.0.0';

		if ( get_option( $db_version_key ) === $current_version ) {
			return;
		}

		global $wpdb;
		$table_name      = $wpdb->prefix . self::TABLE_NAME;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash varchar(64) NOT NULL,
			label varchar(255) NOT NULL DEFAULT '',
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			permissions varchar(20) NOT NULL DEFAULT 'read',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			expires_at datetime DEFAULT NULL,
			last_used_at datetime DEFAULT NULL,
			last_used_ip varchar(45) DEFAULT NULL,
			is_active tinyint(1) NOT NULL DEFAULT 1,
			PRIMARY KEY (id),
			UNIQUE KEY token_hash (token_hash),
			KEY user_id (user_id),
			KEY is_active (is_active)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( $db_version_key, $current_version );
	}

	/**
	 * Authenticate REST API requests using Bearer tokens.
	 *
	 * Hooks into rest_authentication_errors. Returns null to pass through
	 * to the next authentication handler if no Bearer token is present.
	 *
	 * @param WP_Error|true|null $result Existing authentication result.
	 * @return WP_Error|true|null
	 */
	public function authenticate( $result ) {
		// If a previous authentication handler already determined the result, respect it.
		if ( null !== $result ) {
			return $result;
		}

		// Only process REST API requests.
		if ( ! defined( 'REST_REQUEST' ) || ! REST_REQUEST ) {
			return $result;
		}

		$token = $this->extract_bearer_token();

		if ( null === $token ) {
			// No Bearer token present; let other authentication methods handle it.
			return null;
		}

		// Validate the token format.
		if ( ! $this->is_valid_token_format( $token ) ) {
			return new WP_Error(
				'wcet_invalid_token_format',
				__( 'The provided API token has an invalid format.', 'wc-enterprise' ),
				[ 'status' => 401 ]
			);
		}

		// Look up the hashed token in the database.
		$token_record = $this->find_token( $token );

		if ( null === $token_record ) {
			return new WP_Error(
				'wcet_invalid_token',
				__( 'The provided API token is invalid or has been revoked.', 'wc-enterprise' ),
				[ 'status' => 401 ]
			);
		}

		// Check if the token is active.
		if ( ! (bool) $token_record['is_active'] ) {
			return new WP_Error(
				'wcet_token_revoked',
				__( 'This API token has been revoked.', 'wc-enterprise' ),
				[ 'status' => 401 ]
			);
		}

		// Check expiry.
		if ( ! empty( $token_record['expires_at'] ) ) {
			$expires = strtotime( $token_record['expires_at'] );
			if ( $expires && $expires < time() ) {
				return new WP_Error(
					'wcet_token_expired',
					__( 'This API token has expired.', 'wc-enterprise' ),
					[ 'status' => 401 ]
				);
			}
		}

		// Token is valid. Store it for permission checks and update last used.
		$this->current_token = $token_record;
		$this->update_last_used( $token_record['id'] );

		// Set the current user to the token's associated user.
		if ( (int) $token_record['user_id'] > 0 ) {
			wp_set_current_user( (int) $token_record['user_id'] );
		}

		return true;
	}

	/**
	 * Set user context on pre-dispatch for token-authenticated requests.
	 *
	 * This ensures capability checks work correctly for token-authenticated users.
	 *
	 * @param mixed           $result  Pre-dispatch result.
	 * @param WP_REST_Server  $server  REST server instance.
	 * @param WP_REST_Request $request The request.
	 * @return mixed
	 */
	public function set_token_user( $result, WP_REST_Server $server, WP_REST_Request $request ) {
		if ( null !== $this->current_token && (int) $this->current_token['user_id'] > 0 ) {
			wp_set_current_user( (int) $this->current_token['user_id'] );
		}
		return $result;
	}

	/**
	 * Extract the Bearer token from the Authorization header.
	 *
	 * @return string|null The token string, or null if not present.
	 */
	private function extract_bearer_token() {
		$auth_header = null;

		// Check for the standard Authorization header.
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth_header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			// Some server configurations put it here.
			$auth_header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		} elseif ( function_exists( 'getallheaders' ) ) {
			$headers = getallheaders();
			// Headers may be case-insensitive.
			foreach ( $headers as $name => $value ) {
				if ( strtolower( $name ) === 'authorization' ) {
					$auth_header = sanitize_text_field( $value );
					break;
				}
			}
		}

		if ( empty( $auth_header ) ) {
			return null;
		}

		// Parse the Bearer token.
		if ( preg_match( '/^Bearer\s+(.+)$/i', $auth_header, $matches ) ) {
			return trim( $matches[1] );
		}

		return null;
	}

	/**
	 * Check if a token string matches the expected format.
	 *
	 * @param string $token The token string.
	 * @return bool
	 */
	private function is_valid_token_format( string $token ) {
		$expected_length = strlen( self::TOKEN_PREFIX ) + self::TOKEN_LENGTH;
		return strlen( $token ) === $expected_length
			&& strpos( $token, self::TOKEN_PREFIX ) === 0
			&& ctype_xdigit( substr( $token, strlen( self::TOKEN_PREFIX ) ) );
	}

	/**
	 * Find a token record by its plaintext value.
	 *
	 * @param string $token The plaintext token.
	 * @return array|null The token record, or null if not found.
	 */
	private function find_token( string $token ) {
		global $wpdb;

		$hash   = hash( 'sha256', $token );
		$table  = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$record = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE token_hash = %s LIMIT 1",
				$hash
			),
			ARRAY_A
		);

		return $record ?: null;
	}

	/**
	 * Update the last used timestamp and IP for a token.
	 *
	 * @param int $token_id The token record ID.
	 * @return void
	 */
	private function update_last_used( int $token_id ) {
		global $wpdb;

		$ip = $this->get_client_ip();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->prefix . self::TABLE_NAME,
			[
				'last_used_at' => current_time( 'mysql' ),
				'last_used_ip' => sanitize_text_field( $ip ),
			],
			[ 'id' => $token_id ],
			[ '%s', '%s' ],
			[ '%d' ]
		);
	}

	/**
	 * Generate a new API token.
	 *
	 * @param int    $user_id     WordPress user ID to associate with the token.
	 * @param string $label       Human-readable label for the token.
	 * @param string $permissions Permission level (read, write, admin).
	 * @param string $expires_at  Optional expiry datetime (Y-m-d H:i:s).
	 * @return array{token: string, id: int}|WP_Error
	 */
	public function generate_token( int $user_id, string $label, string $permissions = 'read', string $expires_at = '' ) {
		// Validate user exists.
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new WP_Error(
				'wcet_invalid_user',
				__( 'The specified user does not exist.', 'wc-enterprise' )
			);
		}

		// Validate permissions.
		if ( ! in_array( $permissions, self::VALID_PERMISSIONS, true ) ) {
			return new WP_Error(
				'wcet_invalid_permissions',
				sprintf(
					/* translators: %s: valid permission levels */
					__( 'Invalid permissions. Must be one of: %s', 'wc-enterprise' ),
					implode( ', ', self::VALID_PERMISSIONS )
				)
			);
		}

		// Generate the token.
		$raw_hex = bin2hex( random_bytes( self::TOKEN_LENGTH / 2 ) );
		$token   = self::TOKEN_PREFIX . $raw_hex;
		$hash    = hash( 'sha256', $token );

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$inserted = $wpdb->insert(
			$wpdb->prefix . self::TABLE_NAME,
			[
				'token_hash'  => $hash,
				'label'       => sanitize_text_field( $label ),
				'user_id'     => $user_id,
				'permissions' => $permissions,
				'created_at'  => current_time( 'mysql' ),
				'expires_at'  => ! empty( $expires_at ) ? sanitize_text_field( $expires_at ) : null,
				'is_active'   => 1,
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%d' ]
		);

		if ( false === $inserted ) {
			return new WP_Error(
				'wcet_token_creation_failed',
				__( 'Failed to create the API token.', 'wc-enterprise' )
			);
		}

		/**
		 * Fires after a new API token is generated.
		 *
		 * @param int    $token_id   The token record ID.
		 * @param int    $user_id    The associated user ID.
		 * @param string $permissions The token permissions.
		 */
		do_action( 'wcet_api_token_generated', $wpdb->insert_id, $user_id, $permissions );

		return [
			'token' => $token,
			'id'    => (int) $wpdb->insert_id,
		];
	}

	/**
	 * Revoke (deactivate) a token by its ID.
	 *
	 * @param int $token_id The token record ID.
	 * @return bool True on success.
	 */
	public function revoke_token( int $token_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->update(
			$wpdb->prefix . self::TABLE_NAME,
			[ 'is_active' => 0 ],
			[ 'id' => $token_id ],
			[ '%d' ],
			[ '%d' ]
		);

		if ( false !== $result ) {
			/**
			 * Fires after an API token is revoked.
			 *
			 * @param int $token_id The revoked token record ID.
			 */
			do_action( 'wcet_api_token_revoked', $token_id );
		}

		return false !== $result;
	}

	/**
	 * Get the current request's authenticated token record.
	 *
	 * @return array|null
	 */
	public function get_current_token() {
		return $this->current_token;
	}

	/**
	 * Check if the current token has a specific permission level.
	 *
	 * Permission hierarchy: admin > write > read.
	 *
	 * @param string $required_permission The required permission level.
	 * @return bool
	 */
	public function current_token_can( string $required_permission ) {
		if ( null === $this->current_token ) {
			return false;
		}

		$hierarchy = [
			'read'  => 1,
			'write' => 2,
			'admin' => 3,
		];

		$token_level    = $hierarchy[ $this->current_token['permissions'] ] ?? 0;
		$required_level = $hierarchy[ $required_permission ] ?? 0;

		return $token_level >= $required_level;
	}

	/**
	 * Get the client IP address from the request.
	 *
	 * @return string
	 */
	private function get_client_ip() {
		$headers = [
			'HTTP_CF_CONNECTING_IP',  // Cloudflare.
			'HTTP_X_FORWARDED_FOR',
			'HTTP_X_REAL_IP',
			'REMOTE_ADDR',
		];

		foreach ( $headers as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) );
				// X-Forwarded-For may contain multiple IPs; take the first.
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

	// -------------------------------------------------------------------------
	// Admin UI
	// -------------------------------------------------------------------------

	/**
	 * Register the admin page under WooCommerce.
	 *
	 * @return void
	 */
	public function register_admin_page() {
		add_submenu_page(
			'woocommerce',
			__( 'API Tokens', 'wc-enterprise' ),
			__( 'API Tokens', 'wc-enterprise' ),
			'manage_woocommerce',
			'wcet-api-tokens',
			[ $this, 'render_admin_page' ]
		);
	}

	/**
	 * Render the admin page for token management.
	 *
	 * @return void
	 */
	public function render_admin_page() {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE_NAME;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tokens = $wpdb->get_results(
			"SELECT t.*, u.user_login, u.display_name
			 FROM {$table} t
			 LEFT JOIN {$wpdb->users} u ON t.user_id = u.ID
			 ORDER BY t.created_at DESC",
			ARRAY_A
		);

		if ( ! is_array( $tokens ) ) {
			$tokens = [];
		}

		// Get users with manage_woocommerce capability for the dropdown.
		$managers = get_users( [
			'capability' => 'manage_woocommerce',
			'fields'     => [ 'ID', 'user_login', 'display_name' ],
		] );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WCET API Tokens', 'wc-enterprise' ); ?></h1>

			<div id="wcet-token-notice" style="display:none;" class="notice notice-success">
				<p>
					<?php esc_html_e( 'Token generated successfully. Copy it now -- it will not be shown again:', 'wc-enterprise' ); ?>
					<br>
					<code id="wcet-new-token" style="font-size: 14px; padding: 4px 8px; user-select: all;"></code>
				</p>
			</div>

			<h2><?php esc_html_e( 'Generate New Token', 'wc-enterprise' ); ?></h2>
			<form id="wcet-token-form" method="post">
				<?php wp_nonce_field( 'wcet_token_management', '_wcet_token_nonce' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="token_label"><?php esc_html_e( 'Label', 'wc-enterprise' ); ?></label>
						</th>
						<td>
							<input type="text" id="token_label" name="label" class="regular-text" required
								placeholder="<?php esc_attr_e( 'e.g., Mobile App, External Integration', 'wc-enterprise' ); ?>">
							<p class="description">
								<?php esc_html_e( 'A descriptive label to identify this token.', 'wc-enterprise' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="token_user"><?php esc_html_e( 'User', 'wc-enterprise' ); ?></label>
						</th>
						<td>
							<select id="token_user" name="user_id" required>
								<option value=""><?php esc_html_e( '-- Select User --', 'wc-enterprise' ); ?></option>
								<?php foreach ( $managers as $manager ) : ?>
									<option value="<?php echo esc_attr( $manager->ID ); ?>">
										<?php echo esc_html( $manager->display_name . ' (' . $manager->user_login . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'The WordPress user this token will act as.', 'wc-enterprise' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="token_permissions"><?php esc_html_e( 'Permissions', 'wc-enterprise' ); ?></label>
						</th>
						<td>
							<select id="token_permissions" name="permissions">
								<option value="read"><?php esc_html_e( 'Read', 'wc-enterprise' ); ?></option>
								<option value="write"><?php esc_html_e( 'Write', 'wc-enterprise' ); ?></option>
								<option value="admin"><?php esc_html_e( 'Admin', 'wc-enterprise' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Read: view data. Write: view + create/update. Admin: full access.', 'wc-enterprise' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="token_expires"><?php esc_html_e( 'Expires', 'wc-enterprise' ); ?></label>
						</th>
						<td>
							<input type="datetime-local" id="token_expires" name="expires_at">
							<p class="description">
								<?php esc_html_e( 'Leave empty for a non-expiring token.', 'wc-enterprise' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Generate Token', 'wc-enterprise' ); ?>
					</button>
				</p>
			</form>

			<h2><?php esc_html_e( 'Existing Tokens', 'wc-enterprise' ); ?></h2>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'ID', 'wc-enterprise' ); ?></th>
						<th><?php esc_html_e( 'Label', 'wc-enterprise' ); ?></th>
						<th><?php esc_html_e( 'User', 'wc-enterprise' ); ?></th>
						<th><?php esc_html_e( 'Permissions', 'wc-enterprise' ); ?></th>
						<th><?php esc_html_e( 'Created', 'wc-enterprise' ); ?></th>
						<th><?php esc_html_e( 'Expires', 'wc-enterprise' ); ?></th>
						<th><?php esc_html_e( 'Last Used', 'wc-enterprise' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wc-enterprise' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wc-enterprise' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! empty( $tokens ) ) : ?>
					<?php foreach ( $tokens as $tok ) : ?>
						<tr>
							<td><?php echo esc_html( $tok['id'] ); ?></td>
							<td><?php echo esc_html( $tok['label'] ); ?></td>
							<td>
								<?php
								echo esc_html(
									! empty( $tok['display_name'] )
										? $tok['display_name'] . ' (' . $tok['user_login'] . ')'
										: __( 'Unknown', 'wc-enterprise' )
								);
								?>
							</td>
							<td>
								<span class="wcet-permission-badge wcet-permission-<?php echo esc_attr( $tok['permissions'] ); ?>">
									<?php echo esc_html( ucfirst( $tok['permissions'] ) ); ?>
								</span>
							</td>
							<td><?php echo esc_html( $tok['created_at'] ); ?></td>
							<td><?php echo esc_html( $tok['expires_at'] ?: __( 'Never', 'wc-enterprise' ) ); ?></td>
							<td>
								<?php
								if ( ! empty( $tok['last_used_at'] ) ) {
									echo esc_html( $tok['last_used_at'] );
									if ( ! empty( $tok['last_used_ip'] ) ) {
										echo '<br><small>' . esc_html( $tok['last_used_ip'] ) . '</small>';
									}
								} else {
									esc_html_e( 'Never', 'wc-enterprise' );
								}
								?>
							</td>
							<td>
								<?php if ( (bool) $tok['is_active'] ) : ?>
									<span style="color: #46b450; font-weight: bold;">
										<?php esc_html_e( 'Active', 'wc-enterprise' ); ?>
									</span>
								<?php else : ?>
									<span style="color: #dc3232; font-weight: bold;">
										<?php esc_html_e( 'Revoked', 'wc-enterprise' ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( (bool) $tok['is_active'] ) : ?>
									<button type="button" class="button button-small wcet-revoke-token"
										data-token-id="<?php echo esc_attr( $tok['id'] ); ?>">
										<?php esc_html_e( 'Revoke', 'wc-enterprise' ); ?>
									</button>
								<?php else : ?>
									&mdash;
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php else : ?>
					<tr>
						<td colspan="9"><?php esc_html_e( 'No API tokens have been generated yet.', 'wc-enterprise' ); ?></td>
					</tr>
				<?php endif; ?>
				</tbody>
			</table>
		</div>

		<style>
			.wcet-permission-badge {
				display: inline-block;
				padding: 2px 8px;
				border-radius: 3px;
				font-size: 12px;
				font-weight: 600;
				color: #fff;
			}
			.wcet-permission-read { background: #0073aa; }
			.wcet-permission-write { background: #00a32a; }
			.wcet-permission-admin { background: #d63638; }
		</style>

		<script>
		(function($) {
			'use strict';

			$('#wcet-token-form').on('submit', function(e) {
				e.preventDefault();

				var $form = $(this);
				var $button = $form.find('button[type="submit"]');
				$button.prop('disabled', true);

				$.post(ajaxurl, {
					action: 'wcet_generate_token',
					_wcet_token_nonce: $('#_wcet_token_nonce').val(),
					label: $('#token_label').val(),
					user_id: $('#token_user').val(),
					permissions: $('#token_permissions').val(),
					expires_at: $('#token_expires').val()
				}, function(response) {
					$button.prop('disabled', false);
					if (response.success && response.data.token) {
						$('#wcet-new-token').text(response.data.token);
						$('#wcet-token-notice').slideDown();
						// Reload the page after a brief delay to show the new token in the list.
						setTimeout(function() { location.reload(); }, 5000);
					} else {
						alert(response.data || '<?php echo esc_js( __( 'Failed to generate token.', 'wc-enterprise' ) ); ?>');
					}
				}).fail(function() {
					$button.prop('disabled', false);
					alert('<?php echo esc_js( __( 'Request failed. Please try again.', 'wc-enterprise' ) ); ?>');
				});
			});

			$('.wcet-revoke-token').on('click', function() {
				var tokenId = $(this).data('token-id');
				if (!confirm('<?php echo esc_js( __( 'Are you sure you want to revoke this token? This cannot be undone.', 'wc-enterprise' ) ); ?>')) {
					return;
				}

				var $button = $(this);
				$button.prop('disabled', true);

				$.post(ajaxurl, {
					action: 'wcet_revoke_token',
					_wcet_token_nonce: $('#_wcet_token_nonce').val(),
					token_id: tokenId
				}, function(response) {
					if (response.success) {
						location.reload();
					} else {
						$button.prop('disabled', false);
						alert(response.data || '<?php echo esc_js( __( 'Failed to revoke token.', 'wc-enterprise' ) ); ?>');
					}
				}).fail(function() {
					$button.prop('disabled', false);
					alert('<?php echo esc_js( __( 'Request failed. Please try again.', 'wc-enterprise' ) ); ?>');
				});
			});
		})(jQuery);
		</script>
		<?php
	}

	/**
	 * AJAX handler: Generate a new token.
	 *
	 * @return void
	 */
	public function ajax_generate_token() {
		check_ajax_referer( 'wcet_token_management', '_wcet_token_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'You do not have permission to manage API tokens.', 'wc-enterprise' ), 403 );
		}

		$user_id     = absint( $_POST['user_id'] ?? 0 );
		$label       = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		$permissions = sanitize_text_field( wp_unslash( $_POST['permissions'] ?? 'read' ) );
		$expires_at  = sanitize_text_field( wp_unslash( $_POST['expires_at'] ?? '' ) );

		if ( empty( $label ) ) {
			wp_send_json_error( __( 'A label is required.', 'wc-enterprise' ) );
		}

		if ( empty( $user_id ) ) {
			wp_send_json_error( __( 'A user must be selected.', 'wc-enterprise' ) );
		}

		// Convert datetime-local format to MySQL format if provided.
		if ( ! empty( $expires_at ) ) {
			$expires_timestamp = strtotime( $expires_at );
			if ( ! $expires_timestamp || $expires_timestamp <= time() ) {
				wp_send_json_error( __( 'Expiry date must be in the future.', 'wc-enterprise' ) );
			}
			$expires_at = gmdate( 'Y-m-d H:i:s', $expires_timestamp );
		}

		$result = $this->generate_token( $user_id, $label, $permissions, $expires_at );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success( [
			'token' => $result['token'],
			'id'    => $result['id'],
		] );
	}

	/**
	 * AJAX handler: Revoke a token.
	 *
	 * @return void
	 */
	public function ajax_revoke_token() {
		check_ajax_referer( 'wcet_token_management', '_wcet_token_nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( __( 'You do not have permission to manage API tokens.', 'wc-enterprise' ), 403 );
		}

		$token_id = absint( $_POST['token_id'] ?? 0 );

		if ( empty( $token_id ) ) {
			wp_send_json_error( __( 'Invalid token ID.', 'wc-enterprise' ) );
		}

		$revoked = $this->revoke_token( $token_id );

		if ( $revoked ) {
			wp_send_json_success();
		} else {
			wp_send_json_error( __( 'Failed to revoke the token.', 'wc-enterprise' ) );
		}
	}
}

WCET_Token_Auth::instance();
