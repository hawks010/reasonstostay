<?php
/**
 * RTS Bulk Jobs
 *
 * Version: 2.0.0 - Complete Fixes & Error Handling
 * Date: 2026-02-03
 *
 * CHANGES:
 * - Fixed undefined 'rts_flagged' meta key (now uses 'needs_review')
 * - Added comprehensive metadata clearing when moving letters
 * - Integrated with RTS_Engine_Dashboard::queue_letter_scan()
 * - Added error handling and logging throughout
 * - Added timeout protection for large batches
 * - Added cleanup for stuck jobs
 * - Clears queue timestamps to prevent reprocessing issues
 *
 * Bulk rescans are queued as a single Action Scheduler job which then schedules
 * per-letter processing hooks in small batches.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register handlers.
 */
function rts_bulk_jobs_init(): void {
    add_action('rts_bulk_rescan', 'rts_handle_bulk_rescan', 10, 2);
    add_action('rts_bulk_admin_action', 'rts_handle_bulk_admin_action', 10, 2);
    add_action('rts_bulk_refine_job', 'rts_handle_bulk_refine_job', 10, 2);
    add_action('rts_rescan_quarantine_loop', 'rts_handle_quarantine_loop', 10, 2);
    add_action('rts_daily_maintenance', 'rts_cleanup_stuck_bulk_jobs');
}
add_action('init', 'rts_bulk_jobs_init', 20);

/**
 * Action Scheduler handler for bulk rescans.
 *
 * @param string $token  Transient key storing the IDs.
 * @param int    $offset Offset into the list.
 */
function rts_handle_bulk_rescan(string $token, int $offset = 0): void {
    if (!function_exists('as_schedule_single_action')) {
        error_log('RTS Bulk: Action Scheduler not available');
        return;
    }

    // Add execution time limit for safety
    if (function_exists('set_time_limit')) { @set_time_limit(300); } // 5 minutes max

    $ids = get_transient($token);
    if (!is_array($ids) || empty($ids)) {
        error_log('RTS Bulk: Invalid or expired token: ' . $token);
        delete_transient($token);
        return;
    }

    // Log start
    if ($offset === 0) {
        error_log('RTS Bulk Rescan: Starting on ' . count($ids) . ' letters');
    }

    $offset = max(0, (int) $offset);
    $batch_size = 25;
    $slice = array_slice($ids, $offset, $batch_size);

    $queued = 0;
    if (!empty($slice)) {
        foreach ($slice as $post_id) {
            $post_id = absint($post_id);
            if (!$post_id || get_post_type($post_id) !== 'letter') {
                continue;
            }
            
            // Use existing queue_letter_scan if available for consistency
            if (class_exists('RTS_Engine_Dashboard') && method_exists('RTS_Engine_Dashboard', 'queue_letter_scan')) {
                RTS_Engine_Dashboard::queue_letter_scan($post_id);
                $queued++;
            } elseif (!as_next_scheduled_action('rts_process_letter', [$post_id], 'rts')) {
                as_schedule_single_action(time() + 5, 'rts_process_letter', [$post_id], 'rts');
                $queued++;
            }
        }
    }

    $next_offset = $offset + $batch_size;
    if ($next_offset < count($ids)) {
        if (!as_next_scheduled_action('rts_bulk_rescan', [$token, $next_offset], 'rts')) {
            as_schedule_single_action(time() + 10, 'rts_bulk_rescan', [$token, $next_offset], 'rts');
        }
        return;
    }

    // Log completion
    error_log('RTS Bulk Rescan: Completed - queued ' . $queued . ' letters');
    delete_transient($token);
}


/**
 * Bulk admin action handler (async, batched).
 *
 * @param string $token  Transient key storing payload.
 * @param int    $offset Offset into the IDs list.
 */
function rts_handle_bulk_admin_action(string $token, int $offset = 0): void {
    if (!function_exists('as_schedule_single_action')) {
        error_log('RTS Bulk: Action Scheduler not available');
        return;
    }

    // Add execution time limit for safety
    if (function_exists('set_time_limit')) { @set_time_limit(300); } // 5 minutes max

    $payload = get_transient($token);
    if (!is_array($payload) || empty($payload['action']) || empty($payload['ids']) || !is_array($payload['ids'])) {
        error_log('RTS Bulk: Invalid or expired token: ' . $token);
        delete_transient($token);
        return;
    }

    $action = (string) $payload['action'];
    $ids = array_values(array_filter(array_map('absint', (array) $payload['ids'])));
    if (empty($ids)) {
        error_log('RTS Bulk: No valid IDs in payload');
        delete_transient($token);
        return;
    }

    // Log start
    if ($offset === 0) {
        error_log('RTS Bulk Action: Starting "' . $action . '" on ' . count($ids) . ' letters');
    }

    $offset = max(0, (int) $offset);
    $batch_size = 25;
    $slice = array_slice($ids, $offset, $batch_size);

    $processed = 0;
    foreach ($slice as $post_id) {
        if (!$post_id || get_post_type($post_id) !== 'letter') {
            continue;
        }

        try {
            if ($action === 'mark_safe') {
                // Clear ALL quarantine flags and metadata
                delete_post_meta($post_id, 'needs_review');
                delete_post_meta($post_id, 'rts_flag_reasons');
                delete_post_meta($post_id, 'rts_moderation_reasons');
                delete_post_meta($post_id, 'rts_flagged_keywords');
                delete_post_meta($post_id, 'rts_safety_details');
                delete_post_meta($post_id, 'rts_system_error');
                delete_post_meta($post_id, 'rts_scan_queued_ts');
                delete_post_meta($post_id, 'rts_scan_queued_gmt');
                
                // Move to pending for reprocessing
                wp_update_post(['ID' => $post_id, 'post_status' => 'pending']);
                $processed++;
                
            } elseif ($action === 'mark_review') {
                update_post_meta($post_id, 'needs_review', '1');
                wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
                $processed++;
                
            } elseif ($action === 'clear_quarantine_rescan') {
                // Clear queue timestamps to allow reprocessing
                delete_post_meta($post_id, 'rts_scan_queued_ts');
                delete_post_meta($post_id, 'rts_scan_queued_gmt');
                
                // Clear all quarantine flags
                delete_post_meta($post_id, 'needs_review');
                delete_post_meta($post_id, 'rts_flag_reasons');
                delete_post_meta($post_id, 'rts_moderation_reasons');
                delete_post_meta($post_id, 'rts_flagged_keywords');
                delete_post_meta($post_id, 'rts_safety_details');
                delete_post_meta($post_id, 'rts_system_error');
                
                // Move to pending
                wp_update_post(['ID' => $post_id, 'post_status' => 'pending']);
                
                // Queue for processing - USE EXISTING METHOD if available
                if (class_exists('RTS_Engine_Dashboard') && method_exists('RTS_Engine_Dashboard', 'queue_letter_scan')) {
                    RTS_Engine_Dashboard::queue_letter_scan($post_id);
                } elseif (!as_next_scheduled_action('rts_process_letter', [$post_id], 'rts')) {
                    as_schedule_single_action(time() + 5, 'rts_process_letter', [$post_id], 'rts');
                }
                $processed++;
            } elseif ($action === 'rts_send_to_review') {
                // Workflow: Send to Review (sets workflow stage + (optionally) moves to Pending bucket)
                if (class_exists('RTS_Workflow')) {
                    RTS_Workflow::set_stage($post_id, 'pending_review', 'bulk:send_to_review', false);
                }

                $change_status = (bool) apply_filters('rts_send_to_review_change_status', true, $post_id);
                if ($change_status && get_post_status($post_id) !== 'pending') {
                    wp_update_post(['ID' => $post_id, 'post_status' => 'pending']);
                }
                $processed++;
            }
        } catch (\Throwable $e) {
            error_log('RTS Bulk: Error processing post ' . $post_id . ': ' . $e->getMessage());
        }
    }

    $next_offset = $offset + $batch_size;
    if ($next_offset < count($ids)) {
        if (!as_next_scheduled_action('rts_bulk_admin_action', [$token, $next_offset], 'rts')) {
            as_schedule_single_action(time() + 10, 'rts_bulk_admin_action', [$token, $next_offset], 'rts');
        }
        return;
    }

    // Log completion
    error_log('RTS Bulk Action: Completed "' . $action . '" - processed ' . $processed . ' letters');
    delete_transient($token);
}

/**
 * Quarantine rescan loop handler (async, batched).
 *
 * @param int $offset Offset into quarantined query.
 * @param int $batch  Batch size.
 */
function rts_handle_quarantine_loop(int $offset = 0, int $batch = 25): void {
    // Add execution time limit for safety
    if (function_exists('set_time_limit')) { @set_time_limit(300); } // 5 minutes max
    
    $offset = max(0, (int) $offset);
    $batch = max(1, min(250, (int) $batch));

    // Prefer Action Scheduler; WP-Cron can call this too.
    $use_as = function_exists('as_schedule_single_action') && function_exists('as_next_scheduled_action');

    // Log start
    if ($offset === 0) {
        error_log('RTS Quarantine Loop: Starting rescan with batch size ' . $batch);
    }

    $q = new WP_Query([
        'post_type'      => 'letter',
        'post_status'    => 'draft', // quarantine standardized to draft + needs_review
        'fields'         => 'ids',
        'posts_per_page' => $batch,
        'offset'         => $offset,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'meta_query'     => [
            [
                'key'     => 'needs_review',
                'value'   => '1',
                'compare' => '='
            ]
        ],
        'no_found_rows'  => true,
    ]);

    $ids = is_array($q->posts) ? array_map('absint', $q->posts) : [];
    if (empty($ids)) {
        error_log('RTS Quarantine Loop: No quarantined letters found at offset ' . $offset);
        return;
    }

    $queued = 0;
    foreach ($ids as $post_id) {
        if (!$post_id) {
            continue;
        }

        try {
            // Clear queue timestamps before re-queuing
            delete_post_meta($post_id, 'rts_scan_queued_ts');
            delete_post_meta($post_id, 'rts_scan_queued_gmt');

            if ($use_as) {
                // Use existing queue method if available
                if (class_exists('RTS_Engine_Dashboard') && method_exists('RTS_Engine_Dashboard', 'queue_letter_scan')) {
                    RTS_Engine_Dashboard::queue_letter_scan($post_id);
                    $queued++;
                } elseif (!as_next_scheduled_action('rts_process_letter', [$post_id], 'rts')) {
                    as_schedule_single_action(time() + 5, 'rts_process_letter', [$post_id], 'rts');
                    $queued++;
                }
            } else {
                if (!wp_next_scheduled('rts_wpcron_process_letter', [$post_id])) {
                    wp_schedule_single_event(time() + 10, 'rts_wpcron_process_letter', [$post_id]);
                    $queued++;
                }
            }
        } catch (\Throwable $e) {
            error_log('RTS Quarantine Loop: Error queueing post ' . $post_id . ': ' . $e->getMessage());
        }
    }

    // Schedule next page if we filled the batch.
    
	// Safety guard to avoid runaway scheduling.
	$batch_counter_key = $token . '_batches';
	$batches = (int) get_transient($batch_counter_key);
	$batches++;
	set_transient($batch_counter_key, $batches, HOUR_IN_SECONDS);
	if ($batches > 500) {
		rts_bulk_log('Aborting bulk loop: too many batches (' . $batches . ') for token ' . $token);
		delete_transient($token);
		delete_transient($batch_counter_key);
		return;
	}
if (count($ids) === $batch) {
        $next_offset = $offset + $batch;
        if ($use_as) {
            if (!as_next_scheduled_action('rts_rescan_quarantine_loop', [$next_offset, $batch], 'rts')) {
                as_schedule_single_action(time() + 10, 'rts_rescan_quarantine_loop', [$next_offset, $batch], 'rts');
            }
        } else {
            if (!wp_next_scheduled('rts_rescan_quarantine_loop', [$next_offset, $batch])) {
                wp_schedule_single_event(time() + 20, 'rts_rescan_quarantine_loop', [$next_offset, $batch]);
            }
        }
    } else {
        // Log completion
        error_log('RTS Quarantine Loop: Completed - queued ' . $queued . ' letters total');
    }
}

/**
 * Clean up any stuck bulk jobs.
 * Runs daily via cron.
 */
function rts_cleanup_stuck_bulk_jobs(): void {
    global $wpdb;
    
    error_log('RTS Bulk: Starting stuck job cleanup');
    
    // Delete old transient tokens (older than 24 hours)
    $like = $wpdb->esc_like('_transient_rts_bulk_') . '%';
    $deleted = $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE %s
         AND option_value < %d",
        $like,
        time() - DAY_IN_SECONDS
    ));
    
    if ($deleted > 0) {
        error_log('RTS Bulk: Cleaned up ' . $deleted . ' expired bulk job transients');
    }
    
    // Clear any Action Scheduler jobs that are stuck
    if (function_exists('as_get_scheduled_actions')) {
        $stuck_hooks = ['rts_bulk_rescan', 'rts_bulk_admin_action', 'rts_rescan_quarantine_loop'];
        $total_unscheduled = 0;
        
        foreach ($stuck_hooks as $hook) {
            try {
                $stuck = as_get_scheduled_actions([
                    'hook' => $hook,
                    'status' => 'pending',
                    'date' => date('Y-m-d H:i:s', time() - HOUR_IN_SECONDS), // Older than 1 hour
                ], 'ids');
                
                if (!empty($stuck)) {
                    foreach ($stuck as $action_id) {
                        as_unschedule_action($hook, [], 'rts');
                        $total_unscheduled++;
                    }
                }
            } catch (\Throwable $e) {
                error_log('RTS Bulk: Error cleaning stuck actions for ' . $hook . ': ' . $e->getMessage());
            }
        }
        
        if ($total_unscheduled > 0) {
            error_log('RTS Bulk: Unscheduled ' . $total_unscheduled . ' stuck bulk processing jobs');
        }
    }
}

// =============================================================================
// BULK JOB CREATION HELPERS
// Functions to start bulk jobs from dashboard or other code
// =============================================================================

/**
 * Start a bulk rescan of specific letter IDs
 * 
 * @param array $letter_ids Array of post IDs to rescan
 * @return array ['ok' => bool, 'token' => string, 'count' => int, 'error' => string]
 */
function rts_start_bulk_rescan(array $letter_ids): array {
	$valid_ids = [];
	foreach ($letter_ids as $id) {
		$id = absint($id);
		if ($id && get_post_type($id) === 'letter') {
			$valid_ids[] = $id;
		}
	}
	if (empty($valid_ids)) {
		return ['ok' => false, 'error' => 'No valid letter IDs found'];
	}

	if (empty($letter_ids)) {
		return ['ok' => false, 'error' => 'No letters specified'];
	}
	
	if (!function_exists('as_schedule_single_action')) {
		return ['ok' => false, 'error' => 'Action Scheduler not available'];
	}
	
	$token = 'rts_bulk_rescan_' . wp_generate_password(12, false);
	$ids = $valid_ids;
	
	// Store IDs in transient (24 hour expiry)
	set_transient($token, $ids, DAY_IN_SECONDS);
	
	// Schedule first batch
	as_schedule_single_action(time() + 5, 'rts_bulk_rescan', [$token, 0], 'rts');
	
	error_log('RTS Bulk: Started rescan of ' . count($ids) . ' letters, token: ' . $token);
	
	return ['ok' => true, 'token' => $token, 'count' => count($ids)];
}

/**
 * Start a bulk admin action (mark safe, mark review, clear quarantine rescan)
 * 
 * @param string $action One of: mark_safe, mark_review, clear_quarantine_rescan
 * @param array $letter_ids Array of post IDs to process
 * @return array ['ok' => bool, 'token' => string, 'action' => string, 'count' => int, 'error' => string]
 */
function rts_start_bulk_admin_action(string $action, array $letter_ids): array {
	if (empty($letter_ids)) {
		return ['ok' => false, 'error' => 'No letters specified'];
	}
	
	if (!function_exists('as_schedule_single_action')) {
		return ['ok' => false, 'error' => 'Action Scheduler not available'];
	}
	
	$allowed_actions = ['mark_safe', 'mark_review', 'clear_quarantine_rescan', 'rts_send_to_review'];
	if (!in_array($action, $allowed_actions, true)) {
		return ['ok' => false, 'error' => 'Invalid action: ' . $action];
	}
	
	$token = 'rts_bulk_action_' . wp_generate_password(12, false);
	$ids = array_map('absint', $letter_ids);
	
	// Store payload in transient
	$payload = [
		'action' => $action,
		'ids' => $ids,
		'started_at' => time(),
		'started_by' => get_current_user_id(),
	];
	
	set_transient($token, $payload, DAY_IN_SECONDS);
	
	// Schedule first batch
	as_schedule_single_action(time() + 5, 'rts_bulk_admin_action', [$token, 0], 'rts');
	
	error_log('RTS Bulk: Started "' . $action . '" on ' . count($ids) . ' letters, token: ' . $token);
	
	return ['ok' => true, 'token' => $token, 'action' => $action, 'count' => count($ids)];
}

/**
 * Get status of a bulk job
 * 
 * @param string $token Bulk job token
 * @return array ['ok' => bool, 'type' => string, 'count' => int, 'error' => string]
 */
function rts_get_bulk_job_status(string $token): array {
	$payload = get_transient($token);
	
	if (!$payload) {
		return ['ok' => false, 'error' => 'Job not found or expired'];
	}
	
	if (is_array($payload) && isset($payload['action'])) {
		// Admin action job
		return [
			'ok' => true,
			'type' => 'admin_action',
			'action' => $payload['action'],
			'count' => count($payload['ids'] ?? []),
			'started_at' => $payload['started_at'] ?? 0,
			'started_by' => $payload['started_by'] ?? 0,
		];
	} elseif (is_array($payload)) {
		// Simple rescan job
		return [
			'ok' => true,
			'type' => 'rescan',
			'count' => count($payload),
			'ids' => $payload,
		];
	}
	
	return ['ok' => false, 'error' => 'Invalid payload format'];
}


// -----------------------------------------------------------------------
// Auto-Refine Bulk Job (Learning System)
// -----------------------------------------------------------------------

/**
 * Action Scheduler handler for bulk auto-refine jobs.
 *
 * @param string $token  Transient key storing the IDs.
 * @param int    $offset Offset into the list.
 */
function rts_handle_bulk_refine_job(string $token, int $offset = 0): void {
    if (!function_exists('as_schedule_single_action')) {
        return;
    }

    if (function_exists('set_time_limit')) { @set_time_limit(300); }

    $ids = get_transient($token);
    if (!is_array($ids) || empty($ids)) {
        delete_transient($token);
        return;
    }

    $offset = max(0, (int) $offset);
    $batch_size = 20;
    $slice = array_slice($ids, $offset, $batch_size);

    if (empty($slice)) {
        delete_transient($token);
        return;
    }

    foreach ($slice as $post_id) {
        $post_id = absint($post_id);
        if (!$post_id || get_post_type($post_id) !== 'letter') continue;

        if (class_exists('RTS_Content_Refiner')) {
            RTS_Content_Refiner::refine($post_id);
        }

        clean_post_cache($post_id);
    }

    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }

    $next_offset = $offset + $batch_size;
    if ($next_offset < count($ids)) {
        if (!as_next_scheduled_action('rts_bulk_refine_job', [$token, $next_offset], 'rts')) {
            as_schedule_single_action(time() + 2, 'rts_bulk_refine_job', [$token, $next_offset], 'rts');
        }
        return;
    }

    delete_transient($token);
}
