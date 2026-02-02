<?php
/**
 * Wicket Organizations Handler
 * 
 * Manages organizations synchronization between Wicket API and WordPress.
 * Creates custom table, handles sync, and provides cron job for weekly updates.
 * 
 * @package MyIES_Integration
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_Organizations {
    
    private static $instance = null;
    private $table_name;
    private $connections_table;
    
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
        $this->table_name = $wpdb->prefix . 'wicket_organizations';
        $this->connections_table = $wpdb->prefix . 'wicket_person_org_connections';
        
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Create tables on plugin activation
        register_activation_hook(WICKET_INTEGRATION_PLUGIN_FILE, array($this, 'create_tables'));
        
        // Cron job for weekly sync
        add_action('wicket_weekly_org_sync', array($this, 'sync_all_organizations'));
        add_filter('cron_schedules', array($this, 'add_weekly_schedule'));
        
        // Schedule cron on init if not already scheduled
        add_action('init', array($this, 'schedule_cron'));
        
        // Admin AJAX handlers
        add_action('wp_ajax_wicket_sync_organizations', array($this, 'ajax_sync_organizations'));
        add_action('wp_ajax_wicket_get_org_sync_status', array($this, 'ajax_get_sync_status'));
        add_action('wp_ajax_wicket_create_org_tables', array($this, 'ajax_create_tables'));
        
        // Add admin section
        add_action('wicket_acf_sync_settings_after_form', array($this, 'add_org_sync_section'));
        
        // Shortcode for organization search/autocomplete
        add_action('wp_ajax_wicket_search_organizations', array($this, 'ajax_search_organizations'));
        add_action('wp_ajax_nopriv_wicket_search_organizations', array($this, 'ajax_search_organizations'));
    }
    
    /**
     * Add weekly schedule to cron
     */
    public function add_weekly_schedule($schedules) {
        $schedules['weekly'] = array(
            'interval' => 604800, // 7 days in seconds
            'display' => __('Once Weekly', 'wicket-integration')
        );
        return $schedules;
    }
    
    /**
     * Schedule the cron job
     */
    public function schedule_cron() {
        if (!wp_next_scheduled('wicket_weekly_org_sync')) {
            wp_schedule_event(time(), 'weekly', 'wicket_weekly_org_sync');
            error_log('[WICKET ORGS] Scheduled weekly organization sync cron job');
        }
    }
    
    /**
     * Create database tables
     * 
     * IMPORTANT: dbDelta() has very strict formatting requirements:
     * - Each field must be on its own line
     * - Two spaces after PRIMARY KEY
     * - KEY not INDEX
     * - No IF NOT EXISTS
     */
    public function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        error_log('[WICKET ORGS] Starting table creation...');
        error_log('[WICKET ORGS] Organizations table name: ' . $this->table_name);
        error_log('[WICKET ORGS] Connections table name: ' . $this->connections_table);
        
        // Organizations table - dbDelta requires specific formatting
        $sql_orgs = "CREATE TABLE {$this->table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            wicket_uuid varchar(36) NOT NULL,
            legal_name varchar(255) DEFAULT NULL,
            legal_name_en varchar(255) DEFAULT NULL,
            legal_name_fr varchar(255) DEFAULT NULL,
            legal_name_es varchar(255) DEFAULT NULL,
            alternate_name varchar(255) DEFAULT NULL,
            org_type varchar(100) DEFAULT NULL,
            slug varchar(255) DEFAULT NULL,
            description text,
            identifying_number varchar(50) DEFAULT NULL,
            parent_org_uuid varchar(36) DEFAULT NULL,
            people_count int(11) DEFAULT 0,
            wicket_created_at datetime DEFAULT NULL,
            wicket_updated_at datetime DEFAULT NULL,
            synced_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY wicket_uuid (wicket_uuid),
            KEY legal_name (legal_name),
            KEY org_type (org_type),
            KEY parent_org_uuid (parent_org_uuid)
        ) $charset_collate;";
        
        // Person-Organization connections table
        $sql_connections = "CREATE TABLE {$this->connections_table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            connection_uuid varchar(36) NOT NULL,
            person_uuid varchar(36) NOT NULL,
            org_uuid varchar(36) NOT NULL,
            wp_user_id bigint(20) UNSIGNED DEFAULT NULL,
            connection_type varchar(50) DEFAULT 'member',
            description varchar(255) DEFAULT NULL,
            starts_at datetime DEFAULT NULL,
            ends_at datetime DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            synced_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY connection_uuid (connection_uuid),
            KEY person_uuid (person_uuid),
            KEY org_uuid (org_uuid),
            KEY wp_user_id (wp_user_id),
            KEY is_active (is_active)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        
        // Run dbDelta and capture results
        $result_orgs = dbDelta($sql_orgs);
        $result_connections = dbDelta($sql_connections);
        
        error_log('[WICKET ORGS] dbDelta result for organizations: ' . print_r($result_orgs, true));
        error_log('[WICKET ORGS] dbDelta result for connections: ' . print_r($result_connections, true));
        
        // Verify tables exist
        $orgs_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        $conn_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->connections_table}'") === $this->connections_table;
        
        error_log('[WICKET ORGS] Organizations table exists: ' . ($orgs_exists ? 'YES' : 'NO'));
        error_log('[WICKET ORGS] Connections table exists: ' . ($conn_exists ? 'YES' : 'NO'));
        
        if (!$orgs_exists || !$conn_exists) {
            error_log('[WICKET ORGS] ERROR: Tables were not created! Attempting direct SQL...');
            
            // Fallback: try direct SQL
            if (!$orgs_exists) {
                $wpdb->query($sql_orgs);
                $error = $wpdb->last_error;
                if ($error) {
                    error_log('[WICKET ORGS] Direct SQL error for organizations: ' . $error);
                }
            }
            
            if (!$conn_exists) {
                $wpdb->query($sql_connections);
                $error = $wpdb->last_error;
                if ($error) {
                    error_log('[WICKET ORGS] Direct SQL error for connections: ' . $error);
                }
            }
        }
        
        error_log('[WICKET ORGS] Database tables creation complete');
        
        return $orgs_exists && $conn_exists;
    }
    
    /**
     * Check if tables exist
     */
    public function tables_exist() {
        global $wpdb;
        
        $orgs_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'") === $this->table_name;
        $conn_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->connections_table}'") === $this->connections_table;
        
        return $orgs_exists && $conn_exists;
    }
    
    /**
     * Ensure tables exist before operations
     */
    private function ensure_tables() {
        if (!$this->tables_exist()) {
            error_log('[WICKET ORGS] Tables missing, creating now...');
            $this->create_tables();
        }
    }
    
    /**
     * Sync all organizations from Wicket API
     */
    public function sync_all_organizations($return_stats = false) {
        error_log('[WICKET ORGS] Starting full organization sync...');
        
        // Ensure tables exist before syncing
        $this->ensure_tables();
        
        if (!$this->tables_exist()) {
            error_log('[WICKET ORGS] CRITICAL: Tables still do not exist after creation attempt!');
            return $return_stats ? array('success' => false, 'error' => 'Database tables could not be created') : false;
        }
        
        $api = wicket_api();
        $token = $api->generate_jwt_token();
        
        if (is_wp_error($token)) {
            error_log('[WICKET ORGS] Failed to generate JWT token: ' . $token->get_error_message());
            return $return_stats ? array('success' => false, 'error' => $token->get_error_message()) : false;
        }
        
        $stats = array(
            'total' => 0,
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'pages' => 0
        );
        
        $page = 1;
        $page_size = 100; // Max recommended by Wicket
        $has_more = true;
        
        while ($has_more) {
            $result = $this->fetch_organizations_page($token, $page, $page_size);
            
            if (is_wp_error($result)) {
                error_log('[WICKET ORGS] Error fetching page ' . $page . ': ' . $result->get_error_message());
                $stats['errors']++;
                break;
            }
            
            $organizations = $result['data'] ?? array();
            $meta = $result['meta'] ?? array();
            
            foreach ($organizations as $org) {
                $sync_result = $this->save_organization($org);
                if ($sync_result === 'created') {
                    $stats['created']++;
                } elseif ($sync_result === 'updated') {
                    $stats['updated']++;
                } else {
                    $stats['errors']++;
                }
                $stats['total']++;
            }
            
            $stats['pages']++;
            
            // Check if there are more pages
            $total_pages = $meta['page']['total_pages'] ?? 1;
            $has_more = $page < $total_pages;
            $page++;
            
            // Log progress every 5 pages
            if ($page % 5 === 0) {
                error_log("[WICKET ORGS] Progress: Page {$page}/{$total_pages}, Total: {$stats['total']}");
            }
        }
        
        // Update last sync time
        update_option('wicket_orgs_last_sync', current_time('mysql'));
        update_option('wicket_orgs_last_sync_stats', $stats);
        
        error_log("[WICKET ORGS] Sync complete. Created: {$stats['created']}, Updated: {$stats['updated']}, Errors: {$stats['errors']}");
        
        return $return_stats ? array('success' => true, 'stats' => $stats) : true;
    }
    
    /**
     * Fetch a page of organizations from Wicket API
     */
    private function fetch_organizations_page($token, $page, $page_size) {
        $api = wicket_api();
        $endpoint = $api->get_api_url() . "/organizations?page[number]={$page}&page[size]={$page_size}";
        
        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($status_code !== 200) {
            return new WP_Error('api_error', "API returned status {$status_code}");
        }
        
        return $body;
    }
    
    /**
     * Save organization to local database
     */
    private function save_organization($org_data) {
        global $wpdb;
        
        $uuid = $org_data['id'] ?? null;
        if (empty($uuid)) {
            return 'error';
        }
        
        $attributes = $org_data['attributes'] ?? array();
        $relationships = $org_data['relationships'] ?? array();
        
        // Get parent org UUID if exists
        $parent_uuid = null;
        if (!empty($relationships['parent_organization']['data']['id'])) {
            $parent_uuid = $relationships['parent_organization']['data']['id'];
        }
        
        $data = array(
            'wicket_uuid' => $uuid,
            'legal_name' => $attributes['legal_name'] ?? null,
            'legal_name_en' => $attributes['legal_name_en'] ?? null,
            'legal_name_fr' => $attributes['legal_name_fr'] ?? null,
            'legal_name_es' => $attributes['legal_name_es'] ?? null,
            'alternate_name' => $attributes['alternate_name'] ?? null,
            'org_type' => $attributes['type'] ?? null,
            'slug' => $attributes['slug'] ?? null,
            'description' => $attributes['description'] ?? null,
            'identifying_number' => $attributes['identifying_number'] ?? null,
            'parent_org_uuid' => $parent_uuid,
            'people_count' => $attributes['people_count'] ?? 0,
            'wicket_created_at' => !empty($attributes['created_at']) ? date('Y-m-d H:i:s', strtotime($attributes['created_at'])) : null,
            'wicket_updated_at' => !empty($attributes['updated_at']) ? date('Y-m-d H:i:s', strtotime($attributes['updated_at'])) : null,
            'synced_at' => current_time('mysql')
        );
        
        // Check if exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE wicket_uuid = %s",
            $uuid
        ));
        
        if ($existing) {
            $wpdb->update($this->table_name, $data, array('wicket_uuid' => $uuid));
            return 'updated';
        } else {
            $wpdb->insert($this->table_name, $data);
            return 'created';
        }
    }
    
    /**
     * Get organization by UUID
     */
    public function get_organization($uuid) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE wicket_uuid = %s",
            $uuid
        ), ARRAY_A);
    }
    
    /**
     * Get organization by ID
     */
    public function get_organization_by_id($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ), ARRAY_A);
    }
    
    /**
     * Search organizations
     */
    public function search_organizations($search_term, $limit = 20) {
        global $wpdb;
        
        $search = '%' . $wpdb->esc_like($search_term) . '%';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT wicket_uuid, legal_name, alternate_name, org_type 
             FROM {$this->table_name} 
             WHERE 
                org_type = 'company' AND 
                (
                    legal_name LIKE %s 
                    OR alternate_name LIKE %s 
                    OR legal_name_en LIKE %s
                )
             ORDER BY legal_name ASC 
             LIMIT %d",
            $search, $search, $search, $limit
        ), ARRAY_A);
    }
    
    /**
     * Get all organizations (with optional pagination)
     */
    public function get_all_organizations($page = 1, $per_page = 50, $order_by = 'legal_name', $order = 'ASC') {
        global $wpdb;
        
        $offset = ($page - 1) * $per_page;
        $allowed_columns = array('legal_name', 'org_type', 'people_count', 'wicket_updated_at');
        $order_by = in_array($order_by, $allowed_columns) ? $order_by : 'legal_name';
        $order = strtoupper($order) === 'DESC' ? 'DESC' : 'ASC';
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} ORDER BY {$order_by} {$order} LIMIT %d OFFSET %d",
                $per_page, $offset
            ),
            ARRAY_A
        );
    }
    
    /**
     * Get total organizations count
     */
    public function get_organizations_count() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name}");
    }
    
    // =========================================================================
    // PERSON-ORGANIZATION CONNECTIONS
    // =========================================================================
    
    /**
     * Save person-organization connection
     */
    public function save_connection($connection_data) {
        global $wpdb;
        
        $connection_uuid = $connection_data['connection_uuid'] ?? null;
        if (empty($connection_uuid)) {
            return false;
        }
        
        $data = array(
            'connection_uuid' => $connection_uuid,
            'person_uuid' => $connection_data['person_uuid'] ?? null,
            'org_uuid' => $connection_data['org_uuid'] ?? null,
            'wp_user_id' => $connection_data['wp_user_id'] ?? null,
            'connection_type' => $connection_data['connection_type'] ?? 'member',
            'description' => $connection_data['description'] ?? null,
            'starts_at' => $connection_data['starts_at'] ?? null,
            'ends_at' => $connection_data['ends_at'] ?? null,
            'is_active' => $connection_data['is_active'] ?? 1,
            'synced_at' => current_time('mysql')
        );
        
        // Check if exists
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$this->connections_table} WHERE connection_uuid = %s",
            $connection_uuid
        ));
        
        if ($existing) {
            return $wpdb->update($this->connections_table, $data, array('connection_uuid' => $connection_uuid));
        } else {
            return $wpdb->insert($this->connections_table, $data);
        }
    }
    
    /**
     * Get user's organization connections
     */
    public function get_user_connections($user_id) {
        global $wpdb;
        
        $person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);
        if (empty($person_uuid)) {
            return array();
        }
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT c.*, o.legal_name, o.alternate_name, o.org_type 
             FROM {$this->connections_table} c
             LEFT JOIN {$this->table_name} o ON c.org_uuid = o.wicket_uuid
             WHERE c.person_uuid = %s AND c.is_active = 1
             ORDER BY o.legal_name ASC",
            $person_uuid
        ), ARRAY_A);
    }
    
    /**
     * Get user's current/primary organization
     */
    public function get_user_current_organization($user_id) {
        global $wpdb;
        
        $person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);
        if (empty($person_uuid)) {
            return null;
        }
        
        // Try to get from user meta first (cached primary org)
        $primary_org_uuid = get_user_meta($user_id, 'wicket_primary_org_uuid', true);
        
        if (!empty($primary_org_uuid)) {
            $org = $this->get_organization($primary_org_uuid);
            if ($org) {
                return $org;
            }
        }
        
        // Fallback: get first active connection
        $connection = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*, o.* 
             FROM {$this->connections_table} c
             LEFT JOIN {$this->table_name} o ON c.org_uuid = o.wicket_uuid
             WHERE c.person_uuid = %s AND c.is_active = 1
             ORDER BY c.id ASC
             LIMIT 1",
            $person_uuid
        ), ARRAY_A);
        
        return $connection;
    }
    
    /**
     * Set user's primary organization
     */
    public function set_user_primary_organization($user_id, $org_uuid) {
        update_user_meta($user_id, 'wicket_primary_org_uuid', $org_uuid);
        return true;
    }
    
    /**
     * Sync user's connections from Wicket
     */
    public function sync_user_connections($user_id) {
        $person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);
        if (empty($person_uuid)) {
            return false;
        }
        
        $api = wicket_api();
        $connections = $api->get_person_connections($person_uuid);
        
        if (empty($connections)) {
            return true;
        }
        
        global $wpdb;
        
        // Mark all current connections as inactive first
        $wpdb->update(
            $this->connections_table,
            array('is_active' => 0),
            array('person_uuid' => $person_uuid)
        );
        
        foreach ($connections as $conn) {
            $org_uuid = $conn['relationships']['to']['data']['id'] ?? 
                        $conn['relationships']['organization']['data']['id'] ?? null;
            
            if (empty($org_uuid)) {
                continue;
            }
            
            $this->save_connection(array(
                'connection_uuid' => $conn['id'],
                'person_uuid' => $person_uuid,
                'org_uuid' => $org_uuid,
                'wp_user_id' => $user_id,
                'connection_type' => $conn['attributes']['type'] ?? 'member',
                'description' => $conn['attributes']['description'] ?? null,
                'starts_at' => $conn['attributes']['starts_at'] ?? null,
                'ends_at' => $conn['attributes']['ends_at'] ?? null,
                'is_active' => 1
            ));
        }
        
        return true;
    }
    
    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================
    
    /**
     * AJAX: Sync organizations
     */
    public function ajax_sync_organizations() {
        check_ajax_referer('wicket_org_sync', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        // Set sync in progress flag
        update_option('wicket_org_sync_in_progress', true);
        
        $result = $this->sync_all_organizations(true);
        
        // Clear sync in progress flag
        delete_option('wicket_org_sync_in_progress');
        
        if ($result['success']) {
            wp_send_json_success(array(
                'message' => 'Sync completed successfully',
                'stats' => $result['stats']
            ));
        } else {
            wp_send_json_error(array('message' => $result['error'] ?? 'Sync failed'));
        }
    }
    
    /**
     * AJAX: Get sync status
     */
    public function ajax_get_sync_status() {
        check_ajax_referer('wicket_org_sync', 'nonce');
        
        wp_send_json_success(array(
            'in_progress' => get_option('wicket_org_sync_in_progress', false),
            'last_sync' => get_option('wicket_orgs_last_sync', ''),
            'last_stats' => get_option('wicket_orgs_last_sync_stats', array()),
            'total_orgs' => $this->get_organizations_count()
        ));
    }
    
    /**
     * AJAX: Search organizations
     */
    public function ajax_search_organizations() {
        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        
        if (strlen($search) < 2) {
            wp_send_json_success(array());
            return;
        }
        
        $results = $this->search_organizations($search, 20);
        
        // Format for select2/autocomplete
        $formatted = array_map(function($org) {
            return array(
                'id' => $org['wicket_uuid'],
                'text' => $org['legal_name'],
                'alternate_name' => $org['alternate_name'],
                'type' => $org['org_type']
            );
        }, $results);
        
        wp_send_json_success($formatted);
    }
    
    /**
     * AJAX: Create tables manually
     */
    public function ajax_create_tables() {
        check_ajax_referer('wicket_org_sync', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $result = $this->create_tables();
        $tables_exist = $this->tables_exist();
        
        if ($tables_exist) {
            wp_send_json_success(array(
                'message' => 'Tables created successfully',
                'tables_exist' => true
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'Failed to create tables. Check error logs for details.',
                'tables_exist' => false
            ));
        }
    }
    
    /**
     * Add organization sync section to admin
     */
    public function add_org_sync_section() {
        global $wpdb;
        
        $tables_exist = $this->tables_exist();
        $total_orgs = $tables_exist ? $this->get_organizations_count() : 0;
        $last_sync = get_option('wicket_orgs_last_sync', '');
        $last_stats = get_option('wicket_orgs_last_sync_stats', array());
        $sync_in_progress = get_option('wicket_org_sync_in_progress', false);
        $nonce = wp_create_nonce('wicket_org_sync');
        
        // Table status info
        $orgs_table = $wpdb->prefix . 'wicket_organizations';
        $conn_table = $wpdb->prefix . 'wicket_person_org_connections';
        $orgs_exists = $wpdb->get_var("SHOW TABLES LIKE '{$orgs_table}'") === $orgs_table;
        $conn_exists = $wpdb->get_var("SHOW TABLES LIKE '{$conn_table}'") === $conn_table;
        ?>
        
        <div class="wrap" style="margin-top: 30px;">
            <h2><?php _e('Organization Sync', 'wicket-integration'); ?></h2>
            
            <!-- Database Status Card -->
            <div class="card" style="margin-bottom: 20px; <?php echo !$tables_exist ? 'border-left: 4px solid #dc3232;' : 'border-left: 4px solid #46b450;'; ?>">
                <h3><?php _e('Database Status', 'wicket-integration'); ?></h3>
                
                <table class="widefat" style="max-width: 500px;">
                    <tr>
                        <td><code><?php echo esc_html($orgs_table); ?></code></td>
                        <td>
                            <?php if ($orgs_exists): ?>
                                <span style="color: #46b450;">✓ <?php _e('Exists', 'wicket-integration'); ?></span>
                            <?php else: ?>
                                <span style="color: #dc3232;">✗ <?php _e('Missing', 'wicket-integration'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><code><?php echo esc_html($conn_table); ?></code></td>
                        <td>
                            <?php if ($conn_exists): ?>
                                <span style="color: #46b450;">✓ <?php _e('Exists', 'wicket-integration'); ?></span>
                            <?php else: ?>
                                <span style="color: #dc3232;">✗ <?php _e('Missing', 'wicket-integration'); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
                
                <?php if (!$tables_exist): ?>
                    <p style="color: #dc3232; margin-top: 15px;">
                        <strong><?php _e('⚠ Tables are missing! Click the button below to create them.', 'wicket-integration'); ?></strong>
                    </p>
                    <button type="button" id="create-tables-btn" class="button button-primary">
                        <?php _e('Create Database Tables', 'wicket-integration'); ?>
                    </button>
                    <span class="spinner" id="create-tables-spinner" style="float: none; visibility: hidden;"></span>
                    <div id="create-tables-result" style="margin-top: 10px;"></div>
                <?php endif; ?>
            </div>
            
            <!-- Sync Card -->
            <div class="card">
                <h3><?php _e('Sync Organizations from Wicket', 'wicket-integration'); ?></h3>
                <p><?php printf(__('Local organizations: <strong>%d</strong>', 'wicket-integration'), $total_orgs); ?></p>
                
                <?php if ($last_sync): ?>
                    <p><?php printf(__('Last sync: <strong>%s</strong>', 'wicket-integration'), 
                        date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_sync))); ?></p>
                    <?php if (!empty($last_stats)): ?>
                        <p class="description">
                            <?php printf(__('Created: %d, Updated: %d, Errors: %d', 'wicket-integration'),
                                $last_stats['created'] ?? 0,
                                $last_stats['updated'] ?? 0,
                                $last_stats['errors'] ?? 0
                            ); ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>
                
                <div id="org-sync-container">
                    <button type="button" id="start-org-sync-btn" class="button button-primary" <?php echo ($sync_in_progress || !$tables_exist) ? 'disabled' : ''; ?>>
                        <?php _e('Sync Organizations Now', 'wicket-integration'); ?>
                    </button>
                    <span class="spinner" id="org-sync-spinner" style="float: none; visibility: hidden;"></span>
                    <?php if (!$tables_exist): ?>
                        <p class="description" style="color: #dc3232;">
                            <?php _e('Please create the database tables first before syncing.', 'wicket-integration'); ?>
                        </p>
                    <?php else: ?>
                        <p class="description">
                            <?php _e('This will fetch all organizations from Wicket. A weekly cron job also runs automatically.', 'wicket-integration'); ?>
                        </p>
                    <?php endif; ?>
                </div>
                
                <div id="org-sync-result" style="margin-top: 15px; display: none;"></div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            // Create tables button
            $('#create-tables-btn').on('click', function() {
                var $btn = $(this);
                var $spinner = $('#create-tables-spinner');
                var $result = $('#create-tables-result');
                
                $btn.prop('disabled', true);
                $spinner.css('visibility', 'visible');
                $result.html('');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wicket_create_org_tables',
                        nonce: '<?php echo $nonce; ?>'
                    },
                    success: function(response) {
                        $spinner.css('visibility', 'hidden');
                        
                        if (response.success) {
                            $result.html('<div class="notice notice-success"><p><?php _e('Tables created successfully! Reloading page...', 'wicket-integration'); ?></p></div>');
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            $btn.prop('disabled', false);
                            $result.html('<div class="notice notice-error"><p>' + (response.data.message || '<?php _e('Failed to create tables', 'wicket-integration'); ?>') + '</p></div>');
                        }
                    },
                    error: function() {
                        $spinner.css('visibility', 'hidden');
                        $btn.prop('disabled', false);
                        $result.html('<div class="notice notice-error"><p><?php _e('Request failed', 'wicket-integration'); ?></p></div>');
                    }
                });
            });
            
            // Sync button
            $('#start-org-sync-btn').on('click', function() {
                var $btn = $(this);
                var $spinner = $('#org-sync-spinner');
                var $result = $('#org-sync-result');
                
                $btn.prop('disabled', true);
                $spinner.css('visibility', 'visible');
                $result.hide();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    timeout: 300000, // 5 minute timeout for large syncs
                    data: {
                        action: 'wicket_sync_organizations',
                        nonce: '<?php echo $nonce; ?>'
                    },
                    success: function(response) {
                        $spinner.css('visibility', 'hidden');
                        $btn.prop('disabled', false);
                        
                        if (response.success) {
                            var stats = response.data.stats || {};
                            $result.html('<div class="notice notice-success"><p>' +
                                '<?php _e('Sync completed!', 'wicket-integration'); ?> ' +
                                'Created: ' + (stats.created || 0) + ', ' +
                                'Updated: ' + (stats.updated || 0) + ', ' +
                                'Total: ' + (stats.total || 0) +
                                '</p></div>').show();
                            // Reload to update count
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            $result.html('<div class="notice notice-error"><p>' +
                                (response.data.message || '<?php _e('Sync failed', 'wicket-integration'); ?>') +
                                '</p></div>').show();
                        }
                    },
                    error: function(xhr, status, error) {
                        $spinner.css('visibility', 'hidden');
                        $btn.prop('disabled', false);
                        $result.html('<div class="notice notice-error"><p><?php _e('Request failed', 'wicket-integration'); ?>: ' + error + '</p></div>').show();
                    }
                });
            });
        });
        </script>
        <?php
    }
}

/**
 * Get Organizations instance
 */
function wicket_organizations() {
    return Wicket_Organizations::get_instance();
}

// Initialize
add_action('plugins_loaded', function() {
    wicket_organizations();
}, 15);