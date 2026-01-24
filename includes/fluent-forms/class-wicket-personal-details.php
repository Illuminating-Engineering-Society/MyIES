<?php
/**
 * Wicket Personal Details Sync Handler
 * 
 * Sincroniza datos personales de Fluent Forms (Form 49) a Wicket API.
 * Incluye sincronización de:
 * - Atributos de persona (nombre, género, etc.)
 * - Teléfono (recurso secundario)
 * 
 * @package MyIES_Integration
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_Personal_Details_Sync {
    
    private $form_id = 49;
    
    public function __construct() {
        add_action('fluentform/submission_inserted', array($this, 'sync_to_wicket'), 20, 3);
    }
    
    /**
     * Sync personal details to Wicket API
     */
    public function sync_to_wicket($entry_id, $form_data, $form) {
        if ($form->id != $this->form_id) {
            return;
        }
        
        error_log('[Wicket Personal Details] ========================================');
        error_log('[Wicket Personal Details] Processing Form ' . $this->form_id);
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            error_log('[Wicket Personal Details] No logged-in user');
            return;
        }
        
        $person_uuid = wicket_api()->get_person_uuid($user_id);
        if (empty($person_uuid)) {
            error_log('[Wicket Personal Details] Could not find person UUID');
            return;
        }
        
        error_log('[Wicket Personal Details] User ID: ' . $user_id . ', Person UUID: ' . $person_uuid);
        
        // 1. Update person attributes
        $person_attributes = $this->build_person_attributes($form_data);
        if (!empty($person_attributes)) {
            $result = wicket_api()->update_person($person_uuid, $person_attributes);
            if ($result['success']) {
                error_log('[Wicket Personal Details] ✓ Person attributes synced');
            } else {
                error_log('[Wicket Personal Details] ✗ Person sync failed: ' . $result['message']);
            }
        }
        
        // 2. Update phone if provided
        if (!empty($form_data['phone'])) {
            $this->sync_phone($user_id, $person_uuid, $form_data['phone']);
        }
        
        // 3. Update local meta
        $this->update_local_meta($user_id, $form_data);
        
        error_log('[Wicket Personal Details] ========================================');
    }
    
    /**
     * Build person attributes for API
     */
    private function build_person_attributes($form_data) {
        $attributes = array();
        
        $field_mapping = array(
            'first_name'   => 'given_name',
            'last_name'    => 'family_name',
            'middle_name'  => 'additional_name',
            'salutation'   => 'honorific_prefix',
            'suffix'       => 'honorific_suffix',
            'gender'       => 'gender',
            'pronouns'     => 'preferred_pronoun',
            'birth_date'   => 'birth_date',
            'job_title'    => 'job_title',
        );
        
        foreach ($field_mapping as $form_field => $wicket_attr) {
            if (isset($form_data[$form_field]) && $form_data[$form_field] !== '') {
                $value = sanitize_text_field($form_data[$form_field]);
                
                // Convert date format
                if ($form_field === 'birth_date') {
                    $value = $this->convert_date_format($value);
                    if (empty($value)) continue;
                }
                
                $attributes[$wicket_attr] = $value;
            }
        }
        
        return $attributes;
    }
    
    /**
     * Sync phone to Wicket
     */
    private function sync_phone($user_id, $person_uuid, $phone_number) {
        $phone_uuid = get_user_meta($user_id, 'wicket_phone_uuid', true);
        
        $phone_data = array(
            'number' => sanitize_text_field($phone_number),
            'type' => 'main_phone',
            'primary' => true
        );
        
        if (!empty($phone_uuid)) {
            // Update existing phone
            error_log('[Wicket Personal Details] Updating phone: ' . $phone_uuid);
            $result = wicket_api()->update_phone($phone_uuid, $phone_data);
        } else {
            // Create new phone
            error_log('[Wicket Personal Details] Creating new phone');
            $result = wicket_api()->create_phone($person_uuid, $phone_data);
            
            // Save the new phone UUID
            if ($result['success'] && !empty($result['data']['data']['id'])) {
                update_user_meta($user_id, 'wicket_phone_uuid', $result['data']['data']['id']);
            }
        }
        
        if ($result['success']) {
            error_log('[Wicket Personal Details] ✓ Phone synced');
        } else {
            error_log('[Wicket Personal Details] ✗ Phone sync failed: ' . $result['message']);
        }
    }
    
    /**
     * Convert date format to ISO (YYYY-MM-DD)
     */
    private function convert_date_format($date) {
        if (empty($date)) return null;
        
        $date = trim($date);
        
        // Already ISO format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }
        
        // DD/MM/YYYY format
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date, $matches)) {
            $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            
            $iso_date = "{$year}-{$month}-{$day}";
            $timestamp = strtotime($iso_date);
            
            if ($timestamp === false || $timestamp > time()) {
                error_log("[Wicket Personal Details] Invalid or future date: {$date}");
                return null;
            }
            
            return $iso_date;
        }
        
        // Try strtotime as fallback
        $timestamp = strtotime($date);
        if ($timestamp !== false && $timestamp <= time()) {
            return date('Y-m-d', $timestamp);
        }
        
        return null;
    }
    
    /**
     * Update local meta keys
     */
    private function update_local_meta($user_id, $form_data) {
        $meta_mapping = array(
            'first_name'   => 'wicket_given_name',
            'last_name'    => 'wicket_family_name',
            'middle_name'  => 'wicket_additional_name',
            'salutation'   => 'wicket_honorific_prefix',
            'suffix'       => 'wicket_honorific_suffix',
            'gender'       => 'wicket_gender',
            'pronouns'     => 'wicket_preferred_pronoun',
            'birth_date'   => 'wicket_birth_date',
            'email'        => 'wicket_email',
            'phone'        => 'wicket_phone',
            'post_nominal' => 'wicket_post_nominal',
        );
        
        foreach ($meta_mapping as $form_field => $meta_key) {
            if (isset($form_data[$form_field]) && $form_data[$form_field] !== '') {
                $value = sanitize_text_field($form_data[$form_field]);
                
                if ($form_field === 'birth_date') {
                    $value = $this->convert_date_format($value);
                    if (empty($value)) continue;
                }
                
                update_user_meta($user_id, $meta_key, $value);
            }
        }
        
        // Update WordPress core fields
        $wp_data = array('ID' => $user_id);
        if (!empty($form_data['first_name'])) {
            $wp_data['first_name'] = sanitize_text_field($form_data['first_name']);
        }
        if (!empty($form_data['last_name'])) {
            $wp_data['last_name'] = sanitize_text_field($form_data['last_name']);
        }
        if (count($wp_data) > 1) {
            wp_update_user($wp_data);
        }
        
        // Full name
        $full_name = trim(($form_data['first_name'] ?? '') . ' ' . ($form_data['last_name'] ?? ''));
        if (!empty($full_name)) {
            update_user_meta($user_id, 'wicket_full_name', $full_name);
        }
        
        update_user_meta($user_id, 'wicket_profile_last_updated', current_time('mysql'));
    }
}

new Wicket_Personal_Details_Sync();