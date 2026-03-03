<?php
/**
 * Wicket UUID Repair Tool
 *
 * Scans WordPress users for mismatched Wicket UUIDs (caused by a prior
 * email-first lookup bug) and repairs them by looking up the correct UUID
 * via the user's email address in Wicket.
 *
 * @package MyIES_Integration
 * @since 1.0.19
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_UUID_Repair {

    private $batch_size = 10;

    public function __construct() {
        add_action('init', array($this, 'register_ajax_handlers'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function register_ajax_handlers() {
        // Scan handlers
        add_action('wp_ajax_uuid_repair_start_scan', array($this, 'start_scan'));
        add_action('wp_ajax_uuid_repair_process_batch', array($this, 'process_scan_batch'));
        add_action('wp_ajax_uuid_repair_get_status', array($this, 'get_scan_status'));
        add_action('wp_ajax_uuid_repair_cancel', array($this, 'cancel_scan'));

        // Repair handlers
        add_action('wp_ajax_uuid_repair_start_repair', array($this, 'start_repair'));
        add_action('wp_ajax_uuid_repair_process_repair_batch', array($this, 'process_repair_batch'));
        add_action('wp_ajax_uuid_repair_get_repair_status', array($this, 'get_repair_status'));

        // Resolve duplicates handlers (blank-email users)
        add_action('wp_ajax_uuid_repair_start_resolve', array($this, 'start_resolve'));
        add_action('wp_ajax_uuid_repair_process_resolve_batch', array($this, 'process_resolve_batch'));
        add_action('wp_ajax_uuid_repair_get_resolve_status', array($this, 'get_resolve_status'));
    }

    // ------------------------------------------------------------------
    // Self-contained API helpers
    // ------------------------------------------------------------------

    private function get_wicket_config() {
        $tenant    = get_option('wicket_tenant_name', '');
        $secret    = get_option('wicket_api_secret_key', '');
        $admin_uuid = get_option('wicket_admin_user_uuid', '');
        $staging   = get_option('wicket_staging', 0);

        if (empty($tenant) || empty($secret) || empty($admin_uuid)) {
            return new WP_Error('missing_config', 'Wicket API configuration is incomplete.');
        }

        $base_url = $staging
            ? "https://{$tenant}-api.staging.wicketcloud.com"
            : "https://{$tenant}-api.wicketcloud.com";

        return array(
            'tenant'     => $tenant,
            'secret'     => $secret,
            'admin_uuid' => $admin_uuid,
            'base_url'   => $base_url,
        );
    }

    private function generate_jwt_token($config) {
        $header  = json_encode(array('typ' => 'JWT', 'alg' => 'HS256'));
        $payload = json_encode(array(
            'exp' => time() + 3600,
            'sub' => $config['admin_uuid'],
            'aud' => $config['base_url'],
            'iss' => get_site_url(),
        ));

        $b64Header  = str_replace(array('+', '/', '='), array('-', '_', ''), base64_encode($header));
        $b64Payload = str_replace(array('+', '/', '='), array('-', '_', ''), base64_encode($payload));
        $signature  = hash_hmac('sha256', "{$b64Header}.{$b64Payload}", $config['secret'], true);
        $b64Sig     = str_replace(array('+', '/', '='), array('-', '_', ''), base64_encode($signature));

        return "{$b64Header}.{$b64Payload}.{$b64Sig}";
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

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            $msg = isset($body['message']) ? $body['message'] : "HTTP {$code}";
            return new WP_Error('api_error', $msg);
        }

        return $body;
    }

    // ------------------------------------------------------------------
    // Security guard shared by all AJAX handlers
    // ------------------------------------------------------------------

    private function verify_request() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wicket_uuid_repair')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return false;
        }
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return false;
        }
        return true;
    }

    // ------------------------------------------------------------------
    // SCAN — start
    // ------------------------------------------------------------------

    public function start_scan() {
        if (!$this->verify_request()) return;

        // Count users that have a wicket UUID stored
        $users = get_users(array(
            'meta_query' => array(
                'relation' => 'OR',
                array('key' => 'wicket_uuid', 'compare' => 'EXISTS'),
                array('key' => 'wicket_person_uuid', 'compare' => 'EXISTS'),
            ),
            'fields' => 'ID',
        ));

        $total = count($users);

        update_option('wicket_uuid_repair_scan_in_progress', true);
        update_option('wicket_uuid_repair_status', array(
            'total'        => $total,
            'processed'    => 0,
            'offset'       => 0,
            'matches'      => 0,
            'mismatches'   => array(),
            'not_found'    => array(),
            'multi_results' => array(),
            'errors'       => array(),
            'logs'         => array(),
            'started_at'   => current_time('mysql'),
        ));

        wp_send_json_success(array(
            'message'     => 'Scan started',
            'total_users' => $total,
        ));
    }

    // ------------------------------------------------------------------
    // SCAN — process batch
    // ------------------------------------------------------------------

    public function process_scan_batch() {
        if (!$this->verify_request()) return;

        if (!get_option('wicket_uuid_repair_scan_in_progress', false)) {
            wp_send_json_error(array('message' => 'No scan in progress'));
            return;
        }

        $config = $this->get_wicket_config();
        if (is_wp_error($config)) {
            wp_send_json_error(array('message' => $config->get_error_message()));
            return;
        }

        $token  = $this->generate_jwt_token($config);
        $status = get_option('wicket_uuid_repair_status', array());

        // Get next batch of users with a wicket UUID
        $users = get_users(array(
            'meta_query' => array(
                'relation' => 'OR',
                array('key' => 'wicket_uuid', 'compare' => 'EXISTS'),
                array('key' => 'wicket_person_uuid', 'compare' => 'EXISTS'),
            ),
            'number' => $this->batch_size,
            'offset' => $status['offset'],
            'fields' => array('ID', 'user_email', 'display_name'),
        ));

        $logs = $status['logs'] ?? array();

        foreach ($users as $user) {
            $stored_uuid = get_user_meta($user->ID, 'wicket_uuid', true);
            if (empty($stored_uuid)) {
                $stored_uuid = get_user_meta($user->ID, 'wicket_person_uuid', true);
            }

            // Look up by email in Wicket
            $email_encoded = rawurlencode($user->user_email);
            $url  = $config['base_url'] . "/people?filter[emails_address_eq]={$email_encoded}&page[size]=5";
            $resp = $this->make_api_request($url, $token);

            if (is_wp_error($resp)) {
                $status['errors'][] = array(
                    'user_id' => $user->ID,
                    'email'   => $user->user_email,
                    'name'    => $user->display_name,
                    'error'   => $resp->get_error_message(),
                );
                $logs[] = array('message' => "User {$user->ID} ({$user->user_email}): API error — {$resp->get_error_message()}", 'type' => 'error');
                $status['processed']++;
                continue;
            }

            $data = isset($resp['data']) ? $resp['data'] : array();
            $count = count($data);

            if ($count === 0) {
                $status['not_found'][] = array(
                    'user_id'    => $user->ID,
                    'email'      => $user->user_email,
                    'name'       => $user->display_name,
                    'stored_uuid' => $stored_uuid,
                );
                $logs[] = array('message' => "User {$user->ID} ({$user->user_email}): Not found in Wicket", 'type' => 'warning');
            } elseif ($count > 1) {
                $uuids = array_map(function($p) { return $p['id']; }, $data);
                $status['multi_results'][] = array(
                    'user_id'      => $user->ID,
                    'email'        => $user->user_email,
                    'name'         => $user->display_name,
                    'stored_uuid'  => $stored_uuid,
                    'wicket_uuids' => $uuids,
                );
                $logs[] = array('message' => "User {$user->ID} ({$user->user_email}): Multiple results (" . implode(', ', $uuids) . ")", 'type' => 'warning');
            } else {
                // Exactly one result
                $wicket_uuid = $data[0]['id'];
                $attrs       = $data[0]['attributes'] ?? array();
                $wicket_name = trim(($attrs['given_name'] ?? '') . ' ' . ($attrs['family_name'] ?? ''));

                if ($wicket_uuid === $stored_uuid) {
                    $status['matches']++;
                    $logs[] = array('message' => "User {$user->ID} ({$user->user_email}): UUID matches", 'type' => 'success');
                } else {
                    $status['mismatches'][] = array(
                        'user_id'       => $user->ID,
                        'email'         => $user->user_email,
                        'wp_name'       => $user->display_name,
                        'stored_uuid'   => $stored_uuid,
                        'correct_uuid'  => $wicket_uuid,
                        'wicket_name'   => $wicket_name,
                    );
                    $logs[] = array('message' => "User {$user->ID} ({$user->user_email}): MISMATCH — stored {$stored_uuid}, correct {$wicket_uuid}", 'type' => 'error');
                }
            }

            $status['processed']++;
        }

        $status['offset'] += $this->batch_size;
        $status['logs'] = array_slice($logs, -200);

        $completed = $status['processed'] >= $status['total'] || empty($users);

        if ($completed) {
            update_option('wicket_uuid_repair_scan_in_progress', false);
            $status['completed_at'] = current_time('mysql');
        }

        update_option('wicket_uuid_repair_status', $status);

        wp_send_json_success(array(
            'completed'     => $completed,
            'processed'     => $status['processed'],
            'total'         => $status['total'],
            'matches'       => $status['matches'],
            'mismatch_count' => count($status['mismatches']),
            'not_found_count' => count($status['not_found']),
            'multi_count'   => count($status['multi_results']),
            'error_count'   => count($status['errors']),
        ));
    }

    // ------------------------------------------------------------------
    // SCAN — get status
    // ------------------------------------------------------------------

    public function get_scan_status() {
        if (!$this->verify_request()) return;

        $status = get_option('wicket_uuid_repair_status', array());
        $recent_logs = array_slice($status['logs'] ?? array(), -5);

        wp_send_json_success(array(
            'total'          => $status['total'] ?? 0,
            'processed'      => $status['processed'] ?? 0,
            'matches'        => $status['matches'] ?? 0,
            'mismatch_count' => count($status['mismatches'] ?? array()),
            'not_found_count' => count($status['not_found'] ?? array()),
            'multi_count'    => count($status['multi_results'] ?? array()),
            'error_count'    => count($status['errors'] ?? array()),
            'recent_logs'    => $recent_logs,
        ));
    }

    // ------------------------------------------------------------------
    // SCAN — cancel
    // ------------------------------------------------------------------

    public function cancel_scan() {
        if (!$this->verify_request()) return;

        update_option('wicket_uuid_repair_scan_in_progress', false);
        wp_send_json_success(array('message' => 'Scan cancelled'));
    }

    // ------------------------------------------------------------------
    // REPAIR — start
    // ------------------------------------------------------------------

    public function start_repair() {
        if (!$this->verify_request()) return;

        $status = get_option('wicket_uuid_repair_status', array());
        $mismatches = $status['mismatches'] ?? array();

        if (empty($mismatches)) {
            wp_send_json_error(array('message' => 'No mismatches to repair'));
            return;
        }

        update_option('wicket_uuid_repair_in_progress', true);
        update_option('wicket_uuid_repair_repair_status', array(
            'total'      => count($mismatches),
            'processed'  => 0,
            'offset'     => 0,
            'success'    => 0,
            'errors'     => 0,
            'logs'       => array(),
            'started_at' => current_time('mysql'),
        ));

        wp_send_json_success(array(
            'message' => 'Repair started',
            'total'   => count($mismatches),
        ));
    }

    // ------------------------------------------------------------------
    // REPAIR — process batch
    // ------------------------------------------------------------------

    public function process_repair_batch() {
        if (!$this->verify_request()) return;

        if (!get_option('wicket_uuid_repair_in_progress', false)) {
            wp_send_json_error(array('message' => 'No repair in progress'));
            return;
        }

        $config = $this->get_wicket_config();
        if (is_wp_error($config)) {
            wp_send_json_error(array('message' => $config->get_error_message()));
            return;
        }

        $scan_status   = get_option('wicket_uuid_repair_status', array());
        $repair_status = get_option('wicket_uuid_repair_repair_status', array());
        $mismatches    = $scan_status['mismatches'] ?? array();
        $offset        = $repair_status['offset'];
        $batch         = array_slice($mismatches, $offset, $this->batch_size);
        $logs          = $repair_status['logs'] ?? array();

        foreach ($batch as $entry) {
            $user_id      = $entry['user_id'];
            $correct_uuid = $entry['correct_uuid'];

            // 1. Update the UUID meta keys
            update_user_meta($user_id, 'wicket_uuid', $correct_uuid);
            if (get_user_meta($user_id, 'wicket_person_uuid', true) !== '') {
                update_user_meta($user_id, 'wicket_person_uuid', $correct_uuid);
            }

            // 2. Clear last-sync timestamp so the next login triggers a fresh sync
            delete_user_meta($user_id, 'wicket_last_login_sync');

            // 3. Re-sync profile data from Wicket using the corrected UUID
            $sync_ok = true;

            if (isset($GLOBALS['wicket_acf_sync_instance'])) {
                $result = $GLOBALS['wicket_acf_sync_instance']->sync_wicket_person_to_acf(
                    $correct_uuid,
                    $user_id,
                    $config['tenant'],
                    $config['secret'],
                    $config['admin_uuid']
                );
                if (is_wp_error($result)) {
                    $sync_ok = false;
                    $logs[] = array('message' => "User {$user_id}: UUID updated but profile re-sync failed — {$result->get_error_message()}", 'type' => 'warning');
                }
            }

            // 4. Re-sync organization & section data
            if (isset($GLOBALS['wicket_login_sync_instance'])) {
                $GLOBALS['wicket_login_sync_instance']->sync_user_organization_public($user_id, $correct_uuid);
                $GLOBALS['wicket_login_sync_instance']->sync_user_section_public($user_id, $correct_uuid);
            }

            if ($sync_ok) {
                $repair_status['success']++;
                $logs[] = array('message' => "User {$user_id} ({$entry['email']}): Repaired — UUID set to {$correct_uuid}", 'type' => 'success');
            } else {
                $repair_status['errors']++;
            }

            $repair_status['processed']++;
        }

        $repair_status['offset'] += $this->batch_size;
        $repair_status['logs'] = array_slice($logs, -200);

        $completed = $repair_status['processed'] >= $repair_status['total'] || empty($batch);

        if ($completed) {
            update_option('wicket_uuid_repair_in_progress', false);
            $repair_status['completed_at'] = current_time('mysql');
        }

        update_option('wicket_uuid_repair_repair_status', $repair_status);

        wp_send_json_success(array(
            'completed' => $completed,
            'processed' => $repair_status['processed'],
            'total'     => $repair_status['total'],
            'success'   => $repair_status['success'],
            'errors'    => $repair_status['errors'],
        ));
    }

    // ------------------------------------------------------------------
    // REPAIR — get status
    // ------------------------------------------------------------------

    public function get_repair_status() {
        if (!$this->verify_request()) return;

        $status = get_option('wicket_uuid_repair_repair_status', array());
        $recent_logs = array_slice($status['logs'] ?? array(), -5);

        wp_send_json_success(array(
            'total'       => $status['total'] ?? 0,
            'processed'   => $status['processed'] ?? 0,
            'success'     => $status['success'] ?? 0,
            'errors'      => $status['errors'] ?? 0,
            'recent_logs' => $recent_logs,
        ));
    }

    // ------------------------------------------------------------------
    // RESOLVE DUPLICATES — start
    // For blank-email users whose display_name is a UUID, verify it in Wicket
    // ------------------------------------------------------------------

    public function start_resolve() {
        if (!$this->verify_request()) return;

        $scan_status   = get_option('wicket_uuid_repair_status', array());
        $multi_results = $scan_status['multi_results'] ?? array();

        if (empty($multi_results)) {
            wp_send_json_error(array('message' => 'No duplicate entries to resolve'));
            return;
        }

        // Filter to blank-email users whose display_name looks like a UUID
        $candidates = array();
        foreach ($multi_results as $mr) {
            $email = trim($mr['email'] ?? '');
            $name  = trim($mr['name'] ?? '');
            if (empty($email) && $this->is_uuid($name)) {
                $candidates[] = $mr;
            }
        }

        if (empty($candidates)) {
            wp_send_json_error(array('message' => 'No blank-email users with UUID display names found'));
            return;
        }

        update_option('wicket_uuid_resolve_in_progress', true);
        update_option('wicket_uuid_resolve_status', array(
            'total'      => count($candidates),
            'processed'  => 0,
            'offset'     => 0,
            'repaired'   => 0,
            'already_ok' => 0,
            'invalid'    => 0,
            'errors'     => 0,
            'logs'       => array(),
            'candidates' => $candidates,
            'started_at' => current_time('mysql'),
        ));

        wp_send_json_success(array(
            'message' => 'Resolve started',
            'total'   => count($candidates),
        ));
    }

    // ------------------------------------------------------------------
    // RESOLVE DUPLICATES — process batch
    // ------------------------------------------------------------------

    public function process_resolve_batch() {
        if (!$this->verify_request()) return;

        if (!get_option('wicket_uuid_resolve_in_progress', false)) {
            wp_send_json_error(array('message' => 'No resolve in progress'));
            return;
        }

        $config = $this->get_wicket_config();
        if (is_wp_error($config)) {
            wp_send_json_error(array('message' => $config->get_error_message()));
            return;
        }

        $token  = $this->generate_jwt_token($config);
        $status = get_option('wicket_uuid_resolve_status', array());
        $candidates = $status['candidates'] ?? array();
        $offset = $status['offset'];
        $batch  = array_slice($candidates, $offset, $this->batch_size);
        $logs   = $status['logs'] ?? array();

        foreach ($batch as $entry) {
            $user_id       = $entry['user_id'];
            $stored_uuid   = $entry['stored_uuid'];
            $candidate_uuid = trim($entry['name']); // display_name = legacy UUID

            // Verify the candidate UUID exists in Wicket
            $url  = $config['base_url'] . '/people/' . $candidate_uuid;
            $resp = $this->make_api_request($url, $token);

            if (is_wp_error($resp)) {
                $status['invalid']++;
                $logs[] = array('message' => "User {$user_id}: display_name UUID {$candidate_uuid} not found in Wicket — {$resp->get_error_message()}", 'type' => 'warning');
                $status['processed']++;
                continue;
            }

            $person_data = $resp['data'] ?? null;
            if (empty($person_data)) {
                $status['invalid']++;
                $logs[] = array('message' => "User {$user_id}: display_name UUID {$candidate_uuid} returned empty response", 'type' => 'warning');
                $status['processed']++;
                continue;
            }

            $attrs      = $person_data['attributes'] ?? array();
            $wicket_name = trim(($attrs['given_name'] ?? '') . ' ' . ($attrs['family_name'] ?? ''));

            // Already correct?
            if ($stored_uuid === $candidate_uuid) {
                $status['already_ok']++;
                $logs[] = array('message' => "User {$user_id}: stored UUID already matches display_name ({$candidate_uuid}) — {$wicket_name}", 'type' => 'success');
                $status['processed']++;
                continue;
            }

            // Apply repair
            update_user_meta($user_id, 'wicket_uuid', $candidate_uuid);
            if (get_user_meta($user_id, 'wicket_person_uuid', true) !== '') {
                update_user_meta($user_id, 'wicket_person_uuid', $candidate_uuid);
            }
            delete_user_meta($user_id, 'wicket_last_login_sync');

            // Re-sync profile data
            if (isset($GLOBALS['wicket_acf_sync_instance'])) {
                $GLOBALS['wicket_acf_sync_instance']->sync_wicket_person_to_acf(
                    $candidate_uuid, $user_id,
                    $config['tenant'], $config['secret'], $config['admin_uuid']
                );
            }
            if (isset($GLOBALS['wicket_login_sync_instance'])) {
                $GLOBALS['wicket_login_sync_instance']->sync_user_organization_public($user_id, $candidate_uuid);
                $GLOBALS['wicket_login_sync_instance']->sync_user_section_public($user_id, $candidate_uuid);
            }

            $status['repaired']++;
            $logs[] = array('message' => "User {$user_id}: REPAIRED — {$stored_uuid} → {$candidate_uuid} ({$wicket_name})", 'type' => 'success');
            $status['processed']++;
        }

        $status['offset'] += $this->batch_size;
        $status['logs'] = array_slice($logs, -200);

        $completed = $status['processed'] >= $status['total'] || empty($batch);

        if ($completed) {
            update_option('wicket_uuid_resolve_in_progress', false);
            $status['completed_at'] = current_time('mysql');
        }

        update_option('wicket_uuid_resolve_status', $status);

        wp_send_json_success(array(
            'completed'  => $completed,
            'processed'  => $status['processed'],
            'total'      => $status['total'],
            'repaired'   => $status['repaired'],
            'already_ok' => $status['already_ok'],
            'invalid'    => $status['invalid'],
            'errors'     => $status['errors'],
        ));
    }

    // ------------------------------------------------------------------
    // RESOLVE DUPLICATES — get status
    // ------------------------------------------------------------------

    public function get_resolve_status() {
        if (!$this->verify_request()) return;

        $status = get_option('wicket_uuid_resolve_status', array());
        $recent_logs = array_slice($status['logs'] ?? array(), -5);

        wp_send_json_success(array(
            'total'       => $status['total'] ?? 0,
            'processed'   => $status['processed'] ?? 0,
            'repaired'    => $status['repaired'] ?? 0,
            'already_ok'  => $status['already_ok'] ?? 0,
            'invalid'     => $status['invalid'] ?? 0,
            'errors'      => $status['errors'] ?? 0,
            'recent_logs' => $recent_logs,
        ));
    }

    // ------------------------------------------------------------------
    // Helper — check if a string looks like a UUID
    // ------------------------------------------------------------------

    private function is_uuid($str) {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $str);
    }

    // ------------------------------------------------------------------
    // Enqueue admin scripts (only on UUID Repair page)
    // ------------------------------------------------------------------

    public function enqueue_scripts($hook) {
        if (strpos($hook, 'myies-uuid-repair') === false) {
            return;
        }

        $handle = 'wicket-uuid-repair-' . wp_generate_uuid4();
        wp_register_script($handle, '', array('jquery'), '1.0.0', true);
        wp_enqueue_script($handle);

        wp_localize_script($handle, 'wicket_uuid_repair', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wicket_uuid_repair'),
        ));

        wp_add_inline_script($handle, $this->get_inline_js());
    }

    // ------------------------------------------------------------------
    // Admin page HTML
    // ------------------------------------------------------------------

    public function render_page() {
        $scan_in_progress   = get_option('wicket_uuid_repair_scan_in_progress', false);
        $repair_in_progress = get_option('wicket_uuid_repair_in_progress', false);
        $status             = get_option('wicket_uuid_repair_status', array());
        $has_results        = !empty($status) && isset($status['completed_at']);
        $mismatches         = $status['mismatches'] ?? array();
        $not_found          = $status['not_found'] ?? array();
        $multi_results      = $status['multi_results'] ?? array();
        $errors             = $status['errors'] ?? array();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('UUID Repair Tool', 'wicket-integration'); ?></h1>
            <p><?php esc_html_e('This tool scans all WordPress users that have a Wicket UUID stored, looks up their email in Wicket, and detects UUID mismatches caused by a prior sync bug. You can then repair the mismatches in bulk.', 'wicket-integration'); ?></p>

            <!-- Scan controls -->
            <div class="card" style="max-width:1100px; margin-top:20px;">
                <h2><?php esc_html_e('Step 1: Scan for Mismatches', 'wicket-integration'); ?></h2>

                <div id="uuid-scan-controls">
                    <?php if (!$scan_in_progress): ?>
                        <button type="button" id="uuid-start-scan-btn" class="button button-primary">
                            <?php esc_html_e('Start Scan', 'wicket-integration'); ?>
                        </button>
                    <?php else: ?>
                        <button type="button" id="uuid-cancel-scan-btn" class="button button-secondary">
                            <?php esc_html_e('Cancel Scan', 'wicket-integration'); ?>
                        </button>
                        <span class="description" style="color:#d63638;"><?php esc_html_e('Scan in progress…', 'wicket-integration'); ?></span>
                    <?php endif; ?>
                </div>

                <!-- Scan progress -->
                <div id="uuid-scan-progress" style="display:none; margin-top:20px;">
                    <div style="background:#f0f0f0; border-radius:3px; height:20px; position:relative;">
                        <div id="uuid-scan-bar" style="background:#0073aa; height:100%; border-radius:3px; width:0%; transition:width .3s;"></div>
                        <div id="uuid-scan-pct" style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); font-size:12px; font-weight:bold;">0%</div>
                    </div>
                    <div id="uuid-scan-stats" style="margin-top:10px; font-size:14px;"></div>
                    <div id="uuid-scan-log" style="margin-top:15px; max-height:200px; overflow-y:auto; background:#f9f9f9; padding:10px; border:1px solid #ddd; font-family:monospace; font-size:12px;"></div>
                </div>
            </div>

            <!-- Results -->
            <?php if ($has_results): ?>
            <div class="card" style="max-width:1100px; margin-top:20px;">
                <h2><?php esc_html_e('Scan Results', 'wicket-integration'); ?></h2>
                <p>
                    <?php printf(
                        esc_html__('Scanned %1$d users — %2$d match, %3$d mismatches, %4$d not found, %5$d multiple results, %6$d errors.', 'wicket-integration'),
                        $status['processed'] ?? 0,
                        $status['matches'] ?? 0,
                        count($mismatches),
                        count($not_found),
                        count($multi_results),
                        count($errors)
                    ); ?>
                </p>

                <?php if (!empty($mismatches)): ?>
                <h3 style="color:#d63638;"><?php printf(esc_html__('Mismatches (%d)', 'wicket-integration'), count($mismatches)); ?></h3>
                <table class="widefat striped" style="margin-bottom:20px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('WP User ID', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Email', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Name in WP', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Stored UUID', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Correct UUID', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Name in Wicket', 'wicket-integration'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mismatches as $m): ?>
                        <tr>
                            <td><?php echo esc_html($m['user_id']); ?></td>
                            <td><?php echo esc_html($m['email']); ?></td>
                            <td><?php echo esc_html($m['wp_name']); ?></td>
                            <td><code style="color:#d63638;"><?php echo esc_html($m['stored_uuid']); ?></code></td>
                            <td><code style="color:#00a32a;"><?php echo esc_html($m['correct_uuid']); ?></code></td>
                            <td><?php echo esc_html($m['wicket_name']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if (!empty($not_found)): ?>
                <h3 style="color:#dba617;"><?php printf(esc_html__('Not Found in Wicket (%d)', 'wicket-integration'), count($not_found)); ?></h3>
                <table class="widefat striped" style="margin-bottom:20px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('WP User ID', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Email', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Name', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Stored UUID', 'wicket-integration'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($not_found as $nf): ?>
                        <tr>
                            <td><?php echo esc_html($nf['user_id']); ?></td>
                            <td><?php echo esc_html($nf['email']); ?></td>
                            <td><?php echo esc_html($nf['name']); ?></td>
                            <td><code><?php echo esc_html($nf['stored_uuid']); ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if (!empty($multi_results)): ?>
                <h3 style="color:#dba617;"><?php printf(esc_html__('Multiple Results (%d)', 'wicket-integration'), count($multi_results)); ?></h3>
                <table class="widefat striped" style="margin-bottom:20px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('WP User ID', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Email', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Name', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Stored UUID', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Wicket UUIDs Found', 'wicket-integration'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($multi_results as $mr): ?>
                        <tr>
                            <td><?php echo esc_html($mr['user_id']); ?></td>
                            <td><?php echo esc_html($mr['email']); ?></td>
                            <td><?php echo esc_html($mr['name']); ?></td>
                            <td><code><?php echo esc_html($mr['stored_uuid']); ?></code></td>
                            <td><code><?php echo esc_html(implode(', ', $mr['wicket_uuids'])); ?></code></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                <h3 style="color:#d63638;"><?php printf(esc_html__('API Errors (%d)', 'wicket-integration'), count($errors)); ?></h3>
                <table class="widefat striped" style="margin-bottom:20px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('WP User ID', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Email', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Error', 'wicket-integration'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($errors as $err): ?>
                        <tr>
                            <td><?php echo esc_html($err['user_id']); ?></td>
                            <td><?php echo esc_html($err['email']); ?></td>
                            <td><?php echo esc_html($err['error']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- Repair controls -->
            <?php if (!empty($mismatches) && $has_results): ?>
            <div class="card" style="max-width:1100px; margin-top:20px;">
                <h2><?php esc_html_e('Step 2: Repair Mismatches', 'wicket-integration'); ?></h2>
                <p><?php printf(esc_html__('This will update the UUID for %d users and re-sync their profile data from Wicket.', 'wicket-integration'), count($mismatches)); ?></p>

                <div id="uuid-repair-controls">
                    <?php if (!$repair_in_progress): ?>
                        <button type="button" id="uuid-start-repair-btn" class="button button-primary" style="background:#d63638; border-color:#d63638;">
                            <?php printf(esc_html__('Repair All (%d users)', 'wicket-integration'), count($mismatches)); ?>
                        </button>
                    <?php else: ?>
                        <span class="description" style="color:#d63638;"><?php esc_html_e('Repair in progress…', 'wicket-integration'); ?></span>
                    <?php endif; ?>
                </div>

                <!-- Repair progress -->
                <div id="uuid-repair-progress" style="display:none; margin-top:20px;">
                    <div style="background:#f0f0f0; border-radius:3px; height:20px; position:relative;">
                        <div id="uuid-repair-bar" style="background:#00a32a; height:100%; border-radius:3px; width:0%; transition:width .3s;"></div>
                        <div id="uuid-repair-pct" style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); font-size:12px; font-weight:bold;">0%</div>
                    </div>
                    <div id="uuid-repair-stats" style="margin-top:10px; font-size:14px;"></div>
                    <div id="uuid-repair-log" style="margin-top:15px; max-height:200px; overflow-y:auto; background:#f9f9f9; padding:10px; border:1px solid #ddd; font-family:monospace; font-size:12px;"></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Resolve duplicates controls -->
            <?php
            $resolve_in_progress = get_option('wicket_uuid_resolve_in_progress', false);
            // Count blank-email users with UUID display_names
            $blank_email_candidates = 0;
            if (!empty($multi_results)) {
                foreach ($multi_results as $mr) {
                    if (empty(trim($mr['email'] ?? '')) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($mr['name'] ?? ''))) {
                        $blank_email_candidates++;
                    }
                }
            }
            ?>
            <?php if ($blank_email_candidates > 0 && $has_results): ?>
            <div class="card" style="max-width:1100px; margin-top:20px;">
                <h2><?php esc_html_e('Step 3: Resolve Blank-Email Duplicates', 'wicket-integration'); ?></h2>
                <p><?php printf(
                    esc_html__('%d users have no email and their username is a UUID (legacy import). This will verify each UUID in Wicket and fix the stored value if it was overwritten by a bad blank-email lookup.', 'wicket-integration'),
                    $blank_email_candidates
                ); ?></p>

                <div id="uuid-resolve-controls">
                    <?php if (!$resolve_in_progress): ?>
                        <button type="button" id="uuid-start-resolve-btn" class="button button-primary" style="background:#dba617; border-color:#dba617;">
                            <?php printf(esc_html__('Resolve Duplicates (%d users)', 'wicket-integration'), $blank_email_candidates); ?>
                        </button>
                    <?php else: ?>
                        <span class="description" style="color:#dba617;"><?php esc_html_e('Resolve in progress…', 'wicket-integration'); ?></span>
                    <?php endif; ?>
                </div>

                <!-- Resolve progress -->
                <div id="uuid-resolve-progress" style="display:none; margin-top:20px;">
                    <div style="background:#f0f0f0; border-radius:3px; height:20px; position:relative;">
                        <div id="uuid-resolve-bar" style="background:#dba617; height:100%; border-radius:3px; width:0%; transition:width .3s;"></div>
                        <div id="uuid-resolve-pct" style="position:absolute; top:50%; left:50%; transform:translate(-50%,-50%); font-size:12px; font-weight:bold;">0%</div>
                    </div>
                    <div id="uuid-resolve-stats" style="margin-top:10px; font-size:14px;"></div>
                    <div id="uuid-resolve-log" style="margin-top:15px; max-height:200px; overflow-y:auto; background:#f9f9f9; padding:10px; border:1px solid #ddd; font-family:monospace; font-size:12px;"></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <style>
        .card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:20px; margin:20px 0; }
        .sync-success { color:#00a32a; }
        .sync-error { color:#d63638; }
        .sync-warning { color:#dba617; }
        .sync-info { color:#333; }
        </style>
        <?php
    }

    // ------------------------------------------------------------------
    // Inline JavaScript
    // ------------------------------------------------------------------

    private function get_inline_js() {
        return <<<'JS'
jQuery(document).ready(function($) {
    var scanInterval, scanInProgress = false;
    var repairInterval, repairInProgress = false;

    // ---- SCAN ----

    $(document).on('click', '#uuid-start-scan-btn', function(e) {
        e.preventDefault();
        if (!confirm('This will scan all users with a Wicket UUID for mismatches. Continue?')) return;
        startScan();
    });

    $(document).on('click', '#uuid-cancel-scan-btn', function(e) {
        e.preventDefault();
        if (!confirm('Cancel the scan?')) return;
        $.post(wicket_uuid_repair.ajax_url, { action: 'uuid_repair_cancel', nonce: wicket_uuid_repair.nonce }, function() {
            scanInProgress = false;
            clearInterval(scanInterval);
            addLog('scan', 'Scan cancelled.', 'warning');
            setTimeout(function(){ location.reload(); }, 1000);
        });
    });

    function startScan() {
        scanInProgress = true;
        $('#uuid-scan-progress').show();
        $('#uuid-start-scan-btn').prop('disabled', true).text('Scanning…');
        addLog('scan', 'Starting scan…', 'info');

        $.post(wicket_uuid_repair.ajax_url, { action: 'uuid_repair_start_scan', nonce: wicket_uuid_repair.nonce }, function(resp) {
            if (resp.success) {
                addLog('scan', 'Scan started — ' + resp.data.total_users + ' users to check.', 'info');
                scanInterval = setInterval(checkScanStatus, 2000);
                setTimeout(processScanBatch, 500);
            } else {
                addLog('scan', 'Failed to start scan: ' + (resp.data && resp.data.message || 'Unknown error'), 'error');
                resetScanUI();
            }
        }).fail(function(xhr, status, err) {
            addLog('scan', 'AJAX error: ' + err, 'error');
            resetScanUI();
        });
    }

    function processScanBatch() {
        if (!scanInProgress) return;
        $.post(wicket_uuid_repair.ajax_url, { action: 'uuid_repair_process_batch', nonce: wicket_uuid_repair.nonce }, function(resp) {
            if (resp.success) {
                var d = resp.data;
                var pct = d.total > 0 ? (d.processed / d.total) * 100 : 0;
                updateScanProgress(pct, d);
                if (d.completed) {
                    scanInProgress = false;
                    clearInterval(scanInterval);
                    addLog('scan', 'Scan complete! ' + d.mismatch_count + ' mismatches found.', d.mismatch_count > 0 ? 'error' : 'success');
                    setTimeout(function(){ location.reload(); }, 2000);
                } else {
                    setTimeout(processScanBatch, 500);
                }
            } else {
                addLog('scan', 'Batch error: ' + (resp.data && resp.data.message || 'Unknown'), 'error');
                resetScanUI();
            }
        }).fail(function(xhr, status, err) {
            addLog('scan', 'AJAX error: ' + err, 'error');
            resetScanUI();
        });
    }

    function checkScanStatus() {
        $.post(wicket_uuid_repair.ajax_url, { action: 'uuid_repair_get_status', nonce: wicket_uuid_repair.nonce }, function(resp) {
            if (resp.success) {
                var d = resp.data;
                var pct = d.total > 0 ? (d.processed / d.total) * 100 : 0;
                updateScanProgress(pct, d);
            }
        });
    }

    function updateScanProgress(pct, d) {
        $('#uuid-scan-bar').css('width', pct + '%');
        $('#uuid-scan-pct').text(Math.round(pct) + '%');
        $('#uuid-scan-stats').html(
            'Processed: <strong>' + d.processed + '</strong> / <strong>' + d.total + '</strong> | ' +
            'Matches: <span class="sync-success">' + (d.matches || 0) + '</span> | ' +
            'Mismatches: <span class="sync-error">' + (d.mismatch_count || 0) + '</span> | ' +
            'Not Found: <span class="sync-warning">' + (d.not_found_count || 0) + '</span>'
        );
    }

    function resetScanUI() {
        clearInterval(scanInterval);
        scanInProgress = false;
        $('#uuid-start-scan-btn').prop('disabled', false).text('Start Scan');
    }

    // ---- REPAIR ----

    $(document).on('click', '#uuid-start-repair-btn', function(e) {
        e.preventDefault();
        if (!confirm('This will overwrite UUID meta for all mismatched users and re-sync their profiles. This cannot be undone. Continue?')) return;
        startRepair();
    });

    function startRepair() {
        repairInProgress = true;
        $('#uuid-repair-progress').show();
        $('#uuid-start-repair-btn').prop('disabled', true).text('Repairing…');
        addLog('repair', 'Starting repair…', 'info');

        $.post(wicket_uuid_repair.ajax_url, { action: 'uuid_repair_start_repair', nonce: wicket_uuid_repair.nonce }, function(resp) {
            if (resp.success) {
                addLog('repair', 'Repair started — ' + resp.data.total + ' users to fix.', 'info');
                repairInterval = setInterval(checkRepairStatus, 2000);
                setTimeout(processRepairBatch, 500);
            } else {
                addLog('repair', 'Failed: ' + (resp.data && resp.data.message || 'Unknown'), 'error');
                resetRepairUI();
            }
        }).fail(function(xhr, status, err) {
            addLog('repair', 'AJAX error: ' + err, 'error');
            resetRepairUI();
        });
    }

    function processRepairBatch() {
        if (!repairInProgress) return;
        $.post(wicket_uuid_repair.ajax_url, { action: 'uuid_repair_process_repair_batch', nonce: wicket_uuid_repair.nonce }, function(resp) {
            if (resp.success) {
                var d = resp.data;
                var pct = d.total > 0 ? (d.processed / d.total) * 100 : 0;
                updateRepairProgress(pct, d);
                if (d.completed) {
                    repairInProgress = false;
                    clearInterval(repairInterval);
                    addLog('repair', 'Repair complete! ' + d.success + ' fixed, ' + d.errors + ' errors.', d.errors > 0 ? 'warning' : 'success');
                    setTimeout(function(){ location.reload(); }, 3000);
                } else {
                    setTimeout(processRepairBatch, 500);
                }
            } else {
                addLog('repair', 'Batch error: ' + (resp.data && resp.data.message || 'Unknown'), 'error');
                resetRepairUI();
            }
        }).fail(function(xhr, status, err) {
            addLog('repair', 'AJAX error: ' + err, 'error');
            resetRepairUI();
        });
    }

    function checkRepairStatus() {
        $.post(wicket_uuid_repair.ajax_url, { action: 'uuid_repair_get_repair_status', nonce: wicket_uuid_repair.nonce }, function(resp) {
            if (resp.success) {
                var d = resp.data;
                var pct = d.total > 0 ? (d.processed / d.total) * 100 : 0;
                updateRepairProgress(pct, d);
            }
        });
    }

    function updateRepairProgress(pct, d) {
        $('#uuid-repair-bar').css('width', pct + '%');
        $('#uuid-repair-pct').text(Math.round(pct) + '%');
        $('#uuid-repair-stats').html(
            'Processed: <strong>' + d.processed + '</strong> / <strong>' + d.total + '</strong> | ' +
            'Success: <span class="sync-success">' + d.success + '</span> | ' +
            'Errors: <span class="sync-error">' + d.errors + '</span>'
        );
    }

    function resetRepairUI() {
        clearInterval(repairInterval);
        repairInProgress = false;
        $('#uuid-start-repair-btn').prop('disabled', false).text('Repair All');
    }

    // ---- RESOLVE DUPLICATES ----

    var resolveInterval, resolveInProgress = false;

    $(document).on('click', '#uuid-start-resolve-btn', function(e) {
        e.preventDefault();
        if (!confirm('This will verify each blank-email user\'s display_name UUID in Wicket and fix mismatches. Continue?')) return;
        startResolve();
    });

    function startResolve() {
        resolveInProgress = true;
        $('#uuid-resolve-progress').show();
        $('#uuid-start-resolve-btn').prop('disabled', true).text('Resolving…');
        addLog('resolve', 'Starting resolve…', 'info');

        $.post(wicket_uuid_repair.ajax_url, { action: 'uuid_repair_start_resolve', nonce: wicket_uuid_repair.nonce }, function(resp) {
            if (resp.success) {
                addLog('resolve', 'Resolve started — ' + resp.data.total + ' users to check.', 'info');
                resolveInterval = setInterval(checkResolveStatus, 2000);
                setTimeout(processResolveBatch, 500);
            } else {
                addLog('resolve', 'Failed: ' + (resp.data && resp.data.message || 'Unknown'), 'error');
                resetResolveUI();
            }
        }).fail(function(xhr, status, err) {
            addLog('resolve', 'AJAX error: ' + err, 'error');
            resetResolveUI();
        });
    }

    function processResolveBatch() {
        if (!resolveInProgress) return;
        $.post(wicket_uuid_repair.ajax_url, { action: 'uuid_repair_process_resolve_batch', nonce: wicket_uuid_repair.nonce }, function(resp) {
            if (resp.success) {
                var d = resp.data;
                var pct = d.total > 0 ? (d.processed / d.total) * 100 : 0;
                updateResolveProgress(pct, d);
                if (d.completed) {
                    resolveInProgress = false;
                    clearInterval(resolveInterval);
                    addLog('resolve', 'Resolve complete! ' + d.repaired + ' repaired, ' + d.already_ok + ' already OK, ' + d.invalid + ' invalid.', d.repaired > 0 ? 'success' : 'info');
                    setTimeout(function(){ location.reload(); }, 3000);
                } else {
                    setTimeout(processResolveBatch, 500);
                }
            } else {
                addLog('resolve', 'Batch error: ' + (resp.data && resp.data.message || 'Unknown'), 'error');
                resetResolveUI();
            }
        }).fail(function(xhr, status, err) {
            addLog('resolve', 'AJAX error: ' + err, 'error');
            resetResolveUI();
        });
    }

    function checkResolveStatus() {
        $.post(wicket_uuid_repair.ajax_url, { action: 'uuid_repair_get_resolve_status', nonce: wicket_uuid_repair.nonce }, function(resp) {
            if (resp.success) {
                var d = resp.data;
                var pct = d.total > 0 ? (d.processed / d.total) * 100 : 0;
                updateResolveProgress(pct, d);
            }
        });
    }

    function updateResolveProgress(pct, d) {
        $('#uuid-resolve-bar').css('width', pct + '%');
        $('#uuid-resolve-pct').text(Math.round(pct) + '%');
        $('#uuid-resolve-stats').html(
            'Processed: <strong>' + d.processed + '</strong> / <strong>' + d.total + '</strong> | ' +
            'Repaired: <span class="sync-success">' + d.repaired + '</span> | ' +
            'Already OK: <span class="sync-info">' + d.already_ok + '</span> | ' +
            'Invalid: <span class="sync-warning">' + d.invalid + '</span>'
        );
    }

    function resetResolveUI() {
        clearInterval(resolveInterval);
        resolveInProgress = false;
        $('#uuid-start-resolve-btn').prop('disabled', false).text('Resolve Duplicates');
    }

    // ---- Shared log helper ----

    function addLog(target, message, type) {
        var cls = 'sync-' + (type || 'info');
        var ts = new Date().toLocaleTimeString();
        var elMap = { 'repair': '#uuid-repair-log', 'resolve': '#uuid-resolve-log' };
        var el = elMap[target] || '#uuid-scan-log';
        $(el).append('<div class="' + cls + '">[' + ts + '] ' + message + '</div>');
        $(el).scrollTop($(el)[0].scrollHeight);
    }
});
JS;
    }
}

// Initialize
new Wicket_UUID_Repair();
