<?php
/**
 * RTS Learning Engine
 *
 * Learns from human edits when a letter is published.
 * Compares the Bot Snapshot (_rts_bot_snapshot) against the final human content
 * and stores learned patterns to reduce future diffs.
 *
 * DB Version: 4.0
 */

if (!defined('ABSPATH')) { exit; }

class RTS_Learning_Engine {

    const TABLE_LOGS     = 'rts_learning_logs';
    const TABLE_PATTERNS = 'rts_learned_patterns';
    const MIN_SAMPLES_FOR_CONFIDENCE = 3;

    public static function init(): void {
        add_action('admin_init', [__CLASS__, 'check_db']);
        add_action('transition_post_status', [__CLASS__, 'on_post_status_transition'], 10, 3);

        // Daily maintenance hook (scheduled below)
        add_action('rts_daily_learning_cleanup', [__CLASS__, 'daily_maintenance']);

        // Ensure cleanup is scheduled (fail-soft; uses WP-Cron)
        if (!wp_next_scheduled('rts_daily_learning_cleanup')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'rts_daily_learning_cleanup');
        }
    }

    public static function check_db(): void {
        if (get_option('rts_learning_db_ver') === '4.0') return;

        global $wpdb;
        $charset = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        $sql1 = "CREATE TABLE {$wpdb->prefix}" . self::TABLE_LOGS . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT UNSIGNED NOT NULL,
            change_type VARCHAR(50) NOT NULL,
            value_from TEXT,
            value_to TEXT,
            reverted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_type (change_type)
        ) $charset;";

        $sql2 = "CREATE TABLE {$wpdb->prefix}" . self::TABLE_PATTERNS . " (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pattern_type VARCHAR(50) NOT NULL,
            pattern_value VARCHAR(191) NOT NULL,
            failure_count INT DEFAULT 0,
            success_count INT DEFAULT 0,
            is_active BOOLEAN DEFAULT TRUE,
            last_updated DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY idx_pattern (pattern_type, pattern_value)
        ) $charset;";

        dbDelta($sql1);
        dbDelta($sql2);
        update_option('rts_learning_db_ver', '4.0', false);
    }

    /**
     * WORKFLOW TRIGGER: Human Review Complete (Publish)
     */
    public static function on_post_status_transition($new_status, $old_status, $post): void {
        if (!$post instanceof WP_Post) return;
        if ($post->post_type !== 'letter') return;

        // Learn when a letter transitions TO publish (from anything else).
        if ($new_status === 'publish' && $old_status !== 'publish') {
            $bot_version = (string) get_post_meta($post->ID, '_rts_bot_snapshot', true);
            if ($bot_version !== '') {
                $human_version = (string) $post->post_content;

                if (md5($bot_version) !== md5($human_version)) {
                    self::learn_from_comparison((int) $post->ID, $bot_version, $human_version);
                }

                // Clear snapshot (job done)
                delete_post_meta($post->ID, '_rts_bot_snapshot');
            }
        }
    }

    /**
     * CORE: Compare Bot Text vs Human Text
     */
    public static function learn_from_comparison(int $post_id, string $automated_content, string $human_content): void {
        $auto_clean  = self::normalize_for_comparison($automated_content);
        $human_clean = self::normalize_for_comparison($human_content);

        self::detect_capitalization_changes($auto_clean, $human_clean, $post_id);
        self::detect_punctuation_changes($auto_clean, $human_clean, $post_id);
        self::detect_html_changes($automated_content, $human_content, $post_id);

        if (class_exists('RTS_Learning_Cache')) {
            RTS_Learning_Cache::invalidate_cache();
        }

        // Let the workflow layer record a "learned" timestamp (admin clarity).
        do_action('rts_workflow_mark_learned', $post_id, 'learning_engine');
    }

    // ---------------------------------------------------------------------
    // Modules
    // ---------------------------------------------------------------------

    private static function detect_capitalization_changes(string $auto_text, string $human_text, int $post_id): void {
        global $wpdb;

        $auto_words  = self::extract_words_with_context($auto_text);
        $human_words = self::extract_words_with_context($human_text);

        $limit = min(count($auto_words), count($human_words));
        if ($limit <= 0) return;

        for ($i = 0; $i < $limit; $i++) {
            $aw = $auto_words[$i]['word'];
            $hw = $human_words[$i]['word'];

            if (mb_strtolower($aw) === mb_strtolower($hw) && $aw !== $hw) {
                // Bot was wrong: learn exceptions.
                self::register_pattern('ignore_cap', $hw, 'failure');
                if (self::is_likely_proper_noun($hw)) {
                    self::register_pattern('proper_noun', $hw, 'failure');
                }

                $wpdb->insert($wpdb->prefix . self::TABLE_LOGS, [
                    'post_id' => $post_id,
                    'change_type' => 'capitalization',
                    'value_from' => $aw,
                    'value_to'   => $hw
                ], ['%d','%s','%s','%s']);
            }
        }
    }

    private static function detect_punctuation_changes(string $auto_text, string $human_text, int $post_id): void {
        global $wpdb;

        // If the human *re-introduces* a punctuation style the refiner removed, learn it as an exception.
        // Example: some writers deliberately keep a space before punctuation for stylistic effect.
        $patterns = [
            'space_before_punct'        => '/\s+([.,!?;:])/u',
            'missing_space_after_punct' => '/([.,!?;:])([^\s\d])/u',
        ];

        foreach ($patterns as $key => $regex) {
            $am = []; $hm = [];
            preg_match_all($regex, $auto_text, $am);
            preg_match_all($regex, $human_text, $hm);

            // Auto (cleaned) does not contain the pattern, but human final does -> human prefers it.
            if (empty($am[0]) && !empty($hm[0])) {
                $example = (string) $hm[0][0];
                self::register_pattern('ignore_punct_' . $key, $example, 'failure');

                $wpdb->insert($wpdb->prefix . self::TABLE_LOGS, [
                    'post_id' => $post_id,
                    'change_type' => 'punct_' . $key,
                    'value_from' => 'clean',
                    'value_to'   => $example,
                ], ['%d','%s','%s','%s']);
            }
        }
    }

    private static function detect_html_changes(string $auto_html, string $human_html, int $post_id): void {
        global $wpdb;

        // If human added inline styles back, learn that styles should be allowed.
        if (preg_match('/style=["\'][^"\']*["\']/', $human_html) && !preg_match('/style=["\'][^"\']*["\']/', $auto_html)) {
            self::register_pattern('allow_html_style', 'inline_styles', 'failure');
            $wpdb->insert($wpdb->prefix . self::TABLE_LOGS, [
                'post_id' => $post_id,
                'change_type' => 'html_style',
                'value_from' => 'clean',
                'value_to'   => 'styled'
            ], ['%d','%s','%s','%s']);
        }
    }

    // ---------------------------------------------------------------------
    // Patterns
    // ---------------------------------------------------------------------

    public static function register_pattern(string $type, string $value, string $result = 'failure'): void {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_PATTERNS;
        $value = trim((string) $value);
        if ($value === '') return;

        $exists = $wpdb->get_row(
            $wpdb->prepare("SELECT id FROM $table WHERE pattern_type = %s AND pattern_value = %s", $type, $value)
        );

        if ($exists && isset($exists->id)) {
            $col = ($result === 'success') ? 'success_count' : 'failure_count';
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
            $wpdb->query("UPDATE $table SET $col = $col + 1, last_updated = NOW() WHERE id = " . intval($exists->id));
        } else {
            $fail = ($result === 'failure') ? 1 : 0;
            $succ = ($result === 'success') ? 1 : 0;
            $wpdb->insert($table, [
                'pattern_type'  => $type,
                'pattern_value' => $value,
                'failure_count' => $fail,
                'success_count' => $succ,
            ], ['%s','%s','%d','%d']);
        }
    }

    public static function calculate_pattern_confidence(int $id): float {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT success_count, failure_count FROM {$wpdb->prefix}" . self::TABLE_PATTERNS . " WHERE id = %d",
            $id
        ));
        if (!$row) return 0.5;

        $succ = isset($row->success_count) ? (int) $row->success_count : 0;
        $fail = isset($row->failure_count) ? (int) $row->failure_count : 0;

        $total = $succ + $fail;
        if ($total === 0) return 0.5;

        $conf = $succ / $total;

        if ($total < self::MIN_SAMPLES_FOR_CONFIDENCE) {
            $conf = ($conf * $total + 0.5 * (self::MIN_SAMPLES_FOR_CONFIDENCE - $total)) / self::MIN_SAMPLES_FOR_CONFIDENCE;
        }

        return round((float) $conf, 3);
    }

    /**
     * Daily maintenance:
     * - Disable noisy patterns (fail-safe thresholds)
     * - Cleanup stale snapshots to prevent DB bloat
     * - Clear cache
     */
    public static function daily_maintenance(): void {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE_PATTERNS;

        // Disable patterns that are consistently "wrong": more than 2x failures vs successes and 6+ total samples.
        // This is intentionally conservative.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query(
            "UPDATE {$table} SET is_active = 0 WHERE (failure_count > (success_count * 2)) AND (failure_count + success_count) >= 6"
        );

        // Cleanup stale snapshots older than 30 days on letters not yet published.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $wpdb->query(
            "DELETE pm FROM {$wpdb->postmeta} pm\n" .
            "INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id\n" .
            "WHERE pm.meta_key = '_rts_bot_snapshot'\n" .
            "AND p.post_type = 'letter'\n" .
            "AND p.post_status IN ('pending','draft','auto-draft')\n" .
            "AND p.post_modified < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );

        if (class_exists('RTS_Learning_Cache')) {
            RTS_Learning_Cache::invalidate_cache();
        }
    }

    // ---------------------------------------------------------------------
    // Utils
    // ---------------------------------------------------------------------

    private static function normalize_for_comparison(string $t): string {
        return mb_strtolower(trim(wp_strip_all_tags($t)));
    }

    private static function extract_words_with_context(string $t): array {
        preg_match_all('/\b(\p{L}+[\p{L}\']*)\b/u', $t, $m, PREG_OFFSET_CAPTURE);
        $w = [];
        foreach (($m[0] ?? []) as $match) {
            $w[] = ['word' => $match[0], 'offset' => (int) $match[1]];
        }
        return $w;
    }

    private static function is_likely_proper_noun(string $w): bool {
        return (bool) (preg_match('/[a-z]+[A-Z]/', $w) || preg_match('/^[A-Z][a-z]+/', $w));
    }
}

/**
 * Cache Layer (transient)
 */
class RTS_Learning_Cache {

    const KEY = 'rts_learned_patterns_cache';
    const TTL = 3600;

    public static function get_patterns(string $type): array {
        $cache = get_transient(self::KEY);

        if ($cache === false || !is_array($cache) || !array_key_exists($type, $cache)) {
            global $wpdb;
            $vals = $wpdb->get_col($wpdb->prepare(
                "SELECT pattern_value FROM {$wpdb->prefix}rts_learned_patterns WHERE pattern_type = %s AND is_active = 1",
                $type
            ));
            if (!is_array($cache)) $cache = [];
            $cache[$type] = is_array($vals) ? $vals : [];
            set_transient(self::KEY, $cache, self::TTL);
            return $cache[$type];
        }

        return is_array($cache[$type]) ? $cache[$type] : [];
    }

    public static function invalidate_cache(): void {
        delete_transient(self::KEY);
    }
}

add_action('init', ['RTS_Learning_Engine', 'init'], 20);
