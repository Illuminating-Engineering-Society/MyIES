<?php
/**
 * Wicket UUID Check — Per-User Profile Widget
 *
 * Adds a section to each user's profile page that compares
 * wicket_person_uuid / wicket_uuid meta against user_login.
 * If they don't match, shows the mismatch and a button to
 * repair by fetching correct data from Wicket.
 *
 * @package MyIES_Integration
 * @since 1.0.19
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_UUID_Repair {

    public function __construct() {
        add_action('show_user_profile', array($this, 'render_profile_section'), 5);
        add_action('edit_user_profile', array($this, 'render_profile_section'), 5);
        add_action('wp_ajax_wicket_uuid_repair_single', array($this, 'ajax_repair'));
        add_action('wp_ajax_wicket_uuid_clear_meta', array($this, 'ajax_clear_meta'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    private function is_uuid($str) {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $str);
    }

    // ------------------------------------------------------------------
    // Profile section
    // ------------------------------------------------------------------

    public function render_profile_section($user) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $username       = $user->user_login;
        $is_uuid_login  = $this->is_uuid($username);
        $stored_uuid    = get_user_meta($user->ID, 'wicket_person_uuid', true);
        $stored_legacy  = get_user_meta($user->ID, 'wicket_uuid', true);

        if ($is_uuid_login) {
            $correct_uuid = $username;
            $uuid_ok   = !empty($stored_uuid) && $stored_uuid === $correct_uuid;
            $legacy_ok = !empty($stored_legacy) && $stored_legacy === $correct_uuid;
            $all_ok    = $uuid_ok && $legacy_ok;
        } else {
            $uuid_ok   = false;
            $legacy_ok = false;
            $all_ok    = false;
        }
        ?>
        <h3><?php esc_html_e('Wicket UUID Check', 'wicket-integration'); ?></h3>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Username', 'wicket-integration'); ?></th>
                <td>
                    <code><?php echo esc_html($username); ?></code>
                    <?php if (!$is_uuid_login): ?>
                        <span style="color:#dba617;"> — not a UUID</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">wicket_person_uuid</th>
                <td>
                    <?php if (empty($stored_uuid)): ?>
                        <code style="color:#999;">(empty)</code>
                    <?php elseif ($is_uuid_login): ?>
                        <code style="color:<?php echo $uuid_ok ? '#00a32a' : '#d63638'; ?>;">
                            <?php echo esc_html($stored_uuid); ?>
                        </code>
                        <?php echo $uuid_ok ? '<span style="color:#00a32a;">&#10003;</span>' : '<span style="color:#d63638;">&#10007; mismatch</span>'; ?>
                    <?php else: ?>
                        <code><?php echo esc_html($stored_uuid); ?></code>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">wicket_uuid</th>
                <td>
                    <?php if (empty($stored_legacy)): ?>
                        <code style="color:#999;">(empty)</code>
                    <?php elseif ($is_uuid_login): ?>
                        <code style="color:<?php echo $legacy_ok ? '#00a32a' : '#d63638'; ?>;">
                            <?php echo esc_html($stored_legacy); ?>
                        </code>
                        <?php echo $legacy_ok ? '<span style="color:#00a32a;">&#10003;</span>' : '<span style="color:#d63638;">&#10007; mismatch</span>'; ?>
                    <?php else: ?>
                        <code><?php echo esc_html($stored_legacy); ?></code>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Status', 'wicket-integration'); ?></th>
                <td>
                    <?php if ($is_uuid_login && $all_ok): ?>
                        <span style="color:#00a32a; font-weight:bold;"><?php esc_html_e('OK — UUIDs match', 'wicket-integration'); ?></span>
                    <?php elseif (!$is_uuid_login && empty($stored_uuid) && empty($stored_legacy)): ?>
                        <span style="color:#999;"><?php esc_html_e('No Wicket link — non-UUID account with no UUID meta', 'wicket-integration'); ?></span>
                    <?php elseif (!$is_uuid_login): ?>
                        <span style="color:#dba617; font-weight:bold;"><?php esc_html_e('WARNING — Non-UUID username but has Wicket UUID meta stored. This account may be a duplicate.', 'wicket-integration'); ?></span>
                        <div style="margin-top:10px;">
                            <button type="button" id="wicket-uuid-clear-btn" class="button" style="background:#d63638; border-color:#d63638; color:#fff;" data-user-id="<?php echo esc_attr($user->ID); ?>">
                                <?php esc_html_e('Clear Wicket UUID Meta', 'wicket-integration'); ?>
                            </button>
                            <p class="description"><?php esc_html_e('Removes wicket_person_uuid and wicket_uuid from this account, disconnecting it from Wicket. Does NOT delete the user or touch Wicket.', 'wicket-integration'); ?></p>
                        </div>
                        <div id="wicket-uuid-clear-result" style="margin-top:10px;"></div>
                    <?php else: ?>
                        <span style="color:#d63638; font-weight:bold;"><?php esc_html_e('MISMATCH — UUIDs do not match username', 'wicket-integration'); ?></span>
                        <div style="margin-top:10px;">
                            <button type="button" id="wicket-uuid-repair-btn" class="button button-primary" data-user-id="<?php echo esc_attr($user->ID); ?>">
                                <?php esc_html_e('Repair: Fetch from Wicket & Sync All Meta', 'wicket-integration'); ?>
                            </button>
                            <p class="description"><?php esc_html_e('This will use the username as the correct UUID, fetch the person from Wicket, and overwrite all wicket_* meta fields, WP email, display name, and names.', 'wicket-integration'); ?></p>
                        </div>
                        <div id="wicket-uuid-repair-result" style="margin-top:10px;"></div>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php
    }

    // ------------------------------------------------------------------
    // Enqueue scripts on profile pages
    // ------------------------------------------------------------------

    public function enqueue_scripts($hook) {
        if (!in_array($hook, array('profile.php', 'user-edit.php'))) {
            return;
        }

        $handle = 'wicket-uuid-repair';
        wp_register_script($handle, '', array('jquery'), '1.0.0', true);
        wp_enqueue_script($handle);

        wp_localize_script($handle, 'wicket_uuid_repair', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wicket_uuid_repair_single'),
        ));

        wp_add_inline_script($handle, <<<'JS'
jQuery(document).ready(function($) {
    $('#wicket-uuid-repair-btn').on('click', function() {
        var $btn = $(this);
        var userId = $btn.data('user-id');

        if (!confirm('This will overwrite all Wicket meta for this user with data from Wicket. Continue?')) return;

        $btn.prop('disabled', true).text('Repairing...');
        $('#wicket-uuid-repair-result').html('<p>Fetching from Wicket...</p>');

        $.ajax({
            url: wicket_uuid_repair.ajax_url,
            type: 'POST',
            data: { action: 'wicket_uuid_repair_single', nonce: wicket_uuid_repair.nonce, user_id: userId },
            timeout: 60000,
            success: function(resp) {
                if (resp.success) {
                    $('#wicket-uuid-repair-result').html(
                        '<div style="padding:10px; background:#f0f9e8; border-left:4px solid #00a32a;">' +
                        '<strong>Repaired:</strong> ' + resp.data.message +
                        '</div>'
                    );
                    setTimeout(function(){ location.reload(); }, 2000);
                } else {
                    $('#wicket-uuid-repair-result').html(
                        '<div style="padding:10px; background:#fef0f0; border-left:4px solid #d63638;">' +
                        '<strong>Error:</strong> ' + (resp.data && resp.data.message || 'Unknown') +
                        '</div>'
                    );
                    $btn.prop('disabled', false).text('Repair: Fetch from Wicket & Sync All Meta');
                }
            },
            error: function() {
                $('#wicket-uuid-repair-result').html('<div style="color:#d63638;">AJAX error.</div>');
                $btn.prop('disabled', false).text('Repair: Fetch from Wicket & Sync All Meta');
            }
        });
    });

    $('#wicket-uuid-clear-btn').on('click', function() {
        var $btn = $(this);
        var userId = $btn.data('user-id');

        if (!confirm('This will remove wicket_person_uuid and wicket_uuid from this account. The user will no longer be linked to any Wicket person. Continue?')) return;

        $btn.prop('disabled', true).text('Clearing...');

        $.ajax({
            url: wicket_uuid_repair.ajax_url,
            type: 'POST',
            data: { action: 'wicket_uuid_clear_meta', nonce: wicket_uuid_repair.nonce, user_id: userId },
            timeout: 30000,
            success: function(resp) {
                if (resp.success) {
                    $('#wicket-uuid-clear-result').html(
                        '<div style="padding:10px; background:#f0f9e8; border-left:4px solid #00a32a;">' +
                        '<strong>Done:</strong> ' + resp.data.message +
                        '</div>'
                    );
                    setTimeout(function(){ location.reload(); }, 2000);
                } else {
                    $('#wicket-uuid-clear-result').html(
                        '<div style="padding:10px; background:#fef0f0; border-left:4px solid #d63638;">' +
                        '<strong>Error:</strong> ' + (resp.data && resp.data.message || 'Unknown') +
                        '</div>'
                    );
                    $btn.prop('disabled', false).text('Clear Wicket UUID Meta');
                }
            },
            error: function() {
                $('#wicket-uuid-clear-result').html('<div style="color:#d63638;">AJAX error.</div>');
                $btn.prop('disabled', false).text('Clear Wicket UUID Meta');
            }
        });
    });
});
JS
        );
    }

    // ------------------------------------------------------------------
    // AJAX repair handler
    // ------------------------------------------------------------------

    public function ajax_repair() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wicket_uuid_repair_single')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }

        $user_id = intval($_POST['user_id'] ?? 0);
        $user    = get_userdata($user_id);

        if (!$user || !$this->is_uuid($user->user_login)) {
            wp_send_json_error(array('message' => 'Invalid user or username is not a UUID'));
            return;
        }

        $config = $this->get_wicket_config();
        if (is_wp_error($config)) {
            wp_send_json_error(array('message' => $config->get_error_message()));
            return;
        }

        $token = $this->generate_jwt_token($config);
        $uuid  = $user->user_login;
        $url   = $config['base_url'] . '/people/' . $uuid . '?include=addresses,phones,emails';
        $resp  = $this->make_api_request($url, $token);

        if (is_wp_error($resp)) {
            wp_send_json_error(array('message' => 'Wicket API error: ' . $resp->get_error_message()));
            return;
        }

        if (empty($resp['data'])) {
            wp_send_json_error(array('message' => 'Person not found in Wicket for UUID: ' . $uuid));
            return;
        }

        $person   = $resp['data'];
        $included = $resp['included'] ?? array();
        $attrs    = $person['attributes'] ?? array();
        $person_uuid = $attrs['uuid'] ?? $person['id'] ?? $uuid;

        // UUID meta
        update_user_meta($user_id, 'wicket_person_uuid', $person_uuid);
        update_user_meta($user_id, 'wicket_uuid', $person_uuid);

        // WP core fields
        $wp_update = array('ID' => $user_id);
        if (!empty($attrs['given_name']))  $wp_update['first_name']  = sanitize_text_field($attrs['given_name']);
        if (!empty($attrs['family_name'])) $wp_update['last_name']   = sanitize_text_field($attrs['family_name']);
        if (!empty($attrs['primary_email_address'])) $wp_update['user_email'] = sanitize_email($attrs['primary_email_address']);
        if (!empty($attrs['given_name']) && !empty($attrs['family_name'])) {
            $wp_update['display_name'] = sanitize_text_field($attrs['given_name'] . ' ' . $attrs['family_name']);
        }
        if (count($wp_update) > 1) wp_update_user($wp_update);

        // Person attribute meta
        $field_mapping = array(
            'given_name'            => 'first_name',
            'family_name'           => 'last_name',
            'full_name'             => 'wicket_full_name',
            'alternate_name'        => 'wicket_alternate_name',
            'additional_name'       => 'wicket_additional_name',
            'nickname'              => 'wicket_nickname',
            'job_title'             => 'wicket_job_title',
            'job_level'             => 'wicket_job_level',
            'job_function'          => 'wicket_job_function',
            'gender'                => 'wicket_gender',
            'birth_date'            => 'wicket_birth_date',
            'preferred_pronoun'     => 'wicket_preferred_pronoun',
            'honorific_prefix'      => 'wicket_honorific_prefix',
            'honorific_suffix'      => 'wicket_honorific_suffix',
            'membership_number'     => 'wicket_membership_number',
            'membership_began_on'   => 'wicket_membership_began_on',
            'identifying_number'    => 'wicket_identifying_number',
            'updated_at'            => 'wicket_updated_at',
            'created_at'            => 'wicket_created_at',
            'role_names'            => 'wicket_roles',
            'tags'                  => 'wicket_tags',
            'language'              => 'wicket_language',
            'primary_email_address' => 'wicket_primary_email',
        );

        foreach ($field_mapping as $wf => $mk) {
            if (isset($attrs[$wf])) {
                $v = $attrs[$wf];
                if (is_array($v)) $v = implode(', ', $v);
                if (is_string($v)) $v = sanitize_text_field($v);
                update_user_meta($user_id, $mk, $v);
            }
        }

        // Primary address
        $addr = $this->get_primary_from_included($included, 'addresses');
        if ($addr && isset($addr['attributes'])) {
            $aa = $addr['attributes'];
            $map = array(
                'address1' => 'wicket_address1', 'address2' => 'wicket_address2',
                'city' => 'wicket_city', 'state_name' => 'wicket_state',
                'zip_code' => 'wicket_zip_code', 'country_code' => 'wicket_country_code',
                'country_name' => 'wicket_country_name', 'company_name' => 'wicket_company_name',
                'department' => 'wicket_department', 'division' => 'wicket_division',
                'formatted_address_label' => 'wicket_formatted_address',
                'latitude' => 'wicket_latitude', 'longitude' => 'wicket_longitude',
            );
            foreach ($map as $wk => $mk) {
                if (isset($aa[$wk]) && $aa[$wk] !== null) {
                    $v = ($wk === 'formatted_address_label') ? sanitize_textarea_field($aa[$wk]) : (is_string($aa[$wk]) ? sanitize_text_field($aa[$wk]) : $aa[$wk]);
                    update_user_meta($user_id, $mk, $v);
                }
            }
            if (isset($aa['uuid'])) update_user_meta($user_id, 'wicket_address_uuid', sanitize_text_field($aa['uuid']));
        }

        // Primary phone
        $phone = $this->get_primary_from_included($included, 'phones');
        if ($phone && isset($phone['attributes'])) {
            $pa = $phone['attributes'];
            $map = array(
                'number' => 'wicket_phone', 'number_national_format' => 'wicket_phone_national',
                'number_international_format' => 'wicket_phone_international',
                'type' => 'wicket_phone_type', 'extension' => 'wicket_phone_extension',
                'country_code_number' => 'wicket_phone_country_code',
            );
            foreach ($map as $wk => $mk) {
                if (isset($pa[$wk]) && $pa[$wk] !== null) {
                    $v = is_string($pa[$wk]) ? sanitize_text_field($pa[$wk]) : $pa[$wk];
                    update_user_meta($user_id, $mk, $v);
                }
            }
            if (isset($pa['uuid'])) update_user_meta($user_id, 'wicket_phone_uuid', sanitize_text_field($pa['uuid']));
        }

        // Clear sync timestamp
        delete_user_meta($user_id, 'wicket_last_login_sync');

        $name = trim(($attrs['given_name'] ?? '') . ' ' . ($attrs['family_name'] ?? ''));
        wp_send_json_success(array('message' => "Synced from Wicket — {$name} ({$person_uuid})"));
    }

    // ------------------------------------------------------------------
    // AJAX clear UUID meta
    // ------------------------------------------------------------------

    public function ajax_clear_meta() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wicket_uuid_repair_single')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }

        $user_id = intval($_POST['user_id'] ?? 0);
        $user    = get_userdata($user_id);

        if (!$user) {
            wp_send_json_error(array('message' => 'User not found'));
            return;
        }

        $old_uuid   = get_user_meta($user_id, 'wicket_person_uuid', true);
        $old_legacy = get_user_meta($user_id, 'wicket_uuid', true);

        delete_user_meta($user_id, 'wicket_person_uuid');
        delete_user_meta($user_id, 'wicket_uuid');

        wp_send_json_success(array(
            'message' => "Cleared wicket_person_uuid ({$old_uuid}) and wicket_uuid ({$old_legacy}) from user {$user_id}.",
        ));
    }

    // ------------------------------------------------------------------
    // API helpers
    // ------------------------------------------------------------------

    private function get_wicket_config() {
        $tenant     = get_option('wicket_tenant_name', '');
        $secret     = get_option('wicket_api_secret_key', '');
        $admin_uuid = get_option('wicket_admin_user_uuid', '');
        $staging    = get_option('wicket_staging', 0);

        if (empty($tenant) || empty($secret) || empty($admin_uuid)) {
            return new WP_Error('missing_config', 'Wicket API configuration is incomplete.');
        }

        $base_url = $staging
            ? "https://{$tenant}-api.staging.wicketcloud.com"
            : "https://{$tenant}-api.wicketcloud.com";

        return compact('tenant', 'secret', 'admin_uuid', 'base_url');
    }

    private function generate_jwt_token($config) {
        $header  = json_encode(array('typ' => 'JWT', 'alg' => 'HS256'));
        $payload = json_encode(array(
            'exp' => time() + 3600,
            'sub' => $config['admin_uuid'],
            'aud' => $config['base_url'],
            'iss' => get_site_url(),
        ));

        $b64H = str_replace(array('+', '/', '='), array('-', '_', ''), base64_encode($header));
        $b64P = str_replace(array('+', '/', '='), array('-', '_', ''), base64_encode($payload));
        $sig  = hash_hmac('sha256', "{$b64H}.{$b64P}", $config['secret'], true);
        $b64S = str_replace(array('+', '/', '='), array('-', '_', ''), base64_encode($sig));

        return "{$b64H}.{$b64P}.{$b64S}";
    }

    private function make_api_request($url, $token) {
        $response = wp_remote_get($url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'timeout' => 30,
        ));

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            return new WP_Error('api_error', isset($body['message']) ? $body['message'] : "HTTP {$code}");
        }

        return $body;
    }

    private function get_primary_from_included($included, $type) {
        if (empty($included)) return null;
        foreach ($included as $item) {
            if ($item['type'] === $type && !empty($item['attributes']['primary'])) return $item;
        }
        foreach ($included as $item) {
            if ($item['type'] === $type) return $item;
        }
        return null;
    }
}

new Wicket_UUID_Repair();
