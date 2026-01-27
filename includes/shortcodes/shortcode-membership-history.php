<?php
/**
 * Wicket Membership History Shortcode
 * 
 * Displays membership history in a table format.
 * 
 * Usage:
 *   [wicket_membership_history type="person"]
 *   [wicket_membership_history type="organization"]
 * 
 * @package MyIES_Integration
 * @since 1.0.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_Membership_History_Shortcode {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_shortcode('wicket_membership_history', [$this, 'render_shortcode']);
    }
    
    /**
     * Render the shortcode
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts([
            'type' => 'person', // 'person' or 'organization'
            'user_id' => null,
        ], $atts);
        
        $user_id = $atts['user_id'] ? intval($atts['user_id']) : get_current_user_id();
        
        if (!$user_id) {
            return '<p class="wicket-membership-message">Please log in to view membership history.</p>';
        }
        
        $type = sanitize_text_field($atts['type']);
        
        if ($type === 'organization' || $type === 'org') {
            $memberships = wicket_get_user_org_memberships($user_id);
            return $this->render_org_table($memberships);
        } else {
            $memberships = wicket_get_user_person_memberships($user_id);
            return $this->render_person_table($memberships);
        }
    }
    
    /**
     * Render person membership history table
     */
    private function render_person_table($memberships) {
        if (empty($memberships)) {
            return '<p class="wicket-membership-message wicket-membership-message--empty">No membership history found.</p>';
        }
        
        ob_start();
        ?>
        <table class="wicket-history-table">
            <thead>
                <tr>
                    <th>Membership</th>
                    <th>Status</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($memberships as $m): ?>
                <tr class="<?php echo !$m['is_active'] ? 'wicket-history-row--inactive' : ''; ?>">
                    <td>
                        <strong><?php echo esc_html($m['membership_tier_name'] ?: 'Membership'); ?></strong>
                    </td>
                    <td>
                        <span class="wicket-status-badge wicket-status-badge--<?php echo esc_attr(strtolower($m['status'])); ?>">
                            <span class="wicket-status-badge__dot"></span>
                            <?php echo esc_html($m['status']); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($m['starts_formatted']); ?></td>
                    <td><?php echo esc_html($m['expires_formatted']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Render organization membership history table
     */
    private function render_org_table($memberships) {
        if (empty($memberships)) {
            return '<p class="wicket-membership-message wicket-membership-message--empty">No organizational membership history found.</p>';
        }
        
        ob_start();
        ?>
        <table class="wicket-history-table">
            <thead>
                <tr>
                    <th>Organization</th>
                    <th>Tier</th>
                    <th>Status</th>
                    <th>Slots</th>
                    <th>End Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($memberships as $m): ?>
                <tr class="<?php echo !$m['is_active'] ? 'wicket-history-row--inactive' : ''; ?>">
                    <td>
                        <strong><?php echo esc_html($m['organization_name']); ?></strong>
                    </td>
                    <td><?php echo esc_html($m['membership_tier_name']); ?></td>
                    <td>
                        <span class="wicket-status-badge wicket-status-badge--<?php echo esc_attr(strtolower($m['status'])); ?>">
                            <span class="wicket-status-badge__dot"></span>
                            <?php echo esc_html($m['status']); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html($m['slots_display'] ?? '—'); ?></td>
                    <td><?php echo esc_html($m['expires_formatted']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    }
}

// Initialize
add_action('init', function() {
    Wicket_Membership_History_Shortcode::get_instance();
});