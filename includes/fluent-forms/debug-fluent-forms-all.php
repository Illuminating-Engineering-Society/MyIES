<?php
/**
 * Debug handler to catch ALL Fluent Forms submissions
 * This will help identify the correct form ID
 */

if (!defined('ABSPATH')) {
    exit;
}

function debug_all_fluent_forms_submissions($entry_id, $form_data, $form) {
    error_log('!!! FORM SUBMITTED - ID: ' . $form->id . ' !!!');
}

add_action('fluentform/submission_inserted', 'debug_all_fluent_forms_submissions', 1, 3);

error_log('!!! UNIVERSAL FLUENT FORMS DEBUG LOADED !!!');;