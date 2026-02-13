<?php
/**
 * RTS Audit Logger
 *
 * Captures key system events and dispatches optional webhooks.
 *
 * @package RTS_Subscriber_System
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_Audit_Logger {

    /**
     * Prevent duplicate hook registration.
     *
     * @var bool
     */
    private static $wired = false;

    public function __construct() {
        $this->init_hooks();
    }

    public function init_hooks() {
        if (self::$wired) {
            return;
        }
        self::$wired = true;

        add_action('rts_subscriber_created', array($this, 'on_subscriber_created'), 10, 3);
        add_action('rts_subscriber_unsubscribed', array($this, 'on_subscriber_unsubscribed'), 10, 2);
        add_action('rts_subscriber_bounced', array($this, 'on_subscriber_bounced'), 10, 3);
        add_action('rts_email_queued', array($this, 'on_email_queued'), 10, 2);
        add_action('rts_email_sent', array($this, 'on_email_sent'), 10, 2);
        add_action('updated_option', array($this, 'on_option_updated'), 10, 3);
    }

    public function on_subscriber_created($subscriber_id, $email, $source) {
        self::log_event('subscriber_created', 'subscriber', (int) $subscriber_id, array(
            'email'  => sanitize_email((string) $email),
            'source' => sanitize_key((string) $source),
        ));
    }

    public function on_subscriber_unsubscribed($subscriber_id, $source) {
        self::log_event('subscriber_unsubscribed', 'subscriber', (int) $subscriber_id, array(
            'source' => sanitize_key((string) $source),
        ));
    }

    public function on_subscriber_bounced($subscriber_id, $email, $error) {
        self::log_event('subscriber_bounced', 'subscriber', (int) $subscriber_id, array(
            'email' => sanitize_email((string) $email),
            'error' => sanitize_text_field((string) $error),
        ));
    }

    public function on_email_queued($queue_id, $template) {
        self::log_event('email_queued', 'queue_item', (int) $queue_id, array(
            'template' => sanitize_key((string) $template),
        ));
    }

    public function on_email_sent($queue_id, $status) {
        self::log_event('email_sent', 'queue_item', (int) $queue_id, array(
            'status' => sanitize_key((string) $status),
        ));
    }

    public function on_option_updated($option_name, $old_value, $value) {
        if (strpos((string) $option_name, 'rts_') !== 0) {
            return;
        }
        $tracked = array(
            'rts_email_sending_enabled',
            'rts_email_demo_mode',
            'rts_require_email_verification',
            'rts_email_batch_size',
            'rts_newsletter_batch_delay',
            'rts_queue_retention_sent_days',
            'rts_queue_retention_cancelled_days',
            'rts_queue_stuck_timeout_minutes',
            'rts_retention_email_logs_days',
            'rts_retention_tracking_days',
            'rts_retention_bounce_days',
            'rts_webhook_enabled',
            'rts_webhook_url',
        );
        if (!in_array((string) $option_name, $tracked, true)) {
            return;
        }

        self::log_event('setting_updated', 'option', 0, array(
            'option' => sanitize_key((string) $option_name),
        ));
    }

    /**
     * Persist an audit event and fire webhook if configured.
     *
     * @param string   $event_type
     * @param string   $entity_type
     * @param int|null $entity_id
     * @param array    $context
     * @return void
     */
    public static function log_event($event_type, $entity_type, $entity_id = null, array $context = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_system_audit';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        $payload = array(
            'event_type' => sanitize_key((string) $event_type),
            'entity_type'=> sanitize_key((string) $entity_type),
            'entity_id'  => $entity_id !== null ? (int) $entity_id : null,
            'actor_id'   => (int) get_current_user_id(),
            'context'    => !empty($context) ? wp_json_encode($context) : null,
            'created_at' => current_time('mysql', true),
        );

        $wpdb->insert(
            $table,
            $payload,
            array('%s', '%s', '%d', '%d', '%s', '%s')
        );

        self::dispatch_webhook($payload, $context);
    }

    /**
     * Dispatch webhook event to external systems.
     *
     * @param array $payload
     * @param array $context
     * @return void
     */
    private static function dispatch_webhook(array $payload, array $context) {
        if (!get_option('rts_webhook_enabled', false)) {
            return;
        }
        $url = esc_url_raw((string) get_option('rts_webhook_url', ''));
        if ($url === '') {
            return;
        }

        $body = wp_json_encode(array(
            'event'       => $payload['event_type'] ?? 'unknown',
            'entity_type' => $payload['entity_type'] ?? '',
            'entity_id'   => (int) ($payload['entity_id'] ?? 0),
            'actor_id'    => (int) ($payload['actor_id'] ?? 0),
            'context'     => $context,
            'occurred_at' => $payload['created_at'] ?? gmdate('Y-m-d H:i:s'),
        ));
        if (!$body) {
            return;
        }

        $headers = array(
            'Content-Type' => 'application/json',
        );
        $secret = (string) get_option('rts_webhook_secret', '');
        if ($secret !== '') {
            $headers['X-RTS-Signature'] = hash_hmac('sha256', $body, $secret);
        }

        wp_remote_post($url, array(
            'method'      => 'POST',
            'timeout'     => 5,
            'blocking'    => false,
            'headers'     => $headers,
            'body'        => $body,
            'data_format' => 'body',
        ));
    }
}
