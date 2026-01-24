<?php
/**
 * Wicket Contact Details Handler
 * 
 * Handles syncing contact details (emails, phone, web address) from Fluent Forms to Wicket API
 * Form ID: 12
 * 
 * @package Wicket_Integration
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_Contact_Details_Handler {
    
    /**
     * The form ID for the contact details form
     */
    private $form_id = 23;
    
    /**
     * Default country code to use when phone number doesn't have one
     * Set this to your primary country code (e.g., '1' for US/Canada, '54' for Argentina, '44' for UK)
     * You can also make this configurable via WordPress options
     */
    private $default_country_code = '54'; // Argentina by default, change as needed
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('fluentform/submission_inserted', array($this, 'sync_contact_details_to_wicket'), 10, 3);
        
        // Allow filtering the default country code
        $this->default_country_code = apply_filters('wicket_contact_default_country_code', $this->default_country_code);
    }
    
    /**
     * Sync contact details submission to Wicket
     */
    public function sync_contact_details_to_wicket($entry_id, $form_data, $form) {
        // Only process our contact details form
        if ($form->id != $this->form_id) {
            return;
        }
        
        // Get current user
        $user_id = get_current_user_id();
        if (!$user_id) {
            error_log('Wicket Contact Details: No logged-in user found');
            return;
        }
        
        // Get person UUID from user meta
        $person_uuid = get_user_meta($user_id, 'wicket_uuid', true);
        if (empty($person_uuid)) {
            error_log('Wicket Contact Details: No Wicket UUID found for user ' . $user_id);
            return;
        }
        
        error_log('Wicket Contact Details: Processing form submission for person UUID: ' . $person_uuid);
        
        // Extract form data
        $additional_email = isset($form_data['additional_email']) ? sanitize_email($form_data['additional_email']) : '';
        $phone = isset($form_data['phone']) ? sanitize_text_field($form_data['phone']) : '';
        $work_email = isset($form_data['work_email']) ? sanitize_email($form_data['work_email']) : '';
        $web_address = isset($form_data['web_address']) ? esc_url_raw($form_data['web_address']) : '';
        
        // Sync each field to Wicket
        if (!empty($additional_email)) {
            $this->create_or_update_email($person_uuid, $additional_email, 'personal');
        }
        
        if (!empty($work_email)) {
            $this->create_or_update_email($person_uuid, $work_email, 'work');
        }
        
        if (!empty($phone)) {
            $this->create_or_update_phone($person_uuid, $phone);
        }
        
        if (!empty($web_address)) {
            $this->create_or_update_web_address($person_uuid, $web_address);
        }
        
        error_log('Wicket Contact Details: Successfully processed form submission');
    }
    
    /**
     * Create or update email address in Wicket
     */
    private function create_or_update_email($person_uuid, $email_address, $type = 'personal') {
        $jwt_token = $this->generate_jwt_token();
        if (is_wp_error($jwt_token)) {
            error_log('Wicket Contact Details: JWT generation failed - ' . $jwt_token->get_error_message());
            return false;
        }
        
        // First, check if this email already exists for this person
        $existing_email_id = $this->find_existing_email($person_uuid, $email_address);
        
        if ($existing_email_id) {
            // Email exists, update it
            error_log('Wicket Contact Details: Email already exists (ID: ' . $existing_email_id . '), updating instead of creating');
            return $this->update_email($existing_email_id, $email_address, $type);
        }
        
        // Also check if an email of this type already exists (to update it with new address)
        $existing_email_by_type = $this->find_existing_email_by_type($person_uuid, $type);
        
        if ($existing_email_by_type) {
            // Email of this type exists, update it with the new address
            error_log('Wicket Contact Details: Email of type "' . $type . '" exists (ID: ' . $existing_email_by_type . '), updating with new address');
            return $this->update_email($existing_email_by_type, $email_address, $type);
        }
        
        // Email doesn't exist, create it
        error_log('Wicket Contact Details: Creating new email of type "' . $type . '"');
        
        $tenant = get_option('wicket_tenant_name');
        $is_staging = get_option('wicket_staging', 0);
        
        $api_url = $is_staging 
            ? "https://{$tenant}-api.staging.wicketcloud.com/people/{$person_uuid}/emails"
            : "https://{$tenant}-api.wicketcloud.com/people/{$person_uuid}/emails";
        
        $request_body = array(
            'data' => array(
                'type' => 'emails',
                'attributes' => array(
                    'address' => $email_address,
                    'type' => $type,
                    'primary' => false,
                    'unique' => false
                )
            )
        );
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Wicket Contact Details: Email create failed - ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code == 200 || $code == 201) {
            error_log('Wicket Contact Details: Email created successfully');
            return true;
        } else {
            error_log('Wicket Contact Details: Email create failed with code ' . $code);
            if (isset($body['errors'])) {
                error_log('Wicket Contact Details: Errors: ' . json_encode($body['errors']));
            }
            return false;
        }
    }
    
    /**
     * Update existing email in Wicket
     */
    private function update_email($email_id, $email_address, $type = 'personal') {
        $jwt_token = $this->generate_jwt_token();
        if (is_wp_error($jwt_token)) {
            error_log('Wicket Contact Details: JWT generation failed - ' . $jwt_token->get_error_message());
            return false;
        }
        
        $tenant = get_option('wicket_tenant_name');
        $is_staging = get_option('wicket_staging', 0);
        
        $api_url = $is_staging 
            ? "https://{$tenant}-api.staging.wicketcloud.com/emails/{$email_id}"
            : "https://{$tenant}-api.wicketcloud.com/emails/{$email_id}";
        
        $request_body = array(
            'data' => array(
                'type' => 'emails',
                'id' => $email_id,
                'attributes' => array(
                    'address' => $email_address,
                    'type' => $type
                )
            )
        );
        
        $response = wp_remote_request($api_url, array(
            'method' => 'PATCH',
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Wicket Contact Details: Email update failed - ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code == 200) {
            error_log('Wicket Contact Details: Email updated successfully');
            return true;
        } else {
            error_log('Wicket Contact Details: Email update failed with code ' . $code);
            return false;
        }
    }
    
    /**
     * Find existing email for person
     */
    private function find_existing_email($person_uuid, $email_address) {
        $jwt_token = $this->generate_jwt_token();
        if (is_wp_error($jwt_token)) {
            return null;
        }
        
        $tenant = get_option('wicket_tenant_name');
        $is_staging = get_option('wicket_staging', 0);
        
        $api_url = $is_staging 
            ? "https://{$tenant}-api.staging.wicketcloud.com/people/{$person_uuid}/emails"
            : "https://{$tenant}-api.wicketcloud.com/people/{$person_uuid}/emails";
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code == 200 && isset($body['data']) && is_array($body['data'])) {
            foreach ($body['data'] as $email) {
                if (isset($email['attributes']['address']) && 
                    strtolower($email['attributes']['address']) === strtolower($email_address)) {
                    return $email['id'];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Find existing email by type for person
     * This helps update the correct email when user changes their email address
     */
    private function find_existing_email_by_type($person_uuid, $email_type) {
        $jwt_token = $this->generate_jwt_token();
        if (is_wp_error($jwt_token)) {
            return null;
        }
        
        $tenant = get_option('wicket_tenant_name');
        $is_staging = get_option('wicket_staging', 0);
        
        $api_url = $is_staging 
            ? "https://{$tenant}-api.staging.wicketcloud.com/people/{$person_uuid}/emails"
            : "https://{$tenant}-api.wicketcloud.com/people/{$person_uuid}/emails";
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code == 200 && isset($body['data']) && is_array($body['data'])) {
            foreach ($body['data'] as $email) {
                if (isset($email['attributes']['type']) && 
                    strtolower($email['attributes']['type']) === strtolower($email_type)) {
                    return $email['id'];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Create or update phone in Wicket
     */
    private function create_or_update_phone($person_uuid, $phone_number) {
        $jwt_token = $this->generate_jwt_token();
        if (is_wp_error($jwt_token)) {
            error_log('Wicket Contact Details: JWT generation failed - ' . $jwt_token->get_error_message());
            return false;
        }
        
        // Format phone number to E.164 format (international format)
        $formatted_phone = $this->format_phone_number($phone_number);
        
        if (!$formatted_phone) {
            error_log('Wicket Contact Details: Invalid phone number format: ' . $phone_number);
            return false;
        }
        
        error_log('Wicket Contact Details: Original phone: ' . $phone_number . ', Formatted: ' . $formatted_phone);
        
        // Check if this exact phone number already exists
        $existing_phone_id = $this->find_existing_phone($person_uuid, $formatted_phone);
        
        if ($existing_phone_id) {
            error_log('Wicket Contact Details: Phone already exists (ID: ' . $existing_phone_id . '), updating instead of creating');
            return $this->update_phone($existing_phone_id, $formatted_phone);
        }
        
        // Check if a phone of type 'mobile' already exists (to update it with new number)
        $existing_phone_by_type = $this->find_existing_phone_by_type($person_uuid, 'mobile');
        
        if ($existing_phone_by_type) {
            error_log('Wicket Contact Details: Phone of type "mobile" exists (ID: ' . $existing_phone_by_type . '), updating with new number');
            return $this->update_phone($existing_phone_by_type, $formatted_phone);
        }
        
        // Phone doesn't exist, create new one
        error_log('Wicket Contact Details: Creating new phone');
        
        $tenant = get_option('wicket_tenant_name');
        $is_staging = get_option('wicket_staging', 0);
        
        $api_url = $is_staging 
            ? "https://{$tenant}-api.staging.wicketcloud.com/people/{$person_uuid}/phones"
            : "https://{$tenant}-api.wicketcloud.com/people/{$person_uuid}/phones";
        
        $request_body = array(
            'data' => array(
                'type' => 'phones',
                'attributes' => array(
                    'number' => $formatted_phone,
                    'type' => 'mobile',
                    'primary' => false
                )
            )
        );
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Wicket Contact Details: Phone create failed - ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code == 200 || $code == 201) {
            error_log('Wicket Contact Details: Phone created successfully');
            return true;
        } else {
            error_log('Wicket Contact Details: Phone create failed with code ' . $code);
            if (isset($body['errors'])) {
                error_log('Wicket Contact Details: Errors: ' . json_encode($body['errors']));
            }
            return false;
        }
    }
    
    /**
     * Format phone number to E.164 international format
     * Wicket API requires phone numbers in E.164 format (e.g., +15551234567)
     * 
     * @param string $phone_number The raw phone number
     * @return string|false The formatted phone number or false if invalid
     */
    private function format_phone_number($phone_number) {
        // Remove all non-numeric characters except +
        $cleaned = preg_replace('/[^0-9+]/', '', $phone_number);
        
        // If empty after cleaning, return false
        if (empty($cleaned)) {
            error_log('Wicket Contact Details: Phone number is empty after cleaning');
            return false;
        }
        
        // If it already starts with +, validate and return
        if (strpos($cleaned, '+') === 0) {
            // Make sure there's only one + and it's at the start
            if (substr_count($cleaned, '+') === 1 && strlen($cleaned) >= 8) {
                error_log('Wicket Contact Details: Phone already in E.164 format: ' . $cleaned);
                return $cleaned;
            }
            error_log('Wicket Contact Details: Invalid E.164 format (multiple + or too short): ' . $cleaned);
            return false;
        }
        
        // Remove leading zeros (common in many countries when dialing locally)
        $cleaned = ltrim($cleaned, '0');
        
        // If the number is too short after removing zeros, it's likely invalid
        if (strlen($cleaned) < 6) {
            error_log('Wicket Contact Details: Phone number too short after cleaning: ' . $cleaned . ' (original: ' . $phone_number . ')');
            return false;
        }
        
        // Try to detect if it already has a country code
        $formatted = '';
        
        // US/Canada: 11 digits starting with 1
        if (strlen($cleaned) == 11 && $cleaned[0] == '1') {
            $formatted = '+' . $cleaned;
            error_log('Wicket Contact Details: Detected US/Canada number: ' . $formatted);
            return $formatted;
        }
        
        // US/Canada: 10 digits without country code
        if (strlen($cleaned) == 10 && $cleaned[0] >= '2' && $cleaned[0] <= '9') {
            $formatted = '+1' . $cleaned;
            error_log('Wicket Contact Details: Detected US/Canada number (added +1): ' . $formatted);
            return $formatted;
        }
        
        // For other cases, use the default country code
        // E.164 allows 1-15 digits after the +
        if (strlen($cleaned) >= 6 && strlen($cleaned) <= 15) {
            $formatted = '+' . $this->default_country_code . $cleaned;
            error_log('Wicket Contact Details: Applied default country code +' . $this->default_country_code . ': ' . $formatted);
            return $formatted;
        }
        
        error_log('Wicket Contact Details: Could not format phone number: ' . $phone_number . ' (cleaned: ' . $cleaned . ')');
        return false;
    }
    
    /**
     * Update existing phone in Wicket
     */
    private function update_phone($phone_id, $phone_number) {
        $jwt_token = $this->generate_jwt_token();
        if (is_wp_error($jwt_token)) {
            error_log('Wicket Contact Details: JWT generation failed - ' . $jwt_token->get_error_message());
            return false;
        }
        
        // Format phone number to E.164 format
        $formatted_phone = $this->format_phone_number($phone_number);
        
        if (!$formatted_phone) {
            error_log('Wicket Contact Details: Invalid phone number format: ' . $phone_number);
            return false;
        }
        
        $tenant = get_option('wicket_tenant_name');
        $is_staging = get_option('wicket_staging', 0);
        
        $api_url = $is_staging 
            ? "https://{$tenant}-api.staging.wicketcloud.com/phones/{$phone_id}"
            : "https://{$tenant}-api.wicketcloud.com/phones/{$phone_id}";
        
        $request_body = array(
            'data' => array(
                'type' => 'phones',
                'id' => $phone_id,
                'attributes' => array(
                    'number' => $formatted_phone,
                    'type' => 'mobile'
                )
            )
        );
        
        $response = wp_remote_request($api_url, array(
            'method' => 'PATCH',
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Wicket Contact Details: Phone update failed - ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code == 200) {
            error_log('Wicket Contact Details: Phone updated successfully');
            return true;
        } else {
            error_log('Wicket Contact Details: Phone update failed with code ' . $code);
            return false;
        }
    }
    
    /**
     * Find existing phone for person
     */
    private function find_existing_phone($person_uuid, $phone_number) {
        $jwt_token = $this->generate_jwt_token();
        if (is_wp_error($jwt_token)) {
            return null;
        }
        
        $tenant = get_option('wicket_tenant_name');
        $is_staging = get_option('wicket_staging', 0);
        
        $api_url = $is_staging 
            ? "https://{$tenant}-api.staging.wicketcloud.com/people/{$person_uuid}/phones"
            : "https://{$tenant}-api.wicketcloud.com/people/{$person_uuid}/phones";
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code == 200 && isset($body['data']) && is_array($body['data'])) {
            // Normalize phone numbers for comparison (remove spaces, dashes, etc.)
            $normalized_input = preg_replace('/[^0-9+]/', '', $phone_number);
            
            foreach ($body['data'] as $phone) {
                if (isset($phone['attributes']['number'])) {
                    $normalized_existing = preg_replace('/[^0-9+]/', '', $phone['attributes']['number']);
                    if ($normalized_existing === $normalized_input) {
                        return $phone['id'];
                    }
                }
            }
        }
        
        return null;
    }
    
    /**
     * Find existing phone by type for person
     * This helps update the correct phone when user changes their number
     */
    private function find_existing_phone_by_type($person_uuid, $phone_type) {
        $jwt_token = $this->generate_jwt_token();
        if (is_wp_error($jwt_token)) {
            return null;
        }
        
        $tenant = get_option('wicket_tenant_name');
        $is_staging = get_option('wicket_staging', 0);
        
        $api_url = $is_staging 
            ? "https://{$tenant}-api.staging.wicketcloud.com/people/{$person_uuid}/phones"
            : "https://{$tenant}-api.wicketcloud.com/people/{$person_uuid}/phones";
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code == 200 && isset($body['data']) && is_array($body['data'])) {
            foreach ($body['data'] as $phone) {
                if (isset($phone['attributes']['type']) && 
                    strtolower($phone['attributes']['type']) === strtolower($phone_type)) {
                    return $phone['id'];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Create or update web address in Wicket
     */
    private function create_or_update_web_address($person_uuid, $web_address) {
        $jwt_token = $this->generate_jwt_token();
        if (is_wp_error($jwt_token)) {
            error_log('Wicket Contact Details: JWT generation failed - ' . $jwt_token->get_error_message());
            return false;
        }
        
        // Check if this exact web address already exists
        $existing_web_address_id = $this->find_existing_web_address($person_uuid, $web_address);
        
        if ($existing_web_address_id) {
            error_log('Wicket Contact Details: Web address already exists (ID: ' . $existing_web_address_id . '), no update needed');
            return true; // Already exists with same URL, no need to update
        }
        
        // Check if a web address of type 'website' already exists (to update it with new URL)
        $existing_web_address_by_type = $this->find_existing_web_address_by_type($person_uuid, 'website');
        
        if ($existing_web_address_by_type) {
            error_log('Wicket Contact Details: Web address of type "website" exists (ID: ' . $existing_web_address_by_type . '), updating with new URL');
            return $this->update_web_address($existing_web_address_by_type, $web_address);
        }
        
        // Web address doesn't exist, create new one
        error_log('Wicket Contact Details: Creating new web address');
        
        $tenant = get_option('wicket_tenant_name');
        $is_staging = get_option('wicket_staging', 0);
        
        $api_url = $is_staging 
            ? "https://{$tenant}-api.staging.wicketcloud.com/people/{$person_uuid}/web_addresses"
            : "https://{$tenant}-api.wicketcloud.com/people/{$person_uuid}/web_addresses";
        
        $request_body = array(
            'data' => array(
                'type' => 'web_addresses',
                'attributes' => array(
                    'address' => $web_address,
                    'type' => 'website'
                )
            )
        );
        
        $response = wp_remote_post($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Wicket Contact Details: Web address create failed - ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code == 200 || $code == 201) {
            error_log('Wicket Contact Details: Web address created successfully');
            return true;
        } else {
            error_log('Wicket Contact Details: Web address create failed with code ' . $code);
            if (isset($body['errors'])) {
                error_log('Wicket Contact Details: Errors: ' . json_encode($body['errors']));
            }
            return false;
        }
    }
    
    /**
     * Update existing web address in Wicket
     */
    private function update_web_address($web_address_id, $web_address) {
        $jwt_token = $this->generate_jwt_token();
        if (is_wp_error($jwt_token)) {
            error_log('Wicket Contact Details: JWT generation failed - ' . $jwt_token->get_error_message());
            return false;
        }
        
        $tenant = get_option('wicket_tenant_name');
        $is_staging = get_option('wicket_staging', 0);
        
        $api_url = $is_staging 
            ? "https://{$tenant}-api.staging.wicketcloud.com/web_addresses/{$web_address_id}"
            : "https://{$tenant}-api.wicketcloud.com/web_addresses/{$web_address_id}";
        
        $request_body = array(
            'data' => array(
                'type' => 'web_addresses',
                'id' => $web_address_id,
                'attributes' => array(
                    'address' => $web_address,
                    'type' => 'website'
                )
            )
        );
        
        $response = wp_remote_request($api_url, array(
            'method' => 'PATCH',
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($request_body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Wicket Contact Details: Web address update failed - ' . $response->get_error_message());
            return false;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        
        if ($code == 200) {
            error_log('Wicket Contact Details: Web address updated successfully');
            return true;
        } else {
            error_log('Wicket Contact Details: Web address update failed with code ' . $code);
            return false;
        }
    }
    
    /**
     * Find existing web address for person
     */
    private function find_existing_web_address($person_uuid, $web_address) {
        $jwt_token = $this->generate_jwt_token();
        if (is_wp_error($jwt_token)) {
            return null;
        }
        
        $tenant = get_option('wicket_tenant_name');
        $is_staging = get_option('wicket_staging', 0);
        
        $api_url = $is_staging 
            ? "https://{$tenant}-api.staging.wicketcloud.com/people/{$person_uuid}/web_addresses"
            : "https://{$tenant}-api.wicketcloud.com/people/{$person_uuid}/web_addresses";
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code == 200 && isset($body['data']) && is_array($body['data'])) {
            foreach ($body['data'] as $address) {
                if (isset($address['attributes']['address']) && 
                    strtolower($address['attributes']['address']) === strtolower($web_address)) {
                    return $address['id'];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Find existing web address by type for person
     * This helps update the correct web address when user changes their URL
     */
    private function find_existing_web_address_by_type($person_uuid, $address_type) {
        $jwt_token = $this->generate_jwt_token();
        if (is_wp_error($jwt_token)) {
            return null;
        }
        
        $tenant = get_option('wicket_tenant_name');
        $is_staging = get_option('wicket_staging', 0);
        
        $api_url = $is_staging 
            ? "https://{$tenant}-api.staging.wicketcloud.com/people/{$person_uuid}/web_addresses"
            : "https://{$tenant}-api.wicketcloud.com/people/{$person_uuid}/web_addresses";
        
        $response = wp_remote_get($api_url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code == 200 && isset($body['data']) && is_array($body['data'])) {
            foreach ($body['data'] as $address) {
                if (isset($address['attributes']['type']) && 
                    strtolower($address['attributes']['type']) === strtolower($address_type)) {
                    return $address['id'];
                }
            }
        }
        
        return null;
    }
    
    /**
     * Generate JWT token for Wicket API
     */
    private function generate_jwt_token() {
        $tenant = get_option('wicket_tenant_name', '');
        $api_secret = get_option('wicket_api_secret_key', '');
        $admin_uuid = get_option('wicket_admin_user_uuid', '');
        $staging = get_option('wicket_staging', 0);
        
        if (empty($tenant) || empty($api_secret) || empty($admin_uuid)) {
            error_log('Wicket Contact Details: Missing API configuration - tenant: ' . (!empty($tenant) ? 'set' : 'missing') . 
                      ', secret: ' . (!empty($api_secret) ? 'set' : 'missing') . 
                      ', admin_uuid: ' . (!empty($admin_uuid) ? 'set' : 'missing'));
            return new WP_Error('missing_credentials', 'Wicket API credentials not configured');
        }
        
        if ($staging) {
            $api_url = "https://{$tenant}-api.staging.wicketcloud.com";
        } else {
            $api_url = "https://{$tenant}-api.wicketcloud.com";
        }
        
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode([
            'exp' => time() + (60 * 60), // 1 hour expiration
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
}

// Initialize the handler
new Wicket_Contact_Details_Handler();