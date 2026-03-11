<?php
/**
 * Wicket Account Merge
 *
 * Merges a source WordPress account's data into a target account (the one
 * Wicket uses for login). After the merge the source account can be safely
 * deleted or left deactivated.
 *
 * Data moved:
 *   - wp_posts (post_author)
 *   - wp_comments (user_id)
 *   - wp_usermeta (selective, skips wicket UUID keys on target)
 *   - wp_wicket_user_memberships (wp_user_id)
 *   - wp_wicket_person_org_connections (wp_user_id)
 *   - wp_fluentform_submissions (user_id) if table exists
 *
 * @package MyIES_Integration
 * @since 1.0.21
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_Account_Merge {

    /** Meta keys that must NEVER be copied from source to target. */
    private const PROTECTED_META_KEYS = [
        'wicket_person_uuid',
        'wicket_uuid',
    ];

    /** Meta keys that should be copied only if the target has no value. */
    private const FILL_ONLY_META_KEYS = [
        'wicket_primary_org_uuid',
        'wicket_org_uuid',
        'wicket_person_membership_uuid',
        'wicket_org_membership_uuid',
        'wicket_sustaining_org_map',
    ];

    public function __construct() {
        add_action('wp_ajax_wicket_merge_accounts', [$this, 'ajax_merge']);
        add_action('wp_ajax_wicket_merge_preview', [$this, 'ajax_preview']);
    }

    /**
     * AJAX: Preview what a merge would do (dry run).
     */
    public function ajax_preview() {
        check_ajax_referer('wicket_merge_accounts', '_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $target_id = (int) ($_POST['target_id'] ?? 0);
        $source_id = (int) ($_POST['source_id'] ?? 0);

        if (!$target_id || !$source_id || $target_id === $source_id) {
            wp_send_json_error('Invalid user IDs');
        }

        $preview = $this->build_preview($target_id, $source_id);
        wp_send_json_success($preview);
    }

    /**
     * AJAX: Execute the merge.
     */
    public function ajax_merge() {
        check_ajax_referer('wicket_merge_accounts', '_nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }

        $target_id = (int) ($_POST['target_id'] ?? 0);
        $source_id = (int) ($_POST['source_id'] ?? 0);
        $delete_source = !empty($_POST['delete_source']);

        if (!$target_id || !$source_id || $target_id === $source_id) {
            wp_send_json_error('Invalid user IDs');
        }

        $target = get_userdata($target_id);
        $source = get_userdata($source_id);
        if (!$target || !$source) {
            wp_send_json_error('One or both users not found');
        }

        $result = $this->execute_merge($target_id, $source_id, $delete_source);
        wp_send_json_success($result);
    }

    /**
     * Build a preview of what the merge will do.
     */
    public function build_preview(int $target_id, int $source_id): array {
        global $wpdb;

        $target = get_userdata($target_id);
        $source = get_userdata($source_id);

        $preview = [
            'target' => [
                'ID'       => $target_id,
                'login'    => $target->user_login,
                'email'    => $target->user_email,
                'display'  => $target->display_name,
            ],
            'source' => [
                'ID'       => $source_id,
                'login'    => $source->user_login,
                'email'    => $source->user_email,
                'display'  => $source->display_name,
            ],
            'actions' => [],
        ];

        // Posts
        $post_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d",
            $source_id
        ));
        if ($post_count) {
            $preview['actions'][] = "Reassign {$post_count} post(s) from source to target";
        }

        // Comments
        $comment_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->comments} WHERE user_id = %d",
            $source_id
        ));
        if ($comment_count) {
            $preview['actions'][] = "Reassign {$comment_count} comment(s) from source to target";
        }

        // Wicket memberships table
        $memberships_table = $wpdb->prefix . 'wicket_user_memberships';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$memberships_table}'") === $memberships_table) {
            $mem_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$memberships_table} WHERE wp_user_id = %d",
                $source_id
            ));
            if ($mem_count) {
                $preview['actions'][] = "Reassign {$mem_count} row(s) in wicket_user_memberships";
            }
        }

        // Wicket org connections table
        $connections_table = $wpdb->prefix . 'wicket_person_org_connections';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$connections_table}'") === $connections_table) {
            $conn_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$connections_table} WHERE wp_user_id = %d",
                $source_id
            ));
            if ($conn_count) {
                $preview['actions'][] = "Reassign {$conn_count} row(s) in wicket_person_org_connections";
            }
        }

        // Fluent Forms submissions
        $ff_table = $wpdb->prefix . 'fluentform_submissions';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$ff_table}'") === $ff_table) {
            $ff_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$ff_table} WHERE user_id = %d",
                $source_id
            ));
            if ($ff_count) {
                $preview['actions'][] = "Reassign {$ff_count} Fluent Forms submission(s)";
            }
        }

        // User meta to copy
        $source_meta = get_user_meta($source_id);
        $meta_to_copy = [];
        foreach ($source_meta as $key => $values) {
            if (in_array($key, self::PROTECTED_META_KEYS, true)) {
                continue;
            }
            // Skip WordPress internal session tokens
            if ($key === 'session_tokens') {
                continue;
            }
            $target_value = get_user_meta($target_id, $key, true);
            if (in_array($key, self::FILL_ONLY_META_KEYS, true)) {
                if (!empty($target_value)) {
                    continue; // target already has a value
                }
            }
            $source_value = $values[0] ?? '';
            if ($source_value !== '' && $source_value !== $target_value) {
                $meta_to_copy[] = $key;
            }
        }

        if (!empty($meta_to_copy)) {
            $preview['actions'][] = 'Copy ' . count($meta_to_copy) . ' user meta key(s): ' . implode(', ', array_slice($meta_to_copy, 0, 10)) . (count($meta_to_copy) > 10 ? '...' : '');
        }

        // Email sync note
        if ($source->user_email !== $target->user_email && !empty($source->user_email)) {
            $preview['actions'][] = "Note: Source email ({$source->user_email}) differs from target ({$target->user_email}). Target email will NOT be changed. If SureCart orders are linked to the source email, you may need to update the SureCart customer email manually.";
        }

        if (empty($preview['actions'])) {
            $preview['actions'][] = 'No data to merge — source account has no data to move.';
        }

        return $preview;
    }

    /**
     * Execute the merge: move all source data to target.
     */
    public function execute_merge(int $target_id, int $source_id, bool $delete_source = false): array {
        global $wpdb;

        $log = [];

        // 1. Reassign posts
        $posts_updated = $wpdb->update(
            $wpdb->posts,
            ['post_author' => $target_id],
            ['post_author' => $source_id],
            ['%d'],
            ['%d']
        );
        if ($posts_updated) {
            $log[] = "Reassigned {$posts_updated} post(s)";
        }

        // 2. Reassign comments
        $comments_updated = $wpdb->update(
            $wpdb->comments,
            ['user_id' => $target_id],
            ['user_id' => $source_id],
            ['%d'],
            ['%d']
        );
        if ($comments_updated) {
            $log[] = "Reassigned {$comments_updated} comment(s)";
        }

        // 3. Wicket memberships table
        $memberships_table = $wpdb->prefix . 'wicket_user_memberships';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$memberships_table}'") === $memberships_table) {
            // Delete target's existing rows to avoid unique key conflicts, then move source rows
            $wpdb->delete($memberships_table, ['wp_user_id' => $target_id], ['%d']);
            $mem_moved = $wpdb->update(
                $memberships_table,
                ['wp_user_id' => $target_id],
                ['wp_user_id' => $source_id],
                ['%d'],
                ['%d']
            );
            if ($mem_moved) {
                $log[] = "Reassigned {$mem_moved} wicket_user_memberships row(s)";
            }
        }

        // 4. Wicket org connections table
        $connections_table = $wpdb->prefix . 'wicket_person_org_connections';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$connections_table}'") === $connections_table) {
            $wpdb->delete($connections_table, ['wp_user_id' => $target_id], ['%d']);
            $conn_moved = $wpdb->update(
                $connections_table,
                ['wp_user_id' => $target_id],
                ['wp_user_id' => $source_id],
                ['%d'],
                ['%d']
            );
            if ($conn_moved) {
                $log[] = "Reassigned {$conn_moved} wicket_person_org_connections row(s)";
            }
        }

        // 5. Fluent Forms submissions
        $ff_table = $wpdb->prefix . 'fluentform_submissions';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$ff_table}'") === $ff_table) {
            $ff_moved = $wpdb->update(
                $ff_table,
                ['user_id' => $target_id],
                ['user_id' => $source_id],
                ['%d'],
                ['%d']
            );
            if ($ff_moved) {
                $log[] = "Reassigned {$ff_moved} Fluent Forms submission(s)";
            }
        }

        // 6. Copy user meta (selective)
        $source_meta = get_user_meta($source_id);
        $meta_copied = 0;
        foreach ($source_meta as $key => $values) {
            if (in_array($key, self::PROTECTED_META_KEYS, true)) {
                continue;
            }
            if ($key === 'session_tokens') {
                continue;
            }

            $target_value = get_user_meta($target_id, $key, true);

            if (in_array($key, self::FILL_ONLY_META_KEYS, true)) {
                if (!empty($target_value)) {
                    continue;
                }
            }

            $source_value = $values[0] ?? '';
            if ($source_value !== '' && $source_value !== $target_value) {
                update_user_meta($target_id, $key, maybe_unserialize($source_value));
                $meta_copied++;
            }
        }
        if ($meta_copied) {
            $log[] = "Copied {$meta_copied} user meta key(s)";
        }

        // 7. Clear Wicket UUID meta from source to prevent future confusion
        delete_user_meta($source_id, 'wicket_person_uuid');
        delete_user_meta($source_id, 'wicket_uuid');
        $log[] = 'Cleared Wicket UUID meta from source account';

        // 8. Optionally delete source account
        if ($delete_source) {
            // WordPress require this file for wp_delete_user
            require_once ABSPATH . 'wp-admin/includes/user.php';
            $deleted = wp_delete_user($source_id, $target_id); // reassign remaining content to target
            if ($deleted) {
                $log[] = "Deleted source account #{$source_id}";
            } else {
                $log[] = "Failed to delete source account #{$source_id}";
            }
        } else {
            $log[] = "Source account #{$source_id} kept (UUID meta cleared)";
        }

        // 9. Trigger membership re-sync on the target account
        if (function_exists('wicket_sync_user_memberships')) {
            wicket_sync_user_memberships($target_id);
            $log[] = 'Triggered membership re-sync on target account';
        }

        error_log('[WICKET MERGE] Merged user #' . $source_id . ' into #' . $target_id . ': ' . implode('; ', $log));

        return [
            'target_id' => $target_id,
            'source_id' => $source_id,
            'log'       => $log,
        ];
    }
}

new Wicket_Account_Merge();
