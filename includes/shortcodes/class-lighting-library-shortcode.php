<?php
/**
 * Lighting Library Membership Shortcode
 *
 * Displays the current user's Lighting Library Full Access membership status.
 *
 * Usage:
 *   [myies_lighting_library]
 *
 * @package MyIES_Integration
 * @since 1.0.17
 */

if (!defined('ABSPATH')) {
    exit;
}

class Lighting_Library_Shortcode {

    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('myies_lighting_library', [$this, 'render_shortcode']);
    }

    /**
     * Render the shortcode
     */
    public function render_shortcode($atts) {
        $user_id = get_current_user_id();

        if (!$user_id) {
            return '<p class="wicket-membership-message">Please log in to view your Lighting Library membership.</p>';
        }

        $memberships = wicket_get_user_memberships($user_id);
        $match = $this->find_lighting_library_membership($memberships);

        if (!$match) {
            return '<p class="wicket-membership-message wicket-membership-message--empty">You do not currently have a Lighting Library membership.</p>';
        }

        return $this->render_card($match);
    }

    /**
     * Find the Lighting Library Full Access membership from the user's memberships
     */
    private function find_lighting_library_membership($memberships) {
        if (empty($memberships)) {
            return null;
        }

        foreach ($memberships as $m) {
            $slug = sanitize_title($m['membership_tier_name']);
            if ($slug === 'lighting-library-full-access') {
                return $m;
            }
        }

        return null;
    }

    /**
     * Render the membership card
     */
    private function render_card($m) {
        ob_start();
        ?>
        <div class="wicket-lighting-library-card">
            <h3 class="wicket-lighting-library-card__title">
                <?php echo esc_html($m['membership_tier_name'] ?: 'Lighting Library Full Access'); ?>
            </h3>
            <div class="wicket-lighting-library-card__details">
                <div class="wicket-lighting-library-card__row">
                    <span class="wicket-lighting-library-card__label">Status</span>
                    <span class="wicket-status-badge wicket-status-badge--<?php echo esc_attr(strtolower($m['status'])); ?>">
                        <span class="wicket-status-badge__dot"></span>
                        <?php echo esc_html($m['status']); ?>
                    </span>
                </div>
                <div class="wicket-lighting-library-card__row">
                    <span class="wicket-lighting-library-card__label">Start Date</span>
                    <span><?php echo esc_html($m['starts_formatted']); ?></span>
                </div>
                <div class="wicket-lighting-library-card__row">
                    <span class="wicket-lighting-library-card__label">End Date</span>
                    <span><?php echo esc_html($m['expires_formatted']); ?></span>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}

// Initialize
add_action('init', function() {
    Lighting_Library_Shortcode::get_instance();
});
