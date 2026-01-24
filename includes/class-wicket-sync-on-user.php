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
        // Add admin hooks for configuration if needed
        if (is_admin()) {
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_menu', array($this, 'add_admin_menu'));
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
        // NEW: Sync PRIMARY ORGANIZATION from connections
        // =====================================================
        $person_uuid = $attributes['uuid'] ?? null;
        if ($person_uuid) {
            $org_data = $this->sync_user_organization($user_id, $person_uuid);
            if ($org_data && isset($org_data['saved_fields'])) {
                $saved_fields = array_merge($saved_fields, $org_data['saved_fields']);
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
     * Sync user's primary organization from Wicket connections
     * 
     * This fetches the user's connections and saves the primary organization
     * to user meta, similar to how address and phone are synced.
     * 
     * Meta keys saved:
     * - wicket_org_uuid: Organization UUID
     * - wicket_org_name: Organization legal name
     * - wicket_org_type: Organization type
     * - wicket_org_alternate_name: Organization alternate name
     * - wicket_connection_uuid: Connection UUID
     * - wicket_connection_type: Connection type (member, etc.)
     * 
     * @param int $user_id WordPress user ID
     * @param string $person_uuid Wicket person UUID
     * @return array|null Result with saved fields or null on failure
     */
    private function sync_user_organization($user_id, $person_uuid) {
        if (empty($person_uuid)) {
            return null;
        }
        
        // Get Wicket configuration
        $config = $this->get_wicket_config();
        if (!$config['valid']) {
            error_log("Wicket Sync Org: Configuration invalid - " . ($config['error'] ?? 'unknown'));
            return null;
        }
        
        error_log("Wicket Sync: Syncing organization for person {$person_uuid}");
        
        // Generate JWT token
        $jwt_token = $this->generate_jwt_token($config);
        if (is_wp_error($jwt_token)) {
            error_log("Wicket Sync: Failed to generate JWT for org sync");
            return null;
        }
        
        // Build API URL for connections
        $base_url = $config['is_staging'] 
            ? "https://{$config['tenant']}-api.staging.wicketcloud.com"
            : "https://{$config['tenant']}-api.wicketcloud.com";
            
        $api_url = $base_url . "/people/{$person_uuid}/connections?filter[connection_type_eq]=person_to_organization";
        
        // Make API request
        $response = $this->make_api_request($api_url, $jwt_token);
        if (is_wp_error($response)) {
            error_log("Wicket Sync: Failed to get connections: " . $response->get_error_message());
            return null;
        }
        
        // Parse response
        $data = json_decode($response, true);
        $connections = $data['data'] ?? array();
        
        if (empty($connections)) {
            error_log("Wicket Sync: No organization connections found for person {$person_uuid}");
            // Clear existing org meta if no connections
            $this->clear_org_meta($user_id);
            return array('saved_fields' => array());
        }
        
        error_log("Wicket Sync: Found " . count($connections) . " organization connections");
        
        // Get the first (primary) connection
        $connection = $connections[0];
        $connection_uuid = $connection['id'] ?? null;
        $connection_type = $connection['attributes']['type'] ?? 'member';
        
        // Get organization UUID from the connection
        $org_uuid = $connection['relationships']['to']['data']['id'] ?? 
                   $connection['relationships']['organization']['data']['id'] ?? null;
        
        if (empty($org_uuid)) {
            error_log("Wicket Sync: Connection found but no organization UUID");
            return null;
        }
        
        error_log("Wicket Sync: Primary org UUID: {$org_uuid}");
        
        // Fetch organization details
        $org_url = $base_url . "/organizations/{$org_uuid}";
        $org_response = $this->make_api_request($org_url, $jwt_token);
        
        if (is_wp_error($org_response)) {
            error_log("Wicket Sync: Failed to get organization details: " . $org_response->get_error_message());
            return null;
        }
        
        $org_data = json_decode($org_response, true);
        $org_attrs = $org_data['data']['attributes'] ?? array();
        
        // Save organization data to user meta
        $saved_fields = array();
        
        // Organization fields mapping
        $org_field_mapping = array(
            'wicket_org_uuid' => $org_uuid,
            'wicket_org_name' => $org_attrs['legal_name'] ?? '',
            'wicket_org_type' => $org_attrs['type'] ?? '',
            'wicket_org_alternate_name' => $org_attrs['alternate_name'] ?? '',
            'wicket_org_description' => $org_attrs['description'] ?? '',
            'wicket_org_slug' => $org_attrs['slug'] ?? '',
            'wicket_connection_uuid' => $connection_uuid,
            'wicket_connection_type' => $connection_type,
        );
        
        foreach ($org_field_mapping as $meta_key => $value) {
            if ($value !== null && $value !== '') {
                $sanitized_value = is_string($value) ? sanitize_text_field($value) : $value;
                update_user_meta($user_id, $meta_key, $sanitized_value);
                $saved_fields[] = $meta_key;
                error_log("Wicket Sync: Saved {$meta_key} = {$sanitized_value}");
            }
        }
        
        // Also update the legacy primary_org_uuid for backwards compatibility
        update_user_meta($user_id, 'wicket_primary_org_uuid', $org_uuid);
        $saved_fields[] = 'wicket_primary_org_uuid';
        
        error_log("Wicket Sync: Saved organization '" . ($org_attrs['legal_name'] ?? 'unknown') . "' to user meta");
        
        return array(
            'saved_fields' => $saved_fields,
            'org_uuid' => $org_uuid,
            'org_name' => $org_attrs['legal_name'] ?? ''
        );
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
     * Register admin settings
     */
    public function register_settings() {
        register_setting('wicket_login_sync', 'wicket_login_sync_enabled');
        register_setting('wicket_login_sync', 'wicket_login_sync_interval');
        register_setting('wicket_login_sync', 'wicket_login_sync_core_fields');
        register_setting('wicket_login_sync', 'wicket_login_sync_communications');
        register_setting('wicket_login_sync', 'wicket_registration_sync_enabled');
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            'Wicket Login Sync Settings',
            'Wicket Login Sync',
            'manage_options',
            'wicket-login-sync',
            array($this, 'admin_page')
        );
    }
    
    /**
     * Admin settings page
     */
    public function admin_page() {
        if (isset($_POST['submit'])) {
            update_option('wicket_login_sync_enabled', isset($_POST['wicket_login_sync_enabled']) ? 1 : 0);
            update_option('wicket_login_sync_interval', (int)$_POST['wicket_login_sync_interval']);
            update_option('wicket_login_sync_core_fields', isset($_POST['wicket_login_sync_core_fields']) ? 1 : 0);
            update_option('wicket_login_sync_communications', isset($_POST['wicket_login_sync_communications']) ? 1 : 0);
            update_option('wicket_registration_sync_enabled', isset($_POST['wicket_registration_sync_enabled']) ? 1 : 0);
            echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
        }
        
        $enabled = get_option('wicket_login_sync_enabled', 1);
        $interval = get_option('wicket_login_sync_interval', 3600);
        $core_fields = get_option('wicket_login_sync_core_fields', 1);
        $communications = get_option('wicket_login_sync_communications', 1);
        $registration_sync = get_option('wicket_registration_sync_enabled', 1);
        ?>
        <div class="wrap">
            <h1>Wicket Login Sync Settings</h1>
            <form method="post" action="">
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Login Sync</th>
                        <td>
                            <input type="checkbox" name="wicket_login_sync_enabled" value="1" <?php checked($enabled); ?> />
                            <p class="description">Enable automatic user sync on login</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Enable Registration Sync</th>
                        <td>
                            <input type="checkbox" name="wicket_registration_sync_enabled" value="1" <?php checked($registration_sync); ?> />
                            <p class="description">Enable automatic user sync after registration (including auto-login)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Sync Interval (seconds)</th>
                        <td>
                            <input type="number" name="wicket_login_sync_interval" value="<?php echo esc_attr($interval); ?>" min="300" />
                            <p class="description">Minimum time between syncs for the same user (default: 3600 = 1 hour). Registration syncs use a 1-minute interval.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Sync Core Fields</th>
                        <td>
                            <input type="checkbox" name="wicket_login_sync_core_fields" value="1" <?php checked($core_fields); ?> />
                            <p class="description">Update WordPress first_name and last_name fields</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Sync Communications</th>
                        <td>
                            <input type="checkbox" name="wicket_login_sync_communications" value="1" <?php checked($communications); ?> />
                            <p class="description">Sync communication preferences and sublists</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
            
            <h2>Configuration Status</h2>
            <?php
            $config = $this->get_wicket_config();
            if ($config['valid']) {
                echo '<p style="color: green;">✓ Wicket API configuration is valid</p>';
                echo '<p><strong>Tenant:</strong> ' . esc_html($config['tenant']) . '</p>';
                echo '<p><strong>Environment:</strong> ' . ($config['is_staging'] ? 'Staging' : 'Production') . '</p>';
            } else {
                echo '<p style="color: red;">✗ Configuration Error: ' . esc_html($config['error']) . '</p>';
                echo '<p>Please check your Wicket API settings.</p>';
            }
            ?>
            
            <h2>Synced Fields Reference</h2>
            <p>The following user meta fields are synced from Wicket:</p>
            <table class="widefat" style="max-width: 800px;">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Wicket Field</th>
                        <th>WordPress Meta Key</th>
                    </tr>
                </thead>
                <tbody>
                    <tr><td rowspan="4"><strong>Identity</strong></td><td>uuid</td><td><code>wicket_uuid</code></td></tr>
                    <tr><td>full_name</td><td><code>wicket_full_name</code></td></tr>
                    <tr><td>alternate_name</td><td><code>wicket_alternate_name</code></td></tr>
                    <tr><td>nickname</td><td><code>wicket_nickname</code></td></tr>
                    
                    <tr><td rowspan="3"><strong>Professional</strong></td><td>job_title</td><td><code>wicket_job_title</code></td></tr>
                    <tr><td>job_level</td><td><code>wicket_job_level</code></td></tr>
                    <tr><td>job_function</td><td><code>wicket_job_function</code></td></tr>
                    
                    <tr><td rowspan="4"><strong>Personal</strong></td><td>gender</td><td><code>wicket_gender</code></td></tr>
                    <tr><td>birth_date</td><td><code>wicket_birth_date</code></td></tr>
                    <tr><td>preferred_pronoun</td><td><code>wicket_preferred_pronoun</code></td></tr>
                    <tr><td>honorific_prefix</td><td><code>wicket_honorific_prefix</code></td></tr>
                    
                    <tr><td rowspan="4"><strong>Phone</strong></td><td>number</td><td><code>wicket_phone</code></td></tr>
                    <tr><td>number_international_format</td><td><code>wicket_phone_international</code></td></tr>
                    <tr><td>number_national_format</td><td><code>wicket_phone_national</code></td></tr>
                    <tr><td>type</td><td><code>wicket_phone_type</code></td></tr>
                    
                    <tr><td rowspan="7"><strong>Address</strong></td><td>address1</td><td><code>wicket_address1</code></td></tr>
                    <tr><td>address2</td><td><code>wicket_address2</code></td></tr>
                    <tr><td>city</td><td><code>wicket_city</code></td></tr>
                    <tr><td>state_name</td><td><code>wicket_state</code></td></tr>
                    <tr><td>zip_code</td><td><code>wicket_zip_code</code></td></tr>
                    <tr><td>country_name</td><td><code>wicket_country_name</code></td></tr>
                    <tr><td>formatted_address_label</td><td><code>wicket_formatted_address</code></td></tr>
                    
                    <tr><td rowspan="6"><strong>Organization</strong></td><td>org_uuid</td><td><code>wicket_org_uuid</code></td></tr>
                    <tr><td>legal_name</td><td><code>wicket_org_name</code></td></tr>
                    <tr><td>type</td><td><code>wicket_org_type</code></td></tr>
                    <tr><td>alternate_name</td><td><code>wicket_org_alternate_name</code></td></tr>
                    <tr><td>connection_uuid</td><td><code>wicket_connection_uuid</code></td></tr>
                    <tr><td>connection_type</td><td><code>wicket_connection_type</code></td></tr>
                </tbody>
            </table>
            
            <h3>Using Organization in Bricks Builder</h3>
            <p>To display the user's organization in Bricks, use Dynamic Data with user meta:</p>
            <ul>
                <li><code>{user_meta:wicket_org_name}</code> - Organization name</li>
                <li><code>{user_meta:wicket_org_type}</code> - Organization type</li>
                <li><code>{user_meta:wicket_org_uuid}</code> - Organization UUID</li>
            </ul>
            
            <h2>Debug Information</h2>
            <p>If sync isn't working after registration, check your error logs for messages starting with "Wicket Sync:"</p>
            <p><strong>Active Hooks:</strong></p>
            <ul>
                <li>wp_login (normal login)</li>
                <li>user_register (immediately after registration)</li>
                <li>wp_loaded (catches auto-login after registration)</li>
                <li>fluentform_user_registration_completed (FluentForms specific)</li>
                <li>wp_set_current_user (general auto-login detection)</li>
            </ul>
        </div>
        <?php
    }
}

// Initialize the sync system
$GLOBALS['wicket_login_sync_instance'] = new WicketLoginSync();