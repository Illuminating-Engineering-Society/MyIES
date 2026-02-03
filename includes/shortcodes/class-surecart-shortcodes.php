<?php
/**
 * SureCart Shortcodes
 *
 * Provides shortcodes for displaying SureCart order history and product information.
 *
 * Usage:
 *   [surecart_orders_history] - Display order history for logged-in user
 *   [surecart_products_info]  - Display purchased products (excluding memberships/subscriptions)
 *
 * @package MyIES_Integration
 * @since 1.0.5
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class MyIES_SureCart_Shortcodes
 *
 * Handles SureCart-related shortcodes for the MyIES Integration plugin.
 *
 * @since 1.0.5
 */
class MyIES_SureCart_Shortcodes {

	/**
	 * Singleton instance.
	 *
	 * @var MyIES_SureCart_Shortcodes|null
	 */
	private static $instance = null;

	/**
	 * Membership product IDs to exclude from products display.
	 * These are products that represent memberships rather than standalone purchases.
	 *
	 * @var array
	 */
	private $excluded_product_ids = array(
		'99014429-e2b1-4e83-808a-d6b43d3949b7', // Member Grade
		'6ad2ba6c-4e93-4e19-9c83-8c35803f8f27', // Student
		'76e203ae-c9c5-4302-9eff-d5f58d534219', // Emerging Professional
	);

	/**
	 * Get singleton instance.
	 *
	 * @since 1.0.5
	 * @return MyIES_SureCart_Shortcodes
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.5
	 */
	private function __construct() {
		add_shortcode( 'surecart_orders_history', array( $this, 'render_orders_history' ) );
		add_shortcode( 'surecart_products_info', array( $this, 'render_products_info' ) );
	}

	/**
	 * Shortcode: [surecart_orders_history]
	 *
	 * Displays the logged-in user's SureCart order history.
	 *
	 * Features:
	 * - Retrieves the SureCart customer using the WordPress user's email.
	 * - Loads only the orders that belong to the current customer.
	 * - Expands checkout and line items to extract product information.
	 * - Makes the order reference clickable when an invoice PDF is available.
	 * - Sanitizes all output for security.
	 *
	 * @since 1.0.5
	 * @param array $atts Shortcode attributes (unused).
	 * @return string HTML markup containing the order history.
	 */
	public function render_orders_history( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to view your orders.', 'wicket-integration' ) . '</p>';
		}

		if ( ! class_exists( '\SureCart\Models\Customer' ) ) {
			return '<p>' . esc_html__( 'SureCart plugin is required.', 'wicket-integration' ) . '</p>';
		}

		try {
			$current_user = wp_get_current_user();

			if ( empty( $current_user->user_email ) ) {
				return '<p>' . esc_html__( 'Invalid user email.', 'wicket-integration' ) . '</p>';
			}

			$customer = \SureCart\Models\Customer::where(
				array(
					'email' => $current_user->user_email,
				)
			)->first();

			if ( ! $customer || ! isset( $customer->id ) ) {
				return '<p>' . esc_html__( 'Customer not found.', 'wicket-integration' ) . '</p>';
			}

			$orders = \SureCart\Models\Order::where(
				array(
					'customer_ids' => array( $customer->id ),
					'expand'       => array( 'checkout', 'checkout.line_items' ),
				)
			)->get();

			if ( empty( $orders ) || ! is_array( $orders ) ) {
				return '<p>' . esc_html__( 'No orders found.', 'wicket-integration' ) . '</p>';
			}

			ob_start();
			?>
			<div class="brxe-block membership-info">
				<div class="brxe-block func-head-viewall">
					<h3 class="brxe-heading current-section">
						<?php esc_html_e( 'Order History', 'wicket-integration' ); ?>
					</h3>
				</div>

				<div class="brxe-divider dash-divider horizontal">
					<div class="line"></div>
				</div>

				<?php foreach ( $orders as $order ) : ?>
					<?php
					$date = isset( $order->created_at )
						? date_i18n( get_option( 'date_format' ), $order->created_at )
						: __( 'N/A', 'wicket-integration' );

					$amount = isset( $order->checkout->total_amount )
						? $order->checkout->total_amount / 100
						: 0;

					$formatted_amount = number_format( $amount, 2, '.', ',' );

					$order_number = isset( $order->number )
						? $order->number
						: __( 'N/A', 'wicket-integration' );

					$pdf_url = isset( $order->pdf_url ) ? $order->pdf_url : '';

					$product_name = __( 'Product', 'wicket-integration' );
					$products     = array();

					if ( isset( $order->checkout->line_items->data ) && is_array( $order->checkout->line_items->data ) ) {
						foreach ( $order->checkout->line_items->data as $item ) {
							if ( ! empty( $item->price ) && is_string( $item->price ) ) {
								try {
									$price = \SureCart\Models\Price::with( array( 'product' ) )->find( $item->price );

									if ( isset( $price->product->name ) ) {
										$products[] = $price->product->name;
									} elseif ( isset( $price->name ) ) {
										$products[] = $price->name;
									}
								} catch ( \Exception $e ) {
									continue;
								}
							}
						}
					}

					if ( ! empty( $products ) ) {
						$product_name = implode( ', ', $products );
					}

					$status_map = array(
						'paid'       => __( 'Paid', 'wicket-integration' ),
						'processing' => __( 'Processing', 'wicket-integration' ),
						'completed'  => __( 'Completed', 'wicket-integration' ),
						'canceled'   => __( 'Canceled', 'wicket-integration' ),
						'refunded'   => __( 'Refunded', 'wicket-integration' ),
					);

					$order_status = $order->status ?? 'unknown';
					$status       = $status_map[ $order_status ] ?? ucfirst( $order_status );
					$status_class = 'status-' . sanitize_html_class( $order->status );
					?>

					<div class="brxe-block order-hist-grid" data-order-id="<?php echo esc_attr( $order->id ?? '' ); ?>">

						<!-- Order Date -->
						<div class="brxe-block ord-hist-cont">
							<div class="brxe-text-basic ord-hist-text">
								<?php echo esc_html( $date ); ?>
							</div>
						</div>

						<!-- Clickable Order Reference -->
						<div class="brxe-block ord-hist-cont">
							<div class="brxe-text-basic ord-hist-text">
								<?php if ( ! empty( $pdf_url ) ) : ?>
									<a href="<?php echo esc_url( $pdf_url ); ?>"
									   target="_blank"
									   class="ord-hist-ref-link"
									   title="<?php esc_attr_e( 'View Invoice', 'wicket-integration' ); ?>">
										<?php echo esc_html( $order_number ); ?>
									</a>
								<?php else : ?>
									<?php echo esc_html( $order_number ); ?>
								<?php endif; ?>
							</div>
						</div>

						<!-- Product -->
						<div class="brxe-block ord-hist-cont">
							<div class="brxe-text-basic ord-hist-text"
								 title="<?php echo esc_attr( $product_name ); ?>">
								<?php echo esc_html( $product_name ); ?>
							</div>
						</div>

						<!-- Status -->
						<div class="brxe-block ord-hist-cont">
							<div class="brxe-text-basic dash-subs-status <?php echo esc_attr( $status_class ); ?>">
								<?php echo esc_html( $status ); ?>
							</div>
						</div>

						<!-- Total -->
						<div class="brxe-block ord-hist-cont">
							<div class="brxe-text-basic ord-hist-text">
								$<?php echo esc_html( $formatted_amount ); ?>
							</div>
						</div>

					</div>

				<?php endforeach; ?>
			</div>
			<?php

			return ob_get_clean();

		} catch ( \Exception $e ) {
			if ( function_exists( 'myies_log' ) ) {
				myies_log( 'SureCart Orders Error: ' . $e->getMessage(), 'surecart-shortcodes' );
			}

			return '<p>' . esc_html__( 'Unable to load orders. Please try again later.', 'wicket-integration' ) . '</p>';
		}
	}

	/**
	 * Shortcode: [surecart_products_info]
	 *
	 * Displays the logged-in user's purchased products (excluding memberships and subscriptions).
	 *
	 * Features:
	 * - Shows one-time purchased products with expiration dates
	 * - Excludes membership products and active subscriptions
	 * - Displays product status (Active/Expired)
	 * - Includes "Renew" button for each product
	 * - Always shows the "Products" header even when no products exist
	 *
	 * @since 1.0.5
	 * @param array $atts Shortcode attributes (unused).
	 * @return string HTML markup containing the products info.
	 */
	public function render_products_info( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '<p>' . esc_html__( 'You must be logged in to view your products.', 'wicket-integration' ) . '</p>';
		}

		if ( ! class_exists( '\SureCart\Models\Customer' ) ) {
			return '<p>' . esc_html__( 'SureCart plugin is required.', 'wicket-integration' ) . '</p>';
		}

		try {
			$current_user = wp_get_current_user();

			if ( empty( $current_user->user_email ) ) {
				return '<p>' . esc_html__( 'Invalid user email.', 'wicket-integration' ) . '</p>';
			}

			$customer = \SureCart\Models\Customer::where(
				array(
					'email' => $current_user->user_email,
				)
			)->first();

			if ( ! $customer || ! isset( $customer->id ) ) {
				return '<p>' . esc_html__( 'Customer not found.', 'wicket-integration' ) . '</p>';
			}

			$orders = \SureCart\Models\Order::where(
				array(
					'customer_ids' => array( $customer->id ),
					'status'       => array( 'paid', 'processing', 'completed' ),
					'expand'       => array( 'checkout', 'checkout.line_items' ),
				)
			)->get();

			$active_subscription_product_ids = array();
			$subscriptions                   = \SureCart\Models\Subscription::where(
				array(
					'customer_ids' => array( $customer->id ),
					'status'       => array( 'active', 'trialing' ),
					'expand'       => array( 'price', 'price.product' ),
				)
			)->get();

			if ( ! empty( $subscriptions ) && is_array( $subscriptions ) ) {
				foreach ( $subscriptions as $subscription ) {
					if ( isset( $subscription->price->product->id ) ) {
						$active_subscription_product_ids[] = $subscription->price->product->id;
					}
				}
			}

			$products_data = array();

			if ( ! empty( $orders ) && is_array( $orders ) ) {
				foreach ( $orders as $order ) {
					if ( isset( $order->checkout->line_items->data ) && is_array( $order->checkout->line_items->data ) ) {
						foreach ( $order->checkout->line_items->data as $item ) {
							if ( ! empty( $item->price ) && is_string( $item->price ) ) {
								try {
									$price = \SureCart\Models\Price::with( array( 'product' ) )->find( $item->price );

									if ( ! isset( $price->product->id ) ) {
										continue;
									}

									$product_id = $price->product->id;

									if ( in_array( $product_id, $this->excluded_product_ids, true ) ) {
										continue;
									}

									if ( in_array( $product_id, $active_subscription_product_ids, true ) ) {
										continue;
									}

									if ( ! isset( $products_data[ $product_id ] ) ||
										$order->created_at > $products_data[ $product_id ]['purchased_at'] ) {

										$products_data[ $product_id ] = array(
											'name'         => $price->product->name ?? __( 'Product', 'wicket-integration' ),
											'description'  => $price->product->description ?? '',
											'purchased_at' => $order->created_at,
											'price_id'     => $item->price,
											'product_id'   => $product_id,
										);
									}
								} catch ( \Exception $e ) {
									continue;
								}
							}
						}
					}
				}
			}

			ob_start();
			?>
			<div class="brxe-block membership-info">
				<div class="brxe-block func-head-viewall">
					<h3 class="brxe-heading current-section">
						<?php esc_html_e( 'Products', 'wicket-integration' ); ?>
					</h3>
				</div>

				<div class="brxe-block dash-func-container">
					<?php if ( empty( $products_data ) ) : ?>
						<div class="brxe-block grid-subs">
							<div class="brxe-block ins-grid-desc">
								<div class="brxe-text-basic subs-desc">
									<?php esc_html_e( 'No products found.', 'wicket-integration' ); ?>
								</div>
							</div>
						</div>
					<?php else : ?>
						<?php foreach ( $products_data as $product ) : ?>
							<?php
							$expiration_period    = apply_filters( 'myies_surecart_product_expiration_period', '+1 year', $product );
							$expiration_timestamp = strtotime( $expiration_period, $product['purchased_at'] );
							$expiration_date      = date_i18n( get_option( 'date_format' ), $expiration_timestamp );

							$is_active    = $expiration_timestamp >= time();
							$status       = $is_active ? __( 'Active', 'wicket-integration' ) : __( 'Expired', 'wicket-integration' );
							$status_class = $is_active ? 'active-status-sub' : 'expired-status-sub';

							$description = ! empty( $product['description'] )
								? wp_trim_words( $product['description'], 10, '...' )
								: __( 'Product access and benefits', 'wicket-integration' );

							$checkout_url = apply_filters( 'myies_surecart_checkout_url', home_url( '/checkout/' ) );
							$renew_url    = add_query_arg(
								array(
									'product_id' => $product['product_id'],
									'renew'      => '1',
								),
								$checkout_url
							);
							?>

							<div class="brxe-block grid-subs" data-product-id="<?php echo esc_attr( $product['product_id'] ); ?>">

								<!-- Product Name & Status -->
								<div class="brxe-block ins-grid-desc">
									<div class="brxe-block subs-stat">
										<h3 class="brxe-heading inside-func">
											<?php echo esc_html( $product['name'] ); ?>
										</h3>
										<span class="brxe-text-link <?php echo esc_attr( $status_class ); ?>">
											<span class="icon"><i class="fas fa-circle"></i></span>
											<span class="text"><?php echo esc_html( $status ); ?></span>
										</span>
									</div>
									<div class="brxe-text-basic subs-desc">
										<?php echo esc_html( $description ); ?>
									</div>
								</div>

								<!-- Expiration Date -->
								<div class="brxe-block ins-grid-desc">
									<div class="brxe-block subs-stat">
										<h3 class="brxe-heading inside-func">
											<?php esc_html_e( 'Expires', 'wicket-integration' ); ?>
										</h3>
									</div>
									<div class="brxe-text-basic subs-desc">
										<?php echo esc_html( $expiration_date ); ?>
									</div>
								</div>

								<!-- Renew Button -->
								<div class="brxe-block ins-grid-desc">
									<div class="brxe-block subs-stat">
										<a class="brxe-button change-org-btn bricks-button bricks-background-primary"
										   href="<?php echo esc_url( $renew_url ); ?>">
											<?php esc_html_e( 'Renew', 'wicket-integration' ); ?>
										</a>
									</div>
								</div>

							</div>

						<?php endforeach; ?>
					<?php endif; ?>
				</div>
			</div>
			<?php

			return ob_get_clean();

		} catch ( \Exception $e ) {
			if ( function_exists( 'myies_log' ) ) {
				myies_log( 'SureCart Products Error: ' . $e->getMessage(), 'surecart-shortcodes' );
			}

			return '<p>' . esc_html__( 'Unable to load products. Please try again later.', 'wicket-integration' ) . '</p>';
		}
	}
}

/**
 * Initialize SureCart Shortcodes.
 *
 * @since 1.0.5
 * @return void
 */
function myies_init_surecart_shortcodes() {
	MyIES_SureCart_Shortcodes::get_instance();
}
add_action( 'init', 'myies_init_surecart_shortcodes' );