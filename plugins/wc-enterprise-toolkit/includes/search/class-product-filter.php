<?php
/**
 * Advanced Product Filtering
 *
 * AJAX-powered product filtering by attributes, price range, rating,
 * stock status, and custom taxonomies. Outputs optimized queries.
 */

defined( 'ABSPATH' ) || exit;

class WCET_Product_Filter {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_ajax_wcet_filter_products', [ $this, 'handle_filter_request' ] );
        add_action( 'wp_ajax_nopriv_wcet_filter_products', [ $this, 'handle_filter_request' ] );
        add_action( 'woocommerce_before_shop_loop', [ $this, 'render_filter_sidebar' ], 15 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_shortcode( 'wcet_product_filters', [ $this, 'shortcode_output' ] );
    }

    /**
     * Handle AJAX filter requests.
     */
    public function handle_filter_request() {
        check_ajax_referer( 'wcet-filter', 'nonce' );

        $filters = $this->sanitize_filters( $_POST['filters'] ?? [] );
        $page    = max( 1, (int) ( $_POST['page'] ?? 1 ) );
        $per_page = min( 48, max( 12, (int) ( $_POST['per_page'] ?? 24 ) ) );

        $results = $this->query_products( $filters, $page, $per_page );

        wp_send_json_success( $results );
    }

    /**
     * Query products with the applied filters.
     */
    public function query_products( array $filters, int $page = 1, int $per_page = 24 ) {
        $args = [
            'post_type'      => 'product',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'meta_query'     => [ 'relation' => 'AND' ],
            'tax_query'      => [ 'relation' => 'AND' ],
        ];

        // Price range.
        if ( ! empty( $filters['price_min'] ) || ! empty( $filters['price_max'] ) ) {
            $price_meta = [ 'key' => '_price', 'type' => 'DECIMAL(10,2)' ];
            if ( ! empty( $filters['price_min'] ) ) {
                $price_meta['value']   = (float) $filters['price_min'];
                $price_meta['compare'] = '>=';
            }
            if ( ! empty( $filters['price_max'] ) ) {
                $args['meta_query'][] = [
                    'key'     => '_price',
                    'value'   => (float) $filters['price_max'],
                    'compare' => '<=',
                    'type'    => 'DECIMAL(10,2)',
                ];
            }
            if ( ! empty( $filters['price_min'] ) ) {
                $args['meta_query'][] = $price_meta;
            }
        }

        // Categories.
        if ( ! empty( $filters['categories'] ) ) {
            $args['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => array_map( 'intval', (array) $filters['categories'] ),
            ];
        }

        // Tags.
        if ( ! empty( $filters['tags'] ) ) {
            $args['tax_query'][] = [
                'taxonomy' => 'product_tag',
                'field'    => 'term_id',
                'terms'    => array_map( 'intval', (array) $filters['tags'] ),
            ];
        }

        // Attributes (e.g., color, size).
        if ( ! empty( $filters['attributes'] ) ) {
            foreach ( $filters['attributes'] as $taxonomy => $terms ) {
                $args['tax_query'][] = [
                    'taxonomy' => sanitize_text_field( $taxonomy ),
                    'field'    => 'slug',
                    'terms'    => array_map( 'sanitize_title', (array) $terms ),
                ];
            }
        }

        // Stock status.
        if ( ! empty( $filters['stock_status'] ) ) {
            $args['meta_query'][] = [
                'key'   => '_stock_status',
                'value' => sanitize_text_field( $filters['stock_status'] ),
            ];
        }

        // Rating.
        if ( ! empty( $filters['min_rating'] ) ) {
            $args['meta_query'][] = [
                'key'     => '_wc_average_rating',
                'value'   => (float) $filters['min_rating'],
                'compare' => '>=',
                'type'    => 'DECIMAL(3,2)',
            ];
        }

        // On sale.
        if ( ! empty( $filters['on_sale'] ) ) {
            $sale_ids = wc_get_product_ids_on_sale();
            if ( ! empty( $sale_ids ) ) {
                $args['post__in'] = $sale_ids;
            }
        }

        // Sorting.
        $sort = $filters['sort'] ?? 'default';
        switch ( $sort ) {
            case 'price_asc':
                $args['orderby']  = 'meta_value_num';
                $args['meta_key'] = '_price';
                $args['order']    = 'ASC';
                break;
            case 'price_desc':
                $args['orderby']  = 'meta_value_num';
                $args['meta_key'] = '_price';
                $args['order']    = 'DESC';
                break;
            case 'rating':
                $args['orderby']  = 'meta_value_num';
                $args['meta_key'] = '_wc_average_rating';
                $args['order']    = 'DESC';
                break;
            case 'newest':
                $args['orderby'] = 'date';
                $args['order']   = 'DESC';
                break;
            case 'popularity':
                $args['orderby']  = 'meta_value_num';
                $args['meta_key'] = 'total_sales';
                $args['order']    = 'DESC';
                break;
            default:
                $args['orderby'] = 'menu_order title';
                $args['order']   = 'ASC';
        }

        $query = new WP_Query( $args );
        $products = [];

        foreach ( $query->posts as $product_id ) {
            $product = wc_get_product( $product_id );
            if ( ! $product ) {
                continue;
            }
            $products[] = [
                'id'        => $product->get_id(),
                'name'      => $product->get_name(),
                'price'     => $product->get_price(),
                'price_html' => $product->get_price_html(),
                'image'     => wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_thumbnail' ),
                'permalink' => $product->get_permalink(),
                'rating'    => $product->get_average_rating(),
                'on_sale'   => $product->is_on_sale(),
            ];
        }

        return [
            'products'    => $products,
            'total'       => $query->found_posts,
            'pages'       => $query->max_num_pages,
            'current_page' => $page,
        ];
    }

    /**
     * Render the filter sidebar on shop pages.
     */
    public function render_filter_sidebar() {
        if ( ! is_shop() && ! is_product_category() && ! is_product_tag() ) {
            return;
        }
        echo $this->get_filter_html();
    }

    public function shortcode_output() {
        return $this->get_filter_html();
    }

    private function get_filter_html() {
        $categories = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => true ] );
        $attributes = wc_get_attribute_taxonomies();
        $price_range = $this->get_price_range();

        ob_start();
        ?>
        <div class="wcet-product-filters" data-nonce="<?php echo esc_attr( wp_create_nonce( 'wcet-filter' ) ); ?>">
            <h3><?php esc_html_e( 'Filters', 'wc-enterprise' ); ?></h3>

            <!-- Price Range -->
            <div class="wcet-filter-group">
                <h4><?php esc_html_e( 'Price', 'wc-enterprise' ); ?></h4>
                <div class="wcet-price-range">
                    <input type="number" name="price_min" placeholder="<?php echo esc_attr( $price_range['min'] ); ?>" min="0" step="1">
                    <span>—</span>
                    <input type="number" name="price_max" placeholder="<?php echo esc_attr( $price_range['max'] ); ?>" min="0" step="1">
                </div>
            </div>

            <!-- Categories -->
            <?php if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) : ?>
            <div class="wcet-filter-group">
                <h4><?php esc_html_e( 'Categories', 'wc-enterprise' ); ?></h4>
                <?php foreach ( $categories as $cat ) : ?>
                    <label><input type="checkbox" name="categories[]" value="<?php echo esc_attr( $cat->term_id ); ?>"> <?php echo esc_html( $cat->name ); ?> <span>(<?php echo esc_html( $cat->count ); ?>)</span></label><br>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- Attributes -->
            <?php foreach ( $attributes as $attr ) :
                $terms = get_terms( [ 'taxonomy' => 'pa_' . $attr->attribute_name, 'hide_empty' => true ] );
                if ( is_wp_error( $terms ) || empty( $terms ) ) continue;
            ?>
            <div class="wcet-filter-group">
                <h4><?php echo esc_html( $attr->attribute_label ); ?></h4>
                <?php foreach ( $terms as $term ) : ?>
                    <label><input type="checkbox" name="attributes[pa_<?php echo esc_attr( $attr->attribute_name ); ?>][]" value="<?php echo esc_attr( $term->slug ); ?>"> <?php echo esc_html( $term->name ); ?></label><br>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>

            <!-- Rating -->
            <div class="wcet-filter-group">
                <h4><?php esc_html_e( 'Minimum Rating', 'wc-enterprise' ); ?></h4>
                <select name="min_rating">
                    <option value=""><?php esc_html_e( 'Any', 'wc-enterprise' ); ?></option>
                    <option value="4">4+</option>
                    <option value="3">3+</option>
                    <option value="2">2+</option>
                </select>
            </div>

            <!-- Stock -->
            <div class="wcet-filter-group">
                <h4><?php esc_html_e( 'Availability', 'wc-enterprise' ); ?></h4>
                <label><input type="checkbox" name="stock_status" value="instock"> <?php esc_html_e( 'In Stock', 'wc-enterprise' ); ?></label>
            </div>

            <button type="button" class="wcet-apply-filters button"><?php esc_html_e( 'Apply Filters', 'wc-enterprise' ); ?></button>
            <button type="button" class="wcet-clear-filters button"><?php esc_html_e( 'Clear', 'wc-enterprise' ); ?></button>
        </div>
        <?php
        return ob_get_clean();
    }

    private function get_price_range() {
        global $wpdb;
        $cache_key = 'wcet_price_range';
        $range     = wp_cache_get( $cache_key, 'wcet' );

        if ( false === $range ) {
            $range = $wpdb->get_row(
                "SELECT MIN( CAST(meta_value AS DECIMAL(10,2)) ) as min_price,
                        MAX( CAST(meta_value AS DECIMAL(10,2)) ) as max_price
                 FROM {$wpdb->postmeta}
                 WHERE meta_key = '_price'
                   AND meta_value != ''
                   AND meta_value IS NOT NULL",
                ARRAY_A
            ) ?: [ 'min_price' => 0, 'max_price' => 1000 ];

            wp_cache_set( $cache_key, $range, 'wcet', 3600 );
        }

        return [ 'min' => (float) $range['min_price'], 'max' => (float) $range['max_price'] ];
    }

    private function sanitize_filters( $raw ) {
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $clean = [];
        if ( isset( $raw['price_min'] ) ) $clean['price_min'] = floatval( $raw['price_min'] );
        if ( isset( $raw['price_max'] ) ) $clean['price_max'] = floatval( $raw['price_max'] );
        if ( isset( $raw['categories'] ) ) $clean['categories'] = array_map( 'intval', (array) $raw['categories'] );
        if ( isset( $raw['tags'] ) ) $clean['tags'] = array_map( 'intval', (array) $raw['tags'] );
        if ( isset( $raw['stock_status'] ) ) $clean['stock_status'] = sanitize_text_field( $raw['stock_status'] );
        if ( isset( $raw['min_rating'] ) ) $clean['min_rating'] = floatval( $raw['min_rating'] );
        if ( isset( $raw['on_sale'] ) ) $clean['on_sale'] = (bool) $raw['on_sale'];
        if ( isset( $raw['sort'] ) ) $clean['sort'] = sanitize_text_field( $raw['sort'] );
        if ( isset( $raw['attributes'] ) && is_array( $raw['attributes'] ) ) {
            $clean['attributes'] = [];
            foreach ( $raw['attributes'] as $tax => $terms ) {
                $clean['attributes'][ sanitize_text_field( $tax ) ] = array_map( 'sanitize_title', (array) $terms );
            }
        }

        return $clean;
    }

    public function enqueue_assets() {
        if ( ! is_shop() && ! is_product_category() && ! is_product_tag() ) {
            return;
        }

        wp_enqueue_script(
            'wcet-filters',
            WCET_PLUGIN_URL . 'assets/js/filters.js',
            [ 'jquery' ],
            WCET_VERSION,
            true
        );
        wp_localize_script( 'wcet-filters', 'wcetFilters', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
        ] );
        wp_enqueue_style( 'wcet-filters', WCET_PLUGIN_URL . 'assets/css/filters.css', [], WCET_VERSION );
    }
}

WCET_Product_Filter::instance();
