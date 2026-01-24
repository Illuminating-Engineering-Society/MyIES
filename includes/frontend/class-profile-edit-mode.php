<?php
/**
 * Profile Section Edit Mode Handler
 * 
 * Maneja pre-población y UI de edición de perfil.
 * TODOS los meta keys usan formato wicket_*
 * 
 * Forms manejados:
 * - Form 49: Personal Details
 * - Form 50: Professional Information
 * - Form 51: Address
 * 
 * @package MyIES_Integration
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class MyIES_Profile_Section_Edit {
    
    private $personal_form_id = 49;
    private $professional_form_id = 50;
    private $address_form_id = 51;
    
    public function __construct() {
        add_filter('fluentform/rendering_field_data_input_text', array($this, 'prepopulate_text_field'), 10, 2);
        add_filter('fluentform/rendering_field_data_input_email', array($this, 'prepopulate_text_field'), 10, 2);
        add_filter('fluentform/rendering_field_data_input_date', array($this, 'prepopulate_date_field'), 10, 2);
        add_filter('fluentform/rendering_field_data_phone', array($this, 'prepopulate_text_field'), 10, 2);
        add_filter('fluentform/rendering_field_data_select', array($this, 'prepopulate_select_field'), 10, 2);
        add_filter('fluentform/rendering_field_data_textarea', array($this, 'prepopulate_text_field'), 10, 2);
        
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
    }
    
    private function get_managed_form_ids() {
        return array(
            $this->personal_form_id,
            $this->professional_form_id,
            $this->address_form_id
        );
    }
    
    /**
     * Field mapping: Form field name => WordPress meta key
     * Todos usan formato wicket_*
     */
    private function get_field_mapping() {
        return array(
            // ============================================
            // PERSONAL DETAILS (Form 49)
            // ============================================
            'first_name'      => 'wicket_given_name',
            'last_name'       => 'wicket_family_name',
            'middle_name'     => 'wicket_additional_name',
            'email'           => 'wicket_email',
            'phone'           => 'wicket_phone',
            'birth_date'      => 'wicket_birth_date',
            'salutation'      => 'wicket_honorific_prefix',
            'suffix'          => 'wicket_honorific_suffix',
            'gender'          => 'wicket_gender',
            'pronouns'        => 'wicket_preferred_pronoun',
            'post_nominal'    => 'wicket_post_nominal',
            
            // ============================================
            // PROFESSIONAL INFORMATION (Form 50)
            // ============================================
            'job_title'       => 'wicket_job_title',
            'job_function'    => 'wicket_job_function',
            'job_level'       => 'wicket_job_level',
            
            // ============================================
            // ADDRESS (Form 51) - Flat fields
            // ============================================
            'street_address'  => 'wicket_address1',
            'apt_suite'       => 'wicket_address2',
            'city'            => 'wicket_city',
            'province_state'  => 'wicket_state',
            'state'           => 'wicket_state',
            'postal_code'     => 'wicket_zip_code',
            'zip'             => 'wicket_zip_code',
            'country'         => 'wicket_country_code',
            'company_name'    => 'wicket_company_name',
            'department'      => 'wicket_department',
            'division'        => 'wicket_division',
            
            // ADDRESS - Nested field names (address[field])
            'address_line_1'  => 'wicket_address1',
            'address_line_2'  => 'wicket_address2',
        );
    }
    
    /**
     * Pre-populate text fields
     */
    public function prepopulate_text_field($data, $form) {
        if (!in_array($form->id, $this->get_managed_form_ids())) {
            return $data;
        }
        
        if (!is_user_logged_in()) {
            return $data;
        }
        
        $field_name = $this->extract_field_name($data);
        if (!$field_name) {
            return $data;
        }
        
        $mapping = $this->get_field_mapping();
        $user_id = get_current_user_id();
        
        // Direct field mapping
        if (isset($mapping[$field_name])) {
            $meta_key = $mapping[$field_name];
            $value = get_user_meta($user_id, $meta_key, true);
            
            if (!empty($value)) {
                $data['attributes']['value'] = esc_attr($value);
            }
        }
        
        // Handle nested address fields: address[address_line_1], address[city], etc.
        if (strpos($field_name, 'address[') !== false) {
            preg_match('/address\[([^\]]+)\]/', $field_name, $matches);
            if (!empty($matches[1])) {
                $nested_field = $matches[1];
                if (isset($mapping[$nested_field])) {
                    $value = get_user_meta($user_id, $mapping[$nested_field], true);
                    if (!empty($value)) {
                        $data['attributes']['value'] = esc_attr($value);
                    }
                }
            }
        }
        
        // Email fallback to WordPress user email
        if ($field_name === 'email' && empty($data['attributes']['value'])) {
            $user = wp_get_current_user();
            $data['attributes']['value'] = $user->user_email;
        }
        
        return $data;
    }
    
    /**
     * Pre-populate date fields
     */
    public function prepopulate_date_field($data, $form) {
        if (!in_array($form->id, $this->get_managed_form_ids())) {
            return $data;
        }
        
        if (!is_user_logged_in()) {
            return $data;
        }
        
        $field_name = $this->extract_field_name($data);
        if (!$field_name) {
            return $data;
        }
        
        $mapping = $this->get_field_mapping();
        $user_id = get_current_user_id();
        
        if (isset($mapping[$field_name])) {
            $meta_key = $mapping[$field_name];
            $value = get_user_meta($user_id, $meta_key, true);
            
            if (!empty($value)) {
                $data['attributes']['value'] = esc_attr($value);
            }
        }
        
        return $data;
    }
    
    /**
     * Pre-populate select fields
     */
    public function prepopulate_select_field($data, $form) {
        if (!in_array($form->id, $this->get_managed_form_ids())) {
            return $data;
        }
        
        if (!is_user_logged_in()) {
            return $data;
        }
        
        $field_name = $this->extract_field_name($data);
        if (!$field_name) {
            return $data;
        }
        
        $mapping = $this->get_field_mapping();
        $user_id = get_current_user_id();
        
        // Handle nested address select (address[country], address[state])
        $actual_field = $field_name;
        if (strpos($field_name, 'address[') !== false) {
            preg_match('/address\[([^\]]+)\]/', $field_name, $matches);
            if (!empty($matches[1])) {
                $actual_field = $matches[1];
            }
        }
        
        if (isset($mapping[$actual_field])) {
            $meta_key = $mapping[$actual_field];
            $value = get_user_meta($user_id, $meta_key, true);
            
            if (!empty($value) && isset($data['settings']['advanced_options'])) {
                $stored_val = strtolower(trim($value));
                $stored_val_normalized = str_replace(['-', '_'], '/', $stored_val);
                
                foreach ($data['settings']['advanced_options'] as &$option) {
                    $option_val = strtolower(trim($option['value']));
                    $option_val_normalized = str_replace(['-', '_'], '/', $option_val);
                    
                    if ($option_val === $stored_val || $option_val_normalized === $stored_val_normalized) {
                        $option['isSelected'] = true;
                        $data['attributes']['value'] = $option['value'];
                    } else {
                        $option['isSelected'] = false;
                    }
                }
            }
        }
        
        return $data;
    }
    
    private function extract_field_name($data) {
        return $data['attributes']['name'] ?? null;
    }
    
    public function enqueue_assets() {
        if (!is_user_logged_in()) {
            return;
        }
        
        add_action('wp_head', array($this, 'output_css'));
        add_action('wp_footer', array($this, 'output_javascript'));
    }
    
    public function output_css() {
        ?>
        <style id="myies-profile-section-edit-css">
        .myies-section-edit-form {
            display: none !important;
            margin-top: 20px;
        }
        
        .myies-section.editing .myies-section-view {
            display: none !important;
        }
        
        .myies-section.editing .myies-section-edit-form {
            display: block !important;
        }
        
        .myies-section.editing .myies-section-edit-btn {
            display: none !important;
        }
        
        .myies-section-edit-btn {
            padding: 8px 10px;
            border: none;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        
        .myies-section-edit-btn:hover {
            background: #b8621f;
            color: white;
            border-radius: 8px;
        }
        
        .myies-section-cancel-btn {
            background: #6c757d;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            margin-bottom: 15px;
            text-decoration: none;
            display: inline-block;
        }
        
        .myies-section-cancel-btn:hover {
            background: #5a6268;
            color: white;
        }
        
        body.myies-loading {
            pointer-events: none;
        }
        
        body.myies-loading::after {
            content: 'Saving...';
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 20px 40px;
            border-radius: 10px;
            font-size: 16px;
            z-index: 99999;
        }
        
        .myies-section-message {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 15px;
            display: none;
            font-size: 14px;
        }
        
        .myies-section-message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
            display: block;
        }
        
        .myies-section-message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
            display: block;
        }
        
        .myies-section-edit-form .fluentform {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        
        .myies-section-edit-form .ff-btn-submit {
            background: #d4772c !important;
            border: none !important;
            padding: 12px 24px !important;
            border-radius: 6px !important;
        }
        
        .myies-section-edit-form .ff-btn-submit:hover {
            background: #b8621f !important;
        }
        </style>
        <?php
    }
    
    public function output_javascript() {
        ?>
        <script id="myies-profile-section-edit-js">
        (function($) {
            'use strict';
            
            $(document).ready(function() {
                console.log('[MyIES] Profile Section Edit Mode initialized');
                
                $(document).on('click', '.myies-section-edit-btn', function(e) {
                    e.preventDefault();
                    var $section = $(this).closest('.myies-section');
                    if (!$section.length) return;
                    
                    $section.addClass('editing');
                    
                    var $form = $section.find('.myies-section-edit-form');
                    if ($form.length) {
                        $('html, body').animate({ scrollTop: $form.offset().top - 100 }, 400);
                    }
                });
                
                $(document).on('click', '.myies-section-cancel-btn', function(e) {
                    e.preventDefault();
                    var $section = $(this).closest('.myies-section');
                    if (!$section.length) return;
                    
                    $section.removeClass('editing');
                    
                    var $view = $section.find('.myies-section-view');
                    if ($view.length) {
                        $('html, body').animate({ scrollTop: $view.offset().top - 100 }, 400);
                    }
                });
                
                $(document).on('fluentform_submission_success', function() {
                    var $section = $('.myies-section.editing');
                    if ($section.length) {
                        var $msgContainer = $section.find('.myies-section-message');
                        if ($msgContainer.length) {
                            $msgContainer.removeClass('error').addClass('success')
                                .html('✓ Changes saved successfully!').show();
                        }
                    }
                    
                    $('body').addClass('myies-loading');
                    setTimeout(function() { window.location.reload(); }, 1500);
                });
                
                $(document).on('fluentform_submission_failed', function() {
                    var $section = $('.myies-section.editing');
                    if ($section.length) {
                        var $msgContainer = $section.find('.myies-section-message');
                        if ($msgContainer.length) {
                            $msgContainer.removeClass('success').addClass('error')
                                .html('✗ Error saving changes. Please try again.').show();
                        }
                        $section.find('.ff-btn-submit').removeClass('ff-working disabled').prop('disabled', false);
                    }
                });
            });
        })(jQuery);
        </script>
        <?php
    }
}

new MyIES_Profile_Section_Edit();