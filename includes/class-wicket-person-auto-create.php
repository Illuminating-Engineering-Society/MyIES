<?php
/**
 * Wicket Person Auto-Creation on User Registration
 * 
 * This class ensures that EVERY WordPress user created gets a corresponding
 * person record in Wicket immediately. This is critical for Group Accounts
 * where members might be added before going through any checkout.
 * 
 * @package PMPro_Wicket_Integration
 * @version 1.0.1 - FIXED JWT GENERATION
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class Wicket_Person_Auto_Create {
    
    private $wicket_api_url;
    private $wicket_api_secret;
    private $wicket_api_user_uuid;
    private $wicket_tenant;
    private $wicket_staging;
    
    public function __construct() {
        // Hook into user registration - HIGHEST PRIORITY to run first
        add_action('user_register', array($this, 'create_wicket_person_on_registration'), 5, 1);
        
        // Also hook after profile update in case email/name changes
        add_action('profile_update', array($this, 'sync_person_on_profile_update'), 10, 2);
        
        // Load configuration
        $this->load_config();
    }
    
    /**
     * Load Wicket API configuration - FIXED to use correct option names
     */
    private function load_config() {
        $this->wicket_tenant = get_option('wicket_tenant_name', '');
        $this->wicket_api_secret = get_option('wicket_api_secret_key', '');
        $this->wicket_api_user_uuid = get_option('wicket_admin_user_uuid', '');
        $this->wicket_staging = get_option('wicket_staging', 0);
        
        // Build API URL based on tenant and staging setting
        if ($this->wicket_staging) {
            $this->wicket_api_url = "https://{$this->wicket_tenant}-api.staging.wicketcloud.com";
        } else {
            $this->wicket_api_url = "https://{$this->wicket_tenant}-api.wicketcloud.com";
        }
    }
    
    /**
     * Generate JWT token for Wicket API authentication - FIXED with all required claims
     */
    private function generate_jwt_token() {
        if (empty($this->wicket_api_secret) || empty($this->wicket_api_user_uuid) || empty($this->wicket_tenant)) {
            error_log('Wicket Person Auto-Create: Missing API configuration - tenant: ' . 
                (!empty($this->wicket_tenant) ? 'set' : 'missing') . 
                ', secret: ' . (!empty($this->wicket_api_secret) ? 'set' : 'missing') . 
                ', admin_uuid: ' . (!empty($this->wicket_api_user_uuid) ? 'set' : 'missing'));
            return false;
        }
        
        // Create header
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        
        // Create payload with ALL required claims
        $payload = json_encode([
            'exp' => time() + (60 * 60), // 1 hour expiration
            'sub' => $this->wicket_api_user_uuid,
            'aud' => $this->wicket_api_url, // CRITICAL: This was missing!
            'iss' => get_site_url()
        ]);
        
        // Base64 URL encode
        $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        // Create signature
        $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, $this->wicket_api_secret, true);
        $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64Header . "." . $base64Payload . "." . $base64Signature;
    }
    
    /**
     * Make a request to the Wicket API
     */
    private function wicket_api_request($endpoint, $method = 'GET', $data = null) {
        $url = rtrim($this->wicket_api_url, '/') . $endpoint;
        $jwt_token = $this->generate_jwt_token();
        
        if (!$jwt_token) {
            return new WP_Error('no_token', 'Could not generate JWT token');
        }
        
        $args = array(
            'method' => $method,
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $jwt_token,
            ),
            'timeout' => 30,
        );
        
        if ($data && in_array($method, array('POST', 'PATCH', 'PUT'))) {
            $args['body'] = json_encode($data);
        }
        
        $response = wp_remote_request($url, $args);
        
        if (is_wp_error($response)) {
            error_log('Wicket Person Auto-Create API Error: ' . $response->get_error_message());
            return $response;
        }
        
        $body = wp_remote_retrieve_body($response);
        $decoded = json_decode($body, true);
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code >= 400) {
            $error_message = isset($decoded['errors']) ? json_encode($decoded['errors']) : 'Unknown error';
            error_log("Wicket Person Auto-Create API Error (Status $status_code): " . $error_message);
            return new WP_Error('wicket_api_error', $error_message, array('status' => $status_code));
        }
        
        return $decoded;
    }
    
    /**
     * Create Wicket person immediately when WordPress user is created
     * This runs with priority 5 to ensure it happens BEFORE any other integrations
     */
    public function create_wicket_person_on_registration($user_id) {
        error_log("Wicket Person Auto-Create: New user registered - User ID: {$user_id}");
        
        // Check if person already exists in Wicket
        $existing_person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);
        if ($existing_person_uuid) {
            error_log("Wicket Person Auto-Create: User {$user_id} already has person UUID: {$existing_person_uuid}");
            return;
        }
        
        $user = get_userdata($user_id);
        if (!$user) {
            error_log("Wicket Person Auto-Create: Could not get user data for user {$user_id}");
            return;
        }
        
        // Try to find existing person by email first
        error_log("Wicket Person Auto-Create: Searching for person by email: {$user->user_email}");
        $search_response = $this->wicket_api_request("/people?filter[emails_address_eq]=" . urlencode($user->user_email));
        
        if (!is_wp_error($search_response) && !empty($search_response['data'])) {
            $person_uuid = $search_response['data'][0]['id'];
            update_user_meta($user_id, 'wicket_person_uuid', $person_uuid);
            error_log("Wicket Person Auto-Create: Found existing person {$person_uuid} for user {$user_id}");
            return;
        }
        
        // Person doesn't exist - create new one
        error_log("Wicket Person Auto-Create: Creating new person for user {$user_id}");
        
        // Get name data
        $first_name = get_user_meta($user_id, 'first_name', true);
        $last_name = get_user_meta($user_id, 'last_name', true);
        
        // Fallback if no first/last name set yet
        if (empty($first_name)) {
            $first_name = $user->display_name ?: $user->user_login;
        }
        if (empty($last_name)) {
            $last_name = 'Member';
        }
        
        // Prepare person data
        $person_data = array(
            'data' => array(
                'type' => 'people',
                'attributes' => array(
                    'given_name' => $first_name,
                    'family_name' => $last_name,
                    'full_name' => trim($first_name . ' ' . $last_name),
                    'language' => 'en',
                    'user' => array(
                        'confirmed_at' => gmdate('Y-m-d\TH:i:s.000\Z'),
                        'skip_confirmation_notification' => true,
                    ),
                ),
                'relationships' => array(
                    'emails' => array(
                        'data' => array(
                            array(
                                'type' => 'emails',
                                'attributes' => array(
                                    'address' => $user->user_email,
                                    'primary' => true,
                                    'unique' => true
                                )
                            )
                        )
                    )
                )
            )
        );
        
        error_log("Wicket Person Auto-Create: Sending API request to create person");
        error_log("Wicket Person Auto-Create: Person data - Name: {$first_name} {$last_name}, Email: {$user->user_email}");
        
        $create_response = $this->wicket_api_request('/people', 'POST', $person_data);
        
        if (is_wp_error($create_response)) {
            error_log('Wicket Person Auto-Create: FAILED to create person - ' . $create_response->get_error_message());
            
            // Check if error is because email already exists (409 conflict)
            $error_data = $create_response->get_error_data();
            if (isset($error_data['status']) && $error_data['status'] == 409) {
                error_log('Wicket Person Auto-Create: Email conflict (409) - trying to find existing person again');
                // Try one more time to find the person
                $search_again = $this->wicket_api_request("/people?filter[emails_address_eq]=" . urlencode($user->user_email));
                if (!is_wp_error($search_again) && !empty($search_again['data'])) {
                    $person_uuid = $search_again['data'][0]['id'];
                    update_user_meta($user_id, 'wicket_person_uuid', $person_uuid);
                    error_log("Wicket Person Auto-Create: Found person after conflict: {$person_uuid}");
                    return;
                }
            }
            
            return;
        }
        
        $person_uuid = $create_response['data']['id'];
        update_user_meta($user_id, 'wicket_person_uuid', $person_uuid);
        
        error_log("Wicket Person Auto-Create: SUCCESS - Created person {$person_uuid} for user {$user_id}");
        
        // Fire action for other integrations to use
        do_action('wicket_person_created', $user_id, $person_uuid);
    }
    
    /**
     * Sync person data when WordPress profile is updated
     */
    public function sync_person_on_profile_update($user_id, $old_user_data) {
        $person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);
        
        // If no person exists, try to create one
        if (!$person_uuid) {
            error_log("Wicket Person Auto-Create: Profile updated for user {$user_id} but no person exists - creating now");
            $this->create_wicket_person_on_registration($user_id);
            return;
        }
        
        // Person exists - update their data
        $user = get_userdata($user_id);
        if (!$user) {
            return;
        }
        
        // Get current person data from Wicket
        $get_response = $this->wicket_api_request("/people/{$person_uuid}");
        if (is_wp_error($get_response)) {
            error_log("Wicket Person Auto-Create: Could not fetch person {$person_uuid} for update");
            return;
        }
        
        // Prepare update data (only update if fields have changed)
        $first_name = get_user_meta($user_id, 'first_name', true);
        $last_name = get_user_meta($user_id, 'last_name', true);
        
        $current_given_name = $get_response['data']['attributes']['given_name'] ?? '';
        $current_family_name = $get_response['data']['attributes']['family_name'] ?? '';
        
        if ($first_name !== $current_given_name || $last_name !== $current_family_name) {
            $update_data = array(
                'data' => array(
                    'type' => 'people',
                    'id' => $person_uuid,
                    'attributes' => array(
                        'given_name' => $first_name ?: $current_given_name,
                        'family_name' => $last_name ?: $current_family_name,
                        'full_name' => trim(($first_name ?: $current_given_name) . ' ' . ($last_name ?: $current_family_name))
                    )
                )
            );
            
            $update_response = $this->wicket_api_request("/people/{$person_uuid}", 'PATCH', $update_data);
            
            if (is_wp_error($update_response)) {
                error_log("Wicket Person Auto-Create: Failed to update person {$person_uuid}");
            } else {
                error_log("Wicket Person Auto-Create: Updated person {$person_uuid} with new name data");
            }
        }
    }
}

// Initialize the auto-creation class
new Wicket_Person_Auto_Create();

/**
 * Utility function to manually create a Wicket person for an existing WordPress user
 * 
 * @param int $user_id WordPress user ID
 * @return string|WP_Error Person UUID on success, WP_Error on failure
 */
function wicket_create_person_for_user($user_id) {
    $auto_create = new Wicket_Person_Auto_Create();
    $auto_create->create_wicket_person_on_registration($user_id);
    
    // Return the person UUID if successful
    $person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);
    if ($person_uuid) {
        return $person_uuid;
    }
    
    return new WP_Error('creation_failed', 'Failed to create person in Wicket');
}