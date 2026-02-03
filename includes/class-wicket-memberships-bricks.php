<?php
/**
 * Wicket Memberships - Bricks Dynamic Data Tags
 * 
 * Registers custom dynamic data tags for Bricks Builder.
 * 
 * Usage in Bricks:
 *   {wicket_membership:person_tier_name}
 *   {wicket_membership:person_status}
 *   {wicket_membership:person_status_class}
 *   {wicket_membership:person_expires}
 *   {wicket_membership:org_name}
 *   {wicket_membership:org_tier_name}
 *   {wicket_membership:org_status}
 *   {wicket_membership:org_expires}
 *   {wicket_membership:org_slots}
 *   {wicket_membership:has_person_active}  (returns '1' or '' for conditions)
 *   {wicket_membership:has_org_active}
 *   {wicket_membership:has_any}
 *   {wicket_membership:person_count}
 *   {wicket_membership:org_count}
 *   {wicket_membership:person_product_url}  (returns SureCart product URL for person's membership tier)
 *   {wicket_membership:person_expires_soon}  (returns '1' if person's membership expires within 30 days)
 * 
 * @package MyIES_Integration
 * @since 1.0.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_Memberships_Bricks_Tags {
    
    private static $instance = null;
    private $cached_data = null;
    private $cached_user_id = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_filter('bricks/dynamic_tags_list', [$this, 'register_tags']);
        add_filter('bricks/dynamic_data/render_tag', [$this, 'render_tag'], 10, 3);
        add_filter('bricks/dynamic_data/render_content', [$this, 'render_content'], 10, 3);
    }
    
    /**
     * Get cached membership data
     */
    private function get_data($user_id = null) {
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        if (!$user_id) {
            return null;
        }
        
        if ($this->cached_user_id === $user_id && $this->cached_data !== null) {
            return $this->cached_data;
        }
        
        $this->cached_data = wicket_memberships_data($user_id);
        $this->cached_user_id = $user_id;
        
        return $this->cached_data;
    }
    
    /**
     * Register tags with Bricks
     */
    public function register_tags($tags) {
        $group = 'Wicket Memberships';
        
        // Person membership tags
        $tags[] = ['name' => '{wicket_membership:person_tier_name}', 'label' => 'Person Membership: Tier Name', 'group' => $group];
        $tags[] = ['name' => '{wicket_membership:person_status}', 'label' => 'Person Membership: Status', 'group' => $group];
        $tags[] = ['name' => '{wicket_membership:person_status_class}', 'label' => 'Person Membership: Status CSS Class', 'group' => $group];
        $tags[] = ['name' => '{wicket_membership:person_expires}', 'label' => 'Person Membership: Expires', 'group' => $group];
        $tags[] = ['name' => '{wicket_membership:person_starts}', 'label' => 'Person Membership: Start Date', 'group' => $group];
        
        // Org membership tags
        $tags[] = ['name' => '{wicket_membership:org_name}', 'label' => 'Org Membership: Organization Name', 'group' => $group];
        $tags[] = ['name' => '{wicket_membership:org_tier_name}', 'label' => 'Org Membership: Tier Name', 'group' => $group];
        $tags[] = ['name' => '{wicket_membership:org_status}', 'label' => 'Org Membership: Status', 'group' => $group];
        $tags[] = ['name' => '{wicket_membership:org_status_class}', 'label' => 'Org Membership: Status CSS Class', 'group' => $group];
        $tags[] = ['name' => '{wicket_membership:org_expires}', 'label' => 'Org Membership: Expires', 'group' => $group];
        $tags[] = ['name' => '{wicket_membership:org_slots}', 'label' => 'Org Membership: Slots (e.g., 2/9)', 'group' => $group];
        $tags[] = ['name' => '{wicket_membership:org_slots_assigned}', 'label' => 'Org Membership: Slots Assigned', 'group' => $group];
        $tags[] = ['name' => '{wicket_membership:org_slots_max}', 'label' => 'Org Membership: Slots Max', 'group' => $group];
        
        // Conditional tags (return '1' or '')
        $tags[] = ['name' => '{wicket_membership:has_person_active}', 'label' => 'Has Active Person Membership (1/empty)', 'group' => $group];
        $tags[] = ['name' => '{wicket_membership:has_org_active}', 'label' => 'Has Active Org Membership (1/empty)', 'group' => $group];
        $tags[] = ['name' => '{wicket_membership:has_any}', 'label' => 'Has Any Membership (1/empty)', 'group' => $group];
        $tags[] = ['name' => '{wicket_membership:has_any_active}', 'label' => 'Has Any Active Membership (1/empty)', 'group' => $group];
        
        // Counts
        $tags[] = ['name' => '{wicket_membership:person_count}', 'label' => 'Person Membership: Total Count', 'group' => $group];
        $tags[] = ['name' => '{wicket_membership:org_count}', 'label' => 'Org Membership: Total Count', 'group' => $group];
        
        // SureCart Product URL
        $tags[] = ['name' => '{wicket_membership:person_product_url}', 'label' => 'Person Membership: SureCart Product URL', 'group' => $group];
        $tags[] = ['name' => '{wicket_membership:person_expires_soon}', 'label' => 'Person Membership: Expires Within 30 Days (1/empty)', 'group' => $group];
        return $tags;
    }
    
    /**
     * Render single tag
     */
    public function render_tag($tag, $post, $context) {
        // Ensure $tag is a string
        if (!is_string($tag)) {
            return $tag;
        }
        
        if (strpos($tag, 'wicket_membership:') !== 0) {
            return $tag;
        }
        
        $field = str_replace('wicket_membership:', '', $tag);
        return $this->get_value($field);
    }
    
    /**
     * Render tags in content
     */
    public function render_content($content, $post, $context) {
        // Ensure $content is a string
        if (!is_string($content)) {
            return $content;
        }
        
        if (strpos($content, '{wicket_membership:') === false) {
            return $content;
        }
        
        $self = $this;
        return preg_replace_callback(
            '/\{wicket_membership:([a-z_]+)\}/',
            function($matches) use ($self) {
                return $self->get_value($matches[1]);
            },
            $content
        );
    }
    
    /**
     * Get field value
     */
    private function get_value($field) {
        $data = $this->get_data();
        
        if (!$data || !$data['logged_in']) {
            return '';
        }
        
        $person = $data['person_active'];
        $org = $data['org_active'];
        
        switch ($field) {
            // Person fields
            case 'person_tier_name':
                return $person['membership_tier_name'] ?? '';
            case 'person_status':
                return $person['status'] ?? '';
            case 'person_status_class':
                return $person ? strtolower($person['status']) : '';
            case 'person_expires':
                return $person['expires_formatted'] ?? '';
            case 'person_starts':
                return $person['starts_formatted'] ?? '';
                
            // Org fields
            case 'org_name':
                return $org['organization_name'] ?? '';
            case 'org_tier_name':
                $tier_name = $org['membership_tier_name'] ?? '';
                return wicket_get_display_membership_name($tier_name, 'organization');
            case 'org_status':
                return $org['status'] ?? '';
            case 'org_status_class':
                return $org ? strtolower($org['status']) : '';
            case 'org_expires':
                return $org['expires_formatted'] ?? '';
            case 'org_slots':
                return $org['slots_display'] ?? '';
            case 'org_slots_assigned':
                return $org['active_assignments_count'] ?? '';
            case 'org_slots_max':
                return $org['max_assignments'] ?? '';
                
            // Conditionals
            case 'has_person_active':
                return $person ? '1' : '';
            case 'has_org_active':
                return $org ? '1' : '';
            case 'has_any':
                return $data['has_memberships'] ? '1' : '';
            case 'has_any_active':
                return $data['has_active'] ? '1' : '';
                
            // Counts
            case 'person_count':
                return (string) count($data['person_memberships']);
            case 'org_count':
                return (string) count($data['org_memberships']);

            // SureCart Product URL
            case 'person_product_url':
                $tier_name = $person['membership_tier_name'] ?? '';
                if (empty($tier_name)) {
                    return '#';
                }
                return $this->get_surecart_product_url($tier_name) ?: '#';

            // Check if person membership expires within 30 days
            case 'person_expires_soon':
                if (!$person || empty($person['ends_at'])) {
                    return '';
                }
                $ends_at = strtotime($person['ends_at']);
                $now = current_time('timestamp');
                $days_remaining = ($ends_at - $now) / DAY_IN_SECONDS;

                return $days_remaining <= 30 ? '1' : '';
                
            default:
                return '';
        }
    }

    /**
     * Get SureCart product URL by membership tier name
     * 
     * @param string $membership_name Nombre del tier de membresía
     * @return string|null URL del producto o null si no se encuentra
     */
    private function get_surecart_product_url($membership_name) {
        if (empty($membership_name)) {
            return null;
        }
        
        // Buscar producto de SureCart por título exacto
        $products = get_posts(array(
            'post_type'      => 'sc_product',
            'posts_per_page' => 1,
            'post_status'    => 'publish',
            'title'          => $membership_name,
            'suppress_filters' => true,
        ));
        
        // Si no encuentra por título exacto, intentar búsqueda flexible
        if (empty($products)) {
            $products = get_posts(array(
                'post_type'      => 'sc_product',
                'posts_per_page' => 1,
                'post_status'    => 'publish',
                's'              => $membership_name,
                'suppress_filters' => true,
            ));
        }
        
        if (empty($products)) {
            return null;
        }
        
        return get_permalink($products[0]->ID);
    }
}

// Initialize only if Bricks is active
add_action('init', function() {
    if (defined('BRICKS_VERSION')) {
        Wicket_Memberships_Bricks_Tags::get_instance();
    }
}, 20);