<?php
/**
 * Wicket Error Handler
 * 
 * Centraliza el manejo de errores de la API de Wicket
 * y proporciona mensajes amigables al usuario.
 * 
 * @package MyIES_Integration
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Wicket_Error_Handler {
    
    /**
     * Singleton instance
     */
    private static $instance = null;
    
    /**
     * Store errors for current request
     */
    private $errors = array();
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        // Hook into Fluent Forms to display errors
        add_filter('fluentform/submission_confirmation', array($this, 'maybe_show_errors'), 10, 4);
    }
    
    /**
     * Add an error
     */
    public function add_error($field, $message, $raw_error = '') {
        $this->errors[] = array(
            'field' => $field,
            'message' => $message,
            'raw' => $raw_error
        );
        error_log("[Wicket Error Handler] Added error for {$field}: {$message}");
    }
    
    /**
     * Check if there are errors
     */
    public function has_errors() {
        return !empty($this->errors);
    }
    
    /**
     * Get all errors
     */
    public function get_errors() {
        return $this->errors;
    }
    
    /**
     * Clear errors
     */
    public function clear_errors() {
        $this->errors = array();
    }
    
    /**
     * Get formatted error message for display
     */
    public function get_error_message() {
        if (empty($this->errors)) {
            return '';
        }
        
        $messages = array();
        foreach ($this->errors as $error) {
            $messages[] = $error['message'];
        }
        
        return implode('<br>', $messages);
    }
    
    /**
     * Parse Wicket API error response
     * 
     * @param array|string $response The API response body
     * @return array Parsed errors
     */
    public function parse_wicket_errors($response) {
        if (is_string($response)) {
            $response = json_decode($response, true);
        }
        
        $parsed_errors = array();
        
        if (!empty($response['errors']) && is_array($response['errors'])) {
            foreach ($response['errors'] as $error) {
                $field = $error['meta']['field'] ?? 'unknown';
                $error_type = $error['meta']['error'] ?? 'unknown';
                $value = $error['meta']['value'] ?? '';
                $title = $error['title'] ?? 'Validation error';
                
                // Translate error to user-friendly message
                $message = $this->translate_error($field, $error_type, $value, $title);
                
                $parsed_errors[] = array(
                    'field' => $field,
                    'error_type' => $error_type,
                    'message' => $message,
                    'value' => $value
                );
            }
        }
        
        return $parsed_errors;
    }
    
    /**
     * Translate Wicket error to user-friendly message
     */
    private function translate_error($field, $error_type, $value, $title) {
        // Field name translations
        $field_labels = array(
            'number' => 'Phone Number',
            'job_function' => 'Job Function',
            'job_level' => 'Job Level',
            'given_name' => 'First Name',
            'family_name' => 'Last Name',
            'birth_date' => 'Date of Birth',
            'address1' => 'Street Address',
            'city' => 'City',
            'state_name' => 'State/Province',
            'zip_code' => 'Postal Code',
            'country_code' => 'Country',
        );
        
        $field_label = $field_labels[$field] ?? ucwords(str_replace('_', ' ', $field));
        
        // Error type translations
        switch ($error_type) {
            case 'improbable_phone':
                return "The phone number entered is invalid. Please enter a valid phone number with country code (e.g., +1 555 123 4567).";
                
            case 'inclusion':
                return "{$field_label}: The selected value \"{$value}\" is not valid. Please select a valid option.";
                
            case 'blank':
                return "{$field_label} cannot be blank.";
                
            case 'invalid':
                return "{$field_label} is invalid.";
                
            case 'taken':
                return "{$field_label} is already in use.";
                
            case 'too_short':
                return "{$field_label} is too short.";
                
            case 'too_long':
                return "{$field_label} is too long.";
                
            default:
                return "{$field_label}: {$title}";
        }
    }
    
    /**
     * Process API result and add errors if any
     * 
     * @param array $result The result from API helper
     * @return bool True if successful, false if errors
     */
    public function process_api_result($result) {
        if ($result['success']) {
            return true;
        }
        
        // Parse errors from response
        if (!empty($result['data'])) {
            $parsed = $this->parse_wicket_errors($result['data']);
            foreach ($parsed as $error) {
                $this->add_error($error['field'], $error['message'], $error['error_type']);
            }
        } else {
            // Generic error
            $this->add_error('general', 'Failed to update your information. Please try again.');
        }
        
        return false;
    }
    
    /**
     * Maybe show errors in form confirmation
     * 
     * This hooks into Fluent Forms to modify the success message
     * if there were Wicket sync errors.
     */
    public function maybe_show_errors($returnData, $form, $confirmation, $formData) {
        if ($this->has_errors()) {
            $error_message = $this->get_error_message();
            
            // Change the confirmation to show errors
            $returnData['message'] = '<div class="wicket-sync-error" style="color: #dc3545; background: #f8d7da; border: 1px solid #f5c6cb; padding: 15px; border-radius: 4px; margin-bottom: 15px;">' .
                '<strong>⚠️ Some information could not be saved:</strong><br>' .
                $error_message .
                '</div>' .
                '<div style="color: #856404; background: #fff3cd; border: 1px solid #ffeeba; padding: 10px; border-radius: 4px;">' .
                'Your local profile was updated, but some changes could not sync to the membership system. Please correct the errors and try again.' .
                '</div>';
            
            // Clear errors after displaying
            $this->clear_errors();
        }
        
        return $returnData;
    }
}

/**
 * Helper function to get error handler instance
 */
function wicket_errors() {
    return Wicket_Error_Handler::get_instance();
}

// Initialize
Wicket_Error_Handler::get_instance();