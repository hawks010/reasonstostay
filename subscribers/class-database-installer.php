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

    const VERSION = '1.4.0';

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

        // Index notes:
        // - rts_email_queue.status_scheduled supports due-item scans.
        // - rts_newsletter_analytics.newsletter_event + occurred_at supports dashboard timelines.
        // - rts_subscribers.status_next_send supports high-volume drip scheduling.
        // - rts_system_audit.entity_lookup supports actor/entity investigations.
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

        // 8. Subscribers (scheduling index for automated drip logic)
        $schemas[] = "CREATE TABLE {$wpdb->prefix}rts_subscribers (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id bigint(20) UNSIGNED NOT NULL,
            email varchar(250) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            frequency varchar(20) NOT NULL DEFAULT 'weekly',
            preferences text NOT NULL,
            next_send_date datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY post_id (post_id),
            KEY email (email),
            KEY status_next_send (status, next_send_date),
            KEY frequency (frequency)
        ) $charset_collate;";

        // 9. Newsletter Versions (content + builder snapshots)
        $schemas[] = "CREATE TABLE {$wpdb->prefix}rts_newsletter_versions (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            newsletter_id bigint(20) unsigned NOT NULL,
            version_no int(11) unsigned NOT NULL DEFAULT 1,
            title text NOT NULL,
            content longtext NOT NULL,
            builder_json longtext,
            reason varchar(100) DEFAULT NULL,
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY newsletter_version (newsletter_id, version_no),
            KEY created_at (created_at)
        ) $charset_collate;";

        // 10. Newsletter Analytics Events (sent/open/click/bounce/unsubscribe)
        $schemas[] = "CREATE TABLE {$wpdb->prefix}rts_newsletter_analytics (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            newsletter_id bigint(20) unsigned NOT NULL,
            queue_id bigint(20) unsigned DEFAULT NULL,
            subscriber_id bigint(20) unsigned DEFAULT NULL,
            event_type varchar(30) NOT NULL,
            target_url text,
            url_hash char(40) DEFAULT NULL,
            event_hash char(64) NOT NULL,
            metadata longtext,
            occurred_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_hash (event_hash),
            KEY newsletter_event (newsletter_id, event_type),
            KEY queue_id (queue_id),
            KEY occurred_at (occurred_at)
        ) $charset_collate;";

        // 11. Newsletter Template Library
        $schemas[] = "CREATE TABLE {$wpdb->prefix}rts_newsletter_templates (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            slug varchar(120) NOT NULL,
            name varchar(200) NOT NULL,
            thumbnail_url varchar(512) DEFAULT NULL,
            structure longtext NOT NULL,
            is_system tinyint(1) NOT NULL DEFAULT 0,
            created_by bigint(20) unsigned DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY is_system (is_system)
        ) $charset_collate;";

        // 12. Newsletter Audit Trail (workflow + send actions)
        $schemas[] = "CREATE TABLE {$wpdb->prefix}rts_newsletter_audit (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            newsletter_id bigint(20) unsigned NOT NULL,
            actor_id bigint(20) unsigned DEFAULT NULL,
            event_type varchar(40) NOT NULL,
            message text,
            context longtext,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY newsletter_event (newsletter_id, event_type),
            KEY created_at (created_at)
        ) $charset_collate;";

        // 13. System Audit Trail (subscriber, queue, settings actions)
        $schemas[] = "CREATE TABLE {$wpdb->prefix}rts_system_audit (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(80) NOT NULL,
            entity_type varchar(80) NOT NULL,
            entity_id bigint(20) unsigned DEFAULT NULL,
            actor_id bigint(20) unsigned DEFAULT NULL,
            context longtext,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY entity_lookup (entity_type, entity_id),
            KEY created_at (created_at)
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
            'rts_subscribers',
            'rts_newsletter_versions',
            'rts_newsletter_analytics',
            'rts_newsletter_templates',
            'rts_newsletter_audit',
            'rts_system_audit',
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

        $logs      = $wpdb->prefix . 'rts_email_logs';
        $queue     = $wpdb->prefix . 'rts_email_queue';
        $nla_table = $wpdb->prefix . 'rts_newsletter_analytics';

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

        // 3) Subscribers table: ensure it exists (added in v1.2.0 for automated drip).
        // dbDelta in install() handles creation; this ensures the table is present on upgrades.
        $subs = $wpdb->prefix . 'rts_subscribers';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $subs)) !== $subs) {
            // Table will be created by dbDelta in the main install() flow.
            // Nothing extra needed here; just a guard for future column additions.
        }

        // 4) Newsletter analytics: ensure event hash uniqueness index exists.
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $nla_table)) === $nla_table) {
            $has_event_hash = $wpdb->get_var("SHOW INDEX FROM {$nla_table} WHERE Key_name = 'event_hash'");
            if (empty($has_event_hash)) {
                $wpdb->query("ALTER TABLE {$nla_table} ADD UNIQUE KEY event_hash (event_hash)");
            }
        }
    }
}
