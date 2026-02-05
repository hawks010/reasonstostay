<?php
/**
 * RTS Zombie Queue Hard Reset Utility
 *
 * This file provides admin utilities to break the "zombie queue" deadlock
 * where letters are stuck with fresh queue timestamps but never processing.
 *
 * Usage: Include this file from functions.php OR run as standalone WP-CLI
 */

if (!defined('ABSPATH')) { exit; }

class RTS_Zombie_Queue_Hard_Reset {

    /**
     * Security guard to prevent unauthorized direct access to internal methods
     */
    private static function check_permission(): bool {
        // Allow WP-CLI
        if (defined('WP_CLI') && WP_CLI) {
            return true;
        }

        // Check capability
        if (!current_user_can('manage_options')) {
            return false;
        }

        return true;
    }

    /**
     * Clear ALL stale queue timestamps for quarantined letters
     * This allows them to be re-queued by the pump
     */
    public static function clear_all_stale_timestamps(): array {
        if (!self::check_permission()) {
            return ['ok' => false, 'error' => 'Unauthorized access'];
        }

        global $wpdb;

        $start_time = microtime(true);

        // Prepare IN clause safely
        $statuses = ['draft', 'pending'];
        $status_placeholders = implode(', ', array_fill(0, count($statuses), '%s'));
        
        // Params: post_type, ...statuses, meta_key, meta_value
        $params = array_merge(['letter'], $statuses, ['needs_review', '1']);

        // Find all letters with needs_review = 1 (BOTH draft AND pending for safety)
        $quarantined_ids = $wpdb->get_col($wpdb->prepare("
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.post_type = %s
              AND p.post_status IN ($status_placeholders)
              AND pm.meta_key = %s
              AND pm.meta_value = %s
        ", $params));

        $cleared_ts = 0;
        $cleared_gmt = 0;
        $cleared_processing = 0;
        $cleared_locks = 0;

        if (!empty($quarantined_ids)) {
            foreach ($quarantined_ids as $post_id) {
                $post_id = (int) $post_id;
                
                // Clear queue timestamps
                if (delete_post_meta($post_id, 'rts_scan_queued_ts')) {
                    $cleared_ts++;
                }
                if (delete_post_meta($post_id, 'rts_scan_queued_gmt')) {
                    $cleared_gmt++;
                }
                
                // Clear last processing timestamp
                if (delete_post_meta($post_id, 'rts_processing_last')) {
                    $cleared_processing++;
                }
                
                // Clear WordPress edit locks older than 1 hour
                $edit_lock = get_post_meta($post_id, '_edit_lock', true);
                if ($edit_lock) {
                    $lock_parts = explode(':', $edit_lock);
                    if (count($lock_parts) === 2) {
                        $lock_time = (int) $lock_parts[0];
                        if ($lock_time < time() - HOUR_IN_SECONDS) {
                            if (delete_post_meta($post_id, '_edit_lock')) {
                                $cleared_locks++;
                            }
                        }
                    }
                }
            }
        }

        $elapsed = round((microtime(true) - $start_time) * 1000, 2);

        return [
            'ok' => true,
            'quarantined_count' => count($quarantined_ids),
            'cleared_timestamps' => $cleared_ts,
            'cleared_gmt' => $cleared_gmt,
            'cleared_processing' => $cleared_processing,
            'cleared_locks' => $cleared_locks,
            'elapsed_ms' => $elapsed,
        ];
    }

    /**
     * Cancel ALL pending Action Scheduler jobs for letter processing
     * Use this if the queue is completely stuck with thousands of pending jobs
     * 
     * @param int $older_than_hours Only cancel jobs older than this many hours (default: 2)
     */
    public static function cancel_all_pending_scans(int $older_than_hours = 2): array {
        if (!self::check_permission()) {
            return ['ok' => false, 'error' => 'Unauthorized access'];
        }

        if (!function_exists('as_unschedule_all_actions')) {
            return ['ok' => false, 'error' => 'Action Scheduler not available'];
        }

        // Sanitize input
        $older_than_hours = max(1, min(168, abs($older_than_hours))); // Limit between 1 hour and 1 week

        $start_time = microtime(true);
        $canceled_count = 0;
        
        // Only cancel jobs scheduled more than X hours ago
        $old_timestamp = time() - ($older_than_hours * HOUR_IN_SECONDS);
        
        if (function_exists('as_get_scheduled_actions')) {
            // Smart cancellation: only cancel stale jobs
            $pending_actions = as_get_scheduled_actions([
                'hook' => 'rts_process_letter',
                'status' => 'pending',
                'per_page' => 1000, // Batch limit to prevent memory exhaustion
            ], 'ids');
            
            foreach ($pending_actions as $action_id) {
                $action = ActionScheduler::store()->fetch_action($action_id);
                if ($action && $action->get_schedule()->get_date()->getTimestamp() < $old_timestamp) {
                    as_unschedule_action('rts_process_letter', $action->get_args(), 'rts');
                    $canceled_count++;
                }
            }
        } else {
            // Fallback: cancel all if we can't filter by age
            as_unschedule_all_actions('rts_process_letter', [], 'rts');
            $canceled_count = 'all';
        }

        $elapsed = round((microtime(true) - $start_time) * 1000, 2);

        return [
            'ok' => true,
            'message' => sprintf('Cancelled %s scan jobs older than %d hours', $canceled_count, $older_than_hours),
            'canceled_count' => $canceled_count,
            'older_than_hours' => $older_than_hours,
            'elapsed_ms' => $elapsed,
        ];
    }

    /**
     * Full "nuclear option" reset:
     * 1. Cancel stale pending jobs (>2 hours old)
     * 2. Clear all queue timestamps
     * 3. Clear stuck pump cycles
     * 4. Trigger a fresh pump cycle
     * 5. Log the reset for audit trail
     */
    public static function nuclear_reset(): array {
        // Strict check for direct calls: Requires AJAX constant, WP-CLI, or manual Nonce verification
        if (!defined('DOING_AJAX') || !DOING_AJAX) {
            if (!defined('WP_CLI') && !check_admin_referer('rts_direct_reset', '_wpnonce')) {
                if (!current_user_can('manage_options')) {
                    return ['ok' => false, 'error' => 'Direct access forbidden'];
                }
            }
        }

        $results = [];
        $user_id = get_current_user_id();
        $user = $user_id ? get_userdata($user_id) : null;
        $username = $user ? $user->user_login : 'system';

        // Log start
        error_log(sprintf(
            'RTS Nuclear Reset started by %s (ID: %d) at %s',
            $username,
            $user_id,
            current_time('mysql')
        ));

        // Step 1: Cancel stale jobs (not all jobs, only >2 hours old)
        $results['cancel'] = self::cancel_all_pending_scans(2);

        // Step 2: Clear timestamps
        $results['clear'] = self::clear_all_stale_timestamps();

        // Step 3: Clear any stuck pump cycles
        delete_transient('rts_pump_active');
        delete_transient('rts_pump_last_run');
        delete_transient('rts_turbo_active');
        $results['cleared_transients'] = true;

        // Step 4: Trigger pump
        if (class_exists('RTS_Engine_Dashboard')) {
            // Clear any existing pump schedule
            if (function_exists('as_unschedule_action')) {
                as_unschedule_action('rts_scan_pump', [], 'rts');
            }
            
            // Trigger fresh pump
            do_action('rts_scan_pump');
            $results['pump_triggered'] = true;
        }

        // Log completion
        error_log(sprintf(
            'RTS Nuclear Reset completed by %s: cleared %d timestamps from %d quarantined letters, canceled %s jobs',
            $username,
            $results['clear']['cleared_timestamps'] ?? 0,
            $results['clear']['quarantined_count'] ?? 0,
            is_numeric($results['cancel']['canceled_count'] ?? 0) ? $results['cancel']['canceled_count'] : 'all'
        ));

        // Store reset log for audit trail with sanitization
        $reset_log = get_option('rts_reset_log', []);
        
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'user' => sanitize_user($username, true),
            'user_id' => (int) $user_id,
            'ip' => isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown',
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : 'unknown',
            'cleared_timestamps' => (int) ($results['clear']['cleared_timestamps'] ?? 0),
            'cleared_processing' => (int) ($results['clear']['cleared_processing'] ?? 0),
            'cleared_locks' => (int) ($results['clear']['cleared_locks'] ?? 0),
            'canceled_jobs' => is_numeric($results['cancel']['canceled_count'] ?? 0) ? (int) $results['cancel']['canceled_count'] : 0,
            'quarantined_count' => (int) ($results['clear']['quarantined_count'] ?? 0),
        ];

        $reset_log[] = $log_entry;
        
        // Keep only last 50 resets
        if (count($reset_log) > 50) {
            $reset_log = array_slice($reset_log, -50);
        }
        
        update_option('rts_reset_log', $reset_log, false);

        return [
            'ok' => true,
            'steps' => $results,
            'message' => sprintf(
                'Nuclear reset complete by %s: Cleared %d timestamps (%d processing, %d locks) from %d quarantined letters, canceled %s stale jobs, triggered fresh pump',
                $log_entry['user'],
                $log_entry['cleared_timestamps'],
                $log_entry['cleared_processing'],
                $log_entry['cleared_locks'],
                $log_entry['quarantined_count'],
                $results['cancel']['canceled_count']
            ),
        ];
    }

    /**
     * Register AJAX endpoints for admin dashboard
     */
    public static function init(): void {
        add_action('wp_ajax_rts_hard_reset_queue', [__CLASS__, 'ajax_hard_reset']);
        add_action('wp_ajax_rts_cancel_stale_jobs', [__CLASS__, 'ajax_cancel_stale_jobs']);
        add_action('wp_ajax_rts_nuclear_reset_queue', [__CLASS__, 'ajax_nuclear_reset']);
    }

    public static function ajax_hard_reset(): void {
        check_ajax_referer('rts_dashboard_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $result = self::clear_all_stale_timestamps();
        
        // Log the action
        error_log(sprintf(
            'RTS Hard Reset: User %s cleared %d timestamps from %d quarantined letters',
            wp_get_current_user()->user_login ?? 'unknown',
            $result['cleared_timestamps'] ?? 0,
            $result['quarantined_count'] ?? 0
        ));
        
        wp_send_json_success($result);
    }

    public static function ajax_cancel_stale_jobs(): void {
        check_ajax_referer('rts_dashboard_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }
        
        // Validate and sanitize input
        $older_than = isset($_POST['older_than']) ? (int)$_POST['older_than'] : 2;
        $older_than = max(1, min(24, $older_than)); // Clamp between 1-24 hours
        
        $result = self::cancel_all_pending_scans($older_than);
        
        // Log the action
        error_log(sprintf(
            'RTS Cancel Stale Jobs: User %s canceled %s jobs older than %d hours',
            wp_get_current_user()->user_login ?? 'unknown',
            $result['canceled_count'] ?? 0,
            $older_than
        ));
        
        wp_send_json_success($result);
    }

    public static function ajax_nuclear_reset(): void {
        check_ajax_referer('rts_dashboard_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        // Require explicit confirmation
        $confirmed = !empty($_POST['confirmed']) && $_POST['confirmed'] === 'yes';
        if (!$confirmed) {
            // Get count of pending jobs to show in warning
            $pending_count = 0;
            if (function_exists('as_get_scheduled_actions')) {
                $pending = as_get_scheduled_actions([
                    'hook' => 'rts_process_letter',
                    'status' => 'pending',
                    'per_page' => 1,
                ], 'count');
                $pending_count = is_numeric($pending) ? (int)$pending : 0;
            }
            
            wp_send_json_error([
                'message' => 'Confirmation required',
                'requires_confirmation' => true,
                'pending_count' => $pending_count,
                'warning' => sprintf(
                    'This will cancel %d pending scans older than 2 hours and clear queue timestamps. This action cannot be undone. Are you sure?',
                    $pending_count
                )
            ], 400);
        }

        $result = self::nuclear_reset();
        wp_send_json_success($result);
    }
}

// Auto-init if called from WordPress environment
if (function_exists('add_action')) {
    RTS_Zombie_Queue_Hard_Reset::init();
}
