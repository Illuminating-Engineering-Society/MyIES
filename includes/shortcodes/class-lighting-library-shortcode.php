<?php
/**
 * Product Shortcodes
 *
 * Shortcodes for displaying product-related information.
 *
 * Usage:
 *   [myies_lighting_library] - Lighting Library Full Access membership status
 *   [myies_user_products]    - Purchased products (excluding membership collections)
 *
 * @package MyIES_Integration
 * @since 1.0.17
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lighting_Library_Shortcode {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('myies_lighting_library', [$this, 'render_shortcode']);
        add_action('wp_head', [$this, 'print_styles']);
    }

    /**
     * Print inline styles for product grid shortcodes.
     */
    public function print_styles() {
        ?>
        <style id="myies-product-grid-styles">
            /* Active status badge - green */
            .grid-subs .active-status-sub .icon i { color: #10b981; }
            .grid-subs .active-status-sub .text { color: #059669; }

            /* Expired/inactive status badge - gray */
            .grid-subs .expired-status-sub .icon i { color: #9ca3af; }
            .grid-subs .expired-status-sub .text { color: #6b7280; }

            /* Renew button */
            .grid-subs .change-org-btn {
                background-color: #f28c00 !important;
                color: #000 !important;
                border-radius: 4px !important;
                text-decoration: none !important;
            }
            .grid-subs .change-org-btn:hover {
                opacity: 0.9;
                text-decoration: none !important;
            }
        </style>
        <?php
    }

    /**
     * Render the shortcode
     */
    public function render_shortcode($atts) {
        $user_id = get_current_user_id();

        if (!$user_id) {
            return '<p class="wicket-membership-message">Please log in to view your Lighting Library membership.</p>';
        }

        $memberships = wicket_get_user_memberships($user_id);
        $match = $this->find_lighting_library_membership($memberships);

        if (!$match) {
            return '<div class="brxe-block membership-info">
                <div class="brxe-block func-head-viewall">
                    <h3 class="brxe-heading current-section">' . esc_html__('Lighting Library', 'wicket-integration') . '</h3>
                </div>
                <div class="brxe-block dash-func-container">
                    <div class="brxe-block grid-subs">
                        <div class="brxe-block ins-grid-desc">
                            <div class="brxe-text-basic subs-desc">' . esc_html__('You do not currently have a Lighting Library membership.', 'wicket-integration') . '</div>
                        </div>
                    </div>
                </div>
            </div>';
        }

        return $this->render_card($match);
    }

    /**
     * Find the Lighting Library Full Access membership from the user's memberships
     */
    private function find_lighting_library_membership($memberships) {
        if (empty($memberships)) {
            return null;
        }

        foreach ($memberships as $m) {
            $slug = sanitize_title($m['membership_tier_name']);
            if ($slug === 'lighting-library-full-access') {
                return $m;
            }
        }

        return null;
    }

    /**
     * Render the membership card
     */
    private function render_card($m) {
        $status       = $m['status'];
        $status_lower = strtolower($status);
        $is_active    = $status_lower === 'active';
        $status_class = $is_active ? 'active-status-sub' : 'expired-status-sub';

        $renew_url = apply_filters('myies_lighting_library_renew_url', home_url('/checkout/'));

        ob_start();
        ?>
        <div class="brxe-block membership-info">
            <div class="brxe-block func-head-viewall">
                <h3 class="brxe-heading current-section">
                    <?php esc_html_e('Lighting Library', 'wicket-integration'); ?>
                </h3>
            </div>

            <div class="brxe-block dash-func-container">
                <div class="brxe-block grid-subs">

                    <!-- Name & Status -->
                    <div class="brxe-block ins-grid-desc">
                        <div class="brxe-block subs-stat">
                            <h3 class="brxe-heading inside-func">
                                <?php echo esc_html($m['membership_tier_name'] ?: 'Lighting Library Full Access'); ?>
                            </h3>
                            <span class="brxe-text-link <?php echo esc_attr($status_class); ?>">
                                <span class="icon"><i class="fas fa-circle"></i></span>
                                <span class="text"><?php echo esc_html($status); ?></span>
                            </span>
                        </div>
                        <div class="brxe-text-basic subs-desc">
                            <?php echo esc_html($m['starts_formatted']); ?> &ndash; <?php echo esc_html($m['expires_formatted']); ?>
                        </div>
                    </div>

                    <!-- Expiration Date -->
                    <div class="brxe-block ins-grid-desc">
                        <div class="brxe-block subs-stat">
                            <h3 class="brxe-heading inside-func">
                                <?php esc_html_e('Expires', 'wicket-integration'); ?>
                            </h3>
                        </div>
                        <div class="brxe-text-basic subs-desc">
                            <?php echo esc_html($m['expires_formatted']); ?>
                        </div>
                    </div>

                    <!-- Renew Button -->
                    <div class="brxe-block ins-grid-desc">
                        <div class="brxe-block subs-stat">
                            <a class="brxe-button change-org-btn bricks-button bricks-background-primary"
                               href="<?php echo esc_url($renew_url); ?>">
                                <?php esc_html_e('Renew', 'wicket-integration'); ?>
                            </a>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Initialize
add_action('init', function() {
    Lighting_Library_Shortcode::get_instance();
});

/**
 * User Products Shortcode
 *
 * Displays the current user's purchased SureCart products, excluding
 * products in the "membership" and "sustaining-membership" collections.
 *
 * Usage:
 *   [myies_user_products]
 *
 * @package MyIES_Integration
 * @since 1.0.18
 */
class MyIES_User_Products_Shortcode {

    private static $instance = null;

    /**
     * Collection slugs to exclude from product display.
     *
     * @var array
     */
    private $excluded_collection_slugs = array(
        'membership',
        'sustaining-membership',
    );

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('myies_user_products', [$this, 'render_shortcode']);
    }

    /**
     * Render the [myies_user_products] shortcode.
     */
    public function render_shortcode($atts) {
        if (!is_user_logged_in()) {
            return '<p>' . esc_html__('You must be logged in to view your products.', 'wicket-integration') . '</p>';
        }

        if (!class_exists('\SureCart\Models\Customer')) {
            return '<p>' . esc_html__('SureCart plugin is required.', 'wicket-integration') . '</p>';
        }

        try {
            $current_user = wp_get_current_user();

            if (empty($current_user->user_email)) {
                return '<p>' . esc_html__('Invalid user email.', 'wicket-integration') . '</p>';
            }

            // Find SureCart customer by email.
            $customer = \SureCart\Models\Customer::where([
                'email' => $current_user->user_email,
            ])->first();

            if (!$customer || !isset($customer->id)) {
                return '<p>' . esc_html__('Customer not found.', 'wicket-integration') . '</p>';
            }

            // Get excluded product IDs from membership collections.
            $excluded_product_ids = $this->get_excluded_product_ids();

            // Also exclude products with active subscriptions.
            $active_subscription_product_ids = [];
            $subscriptions = \SureCart\Models\Subscription::where([
                'customer_ids' => [$customer->id],
                'status'       => ['active', 'trialing'],
                'expand'       => ['price', 'price.product'],
            ])->get();

            if (!empty($subscriptions) && is_array($subscriptions)) {
                foreach ($subscriptions as $subscription) {
                    if (isset($subscription->price->product->id)) {
                        $active_subscription_product_ids[] = $subscription->price->product->id;
                    }
                }
            }

            // Fetch orders (same approach as the working orders shortcode).
            $orders = \SureCart\Models\Order::where([
                'customer_ids' => [$customer->id],
                'expand'       => ['checkout', 'checkout.line_items'],
            ])->get();

            // Extract products from order line items.
            $products_data = [];

            if (!empty($orders) && is_array($orders)) {
                foreach ($orders as $order) {
                    if (!isset($order->checkout->line_items->data) || !is_array($order->checkout->line_items->data)) {
                        continue;
                    }

                    foreach ($order->checkout->line_items->data as $item) {
                        if (empty($item->price) || !is_string($item->price)) {
                            continue;
                        }

                        try {
                            $price = \SureCart\Models\Price::with(['product'])->find($item->price);

                            if (!isset($price->product->id)) {
                                continue;
                            }

                            $product_id = $price->product->id;

                            // Skip excluded (membership collection) products.
                            if (in_array($product_id, $excluded_product_ids, true)) {
                                continue;
                            }

                            // Skip active subscription products.
                            if (in_array($product_id, $active_subscription_product_ids, true)) {
                                continue;
                            }

                            // Keep the most recent order per product.
                            if (!isset($products_data[$product_id]) ||
                                $order->created_at > $products_data[$product_id]['purchased_at']) {

                                $products_data[$product_id] = [
                                    'name'         => $price->product->name ?? __('Product', 'wicket-integration'),
                                    'description'  => $price->product->description ?? '',
                                    'purchased_at' => $order->created_at,
                                    'product_id'   => $product_id,
                                    'product'      => $price->product,
                                ];
                            }
                        } catch (\Exception $e) {
                            continue;
                        }
                    }
                }
            }

            return $this->render_products($products_data);

        } catch (\Exception $e) {

            return '<p>' . esc_html__('Unable to load products. Please try again later.', 'wicket-integration') . '</p>';
        }
    }

    /**
     * Get product IDs that belong to excluded collections (membership, sustaining-membership).
     * Results are cached in a transient for 24 hours.
     *
     * @return array Array of product IDs to exclude.
     */
    private function get_excluded_product_ids() {
        $transient_key = 'myies_excluded_collection_product_ids';
        $cached = get_transient($transient_key);

        if (false !== $cached) {
            return $cached;
        }

        $excluded_ids = [];

        foreach ($this->excluded_collection_slugs as $slug) {
            try {
                // Search for the collection by slug.
                $collections = \SureCart\Models\ProductCollection::where([
                    'query' => $slug,
                ])->get();

                if (empty($collections) || !is_array($collections)) {
                    continue;
                }

                // Find the exact slug match.
                $collection_id = null;
                foreach ($collections as $collection) {
                    if (isset($collection->slug) && $collection->slug === $slug) {
                        $collection_id = $collection->id;
                        break;
                    }
                }

                if (!$collection_id) {
                    continue;
                }

                // Fetch products in this collection.
                $products = \SureCart\Models\Product::where([
                    'product_collection_ids' => [$collection_id],
                ])->get();

                if (!empty($products) && is_array($products)) {
                    foreach ($products as $product) {
                        if (isset($product->id)) {
                            $excluded_ids[] = $product->id;
                        }
                    }
                }
            } catch (\Exception $e) {
                if (function_exists('myies_log')) {
                    myies_log('Error fetching collection "' . $slug . '": ' . $e->getMessage(), 'user-products-shortcode');
                }
            }
        }

        $excluded_ids = array_unique($excluded_ids);
        set_transient($transient_key, $excluded_ids, DAY_IN_SECONDS);

        return $excluded_ids;
    }

    /**
     * Render the products grid using Bricks-style markup.
     *
     * @param array $products_data Product data array keyed by product ID.
     * @return string HTML output.
     */
    private function render_products($products_data) {
        ob_start();
        ?>
        <div class="brxe-block membership-info">
            <div class="brxe-block func-head-viewall">
                <h3 class="brxe-heading current-section">
                    <?php esc_html_e('Products', 'wicket-integration'); ?>
                </h3>
            </div>

            <div class="brxe-block dash-func-container">
                <?php if (empty($products_data)) : ?>
                    <div class="brxe-block grid-subs">
                        <div class="brxe-block ins-grid-desc">
                            <div class="brxe-text-basic subs-desc">
                                <?php esc_html_e('No products found.', 'wicket-integration'); ?>
                            </div>
                        </div>
                    </div>
                <?php else : ?>
                    <?php foreach ($products_data as $product) : ?>
                        <?php
                        $expiration_period    = apply_filters('myies_surecart_product_expiration_period', '+1 year', $product);
                        $expiration_timestamp = strtotime($expiration_period, $product['purchased_at']);
                        $expiration_date      = date_i18n(get_option('date_format'), $expiration_timestamp);

                        $is_active    = $expiration_timestamp >= time();
                        $status       = $is_active ? __('Active', 'wicket-integration') : __('Expired', 'wicket-integration');
                        $status_class = $is_active ? 'active-status-sub' : 'expired-status-sub';

                        $description = !empty($product['description'])
                            ? wp_trim_words($product['description'], 10, '...')
                            : __('Product access and benefits', 'wicket-integration');

                        // Build renew URL from SureCart product page.
                        $renew_url = '';
                        $product_obj = $product['product'] ?? null;
                        if ($product_obj && isset($product_obj->id)) {
                            $product_posts = get_posts([
                                'post_type'        => 'sc_product',
                                'posts_per_page'   => 1,
                                'post_status'      => 'publish',
                                'meta_key'         => 'sc_id',
                                'meta_value'       => $product_obj->id,
                                'suppress_filters' => true,
                            ]);

                            if (!empty($product_posts)) {
                                $renew_url = get_permalink($product_posts[0]->ID);
                            }
                        }

                        if (empty($renew_url)) {
                            $checkout_url = apply_filters('myies_surecart_checkout_url', home_url('/checkout/'));
                            $renew_url    = add_query_arg([
                                'product_id' => $product['product_id'],
                                'renew'      => '1',
                            ], $checkout_url);
                        }

                        $renew_url = apply_filters('myies_user_products_renew_url', $renew_url, $product);
                        ?>

                        <div class="brxe-block grid-subs" data-product-id="<?php echo esc_attr($product['product_id']); ?>">

                            <!-- Product Name & Status -->
                            <div class="brxe-block ins-grid-desc">
                                <div class="brxe-block subs-stat">
                                    <h3 class="brxe-heading inside-func">
                                        <?php echo esc_html($product['name']); ?>
                                    </h3>
                                    <span class="brxe-text-link <?php echo esc_attr($status_class); ?>">
                                        <span class="icon"><i class="fas fa-circle"></i></span>
                                        <span class="text"><?php echo esc_html($status); ?></span>
                                    </span>
                                </div>
                                <div class="brxe-text-basic subs-desc">
                                    <?php echo esc_html($description); ?>
                                </div>
                            </div>

                            <!-- Expiration Date -->
                            <div class="brxe-block ins-grid-desc">
                                <div class="brxe-block subs-stat">
                                    <h3 class="brxe-heading inside-func">
                                        <?php esc_html_e('Expires', 'wicket-integration'); ?>
                                    </h3>
                                </div>
                                <div class="brxe-text-basic subs-desc">
                                    <?php echo esc_html($expiration_date); ?>
                                </div>
                            </div>

                            <!-- Renew Button -->
                            <div class="brxe-block ins-grid-desc">
                                <div class="brxe-block subs-stat">
                                    <a class="brxe-button change-org-btn bricks-button bricks-background-primary"
                                       href="<?php echo esc_url($renew_url); ?>">
                                        <?php esc_html_e('Renew', 'wicket-integration'); ?>
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
    }
}

// Initialize User Products shortcode.
add_action('init', function() {
    MyIES_User_Products_Shortcode::get_instance();
});
