<?php
/**
 * Debug handler for Fluent Forms submissions
 *
 * This file is intentionally disabled. To enable debug logging for all
 * Fluent Forms submissions, uncomment the require_once line in myies-integration.php
 * and uncomment the code below.
 *
 * WARNING: Do NOT enable in production - it logs every form submission.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Uncomment below for debugging:
// function debug_all_fluent_forms_submissions($entry_id, $form_data, $form) {
//     myies_log('Form submitted - ID: ' . $form->id, 'Fluent Forms Debug');
// }
// add_action('fluentform/submission_inserted', 'debug_all_fluent_forms_submissions', 1, 3);
