<?php
/**
 * GitHub Plugin Updater
 *
 * Enables automatic plugin updates from a GitHub repository.
 * When you push updates to your GitHub repo, WordPress will detect
 * the new version and prompt for update.
 *
 * SETUP INSTRUCTIONS:
 * 1. Create releases on GitHub with semantic versioning (e.g., v1.0.1, v1.1.0)
 * 2. The release tag should match the version in the plugin header
 * 3. Optionally create a plugin-info.json in your repo root for custom update details
 *
 * @package MyIES_Integration
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyIES_GitHub_Updater {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * GitHub repository owner/name
     */
    private $github_repo = '';

    /**
     * Plugin slug
     */
    private $plugin_slug = '';

    /**
     * Plugin basename
     */
    private $plugin_basename = '';

    /**
     * Current plugin version
     */
    private $current_version = '';

    /**
     * GitHub API response cache
     */
    private $github_response = null;

    /**
     * Cache expiration in seconds (12 hours)
     */
    private $cache_expiration = 43200;

    /**
     * GitHub access token (optional, for private repos)
     */
    private $access_token = '';

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
     * Initialize the updater
     *
     * @param array $config Configuration array:
     *                      - github_repo: 'owner/repository-name' (required)
     *                      - plugin_file: __FILE__ from main plugin (required)
     *                      - access_token: GitHub token for private repos (optional)
     */
    public function init($config) {
        if (empty($config['github_repo']) || empty($config['plugin_file'])) {
            error_log('[MyIES GitHub Updater] Missing required configuration');
            return false;
        }

        $this->github_repo = $config['github_repo'];
        $this->plugin_basename = plugin_basename($config['plugin_file']);
        $this->plugin_slug = dirname($this->plugin_basename);
        $this->current_version = WICKET_INTEGRATION_VERSION;

        if (!empty($config['access_token'])) {
            $this->access_token = $config['access_token'];
        }

        // Hook into WordPress update system
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);

        // Add "Check for updates" link on plugins page
        add_filter('plugin_action_links_' . $this->plugin_basename, array($this, 'add_action_links'));

        // Handle manual update check
        add_action('admin_init', array($this, 'handle_manual_update_check'));

        return true;
    }

    /**
     * Add action links to plugins page
     */
    public function add_action_links($links) {
        $check_update_url = wp_nonce_url(
            admin_url('plugins.php?myies_check_update=1'),
            'myies_check_update'
        );

        $links['check_update'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url($check_update_url),
            __('Check for updates', 'wicket-integration')
        );

        return $links;
    }

    /**
     * Handle manual update check
     */
    public function handle_manual_update_check() {
        if (!isset($_GET['myies_check_update']) || !current_user_can('update_plugins')) {
            return;
        }

        if (!wp_verify_nonce($_GET['_wpnonce'], 'myies_check_update')) {
            return;
        }

        // Clear cached data
        delete_transient('myies_github_response');
        delete_site_transient('update_plugins');

        // Force update check
        wp_update_plugins();

        // Redirect back with message
        wp_redirect(admin_url('plugins.php?myies_update_checked=1'));
        exit;
    }

    /**
     * Get repository info from GitHub API
     */
    private function get_github_response() {
        // Check cache first
        $cached = get_transient('myies_github_response');
        if ($cached !== false) {
            $this->github_response = $cached;
            return $cached;
        }

        // Build API URL for latest release
        $api_url = sprintf(
            'https://api.github.com/repos/%s/releases/latest',
            $this->github_repo
        );

        $args = array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url()
            )
        );

        // Add authentication for private repos
        if (!empty($this->access_token)) {
            $args['headers']['Authorization'] = 'token ' . $this->access_token;
        }

        $response = wp_remote_get($api_url, $args);

        if (is_wp_error($response)) {
            error_log('[MyIES GitHub Updater] API request failed: ' . $response->get_error_message());
            return false;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            error_log('[MyIES GitHub Updater] API returned status: ' . $status_code);
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body) || !isset($body['tag_name'])) {
            error_log('[MyIES GitHub Updater] Invalid API response');
            return false;
        }

        // Cache for 12 hours
        set_transient('myies_github_response', $body, $this->cache_expiration);

        $this->github_response = $body;
        return $body;
    }

    /**
     * Parse version from tag name (removes 'v' prefix if present)
     */
    private function parse_version($tag_name) {
        return ltrim($tag_name, 'vV');
    }

    /**
     * Check for plugin updates
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $github_response = $this->get_github_response();

        if (!$github_response) {
            return $transient;
        }

        $latest_version = $this->parse_version($github_response['tag_name']);

        // Compare versions
        if (version_compare($latest_version, $this->current_version, '>')) {
            // Get download URL (prefer zipball_url for the full repo)
            $download_url = $github_response['zipball_url'];

            // Check if there's a specific asset (e.g., myies-integration.zip)
            if (!empty($github_response['assets'])) {
                foreach ($github_response['assets'] as $asset) {
                    if (strpos($asset['name'], '.zip') !== false) {
                        $download_url = $asset['browser_download_url'];
                        break;
                    }
                }
            }

            // Add auth token to download URL if private repo
            if (!empty($this->access_token)) {
                $download_url = add_query_arg('access_token', $this->access_token, $download_url);
            }

            $transient->response[$this->plugin_basename] = (object) array(
                'slug' => $this->plugin_slug,
                'plugin' => $this->plugin_basename,
                'new_version' => $latest_version,
                'url' => $github_response['html_url'],
                'package' => $download_url,
                'icons' => array(),
                'banners' => array(),
                'banners_rtl' => array(),
                'tested' => '',
                'requires_php' => '7.4',
                'compatibility' => new stdClass()
            );
        }

        return $transient;
    }

    /**
     * Provide plugin information for the update details popup
     */
    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== $this->plugin_slug) {
            return $result;
        }

        $github_response = $this->get_github_response();

        if (!$github_response) {
            return $result;
        }

        $latest_version = $this->parse_version($github_response['tag_name']);

        // Get download URL
        $download_url = $github_response['zipball_url'];
        if (!empty($github_response['assets'])) {
            foreach ($github_response['assets'] as $asset) {
                if (strpos($asset['name'], '.zip') !== false) {
                    $download_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        // Build plugin info object
        $plugin_info = (object) array(
            'name' => 'MyIES Integration',
            'slug' => $this->plugin_slug,
            'version' => $latest_version,
            'author' => '<a href="https://s-fx.com">S-FX</a>',
            'author_profile' => 'https://s-fx.com',
            'homepage' => 'https://github.com/' . $this->github_repo,
            'short_description' => 'Comprehensive integration between Wicket CRM and WordPress with Paid Memberships Pro support.',
            'sections' => array(
                'description' => $this->get_plugin_description(),
                'changelog' => $this->parse_changelog($github_response['body'] ?? ''),
                'installation' => $this->get_installation_instructions()
            ),
            'download_link' => $download_url,
            'requires' => '5.8',
            'tested' => get_bloginfo('version'),
            'requires_php' => '7.4',
            'last_updated' => $github_response['published_at'] ?? '',
            'downloaded' => 0,
            'active_installs' => 0
        );

        return $plugin_info;
    }

    /**
     * Get plugin description for info popup
     */
    private function get_plugin_description() {
        return '
            <h4>MyIES Integration - Wicket CRM Sync</h4>
            <p>This plugin provides comprehensive integration between Wicket CRM and WordPress, including:</p>
            <ul>
                <li>Automatic user sync on login and registration</li>
                <li>Fluent Forms integration for profile updates</li>
                <li>Organization management and sync</li>
                <li>Bulk user synchronization</li>
                <li>ACF field group support</li>
            </ul>
            <p>Updates are provided via GitHub releases.</p>
        ';
    }

    /**
     * Get installation instructions
     */
    private function get_installation_instructions() {
        return '
            <ol>
                <li>Deactivate the current version if active</li>
                <li>Delete the old plugin folder</li>
                <li>Upload the new plugin files to /wp-content/plugins/</li>
                <li>Activate the plugin</li>
                <li>Verify your Wicket API settings under Settings > Wicket ACF Sync</li>
            </ol>
        ';
    }

    /**
     * Parse changelog from release notes
     */
    private function parse_changelog($release_body) {
        if (empty($release_body)) {
            return '<p>See GitHub releases for full changelog.</p>';
        }

        // Convert markdown to basic HTML
        $changelog = esc_html($release_body);
        $changelog = nl2br($changelog);
        $changelog = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $changelog);
        $changelog = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $changelog);
        $changelog = preg_replace('/^- (.+)$/m', '<li>$1</li>', $changelog);

        return '<div class="changelog">' . $changelog . '</div>';
    }

    /**
     * Handle post-installation (rename folder if needed)
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        // Only process our plugin
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $result;
        }

        // GitHub downloads create folders like owner-repo-hash
        // We need to rename to the correct plugin folder name
        $plugin_folder = WP_PLUGIN_DIR . '/' . $this->plugin_slug;

        // Move the extracted folder to correct location
        if (isset($result['destination'])) {
            $wp_filesystem->move($result['destination'], $plugin_folder);
            $result['destination'] = $plugin_folder;
        }

        // Reactivate the plugin
        activate_plugin($this->plugin_basename);

        return $result;
    }
}

/**
 * Initialize the GitHub Updater
 *
 * To configure, add the following to your wp-config.php for private repos:
 * define('MYIES_GITHUB_TOKEN', 'your-github-personal-access-token');
 */
function myies_init_github_updater() {
    $updater = MyIES_GitHub_Updater::get_instance();

    // Get GitHub token from wp-config if defined
    $access_token = defined('MYIES_GITHUB_TOKEN') ? MYIES_GITHUB_TOKEN : '';

    // Get repository from option or use default
    $github_repo = get_option('myies_github_repo', 'Illuminating-Engineering-Society/MyIES');

    $updater->init(array(
        'github_repo' => $github_repo,
        'plugin_file' => WICKET_INTEGRATION_PLUGIN_FILE,
        'access_token' => $access_token
    ));
}
add_action('admin_init', 'myies_init_github_updater');

// GitHub settings are now integrated into the unified settings page
// in class-wicket-settings-page.php under the "Updates" tab

/**
 * Show admin notice after manual update check
 */
function myies_show_update_check_notice() {
    if (isset($_GET['myies_update_checked'])) {
        $class = 'notice notice-success is-dismissible';
        $message = __('Update check complete. If a new version is available, it will appear in the plugins list.', 'wicket-integration');
        printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
    }
}
add_action('admin_notices', 'myies_show_update_check_notice');
