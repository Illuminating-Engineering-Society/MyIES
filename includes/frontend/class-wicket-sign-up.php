<?php
/**
 * Wicket Person Registration Handler for Fluent Forms
 * 
 * This script handles creating a new person in Wicket with basic user credentials
 * when a Fluent Forms form is submitted with the required fields:
 * - names[first_name]
 * - names[last_name]  
 * - email
 * - password
 */

/**
 * Generate JWT token for Wicket API authentication
 */
function wicket_generate_jwt_token() {
    $tenant = get_option('wicket_tenant_name', '');
    $api_secret = get_option('wicket_api_secret_key', '');
    $admin_uuid = get_option('wicket_admin_user_uuid', '');
    $staging = get_option('wicket_staging', 0);
    
    if (empty($tenant) || empty($api_secret) || empty($admin_uuid)) {
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
function wicket_get_api_url() {
    $tenant = get_option('wicket_tenant_name', '');
    $staging = get_option('wicket_staging', 0);
    
    if ($staging) {
        return "https://{$tenant}-api.staging.wicketcloud.com";
    } else {
        return "https://{$tenant}-api.wicketcloud.com";
    }
}

/**
 * Create person in Wicket
 */
function wicket_create_basic_person($first_name, $last_name, $email, $password) {
    $token = wicket_generate_jwt_token();
    if (!$token) {
        return array(
            'success' => false,
            'message' => 'Wicket API credentials not configured properly'
        );
    }
    
    $api_url = wicket_get_api_url();
    
    // Prepare the person data according to Wicket API format
    $person_data = array(
        'data' => array(
            'type' => 'people',
            'attributes' => array(
                'given_name' => sanitize_text_field($first_name),
                'family_name' => sanitize_text_field($last_name),
                'user' => array(
                    'password' => $password,
                    'password_confirmation' => $password,
                    'confirmed_at' => gmdate('Y-m-d\TH:i:s.000\Z'),
                    'skip_confirmation_notification' => true,
                )
            ),
            'relationships' => array(
                'emails' => array(
                    'data' => array(
                        array(
                            'type' => 'emails',
                            'attributes' => array(
                                'address' => sanitize_email($email)
                            )
                        )
                    )
                )
            )
        )
    );
    
    // Make API request to create person
    $response = wp_remote_post($api_url . '/people', array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ),
        'body' => json_encode($person_data),
        'timeout' => 30
    ));
    
    if (is_wp_error($response)) {
        return array(
            'success' => false,
            'message' => 'API request failed: ' . $response->get_error_message()
        );
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($status_code === 200 || $status_code === 201) {
        return array(
            'success' => true,
            'message' => 'Person created successfully in Wicket',
            'person_id' => $body['data']['id'] ?? null,
            'data' => $body
        );
    } else if ($status_code === 409) {
        // Handle email already exists scenario
        return array(
            'success' => false,
            'message' => 'Email address already exists in Wicket',
            'status_code' => $status_code,
            'error_data' => $body
        );
    } else {
        return array(
            'success' => false,
            'message' => 'Failed to create person. Status: ' . $status_code,
            'status_code' => $status_code,
            'error_data' => $body
        );
    }
}

/**
 * Fluent Forms submission handler
 * Hook into Fluent Forms after form submission
 */
function handle_fluent_forms_wicket_registration($entry_id, $form_data, $form) {
    // You can specify which form IDs should trigger Wicket registration
    // Replace 'YOUR_FORM_ID' with the actual form ID, or remove this check to apply to all forms
    $target_form_ids = array(5); // Replace with your actual form IDs
    
    if (!empty($target_form_ids) && !in_array($form->id, $target_form_ids)) {
        return; // Skip if not the target form
    }
    
    // Extract form data
    $first_name = '';
    $last_name = '';
    $email = '';
    $password = '';
    
    foreach ($form_data as $field_name => $field_value) {
        switch ($field_name) {
            case 'names':
                if (is_array($field_value)) {
                    $first_name = $field_value['first_name'] ?? '';
                    $last_name = $field_value['last_name'] ?? '';
                }
                break;
            case 'email':
                $email = $field_value;
                break;
            case 'password':
                $password = $field_value;
                break;
        }
    }
    
    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        error_log('Wicket Registration: Missing required fields');
        return;
    }
    
    // Create person in Wicket
    $result = wicket_create_basic_person($first_name, $last_name, $email, $password);
    
    // Log the result
    if ($result['success']) {
        error_log("Wicket Registration Success: Person created with ID " . ($result['person_id'] ?? 'unknown'));
        
        // Optionally store the Wicket person ID in the form entry meta
        if ($entry_id && !empty($result['person_id'])) {
            // Store Wicket person ID for future reference
            update_post_meta($entry_id, '_wicket_person_id', $result['person_id']);
        }
        
        // You can add custom actions here for successful registration
        do_action('wicket_person_registration_success', $result, $form_data, $form);
        
    } else {
        error_log("Wicket Registration Failed: " . $result['message']);
        
        // You can add custom actions here for failed registration
        do_action('wicket_person_registration_failed', $result, $form_data, $form);
    }
}

// Hook into Fluent Forms after form submission
add_action('fluentform/submission_inserted', 'handle_fluent_forms_wicket_registration', 10, 3);
