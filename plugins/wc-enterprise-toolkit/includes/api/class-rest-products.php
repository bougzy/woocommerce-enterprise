<?php
/**
 * Custom REST API Endpoint for Products
 *
 * Provides a performant, cacheable REST API for product data under the
 * wcet/v1 namespace. Supports advanced filtering, pagination, and
 * leverages the WCET object cache layer for fast responses.
 *
 * @package WCET
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class WCET_REST_Products extends WP_REST_Controller {

	/**
	 * API namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'wcet/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'products';

	/**
	 * Cache group for product API responses.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'wcet_api_products';

	/**
	 * Cache TTL in seconds (5 minutes).
	 *
	 * @var int
	 */
	private const CACHE_TTL = 300;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get singleton instance.
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
	 * Constructor.
	 */
	private function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		// Invalidate caches when products change.
		add_action( 'woocommerce_update_product', [ $this, 'invalidate_product_cache' ] );
		add_action( 'woocommerce_delete_product', [ $this, 'invalidate_product_cache' ] );
		add_action( 'woocommerce_product_set_stock', [ $this, 'invalidate_product_stock_cache' ] );
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_items' ],
					'permission_callback' => [ $this, 'get_items_permissions_check' ],
					'args'                => $this->get_collection_params(),
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_item' ],
					'permission_callback' => [ $this, 'get_item_permissions_check' ],
					'args'                => [
						'id' => [
							'description' => __( 'Unique identifier for the product.', 'wc-enterprise' ),
							'type'        => 'integer',
							'required'    => true,
							'validate_callback' => function ( $value ) {
								return is_numeric( $value ) && (int) $value > 0;
							},
							'sanitize_callback' => 'absint',
						],
					],
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);
	}

	/**
	 * Check permissions for listing products.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! $this->check_read_permission() ) {
			return new WP_Error(
				'wcet_rest_forbidden',
				__( 'You do not have permission to access this resource.', 'wc-enterprise' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Check permissions for reading a single product.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! $this->check_read_permission() ) {
			return new WP_Error(
				'wcet_rest_forbidden',
				__( 'You do not have permission to access this resource.', 'wc-enterprise' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Check if the current user or API token has read permission.
	 *
	 * @return bool
	 */
	private function check_read_permission(): bool {
		// Allow authenticated WordPress users who can at minimum read products.
		if ( current_user_can( 'read' ) ) {
			return true;
		}

		// Allow requests authenticated via WCET API token with read permission.
		$token_user_id = get_current_user_id();
		if ( $token_user_id > 0 ) {
			return true;
		}

		/**
		 * Filter read permission for the products endpoint.
		 *
		 * @param bool $has_permission Whether the requester has read permission.
		 */
		return apply_filters( 'wcet_rest_products_read_permission', false );
	}

	/**
	 * Get a collection of products.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$cache_key = $this->build_cache_key( 'list', $request->get_params() );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			$response = rest_ensure_response( $cached['data'] );
			$response->header( 'X-WCET-Cache', 'HIT' );
			$this->set_pagination_headers( $response, $cached['total'], $cached['total_pages'], $request );
			return $response;
		}

		$per_page = $request->get_param( 'per_page' ) ?: 10;
		$page     = $request->get_param( 'page' ) ?: 1;

		$query_args = $this->build_query_args( $request, $per_page, $page );

		$query   = new WC_Product_Query( $query_args );
		$results = $query->get_products();

		// Build separate count query for pagination.
		$count_args            = $query_args;
		$count_args['return']  = 'ids';
		$count_args['limit']   = -1;
		$count_args['offset']  = 0;
		$count_query           = new WC_Product_Query( $count_args );
		$total_items           = count( $count_query->get_products() );
		$total_pages           = (int) ceil( $total_items / $per_page );

		$data = [];
		foreach ( $results as $product ) {
			$data[] = $this->prepare_product_data( $product );
		}

		// Cache the result.
		wp_cache_set(
			$cache_key,
			[
				'data'        => $data,
				'total'       => $total_items,
				'total_pages' => $total_pages,
			],
			self::CACHE_GROUP,
			self::CACHE_TTL
		);

		$response = rest_ensure_response( $data );
		$response->header( 'X-WCET-Cache', 'MISS' );
		$this->set_pagination_headers( $response, $total_items, $total_pages, $request );

		return $response;
	}

	/**
	 * Get a single product by ID.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$product_id = (int) $request->get_param( 'id' );
		$cache_key  = $this->build_cache_key( 'single', [ 'id' => $product_id ] );
		$cached     = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached ) {
			$response = rest_ensure_response( $cached );
			$response->header( 'X-WCET-Cache', 'HIT' );
			return $response;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product || 'trash' === $product->get_status() ) {
			return new WP_Error(
				'wcet_rest_product_not_found',
				__( 'Product not found.', 'wc-enterprise' ),
				[ 'status' => 404 ]
			);
		}

		$data = $this->prepare_product_data( $product );

		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, self::CACHE_TTL );

		$response = rest_ensure_response( $data );
		$response->header( 'X-WCET-Cache', 'MISS' );

		return $response;
	}

	/**
	 * Build WC_Product_Query arguments from the REST request.
	 *
	 * @param WP_REST_Request $request  The request object.
	 * @param int             $per_page Number of items per page.
	 * @param int             $page     Current page number.
	 * @return array
	 */
	private function build_query_args( WP_REST_Request $request, int $per_page, int $page ): array {
		$args = [
			'limit'  => $per_page,
			'offset' => ( $page - 1 ) * $per_page,
			'return' => 'objects',
		];

		// Search.
		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$args['s'] = sanitize_text_field( $search );
		}

		// Status filter.
		$status = $request->get_param( 'status' );
		if ( ! empty( $status ) ) {
			$args['status'] = sanitize_text_field( $status );
		} else {
			$args['status'] = 'publish';
		}

		// Category filter.
		$category = $request->get_param( 'category' );
		if ( ! empty( $category ) ) {
			$args['category'] = array_map( 'sanitize_text_field', (array) $category );
		}

		// Tag filter.
		$tag = $request->get_param( 'tag' );
		if ( ! empty( $tag ) ) {
			$args['tag'] = array_map( 'sanitize_text_field', (array) $tag );
		}

		// Stock status filter.
		$stock_status = $request->get_param( 'stock_status' );
		if ( ! empty( $stock_status ) ) {
			$args['stock_status'] = sanitize_text_field( $stock_status );
		}

		// On-sale filter.
		$on_sale = $request->get_param( 'on_sale' );
		if ( null !== $on_sale ) {
			if ( $on_sale ) {
				$args['include'] = wc_get_product_ids_on_sale();
				if ( empty( $args['include'] ) ) {
					$args['include'] = [ 0 ]; // No products on sale; return empty.
				}
			}
		}

		// Price range filter.
		$min_price = $request->get_param( 'min_price' );
		$max_price = $request->get_param( 'max_price' );
		if ( null !== $min_price || null !== $max_price ) {
			$args['meta_query'] = $this->build_price_meta_query( $min_price, $max_price );
		}

		// Ordering.
		$orderby = $request->get_param( 'orderby' );
		if ( ! empty( $orderby ) ) {
			$allowed_orderby = [ 'date', 'title', 'price', 'popularity', 'rating', 'id', 'menu_order' ];
			if ( in_array( $orderby, $allowed_orderby, true ) ) {
				$args['orderby'] = $orderby;
			}
		}

		$order = $request->get_param( 'order' );
		if ( ! empty( $order ) ) {
			$args['order'] = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';
		}

		return $args;
	}

	/**
	 * Build a meta query for price range filtering.
	 *
	 * @param float|null $min_price Minimum price.
	 * @param float|null $max_price Maximum price.
	 * @return array
	 */
	private function build_price_meta_query( ?float $min_price, ?float $max_price ): array {
		$meta_query = [ 'relation' => 'AND' ];

		if ( null !== $min_price ) {
			$meta_query[] = [
				'key'     => '_price',
				'value'   => (float) $min_price,
				'compare' => '>=',
				'type'    => 'DECIMAL(10,2)',
			];
		}

		if ( null !== $max_price ) {
			$meta_query[] = [
				'key'     => '_price',
				'value'   => (float) $max_price,
				'compare' => '<=',
				'type'    => 'DECIMAL(10,2)',
			];
		}

		return $meta_query;
	}

	/**
	 * Prepare a single product for the REST response.
	 *
	 * @param WC_Product $product Product object.
	 * @return array
	 */
	private function prepare_product_data( WC_Product $product ): array {
		$categories = [];
		$terms      = get_the_terms( $product->get_id(), 'product_cat' );
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[] = [
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				];
			}
		}

		$tags      = [];
		$tag_terms = get_the_terms( $product->get_id(), 'product_tag' );
		if ( $tag_terms && ! is_wp_error( $tag_terms ) ) {
			foreach ( $tag_terms as $term ) {
				$tags[] = [
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				];
			}
		}

		$images         = [];
		$attachment_ids  = $product->get_gallery_image_ids();
		$main_image_id   = $product->get_image_id();

		if ( $main_image_id ) {
			array_unshift( $attachment_ids, $main_image_id );
		}

		$attachment_ids = array_unique( $attachment_ids );
		foreach ( $attachment_ids as $attachment_id ) {
			$image_url = wp_get_attachment_url( $attachment_id );
			if ( $image_url ) {
				$images[] = [
					'id'  => (int) $attachment_id,
					'src' => esc_url( $image_url ),
					'alt' => get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
				];
			}
		}

		$attributes = [];
		foreach ( $product->get_attributes() as $attr_key => $attribute ) {
			$attr_data = [
				'name'    => wc_attribute_label( $attr_key, $product ),
				'visible' => $attribute->get_visible(),
			];

			if ( $attribute->is_taxonomy() ) {
				$attr_data['options'] = wp_get_post_terms(
					$product->get_id(),
					$attribute->get_name(),
					[ 'fields' => 'names' ]
				);
			} else {
				$attr_data['options'] = $attribute->get_options();
			}

			$attributes[] = $attr_data;
		}

		return [
			'id'             => $product->get_id(),
			'name'           => $product->get_name(),
			'slug'           => $product->get_slug(),
			'type'           => $product->get_type(),
			'status'         => $product->get_status(),
			'price'          => $product->get_price(),
			'regular_price'  => $product->get_regular_price(),
			'sale_price'     => $product->get_sale_price(),
			'sku'            => $product->get_sku(),
			'stock_status'   => $product->get_stock_status(),
			'stock_quantity' => $product->get_stock_quantity(),
			'categories'     => $categories,
			'tags'           => $tags,
			'images'         => $images,
			'attributes'     => $attributes,
			'average_rating' => $product->get_average_rating(),
			'permalink'      => $product->get_permalink(),
		];
	}

	/**
	 * Set pagination headers on the response.
	 *
	 * @param WP_REST_Response $response    The response object.
	 * @param int              $total       Total number of items.
	 * @param int              $total_pages Total number of pages.
	 * @param WP_REST_Request  $request     The request object.
	 * @return void
	 */
	private function set_pagination_headers( WP_REST_Response $response, int $total, int $total_pages, WP_REST_Request $request ): void {
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', $total_pages );

		$page     = (int) ( $request->get_param( 'page' ) ?: 1 );
		$base_url = rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) );

		$links = [];

		if ( $page > 1 ) {
			$links[] = sprintf(
				'<%s>; rel="prev"',
				esc_url( add_query_arg( 'page', $page - 1, $base_url ) )
			);
		}

		if ( $page < $total_pages ) {
			$links[] = sprintf(
				'<%s>; rel="next"',
				esc_url( add_query_arg( 'page', $page + 1, $base_url ) )
			);
		}

		if ( ! empty( $links ) ) {
			$response->header( 'Link', implode( ', ', $links ) );
		}
	}

	/**
	 * Build a cache key from the request parameters.
	 *
	 * @param string $type   The type of cache key (list or single).
	 * @param array  $params The request parameters.
	 * @return string
	 */
	private function build_cache_key( string $type, array $params ): string {
		ksort( $params );
		return sprintf(
			'wcet_products_%s_%s',
			$type,
			md5( wp_json_encode( $params ) )
		);
	}

	/**
	 * Invalidate product cache when a product is updated or deleted.
	 *
	 * @param int $product_id The product ID.
	 * @return void
	 */
	public function invalidate_product_cache( int $product_id ): void {
		// Invalidate the single-product cache.
		$single_key = $this->build_cache_key( 'single', [ 'id' => $product_id ] );
		wp_cache_delete( $single_key, self::CACHE_GROUP );

		// Flush the entire list cache group since lists may contain this product.
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::CACHE_GROUP );
		}

		/**
		 * Fires after product API cache is invalidated.
		 *
		 * @param int $product_id The product ID.
		 */
		do_action( 'wcet_rest_products_cache_invalidated', $product_id );
	}

	/**
	 * Invalidate product cache on stock change.
	 *
	 * @param WC_Product $product The product object.
	 * @return void
	 */
	public function invalidate_product_stock_cache( WC_Product $product ): void {
		$this->invalidate_product_cache( $product->get_id() );
	}

	/**
	 * Get the query params for collections.
	 *
	 * @return array
	 */
	public function get_collection_params(): array {
		return [
			'page'         => [
				'description'       => __( 'Current page of the collection.', 'wc-enterprise' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'per_page'     => [
				'description'       => __( 'Maximum number of items to be returned per page.', 'wc-enterprise' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'search'       => [
				'description'       => __( 'Limit results to those matching a string.', 'wc-enterprise' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'category'     => [
				'description'       => __( 'Limit results to products in a specific category slug.', 'wc-enterprise' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'tag'          => [
				'description'       => __( 'Limit results to products with a specific tag slug.', 'wc-enterprise' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'min_price'    => [
				'description'       => __( 'Limit results to products with a price greater than or equal to this value.', 'wc-enterprise' ),
				'type'              => 'number',
				'minimum'           => 0,
				'sanitize_callback' => 'floatval',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'max_price'    => [
				'description'       => __( 'Limit results to products with a price less than or equal to this value.', 'wc-enterprise' ),
				'type'              => 'number',
				'minimum'           => 0,
				'sanitize_callback' => 'floatval',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'orderby'      => [
				'description'       => __( 'Sort collection by product attribute.', 'wc-enterprise' ),
				'type'              => 'string',
				'default'           => 'date',
				'enum'              => [ 'date', 'title', 'price', 'popularity', 'rating', 'id', 'menu_order' ],
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'order'        => [
				'description'       => __( 'Order sort attribute ascending or descending.', 'wc-enterprise' ),
				'type'              => 'string',
				'default'           => 'DESC',
				'enum'              => [ 'ASC', 'DESC' ],
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'status'       => [
				'description'       => __( 'Limit results to products with a specific status.', 'wc-enterprise' ),
				'type'              => 'string',
				'default'           => 'publish',
				'enum'              => [ 'publish', 'draft', 'pending', 'private', 'any' ],
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'on_sale'      => [
				'description'       => __( 'Limit results to products that are on sale.', 'wc-enterprise' ),
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'stock_status' => [
				'description'       => __( 'Limit results to products with a specific stock status.', 'wc-enterprise' ),
				'type'              => 'string',
				'enum'              => [ 'instock', 'outofstock', 'onbackorder' ],
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			],
		];
	}

	/**
	 * Get the product schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wcet-product',
			'type'       => 'object',
			'properties' => [
				'id'             => [
					'description' => __( 'Unique identifier for the product.', 'wc-enterprise' ),
					'type'        => 'integer',
					'context'     => [ 'view' ],
					'readonly'    => true,
				],
				'name'           => [
					'description' => __( 'Product name.', 'wc-enterprise' ),
					'type'        => 'string',
					'context'     => [ 'view' ],
				],
				'slug'           => [
					'description' => __( 'Product slug.', 'wc-enterprise' ),
					'type'        => 'string',
					'context'     => [ 'view' ],
				],
				'type'           => [
					'description' => __( 'Product type.', 'wc-enterprise' ),
					'type'        => 'string',
					'context'     => [ 'view' ],
				],
				'status'         => [
					'description' => __( 'Product status.', 'wc-enterprise' ),
					'type'        => 'string',
					'context'     => [ 'view' ],
				],
				'price'          => [
					'description' => __( 'Current product price.', 'wc-enterprise' ),
					'type'        => 'string',
					'context'     => [ 'view' ],
				],
				'regular_price'  => [
					'description' => __( 'Product regular price.', 'wc-enterprise' ),
					'type'        => 'string',
					'context'     => [ 'view' ],
				],
				'sale_price'     => [
					'description' => __( 'Product sale price.', 'wc-enterprise' ),
					'type'        => 'string',
					'context'     => [ 'view' ],
				],
				'sku'            => [
					'description' => __( 'Product SKU.', 'wc-enterprise' ),
					'type'        => 'string',
					'context'     => [ 'view' ],
				],
				'stock_status'   => [
					'description' => __( 'Product stock status.', 'wc-enterprise' ),
					'type'        => 'string',
					'context'     => [ 'view' ],
				],
				'stock_quantity' => [
					'description' => __( 'Product stock quantity.', 'wc-enterprise' ),
					'type'        => [ 'integer', 'null' ],
					'context'     => [ 'view' ],
				],
				'categories'     => [
					'description' => __( 'Product categories.', 'wc-enterprise' ),
					'type'        => 'array',
					'context'     => [ 'view' ],
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'   => [ 'type' => 'integer' ],
							'name' => [ 'type' => 'string' ],
							'slug' => [ 'type' => 'string' ],
						],
					],
				],
				'tags'           => [
					'description' => __( 'Product tags.', 'wc-enterprise' ),
					'type'        => 'array',
					'context'     => [ 'view' ],
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'   => [ 'type' => 'integer' ],
							'name' => [ 'type' => 'string' ],
							'slug' => [ 'type' => 'string' ],
						],
					],
				],
				'images'         => [
					'description' => __( 'Product images.', 'wc-enterprise' ),
					'type'        => 'array',
					'context'     => [ 'view' ],
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'id'  => [ 'type' => 'integer' ],
							'src' => [ 'type' => 'string', 'format' => 'uri' ],
							'alt' => [ 'type' => 'string' ],
						],
					],
				],
				'attributes'     => [
					'description' => __( 'Product attributes.', 'wc-enterprise' ),
					'type'        => 'array',
					'context'     => [ 'view' ],
					'items'       => [
						'type'       => 'object',
						'properties' => [
							'name'    => [ 'type' => 'string' ],
							'visible' => [ 'type' => 'boolean' ],
							'options' => [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
						],
					],
				],
				'average_rating' => [
					'description' => __( 'Average product rating.', 'wc-enterprise' ),
					'type'        => 'string',
					'context'     => [ 'view' ],
					'readonly'    => true,
				],
				'permalink'      => [
					'description' => __( 'Product URL.', 'wc-enterprise' ),
					'type'        => 'string',
					'format'      => 'uri',
					'context'     => [ 'view' ],
					'readonly'    => true,
				],
			],
		];

		return $this->add_additional_fields_schema( $this->schema );
	}
}

WCET_REST_Products::instance();
