<?php
/**
 * Wicket Memberships Handler
 * 
 * Manages memberships synchronization between Wicket API and WordPress.
 * Creates custom table, handles sync on login, and provides helper functions.
 * 
 * @package MyIES_Integration
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_Memberships {
    
    private static $instance = null;
    private $table_name;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __clone() {}
    public function __wakeup() {
        throw new \Exception('Cannot unserialize singleton');
    }

    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wicket_user_memberships';
        $this->init_hooks();
    }
    
    private function init_hooks() {
        add_action('wp_login', array($this, 'sync_user_memberships_on_login'), 20, 2);
        add_action('user_register', array($this, 'sync_user_memberships_on_registration'), 20, 1);
        add_action('wp_ajax_wicket_sync_user_memberships', array($this, 'ajax_sync_user_memberships'));
        add_action('wp_ajax_wicket_get_user_memberships', array($this, 'ajax_get_user_memberships'));
        add_action('wicket_acf_sync_settings_after_form', array($this, 'add_memberships_admin_section'), 25);
    }
    
    /**
     * Create database table with status column
     */
    public function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        error_log('[WICKET MEMBERSHIPS] Creating table: ' . $this->table_name);
        
        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            wp_user_id bigint(20) UNSIGNED NOT NULL,
            person_uuid varchar(36) NOT NULL,
            membership_uuid varchar(36) NOT NULL,
            membership_type enum('person','organization') DEFAULT 'person',
            membership_tier_uuid varchar(36) DEFAULT NULL,
            membership_tier_name varchar(255) DEFAULT NULL,
            membership_tier_type varchar(50) DEFAULT NULL,
            organization_uuid varchar(36) DEFAULT NULL,
            organization_name varchar(255) DEFAULT NULL,
            organization_membership_uuid varchar(36) DEFAULT NULL,
            starts_at datetime DEFAULT NULL,
            ends_at datetime DEFAULT NULL,
            status varchar(20) DEFAULT 'Inactive',
            max_assignments int(11) DEFAULT NULL,
            active_assignments_count int(11) DEFAULT 0,
            unlimited_assignments tinyint(1) DEFAULT 0,
            synced_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY membership_uuid (membership_uuid),
            KEY wp_user_id (wp_user_id),
            KEY person_uuid (person_uuid),
            KEY membership_type (membership_type),
            KEY status (status),
            KEY organization_uuid (organization_uuid),
            KEY ends_at (ends_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->table_name)) === $this->table_name;
        error_log('[WICKET MEMBERSHIPS] Table exists: ' . ($table_exists ? 'YES' : 'NO'));
        
        return $table_exists;
    }
    
    public function table_exists() {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->table_name)) === $this->table_name;
    }
    
    private function ensure_table() {
        if (!$this->table_exists()) {
            $this->create_table();
        }
    }
    
    // =========================================================================
    // SYNC FUNCTIONS
    // =========================================================================
    
    public function sync_user_memberships_on_login($user_login, $user) {
        if (!$user || !isset($user->ID)) {
            return;
        }
        $this->sync_user_memberships($user->ID);
    }
    
    public function sync_user_memberships_on_registration($user_id) {
        $this->sync_user_memberships($user_id);
    }
    
    public function sync_user_memberships($user_id) {
        $this->ensure_table();
        
        $person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);
        if (empty($person_uuid)) {
            $person_uuid = get_user_meta($user_id, 'wicket_uuid', true);
        }
        
        if (empty($person_uuid)) {
            error_log('[WICKET MEMBERSHIPS] No person_uuid for user ' . $user_id);
            return false;
        }
        
        error_log('[WICKET MEMBERSHIPS] Syncing memberships for user ' . $user_id);
        
        $api = wicket_api();
        $token = $api->generate_jwt_token();
        
        if (is_wp_error($token)) {
            error_log('[WICKET MEMBERSHIPS] Failed to generate JWT: ' . $token->get_error_message());
            return false;
        }
        
        $stats = array('person_memberships' => 0, 'org_memberships' => 0, 'errors' => 0);

        global $wpdb;

        // Fetch new data first before deleting old records
        // This prevents data loss if API calls fail
        $new_person_memberships = array();
        $new_org_assignments = array();

        // Fetch person memberships
        $person_memberships = $this->fetch_person_memberships($person_uuid, $token);
        if (!is_wp_error($person_memberships) && !empty($person_memberships)) {
            $new_person_memberships = $person_memberships;
        }

        // Fetch org membership assignments
        $org_assignments = $this->fetch_org_membership_assignments($person_uuid, $token);
        if (!is_wp_error($org_assignments) && !empty($org_assignments)) {
            $new_org_assignments = $org_assignments;
        }

        // Only delete old records after successful API fetches
        $wpdb->delete($this->table_name, array('wp_user_id' => $user_id));

        // Save person memberships
        foreach ($new_person_memberships as $membership) {
            if ($this->save_membership($user_id, $person_uuid, $membership, 'person', $token)) {
                $stats['person_memberships']++;
            } else {
                $stats['errors']++;
            }
        }

        // Save org membership assignments
        foreach ($new_org_assignments as $assignment) {
            if ($this->save_membership($user_id, $person_uuid, $assignment, 'organization', $token)) {
                $stats['org_memberships']++;
            } else {
                $stats['errors']++;
            }
        }
        
        update_user_meta($user_id, 'wicket_memberships_last_sync', current_time('mysql'));
        
        error_log('[WICKET MEMBERSHIPS] Sync complete: Person=' . $stats['person_memberships'] . ', Org=' . $stats['org_memberships']);
        
        return $stats;
    }
    
    private function fetch_person_memberships($person_uuid, $token) {
        $api = wicket_api();
        $endpoint = $api->get_api_url() . "/people/{$person_uuid}/membership_entries?include=membership";
        
        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            error_log('[WICKET MEMBERSHIPS] API error: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200) {
            return new WP_Error('api_error', "API returned status {$status_code}");
        }
        
        $memberships = $body['data'] ?? array();
        $included = $body['included'] ?? array();
        
        // Attach tier data
        foreach ($memberships as &$membership) {
            $tier_id = $membership['relationships']['membership']['data']['id'] ?? null;
            if ($tier_id) {
                foreach ($included as $inc) {
                    if ($inc['type'] === 'memberships' && $inc['id'] === $tier_id) {
                        $membership['_tier'] = $inc;
                        break;
                    }
                }
            }
        }
        
        // Filter out org membership assignments
        return array_values(array_filter($memberships, function($m) {
            return empty($m['relationships']['organization_membership']['data']);
        }));
    }
    
    private function fetch_org_membership_assignments($person_uuid, $token) {
        $api = wicket_api();
        $endpoint = $api->get_api_url() . "/people/{$person_uuid}/membership_entries?include=membership,organization_membership";
        
        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if (wp_remote_retrieve_response_code($response) !== 200) {
            return new WP_Error('api_error', 'API error');
        }
        
        $memberships = $body['data'] ?? array();
        $included = $body['included'] ?? array();
        
        $included_lookup = array();
        foreach ($included as $inc) {
            $included_lookup[$inc['type'] . ':' . $inc['id']] = $inc;
        }
        
        $org_assignments = array();
        foreach ($memberships as $membership) {
            $org_membership_data = $membership['relationships']['organization_membership']['data'] ?? null;
            
            if (!empty($org_membership_data)) {
                $org_mem_key = 'organization_memberships:' . $org_membership_data['id'];
                if (isset($included_lookup[$org_mem_key])) {
                    $membership['_org_membership'] = $included_lookup[$org_mem_key];
                    
                    $tier_id = $membership['relationships']['membership']['data']['id'] ?? null;
                    if ($tier_id && isset($included_lookup['memberships:' . $tier_id])) {
                        $membership['_tier'] = $included_lookup['memberships:' . $tier_id];
                    }
                    
                    $org_rel = $membership['_org_membership']['relationships']['organization']['data'] ?? null;
                    if ($org_rel) {
                        $membership['_organization'] = $this->get_organization_data($org_rel['id'], $token);
                    }
                }
                
                $org_assignments[] = $membership;
            }
        }
        
        return $org_assignments;
    }
    
    private function get_organization_data($org_uuid, $token) {
        global $wpdb;
        
        $orgs_table = $wpdb->prefix . 'wicket_organizations';
        $local_org = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$orgs_table} WHERE organization_uuid = %s",
            $org_uuid
        ), ARRAY_A);
        
        if ($local_org) {
            return array(
                'id' => $org_uuid,
                'attributes' => array('legal_name' => $local_org['organization_name'])
            );
        }
        
        return $this->fetch_organization($org_uuid, $token);
    }
    
    private function fetch_organization($org_uuid, $token) {
        $api = wicket_api();
        $endpoint = $api->get_api_url() . "/organizations/{$org_uuid}";
        
        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json'
            ),
            'timeout' => 15
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['data'] ?? null;
    }
    
    /**
     * Save membership - now saves status from Wicket
     */
    private function save_membership($user_id, $person_uuid, $membership, $type, $token) {
        global $wpdb;
        
        $membership_uuid = $membership['id'] ?? null;
        if (empty($membership_uuid)) {
            return false;
        }
        
        $attributes = $membership['attributes'] ?? array();
        $tier = $membership['_tier'] ?? array();
        $tier_attrs = $tier['attributes'] ?? array();
        
        // Status comes directly from Wicket API
        $status = $attributes['status'] ?? 'Inactive';
        
        $data = array(
            'wp_user_id' => $user_id,
            'person_uuid' => $person_uuid,
            'membership_uuid' => $membership_uuid,
            'membership_type' => $type,
            'membership_tier_uuid' => $tier['id'] ?? null,
            'membership_tier_name' => $tier_attrs['name'] ?? $tier_attrs['name_en'] ?? null,
            'membership_tier_type' => $tier_attrs['type'] ?? null,
            'starts_at' => !empty($attributes['starts_at']) ? date('Y-m-d H:i:s', strtotime($attributes['starts_at'])) : null,
            'ends_at' => !empty($attributes['ends_at']) ? date('Y-m-d H:i:s', strtotime($attributes['ends_at'])) : null,
            'status' => $status,
            'synced_at' => current_time('mysql')
        );
        
        if ($type === 'organization') {
            $org_membership = $membership['_org_membership'] ?? array();
            $org_mem_attrs = $org_membership['attributes'] ?? array();
            $organization = $membership['_organization'] ?? array();
            $org_attrs = $organization['attributes'] ?? array();
            
            $data['organization_membership_uuid'] = $org_membership['id'] ?? null;
            $data['organization_uuid'] = $organization['id'] ?? null;
            $data['organization_name'] = $org_attrs['legal_name'] ?? null;
            $data['max_assignments'] = $org_mem_attrs['max_assignments'] ?? null;
            $data['active_assignments_count'] = $org_mem_attrs['active_assignments_count'] ?? 0;
            $data['unlimited_assignments'] = !empty($org_mem_attrs['unlimited_assignments']) ? 1 : 0;
        }
        
        $result = $wpdb->insert($this->table_name, $data);
        
        if ($result === false) {
            error_log('[WICKET MEMBERSHIPS] DB error: ' . $wpdb->last_error);
            return false;
        }
        
        return true;
    }
    
    // =========================================================================
    // GETTER FUNCTIONS
    // =========================================================================
    
    public function get_user_memberships($user_id = null, $active_only = false) {
        global $wpdb;
        
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return array();
        }
        
        $this->ensure_table();
        
        $sql = "SELECT * FROM {$this->table_name} WHERE wp_user_id = %d";
        
        if ($active_only) {
            $sql .= " AND status = 'Active'";
        }
        
        // Order: Active first, then by end date descending
        $sql .= " ORDER BY FIELD(status, 'Active', 'Inactive') ASC, ends_at DESC";
        
        $memberships = $wpdb->get_results($wpdb->prepare($sql, $user_id), ARRAY_A);
        
        if (empty($memberships)) {
            return array();
        }
        
        foreach ($memberships as &$m) {
            $m['is_active'] = ($m['status'] === 'Active');
            $m['expires_formatted'] = !empty($m['ends_at']) ? date_i18n('F j, Y', strtotime($m['ends_at'])) : 'No expiration';
            $m['starts_formatted'] = !empty($m['starts_at']) ? date_i18n('F j, Y', strtotime($m['starts_at'])) : '—';
            
            if ($m['membership_type'] === 'organization') {
                if ($m['unlimited_assignments']) {
                    $m['slots_display'] = 'Unlimited';
                } elseif ($m['max_assignments'] !== null) {
                    $m['slots_display'] = $m['active_assignments_count'] . '/' . $m['max_assignments'];
                } else {
                    $m['slots_display'] = '—';
                }
            }
        }
        
        return $memberships;
    }
    
    public function get_user_person_memberships($user_id = null, $active_only = false) {
        return array_values(array_filter($this->get_user_memberships($user_id, $active_only), function($m) {
            return $m['membership_type'] === 'person';
        }));
    }
    
    public function get_user_org_memberships($user_id = null, $active_only = false) {
        return array_values(array_filter($this->get_user_memberships($user_id, $active_only), function($m) {
            return $m['membership_type'] === 'organization';
        }));
    }
    
    public function get_active_person_membership($user_id = null) {
        $memberships = $this->get_user_person_memberships($user_id, true);
        return !empty($memberships) ? $memberships[0] : null;
    }
    
    public function get_active_org_membership($user_id = null) {
        $memberships = $this->get_user_org_memberships($user_id, true);
        return !empty($memberships) ? $memberships[0] : null;
    }
    
    public function user_has_active_membership($user_id = null) {
        return !empty($this->get_user_memberships($user_id, true));
    }
    
    public function get_template_data($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return array(
                'logged_in' => false,
                'has_memberships' => false,
                'person_memberships' => array(),
                'org_memberships' => array(),
                'person_active' => null,
                'org_active' => null,
                'has_active' => false
            );
        }
        
        return array(
            'logged_in' => true,
            'has_memberships' => !empty($this->get_user_memberships($user_id)),
            'person_memberships' => $this->get_user_person_memberships($user_id),
            'org_memberships' => $this->get_user_org_memberships($user_id),
            'person_active' => $this->get_active_person_membership($user_id),
            'org_active' => $this->get_active_org_membership($user_id),
            'has_active' => $this->user_has_active_membership($user_id)
        );
    }
    
    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================
    
    public function ajax_sync_user_memberships() {
        check_ajax_referer('wicket_memberships_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }
        
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : get_current_user_id();
        $result = $this->sync_user_memberships($user_id);
        
        if ($result === false) {
            wp_send_json_error(array('message' => 'Sync failed'));
        }
        
        wp_send_json_success(array('message' => 'Synced', 'stats' => $result));
    }
    
    public function ajax_get_user_memberships() {
        check_ajax_referer('wicket_memberships_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }
        
        wp_send_json_success($this->get_template_data());
    }
    
    // =========================================================================
    // ADMIN
    // =========================================================================
    
    public function add_memberships_admin_section() {
        global $wpdb;
        
        $table_exists = $this->table_exists();
        $total = $table_exists ? $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}") : 0;
        $active = $table_exists ? $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} WHERE status = 'Active'") : 0;
        ?>
        <div class="wrap" style="margin-top: 30px;">
            <h2>User Memberships Sync</h2>
            <div class="card" style="border-left: 4px solid <?php echo $table_exists ? '#46b450' : '#dc3232'; ?>;">
                <h3>Database Status</h3>
                <table class="widefat" style="max-width: 400px;">
                    <tr><td><code><?php echo $this->table_name; ?></code></td>
                        <td><?php echo $table_exists ? '<span style="color:#46b450">✓ Exists</span>' : '<span style="color:#dc3232">✗ Missing</span>'; ?></td></tr>
                    <tr><td>Total Records</td><td><?php echo number_format($total); ?></td></tr>
                    <tr><td>Active</td><td><?php echo number_format($active); ?></td></tr>
                </table>
                
                <h4 style="margin-top:20px;">Bricks Dynamic Tags</h4>
                <pre style="background:#f5f5f5;padding:10px;font-size:11px;">{wicket_membership:person_tier_name}
{wicket_membership:person_status}
{wicket_membership:person_expires}
{wicket_membership:org_name}
{wicket_membership:org_tier_name}
{wicket_membership:org_slots}
{wicket_membership:has_person_active}
{wicket_membership:has_org_active}</pre>
                
                <h4>Shortcodes</h4>
                <pre style="background:#f5f5f5;padding:10px;font-size:11px;">[wicket_membership_history type="person"]
[wicket_membership_history type="organization"]</pre>
            </div>
        </div>
        <?php
    }
}

// =============================================================================
// GLOBAL FUNCTIONS
// =============================================================================

function wicket_memberships() {
    return Wicket_Memberships::get_instance();
}

function wicket_get_user_memberships($user_id = null, $active_only = false) {
    return wicket_memberships()->get_user_memberships($user_id, $active_only);
}

function wicket_get_user_person_memberships($user_id = null, $active_only = false) {
    return wicket_memberships()->get_user_person_memberships($user_id, $active_only);
}

function wicket_get_user_org_memberships($user_id = null, $active_only = false) {
    return wicket_memberships()->get_user_org_memberships($user_id, $active_only);
}

function wicket_user_has_active_membership($user_id = null) {
    return wicket_memberships()->user_has_active_membership($user_id);
}

function wicket_memberships_data($user_id = null) {
    return wicket_memberships()->get_template_data($user_id);
}

function wicket_sync_user_memberships($user_id) {
    return wicket_memberships()->sync_user_memberships($user_id);
}

/**
 * Get display name for sustaining/organizational membership tier
 *
 * Maps Wicket tier names to frontend display names:
 * - Contributor → Bronze
 * - Supporter → Silver
 * - Benefactor → Gold
 * - Ambassador → Platinum
 * - Champion → Diamond
 *
 * @param string $wicket_name The membership tier name from Wicket
 * @param string $membership_type Optional. 'person' or 'organization'. Default 'organization'.
 * @return string The display name for the frontend
 */
function wicket_get_display_membership_name($wicket_name, $membership_type = 'organization') {
    if (empty($wicket_name)) {
        return $wicket_name;
    }

    // Default mapping for sustaining (organizational) memberships
    $sustaining_name_map = array(
        'Contributor' => 'Bronze',
        'Supporter'   => 'Silver',
        'Benefactor'  => 'Gold',
        'Ambassador'  => 'Platinum',
        'Champion'    => 'Diamond',
    );

    /**
     * Filter the sustaining membership name mapping
     *
     * @param array  $sustaining_name_map Array of Wicket name => Display name mappings
     * @param string $membership_type     Either 'person' or 'organization'
     */
    $sustaining_name_map = apply_filters('myies_sustaining_membership_name_map', $sustaining_name_map, $membership_type);

    // Check if the Wicket name exists in the mapping
    $display_name = isset($sustaining_name_map[$wicket_name]) ? $sustaining_name_map[$wicket_name] : $wicket_name;

    /**
     * Filter the final display name for a membership tier
     *
     * @param string $display_name    The mapped display name
     * @param string $wicket_name     The original name from Wicket
     * @param string $membership_type Either 'person' or 'organization'
     */
    return apply_filters('myies_membership_display_name', $display_name, $wicket_name, $membership_type);
}

add_action('plugins_loaded', function() {
    wicket_memberships();
}, 15);