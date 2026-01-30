<?php
/**
 * Capability Manager — custom roles & capabilities for WooCommerce Enterprise Toolkit.
 *
 * Defines plugin-specific capabilities, creates custom roles on activation,
 * exposes helpers for capability checking, conditionally shows/hides admin
 * menus, and provides an admin UI for reviewing and modifying role assignments.
 *
 * @package WC_Enterprise_Toolkit
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class WCET_Capability_Manager
 */
final class WCET_Capability_Manager {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	private const ADMIN_SLUG = 'wcet-capabilities';

	/**
	 * Nonce action for the admin form.
	 *
	 * @var string
	 */
	private const NONCE_ACTION = 'wcet_capability_manager';

	/**
	 * Option key that controls whether roles/caps are preserved on deactivation.
	 *
	 * @var string
	 */
	private const PRESERVE_OPTION = 'wcet_preserve_roles_on_deactivation';

	/**
	 * Custom capabilities defined by this plugin.
	 *
	 * @var string[]
	 */
	private const CUSTOM_CAPABILITIES = array(
		'wcet_manage_pricing',
		'wcet_manage_orders',
		'wcet_view_analytics',
		'wcet_manage_search',
		'wcet_manage_api',
		'wcet_manage_security',
	);

	/**
	 * Custom roles defined by this plugin.
	 *
	 * Each key is the role slug; each value contains the display name
	 * and the list of capabilities to assign.
	 *
	 * @var array<string,array{name:string,capabilities:string[]}>
	 */
	private const CUSTOM_ROLES = array(
		'store_manager' => array(
			'name'         => 'Store Manager',
			'capabilities' => array(
				// WooCommerce core caps are added programmatically — see get_role_caps().
				'wcet_manage_pricing',
				'wcet_manage_orders',
				'wcet_view_analytics',
			),
		),
		'store_analyst' => array(
			'name'         => 'Store Analyst',
			'capabilities' => array(
				'read',
				'wcet_view_analytics',
			),
		),
		'api_user'      => array(
			'name'         => 'API User',
			'capabilities' => array(
				'read',
				'wcet_manage_api',
			),
		),
	);

	/**
	 * Return the singleton instance.
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — register hooks.
	 */
	private function __construct() {
		// Activation / deactivation.
		register_activation_hook(
			WCET_PLUGIN_DIR . 'wc-enterprise-toolkit.php',
			array( $this, 'on_activation' )
		);
		register_deactivation_hook(
			WCET_PLUGIN_DIR . 'wc-enterprise-toolkit.php',
			array( $this, 'on_deactivation' )
		);

		// Admin menu filtering based on capabilities.
		add_action( 'admin_menu', array( $this, 'filter_admin_menu' ), 999 );

		// Admin UI page.
		if ( is_admin() ) {
			add_action( 'admin_menu', array( $this, 'register_admin_page' ), 99 );
			add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
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
	// Activation / Deactivation
	// =========================================================================

	/**
	 * Create custom roles and add capabilities on plugin activation.
	 *
	 * @return void
	 */
	public function on_activation(): void {
		$this->create_custom_roles();
		$this->add_caps_to_admin();

		// Default: preserve roles on deactivation.
		if ( false === get_option( self::PRESERVE_OPTION ) ) {
			update_option( self::PRESERVE_OPTION, '1' );
		}
	}

	/**
	 * Optionally remove custom roles and capabilities on deactivation.
	 *
	 * @return void
	 */
	public function on_deactivation(): void {
		$preserve = get_option( self::PRESERVE_OPTION, '1' );

		if ( '1' === $preserve ) {
			return;
		}

		$this->remove_custom_roles();
		$this->remove_custom_caps_from_all_roles();
	}

	// =========================================================================
	// Role & Capability CRUD
	// =========================================================================

	/**
	 * Create all custom roles.
	 *
	 * @return void
	 */
	private function create_custom_roles(): void {
		foreach ( self::CUSTOM_ROLES as $slug => $definition ) {
			$caps = $this->get_role_caps( $slug, $definition['capabilities'] );

			// Remove role first so changes are re-applied cleanly.
			remove_role( $slug );
			add_role( $slug, $definition['name'], $caps );
		}
	}

	/**
	 * Build the full capability map for a custom role.
	 *
	 * For `store_manager` this includes every WooCommerce capability so the
	 * role can operate the store without needing the built-in `shop_manager`.
	 *
	 * @param string   $slug          Role slug.
	 * @param string[] $extra_caps    Plugin-specific capabilities.
	 * @return array<string,bool>
	 */
	private function get_role_caps( string $slug, array $extra_caps ): array {
		$caps = array();

		if ( 'store_manager' === $slug ) {
			// Mirror WooCommerce shop_manager capabilities.
			$wc_caps = $this->get_woocommerce_capabilities();
			foreach ( $wc_caps as $cap ) {
				$caps[ $cap ] = true;
			}
		}

		foreach ( $extra_caps as $cap ) {
			$caps[ $cap ] = true;
		}

		return $caps;
	}

	/**
	 * Return an array of all WooCommerce capabilities.
	 *
	 * @return string[]
	 */
	private function get_woocommerce_capabilities(): array {
		$capabilities = array(
			// General.
			'read',
			'manage_woocommerce',
			'view_woocommerce_reports',

			// Products.
			'edit_products',
			'edit_others_products',
			'edit_published_products',
			'edit_private_products',
			'delete_products',
			'delete_others_products',
			'delete_published_products',
			'delete_private_products',
			'publish_products',
			'read_private_products',

			// Orders (CRUD).
			'edit_shop_orders',
			'edit_others_shop_orders',
			'edit_published_shop_orders',
			'edit_private_shop_orders',
			'delete_shop_orders',
			'delete_others_shop_orders',
			'delete_published_shop_orders',
			'delete_private_shop_orders',
			'publish_shop_orders',
			'read_private_shop_orders',

			// Coupons.
			'edit_shop_coupons',
			'edit_others_shop_coupons',
			'edit_published_shop_coupons',
			'edit_private_shop_coupons',
			'delete_shop_coupons',
			'delete_others_shop_coupons',
			'delete_published_shop_coupons',
			'delete_private_shop_coupons',
			'publish_shop_coupons',
			'read_private_shop_coupons',

			// Customers.
			'list_users',
			'edit_users',

			// Media.
			'upload_files',
		);

		/**
		 * Filter the WooCommerce capabilities assigned to the store_manager role.
		 *
		 * @since 1.0.0
		 *
		 * @param string[] $capabilities WooCommerce capabilities.
		 */
		return apply_filters( 'wcet_woocommerce_capabilities', $capabilities );
	}

	/**
	 * Grant every custom capability to the administrator role.
	 *
	 * @return void
	 */
	private function add_caps_to_admin(): void {
		$admin = get_role( 'administrator' );
		if ( ! $admin ) {
			return;
		}

		foreach ( self::CUSTOM_CAPABILITIES as $cap ) {
			$admin->add_cap( $cap );
		}
	}

	/**
	 * Remove all custom roles.
	 *
	 * @return void
	 */
	private function remove_custom_roles(): void {
		foreach ( array_keys( self::CUSTOM_ROLES ) as $slug ) {
			remove_role( $slug );
		}
	}

	/**
	 * Remove custom capabilities from every role that has them.
	 *
	 * @return void
	 */
	private function remove_custom_caps_from_all_roles(): void {
		global $wp_roles;

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = wp_roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		foreach ( $wp_roles->role_objects as $role ) {
			foreach ( self::CUSTOM_CAPABILITIES as $cap ) {
				$role->remove_cap( $cap );
			}
		}
	}

	// =========================================================================
	// Public helpers — capability checking
	// =========================================================================

	/**
	 * Add capabilities to an existing WordPress or WooCommerce role.
	 *
	 * @param string   $role_slug    Role slug (e.g. 'editor', 'shop_manager').
	 * @param string[] $capabilities Capabilities to add.
	 * @return bool True if role exists and caps were added, false otherwise.
	 */
	public function add_caps_to_role( string $role_slug, array $capabilities ): bool {
		$role = get_role( $role_slug );
		if ( ! $role ) {
			return false;
		}

		foreach ( $capabilities as $cap ) {
			if ( ! in_array( $cap, self::CUSTOM_CAPABILITIES, true ) ) {
				continue; // Only allow adding plugin-defined capabilities.
			}
			$role->add_cap( $cap );
		}

		return true;
	}

	/**
	 * Remove capabilities from an existing role.
	 *
	 * @param string   $role_slug    Role slug.
	 * @param string[] $capabilities Capabilities to remove.
	 * @return bool True if role exists, false otherwise.
	 */
	public function remove_caps_from_role( string $role_slug, array $capabilities ): bool {
		$role = get_role( $role_slug );
		if ( ! $role ) {
			return false;
		}

		foreach ( $capabilities as $cap ) {
			$role->remove_cap( $cap );
		}

		return true;
	}

	/**
	 * Check whether the current user (or a specific user) has a plugin capability.
	 *
	 * This is a convenience wrapper around `current_user_can()` / `user_can()`
	 * that also fires a filter so other modules can override access.
	 *
	 * @param string   $capability  Capability to check.
	 * @param int|null $user_id     Optional user ID. Defaults to current user.
	 * @return bool
	 */
	public static function user_has_capability( string $capability, ?int $user_id = null ): bool {
		if ( ! in_array( $capability, self::CUSTOM_CAPABILITIES, true ) ) {
			return false;
		}

		$has_cap = null === $user_id
			? current_user_can( $capability )
			: user_can( $user_id, $capability );

		/**
		 * Filter whether a user has a specific WCET capability.
		 *
		 * @since 1.0.0
		 *
		 * @param bool     $has_cap    Whether the user has the capability.
		 * @param string   $capability The capability being checked.
		 * @param int|null $user_id    The user ID (null = current user).
		 */
		return (bool) apply_filters( 'wcet_user_has_capability', $has_cap, $capability, $user_id );
	}

	/**
	 * Return the list of custom capabilities defined by this plugin.
	 *
	 * @return string[]
	 */
	public static function get_custom_capabilities(): array {
		return self::CUSTOM_CAPABILITIES;
	}

	/**
	 * Return the custom role definitions.
	 *
	 * @return array<string,array{name:string,capabilities:string[]}>
	 */
	public static function get_custom_role_definitions(): array {
		return self::CUSTOM_ROLES;
	}

	// =========================================================================
	// Admin menu filtering
	// =========================================================================

	/**
	 * Conditionally show / hide plugin sub-menu items based on capabilities.
	 *
	 * Runs at priority 999 so all menu items have been registered.
	 *
	 * @return void
	 */
	public function filter_admin_menu(): void {
		// Map plugin page slugs to required capabilities.
		$menu_capability_map = array(
			'wcet-pricing'      => 'wcet_manage_pricing',
			'wcet-orders'       => 'wcet_manage_orders',
			'wcet-analytics'    => 'wcet_view_analytics',
			'wcet-search'       => 'wcet_manage_search',
			'wcet-api'          => 'wcet_manage_api',
			'wcet-security'     => 'wcet_manage_security',
			'wcet-capabilities' => 'manage_options', // Only full admins.
		);

		/**
		 * Filter the menu-slug-to-capability map used to control visibility.
		 *
		 * @since 1.0.0
		 *
		 * @param array<string,string> $menu_capability_map Slug => capability.
		 */
		$menu_capability_map = apply_filters( 'wcet_menu_capability_map', $menu_capability_map );

		foreach ( $menu_capability_map as $slug => $capability ) {
			if ( ! current_user_can( $capability ) ) {
				remove_submenu_page( 'woocommerce', $slug );
			}
		}
	}

	// =========================================================================
	// Admin UI page
	// =========================================================================

	/**
	 * Register the Capabilities admin page under WooCommerce.
	 *
	 * @return void
	 */
	public function register_admin_page(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Roles & Capabilities', 'wc-enterprise' ),
			__( 'Capabilities', 'wc-enterprise' ),
			'manage_options',
			self::ADMIN_SLUG,
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Register the preservation setting.
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'wcet_capabilities_group',
			self::PRESERVE_OPTION,
			array(
				'type'              => 'string',
				'sanitize_callback' => function ( $value ) {
					return in_array( $value, array( '0', '1' ), true ) ? $value : '1';
				},
				'default'           => '1',
			)
		);
	}

	/**
	 * Enqueue admin assets for the capabilities page.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'woocommerce_page_' . self::ADMIN_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'wcet-capabilities-admin',
			WCET_PLUGIN_URL . 'assets/css/capabilities-admin.css',
			array(),
			WCET_VERSION
		);
	}

	/**
	 * Render the Roles & Capabilities admin page.
	 *
	 * @return void
	 */
	public function render_admin_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wc-enterprise' ) );
		}

		// Process form submission.
		$this->handle_admin_form_submission();

		$all_roles     = $this->get_all_roles_with_custom_caps();
		$preserve      = get_option( self::PRESERVE_OPTION, '1' );

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Roles & Capabilities', 'wc-enterprise' ); ?></h1>

			<?php settings_errors( self::ADMIN_SLUG ); ?>

			<!-- Deactivation behaviour -->
			<h2><?php esc_html_e( 'Deactivation Settings', 'wc-enterprise' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'wcet_capabilities_group' ); ?>
				<table class="form-table">
					<tr>
						<th scope="row">
							<?php esc_html_e( 'Preserve roles on deactivation', 'wc-enterprise' ); ?>
						</th>
						<td>
							<label>
								<input type="radio" name="<?php echo esc_attr( self::PRESERVE_OPTION ); ?>" value="1" <?php checked( $preserve, '1' ); ?> />
								<?php esc_html_e( 'Yes — keep custom roles and capabilities when the plugin is deactivated.', 'wc-enterprise' ); ?>
							</label>
							<br />
							<label>
								<input type="radio" name="<?php echo esc_attr( self::PRESERVE_OPTION ); ?>" value="0" <?php checked( $preserve, '0' ); ?> />
								<?php esc_html_e( 'No — remove custom roles and capabilities on deactivation.', 'wc-enterprise' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Setting', 'wc-enterprise' ) ); ?>
			</form>

			<!-- Custom roles overview -->
			<h2><?php esc_html_e( 'Custom Roles', 'wc-enterprise' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'The following roles are defined by WC Enterprise Toolkit.', 'wc-enterprise' ); ?>
			</p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Role', 'wc-enterprise' ); ?></th>
						<th><?php esc_html_e( 'Slug', 'wc-enterprise' ); ?></th>
						<th><?php esc_html_e( 'Custom Capabilities', 'wc-enterprise' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wc-enterprise' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( self::CUSTOM_ROLES as $slug => $definition ) : ?>
						<?php $role_exists = (bool) get_role( $slug ); ?>
						<tr>
							<td><strong><?php echo esc_html( $definition['name'] ); ?></strong></td>
							<td><code><?php echo esc_html( $slug ); ?></code></td>
							<td>
								<?php
								$custom_only = array_intersect( $definition['capabilities'], self::CUSTOM_CAPABILITIES );
								echo esc_html( implode( ', ', $custom_only ) ?: '—' );
								?>
							</td>
							<td>
								<?php if ( $role_exists ) : ?>
									<span style="color:#46b450;">&#10003; <?php esc_html_e( 'Active', 'wc-enterprise' ); ?></span>
								<?php else : ?>
									<span style="color:#dc3232;">&#10007; <?php esc_html_e( 'Missing', 'wc-enterprise' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<!-- Re-create roles button -->
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, '_wcet_cap_nonce' ); ?>
				<p>
					<button type="submit" name="wcet_recreate_roles" value="1" class="button">
						<?php esc_html_e( 'Recreate Custom Roles', 'wc-enterprise' ); ?>
					</button>
					<span class="description">
						<?php esc_html_e( 'Removes and re-adds custom roles with the default capability set.', 'wc-enterprise' ); ?>
					</span>
				</p>
			</form>

			<!-- Manage capabilities per role -->
			<h2><?php esc_html_e( 'Manage Capabilities', 'wc-enterprise' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Assign or remove custom plugin capabilities for any role.', 'wc-enterprise' ); ?>
			</p>

			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, '_wcet_cap_nonce' ); ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Role', 'wc-enterprise' ); ?></th>
							<?php foreach ( self::CUSTOM_CAPABILITIES as $cap ) : ?>
								<th style="text-align:center;">
									<code style="font-size:11px;"><?php echo esc_html( str_replace( 'wcet_', '', $cap ) ); ?></code>
								</th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $all_roles as $role_slug => $role_data ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $role_data['name'] ); ?></strong>
									<br /><code style="font-size:11px;"><?php echo esc_html( $role_slug ); ?></code>
								</td>
								<?php foreach ( self::CUSTOM_CAPABILITIES as $cap ) : ?>
									<td style="text-align:center;">
										<input
											type="checkbox"
											name="wcet_caps[<?php echo esc_attr( $role_slug ); ?>][<?php echo esc_attr( $cap ); ?>]"
											value="1"
											<?php checked( ! empty( $role_data['capabilities'][ $cap ] ) ); ?>
											<?php disabled( 'administrator' === $role_slug ); ?>
										/>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php esc_html_e( 'Administrator capabilities cannot be modified from this screen.', 'wc-enterprise' ); ?>
				</p>
				<p>
					<button type="submit" name="wcet_update_caps" value="1" class="button button-primary">
						<?php esc_html_e( 'Update Capabilities', 'wc-enterprise' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Handle form submissions on the admin page.
	 *
	 * @return void
	 */
	private function handle_admin_form_submission(): void {
		// Recreate roles.
		if (
			isset( $_POST['wcet_recreate_roles'] ) &&
			check_admin_referer( self::NONCE_ACTION, '_wcet_cap_nonce' )
		) {
			$this->create_custom_roles();
			$this->add_caps_to_admin();

			add_settings_error(
				self::ADMIN_SLUG,
				'roles_recreated',
				__( 'Custom roles have been recreated successfully.', 'wc-enterprise' ),
				'updated'
			);
			return;
		}

		// Update capabilities.
		if (
			isset( $_POST['wcet_update_caps'] ) &&
			check_admin_referer( self::NONCE_ACTION, '_wcet_cap_nonce' )
		) {
			$submitted = isset( $_POST['wcet_caps'] ) && is_array( $_POST['wcet_caps'] )
				? map_deep( wp_unslash( $_POST['wcet_caps'] ), 'sanitize_text_field' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				: array();

			$all_roles = $this->get_all_roles_with_custom_caps();

			foreach ( $all_roles as $role_slug => $role_data ) {
				// Skip administrator — always has all caps.
				if ( 'administrator' === $role_slug ) {
					continue;
				}

				$role = get_role( $role_slug );
				if ( ! $role ) {
					continue;
				}

				foreach ( self::CUSTOM_CAPABILITIES as $cap ) {
					$should_have = ! empty( $submitted[ $role_slug ][ $cap ] );

					if ( $should_have ) {
						$role->add_cap( $cap );
					} else {
						$role->remove_cap( $cap );
					}
				}
			}

			add_settings_error(
				self::ADMIN_SLUG,
				'caps_updated',
				__( 'Capabilities have been updated successfully.', 'wc-enterprise' ),
				'updated'
			);
		}
	}

	/**
	 * Retrieve all WordPress roles with their custom-capability state.
	 *
	 * @return array<string,array{name:string,capabilities:array<string,bool>}>
	 */
	private function get_all_roles_with_custom_caps(): array {
		global $wp_roles;

		if ( ! isset( $wp_roles ) ) {
			$wp_roles = wp_roles(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		$result = array();
		foreach ( $wp_roles->roles as $slug => $role_data ) {
			$result[ $slug ] = array(
				'name'         => translate_user_role( $role_data['name'] ),
				'capabilities' => $role_data['capabilities'],
			);
		}

		return $result;
	}
}

// Initialise the singleton.
WCET_Capability_Manager::instance();
