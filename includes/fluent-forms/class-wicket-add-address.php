<?php
/**
 * Wicket Address Sync Handler
 * 
 * Sincroniza dirección de Fluent Forms (Form 51) a Wicket API.
 * Las direcciones son recursos secundarios en Wicket.
 * 
 * @package MyIES_Integration
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_Address_Sync {
    
    private $form_id = 51;
    
    public function __construct() {
        add_action('fluentform/submission_inserted', array($this, 'sync_to_wicket'), 20, 3);
    }
    
    /**
     * Sync address to Wicket API
     */
    public function sync_to_wicket($entry_id, $form_data, $form) {
        if ($form->id != $this->form_id) {
            return;
        }
        
        error_log('[Wicket Address] ========================================');
        error_log('[Wicket Address] Processing Form ' . $this->form_id);
        error_log('[Wicket Address] Form data: ' . print_r($form_data, true));
        
        $user_id = get_current_user_id();
        if (!$user_id) {
            error_log('[Wicket Address] No logged-in user');
            return;
        }
        
        $person_uuid = wicket_api()->get_person_uuid($user_id);
        if (empty($person_uuid)) {
            error_log('[Wicket Address] Could not find person UUID');
            return;
        }
        
        error_log('[Wicket Address] User ID: ' . $user_id . ', Person UUID: ' . $person_uuid);
        
        // Build address data
        $address_data = $this->build_address_data($form_data);
        
        if (empty($address_data)) {
            error_log('[Wicket Address] No address data to sync');
            return;
        }
        
        error_log('[Wicket Address] Address data: ' . print_r($address_data, true));
        
        // Check if we have an existing address UUID
        $address_uuid = get_user_meta($user_id, 'wicket_address_uuid', true);
        
        if (!empty($address_uuid)) {
            // Update existing address
            error_log('[Wicket Address] Updating existing address: ' . $address_uuid);
            $result = wicket_api()->update_address($address_uuid, $address_data);
        } else {
            // Create new address
            error_log('[Wicket Address] Creating new address');
            $result = wicket_api()->create_address($person_uuid, $address_data);
            
            // Save the new address UUID
            if ($result['success'] && !empty($result['data']['data']['id'])) {
                $new_uuid = $result['data']['data']['id'];
                update_user_meta($user_id, 'wicket_address_uuid', $new_uuid);
                error_log('[Wicket Address] Saved new address UUID: ' . $new_uuid);
            }
        }
        
        if ($result['success']) {
            error_log('[Wicket Address] ✓ Address synced to Wicket');
            $this->update_local_meta($user_id, $form_data);
        } else {
            error_log('[Wicket Address] ✗ Sync failed: ' . $result['message']);
        }
        
        error_log('[Wicket Address] ========================================');
    }
    
    /**
     * Build address data from form submission
     * Handles both nested (Fluent Forms address field) and flat field structures
     */
    private function build_address_data($form_data) {
        $address = array();
        
        // =====================================================
        // Handle Fluent Forms nested address field
        // Format: address[address_line_1], address[city], etc.
        // =====================================================
        if (isset($form_data['address']) && is_array($form_data['address'])) {
            $addr = $form_data['address'];
            
            if (!empty($addr['address_line_1'])) {
                $address['address1'] = sanitize_text_field($addr['address_line_1']);
            }
            if (!empty($addr['address_line_2'])) {
                $address['address2'] = sanitize_text_field($addr['address_line_2']);
            }
            if (!empty($addr['city'])) {
                $address['city'] = sanitize_text_field($addr['city']);
            }
            if (!empty($addr['state'])) {
                $address['state_name'] = sanitize_text_field($addr['state']);
            }
            if (!empty($addr['zip'])) {
                $address['zip_code'] = sanitize_text_field($addr['zip']);
            }
            if (!empty($addr['country'])) {
                $address['country_code'] = sanitize_text_field($addr['country']);
            }
        }
        
        // =====================================================
        // Handle flat fields (alternative field naming)
        // =====================================================
        $flat_mapping = array(
            // Various possible field names => Wicket attribute
            'street_address'  => 'address1',
            'address_line_1'  => 'address1',
            'address1'        => 'address1',
            'apt_suite'       => 'address2',
            'address_line_2'  => 'address2',
            'address2'        => 'address2',
            'city'            => 'city',
            'province_state'  => 'state_name',
            'state'           => 'state_name',
            'state_name'      => 'state_name',
            'postal_code'     => 'zip_code',
            'zip'             => 'zip_code',
            'zip_code'        => 'zip_code',
            'country'         => 'country_code',
            'country_code'    => 'country_code',
            'company_name'    => 'company_name',
            'department'      => 'department',
            'division'        => 'division',
        );
        
        foreach ($flat_mapping as $form_field => $wicket_field) {
            if (!empty($form_data[$form_field]) && !isset($address[$wicket_field])) {
                $address[$wicket_field] = sanitize_text_field($form_data[$form_field]);
            }
        }
        
        // =====================================================
        // Handle address type
        // =====================================================
        if (!empty($form_data['address_type'])) {
            $address['type'] = sanitize_text_field($form_data['address_type']);
        } elseif (!empty($form_data['type'])) {
            $address['type'] = sanitize_text_field($form_data['type']);
        } else {
            $address['type'] = 'main_address'; // Default
        }
        
        // =====================================================
        // Handle checkboxes (primary, mailing)
        // =====================================================
        $address['primary'] = false;
        $address['mailing'] = false;
        
        // Check for checkbox array
        if (isset($form_data['checkbox']) && is_array($form_data['checkbox'])) {
            $address['primary'] = in_array('Is Primary', $form_data['checkbox']) || in_array('Primary', $form_data['checkbox']);
            $address['mailing'] = in_array('Is Mailing', $form_data['checkbox']) || in_array('Mailing', $form_data['checkbox']);
        }
        
        // Check for individual checkbox fields
        if (isset($form_data['is_primary'])) {
            $address['primary'] = ($form_data['is_primary'] === 'yes' || $form_data['is_primary'] === '1' || $form_data['is_primary'] === true);
        }
        if (isset($form_data['is_mailing'])) {
            $address['mailing'] = ($form_data['is_mailing'] === 'yes' || $form_data['is_mailing'] === '1' || $form_data['is_mailing'] === true);
        }
        
        // Active by default
        $address['active'] = true;
        
        return $address;
    }
    
    /**
     * Update local WordPress meta keys
     */
    private function update_local_meta($user_id, $form_data) {
        // Handle nested address
        if (isset($form_data['address']) && is_array($form_data['address'])) {
            $addr = $form_data['address'];
            
            $nested_mapping = array(
                'address_line_1' => 'wicket_address1',
                'address_line_2' => 'wicket_address2',
                'city'           => 'wicket_city',
                'state'          => 'wicket_state',
                'zip'            => 'wicket_zip_code',
                'country'        => 'wicket_country_code',
            );
            
            foreach ($nested_mapping as $field => $meta_key) {
                if (!empty($addr[$field])) {
                    update_user_meta($user_id, $meta_key, sanitize_text_field($addr[$field]));
                    error_log("[Wicket Address] Updated meta: {$meta_key}");
                }
            }
        }
        
        // Handle flat fields
        $flat_mapping = array(
            'street_address'  => 'wicket_address1',
            'address_line_1'  => 'wicket_address1',
            'address1'        => 'wicket_address1',
            'apt_suite'       => 'wicket_address2',
            'address_line_2'  => 'wicket_address2',
            'address2'        => 'wicket_address2',
            'city'            => 'wicket_city',
            'province_state'  => 'wicket_state',
            'state'           => 'wicket_state',
            'postal_code'     => 'wicket_zip_code',
            'zip'             => 'wicket_zip_code',
            'country'         => 'wicket_country_code',
            'company_name'    => 'wicket_company_name',
            'department'      => 'wicket_department',
            'division'        => 'wicket_division',
        );
        
        foreach ($flat_mapping as $form_field => $meta_key) {
            if (!empty($form_data[$form_field])) {
                update_user_meta($user_id, $meta_key, sanitize_text_field($form_data[$form_field]));
            }
        }
        
        update_user_meta($user_id, 'wicket_profile_last_updated', current_time('mysql'));
    }
}

new Wicket_Address_Sync();