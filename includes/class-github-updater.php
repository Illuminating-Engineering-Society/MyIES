<?php
/**
 * GitHub Plugin Updater
 *
 * Enables automatic plugin updates from a GitHub repository.
 * Detects new versions by reading the plugin header from the main branch,
 * so any push that bumps the version triggers a WordPress update.
 *
 * For PRIVATE repositories, add to wp-config.php:
 *   define('MYIES_GITHUB_TOKEN', 'ghp_your_personal_access_token');
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
     * Default branch name
     */
    private $branch = 'main';

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
     * Cached remote data (in-memory)
     */
    private $remote_data = null;

    /**
     * Cache expiration in seconds (6 hours)
     */
    private $cache_expiration = 21600;

    /**
     * GitHub access token (optional, for private repos)
     */
    private $access_token = '';

    /**
     * Transient key for caching GitHub response
     */
    private $cache_key = 'myies_github_response';

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
     *                      - branch: branch to track, defaults to 'main' (optional)
     */
    public function init($config) {
        if (empty($config['github_repo']) || empty($config['plugin_file'])) {
            $this->log('Missing required configuration (github_repo or plugin_file)');
            return false;
        }

        $this->github_repo = $config['github_repo'];
        $this->plugin_basename = plugin_basename($config['plugin_file']);
        $this->plugin_slug = dirname($this->plugin_basename);
        $this->current_version = WICKET_INTEGRATION_VERSION;

        if (!empty($config['access_token'])) {
            $this->access_token = $config['access_token'];
        }

        if (!empty($config['branch'])) {
            $this->branch = $config['branch'];
        }

        // Hook into WordPress update system
        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_for_update'));
        add_filter('plugins_api', array($this, 'plugin_info'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);

        // Inject auth header into download requests for our repo
        add_filter('http_request_args', array($this, 'inject_auth_header'), 10, 2);

        // Add "Check for updates" link on plugins page
        add_filter('plugin_action_links_' . $this->plugin_basename, array($this, 'add_action_links'));

        // Handle manual update check
        add_action('admin_init', array($this, 'handle_manual_update_check'));

        // Clear our cache when WordPress forces an update check
        add_action('load-update-core.php', array($this, 'clear_cache_on_wp_check'));
        add_action('wp_update_plugins', array($this, 'clear_cache_on_wp_check'));

        return true;
    }

    /**
     * Helper to log messages
     */
    private function log($message) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[MyIES GitHub Updater] ' . $message);
        }
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
     * Handle manual update check from plugins page link
     */
    public function handle_manual_update_check() {
        if (!isset($_GET['myies_check_update']) || !current_user_can('update_plugins')) {
            return;
        }

        if (!wp_verify_nonce($_GET['_wpnonce'], 'myies_check_update')) {
            return;
        }

        // Clear all cached data
        $this->clear_cache();

        // Force update check
        wp_update_plugins();

        // Redirect back with message
        wp_redirect(admin_url('plugins.php?myies_update_checked=1'));
        exit;
    }

    /**
     * Clear our GitHub response cache when WordPress forces an update check
     * (e.g. Dashboard > Updates > "Check Again")
     */
    public function clear_cache_on_wp_check() {
        $this->clear_cache();
    }

    /**
     * Clear all cached update data
     */
    private function clear_cache() {
        delete_transient($this->cache_key);
        delete_site_transient('update_plugins');
        $this->remote_data = null;
        $this->log('Cache cleared');
    }

    /**
     * Build standard headers for GitHub API requests
     */
    private function api_headers() {
        $headers = array(
            'Accept'     => 'application/vnd.github.v3+json',
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
        );

        if (!empty($this->access_token)) {
            $headers['Authorization'] = 'Bearer ' . $this->access_token;
        }

        return $headers;
    }

    /**
     * Inject authorization header into download requests for our GitHub repo.
     * This replaces the deprecated ?access_token= query parameter approach.
     */
    public function inject_auth_header($args, $url) {
        if (empty($this->access_token)) {
            return $args;
        }

        // Only inject for requests to our repo
        $repo_url = 'api.github.com/repos/' . $this->github_repo;
        if (strpos($url, $repo_url) === false && strpos($url, 'github.com/' . $this->github_repo) === false) {
            return $args;
        }

        if (!isset($args['headers'])) {
            $args['headers'] = array();
        }

        $args['headers']['Authorization'] = 'Bearer ' . $this->access_token;
        $args['headers']['Accept'] = 'application/octet-stream';

        return $args;
    }

    /**
     * Fetch the remote plugin version from the main branch.
     *
     * Reads myies-integration.php via the GitHub Contents API and
     * parses the "Version:" line from the plugin header.
     */
    private function get_remote_version() {
        $api_url = sprintf(
            'https://api.github.com/repos/%s/contents/myies-integration.php?ref=%s',
            $this->github_repo,
            $this->branch
        );

        $response = wp_remote_get($api_url, array(
            'timeout' => 15,
            'headers' => $this->api_headers(),
        ));

        if (is_wp_error($response)) {
            $this->log('API request failed: ' . $response->get_error_message());
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);

        if ($code === 404) {
            $this->log('Repository or file not found (404). If this is a private repo, define MYIES_GITHUB_TOKEN in wp-config.php');
            return false;
        }

        if ($code === 401 || $code === 403) {
            $this->log('Authentication failed (' . $code . '). Check your MYIES_GITHUB_TOKEN in wp-config.php');
            return false;
        }

        if ($code !== 200) {
            $this->log('Unexpected response status: ' . $code);
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (empty($body['content'])) {
            $this->log('Remote file has no content');
            return false;
        }

        // GitHub returns base64-encoded content
        $file_content = base64_decode($body['content']);

        // Parse "Version: x.y.z" from the plugin header block
        if (preg_match('/^\s*\*?\s*Version:\s*(.+)$/mi', $file_content, $matches)) {
            $version = trim($matches[1]);
            $this->log('Remote version detected: ' . $version . ' (local: ' . $this->current_version . ')');
            return $version;
        }

        $this->log('Could not parse version from remote file');
        return false;
    }

    /**
     * Optionally fetch the latest release for changelog info.
     * Returns null if no release exists (this is fine).
     */
    private function get_latest_release() {
        $api_url = sprintf(
            'https://api.github.com/repos/%s/releases/latest',
            $this->github_repo
        );

        $response = wp_remote_get($api_url, array(
            'timeout' => 10,
            'headers' => $this->api_headers(),
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        return json_decode(wp_remote_retrieve_body($response), true);
    }

    /**
     * Get combined remote data (version from branch, changelog from release).
     * Result is cached for 6 hours.
     */
    private function get_remote_data() {
        // Return in-memory cache
        if ($this->remote_data !== null) {
            return $this->remote_data;
        }

        // Check transient cache
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            $this->remote_data = $cached;
            return $cached;
        }

        $this->log('Fetching remote version from GitHub (' . $this->github_repo . '@' . $this->branch . ')');

        // 1. Read version from the branch
        $remote_version = $this->get_remote_version();
        if (!$remote_version) {
            // Cache a negative result for 1 hour to avoid hammering on errors
            set_transient($this->cache_key, array('version' => false), HOUR_IN_SECONDS);
            return false;
        }

        // 2. Build download URL (zipball of the tracked branch)
        $download_url = sprintf(
            'https://api.github.com/repos/%s/zipball/%s',
            $this->github_repo,
            $this->branch
        );

        // 3. Assemble response
        $data = array(
            'version'      => $remote_version,
            'download_url' => $download_url,
            'url'          => sprintf('https://github.com/%s', $this->github_repo),
            'changelog'    => '',
            'published_at' => '',
        );

        // 4. Try to enrich with release info (changelog, assets)
        $release = $this->get_latest_release();
        if ($release) {
            $data['changelog']    = $release['body'] ?? '';
            $data['published_at'] = $release['published_at'] ?? '';

            // Prefer a zip asset from the release if the release version matches
            $release_version = ltrim($release['tag_name'] ?? '', 'vV');
            if ($release_version === $remote_version && !empty($release['assets'])) {
                foreach ($release['assets'] as $asset) {
                    if (strpos($asset['name'], '.zip') !== false) {
                        $data['download_url'] = $asset['browser_download_url'];
                        break;
                    }
                }
            }
        }

        // Cache for 6 hours
        set_transient($this->cache_key, $data, $this->cache_expiration);
        $this->remote_data = $data;

        return $data;
    }

    /**
     * Check for plugin updates
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $remote = $this->get_remote_data();

        if (!$remote || empty($remote['version']) || $remote['version'] === false) {
            return $transient;
        }

        if (version_compare($remote['version'], $this->current_version, '>')) {
            $this->log('Update available: ' . $this->current_version . ' -> ' . $remote['version']);

            $transient->response[$this->plugin_basename] = (object) array(
                'slug'         => $this->plugin_slug,
                'plugin'       => $this->plugin_basename,
                'new_version'  => $remote['version'],
                'url'          => $remote['url'],
                'package'      => $remote['download_url'],
                'icons'        => array(),
                'banners'      => array(),
                'banners_rtl'  => array(),
                'tested'       => get_bloginfo('version'),
                'requires_php' => '7.4',
                'compatibility' => new stdClass(),
            );
        } else {
            // Tell WordPress this plugin is up to date (prevents "unknown" status)
            $transient->no_update[$this->plugin_basename] = (object) array(
                'slug'        => $this->plugin_slug,
                'plugin'      => $this->plugin_basename,
                'new_version' => $this->current_version,
                'url'         => $remote['url'],
                'package'     => '',
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

        $remote = $this->get_remote_data();
        if (!$remote || empty($remote['version']) || $remote['version'] === false) {
            return $result;
        }

        return (object) array(
            'name'              => 'MyIES Integration',
            'slug'              => $this->plugin_slug,
            'version'           => $remote['version'],
            'author'            => '<a href="https://s-fx.com">S-FX</a>',
            'author_profile'    => 'https://s-fx.com',
            'homepage'          => 'https://github.com/' . $this->github_repo,
            'short_description' => 'Comprehensive integration between Wicket CRM and WordPress with Paid Memberships Pro support.',
            'sections'          => array(
                'description'  => $this->get_plugin_description(),
                'changelog'    => $this->parse_changelog($remote['changelog']),
                'installation' => $this->get_installation_instructions(),
            ),
            'download_link'     => $remote['download_url'],
            'requires'          => '5.8',
            'tested'            => get_bloginfo('version'),
            'requires_php'      => '7.4',
            'last_updated'      => $remote['published_at'],
            'downloaded'        => 0,
            'active_installs'   => 0,
        );
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
            <p>Updates are detected automatically when a new version is pushed to the main branch.</p>
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
            return '<p>See the <a href="https://github.com/' . esc_attr($this->github_repo) . '/commits/' . esc_attr($this->branch) . '">commit history</a> for details.</p>';
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
     * Handle post-installation (rename folder from GitHub's owner-repo-hash format)
     */
    public function after_install($response, $hook_extra, $result) {
        global $wp_filesystem;

        // Only process our plugin
        if (!isset($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $result;
        }

        $plugin_folder = WP_PLUGIN_DIR . '/' . $this->plugin_slug;
        $source = isset($result['destination']) ? $result['destination'] : '';

        // If the extracted folder doesn't match our expected plugin folder, rename it
        if (!empty($source) && $source !== $plugin_folder) {
            // Remove the old plugin folder if it exists
            if ($wp_filesystem->is_dir($plugin_folder)) {
                $wp_filesystem->delete($plugin_folder, true);
            }

            $wp_filesystem->move($source, $plugin_folder);
            $result['destination'] = $plugin_folder;
            $result['destination_name'] = $this->plugin_slug;

            $this->log('Renamed extracted folder to: ' . $this->plugin_slug);
        }

        // Clear update cache so version refreshes
        $this->clear_cache();

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
        'github_repo'  => $github_repo,
        'plugin_file'  => WICKET_INTEGRATION_PLUGIN_FILE,
        'access_token' => $access_token,
        'branch'       => 'main',
    ));
}
add_action('admin_init', 'myies_init_github_updater');

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

/**
 * Show admin warning if MYIES_GITHUB_TOKEN is not configured
 */
function myies_github_token_notice() {
    if (!current_user_can('manage_options')) {
        return;
    }

    // Only show on plugins page and updates page
    $screen = get_current_screen();
    if (!$screen || !in_array($screen->id, array('plugins', 'update-core'), true)) {
        return;
    }

    if (defined('MYIES_GITHUB_TOKEN') && !empty(MYIES_GITHUB_TOKEN)) {
        return;
    }

    // Check if the repo is private by testing the API
    $test_cached = get_transient('myies_github_repo_public');
    if ($test_cached === 'yes') {
        return; // Repo is public, no token needed
    }

    if ($test_cached === false) {
        // Test if repo is public
        $github_repo = get_option('myies_github_repo', 'Illuminating-Engineering-Society/MyIES');
        $test_url = sprintf('https://api.github.com/repos/%s', $github_repo);
        $test = wp_remote_get($test_url, array(
            'timeout' => 5,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version'),
            ),
        ));

        if (!is_wp_error($test) && wp_remote_retrieve_response_code($test) === 200) {
            set_transient('myies_github_repo_public', 'yes', DAY_IN_SECONDS);
            return;
        }

        set_transient('myies_github_repo_public', 'no', HOUR_IN_SECONDS);
    }

    printf(
        '<div class="notice notice-warning"><p><strong>MyIES Integration:</strong> %s</p><p><code>define(\'MYIES_GITHUB_TOKEN\', \'ghp_your_token_here\');</code></p></div>',
        esc_html__('Automatic updates require a GitHub Personal Access Token for private repositories. Add this to your wp-config.php:', 'wicket-integration')
    );
}
add_action('admin_notices', 'myies_github_token_notice');
