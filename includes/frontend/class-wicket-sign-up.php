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
 * Validate email uniqueness before form saves
 */
function wicket_validate_registration_email($errors, $data, $form, $fields) {
    $target_form_ids = array(38);

    if (!in_array($form->id, $target_form_ids)) {
        return $errors;
    }

    $email = isset($data['email']) ? sanitize_email($data['email']) : '';

    if (!empty($email) && email_exists($email)) {
        $errors['email'] = array(__('This email address is already registered. Please log in or use a different email.', 'wicket-integration'));
    }

    return $errors;
}
add_filter('fluentform/validation_errors', 'wicket_validate_registration_email', 10, 4);

/**
 * Fluent Forms submission handler
 * Creates person in Wicket, then WordPress user, then logs in
 */
function handle_fluent_forms_wicket_registration($entry_id, $form_data, $form) {
    $target_form_ids = array(38);

    if (!in_array($form->id, $target_form_ids)) {
        return;
    }

    // Extract form data
    $first_name = '';
    $last_name = '';
    $email = '';
    $password = '';
    $company = '';

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
            case 'company':
                $company = sanitize_text_field($field_value);
                break;
        }
    }

    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        error_log('Wicket Registration: Missing required fields');
        return;
    }

    // Safety check
    if (email_exists($email)) {
        error_log('Wicket Registration: Email already exists in WordPress — ' . $email);
        return;
    }

    // Step 1: Create person in Wicket
    $result = wicket_create_basic_person($first_name, $last_name, $email, $password);

    if ($result['success']) {
        error_log("Wicket Registration: Person created in Wicket — UUID: " . ($result['person_id'] ?? 'unknown'));
    } else {
        error_log("Wicket Registration: Wicket creation failed — " . $result['message']);
        // On 409 (email exists in Wicket) we still create the WP user
        if (($result['status_code'] ?? 0) !== 409) {
            return;
        }
    }

    // Step 2: Create WordPress user
    $user_id = wp_insert_user(array(
        'user_login'   => $email,
        'user_email'   => $email,
        'user_pass'    => $password,
        'first_name'   => sanitize_text_field($first_name),
        'last_name'    => sanitize_text_field($last_name),
        'display_name' => trim(sanitize_text_field($first_name) . ' ' . sanitize_text_field($last_name)),
        'role'         => 'subscriber',
    ));

    if (is_wp_error($user_id)) {
        error_log('Wicket Registration: WP user creation failed — ' . $user_id->get_error_message());
        return;
    }

    error_log('Wicket Registration: WP user created — ID: ' . $user_id);

    // Link Wicket UUID
    if (!empty($result['person_id'])) {
        update_user_meta($user_id, 'wicket_person_uuid', $result['person_id']);
    }

    // Step 3: Create company in Wicket and connect to person
    if (!empty($company) && !empty($result['person_id'])) {
        $person_uuid = $result['person_id'];

        // Create the organization in Wicket (type: company)
        $new_org = wicket_myies_create_organization($company, 'company');

        if ($new_org && isset($new_org['id'])) {
            error_log('Wicket Registration: Company created — UUID: ' . $new_org['id']);

            // Connect person to organization
            $conn_result = wicket_create_person_org_connection($person_uuid, $new_org['id']);
            if (!is_wp_error($conn_result)) {
                error_log('Wicket Registration: Person connected to company');
                update_user_meta($user_id, 'wicket_org_uuid', $new_org['id']);
                update_user_meta($user_id, 'wicket_org_name', $company);
            } else {
                error_log('Wicket Registration: Failed to connect person to company — ' . $conn_result->get_error_message());
            }
        } else {
            error_log('Wicket Registration: Failed to create company in Wicket — ' . $company);
        }
    }

    // Step 4: Log the user in
    wp_set_current_user($user_id);
    wp_set_auth_cookie($user_id, true);

    error_log('Wicket Registration: User logged in — ID: ' . $user_id);

    do_action('wicket_person_registration_success', $result, $form_data, $form);
}

// Hook into Fluent Forms
add_action('fluentform/submission_inserted', 'handle_fluent_forms_wicket_registration', 10, 3);
