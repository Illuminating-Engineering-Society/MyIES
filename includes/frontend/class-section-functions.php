<?php
/**
 * Wicket Section Functions
 *
 * Provides helper functions and AJAX handlers for the Section page.
 * Sections are Organizations with type="section" in Wicket (regional chapters).
 *
 * =============================================================================
 * AVAILABLE FUNCTIONS (for use in Bricks PHP code or templates):
 * =============================================================================
 *
 * wicket_get_user_section($user_id)        - Get user's current section
 * wicket_get_user_sections($user_id)       - Get all user's section connections
 * wicket_section_data()                    - Get all data needed for section page
 * wicket_search_sections($term)            - Search sections in local DB
 *
 * =============================================================================
 * AJAX ENDPOINTS (for frontend JavaScript):
 * =============================================================================
 *
 * Action: wicket_get_user_section       - Get user's current section
 * Action: wicket_search_sections        - Search sections (for autocomplete)
 * Action: wicket_set_section            - Set/change user's section
 * Action: wicket_leave_section          - Remove user from section
 *
 * All AJAX calls require: nonce = wicket_section_nonce
 *
 * @package MyIES_Integration
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_Section_Functions {

    private static $instance = null;

    /**
     * Organization type for sections in Wicket
     */
    private $section_type = 'section';

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
        add_action('wp_ajax_wicket_get_user_section', array($this, 'ajax_get_user_section'));
        add_action('wp_ajax_wicket_search_sections', array($this, 'ajax_search_sections'));
        add_action('wp_ajax_wicket_set_section', array($this, 'ajax_set_section'));
        add_action('wp_ajax_wicket_leave_section', array($this, 'ajax_leave_section'));

        // Public AJAX for section search (needed for autocomplete)
        add_action('wp_ajax_nopriv_wicket_search_sections', array($this, 'ajax_search_sections'));

        // Register assets
        add_action('wp_enqueue_scripts', array($this, 'register_assets'));
    }

    /**
     * Register CSS and JS assets
     */
    public function register_assets() {
        wp_register_style(
            'wicket-section-change',
            plugin_dir_url(dirname(dirname(__FILE__))) . 'assets/css/section-change.css',
            array(),
            WICKET_INTEGRATION_VERSION
        );

        wp_register_script(
            'wicket-section-change',
            plugin_dir_url(dirname(dirname(__FILE__))) . 'assets/js/section-change.js',
            array('jquery'),
            WICKET_INTEGRATION_VERSION,
            true
        );
    }

    /**
     * Enqueue section page assets
     */
    public function enqueue_assets() {
        wp_enqueue_style('wicket-section-change');
        wp_enqueue_script('wicket-section-change');

        wp_localize_script('wicket-section-change', 'wicketSectionConfig', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wicket_section_nonce')
        ));
    }

    // =========================================================================
    // HELPER FUNCTIONS
    // =========================================================================

    /**
     * Get user's sections (connections to section-type organizations)
     *
     * @param int|null $user_id User ID (defaults to current user)
     * @return array Array of section data
     */
    public function get_user_sections($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        if (!$user_id) {
            myies_log('No user ID provided', 'Section Functions');
            return array();
        }

        $person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);
        if (empty($person_uuid)) {
            $person_uuid = get_user_meta($user_id, 'wicket_uuid', true);
        }

        if (empty($person_uuid)) {
            myies_log('No person_uuid found for user ' . $user_id, 'Section Functions');
            return array();
        }

        myies_log('Getting sections for person_uuid: ' . $person_uuid, 'Section Functions');

        // Get connections from Wicket API
        $api = wicket_api();
        $connections = $api->get_person_connections($person_uuid);

        if (empty($connections)) {
            myies_log('No connections found in Wicket', 'Section Functions');
            return array();
        }

        $orgs = wicket_organizations();
        $sections = array();
        $primary_section_uuid = get_user_meta($user_id, 'wicket_section_uuid', true);

        foreach ($connections as $conn) {

            // FILTER: Only process active connections
            $conn_active = $conn['attributes']['active'] ?? null;
            if ($conn_active === false) {
                myies_log('Skipping inactive connection: ' . ($conn['id'] ?? 'unknown'), 'Section Functions');
                continue;
            }

            $org_uuid = $conn['relationships']['to']['data']['id'] ??
                        $conn['relationships']['organization']['data']['id'] ?? null;

            if (empty($org_uuid)) {
                continue;
            }

            // Get org details from local cache first
            $org = $orgs->get_organization($org_uuid);

            // If not in local cache, try API
            if (empty($org)) {
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

            // Only include section-type organizations
            if ($org && strtolower($org['org_type'] ?? '') === $this->section_type) {
                $sections[] = array(
                    'connection_uuid' => $conn['id'],
                    'org_uuid' => $org_uuid,
                    'legal_name' => $org['legal_name'] ?? 'Unknown',
                    'alternate_name' => $org['alternate_name'] ?? '',
                    'description' => $org['description'] ?? '',
                    'connection_type' => $conn['attributes']['type'] ?? 'member',
                    'is_primary' => ($org_uuid === $primary_section_uuid)
                );
            }
        }

        myies_log('Found ' . count($sections) . ' sections', 'Section Functions');

        return $sections;
    }

    /**
     * Get user's primary/current section
     *
     * @param int|null $user_id User ID (defaults to current user)
     * @return array|null Section data or null if none
     */
    public function get_user_section($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }

        // First check if we have a stored section UUID
        $section_uuid = get_user_meta($user_id, 'wicket_section_uuid', true);

        if (!empty($section_uuid)) {
            // Verify the connection still exists
            $sections = $this->get_user_sections($user_id);
            foreach ($sections as $section) {
                if ($section['org_uuid'] === $section_uuid) {
                    return $section;
                }
            }
            // Connection no longer exists, clear the stored value
            delete_user_meta($user_id, 'wicket_section_uuid');
            delete_user_meta($user_id, 'wicket_section_name');
        }

        // Return first section if any exist
        $sections = $this->get_user_sections($user_id);
        if (!empty($sections)) {
            // Auto-set the first section as primary
            update_user_meta($user_id, 'wicket_section_uuid', $sections[0]['org_uuid']);
            update_user_meta($user_id, 'wicket_section_name', $sections[0]['legal_name']);
            return $sections[0];
        }

        return null;
    }

    /**
     * Search sections in local database
     *
     * @param string $search_term Search term
     * @param int $limit Max results
     * @return array Sections matching search
     */
    public function search_sections($search_term, $limit = 20) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wicket_organizations';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name}
             WHERE org_type = %s
             AND (legal_name LIKE %s OR alternate_name LIKE %s)
             ORDER BY legal_name ASC
             LIMIT %d",
            $this->section_type,
            '%' . $wpdb->esc_like($search_term) . '%',
            '%' . $wpdb->esc_like($search_term) . '%',
            $limit
        ), ARRAY_A);

        return $results ?: array();
    }

    /**
     * Get all data needed for section page template
     *
     * @return array All section page data
     */
    public function get_template_data() {
        $user_id = get_current_user_id();

        if (!$user_id) {
            return array(
                'logged_in' => false,
                'has_wicket_account' => false,
                'sections' => array(),
                'current_section' => null,
                'person_uuid' => null,
                'nonce' => wp_create_nonce('wicket_section_nonce'),
                'ajax_url' => admin_url('admin-ajax.php')
            );
        }

        $person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);

        return array(
            'logged_in' => true,
            'has_wicket_account' => !empty($person_uuid),
            'sections' => $this->get_user_sections($user_id),
            'current_section' => $this->get_user_section($user_id),
            'person_uuid' => $person_uuid,
            'nonce' => wp_create_nonce('wicket_section_nonce'),
            'ajax_url' => admin_url('admin-ajax.php')
        );
    }

    /**
     * Update section user meta for Bricks Dynamic Data
     *
     * @param int $user_id WordPress user ID
     * @param string $section_uuid Section UUID
     */
    private function update_section_user_meta($user_id, $section_uuid) {
        if (empty($section_uuid)) {
            // Clear section meta
            delete_user_meta($user_id, 'wicket_section_uuid');
            delete_user_meta($user_id, 'wicket_section_name');
            delete_user_meta($user_id, 'wicket_section_connection_uuid');
            return;
        }

        myies_log('Updating section user_meta for section: ' . $section_uuid, 'Section Functions');

        // Try to get section details from local cache first
        $orgs = wicket_organizations();
        $section = $orgs->get_organization($section_uuid);

        // If not in local cache, try API
        if (empty($section)) {
            $api = wicket_api();
            $section_api = $api->get_organization($section_uuid);
            if ($section_api && isset($section_api['attributes'])) {
                $section = array(
                    'wicket_uuid' => $section_uuid,
                    'legal_name' => $section_api['attributes']['legal_name'] ?? '',
                    'alternate_name' => $section_api['attributes']['alternate_name'] ?? '',
                    'description' => $section_api['attributes']['description'] ?? ''
                );
            }
        }

        if (empty($section)) {
            myies_log('Could not get section details for: ' . $section_uuid, 'Section Functions');
            update_user_meta($user_id, 'wicket_section_uuid', sanitize_text_field($section_uuid));
            return;
        }

        // Update user meta with section data
        update_user_meta($user_id, 'wicket_section_uuid', sanitize_text_field($section_uuid));
        update_user_meta($user_id, 'wicket_section_name', sanitize_text_field($section['legal_name'] ?? ''));

        myies_log('Updated section user_meta: ' . ($section['legal_name'] ?? $section_uuid), 'Section Functions');
    }

    // =========================================================================
    // AJAX HANDLERS
    // =========================================================================

    /**
     * AJAX: Get user's current section
     */
    public function ajax_get_user_section() {
        check_ajax_referer('wicket_section_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }

        $user_id = get_current_user_id();

        wp_send_json_success(array(
            'sections' => $this->get_user_sections($user_id),
            'current_section' => $this->get_user_section($user_id)
        ));
    }

    /**
     * AJAX: Search sections
     */
    public function ajax_search_sections() {
        check_ajax_referer('wicket_section_nonce', 'nonce');

        $search = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
        if (empty($search)) {
            $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        }

        myies_log('Section search request for: ' . $search, 'Section Functions');

        if (strlen($search) < 2) {
            wp_send_json_success(array('results' => array()));
            return;
        }

        $results = $this->search_sections($search, 20);

        myies_log('Section search found ' . count($results) . ' results', 'Section Functions');

        // Format for easy use in JS
        $formatted = array_map(function($section) {
            return array(
                'id' => $section['wicket_uuid'],
                'wicket_uuid' => $section['wicket_uuid'],
                'text' => $section['legal_name'],
                'legal_name' => $section['legal_name'],
                'alternate_name' => $section['alternate_name'] ?? '',
                'description' => $section['description'] ?? ''
            );
        }, $results);

        wp_send_json_success(array('results' => $formatted));
    }

    /**
     * AJAX: Set/change user's section
     */
    public function ajax_set_section() {
        check_ajax_referer('wicket_section_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }

        $section_uuid = isset($_POST['section_uuid']) ? sanitize_text_field($_POST['section_uuid']) : '';

        if (empty($section_uuid)) {
            wp_send_json_error(array('message' => 'Section UUID required'));
        }

        $user_id = get_current_user_id();
        $person_uuid = get_user_meta($user_id, 'wicket_person_uuid', true);

        if (empty($person_uuid)) {
            $person_uuid = get_user_meta($user_id, 'wicket_uuid', true);
        }

        if (empty($person_uuid)) {
            wp_send_json_error(array('message' => 'User does not have a Wicket account'));
        }

        myies_log('Setting section: ' . $section_uuid . ' for person: ' . $person_uuid, 'Section Functions');

        $api = wicket_api();

        // Check if user already has connection to this section
        $existing_connections = $api->get_person_connections($person_uuid);
        $has_connection = false;

        foreach ($existing_connections as $conn) {
            $conn_org_uuid = $conn['relationships']['to']['data']['id'] ??
                            $conn['relationships']['organization']['data']['id'] ?? null;
            if ($conn_org_uuid === $section_uuid) {
                $has_connection = true;
                myies_log('User already has connection to this section', 'Section Functions');
                break;
            }
        }

        // If no connection exists, create one in Wicket
        if (!$has_connection) {
            myies_log('Creating new connection in Wicket', 'Section Functions');
            $result = $api->create_person_org_connection($person_uuid, $section_uuid, 'member', 'Section member');

            if (!$result['success'] && empty($result['already_existed'])) {
                myies_log('Failed to create connection: ' . ($result['message'] ?? 'Unknown error'), 'Section Functions');
                wp_send_json_error(array('message' => 'Failed to create connection in Wicket: ' . ($result['message'] ?? 'Unknown error')));
            }
        }

        // Save locally
        $this->update_section_user_meta($user_id, $section_uuid);

        myies_log('Section set successfully', 'Section Functions');

        wp_send_json_success(array(
            'message' => 'Section updated successfully',
            'sections' => $this->get_user_sections($user_id),
            'current_section' => $this->get_user_section($user_id)
        ));
    }

    /**
     * AJAX: Leave section (remove connection)
     */
    public function ajax_leave_section() {
        check_ajax_referer('wicket_section_nonce', 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array('message' => 'Not logged in'));
        }

        $connection_uuid = isset($_POST['connection_uuid']) ? sanitize_text_field($_POST['connection_uuid']) : '';
        $section_uuid = isset($_POST['section_uuid']) ? sanitize_text_field($_POST['section_uuid']) : '';

        if (empty($connection_uuid)) {
            wp_send_json_error(array('message' => 'Connection UUID required'));
        }

        $user_id = get_current_user_id();

        myies_log('Leaving section, connection: ' . $connection_uuid, 'Section Functions');

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

            // Clear section meta if this was the user's current section
            $current_section_uuid = get_user_meta($user_id, 'wicket_section_uuid', true);
            if ($current_section_uuid === $section_uuid) {
                $this->update_section_user_meta($user_id, null);

                // Set another section as current if exists
                $sections = $this->get_user_sections($user_id);
                if (!empty($sections)) {
                    $this->update_section_user_meta($user_id, $sections[0]['org_uuid']);
                }
            }

            myies_log('Left section successfully', 'Section Functions');

            wp_send_json_success(array(
                'message' => 'You have left the section',
                'sections' => $this->get_user_sections($user_id),
                'current_section' => $this->get_user_section($user_id)
            ));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
}

// =============================================================================
// GLOBAL HELPER FUNCTIONS
// =============================================================================

/**
 * Get Section Functions instance
 */
function wicket_section() {
    return Wicket_Section_Functions::get_instance();
}

/**
 * Get user's current section
 *
 * @param int|null $user_id
 * @return array|null
 */
function wicket_get_user_section($user_id = null) {
    return wicket_section()->get_user_section($user_id);
}

/**
 * Get user's sections
 *
 * @param int|null $user_id
 * @return array
 */
function wicket_get_user_sections($user_id = null) {
    return wicket_section()->get_user_sections($user_id);
}

/**
 * Search sections in local database
 *
 * @param string $search_term
 * @param int $limit
 * @return array
 */
function wicket_search_sections($search_term, $limit = 20) {
    return wicket_section()->search_sections($search_term, $limit);
}

/**
 * Get all template data for section page
 *
 * @return array
 */
function wicket_section_data() {
    return wicket_section()->get_template_data();
}

/**
 * Get nonce for AJAX calls
 *
 * @return string
 */
function wicket_section_nonce() {
    return wp_create_nonce('wicket_section_nonce');
}

/**
 * Output JavaScript config for section AJAX
 */
function wicket_section_js_config() {
    ?>
    <script>
    var wicketSectionConfig = {
        ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>',
        nonce: '<?php echo wp_create_nonce('wicket_section_nonce'); ?>'
    };
    </script>
    <?php
}

/**
 * Enqueue section page assets
 */
function wicket_section_enqueue_assets() {
    wicket_section()->enqueue_assets();
}

// Initialize
add_action('plugins_loaded', function() {
    wicket_section();
}, 15);
