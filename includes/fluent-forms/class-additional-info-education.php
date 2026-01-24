<?php
/**
 * Wicket Additional Info Education Handler for Fluent Forms
 * 
 * This script handles updating person details in Wicket when the 
 * "Additional Info" form (ID: 13) is submitted with the following fields:
 * 
 * Education Schema (slug: "education"):
 * - highest_level (Highest Level of Education)
 * - grad_date (Graduation date)
 * 
 * Ethics Schema (slug: "ethics"):
 * - policy (Familiar with IES Policies)
 * - gdpr_permission (GDPR Permission)
 * - agreement (IES Code of Ethics Agreement)
 * - agreement_date (IES Code of Ethics Last Agreement Date)
 * 
 * Communications (standard attributes):
 * - directory_option (Opt out of member directory)
 * - mag_option (Receive physical LD+A Magazine)
 * 
 * @package Wicket_Integration
 * @version 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_Additional_Info_Education_Handler {
    
    /**
     * The form ID for the additional info education form
     */
    private $form_id = 24;
    
    /**
     * Constructor
     */
    public function __construct() {
        add_action('fluentform/submission_inserted', array($this, 'sync_additional_info_to_wicket'), 10, 3);
    }
    
    /**
     * Sync additional info submission to Wicket
     */
    public function sync_additional_info_to_wicket($entry_id, $form_data, $form) {
        // Only process our additional info form
        if ($form->id != $this->form_id) {
            return;
        }
        
        // Get current user
        $user_id = get_current_user_id();
        if (!$user_id) {
            error_log('Wicket Additional Info Education: No logged-in user found');
            return;
        }
        
        // Get person UUID from user meta
        $person_uuid = get_user_meta($user_id, 'wicket_uuid', true);
        if (empty($person_uuid)) {
            error_log('Wicket Additional Info Education: No Wicket UUID found for user ' . $user_id);
            return;
        }
        
        error_log('Wicket Additional Info Education: Processing form submission for person UUID: ' . $person_uuid);
        
        // Update person details in Wicket
        $result = $this->update_person_additional_info($person_uuid, $form_data);
        
        // Log the result
        if ($result['success']) {
            error_log("Wicket Additional Info Education Success: Person updated with UUID " . $person_uuid);
            
            // Optionally store the last update timestamp in the form entry meta
            if ($entry_id) {
                update_post_meta($entry_id, '_wicket_person_uuid', $person_uuid);
                update_post_meta($entry_id, '_wicket_updated_at', current_time('mysql'));
            }
            
            // You can add custom actions here for successful update
            do_action('wicket_person_additional_info_education_success', $result, $form_data, $form);
            
        } else {
            error_log("Wicket Additional Info Education Failed: " . $result['message']);
            
            // You can add custom actions here for failed update
            do_action('wicket_person_additional_info_education_failed', $result, $form_data, $form);
        }
    }
    
    /**
     * Update person details in Wicket
     */
    private function update_person_additional_info($person_uuid, $form_data) {
        $jwt_token = $this->generate_jwt_token();
        if (is_wp_error($jwt_token)) {
            error_log('Wicket Additional Info Education: JWT generation failed - ' . $jwt_token->get_error_message());
            return array(
                'success' => false,
                'message' => 'JWT token generation failed: ' . $jwt_token->get_error_message()
            );
        }
        
        // Step 1: GET the current person data to retrieve existing data_fields
        $current_person = $this->get_person_data($person_uuid, $jwt_token);
        if (!$current_person) {
            return array(
                'success' => false,
                'message' => 'Failed to retrieve current person data'
            );
        }
        
        // Extract and sanitize form data
        $form_values = array(
            'highest_level' => isset($form_data['highest_level']) ? sanitize_text_field($form_data['highest_level']) : '',
            'grad_date' => isset($form_data['grad_date']) ? $this->convert_date_format($form_data['grad_date']) : '',
            'directory_option' => isset($form_data['directory_option']) ? sanitize_text_field($form_data['directory_option']) : '',
            'mag_option' => isset($form_data['mag_option']) ? sanitize_text_field($form_data['mag_option']) : '',
            'policy' => isset($form_data['policy']) ? sanitize_text_field($form_data['policy']) : '',
            'gdpr_permission' => isset($form_data['gdpr_permission']) ? sanitize_text_field($form_data['gdpr_permission']) : '',
            'agreement' => isset($form_data['agreement']) ? sanitize_text_field($form_data['agreement']) : '',
            'agreement_date' => isset($form_data['agreement_date']) ? $this->convert_date_format($form_data['agreement_date']) : ''
        );
        
        // Step 2: Build data_fields with the complete structure for BOTH schemas
        $data_fields = $this->build_data_fields($current_person, $form_values);
        
        // Step 3: Build communications attributes if applicable
        $attributes = array(
            'data_fields' => $data_fields
        );
        
        // Handle directory and magazine options in communications
        if (!empty($form_values['directory_option']) || !empty($form_values['mag_option'])) {
            $communications = array();
            
            // Directory opt-out
            if (!empty($form_values['directory_option'])) {
                // Note: You may need to adjust this field name based on your Wicket setup
                $communications['directory_opt_out'] = ($form_values['directory_option'] === 'yes');
            }
            
            // Magazine physical copy
            if (!empty($form_values['mag_option'])) {
                $communications['journal_physical_copy'] = ($form_values['mag_option'] === 'yes');
            }
            
            if (!empty($communications)) {
                $attributes['communications'] = $communications;
            }
        }
        
        // Step 4: Prepare the PATCH request
        $api_url = $this->get_api_url();
        
        $person_data = array(
            'data' => array(
                'type' => 'people',
                'id' => $person_uuid,
                'attributes' => $attributes
            )
        );
        
        // Log the request for debugging
        error_log('Wicket Additional Info Education: Updating person ' . $person_uuid);
        error_log('Wicket Additional Info Education: Request data: ' . json_encode($person_data, JSON_PRETTY_PRINT));
        
        // Make API request to update person (PATCH request)
        $response = wp_remote_request($api_url . '/people/' . $person_uuid, array(
            'method' => 'PATCH',
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'body' => json_encode($person_data),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Wicket Additional Info Education: API request failed - ' . $response->get_error_message());
            return array(
                'success' => false,
                'message' => 'API request failed: ' . $response->get_error_message()
            );
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        // Log response for debugging
        error_log('Wicket Additional Info Education: Response status: ' . $status_code);
        error_log('Wicket Additional Info Education: Response body: ' . json_encode($body));
        
        if ($status_code === 200) {
            return array(
                'success' => true,
                'message' => 'Person updated successfully',
                'data' => $body
            );
        } else if ($status_code === 409) {
            // Handle concurrent update conflict
            error_log('Wicket Additional Info Education: Concurrent update detected (409 Conflict). Retrying...');
            
            // Retry logic: GET the latest data and try again
            $retry_result = $this->retry_update_after_conflict($person_uuid, $form_data);
            return $retry_result;
        } else {
            // Handle error response
            $error_message = 'Failed to update person. Status: ' . $status_code;
            
            if (isset($body['errors']) && is_array($body['errors'])) {
                foreach ($body['errors'] as $error) {
                    $error_detail = json_encode($error);
                    error_log('Wicket Additional Info Education: API Error - ' . $error_detail);
                    $error_message .= ' | ' . $error_detail;
                }
            }
            
            return array(
                'success' => false,
                'message' => $error_message,
                'status_code' => $status_code,
                'error_data' => $body
            );
        }
    }
    
    /**
     * Convert date format from d/m/Y to Y-m-d (ISO format for Wicket)
     */
    private function convert_date_format($date_string) {
        if (empty($date_string)) {
            return '';
        }
        
        // Try to parse the date - Fluent Forms uses d/m/Y format
        $date = DateTime::createFromFormat('d/m/Y', $date_string);
        if ($date === false) {
            // If that fails, try Y-m-d format
            $date = DateTime::createFromFormat('Y-m-d', $date_string);
        }
        
        if ($date === false) {
            error_log('Wicket Additional Info Education: Invalid date format: ' . $date_string);
            return $date_string; // Return as-is if we can't parse it
        }
        
        // Return in ISO format (Y-m-d)
        return $date->format('Y-m-d');
    }
    
    /**
     * Convert form values to match Wicket enum format
     * The form uses "High School", "Doctorate" etc., but Wicket uses "high_school", "doctorate"
     */
    private function normalize_education_level($form_value) {
        if (empty($form_value)) {
            return '';
        }
        
        // Map form values to Wicket enum values
        $mapping = array(
            'High School' => 'high_school',
            'Associate' => 'associate',
            'Bachelors' => 'bachelors',
            'Masters' => 'masters',
            'Doctorate' => 'doctorate'
        );
        
        return isset($mapping[$form_value]) ? $mapping[$form_value] : strtolower(str_replace(' ', '_', $form_value));
    }
    
    /**
     * Get current person data from Wicket
     */
    private function get_person_data($person_uuid, $jwt_token) {
        $api_url = $this->get_api_url();
        
        // Include json_schemas_available to understand data_fields structure
        $response = wp_remote_get($api_url . '/people/' . $person_uuid . '?include=json_schemas_available', array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('Wicket Additional Info Education: Failed to get person data - ' . $response->get_error_message());
            return null;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code === 200 && isset($body['data'])) {
            return $body['data'];
        }
        
        return null;
    }
    
    /**
     * Build data_fields with complete structure for BOTH schemas
     * 
     * This follows the Wicket API requirement to provide the complete structure
     * for each field, including all nested properties
     */
    private function build_data_fields($current_person, $form_values) {
        $data_fields = array();
        
        // Get existing data_fields if they exist
        $existing_data_fields = isset($current_person['attributes']['data_fields']) 
            ? $current_person['attributes']['data_fields'] 
            : array();
        
        // Find existing education and ethics entries
        $education_entry = null;
        $education_version = 0;
        $ethics_entry = null;
        $ethics_version = 0;
        
        foreach ($existing_data_fields as $data_field) {
            if (isset($data_field['schema_slug'])) {
                if ($data_field['schema_slug'] === 'education') {
                    $education_entry = $data_field;
                    $education_version = isset($data_field['version']) ? $data_field['version'] : 0;
                } else if ($data_field['schema_slug'] === 'ethics') {
                    $ethics_entry = $data_field;
                    $ethics_version = isset($data_field['version']) ? $data_field['version'] : 0;
                }
            }
        }
        
        // Build EDUCATION schema data
        $has_education_update = !empty($form_values['highest_level']) || !empty($form_values['grad_date']);
        
        if ($has_education_update) {
            $education_value = array();
            if ($education_entry && isset($education_entry['value'])) {
                $education_value = $education_entry['value'];
            }
            
            // Update education fields if provided
            if (!empty($form_values['highest_level'])) {
                $education_value['highest_level'] = $this->normalize_education_level($form_values['highest_level']);
            }
            
            if (!empty($form_values['grad_date'])) {
                $education_value['grad_date'] = $form_values['grad_date'];
            }
            
            // Add education schema
            $data_fields[] = array(
                'schema_slug' => 'education',
                'version' => $education_version,
                'value' => $education_value
            );
        } else if ($education_entry) {
            // No updates to education, but preserve existing education data
            $data_fields[] = $education_entry;
        }
        
        // Build ETHICS schema data
        // NOTE: ethics schema has REQUIRED fields: policy, agreement, gdpr_permission
        // We need to check if ANY ethics field is being updated
        $has_ethics_update = !empty($form_values['policy']) || 
                            !empty($form_values['gdpr_permission']) || 
                            !empty($form_values['agreement']) || 
                            !empty($form_values['agreement_date']);
        
        if ($has_ethics_update) {
            $ethics_value = array();
            if ($ethics_entry && isset($ethics_entry['value'])) {
                $ethics_value = $ethics_entry['value'];
            }
            
            // Update ethics fields if provided, or ensure required fields exist
            if (!empty($form_values['policy'])) {
                $ethics_value['policy'] = $form_values['policy'];
            } else if (!isset($ethics_value['policy'])) {
                // Set default if required field doesn't exist
                $ethics_value['policy'] = 'no';
            }
            
            if (!empty($form_values['gdpr_permission'])) {
                $ethics_value['gdpr_permission'] = $form_values['gdpr_permission'];
            } else if (!isset($ethics_value['gdpr_permission'])) {
                // Set default if required field doesn't exist
                $ethics_value['gdpr_permission'] = 'no';
            }
            
            if (!empty($form_values['agreement'])) {
                $ethics_value['agreement'] = $form_values['agreement'];
            } else if (!isset($ethics_value['agreement'])) {
                // Set default if required field doesn't exist
                $ethics_value['agreement'] = 'no';
            }
            
            // agreement_date is optional
            if (!empty($form_values['agreement_date'])) {
                $ethics_value['agreement_date'] = $form_values['agreement_date'];
            }
            
            // Add ethics schema with all required fields
            $data_fields[] = array(
                'schema_slug' => 'ethics',
                'version' => $ethics_version,
                'value' => $ethics_value
            );
        } else if ($ethics_entry) {
            // No updates to ethics, but preserve existing ethics data
            $data_fields[] = $ethics_entry;
        }
        
        // Preserve any other existing data_fields that we're not modifying
        foreach ($existing_data_fields as $data_field) {
            if (isset($data_field['schema_slug']) && 
                $data_field['schema_slug'] !== 'education' && 
                $data_field['schema_slug'] !== 'ethics') {
                $data_fields[] = $data_field;
            }
        }
        
        return $data_fields;
    }
    
    /**
     * Retry update after concurrent modification conflict
     */
    private function retry_update_after_conflict($person_uuid, $form_data) {
        error_log('Wicket Additional Info Education: Retrying update after conflict...');
        
        // Just call the main update function again - it will GET fresh data
        return $this->update_person_additional_info($person_uuid, $form_data);
    }
    
    /**
     * Generate JWT token for Wicket API authentication
     */
    private function generate_jwt_token() {
        $tenant = get_option('wicket_tenant_name', '');
        $api_secret = get_option('wicket_api_secret_key', '');
        $admin_uuid = get_option('wicket_admin_user_uuid', '');
        
        if (empty($tenant) || empty($api_secret) || empty($admin_uuid)) {
            error_log('Wicket Additional Info Education: Missing API configuration');
            return new WP_Error('missing_config', 'Wicket API credentials not configured properly');
        }
        
        $api_url = $this->get_api_url();
        
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
    
    /**
     * Get Wicket API URL
     */
    private function get_api_url() {
        $tenant = get_option('wicket_tenant_name', '');
        $staging = get_option('wicket_staging', 0);
        
        if ($staging) {
            return "https://{$tenant}-api.staging.wicketcloud.com";
        } else {
            return "https://{$tenant}-api.wicketcloud.com";
        }
    }
}

// Initialize the handler
new Wicket_Additional_Info_Education_Handler();