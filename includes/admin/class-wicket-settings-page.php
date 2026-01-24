<?php
/**
 * Optional: Add settings page for Wicket configuration
 */
function wicket_acf_sync_settings_page() {
    add_options_page(
        'Wicket ACF Sync Settings',
        'Wicket ACF Sync',
        'manage_options',
        'wicket-acf-sync',
        'wicket_acf_sync_settings_callback'
    );
}
add_action('admin_menu', 'wicket_acf_sync_settings_page');

function wicket_acf_sync_settings_callback() {
    if (isset($_POST['submit'])) {
        update_option('wicket_tenant_name', sanitize_text_field($_POST['wicket_tenant_name']));
        update_option('wicket_api_secret_key', sanitize_text_field($_POST['wicket_api_secret_key']));
        update_option('wicket_admin_user_uuid', sanitize_text_field($_POST['wicket_admin_user_uuid']));
        update_option('wicket_staging', isset($_POST['wicket_staging']) ? 1 : 0);
        echo '<div class="notice notice-success"><p>Settings saved!</p></div>';
    }
    
    $tenant = get_option('wicket_tenant_name', '');
    $api_secret = get_option('wicket_api_secret_key', '');
    $admin_uuid = get_option('wicket_admin_user_uuid', '');
    $staging = get_option('wicket_staging', 0);
    ?>
    
    <div class="wrap">
        <h1>Wicket ACF Sync Settings</h1>
        <form method="post" action="">
            <table class="form-table">
                <tr>
                    <th scope="row">Tenant Name</th>
                    <td>
                        <input type="text" name="wicket_tenant_name" value="<?php echo esc_attr($tenant); ?>" class="regular-text" />
                        <p class="description">Your Wicket tenant name (from your API URL: https://TENANT-api.wicketcloud.com)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">API Secret Key</th>
                    <td>
                        <input type="password" name="wicket_api_secret_key" value="<?php echo esc_attr($api_secret); ?>" class="regular-text" />
                        <p class="description">Your Wicket API secret key</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Admin User UUID</th>
                    <td>
                        <input type="text" name="wicket_admin_user_uuid" value="<?php echo esc_attr($admin_uuid); ?>" class="regular-text" />
                        <p class="description">Your Wicket admin user UUID</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Staging</th>
                    <td>
                        <label for="wicket_staging">
                            <input type="checkbox" id="wicket_staging" name="wicket_staging" value="1" <?php checked(1, $staging); ?> />
                            Staging
                        </label>
                        <p class="description">Check this box if using staging environment</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
        
        <?php
        // Add hook for bulk sync section
        do_action('wicket_acf_sync_settings_after_form');
        ?>
    </div>
    <?php
}

/**
 * Add Wicket connection test section
 */
function wicket_add_connection_test_section() {
    $tenant = get_option('wicket_tenant_name', '');
    $api_secret = get_option('wicket_api_secret_key', '');
    $admin_uuid = get_option('wicket_admin_user_uuid', '');
    $staging = get_option('wicket_staging', 0);
    
    // Check if credentials are configured
    $credentials_configured = !empty($tenant) && !empty($api_secret) && !empty($admin_uuid);
    
    // Handle connection test
    if (isset($_POST['test_wicket_connection']) && current_user_can('manage_options')) {
        if (wp_verify_nonce($_POST['test_connection_nonce'], 'test_wicket_connection')) {
            
            if ($staging) {
                $api_url = "https://{$tenant}-api.staging.wicketcloud.com";
            } else {
                $api_url = "https://{$tenant}-api.wicketcloud.com";
            }
            
            // Generate JWT token
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
            
            $token = $base64Header . "." . $base64Payload . "." . $base64Signature;
            
            // Test API call
            $response = wp_remote_get($api_url . '/memberships?page[size]=50', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ),
                'timeout' => 30
            ));
            
            if (is_wp_error($response)) {
                echo '<div class="notice notice-error"><p><strong>Connection Failed:</strong> ' . esc_html($response->get_error_message()) . '</p></div>';
            } else {
                $status_code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);
                
                if ($status_code >= 400) {
                    $error_msg = isset($body['message']) ? $body['message'] : "API returned status {$status_code}";
                    echo '<div class="notice notice-error"><p><strong>Connection Failed:</strong> ' . esc_html($error_msg) . '</p></div>';
                } else {
                    echo '<div class="notice notice-success"><p><strong>Connection Successful!</strong> API is responding correctly.</p></div>';
                    
                    // Show available Wicket membership tiers
                    if (!empty($body['data'])) {
                        echo '<h3>Available Wicket Membership Tiers</h3>';
                        echo '<p class="description">These are the membership tiers configured in Wicket. You will need the UUIDs when configuring membership integrations.</p>';
                        echo '<table class="widefat striped" style="margin-top: 10px;">';
                        echo '<thead><tr><th>Name</th><th>UUID</th><th>Type</th><th>Status</th></tr></thead>';
                        echo '<tbody>';
                        
                        foreach ($body['data'] as $membership) {
                            $name = isset($membership['attributes']['name']) ? $membership['attributes']['name'] : 'N/A';
                            $uuid = isset($membership['id']) ? $membership['id'] : 'N/A';
                            $type = isset($membership['attributes']['type']) ? $membership['attributes']['type'] : 'N/A';
                            $active = !empty($membership['attributes']['active']) ? 'Active' : 'Inactive';
                            
                            echo '<tr>';
                            echo '<td><strong>' . esc_html($name) . '</strong></td>';
                            echo '<td><code style="background: #f1f1f1; padding: 2px 6px;">' . esc_html($uuid) . '</code></td>';
                            echo '<td>' . esc_html(ucfirst($type)) . '</td>';
                            echo '<td>' . ($active === 'Active' ? '<span style="color: green;">✓ Active</span>' : '<span style="color: gray;">Inactive</span>') . '</td>';
                            echo '</tr>';
                        }
                        
                        echo '</tbody></table>';
                    }
                }
            }
        }
    }
    
    // Only show test section if credentials are configured
    if ($credentials_configured): ?>
        <hr style="margin: 30px 0;" />
        <h2>Test Wicket API Connection</h2>
        <p>Click the button below to test your Wicket API connection and view available membership tiers.</p>
        
        <form method="post" action="">
            <?php wp_nonce_field('test_wicket_connection', 'test_connection_nonce'); ?>
            <p>
                <input type="submit" name="test_wicket_connection" class="button-secondary" value="Test Connection & Show Wicket Memberships" />
            </p>
        </form>
    <?php else: ?>
        <hr style="margin: 30px 0;" />
        <p><em>Save your Wicket API credentials above to enable connection testing.</em></p>
    <?php endif;
}
add_action('wicket_acf_sync_settings_after_form', 'wicket_add_connection_test_section');