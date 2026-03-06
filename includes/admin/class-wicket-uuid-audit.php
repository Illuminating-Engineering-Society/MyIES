<?php
/**
 * Wicket UUID Audit — Bulk Scan
 *
 * Instant local scan (no API calls) that:
 * 1. Finds UUID mismatches (wicket_person_uuid / wicket_uuid != user_login)
 * 2. Finds non-UUID accounts that have Wicket UUID meta (potential duplicates)
 * 3. Groups accounts by Wicket UUID to expose duplicates sharing the same person
 * 4. Shows which accounts have email and which don't
 *
 * @package MyIES_Integration
 * @since 1.0.20
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_UUID_Audit {

    private function is_uuid($str) {
        return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $str);
    }

    /**
     * Run the local scan and return structured results.
     */
    private function run_scan() {
        $users = get_users(array(
            'fields' => array('ID', 'user_login', 'user_email', 'display_name'),
        ));

        // Map: wicket_uuid => array of account info
        $uuid_map = array();
        $mismatches = array();
        $non_uuid_with_meta = array();

        foreach ($users as $user) {
            $stored_uuid   = get_user_meta($user->ID, 'wicket_person_uuid', true);
            $stored_legacy = get_user_meta($user->ID, 'wicket_uuid', true);
            $effective_uuid = !empty($stored_uuid) ? $stored_uuid : $stored_legacy;
            $is_uuid_login = $this->is_uuid($user->user_login);
            $has_email     = !empty($user->user_email);

            $account_info = array(
                'user_id'        => $user->ID,
                'username'       => $user->user_login,
                'email'          => $user->user_email,
                'has_email'      => $has_email,
                'display_name'   => $user->display_name,
                'is_uuid_login'  => $is_uuid_login,
                'stored_uuid'    => $stored_uuid,
                'stored_legacy'  => $stored_legacy,
            );

            // Track UUID mismatches (UUID username but meta doesn't match)
            if ($is_uuid_login) {
                $uuid_ok   = !empty($stored_uuid) && $stored_uuid === $user->user_login;
                $legacy_ok = !empty($stored_legacy) && $stored_legacy === $user->user_login;
                if (!$uuid_ok || !$legacy_ok) {
                    $account_info['issues'] = array();
                    if (empty($stored_uuid))                     $account_info['issues'][] = 'wicket_person_uuid empty';
                    elseif ($stored_uuid !== $user->user_login)  $account_info['issues'][] = 'wicket_person_uuid mismatch';
                    if (empty($stored_legacy))                   $account_info['issues'][] = 'wicket_uuid empty';
                    elseif ($stored_legacy !== $user->user_login) $account_info['issues'][] = 'wicket_uuid mismatch';
                    $mismatches[] = $account_info;
                }
            }

            // Track non-UUID accounts that have Wicket meta
            if (!$is_uuid_login && !empty($effective_uuid)) {
                $non_uuid_with_meta[] = $account_info;
            }

            // Group by effective UUID for duplicate detection
            if (!empty($effective_uuid)) {
                if (!isset($uuid_map[$effective_uuid])) {
                    $uuid_map[$effective_uuid] = array();
                }
                $uuid_map[$effective_uuid][] = $account_info;
            }
        }

        // Filter uuid_map to only duplicates (more than 1 account per UUID)
        $duplicates = array();
        foreach ($uuid_map as $uuid => $accounts) {
            if (count($accounts) > 1) {
                $duplicates[$uuid] = $accounts;
            }
        }

        return array(
            'total_users'        => count($users),
            'mismatches'         => $mismatches,
            'non_uuid_with_meta' => $non_uuid_with_meta,
            'duplicates'         => $duplicates,
        );
    }

    /**
     * Render the admin page.
     */
    public function render_page() {
        $results = null;
        $scanned = isset($_GET['scan']) && $_GET['scan'] === '1';

        if ($scanned) {
            $results = $this->run_scan();
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('UUID Audit', 'wicket-integration'); ?></h1>
            <p><?php esc_html_e('Instant local scan — no API calls. Finds UUID mismatches, duplicate accounts sharing the same Wicket person, and non-UUID accounts with Wicket meta.', 'wicket-integration'); ?></p>

            <div class="card" style="max-width:1400px; margin-top:20px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=myies-uuid-audit&scan=1')); ?>" class="button button-primary">
                    <?php esc_html_e('Run Scan', 'wicket-integration'); ?>
                </a>
            </div>

            <?php if ($results): ?>

            <!-- Summary -->
            <div class="card" style="max-width:1400px; margin-top:20px;">
                <h2><?php esc_html_e('Summary', 'wicket-integration'); ?></h2>
                <table class="widefat" style="max-width:500px;">
                    <tbody>
                        <tr>
                            <td><?php esc_html_e('Total users scanned', 'wicket-integration'); ?></td>
                            <td><strong><?php echo intval($results['total_users']); ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('UUID mismatches (meta != username)', 'wicket-integration'); ?></td>
                            <td><strong style="color:<?php echo count($results['mismatches']) > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo count($results['mismatches']); ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Non-UUID accounts with Wicket meta', 'wicket-integration'); ?></td>
                            <td><strong style="color:<?php echo count($results['non_uuid_with_meta']) > 0 ? '#dba617' : '#00a32a'; ?>;"><?php echo count($results['non_uuid_with_meta']); ?></strong></td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e('Wicket UUIDs shared by multiple accounts', 'wicket-integration'); ?></td>
                            <td><strong style="color:<?php echo count($results['duplicates']) > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo count($results['duplicates']); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- Duplicates (most important) -->
            <?php if (!empty($results['duplicates'])): ?>
            <div class="card" style="max-width:1400px; margin-top:20px;">
                <h2 style="color:#d63638;"><?php printf(esc_html__('Duplicate Accounts (%d Wicket UUIDs shared)', 'wicket-integration'), count($results['duplicates'])); ?></h2>
                <p><?php esc_html_e('These Wicket person UUIDs are linked to more than one WordPress account.', 'wicket-integration'); ?></p>

                <?php foreach ($results['duplicates'] as $uuid => $accounts): ?>
                <div style="margin-bottom:20px; padding:12px; border:2px solid #d63638; border-radius:4px; background:#fef0f0;">
                    <h3 style="margin-top:0;">
                        <?php esc_html_e('Wicket UUID:', 'wicket-integration'); ?>
                        <code><?php echo esc_html($uuid); ?></code>
                        <span style="color:#666; font-weight:normal; font-size:13px;">
                            — <?php printf(esc_html__('%d accounts', 'wicket-integration'), count($accounts)); ?>
                        </span>
                    </h3>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('User ID', 'wicket-integration'); ?></th>
                                <th><?php esc_html_e('Username', 'wicket-integration'); ?></th>
                                <th><?php esc_html_e('Email', 'wicket-integration'); ?></th>
                                <th><?php esc_html_e('Display Name', 'wicket-integration'); ?></th>
                                <th><?php esc_html_e('UUID Login?', 'wicket-integration'); ?></th>
                                <th><?php esc_html_e('wicket_person_uuid', 'wicket-integration'); ?></th>
                                <th><?php esc_html_e('wicket_uuid', 'wicket-integration'); ?></th>
                                <th><?php esc_html_e('Actions', 'wicket-integration'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($accounts as $a): ?>
                            <tr>
                                <td><?php echo intval($a['user_id']); ?></td>
                                <td>
                                    <code><?php echo esc_html($a['username']); ?></code>
                                    <?php if (!$a['is_uuid_login']): ?>
                                        <span style="color:#dba617;"> (not UUID)</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($a['has_email']): ?>
                                        <?php echo esc_html($a['email']); ?>
                                    <?php else: ?>
                                        <span style="color:#d63638; font-weight:bold;"><?php esc_html_e('(no email)', 'wicket-integration'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($a['display_name']); ?></td>
                                <td><?php echo $a['is_uuid_login'] ? '<span style="color:#00a32a;">Yes</span>' : '<span style="color:#dba617;">No</span>'; ?></td>
                                <td><code style="font-size:11px;"><?php echo esc_html($a['stored_uuid'] ?: '(empty)'); ?></code></td>
                                <td><code style="font-size:11px;"><?php echo esc_html($a['stored_legacy'] ?: '(empty)'); ?></code></td>
                                <td>
                                    <a href="<?php echo esc_url(get_edit_user_link($a['user_id'])); ?>" class="button button-small">
                                        <?php esc_html_e('Edit', 'wicket-integration'); ?>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- UUID Mismatches -->
            <?php if (!empty($results['mismatches'])): ?>
            <div class="card" style="max-width:1400px; margin-top:20px;">
                <h2 style="color:#d63638;"><?php printf(esc_html__('UUID Mismatches (%d)', 'wicket-integration'), count($results['mismatches'])); ?></h2>
                <p><?php esc_html_e('These users have a UUID as their username, but their stored wicket_person_uuid or wicket_uuid does not match.', 'wicket-integration'); ?></p>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('User ID', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Username (correct UUID)', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Email', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('wicket_person_uuid', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('wicket_uuid', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Issues', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Actions', 'wicket-integration'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['mismatches'] as $m): ?>
                        <tr>
                            <td><?php echo intval($m['user_id']); ?></td>
                            <td><code><?php echo esc_html($m['username']); ?></code></td>
                            <td>
                                <?php if ($m['has_email']): ?>
                                    <?php echo esc_html($m['email']); ?>
                                <?php else: ?>
                                    <span style="color:#d63638; font-weight:bold;"><?php esc_html_e('(no email)', 'wicket-integration'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><code style="color:<?php echo ($m['stored_uuid'] === $m['username']) ? '#00a32a' : '#d63638'; ?>;"><?php echo esc_html($m['stored_uuid'] ?: '(empty)'); ?></code></td>
                            <td><code style="color:<?php echo ($m['stored_legacy'] === $m['username']) ? '#00a32a' : '#d63638'; ?>;"><?php echo esc_html($m['stored_legacy'] ?: '(empty)'); ?></code></td>
                            <td>
                                <?php foreach ($m['issues'] as $issue): ?>
                                    <span style="color:#d63638;"><?php echo esc_html($issue); ?></span><br>
                                <?php endforeach; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(get_edit_user_link($m['user_id'])); ?>" class="button button-small">
                                    <?php esc_html_e('Edit', 'wicket-integration'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <!-- Non-UUID accounts with Wicket meta -->
            <?php if (!empty($results['non_uuid_with_meta'])): ?>
            <div class="card" style="max-width:1400px; margin-top:20px;">
                <h2 style="color:#dba617;"><?php printf(esc_html__('Non-UUID Accounts with Wicket Meta (%d)', 'wicket-integration'), count($results['non_uuid_with_meta'])); ?></h2>
                <p><?php esc_html_e('These accounts have a regular username (not a UUID) but have wicket_person_uuid or wicket_uuid stored. They may be duplicates.', 'wicket-integration'); ?></p>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('User ID', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Username', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Email', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Display Name', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('wicket_person_uuid', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('wicket_uuid', 'wicket-integration'); ?></th>
                            <th><?php esc_html_e('Actions', 'wicket-integration'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results['non_uuid_with_meta'] as $n): ?>
                        <tr>
                            <td><?php echo intval($n['user_id']); ?></td>
                            <td><code><?php echo esc_html($n['username']); ?></code></td>
                            <td>
                                <?php if ($n['has_email']): ?>
                                    <?php echo esc_html($n['email']); ?>
                                <?php else: ?>
                                    <span style="color:#d63638; font-weight:bold;"><?php esc_html_e('(no email)', 'wicket-integration'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($n['display_name']); ?></td>
                            <td><code style="font-size:11px;"><?php echo esc_html($n['stored_uuid'] ?: '(empty)'); ?></code></td>
                            <td><code style="font-size:11px;"><?php echo esc_html($n['stored_legacy'] ?: '(empty)'); ?></code></td>
                            <td>
                                <a href="<?php echo esc_url(get_edit_user_link($n['user_id'])); ?>" class="button button-small">
                                    <?php esc_html_e('Edit', 'wicket-integration'); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

            <?php if ($results && empty($results['mismatches']) && empty($results['non_uuid_with_meta']) && empty($results['duplicates'])): ?>
            <div class="card" style="max-width:1400px; margin-top:20px;">
                <p style="color:#00a32a; font-weight:bold; font-size:16px;"><?php esc_html_e('All clean — no issues found.', 'wicket-integration'); ?></p>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>

        <style>
        .card { background:#fff; border:1px solid #c3c4c7; border-radius:4px; padding:20px; }
        </style>
        <?php
    }
}
