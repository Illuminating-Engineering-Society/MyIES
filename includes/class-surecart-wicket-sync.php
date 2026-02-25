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
     * Get mapping entry for a SureCart product.
     * Handles both legacy flat format (string) and new structured format (array).
     *
     * @return array|null ['membership_uuid' => string, 'type' => 'individual'|'sustaining'] or null
     */
    public static function get_product_mapping(string $product_id): ?array {
        $mapping = get_option('wicket_surecart_membership_mapping', []);
        if (!isset($mapping[$product_id])) {
            return null;
        }

        $entry = $mapping[$product_id];

        // Legacy flat format: product_id => 'uuid-string'
        if (is_string($entry)) {
            return [
                'membership_uuid' => $entry,
                'type'            => 'individual',
            ];
        }

        // Structured format: product_id => ['membership_uuid' => ..., 'type' => ...]
        return [
            'membership_uuid' => $entry['membership_uuid'] ?? '',
            'type'            => $entry['type'] ?? 'individual',
        ];
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
     * Create an organization membership in Wicket.
     *
     * Used for sustaining / organizational memberships where the membership
     * is written to the organization record rather than the person.
     */
    public function create_organization_membership(
        string $org_uuid,
        string $membership_uuid,
        string $owner_person_uuid,
        ?string $starts_at = null,
        ?string $ends_at = null
    ) {
        $payload = [
            'data' => [
                'type' => 'organization_memberships',
                'attributes' => [
                    'starts_at' => $starts_at ?: current_time('c'),
                ],
                'relationships' => [
                    'membership' => [
                        'data' => [
                            'type' => 'memberships',
                            'id'   => $membership_uuid,
                        ],
                    ],
                    'organization' => [
                        'data' => [
                            'type' => 'organizations',
                            'id'   => $org_uuid,
                        ],
                    ],
                    'owner' => [
                        'data' => [
                            'type' => 'people',
                            'id'   => $owner_person_uuid,
                        ],
                    ],
                ],
            ],
        ];

        if ($ends_at) {
            $payload['data']['attributes']['ends_at'] = $ends_at;
        }

        error_log('[SURECART-WICKET] Creating org membership: org=' . $org_uuid . ', membership=' . $membership_uuid . ', owner=' . $owner_person_uuid);

        return $this->request('/organization_memberships', 'POST', $payload);
    }

    /**
     * Look up a Wicket role UUID by name.
     * Results are cached in a transient for 24 hours.
     */
    public function get_role_uuid(string $role_name): ?string {
        $roles = get_transient('myies_wicket_roles');

        if ($roles === false) {
            $res = $this->request('/roles?page[size]=200');
            if (is_wp_error($res) || empty($res['data'])) {
                error_log('[SURECART-WICKET] Failed to fetch roles');
                return null;
            }

            $roles = [];
            foreach ($res['data'] as $role) {
                $name = $role['attributes']['name'] ?? '';
                $roles[$name] = $role['id'];
            }

            set_transient('myies_wicket_roles', $roles, DAY_IN_SECONDS);
        }

        return $roles[$role_name] ?? null;
    }

    /**
     * Assign one or more roles to a person in Wicket.
     *
     * @param string   $person_uuid
     * @param string[] $role_uuids
     */
    public function assign_roles(string $person_uuid, array $role_uuids) {
        $role_data = array_map(function ($uuid) {
            return ['type' => 'roles', 'id' => $uuid];
        }, $role_uuids);

        error_log('[SURECART-WICKET] Assigning ' . count($role_uuids) . ' role(s) to person ' . $person_uuid);

        return $this->request(
            "/people/{$person_uuid}/relationships/roles",
            'POST',
            ['data' => $role_data]
        );
    }

    /**
     * Update an existing membership entry in Wicket
     */
    public function update_membership(
        string $person_membership_uuid,
        ?string $starts_at = null,
        ?string $ends_at = null
    ) {
        $attributes = [];
        if ($starts_at !== null) {
            $attributes['starts_at'] = $starts_at;
        }
        if ($ends_at !== null) {
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
                ],
            ]
        );
    }

    /**
     * Update an existing organization membership entry in Wicket
     */
    public function update_organization_membership(
        string $org_membership_uuid,
        ?string $starts_at = null,
        ?string $ends_at = null
    ) {
        $attributes = [];
        if ($starts_at !== null) {
            $attributes['starts_at'] = $starts_at;
        }
        if ($ends_at !== null) {
            $attributes['ends_at'] = $ends_at;
        }

        return $this->request(
            "/organization_memberships/{$org_membership_uuid}",
            'PATCH',
            [
                'data' => [
                    'type' => 'organization_memberships',
                    'id'   => $org_membership_uuid,
                    'attributes' => $attributes,
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

    /**
     * Get the person_membership UUID for a specific membership tier.
     *
     * The meta value is stored as a JSON map: { tier_uuid => person_membership_uuid }.
     * Legacy single-string values are migrated on read.
     *
     * @param int    $user_id
     * @param string $tier_uuid  The Wicket membership tier UUID to look up.
     * @return string|null  The person_membership UUID, or null if none exists for this tier.
     */
    private static function get_person_membership_uuid(int $user_id, string $tier_uuid): ?string {
        $raw = get_user_meta($user_id, 'wicket_person_membership_uuid', true);
        if (empty($raw)) {
            return null;
        }

        // New JSON map format
        $map = json_decode($raw, true);
        if (is_array($map)) {
            return $map[$tier_uuid] ?? null;
        }

        // Legacy single-string format — we can't know which tier it belongs to,
        // so return null to avoid matching the wrong tier.
        return null;
    }

    /**
     * Store the person_membership UUID for a specific membership tier.
     *
     * Merges into the existing JSON map, migrating legacy single-string values.
     */
    private static function set_person_membership_uuid(int $user_id, string $tier_uuid, string $person_membership_uuid): void {
        $raw = get_user_meta($user_id, 'wicket_person_membership_uuid', true);
        $map = [];

        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $map = $decoded;
            } else {
                // Legacy single-string: preserve it under a special key so it isn't lost
                $map['_legacy'] = $raw;
            }
        }

        $map[$tier_uuid] = $person_membership_uuid;
        update_user_meta($user_id, 'wicket_person_membership_uuid', wp_json_encode($map));
    }

    /**
     * Get the org_membership UUID for a specific membership tier.
     */
    private static function get_org_membership_uuid(int $user_id, string $tier_uuid): ?string {
        $raw = get_user_meta($user_id, 'wicket_org_membership_uuid', true);
        if (empty($raw)) {
            return null;
        }

        $map = json_decode($raw, true);
        if (is_array($map)) {
            return $map[$tier_uuid] ?? null;
        }

        // Legacy single-string format — return null to avoid matching the wrong tier.
        return null;
    }

    /**
     * Store the org_membership UUID for a specific membership tier.
     */
    private static function set_org_membership_uuid(int $user_id, string $tier_uuid, string $org_membership_uuid): void {
        $raw = get_user_meta($user_id, 'wicket_org_membership_uuid', true);
        $map = [];

        if (!empty($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $map = $decoded;
            } else {
                $map['_legacy'] = $raw;
            }
        }

        $map[$tier_uuid] = $org_membership_uuid;
        update_user_meta($user_id, 'wicket_org_membership_uuid', wp_json_encode($map));
    }

    /**
     * Get ALL person_membership UUIDs for a user (across all tiers).
     * Used by renewal/deactivation when we need to find by type rather than tier.
     *
     * @return array  Map of tier_uuid => person_membership_uuid
     */
    private static function get_all_person_membership_uuids(int $user_id): array {
        $raw = get_user_meta($user_id, 'wicket_person_membership_uuid', true);
        if (empty($raw)) {
            return [];
        }

        $map = json_decode($raw, true);
        if (is_array($map)) {
            return $map;
        }

        // Legacy single-string
        return ['_legacy' => $raw];
    }

    /**
     * Get ALL org_membership UUIDs for a user (across all tiers).
     */
    private static function get_all_org_membership_uuids(int $user_id): array {
        $raw = get_user_meta($user_id, 'wicket_org_membership_uuid', true);
        if (empty($raw)) {
            return [];
        }

        $map = json_decode($raw, true);
        if (is_array($map)) {
            return $map;
        }

        return ['_legacy' => $raw];
    }

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

        // Subscription renewal hook
        add_action('surecart/subscription_renewed', [$this, 'handle_subscription_renewed'], 10, 2);

        // Purchase revocation hook (cancellation/refund)
        add_action('surecart/purchase_revoked', [$this, 'handle_purchase_revoked'], 10, 2);

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
            $purchase_data = \SureCart\Models\Purchase::with(['product', 'customer', 'initial_order', 'order.checkout', 'subscription', 'subscription.current_period'])->find($purchase->id);

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
            $product_mapping = Wicket_Membership_Service::get_product_mapping($product_id);
            if (!$product_mapping) {
                error_log('[SURECART-WICKET] Product not mapped to Wicket membership: ' . $product_id);
                return;
            }

            $wicket_membership_uuid = $product_mapping['membership_uuid'];
            $membership_type = $product_mapping['type']; // 'individual' or 'sustaining'
            error_log('[SURECART-WICKET] Found Wicket membership UUID: ' . $wicket_membership_uuid . ' (type: ' . $membership_type . ')');

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
            $this->sync_membership_to_wicket($user->ID, $wicket_membership_uuid, $purchase_data, $membership_type);

        } catch (Exception $e) {
            error_log('[SURECART-WICKET] Error processing purchase: ' . $e->getMessage());
        }
    }

    /**
     * Handle SureCart subscription renewed event.
     *
     * Extends the membership end date in Wicket when a subscription auto-renews.
     */
    public function handle_subscription_renewed($subscription, $data = []) {
        error_log('[SURECART-WICKET] Subscription renewed event triggered');

        if (!get_option('wicket_surecart_sync_enabled', 1)) {
            error_log('[SURECART-WICKET] Sync is disabled, skipping renewal');
            return;
        }

        try {
            $subscription_data = \SureCart\Models\Subscription::with(['price', 'price.product', 'customer', 'current_period'])->find($subscription->id);

            if (!$subscription_data) {
                error_log('[SURECART-WICKET] Could not find subscription data for ID: ' . $subscription->id);
                return;
            }

            $product_id = $subscription_data->price->product->id ?? null;
            if (!$product_id) {
                error_log('[SURECART-WICKET] No product ID found in subscription');
                return;
            }

            error_log('[SURECART-WICKET] Renewal for product ID: ' . $product_id);

            $product_mapping = Wicket_Membership_Service::get_product_mapping($product_id);
            if (!$product_mapping) {
                error_log('[SURECART-WICKET] Product not mapped to Wicket membership: ' . $product_id);
                return;
            }

            $customer_email = $subscription_data->customer->email ?? null;
            if (!$customer_email) {
                error_log('[SURECART-WICKET] No customer email found in subscription');
                return;
            }

            $user = get_user_by('email', $customer_email);
            if (!$user) {
                error_log('[SURECART-WICKET] No WordPress user found for email: ' . $customer_email);
                return;
            }

            // Calculate new ends_at from the subscription's current period
            $ends_at = null;
            if (isset($subscription_data->current_period) && is_object($subscription_data->current_period)) {
                $ends_at = isset($subscription_data->current_period->end_at)
                    ? date('c', $subscription_data->current_period->end_at)
                    : null;
            }
            if (!$ends_at && isset($subscription_data->current_period_end_at)) {
                $ends_at = date('c', $subscription_data->current_period_end_at);
            }

            if (!$ends_at) {
                error_log('[SURECART-WICKET] Could not determine new end date from subscription');
                return;
            }

            error_log('[SURECART-WICKET] Renewing membership, new ends_at: ' . $ends_at);
            $this->renew_membership_in_wicket($user->ID, $product_mapping['type'], $product_mapping['membership_uuid'], $ends_at);

        } catch (Exception $e) {
            error_log('[SURECART-WICKET] Error processing subscription renewal: ' . $e->getMessage());
        }
    }

    /**
     * Extend a membership's end date in Wicket upon renewal.
     *
     * @param int    $user_id
     * @param string $membership_type 'individual' or 'sustaining'
     * @param string $tier_uuid       Wicket membership tier UUID
     * @param string $ends_at         New end date in ISO 8601 format
     * @return bool
     */
    private function renew_membership_in_wicket($user_id, $membership_type, $tier_uuid, $ends_at) {
        try {
            $svc = new Wicket_Membership_Service();
        } catch (Exception $e) {
            error_log('[SURECART-WICKET] Failed to initialize service for renewal: ' . $e->getMessage());
            return false;
        }

        if ($membership_type === 'sustaining') {
            $org_membership_uuid = self::get_org_membership_uuid($user_id, $tier_uuid);
            if (empty($org_membership_uuid)) {
                // Legacy fallback: user meta is a plain string from before multi-tier support
                $org_membership_uuid = get_user_meta($user_id, 'wicket_org_membership_uuid', true);
            }
            if (empty($org_membership_uuid)) {
                error_log('[SURECART-WICKET] No org membership UUID found for user ' . $user_id . ' tier ' . $tier_uuid . ', cannot renew');
                return false;
            }

            error_log('[SURECART-WICKET] Renewing org membership: ' . $org_membership_uuid . ' (tier: ' . $tier_uuid . ')');
            $res = $svc->update_organization_membership($org_membership_uuid, null, $ends_at);
        } else {
            $person_membership_uuid = self::get_person_membership_uuid($user_id, $tier_uuid);
            if (empty($person_membership_uuid)) {
                // Legacy fallback: user meta is a plain string from before multi-tier support
                $person_membership_uuid = get_user_meta($user_id, 'wicket_person_membership_uuid', true);
            }
            if (empty($person_membership_uuid)) {
                error_log('[SURECART-WICKET] No person membership UUID found for user ' . $user_id . ' tier ' . $tier_uuid . ', cannot renew');
                return false;
            }

            error_log('[SURECART-WICKET] Renewing person membership: ' . $person_membership_uuid . ' (tier: ' . $tier_uuid . ')');
            $res = $svc->update_membership($person_membership_uuid, null, $ends_at);
        }

        if (is_wp_error($res)) {
            error_log('[SURECART-WICKET] Failed to renew membership: ' . $res->get_error_message());
            return false;
        }

        error_log('[SURECART-WICKET] Membership renewed successfully');

        if (function_exists('wicket_sync_user_memberships')) {
            wicket_sync_user_memberships($user_id);
        }

        return true;
    }

    /**
     * Handle SureCart purchase revoked event.
     *
     * Deactivates the membership in Wicket by setting ends_at to now.
     */
    public function handle_purchase_revoked($purchase, $webhook_data = []) {
        error_log('[SURECART-WICKET] Purchase revoked event triggered');

        if (!get_option('wicket_surecart_sync_enabled', 1)) {
            error_log('[SURECART-WICKET] Sync is disabled, skipping revocation');
            return;
        }

        try {
            $purchase_data = \SureCart\Models\Purchase::with(['product', 'customer'])->find($purchase->id);

            if (!$purchase_data) {
                error_log('[SURECART-WICKET] Could not find purchase data for revocation, ID: ' . $purchase->id);
                return;
            }

            $product_id = $purchase_data->product->id ?? null;
            if (!$product_id) {
                error_log('[SURECART-WICKET] No product ID found in revoked purchase');
                return;
            }

            error_log('[SURECART-WICKET] Revocation for product ID: ' . $product_id);

            $product_mapping = Wicket_Membership_Service::get_product_mapping($product_id);
            if (!$product_mapping) {
                error_log('[SURECART-WICKET] Product not mapped to Wicket membership: ' . $product_id);
                return;
            }

            $customer_email = $purchase_data->customer->email ?? null;
            if (!$customer_email) {
                error_log('[SURECART-WICKET] No customer email found in revoked purchase');
                return;
            }

            $user = get_user_by('email', $customer_email);
            if (!$user) {
                error_log('[SURECART-WICKET] No WordPress user found for email: ' . $customer_email);
                return;
            }

            $this->deactivate_membership_in_wicket($user->ID, $product_mapping['type'], $product_mapping['membership_uuid']);

        } catch (Exception $e) {
            error_log('[SURECART-WICKET] Error processing purchase revocation: ' . $e->getMessage());
        }
    }

    /**
     * Deactivate a membership in Wicket by setting ends_at to now.
     *
     * @param int    $user_id
     * @param string $membership_type 'individual' or 'sustaining'
     * @param string $tier_uuid       Wicket membership tier UUID
     * @return bool
     */
    private function deactivate_membership_in_wicket($user_id, $membership_type, $tier_uuid) {
        try {
            $svc = new Wicket_Membership_Service();
        } catch (Exception $e) {
            error_log('[SURECART-WICKET] Failed to initialize service for deactivation: ' . $e->getMessage());
            return false;
        }

        $now = current_time('c');

        if ($membership_type === 'sustaining') {
            $org_membership_uuid = self::get_org_membership_uuid($user_id, $tier_uuid);
            if (empty($org_membership_uuid)) {
                // Legacy fallback: user meta is a plain string from before multi-tier support
                $org_membership_uuid = get_user_meta($user_id, 'wicket_org_membership_uuid', true);
            }
            if (empty($org_membership_uuid)) {
                error_log('[SURECART-WICKET] No org membership UUID found for user ' . $user_id . ' tier ' . $tier_uuid . ', cannot deactivate');
                return false;
            }

            error_log('[SURECART-WICKET] Deactivating org membership: ' . $org_membership_uuid . ' (tier: ' . $tier_uuid . ')');
            $res = $svc->update_organization_membership($org_membership_uuid, null, $now);
        } else {
            $person_membership_uuid = self::get_person_membership_uuid($user_id, $tier_uuid);
            if (empty($person_membership_uuid)) {
                // Legacy fallback: user meta is a plain string from before multi-tier support
                $person_membership_uuid = get_user_meta($user_id, 'wicket_person_membership_uuid', true);
            }
            if (empty($person_membership_uuid)) {
                error_log('[SURECART-WICKET] No person membership UUID found for user ' . $user_id . ' tier ' . $tier_uuid . ', cannot deactivate');
                return false;
            }

            error_log('[SURECART-WICKET] Deactivating person membership: ' . $person_membership_uuid . ' (tier: ' . $tier_uuid . ')');
            $res = $svc->update_membership($person_membership_uuid, null, $now);
        }

        if (is_wp_error($res)) {
            error_log('[SURECART-WICKET] Failed to deactivate membership: ' . $res->get_error_message());
            return false;
        }

        error_log('[SURECART-WICKET] Membership deactivated successfully');

        if (function_exists('wicket_sync_user_memberships')) {
            wicket_sync_user_memberships($user_id);
        }

        return true;
    }

    /**
     * Sync membership to Wicket
     *
     * @param int         $user_id
     * @param string      $wicket_membership_uuid  Wicket membership tier UUID
     * @param object|null $purchase_data           SureCart purchase object
     * @param string      $membership_type         'individual' or 'sustaining'
     */
    private function sync_membership_to_wicket($user_id, $wicket_membership_uuid, $purchase_data = null, $membership_type = 'individual') {
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

        if ($purchase_data && isset($purchase_data->subscription) && is_object($purchase_data->subscription)) {
            // Auto-renewal: use the subscription's current period end as expiration
            $subscription = $purchase_data->subscription;
            if (isset($subscription->current_period_end_at)) {
                $ends_at = date('c', $subscription->current_period_end_at);
            }
        }

        // Manual renewal (one-time purchase): calculate expiration from purchase date
        if (!$ends_at && $purchase_data) {
            $product_id = $purchase_data->product->id ?? null;
            $expiration_period = apply_filters('myies_surecart_product_expiration_period', '+1 year', $product_id);
            $ends_at = date('c', strtotime($expiration_period));
        }

        // Route to the correct handler based on membership type
        if ($membership_type === 'sustaining') {
            return $this->sync_sustaining_membership($svc, $user_id, $person_uuid, $wicket_membership_uuid, $starts_at, $ends_at);
        }

        return $this->sync_individual_membership($svc, $user_id, $person_uuid, $wicket_membership_uuid, $starts_at, $ends_at);
    }

    /**
     * Sync an individual (person) membership to Wicket
     */
    private function sync_individual_membership($svc, $user_id, $person_uuid, $wicket_membership_uuid, $starts_at, $ends_at) {
        $existing = self::get_person_membership_uuid($user_id, $wicket_membership_uuid);

        if ($existing) {
            error_log('[SURECART-WICKET] Updating existing individual membership: ' . $existing . ' (tier: ' . $wicket_membership_uuid . ')');
            $res = $svc->update_membership($existing, $starts_at, $ends_at);
        } else {
            error_log('[SURECART-WICKET] Creating new individual membership for tier: ' . $wicket_membership_uuid);
            $res = $svc->create_membership($person_uuid, $wicket_membership_uuid, $starts_at, $ends_at);

            if (!is_wp_error($res) && isset($res['data']['id'])) {
                self::set_person_membership_uuid($user_id, $wicket_membership_uuid, $res['data']['id']);
                error_log('[SURECART-WICKET] Stored person membership UUID: ' . $res['data']['id'] . ' for tier: ' . $wicket_membership_uuid);
            }
        }

        if (is_wp_error($res)) {
            error_log('[SURECART-WICKET] Failed to sync individual membership: ' . $res->get_error_message());
            return false;
        }

        error_log('[SURECART-WICKET] Individual membership synced successfully');

        if (function_exists('wicket_sync_user_memberships')) {
            wicket_sync_user_memberships($user_id);
        }

        return true;
    }

    /**
     * Sync a sustaining (organization) membership to Wicket.
     *
     * 1. Reads the org_uuid the user selected before checkout (stored in transient).
     * 2. Creates an organization_membership in Wicket (membership written to the org).
     * 3. Ensures a person-to-org connection exists.
     * 4. Assigns "Company - Primary Contact" and "Company - Billing Contact" roles to the person.
     */
    private function sync_sustaining_membership($svc, $user_id, $person_uuid, $wicket_membership_uuid, $starts_at, $ends_at) {
        // 1. Get the organization UUID from the pre-checkout selection
        $org_uuid = get_transient('myies_checkout_org_' . $user_id);

        if (empty($org_uuid)) {
            // Fallback: check user's primary org
            $org_uuid = get_user_meta($user_id, 'wicket_primary_org_uuid', true);
        }

        if (empty($org_uuid)) {
            error_log('[SURECART-WICKET] No organization UUID found for sustaining membership. User: ' . $user_id);
            return false;
        }

        error_log('[SURECART-WICKET] Sustaining membership for org: ' . $org_uuid);

        // 2. Create organization membership in Wicket
        $res = $svc->create_organization_membership(
            $org_uuid,
            $wicket_membership_uuid,
            $person_uuid,
            $starts_at,
            $ends_at
        );

        if (is_wp_error($res)) {
            error_log('[SURECART-WICKET] Failed to create org membership: ' . $res->get_error_message());
            return false;
        }

        if (isset($res['data']['id'])) {
            self::set_org_membership_uuid($user_id, $wicket_membership_uuid, $res['data']['id']);
            error_log('[SURECART-WICKET] Stored org membership UUID: ' . $res['data']['id'] . ' for tier: ' . $wicket_membership_uuid);
        }

        // 3. Ensure person-to-org connection exists (via existing API helper)
        if (function_exists('wicket_api')) {
            $api = wicket_api();
            $api->create_person_org_connection($person_uuid, $org_uuid, 'employee', 'Sustaining membership owner', $starts_at, $ends_at);
        }

        // 4. Assign contact roles
        $role_names = [
            'Company - Primary Contact',
            'Company - Billing Contact',
        ];

        $role_uuids = [];
        foreach ($role_names as $name) {
            $uuid = $svc->get_role_uuid($name);
            if ($uuid) {
                $role_uuids[] = $uuid;
            } else {
                error_log('[SURECART-WICKET] Role not found in Wicket: ' . $name);
            }
        }

        if (!empty($role_uuids)) {
            $role_res = $svc->assign_roles($person_uuid, $role_uuids);
            if (is_wp_error($role_res)) {
                error_log('[SURECART-WICKET] Failed to assign roles: ' . $role_res->get_error_message());
            } else {
                error_log('[SURECART-WICKET] Roles assigned successfully');
            }
        }

        // Clean up the transient
        delete_transient('myies_checkout_org_' . $user_id);

        error_log('[SURECART-WICKET] Sustaining membership synced successfully');

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
                return current_user_can('manage_options');
            },
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
        $product_mapping = Wicket_Membership_Service::get_product_mapping($product_id);
        if (!$product_mapping) {
            return new WP_Error('no_mapping', 'Product not mapped to Wicket membership', ['status' => 400]);
        }

        $wicket_membership_uuid = $product_mapping['membership_uuid'];
        $membership_type = $product_mapping['type'];

        try {
            $svc = new Wicket_Membership_Service();
        } catch (Exception $e) {
            return new WP_Error('config_error', $e->getMessage(), ['status' => 500]);
        }

        $person_uuid = $svc->find_or_create_person($user_id);
        if (is_wp_error($person_uuid)) {
            return $person_uuid;
        }

        $existing_person_membership_uuid = self::get_person_membership_uuid($user_id, $wicket_membership_uuid);

        if ($existing_person_membership_uuid) {
            $res = $svc->update_membership(
                $existing_person_membership_uuid,
                null,
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
                self::set_person_membership_uuid($user_id, $wicket_membership_uuid, $res['data']['id']);
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
            'person_membership_uuid' => self::get_person_membership_uuid($user_id, $wicket_membership_uuid),
        ];
    }
}

// Initialize the sync handler
add_action('plugins_loaded', function() {
    $sc = class_exists('\SureCart\Models\Purchase');
    error_log('[SURECART-WICKET] plugins_loaded:20 — SureCart class: ' . ($sc ? 'YES' : 'NO'));
    if ($sc) {
        SureCart_Wicket_Sync::get_instance();
        error_log('[SURECART-WICKET] Sync handler initialized, purchase hook registered');
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
