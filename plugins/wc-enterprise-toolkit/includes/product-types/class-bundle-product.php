<?php
/**
 * Bundle Product Type — allows grouping multiple products into a single purchasable bundle
 * with optional per-item quantity controls and bundle-level discounts.
 */

defined( 'ABSPATH' ) || exit;

class WCET_Bundle_Product extends WC_Product {

    protected $product_type = 'bundle';

    public function get_type(): string {
        return 'bundle';
    }

    /**
     * Get bundled product IDs with quantities.
     * Stored as meta: [ ['product_id' => 123, 'quantity' => 2, 'optional' => false], ... ]
     */
    public function get_bundled_items(): array {
        $items = $this->get_meta( '_bundle_items', true );
        return is_array( $items ) ? $items : [];
    }

    public function set_bundled_items( array $items ): void {
        $this->update_meta_data( '_bundle_items', $items );
    }

    public function get_bundle_discount(): float {
        return (float) $this->get_meta( '_bundle_discount', true );
    }

    /**
     * Calculate the bundle price from component products.
     */
    public function calculate_bundle_price(): float {
        $total    = 0.0;
        $items    = $this->get_bundled_items();
        $discount = $this->get_bundle_discount();

        foreach ( $items as $item ) {
            $product = wc_get_product( $item['product_id'] ?? 0 );
            if ( ! $product ) {
                continue;
            }
            $qty    = max( 1, (int) ( $item['quantity'] ?? 1 ) );
            $total += (float) $product->get_price() * $qty;
        }

        if ( $discount > 0 ) {
            $total -= $total * ( $discount / 100 );
        }

        return round( $total, wc_get_price_decimals() );
    }

    public function is_purchasable(): bool {
        foreach ( $this->get_bundled_items() as $item ) {
            $product = wc_get_product( $item['product_id'] ?? 0 );
            if ( ! $product || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
                return false;
            }
        }
        return true;
    }
}

// Register the product type.
add_filter( 'product_type_selector', function ( array $types ): array {
    $types['bundle'] = __( 'Bundle Product', 'wc-enterprise' );
    return $types;
} );

add_filter( 'woocommerce_product_class', function ( string $class, string $type ): string {
    if ( 'bundle' === $type ) {
        return 'WCET_Bundle_Product';
    }
    return $class;
}, 10, 2 );

// Admin fields for bundle configuration.
add_action( 'woocommerce_product_data_panels', function () {
    global $post;
    $product = wc_get_product( $post->ID );
    if ( ! $product || 'bundle' !== $product->get_type() ) {
        return;
    }

    echo '<div id="bundle_product_data" class="panel woocommerce_options_panel">';
    woocommerce_wp_textarea_input( [
        'id'          => '_bundle_items_json',
        'label'       => __( 'Bundle Items (JSON)', 'wc-enterprise' ),
        'description' => __( 'JSON array: [{"product_id":123,"quantity":2,"optional":false}]', 'wc-enterprise' ),
        'value'       => wp_json_encode( $product->get_bundled_items() ),
    ] );
    woocommerce_wp_text_input( [
        'id'          => '_bundle_discount',
        'label'       => __( 'Bundle Discount (%)', 'wc-enterprise' ),
        'type'        => 'number',
        'value'       => $product->get_bundle_discount(),
        'custom_attributes' => [ 'step' => '0.01', 'min' => '0', 'max' => '100' ],
    ] );
    echo '</div>';
} );

add_action( 'woocommerce_process_product_meta', function ( int $post_id ) {
    $product = wc_get_product( $post_id );
    if ( ! $product || 'bundle' !== $product->get_type() ) {
        return;
    }

    if ( isset( $_POST['_bundle_items_json'] ) ) {
        $items = json_decode( sanitize_textarea_field( wp_unslash( $_POST['_bundle_items_json'] ) ), true );
        if ( is_array( $items ) ) {
            $product->set_bundled_items( $items );
        }
    }
    if ( isset( $_POST['_bundle_discount'] ) ) {
        $product->update_meta_data( '_bundle_discount', floatval( $_POST['_bundle_discount'] ) );
    }
    $product->save();
} );
