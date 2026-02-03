<?php
/**
 * RTS Bulk Jobs
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
    add_action('rts_rescan_quarantine_loop', 'rts_handle_quarantine_loop', 10, 2);
}
add_action('init', 'rts_bulk_jobs_init', 20);

/**
 * Action Scheduler handler.
 *
 * @param string $token  Transient key storing the IDs.
 * @param int    $offset Offset into the list.
 */
function rts_handle_bulk_rescan(string $token, int $offset = 0): void {
    if (!function_exists('as_schedule_single_action')) {
        return;
    }

    $ids = get_transient($token);
    if (!is_array($ids) || empty($ids)) {
        delete_transient($token);
        return;
    }

    $offset = max(0, (int) $offset);
    $batch_size = 25;
    $slice = array_slice($ids, $offset, $batch_size);

    if (!empty($slice)) {
        foreach ($slice as $post_id) {
            $post_id = absint($post_id);
            if (!$post_id) {
                continue;
            }
            if (!as_next_scheduled_action('rts_process_letter', [$post_id], 'rts')) {
                as_schedule_single_action(time() + 5, 'rts_process_letter', [$post_id], 'rts');
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
        return;
    }

    $payload = get_transient($token);
    if (!is_array($payload) || empty($payload['action']) || empty($payload['ids']) || !is_array($payload['ids'])) {
        delete_transient($token);
        return;
    }

    $action = (string) $payload['action'];
    $ids = array_values(array_filter(array_map('absint', (array) $payload['ids'])));
    if (empty($ids)) {
        delete_transient($token);
        return;
    }

    $offset = max(0, (int) $offset);
    $batch_size = 25;
    $slice = array_slice($ids, $offset, $batch_size);

    foreach ($slice as $post_id) {
        if (!$post_id || get_post_type($post_id) !== 'letter') {
            continue;
        }

        if ($action === 'mark_safe') {
            delete_post_meta($post_id, 'needs_review');
            delete_post_meta($post_id, 'rts_flagged');
        } elseif ($action === 'mark_review') {
            update_post_meta($post_id, 'needs_review', 1);
            update_post_meta($post_id, 'rts_flagged', 1);
            wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
        } elseif ($action === 'clear_quarantine_rescan') {
            delete_post_meta($post_id, 'needs_review');
            delete_post_meta($post_id, 'rts_flagged');
            delete_post_meta($post_id, 'rts_flag_reasons');
            delete_post_meta($post_id, 'rts_moderation_reasons');
            delete_post_meta($post_id, 'rts_flagged_keywords');
            wp_update_post(['ID' => $post_id, 'post_status' => 'pending']);

            // Queue for processing (batched fan-out).
            if (!as_next_scheduled_action('rts_process_letter', [$post_id], 'rts')) {
                as_schedule_single_action(time() + 5, 'rts_process_letter', [$post_id], 'rts');
            }
        }
    }

    $next_offset = $offset + $batch_size;
    if ($next_offset < count($ids)) {
        if (!as_next_scheduled_action('rts_bulk_admin_action', [$token, $next_offset], 'rts')) {
            as_schedule_single_action(time() + 10, 'rts_bulk_admin_action', [$token, $next_offset], 'rts');
        }
        return;
    }

    delete_transient($token);
}

/**
 * Quarantine rescan loop handler (async, batched).
 *
 * @param int $offset Offset into quarantined query.
 * @param int $batch  Batch size.
 */
function rts_handle_quarantine_loop(int $offset = 0, int $batch = 25): void {
    $offset = max(0, (int) $offset);
    $batch = max(1, min(250, (int) $batch));

    // Prefer Action Scheduler; WP-Cron can call this too.
    $use_as = function_exists('as_schedule_single_action') && function_exists('as_next_scheduled_action');

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
        return;
    }

    foreach ($ids as $post_id) {
        if (!$post_id) {
            continue;
        }

        if ($use_as) {
            if (!as_next_scheduled_action('rts_process_letter', [$post_id], 'rts')) {
                as_schedule_single_action(time() + 5, 'rts_process_letter', [$post_id], 'rts');
            }
        } else {
            if (!wp_next_scheduled('rts_wpcron_process_letter', [$post_id])) {
                wp_schedule_single_event(time() + 10, 'rts_wpcron_process_letter', [$post_id]);
            }
        }
    }

    // Schedule next page if we filled the batch.
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
    }
}
