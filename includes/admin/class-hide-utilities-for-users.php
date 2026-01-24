<?php
// Hide admin bar for non-admin/non-editor users
function hide_admin_bar_for_non_privileged_users() {
    if (!current_user_can('edit_others_posts')) {
        show_admin_bar(false);
        remove_action('wp_head', '_admin_bar_bump_cb');
    }
}
add_action('wp_loaded', 'hide_admin_bar_for_non_privileged_users');

// Redirect non-admin/non-editor users away from dashboard
function redirect_non_privileged_users_from_admin() {
    // Allow AJAX requests to pass through
    if (defined('DOING_AJAX') && DOING_AJAX) {
        return;
    }
    
    // Check if user can edit others' posts (editors and admins only)
    if (!current_user_can('edit_others_posts')) {
        // Redirect to homepage or custom page
        wp_redirect(home_url());
        exit;
    }
}
add_action('admin_init', 'redirect_non_privileged_users_from_admin');

// Optional: Redirect after login for non-privileged users
function redirect_non_privileged_users_after_login($redirect_to, $request, $user) {
    // Check if user exists and doesn't have edit_others_posts capability
    if (isset($user->roles) && is_array($user->roles)) {
        if (!user_can($user, 'edit_others_posts')) {
            return home_url();
        }
    }
    return $redirect_to;
}
add_filter('login_redirect', 'redirect_non_privileged_users_after_login', 10, 3);