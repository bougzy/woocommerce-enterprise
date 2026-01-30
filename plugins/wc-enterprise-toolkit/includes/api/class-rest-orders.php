<?php
/**
 * Custom REST API Endpoint for Orders
 *
 * Provides REST endpoints for listing, retrieving, creating orders, and
 * updating order status under the wcet/v1 namespace. Status transitions
 * are validated against the WCET_Order_Workflow allowed transitions.
 *
 * @package WCET
 * @since   1.0.0
 */

defined( 'ABSPATH' ) || exit;

class WCET_REST_Orders extends WP_REST_Controller {

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
	protected $rest_base = 'orders';

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
	}

	/**
	 * Register REST API routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		// GET /orders — list orders.
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
				// POST /orders — create order.
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_item' ],
					'permission_callback' => [ $this, 'create_item_permissions_check' ],
					'args'                => $this->get_create_params(),
				],
				'schema' => [ $this, 'get_public_item_schema' ],
			]
		);

		// GET /orders/{id} — single order.
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
							'description'       => __( 'Unique identifier for the order.', 'wc-enterprise' ),
							'type'              => 'integer',
							'required'          => true,
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

		// PUT /orders/{id}/status — update order status.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/status',
			[
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_status' ],
					'permission_callback' => [ $this, 'update_status_permissions_check' ],
					'args'                => [
						'id'     => [
							'description'       => __( 'Unique identifier for the order.', 'wc-enterprise' ),
							'type'              => 'integer',
							'required'          => true,
							'validate_callback' => function ( $value ) {
								return is_numeric( $value ) && (int) $value > 0;
							},
							'sanitize_callback' => 'absint',
						],
						'status' => [
							'description'       => __( 'New order status.', 'wc-enterprise' ),
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						],
						'note'   => [
							'description'       => __( 'Optional note to add with the status change.', 'wc-enterprise' ),
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
						],
					],
				],
			]
		);
	}

	/**
	 * Check permissions for listing orders.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error(
				'wcet_rest_forbidden',
				__( 'You do not have permission to view orders.', 'wc-enterprise' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Check permissions for reading a single order.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error(
				'wcet_rest_forbidden',
				__( 'You do not have permission to view this order.', 'wc-enterprise' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Check permissions for creating an order.
	 *
	 * Requires an authenticated user (including API token auth).
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error
	 */
	public function create_item_permissions_check( $request ) {
		if ( ! is_user_logged_in() && 0 === get_current_user_id() ) {
			return new WP_Error(
				'wcet_rest_unauthorized',
				__( 'Authentication is required to create orders.', 'wc-enterprise' ),
				[ 'status' => 401 ]
			);
		}
		return true;
	}

	/**
	 * Check permissions for updating order status.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return true|WP_Error
	 */
	public function update_status_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new WP_Error(
				'wcet_rest_forbidden',
				__( 'You do not have permission to update order status.', 'wc-enterprise' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}
		return true;
	}

	/**
	 * Get a collection of orders.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$per_page = $request->get_param( 'per_page' ) ?: 10;
		$page     = $request->get_param( 'page' ) ?: 1;

		$args = [
			'limit'   => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		];

		// Status filter.
		$status = $request->get_param( 'status' );
		if ( ! empty( $status ) ) {
			$args['status'] = sanitize_text_field( $status );
		}

		// Customer filter.
		$customer = $request->get_param( 'customer' );
		if ( ! empty( $customer ) ) {
			$args['customer_id'] = absint( $customer );
		}

		// Date range filters.
		$date_from = $request->get_param( 'date_from' );
		$date_to   = $request->get_param( 'date_to' );

		if ( ! empty( $date_from ) || ! empty( $date_to ) ) {
			$date_query = '';
			if ( ! empty( $date_from ) && ! empty( $date_to ) ) {
				$date_query = sanitize_text_field( $date_from ) . '...' . sanitize_text_field( $date_to );
			} elseif ( ! empty( $date_from ) ) {
				$date_query = '>=' . sanitize_text_field( $date_from );
			} else {
				$date_query = '<=' . sanitize_text_field( $date_to );
			}
			$args['date_created'] = $date_query;
		}

		$orders = wc_get_orders( $args );

		// Count total for pagination.
		$count_args           = $args;
		$count_args['return'] = 'ids';
		$count_args['limit']  = -1;
		$count_args['offset'] = 0;
		$total_items          = count( wc_get_orders( $count_args ) );
		$total_pages          = (int) ceil( $total_items / $per_page );

		$data = [];
		foreach ( $orders as $order ) {
			$data[] = $this->prepare_order_data( $order );
		}

		$response = rest_ensure_response( $data );
		$response->header( 'X-WP-Total', $total_items );
		$response->header( 'X-WP-TotalPages', $total_pages );

		$this->set_pagination_links( $response, $page, $total_pages );

		return $response;
	}

	/**
	 * Get a single order.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$order_id = (int) $request->get_param( 'id' );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'wcet_rest_order_not_found',
				__( 'Order not found.', 'wc-enterprise' ),
				[ 'status' => 404 ]
			);
		}

		return rest_ensure_response( $this->prepare_order_data( $order ) );
	}

	/**
	 * Create a new order.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$params = $request->get_json_params();

		if ( empty( $params ) ) {
			$params = $request->get_body_params();
		}

		try {
			$order = wc_create_order();

			// Set billing address.
			if ( ! empty( $params['billing'] ) ) {
				$billing_fields = [
					'first_name', 'last_name', 'company', 'address_1',
					'address_2', 'city', 'state', 'postcode', 'country',
					'email', 'phone',
				];
				foreach ( $billing_fields as $field ) {
					if ( isset( $params['billing'][ $field ] ) ) {
						$setter = "set_billing_{$field}";
						if ( method_exists( $order, $setter ) ) {
							$order->$setter( sanitize_text_field( $params['billing'][ $field ] ) );
						}
					}
				}
			}

			// Set shipping address.
			if ( ! empty( $params['shipping'] ) ) {
				$shipping_fields = [
					'first_name', 'last_name', 'company', 'address_1',
					'address_2', 'city', 'state', 'postcode', 'country',
				];
				foreach ( $shipping_fields as $field ) {
					if ( isset( $params['shipping'][ $field ] ) ) {
						$setter = "set_shipping_{$field}";
						if ( method_exists( $order, $setter ) ) {
							$order->$setter( sanitize_text_field( $params['shipping'][ $field ] ) );
						}
					}
				}
			}

			// Add line items.
			if ( ! empty( $params['line_items'] ) && is_array( $params['line_items'] ) ) {
				foreach ( $params['line_items'] as $item_data ) {
					$product_id = absint( $item_data['product_id'] ?? 0 );
					$quantity   = max( 1, absint( $item_data['quantity'] ?? 1 ) );
					$product    = wc_get_product( $product_id );

					if ( ! $product ) {
						return new WP_Error(
							'wcet_rest_invalid_product',
							sprintf(
								/* translators: %d: product ID */
								__( 'Product #%d not found.', 'wc-enterprise' ),
								$product_id
							),
							[ 'status' => 400 ]
						);
					}

					$item = new WC_Order_Item_Product();
					$item->set_product( $product );
					$item->set_quantity( $quantity );

					if ( isset( $item_data['variation_id'] ) ) {
						$item->set_variation_id( absint( $item_data['variation_id'] ) );
					}

					if ( isset( $item_data['subtotal'] ) ) {
						$item->set_subtotal( wc_format_decimal( $item_data['subtotal'] ) );
					} else {
						$item->set_subtotal( wc_format_decimal( (float) $product->get_price() * $quantity ) );
					}

					if ( isset( $item_data['total'] ) ) {
						$item->set_total( wc_format_decimal( $item_data['total'] ) );
					} else {
						$item->set_total( wc_format_decimal( (float) $product->get_price() * $quantity ) );
					}

					$order->add_item( $item );
				}
			}

			// Set payment method.
			if ( ! empty( $params['payment_method'] ) ) {
				$order->set_payment_method( sanitize_text_field( $params['payment_method'] ) );
			}
			if ( ! empty( $params['payment_method_title'] ) ) {
				$order->set_payment_method_title( sanitize_text_field( $params['payment_method_title'] ) );
			}

			// Set customer.
			if ( ! empty( $params['customer_id'] ) ) {
				$order->set_customer_id( absint( $params['customer_id'] ) );
			}

			// Set currency.
			if ( ! empty( $params['currency'] ) ) {
				$order->set_currency( sanitize_text_field( $params['currency'] ) );
			}

			// Set customer note.
			if ( ! empty( $params['customer_note'] ) ) {
				$order->set_customer_note( sanitize_textarea_field( $params['customer_note'] ) );
			}

			// Calculate totals.
			$order->calculate_totals();

			// Set initial status.
			$status = ! empty( $params['status'] ) ? sanitize_text_field( $params['status'] ) : 'pending';
			$order->set_status( $status );

			$order->save();

			// Add order note.
			$order->add_order_note(
				__( 'Order created via WCET REST API.', 'wc-enterprise' ),
				false,
				true
			);

			/**
			 * Fires after an order is created via the WCET REST API.
			 *
			 * @param WC_Order        $order   The created order.
			 * @param WP_REST_Request $request The request object.
			 */
			do_action( 'wcet_rest_order_created', $order, $request );

			$response = rest_ensure_response( $this->prepare_order_data( $order ) );
			$response->set_status( 201 );
			$response->header(
				'Location',
				rest_url( sprintf( '%s/%s/%d', $this->namespace, $this->rest_base, $order->get_id() ) )
			);

			return $response;

		} catch ( Exception $e ) {
			return new WP_Error(
				'wcet_rest_order_creation_failed',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}
	}

	/**
	 * Update an order's status.
	 *
	 * Validates the transition against WCET_Order_Workflow allowed transitions.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_status( $request ) {
		$order_id   = (int) $request->get_param( 'id' );
		$new_status = sanitize_text_field( $request->get_param( 'status' ) );
		$note       = sanitize_textarea_field( $request->get_param( 'note' ) ?? '' );

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'wcet_rest_order_not_found',
				__( 'Order not found.', 'wc-enterprise' ),
				[ 'status' => 404 ]
			);
		}

		$current_status = $order->get_status();

		// Strip the wc- prefix from the new status if present.
		$new_status_clean = str_replace( 'wc-', '', $new_status );

		// If the status is already set, return success without changes.
		if ( $current_status === $new_status_clean ) {
			return rest_ensure_response( $this->prepare_order_data( $order ) );
		}

		// Validate transition against WCET_Order_Workflow if available.
		if ( class_exists( 'WCET_Order_Workflow' ) ) {
			$workflow            = WCET_Order_Workflow::instance();
			$allowed_transitions = $workflow->get_allowed_transitions( $order );

			// Only enforce custom transitions for custom statuses that have defined transitions.
			if ( ! empty( $allowed_transitions ) && ! in_array( $new_status_clean, $allowed_transitions, true ) ) {
				// Also check standard WooCommerce status transitions as a fallback.
				$standard_statuses = [
					'pending', 'processing', 'on-hold', 'completed',
					'cancelled', 'refunded', 'failed',
				];

				if ( ! in_array( $new_status_clean, $standard_statuses, true ) ) {
					return new WP_Error(
						'wcet_rest_invalid_status_transition',
						sprintf(
							/* translators: 1: current status 2: new status 3: allowed statuses */
							__( 'Cannot transition from "%1$s" to "%2$s". Allowed transitions: %3$s', 'wc-enterprise' ),
							$current_status,
							$new_status_clean,
							implode( ', ', $allowed_transitions )
						),
						[ 'status' => 422 ]
					);
				}
			}
		}

		// Build the status change note.
		$status_note = ! empty( $note )
			? sprintf(
				/* translators: 1: new status 2: note */
				__( 'Status updated to %1$s via WCET API. Note: %2$s', 'wc-enterprise' ),
				$new_status_clean,
				$note
			)
			: sprintf(
				/* translators: %s: new status */
				__( 'Status updated to %s via WCET API.', 'wc-enterprise' ),
				$new_status_clean
			);

		$order->update_status( $new_status_clean, $status_note );

		/**
		 * Fires after an order status is updated via the WCET REST API.
		 *
		 * @param WC_Order $order      The order.
		 * @param string   $old_status The old status.
		 * @param string   $new_status The new status.
		 */
		do_action( 'wcet_rest_order_status_updated', $order, $current_status, $new_status_clean );

		return rest_ensure_response( $this->prepare_order_data( $order ) );
	}

	/**
	 * Prepare a single order for the REST response.
	 *
	 * @param WC_Order $order Order object.
	 * @return array
	 */
	private function prepare_order_data( WC_Order $order ): array {
		// Line items.
		$line_items = [];
		foreach ( $order->get_items() as $item_id => $item ) {
			$product      = $item->get_product();
			$line_items[] = [
				'id'           => $item_id,
				'product_id'   => $item->get_product_id(),
				'variation_id' => $item->get_variation_id(),
				'name'         => $item->get_name(),
				'quantity'     => $item->get_quantity(),
				'subtotal'     => $item->get_subtotal(),
				'total'        => $item->get_total(),
				'tax'          => $item->get_total_tax(),
				'sku'          => $product ? $product->get_sku() : '',
				'price'        => $product ? $product->get_price() : '',
			];
		}

		// Shipping lines.
		$shipping_lines = [];
		foreach ( $order->get_shipping_methods() as $shipping_id => $shipping ) {
			$shipping_lines[] = [
				'id'          => $shipping_id,
				'method_id'   => $shipping->get_method_id(),
				'method_title' => $shipping->get_method_title(),
				'total'       => $shipping->get_total(),
			];
		}

		// Order notes.
		$notes      = [];
		$raw_notes  = wc_get_order_notes( [
			'order_id' => $order->get_id(),
			'limit'    => 20,
			'orderby'  => 'date_created',
			'order'    => 'DESC',
		] );

		foreach ( $raw_notes as $note ) {
			$notes[] = [
				'id'               => $note->id,
				'content'          => $note->content,
				'date_created'     => $note->date_created ? $note->date_created->date( 'Y-m-d\TH:i:s' ) : null,
				'customer_note'    => $note->customer_note,
				'added_by'         => $note->added_by,
			];
		}

		// Billing address.
		$billing = [
			'first_name' => $order->get_billing_first_name(),
			'last_name'  => $order->get_billing_last_name(),
			'company'    => $order->get_billing_company(),
			'address_1'  => $order->get_billing_address_1(),
			'address_2'  => $order->get_billing_address_2(),
			'city'       => $order->get_billing_city(),
			'state'      => $order->get_billing_state(),
			'postcode'   => $order->get_billing_postcode(),
			'country'    => $order->get_billing_country(),
			'email'      => $order->get_billing_email(),
			'phone'      => $order->get_billing_phone(),
		];

		// Shipping address.
		$shipping_address = [
			'first_name' => $order->get_shipping_first_name(),
			'last_name'  => $order->get_shipping_last_name(),
			'company'    => $order->get_shipping_company(),
			'address_1'  => $order->get_shipping_address_1(),
			'address_2'  => $order->get_shipping_address_2(),
			'city'       => $order->get_shipping_city(),
			'state'      => $order->get_shipping_state(),
			'postcode'   => $order->get_shipping_postcode(),
			'country'    => $order->get_shipping_country(),
		];

		// Customer info.
		$customer_id = $order->get_customer_id();
		$customer    = [
			'id'    => $customer_id,
			'email' => $order->get_billing_email(),
			'name'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
		];

		if ( $customer_id > 0 ) {
			$user = get_userdata( $customer_id );
			if ( $user ) {
				$customer['username'] = $user->user_login;
			}
		}

		return [
			'id'                   => $order->get_id(),
			'number'               => $order->get_order_number(),
			'status'               => $order->get_status(),
			'total'                => $order->get_total(),
			'subtotal'             => $order->get_subtotal(),
			'total_tax'            => $order->get_total_tax(),
			'discount_total'       => $order->get_discount_total(),
			'shipping_total'       => $order->get_shipping_total(),
			'currency'             => $order->get_currency(),
			'customer'             => $customer,
			'billing'              => $billing,
			'shipping'             => $shipping_address,
			'line_items'           => $line_items,
			'shipping_lines'       => $shipping_lines,
			'payment_method'       => $order->get_payment_method(),
			'payment_method_title' => $order->get_payment_method_title(),
			'transaction_id'       => $order->get_transaction_id(),
			'date_created'         => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d\TH:i:s' ) : null,
			'date_modified'        => $order->get_date_modified() ? $order->get_date_modified()->date( 'Y-m-d\TH:i:s' ) : null,
			'date_completed'       => $order->get_date_completed() ? $order->get_date_completed()->date( 'Y-m-d\TH:i:s' ) : null,
			'date_paid'            => $order->get_date_paid() ? $order->get_date_paid()->date( 'Y-m-d\TH:i:s' ) : null,
			'customer_note'        => $order->get_customer_note(),
			'notes'                => $notes,
		];
	}

	/**
	 * Set pagination link headers on the response.
	 *
	 * @param WP_REST_Response $response    The response object.
	 * @param int              $page        Current page.
	 * @param int              $total_pages Total pages.
	 * @return void
	 */
	private function set_pagination_links( WP_REST_Response $response, int $page, int $total_pages ): void {
		$base_url = rest_url( sprintf( '%s/%s', $this->namespace, $this->rest_base ) );
		$links    = [];

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
	 * Get the query params for collections.
	 *
	 * @return array
	 */
	public function get_collection_params(): array {
		return [
			'page'      => [
				'description'       => __( 'Current page of the collection.', 'wc-enterprise' ),
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'per_page'  => [
				'description'       => __( 'Maximum number of items to be returned per page.', 'wc-enterprise' ),
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'status'    => [
				'description'       => __( 'Limit results to orders with a specific status.', 'wc-enterprise' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'customer'  => [
				'description'       => __( 'Limit results to orders placed by a specific customer ID.', 'wc-enterprise' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			],
			'date_from' => [
				'description'       => __( 'Limit results to orders created after this date (YYYY-MM-DD).', 'wc-enterprise' ),
				'type'              => 'string',
				'format'            => 'date',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $value ) {
					return empty( $value ) || (bool) strtotime( $value );
				},
			],
			'date_to'   => [
				'description'       => __( 'Limit results to orders created before this date (YYYY-MM-DD).', 'wc-enterprise' ),
				'type'              => 'string',
				'format'            => 'date',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function ( $value ) {
					return empty( $value ) || (bool) strtotime( $value );
				},
			],
		];
	}

	/**
	 * Get the parameters for creating an order.
	 *
	 * @return array
	 */
	private function get_create_params(): array {
		return [
			'billing'              => [
				'description' => __( 'Billing address.', 'wc-enterprise' ),
				'type'        => 'object',
				'properties'  => [
					'first_name' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'last_name'  => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'company'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'address_1'  => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'address_2'  => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'city'       => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'state'      => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'postcode'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'country'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'email'      => [ 'type' => 'string', 'format' => 'email', 'sanitize_callback' => 'sanitize_email' ],
					'phone'      => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
			'shipping'             => [
				'description' => __( 'Shipping address.', 'wc-enterprise' ),
				'type'        => 'object',
				'properties'  => [
					'first_name' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'last_name'  => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'company'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'address_1'  => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'address_2'  => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'city'       => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'state'      => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'postcode'   => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
					'country'    => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
			'line_items'           => [
				'description' => __( 'Line items for the order.', 'wc-enterprise' ),
				'type'        => 'array',
				'required'    => true,
				'items'       => [
					'type'       => 'object',
					'properties' => [
						'product_id'   => [ 'type' => 'integer', 'required' => true, 'sanitize_callback' => 'absint' ],
						'quantity'     => [ 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ],
						'variation_id' => [ 'type' => 'integer', 'sanitize_callback' => 'absint' ],
						'subtotal'     => [ 'type' => 'string' ],
						'total'        => [ 'type' => 'string' ],
					],
				],
			],
			'payment_method'       => [
				'description'       => __( 'Payment method ID.', 'wc-enterprise' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'payment_method_title' => [
				'description'       => __( 'Payment method title.', 'wc-enterprise' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'customer_id'          => [
				'description'       => __( 'Customer user ID.', 'wc-enterprise' ),
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'customer_note'        => [
				'description'       => __( 'Note left by the customer.', 'wc-enterprise' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			],
			'currency'             => [
				'description'       => __( 'Currency code (ISO 4217).', 'wc-enterprise' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'status'               => [
				'description'       => __( 'Order status.', 'wc-enterprise' ),
				'type'              => 'string',
				'default'           => 'pending',
				'sanitize_callback' => 'sanitize_text_field',
			],
		];
	}

	/**
	 * Get the order schema, conforming to JSON Schema.
	 *
	 * @return array
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = [
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'wcet-order',
			'type'       => 'object',
			'properties' => [
				'id'                   => [
					'description' => __( 'Unique identifier for the order.', 'wc-enterprise' ),
					'type'        => 'integer',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
				'number'               => [
					'description' => __( 'Order number.', 'wc-enterprise' ),
					'type'        => 'string',
					'context'     => [ 'view' ],
					'readonly'    => true,
				],
				'status'               => [
					'description' => __( 'Order status.', 'wc-enterprise' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
				],
				'total'                => [
					'description' => __( 'Order total.', 'wc-enterprise' ),
					'type'        => 'string',
					'context'     => [ 'view' ],
					'readonly'    => true,
				],
				'currency'             => [
					'description' => __( 'Currency code.', 'wc-enterprise' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
				],
				'customer'             => [
					'description' => __( 'Customer information.', 'wc-enterprise' ),
					'type'        => 'object',
					'context'     => [ 'view' ],
				],
				'billing'              => [
					'description' => __( 'Billing address.', 'wc-enterprise' ),
					'type'        => 'object',
					'context'     => [ 'view', 'edit' ],
				],
				'shipping'             => [
					'description' => __( 'Shipping address.', 'wc-enterprise' ),
					'type'        => 'object',
					'context'     => [ 'view', 'edit' ],
				],
				'line_items'           => [
					'description' => __( 'Line items.', 'wc-enterprise' ),
					'type'        => 'array',
					'context'     => [ 'view', 'edit' ],
				],
				'payment_method'       => [
					'description' => __( 'Payment method ID.', 'wc-enterprise' ),
					'type'        => 'string',
					'context'     => [ 'view', 'edit' ],
				],
				'payment_method_title' => [
					'description' => __( 'Payment method title.', 'wc-enterprise' ),
					'type'        => 'string',
					'context'     => [ 'view' ],
				],
				'date_created'         => [
					'description' => __( 'Date created (ISO 8601).', 'wc-enterprise' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => [ 'view' ],
					'readonly'    => true,
				],
				'date_modified'        => [
					'description' => __( 'Date modified (ISO 8601).', 'wc-enterprise' ),
					'type'        => 'string',
					'format'      => 'date-time',
					'context'     => [ 'view' ],
					'readonly'    => true,
				],
				'notes'                => [
					'description' => __( 'Order notes.', 'wc-enterprise' ),
					'type'        => 'array',
					'context'     => [ 'view' ],
					'readonly'    => true,
				],
			],
		];

		return $this->add_additional_fields_schema( $this->schema );
	}
}

WCET_REST_Orders::instance();
