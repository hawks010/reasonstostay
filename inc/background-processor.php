<?php
/**
 * Reasons to Stay - Background Processor
 * Safely processes 33k+ letters without timeouts or crashes.
 * Optimized for high-volume data, memory efficiency, and PHP 8.1+ compatibility.
 */

if (!defined('ABSPATH')) exit;

class RTS_Background_Processor {
    
    private static $instance = null;
    private $chunk_size = 50; 
    private $processing_delay = 1.5; 
    private $log_file;
    private $stats = [
        'total_time' => 0,
        'total_processed' => 0,
        'avg_speed' => 0
    ];
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->log_file = WP_CONTENT_DIR . '/rts-processing.log';

        // AJAX handlers
        add_action('wp_ajax_rts_process_chunk', [$this, 'ajax_process_chunk']);
        add_action('wp_ajax_rts_get_progress', [$this, 'ajax_get_progress']);
        add_action('wp_ajax_rts_emergency_stop', [$this, 'ajax_emergency_stop']);
        
        // Disable auto-hooks during import
        add_filter('rts_disable_auto_processing', [$this, 'check_import_mode']);

        // Schedule cleanup for stuck processes
        add_action('rts_cleanup_stuck_processing', [$this, 'cleanup_stuck_processing']);
        if (!wp_next_scheduled('rts_cleanup_stuck_processing')) {
            wp_schedule_event(time(), 'hourly', 'rts_cleanup_stuck_processing');
        }

        // Check DB indexes on admin load
        add_action('admin_init', [$this, 'check_database_indexes']);

        // Adjust chunk size for massive datasets on init
        if ($this->get_total_letters() > 10000) {
            $this->chunk_size = 30;
        }
    }

    /**
     * Check if database has proper indexes for performance
     */
    public function check_database_indexes() {
        if (!current_user_can('manage_options')) return;
        
        global $wpdb;
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$wpdb->posts} WHERE Key_name LIKE '%post_type%' OR Key_name LIKE '%post_status%'");
        
        if (count($indexes) < 2) {
             // Just a silent log or transient check to avoid spamming admin notices
             // Ideally this would display a dismissible admin notice, but keeping it simple as per request
             // error_log('RTS Processor Warning: Consider adding indexes to wp_posts for post_type and post_status.');
        }
    }

    /**
     * Clear transients for processes inactive for over an hour
     */
    public function cleanup_stuck_processing() {
        $operations = ['auto_tag', 'generate_images', 'scan_content', 'all'];
        foreach ($operations as $op) {
            $progress_key = 'rts_progress_' . $op;
            $progress = get_transient($progress_key);
            if ($progress && isset($progress['last_updated'])) {
                if (time() - $progress['last_updated'] > 3600) {
                    delete_transient($progress_key);
                }
            }
        }
    }

    /**
     * Emergency Stop Handler
     */
    public function ajax_emergency_stop() {
        check_ajax_referer('rts_emergency_stop', 'nonce');
        
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized']);

        $operations = ['auto_tag', 'generate_images', 'scan_content', 'all'];
        foreach ($operations as $op) {
            delete_transient('rts_progress_' . $op);
        }
        
        delete_transient('rts_processing_stats');
        wp_send_json_success(['message' => 'Emergency stop executed.']);
    }

    /**
     * Optimize processing delay based on server load
     */
    private function optimize_server_load() {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load[0] > 4.0) { 
                $this->processing_delay = 5.0; 
            } else {
                $this->processing_delay = 1.5;
            }
        }
    }

    /**
     * Dynamic Chunk Sizing based on Memory Usage
     */
    private function optimize_memory_usage() {
        $usage = memory_get_usage(true);
        $limit = wp_convert_hr_to_bytes(ini_get('memory_limit'));
        $percent = ($usage / $limit) * 100;

        if ($percent > 80) {
            $this->chunk_size = max(10, floor($this->chunk_size * 0.5));
        } elseif ($percent < 40) {
            $this->chunk_size = min(200, floor($this->chunk_size * 1.2));
        }
    }

    /**
     * Update processing stats
     */
    private function update_processing_stats($processed_count, $chunk_time) {
        $this->stats['total_time'] += $chunk_time;
        $this->stats['total_processed'] += $processed_count;
        
        if ($this->stats['total_processed'] > 0) {
            $this->stats['avg_speed'] = $this->stats['total_processed'] / $this->stats['total_time'];
        }
        
        set_transient('rts_processing_stats', $this->stats, HOUR_IN_SECONDS);
    }

    /**
     * Log errors to dedicated file
     */
    private function log_error($letter_id, $operation, $error) {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = isset($trace[2]['function']) ? $trace[2]['function'] : 'unknown';

        $message = sprintf(
            "[%s] ID: %d | Op: %s | Caller: %s | Error: %s\n",
            date('Y-m-d H:i:s'),
            $letter_id,
            $operation,
            $caller,
            $error
        );
        @file_put_contents($this->log_file, $message, FILE_APPEND);
    }

    /**
     * Render processor page
     */
    public function render_processor_page() {
        $pending_count = wp_count_posts('letter')->pending;
        $published_count = $this->get_total_letters();
        
        $needs_tagging = $this->count_needs_processing('auto_tagged');
        $needs_images = $this->count_needs_processing('_thumbnail_id');
        $needs_scanning = $this->count_needs_processing('content_flags');
        
        ?>
        <div class="wrap">
            <h1>⚙️ Bulk Background Processor</h1>
            
            <div class="notice notice-warning">
                <p><strong>⚠️ IMPORTANT: Optimized for 33k+ Letters</strong></p>
                <p>This processor monitors server load, memory usage, and allows resuming from saved states.</p>
                <ul style="margin-left: 20px;">
                    <li>Dynamic chunk sizing based on performance</li>
                    <li>Automatic Resume detection</li>
                    <li>Server load-aware delays</li>
                    <li>Safe to close browser</li>
                </ul>
            </div>
            
            <div class="card" style="max-width: 900px;">
                <h2>📊 Current Status</h2>
                <table class="widefat">
                    <tr><td><strong>Published Letters:</strong></td><td><?php echo number_format($published_count); ?></td></tr>
                    <tr><td><strong>Pending Letters:</strong></td><td><?php echo number_format($pending_count); ?></td></tr>
                    <tr><td><strong>Need Auto-Tagging:</strong></td><td><?php echo number_format($needs_tagging); ?></td></tr>
                    <tr><td><strong>Need Social Images:</strong></td><td><?php echo number_format($needs_images); ?></td></tr>
                    <tr><td><strong>Need Content Scan:</strong></td><td><?php echo number_format($needs_scanning); ?></td></tr>
                </table>
            </div>
            
            <div class="card" style="max-width: 900px; margin-top: 20px;">
                <h2>🚀 Bulk Operations</h2>
                <div id="rts-processor-controls">
                    <h3>1. Auto-Tag All Letters</h3>
                    <p><button type="button" class="button button-primary" onclick="rtsCheckResume('auto_tag', <?php echo $needs_tagging; ?>)">🏷️ Start Auto-Tagging</button></p>
                    
                    <hr>
                    <h3>2. Generate Social Images</h3>
                    <p><button type="button" class="button button-primary" onclick="rtsCheckResume('generate_images', <?php echo $needs_images; ?>)">🖼️ Generate Images</button></p>
                    
                    <hr>
                    <h3>3. Scan Content</h3>
                    <p><button type="button" class="button button-primary" onclick="rtsCheckResume('scan_content', <?php echo $needs_scanning; ?>)">🔍 Scan Problems</button></p>
                    
                    <hr>
                    <h3>4. Process ALL</h3>
                    <p><button type="button" class="button button-primary button-large" onclick="rtsCheckResume('all', <?php echo $published_count; ?>)">⚡ Process Everything</button></p>
                </div>
                
                <div id="rts-processor-progress" style="display: none; margin-top: 30px;">
                    <div style="background: #f0f0f0; border: 1px solid #ccc; height: 30px; border-radius: 4px; overflow: hidden; margin-bottom: 10px;">
                        <div id="rts-progress-bar" style="background: #2271b1; height: 100%; width: 0%; color: white; display: flex; align-items: center; justify-content: center; font-weight: bold;"></div>
                    </div>
                    <table class="widefat">
                        <tr><td><strong>Status:</strong></td><td id="rts-status-text">-</td></tr>
                        <tr><td><strong>Batch Size:</strong></td><td id="rts-batch-size"><?php echo $this->chunk_size; ?></td></tr>
                        <tr><td><strong>Processed:</strong></td><td id="rts-progress-text">0 / 0</td></tr>
                        <tr><td><strong>Speed:</strong></td><td id="rts-speed-text">Calculating...</td></tr>
                        <tr><td><strong>Estimated Remaining:</strong></td><td id="rts-remaining-text">-</td></tr>
                    </table>
                    <p style="margin-top: 20px;">
                        <button type="button" id="rts-pause-btn" class="button" onclick="rtsPauseProcessing()">Pause</button>
                        <button type="button" class="button button-secondary" onclick="rtsStopProcessing()">Stop</button>
                        <button type="button" class="button button-secondary" onclick="rtsEmergencyStop()" style="margin-left: 10px; color: #d63638; border-color: #d63638;">🛑 Emergency Stop</button>
                    </p>
                </div>

                <div id="rts-processor-complete" style="display: none; margin-top: 20px; padding: 15px; background: #f0f8f0; border-left: 4px solid #46b450;">
                    <h3>✅ Processing Complete!</h3>
                    <p id="rts-complete-summary"></p>
                    <button type="button" class="button" onclick="location.reload()">Refresh</button>
                </div>
            </div>
        </div>
        
        <script>
        let rtsProcessing = false;
        let rtsPaused = false;
        let rtsStartTime = 0;
        let rtsCurrentChunk = 0;
        let rtsOperation = '';
        
        function rtsCheckResume(operation, count) {
            if (count === 0) {
                alert('Nothing to process.');
                return;
            }
            
            jQuery.post(ajaxurl, {
                action: 'rts_get_progress',
                operation: operation,
                nonce: '<?php echo wp_create_nonce('rts_get_progress'); ?>'
            }, function(res) {
                if (res.success && res.data.chunk > 0) {
                    if (confirm('Saved progress found at ' + res.data.processed.toLocaleString() + ' letters. Resume?')) {
                        rtsCurrentChunk = res.data.chunk;
                        rtsUpdateUI(res.data);
                    }
                }
                rtsStartProcessing(operation);
            });
        }

        function rtsStartProcessing(operation) {
            rtsProcessing = true;
            rtsOperation = operation;
            if (rtsStartTime === 0) rtsStartTime = Date.now();
            document.getElementById('rts-processor-controls').style.display = 'none';
            document.getElementById('rts-processor-progress').style.display = 'block';
            rtsProcessNextChunk();
        }
        
        function rtsProcessNextChunk() {
            if (!rtsProcessing || rtsPaused) return;

            if (rtsCurrentChunk > 10000) {
                alert('Safety limit exceeded (10,000 chunks). Processing stopped.');
                rtsStopProcessing();
                return;
            }
            
            jQuery.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rts_process_chunk',
                    operation: rtsOperation,
                    chunk: rtsCurrentChunk,
                    nonce: '<?php echo wp_create_nonce('rts_process_chunk'); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        rtsCurrentChunk++;
                        rtsUpdateUI(response.data);
                        if (response.data.complete) {
                            rtsComplete(response.data);
                        } else {
                            setTimeout(rtsProcessNextChunk, response.data.delay * 1000);
                        }
                    } else {
                        alert('Error: ' + response.data.message);
                        rtsPauseProcessing();
                    }
                },
                error: function() {
                    console.log('Network error, retrying in 5s...');
                    setTimeout(rtsProcessNextChunk, 5000);
                }
            });
        }

        function rtsUpdateUI(data) {
            const percent = Math.round((data.processed / data.total) * 100);
            document.getElementById('rts-progress-bar').style.width = percent + '%';
            document.getElementById('rts-progress-bar').textContent = percent + '%';
            document.getElementById('rts-status-text').textContent = data.status;
            document.getElementById('rts-batch-size').textContent = data.chunk_size || '...';
            document.getElementById('rts-progress-text').textContent = data.processed.toLocaleString() + ' / ' + data.total.toLocaleString();
            
            const elapsed = (Date.now() - rtsStartTime) / 1000;
            if (elapsed > 0) {
                const speed = data.processed / elapsed;
                document.getElementById('rts-speed-text').textContent = speed.toFixed(2) + ' letters/sec';
                
                if (speed > 0) {
                    const remaining = (data.total - data.processed) / speed;
                    document.getElementById('rts-remaining-text').textContent = rtsFormatTimeDetailed(Math.floor(remaining));
                }
            }
        }

        function rtsComplete(data) {
            rtsProcessing = false;
            document.getElementById('rts-processor-progress').style.display = 'none';
            document.getElementById('rts-processor-complete').style.display = 'block';
            document.getElementById('rts-complete-summary').innerHTML = 'Processed ' + data.processed.toLocaleString() + ' letters.';
        }

        function rtsPauseProcessing() {
            rtsPaused = !rtsPaused;
            document.getElementById('rts-pause-btn').textContent = rtsPaused ? '▶️ Resume' : '⏸️ Pause';
            if (!rtsPaused) rtsProcessNextChunk();
        }

        function rtsStopProcessing() {
            if (confirm('Stop processing? Progress is saved.')) location.reload();
        }

        function rtsEmergencyStop() {
            if (confirm('EMERGENCY: This will clear ALL progress and stop processing immediately. Continue?')) {
                jQuery.post(ajaxurl, {
                    action: 'rts_emergency_stop',
                    nonce: '<?php echo wp_create_nonce('rts_emergency_stop'); ?>'
                }, function() {
                    location.reload();
                });
            }
        }

        function rtsFormatTimeDetailed(s) {
            if (s < 60) return s + 's';
            const h = Math.floor(s / 3600);
            const m = Math.floor((s % 3600) / 60);
            const secs = Math.floor(s % 60);
            if (h > 0) return `${h}h ${m}m ${secs}s`;
            if (m > 0) return `${m}m ${secs}s`;
            return `${secs}s`;
        }
        </script>
        <?php
    }

    /**
     * Get total letters using direct SQL for performance
     */
    private function get_total_letters() {
        global $wpdb;
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'letter' AND post_status = 'publish'");
    }

    /**
     * Count letters needing processing with validation
     */
    private function count_needs_processing($meta_key) {
        global $wpdb;
        $valid_keys = ['auto_tagged', '_thumbnail_id', 'content_flags'];
        if (!in_array($meta_key, $valid_keys)) return 0;

        return (int) $wpdb->get_var($wpdb->prepare("
            SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
            WHERE p.post_type = 'letter' AND p.post_status = 'publish' AND pm.post_id IS NULL
        ", $meta_key));
    }

    /**
     * Filter already processed IDs (Efficiency)
     */
    private function filter_processed_ids($letter_ids, $operation) {
        global $wpdb;
        if (empty($letter_ids)) return [];

        if ($operation === 'auto_tag') {
            $ids_string = implode(',', array_map('intval', $letter_ids));
            $processed = $wpdb->get_col(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = 'auto_tagged' AND post_id IN ($ids_string)"
            );
            return array_values(array_diff($letter_ids, $processed));
        }
        return $letter_ids;
    }

    /**
     * Get IDs to process
     */
    private function get_letters_to_process($offset, $limit) {
        global $wpdb;
        return $wpdb->get_col($wpdb->prepare("
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'letter' AND post_status = 'publish' 
            ORDER BY ID ASC 
            LIMIT %d OFFSET %d
        ", $limit, $offset));
    }

    /**
     * AJAX: Get saved progress
     */
    public function ajax_get_progress() {
        check_ajax_referer('rts_get_progress', 'nonce');
        $operation = sanitize_text_field($_POST['operation']);
        $progress = get_transient('rts_progress_' . $operation);
        if ($progress) wp_send_json_success($progress);
        wp_send_json_error();
    }

    /**
     * AJAX: Process Chunk
     */
    public function ajax_process_chunk() {
        $start_time = microtime(true);

        if (!check_ajax_referer('rts_process_chunk', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security token expired. Please refresh the page.']);
        }

        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Unauthorized']);

        $operation = sanitize_text_field($_POST['operation']);
        $chunk = intval($_POST['chunk']);
        
        set_time_limit(0);
        $this->optimize_server_load();
        $this->optimize_memory_usage();

        $needs_analyzer = in_array($operation, ['auto_tag', 'scan_content', 'all']);
        $needs_sharing = in_array($operation, ['generate_images', 'all']);

        if ($needs_analyzer && !class_exists('RTS_Content_Analyzer')) wp_send_json_error(['message' => 'Analyzer class missing']);
        if ($needs_sharing && !class_exists('RTS_Social_Sharing')) wp_send_json_error(['message' => 'Sharing class missing']);

        $offset = $chunk * $this->chunk_size;
        $letters = $this->get_letters_to_process($offset, $this->chunk_size);
        
        // Filter out already processed items for efficiency
        $letters_to_run = $this->filter_processed_ids($letters, $operation);

        $total = $this->get_total_letters();
        $processed = min($offset + count($letters), $total);
        $complete = ($processed >= $total);
        $errors = 0;

        foreach ($letters_to_run as $id) {
            $id = (int)$id; 
            try {
                if ($operation === 'auto_tag' || $operation === 'all') RTS_Content_Analyzer::get_instance()->analyze_and_tag($id);
                if ($operation === 'scan_content' || $operation === 'all') RTS_Content_Analyzer::get_instance()->scan_for_problems($id, get_post($id));
                if ($operation === 'generate_images' || $operation === 'all') RTS_Social_Sharing::get_instance()->generate_social_image('publish', 'pending', get_post($id));
            } catch (Exception $e) {
                $errors++;
                $this->log_error($id, $operation, $e->getMessage());
            }
            clean_post_cache($id);
        }

        $chunk_time = microtime(true) - $start_time;
        $this->update_processing_stats(count($letters_to_run), $chunk_time);

        $progress_data = [
            'processed' => $processed, 'total' => $total, 'chunk' => $chunk,
            'complete' => $complete, 'errors' => $errors, 'delay' => $this->processing_delay,
            'chunk_size' => $this->chunk_size,
            'status' => $complete ? 'Complete!' : 'Processing...', 'last_updated' => time()
        ];
        set_transient('rts_progress_' . $operation, $progress_data, HOUR_IN_SECONDS);

        if ($complete) {
            $this->send_completion_email($operation, $processed, $errors);
        }

        wp_send_json_success($progress_data);
    }

    private function send_completion_email($op, $count, $err) {
        $msg = "Background processing completed.\n\nOperation: $op\nProcessed: $count\nErrors: $err\nLog: " . $this->log_file;
        wp_mail(get_option('admin_email'), "[RTS] Processing Complete: $op", $msg);
    }

    public function check_import_mode() {
        return get_transient('rts_import_mode') === 'active';
    }
}

RTS_Background_Processor::get_instance();
?>