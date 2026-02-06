<?php
/**
 * SureCart to Wicket Membership Sync
 *
 * Handles synchronization of membership purchases from SureCart to Wicket CRM.
 * When a membership product is purchased via SureCart, the corresponding
 * membership is created or updated in Wicket for the user.
 *
 * @package MyIES_Integration
 * @since 1.0.4
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Wicket API Credentials Handler
 *
 * Manages Wicket API authentication including JWT generation.
 */
class Wicket_Credentials {

    private string $tenant;
    private string $api_secret;
    private string $admin_uuid;
    private bool $staging;

    public function __construct() {
        $this->tenant     = get_option('wicket_tenant_name', '');
        $this->api_secret = get_option('wicket_api_secret_key', '');
        $this->admin_uuid = get_option('wicket_admin_user_uuid', '');
        $this->staging    = (bool) get_option('wicket_staging', 0);
    }

    /**
     * Validate credentials are configured
     */
    public function is_configured(): bool {
        return !empty($this->tenant)
            && !empty($this->api_secret)
            && !empty($this->admin_uuid);
    }

    /**
     * Get base API URL
     */
    public function api_url(): string {
        if ($this->staging) {
            return "https://{$this->tenant}-api.staging.wicketcloud.com";
        }
        return "https://{$this->tenant}-api.wicketcloud.com";
    }

    /**
     * Generate JWT token for API authentication
     */
    public function jwt(): string {
        if (!$this->is_configured()) {
            throw new Exception('Wicket credentials not configured');
        }

        $header = json_encode([
            'typ' => 'JWT',
            'alg' => 'HS256'
        ]);

        $payload = json_encode([
            'exp' => time() + HOUR_IN_SECONDS,
            'sub' => $this->admin_uuid,
            'aud' => $this->api_url(),
            'iss' => get_site_url()
        ]);

        $base64Header  = $this->base64url($header);
        $base64Payload = $this->base64url($payload);

        $signature = hash_hmac(
            'sha256',
            $base64Header . '.' . $base64Payload,
            $this->api_secret,
            true
        );

        return $base64Header . '.'
             . $base64Payload . '.'
             . $this->base64url($signature);
    }

    private function base64url(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

/**
 * Wicket Membership Service
 *
 * Handles creating and updating memberships in Wicket CRM.
 */
class Wicket_Membership_Service {

    private Wicket_Credentials $creds;
    private string $api_url;

    public function __construct(?Wicket_Credentials $creds = null) {
        $this->creds = $creds ?: new Wicket_Credentials();

        if (!$this->creds->is_configured()) {
            throw new Exception('Wicket credentials not configured');
        }

        $this->api_url = $this->creds->api_url();
    }

    /**
     * Make API request to Wicket
     */
    private function request(string $endpoint, string $method = 'GET', array $data = null) {
        try {
            $token = $this->creds->jwt();
        } catch (Exception $e) {
            return new WP_Error('auth_failed', $e->getMessage());
        }

        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => "Bearer {$token}",
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ];

        if ($data && in_array($method, ['POST', 'PATCH', 'PUT'], true)) {
            $args['body'] = wp_json_encode($data);
        }

        $res = wp_remote_request($this->api_url . $endpoint, $args);

        if (is_wp_error($res)) {
            error_log('[SURECART-WICKET] API request error: ' . $res->get_error_message());
            return $res;
        }

        $code = wp_remote_retrieve_response_code($res);
        $body = wp_remote_retrieve_body($res);

        if ($code >= 400) {
            error_log('[SURECART-WICKET] API error ' . $code . ': ' . $body);
            return new WP_Error(
                'wicket_api_error',
                $body ?: 'Wicket API error',
                ['status' => $code]
            );
        }

        return json_decode($body, true);
    }

    /**
     * Find or create a person in Wicket by WordPress user
     */
    public function find_or_create_person(int $user_id) {
        $user = get_userdata($user_id);
        if (!$user) {
            return new WP_Error('user_not_found', 'WP user not found');
        }

        // Check if we already have the UUID stored
        $existing_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);
        if (!empty($existing_uuid)) {
            return $existing_uuid;
        }

        // Search for person by email
        $search = $this->request(
            '/people?filter[emails_address_eq]=' . urlencode($user->user_email)
        );

        if (is_wp_error($search)) {
            return $search;
        }

        if (!empty($search['data'][0]['id'])) {
            update_user_meta($user_id, 'wicket_person_uuid', $search['data'][0]['id']);
            return $search['data'][0]['id'];
        }

        // Create new person
        $payload = [
            'data' => [
                'type' => 'people',
                'attributes' => [
                    'given_name'  => $user->first_name ?: $user->display_name,
                    'family_name' => $user->last_name ?: '',
                    'full_name'   => $user->display_name,
                    'language'    => 'en',
                ],
                'relationships' => [
                    'emails' => [
                        'data' => [[
                            'type' => 'emails',
                            'attributes' => [
                                'address' => $user->user_email,
                                'primary' => true,
                                'unique'  => true,
                            ],
                        ]],
                    ],
                ],
            ],
        ];

        $res = $this->request('/people', 'POST', $payload);

        if (is_wp_error($res)) {
            return $res;
        }

        update_user_meta($user_id, 'wicket_person_uuid', $res['data']['id']);
        return $res['data']['id'];
    }

    /**
     * Get Wicket membership UUID from SureCart product mapping
     */
    public function get_membership_uuid_from_product(string $product_id): ?string {
        $mapping = get_option('wicket_surecart_membership_mapping', []);
        return $mapping[$product_id] ?? null;
    }

    /**
     * Create a membership entry in Wicket
     */
    public function create_membership(
        string $person_uuid,
        string $membership_uuid,
        ?string $starts_at = null,
        ?string $ends_at = null
    ) {
        $payload = [
            'data' => [
                'type' => 'person_memberships',
                'attributes' => [
                    'starts_at' => $starts_at ?: current_time('c'),
                ],
                'relationships' => [
                    'person' => [
                        'data' => [
                            'type' => 'people',
                            'id'   => $person_uuid,
                        ],
                    ],
                    'membership' => [
                        'data' => [
                            'type' => 'memberships',
                            'id'   => $membership_uuid,
                        ],
                    ],
                ],
            ],
        ];

        if ($ends_at) {
            $payload['data']['attributes']['ends_at'] = $ends_at;
        }

        error_log('[SURECART-WICKET] Creating membership: person=' . $person_uuid . ', membership=' . $membership_uuid);

        return $this->request('/person_memberships', 'POST', $payload);
    }

    /**
     * Update an existing membership entry in Wicket
     */
    public function update_membership(
        string $person_membership_uuid,
        string $membership_uuid,
        ?string $ends_at = null
    ) {
        $attributes = [];
        if ($ends_at) {
            $attributes['ends_at'] = $ends_at;
        }

        return $this->request(
            "/person_memberships/{$person_membership_uuid}",
            'PATCH',
            [
                'data' => [
                    'type' => 'person_memberships',
                    'id'   => $person_membership_uuid,
                    'attributes' => $attributes,
                    'relationships' => [
                        'membership' => [
                            'data' => [
                                'type' => 'memberships',
                                'id'   => $membership_uuid,
                            ],
                        ],
                    ],
                ],
            ]
        );
    }
}

/**
 * SureCart to Wicket Sync Handler
 *
 * Listens for SureCart purchase events and syncs to Wicket.
 */
class SureCart_Wicket_Sync {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // SureCart purchase hook
        add_action('surecart/purchase_created', [$this, 'handle_purchase_created'], 10, 2);

        // REST API endpoint for manual/testing
        add_action('rest_api_init', [$this, 'register_rest_routes']);
    }

    /**
     * Handle SureCart purchase created event
     */
    public function handle_purchase_created($purchase, $webhook_data = []) {
        error_log('[SURECART-WICKET] Purchase created event triggered');

        // Check if sync is enabled
        if (!get_option('wicket_surecart_sync_enabled', 1)) {
            error_log('[SURECART-WICKET] Sync is disabled, skipping');
            return;
        }

        try {
            // Get purchase details with product info
            $purchase_data = \SureCart\Models\Purchase::with(['product', 'customer', 'initial_order', 'order.checkout'])->find($purchase->id);

            if (!$purchase_data) {
                error_log('[SURECART-WICKET] Could not find purchase data for ID: ' . $purchase->id);
                return;
            }

            // Get product ID
            $product_id = $purchase_data->product->id ?? null;
            if (!$product_id) {
                error_log('[SURECART-WICKET] No product ID found in purchase');
                return;
            }

            error_log('[SURECART-WICKET] Processing product ID: ' . $product_id);

            // Check if this product is mapped to a Wicket membership
            $mapping = get_option('wicket_surecart_membership_mapping', []);
            if (!isset($mapping[$product_id])) {
                error_log('[SURECART-WICKET] Product not mapped to Wicket membership: ' . $product_id);
                return;
            }

            $wicket_membership_uuid = $mapping[$product_id];
            error_log('[SURECART-WICKET] Found Wicket membership UUID: ' . $wicket_membership_uuid);

            // Get customer email and find WordPress user
            $customer_email = $purchase_data->customer->email ?? null;
            if (!$customer_email) {
                error_log('[SURECART-WICKET] No customer email found');
                return;
            }

            $user = get_user_by('email', $customer_email);
            if (!$user) {
                error_log('[SURECART-WICKET] No WordPress user found for email: ' . $customer_email);
                return;
            }

            // Process the membership sync
            $this->sync_membership_to_wicket($user->ID, $wicket_membership_uuid, $purchase_data);

        } catch (Exception $e) {
            error_log('[SURECART-WICKET] Error processing purchase: ' . $e->getMessage());
        }
    }

    /**
     * Sync membership to Wicket
     */
    private function sync_membership_to_wicket($user_id, $wicket_membership_uuid, $purchase_data = null) {
        try {
            $svc = new Wicket_Membership_Service();
        } catch (Exception $e) {
            error_log('[SURECART-WICKET] Failed to initialize service: ' . $e->getMessage());
            return false;
        }

        // Find or create person in Wicket
        $person_uuid = $svc->find_or_create_person($user_id);
        if (is_wp_error($person_uuid)) {
            error_log('[SURECART-WICKET] Failed to get person UUID: ' . $person_uuid->get_error_message());
            return false;
        }

        error_log('[SURECART-WICKET] Person UUID: ' . $person_uuid);

        // Calculate membership dates
        $starts_at = current_time('c');
        $ends_at = null;

        // If we have subscription data, try to get the period end
        if ($purchase_data && isset($purchase_data->subscription)) {
            $subscription = $purchase_data->subscription;
            if (isset($subscription->current_period_end_at)) {
                $ends_at = date('c', $subscription->current_period_end_at);
            }
        }

        // Check if user already has a Wicket person membership UUID stored
        $existing_person_membership_uuid = get_user_meta($user_id, 'wicket_person_membership_uuid', true);

        if ($existing_person_membership_uuid) {
            // Update existing membership
            error_log('[SURECART-WICKET] Updating existing membership: ' . $existing_person_membership_uuid);
            $res = $svc->update_membership(
                $existing_person_membership_uuid,
                $wicket_membership_uuid,
                $ends_at
            );
        } else {
            // Create new membership
            error_log('[SURECART-WICKET] Creating new membership');
            $res = $svc->create_membership(
                $person_uuid,
                $wicket_membership_uuid,
                $starts_at,
                $ends_at
            );

            if (!is_wp_error($res) && isset($res['data']['id'])) {
                update_user_meta($user_id, 'wicket_person_membership_uuid', $res['data']['id']);
                error_log('[SURECART-WICKET] Stored person membership UUID: ' . $res['data']['id']);
            }
        }

        if (is_wp_error($res)) {
            error_log('[SURECART-WICKET] Failed to sync membership: ' . $res->get_error_message());
            return false;
        }

        error_log('[SURECART-WICKET] Membership synced successfully');

        // Trigger local membership sync to update the local database
        if (function_exists('wicket_sync_user_memberships')) {
            wicket_sync_user_memberships($user_id);
        }

        return true;
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('wicket/v1', '/sync-surecart-membership', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_sync_membership'],
            'permission_callback' => function() {
                return current_user_can('edit_users');
            }
        ]);
    }

    /**
     * REST endpoint for manual membership sync
     */
    public function rest_sync_membership(WP_REST_Request $request) {
        $user_id = (int) $request->get_param('user_id');
        $product_id = (string) $request->get_param('product_id');
        $ends_at = $request->get_param('ends_at');

        if (!$user_id || !$product_id) {
            return new WP_Error('invalid_data', 'user_id and product_id required', ['status' => 400]);
        }

        // Get membership UUID from mapping
        $mapping = get_option('wicket_surecart_membership_mapping', []);
        if (!isset($mapping[$product_id])) {
            return new WP_Error('no_mapping', 'Product not mapped to Wicket membership', ['status' => 400]);
        }

        $wicket_membership_uuid = $mapping[$product_id];

        try {
            $svc = new Wicket_Membership_Service();
        } catch (Exception $e) {
            return new WP_Error('config_error', $e->getMessage(), ['status' => 500]);
        }

        $person_uuid = $svc->find_or_create_person($user_id);
        if (is_wp_error($person_uuid)) {
            return $person_uuid;
        }

        $existing_person_membership_uuid = get_user_meta($user_id, 'wicket_person_membership_uuid', true);

        if ($existing_person_membership_uuid) {
            $res = $svc->update_membership(
                $existing_person_membership_uuid,
                $wicket_membership_uuid,
                $ends_at
            );
        } else {
            $res = $svc->create_membership(
                $person_uuid,
                $wicket_membership_uuid,
                null,
                $ends_at
            );

            if (!is_wp_error($res) && isset($res['data']['id'])) {
                update_user_meta($user_id, 'wicket_person_membership_uuid', $res['data']['id']);
            }
        }

        if (is_wp_error($res)) {
            return $res;
        }

        // Trigger local sync
        if (function_exists('wicket_sync_user_memberships')) {
            wicket_sync_user_memberships($user_id);
        }

        return [
            'success' => true,
            'person_uuid' => $person_uuid,
            'person_membership_uuid' => get_user_meta($user_id, 'wicket_person_membership_uuid', true),
        ];
    }
}

// Initialize the sync handler
add_action('plugins_loaded', function() {
    // Only initialize if SureCart is active
    if (class_exists('\SureCart\Models\Purchase')) {
        SureCart_Wicket_Sync::get_instance();
    }
}, 20);

/**
 * Helper function to get credentials instance
 */
function wicket_surecart_credentials() {
    return new Wicket_Credentials();
}

/**
 * Helper function to get membership service instance
 */
function wicket_surecart_membership_service() {
    return new Wicket_Membership_Service();
}
