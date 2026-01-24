<?php
/**
 * Wicket Company Functions
 * 
 * Provides helper functions and AJAX handlers for the Company page.
 * Designed to work with Bricks Builder - no shortcode, use functions and AJAX from your template.
 * 
 * =============================================================================
 * AVAILABLE FUNCTIONS (for use in Bricks PHP code or templates):
 * =============================================================================
 * 
 * wicket_get_user_companies($user_id)      - Get array of user's company connections
 * wicket_get_user_primary_company($user_id) - Get user's primary company
 * wicket_company_data()                     - Get all data needed for company page
 * wicket_get_org_types()                    - Get organization types for dropdowns
 * wicket_search_local_companies($term)      - Search companies in local DB
 * 
 * =============================================================================
 * AJAX ENDPOINTS (for frontend JavaScript):
 * =============================================================================
 * 
 * Action: wicket_get_user_companies    - Get user's companies
 * Action: wicket_search_companies      - Search companies (for autocomplete)
 * Action: wicket_add_company_connection - Add user to existing company
 * Action: wicket_create_new_company    - Create new company and connect user
 * Action: wicket_set_primary_company   - Set user's primary company
 * Action: wicket_remove_company_connection - Remove user from company
 * 
 * All AJAX calls require: nonce = wicket_company_nonce
 * 
 * @package MyIES_Integration
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_Company_Functions {
    
    private static $instance = null;
    
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
        $this->init_hooks();
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // AJAX handlers for logged-in users
        add_action('wp_ajax_wicket_get_user_companies', array($this, 'ajax_get_user_companies'));
        add_action('wp_ajax_wicket_search_companies', array($this, 'ajax_search_companies'));
        add_action('wp_ajax_wicket_add_company_connection', array($this, 'ajax_add_company_connection'));
        add_action('wp_ajax_wicket_create_new_company', array($this, 'ajax_create_new_company'));
        add_action('wp_ajax_wicket_set_primary_company', array($this, 'ajax_set_primary_company'));
        add_action('wp_ajax_wicket_remove_company_connection', array($this, 'ajax_remove_company_connection'));
        
        // Public AJAX for company search (needed for autocomplete)
        add_action('wp_ajax_nopriv_wicket_search_companies', array($this, 'ajax_search_companies'));
        
        // Register assets
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
    }
    
    /**
     * Register CSS and JS assets (not enqueued by default)
     * Call wicket_company_enqueue_assets() in your template to load them
     */
    public function register_assets() {
        // Register CSS
        wp_register_style(
            'wicket-company-change-org',
            plugin_dir_url(dirname(dirname(__FILE__))) . 'assets/css/company-change-org.css',
            array(),
            WICKET_INTEGRATION_VERSION
        );
        
        // Register JS
        wp_register_script(
            'wicket-company-change-org',
            plugin_dir_url(dirname(dirname(__FILE__))) . 'assets/js/company-change-org.js',
            array('jquery'),
            WICKET_INTEGRATION_VERSION,
            true
        );
    }
    
    /**
     * Enqueue company page assets
     * Call this from your Bricks template or use wicket_company_enqueue_assets()
     */
    public function enqueue_assets() {
        wp_enqueue_style('wicket-company-change-org');
        wp_enqueue_script('wicket-company-change-org');
        
        // Localize script with config
        wp_localize_script('wicket-company-change-org', 'wicketCompanyConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wicket_company_nonce')
        ));
    }
    
    // =========================================================================
    // HELPER FUNCTIONS
    // =========================================================================
    
    /**
     * Get user's companies with full details
     * 
     * @param int|null $user_id User ID (defaults to current user)
     * @return array Array of company data with keys:
     *               - connection_uuid
     *               - org_uuid  
     *               - legal_name
     *               - alternate_name
     *               - org_type
     *               - description
     *               - connection_type
     *               - is_primary
     */
    public function get_user_companies($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            error_log('[WICKET COMPANY] No user ID provided');
            return array();
        }
        
        $person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);
        
        // Try alternate meta key
        if (empty($person_uuid)) {
            $person_uuid = get_user_meta($user_id, 'wicket_uuid', true);
        }
        
        if (empty($person_uuid)) {
            error_log('[WICKET COMPANY] No person_uuid found for user ' . $user_id);
            return array();
        }
        
        error_log('[WICKET COMPANY] Getting companies for person_uuid: ' . $person_uuid);
        
        // Get connections from Wicket API
        $api = wicket_api();
        $connections = $api->get_person_connections($person_uuid);
        
        error_log('[WICKET COMPANY] Connections from Wicket: ' . print_r($connections, true));
        
        if (empty($connections)) {
            error_log('[WICKET COMPANY] No connections found in Wicket');
            return array();
        }
        
        $orgs = wicket_organizations();
        $companies = array();
        $primary_org_uuid = get_user_meta($user_id, 'wicket_primary_org_uuid', true);
        
        foreach ($connections as $conn) {
            // Try different relationship structures (Wicket API can return different formats)
            $org_uuid = $conn['relationships']['to']['data']['id'] ?? 
                        $conn['relationships']['organization']['data']['id'] ?? null;
            
            error_log('[WICKET COMPANY] Processing connection: ' . ($conn['id'] ?? 'unknown') . ' -> org: ' . ($org_uuid ?? 'null'));
            
            if (empty($org_uuid)) {
                continue;
            }
            
            // Get org details from local cache first
            $org = $orgs->get_organization($org_uuid);
            
            // If not in local cache, try API
            if (empty($org)) {
                error_log('[WICKET COMPANY] Org not in local cache, fetching from API: ' . $org_uuid);
                $org_api = $api->get_organization($org_uuid);
                if ($org_api) {
                    $org = array(
                        'wicket_uuid' => $org_uuid,
                        'legal_name' => $org_api['attributes']['legal_name'] ?? 'Unknown',
                        'alternate_name' => $org_api['attributes']['alternate_name'] ?? '',
                        'org_type' => $org_api['attributes']['type'] ?? '',
                        'description' => $org_api['attributes']['description'] ?? ''
                    );
                }
            }
            
            if ($org) {
                $companies[] = array(
                    'connection_uuid' => $conn['id'],
                    'org_uuid' => $org_uuid,
                    'legal_name' => $org['legal_name'] ?? 'Unknown',
                    'alternate_name' => $org['alternate_name'] ?? '',
                    'org_type' => $org['org_type'] ?? '',
                    'description' => $org['description'] ?? '',
                    'connection_type' => $conn['attributes']['type'] ?? 'member',
                    'is_primary' => ($org_uuid === $primary_org_uuid) || (count($companies) === 0) // First one is primary by default
                );
            }
        }
        
        // If no primary set but we have companies, set the first one as primary
        if (!empty($companies) && empty($primary_org_uuid)) {
            update_user_meta($user_id, 'wicket_primary_org_uuid', $companies[0]['org_uuid']);
            $companies[0]['is_primary'] = true;
        }
        
        error_log('[WICKET COMPANY] Returning ' . count($companies) . ' companies');
        
        return $companies;
    }
    
    /**
     * Get user's primary company
     * 
     * @param int|null $user_id User ID (defaults to current user)
     * @return array|null Company data or null if none
     */
    public function get_user_primary_company($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        $companies = $this->get_user_companies($user_id);
        
        // First try to find the one marked as primary
        foreach ($companies as $company) {
            if ($company['is_primary']) {
                return $company;
            }
        }
        
        // If no primary set, return first company
        return !empty($companies) ? $companies[0] : null;
    }
    
    /**
     * Search companies in local database
     * 
     * @param string $search_term Search term
     * @param int $limit Max results
     * @return array Companies matching search
     */
    public function search_companies($search_term, $limit = 20) {
        $orgs = wicket_organizations();
        return $orgs->search_organizations($search_term, $limit);
    }
    
    /**
     * Get all data needed for company page template
     * Use this in Bricks to get everything in one call
     * 
     * @return array All company page data
     */
    public function get_template_data() {
        $user_id = get_current_user_id();
        
        if (!$user_id) {
            return array(
                'logged_in' => false,
                'has_wicket_account' => false,
                'companies' => array(),
                'primary_company' => null,
                'person_uuid' => null,
                'org_types' => $this->get_organization_types(),
                'nonce' => wp_create_nonce('wicket_company_nonce'),
                'ajax_url' => admin_url('admin-ajax.php')
            );
        }
        
        $person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);
        
        return array(
            'logged_in' => true,
            'has_wicket_account' => !empty($person_uuid),
            'companies' => $this->get_user_companies($user_id),
            'primary_company' => $this->get_user_primary_company($user_id),
            'person_uuid' => $person_uuid,
            'org_types' => $this->get_organization_types(),
            'nonce' => wp_create_nonce('wicket_company_nonce'),
            'ajax_url' => admin_url('admin-ajax.php')
        );
    }
    
    /**
     * Get organization types for dropdowns
     * Modify this array based on your Wicket configuration
     * 
     * @return array Organization types with value/label
     */
    public function get_organization_types() {
        return array(
            array('value' => 'company', 'label' => __('Company', 'wicket-integration')),
            array('value' => 'corporation', 'label' => __('Corporation', 'wicket-integration')),
            array('value' => 'nonprofit', 'label' => __('Non-Profit', 'wicket-integration')),
            array('value' => 'government', 'label' => __('Government', 'wicket-integration')),
            array('value' => 'education', 'label' => __('Education', 'wicket-integration')),
            array('value' => 'association', 'label' => __('Association', 'wicket-integration')),
            array('value' => 'other', 'label' => __('Other', 'wicket-integration')),
        );
    }
    
    /**
     * Update organization user meta for Bricks Dynamic Data
     * 
     * This updates the same meta keys that WicketLoginSync uses,
     * ensuring consistency when the user changes their organization.
     * 
     * @param int $user_id WordPress user ID
     * @param string $org_uuid Organization UUID
     */
    private function update_org_user_meta($user_id, $org_uuid) {
        if (empty($org_uuid)) {
            return;
        }
        
        error_log('[WICKET COMPANY] Updating org user_meta for org: ' . $org_uuid);
        
        // Try to get org details from local cache first
        $orgs = wicket_organizations();
        $org = $orgs->get_organization($org_uuid);
        
        // If not in local cache, try API
        if (empty($org)) {
            $api = wicket_api();
            $org_api = $api->get_organization($org_uuid);
            if ($org_api && isset($org_api['attributes'])) {
                $org = array(
                    'wicket_uuid' => $org_uuid,
                    'legal_name' => $org_api['attributes']['legal_name'] ?? '',
                    'alternate_name' => $org_api['attributes']['alternate_name'] ?? '',
                    'org_type' => $org_api['attributes']['type'] ?? '',
                    'description' => $org_api['attributes']['description'] ?? '',
                    'slug' => $org_api['attributes']['slug'] ?? ''
                );
            }
        }
        
        if (empty($org)) {
            error_log('[WICKET COMPANY] Could not get org details for: ' . $org_uuid);
            // Still save the UUID even if we can't get details
            update_user_meta($user_id, 'wicket_org_uuid', sanitize_text_field($org_uuid));
            return;
        }
        
        // Update user meta with organization data
        // These match the keys used by WicketLoginSync for consistency
        update_user_meta($user_id, 'wicket_org_uuid', sanitize_text_field($org_uuid));
        update_user_meta($user_id, 'wicket_org_name', sanitize_text_field($org['legal_name'] ?? ''));
        update_user_meta($user_id, 'wicket_org_type', sanitize_text_field($org['org_type'] ?? ''));
        update_user_meta($user_id, 'wicket_org_alternate_name', sanitize_text_field($org['alternate_name'] ?? ''));
        
        error_log('[WICKET COMPANY] Updated org user_meta: ' . ($org['legal_name'] ?? $org_uuid));
    }
    
    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================
    
    /**
     * AJAX: Get user's companies
     * 
     * Returns: { success: true, data: { companies: [...], primary_company: {...} } }
     */
    public function ajax_get_user_companies() {
        check_ajax_referer('wicket_company_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }
        
        $user_id = get_current_user_id();
        
        wp_send_json_success(array(
            'companies' => $this->get_user_companies($user_id),
            'primary_company' => $this->get_user_primary_company($user_id)
        ));
    }
    
    /**
     * AJAX: Search companies
     * 
     * Params: search (GET or POST)
     * Returns: { success: true, data: { results: [...] } }
     */
    public function ajax_search_companies() {
        check_ajax_referer('wicket_company_nonce', 'nonce');
        
        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        if (empty($search)) {
            $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        }
        
        error_log('[WICKET COMPANY] Search request for: ' . $search);
        
        if (strlen($search) < 2) {
            wp_send_json_success(array('results' => array()));
            return;
        }
        
        $results = $this->search_companies($search, 20);
        
        error_log('[WICKET COMPANY] Search found ' . count($results) . ' results');
        
        // Format for easy use in JS
        $formatted = array_map(function($org) {
            return array(
                'id' => $org['wicket_uuid'],
                'wicket_uuid' => $org['wicket_uuid'],
                'text' => $org['legal_name'],
                'legal_name' => $org['legal_name'],
                'alternate_name' => $org['alternate_name'] ?? '',
                'org_type' => $org['org_type'] ?? ''
            );
        }, $results);
        
        wp_send_json_success(array('results' => $formatted));
    }
    
    /**
     * AJAX: Add company connection
     * 
     * Params: org_uuid (POST)
     * Returns: { success: true, data: { message, companies, primary_company, connection_uuid } }
     */
    public function ajax_add_company_connection() {
        check_ajax_referer('wicket_company_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }
        
        $org_uuid = isset($_POST['org_uuid']) ? sanitize_text_field($_POST['org_uuid']) : '';
        
        if (empty($org_uuid)) {
            wp_send_json_error(array('message' => 'Organization UUID required'));
        }
        
        $user_id = get_current_user_id();
        $person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);
        
        if (empty($person_uuid)) {
            wp_send_json_error(array('message' => 'User not linked to Wicket'));
        }
        
        $api = wicket_api();
        $result = $api->create_person_org_connection($person_uuid, $org_uuid);
        
        if ($result['success']) {
            // Sync to local database
            $orgs = wicket_organizations();
            $orgs->sync_user_connections($user_id);
            
            // If this is user's first company, set as primary
            $primary = get_user_meta($user_id, 'wicket_primary_org_uuid', true);
            if (empty($primary)) {
                update_user_meta($user_id, 'wicket_primary_org_uuid', $org_uuid);
            }
            
            wp_send_json_success(array(
                'message' => $result['already_existed'] ? 'Already connected to this company' : 'Company added successfully',
                'companies' => $this->get_user_companies($user_id),
                'primary_company' => $this->get_user_primary_company($user_id),
                'connection_uuid' => $result['connection_uuid'] ?? null
            ));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
    
    /**
     * AJAX: Create new company and connect user
     * 
     * Params: legal_name, type, alternate_name (optional) (POST)
     * Returns: { success: true, data: { message, org_uuid, companies, primary_company } }
     */
    public function ajax_create_new_company() {
        check_ajax_referer('wicket_company_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }
        
        $legal_name = isset($_POST['legal_name']) ? sanitize_text_field($_POST['legal_name']) : '';
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $alternate_name = isset($_POST['alternate_name']) ? sanitize_text_field($_POST['alternate_name']) : '';
        
        if (empty($legal_name) || empty($type)) {
            wp_send_json_error(array('message' => 'Company name and type are required'));
        }
        
        $user_id = get_current_user_id();
        $person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);
        
        if (empty($person_uuid)) {
            wp_send_json_error(array('message' => 'User not linked to Wicket'));
        }
        
        $api = wicket_api();
        
        // Create the organization in Wicket
        $org_data = array(
            'legal_name' => $legal_name,
            'type' => strtolower($type)
        );
        
        if (!empty($alternate_name)) {
            $org_data['alternate_name'] = $alternate_name;
        }
        
        $create_result = $api->create_organization($org_data);
        
        if (!$create_result['success']) {
            wp_send_json_error(array('message' => 'Failed to create organization: ' . $create_result['message']));
        }
        
        $org_uuid = $create_result['uuid'];
        
        // Create connection to the new organization
        $conn_result = $api->create_person_org_connection($person_uuid, $org_uuid);
        
        if (!$conn_result['success']) {
            wp_send_json_error(array('message' => 'Organization created but failed to connect: ' . $conn_result['message']));
        }
        
        // Save the new org to local cache
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'wicket_organizations',
            array(
                'wicket_uuid' => $org_uuid,
                'legal_name' => $legal_name,
                'org_type' => strtolower($type),
                'alternate_name' => $alternate_name,
                'synced_at' => current_time('mysql')
            )
        );

        // Sync user connections
        $orgs = wicket_organizations();
        $orgs->sync_user_connections($user_id);

        // Always set newly created company as primary (user created it from Change Org modal)
        update_user_meta($user_id, 'wicket_primary_org_uuid', $org_uuid);

        // Update organization user_meta for Bricks Dynamic Data
        $this->update_org_user_meta($user_id, $org_uuid);
        
        wp_send_json_success(array(
            'message' => 'Company created and added successfully',
            'org_uuid' => $org_uuid,
            'companies' => $this->get_user_companies($user_id),
            'primary_company' => $this->get_user_primary_company($user_id)
        ));
    }
    
    /**
     * AJAX: Set primary company
     * 
     * This will:
     * 1. Create a connection in Wicket if it doesn't exist
     * 2. Save the org as primary locally
     * 
     * Params: org_uuid (POST)
     * Returns: { success: true, data: { message, companies, primary_company } }
     */
    public function ajax_set_primary_company() {
        check_ajax_referer('wicket_company_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }
        
        $org_uuid = isset($_POST['org_uuid']) ? sanitize_text_field($_POST['org_uuid']) : '';
        
        if (empty($org_uuid)) {
            wp_send_json_error(array('message' => 'Organization UUID required'));
        }
        
        $user_id = get_current_user_id();
        $person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);
        
        if (empty($person_uuid)) {
            $person_uuid = get_user_meta($user_id, 'wicket_uuid', true);
        }
        
        if (empty($person_uuid)) {
            wp_send_json_error(array('message' => 'User does not have a Wicket account'));
        }
        
        error_log('[WICKET COMPANY] Setting primary company: ' . $org_uuid . ' for person: ' . $person_uuid);
        
        $api = wicket_api();
        
        // Check if user already has connection to this org
        $existing_connections = $api->get_person_connections($person_uuid);
        $has_connection = false;
        
        foreach ($existing_connections as $conn) {
            $conn_org_uuid = $conn['relationships']['to']['data']['id'] ?? 
                            $conn['relationships']['organization']['data']['id'] ?? null;
            if ($conn_org_uuid === $org_uuid) {
                $has_connection = true;
                error_log('[WICKET COMPANY] User already has connection to this org');
                break;
            }
        }
        
        // If no connection exists, create one in Wicket
        if (!$has_connection) {
            error_log('[WICKET COMPANY] Creating new connection in Wicket');
            $result = $api->create_person_org_connection($person_uuid, $org_uuid, 'member', 'Organization member');
            
            if (!$result['success'] && empty($result['already_existed'])) {
                error_log('[WICKET COMPANY] Failed to create connection: ' . ($result['message'] ?? 'Unknown error'));
                wp_send_json_error(array('message' => 'Failed to create connection in Wicket: ' . ($result['message'] ?? 'Unknown error')));
            }
        }
        
        // Save locally as primary
        update_user_meta($user_id, 'wicket_primary_org_uuid', $org_uuid);
        
        // Update organization user_meta for Bricks Dynamic Data
        $this->update_org_user_meta($user_id, $org_uuid);
        
        error_log('[WICKET COMPANY] Primary company set successfully');
        
        wp_send_json_success(array(
            'message' => 'Organization updated successfully',
            'companies' => $this->get_user_companies($user_id),
            'primary_company' => $this->get_user_primary_company($user_id)
        ));
    }
    
    /**
     * AJAX: Remove company connection
     * 
     * Params: connection_uuid, org_uuid (POST)
     * Returns: { success: true, data: { message, companies, primary_company } }
     */
    public function ajax_remove_company_connection() {
        check_ajax_referer('wicket_company_nonce', 'nonce');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }
        
        $connection_uuid = isset($_POST['connection_uuid']) ? sanitize_text_field($_POST['connection_uuid']) : '';
        $org_uuid = isset($_POST['org_uuid']) ? sanitize_text_field($_POST['org_uuid']) : '';
        
        if (empty($connection_uuid)) {
            wp_send_json_error(array('message' => 'Connection UUID required'));
        }
        
        $user_id = get_current_user_id();
        $api = wicket_api();
        
        $result = $api->delete_connection($connection_uuid);
        
        if ($result['success']) {
            // Update local database
            global $wpdb;
            $wpdb->update(
                $wpdb->prefix . 'wicket_person_org_connections',
                array('is_active' => 0),
                array('connection_uuid' => $connection_uuid)
            );
            
            // If this was primary, clear it and set another
            $primary = get_user_meta($user_id, 'wicket_primary_org_uuid', true);
            if ($primary === $org_uuid) {
                delete_user_meta($user_id, 'wicket_primary_org_uuid');
                
                // Set another org as primary if exists
                $companies = $this->get_user_companies($user_id);
                if (!empty($companies)) {
                    update_user_meta($user_id, 'wicket_primary_org_uuid', $companies[0]['org_uuid']);
                }
            }
            
            wp_send_json_success(array(
                'message' => 'Company connection removed',
                'companies' => $this->get_user_companies($user_id),
                'primary_company' => $this->get_user_primary_company($user_id)
            ));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
}

// =============================================================================
// GLOBAL HELPER FUNCTIONS
// Use these in your Bricks templates
// =============================================================================

/**
 * Get Company Functions instance
 */
function wicket_company() {
    return Wicket_Company_Functions::get_instance();
}

/**
 * Get user's companies
 * 
 * Example in Bricks Code block:
 * <?php 
 * $companies = wicket_get_user_companies();
 * foreach ($companies as $company) {
 *     echo $company['legal_name'];
 * }
 * ?>
 * 
 * @param int|null $user_id
 * @return array
 */
function wicket_get_user_companies($user_id = null) {
    return wicket_company()->get_user_companies($user_id);
}

/**
 * Get user's primary company
 * 
 * Example in Bricks:
 * <?php $primary = wicket_get_user_primary_company(); ?>
 * 
 * @param int|null $user_id
 * @return array|null
 */
function wicket_get_user_primary_company($user_id = null) {
    return wicket_company()->get_user_primary_company($user_id);
}

/**
 * Search companies in local database
 * 
 * @param string $search_term
 * @param int $limit
 * @return array
 */
function wicket_search_local_companies($search_term, $limit = 20) {
    return wicket_company()->search_companies($search_term, $limit);
}

/**
 * Get all template data for company page
 * 
 * Example in Bricks:
 * <?php 
 * $data = wicket_company_data();
 * // $data contains: logged_in, has_wicket_account, companies, primary_company, 
 * //                 person_uuid, org_types, nonce, ajax_url
 * ?>
 * 
 * @return array
 */
function wicket_company_data() {
    return wicket_company()->get_template_data();
}

/**
 * Get organization types for dropdowns
 * 
 * @return array
 */
function wicket_get_org_types() {
    return wicket_company()->get_organization_types();
}

/**
 * Get nonce for AJAX calls
 * Use this in your Bricks template to pass nonce to JavaScript
 * 
 * @return string
 */
function wicket_company_nonce() {
    return wp_create_nonce('wicket_company_nonce');
}

/**
 * Output JavaScript config for company AJAX
 * Call this in your Bricks template to set up JS variables
 * 
 * Example: <?php wicket_company_js_config(); ?>
 */
function wicket_company_js_config() {
    ?>
    <script>
    var wicketCompanyConfig = {
        ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
        nonce: '<?php echo wp_create_nonce('wicket_company_nonce'); ?>'
    };
    </script>
    <?php
}

/**
 * Enqueue company page assets (CSS and JS)
 * Call this in your Bricks template to load the Change Organization functionality
 * 
 * Example in Bricks Code block:
 * <?php wicket_company_enqueue_assets(); ?>
 */
function wicket_company_enqueue_assets() {
    wicket_company()->enqueue_assets();
}

// Initialize
add_action('plugins_loaded', function() {
    wicket_company();
}, 15);