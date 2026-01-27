<?php
/**
 * Wicket Bulk User Sync - Separate script for syncing all users
 * 
 * This script handles bulk syncing of all WordPress users with Wicket data
 * using AJAX pagination to handle large datasets and avoid timeouts.
 */

class WicketBulkSync {
    
    private $batch_size = 10; // Users per batch to avoid timeouts
    private $wicket_sync; // Reference to main WicketACFSync class
    
    public function __construct() {
        add_action('init', array($this, 'init'));
    }
    
    public function init() {
        // Get reference to main sync class
        if (isset($GLOBALS['wicket_acf_sync_instance'])) {
            $this->wicket_sync = $GLOBALS['wicket_acf_sync_instance'];
        }
        
        // Add AJAX handlers
        add_action('wp_ajax_start_bulk_sync', array($this, 'start_bulk_sync'));
        add_action('wp_ajax_process_bulk_sync_batch', array($this, 'process_bulk_sync_batch'));
        add_action('wp_ajax_get_bulk_sync_status', array($this, 'get_bulk_sync_status'));
        add_action('wp_ajax_cancel_bulk_sync', array($this, 'cancel_bulk_sync'));
        
        // Add bulk sync section to settings page
        add_action('wicket_acf_sync_settings_after_form', array($this, 'add_bulk_sync_section'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_bulk_sync_scripts'));
    }
    
    /**
     * Add bulk sync section to the settings page
     */
    public function add_bulk_sync_section() {
        $total_users = count_users()['total_users'];
        $last_bulk_sync = get_option('wicket_last_bulk_sync', '');
        $sync_in_progress = get_option('wicket_bulk_sync_in_progress', false);
        ?>
        
        <div class="wrap" style="margin-top: 30px;">
            <h2><?php _e('Bulk User Sync', 'wicket-integration'); ?></h2>
            
            <div class="card">
                <h3><?php _e('Sync All Users with Wicket', 'wicket-integration'); ?></h3>
                <p><?php printf(__('Total WordPress users: <strong>%d</strong>', 'wicket-integration'), $total_users); ?></p>
                
                <?php if ($last_bulk_sync): ?>
                    <p><?php printf(__('Last bulk sync: <strong>%s</strong>', 'wicket-integration'), 
                        date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_bulk_sync))); ?></p>
                <?php endif; ?>
                
                <div id="bulk-sync-container">
                    <?php if (!$sync_in_progress): ?>
                        <button type="button" id="start-bulk-sync-btn" class="button button-primary">
                            <?php _e('Start Bulk Sync', 'wicket-integration'); ?>
                        </button>
                        <p class="description">
                            <?php printf(__('This will sync all %d users with Wicket data. The process runs in batches to avoid timeouts.', 'wicket-integration'), $total_users); ?>
                        </p>
                    <?php else: ?>
                        <button type="button" id="cancel-bulk-sync-btn" class="button button-secondary">
                            <?php _e('Cancel Sync', 'wicket-integration'); ?>
                        </button>
                        <p class="description" style="color: #d63638;">
                            <?php _e('Bulk sync is currently in progress...', 'wicket-integration'); ?>
                        </p>
                    <?php endif; ?>
                </div>
                
                <!-- Progress container -->
                <div id="bulk-sync-progress" style="display: none; margin-top: 20px;">
                    <div class="progress-bar-container" style="background: #f0f0f0; border-radius: 3px; height: 20px; position: relative;">
                        <div id="progress-bar" style="background: #0073aa; height: 100%; border-radius: 3px; width: 0%; transition: width 0.3s;"></div>
                        <div id="progress-text" style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 12px; font-weight: bold; color: #333;">0%</div>
                    </div>
                    <div id="sync-stats" style="margin-top: 10px; font-size: 14px;"></div>
                    <div id="sync-log" style="margin-top: 15px; max-height: 200px; overflow-y: auto; background: #f9f9f9; padding: 10px; border: 1px solid #ddd; font-family: monospace; font-size: 12px;"></div>
                </div>
            </div>
        </div>
        
        <style>
        .card {
            background: #fff;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            padding: 20px;
            margin: 20px 0;
        }
        .progress-bar-container {
            box-shadow: inset 0 1px 2px rgba(0,0,0,.1);
        }
        .sync-success { color: #00a32a; }
        .sync-error { color: #d63638; }
        .sync-warning { color: #dba617; }
        </style>
        <?php
    }
    
    /**
     * Enqueue scripts for bulk sync functionality
     */
    public function enqueue_bulk_sync_scripts($hook) {
        // Check if we're on the correct admin page
        if (strpos($hook, 'wicket-integration') === false) {
            return;
        }
        
        // Create unique handle to avoid conflicts
        $handle = 'wicket-bulk-sync-' . wp_generate_uuid4();
        
        wp_register_script($handle, '', array('jquery'), '1.0.0', true);
        wp_enqueue_script($handle);
        
        wp_localize_script($handle, 'wicket_bulk_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wicket_bulk_sync'),
            'messages' => array(
                'starting' => __('Starting bulk sync...', 'wicket-integration'),
                'processing' => __('Processing batch...', 'wicket-integration'),
                'completed' => __('Bulk sync completed!', 'wicket-integration'),
                'cancelled' => __('Bulk sync cancelled.', 'wicket-integration'),
                'error' => __('An error occurred during bulk sync.', 'wicket-integration')
            )
        ));
        
        $script = "
        jQuery(document).ready(function($) {
            console.log('Wicket Bulk Sync script loaded');
            console.log('AJAX URL:', wicket_bulk_ajax.ajax_url);
            console.log('Nonce:', wicket_bulk_ajax.nonce);
            
            var syncInterval;
            var syncInProgress = false;
            
            // Check if button exists
            if ($('#start-bulk-sync-btn').length === 0) {
                console.error('Start bulk sync button not found');
                return;
            }
            
            console.log('Start bulk sync button found, attaching event');
            
            // Start bulk sync
            $(document).on('click', '#start-bulk-sync-btn', function(e) {
                e.preventDefault();
                console.log('Start bulk sync button clicked');
                
                if (!confirm('This will sync all users with Wicket. This may take a while. Continue?')) {
                    console.log('User cancelled confirmation');
                    return;
                }
                
                console.log('User confirmed, starting bulk sync');
                startBulkSync();
            });
            
            // Cancel bulk sync
            $(document).on('click', '#cancel-bulk-sync-btn', function(e) {
                e.preventDefault();
                console.log('Cancel bulk sync button clicked');
                
                if (!confirm('Are you sure you want to cancel the bulk sync?')) {
                    return;
                }
                
                cancelBulkSync();
            });
            
            function startBulkSync() {
                console.log('Starting bulk sync process');
                syncInProgress = true;
                $('#bulk-sync-progress').show();
                $('#start-bulk-sync-btn').prop('disabled', true).text('Sync in Progress...');
                
                addLogMessage(wicket_bulk_ajax.messages.starting, 'info');
                
                var ajaxData = {
                    action: 'start_bulk_sync',
                    nonce: wicket_bulk_ajax.nonce
                };
                
                console.log('Sending AJAX request to start bulk sync:', ajaxData);
                
                $.ajax({
                    url: wicket_bulk_ajax.ajax_url,
                    type: 'POST',
                    data: ajaxData,
                    beforeSend: function() {
                        console.log('AJAX request starting...');
                    },
                    success: function(response) {
                        console.log('Start bulk sync response:', response);
                        
                        if (response.success) {
                            addLogMessage('Sync started successfully', 'success');
                            // Start monitoring progress
                            syncInterval = setInterval(checkSyncStatus, 2000);
                            // Start processing first batch
                            setTimeout(processBatch, 1000);
                        } else {
                            var errorMsg = response.data && response.data.message ? response.data.message : 'Unknown error';
                            console.error('Failed to start sync:', errorMsg);
                            addLogMessage('Failed to start sync: ' + errorMsg, 'error');
                            resetUI();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error starting bulk sync:', xhr, status, error);
                        console.error('Response text:', xhr.responseText);
                        addLogMessage('AJAX error: ' + error, 'error');
                        resetUI();
                    }
                });
            }
            
            function processBatch() {
                if (!syncInProgress) {
                    console.log('Sync no longer in progress, stopping batch processing');
                    return;
                }
                
                console.log('Processing batch...');
                
                $.ajax({
                    url: wicket_bulk_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'process_bulk_sync_batch',
                        nonce: wicket_bulk_ajax.nonce
                    },
                    success: function(response) {
                        console.log('Batch processing response:', response);
                        
                        if (response.success) {
                            var data = response.data;
                            
                            if (data.completed) {
                                // Sync completed
                                console.log('Bulk sync completed');
                                clearInterval(syncInterval);
                                addLogMessage(wicket_bulk_ajax.messages.completed, 'success');
                                updateProgress(100, data.total_users, data.total_users, data.success_count, data.error_count);
                                syncInProgress = false;
                                $('#start-bulk-sync-btn').prop('disabled', false).text('Start Bulk Sync');
                                setTimeout(function() { 
                                    console.log('Reloading page after completion');
                                    location.reload(); 
                                }, 3000);
                            } else {
                                // Continue with next batch
                                addLogMessage('Batch processed: ' + data.batch_size + ' users', 'info');
                                setTimeout(processBatch, 1000); // 1 second delay between batches
                            }
                        } else {
                            var errorMsg = response.data && response.data.message ? response.data.message : 'Unknown error';
                            console.error('Batch processing failed:', errorMsg);
                            addLogMessage('Batch processing failed: ' + errorMsg, 'error');
                            resetUI();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error processing batch:', xhr, status, error);
                        addLogMessage('Batch processing error: ' + error, 'error');
                        resetUI();
                    }
                });
            }
            
            function checkSyncStatus() {
                $.ajax({
                    url: wicket_bulk_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'get_bulk_sync_status',
                        nonce: wicket_bulk_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            var data = response.data;
                            var progress = data.total > 0 ? (data.processed / data.total) * 100 : 0;
                            updateProgress(progress, data.processed, data.total, data.success_count, data.error_count);
                            
                            if (data.recent_logs && data.recent_logs.length > 0) {
                                data.recent_logs.forEach(function(log) {
                                    addLogMessage(log.message, log.type);
                                });
                            }
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Error checking sync status:', error);
                    }
                });
            }
            
            function cancelBulkSync() {
                console.log('Cancelling bulk sync');
                
                $.ajax({
                    url: wicket_bulk_ajax.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'cancel_bulk_sync',
                        nonce: wicket_bulk_ajax.nonce
                    },
                    success: function(response) {
                        console.log('Cancel response:', response);
                        addLogMessage(wicket_bulk_ajax.messages.cancelled, 'warning');
                        resetUI();
                    },
                    error: function(xhr, status, error) {
                        console.error('Error cancelling sync:', error);
                        addLogMessage('Error cancelling sync: ' + error, 'error');
                        resetUI();
                    }
                });
            }
            
            function updateProgress(percentage, processed, total, success, errors) {
                $('#progress-bar').css('width', percentage + '%');
                $('#progress-text').text(Math.round(percentage) + '%');
                $('#sync-stats').html(
                    'Processed: <strong>' + processed + '</strong> / <strong>' + total + '</strong> | ' +
                    'Success: <span class=\"sync-success\">' + success + '</span> | ' +
                    'Errors: <span class=\"sync-error\">' + errors + '</span>'
                );
            }
            
            function addLogMessage(message, type) {
                var logClass = 'sync-' + (type || 'info');
                var timestamp = new Date().toLocaleTimeString();
                var logEntry = '<div class=\"' + logClass + '\">[' + timestamp + '] ' + message + '</div>';
                $('#sync-log').append(logEntry);
                $('#sync-log').scrollTop($('#sync-log')[0].scrollHeight);
                
                // Also log to console
                console.log('[' + timestamp + '] ' + message);
            }
            
            function resetUI() {
                clearInterval(syncInterval);
                syncInProgress = false;
                $('#start-bulk-sync-btn').prop('disabled', false).text('Start Bulk Sync');
                console.log('UI reset completed');
            }
            
            // Test AJAX connectivity
            console.log('Testing AJAX connectivity...');
            $.ajax({
                url: wicket_bulk_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'heartbeat',
                    nonce: wicket_bulk_ajax.nonce
                },
                success: function(response) {
                    console.log('AJAX connectivity test successful');
                },
                error: function(xhr, status, error) {
                    console.error('AJAX connectivity test failed:', error);
                }
            });
        });
        ";
        
        wp_add_inline_script($handle, $script);
    }
    
    /**
     * Start bulk sync process
     */
    public function start_bulk_sync() {
        error_log('Wicket Bulk Sync: start_bulk_sync called');
        error_log('POST data: ' . print_r($_POST, true));
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wicket_bulk_sync')) {
            error_log('Wicket Bulk Sync: Nonce verification failed');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            error_log('Wicket Bulk Sync: Permission denied for user ' . get_current_user_id());
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        // Check if sync is already in progress
        if (get_option('wicket_bulk_sync_in_progress', false)) {
            error_log('Wicket Bulk Sync: Sync already in progress');
            wp_send_json_error(array('message' => 'Bulk sync already in progress'));
            return;
        }
        
        // Get total user count
        $user_count = count_users();
        $total_users = $user_count['total_users'];
        
        error_log('Wicket Bulk Sync: Total users to sync: ' . $total_users);
        
        // Initialize sync status
        update_option('wicket_bulk_sync_in_progress', true);
        update_option('wicket_bulk_sync_status', array(
            'total' => $total_users,
            'processed' => 0,
            'success_count' => 0,
            'error_count' => 0,
            'current_offset' => 0,
            'started_at' => current_time('mysql'),
            'logs' => array()
        ));
        
        error_log('Wicket Bulk Sync: Initialization complete');
        
        wp_send_json_success(array(
            'message' => 'Bulk sync started',
            'total_users' => $total_users
        ));
    }
    
    /**
     * Process a batch of users
     */
    public function process_bulk_sync_batch() {
        error_log('Wicket Bulk Sync: process_bulk_sync_batch called');
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wicket_bulk_sync')) {
            error_log('Wicket Bulk Sync: Batch nonce verification failed');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            error_log('Wicket Bulk Sync: Batch permission denied');
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        $status = get_option('wicket_bulk_sync_status', array());
        
        if (!get_option('wicket_bulk_sync_in_progress', false)) {
            error_log('Wicket Bulk Sync: No sync in progress when processing batch');
            wp_send_json_error(array('message' => 'No sync in progress'));
            return;
        }
        
        error_log('Wicket Bulk Sync: Current status: ' . print_r($status, true));
        
        // Get batch of users
        $users = get_users(array(
            'number' => $this->batch_size,
            'offset' => $status['current_offset'],
            'fields' => array('ID', 'user_email')
        ));
        
        error_log('Wicket Bulk Sync: Retrieved ' . count($users) . ' users for batch processing');
        
        $batch_success = 0;
        $batch_errors = 0;
        $logs = $status['logs'] ?? array();
        
        // Get Wicket configuration
        $tenant = get_option('wicket_tenant_name');
        $api_secret_key = get_option('wicket_api_secret_key');
        $admin_user_uuid = get_option('wicket_admin_user_uuid');
        
        if (empty($tenant) || empty($api_secret_key) || empty($admin_user_uuid)) {
            error_log('Wicket Bulk Sync: Missing Wicket configuration');
            wp_send_json_error(array('message' => 'Wicket API configuration incomplete'));
            return;
        }
        
        foreach ($users as $user) {
            try {
                error_log("Wicket Bulk Sync: Processing user {$user->ID} ({$user->user_email})");
                
                $result = $this->sync_user_with_wicket($user->ID, $user->user_email, $tenant, $api_secret_key, $admin_user_uuid);
                
                if (is_wp_error($result)) {
                    $batch_errors++;
                    $error_msg = $result->get_error_message();
                    error_log("Wicket Bulk Sync: User {$user->ID} sync failed: {$error_msg}");
                    $logs[] = array(
                        'message' => "User {$user->ID} ({$user->user_email}): " . $error_msg,
                        'type' => 'error',
                        'timestamp' => current_time('mysql')
                    );
                } else {
                    $batch_success++;
                    error_log("Wicket Bulk Sync: User {$user->ID} synced successfully");
                    $logs[] = array(
                        'message' => "User {$user->ID} ({$user->user_email}): Synced successfully",
                        'type' => 'success',
                        'timestamp' => current_time('mysql')
                    );
                }
            } catch (Exception $e) {
                $batch_errors++;
                $error_msg = $e->getMessage();
                error_log("Wicket Bulk Sync: User {$user->ID} exception: {$error_msg}");
                $logs[] = array(
                    'message' => "User {$user->ID} ({$user->user_email}): Exception - " . $error_msg,
                    'type' => 'error',
                    'timestamp' => current_time('mysql')
                );
            }
        }
        
        // Update status
        $status['processed'] += count($users);
        $status['success_count'] += $batch_success;
        $status['error_count'] += $batch_errors;
        $status['current_offset'] += $this->batch_size;
        $status['logs'] = array_slice($logs, -100); // Keep only last 100 log entries
        
        $completed = $status['processed'] >= $status['total'] || empty($users);
        
        error_log("Wicket Bulk Sync: Batch complete. Processed: {$status['processed']}/{$status['total']}, Completed: " . ($completed ? 'Yes' : 'No'));
        
        if ($completed) {
            // Mark sync as completed
            update_option('wicket_bulk_sync_in_progress', false);
            update_option('wicket_last_bulk_sync', current_time('mysql'));
            $status['completed_at'] = current_time('mysql');
            error_log('Wicket Bulk Sync: Marking sync as completed');
        }
        
        update_option('wicket_bulk_sync_status', $status);
        
        wp_send_json_success(array(
            'completed' => $completed,
            'processed' => $status['processed'],
            'total_users' => $status['total'],
            'success_count' => $status['success_count'],
            'error_count' => $status['error_count'],
            'batch_size' => count($users)
        ));
    }
    
    /**
     * Get current sync status
     */
    public function get_bulk_sync_status() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wicket_bulk_sync')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        $status = get_option('wicket_bulk_sync_status', array());
        
        // Get recent logs (last 5)
        $recent_logs = array_slice($status['logs'] ?? array(), -5);
        
        wp_send_json_success(array(
            'total' => $status['total'] ?? 0,
            'processed' => $status['processed'] ?? 0,
            'success_count' => $status['success_count'] ?? 0,
            'error_count' => $status['error_count'] ?? 0,
            'recent_logs' => $recent_logs
        ));
    }
    
    /**
     * Cancel bulk sync
     */
    public function cancel_bulk_sync() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wicket_bulk_sync')) {
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
            return;
        }
        
        update_option('wicket_bulk_sync_in_progress', false);
        error_log('Wicket Bulk Sync: Sync cancelled by user');
        
        wp_send_json_success(array('message' => 'Bulk sync cancelled'));
    }
    
    /**
     * Sync individual user with Wicket
     */
    private function sync_user_with_wicket($user_id, $email, $tenant, $api_secret_key, $admin_user_uuid) {
        
        if (!$this->wicket_sync) {
            return new WP_Error('no_sync_class', 'Wicket sync class not available');
        }
        
        // Try to sync by email first
        $result = $this->wicket_sync->sync_wicket_person_by_email($email, $user_id, $tenant, $api_secret_key, $admin_user_uuid);
        
        // If email sync fails, try by UUID
        if (is_wp_error($result)) {
            $wicket_uuid = get_user_meta($user_id, 'wicket_uuid', true);
            if (!empty($wicket_uuid)) {
                $result = $this->wicket_sync->sync_wicket_person_to_acf($wicket_uuid, $user_id, $tenant, $api_secret_key, $admin_user_uuid);
            }
        }
        
        // Sync memberships if the memberships class is available
        if (function_exists('wicket_memberships')) {
            try {
                $memberships_result = wicket_memberships()->sync_user_memberships($user_id);
                if ($memberships_result !== false) {
                    error_log("Wicket Bulk Sync: Memberships synced for user {$user_id} - Person: " . ($memberships_result['person_memberships'] ?? 0) . ", Org: " . ($memberships_result['org_memberships'] ?? 0));
                }
            } catch (Exception $e) {
                error_log("Wicket Bulk Sync: Memberships sync failed for user {$user_id} - " . $e->getMessage());
            }
        }
        
        return $result;
    }
}

// Initialize bulk sync
new WicketBulkSync();