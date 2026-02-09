<?php
/**
 * Wicket API Helper Class
 *
 * Centralized helper functions for Wicket API integration.
 * All classes should use this singleton for API operations.
 *
 * @package MyIES_Integration
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define plugin debug constant if not set
if (!defined('MYIES_DEBUG')) {
    define('MYIES_DEBUG', defined('WP_DEBUG') && WP_DEBUG);
}

// Define configurable form IDs (can be overridden in wp-config.php)
if (!defined('MYIES_FORM_PERSONAL_DETAILS')) {
    define('MYIES_FORM_PERSONAL_DETAILS', 49);
}
if (!defined('MYIES_FORM_PROFESSIONAL_INFO')) {
    define('MYIES_FORM_PROFESSIONAL_INFO', 50);
}
if (!defined('MYIES_FORM_ADDRESS')) {
    define('MYIES_FORM_ADDRESS', 51);
}
if (!defined('MYIES_FORM_CONTACT_DETAILS')) {
    define('MYIES_FORM_CONTACT_DETAILS', 23);
}

/**
 * Debug logging helper function
 *
 * Only logs when MYIES_DEBUG is true (inherits from WP_DEBUG by default)
 *
 * @param string $message Log message
 * @param string $context Optional context/class name
 */
function myies_log($message, $context = 'MyIES') {
    if (MYIES_DEBUG) {
        error_log("[{$context}] {$message}");
    }
}

class Wicket_API_Helper {

    private static $instance = null;
    private $person_uuid_cache = array();
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {}
    
    /**
     * Get Wicket API base URL
     */
    public function get_api_url() {
        $tenant = get_option('wicket_tenant_name', '');
        $staging = get_option('wicket_staging', 0);
        
        if ($staging) {
            return "https://{$tenant}-api.staging.wicketcloud.com";
        }
        
        return "https://{$tenant}-api.wicketcloud.com";
    }
    
    /**
     * Generate JWT token for Wicket API authentication
     */
    public function generate_jwt_token() {
        $tenant = get_option('wicket_tenant_name', '');
        $api_secret = get_option('wicket_api_secret_key', '');
        $admin_uuid = get_option('wicket_admin_user_uuid', '');
        
        if (empty($tenant) || empty($api_secret) || empty($admin_uuid)) {
            error_log('[Wicket API Helper] Missing API configuration');
            return new WP_Error('missing_credentials', 'Wicket API credentials not configured');
        }
        
        $api_url = $this->get_api_url();
        
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'exp' => time() + 3600,
            'sub' => $admin_uuid,
            'aud' => $api_url,
            'iss' => get_site_url()
        ]);
        
        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, $api_secret, true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64Header . "." . $base64Payload . "." . $base64Signature;
    }
    
    /**
     * Get person UUID for a user
     */
    public function get_person_uuid($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return null;
        }
        
        if (isset($this->person_uuid_cache[$user_id])) {
            return $this->person_uuid_cache[$user_id];
        }
        
        $person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);
        
        if (empty($person_uuid)) {
            $person_uuid = get_user_meta($user_id, 'wicket_uuid', true);
        }
        
        if (empty($person_uuid)) {
            $user = get_userdata($user_id);
            if ($user && !empty($user->user_email)) {
                error_log('[Wicket API Helper] UUID not in meta, searching by email: ' . $user->user_email);
                $person_uuid = $this->find_person_by_email($user->user_email);
                
                if (!empty($person_uuid)) {
                    update_user_meta($user_id, 'wicket_person_uuid', $person_uuid);
                }
            }
        }
        
        if (!empty($person_uuid)) {
            $this->person_uuid_cache[$user_id] = $person_uuid;
        }
        
        return $person_uuid ?: null;
    }
    
    /**
     * Find person UUID by email
     */
    public function find_person_by_email($email) {
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return null;
        }
        
        $endpoint = $this->get_api_url() . '/people?filter[emails_address_eq]=' . urlencode($email);
        
        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code === 200 && !empty($body['data'][0]['id'])) {
            return $body['data'][0]['id'];
        }
        
        return null;
    }
    
    /**
     * Update person attributes in Wicket API
     */
    public function update_person($person_uuid, $attributes) {
        if (empty($person_uuid) || empty($attributes)) {
            return array('success' => false, 'message' => 'Missing person_uuid or attributes');
        }
        
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return array('success' => false, 'message' => $token->get_error_message());
        }
        
        $api_url = $this->get_api_url() . '/people/' . $person_uuid;
        
        $request_body = array(
            'data' => array(
                'type' => 'people',
                'id' => $person_uuid,
                'attributes' => $attributes
            )
        );
        
        error_log('[Wicket API Helper] Updating person: ' . $person_uuid);
        error_log('[Wicket API Helper] Request: ' . json_encode($request_body, JSON_PRETTY_PRINT));
        
        $response = wp_remote_request($api_url, array(
            'method' => 'PATCH',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        error_log('[Wicket API Helper] Response status: ' . $status_code);
        error_log('[Wicket API Helper] Response: ' . $body);
        
        return array(
            'success' => ($status_code === 200),
            'message' => ($status_code === 200) ? 'Updated successfully' : "Error: {$status_code} - {$body}",
            'status_code' => $status_code,
            'data' => json_decode($body, true)
        );
    }
    
    /**
     * Get person data from Wicket
     */
    public function get_person($person_uuid) {
        if (empty($person_uuid)) {
            return null;
        }
        
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return null;
        }
        
        $response = wp_remote_get($this->get_api_url() . '/people/' . $person_uuid, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['data'] ?? null;
    }
    
    // =========================================================================
    // PHONE METHODS
    // =========================================================================
    
    /**
     * Update phone in Wicket API
     * 
     * @param string $phone_uuid Phone UUID
     * @param array $phone_data Phone attributes (number, type, primary, etc.)
     * @return array Result
     */
    public function update_phone($phone_uuid, $phone_data) {
        if (empty($phone_uuid)) {
            return array('success' => false, 'message' => 'Phone UUID is required');
        }
        
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return array('success' => false, 'message' => $token->get_error_message());
        }
        
        $api_url = $this->get_api_url() . '/phones/' . $phone_uuid;
        
        $request_body = array(
            'data' => array(
                'type' => 'phones',
                'id' => $phone_uuid,
                'attributes' => $phone_data
            )
        );
        
        error_log('[Wicket API Helper] Updating phone: ' . $phone_uuid);
        error_log('[Wicket API Helper] Phone data: ' . json_encode($request_body, JSON_PRETTY_PRINT));
        
        $response = wp_remote_request($api_url, array(
            'method' => 'PATCH',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        error_log('[Wicket API Helper] Phone update response: ' . $code . ' - ' . $body);
        
        return array(
            'success' => ($code === 200),
            'message' => ($code === 200) ? 'Phone updated' : "Error: {$code} - {$body}",
            'status_code' => $code
        );
    }
    
    /**
     * Create phone for a person
     */
    public function create_phone($person_uuid, $phone_data) {
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return array('success' => false, 'message' => $token->get_error_message());
        }
        
        $api_url = $this->get_api_url() . '/people/' . $person_uuid . '/phones';
        
        $request_body = array(
            'data' => array(
                'type' => 'phones',
                'attributes' => $phone_data
            )
        );
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return array(
            'success' => ($code == 200 || $code == 201),
            'message' => ($code == 200 || $code == 201) ? 'Phone created' : "Error: {$code}",
            'data' => $body,
            'status_code' => $code
        );
    }
    
    // =========================================================================
    // ADDRESS METHODS
    // =========================================================================
    
    /**
     * Update address in Wicket API
     * 
     * @param string $address_uuid Address UUID
     * @param array $address_data Address attributes
     * @return array Result
     */
    public function update_address($address_uuid, $address_data) {
        if (empty($address_uuid)) {
            return array('success' => false, 'message' => 'Address UUID is required');
        }
        
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return array('success' => false, 'message' => $token->get_error_message());
        }
        
        $api_url = $this->get_api_url() . '/addresses/' . $address_uuid;
        
        $request_body = array(
            'data' => array(
                'type' => 'addresses',
                'id' => $address_uuid,
                'attributes' => $address_data
            )
        );
        
        error_log('[Wicket API Helper] Updating address: ' . $address_uuid);
        error_log('[Wicket API Helper] Address data: ' . json_encode($request_body, JSON_PRETTY_PRINT));
        
        $response = wp_remote_request($api_url, array(
            'method' => 'PATCH',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        error_log('[Wicket API Helper] Address update response: ' . $code . ' - ' . $body);
        
        return array(
            'success' => ($code === 200),
            'message' => ($code === 200) ? 'Address updated' : "Error: {$code} - {$body}",
            'status_code' => $code
        );
    }
    
    /**
     * Create address for a person
     */
    public function create_address($person_uuid, $address_data) {
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return array('success' => false, 'message' => $token->get_error_message());
        }
        
        $api_url = $this->get_api_url() . '/people/' . $person_uuid . '/addresses';
        
        $request_body = array(
            'data' => array(
                'type' => 'addresses',
                'attributes' => $address_data
            )
        );
        
        error_log('[Wicket API Helper] Creating address for person: ' . $person_uuid);
        error_log('[Wicket API Helper] Address data: ' . json_encode($request_body, JSON_PRETTY_PRINT));
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        error_log('[Wicket API Helper] Address create response: ' . $code);
        
        return array(
            'success' => ($code == 200 || $code == 201),
            'message' => ($code == 200 || $code == 201) ? 'Address created' : "Error: {$code}",
            'data' => $body,
            'status_code' => $code
        );
    }
    
    /**
     * Get person's addresses
     */
    public function get_person_addresses($person_uuid) {
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return array();
        }
        
        $response = wp_remote_get($this->get_api_url() . '/people/' . $person_uuid . '/addresses', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array();
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['data'] ?? array();
    }
    
    /**
     * Get person's phones
     */
    public function get_person_phones($person_uuid) {
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return array();
        }
        
        $response = wp_remote_get($this->get_api_url() . '/people/' . $person_uuid . '/phones', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array();
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['data'] ?? array();
    }


    // =========================================================================
    // ORGANIZATION METHODS
    // =========================================================================
    
    /**
     * Get all organizations with pagination
     * 
     * @param int $page Page number
     * @param int $page_size Items per page (max 500)
     * @return array|null Organizations data or null on error
     */
    public function get_organizations($page = 1, $page_size = 100) {
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            error_log('[Wicket API Helper] Failed to generate token for organizations');
            return null;
        }
        
        $endpoint = $this->get_api_url() . "/organizations?page[number]={$page}&page[size]={$page_size}";
        
        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            error_log('[Wicket API Helper] Organizations fetch error: ' . $response->get_error_message());
            return null;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body;
    }
    
    /**
     * Get single organization by UUID
     * 
     * @param string $org_uuid Organization UUID
     * @return array|null Organization data or null
     */
    public function get_organization($org_uuid) {
        if (empty($org_uuid)) {
            return null;
        }
        
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return null;
        }
        
        $response = wp_remote_get($this->get_api_url() . '/organizations/' . $org_uuid, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['data'] ?? null;
    }
    
    /**
     * Search organizations by name in Wicket API
     * 
     * @param string $search_term Search term
     * @param int $limit Max results
     * @return array Organizations matching search
     */
    public function search_organizations_api($search_term, $limit = 20) {
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return array();
        }
        
        $endpoint = $this->get_api_url() . '/organizations?filter[legal_name_cont]=' . urlencode($search_term) . '&page[size]=' . $limit;
        
        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array();
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['data'] ?? array();
    }
    
    /**
     * Create new organization in Wicket
     * 
     * @param array $org_data Organization attributes
     * @return array Result with success status and data/error
     */
    public function create_organization($org_data) {
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return array('success' => false, 'message' => $token->get_error_message());
        }
        
        $request_body = array(
            'data' => array(
                'type' => 'organizations',
                'attributes' => $org_data
            )
        );
        
        error_log('[Wicket API Helper] Creating organization: ' . json_encode($request_body, JSON_PRETTY_PRINT));
        
        $response = wp_remote_post($this->get_api_url() . '/organizations', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code === 201) {
            error_log('[Wicket API Helper] Organization created: ' . ($body['data']['id'] ?? 'unknown'));
            return array(
                'success' => true,
                'data' => $body['data'],
                'uuid' => $body['data']['id'] ?? null
            );
        }
        
        $error_msg = isset($body['errors']) ? json_encode($body['errors']) : "Status {$code}";
        error_log('[Wicket API Helper] Organization creation failed: ' . $error_msg);
        
        return array('success' => false, 'message' => $error_msg, 'status_code' => $code);
    }
    
    /**
     * Update organization in Wicket
     * 
     * @param string $org_uuid Organization UUID
     * @param array $org_data Organization attributes to update
     * @return array Result
     */
    public function update_organization($org_uuid, $org_data) {
        if (empty($org_uuid)) {
            return array('success' => false, 'message' => 'Organization UUID required');
        }
        
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return array('success' => false, 'message' => $token->get_error_message());
        }
        
        $request_body = array(
            'data' => array(
                'type' => 'organizations',
                'id' => $org_uuid,
                'attributes' => $org_data
            )
        );
        
        error_log('[Wicket API Helper] Updating organization: ' . $org_uuid);
        
        $response = wp_remote_request($this->get_api_url() . '/organizations/' . $org_uuid, array(
            'method' => 'PATCH',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return array(
            'success' => ($code === 200),
            'message' => ($code === 200) ? 'Organization updated' : "Error: {$code}",
            'data' => $body['data'] ?? null,
            'status_code' => $code
        );
    }
    
    // =========================================================================
    // CONNECTION METHODS (Person to Organization)
    // =========================================================================
    
    /**
     * Get person's connections to organizations
     * 
     * @param string $person_uuid Person UUID
     * @return array Connections
     */
    public function get_person_connections($person_uuid) {
        if (empty($person_uuid)) {
            return array();
        }
        
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return array();
        }
        
        $endpoint = $this->get_api_url() . '/people/' . $person_uuid . '/connections?filter[connection_type_eq]=person_to_organization';
        
        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('[Wicket API Helper] Failed to get connections: ' . $response->get_error_message());
            return array();
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['data'] ?? array();
    }
    
    /**
     * Create connection between person and organization
     * 
     * @param string $person_uuid Person UUID
     * @param string $org_uuid Organization UUID
     * @param string $connection_type Connection type (default: 'member')
     * @param string $description Optional description
     * @return array Result
     */
    public function create_person_org_connection($person_uuid, $org_uuid, $connection_type = 'member', $description = '', $starts_at = null, $ends_at = null) {
        if (empty($person_uuid) || empty($org_uuid)) {
            return array('success' => false, 'message' => 'Person and Organization UUIDs required');
        }

        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return array('success' => false, 'message' => $token->get_error_message());
        }

        // Check if connection already exists
        $existing = $this->get_person_connections($person_uuid);
        foreach ($existing as $conn) {
            $to_id = $conn['relationships']['to']['data']['id'] ?? null;
            if ($to_id === $org_uuid) {
                error_log('[Wicket API Helper] Connection already exists between person and org');

                // If dates were provided, update the existing connection
                $existing_conn_uuid = $conn['id'] ?? null;
                if ($existing_conn_uuid && ($starts_at || $ends_at)) {
                    $update_data = array();
                    if ($starts_at) {
                        $update_data['starts_at'] = $starts_at;
                    }
                    if ($ends_at) {
                        $update_data['ends_at'] = $ends_at;
                    }
                    error_log('[Wicket API Helper] Updating existing connection dates: ' . $existing_conn_uuid);
                    $this->update_connection($existing_conn_uuid, $update_data);
                }

                return array(
                    'success' => true,
                    'message' => 'Connection already exists',
                    'data' => $conn,
                    'already_existed' => true,
                    'connection_uuid' => $existing_conn_uuid
                );
            }
        }

        $attributes = array(
            'type' => $connection_type,
            'description' => $description ?: 'Organization member',
            'connection_type' => 'person_to_organization'
        );

        if ($starts_at) {
            $attributes['starts_at'] = $starts_at;
        }

        if ($ends_at) {
            $attributes['ends_at'] = $ends_at;
        }

        $request_body = array(
            'data' => array(
                'type' => 'connections',
                'attributes' => $attributes,
                'relationships' => array(
                    'from' => array(
                        'data' => array(
                            'type' => 'people',
                            'id' => $person_uuid
                        )
                    ),
                    'to' => array(
                        'data' => array(
                            'type' => 'organizations',
                            'id' => $org_uuid
                        )
                    )
                )
            )
        );
        
        error_log('[Wicket API Helper] Creating person-org connection: ' . json_encode($request_body, JSON_PRETTY_PRINT));
        
        $response = wp_remote_post($this->get_api_url() . '/connections', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code === 200 || $code === 201) {
            error_log('[Wicket API Helper] Connection created successfully');
            return array(
                'success' => true,
                'data' => $body['data'] ?? $body,
                'connection_uuid' => $body['data']['id'] ?? null
            );
        }
        
        $error_msg = isset($body['errors']) ? json_encode($body['errors']) : "Status {$code}";
        error_log('[Wicket API Helper] Connection creation failed: ' . $error_msg);
        
        return array('success' => false, 'message' => $error_msg, 'status_code' => $code);
    }
    
    /**
     * Delete a connection
     * 
     * @param string $connection_uuid Connection UUID
     * @return array Result
     */
    public function delete_connection($connection_uuid) {
        if (empty($connection_uuid)) {
            return array('success' => false, 'message' => 'Connection UUID required');
        }
        
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return array('success' => false, 'message' => $token->get_error_message());
        }
        
        $response = wp_remote_request($this->get_api_url() . '/connections/' . $connection_uuid, array(
            'method' => 'DELETE',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        return array(
            'success' => ($code === 204 || $code === 200),
            'message' => ($code === 204 || $code === 200) ? 'Connection deleted' : "Error: {$code}",
            'status_code' => $code
        );
    }
    
    /**
     * Update connection (e.g., set end date)
     * 
     * @param string $connection_uuid Connection UUID
     * @param array $connection_data Connection attributes
     * @return array Result
     */
    public function update_connection($connection_uuid, $connection_data) {
        if (empty($connection_uuid)) {
            return array('success' => false, 'message' => 'Connection UUID required');
        }
        
        $token = $this->generate_jwt_token();
        if (is_wp_error($token)) {
            return array('success' => false, 'message' => $token->get_error_message());
        }
        
        $request_body = array(
            'data' => array(
                'type' => 'connections',
                'id' => $connection_uuid,
                'attributes' => $connection_data
            )
        );
        
        $response = wp_remote_request($this->get_api_url() . '/connections/' . $connection_uuid, array(
            'method' => 'PATCH',
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'message' => $response->get_error_message());
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        return array(
            'success' => ($code === 200),
            'message' => ($code === 200) ? 'Connection updated' : "Error: {$code}",
            'data' => $body['data'] ?? null,
            'status_code' => $code
        );
    }
}

/**
 * Global function to get the helper instance
 */
function wicket_api() {
    return Wicket_API_Helper::get_instance();
}