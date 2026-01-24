<?php
/**
 * Wicket Organization Information Handler - Form 26
 * 
 * Creates person-to-organization connection via POST /connections
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sync organization info to Wicket when Form 26 is submitted
 */
function wicket_sync_organization_info_to_wicket($entry_id, $form_data, $form) {
    $form_id = 26;
    error_log('hi');
    
    if ($form->id != $form_id) {
        error_log('hi there');
        return;
    }
    
    $user_id = get_current_user_id();
    if (!$user_id) {
        error_log('Wicket Org Info: No logged-in user');
        return;
    }
    
    $person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);

    error_log('user id' . $user_id . ' wicket person' . $person_uuid);
    
    if (empty($person_uuid)) {
        error_log('Wicket Org Info: No Wicket person UUID for user');
        return;
    }
    
    error_log('Wicket Org Info: Processing form 26 for person: ' . $person_uuid);
    
    $selected_org_name = isset($form_data['company_name']) ? sanitize_text_field($form_data['company_name']) : '';
    $new_org_name = isset($form_data['comapny_name_alt']) ? sanitize_text_field($form_data['comapny_name_alt']) : '';
    $new_org_type = isset($form_data['type_of_organization']) ? sanitize_text_field($form_data['type_of_organization']) : '';
    
    $org_id_to_add = null;
    
    // If they selected an existing organization
    if (!empty($selected_org_name)) {
        // Search for the organization by name to get its UUID
        $org_id_to_add = wicket_find_organization_by_name($selected_org_name);
        if ($org_id_to_add) {
            error_log('Wicket Org Info: Found existing org UUID: ' . $org_id_to_add);
        } else {
            error_log('Wicket Org Info: Could not find UUID for organization: ' . $selected_org_name);
        }
    } 
    // If they entered a new organization
    elseif (!empty($new_org_name) && !empty($new_org_type)) {
        $new_org = wicket_myies_create_organization($new_org_name, $new_org_type);
        if ($new_org && isset($new_org['id'])) {
            $org_id_to_add = $new_org['id'];
            error_log('Wicket Org Info: Created new org: ' . $org_id_to_add);
        }
    }
    
    // Create connection between person and organization
    if (!empty($org_id_to_add)) {
        $result = wicket_create_person_org_connection($person_uuid, $org_id_to_add);
        if (!is_wp_error($result)) {
            error_log('Wicket Org Info: Successfully created connection between person and organization');
        } else {
            error_log('Wicket Org Info: Failed to create connection - ' . $result->get_error_message());
        }
    }
    
    error_log('Wicket Org Info: Processing complete');
}
add_action('fluentform/before_submission_confirmation', 'wicket_sync_organization_info_to_wicket', 10, 3);

/**
 * Find organization by name and return its UUID
 */
function wicket_find_organization_by_name($org_name) {
    $jwt_token = wicket_generate_jwt_token();
    if (is_wp_error($jwt_token)) {
        return null;
    }
    
    $tenant = get_option('wicket_tenant_name');
    $is_staging = get_option('wicket_staging', 0);
    
    $api_url = $is_staging 
        ? "https://{$tenant}-api.staging.wicketcloud.com/organizations?filter[legal_name]=" . urlencode($org_name)
        : "https://{$tenant}-api.wicketcloud.com/organizations?filter[legal_name]=" . urlencode($org_name);
    
    $response = wp_remote_get($api_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $jwt_token,
            'Accept' => 'application/json'
        ),
        'timeout' => 30
    ));
    
    if (is_wp_error($response)) {
        error_log('Wicket Org Info: Search failed - ' . $response->get_error_message());
        return null;
    }
    
    $code = wp_remote_retrieve_response_code($response);
    $response_body = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($code == 200 && isset($response_body['data']) && !empty($response_body['data'])) {
        return $response_body['data'][0]['id'];
    }
    
    return null;
}

/**
 * Create person-to-organization connection
 */
function wicket_create_person_org_connection($person_uuid, $org_uuid, $connection_type = 'member') {
    error_log('meqll');
    // First check if connection already exists
    $jwt_token = wicket_generate_jwt_token();
    if (is_wp_error($jwt_token)) {
        return $jwt_token;
    }
    
    $tenant = get_option('wicket_tenant_name');
    $is_staging = get_option('wicket_staging', 0);
    
    $search_url = $is_staging 
        ? "https://{$tenant}-api.staging.wicketcloud.com/people/{$person_uuid}/connections?filter[connection_type_eq]=person_to_organization"
        : "https://{$tenant}-api.wicketcloud.com/people/{$person_uuid}/connections?filter[connection_type_eq]=person_to_organization";
    
    $search_response = wp_remote_get($search_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $jwt_token,
            'Accept' => 'application/json'
        ),
        'timeout' => 30
    ));
    
    if (!is_wp_error($search_response)) {
        $search_body = json_decode(wp_remote_retrieve_body($search_response), true);
        
        if (!empty($search_body['data'])) {
            // Check if connection to this specific org exists
            foreach ($search_body['data'] as $conn) {
                if (isset($conn['relationships']['to']['data']['id']) && 
                    $conn['relationships']['to']['data']['id'] === $org_uuid) {
                    error_log("Wicket Org Info: Connection already exists between person {$person_uuid} and org {$org_uuid}");
                    return $conn;
                }
            }
        }
    }
    
    // Create new connection using POST /connections
    $connection_data = array(
        'data' => array(
            'type' => 'connections',
            'attributes' => array(
                'type' => $connection_type,
                'description' => 'Organization member',
                'connection_type' => 'person_to_organization'
            ),
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
    
    error_log("Wicket Org Info: Creating connection between person {$person_uuid} and organization {$org_uuid}");
    error_log("Wicket Org Info: Connection data: " . json_encode($connection_data));
    
    $api_url = $is_staging 
        ? "https://{$tenant}-api.staging.wicketcloud.com/connections"
        : "https://{$tenant}-api.wicketcloud.com/connections";
    
    $response = wp_remote_post($api_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $jwt_token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ),
        'body' => json_encode($connection_data),
        'timeout' => 30
    ));
    
    if (is_wp_error($response)) {
        error_log('Wicket Org Info: POST /connections failed - ' . $response->get_error_message());
        return $response;
    }
    
    $code = wp_remote_retrieve_response_code($response);
    $response_body = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($code == 200 || $code == 201) {
        error_log('Wicket Org Info: ✓ Successfully created connection');
        return $response_body;
    } else {
        $error_message = isset($response_body['errors']) ? json_encode($response_body['errors']) : 'Unknown error';
        error_log("Wicket Org Info: POST /connections failed. Status: {$code}, Response: " . $error_message);
        return new WP_Error('wicket_connection_error', $error_message, array('status' => $code));
    }
}

/**
 * Create a new organization
 */
function wicket_myies_create_organization($name, $type) {
    $jwt_token = wicket_generate_jwt_token();
    if (is_wp_error($jwt_token)) {
        return null;
    }
    
    $tenant = get_option('wicket_tenant_name');
    $is_staging = get_option('wicket_staging', 0);
    
    $api_url = $is_staging 
        ? "https://{$tenant}-api.staging.wicketcloud.com/organizations"
        : "https://{$tenant}-api.wicketcloud.com/organizations";
    
    $body = array(
        'data' => array(
            'type' => 'organizations',
            'attributes' => array(
                'legal_name' => $name,
                'type' => strtolower($type)
            )
        )
    );
    
    error_log('Wicket Org Info: Creating organization: ' . json_encode($body));
    
    $response = wp_remote_post($api_url, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $jwt_token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ),
        'body' => json_encode($body),
        'timeout' => 30
    ));
    
    if (is_wp_error($response)) {
        error_log('Wicket Org Info: Failed to create organization - ' . $response->get_error_message());
        return null;
    }
    
    $code = wp_remote_retrieve_response_code($response);
    $response_body = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($code == 201 && isset($response_body['data']['id'])) {
        error_log('Wicket Org Info: ✓ Successfully created organization: ' . $response_body['data']['id']);
        return array('id' => $response_body['data']['id']);
    } else {
        error_log('Wicket Org Info: Failed to create organization. Status: ' . $code . ', Response: ' . json_encode($response_body));
        return null;
    }
}