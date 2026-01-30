<?php
/**
 * RTS - Automated letter processing (WP-Cron)
 * FINAL REFACTOR: Optimized locking, status logic, and error handling.
 * * Changelog:
 * 1. Fixed double-locking: process() is now a pure wrapper.
 * 2. Optimized wp_update_post: Only runs if status actually changes.
 * 3. Large posts: Now flagged for review with default score 30.
 * 4. AJAX: Added script localization and manual nonce check.
 * 5. Cleanup: Removed theme switch hook; use manual cleanup() call.
 * 6. Added get_nonce() helper.
 * 7. Added missing constants and memory limits.
 * 8. Fix: Proper script localization handles (src=false).
 * 9. Added filters for content length and error logging.
 * 10. Logic Fix: Don't unpublish safe low-score posts; clear flags on success.
 */

if (!defined('ABSPATH')) exit;

// Ensure constants are available
if (!defined('HOUR_IN_SECONDS')) define('HOUR_IN_SECONDS', 3600);
if (!defined('MINUTE_IN_SECONDS')) define('MINUTE_IN_SECONDS', 60);

class RTS_Cron_Processing {

    const HOOK = 'rts_auto_process_letters';
    const LOCK_KEY = 'rts_cron_processing_lock';
    const LOCK_DURATION = 600; // 10 minutes max lock
    const TEXT_DOMAIN = 'hello-elementor-child';
    
    /**
     * Initialize cron system
     */
    public static function init() {
        // Register schedule early
        add_filter('cron_schedules', [__CLASS__, 'add_schedules'], 5);
        
        // Schedule event
        add_action('init', [__CLASS__, 'ensure_scheduled'], 20);
        
        // AJAX Handler
        add_action('wp_ajax_rts_run_now', [__CLASS__, 'ajax_run_now']);
        
        // Output Nonce for AJAX
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_script_data']);
        
        // Processor callbacks
        add_action('admin_init', [__CLASS__, 'fallback_tick']);
        add_action(self::HOOK, [__CLASS__, 'process']);
        
        // NOTE: Deactivation cleanup should be called manually via RTS_Cron_Processing::cleanup()
        // from your main plugin/theme deactivation hook.
    }

    /**
     * Enqueue Nonce for Admin AJAX
     */
    public static function enqueue_script_data() {
        if (!current_user_can('edit_posts')) return;
        
        // Register a virtual script handle to attach data to
        // Using 'false' as src is safer for virtual scripts
        wp_register_script('rts-cron-helper', false);
        wp_enqueue_script('rts-cron-helper');
        wp_localize_script('rts-cron-helper', 'rts_cron_obj', [
            'nonce'    => self::get_nonce(),
            'ajax_url' => admin_url('admin-ajax.php')
        ]);
    }

    /**
     * Helper to get nonce
     */
    public static function get_nonce() {
        return wp_create_nonce('rts_cron_nonce');
    }
    
    /**
     * Add custom cron schedule
     */
    public static function add_schedules($schedules) {
        if (!isset($schedules['every_five_minutes'])) {
            $schedules['every_five_minutes'] = [
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __('Every 5 minutes', self::TEXT_DOMAIN)
            ];
        }
        return $schedules;
    }
    
    /**
     * Ensure cron job is scheduled
     */
    public static function ensure_scheduled() {
        if (defined('WP_INSTALLING') && WP_INSTALLING) return;
        
        $enabled = get_option('rts_auto_processing_enabled', '1');
        $enabled = in_array($enabled, ['0', '1'], true) ? $enabled : '1';
        
        if ($enabled === '0') {
            self::unschedule();
            return;
        }
        
        $next_run = wp_next_scheduled(self::HOOK);
        
        // Stale check
        $hour_in_seconds = defined('HOUR_IN_SECONDS') ? HOUR_IN_SECONDS : 3600;
        if ($next_run && $next_run < (time() - $hour_in_seconds)) {
            self::unschedule();
            $next_run = false;
        }
        
        if (!$next_run) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'every_five_minutes', self::HOOK);
        }
    }
    
    /**
     * Unschedule all events
     */
    public static function unschedule() {
        $timestamp = wp_next_scheduled(self::HOOK);
        $iterations = 0;
        while ($timestamp && $iterations < 10) {
            wp_unschedule_event($timestamp, self::HOOK);
            $timestamp = wp_next_scheduled(self::HOOK);
            $iterations++;
        }
    }
    
    /**
     * AJAX Handler for manual triggering
     */
    public static function ajax_run_now() {
        // Manual nonce check for better JSON error handling
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'rts_cron_nonce')) {
            wp_send_json_error(['message' => 'Invalid nonce']);
        }
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $result = self::process_letters_batch('pending', 5, 'manual');
        
        if (isset($result['error'])) {
            wp_send_json_error($result);
        } else {
            wp_send_json_success($result);
        }
    }
    
    /**
     * Admin fallback tick
     */
    public static function fallback_tick() {
        if (!is_admin() || wp_doing_ajax()) return;
        if (!current_user_can('edit_posts')) return;

        $now = time();
        $last = (int) get_option('rts_cron_last_run', 0);
        $min_interval = (int) apply_filters('rts_fallback_min_interval', 5 * MINUTE_IN_SECONDS);
        
        if ($last && ($now - $last) < $min_interval) return;

        $next = wp_next_scheduled(self::HOOK);
        if ($next && ($next - $now) < 60) return;

        self::process_letters_batch('pending', 5, 'fallback');
    }

    /**
     * Cron wrapper - NO LOCKING HERE (delegated to batch)
     */
    public static function process() {
        $limit = (int) get_option('rts_auto_processing_batch_size', 10);
        $limit = max(5, min(50, $limit)); 
        
        self::process_letters_batch('pending', $limit, 'cron');
    }
    
    /**
     * Unified Processing Logic (Public API)
     */
    public static function process_letters_batch($mode = 'pending', $limit = 25, $source = 'manual') {
        // Safety Checks
        if (defined('WP_INSTALLING') && WP_INSTALLING) {
            return ['error' => 'WP Installing', 'processed' => 0];
        }
        
        // Load Check
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : null;
        $load1 = is_array($load) && isset($load[0]) ? (float) $load[0] : 0.0;
        $max_load = (float) apply_filters('rts_max_load_avg', 0.0);
        if ($max_load > 0 && $load1 > 0 && $load1 >= $max_load) {
            if (defined('WP_DEBUG') && WP_DEBUG) error_log("RTS: High load ($load1), skipping.");
            return ['error' => 'High server load', 'processed' => 0];
        }

        // Lock Check
        if (get_transient(self::LOCK_KEY)) {
            return ['error' => 'Already running', 'processed' => 0];
        }
        
        set_transient(self::LOCK_KEY, time(), self::LOCK_DURATION);
        
        // Environment settings
        @set_time_limit(300);
        @ini_set('memory_limit', apply_filters('rts_memory_limit', '256M'));
        
        // Validate Limit
        $limit = (int) $limit;
        if ($limit <= 0) $limit = 10;
        $limit = min(50, $limit); // Hard cap
        $limit = apply_filters('rts_batch_size', $limit, $mode, $source);
        
        try {
            $result = [
                'processed' => 0, 
                'published' => 0, 
                'flagged' => 0, 
                'errors' => []
            ];
            
            if (!class_exists('RTS_Content_Analyzer')) {
                throw new Exception('Content Analyzer class not found');
            }
            
            $analyzer = RTS_Content_Analyzer::get_instance();
            $post_type = apply_filters('rts_cron_post_type', 'letter');
            
            // --- QUERY SECTION ---
            
            if ($mode === 'recheck_all') {
                $active = get_option('rts_recheck_all_active', 0);
                if (!$active) {
                    delete_transient(self::LOCK_KEY);
                    return ['error' => 'Recheck not active', 'processed' => 0];
                }
                
                $offset = (int) get_option('rts_recheck_all_offset', 0);
                $total = (int) get_option('rts_recheck_all_total', 0);
                
                $query = new WP_Query([
                    'post_type'      => $post_type,
                    'post_status'    => ['publish', 'pending', 'draft'],
                    'posts_per_page' => $limit,
                    'offset'         => $offset,
                    'fields'         => 'ids',
                    'orderby'        => 'ID',
                    'order'          => 'ASC',
                    'no_found_rows'  => true,
                ]);
                
                if (empty($query->posts)) {
                    update_option('rts_recheck_all_active', 0);
                    update_option('rts_recheck_all_offset', 0);
                    update_option('rts_recheck_all_total', 0);
                    delete_transient(self::LOCK_KEY);
                    return ['complete' => true, 'message' => "Recheck complete. Total: $total", 'processed' => 0];
                }
                $ids = $query->posts;
                
            } else {
                // Normal pending mode
                $query = new WP_Query([
                    'post_type'      => $post_type,
                    'post_status'    => ['pending', 'publish'],
                    'posts_per_page' => $limit,
                    'fields'         => 'ids',
                    'orderby'        => 'date',
                    'order'          => 'DESC',
                    'meta_query'     => [[
                        'key'     => 'rts_auto_tagged',
                        'compare' => 'NOT EXISTS',
                    ]],
                    'no_found_rows'  => true,
                ]);
                $ids = $query->posts;
            }
            
            if (empty($ids)) {
                delete_transient(self::LOCK_KEY);
                update_option('rts_cron_last_status', 'Nothing to process');
                return ['processed' => 0, 'message' => 'No letters need processing'];
            }
            
            // --- PROCESSING SECTION ---
            
            $min_score = (int) get_option('rts_min_quality_score', 70);
            $auto_publish = get_option('rts_auto_publish_enabled', '1') === '1';
            $feeling_tax = apply_filters('rts_feeling_taxonomy', 'letter_feeling');
            $tone_tax = apply_filters('rts_tone_taxonomy', 'letter_tone');
            
            // Allow override of content length limit
            $max_content_length = apply_filters('rts_max_content_length', 100000);

            foreach ($ids as $post_id) {
                $post_lock = 'rts_processing_post_' . $post_id;
                
                try {
                    // Lock Check
                    if (get_transient($post_lock)) continue;
                    set_transient($post_lock, 1, 60);

                    // Post Existence Check
                    $post = get_post($post_id);
                    if (!$post) {
                        delete_transient($post_lock);
                        continue;
                    }
                    
                    // Recheck Cleanup
                    if ($mode === 'recheck_all') {
                        delete_post_meta($post_id, 'rts_auto_tagged');
                        delete_post_meta($post_id, 'quality_score');
                        delete_post_meta($post_id, 'rts_flagged');
                        delete_post_meta($post_id, 'needs_review');
                        delete_post_meta($post_id, 'rts_last_processed');
                        delete_post_meta($post_id, 'rts_processing_error');
                        wp_set_object_terms($post_id, [], $feeling_tax);
                        wp_set_object_terms($post_id, [], $tone_tax);
                    }

                    // Content Length Check
                    $content = $post->post_content;
                    if (strlen($content) > $max_content_length) {
                        // Give it a default low score instead of skipping silently
                        update_post_meta($post_id, 'quality_score', 30);
                        update_post_meta($post_id, 'rts_auto_tagged', 1);
                        update_post_meta($post_id, 'rts_flagged', 1);
                        update_post_meta($post_id, 'needs_review', 1);
                        update_post_meta($post_id, 'rts_processing_error', 'Content too large (skipped)');
                        update_post_meta($post_id, 'rts_last_processed', time()); // Mark as processed for admin tools
                        $result['flagged']++;
                        $result['processed']++;
                        delete_transient($post_lock);
                        continue;
                    }

                    // ANALYZE
                    $analysis_result = $analyzer->analyze_and_tag($post_id, true);
                    
                    if (is_wp_error($analysis_result)) {
                        throw new Exception($analysis_result->get_error_message());
                    }
                    
                    // STATUS & FLAGGING LOGIC
                    $quality = (int) get_post_meta($post_id, 'quality_score', true);
                    $is_safe = !get_post_meta($post_id, 'needs_review', true);
                    
                    $current_status = $post->post_status;
                    $target_status = $current_status; // Default to current

                    if ($auto_publish && $quality >= $min_score && $is_safe) {
                        // Publish Path
                        if ($current_status !== 'publish') {
                            $target_status = 'publish';
                        }
                        delete_post_meta($post_id, 'needs_review');
                        delete_post_meta($post_id, 'rts_flagged'); // Clear any old flags on success
                        $result['published']++;
                    } else {
                        // Flag Path
                        update_post_meta($post_id, 'rts_flagged', 1);
                        $result['flagged']++;

                        if ($current_status === 'publish') {
                            // Only unpublish if unsafe. Low quality but safe stays published + flagged.
                            if (!$is_safe) {
                                $target_status = 'pending';
                            }
                        } else {
                            // Ensure non-published posts stay pending
                            $target_status = 'pending';
                        }
                    }
                    
                    // Perform Status Update (Optimized)
                    if ($target_status && $target_status !== $current_status) {
                        $update_result = wp_update_post([
                            'ID' => $post_id,
                            'post_status' => $target_status
                        ], true);
                        
                        if (is_wp_error($update_result)) {
                            throw new Exception('Status update failed: ' . $update_result->get_error_message());
                        }
                    }

                    // Mark Complete
                    update_post_meta($post_id, 'rts_auto_tagged', 1);
                    update_post_meta($post_id, 'rts_last_processed', time());
                    // Only clear error if we succeeded
                    delete_post_meta($post_id, 'rts_processing_error');
                    
                    $result['processed']++;
                    
                } catch (Exception $e) {
                    $result['errors'][] = $post_id;
                    
                    // Only update error meta if it's different or new
                    $prev_error = get_post_meta($post_id, 'rts_processing_error', true);
                    if ($prev_error !== $e->getMessage()) {
                        update_post_meta($post_id, 'rts_processing_error', $e->getMessage());
                        update_post_meta($post_id, 'rts_processing_error_time', time());
                    }
                    
                    $log_message = apply_filters('rts_error_log_message', 
                        "RTS Processing Error ID $post_id: " . $e->getMessage(), 
                        $post_id, 
                        $e
                    );
                    error_log($log_message);
                } finally {
                    delete_transient($post_lock);
                }
            }
            
            // Update recheck offset
            if ($mode === 'recheck_all') {
                $new_offset = $offset + count($ids);
                update_option('rts_recheck_all_offset', $new_offset);
            }
            
            // Save Stats
            update_option('rts_cron_last_run', time());
            update_option('rts_cron_last_status', count($result['errors']) > 0 ? 'completed_with_errors' : 'ok');
            update_option('rts_cron_last_processed', $result['processed']);
            update_option('rts_cron_last_published', $result['published']);
            update_option('rts_cron_last_flagged', $result['flagged']);
            update_option('rts_cron_last_errors', count($result['errors']));
            update_option('rts_cron_last_mode', $mode);
            update_option('rts_cron_last_source', $source);
            
            // Log Batch Summary (Only on error or Cron to reduce spam)
            if ($source === 'cron' || count($result['errors']) > 0 || (defined('WP_DEBUG') && WP_DEBUG)) {
                error_log(sprintf(
                    'RTS Batch (%s): %d processed, %d published, %d flagged, %d errors.',
                    $source, $result['processed'], $result['published'], $result['flagged'], count($result['errors'])
                ));
            }

            delete_transient(self::LOCK_KEY);
            return $result;

        } catch (Exception $e) {
            delete_transient(self::LOCK_KEY);
            update_option('rts_cron_last_error', $e->getMessage());
            update_option('rts_cron_last_status', 'fatal_error');
            error_log('RTS Critical: ' . $e->getMessage());
            return ['error' => $e->getMessage(), 'processed' => 0];
        }
    }
    
    /**
     * Cleanup (Public API for manual deactivation)
     */
    public static function cleanup() {
        self::deactivate();
    }

    /**
     * Internal Deactivation Logic
     */
    public static function deactivate() {
        self::unschedule();
        update_option('rts_cron_last_status', 'deactivated', false);
        delete_transient(self::LOCK_KEY);
        
        global $wpdb;
        $search = $wpdb->esc_like('_transient_rts_processing_post_') . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 
            $search
        ));
        
        $search_timeout = $wpdb->esc_like('_transient_timeout_rts_processing_post_') . '%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", 
            $search_timeout
        ));
    }
    
    /**
     * Get cron status for debugging
     */
    public static function get_status() {
        $next_run = wp_next_scheduled(self::HOOK);
        
        $recheck_active = get_option('rts_recheck_all_active', 0);
        $recheck_offset = get_option('rts_recheck_all_offset', 0);
        $recheck_total = get_option('rts_recheck_all_total', 0);
        $recheck_percent = $recheck_total > 0 ? round(($recheck_offset / $recheck_total) * 100) : 0;
        
        return [
            'enabled' => get_option('rts_auto_processing_enabled', '1') === '1',
            'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : 'Not scheduled',
            'next_run_in' => $next_run ? human_time_diff(time(), $next_run) : 'N/A',
            'is_locked' => (bool) get_transient(self::LOCK_KEY),
            'last_run' => get_option('rts_cron_last_run', 0),
            'last_processed' => get_option('rts_cron_last_processed', 0),
            'last_published' => get_option('rts_cron_last_published', 0),
            'last_flagged' => get_option('rts_cron_last_flagged', 0),
            'last_errors' => get_option('rts_cron_last_errors', 0),
            'last_error_message' => get_option('rts_cron_last_error', ''),
            'last_mode' => get_option('rts_cron_last_mode', 'pending'),
            'last_source' => get_option('rts_cron_last_source', 'cron'),
            'recheck_active' => $recheck_active,
            'recheck_progress' => $recheck_active ? "{$recheck_offset} / {$recheck_total} ({$recheck_percent}%)" : 'Not running',
        ];
    }
}

// Initialize
RTS_Cron_Processing::init();