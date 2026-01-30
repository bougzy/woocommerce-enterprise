<?php
/**
 * Advanced Pricing Rules Engine
 *
 * Supports: percentage discounts, fixed discounts, BOGO, tiered pricing,
 * and role-based pricing with date ranges and stacking priority.
 */

defined( 'ABSPATH' ) || exit;

class WCET_Pricing_Engine {

    private static ?self $instance = null;
    private array $rules_cache = [];

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_filter( 'woocommerce_product_get_price', [ $this, 'apply_rules_to_price' ], 20, 2 );
        add_filter( 'woocommerce_product_get_sale_price', [ $this, 'apply_rules_to_price' ], 20, 2 );
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'apply_cart_rules' ], 20 );
        add_action( 'admin_menu', [ $this, 'register_admin_page' ] );
        add_action( 'wp_ajax_wcet_save_pricing_rule', [ $this, 'ajax_save_rule' ] );
        add_action( 'wp_ajax_wcet_delete_pricing_rule', [ $this, 'ajax_delete_rule' ] );
    }

    /**
     * Get all active pricing rules, ordered by priority.
     */
    public function get_active_rules(): array {
        if ( ! empty( $this->rules_cache ) ) {
            return $this->rules_cache;
        }

        $cache_key = 'wcet_active_pricing_rules';
        $rules     = wp_cache_get( $cache_key, 'wcet' );

        if ( false === $rules ) {
            global $wpdb;
            $now   = current_time( 'mysql' );
            $rules = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}wcet_pricing_rules
                 WHERE is_active = 1
                   AND (starts_at IS NULL OR starts_at <= %s)
                   AND (ends_at IS NULL OR ends_at >= %s)
                 ORDER BY priority ASC",
                $now,
                $now
            ), ARRAY_A );

            if ( ! is_array( $rules ) ) {
                $rules = [];
            }

            // Decode conditions JSON.
            foreach ( $rules as &$rule ) {
                $rule['conditions'] = json_decode( $rule['conditions'], true ) ?: [];
            }

            wp_cache_set( $cache_key, $rules, 'wcet', 300 );
        }

        $this->rules_cache = $rules;
        return $rules;
    }

    /**
     * Apply pricing rules to a single product price.
     */
    public function apply_rules_to_price( $price, WC_Product $product ) {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return $price;
        }

        $original_price = (float) $price;
        if ( $original_price <= 0 ) {
            return $price;
        }

        $rules = $this->get_active_rules();

        foreach ( $rules as $rule ) {
            if ( ! $this->rule_matches_product( $rule, $product ) ) {
                continue;
            }

            $original_price = $this->calculate_discount( $original_price, $rule );

            // Only apply first matching rule (highest priority).
            if ( empty( $rule['conditions']['stackable'] ) ) {
                break;
            }
        }

        return max( 0, round( $original_price, wc_get_price_decimals() ) );
    }

    /**
     * Apply cart-level rules (BOGO, tiered, etc.).
     */
    public function apply_cart_rules( WC_Cart $cart ): void {
        if ( is_admin() && ! wp_doing_ajax() ) {
            return;
        }

        $rules = $this->get_active_rules();

        foreach ( $cart->get_cart() as $cart_key => $cart_item ) {
            $product        = $cart_item['data'];
            $base_price     = (float) $product->get_regular_price();
            $quantity       = $cart_item['quantity'];
            $adjusted_price = $base_price;

            foreach ( $rules as $rule ) {
                if ( ! $this->rule_matches_product( $rule, $product ) ) {
                    continue;
                }

                switch ( $rule['type'] ) {
                    case 'tiered':
                        $adjusted_price = $this->apply_tiered_pricing( $base_price, $quantity, $rule );
                        break;

                    case 'bogo':
                        $adjusted_price = $this->apply_bogo( $base_price, $quantity, $rule );
                        break;

                    default:
                        $adjusted_price = $this->calculate_discount( $base_price, $rule );
                        break;
                }

                if ( empty( $rule['conditions']['stackable'] ) ) {
                    break;
                }
            }

            $product->set_price( max( 0, round( $adjusted_price, wc_get_price_decimals() ) ) );
        }
    }

    /**
     * Check if a rule applies to a given product.
     */
    private function rule_matches_product( array $rule, WC_Product $product ): bool {
        $conditions = $rule['conditions'];

        // Product ID filter.
        if ( ! empty( $conditions['product_ids'] ) ) {
            if ( ! in_array( $product->get_id(), (array) $conditions['product_ids'], true ) ) {
                return false;
            }
        }

        // Category filter.
        if ( ! empty( $conditions['category_ids'] ) ) {
            $product_cats = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'ids' ] );
            if ( ! array_intersect( (array) $conditions['category_ids'], $product_cats ) ) {
                return false;
            }
        }

        // Role-based filter.
        if ( ! empty( $conditions['user_roles'] ) && 'role_based' === $rule['type'] ) {
            if ( ! is_user_logged_in() ) {
                return false;
            }
            $user  = wp_get_current_user();
            $roles = (array) $conditions['user_roles'];
            if ( ! array_intersect( $roles, $user->roles ) ) {
                return false;
            }
        }

        // Minimum quantity.
        if ( ! empty( $conditions['min_quantity'] ) ) {
            $cart_qty = $this->get_cart_quantity_for_product( $product->get_id() );
            if ( $cart_qty < (int) $conditions['min_quantity'] ) {
                return false;
            }
        }

        return true;
    }

    private function calculate_discount( float $price, array $rule ): float {
        $value = (float) $rule['discount_value'];

        return match ( $rule['type'] ) {
            'percentage', 'role_based' => $price - ( $price * ( $value / 100 ) ),
            'fixed'                    => $price - $value,
            default                    => $price,
        };
    }

    private function apply_tiered_pricing( float $base_price, int $quantity, array $rule ): float {
        $tiers = $rule['conditions']['tiers'] ?? [];
        usort( $tiers, fn( $a, $b ) => ( $b['min_qty'] ?? 0 ) <=> ( $a['min_qty'] ?? 0 ) );

        foreach ( $tiers as $tier ) {
            if ( $quantity >= ( $tier['min_qty'] ?? 0 ) ) {
                $discount = (float) ( $tier['discount'] ?? 0 );
                return $base_price - ( $base_price * ( $discount / 100 ) );
            }
        }

        return $base_price;
    }

    private function apply_bogo( float $base_price, int $quantity, array $rule ): float {
        $buy  = max( 1, (int) ( $rule['conditions']['buy_qty'] ?? 1 ) );
        $free = max( 1, (int) ( $rule['conditions']['free_qty'] ?? 1 ) );
        $sets = intdiv( $quantity, $buy + $free );
        $rem  = $quantity - $sets * ( $buy + $free );

        $paid_items = $sets * $buy + min( $rem, $buy );
        return ( $paid_items / $quantity ) * $base_price;
    }

    private function get_cart_quantity_for_product( int $product_id ): int {
        if ( ! WC()->cart ) {
            return 0;
        }
        $qty = 0;
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( (int) $item['product_id'] === $product_id ) {
                $qty += $item['quantity'];
            }
        }
        return $qty;
    }

    // --- Admin UI ---

    public function register_admin_page(): void {
        add_submenu_page(
            'woocommerce',
            __( 'Pricing Rules', 'wc-enterprise' ),
            __( 'Pricing Rules', 'wc-enterprise' ),
            'manage_woocommerce',
            'wcet-pricing-rules',
            [ $this, 'render_admin_page' ]
        );
    }

    public function render_admin_page(): void {
        global $wpdb;
        $rules = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}wcet_pricing_rules ORDER BY priority ASC",
            ARRAY_A
        );
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Pricing Rules Engine', 'wc-enterprise' ); ?></h1>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'wc-enterprise' ); ?></th>
                        <th><?php esc_html_e( 'Type', 'wc-enterprise' ); ?></th>
                        <th><?php esc_html_e( 'Discount', 'wc-enterprise' ); ?></th>
                        <th><?php esc_html_e( 'Priority', 'wc-enterprise' ); ?></th>
                        <th><?php esc_html_e( 'Active', 'wc-enterprise' ); ?></th>
                        <th><?php esc_html_e( 'Date Range', 'wc-enterprise' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if ( $rules ) : foreach ( $rules as $rule ) : ?>
                    <tr>
                        <td><?php echo esc_html( $rule['name'] ); ?></td>
                        <td><?php echo esc_html( $rule['type'] ); ?></td>
                        <td><?php echo esc_html( $rule['discount_value'] ); ?></td>
                        <td><?php echo esc_html( $rule['priority'] ); ?></td>
                        <td><?php echo $rule['is_active'] ? '&#10003;' : '&#10007;'; ?></td>
                        <td><?php echo esc_html( ( $rule['starts_at'] ?? '—' ) . ' → ' . ( $rule['ends_at'] ?? '—' ) ); ?></td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="6"><?php esc_html_e( 'No rules defined.', 'wc-enterprise' ); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <h2><?php esc_html_e( 'Add New Rule', 'wc-enterprise' ); ?></h2>
            <form id="wcet-pricing-form" method="post">
                <?php wp_nonce_field( 'wcet_pricing_nonce', '_wcet_nonce' ); ?>
                <table class="form-table">
                    <tr><th><label for="rule_name"><?php esc_html_e( 'Name', 'wc-enterprise' ); ?></label></th>
                        <td><input type="text" id="rule_name" name="name" class="regular-text" required></td></tr>
                    <tr><th><label for="rule_type"><?php esc_html_e( 'Type', 'wc-enterprise' ); ?></label></th>
                        <td><select id="rule_type" name="type">
                            <option value="percentage">Percentage</option>
                            <option value="fixed">Fixed</option>
                            <option value="bogo">BOGO</option>
                            <option value="tiered">Tiered</option>
                            <option value="role_based">Role Based</option>
                        </select></td></tr>
                    <tr><th><label for="rule_value"><?php esc_html_e( 'Discount Value', 'wc-enterprise' ); ?></label></th>
                        <td><input type="number" id="rule_value" name="discount_value" step="0.01" required></td></tr>
                    <tr><th><label for="rule_conditions"><?php esc_html_e( 'Conditions (JSON)', 'wc-enterprise' ); ?></label></th>
                        <td><textarea id="rule_conditions" name="conditions" rows="4" class="large-text">{}</textarea></td></tr>
                    <tr><th><label for="rule_priority"><?php esc_html_e( 'Priority', 'wc-enterprise' ); ?></label></th>
                        <td><input type="number" id="rule_priority" name="priority" value="10"></td></tr>
                    <tr><th><label for="rule_starts"><?php esc_html_e( 'Start Date', 'wc-enterprise' ); ?></label></th>
                        <td><input type="datetime-local" id="rule_starts" name="starts_at"></td></tr>
                    <tr><th><label for="rule_ends"><?php esc_html_e( 'End Date', 'wc-enterprise' ); ?></label></th>
                        <td><input type="datetime-local" id="rule_ends" name="ends_at"></td></tr>
                </table>
                <p class="submit"><button type="submit" class="button button-primary"><?php esc_html_e( 'Save Rule', 'wc-enterprise' ); ?></button></p>
            </form>
        </div>
        <script>
        jQuery('#wcet-pricing-form').on('submit', function(e) {
            e.preventDefault();
            jQuery.post(ajaxurl, {
                action: 'wcet_save_pricing_rule',
                _wcet_nonce: jQuery('#_wcet_nonce').val(),
                name: jQuery('#rule_name').val(),
                type: jQuery('#rule_type').val(),
                discount_value: jQuery('#rule_value').val(),
                conditions: jQuery('#rule_conditions').val(),
                priority: jQuery('#rule_priority').val(),
                starts_at: jQuery('#rule_starts').val(),
                ends_at: jQuery('#rule_ends').val()
            }, function() { location.reload(); });
        });
        </script>
        <?php
    }

    public function ajax_save_rule(): void {
        check_ajax_referer( 'wcet_pricing_nonce', '_wcet_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'wcet_pricing_rules', [
            'name'           => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
            'type'           => sanitize_text_field( wp_unslash( $_POST['type'] ?? 'percentage' ) ),
            'discount_value' => floatval( $_POST['discount_value'] ?? 0 ),
            'conditions'     => sanitize_textarea_field( wp_unslash( $_POST['conditions'] ?? '{}' ) ),
            'priority'       => intval( $_POST['priority'] ?? 10 ),
            'starts_at'      => sanitize_text_field( wp_unslash( $_POST['starts_at'] ?? '' ) ) ?: null,
            'ends_at'        => sanitize_text_field( wp_unslash( $_POST['ends_at'] ?? '' ) ) ?: null,
        ] );

        wp_cache_delete( 'wcet_active_pricing_rules', 'wcet' );
        wp_send_json_success();
    }

    public function ajax_delete_rule(): void {
        check_ajax_referer( 'wcet_pricing_nonce', '_wcet_nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized', 403 );
        }

        global $wpdb;
        $wpdb->delete(
            $wpdb->prefix . 'wcet_pricing_rules',
            [ 'id' => absint( $_POST['rule_id'] ?? 0 ) ],
            [ '%d' ]
        );

        wp_cache_delete( 'wcet_active_pricing_rules', 'wcet' );
        wp_send_json_success();
    }
}

WCET_Pricing_Engine::instance();
