<?php
/**
 * Wicket API to ACF User Fields Sync with WordPress Profile Integration
 * 
 * This script provides functionality to sync Wicket person data to ACF user fields
 * with a convenient sync button in the WordPress user profile page.
 * 
 * UPDATED: Unified field mapping with wicket_ prefix for consistency
 * UPDATED: Added support for addresses and phones
 */

class WicketACFSync {
    
    public function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        // Add hooks for user profile integration
        add_action('show_user_profile', array($this, 'add_sync_button_to_profile'));
        add_action('edit_user_profile', array($this, 'add_sync_button_to_profile'));
        add_action('wp_ajax_sync_wicket_data', array($this, 'handle_sync_ajax_request'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // REMOVED: auto_sync_wicket_data_on_login to avoid duplicate syncs
        // The WicketLoginSync class handles login sync now
    }
    
    /**
     * Add sync button to user profile page
     */
    public function add_sync_button_to_profile($user) {
        // Check if current user can edit this profile
        if (!current_user_can('edit_user', $user->ID)) {
            return;
        }
        
        // Get Wicket configuration status
        $config_status = $this->check_wicket_configuration();
        
        ?>
        <h3><?php _e('Wicket Data Sync', 'wicket-acf-sync'); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label><?php _e('Sync with Wicket', 'wicket-acf-sync'); ?></label>
                </th>
                <td>
                    <div id="wicket-sync-container">
                        <?php if ($config_status['configured']): ?>
                            <button type="button" id="sync-wicket-btn" class="button button-secondary" data-user-id="<?php echo esc_attr($user->ID); ?>">
                                <span class="sync-text"><?php _e('Sync Wicket Data', 'wicket-acf-sync'); ?></span>
                                <span class="spinner" style="display: none;"></span>
                            </button>
                            <p class="description">
                                <?php _e('Click to sync user data from Wicket. This will update all profile fields with the latest information.', 'wicket-acf-sync'); ?>
                            </p>
                            
                            <!-- Display last sync information if available -->
                            <?php 
                            $last_sync = get_user_meta($user->ID, 'wicket_last_sync', true);
                            $last_login_sync = get_user_meta($user->ID, 'wicket_last_login_sync', true);
                            $display_sync = $last_sync ?: $last_login_sync;
                            if ($display_sync): 
                            ?>
                                <p class="description">
                                    <strong><?php _e('Last sync:', 'wicket-acf-sync'); ?></strong> 
                                    <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($display_sync))); ?>
                                </p>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <p class="notice notice-warning inline">
                                <strong><?php _e('Wicket API not configured.', 'wicket-acf-sync'); ?></strong><br>
                                <?php echo esc_html($config_status['message']); ?>
                            </p>
                        <?php endif; ?>
                        
                        <!-- Results container -->
                        <div id="wicket-sync-results" style="margin-top: 10px;"></div>
                    </div>
                </td>
            </tr>
        </table>
        
        <style>
        #wicket-sync-container .spinner {
            float: none;
            margin: 0 5px;
            visibility: visible;
        }
        #sync-wicket-btn:disabled {
            pointer-events: none;
        }
        .wicket-sync-success {
            color: #00a32a;
            font-weight: bold;
        }
        .wicket-sync-error {
            color: #d63638;
            font-weight: bold;
        }
        .wicket-sync-fields {
            margin-top: 10px;
            padding: 10px;
            background: #f9f9f9;
            border-left: 4px solid #00a32a;
        }
        </style>
        <?php
    }
    
    /**
     * Enqueue admin scripts for the sync functionality
     */
    public function enqueue_admin_scripts($hook) {
        // Only load on user profile pages
        if (!in_array($hook, array('profile.php', 'user-edit.php'))) {
            return;
        }
        
        wp_enqueue_script('jquery');
        
        // Create a unique script handle and localize it
        wp_register_script('wicket-sync-ajax', '', array('jquery'), '1.0', true);
        wp_enqueue_script('wicket-sync-ajax');
        
        // Localize script with AJAX URL and nonce
        wp_localize_script('wicket-sync-ajax', 'wicket_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('sync_wicket_data'),
            'syncing_text' => __('Syncing...', 'wicket-acf-sync'),
            'sync_button_text' => __('Sync Wicket Data', 'wicket-acf-sync'),
            'updated_fields_text' => __('Updated fields:', 'wicket-acf-sync'),
            'error_message' => __('An error occurred during sync. Please try again.', 'wicket-acf-sync')
        ));
        
        // Inline script for AJAX handling
        $script = "
        jQuery(document).ready(function($) {
            console.log('Wicket sync script loaded');
            console.log('AJAX URL:', wicket_ajax.ajax_url);
            
            $('#sync-wicket-btn').on('click', function(e) {
                e.preventDefault();
                console.log('Sync button clicked');
                
                var \$btn = \$(this);
                var \$spinner = \$btn.find('.spinner');
                var \$text = \$btn.find('.sync-text');
                var \$results = \$('#wicket-sync-results');
                var userId = \$btn.data('user-id');
                
                console.log('User ID:', userId);
                
                // Disable button and show spinner
                \$btn.prop('disabled', true);
                \$spinner.show();
                \$text.text(wicket_ajax.syncing_text);
                \$results.empty();
                
                var ajaxData = {
                    action: 'sync_wicket_data',
                    user_id: userId,
                    nonce: wicket_ajax.nonce
                };
                
                console.log('AJAX data:', ajaxData);
                
                $.ajax({
                    url: wicket_ajax.ajax_url,
                    type: 'POST',
                    data: ajaxData,
                    beforeSend: function() {
                        console.log('AJAX request starting...');
                    },
                    success: function(response) {
                        console.log('AJAX response:', response);
                        
                        if (response.success) {
                            \$results.html('<div class=\"wicket-sync-success\">✓ ' + response.data.message + '</div>');
                            
                            if (response.data.saved_fields && response.data.saved_fields.length > 0) {
                                var fieldsHtml = '<div class=\"wicket-sync-fields\">' +
                                    '<strong>' + wicket_ajax.updated_fields_text + '</strong><br>' +
                                    response.data.saved_fields.join(', ') + '</div>';
                                \$results.append(fieldsHtml);
                            }
                            
                            // Update last sync time display
                            setTimeout(function() {
                                location.reload();
                            }, 3000);
                            
                        } else {
                            var errorMsg = response.data && response.data.message ? response.data.message : 'Unknown error occurred';
                            \$results.html('<div class=\"wicket-sync-error\">✗ ' + errorMsg + '</div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('AJAX error:', xhr, status, error);
                        console.log('Response text:', xhr.responseText);
                        \$results.html('<div class=\"wicket-sync-error\">✗ ' + wicket_ajax.error_message + '</div>');
                    },
                    complete: function() {
                        console.log('AJAX request completed');
                        // Re-enable button and hide spinner
                        \$btn.prop('disabled', false);
                        \$spinner.hide();
                        \$text.text(wicket_ajax.sync_button_text);
                    }
                });
            });
        });
        ";
        
        wp_add_inline_script('wicket-sync-ajax', $script);
    }
    
    /**
     * Handle AJAX request for syncing Wicket data
     */
    public function handle_sync_ajax_request() {
        // Add debug logging
        error_log('Wicket AJAX handler called');
        error_log('POST data: ' . print_r($_POST, true));
        
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sync_wicket_data')) {
            error_log('Nonce verification failed');
            wp_send_json_error(array('message' => __('Security check failed.', 'wicket-acf-sync')));
            return;
        }
        
        if (!isset($_POST['user_id'])) {
            error_log('User ID missing from request');
            wp_send_json_error(array('message' => __('User ID is required.', 'wicket-acf-sync')));
            return;
        }
        
        $user_id = intval($_POST['user_id']);
        error_log('Processing sync for user ID: ' . $user_id);
        
        // Check permissions
        if (!current_user_can('edit_user', $user_id)) {
            error_log('Permission denied for user: ' . get_current_user_id());
            wp_send_json_error(array('message' => __('Permission denied.', 'wicket-acf-sync')));
            return;
        }
        
        // Get user data
        $user = get_userdata($user_id);
        if (!$user) {
            error_log('User not found: ' . $user_id);
            wp_send_json_error(array('message' => __('User not found.', 'wicket-acf-sync')));
            return;
        }
        
        // Get Wicket configuration
        $tenant = get_option('wicket_tenant_name');
        $api_secret_key = get_option('wicket_api_secret_key');
        $admin_user_uuid = get_option('wicket_admin_user_uuid');
        
        error_log('Wicket config - Tenant: ' . $tenant . ', Secret: ' . (!empty($api_secret_key) ? 'SET' : 'EMPTY') . ', UUID: ' . $admin_user_uuid);
        
        if (empty($tenant) || empty($api_secret_key) || empty($admin_user_uuid)) {
            error_log('Wicket configuration incomplete');
            wp_send_json_error(array('message' => __('Wicket API configuration is incomplete.', 'wicket-acf-sync')));
            return;
        }
        
        // Attempt to sync by email first
        error_log('Attempting sync by email: ' . $user->user_email);
        $result = $this->sync_wicket_person_by_email($user->user_email, $user_id, $tenant, $api_secret_key, $admin_user_uuid);
        
        // If email sync fails, try by Wicket UUID if it exists
        if (is_wp_error($result)) {
            error_log('Email sync failed: ' . $result->get_error_message());
            $wicket_uuid = get_user_meta($user_id, 'wicket_uuid', true);
            if (!empty($wicket_uuid)) {
                error_log('Attempting sync by UUID: ' . $wicket_uuid);
                $result = $this->sync_wicket_person_to_acf($wicket_uuid, $user_id, $tenant, $api_secret_key, $admin_user_uuid);
            }
        }
        
        if (is_wp_error($result)) {
            error_log('Final sync result failed: ' . $result->get_error_message());
            wp_send_json_error(array('message' => $result->get_error_message()));
        } else {
            // Update last sync timestamp
            update_user_meta($user_id, 'wicket_last_sync', current_time('mysql'));
            error_log('Sync successful for user: ' . $user_id);
            
            wp_send_json_success(array(
                'message' => $result['message'],
                'saved_fields' => $result['saved_fields']
            ));
        }
    }
    
    /**
     * Check if Wicket configuration is complete
     */
    private function check_wicket_configuration() {
        $tenant = get_option('wicket_tenant_name');
        $api_secret_key = get_option('wicket_api_secret_key');
        $admin_user_uuid = get_option('wicket_admin_user_uuid');
        
        if (empty($tenant)) {
            return array('configured' => false, 'message' => 'Tenant name is missing.');
        }
        
        if (empty($api_secret_key)) {
            return array('configured' => false, 'message' => 'API secret key is missing.');
        }
        
        if (empty($admin_user_uuid)) {
            return array('configured' => false, 'message' => 'Admin user UUID is missing.');
        }
        
        return array('configured' => true, 'message' => 'Configuration is complete.');
    }
    
    /**
     * Retrieve person details from Wicket API and save to ACF user fields
     */
    public function sync_wicket_person_to_acf($person_uuid, $user_id, $tenant, $api_secret_key, $admin_user_uuid) {
        // Validate inputs
        if (empty($person_uuid) || empty($user_id) || empty($tenant) || empty($api_secret_key) || empty($admin_user_uuid)) {
            return new WP_Error('missing_params', 'All parameters are required');
        }

        // Check if user exists
        if (!get_userdata($user_id)) {
            return new WP_Error('user_not_found', 'WordPress user not found');
        }

        // Generate JWT token for Wicket API authentication
        $jwt_token = $this->generate_wicket_jwt_token($api_secret_key, $admin_user_uuid, $tenant);
        if (is_wp_error($jwt_token)) {
            return $jwt_token;
        }

        // Fetch person data from Wicket API (with includes)
        $api_response = $this->fetch_wicket_person_with_includes($person_uuid, $tenant, $jwt_token);
        if (is_wp_error($api_response)) {
            return $api_response;
        }

        // Map Wicket fields to user meta and save
        $saved_fields = $this->save_wicket_data_to_user_meta($api_response['data'], $user_id, $api_response['included']);
        
        // Check if saving failed
        if (is_wp_error($saved_fields)) {
            return $saved_fields;
        }
        
        return array(
            'success' => true,
            'person_uuid' => $person_uuid,
            'user_id' => $user_id,
            'saved_fields' => $saved_fields,
            'message' => 'Person data successfully synced'
        );
    }

    /**
     * Generate JWT token for Wicket API authentication
     */
    private function generate_wicket_jwt_token($secret_key, $admin_user_uuid, $tenant) {
        // JWT header
        $is_staging = get_option('wicket_staging', 0);

        // Determine the API URL based on staging setting
        $api_url = $is_staging 
            ? "https://{$tenant}-api.staging.wicketcloud.com" 
            : "https://{$tenant}-api.wicketcloud.com";
        
        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        
        // JWT payload with required claims
        $payload = json_encode([
            'exp' => time() + 3600, // Token expires in 1 hour
            'sub' => $admin_user_uuid, // Admin user UUID
            'aud' => $api_url, // API URL (staging or production)
            'iss' => get_site_url() // Optional issuer domain
        ]);
        
        // Base64 encode
        $base64_header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
        $base64_payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
        
        // Create signature
        $signature = hash_hmac('sha256', $base64_header . "." . $base64_payload, $secret_key, true);
        $base64_signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
        
        return $base64_header . "." . $base64_payload . "." . $base64_signature;
    }

    /**
     * Fetch person data from Wicket API WITH includes for addresses and phones
     */
    private function fetch_wicket_person_with_includes($person_uuid, $tenant, $jwt_token) {
        
        $is_staging = get_option('wicket_staging', 0);

        // Determine the API URL based on staging setting - UPDATED: added includes
        $api_url = $is_staging 
            ? "https://{$tenant}-api.staging.wicketcloud.com/people/{$person_uuid}?include=addresses,phones,emails" 
            : "https://{$tenant}-api.wicketcloud.com/people/{$person_uuid}?include=addresses,phones,emails";
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        );
        
        $response = wp_remote_get($api_url, $args);
        
        if (is_wp_error($response)) {
            return new WP_Error('api_request_failed', 'Failed to connect to Wicket API: ' . $response->get_error_message());
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            return new WP_Error('api_error', "Wicket API returned error code {$response_code}: {$body}");
        }
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_decode_error', 'Failed to decode API response');
        }
        
        if (!isset($data['data'])) {
            return new WP_Error('invalid_response', 'Invalid API response format');
        }
        
        return array(
            'data' => $data['data'],
            'included' => $data['included'] ?? array()
        );
    }

    /**
     * Helper function to get primary item from included data
     */
    private function get_primary_from_included($included_data, $type) {
        if (empty($included_data)) {
            return null;
        }
        
        // First try to find a primary item
        foreach ($included_data as $item) {
            if ($item['type'] === $type && !empty($item['attributes']['primary'])) {
                return $item;
            }
        }
        
        // If no primary found, return the first item of the type
        foreach ($included_data as $item) {
            if ($item['type'] === $type) {
                return $item;
            }
        }
        
        return null;
    }

    /**
     * Save Wicket person data to WordPress user meta
     * UPDATED: Uses unified field mapping with wicket_ prefix
     */
    private function save_wicket_data_to_user_meta($person_data, $user_id, $included_data = array()) {
        $attributes = $person_data['attributes'] ?? array();
        $saved_fields = array();
        
        // =============================================================
        // UNIFIED FIELD MAPPING - All fields use wicket_ prefix
        // This matches the WicketLoginSync class mapping
        // =============================================================
        $field_mapping = array(
            // Core identity fields
            'uuid' => 'wicket_uuid',
            'given_name' => 'wicket_given_name',
            'family_name' => 'wicket_family_name',
            'full_name' => 'wicket_full_name',
            'additional_name' => 'wicket_additional_name',
            'alternate_name' => 'wicket_alternate_name',
            'nickname' => 'wicket_nickname',
            
            // Professional fields
            'job_title' => 'wicket_job_title',
            'job_level' => 'wicket_job_level',
            'job_function' => 'wicket_job_function',
            
            // Personal fields
            'gender' => 'wicket_gender',
            'birth_date' => 'wicket_birth_date',
            'preferred_pronoun' => 'wicket_preferred_pronoun',
            'honorific_prefix' => 'wicket_honorific_prefix',
            'honorific_suffix' => 'wicket_honorific_suffix',
            
            // Membership fields
            'membership_number' => 'wicket_membership_number',
            'membership_began_on' => 'wicket_membership_began_on',
            'identifying_number' => 'wicket_identifying_number',
            
            // Status fields
            'created_at' => 'wicket_created_at',
            'updated_at' => 'wicket_updated_at',
            'role_names' => 'wicket_roles',
            'tags' => 'wicket_tags',
            'language' => 'wicket_language',
            
            // Email
            'primary_email_address' => 'wicket_primary_email',
        );
        
        // Update WordPress core fields (first_name, last_name)
        $wp_user_data = array('ID' => $user_id);
        
        if (isset($attributes['given_name']) && !empty($attributes['given_name'])) {
            $wp_user_data['first_name'] = sanitize_text_field($attributes['given_name']);
        }
        
        if (isset($attributes['family_name']) && !empty($attributes['family_name'])) {
            $wp_user_data['last_name'] = sanitize_text_field($attributes['family_name']);
        }
        
        if (count($wp_user_data) > 1) {
            wp_update_user($wp_user_data);
            $saved_fields[] = 'core_wp_fields';
        }
        
        // Save all mapped fields to user meta
        foreach ($field_mapping as $wicket_field => $meta_key) {
            if (isset($attributes[$wicket_field])) {
                $value = $attributes[$wicket_field];
                
                // Handle array fields
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                
                // Sanitize and save
                $value = is_string($value) ? sanitize_text_field($value) : $value;
                update_user_meta($user_id, $meta_key, $value);
                $saved_fields[] = $meta_key;
            }
        }
        
        // =============================================================
        // SYNC PRIMARY ADDRESS from included data
        // =============================================================
        $primary_address = $this->get_primary_from_included($included_data, 'addresses');
        if ($primary_address && isset($primary_address['attributes'])) {
            $addr_attrs = $primary_address['attributes'];
            
            $address_field_mapping = array(
                'address1' => 'wicket_address1',
                'address2' => 'wicket_address2',
                'city' => 'wicket_city',
                'state_name' => 'wicket_state',
                'zip_code' => 'wicket_zip_code',
                'country_code' => 'wicket_country_code',
                'country_name' => 'wicket_country_name',
                'company_name' => 'wicket_company_name',
                'department' => 'wicket_department',
                'division' => 'wicket_division',
                'formatted_address_label' => 'wicket_formatted_address',
                'latitude' => 'wicket_latitude',
                'longitude' => 'wicket_longitude',
            );
            
            foreach ($address_field_mapping as $wicket_key => $meta_key) {
                if (isset($addr_attrs[$wicket_key]) && $addr_attrs[$wicket_key] !== null) {
                    $value = $addr_attrs[$wicket_key];
                    // For formatted_address_label, use textarea sanitization to preserve newlines
                    if ($wicket_key === 'formatted_address_label') {
                        $value = sanitize_textarea_field($value);
                    } else {
                        $value = is_string($value) ? sanitize_text_field($value) : $value;
                    }
                    update_user_meta($user_id, $meta_key, $value);
                    $saved_fields[] = $meta_key;
                }
            }
            
            // Store address UUID for reference
            if (isset($addr_attrs['uuid'])) {
                update_user_meta($user_id, 'wicket_address_uuid', sanitize_text_field($addr_attrs['uuid']));
            }
        }
        
        // =============================================================
        // SYNC PRIMARY PHONE from included data
        // =============================================================
        $primary_phone = $this->get_primary_from_included($included_data, 'phones');
        if ($primary_phone && isset($primary_phone['attributes'])) {
            $phone_attrs = $primary_phone['attributes'];
            
            $phone_field_mapping = array(
                'number' => 'wicket_phone',
                'number_national_format' => 'wicket_phone_national',
                'number_international_format' => 'wicket_phone_international',
                'type' => 'wicket_phone_type',
                'extension' => 'wicket_phone_extension',
                'country_code_number' => 'wicket_phone_country_code',
            );
            
            foreach ($phone_field_mapping as $wicket_key => $meta_key) {
                if (isset($phone_attrs[$wicket_key]) && $phone_attrs[$wicket_key] !== null) {
                    $value = is_string($phone_attrs[$wicket_key]) 
                        ? sanitize_text_field($phone_attrs[$wicket_key]) 
                        : $phone_attrs[$wicket_key];
                    update_user_meta($user_id, $meta_key, $value);
                    $saved_fields[] = $meta_key;
                }
            }
            
            // Store phone UUID for reference
            if (isset($phone_attrs['uuid'])) {
                update_user_meta($user_id, 'wicket_phone_uuid', sanitize_text_field($phone_attrs['uuid']));
            }
        }
        
        // =============================================================
        // SYNC USER INFORMATION
        // =============================================================
        if (isset($attributes['user']) && is_array($attributes['user'])) {
            $user_data = $attributes['user'];
            if (isset($user_data['email'])) {
                update_user_meta($user_id, 'wicket_email', sanitize_email($user_data['email']));
                $saved_fields[] = 'wicket_email';
            }
        }
        
        // =============================================================
        // SYNC COMMUNICATION PREFERENCES
        // =============================================================
        if (isset($attributes['data']['communications'])) {
            $communications = $attributes['data']['communications'];
            
            // Save main email preference
            if (isset($communications['email'])) {
                update_user_meta($user_id, 'wicket_email_communications', $communications['email'] ? 'yes' : 'no');
                $saved_fields[] = 'wicket_email_communications';
            }
            
            // Save sublist preferences
            if (isset($communications['sublists']) && is_array($communications['sublists'])) {
                foreach ($communications['sublists'] as $sublist => $value) {
                    $field_name = "wicket_sublist_{$sublist}";
                    update_user_meta($user_id, $field_name, $value ? 'yes' : 'no');
                    $saved_fields[] = $field_name;
                }
            }
        }
        
        // Store sync metadata
        update_user_meta($user_id, 'wicket_sync_count', (int)get_user_meta($user_id, 'wicket_sync_count', true) + 1);
        
        return $saved_fields;
    }

    /**
     * Helper function to sync person by email address
     */
    public function sync_wicket_person_by_email($email, $user_id, $tenant, $api_secret_key, $admin_user_uuid) {
        // Generate JWT token
        $jwt_token = $this->generate_wicket_jwt_token($api_secret_key, $admin_user_uuid, $tenant);
        if (is_wp_error($jwt_token)) {
            return $jwt_token;
        }

        // JWT header
        $is_staging = get_option('wicket_staging', 0);

        // Determine the API URL based on staging setting - UPDATED: added includes
        $api_url = $is_staging 
            ? "https://{$tenant}-api.staging.wicketcloud.com/people?filter[emails_address_eq]=" . urlencode($email) . "&include=addresses,phones,emails"
            : "https://{$tenant}-api.wicketcloud.com/people?filter[emails_address_eq]=" . urlencode($email) . "&include=addresses,phones,emails";
        
        $args = array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $jwt_token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        );
        
        $response = wp_remote_get($api_url, $args);
        
        if (is_wp_error($response)) {
            return new WP_Error('api_request_failed', 'Failed to search for person by email');
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            return new WP_Error('api_error', "Wicket API returned error code {$response_code}");
        }
        
        $data = json_decode($body, true);
        
        if (!isset($data['data']) || empty($data['data'])) {
            return new WP_Error('person_not_found', 'No person found with the provided email address');
        }
        
        // Get the first person found
        $person = $data['data'][0];
        $person_uuid = $person['attributes']['uuid'];
        $included = $data['included'] ?? array();
        
        // Save the person data with included data for addresses/phones
        $saved_fields = $this->save_wicket_data_to_user_meta($person, $user_id, $included);
        
        if (is_wp_error($saved_fields)) {
            return $saved_fields;
        }
        
        return array(
            'success' => true,
            'person_uuid' => $person_uuid,
            'user_id' => $user_id,
            'saved_fields' => $saved_fields,
            'message' => 'Person data successfully synced'
        );
    }
}

// Initialize the class and store global reference
$GLOBALS['wicket_acf_sync_instance'] = new WicketACFSync();