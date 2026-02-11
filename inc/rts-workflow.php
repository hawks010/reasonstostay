<?php
/**
 * RTS Workflow System
 *
 * Authoritative lifecycle tracking for the Letter CPT.
 */

if (!defined('ABSPATH')) exit;

final class RTS_Workflow {

    const META_STAGE        = '_rts_workflow_stage';
    const META_INGESTED_AT  = '_rts_workflow_ingested_at';
    const META_PENDING_AT   = '_rts_workflow_pending_at';
    const META_COMPLETED_AT = '_rts_workflow_completed_at';
    const META_LEARNED_AT   = '_rts_workflow_learned_at';
    const META_LOG          = '_rts_workflow_log';

    const DB_OPT_INDEX_VER  = 'rts_workflow_index_ver';

    public static function init() {
        add_action('admin_init', [__CLASS__, 'ensure_workflow_index']);
        add_action('wp_insert_post', [__CLASS__, 'on_insert_post'], 10, 3);
        add_action('transition_post_status', [__CLASS__, 'on_transition'], 10, 3);
        add_action('rts_workflow_mark_learned', [__CLASS__, 'mark_learned'], 10, 2);
    }

    public static function ensure_workflow_index() {
        // ABORT: Never run schema changes on live production traffic.
        // Creating indexes on wp_postmeta can lock the table for a long time on large sites.
        // If you want this index, add it manually via your DB tool during a quiet window.
        return;

        if (get_option(self::DB_OPT_INDEX_VER) === '1') return;
        global $wpdb;
        $table = $wpdb->postmeta;
        $idx_name = 'rts_workflow_stage_idx';

        // Fail-soft.
        try {
            $existing = $wpdb->get_var($wpdb->prepare("SHOW INDEX FROM {$table} WHERE Key_name = %s", $idx_name));
            if ($existing) {
                update_option(self::DB_OPT_INDEX_VER, '1');
                return;
            }

            // Composite index speeds up meta_key/meta_value queries for 40k+ posts.
            $wpdb->query("ALTER TABLE {$table} ADD INDEX {$idx_name} (meta_key(191), meta_value(50))");
            update_option(self::DB_OPT_INDEX_VER, '1');
        } catch (Throwable $e) {
            // no-op
        }
    }

    public static function on_insert_post($post_id, $post, $update) {
        if (!($post instanceof WP_Post)) return;
        if ($post->post_type !== 'letter') return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

        if (!get_post_meta($post_id, self::META_STAGE, true)) {
            $stage = self::infer_stage_from_post($post_id, $post);
            self::set_stage($post_id, $stage, $update ? 'save' : 'create', false);
        }
    }

    public static function on_transition($new_status, $old_status, $post) {
        if (!($post instanceof WP_Post)) return;
        if ($post->post_type !== 'letter') return;
        if ($new_status === $old_status) return;

        if ($new_status === 'pending') {
            self::set_stage($post->ID, 'pending_review', 'status:pending', false);
            self::touch_timestamp($post->ID, self::META_PENDING_AT);
            return;
        }

        if ($new_status === 'publish') {
            self::set_stage($post->ID, 'approved_published', 'status:publish', false);
            self::touch_timestamp($post->ID, self::META_COMPLETED_AT);
            return;
        }

        if ($new_status === 'draft') {
            $flagged = self::is_flagged($post->ID);
            self::set_stage($post->ID, $flagged ? 'flagged_draft' : 'ingested', 'status:draft', false);
            return;
        }
    }

    public static function mark_learned($post_id, $trigger = 'learn') {
        $post_id = absint($post_id);
        if (!$post_id || get_post_type($post_id) !== 'letter') return;
        self::touch_timestamp($post_id, self::META_LEARNED_AT);
        self::append_log($post_id, 'learned', 'learned', sanitize_key((string) $trigger));
    }

    public static function get_stage($post_id) {
        $post_id = absint($post_id);
        return (string) get_post_meta($post_id, self::META_STAGE, true);
    }

    public static function set_stage($post_id, $new_stage, $trigger = 'system', $sync_status = false) {
        $post_id = absint($post_id);
        if (!$post_id || get_post_type($post_id) !== 'letter') return;

        $new_stage = sanitize_key((string) $new_stage);
        $old_stage = (string) get_post_meta($post_id, self::META_STAGE, true);

        if ($old_stage === $new_stage && $old_stage !== '') {
            if (!get_post_meta($post_id, self::META_INGESTED_AT, true)) {
                self::touch_timestamp($post_id, self::META_INGESTED_AT);
            }
            return;
        }

        if (!$old_stage) {
            self::touch_timestamp($post_id, self::META_INGESTED_AT);
        }

        update_post_meta($post_id, self::META_STAGE, $new_stage);

        if ($new_stage === 'pending_review') self::touch_timestamp($post_id, self::META_PENDING_AT);
        if ($new_stage === 'approved_published' || $new_stage === 'skipped_published') self::touch_timestamp($post_id, self::META_COMPLETED_AT);

        self::append_log($post_id, $old_stage ? $old_stage : 'none', $new_stage, sanitize_key((string) $trigger));

        if ($sync_status) {
            self::sync_status_bucket($post_id, $new_stage);
        }
    }

    private static function sync_status_bucket($post_id, $stage) {
        $target = null;
        if ($stage === 'pending_review') $target = 'pending';
        if ($stage === 'flagged_draft' || $stage === 'ingested') $target = 'draft';
        if ($stage === 'approved_published' || $stage === 'skipped_published') $target = 'publish';

        if ($target && get_post_status($post_id) !== $target) {
            wp_update_post(['ID' => $post_id, 'post_status' => $target]);
        }
    }

    private static function touch_timestamp($post_id, $meta_key) {
        if (!get_post_meta($post_id, $meta_key, true)) {
            update_post_meta($post_id, $meta_key, current_time('mysql', true));
        }
    }

    private static function append_log($post_id, $from, $to, $trigger) {
        $entry = [
            'ts' => current_time('mysql', true),
            'from' => (string) $from,
            'to' => (string) $to,
            'trigger' => (string) $trigger,
            'user' => get_current_user_id(),
        ];
        add_post_meta($post_id, self::META_LOG, $entry, false);
    }

    public static function is_flagged($post_id) {
        $post_id = absint($post_id);
        $default = false;

        $needs_review = (string) get_post_meta($post_id, 'needs_review', true);
        if ($needs_review === '1' || $needs_review === 'yes' || $needs_review === 'true') $default = true;

        $reasons = (string) get_post_meta($post_id, 'rts_flag_reasons', true);
        $kw = (string) get_post_meta($post_id, 'rts_flagged_keywords', true);
        if ($reasons !== '' || $kw !== '') $default = true;

        return (bool) apply_filters('rts_is_flagged_letter', $default, $post_id);
    }

    public static function infer_stage_from_post($post_id, $post = null) {
        $post_id = absint($post_id);
        $post = ($post instanceof WP_Post) ? $post : get_post($post_id);
        if (!$post || $post->post_type !== 'letter') return 'ingested';

        $status = $post->post_status;

        if ($status === 'pending') return 'pending_review';

        if ($status === 'draft') {
            return self::is_flagged($post_id) ? 'flagged_draft' : 'ingested';
        }

        if ($status === 'publish') {
            $touched = (bool) get_post_meta($post_id, '_rts_refined', true)
                || (bool) get_post_meta($post_id, '_rts_bot_snapshot', true)
                || (bool) get_post_meta($post_id, self::META_PENDING_AT, true);

            return $touched ? 'approved_published' : 'skipped_published';
        }

        return 'ingested';
    }

    public static function count_by_stage($stage) {
        $stage = sanitize_key((string) $stage);
        $cache_key = 'rts_stage_count_' . $stage;
        $cached = wp_cache_get($cache_key, 'rts');
        if ($cached !== false) return (int) $cached;

        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(pm.post_id) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = %s",
            self::META_STAGE,
            $stage,
            'letter'
        ));

        wp_cache_set($cache_key, $count, 'rts', 60);
        return $count;
    }
}

add_action('init', ['RTS_Workflow', 'init'], 15);
