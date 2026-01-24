<?php
/**
 * ACF User Field Group Shortcode
 * 
 * Retrieves and displays ACF field group data stored in user meta
 * 
 * Usage: [acf_user_fieldgroup group="group_key" user_id="123"]
 */
add_shortcode('acf_user_fieldgroup', 'acf_user_fieldgroup_shortcode');
function acf_user_fieldgroup_shortcode($atts) {
    $atts = shortcode_atts(array(
        'group'   => '',
        'user_id' => 'current'
    ), $atts);
    
    // Get user ID
    if ($atts['user_id'] === 'current') {
        $user_id = get_current_user_id();
    } else {
        $user_id = intval($atts['user_id']);
    }
    
    if (!$user_id) {
        return '';
    }
    
    // Get fields from the group
    $fields = acf_get_fields($atts['group']);
    
    if (!$fields) {
        return '';
    }
    
    $output = '';
    $output .= '<div class="myies_card">';
    
    foreach ($fields as $field) {
        $value = get_field($field['name'], 'user_' . $user_id);
        
        // Check if value exists or if it's a boolean field that should be displayed
        $should_display = false;
        $display_value = '';
        
        if ($field['type'] === 'true_false') {
            // For true/false fields, always display them
            $should_display = true;
            if ($value) {
                $display_value = '<span class="acf-approved">✓</span>';
            } else {
                $display_value = '<span class="acf-not-approved">✗</span>';
            }
        } else {
            // For other field types, only display if they have a value
            if (!empty($value)) {
                $should_display = true;
                $display_value = $value;
            }
        }
        
        if ($should_display) {
            $output .= '<p><strong>' . $field['label'] . ':</strong> ' . $display_value . '</p>';
        }
    }
    
    $output .= '</div>';
    
    return $output;
}

/**
 * Add CSS for the approval status styling
 */
add_action('wp_head', 'acf_user_fieldgroup_styles');
function acf_user_fieldgroup_styles() {
    ?>
    <style>
    .acf-approved {
        color: #28a745;
        font-weight: bold;
    }
    .acf-not-approved {
        color: #dc3545;
        font-weight: bold;
    }
    </style>
    <?php
}
