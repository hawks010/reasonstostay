<?php
/**
 * RTS Email Engine
 *
 * Builds emails, queues them, sends queued items, logs, tracking, and bounces.
 *
 * @package    RTS_Subscriber_System
 * @subpackage Engine
 * @version    1.0.4
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_Email_Engine {

    /**
     * Cache for subscriber validation results to reduce DB hits in loops.
     * @var array
     */
    private $subscriber_status_cache = array();

    public function __construct() {
        // Queue processing hooks
        add_action('rts_send_queued_email', array($this, 'send_queued_email'), 10, 1);
        
        // Error handling
        add_action('wp_mail_failed', array($this, 'handle_wp_mail_failed'), 10, 1);
        
        // Digest scheduling
        add_action('rts_daily_digest', array($this, 'run_daily_digest'));
        add_action('rts_weekly_digest', array($this, 'run_weekly_digest'));
        add_action('rts_monthly_digest', array($this, 'run_monthly_digest'));

        // Table initialization (runs once per version update)
        add_action('admin_init', array($this, 'maybe_create_tables'));

        // Tracking Handler (Listens for pixel/link clicks)
        add_action('init', array($this, 'handle_tracking_request'));
    }

    /**
     * Ensure required database tables exist.
     */
    public function maybe_create_tables() {
        // Skip if centralized installer is handling tables
        if (get_option('rts_centralized_tables') || class_exists('RTS_Database_Installer')) {
            return;
        }

        $db_version = get_option('rts_email_engine_db_version');
        $current_version = '1.0.3'; 

        if ($db_version !== $current_version) {
            $this->create_tables();
            update_option('rts_email_engine_db_version', $current_version);
        }
    }

    // ... (keep create_tables method as fallback for now, though it won't run if centralized is present) ...
    private function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Tracking Table (Simplified fallback)
        $sql_tracking = "CREATE TABLE {$wpdb->prefix}rts_email_tracking (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            queue_id bigint(20) unsigned NOT NULL,
            subscriber_id bigint(20) unsigned NOT NULL,
            template varchar(50) NOT NULL,
            track_id varchar(64) NOT NULL,
            type varchar(20) NOT NULL,
            url text,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY track_id (track_id),
            KEY queue_id (queue_id),
            KEY subscriber_id (subscriber_id)
        ) $charset_collate;";

        dbDelta($sql_tracking);
    }

    // ... (Rest of the class methods) ...

    /**
     * Store tracking row.
     */
    private function store_tracking_row($queue_id, $subscriber_id, $template, $track_id, $type, $url = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_email_tracking';

        // Check existence to prevent unique constraint errors
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE track_id = %s", $track_id));
        if ($exists) {
            return;
        }

        // Limit URL length for DB safety
        if ($url && strlen($url) > 2000) {
            $url = substr($url, 0, 2000);
        }

        $data = array(
            'queue_id'      => intval($queue_id),
            'subscriber_id' => intval($subscriber_id),
            'template'      => sanitize_key($template),
            'track_id'      => sanitize_text_field($track_id),
            'type'          => $type === 'click' ? 'click' : 'open',
            'url'           => $url ? esc_url_raw($url) : null,
            'created_at'    => current_time('mysql'),
        );

        // Add extra columns if centralized installer tables are present
        if (get_option('rts_centralized_tables')) {
            $data['opened'] = 0;
            $data['clicked'] = 0;
        }

        $wpdb->insert($table, $data);
    }

    // ... (Keep existing methods: send_queued_email, handle_tracking_request, etc.) ...
    
    // (Note: For brevity, I am not repeating the entire file content here, but you should ensure
    // the store_tracking_row and maybe_create_tables methods are updated as above in your full file.)
}