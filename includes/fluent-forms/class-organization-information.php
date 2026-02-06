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
    $form_id = defined('MYIES_FORM_ORGANIZATION_INFO') ? MYIES_FORM_ORGANIZATION_INFO : 26;

    if ($form->id != $form_id) {
        return;
    }

    $user_id = get_current_user_id();
    if (!$user_id) {
        myies_log('No logged-in user', 'Wicket Org Info');
        return;
    }

    $person_uuid = wicket_api()->get_person_uuid($user_id);
    
    if (empty($person_uuid)) {
        myies_log('No Wicket person UUID for user ' . $user_id, 'Wicket Org Info');
        return;
    }

    myies_log('Processing form for person: ' . $person_uuid, 'Wicket Org Info');
    
    $selected_org_name = isset($form_data['company_name']) ? sanitize_text_field($form_data['company_name']) : '';
    $new_org_name = isset($form_data['company_name_alt']) ? sanitize_text_field($form_data['company_name_alt']) : '';
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
add_action('fluentform/submission_inserted', 'wicket_sync_organization_info_to_wicket', 10, 3);

/**
 * Find organization by name and return its UUID
 */
function wicket_find_organization_by_name($org_name) {
    $api = wicket_api();
    $results = $api->search_organizations_api($org_name, 1);

    if (!empty($results) && isset($results[0]['id'])) {
        return $results[0]['id'];
    }

    return null;
}

/**
 * Create person-to-organization connection
 */
function wicket_create_person_org_connection($person_uuid, $org_uuid, $connection_type = 'member') {
    $api = wicket_api();
    return $api->create_person_org_connection($person_uuid, $org_uuid, $connection_type);
}

/**
 * Create a new organization
 */
function wicket_myies_create_organization($name, $type) {
    $api = wicket_api();
    $result = $api->create_organization(array(
        'legal_name' => $name,
        'type' => strtolower($type)
    ));

    if ($result && isset($result['data']['id'])) {
        myies_log('Created organization: ' . $result['data']['id'], 'Wicket Org Info');
        return array('id' => $result['data']['id']);
    }

    myies_log('Failed to create organization: ' . $name, 'Wicket Org Info');
    return null;
}