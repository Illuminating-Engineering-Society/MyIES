<?php
/**
 * Wicket Communication Preferences Handler for Fluent Forms
 * 
 * This script handles updating person communication preferences in Wicket when the 
 * "Communication Preferences" form (ID: 27) is submitted.
 * 
 * @package Wicket_Integration
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Update communication preferences in Wicket
 */
function wicket_update_communication_preferences($person_uuid, $form_data) {
    $token = wicket_generate_jwt_token_comm_prefs();
    if (!$token) {
        return array(
            'success' => false,
            'message' => 'Wicket API credentials not configured properly'
        );
    }
    
    $api_url = wicket_get_api_url_comm_prefs();
    
    error_log('Wicket Communication Preferences: Starting update for person ' . $person_uuid);
    error_log('Wicket Communication Preferences: Form data keys: ' . implode(', ', array_keys($form_data)));
    
    // Step 1: GET current person data
    $current_person = wicket_get_person_comm_prefs($person_uuid);
    if (!$current_person) {
        error_log('Wicket Communication Preferences: Failed to get current person data');
        return array(
            'success' => false,
            'message' => 'Failed to retrieve current person data'
        );
    }
    
    error_log('Wicket Communication Preferences: Got current person data');
    
    // Build attributes
    $attributes = array();
    
    // Language fields
    if (!empty($form_data['language'])) {
        $attributes['language'] = wicket_normalize_language_code($form_data['language']);
        error_log('Wicket Communication Preferences: Language set to: ' . $attributes['language']);
    }
    
    if (!empty($form_data['languages_spoken'])) {
        // languages_spoken must be an array
        $attributes['languages_spoken'] = array(sanitize_text_field($form_data['languages_spoken']));
        error_log('Wicket Communication Preferences: Languages spoken: ' . print_r($attributes['languages_spoken'], true));
    }
    
    if (!empty($form_data['languages_written'])) {
        // languages_written must be an array
        $attributes['languages_written'] = array(sanitize_text_field($form_data['languages_written']));
        error_log('Wicket Communication Preferences: Languages written: ' . print_r($attributes['languages_written'], true));
    }
    
    // Build communications data
    $communications_checkboxes = isset($form_data['communications']) && is_array($form_data['communications']) 
        ? $form_data['communications'] 
        : array();
    
    $communications_data = wicket_build_communications_data($current_person, $communications_checkboxes);
    
    error_log('Wicket Communication Preferences: Built communications data: ' . json_encode($communications_data));
    
    if (!empty($communications_data)) {
        $attributes['data'] = array(
            'communications' => $communications_data
        );
    }
    
    // Prepare PATCH request
    $person_data = array(
        'data' => array(
            'type' => 'people',
            'id' => $person_uuid,
            'attributes' => $attributes
        )
    );
    
    error_log('Wicket Communication Preferences: Request data: ' . json_encode($person_data, JSON_PRETTY_PRINT));
    
    // Make API request
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
        error_log('Wicket Communication Preferences: API request failed - ' . $response->get_error_message());
        return array(
            'success' => false,
            'message' => 'API request failed: ' . $response->get_error_message()
        );
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    error_log('Wicket Communication Preferences: Response status: ' . $status_code);
    error_log('Wicket Communication Preferences: Response body: ' . json_encode($body, JSON_PRETTY_PRINT));
    
    if ($status_code === 200) {
        return array(
            'success' => true,
            'message' => 'Communication preferences updated successfully',
            'data' => $body
        );
    } else {
        return array(
            'success' => false,
            'message' => 'Failed to update. Status: ' . $status_code,
            'status_code' => $status_code,
            'error_data' => $body
        );
    }
}

/**
 * Get current person data from Wicket
 */
function wicket_get_person_comm_prefs($person_uuid) {
    $token = wicket_generate_jwt_token_comm_prefs();
    if (!$token) {
        return null;
    }
    
    $api_url = wicket_get_api_url_comm_prefs();
    
    $response = wp_remote_get($api_url . '/people/' . $person_uuid, array(
        'headers' => array(
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ),
        'timeout' => 30
    ));
    
    if (is_wp_error($response)) {
        error_log('Wicket Communication Preferences: Failed to get person - ' . $response->get_error_message());
        return null;
    }
    
    $status_code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    
    if ($status_code === 200 && !empty($body['data'])) {
        return $body['data'];
    }
    
    return null;
}

/**
 * Build communications data structure
 */
function wicket_build_communications_data($current_person, $communications_checkboxes) {
    // Mapping of form checkbox values to Wicket sublists
    $sublist_mapping = array(
        'LDA Magazine' => 'one',
        'IES Lighting Conference (Annual Conference)' => 'two',
        'Street & Area Lighting Conference' => 'three',
        'Other IES Events' => 'four',
        'Illumination Awards' => 'five',
        'Webinars' => 'six',
        'Section Events' => 'seven',
        'Online Education Opportunities' => 'eight',
        'In-Person Education Opportunities' => 'nine',
        'Speaking Opportunities' => 'ten',
        'Lighting Standards' => 'eleven',
        'Press/Media Releases' => 'twelve',
        'Partner Organization News' => 'thirteen',
        'Scholarships & Funding' => 'fourteen',
        'Sponsorship & Partnership Opportunities' => 'fifteen',
        'LC Exam & Study Groups' => 'sixteen',
        'IES Newsletters' => 'seventeen'
    );
    
    // Get existing communications
    $existing_comms = isset($current_person['attributes']['data']['communications']) 
        ? $current_person['attributes']['data']['communications'] 
        : array();
    
    // Initialize structure
    $communications = array(
        'email' => false,
        'sublists' => array(),
        'sync_services' => isset($existing_comms['sync_services']) ? $existing_comms['sync_services'] : true,
        'journal_physical_copy' => isset($existing_comms['journal_physical_copy']) ? $existing_comms['journal_physical_copy'] : false
    );
    
    // Initialize all sublists to false
    $all_sublists = array('one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 
                         'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 
                         'fifteen', 'sixteen', 'seventeen');
    
    foreach ($all_sublists as $sublist) {
        $communications['sublists'][$sublist] = false;
    }
    
    // Process checkboxes
    if (!empty($communications_checkboxes)) {
        foreach ($communications_checkboxes as $checkbox_value) {
            $checkbox_value = trim($checkbox_value);
            
            if ($checkbox_value === 'Yes, accept email communications') {
                $communications['email'] = true;
                error_log('Wicket Communication Preferences: Email enabled');
            } else if (isset($sublist_mapping[$checkbox_value])) {
                $sublist_key = $sublist_mapping[$checkbox_value];
                $communications['sublists'][$sublist_key] = true;
                error_log('Wicket Communication Preferences: Sublist ' . $sublist_key . ' enabled');
            }
        }
    }
    
    return $communications;
}

/**
 * Normalize language code to ISO 639-1
 */
function wicket_normalize_language_code($language) {
    $language_map = array(
        'English' => 'en', 'Spanish' => 'es', 'French' => 'fr', 'German' => 'de',
        'Italian' => 'it', 'Portuguese' => 'pt', 'Russian' => 'ru', 'Japanese' => 'ja',
        'Korean' => 'ko', 'Chinese' => 'zh', 'Arabic' => 'ar', 'Hindi' => 'hi',
        'Bengali' => 'bn', 'Punjabi' => 'pa', 'Javanese' => 'jv', 'Vietnamese' => 'vi',
        'Turkish' => 'tr', 'Tamil' => 'ta', 'Telugu' => 'te', 'Marathi' => 'mr',
        'Urdu' => 'ur', 'Indonesian' => 'id', 'Dutch' => 'nl', 'Polish' => 'pl',
        'Ukrainian' => 'uk', 'Romanian' => 'ro', 'Greek' => 'el', 'Czech' => 'cs',
        'Swedish' => 'sv', 'Hungarian' => 'hu', 'Finnish' => 'fi', 'Danish' => 'da',
        'Norwegian' => 'no', 'Slovak' => 'sk', 'Bulgarian' => 'bg', 'Hebrew' => 'he',
        'Thai' => 'th', 'Persian' => 'fa', 'Malay' => 'ms', 'Swahili' => 'sw',
        'Afrikaans' => 'af'
    );
    
    if (strlen($language) === 2) {
        return strtolower($language);
    }
    
    if (isset($language_map[$language])) {
        return $language_map[$language];
    }
    
    return strtolower(substr($language, 0, 2));
}

/**
 * Fluent Forms submission handler
 */
function handle_fluent_forms_wicket_comm_prefs($entry_id, $form_data, $form) {
    error_log('Wicket Communication Preferences: HOOK FIRED - Form ID: ' . $form->id);
    
    // Target form ID
    $target_form_ids = array(27);
    
    if (!in_array($form->id, $target_form_ids)) {
        error_log('Wicket Communication Preferences: Not target form, skipping');
        return;
    }
    
    error_log('Wicket Communication Preferences: Processing form 27');
    
    // Get current user
    $user_id = get_current_user_id();
    if (!$user_id) {
        error_log('Wicket Communication Preferences: No logged-in user');
        return;
    }
    
    error_log('Wicket Communication Preferences: User ID: ' . $user_id);
    
    // Get person UUID
    $person_uuid = get_user_meta($user_id, 'wicket_uuid', true);
    if (empty($person_uuid)) {
        error_log('Wicket Communication Preferences: No wicket_uuid for user');
        return;
    }
    
    error_log('Wicket Communication Preferences: Person UUID: ' . $person_uuid);
    
    // Update preferences
    $result = wicket_update_communication_preferences($person_uuid, $form_data);
    
    if ($result['success']) {
        error_log("Wicket Communication Preferences: Success!");
        
        if ($entry_id) {
            update_post_meta($entry_id, '_wicket_person_uuid', $person_uuid);
            update_post_meta($entry_id, '_wicket_updated_at', current_time('mysql'));
        }
    } else {
        error_log("Wicket Communication Preferences: Failed - " . $result['message']);
    }
}

/**
 * Generate JWT token
 */
function wicket_generate_jwt_token_comm_prefs() {
    $tenant = get_option('wicket_tenant_name', '');
    $api_secret = get_option('wicket_api_secret_key', '');
    $admin_uuid = get_option('wicket_admin_user_uuid', '');
    
    if (empty($tenant) || empty($api_secret) || empty($admin_uuid)) {
        error_log('Wicket Communication Preferences: Missing API configuration');
        return false;
    }
    
    $api_url = wicket_get_api_url_comm_prefs();
    
    $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
    $payload = json_encode([
        'exp' => time() + (60 * 60),
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
function wicket_get_api_url_comm_prefs() {
    $tenant = get_option('wicket_tenant_name', '');
    $staging = get_option('wicket_staging', 0);
    
    if ($staging) {
        return "https://{$tenant}-api.staging.wicketcloud.com";
    } else {
        return "https://{$tenant}-api.wicketcloud.com";
    }
}

// Hook into Fluent Forms - REGISTER DIRECTLY
error_log('Wicket Communication Preferences: Registering hook');
add_action('fluentform/submission_inserted', 'handle_fluent_forms_wicket_comm_prefs', 10, 3);
error_log('Wicket Communication Preferences: Hook registered');