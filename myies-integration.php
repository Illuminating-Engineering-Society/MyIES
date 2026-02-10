<?php
/**
 * Plugin Name: MyIES Integration
 * Plugin URI: https://s-fx.com
 * Description: Comprehensive integration between Wicket CRM and WordPress with Paid Memberships Pro support
 * Version: 1.0.12
 * Author: S-FX.com Small Business Solutions
 * Author URI: https://s-fx.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wicket-integration
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WICKET_INTEGRATION_VERSION', '1.0.12');
define('WICKET_INTEGRATION_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WICKET_INTEGRATION_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WICKET_INTEGRATION_PLUGIN_FILE', __FILE__);

/**
 * Main Wicket Integration Class
 */
class Wicket_Integration {
    
    /**
     * Instance of this class
     */
    private static $instance = null;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        // GitHub Updater (must load early for update checks)
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/class-github-updater.php';

        // Core functionality
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/class-person-details.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/class-wicket-bulk-sync.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/class-wicket-sync-on-user.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/class-wicket-person-auto-create.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/class-wicket-organizations.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/class-wicket-memberships.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/class-wicket-memberships-bricks.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/class-surecart-wicket-sync.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/membership-history-modal.php';


        // Admin & Settings
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/admin/class-wicket-settings-page.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/admin/class-hide-utilities-for-users.php';
        
        // Frontend
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/shortcodes/class-person-details-shortcode.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/shortcodes/shortcode-membership-history.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/shortcodes/class-surecart-shortcodes.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/shortcodes/class-sustaining-company-select.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/shortcodes/class-org-management.php';

        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/frontend/class-wicket-sign-up.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/frontend/class-profile-edit-mode.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/frontend/class-company-functions.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/frontend/class-section-functions.php';

        // Fluent Forms Handlers
     
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-wicket-error-handler.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-wicket-api-helper.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-wicket-additional-info.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-wicket-contact-details.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-additional-info-education.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-organization-information.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-wicket-communication-preferences.php';

        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-wicket-personal-details.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-wicket-professional-info.php';
        require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-wicket-add-address.php';
        //require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/debug-fluent-forms-all.php';
    }
    
    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Activation/Deactivation hooks
        register_activation_hook(WICKET_INTEGRATION_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(WICKET_INTEGRATION_PLUGIN_FILE, array($this, 'deactivate'));
        
        // Load text domain
        add_action('plugins_loaded', array($this, 'load_textdomain'));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {

        // Create organization tables
        if (class_exists('Wicket_Organizations')) {
            $orgs = Wicket_Organizations::get_instance();
            $orgs->create_tables();
        }

        // Create memberships table
        if (class_exists('Wicket_Memberships')) {
            $memberships = Wicket_Memberships::get_instance();
            $memberships->create_table();
        }
        
        flush_rewrite_rules();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled cron job
        $timestamp = wp_next_scheduled('wicket_weekly_org_sync');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'wicket_weekly_org_sync');
        }
        
        flush_rewrite_rules();
    }
    
    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'wicket-integration',
            false,
            dirname(plugin_basename(WICKET_INTEGRATION_PLUGIN_FILE)) . '/languages'
        );
    }
}

/**
 * Initialize the plugin
 */
function wicket_integration_init() {
    return Wicket_Integration::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'wicket_integration_init', 5);
