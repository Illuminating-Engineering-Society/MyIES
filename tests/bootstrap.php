<?php
/**
 * Test bootstrap - provides WordPress stubs for unit testing
 */

// Define WordPress constants that plugin code checks
define('ABSPATH', '/tmp/wordpress/');
define('WICKET_INTEGRATION_PLUGIN_DIR', dirname(__DIR__) . '/');
define('WICKET_INTEGRATION_PLUGIN_FILE', dirname(__DIR__) . '/myies-integration.php');
define('WICKET_INTEGRATION_PLUGIN_URL', 'https://example.com/wp-content/plugins/myies/');
define('WICKET_INTEGRATION_VERSION', '1.0.4-test');
define('MYIES_DEBUG', true);
define('WP_DEBUG', true);

// Stub WordPress functions used in the API helper
if (!function_exists('get_option')) {
    function get_option($key, $default = false) {
        $options = [
            'wicket_tenant_name' => 'test-tenant',
            'wicket_api_secret_key' => 'test-secret-key-1234567890',
            'wicket_admin_user_uuid' => 'test-admin-uuid-1234',
            'wicket_staging' => 0,
        ];
        return $options[$key] ?? $default;
    }
}

if (!function_exists('get_site_url')) {
    function get_site_url() {
        return 'https://example.com';
    }
}

if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key, $single = false) {
        $meta = [
            1 => ['wicket_person_uuid' => 'uuid-1234', 'wicket_uuid' => 'uuid-1234'],
            2 => ['wicket_uuid' => 'uuid-5678'],
            3 => [],
        ];
        $user_meta = $meta[$user_id] ?? [];
        return $single ? ($user_meta[$key] ?? '') : ($user_meta[$key] ?? []);
    }
}

if (!function_exists('update_user_meta')) {
    function update_user_meta($user_id, $key, $value) { return true; }
}

if (!function_exists('get_userdata')) {
    function get_userdata($user_id) {
        if ($user_id === 1) {
            return (object)['ID' => 1, 'user_email' => 'test@example.com'];
        }
        return false;
    }
}

if (!function_exists('get_current_user_id')) {
    function get_current_user_id() { return 1; }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) { return strip_tags(trim($str)); }
}

if (!function_exists('esc_html')) {
    function esc_html($text) { return htmlspecialchars($text, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('apply_filters')) {
    function apply_filters($tag, $value, ...$args) { return $value; }
}

if (!function_exists('add_action')) {
    function add_action($tag, $callback, $priority = 10, $args = 1) {}
}

if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10, $args = 1) {}
}

if (!function_exists('wp_remote_get')) {
    function wp_remote_get($url, $args = []) { return []; }
}

if (!function_exists('wp_remote_post')) {
    function wp_remote_post($url, $args = []) { return []; }
}

if (!function_exists('wp_remote_request')) {
    function wp_remote_request($url, $args = []) { return []; }
}

if (!function_exists('wp_remote_retrieve_response_code')) {
    function wp_remote_retrieve_response_code($response) { return 200; }
}

if (!function_exists('wp_remote_retrieve_body')) {
    function wp_remote_retrieve_body($response) { return ''; }
}

if (!function_exists('is_wp_error')) {
    function is_wp_error($thing) {
        return $thing instanceof WP_Error;
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error {
        private $code;
        private $message;
        private $data;
        public function __construct($code = '', $message = '', $data = '') {
            $this->code = $code;
            $this->message = $message;
            $this->data = $data;
        }
        public function get_error_message() { return $this->message; }
        public function get_error_code() { return $this->code; }
    }
}

// Load the API helper (our primary test target)
require_once WICKET_INTEGRATION_PLUGIN_DIR . 'includes/fluent-forms/class-wicket-api-helper.php';
