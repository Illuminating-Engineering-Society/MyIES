<?php
/**
 * Wicket Memberships Handler
 * 
 * Manages memberships synchronization between Wicket API and WordPress.
 * Creates custom table, handles sync on login, and provides helper functions.
 * 
 * Supports both:
 * - Person Memberships (individual memberships)
 * - Organization Memberships (org memberships with seat assignments)
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
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wicket_user_memberships';
        
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Create table on plugin activation
        register_activation_hook(WICKET_INTEGRATION_PLUGIN_FILE, array($this, 'create_table'));
        
        // Sync memberships on user login (after other syncs)
        add_action('wp_login', array($this, 'sync_user_memberships_on_login'), 20, 2);
        
        // Also sync after registration
        add_action('user_register', array($this, 'sync_user_memberships_on_registration'), 20, 1);
        
        // Admin AJAX handlers
        add_action('wp_ajax_wicket_sync_user_memberships', array($this, 'ajax_sync_user_memberships'));
        add_action('wp_ajax_wicket_get_user_memberships', array($this, 'ajax_get_user_memberships'));
        
        // Add admin section to settings page
        add_action('wicket_acf_sync_settings_after_form', array($this, 'add_memberships_admin_section'), 25);
    }
    
    /**
     * Create database table
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
            max_assignments int(11) DEFAULT NULL,
            active_assignments_count int(11) DEFAULT 0,
            unlimited_assignments tinyint(1) DEFAULT 0,
            synced_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY membership_uuid (membership_uuid),
            KEY wp_user_id (wp_user_id),
            KEY person_uuid (person_uuid),
            KEY membership_type (membership_type),
            KEY organization_uuid (organization_uuid),
            KEY ends_at (ends_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        
        error_log('[WICKET MEMBERSHIPS] dbDelta result: ' . print_r($result, true));
        
        // Verify table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        error_log('[WICKET MEMBERSHIPS] Table exists: ' . ($table_exists ? 'YES' : 'NO'));
        
        return $table_exists;
    }
    
    /**
     * Check if table exists
     */
    public function table_exists() {
        global $wpdb;
        return $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
    }
    
    /**
     * Ensure table exists before operations
     */
    private function ensure_table() {
        if (!$this->table_exists()) {
            error_log('[WICKET MEMBERSHIPS] Table missing, creating now...');
            $this->create_table();
        }
    }
    
    // =========================================================================
    // SYNC FUNCTIONS
    // =========================================================================
    
    /**
     * Sync user memberships on login
     */
    public function sync_user_memberships_on_login($user_login, $user) {
        if (!$user || !isset($user->ID)) {
            return;
        }
        
        $this->sync_user_memberships($user->ID);
    }
    
    /**
     * Sync user memberships on registration
     */
    public function sync_user_memberships_on_registration($user_id) {
        // Small delay to allow person creation to complete
        $this->sync_user_memberships($user_id);
    }
    
    /**
     * Main sync function for a user's memberships
     * 
     * @param int $user_id WordPress user ID
     * @return array|false Result array or false on failure
     */
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
        
        error_log('[WICKET MEMBERSHIPS] Syncing memberships for user ' . $user_id . ' (person: ' . $person_uuid . ')');
        
        $api = wicket_api();
        $token = $api->generate_jwt_token();
        
        if (is_wp_error($token)) {
            error_log('[WICKET MEMBERSHIPS] Failed to generate JWT: ' . $token->get_error_message());
            return false;
        }
        
        $stats = array(
            'person_memberships' => 0,
            'org_memberships' => 0,
            'errors' => 0
        );
        
        // Clear existing memberships for this user before syncing
        global $wpdb;
        $wpdb->delete($this->table_name, array('wp_user_id' => $user_id));
        
        // 1. Fetch Person Memberships (individual memberships)
        $person_memberships = $this->fetch_person_memberships($person_uuid, $token);
        if (!is_wp_error($person_memberships) && !empty($person_memberships)) {
            foreach ($person_memberships as $membership) {
                $result = $this->save_membership($user_id, $person_uuid, $membership, 'person', $token);
                if ($result) {
                    $stats['person_memberships']++;
                } else {
                    $stats['errors']++;
                }
            }
        }
        
        // 2. Fetch Organization Membership Assignments
        // These are person_memberships that have an organization_membership relationship
        $org_assignments = $this->fetch_org_membership_assignments($person_uuid, $token);
        if (!is_wp_error($org_assignments) && !empty($org_assignments)) {
            foreach ($org_assignments as $assignment) {
                $result = $this->save_membership($user_id, $person_uuid, $assignment, 'organization', $token);
                if ($result) {
                    $stats['org_memberships']++;
                } else {
                    $stats['errors']++;
                }
            }
        }
        
        // Update last sync timestamp
        update_user_meta($user_id, 'wicket_memberships_last_sync', current_time('mysql'));
        
        error_log('[WICKET MEMBERSHIPS] Sync complete for user ' . $user_id . ': ' . 
            'Person=' . $stats['person_memberships'] . ', Org=' . $stats['org_memberships'] . 
            ', Errors=' . $stats['errors']);
        
        return $stats;
    }
    
    /**
     * Fetch person's individual memberships from Wicket API
     * 
     * @param string $person_uuid Person UUID
     * @param string $token JWT token
     * @return array|WP_Error Memberships array or error
     */
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
            error_log('[WICKET MEMBERSHIPS] API error fetching person memberships: ' . $response->get_error_message());
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200) {
            error_log('[WICKET MEMBERSHIPS] API returned status ' . $status_code);
            return new WP_Error('api_error', "API returned status {$status_code}");
        }
        
        $memberships = $body['data'] ?? array();
        $included = $body['included'] ?? array();
        
        // Attach included membership tier data
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
        
        // Filter out organization membership assignments (those with organization_membership relationship)
        $individual_memberships = array_filter($memberships, function($m) {
            $org_membership_data = $m['relationships']['organization_membership']['data'] ?? null;
            return empty($org_membership_data);
        });
        
        return array_values($individual_memberships);
    }
    
    /**
     * Fetch person's organization membership assignments from Wicket API
     * 
     * These are person_memberships that have an organization_membership relationship,
     * meaning the person is assigned to an organization's membership.
     * 
     * @param string $person_uuid Person UUID
     * @param string $token JWT token
     * @return array|WP_Error Memberships array or error
     */
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
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200) {
            return new WP_Error('api_error', "API returned status {$status_code}");
        }
        
        $memberships = $body['data'] ?? array();
        $included = $body['included'] ?? array();
        
        // Build lookup for included resources
        $included_lookup = array();
        foreach ($included as $inc) {
            $key = $inc['type'] . ':' . $inc['id'];
            $included_lookup[$key] = $inc;
        }
        
        // Filter only organization membership assignments and attach data
        $org_assignments = array();
        foreach ($memberships as $membership) {
            $org_membership_data = $membership['relationships']['organization_membership']['data'] ?? null;
            
            if (!empty($org_membership_data)) {
                // Get organization_membership details
                $org_mem_key = 'organization_memberships:' . $org_membership_data['id'];
                if (isset($included_lookup[$org_mem_key])) {
                    $membership['_org_membership'] = $included_lookup[$org_mem_key];
                    
                    // Get membership tier
                    $tier_id = $membership['relationships']['membership']['data']['id'] ?? null;
                    if ($tier_id && isset($included_lookup['memberships:' . $tier_id])) {
                        $membership['_tier'] = $included_lookup['memberships:' . $tier_id];
                    }
                }
                
                $org_assignments[] = $membership;
            }
        }
        
        // For each org assignment, fetch the organization details
        foreach ($org_assignments as &$assignment) {
            $org_membership = $assignment['_org_membership'] ?? null;
            if ($org_membership) {
                $org_id = $org_membership['relationships']['organization']['data']['id'] ?? null;
                if ($org_id) {
                    // Try local cache first
                    $orgs = wicket_organizations();
                    $org = $orgs->get_organization($org_id);
                    
                    if ($org) {
                        $assignment['_organization'] = array(
                            'id' => $org_id,
                            'attributes' => array(
                                'legal_name' => $org['legal_name'],
                                'alternate_name' => $org['alternate_name']
                            )
                        );
                    } else {
                        // Fetch from API
                        $org_data = $this->fetch_organization($org_id, $token);
                        if ($org_data) {
                            $assignment['_organization'] = $org_data;
                        }
                    }
                }
            }
        }
        
        return $org_assignments;
    }
    
    /**
     * Fetch organization details from Wicket API
     */
    private function fetch_organization($org_uuid, $token) {
        $api = wicket_api();
        $endpoint = $api->get_api_url() . "/organizations/{$org_uuid}";
        
        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
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
     * Save membership to local database
     * 
     * @param int $user_id WordPress user ID
     * @param string $person_uuid Person UUID
     * @param array $membership Membership data from API
     * @param string $type 'person' or 'organization'
     * @param string $token JWT token (for additional API calls if needed)
     * @return bool Success
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
        
        // Base data
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
            'synced_at' => current_time('mysql')
        );
        
        // Organization membership specific data
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
        
        // Check if exists (shouldn't happen since we clear before sync, but just in case)
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE membership_uuid = %s",
            $membership_uuid
        ));
        
        if ($existing) {
            $result = $wpdb->update($this->table_name, $data, array('membership_uuid' => $membership_uuid));
        } else {
            $result = $wpdb->insert($this->table_name, $data);
        }
        
        if ($result === false) {
            error_log('[WICKET MEMBERSHIPS] DB error: ' . $wpdb->last_error);
            return false;
        }
        
        return true;
    }
    
    // =========================================================================
    // GETTER FUNCTIONS
    // =========================================================================
    
    /**
     * Get all memberships for a user
     * 
     * @param int|null $user_id User ID (defaults to current user)
     * @param bool $active_only Only return currently active memberships
     * @return array Memberships with computed status
     */
    public function get_user_memberships($user_id = null, $active_only = false) {
        global $wpdb;
        
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return array();
        }
        
        $this->ensure_table();
        
        $sql = "SELECT * FROM {$this->table_name} WHERE wp_user_id = %d ORDER BY membership_type ASC, ends_at DESC";
        $memberships = $wpdb->get_results($wpdb->prepare($sql, $user_id), ARRAY_A);
        
        if (empty($memberships)) {
            return array();
        }
        
        // Add computed status to each membership
        $now = current_time('mysql');
        foreach ($memberships as &$membership) {
            $membership['status'] = $this->compute_status($membership['starts_at'], $membership['ends_at'], $now);
            $membership['is_active'] = ($membership['status'] === 'Active');
            
            // Format dates for display
            if (!empty($membership['ends_at'])) {
                $membership['expires_formatted'] = date_i18n(get_option('date_format'), strtotime($membership['ends_at']));
            }
            if (!empty($membership['starts_at'])) {
                $membership['starts_formatted'] = date_i18n(get_option('date_format'), strtotime($membership['starts_at']));
            }
            
            // Calculate slots display for org memberships
            if ($membership['membership_type'] === 'organization' && $membership['max_assignments'] !== null) {
                $membership['slots_display'] = $membership['active_assignments_count'] . '/' . $membership['max_assignments'];
                $membership['slots_available'] = $membership['max_assignments'] - $membership['active_assignments_count'];
            }
        }
        
        // Filter active only if requested
        if ($active_only) {
            $memberships = array_filter($memberships, function($m) {
                return $m['is_active'];
            });
            $memberships = array_values($memberships);
        }
        
        return $memberships;
    }
    
    /**
     * Get only person (individual) memberships for a user
     * 
     * @param int|null $user_id User ID
     * @param bool $active_only Only active memberships
     * @return array
     */
    public function get_user_person_memberships($user_id = null, $active_only = false) {
        $all = $this->get_user_memberships($user_id, $active_only);
        return array_values(array_filter($all, function($m) {
            return $m['membership_type'] === 'person';
        }));
    }
    
    /**
     * Get only organization memberships for a user
     * 
     * @param int|null $user_id User ID
     * @param bool $active_only Only active memberships
     * @return array
     */
    public function get_user_org_memberships($user_id = null, $active_only = false) {
        $all = $this->get_user_memberships($user_id, $active_only);
        return array_values(array_filter($all, function($m) {
            return $m['membership_type'] === 'organization';
        }));
    }
    
    /**
     * Compute membership status based on dates
     * 
     * @param string|null $starts_at Start date
     * @param string|null $ends_at End date
     * @param string $now Current datetime
     * @return string 'Active', 'Inactive', 'Expired', 'Pending'
     */
    private function compute_status($starts_at, $ends_at, $now) {
        // If no dates, consider it active (open-ended)
        if (empty($starts_at) && empty($ends_at)) {
            return 'Active';
        }
        
        $now_ts = strtotime($now);
        
        // Check if not yet started
        if (!empty($starts_at)) {
            $start_ts = strtotime($starts_at);
            if ($now_ts < $start_ts) {
                return 'Pending';
            }
        }
        
        // Check if expired
        if (!empty($ends_at)) {
            $end_ts = strtotime($ends_at);
            if ($now_ts > $end_ts) {
                return 'Expired';
            }
        }
        
        return 'Active';
    }
    
    /**
     * Check if user has any active membership
     * 
     * @param int|null $user_id User ID
     * @return bool
     */
    public function user_has_active_membership($user_id = null) {
        $active = $this->get_user_memberships($user_id, true);
        return !empty($active);
    }
    
    /**
     * Check if user has a specific membership tier
     * 
     * @param string $tier_uuid Membership tier UUID
     * @param int|null $user_id User ID
     * @param bool $active_only Only check active memberships
     * @return bool
     */
    public function user_has_membership_tier($tier_uuid, $user_id = null, $active_only = true) {
        $memberships = $this->get_user_memberships($user_id, $active_only);
        foreach ($memberships as $m) {
            if ($m['membership_tier_uuid'] === $tier_uuid) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get template data for Bricks
     * 
     * @param int|null $user_id User ID
     * @return array All data needed for subscriptions page
     */
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
                'all_memberships' => array(),
                'has_active' => false,
                'last_sync' => null,
                'nonce' => wp_create_nonce('wicket_memberships_nonce'),
                'ajax_url' => admin_url('admin-ajax.php')
            );
        }
        
        $all = $this->get_user_memberships($user_id);
        $person = $this->get_user_person_memberships($user_id);
        $org = $this->get_user_org_memberships($user_id);
        
        return array(
            'logged_in' => true,
            'has_memberships' => !empty($all),
            'person_memberships' => $person,
            'org_memberships' => $org,
            'all_memberships' => $all,
            'has_active' => $this->user_has_active_membership($user_id),
            'last_sync' => get_user_meta($user_id, 'wicket_memberships_last_sync', true),
            'nonce' => wp_create_nonce('wicket_memberships_nonce'),
            'ajax_url' => admin_url('admin-ajax.php')
        );
    }
    
    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================
    
    /**
     * AJAX: Sync current user's memberships
     */
    public function ajax_sync_user_memberships() {
        check_ajax_referer('wicket_memberships_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }
        
        $user_id = get_current_user_id();
        $result = $this->sync_user_memberships($user_id);
        
        if ($result === false) {
            wp_send_json_error(array('message' => 'Sync failed. User may not have a Wicket account.'));
        }
        
        wp_send_json_success(array(
            'message' => 'Memberships synced successfully',
            'stats' => $result,
            'memberships' => $this->get_template_data($user_id)
        ));
    }
    
    /**
     * AJAX: Get current user's memberships
     */
    public function ajax_get_user_memberships() {
        check_ajax_referer('wicket_memberships_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }
        
        $user_id = get_current_user_id();
        $active_only = isset($_POST['active_only']) && $_POST['active_only'] === 'true';
        
        wp_send_json_success($this->get_template_data($user_id));
    }
    
    // =========================================================================
    // ADMIN SECTION
    // =========================================================================
    
    /**
     * Add memberships section to admin settings page
     */
    public function add_memberships_admin_section() {
        global $wpdb;
        
        $table_exists = $this->table_exists();
        $total_records = $table_exists ? $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}") : 0;
        $nonce = wp_create_nonce('wicket_memberships_admin');
        ?>
        
        <div class="wrap" style="margin-top: 30px;">
            <h2><?php _e('User Memberships Sync', 'wicket-integration'); ?></h2>
            
            <div class="card" style="<?php echo !$table_exists ? 'border-left: 4px solid #dc3232;' : 'border-left: 4px solid #46b450;'; ?>">
                <h3><?php _e('Database Status', 'wicket-integration'); ?></h3>
                
                <table class="widefat" style="max-width: 500px;">
                    <tr>
                        <td><code><?php echo esc_html($this->table_name); ?></code></td>
                        <td>
                            <?php if ($table_exists): ?>
                                <span style="color: #46b450;">✓ <?php _e('Exists', 'wicket-integration'); ?></span>
                                <span style="margin-left: 10px;">(<?php echo number_format($total_records); ?> <?php _e('records', 'wicket-integration'); ?>)</span>
                            <?php else: ?>
                                <span style="color: #dc3232;">✗ <?php _e('Missing', 'wicket-integration'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <?php if (!$table_exists): ?>
                    <p style="color: #dc3232; margin-top: 15px;">
                        <strong><?php _e('⚠ Table is missing! Deactivate and reactivate the plugin to create it.', 'wicket-integration'); ?></strong>
                    </p>
                <?php endif; ?>
                
                <h4 style="margin-top: 20px;"><?php _e('How it works', 'wicket-integration'); ?></h4>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li><?php _e('User memberships are synced automatically on login', 'wicket-integration'); ?></li>
                    <li><?php _e('Both individual and organization memberships are captured', 'wicket-integration'); ?></li>
                    <li><?php _e('Status (Active/Inactive/Expired) is computed dynamically from dates', 'wicket-integration'); ?></li>
                    <li><?php _e('Use the helper functions in Bricks templates to display membership data', 'wicket-integration'); ?></li>
                </ul>
                
                <h4 style="margin-top: 20px;"><?php _e('Available Functions for Bricks', 'wicket-integration'); ?></h4>
                <pre style="background: #f5f5f5; padding: 15px; border-radius: 4px; overflow-x: auto;">
// Get all memberships
$memberships = wicket_get_user_memberships();

// Get only individual memberships
$individual = wicket_get_user_person_memberships();

// Get only organization memberships  
$org = wicket_get_user_org_memberships();

// Check if user has active membership
if (wicket_user_has_active_membership()) { ... }

// Get all template data
$data = wicket_memberships_data();
                </pre>
            </div>
        </div>
        <?php
    }
}

// =============================================================================
// GLOBAL HELPER FUNCTIONS
// =============================================================================

/**
 * Get Memberships instance
 */
function wicket_memberships() {
    return Wicket_Memberships::get_instance();
}

/**
 * Get user's memberships
 * 
 * @param int|null $user_id User ID (defaults to current user)
 * @param bool $active_only Only return active memberships
 * @return array
 */
function wicket_get_user_memberships($user_id = null, $active_only = false) {
    return wicket_memberships()->get_user_memberships($user_id, $active_only);
}

/**
 * Get user's individual (person) memberships
 * 
 * @param int|null $user_id
 * @param bool $active_only
 * @return array
 */
function wicket_get_user_person_memberships($user_id = null, $active_only = false) {
    return wicket_memberships()->get_user_person_memberships($user_id, $active_only);
}

/**
 * Get user's organization memberships
 * 
 * @param int|null $user_id
 * @param bool $active_only
 * @return array
 */
function wicket_get_user_org_memberships($user_id = null, $active_only = false) {
    return wicket_memberships()->get_user_org_memberships($user_id, $active_only);
}

/**
 * Check if user has any active membership
 * 
 * @param int|null $user_id
 * @return bool
 */
function wicket_user_has_active_membership($user_id = null) {
    return wicket_memberships()->user_has_active_membership($user_id);
}

/**
 * Check if user has a specific membership tier
 * 
 * @param string $tier_uuid Membership tier UUID
 * @param int|null $user_id
 * @param bool $active_only
 * @return bool
 */
function wicket_user_has_membership_tier($tier_uuid, $user_id = null, $active_only = true) {
    return wicket_memberships()->user_has_membership_tier($tier_uuid, $user_id, $active_only);
}

/**
 * Get all template data for memberships/subscriptions page
 * 
 * @param int|null $user_id
 * @return array
 */
function wicket_memberships_data($user_id = null) {
    return wicket_memberships()->get_template_data($user_id);
}

/**
 * Manually sync a user's memberships
 * 
 * @param int $user_id
 * @return array|false
 */
function wicket_sync_user_memberships($user_id) {
    return wicket_memberships()->sync_user_memberships($user_id);
}

/**
 * Get nonce for AJAX calls
 * 
 * @return string
 */
function wicket_memberships_nonce() {
    return wp_create_nonce('wicket_memberships_nonce');
}

/**
 * Output JavaScript config for memberships AJAX
 */
function wicket_memberships_js_config() {
    ?>
    <script>
    var wicketMembershipsConfig = {
        ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
        nonce: '<?php echo wp_create_nonce('wicket_memberships_nonce'); ?>'
    };
    </script>
    <?php
}

// Initialize
add_action('plugins_loaded', function() {
    wicket_memberships();
}, 15);