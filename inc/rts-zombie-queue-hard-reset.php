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
	 * Clear ALL stale queue timestamps for quarantined letters
	 * This allows them to be re-queued by the pump
	 */
	public static function clear_all_stale_timestamps(): array {
		global $wpdb;

		$start_time = microtime(true);

		// Find all letters with needs_review = 1
		$quarantined_ids = $wpdb->get_col($wpdb->prepare("
			SELECT DISTINCT p.ID
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
			WHERE p.post_type = %s
			  AND p.post_status = %s
			  AND pm.meta_key = %s
			  AND pm.meta_value = %s
		", 'letter', 'draft', 'needs_review', '1'));

		$cleared_ts = 0;
		$cleared_gmt = 0;

		foreach ($quarantined_ids as $post_id) {
			if (delete_post_meta($post_id, 'rts_scan_queued_ts')) {
				$cleared_ts++;
			}
			if (delete_post_meta($post_id, 'rts_scan_queued_gmt')) {
				$cleared_gmt++;
			}
		}

		$elapsed = round((microtime(true) - $start_time) * 1000, 2);

		return [
			'ok' => true,
			'quarantined_count' => count($quarantined_ids),
			'cleared_timestamps' => $cleared_ts,
			'cleared_gmt' => $cleared_gmt,
			'elapsed_ms' => $elapsed,
		];
	}

	/**
	 * Cancel ALL pending Action Scheduler jobs for letter processing
	 * Use this if the queue is completely stuck with thousands of pending jobs
	 */
	public static function cancel_all_pending_scans(): array {
		if (!function_exists('as_unschedule_all_actions')) {
			return ['ok' => false, 'error' => 'Action Scheduler not available'];
		}

		$start_time = microtime(true);

		// Cancel all pending rts_process_letter actions
		as_unschedule_all_actions('rts_process_letter', [], 'rts');

		$elapsed = round((microtime(true) - $start_time) * 1000, 2);

		return [
			'ok' => true,
			'message' => 'Cancelled all pending scan jobs',
			'elapsed_ms' => $elapsed,
		];
	}

	/**
	 * Full "nuclear option" reset:
	 * 1. Cancel all pending jobs
	 * 2. Clear all queue timestamps
	 * 3. Trigger a fresh pump cycle
	 */
	public static function nuclear_reset(): array {
		$results = [];

		// Step 1: Cancel pending jobs
		$results['cancel'] = self::cancel_all_pending_scans();

		// Step 2: Clear timestamps
		$results['clear'] = self::clear_all_stale_timestamps();

		// Step 3: Trigger pump
		if (class_exists('RTS_Engine_Dashboard')) {
			do_action('rts_scan_pump');
			$results['pump_triggered'] = true;
		}

		return [
			'ok' => true,
			'steps' => $results,
			'message' => sprintf(
				'Nuclear reset complete: Cancelled jobs, cleared %d timestamps, triggered pump',
				$results['clear']['cleared_timestamps'] ?? 0
			),
		];
	}

	/**
	 * Register AJAX endpoints for admin dashboard
	 */
	public static function init(): void {
		add_action('wp_ajax_rts_hard_reset_queue', [__CLASS__, 'ajax_hard_reset']);
		add_action('wp_ajax_rts_nuclear_reset_queue', [__CLASS__, 'ajax_nuclear_reset']);
	}

	public static function ajax_hard_reset(): void {
		check_ajax_referer('rts_dashboard_nonce', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Unauthorized'], 403);
		}

		$result = self::clear_all_stale_timestamps();
		wp_send_json_success($result);
	}

	public static function ajax_nuclear_reset(): void {
		check_ajax_referer('rts_dashboard_nonce', 'nonce');
		if (!current_user_can('manage_options')) {
			wp_send_json_error(['message' => 'Unauthorized'], 403);
		}

		$result = self::nuclear_reset();
		wp_send_json_success($result);
	}
}

// Auto-init if called from WordPress environment
if (function_exists('add_action')) {
	RTS_Zombie_Queue_Hard_Reset::init();
}
