<?php
/**
 * RTS Workflow System (Authoritative)
 *
 * Non-negotiable: ALL workflow decisions must rely only on the rts_workflow_stage meta.
 * WordPress post_status may be used for visibility, but never for workflow routing.
 */

if (!defined('ABSPATH')) exit;

final class RTS_Workflow {

    /** Canonical meta keys (do not change) */
    const META_STAGE                 = 'rts_workflow_stage';
    const META_STAGE_LEGACY          = '_rts_workflow_stage';

    const META_INGESTED_AT           = 'rts_workflow_ingested_at_gmt';
    const META_PENDING_AT            = 'rts_workflow_pending_at_gmt';
    const META_PUBLISHED_AT          = 'rts_workflow_published_at_gmt';
    const META_ARCHIVED_AT           = 'rts_workflow_archived_at_gmt';

    const META_PROCESSING_STARTED_AT = 'rts_processing_started_gmt';
    const META_PROCESSING_LOCK       = 'rts_processing_lock'; // '1' / '0'

    // Bump when migration inference rules change.
    const OPT_MIGRATION_VER          = 'rts_workflow_migration_v3';

    /** The ONLY valid stages */
    const STAGE_UNPROCESSED   = 'unprocessed';
    const STAGE_PROCESSING    = 'processing';
    const STAGE_PENDING_REVIEW= 'pending_review';
    const STAGE_QUARANTINED   = 'quarantined';
    const STAGE_PUBLISHED     = 'published';
    const STAGE_ARCHIVED      = 'archived';

    public static function init(): void {
        add_action('admin_init', [__CLASS__, 'maybe_migrate_legacy_workflow'], 1);
        add_action('wp_insert_post', [__CLASS__, 'ensure_default_stage_on_insert'], 10, 3);
    }

    public static function valid_stages(): array {
        return [
            self::STAGE_UNPROCESSED,
            self::STAGE_PROCESSING,
            self::STAGE_PENDING_REVIEW,
            self::STAGE_QUARANTINED,
            self::STAGE_PUBLISHED,
            self::STAGE_ARCHIVED,
        ];
    }

    public static function get_stage(int $post_id): string {
        $post_id = absint($post_id);
        if (!$post_id) return self::STAGE_UNPROCESSED;

        $stage = (string) get_post_meta($post_id, self::META_STAGE, true);
        if ($stage === '') {
            $legacy = (string) get_post_meta($post_id, self::META_STAGE_LEGACY, true);
            if ($legacy !== '') {
                $stage = self::map_legacy_stage($legacy);
            }
        }

        if (!in_array($stage, self::valid_stages(), true)) {
            // Fail-safe default: treat unknown/missing as unprocessed (eligible for scan),
            // unless the post is already published (migration handles this in admin context).
            $stage = self::STAGE_UNPROCESSED;
        }
        return $stage;
    }

    public static function set_stage(int $post_id, string $stage, string $note = ''): bool {
        $post_id = absint($post_id);
        $stage = sanitize_key($stage);
        if (!$post_id) return false;
        if (!in_array($stage, self::valid_stages(), true)) return false;

        update_post_meta($post_id, self::META_STAGE, $stage);

        // Keep legacy meta in sync for backwards compatibility, but do not query it.
        update_post_meta($post_id, self::META_STAGE_LEGACY, $stage);

        $now = gmdate('c');
        switch ($stage) {
            case self::STAGE_UNPROCESSED:
                if ((string) get_post_meta($post_id, self::META_INGESTED_AT, true) === '') {
                    update_post_meta($post_id, self::META_INGESTED_AT, $now);
                }
                break;
            case self::STAGE_PENDING_REVIEW:
                update_post_meta($post_id, self::META_PENDING_AT, $now);
                break;
            case self::STAGE_PUBLISHED:
                update_post_meta($post_id, self::META_PUBLISHED_AT, $now);
                break;
            case self::STAGE_ARCHIVED:
                update_post_meta($post_id, self::META_ARCHIVED_AT, $now);
                break;
        }

        if ($note !== '') {
            self::append_log($post_id, $note);
        }

        return true;
    }

    public static function append_log(int $post_id, string $note): void {
        $post_id = absint($post_id);
        if (!$post_id) return;

        $note = trim(wp_strip_all_tags($note));
        if ($note === '') return;

        $log = get_post_meta($post_id, 'rts_workflow_log', true);
        if (!is_array($log)) $log = [];

        $log[] = [
            't' => gmdate('c'),
            'u' => get_current_user_id(),
            'n' => $note,
        ];

        // cap to last 100 entries
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }

        update_post_meta($post_id, 'rts_workflow_log', $log);
    }

    public static function mark_processing_started(int $post_id): void {
        $post_id = absint($post_id);
        if (!$post_id) return;

        update_post_meta($post_id, self::META_PROCESSING_STARTED_AT, gmdate('c'));
        update_post_meta($post_id, self::META_PROCESSING_LOCK, '1');
    }

    public static function clear_processing_lock(int $post_id): void {
        $post_id = absint($post_id);
        if (!$post_id) return;

        delete_post_meta($post_id, self::META_PROCESSING_STARTED_AT);
        update_post_meta($post_id, self::META_PROCESSING_LOCK, '0');
    }

    public static function is_processing_stale(int $post_id): bool {
        $post_id = absint($post_id);
        if (!$post_id) return false;

        $stage = self::get_stage($post_id);
        if ($stage !== self::STAGE_PROCESSING) return false;

        $started = (string) get_post_meta($post_id, self::META_PROCESSING_STARTED_AT, true);
        if ($started === '') return false;

        $ts = strtotime($started);
        if (!$ts) return false;

        $threshold = (int) apply_filters('rts_processing_stale_seconds', 15 * 60); // 15 minutes default
        return (time() - $ts) > $threshold;
    }

    public static function reset_stuck_to_unprocessed(int $post_id, string $note = ''): bool {
        $post_id = absint($post_id);
        if (!$post_id) return false;

        if (!self::is_processing_stale($post_id)) return false;

        self::clear_processing_lock($post_id);
        return self::set_stage($post_id, self::STAGE_UNPROCESSED, $note ?: 'Rescan stuck: reset processing lock');
    }

    public static function count_by_stage(string $stage): int {
        $stage = sanitize_key($stage);
        if (!in_array($stage, self::valid_stages(), true)) return 0;

        $q = new WP_Query([
            'post_type'      => 'letter',
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => false,
            'meta_query'     => [
                [ 'key' => self::META_STAGE, 'value' => $stage ],
            ],
        ]);

        return (int) $q->found_posts;
    }

    /**
     * Ensure new Letter CPT posts always have a valid workflow stage.
     * This is a defaulting behavior only, not a routing decision.
     */
    public static function ensure_default_stage_on_insert(int $post_id, WP_Post $post, bool $update): void {
        if ($update) return;
        if (!$post || $post->post_type !== 'letter') return;
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

        $stage = (string) get_post_meta($post_id, self::META_STAGE, true);
        if ($stage !== '' && in_array($stage, self::valid_stages(), true)) {
            return;
        }

        // Default to unprocessed, and stamp ingest time.
        self::set_stage($post_id, self::STAGE_UNPROCESSED, 'Auto default stage on insert');
    }

    /**
     * One-time migration to canonical stage set + canonical meta key.
     * Runs in admin only.
     */
    public static function maybe_migrate_legacy_workflow(): void {
        if (get_option(self::OPT_MIGRATION_VER) === '3') return;

        if (!current_user_can('manage_options')) return;

        // We migrate in small batches to avoid admin timeouts.
        $batch = 250;
        $offset = (int) get_option('rts_workflow_migration_offset', 0);

        $q = new WP_Query([
            'post_type'      => 'letter',
            'post_status'    => 'any',
            'posts_per_page' => $batch,
            'offset'         => $offset,
            'fields'         => 'ids',
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);

        foreach ((array) $q->posts as $id) {
            $id = (int) $id;

            $stage_new = (string) get_post_meta($id, self::META_STAGE, true);
            if ($stage_new !== '' && in_array($stage_new, self::valid_stages(), true)) {
                continue;
            }

            $legacy = (string) get_post_meta($id, self::META_STAGE_LEGACY, true);
            if ($legacy !== '') {
                $mapped = self::map_legacy_stage($legacy);
                self::set_stage($id, $mapped, 'Migrated legacy workflow stage');
                continue;
            }

            // No meta at all: infer a SAFE stage.
            // Hard rule: do NOT default legacy content into unprocessed if it could already be in a review queue.
            // We intentionally bias toward pending_review to avoid accidental automatic reprocessing.
            $ps = get_post_status($id);

            if ($ps === 'publish') {
                self::set_stage($id, self::STAGE_PUBLISHED, 'Inferred stage from published post_status during migration');
                continue;
            }

            if ($ps === 'trash') {
                self::set_stage($id, self::STAGE_ARCHIVED, 'Inferred stage from trashed post_status during migration');
                continue;
            }

            if ($ps === 'pending') {
                self::set_stage($id, self::STAGE_PENDING_REVIEW, 'Inferred stage from pending post_status during migration');
                continue;
            }

            // If the legacy quarantine flag exists, treat as quarantined.
            $needs_review = (string) get_post_meta($id, 'needs_review', true);
            if ($needs_review === '1') {
                self::set_stage($id, self::STAGE_QUARANTINED, 'Inferred stage from needs_review flag during migration');
                continue;
            }

            // Draft/private/future or anything else without stage: treat as pending_review by default.
            // If the site owner wants these to be rescanned, they can manually push them to unprocessed.
            self::set_stage($id, self::STAGE_PENDING_REVIEW, 'Defaulted missing stage to pending_review during migration');
        }

        $next = $offset + count((array) $q->posts);
        update_option('rts_workflow_migration_offset', $next, false);

        if (count((array) $q->posts) < $batch) {
            update_option(self::OPT_MIGRATION_VER, '3', false);
            delete_option('rts_workflow_migration_offset');
        }
    }

    private static function map_legacy_stage(string $legacy): string {
        $legacy = sanitize_key($legacy);

        // Map older RTS stage keys to the canonical stage set.
        $map = [
            // previous "ingested" / missing -> unprocessed
            'ingested'           => self::STAGE_UNPROCESSED,
            'unprocessed'        => self::STAGE_UNPROCESSED,
            // processing stays
            'processing'         => self::STAGE_PROCESSING,
            // waiting for human review
            'pending_review'     => self::STAGE_PENDING_REVIEW,
            // quarantine variants
            'flagged_draft'      => self::STAGE_QUARANTINED,
            'quarantined'        => self::STAGE_QUARANTINED,
            // published variants
            'approved_published' => self::STAGE_PUBLISHED,
            'skipped_published'  => self::STAGE_PUBLISHED,
            'published'          => self::STAGE_PUBLISHED,
            // archived stays
            'archived'           => self::STAGE_ARCHIVED,
        ];

        return $map[$legacy] ?? self::STAGE_UNPROCESSED;
    }
}

RTS_Workflow::init();
