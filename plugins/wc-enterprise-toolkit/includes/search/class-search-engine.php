<?php
/**
 * External Search Engine Integration
 *
 * Supports Algolia and Meilisearch as drop-in replacements for WooCommerce's
 * default product search. Handles indexing, syncing, and querying.
 */

defined( 'ABSPATH' ) || exit;

class WCET_Search_Engine {

    private static $instance = null;
    private $engine;
    private $config;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->engine = get_option( 'wcet_search_engine', 'meilisearch' );
        $this->config = [
            'algolia' => [
                'app_id'  => get_option( 'wcet_algolia_app_id', '' ),
                'api_key' => get_option( 'wcet_algolia_api_key', '' ),
                'index'   => get_option( 'wcet_algolia_index', 'products' ),
            ],
            'meilisearch' => [
                'host'    => get_option( 'wcet_meilisearch_host', 'http://127.0.0.1:7700' ),
                'api_key' => get_option( 'wcet_meilisearch_api_key', '' ),
                'index'   => get_option( 'wcet_meilisearch_index', 'products' ),
            ],
        ];

        // Override default WooCommerce search.
        add_filter( 'posts_search', [ $this, 'intercept_product_search' ], 10, 2 );

        // Index products on save.
        add_action( 'woocommerce_update_product', [ $this, 'index_product' ] );
        add_action( 'woocommerce_new_product', [ $this, 'index_product' ] );
        add_action( 'woocommerce_delete_product', [ $this, 'remove_product' ] );

        // Admin settings.
        add_action( 'admin_menu', [ $this, 'register_settings_page' ] );

        // Bulk reindex via AJAX.
        add_action( 'wp_ajax_wcet_reindex_products', [ $this, 'ajax_reindex' ] );
    }

    /**
     * Search products using the external engine.
     */
    public function search( string $query, array $filters = [], int $limit = 20, int $offset = 0 ) {
        if ( empty( $query ) ) {
            return [ 'hits' => [], 'total' => 0 ];
        }

        switch ( $this->engine ) {
            case 'algolia':
                return $this->search_algolia( $query, $filters, $limit, $offset );
            case 'meilisearch':
                return $this->search_meilisearch( $query, $filters, $limit, $offset );
            default:
                return $this->search_fallback( $query, $limit, $offset );
        }
    }

    /**
     * Intercept WordPress product search to use external engine.
     */
    public function intercept_product_search( string $search, WP_Query $query ) {
        if ( ! $query->is_search() || 'product' !== $query->get( 'post_type' ) ) {
            return $search;
        }

        $term = $query->get( 's' );
        if ( empty( $term ) ) {
            return $search;
        }

        $results = $this->search( $term, [], 100 );

        if ( ! empty( $results['hits'] ) ) {
            $ids = array_column( $results['hits'], 'id' );
            $query->set( 'post__in', $ids );
            $query->set( 'orderby', 'post__in' );
            $query->set( 's', '' ); // Clear the search to use post__in.
            return '';
        }

        return $search;
    }

    /**
     * Index a single product.
     */
    public function index_product( int $product_id ) {
        $product = wc_get_product( $product_id );
        if ( ! $product || 'publish' !== $product->get_status() ) {
            $this->remove_product( $product_id );
            return;
        }

        $document = $this->build_document( $product );

        switch ( $this->engine ) {
            case 'algolia':
                $this->algolia_index( $document );
                break;
            case 'meilisearch':
                $this->meilisearch_index( $document );
                break;
        }
    }

    public function remove_product( int $product_id ) {
        switch ( $this->engine ) {
            case 'algolia':
                $this->algolia_remove( $product_id );
                break;
            case 'meilisearch':
                $this->meilisearch_remove( $product_id );
                break;
        }
    }

    /**
     * Build a searchable document from a WC_Product.
     */
    private function build_document( WC_Product $product ) {
        $categories = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'names' ] );
        $tags       = wp_get_post_terms( $product->get_id(), 'product_tag', [ 'fields' => 'names' ] );

        return [
            'id'            => $product->get_id(),
            'name'          => $product->get_name(),
            'description'   => wp_strip_all_tags( $product->get_short_description() ),
            'sku'           => $product->get_sku(),
            'price'         => (float) $product->get_price(),
            'regular_price' => (float) $product->get_regular_price(),
            'sale_price'    => (float) $product->get_sale_price(),
            'categories'    => is_array( $categories ) ? $categories : [],
            'tags'          => is_array( $tags ) ? $tags : [],
            'stock_status'  => $product->get_stock_status(),
            'average_rating' => (float) $product->get_average_rating(),
            'review_count'  => (int) $product->get_review_count(),
            'image'         => wp_get_attachment_url( $product->get_image_id() ),
            'permalink'     => $product->get_permalink(),
            'type'          => $product->get_type(),
            'created_at'    => $product->get_date_created() ? $product->get_date_created()->getTimestamp() : 0,
        ];
    }

    // --- Meilisearch ---

    private function search_meilisearch( string $query, array $filters, int $limit, int $offset ) {
        $cfg  = $this->config['meilisearch'];
        $body = [
            'q'      => $query,
            'limit'  => $limit,
            'offset' => $offset,
        ];

        if ( ! empty( $filters ) ) {
            $body['filter'] = $this->build_meili_filter( $filters );
        }

        $response = wp_remote_post( "{$cfg['host']}/indexes/{$cfg['index']}/search", [
            'headers' => [
                'Authorization' => "Bearer {$cfg['api_key']}",
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( $body ),
            'timeout' => 5,
        ] );

        if ( is_wp_error( $response ) ) {
            return $this->search_fallback( $query, $limit, $offset );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return [
            'hits'  => $data['hits'] ?? [],
            'total' => $data['estimatedTotalHits'] ?? 0,
        ];
    }

    private function meilisearch_index( array $document ) {
        $cfg = $this->config['meilisearch'];
        wp_remote_post( "{$cfg['host']}/indexes/{$cfg['index']}/documents", [
            'headers' => [
                'Authorization' => "Bearer {$cfg['api_key']}",
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [ $document ] ),
            'timeout' => 5,
        ] );
    }

    private function meilisearch_remove( int $product_id ) {
        $cfg = $this->config['meilisearch'];
        wp_remote_request( "{$cfg['host']}/indexes/{$cfg['index']}/documents/{$product_id}", [
            'method'  => 'DELETE',
            'headers' => [ 'Authorization' => "Bearer {$cfg['api_key']}" ],
            'timeout' => 5,
        ] );
    }

    private function build_meili_filter( array $filters ) {
        $parts = [];
        foreach ( $filters as $key => $value ) {
            if ( is_array( $value ) ) {
                $parts[] = $key . ' IN [' . implode( ', ', array_map( function( $v ) { return '"' . $v . '"'; }, $value ) ) . ']';
            } else {
                $parts[] = "$key = \"$value\"";
            }
        }
        return implode( ' AND ', $parts );
    }

    // --- Algolia ---

    private function search_algolia( string $query, array $filters, int $limit, int $offset ) {
        $cfg  = $this->config['algolia'];
        $body = [
            'query'        => $query,
            'hitsPerPage'  => $limit,
            'page'         => intdiv( $offset, max( 1, $limit ) ),
        ];

        if ( ! empty( $filters ) ) {
            $body['filters'] = $this->build_algolia_filter( $filters );
        }

        $response = wp_remote_post(
            "https://{$cfg['app_id']}-dsn.algolia.net/1/indexes/{$cfg['index']}/query",
            [
                'headers' => [
                    'X-Algolia-API-Key'        => $cfg['api_key'],
                    'X-Algolia-Application-Id' => $cfg['app_id'],
                    'Content-Type'             => 'application/json',
                ],
                'body'    => wp_json_encode( $body ),
                'timeout' => 5,
            ]
        );

        if ( is_wp_error( $response ) ) {
            return $this->search_fallback( $query, $limit, $offset );
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return [
            'hits'  => $data['hits'] ?? [],
            'total' => $data['nbHits'] ?? 0,
        ];
    }

    private function algolia_index( array $document ) {
        $cfg = $this->config['algolia'];
        $document['objectID'] = $document['id'];
        wp_remote_request(
            "https://{$cfg['app_id']}.algolia.net/1/indexes/{$cfg['index']}",
            [
                'method'  => 'POST',
                'headers' => [
                    'X-Algolia-API-Key'        => $cfg['api_key'],
                    'X-Algolia-Application-Id' => $cfg['app_id'],
                    'Content-Type'             => 'application/json',
                ],
                'body'    => wp_json_encode( $document ),
                'timeout' => 5,
            ]
        );
    }

    private function algolia_remove( int $product_id ) {
        $cfg = $this->config['algolia'];
        wp_remote_request(
            "https://{$cfg['app_id']}.algolia.net/1/indexes/{$cfg['index']}/{$product_id}",
            [
                'method'  => 'DELETE',
                'headers' => [
                    'X-Algolia-API-Key'        => $cfg['api_key'],
                    'X-Algolia-Application-Id' => $cfg['app_id'],
                ],
                'timeout' => 5,
            ]
        );
    }

    private function build_algolia_filter( array $filters ) {
        $parts = [];
        foreach ( $filters as $key => $value ) {
            if ( is_array( $value ) ) {
                $or_parts = array_map( function( $v ) use ( $key ) { return "$key:\"$v\""; }, $value );
                $parts[]  = '(' . implode( ' OR ', $or_parts ) . ')';
            } else {
                $parts[] = "$key:\"$value\"";
            }
        }
        return implode( ' AND ', $parts );
    }

    // --- Fallback (MySQL LIKE) ---

    private function search_fallback( string $query, int $limit, int $offset ) {
        $args = [
            'status'  => 'publish',
            'limit'   => $limit,
            'offset'  => $offset,
            's'       => $query,
            'return'  => 'ids',
        ];

        $products = wc_get_products( $args );
        $hits     = [];

        foreach ( $products as $id ) {
            $product = wc_get_product( $id );
            if ( $product ) {
                $hits[] = $this->build_document( $product );
            }
        }

        return [ 'hits' => $hits, 'total' => count( $hits ) ];
    }

    // --- Admin ---

    public function register_settings_page() {
        add_submenu_page(
            'woocommerce',
            __( 'Search Settings', 'wc-enterprise' ),
            __( 'Search Engine', 'wc-enterprise' ),
            'manage_woocommerce',
            'wcet-search-settings',
            [ $this, 'render_settings_page' ]
        );
    }

    public function render_settings_page() {
        if ( isset( $_POST['wcet_search_save'] ) && wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'wcet_search_settings' ) ) {
            update_option( 'wcet_search_engine', sanitize_text_field( $_POST['engine'] ?? 'meilisearch' ) );
            update_option( 'wcet_meilisearch_host', esc_url_raw( $_POST['meili_host'] ?? '' ) );
            update_option( 'wcet_meilisearch_api_key', sanitize_text_field( $_POST['meili_key'] ?? '' ) );
            update_option( 'wcet_algolia_app_id', sanitize_text_field( $_POST['algolia_app_id'] ?? '' ) );
            update_option( 'wcet_algolia_api_key', sanitize_text_field( $_POST['algolia_key'] ?? '' ) );
            echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'wc-enterprise' ) . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Search Engine Configuration', 'wc-enterprise' ); ?></h1>
            <form method="post">
                <?php wp_nonce_field( 'wcet_search_settings' ); ?>
                <table class="form-table">
                    <tr><th><?php esc_html_e( 'Engine', 'wc-enterprise' ); ?></th>
                        <td><select name="engine">
                            <option value="meilisearch" <?php selected( $this->engine, 'meilisearch' ); ?>>Meilisearch</option>
                            <option value="algolia" <?php selected( $this->engine, 'algolia' ); ?>>Algolia</option>
                            <option value="default" <?php selected( $this->engine, 'default' ); ?>>WordPress Default</option>
                        </select></td></tr>
                    <tr><th><?php esc_html_e( 'Meilisearch Host', 'wc-enterprise' ); ?></th>
                        <td><input type="url" name="meili_host" value="<?php echo esc_attr( $this->config['meilisearch']['host'] ); ?>" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e( 'Meilisearch API Key', 'wc-enterprise' ); ?></th>
                        <td><input type="password" name="meili_key" value="<?php echo esc_attr( $this->config['meilisearch']['api_key'] ); ?>" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e( 'Algolia App ID', 'wc-enterprise' ); ?></th>
                        <td><input type="text" name="algolia_app_id" value="<?php echo esc_attr( $this->config['algolia']['app_id'] ); ?>" class="regular-text"></td></tr>
                    <tr><th><?php esc_html_e( 'Algolia API Key', 'wc-enterprise' ); ?></th>
                        <td><input type="password" name="algolia_key" value="<?php echo esc_attr( $this->config['algolia']['api_key'] ); ?>" class="regular-text"></td></tr>
                </table>
                <p class="submit">
                    <button type="submit" name="wcet_search_save" class="button button-primary"><?php esc_html_e( 'Save', 'wc-enterprise' ); ?></button>
                    <button type="button" id="wcet-reindex" class="button"><?php esc_html_e( 'Reindex All Products', 'wc-enterprise' ); ?></button>
                </p>
            </form>
        </div>
        <script>
        jQuery('#wcet-reindex').on('click', function() {
            jQuery(this).prop('disabled', true).text('Reindexing...');
            jQuery.post(ajaxurl, { action: 'wcet_reindex_products', _wpnonce: '<?php echo wp_create_nonce( 'wcet_reindex' ); ?>' }, function(r) {
                alert(r.data || 'Done');
                location.reload();
            });
        });
        </script>
        <?php
    }

    public function ajax_reindex() {
        check_ajax_referer( 'wcet_reindex', '_wpnonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $ids = wc_get_products( [ 'status' => 'publish', 'limit' => -1, 'return' => 'ids' ] );
        $count = 0;
        foreach ( $ids as $id ) {
            $this->index_product( $id );
            $count++;
        }

        wp_send_json_success( sprintf( '%d products indexed.', $count ) );
    }
}

WCET_Search_Engine::instance();
