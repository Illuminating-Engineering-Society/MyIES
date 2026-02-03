<?php
/**
 * Wicket User Sync on Login and Registration
 * 
 * This script syncs existing WordPress users with Wicket data on login and registration.
 * It only updates existing users and never creates new ones.
 * 
 * UPDATED: Now includes addresses, phones, gender, job_level, job_function
 */

class WicketLoginSync {
    
    public function __construct() {
        // Login hooks
        add_action('wp_login', array($this, 'sync_user_on_login'), 10, 2);
        
        // Registration hooks for auto-login after registration
        add_action('user_register', array($this, 'sync_user_on_registration'), 10, 1);
        add_action('wp_loaded', array($this, 'maybe_sync_after_registration'), 20);
        
        // FluentForms specific hooks (if available)
        add_action('fluentform/user_registration_completed', array($this, 'sync_user_after_fluent_registration'), 10, 3);
        
        // General post-registration hook that catches most auto-login scenarios
        add_action('wp_set_current_user', array($this, 'maybe_sync_current_user'), 10, 1);
        
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        // Settings are now handled by the unified settings page
        // in class-wicket-settings-page.php
        if (is_admin()) {
            add_action('admin_init', array($this, 'register_settings'));
        }
    }
    
    /**
     * Main sync function triggered on user login
     */
    public function sync_user_on_login($user_login, $user) {
        $this->perform_user_sync($user, 'login');
    }
    
    /**
     * Sync function triggered on user registration
     */
    public function sync_user_on_registration($user_id) {
        $user = get_userdata($user_id);
        if ($user) {
            $this->perform_user_sync($user, 'registration');
        }
    }
    
    /**
     * FluentForms specific sync after registration
     */
    public function sync_user_after_fluent_registration($user_id, $feed_data, $entry) {
        $user = get_userdata($user_id);
        if ($user) {
            error_log("Wicket Sync: FluentForms registration detected for user {$user_id}");
            $this->perform_user_sync($user, 'fluent_registration');
        }
    }
    
    /**
     * Check if we should sync after registration on wp_loaded
     * This catches cases where users are auto-logged in after registration
     */
    public function maybe_sync_after_registration() {
        if (!is_user_logged_in()) {
            return;
        }
        
        $current_user = wp_get_current_user();
        if (!$current_user || !$current_user->exists()) {
            return;
        }
        
        // Check if this user was just registered (within last 5 minutes)
        $user_registered = strtotime($current_user->user_registered);
        $time_since_registration = time() - $user_registered;
        
        if ($time_since_registration <= 300) { // 5 minutes
            // Check if we've already synced this registration
            $registration_sync = get_user_meta($current_user->ID, 'wicket_registration_sync_done', true);
            
            if (!$registration_sync) {
                error_log("Wicket Sync: Post-registration sync for user {$current_user->ID}");
                $this->perform_user_sync($current_user, 'post_registration');
                
                // Mark registration sync as done
                update_user_meta($current_user->ID, 'wicket_registration_sync_done', current_time('mysql'));
            }
        }
    }
    
    /**
     * Maybe sync when current user is set (catches auto-login scenarios)
     */
    public function maybe_sync_current_user($user_id) {
        if (!$user_id || $user_id === 0) {
            return;
        }
        
        $user = get_userdata($user_id);
        if (!$user || !$user->exists()) {
            return;
        }
        
        // Only sync recently registered users via this hook
        $user_registered = strtotime($user->user_registered);
        $time_since_registration = time() - $user_registered;
        
        if ($time_since_registration <= 300) { // 5 minutes
            // Check if we've already synced this via other hooks
            $last_sync = get_user_meta($user_id, 'wicket_last_login_sync', true);
            
            if (!$last_sync || (time() - strtotime($last_sync)) > 60) { // Don't sync if synced in last minute
                error_log("Wicket Sync: Current user sync for recently registered user {$user_id}");
                $this->perform_user_sync($user, 'current_user_set');
            }
        }
    }
    
    /**
     * Centralized sync performance function
     */
    private function perform_user_sync($user, $sync_source = 'unknown') {
        // Only proceed if this is a valid user object
        if (!$user || !isset($user->ID)) {
            return;
        }
        
        // Check if user sync is enabled
        if (!get_option('wicket_login_sync_enabled', 1)) {
            return;
        }
        
        // Get Wicket configuration
        $config = $this->get_wicket_config();
        if (!$config['valid']) {
            error_log('Wicket Login Sync: Configuration invalid - ' . $config['error']);
            return;
        }
        
        // For registration syncs, be less restrictive with throttling
        $sync_interval = $sync_source === 'login' 
            ? get_option('wicket_login_sync_interval', 3600) 
            : 60; // Only 1 minute throttle for registration syncs
        
        // Check if we should throttle syncs to avoid API overload
        $last_sync = get_user_meta($user->ID, 'wicket_last_login_sync', true);
        
        if ($last_sync && (time() - strtotime($last_sync)) < $sync_interval) {
            error_log("Wicket Sync: Skipping {$sync_source} sync for user {$user->ID} - too recent (last: {$last_sync})");
            return;
        }
        
        error_log("Wicket Sync: Starting {$sync_source} sync for user {$user->ID} ({$user->user_email})");
        
        // Attempt sync by email first
        $result = $this->sync_user_by_email($user->user_email, $user->ID, $config);
        
        // If email sync fails, try by stored Wicket UUID
        if (is_wp_error($result)) {
            $wicket_uuid = get_user_meta($user->ID, 'wicket_uuid', true);
            if (!empty($wicket_uuid)) {
                $result = $this->sync_user_by_uuid($wicket_uuid, $user->ID, $config);
            }
        }
        
        // Log results
        if (is_wp_error($result)) {
            error_log("Wicket {$sync_source} sync failed for user {$user->ID} ({$user->user_email}): " . $result->get_error_message());
        } else {
            error_log("Wicket {$sync_source} sync successful for user {$user->ID} - synced " . count($result['saved_fields']) . " fields");
            // Update last sync timestamp
            update_user_meta($user->ID, 'wicket_last_login_sync', current_time('mysql'));
            update_user_meta($user->ID, 'wicket_last_sync_source', $sync_source);
        }
    }
    
    /**
     * Get and validate Wicket configuration
     */
    private function get_wicket_config() {
        $tenant = get_option('wicket_tenant_name');
        $api_secret_key = get_option('wicket_api_secret_key');
        $admin_user_uuid = get_option('wicket_admin_user_uuid');
        $is_staging = get_option('wicket_staging', 0);
        
        if (empty($tenant)) {
            return array('valid' => false, 'error' => 'Tenant name missing');
        }
        
        if (empty($api_secret_key)) {
            return array('valid' => false, 'error' => 'API secret key missing');
        }
        
        if (empty($admin_user_uuid)) {
            return array('valid' => false, 'error' => 'Admin user UUID missing');
        }
        
        return array(
            'valid' => true,
            'tenant' => $tenant,
            'api_secret_key' => $api_secret_key,
            'admin_user_uuid' => $admin_user_uuid,
            'is_staging' => $is_staging
        );
    }
    
    /**
     * Sync user by email address
     * UPDATED: Added include=addresses,phones,emails
     */
    private function sync_user_by_email($email, $user_id, $config) {
        // Generate JWT token
        $jwt_token = $this->generate_jwt_token($config);
        if (is_wp_error($jwt_token)) {
            return $jwt_token;
        }
        
        // Build API URL for email search - UPDATED: added includes
        $base_url = $config['is_staging'] 
            ? "https://{$config['tenant']}-api.staging.wicketcloud.com"
            : "https://{$config['tenant']}-api.wicketcloud.com";
            
        $api_url = $base_url . "/people?filter[emails_address_eq]=" . urlencode($email) . "&include=addresses,phones,emails";
        
        // Make API request
        $response = $this->make_api_request($api_url, $jwt_token);
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Parse response
        $data = json_decode($response, true);
        if (!isset($data['data']) || empty($data['data'])) {
            return new WP_Error('person_not_found', 'No Wicket person found with email: ' . $email);
        }
        
        // Get first person and sync - UPDATED: pass included data
        $person = $data['data'][0];
        $included = $data['included'] ?? array();
        
        return $this->sync_user_data($person, $user_id, $included);
    }
    
    /**
     * Sync user by Wicket UUID
     * UPDATED: Added include=addresses,phones,emails
     */
    private function sync_user_by_uuid($uuid, $user_id, $config) {
        // Generate JWT token
        $jwt_token = $this->generate_jwt_token($config);
        if (is_wp_error($jwt_token)) {
            return $jwt_token;
        }
        
        // Build API URL for person fetch - UPDATED: added includes
        $base_url = $config['is_staging'] 
            ? "https://{$config['tenant']}-api.staging.wicketcloud.com"
            : "https://{$config['tenant']}-api.wicketcloud.com";
            
        $api_url = $base_url . "/people/" . $uuid . "?include=addresses,phones,emails";
        
        // Make API request
        $response = $this->make_api_request($api_url, $jwt_token);
        if (is_wp_error($response)) {
            return $response;
        }
        
        // Parse response
        $data = json_decode($response, true);
        if (!isset($data['data'])) {
            return new WP_Error('invalid_response', 'Invalid API response format');
        }
        
        // UPDATED: pass included data
        $included = $data['included'] ?? array();
        
        return $this->sync_user_data($data['data'], $user_id, $included);
    }
    
    /**
     * Generate JWT token for Wicket API
     */
    private function generate_jwt_token($config) {
        // JWT header
        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        
        // Build audience URL
        $audience = $config['is_staging'] 
            ? "https://{$config['tenant']}-api.staging.wicketcloud.com"
            : "https://{$config['tenant']}-api.wicketcloud.com";
        
        // JWT payload
        $payload = json_encode([
            'exp' => time() + 3600, // 1 hour expiration
            'sub' => $config['admin_user_uuid'],
            'aud' => $audience,
            'iss' => get_site_url()
        ]);
        
        // Base64 encode (URL safe)
        $base64_header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64_payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        // Create signature
        $signature = hash_hmac('sha256', $base64_header . "." . $base64_payload, $config['api_secret_key'], true);
        $base64_signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64_header . "." . $base64_payload . "." . $base64_signature;
    }
    
    /**
     * Make API request to Wicket
     */
    private function make_api_request($url, $jwt_token) {
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30,
            'user-agent' => 'WordPress-WicketSync/1.0'
        );
        
        $response = wp_remote_get($url, $args);
        
        if (is_wp_error($response)) {
            return new WP_Error('api_request_failed', 'API request failed: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            return new WP_Error('api_error', "API returned error {$response_code}: {$body}");
        }
        
        return $body;
    }
    
    /**
     * NEW: Helper function to get primary item from included data
     */
    private function get_primary_from_included($included_data, $type) {
        if (empty($included_data)) {
            return null;
        }
        
        // First try to find a primary item
        foreach ($included_data as $item) {
            if ($item['type'] === $type && !empty($item['attributes']['primary'])) {
                return $item;
            }
        }
        
        // If no primary found, return the first item of the type
        foreach ($included_data as $item) {
            if ($item['type'] === $type) {
                return $item;
            }
        }
        
        return null;
    }
    
    /**
     * Sync Wicket person data to WordPress user
     * UPDATED: Added included_data parameter and new fields
     */
    private function sync_user_data($person_data, $user_id, $included_data = array()) {
        $attributes = $person_data['attributes'] ?? array();
        $saved_fields = array();
        
        // Only sync if we have valid user
        if (!get_userdata($user_id)) {
            return new WP_Error('user_not_found', 'WordPress user not found');
        }
        
        // UPDATED: Field mapping - expanded with new fields
        $field_mapping = array(
            // Core identity fields
            'uuid' => 'wicket_uuid',
            'given_name' => 'first_name',
            'family_name' => 'last_name',
            'full_name' => 'wicket_full_name',
            'alternate_name' => 'wicket_alternate_name',
            'additional_name' => 'wicket_additional_name',
            'nickname' => 'wicket_nickname',
            
            // NEW: Professional fields
            'job_title' => 'wicket_job_title',
            'job_level' => 'wicket_job_level',
            'job_function' => 'wicket_job_function',
            
            // NEW: Personal fields
            'gender' => 'wicket_gender',
            'birth_date' => 'wicket_birth_date',
            'preferred_pronoun' => 'wicket_preferred_pronoun',
            'honorific_prefix' => 'wicket_honorific_prefix',
            'honorific_suffix' => 'wicket_honorific_suffix',
            
            // Membership fields
            'membership_number' => 'wicket_membership_number',
            'membership_began_on' => 'wicket_membership_began_on',
            
            // Status fields
            'updated_at' => 'wicket_updated_at',
            'created_at' => 'wicket_created_at',
            'role_names' => 'wicket_roles',
            'tags' => 'wicket_tags',
            'language' => 'wicket_language',
            
            // Email (primary)
            'primary_email_address' => 'wicket_primary_email',
        );
        
        // Update WordPress core fields if enabled
        if (get_option('wicket_login_sync_core_fields', 1)) {
            $wp_user_data = array('ID' => $user_id);
            
            if (isset($attributes['given_name']) && !empty($attributes['given_name'])) {
                $wp_user_data['first_name'] = sanitize_text_field($attributes['given_name']);
            }
            
            if (isset($attributes['family_name']) && !empty($attributes['family_name'])) {
                $wp_user_data['last_name'] = sanitize_text_field($attributes['family_name']);
            }
            
            if (count($wp_user_data) > 1) {
                wp_update_user($wp_user_data);
                $saved_fields[] = 'core_wp_fields';
            }
        }
        
        // Sync user meta fields from attributes
        foreach ($field_mapping as $wicket_field => $wp_field) {
            if (isset($attributes[$wicket_field])) {
                $value = $attributes[$wicket_field];
                
                // Handle array fields
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                
                // Sanitize and save
                $value = is_string($value) ? sanitize_text_field($value) : $value;
                update_user_meta($user_id, $wp_field, $value);
                $saved_fields[] = $wp_field;
            }
        }
        
        // =====================================================
        // NEW: Sync PRIMARY ADDRESS from included data
        // =====================================================
        $primary_address = $this->get_primary_from_included($included_data, 'addresses');
        if ($primary_address && isset($primary_address['attributes'])) {
            $addr_attrs = $primary_address['attributes'];
            
            $address_field_mapping = array(
                'address1' => 'wicket_address1',
                'address2' => 'wicket_address2',
                'city' => 'wicket_city',
                'state_name' => 'wicket_state',
                'zip_code' => 'wicket_zip_code',
                'country_code' => 'wicket_country_code',
                'country_name' => 'wicket_country_name',
                'company_name' => 'wicket_company_name',
                'department' => 'wicket_department',
                'division' => 'wicket_division',
                'formatted_address_label' => 'wicket_formatted_address',
                'latitude' => 'wicket_latitude',
                'longitude' => 'wicket_longitude',
            );
            
            foreach ($address_field_mapping as $wicket_key => $meta_key) {
                if (isset($addr_attrs[$wicket_key]) && $addr_attrs[$wicket_key] !== null) {
                    $value = $addr_attrs[$wicket_key];
                    // For formatted_address_label, use textarea sanitization to preserve newlines
                    if ($wicket_key === 'formatted_address_label') {
                        $value = sanitize_textarea_field($value);
                    } else {
                        $value = is_string($value) ? sanitize_text_field($value) : $value;
                    }
                    update_user_meta($user_id, $meta_key, $value);
                    $saved_fields[] = $meta_key;
                }
            }
            
            // Store address UUID for reference
            if (isset($addr_attrs['uuid'])) {
                update_user_meta($user_id, 'wicket_address_uuid', sanitize_text_field($addr_attrs['uuid']));
            }
        }
        
        // =====================================================
        // NEW: Sync PRIMARY PHONE from included data
        // =====================================================
        $primary_phone = $this->get_primary_from_included($included_data, 'phones');
        if ($primary_phone && isset($primary_phone['attributes'])) {
            $phone_attrs = $primary_phone['attributes'];
            
            $phone_field_mapping = array(
                'number' => 'wicket_phone',
                'number_national_format' => 'wicket_phone_national',
                'number_international_format' => 'wicket_phone_international',
                'type' => 'wicket_phone_type',
                'extension' => 'wicket_phone_extension',
                'country_code_number' => 'wicket_phone_country_code',
            );
            
            foreach ($phone_field_mapping as $wicket_key => $meta_key) {
                if (isset($phone_attrs[$wicket_key]) && $phone_attrs[$wicket_key] !== null) {
                    $value = is_string($phone_attrs[$wicket_key]) 
                        ? sanitize_text_field($phone_attrs[$wicket_key]) 
                        : $phone_attrs[$wicket_key];
                    update_user_meta($user_id, $meta_key, $value);
                    $saved_fields[] = $meta_key;
                }
            }
            
            // Store phone UUID for reference
            if (isset($phone_attrs['uuid'])) {
                update_user_meta($user_id, 'wicket_phone_uuid', sanitize_text_field($phone_attrs['uuid']));
            }
        }
        
        // =====================================================
        // Sync PRIMARY COMPANY and SECTION from connections
        // =====================================================
        $person_uuid = $attributes['uuid'] ?? null;
        if ($person_uuid) {
            $org_section_data = $this->sync_user_company_and_section($user_id, $person_uuid);
            if ($org_section_data && isset($org_section_data['saved_fields'])) {
                $saved_fields = array_merge($saved_fields, $org_section_data['saved_fields']);
            }
        }
        
        // Sync communication preferences if enabled
        if (get_option('wicket_login_sync_communications', 1) && isset($attributes['data']['communications'])) {
            $communications = $attributes['data']['communications'];
            
            if (isset($communications['email'])) {
                update_user_meta($user_id, 'wicket_email_communications', $communications['email'] ? 'yes' : 'no');
                $saved_fields[] = 'wicket_email_communications';
            }
            
            // Sync sublists
            if (isset($communications['sublists']) && is_array($communications['sublists'])) {
                foreach ($communications['sublists'] as $sublist => $enabled) {
                    $meta_key = "wicket_sublist_{$sublist}";
                    update_user_meta($user_id, $meta_key, $enabled ? 'yes' : 'no');
                    $saved_fields[] = $meta_key;
                }
            }
        }
        
        // Store sync metadata
        update_user_meta($user_id, 'wicket_sync_count', (int)get_user_meta($user_id, 'wicket_sync_count', true) + 1);
        
        return array(
            'success' => true,
            'user_id' => $user_id,
            'person_uuid' => $attributes['uuid'] ?? 'unknown',
            'saved_fields' => $saved_fields,
            'message' => 'User data synced successfully'
        );
    }
    
    /**
     * Sync user's primary company AND section from Wicket connections in a single API call
     *
     * This fetches the user's connections ONCE and identifies both:
     * - Organization with type="company"
     * - Organization with type="section"
     *
     * Organization details are extracted from the 'included' array in the API response,
     * avoiding separate API calls for each organization.
     *
     * Only active connections are processed (based on starts_at/ends_at dates).
     *
     * @param int $user_id WordPress user ID
     * @param string $person_uuid Wicket person UUID
     * @return array|null Result with saved fields or null on failure
     */
    private function sync_user_company_and_section($user_id, $person_uuid) {
        if (empty($person_uuid)) {
            return null;
        }

        $config = $this->get_wicket_config();
        if (!$config['valid']) {
            error_log("[Wicket Sync] Configuration invalid - " . ($config['error'] ?? 'unknown'));
            return null;
        }

        error_log("[Wicket Sync] Syncing company and section for person {$person_uuid}");

        $jwt_token = $this->generate_jwt_token($config);
        if (is_wp_error($jwt_token)) {
            error_log("[Wicket Sync] Failed to generate JWT");
            return null;
        }

        $base_url = $config['is_staging']
            ? "https://{$config['tenant']}-api.staging.wicketcloud.com"
            : "https://{$config['tenant']}-api.wicketcloud.com";

        // Single API call - connections endpoint includes organization data
        $api_url = $base_url . "/people/{$person_uuid}/connections?filter[connection_type_eq]=person_to_organization";
        $response = $this->make_api_request($api_url, $jwt_token);

        if (is_wp_error($response)) {
            error_log("[Wicket Sync] Failed to get connections: " . $response->get_error_message());
            return null;
        }

        $data = json_decode($response, true);
        $connections = $data['data'] ?? array();
        $included = $data['included'] ?? array();

        if (empty($connections)) {
            error_log("[Wicket Sync] No connections found for person {$person_uuid}");
            $this->clear_org_meta($user_id);
            $this->clear_section_meta($user_id);
            return array('saved_fields' => array());
        }

        error_log("[Wicket Sync] Found " . count($connections) . " connections");

        // Build a lookup map of organizations from the included array
        $org_lookup = array();
        foreach ($included as $item) {
            if (($item['type'] ?? '') === 'organizations') {
                $org_lookup[$item['id']] = $item['attributes'] ?? array();
            }
        }

        error_log("[Wicket Sync] Found " . count($org_lookup) . " organizations in included data");

        // Track what we find
        $found_company = null;
        $found_company_conn = null;
        $found_section = null;
        $found_section_conn = null;

        // Single loop through all connections - no additional API calls needed
        foreach ($connections as $conn) {
            // Skip inactive connections (based on starts_at/ends_at dates)
            if (!$this->is_connection_active($conn)) {
                error_log("[Wicket Sync] Skipping inactive connection: " . ($conn['id'] ?? 'unknown'));
                continue;
            }

            // Already found both? Stop early
            if ($found_company && $found_section) {
                break;
            }

            $org_uuid = $conn['relationships']['to']['data']['id'] ??
                        $conn['relationships']['organization']['data']['id'] ?? null;

            if (empty($org_uuid)) {
                continue;
            }

            // Get organization details from included data (no API call)
            if (!isset($org_lookup[$org_uuid])) {
                error_log("[Wicket Sync] Organization {$org_uuid} not found in included data");
                continue;
            }

            $org_attrs = $org_lookup[$org_uuid];
            $org_type = strtolower(trim($org_attrs['type'] ?? ''));

            // Check for company
            if (!$found_company && $org_type === 'company') {
                $found_company = array('uuid' => $org_uuid, 'attrs' => $org_attrs);
                $found_company_conn = $conn;
                error_log("[Wicket Sync] Found company: {$org_uuid} - " . ($org_attrs['legal_name'] ?? 'unknown'));
            }

            // Check for section
            if (!$found_section && $org_type === 'section') {
                $found_section = array('uuid' => $org_uuid, 'attrs' => $org_attrs);
                $found_section_conn = $conn;
                error_log("[Wicket Sync] Found section: {$org_uuid} - " . ($org_attrs['legal_name'] ?? 'unknown'));
            }
        }

        $saved_fields = array();

        // Save company data
        if ($found_company) {
            $company_fields = $this->save_company_meta($user_id, $found_company, $found_company_conn);
            $saved_fields = array_merge($saved_fields, $company_fields);
        } else {
            error_log("[Wicket Sync] No active company connection found for person {$person_uuid}");
            $this->clear_org_meta($user_id);
        }

        // Save section data
        if ($found_section) {
            $section_fields = $this->save_section_meta($user_id, $found_section, $found_section_conn);
            $saved_fields = array_merge($saved_fields, $section_fields);
        } else {
            error_log("[Wicket Sync] No active section connection found for person {$person_uuid}");
            $this->clear_section_meta($user_id);
        }

        return array(
            'saved_fields' => $saved_fields,
            'company' => $found_company,
            'section' => $found_section
        );
    }

    /**
     * Save company meta to user
     */
    private function save_company_meta($user_id, $company_data, $connection) {
        $saved_fields = array();
        $org_uuid = $company_data['uuid'];
        $org_attrs = $company_data['attrs'];
        $connection_uuid = $connection['id'] ?? null;
        $connection_type = $connection['attributes']['type'] ?? 'member';

        $field_mapping = array(
            'wicket_org_uuid' => $org_uuid,
            'wicket_org_name' => $org_attrs['legal_name'] ?? '',
            'wicket_org_type' => $org_attrs['type'] ?? '',
            'wicket_org_alternate_name' => $org_attrs['alternate_name'] ?? '',
            'wicket_org_description' => $org_attrs['description'] ?? '',
            'wicket_org_slug' => $org_attrs['slug'] ?? '',
            'wicket_connection_uuid' => $connection_uuid,
            'wicket_connection_type' => $connection_type,
        );

        foreach ($field_mapping as $meta_key => $value) {
            if ($value !== null && $value !== '') {
                $sanitized_value = is_string($value) ? sanitize_text_field($value) : $value;
                update_user_meta($user_id, $meta_key, $sanitized_value);
                $saved_fields[] = $meta_key;
            }
        }

        update_user_meta($user_id, 'wicket_primary_org_uuid', $org_uuid);
        $saved_fields[] = 'wicket_primary_org_uuid';

        error_log("[Wicket Sync] Saved company '" . ($org_attrs['legal_name'] ?? 'unknown') . "' to user meta");

        return $saved_fields;
    }

    /**
     * Save section meta to user
     */
    private function save_section_meta($user_id, $section_data, $connection) {
        $saved_fields = array();
        $section_uuid = $section_data['uuid'];
        $section_attrs = $section_data['attrs'];
        $connection_uuid = $connection['id'] ?? null;
        $connection_type = $connection['attributes']['type'] ?? 'member';

        $field_mapping = array(
            'wicket_section_uuid' => $section_uuid,
            'wicket_section_name' => $section_attrs['legal_name'] ?? '',
            'wicket_section_alternate_name' => $section_attrs['alternate_name'] ?? '',
            'wicket_section_description' => $section_attrs['description'] ?? '',
            'wicket_section_slug' => $section_attrs['slug'] ?? '',
            'wicket_section_connection_uuid' => $connection_uuid,
            'wicket_section_connection_type' => $connection_type,
        );

        foreach ($field_mapping as $meta_key => $value) {
            if ($value !== null && $value !== '') {
                $sanitized_value = is_string($value) ? sanitize_text_field($value) : $value;
                update_user_meta($user_id, $meta_key, $sanitized_value);
                $saved_fields[] = $meta_key;
            }
        }

        update_user_meta($user_id, 'wicket_primary_section_uuid', $section_uuid);
        $saved_fields[] = 'wicket_primary_section_uuid';

        error_log("[Wicket Sync] Saved section '" . ($section_attrs['legal_name'] ?? 'unknown') . "' to user meta");

        return $saved_fields;
    }

    /**
     * Legacy wrapper: Sync user's primary organization (company) from Wicket connections
     *
     * @deprecated Use sync_user_company_and_section() for better efficiency
     * @param int $user_id WordPress user ID
     * @param string $person_uuid Wicket person UUID
     * @return array|null Result with saved fields or null on failure
     */
    private function sync_user_organization($user_id, $person_uuid) {
        $result = $this->sync_user_company_and_section($user_id, $person_uuid);
        if (!$result) {
            return null;
        }

        return array(
            'saved_fields' => array_filter($result['saved_fields'], function($field) {
                return strpos($field, 'wicket_org') === 0 || $field === 'wicket_connection_uuid' || $field === 'wicket_connection_type' || $field === 'wicket_primary_org_uuid';
            }),
            'org_uuid' => $result['company']['uuid'] ?? null,
            'org_name' => $result['company']['attrs']['legal_name'] ?? ''
        );
    }
    
    /**
     * Check if a connection is active based on starts_at and ends_at dates
     *
     * A connection is active when the current UTC time falls between starts_at and ends_at.
     * If starts_at is null, the connection is considered to have started.
     * If ends_at is null, the connection is considered open-ended (no end date).
     *
     * @param array $connection Connection data from API
     * @return bool True if connection is active
     */
    private function is_connection_active($connection) {
        $attributes = $connection['attributes'] ?? array();
        $starts_at = $attributes['starts_at'] ?? null;
        $ends_at = $attributes['ends_at'] ?? null;

        $now = time();

        // Check starts_at: if set, current time must be >= starts_at
        if (!empty($starts_at)) {
            $start_time = strtotime($starts_at);
            if ($start_time && $now < $start_time) {
                return false; // Connection hasn't started yet
            }
        }

        // Check ends_at: if set, current time must be < ends_at
        if (!empty($ends_at)) {
            $end_time = strtotime($ends_at);
            if ($end_time && $now >= $end_time) {
                return false; // Connection has ended
            }
        }

        return true;
    }

    /**
     * Clear organization meta for a user
     *
     * @param int $user_id WordPress user ID
     */
    private function clear_org_meta($user_id) {
        $org_meta_keys = array(
            'wicket_org_uuid',
            'wicket_org_name',
            'wicket_org_type',
            'wicket_org_alternate_name',
            'wicket_org_description',
            'wicket_org_slug',
            'wicket_connection_uuid',
            'wicket_connection_type',
            'wicket_primary_org_uuid'
        );
        
        foreach ($org_meta_keys as $meta_key) {
            delete_user_meta($user_id, $meta_key);
        }
    }

    /**
     * Legacy wrapper: Sync user's primary section from Wicket connections
     *
     * @deprecated Use sync_user_company_and_section() for better efficiency
     * @param int $user_id WordPress user ID
     * @param string $person_uuid Wicket person UUID
     * @return array|null Result with saved fields or null on failure
     */
    private function sync_user_section($user_id, $person_uuid) {
        $result = $this->sync_user_company_and_section($user_id, $person_uuid);
        if (!$result) {
            return null;
        }

        return array(
            'saved_fields' => array_filter($result['saved_fields'], function($field) {
                return strpos($field, 'wicket_section') === 0 || $field === 'wicket_primary_section_uuid';
            }),
            'section_uuid' => $result['section']['uuid'] ?? null,
            'section_name' => $result['section']['attrs']['legal_name'] ?? ''
        );
    }
    
    /**
     * Clear section meta for a user
     */
    private function clear_section_meta($user_id) {
        $section_meta_keys = array(
            'wicket_section_uuid',
            'wicket_section_name',
            'wicket_section_alternate_name',
            'wicket_section_description',
            'wicket_section_slug',
            'wicket_section_connection_uuid',
            'wicket_section_connection_type',
            'wicket_primary_section_uuid'
        );
        
        foreach ($section_meta_keys as $meta_key) {
            delete_user_meta($user_id, $meta_key);
        }
    }

    /**
     * Public wrapper for sync_user_organization (used by WicketACFSync manual sync)
     */
    public function sync_user_organization_public($user_id, $person_uuid) {
        return $this->sync_user_organization($user_id, $person_uuid);
    }
    
    /**
     * Public wrapper for sync_user_section (used by WicketACFSync manual sync)
     */
    public function sync_user_section_public($user_id, $person_uuid) {
        return $this->sync_user_section($user_id, $person_uuid);
    }
    
    /**
     * Register admin settings
     */
    public function register_settings() {
        register_setting('wicket_login_sync', 'wicket_login_sync_enabled');
        register_setting('wicket_login_sync', 'wicket_login_sync_interval');
        register_setting('wicket_login_sync', 'wicket_login_sync_core_fields');
        register_setting('wicket_login_sync', 'wicket_login_sync_communications');
        register_setting('wicket_login_sync', 'wicket_registration_sync_enabled');
    }
    
}

// Initialize the sync system
$GLOBALS['wicket_login_sync_instance'] = new WicketLoginSync();