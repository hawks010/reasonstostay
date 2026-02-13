<?php
/**
 * RTS Email Queue Management
 *
 * Handles queue operations, cleanup, retry logic, prioritization,
 * and dead-letter escalation.
 *
 * @package    RTS_Subscriber_System
 * @subpackage Queue
 * @version    1.0.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_Email_Queue {

    const QUEUE_TABLE = 'rts_email_queue';
    const DLQ_TABLE   = 'rts_dead_letter_queue';
    const DEFAULT_SENT_RETENTION_DAYS = 90;
    const DEFAULT_CANCELLED_RETENTION_DAYS = 30;
    const DEFAULT_STUCK_TIMEOUT_MINUTES = 60;
    const DEFAULT_LOG_RETENTION_DAYS = 90;
    const DEFAULT_TRACKING_RETENTION_DAYS = 90;
    const DEFAULT_BOUNCE_RETENTION_DAYS = 180;

    /**
     * Prevent duplicate hook wiring if this class is instantiated more than once.
     * (Some components may construct queue helpers for utility purposes.)
     */
    private static $hooks_wired = false;

    public function __construct() {
        if (self::$hooks_wired) {
            return;
        }
        self::$hooks_wired = true;
        $this->init_hooks();
    }

    // Hooks separated so class can be instantiated for utility
    public function init_hooks() {
        add_action('rts_queue_cleanup', array($this, 'cleanup_old_queue_items'));
        add_action('rts_process_email_queue', array($this, 'process_queue_batch'));
    }

    /**
     * Enqueue an email for sending.
     *
     * @param int         $subscriber_id
     * @param string      $template
     * @param string      $subject
     * @param string      $body
     * @param string|null $scheduled_at MySQL datetime (GMT) or null for now.
     * @param int         $priority     1 (Low) to 10 (High).
     * @param int|null    $letter_id
     * @return int|WP_Error
     */
    public function enqueue_email($subscriber_id, $template, $subject, $body, $scheduled_at = null, $priority = 5, $letter_id = null) {
        global $wpdb;

        // Template Validation
        $valid_templates = array('welcome', 'verification', 'daily_digest', 'weekly_digest', 'monthly_digest', 'reconsent', 'newsletter_custom', 'all_caught_up', 'automated_letter');
        if (!in_array($template, $valid_templates, true)) {
            return new WP_Error('invalid_template', 'Invalid email template: ' . esc_html($template));
        }

        $subscriber_id = intval($subscriber_id);
        if ($subscriber_id <= 0) {
            return new WP_Error('invalid_subscriber', 'Invalid subscriber id');
        }

        // Use GMT for consistent internal scheduling
        $scheduled_at = $scheduled_at ? $scheduled_at : current_time('mysql', true);
        $created_at   = current_time('mysql', true);

        $table = $wpdb->prefix . self::QUEUE_TABLE;

        $inserted = $wpdb->insert(
            $table,
            array(
                'subscriber_id' => $subscriber_id,
                'letter_id'     => $letter_id ? intval($letter_id) : null,
                'template'      => sanitize_key($template),
                'subject'       => sanitize_text_field($subject),
                'body'          => wp_kses_post($body),
                'status'        => 'pending',
                'attempts'      => 0,
                'priority'      => max(1, min(10, intval($priority))),
                'scheduled_at'  => $scheduled_at,
                'created_at'    => $created_at,
            ),
            array('%d', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s')
        );

        if (!$inserted) {
            // Log the specific DB error for debugging
            error_log('RTS Queue Insert Error: ' . $wpdb->last_error);
            return new WP_Error('queue_insert_failed', 'Failed to enqueue email');
        }

        $queue_id = intval($wpdb->insert_id);

        do_action('rts_email_queued', $queue_id, $template);

        return $queue_id;
    }

    /**
     * Fetch pending items for processing.
     * Used internally and by the Email Engine.
     *
     * @param int $limit
     * @return array
     */
    public function get_pending_items($limit = 100) {
        global $wpdb;
        $table = $wpdb->prefix . self::QUEUE_TABLE;

        // Prioritize: High Priority > Oldest Schedule
        // Using GMT for comparison
        $now = current_time('mysql', true);

        // Using the composite index (status, scheduled_at) implicitly via the WHERE clause
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE status = 'pending'
             AND scheduled_at <= %s
             ORDER BY priority DESC, scheduled_at ASC
             LIMIT %d",
            $now,
            intval($limit)
        );

        return $wpdb->get_results($sql);
    }

    /**
     * Mark an item as processing safely to avoid races.
     * Uses atomic UPDATE to ensure only one process claims the row.
     *
     * @param int $queue_id
     * @return bool True if claimed, False if already claimed/processed.
     */
    private function claim_item($queue_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::QUEUE_TABLE;

        // Atomic update: Returns number of rows affected. 
        // If 0, someone else claimed it or it's not pending.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET status = 'processing', updated_at = UTC_TIMESTAMP()
             WHERE id = %d AND status = 'pending'",
            intval($queue_id)
        ));

        return $updated === 1;
    }

    /**
     * Process queue batch.
     *
     * @param int|null $limit
     * @return void
     */
    public function process_queue_batch($limit = null) {
        $start_time = time();

        // Check pause state first
        if ($this->is_paused()) {
            return;
        }

        $limit = $limit ? intval($limit) : intval(get_option('rts_email_batch_size', 100));
        if ($limit <= 0) {
            $limit = 50;
        }

        update_option('rts_last_queue_run', current_time('mysql', true));
        $this->recover_stuck_processing_items();

        $items = $this->get_pending_items($limit);
        if (empty($items)) {
            return;
        }

        foreach ($items as $item) {
            if (time() - $start_time > 45) { break; }

            // Claim item to avoid double-sends (Atomicity check)
            if (!$this->claim_item($item->id)) {
                continue;
            }

            /**
             * Let Email Engine send it.
             * The engine should call mark_sent / mark_failed.
             */
            do_action('rts_send_queued_email', $item);
        }
    }

    /**
     * Mark item sent.
     *
     * @param int $queue_id
     * @return void
     */
    public function mark_sent($queue_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::QUEUE_TABLE;

        $wpdb->update(
            $table,
            array(
                'status'  => 'sent',
                'sent_at' => current_time('mysql', true),
            ),
            array('id' => intval($queue_id)),
            array('%s', '%s'),
            array('%d')
        );
    }

    /**
     * Mark item failed and retry or dead-letter.
     *
     * @param int    $queue_id
     * @param string $error
     * @return void
     */
    public function mark_failed($queue_id, $error) {
        global $wpdb;
        $table = $wpdb->prefix . self::QUEUE_TABLE;

        $queue_id = intval($queue_id);
        $max_attempts = intval(get_option('rts_email_retry_attempts', 3));

        $item = $wpdb->get_row($wpdb->prepare("SELECT attempts, created_at FROM {$table} WHERE id = %d", $queue_id));
        if (!$item) {
            return;
        }

        $attempts = intval($item->attempts) + 1;

        if ($attempts >= $max_attempts) {
            $this->move_to_dead_letter($queue_id, $error);
            return;
        }

        // Exponential backoff: 5, 15, 45, 135 minutes...
        $delay_minutes = pow(3, $attempts) * 5; 
        
        // Cap retry delay at 3 hours (180 minutes) to avoid waiting too long
        $delay_minutes = min($delay_minutes, 180);
        
        /**
         * Filter retry delay in minutes.
         *
         * @param int $delay_minutes Calculated delay.
         * @param int $attempts      Current attempt count.
         * @param int $queue_id      The queue item ID.
         */
        $delay_minutes = apply_filters('rts_email_retry_delay', $delay_minutes, $attempts, $queue_id);

        $next_schedule = gmdate('Y-m-d H:i:s', time() + ($delay_minutes * 60));

        // Single update to reschedule
        $wpdb->update(
            $table,
            array(
                'status'       => 'pending', // Set back to pending so it gets picked up
                'attempts'     => $attempts,
                'error_log'    => wp_strip_all_tags($error), // Safe storage
                'scheduled_at' => $next_schedule,
            ),
            array('id' => $queue_id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );
    }

    /**
     * Mark item cancelled (e.g. demo mode or unsubscribed).
     *
     * @param int    $queue_id
     * @param string $reason
     * @return void
     */
    public function mark_cancelled($queue_id, $reason) {
        global $wpdb;
        $table = $wpdb->prefix . self::QUEUE_TABLE;

        $wpdb->update(
            $table,
            array(
                'status'    => 'cancelled',
                'error_log' => wp_strip_all_tags($reason),
                'sent_at'   => current_time('mysql', true), // Mark "done" time
            ),
            array('id' => intval($queue_id)),
            array('%s', '%s', '%s'),
            array('%d')
        );
    }

    /**
     * Retry failed items (all or selected).
     *
     * @param array $ids Optional array of IDs to retry.
     * @return void
     */
    public function retry_failed_items($ids = array()) {
        global $wpdb;
        $table = $wpdb->prefix . self::QUEUE_TABLE;

        $now = current_time('mysql', true);

        if (empty($ids)) {
            // Retry ALL failed items
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET status = 'pending',
                     attempts = 0,
                     scheduled_at = %s
                 WHERE status = 'failed'",
                $now
            ));
            return;
        }

        // Validate IDs are integers
        $ids = array_map('intval', $ids);
        $ids = array_filter($ids); // Remove 0s

        if (empty($ids)) return;

        // Secure IN clause
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        
        $sql = "UPDATE {$table}
                SET status = 'pending',
                    attempts = 0,
                    scheduled_at = %s
                WHERE id IN ({$placeholders})";

        // Merge params: [scheduled_at, id1, id2, ...]
        $params = array_merge(array($now), $ids);
        
        $wpdb->query($wpdb->prepare($sql, $params));
    }

    /**
     * Move a permanently failed item to dead letter queue.
     *
     * @param int    $queue_id
     * @param string $reason
     * @return bool
     */
    public function move_to_dead_letter($queue_id, $reason) {
        global $wpdb;

        $queue_id = intval($queue_id);
        $table_queue = $wpdb->prefix . self::QUEUE_TABLE;
        $table_dlq   = $wpdb->prefix . self::DLQ_TABLE;

        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table_queue} WHERE id = %d", $queue_id));
        if (!$item) {
            return false;
        }

        $inserted = $wpdb->insert(
            $table_dlq,
            array(
                'original_id'   => intval($item->id),
                'subscriber_id' => intval($item->subscriber_id),
                'template'      => sanitize_key($item->template),
                'subject'       => sanitize_text_field($item->subject),
                'error_log'     => wp_strip_all_tags($reason),
                'attempts'      => intval($item->attempts),
                'created_at'    => $item->created_at,
                'moved_at'      => current_time('mysql', true),
            ),
            array('%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s')
        );

        if ($inserted) {
            // Remove original item
            $wpdb->delete($table_queue, array('id' => $queue_id), array('%d'));
            do_action('rts_email_dead_lettered', $queue_id, $reason);
            return true;
        }

        return false;
    }

    /**
     * Cleanup old queue items with transaction safety (best-effort).
     *
     * @return void
     */
    public function cleanup_old_queue_items() {
        global $wpdb;
        $table = $wpdb->prefix . self::QUEUE_TABLE;
        $sent_retention_days = max(1, (int) get_option('rts_queue_retention_sent_days', self::DEFAULT_SENT_RETENTION_DAYS));
        $cancelled_retention_days = max(1, (int) get_option('rts_queue_retention_cancelled_days', self::DEFAULT_CANCELLED_RETENTION_DAYS));
        $stuck_timeout_minutes = max(5, (int) get_option('rts_queue_stuck_timeout_minutes', self::DEFAULT_STUCK_TIMEOUT_MINUTES));
        $logs_retention_days = max(1, (int) get_option('rts_retention_email_logs_days', self::DEFAULT_LOG_RETENTION_DAYS));
        $tracking_retention_days = max(1, (int) get_option('rts_retention_tracking_days', self::DEFAULT_TRACKING_RETENTION_DAYS));
        $bounce_retention_days = max(1, (int) get_option('rts_retention_bounce_days', self::DEFAULT_BOUNCE_RETENTION_DAYS));

        // Clean Sent items by configured retention policy.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status = 'sent' AND sent_at < %s",
            gmdate('Y-m-d H:i:s', time() - ($sent_retention_days * DAY_IN_SECONDS))
        ));

        // Clean Cancelled items by configured retention policy.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE status = 'cancelled' AND COALESCE(updated_at, created_at) < %s",
            gmdate('Y-m-d H:i:s', time() - ($cancelled_retention_days * DAY_IN_SECONDS))
        ));

        $this->recover_stuck_processing_items($stuck_timeout_minutes);

        $logs_table = $wpdb->prefix . 'rts_email_logs';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $logs_table)) === $logs_table) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$logs_table} WHERE sent_at < %s",
                gmdate('Y-m-d H:i:s', time() - ($logs_retention_days * DAY_IN_SECONDS))
            ));
        }

        $tracking_table = $wpdb->prefix . 'rts_email_tracking';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $tracking_table)) === $tracking_table) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$tracking_table} WHERE created_at < %s",
                gmdate('Y-m-d H:i:s', time() - ($tracking_retention_days * DAY_IN_SECONDS))
            ));
        }

        $bounce_table = $wpdb->prefix . 'rts_email_bounces';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $bounce_table)) === $bounce_table) {
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$bounce_table} WHERE bounced_at < %s",
                gmdate('Y-m-d H:i:s', time() - ($bounce_retention_days * DAY_IN_SECONDS))
            ));
        }
    }

    /**
     * Pause the queue processing.
     */
    public function pause_queue() {
        update_option('rts_email_queue_paused', time());
    }

    /**
     * Resume the queue processing.
     */
    public function resume_queue() {
        delete_option('rts_email_queue_paused');
    }

    /**
     * Check if queue is paused.
     * @return bool
     */
    public function is_paused() {
        return (bool) get_option('rts_email_queue_paused', false);
    }

    /**
     * Bulk cancel items for a specific subscriber.
     * Useful when a user unsubscribes or is deleted.
     *
     * @param int $subscriber_id
     * @return int|false Number of rows affected or false on error.
     */
    public function bulk_cancel_by_subscriber($subscriber_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::QUEUE_TABLE;
        
        return $wpdb->update(
            $table,
            array('status' => 'cancelled'),
            array('subscriber_id' => intval($subscriber_id), 'status' => 'pending'),
            array('%s'),
            array('%d', '%s')
        );
    }

    /**
     * Get statistics broken down by template and status.
     * * @return array
     */
    public function get_template_stats() {
        global $wpdb;
        $table = $wpdb->prefix . self::QUEUE_TABLE;
        
        return $wpdb->get_results(
            "SELECT template, status, COUNT(*) as count 
             FROM {$table} 
             GROUP BY template, status 
             ORDER BY template, status"
        );
    }

    /**
     * Queue health stats.
     *
     * @return array
     */
    public function get_queue_health() {
        global $wpdb;
        $table = $wpdb->prefix . self::QUEUE_TABLE;
        $stuck_timeout_minutes = max(5, (int) get_option('rts_queue_stuck_timeout_minutes', self::DEFAULT_STUCK_TIMEOUT_MINUTES));
        $stuck_cutoff = gmdate('Y-m-d H:i:s', time() - ($stuck_timeout_minutes * MINUTE_IN_SECONDS));

        return array(
            'total_pending'     => intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='pending'")),
            'stuck_items'       => intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status='processing' AND COALESCE(updated_at, created_at) < %s", $stuck_cutoff))),
            'avg_wait_minutes'  => floatval($wpdb->get_var("SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, NOW())) FROM {$table} WHERE status='pending'")),
            'hourly_throughput' => intval($wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status='sent' AND sent_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)")),
            'is_paused'         => $this->is_paused(),
        );
    }

    /**
     * Recover queue items stuck in processing due to crash/timeout.
     *
     * @param int|null $timeout_minutes
     * @return int Number of recovered items.
     */
    private function recover_stuck_processing_items($timeout_minutes = null) {
        global $wpdb;
        $table = $wpdb->prefix . self::QUEUE_TABLE;
        $timeout_minutes = $timeout_minutes !== null
            ? max(5, (int) $timeout_minutes)
            : max(5, (int) get_option('rts_queue_stuck_timeout_minutes', self::DEFAULT_STUCK_TIMEOUT_MINUTES));

        $cutoff = gmdate('Y-m-d H:i:s', time() - ($timeout_minutes * MINUTE_IN_SECONDS));
        $stuck_count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}
             WHERE status = 'processing'
               AND COALESCE(updated_at, created_at) < %s",
            $cutoff
        ));
        if ($stuck_count <= 0) {
            return 0;
        }

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET status = 'pending', updated_at = UTC_TIMESTAMP()
             WHERE status = 'processing'
               AND COALESCE(updated_at, created_at) < %s",
            $cutoff
        ));

        return $stuck_count;
    }
}
