<?php
/**
 * RTS Moderation Feedback Learning System
 *
 * Version: 1.3.0
 * Date: 2026-02-03
 *
 * Tracks admin decisions on flagged content and adjusts future scoring.
 * Learn from manual overrides to improve automatic moderation accuracy.
 * * Changelog:
 * 1.3.0 - Added JSON safety, Unicode word counting, FULLTEXT index, and batch pruning.
 * 1.2.0 - Added transaction support, improved indexing, and robust multi-byte handling.
 * 1.1.0 - Hardened security, added object caching, fixed SQL injection vulnerability.
 */

if (!defined('ABSPATH')) { exit; }

if (!class_exists('RTS_Moderation_Learning')) {

class RTS_Moderation_Learning {
    
    const TABLE_NAME = 'rts_moderation_feedback';
    const DB_VERSION = '1.3.0';
    const LEARNING_HISTORY_SIZE = 1000; // Keep last 1000 decisions
    const MIN_CONFIDENCE = 5; // Need at least 5 similar cases to adjust
    const CACHE_GROUP = 'rts_moderation';
    
    /**
     * Initialize the learning system
     */
    public static function init(): void {
        add_action('save_post_letter', [__CLASS__, 'on_letter_status_change'], 30, 3);
        add_action('admin_post_rts_record_feedback', [__CLASS__, 'handle_admin_feedback']);
        add_action('wp_ajax_rts_record_feedback_ajax', [__CLASS__, 'ajax_admin_feedback']);
        
        // Schedule daily maintenance
        if (!wp_next_scheduled('rts_daily_maintenance')) {
            wp_schedule_event(time(), 'daily', 'rts_daily_maintenance');
        }
        add_action('rts_daily_maintenance', [__CLASS__, 'prune_old_entries']);
        
        // Optimization: Only run table check if version changes, not every load
        if (get_option('rts_db_version') !== self::DB_VERSION) {
            self::ensure_table_exists();
            update_option('rts_db_version', self::DB_VERSION);
        }
    }
    
    /**
     * Record when admin overrides moderation decision
     */
    public static function record_admin_decision(int $post_id, string $action, array $scan_result): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        
        // Performance: Limit content processing length
        $content = (string) get_post_field('post_content', $post_id);
        $content = wp_strip_all_tags(mb_substr($content, 0, 20000)); // Limit to first 20k chars
        
        $fingerprint = self::generate_content_fingerprint($content);
        
        // Reliability: Safe JSON encoding
        $flags_json = wp_json_encode($scan_result['flags'] ?? []) ?: '[]';
        $details_json = wp_json_encode($scan_result['details'] ?? []) ?: '[]';
        
        // Transaction Support: Ensure insert and weight updates happen atomically
        $wpdb->query('START TRANSACTION');
        
        try {
            $result = $wpdb->insert($table, [
                'post_id' => $post_id,
                'admin_action' => sanitize_key($action),
                'original_verdict' => ($scan_result['pass'] ?? true) ? 'pass' : 'fail',
                'admin_verdict' => $action === 'override_to_publish' ? 'pass' : 'fail',
                'severity_score' => (int) ($scan_result['score'] ?? 0),
                'flags' => $flags_json,
                'scan_details' => $details_json,
                'content_fingerprint' => $fingerprint,
                'content_length' => mb_strlen($content),
                'word_count' => self::count_words($content), // Unicode aware count
                'admin_user_id' => get_current_user_id(),
                'recorded_at' => current_time('mysql'),
            ]);

            if (false === $result) {
                // Namespace fix: explicit \Exception
                throw new \Exception("Failed to insert decision for post ID $post_id. DB Error: " . $wpdb->last_error);
            }
            
            // Update pattern weights based on this decision
            self::update_pattern_weights($scan_result, $action);
            
            $wpdb->query('COMMIT');
            
        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');
            error_log("RTS Learning: Transaction failed - " . $e->getMessage());
        }
    }
    
    /**
     * Generate a fingerprint for content (for similarity matching)
     */
    private static function generate_content_fingerprint(string $content): string {
        // Multi-byte safety and length limit
        if (mb_strlen($content) > 10000) {
            $content = mb_substr($content, 0, 10000);
        }

        $features = [];
        
        // Word n-grams (2-3 word phrases)
        $words = preg_split('/\s+/', mb_strtolower($content));
        
        // Filter out short words and common stop words using dedicated helper
        $words = array_filter($words, function($w) {
            return mb_strlen($w) > 3 && !self::is_stop_word($w);
        });
        
        // Reset keys after filter
        $words = array_values($words); 

        // Create 2-grams only (3-grams can be too specific/sparse)
        $count = count($words);
        for ($i = 0; $i < $count - 1; $i++) {
            $features[] = $words[$i] . '_' . $words[$i + 1];
        }
        
        // Take top 15 most unique features
        $feature_counts = array_count_values($features);
        arsort($feature_counts);
        $top_features = array_slice(array_keys($feature_counts), 0, 15);
        
        // Ensure we don't exceed column limits (varchar 500)
        return mb_substr(implode('|', $top_features), 0, 500);
    }

    /**
     * Check if a word is a common stop word
     */
    private static function is_stop_word(string $word): bool {
        static $stop_words = [
            'the', 'be', 'to', 'of', 'and', 'a', 'in', 'that', 'have',
            'i', 'it', 'for', 'not', 'on', 'with', 'he', 'as', 'you',
            'do', 'at', 'this', 'but', 'his', 'by', 'from', 'they', 'we',
            'say', 'her', 'she', 'or', 'an', 'will', 'my', 'one', 'all',
            'would', 'there', 'their', 'what', 'so', 'up', 'out', 'if',
            'about', 'who', 'get', 'which', 'go', 'me', 'when', 'make',
            'can', 'like', 'time', 'no', 'just', 'know', 'take', 'person',
            'into', 'year', 'your', 'good', 'some', 'could', 'them', 'see',
            'other', 'than', 'then', 'now', 'look', 'only', 'come', 'its',
            'over', 'think', 'also', 'back', 'after', 'use', 'two', 'how',
            'our', 'work', 'first', 'well', 'way', 'even', 'new', 'want',
            'because', 'any', 'these', 'give', 'day', 'most', 'us'
        ];
        return in_array($word, $stop_words, true);
    }

    /**
     * Accurate Unicode word counting
     */
    private static function count_words(string $content): int {
        // Simple approximation - count spaces + 1
        $content = trim($content);
        if (empty($content)) return 0;
        
        // Count word boundaries for Unicode
        $words = preg_split('/\s+/u', $content);
        return count(array_filter($words, function($word) {
            return !empty(trim($word));
        }));
    }

    /**
     * Safe JSON decoding with error checking
     */
    private static function safe_json_decode($json): array {
        // Accept already-decoded arrays (some callers may pass meta arrays directly)
        if (is_array($json)) {
            return $json;
        }

        // Accept objects that can be cast safely
        if (is_object($json)) {
            $json = wp_json_encode($json);
        }

        if (!is_string($json)) {
            return [];
        }

        $json = trim($json);
        if ($json === '') {
            return [];
        }

        $data = json_decode($json, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($data)) ? $data : [];
    }
    
    /**
     * Adjust pattern weights based on admin feedback
     */
    private static function update_pattern_weights(array $scan_result, string $action): void {
        $flags = $scan_result['flags'] ?? [];
        $score = $scan_result['score'] ?? 0;
        
        // Performance: Fetch from cache or DB
        $weights = self::get_all_weights();
        
        foreach ($flags as $flag) {
            if (!isset($weights[$flag])) {
                $weights[$flag] = [
                    'count' => 0,
                    'admin_overrides' => 0,
                    'weight' => self::get_default_weight($flag),
                    'confidence' => 0,
                ];
            }
            
            $weights[$flag]['count']++;
            
            // If admin overrode the decision, reduce this flag's weight
            if ($action === 'override_to_publish') {
                $weights[$flag]['admin_overrides']++;
                
                // Adjust weight based on override rate
                $override_rate = $weights[$flag]['admin_overrides'] / $weights[$flag]['count'];
                if ($override_rate > 0.3 && $weights[$flag]['count'] >= 5) {
                    // Reduce weight if >30% of cases are overridden
                    $weights[$flag]['weight'] = max(1, $weights[$flag]['weight'] * 0.8);
                }
            }
            
            // Calculate confidence (more data = higher confidence)
            $weights[$flag]['confidence'] = min(100, $weights[$flag]['count'] * 5);
        }
        
        // Also track overall severity scoring accuracy
        $accuracy_data = get_option('rts_severity_accuracy', [
            'total' => 0,
            'correct' => 0,
            'threshold_adjustment' => 0,
        ]);
        
        $accuracy_data['total']++;
        $was_correct = ($score >= 40 && $action === 'quarantine') || 
                       ($score < 40 && $action === 'override_to_publish');
        
        if ($was_correct) {
            $accuracy_data['correct']++;
        }
        
        // Adjust threshold if accuracy is low
        if ($accuracy_data['total'] >= 20) {
            $accuracy_rate = $accuracy_data['correct'] / $accuracy_data['total'];
            if ($accuracy_rate < 0.7) { // Less than 70% accuracy
                $accuracy_data['threshold_adjustment'] = $accuracy_rate < 0.6 ? 5 : 2;
            }
        }
        
        // Cache Update: Set immediately to prevent stampede, then update DB
        wp_cache_set('rts_pattern_weights', $weights, self::CACHE_GROUP, HOUR_IN_SECONDS);
        update_option('rts_pattern_weights', $weights);
        update_option('rts_severity_accuracy', $accuracy_data);
    }
    
    /**
     * Helper to get all weights with caching
     */
    private static function get_all_weights(): array {
        $weights = wp_cache_get('rts_pattern_weights', self::CACHE_GROUP);
        if (false === $weights) {
            $weights = get_option('rts_pattern_weights', []);
            wp_cache_set('rts_pattern_weights', $weights, self::CACHE_GROUP, HOUR_IN_SECONDS);
        }
        return is_array($weights) ? $weights : [];
    }

    /**
     * Get default weight for a flag type
     */
    private static function get_default_weight(string $flag): int {
        $defaults = [
            'spam_keywords' => 100,
            'suspicious_links' => 100,
            'malicious_code' => 100,
            'encouragement_of_harm' => 50,
            'abusive_language' => 30,
            'imminent_danger' => 20,
        ];
        
        return $defaults[$flag] ?? 20;
    }
    
    /**
     * Get learned weight for a flag (with confidence)
     */
    public static function get_flag_weight(string $flag): array {
        $weights = self::get_all_weights();
        
        if (isset($weights[$flag]) && $weights[$flag]['confidence'] >= self::MIN_CONFIDENCE) {
            return [
                'weight' => $weights[$flag]['weight'],
                'confidence' => $weights[$flag]['confidence'],
                'override_rate' => $weights[$flag]['admin_overrides'] / max(1, $weights[$flag]['count']),
            ];
        }
        
        // Return default with low confidence
        return [
            'weight' => self::get_default_weight($flag),
            'confidence' => 0,
            'override_rate' => 0,
        ];
    }
    
    /**
     * Find similar historical decisions
     */
    public static function find_similar_decisions(string $content_fingerprint, array $flags): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        
        $fingerprint_parts = explode('|', $content_fingerprint);
        // Performance: Only check top 10 fingerprint parts to avoid massive SQL queries
        $fingerprint_parts = array_slice($fingerprint_parts, 0, 10);
        
        $similarity_sql = [];
        
        foreach ($fingerprint_parts as $part) {
            if (mb_strlen($part) > 3) { // Fixed: mb_strlen for consistency
                $similarity_sql[] = $wpdb->prepare("content_fingerprint LIKE %s", '%' . $wpdb->esc_like($part) . '%');
            }
        }
        
        if (empty($similarity_sql)) {
            return [];
        }
        
        // Check MySQL version for JSON_CONTAINS support (MySQL 5.7+)
        $mysql_version = $wpdb->db_version();
        $flags_json = wp_json_encode($flags) ?: '[]';
        
        if (version_compare($mysql_version, '5.7.0', '>=')) {
            // Use JSON_CONTAINS for MySQL 5.7+
            $flags_condition = "JSON_CONTAINS(flags, %s)";
            $flags_param = $flags_json;
        } else {
            // Fallback for older MySQL - simple LIKE search
            $flags_condition = "flags LIKE %s";
            $flags_param = '%' . $wpdb->esc_like($flags_json) . '%';
        }
        
        $query = "
            SELECT admin_action, COUNT(*) as count, 
                   AVG(severity_score) as avg_score,
                   GROUP_CONCAT(DISTINCT admin_verdict) as verdicts
            FROM {$table}
            WHERE (" . implode(' OR ', $similarity_sql) . ")
            OR {$flags_condition}
            GROUP BY admin_action
            ORDER BY count DESC
            LIMIT 10
        ";
        
        return $wpdb->get_results($wpdb->prepare($query, $flags_param), ARRAY_A);
    }
    
    /**
     * Hook into letter status changes
     */
    public static function on_letter_status_change(int $post_id, \WP_Post $post, bool $update): void {
        // Reliability: Prevent running on autosaves or revisions
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($post_id)) return;
        
        if ($post->post_type !== 'letter' || !$update) {
            return;
        }
        
        $old_status = get_post_meta($post_id, '_previous_status', true);
        $new_status = $post->post_status;
        
        // Track when admin manually publishes a quarantined letter
        // Logic: Valid old statuses include draft, pending, auto-draft, future
        if (in_array($old_status, ['draft', 'pending', 'auto-draft', 'future']) && $new_status === 'publish') {
            $needs_review = get_post_meta($post_id, 'needs_review', true);
            if ($needs_review === '1') {
                // Admin is overriding quarantine
                // Improvement: Safe JSON decoding
                $scan_result = [
                    'pass' => false,
                    'flags' => self::safe_json_decode(get_post_meta($post_id, 'rts_flagged_keywords', true) ?: '[]'),
                    'score' => (int) get_post_meta($post_id, 'quality_score', true),
                    'details' => self::safe_json_decode(get_post_meta($post_id, 'rts_safety_details', true) ?: '[]'),
                ];
                
                self::record_admin_decision($post_id, 'override_to_publish', $scan_result);
                
                // Log for debugging
                error_log("RTS Learning: Admin override for post {$post_id} with score {$scan_result['score']}");
            }
        }
        
        // Update previous status for next change
        update_post_meta($post_id, '_previous_status', $new_status);
    }
    
    /**
     * Handle explicit admin feedback (like/dislike of moderation decision)
     */
    public static function handle_admin_feedback(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }
        
        check_admin_referer('rts_feedback_action');
        
        $post_id = absint($_POST['post_id'] ?? 0);
        $feedback = sanitize_key($_POST['feedback'] ?? ''); // 'correct', 'too_strict', 'too_lenient'
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        
        // Validation: Ensure valid feedback type
        $allowed_feedback = ['correct', 'too_strict', 'too_lenient'];
        if (!in_array($feedback, $allowed_feedback, true)) {
             wp_safe_redirect(wp_get_referer() . '&rts_msg=feedback_invalid_type');
             exit;
        }
        
        if (!$post_id || get_post_type($post_id) !== 'letter') {
            wp_safe_redirect(wp_get_referer() . '&rts_msg=feedback_invalid_id');
            exit;
        }
        
        // Store feedback
        update_post_meta($post_id, 'rts_moderation_feedback', [
            'feedback' => $feedback,
            'notes' => $notes,
            'user_id' => get_current_user_id(),
            'timestamp' => current_time('mysql'),
        ]);
        
        // Adjust weights based on feedback
        if ($feedback === 'too_strict') {
            self::adjust_scoring_for_post($post_id, -1); // Make scoring more lenient
        } elseif ($feedback === 'too_lenient') {
            self::adjust_scoring_for_post($post_id, 1); // Make scoring more strict
        }
        
        wp_safe_redirect(wp_get_referer() . '&rts_msg=feedback_recorded');
        exit;
    }

        
    /**
     * AJAX: Record admin feedback without page reload (used in post submit box).
     */
    public static function ajax_admin_feedback(): void {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
        }

        $nonce = $_POST['_wpnonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'rts_feedback_action')) {
            wp_send_json_error(['message' => 'Invalid security token'], 403);
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        $feedback = sanitize_key($_POST['feedback'] ?? '');
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');

        $allowed_feedback = ['correct', 'too_strict', 'too_lenient'];
        if (!in_array($feedback, $allowed_feedback, true)) {
            wp_send_json_error(['message' => 'Invalid feedback type'], 400);
        }

        if (!$post_id || get_post_type($post_id) !== 'letter') {
            wp_send_json_error(['message' => 'Invalid letter'], 400);
        }

        update_post_meta($post_id, 'rts_moderation_feedback', [
            'feedback'  => $feedback,
            'notes'     => $notes,
            'user_id'   => get_current_user_id(),
            'timestamp' => current_time('mysql'),
        ]);

        // Adjust weights based on feedback (same as non-AJAX handler)
        if ($feedback === 'too_strict') {
            self::adjust_scoring_for_post($post_id, -1);
        } elseif ($feedback === 'too_lenient') {
            self::adjust_scoring_for_post($post_id, 1);
        }

        wp_send_json_success(['message' => 'Feedback recorded']);
    }


    /**
     * Adjust scoring based on explicit feedback
     */
    private static function adjust_scoring_for_post(int $post_id, int $direction): void {
        $flags = self::safe_json_decode(get_post_meta($post_id, 'rts_flagged_keywords', true) ?: '[]');
        $weights = self::get_all_weights();
        
        foreach ($flags as $flag) {
            if (!isset($weights[$flag])) {
                $weights[$flag] = [
                    'count' => 0,
                    'admin_overrides' => 0,
                    'weight' => self::get_default_weight($flag),
                    'confidence' => 0,
                ];
            }
            
            // Adjust weight in specified direction (±10%)
            $adjustment = $direction > 0 ? 1.1 : 0.9;
            $weights[$flag]['weight'] = max(1, $weights[$flag]['weight'] * $adjustment);
            $weights[$flag]['count']++;
        }
        
        // Update cache immediately then DB
        wp_cache_set('rts_pattern_weights', $weights, self::CACHE_GROUP, HOUR_IN_SECONDS);
        update_option('rts_pattern_weights', $weights);
    }
    
    /**
     * Create database table
     */
    public static function ensure_table_exists(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            admin_action varchar(50) NOT NULL,
            original_verdict varchar(20) NOT NULL,
            admin_verdict varchar(20) NOT NULL,
            severity_score int(11) DEFAULT 0,
            flags text,
            scan_details text,
            content_fingerprint varchar(500),
            content_length int(11),
            word_count int(11),
            admin_user_id bigint(20) unsigned,
            recorded_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY post_id (post_id),
            KEY admin_action (admin_action),
            KEY content_fingerprint (content_fingerprint(100)),
            FULLTEXT KEY ft_fingerprint (content_fingerprint),
            KEY fingerprint_prefix (content_fingerprint(10)),
            KEY recorded_at (recorded_at),
            KEY severity_score (severity_score),
            KEY idx_admin_user (admin_user_id),
            KEY idx_action_score (admin_action, severity_score)
        ) {$charset_collate};";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }
    
    /**
     * Prune old entries to keep table size manageable
     */
    public static function prune_old_entries(): void {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        
        // Improvement: Batch delete loop
        $batch_size = 1000;
        $date_limit = date('Y-m-d H:i:s', time() - (90 * DAY_IN_SECONDS)); // Keep 90 days
        
        do {
            $deleted = $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} 
                 WHERE recorded_at < %s 
                 ORDER BY recorded_at ASC 
                 LIMIT %d",
                $date_limit,
                $batch_size
            ));
        } while ($deleted === $batch_size);
        
        // Also limit total rows
        $count = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > self::LEARNING_HISTORY_SIZE) {
            $excess = (int) ($count - self::LEARNING_HISTORY_SIZE);
            if ($excess > 0) {
                // Batch delete for excess rows too if large
                $chunk_size = 100;
                while ($excess > 0) {
                    $limit = min($excess, $chunk_size);
                    $wpdb->query($wpdb->prepare(
                        "DELETE FROM {$table} ORDER BY recorded_at ASC LIMIT %d",
                        $limit
                    ));
                    $excess -= $limit;
                }
            }
        }
    }
    
    /**
     * Get learning statistics
     */
    public static function get_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        
        return [
            'total_decisions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'override_rate' => self::calculate_override_rate(),
            'accuracy_trend' => self::calculate_accuracy_trend(),
            'common_overrides' => self::get_common_override_flags(),
            'confidence_levels' => self::get_confidence_levels(),
        ];
    }
    
    private static function calculate_override_rate(): float {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        
        $total = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $overrides = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table} WHERE admin_action = 'override_to_publish'"
        );
        
        return $total > 0 ? round(($overrides / $total) * 100, 1) : 0;
    }
    
    private static function calculate_accuracy_trend(): array {
        $accuracy = get_option('rts_severity_accuracy', []);
        
        return [
            'total' => $accuracy['total'] ?? 0,
            'correct' => $accuracy['correct'] ?? 0,
            'rate' => isset($accuracy['total']) && $accuracy['total'] > 0 
                ? round(($accuracy['correct'] / $accuracy['total']) * 100, 1) 
                : 0,
        ];
    }
    
    private static function get_common_override_flags(): array {
        $weights = self::get_all_weights(); // Use cached getter
        
        $override_rates = [];
        foreach ($weights as $flag => $data) {
            if ($data['count'] >= 5) {
                $override_rates[$flag] = round(($data['admin_overrides'] / $data['count']) * 100, 1);
            }
        }
        
        arsort($override_rates);
        return array_slice($override_rates, 0, 10, true);
    }
    
    private static function get_confidence_levels(): array {
        $weights = self::get_all_weights(); // Use cached getter
        
        $confidence = [];
        foreach ($weights as $flag => $data) {
            $confidence[$flag] = [
                'confidence' => $data['confidence'] ?? 0,
                'data_points' => $data['count'] ?? 0,
            ];
        }
        
        return $confidence;
    }
}

} // End class_exists check