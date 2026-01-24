<?php
/**
 * Wicket Professional Information Handler
 * 
 * Sincroniza información profesional de Fluent Forms (Form 50) a Wicket API.
 * 
 * Campos manejados:
 * - job_title (atributo directo de person)
 * - job_function (atributo directo de person)
 * - job_level (atributo directo de person)
 * 
 * @package MyIES_Integration
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_Professional_Info_Handler {
    
    private $form_id = 50;
    
    public function __construct() {
        add_action('fluentform/submission_inserted', array($this, 'sync_to_wicket'), 20, 3);
    }
    
    /**
     * Sync professional info to Wicket API
     */
    public function sync_to_wicket($entry_id, $form_data, $form) {
        if ($form->id != $this->form_id) {
            return;
        }
        
        error_log('[Wicket Professional Info] ========================================');
        error_log('[Wicket Professional Info] Processing Form ' . $this->form_id);
        error_log('[Wicket Professional Info] Form data: ' . print_r($form_data, true));
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            error_log('[Wicket Professional Info] No logged-in user');
            return;
        }
        
        $person_uuid = wicket_api()->get_person_uuid($user_id);
        if (empty($person_uuid)) {
            error_log('[Wicket Professional Info] Could not find person UUID');
            return;
        }
        
        error_log('[Wicket Professional Info] User ID: ' . $user_id . ', Person UUID: ' . $person_uuid);
        
        // Build attributes
        $attributes = $this->build_attributes($form_data);
        
        if (empty($attributes)) {
            error_log('[Wicket Professional Info] No attributes to update');
            return;
        }
        
        error_log('[Wicket Professional Info] Attributes: ' . print_r($attributes, true));
        
        // Send to Wicket API
        $result = wicket_api()->update_person($person_uuid, $attributes);
        
        if ($result['success']) {
            error_log('[Wicket Professional Info] ✓ Successfully synced to Wicket');
            $this->update_local_meta($user_id, $form_data);
        } else {
            error_log('[Wicket Professional Info] ✗ Failed to sync: ' . $result['message']);
        }
        
        error_log('[Wicket Professional Info] ========================================');
    }
    
    /**
     * Build attributes for Wicket API
     */
    private function build_attributes($form_data) {
        $attributes = array();
        
        // job_title, job_function, job_level are direct person attributes in Wicket
        $field_mapping = array(
            'job_title'    => 'job_title',
            'job_function' => 'job_function',
            'job_level'    => 'job_level',
        );
        
        foreach ($field_mapping as $form_field => $wicket_attr) {
            if (isset($form_data[$form_field]) && $form_data[$form_field] !== '') {
                $attributes[$wicket_attr] = sanitize_text_field($form_data[$form_field]);
            }
        }
        
        return $attributes;
    }
    
    /**
     * Update local meta keys
     */
    private function update_local_meta($user_id, $form_data) {
        $meta_mapping = array(
            'job_title'    => 'wicket_job_title',
            'job_function' => 'wicket_job_function',
            'job_level'    => 'wicket_job_level',
        );
        
        foreach ($meta_mapping as $form_field => $meta_key) {
            if (isset($form_data[$form_field]) && $form_data[$form_field] !== '') {
                update_user_meta($user_id, $meta_key, sanitize_text_field($form_data[$form_field]));
                error_log("[Wicket Professional Info] Updated meta: {$meta_key}");
            }
        }
        
        update_user_meta($user_id, 'wicket_profile_last_updated', current_time('mysql'));
    }
}

new Wicket_Professional_Info_Handler();