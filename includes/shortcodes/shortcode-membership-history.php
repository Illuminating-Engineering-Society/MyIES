<?php
/**
 * Wicket Membership History Shortcodes
 *
 * Displays membership history using Bricks grid style with renewal buttons.
 *
 * Usage:
 *   [wicket_individual_membership_history]
 *   [wicket_org_membership_history]
 *
 * @package MyIES_Integration
 * @since 1.0.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_Membership_History_Shortcode {

    private static $instance = null;

    /**
     * Renewal URLs keyed by Wicket membership_tier_name.
     */
    private $individual_renew_urls = [
        'Student'                => 'https://ies.org/products/student-member/',
        'Emerging Professional'  => 'https://ies.org/products/emerging-professional/',
        'Member Grade'           => 'https://ies.org/products/professional-membership/',
    ];

    private $org_renew_urls = [
        'Champion'    => 'https://ies.org/products/membership-diamond/',
        'Ambassador'  => 'https://ies.org/products/membership-platinum/',
        'Benefactor'  => 'https://ies.org/products/membership-gold/',
        'Supporter'   => 'https://ies.org/products/membership-silver/',
        'Contributor' => 'https://ies.org/products/membership-bronze/',
    ];

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('wicket_individual_membership_history', [$this, 'render_individual']);
        add_shortcode('wicket_org_membership_history', [$this, 'render_org']);
    }

    /**
     * Get user ID from shortcode attributes.
     */
    private function get_user_id($atts) {
        $atts = shortcode_atts([
            'user_id' => null,
        ], $atts);

        return $atts['user_id'] ? intval($atts['user_id']) : get_current_user_id();
    }

    /**
     * Match a tier name to its base key in a URL map using prefix matching.
     *
     * E.g. "Emerging Professional Year 2" matches "Emerging Professional".
     * Returns the matched key or null.
     */
    private function match_tier($tier_name, $url_map) {
        if (isset($url_map[$tier_name])) {
            return $tier_name;
        }
        foreach (array_keys($url_map) as $key) {
            if (str_starts_with($tier_name, $key)) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Check if a membership should show a renew button.
     *
     * Returns true when the membership is expired or expires within 1 month.
     */
    private function should_show_renew($membership) {
        if (empty($membership['ends_at'])) {
            return false;
        }

        $ends_at = strtotime($membership['ends_at']);
        $one_month_from_now = strtotime('+1 month');

        return $ends_at <= $one_month_from_now;
    }

    /**
     * Shortcode: [wicket_individual_membership_history]
     */
    public function render_individual($atts) {
        $user_id = $this->get_user_id($atts);

        if (!$user_id) {
            return '<p class="wicket-membership-message">Please log in to view membership history.</p>';
        }

        $memberships = wicket_get_user_person_memberships($user_id);

        // Only show tiers that match a known renew URL (prefix match).
        $memberships = array_filter($memberships, function ($m) {
            return $this->match_tier($m['membership_tier_name'] ?? '', $this->individual_renew_urls) !== null;
        });

        return $this->render_person_grid($memberships);
    }

    /**
     * Shortcode: [wicket_org_membership_history]
     */
    public function render_org($atts) {
        $user_id = $this->get_user_id($atts);

        if (!$user_id) {
            return '<p class="wicket-membership-message">Please log in to view membership history.</p>';
        }

        $memberships = wicket_get_user_org_memberships($user_id);

        // Only show tiers that match a known renew URL (prefix match).
        $memberships = array_filter($memberships, function ($m) {
            return $this->match_tier($m['membership_tier_name'] ?? '', $this->org_renew_urls) !== null;
        });

        return $this->render_org_grid($memberships);
    }

    /**
     * Render person membership history in Bricks grid style
     */
    private function render_person_grid($memberships) {
        ob_start();
        ?>
        <div class="brxe-block membership-info">
            <div class="brxe-block func-head-viewall">
                <h3 class="brxe-heading current-section">
                    <?php esc_html_e('Individual Membership', 'wicket-integration'); ?>
                </h3>
            </div>

            <div class="brxe-block dash-func-container">
                <?php if (empty($memberships)): ?>
                    <div class="brxe-block grid-subs">
                        <div class="brxe-block ins-grid-desc">
                            <div class="brxe-text-basic subs-desc">
                                <?php esc_html_e('No membership history found.', 'wicket-integration'); ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($memberships as $m):
                        $status_class = $m['is_active'] ? 'active-status-sub' : 'expired-status-sub';
                    ?>
                        <div class="brxe-block grid-subs">

                            <!-- Membership Name & Status -->
                            <div class="brxe-block ins-grid-desc">
                                <div class="brxe-block subs-stat">
                                    <h3 class="brxe-heading inside-func">
                                        <?php echo esc_html($m['membership_tier_name'] ?: 'Membership'); ?>
                                    </h3>
                                    <span class="brxe-text-link <?php echo esc_attr($status_class); ?>">
                                        <span class="icon"><i class="fas fa-circle"></i></span>
                                        <span class="text"><?php echo esc_html($m['status']); ?></span>
                                    </span>
                                </div>
                                <div class="brxe-text-basic subs-desc">
                                    <?php echo esc_html($m['starts_formatted']); ?> — <?php echo esc_html($m['expires_formatted']); ?>
                                </div>
                            </div>

                            <!-- End Date -->
                            <div class="brxe-block ins-grid-desc">
                                <div class="brxe-block subs-stat">
                                    <h3 class="brxe-heading inside-func">
                                        <?php esc_html_e('Expires', 'wicket-integration'); ?>
                                    </h3>
                                </div>
                                <div class="brxe-text-basic subs-desc">
                                    <?php echo esc_html($m['expires_formatted']); ?>
                                </div>
                            </div>

                            <!-- Renew Button -->
                            <div class="brxe-block ins-grid-desc">
                                <div class="brxe-block subs-stat">
                                    <?php if ($this->should_show_renew($m)):
                                        $matched_key = $this->match_tier($m['membership_tier_name'], $this->individual_renew_urls);
                                        $renew_url = $matched_key ? $this->individual_renew_urls[$matched_key] : '';
                                        if ($renew_url):
                                    ?>
                                        <a class="brxe-button change-org-btn bricks-button bricks-background-primary"
                                           href="<?php echo esc_url($renew_url); ?>">
                                            <?php esc_html_e('Renew', 'wicket-integration'); ?>
                                        </a>
                                    <?php endif; endif; ?>
                                </div>
                            </div>

                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render organization membership history in Bricks grid style
     */
    private function render_org_grid($memberships) {
        ob_start();
        ?>
        <div class="brxe-block membership-info">
            <div class="brxe-block func-head-viewall">
                <h3 class="brxe-heading current-section">
                    <?php esc_html_e('Organizational Membership', 'wicket-integration'); ?>
                </h3>
            </div>

            <div class="brxe-block dash-func-container">
                <?php if (empty($memberships)): ?>
                    <div class="brxe-block grid-subs">
                        <div class="brxe-block ins-grid-desc">
                            <div class="brxe-text-basic subs-desc">
                                <?php esc_html_e('No organizational membership history found.', 'wicket-integration'); ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <?php foreach ($memberships as $m):
                        $status_class = $m['is_active'] ? 'active-status-sub' : 'expired-status-sub';
                        $display_tier = wicket_get_display_membership_name($m['membership_tier_name'], 'organization');
                    ?>
                        <div class="brxe-block grid-subs">

                            <!-- Organization & Tier -->
                            <div class="brxe-block ins-grid-desc">
                                <div class="brxe-block subs-stat">
                                    <h3 class="brxe-heading inside-func">
                                        <?php echo esc_html($m['organization_name']); ?>
                                    </h3>
                                    <span class="brxe-text-link <?php echo esc_attr($status_class); ?>">
                                        <span class="icon"><i class="fas fa-circle"></i></span>
                                        <span class="text"><?php echo esc_html($m['status']); ?></span>
                                    </span>
                                </div>
                                <div class="brxe-text-basic subs-desc">
                                    <?php echo esc_html($display_tier); ?>
                                    <?php if (!empty($m['slots_display']) && $m['slots_display'] !== '—'): ?>
                                        &middot; <?php echo esc_html($m['slots_display']); ?> <?php esc_html_e('slots', 'wicket-integration'); ?>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- End Date -->
                            <div class="brxe-block ins-grid-desc">
                                <div class="brxe-block subs-stat">
                                    <h3 class="brxe-heading inside-func">
                                        <?php esc_html_e('Expires', 'wicket-integration'); ?>
                                    </h3>
                                </div>
                                <div class="brxe-text-basic subs-desc">
                                    <?php echo esc_html($m['expires_formatted']); ?>
                                </div>
                            </div>

                            <!-- Renew Button -->
                            <div class="brxe-block ins-grid-desc">
                                <div class="brxe-block subs-stat">
                                    <?php if ($this->should_show_renew($m)):
                                        $matched_key = $this->match_tier($m['membership_tier_name'], $this->org_renew_urls);
                                        $renew_url = $matched_key ? $this->org_renew_urls[$matched_key] : '';
                                        if ($renew_url):
                                    ?>
                                        <a class="brxe-button change-org-btn bricks-button bricks-background-primary"
                                           href="<?php echo esc_url($renew_url); ?>">
                                            <?php esc_html_e('Renew', 'wicket-integration'); ?>
                                        </a>
                                    <?php endif; endif; ?>
                                </div>
                            </div>

                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Initialize
add_action('init', function() {
    Wicket_Membership_History_Shortcode::get_instance();
});
