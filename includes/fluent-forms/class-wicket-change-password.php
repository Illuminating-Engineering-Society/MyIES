<?php
/**
 * Wicket Change Password Handler
 *
 * Handles password change via Fluent Forms submission.
 * Sends current_password, password, and password_confirmation to the Wicket API
 * and updates the WordPress password on success.
 *
 * Form ID is configured in MyIES Controls > API Configuration
 * under the option 'myies_form_change_password'.
 *
 * Fluent Form fields expected:
 *   - current_password
 *   - new_password
 *   - confirm_password
 *
 * @package MyIES_Integration
 * @since 1.0.19
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_Change_Password_Sync {

    private $form_id;

    public function __construct() {
        $this->form_id = (int) get_option('myies_form_change_password', 0);

        if ($this->form_id) {
            add_action('fluentform/submission_inserted', array($this, 'handle_password_change'), 20, 3);
        }
    }

    /**
     * Handle password change form submission
     */
    public function handle_password_change($entry_id, $form_data, $form) {
        if ($form->id != $this->form_id) {
            return;
        }

        error_log('[Wicket Change Password] Processing form ' . $this->form_id);
        error_log('[Wicket Change Password] Form data keys: ' . implode(', ', array_keys($form_data)));

        $user_id = get_current_user_id();
        if (!$user_id) {
            error_log('[Wicket Change Password] No logged-in user');
            return;
        }

        $current_password = $form_data['current_password'] ?? '';
        $new_password     = $form_data['new_password'] ?? '';
        $confirm_password = $form_data['confirm_password'] ?? '';

        if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
            error_log('[Wicket Change Password] Missing required fields. Keys received: ' . implode(', ', array_keys($form_data)));
            return;
        }

        if ($new_password !== $confirm_password) {
            error_log('[Wicket Change Password] Password confirmation does not match');
            return;
        }

        // Verify current password against WordPress.
        $user = get_user_by('id', $user_id);
        if (!$user || !wp_check_password($current_password, $user->user_pass, $user_id)) {
            error_log('[Wicket Change Password] Current password verification failed');
            return;
        }

        $person_uuid = wicket_api()->get_person_uuid($user_id);
        if (empty($person_uuid)) {
            error_log('[Wicket Change Password] Could not find person UUID');
            return;
        }

        // Update password in Wicket API.
        $result = $this->update_wicket_password($person_uuid, $current_password, $new_password);

        if ($result['success']) {
            error_log('[Wicket Change Password] Wicket password updated successfully');

            // Update WordPress password to stay in sync.
            wp_set_password($new_password, $user_id);

            // Re-authenticate the user so they don't get logged out.
            wp_set_current_user($user_id);
            wp_set_auth_cookie($user_id, true);

            error_log('[Wicket Change Password] WordPress password updated');
        } else {
            error_log('[Wicket Change Password] Wicket update failed: ' . $result['message']);
        }
    }

    /**
     * Update password in Wicket API via PATCH /people/{uuid}
     */
    private function update_wicket_password($person_uuid, $current_password, $new_password) {
        $attributes = array(
            'user' => array(
                'current_password'      => $current_password,
                'password'              => $new_password,
                'password_confirmation'  => $new_password,
            ),
        );

        return wicket_api()->update_person($person_uuid, $attributes);
    }
}

new Wicket_Change_Password_Sync();
