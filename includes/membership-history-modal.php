<?php
/**
 * Wicket Membership History - Modal Support
 *
 * AJAX handler to render membership history shortcode in modal.
 * Enqueue function for modal assets.
 *
 * Usage in Bricks:
 * 1. Add Code element with: <?php wicket_membership_enqueue_assets(); ?>
 * 2. Add button/link with class: wicket-view-membership-history
 *    Required: data-type="person" or data-type="organization"
 *
 * @package MyIES_Integration
 * @since 1.0.4
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register scripts and styles
 */
add_action('wp_enqueue_scripts', 'wicket_membership_register_assets');

function wicket_membership_register_assets() {
    $plugin_url = defined('MYIES_PLUGIN_URL') ? MYIES_PLUGIN_URL : plugin_dir_url(dirname(__FILE__));
    $version = defined('MYIES_VERSION') ? MYIES_VERSION : '1.0.4';

    // Register CSS
    wp_register_style(
        'wicket-membership-history',
        $plugin_url . 'assets/css/membership-history.css',
        [],
        $version
    );

    // Register JS
    wp_register_script(
        'wicket-membership-history',
        $plugin_url . 'assets/js/modal-membership-history.js',
        ['jquery'],
        $version,
        true
    );
}

/**
 * Register AJAX handlers
 */
add_action('wp_ajax_wicket_render_membership_history', 'wicket_ajax_render_membership_history');
add_action('wp_ajax_nopriv_wicket_render_membership_history', 'wicket_ajax_render_membership_history_nopriv');

/**
 * AJAX handler - Render membership history shortcode
 */
function wicket_ajax_render_membership_history() {
    // Verify nonce
    if (!isset($_GET['nonce']) || !wp_verify_nonce($_GET['nonce'], 'wicket_membership_history')) {
        wp_send_json_error(['message' => 'Security check failed.']);
        return;
    }

    if (!is_user_logged_in()) {
        wp_send_json_error(['message' => 'Please log in to view membership history.']);
        return;
    }

    $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'person';
    
    // Validate type
    if (!in_array($type, ['person', 'organization', 'org'])) {
        $type = 'person';
    }

    // Render the existing shortcode
    $html = do_shortcode('[wicket_membership_history type="' . $type . '"]');

    wp_send_json_success(['html' => $html]);
}

/**
 * AJAX handler for non-logged in users
 */
function wicket_ajax_render_membership_history_nopriv() {
    wp_send_json_error(['message' => 'Please log in to view membership history.']);
}

/**
 * Enqueue membership history modal assets
 *
 * Call this function in Bricks Code element:
 * <?php wicket_membership_enqueue_assets(); ?>
 */
function wicket_membership_enqueue_assets() {
    wp_enqueue_style('wicket-membership-history');
    wp_enqueue_script('wicket-membership-history');

    // Localize script
    wp_localize_script('wicket-membership-history', 'wicketMembershipConfig', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('wicket_membership_history')
    ]);
}