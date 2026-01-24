<?php
/**
 * Unified Wicket Settings Page with Tabs
 *
 * Provides a single settings page with tabs for all Wicket integration configuration.
 *
 * @package MyIES_Integration
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add settings page to admin menu
 */
function wicket_acf_sync_settings_page() {
    add_options_page(
        __('Wicket Integration Settings', 'wicket-integration'),
        __('Wicket Integration', 'wicket-integration'),
        'manage_options',
        'wicket-acf-sync',
        'wicket_acf_sync_settings_callback'
    );
}
add_action('admin_menu', 'wicket_acf_sync_settings_page');

/**
 * Main settings page callback with tabbed interface
 */
function wicket_acf_sync_settings_callback() {
    // Handle form submissions with nonce verification
    if (isset($_POST['wicket_settings_submit']) && current_user_can('manage_options')) {
        if (!isset($_POST['wicket_settings_nonce']) || !wp_verify_nonce($_POST['wicket_settings_nonce'], 'wicket_save_settings')) {
            wp_die(__('Security check failed. Please try again.', 'wicket-integration'));
        }

        // Save API settings
        if (isset($_POST['wicket_tenant_name'])) {
            update_option('wicket_tenant_name', sanitize_text_field($_POST['wicket_tenant_name']));
        }
        if (isset($_POST['wicket_api_secret_key'])) {
            update_option('wicket_api_secret_key', sanitize_text_field($_POST['wicket_api_secret_key']));
        }
        if (isset($_POST['wicket_admin_user_uuid'])) {
            update_option('wicket_admin_user_uuid', sanitize_text_field($_POST['wicket_admin_user_uuid']));
        }
        update_option('wicket_staging', isset($_POST['wicket_staging']) ? 1 : 0);

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Settings saved!', 'wicket-integration') . '</p></div>';
    }

    // Handle sync settings form
    if (isset($_POST['wicket_sync_settings_submit']) && current_user_can('manage_options')) {
        if (!isset($_POST['wicket_sync_nonce']) || !wp_verify_nonce($_POST['wicket_sync_nonce'], 'wicket_save_sync_settings')) {
            wp_die(__('Security check failed. Please try again.', 'wicket-integration'));
        }

        update_option('wicket_login_sync_enabled', isset($_POST['wicket_login_sync_enabled']) ? 1 : 0);
        update_option('wicket_login_sync_interval', absint($_POST['wicket_login_sync_interval']));
        update_option('wicket_login_sync_core_fields', isset($_POST['wicket_login_sync_core_fields']) ? 1 : 0);
        update_option('wicket_login_sync_communications', isset($_POST['wicket_login_sync_communications']) ? 1 : 0);
        update_option('wicket_registration_sync_enabled', isset($_POST['wicket_registration_sync_enabled']) ? 1 : 0);

        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Sync settings saved!', 'wicket-integration') . '</p></div>';
    }

    // Get current tab
    $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'api';

    // Get saved values
    $tenant = get_option('wicket_tenant_name', '');
    $api_secret = get_option('wicket_api_secret_key', '');
    $admin_uuid = get_option('wicket_admin_user_uuid', '');
    $staging = get_option('wicket_staging', 0);
    ?>

    <div class="wrap">
        <h1><?php esc_html_e('Wicket Integration Settings', 'wicket-integration'); ?></h1>

        <h2 class="nav-tab-wrapper">
            <a href="<?php echo esc_url(admin_url('options-general.php?page=wicket-acf-sync&tab=api')); ?>"
               class="nav-tab <?php echo $current_tab === 'api' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('API Configuration', 'wicket-integration'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=wicket-acf-sync&tab=sync')); ?>"
               class="nav-tab <?php echo $current_tab === 'sync' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('Sync Settings', 'wicket-integration'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=wicket-acf-sync&tab=bulk')); ?>"
               class="nav-tab <?php echo $current_tab === 'bulk' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('Bulk Sync', 'wicket-integration'); ?>
            </a>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=wicket-acf-sync&tab=updates')); ?>"
               class="nav-tab <?php echo $current_tab === 'updates' ? 'nav-tab-active' : ''; ?>">
                <?php esc_html_e('Updates', 'wicket-integration'); ?>
            </a>
        </h2>

        <?php
        switch ($current_tab) {
            case 'sync':
                wicket_render_sync_settings_tab();
                break;
            case 'bulk':
                wicket_render_bulk_sync_tab();
                break;
            case 'updates':
                wicket_render_updates_tab();
                break;
            case 'api':
            default:
                wicket_render_api_settings_tab($tenant, $api_secret, $admin_uuid, $staging);
                break;
        }
        ?>
    </div>
    <?php
}

/**
 * Render API Configuration tab
 */
function wicket_render_api_settings_tab($tenant, $api_secret, $admin_uuid, $staging) {
    ?>
    <form method="post" action="">
        <?php wp_nonce_field('wicket_save_settings', 'wicket_settings_nonce'); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Tenant Name', 'wicket-integration'); ?></th>
                <td>
                    <input type="text" name="wicket_tenant_name" value="<?php echo esc_attr($tenant); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Your Wicket tenant name (from your API URL: https://TENANT-api.wicketcloud.com)', 'wicket-integration'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('API Secret Key', 'wicket-integration'); ?></th>
                <td>
                    <input type="password" name="wicket_api_secret_key" value="<?php echo esc_attr($api_secret); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Your Wicket API secret key', 'wicket-integration'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Admin User UUID', 'wicket-integration'); ?></th>
                <td>
                    <input type="text" name="wicket_admin_user_uuid" value="<?php echo esc_attr($admin_uuid); ?>" class="regular-text" />
                    <p class="description"><?php esc_html_e('Your Wicket admin user UUID', 'wicket-integration'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Environment', 'wicket-integration'); ?></th>
                <td>
                    <label for="wicket_staging">
                        <input type="checkbox" id="wicket_staging" name="wicket_staging" value="1" <?php checked(1, $staging); ?> />
                        <?php esc_html_e('Use Staging Environment', 'wicket-integration'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Check this box if using the staging environment', 'wicket-integration'); ?></p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" name="wicket_settings_submit" class="button-primary" value="<?php esc_attr_e('Save Settings', 'wicket-integration'); ?>" />
        </p>
    </form>

    <?php wicket_render_connection_test(); ?>
    <?php
}

/**
 * Render Sync Settings tab
 */
function wicket_render_sync_settings_tab() {
    $enabled = get_option('wicket_login_sync_enabled', 1);
    $interval = get_option('wicket_login_sync_interval', 3600);
    $core_fields = get_option('wicket_login_sync_core_fields', 1);
    $communications = get_option('wicket_login_sync_communications', 1);
    $registration_sync = get_option('wicket_registration_sync_enabled', 1);
    ?>
    <form method="post" action="">
        <?php wp_nonce_field('wicket_save_sync_settings', 'wicket_sync_nonce'); ?>

        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e('Enable Login Sync', 'wicket-integration'); ?></th>
                <td>
                    <input type="checkbox" name="wicket_login_sync_enabled" value="1" <?php checked($enabled); ?> />
                    <p class="description"><?php esc_html_e('Enable automatic user sync on login', 'wicket-integration'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Enable Registration Sync', 'wicket-integration'); ?></th>
                <td>
                    <input type="checkbox" name="wicket_registration_sync_enabled" value="1" <?php checked($registration_sync); ?> />
                    <p class="description"><?php esc_html_e('Enable automatic user sync after registration (including auto-login)', 'wicket-integration'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Sync Interval (seconds)', 'wicket-integration'); ?></th>
                <td>
                    <input type="number" name="wicket_login_sync_interval" value="<?php echo esc_attr($interval); ?>" min="300" class="regular-text" />
                    <p class="description"><?php esc_html_e('Minimum time between syncs for the same user (default: 3600 = 1 hour)', 'wicket-integration'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Sync Core Fields', 'wicket-integration'); ?></th>
                <td>
                    <input type="checkbox" name="wicket_login_sync_core_fields" value="1" <?php checked($core_fields); ?> />
                    <p class="description"><?php esc_html_e('Update WordPress first_name and last_name fields', 'wicket-integration'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Sync Communications', 'wicket-integration'); ?></th>
                <td>
                    <input type="checkbox" name="wicket_login_sync_communications" value="1" <?php checked($communications); ?> />
                    <p class="description"><?php esc_html_e('Sync communication preferences and sublists', 'wicket-integration'); ?></p>
                </td>
            </tr>
        </table>

        <p class="submit">
            <input type="submit" name="wicket_sync_settings_submit" class="button-primary" value="<?php esc_attr_e('Save Sync Settings', 'wicket-integration'); ?>" />
        </p>
    </form>

    <hr />

    <h2><?php esc_html_e('Synced Fields Reference', 'wicket-integration'); ?></h2>
    <p><?php esc_html_e('The following user meta fields are synced from Wicket:', 'wicket-integration'); ?></p>
    <?php wicket_render_fields_reference_table(); ?>
    <?php
}

/**
 * Render Bulk Sync tab
 */
function wicket_render_bulk_sync_tab() {
    // This triggers the bulk sync section from WicketBulkSync class
    do_action('wicket_acf_sync_settings_after_form');
}

/**
 * Render Updates tab
 */
function wicket_render_updates_tab() {
    // This is handled by the GitHub updater class hook
    $github_repo = get_option('myies_github_repo', 'Illuminating-Engineering-Society/MyIES');

    if (isset($_POST['myies_github_repo']) && current_user_can('manage_options')) {
        if (isset($_POST['github_nonce']) && wp_verify_nonce($_POST['github_nonce'], 'myies_save_github')) {
            $new_repo = sanitize_text_field($_POST['myies_github_repo']);
            if (!empty($new_repo) && strpos($new_repo, '/') !== false) {
                update_option('myies_github_repo', $new_repo);
                $github_repo = $new_repo;
                delete_transient('myies_github_response');
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('GitHub settings saved!', 'wicket-integration') . '</p></div>';
            }
        }
    }
    ?>
    <div class="card" style="max-width: 800px; margin-top: 20px;">
        <h2><?php esc_html_e('Plugin Updates from GitHub', 'wicket-integration'); ?></h2>
        <p><?php esc_html_e('This plugin checks for updates from a GitHub repository.', 'wicket-integration'); ?></p>

        <form method="post" action="">
            <?php wp_nonce_field('myies_save_github', 'github_nonce'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><?php esc_html_e('GitHub Repository', 'wicket-integration'); ?></th>
                    <td>
                        <input type="text" name="myies_github_repo" value="<?php echo esc_attr($github_repo); ?>" class="regular-text" placeholder="owner/repository" />
                        <p class="description"><?php esc_html_e('Format: owner/repository (e.g., Illuminating-Engineering-Society/MyIES)', 'wicket-integration'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Current Version', 'wicket-integration'); ?></th>
                    <td><code><?php echo esc_html(WICKET_INTEGRATION_VERSION); ?></code></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Private Repository', 'wicket-integration'); ?></th>
                    <td>
                        <?php if (defined('MYIES_GITHUB_TOKEN') && !empty(MYIES_GITHUB_TOKEN)): ?>
                            <span style="color: green;">&#10003; <?php esc_html_e('GitHub token configured', 'wicket-integration'); ?></span>
                        <?php else: ?>
                            <span style="color: gray;"><?php esc_html_e('No token configured (public repo)', 'wicket-integration'); ?></span>
                            <p class="description">
                                <?php esc_html_e('For private repos, add to wp-config.php:', 'wicket-integration'); ?><br>
                                <code>define('MYIES_GITHUB_TOKEN', 'your-token');</code>
                            </p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>

            <?php submit_button(__('Save Repository', 'wicket-integration'), 'secondary'); ?>
        </form>

        <h3><?php esc_html_e('Creating Releases', 'wicket-integration'); ?></h3>
        <ol>
            <li><?php esc_html_e('Update version number in myies-integration.php header', 'wicket-integration'); ?></li>
            <li><?php esc_html_e('Commit and push changes to GitHub', 'wicket-integration'); ?></li>
            <li><?php esc_html_e('Create a new GitHub Release with matching tag (e.g., v1.0.1)', 'wicket-integration'); ?></li>
            <li><?php esc_html_e('WordPress will detect the new version within 12 hours', 'wicket-integration'); ?></li>
        </ol>
    </div>
    <?php
}

/**
 * Render connection test section
 */
function wicket_render_connection_test() {
    $tenant = get_option('wicket_tenant_name', '');
    $api_secret = get_option('wicket_api_secret_key', '');
    $admin_uuid = get_option('wicket_admin_user_uuid', '');
    $staging = get_option('wicket_staging', 0);

    $credentials_configured = !empty($tenant) && !empty($api_secret) && !empty($admin_uuid);

    // Handle connection test
    if (isset($_POST['test_wicket_connection']) && current_user_can('manage_options')) {
        if (wp_verify_nonce($_POST['test_connection_nonce'], 'test_wicket_connection')) {
            $api_url = $staging
                ? "https://{$tenant}-api.staging.wicketcloud.com"
                : "https://{$tenant}-api.wicketcloud.com";

            // Generate JWT token
            $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
            $payload = json_encode([
                'exp' => time() + 3600,
                'sub' => $admin_uuid,
                'aud' => $api_url,
                'iss' => get_site_url()
            ]);

            $base64Header = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
            $base64Payload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));
            $signature = hash_hmac('sha256', $base64Header . "." . $base64Payload, $api_secret, true);
            $base64Signature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
            $token = $base64Header . "." . $base64Payload . "." . $base64Signature;

            $response = wp_remote_get($api_url . '/memberships?page[size]=50', array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ),
                'timeout' => 30
            ));

            if (is_wp_error($response)) {
                echo '<div class="notice notice-error"><p><strong>' . esc_html__('Connection Failed:', 'wicket-integration') . '</strong> ' . esc_html($response->get_error_message()) . '</p></div>';
            } else {
                $status_code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);

                if ($status_code >= 400) {
                    $error_msg = isset($body['message']) ? $body['message'] : "API returned status {$status_code}";
                    echo '<div class="notice notice-error"><p><strong>' . esc_html__('Connection Failed:', 'wicket-integration') . '</strong> ' . esc_html($error_msg) . '</p></div>';
                } else {
                    echo '<div class="notice notice-success"><p><strong>' . esc_html__('Connection Successful!', 'wicket-integration') . '</strong> ' . esc_html__('API is responding correctly.', 'wicket-integration') . '</p></div>';

                    if (!empty($body['data'])) {
                        echo '<h3>' . esc_html__('Available Wicket Membership Tiers', 'wicket-integration') . '</h3>';
                        echo '<table class="widefat striped" style="margin-top: 10px;">';
                        echo '<thead><tr><th>' . esc_html__('Name', 'wicket-integration') . '</th><th>' . esc_html__('UUID', 'wicket-integration') . '</th><th>' . esc_html__('Type', 'wicket-integration') . '</th><th>' . esc_html__('Status', 'wicket-integration') . '</th></tr></thead>';
                        echo '<tbody>';

                        foreach ($body['data'] as $membership) {
                            $name = isset($membership['attributes']['name']) ? $membership['attributes']['name'] : 'N/A';
                            $uuid = isset($membership['id']) ? $membership['id'] : 'N/A';
                            $type = isset($membership['attributes']['type']) ? $membership['attributes']['type'] : 'N/A';
                            $active = !empty($membership['attributes']['active']);

                            echo '<tr>';
                            echo '<td><strong>' . esc_html($name) . '</strong></td>';
                            echo '<td><code>' . esc_html($uuid) . '</code></td>';
                            echo '<td>' . esc_html(ucfirst($type)) . '</td>';
                            echo '<td>' . ($active ? '<span style="color: green;">✓ Active</span>' : '<span style="color: gray;">Inactive</span>') . '</td>';
                            echo '</tr>';
                        }

                        echo '</tbody></table>';
                    }
                }
            }
        }
    }

    ?>
    <hr style="margin: 30px 0;" />
    <h2><?php esc_html_e('Test Wicket API Connection', 'wicket-integration'); ?></h2>

    <?php if ($credentials_configured): ?>
        <p><?php esc_html_e('Click the button below to test your Wicket API connection.', 'wicket-integration'); ?></p>
        <form method="post" action="">
            <?php wp_nonce_field('test_wicket_connection', 'test_connection_nonce'); ?>
            <p>
                <input type="submit" name="test_wicket_connection" class="button-secondary" value="<?php esc_attr_e('Test Connection & Show Memberships', 'wicket-integration'); ?>" />
            </p>
        </form>
    <?php else: ?>
        <p><em><?php esc_html_e('Save your Wicket API credentials above to enable connection testing.', 'wicket-integration'); ?></em></p>
    <?php endif;
}

/**
 * Render fields reference table
 */
function wicket_render_fields_reference_table() {
    ?>
    <table class="widefat" style="max-width: 800px;">
        <thead>
            <tr>
                <th><?php esc_html_e('Category', 'wicket-integration'); ?></th>
                <th><?php esc_html_e('Wicket Field', 'wicket-integration'); ?></th>
                <th><?php esc_html_e('WordPress Meta Key', 'wicket-integration'); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr><td rowspan="3"><strong><?php esc_html_e('Identity', 'wicket-integration'); ?></strong></td><td>uuid</td><td><code>wicket_person_uuid</code></td></tr>
            <tr><td>full_name</td><td><code>wicket_full_name</code></td></tr>
            <tr><td>alternate_name</td><td><code>wicket_alternate_name</code></td></tr>

            <tr><td rowspan="3"><strong><?php esc_html_e('Professional', 'wicket-integration'); ?></strong></td><td>job_title</td><td><code>wicket_job_title</code></td></tr>
            <tr><td>job_level</td><td><code>wicket_job_level</code></td></tr>
            <tr><td>job_function</td><td><code>wicket_job_function</code></td></tr>

            <tr><td rowspan="4"><strong><?php esc_html_e('Personal', 'wicket-integration'); ?></strong></td><td>gender</td><td><code>wicket_gender</code></td></tr>
            <tr><td>birth_date</td><td><code>wicket_birth_date</code></td></tr>
            <tr><td>preferred_pronoun</td><td><code>wicket_preferred_pronoun</code></td></tr>
            <tr><td>honorific_prefix</td><td><code>wicket_honorific_prefix</code></td></tr>

            <tr><td rowspan="3"><strong><?php esc_html_e('Phone', 'wicket-integration'); ?></strong></td><td>number</td><td><code>wicket_phone</code></td></tr>
            <tr><td>number_international_format</td><td><code>wicket_phone_international</code></td></tr>
            <tr><td>type</td><td><code>wicket_phone_type</code></td></tr>

            <tr><td rowspan="5"><strong><?php esc_html_e('Address', 'wicket-integration'); ?></strong></td><td>address1</td><td><code>wicket_address1</code></td></tr>
            <tr><td>city</td><td><code>wicket_city</code></td></tr>
            <tr><td>state_name</td><td><code>wicket_state</code></td></tr>
            <tr><td>zip_code</td><td><code>wicket_zip_code</code></td></tr>
            <tr><td>country_name</td><td><code>wicket_country_name</code></td></tr>

            <tr><td rowspan="3"><strong><?php esc_html_e('Organization', 'wicket-integration'); ?></strong></td><td>org_uuid</td><td><code>wicket_org_uuid</code></td></tr>
            <tr><td>legal_name</td><td><code>wicket_org_name</code></td></tr>
            <tr><td>type</td><td><code>wicket_org_type</code></td></tr>
        </tbody>
    </table>
    <?php
}
