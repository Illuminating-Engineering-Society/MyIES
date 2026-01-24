<?php
/**
 * Wicket Additional Information Handler for Fluent Forms
 * 
 * This script handles updating person details in Wicket when a Fluent Forms 
 * "Additional Information" form is submitted with the following fields:
 * - salutation
 * - alternate_name
 * - names[first_name] (Name)
 * - names[last_name] (Last name)
 * - names[middle_name] (Middle name(s)/initial(s))
 * - suffix
 * - post_nominal (Post-nominal)
 * - nickname
 * - pronouns
 * - gender
 * - birth_date
 * - title
 * - job_function
 * - job_level
 * 
 * @version 2.1.0 - Fixed data_fields structure for API validation
 */

/**
 * Update person details in Wicket
 */
function wicket_update_person_additional_info($person_uuid, $form_data) {
    $token = wicket_generate_jwt_token_additional_info();
    if (!$token) {
        return array(
            'success' => false,
            'message' => 'Wicket API credentials not configured properly'
        );
    }
    
    $api_url = wicket_get_api_url_additional_info();
    
    // Debug: Log the incoming form data to see what fields we're receiving
    error_log('Wicket Additional Info: Received form_data keys: ' . implode(', ', array_keys($form_data)));
    
    // Build the person data structure
    $attributes = array();
    
    // Map form fields to Wicket person attributes
    // Salutation -> honorific_prefix (Mr., Mrs., Ms., Dr., etc.)
    if (!empty($form_data['salutation'])) {
        $attributes['honorific_prefix'] = sanitize_text_field($form_data['salutation']);
    }
    
    // Name fields - these are flat form fields, not nested
    if (!empty($form_data['first_name'])) {
        $attributes['given_name'] = sanitize_text_field($form_data['first_name']);
    }
    if (!empty($form_data['last_name'])) {
        $attributes['family_name'] = sanitize_text_field($form_data['last_name']);
    }
    if (!empty($form_data['middle_name'])) {
        $attributes['additional_name'] = sanitize_text_field($form_data['middle_name']);
    }
    
    // Build full name if we have name components
    if (!empty($attributes['given_name']) || !empty($attributes['family_name'])) {
        $full_name_parts = array();
        if (!empty($attributes['given_name'])) {
            $full_name_parts[] = $attributes['given_name'];
        }
        if (!empty($attributes['additional_name'])) {
            $full_name_parts[] = $attributes['additional_name'];
        }
        if (!empty($attributes['family_name'])) {
            $full_name_parts[] = $attributes['family_name'];
        }
        $attributes['full_name'] = implode(' ', $full_name_parts);
    }
    
    // Suffix -> honorific_suffix (Jr., Sr., III, etc.)
    if (!empty($form_data['suffix'])) {
        $attributes['honorific_suffix'] = sanitize_text_field($form_data['suffix']);
    }
    
    // Post-nominal -> also maps to honorific_suffix (Ph.D., M.D., etc.)
    // If both suffix and post-nominal are provided, combine them
    if (!empty($form_data['post_nominal'])) {
        $post_nominal = sanitize_text_field($form_data['post_nominal']);
        if (isset($attributes['honorific_suffix'])) {
            $attributes['honorific_suffix'] .= ', ' . $post_nominal;
        } else {
            $attributes['honorific_suffix'] = $post_nominal;
        }
    }
    
    // Alternate name
    if (!empty($form_data['alternate_name'])) {
        $attributes['alternate_name'] = sanitize_text_field($form_data['alternate_name']);
    }
    
    // Nickname -> can also map to alternate_name if alternate_name is not set
    if (!empty($form_data['nickname'])) {
        if (!isset($attributes['alternate_name'])) {
            $attributes['alternate_name'] = sanitize_text_field($form_data['nickname']);
        }
        // Or store as slug
        $attributes['slug'] = sanitize_title($form_data['nickname']);
    }
    
    // Pronouns -> preferred_pronoun
    if (!empty($form_data['pronouns'])) {
        $attributes['preferred_pronoun'] = sanitize_text_field($form_data['pronouns']);
    }
    
    // Gender
    if (!empty($form_data['gender'])) {
        $attributes['gender'] = sanitize_text_field($form_data['gender']);
    }
    
    // Birth date
    if (!empty($form_data['birth_date'])) {
        // Ensure proper date format (YYYY-MM-DD)
        $attributes['birth_date'] = sanitize_text_field($form_data['birth_date']);
    }
    
    // Title -> job_title
    if (!empty($form_data['job_title'])) {
        $attributes['job_title'] = sanitize_text_field($form_data['job_title']);
    }
    
    // Job function -> can also be stored in job_title or as custom field
    /*if (!empty($form_data['job_function'])) {
        if (!isset($attributes['job_function'])) {
            $attributes['job_function'] = sanitize_text_field($form_data['job_function']);
        }
    }
    
    // Job level -> This IS a standard Wicket person field (discovered via API)!
    if (!empty($form_data['job_level'])) {
        $attributes['job_level'] = sanitize_text_field($form_data['job_level']);
    }*/
    
    // Prepare the person data according to Wicket API format
    // CRITICAL: Must include 'id' and 'type' fields for PATCH requests
    $person_data = array(
        'data' => array(
            'type' => 'people',
            'id' => $person_uuid,  // REQUIRED for PATCH
            'attributes' => $attributes
        )
    );
    
    // No data_fields needed - all fields are standard attributes!
    
    // Log the request for debugging
    error_log('Wicket Additional Info: Updating person ' . $person_uuid);
    error_log('Wicket Additional Info: Request data: ' . json_encode($person_data, JSON_PRETTY_PRINT));
    
    // Make API request to update person (PATCH request)
    $response = wp_remote_request($api_url . '/people/' . $person_uuid, array(
        'method' => 'PATCH',
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ),
        'body' => json_encode($person_data),
        'timeout' => 30
    ));
    
    if (is_wp_error($response)) {
        error_log('Wicket Additional Info: API request failed - ' . $response->get_error_message());
        return array(
            'success' => false,
            'message' => 'API request failed: ' . $response->get_error_message()
        );
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    // Log response for debugging
    error_log('Wicket Additional Info: Response status: ' . $status_code);
    error_log('Wicket Additional Info: Response body: ' . json_encode($body));
    
    if ($status_code === 200) {
        return array(
            'success' => true,
            'message' => 'Person updated successfully',
            'data' => $body
        );
    } else {
        // Handle error response
        $error_message = 'Failed to update person. Status: ' . $status_code;
        
        if (isset($body['errors']) && is_array($body['errors'])) {
            foreach ($body['errors'] as $error) {
                $error_detail = json_encode($error);
                error_log('Wicket Additional Info: API Error - ' . $error_detail);
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
 * Get person details from Wicket (needed for data_fields updates)
 * IMPORTANT: Always include schemas to understand data_fields structure
 */
function wicket_get_person_additional_info($person_uuid) {
    $token = wicket_generate_jwt_token_additional_info();
    if (!$token) {
        return null;
    }
    
    $api_url = wicket_get_api_url_additional_info();
    
    // Include json_schemas_available to understand data_fields structure
    $response = wp_remote_get($api_url . '/people/' . $person_uuid . '?include=json_schemas_available', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ),
        'timeout' => 30
    ));
    
    if (is_wp_error($response)) {
        error_log('Wicket Additional Info: Failed to get person - ' . $response->get_error_message());
        return null;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($status_code === 200 && !empty($body['data'])) {
        return $body;
    }
    
    error_log('Wicket Additional Info: Failed to get person - Status: ' . $status_code);
    if (isset($body['errors'])) {
        error_log('Wicket Additional Info: Errors: ' . json_encode($body['errors']));
    }
    
    return null;
}

/**
 * Find person by email in Wicket
 */
function wicket_find_person_by_email_additional_info($email) {
    $token = wicket_generate_jwt_token_additional_info();
    if (!$token) {
        return null;
    }
    
    $api_url = wicket_get_api_url_additional_info();
    
    // Search for person by email
    $response = wp_remote_get($api_url . '/people?filter[emails_address_eq]=' . urlencode($email), array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ),
        'timeout' => 30
    ));
    
    if (is_wp_error($response)) {
        error_log('Wicket Additional Info: Failed to search person - ' . $response->get_error_message());
        return null;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($status_code === 200 && !empty($body['data']) && is_array($body['data'])) {
        // Return the first person found
        return $body['data'][0]['id'] ?? null;
    }
    
    return null;
}

/**
 * Fluent Forms submission handler for Additional Information form
 * Hook into Fluent Forms after form submission
 */
function handle_fluent_forms_wicket_additional_info($entry_id, $form_data, $form) {
    // Specify which form IDs should trigger this handler
    // Replace with your actual Additional Information form ID
    $target_form_ids = array(1); // Change this to your form ID
    
    if (!empty($target_form_ids) && !in_array($form->id, $target_form_ids)) {
        return; // Skip if not the target form
    }
    
    // Get the current user's email or extract from form data
    $email = '';
    
    // Method 1: Get from current logged-in user
    if (is_user_logged_in()) {
        $current_user = wp_get_current_user();
        $email = $current_user->user_email;
    }
    
    // Method 2: If form has an email field, use that instead
    if (empty($email) && !empty($form_data['email'])) {
        $email = $form_data['email'];
    }
    
    // Method 3: Check if there's a hidden field with email
    if (empty($email) && !empty($form_data['user_email'])) {
        $email = $form_data['user_email'];
    }
    
    if (empty($email)) {
        error_log('Wicket Additional Info: No email address found for user');
        return;
    }
    
    error_log('Wicket Additional Info: Processing form submission for email: ' . $email);
    
    // Find the person in Wicket by email
    $person_uuid = wicket_find_person_by_email_additional_info($email);
    
    if (!$person_uuid) {
        error_log('Wicket Additional Info: Person not found in Wicket for email: ' . $email);
        // Optionally, you could create a new person here instead
        return;
    }
    
    error_log('Wicket Additional Info: Found person UUID: ' . $person_uuid);
    
    // Update person details in Wicket
    $result = wicket_update_person_additional_info($person_uuid, $form_data);
    
    // Log the result
    if ($result['success']) {
        error_log("Wicket Additional Info Success: Person updated with UUID " . $person_uuid);
        
        // Optionally store the last update timestamp in the form entry meta
        if ($entry_id) {
            update_post_meta($entry_id, '_wicket_person_uuid', $person_uuid);
            update_post_meta($entry_id, '_wicket_updated_at', current_time('mysql'));
        }
        
        // You can add custom actions here for successful update
        do_action('wicket_person_additional_info_success', $result, $form_data, $form);
        
    } else {
        error_log("Wicket Additional Info Failed: " . $result['message']);
        
        // You can add custom actions here for failed update
        do_action('wicket_person_additional_info_failed', $result, $form_data, $form);
    }
}

/**
 * Generate JWT token for Wicket API authentication
 */
function wicket_generate_jwt_token_additional_info() {
    $tenant = get_option('wicket_tenant_name', '');
    $api_secret = get_option('wicket_api_secret_key', '');
    $admin_uuid = get_option('wicket_admin_user_uuid', '');
    $staging = get_option('wicket_staging', 0);
    
    if (empty($tenant) || empty($api_secret) || empty($admin_uuid)) {
        error_log('Wicket Additional Info: Missing API configuration - tenant: ' . (!empty($tenant) ? 'set' : 'missing') . 
                  ', secret: ' . (!empty($api_secret) ? 'set' : 'missing') . 
                  ', admin_uuid: ' . (!empty($admin_uuid) ? 'set' : 'missing'));
        return false;
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

/**
 * Get Wicket API URL
 */
function wicket_get_api_url_additional_info() {
    $tenant = get_option('wicket_tenant_name', '');
    $staging = get_option('wicket_staging', 0);
    
    if ($staging) {
        return "https://{$tenant}-api.staging.wicketcloud.com";
    } else {
        return "https://{$tenant}-api.wicketcloud.com";
    }
}

// Hook into Fluent Forms after form submission
add_action('fluentform/submission_inserted', 'handle_fluent_forms_wicket_additional_info', 10, 3);