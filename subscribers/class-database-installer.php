<?php
/**
 * RTS Database Installer
 *
 * Centralizes all table creation logic.
 *
 * @package    RTS_Subscriber_System
 * @subpackage Core
 * @version    1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_Database_Installer {

    const VERSION = '1.1.0';

    public static function install() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $tables = self::get_table_schemas();

        foreach ($tables as $sql) {
            dbDelta($sql);
        }

        // Apply additive upgrades for existing installs (no drops).
        self::maybe_upgrade_schema();

        // Update version
        update_option('rts_database_version', self::VERSION);
        
        // Flag to tell other classes that tables are centralized
        update_option('rts_centralized_tables', true);
    }

    private static function get_table_schemas() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $schemas = array();

        // 1. Email Logs
        $schemas[] = "CREATE TABLE {$wpdb->prefix}rts_email_logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            subscriber_id bigint(20) unsigned NOT NULL,
            email varchar(250) NOT NULL,
            template varchar(50) NOT NULL,
            letter_id bigint(20) unsigned DEFAULT NULL,
            subject text NOT NULL,
            status varchar(20) NOT NULL,
            sent_at datetime NOT NULL,
            error text,
            metadata longtext,
            PRIMARY KEY  (id),
            KEY subscriber_id (subscriber_id),
            KEY email (email),
            KEY status (status),
            KEY sent_at (sent_at),
            KEY template (template)
        ) $charset_collate;";

        // 2. Email Queue
        $schemas[] = "CREATE TABLE {$wpdb->prefix}rts_email_queue (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            subscriber_id bigint(20) unsigned NOT NULL,
            letter_id bigint(20) unsigned DEFAULT NULL,
            template varchar(50) NOT NULL,
            subject text NOT NULL,
            body longtext NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            priority tinyint(3) unsigned NOT NULL DEFAULT 5,
            scheduled_at datetime NOT NULL,
            sent_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            error_log text,
            PRIMARY KEY  (id),
            KEY status_scheduled (status, scheduled_at),
            KEY priority (priority),
            KEY subscriber_id (subscriber_id),
            KEY template (template)
        ) $charset_collate;";

        // 3. Dead Letter Queue
        $schemas[] = "CREATE TABLE {$wpdb->prefix}rts_dead_letter_queue (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            original_id bigint(20) unsigned NOT NULL,
            subscriber_id bigint(20) unsigned NOT NULL,
            template varchar(50) NOT NULL,
            subject text NOT NULL,
            error_log text NOT NULL,
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            moved_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY subscriber_id (subscriber_id),
            KEY moved_at (moved_at)
        ) $charset_collate;";

        // 4. Tracking
        $schemas[] = "CREATE TABLE {$wpdb->prefix}rts_email_tracking (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            queue_id bigint(20) unsigned NOT NULL,
            subscriber_id bigint(20) unsigned NOT NULL,
            template varchar(50) NOT NULL,
            track_id varchar(64) NOT NULL,
            type varchar(20) NOT NULL,
            url text,
            opened tinyint(1) DEFAULT 0,
            clicked tinyint(1) DEFAULT 0,
            opened_at datetime DEFAULT NULL,
            clicked_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY track_id (track_id),
            KEY queue_id (queue_id),
            KEY subscriber_id (subscriber_id),
            KEY type (type)
        ) $charset_collate;";

        // 5. Bounces
        $schemas[] = "CREATE TABLE {$wpdb->prefix}rts_email_bounces (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            subscriber_id bigint(20) unsigned NOT NULL,
            email varchar(250) NOT NULL,
            error text NOT NULL,
            bounced_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY subscriber_id (subscriber_id),
            KEY email (email),
            KEY bounced_at (bounced_at)
        ) $charset_collate;";

        // 6. Rate Limits
        $schemas[] = "CREATE TABLE {$wpdb->prefix}rts_rate_limits (
            id VARCHAR(64) NOT NULL, 
            attempts INT DEFAULT 0, 
            expires DATETIME, 
            PRIMARY KEY (id),
            KEY expires (expires)
        ) $charset_collate;";

        // 7. Engagement
        $schemas[] = "CREATE TABLE {$wpdb->prefix}rts_engagement (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            subscriber_id bigint(20) UNSIGNED NOT NULL,
            date date NOT NULL,
            emails_sent int(11) DEFAULT 0,
            opened int(11) DEFAULT 0,
            clicked int(11) DEFAULT 0,
            open_rate float DEFAULT 0,
            click_rate float DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY subscriber_date (subscriber_id, date),
            KEY date (date)
        ) $charset_collate;";

        return $schemas;
    }

    public static function tables_exist() {
        global $wpdb;
        $tables = array(
            'rts_email_logs',
            'rts_email_queue',
            'rts_dead_letter_queue',
            'rts_email_tracking',
            'rts_email_bounces',
            'rts_rate_limits',
            'rts_engagement',
        );
        
        foreach ($tables as $table) {
            $full_name = $wpdb->prefix . $table;
            if ($wpdb->get_var("SHOW TABLES LIKE '$full_name'") !== $full_name) {
                return false;
            }
        }
        return true;
    }


    /**
     * Helper to migrate schemas from individual classes to centralized.
     * dbDelta in install() handles the heavy lifting, this just ensures it runs.
     */
    public static function migrate_from_individual() {
        global $wpdb;

        // Check if main tables exist (indicating old install might be present)
        $full_name = $wpdb->prefix . 'rts_email_logs';
        if ($wpdb->get_var("SHOW TABLES LIKE '$full_name'") === $full_name) {
            // Re-run install to ensure schema updates (like adding new columns)
            self::install();
        }
    }

    /**
     * Apply additive schema upgrades without dropping legacy tables.
     * - Adds rts_email_logs.letter_id if missing (to prevent duplicates).
     * - Ensures rts_email_queue has index on (status, scheduled_at) for scalability.
     */
    private static function maybe_upgrade_schema() {
        global $wpdb;

        $logs  = $wpdb->prefix . 'rts_email_logs';
        $queue = $wpdb->prefix . 'rts_email_queue';

        // 1) Email logs: add letter_id column if missing.
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $logs)) === $logs) {
            $has_letter = $wpdb->get_var("SHOW COLUMNS FROM {$logs} LIKE 'letter_id'");
            if (empty($has_letter)) {
                // Add AFTER template for readability (non-breaking).
                $wpdb->query("ALTER TABLE {$logs} ADD COLUMN letter_id bigint(20) unsigned DEFAULT NULL AFTER template");

                // Optional index to speed up exclusion queries.
                $has_index = $wpdb->get_var("SHOW INDEX FROM {$logs} WHERE Key_name = 'letter_id'");
                if (empty($has_index)) {
                    $wpdb->query("ALTER TABLE {$logs} ADD KEY letter_id (letter_id)");
                }
            }
        }

        // 2) Queue: ensure composite index on (status, scheduled_at) exists.
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $queue)) === $queue) {
            $has_status_scheduled = $wpdb->get_var("SHOW INDEX FROM {$queue} WHERE Key_name = 'status_scheduled'");
            if (empty($has_status_scheduled)) {
                $wpdb->query("ALTER TABLE {$queue} ADD KEY status_scheduled (status, scheduled_at)");
            }
        }
    }
}
