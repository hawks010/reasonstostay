<?php
/**
 * RTS Content Refiner
 *
 * Version: 7.2 (Pending Review Workflow & Dear Strange Fix)
 * - Forces 'pending' status for review (unless already published).
 * - Saves Snapshot for Learning Engine (_rts_bot_snapshot).
 * - Enforces "Dear stranger" correctly (strict word boundary to avoid Today/Tomorrow).
 * - WCAG 2.2 AA: strips inline styles unless learning cache allows them.
 */

if (!defined('ABSPATH')) { exit; }

class RTS_Content_Refiner {

    const META_PROCESSED = '_rts_refined';
    const META_LOCKED    = '_rts_manual_lock';
    const META_SNAPSHOT  = '_rts_bot_snapshot';

    /**
     * Refine a letter post.
     *
     * @param int  $post_id
     * @param bool $force   Ignore manual lock when true.
     * @return array{success:bool,msg:string}
     */
    public static function refine(int $post_id, bool $force = false): array {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'letter') {
            return ['success' => false, 'msg' => 'Invalid post'];
        }

        if (!$force && get_post_meta($post_id, self::META_LOCKED, true)) {
            return ['success' => false, 'msg' => 'Locked by manual edit'];
        }

        $content       = (string) $post->post_content;
        $original_hash = md5($content);

        // Safety: create a revision before we touch content.
        if (function_exists('wp_save_post_revision')) {
            wp_save_post_revision($post_id);
        }

        $new_content = self::pipeline_html_cleanup($content);
        $new_content = self::pipeline_heuristics($new_content);
        $new_content = self::pipeline_structure($new_content);

        // Status logic: force PENDING if not already published.
        $current_status = (string) get_post_status($post_id);
        $new_status     = ($current_status === 'publish') ? 'publish' : 'pending';

        if (md5($new_content) !== $original_hash || $current_status !== $new_status) {
            // Prevent our save hook(s) from treating this as a manual edit.
            $_POST['rts_refiner_bypass'] = true;

            $res = wp_update_post([
                'ID'           => $post_id,
                'post_content' => $new_content,
                'post_status'  => $new_status,
            ], true);

            unset($_POST['rts_refiner_bypass']);

            if (is_wp_error($res)) {
                return ['success' => false, 'msg' => $res->get_error_message()];
            }

            update_post_meta($post_id, self::META_PROCESSED, time());

            // Snapshot for Learning Engine (bot version)
            update_post_meta($post_id, self::META_SNAPSHOT, $new_content);

            return ['success' => true, 'msg' => 'Refined & moved to Pending'];
        }

        return ['success' => true, 'msg' => 'No changes'];
    }

    /**
     * Revert to the latest revision, and clear refine metadata.
     */
    public static function revert(int $post_id): bool {
        $revisions = wp_get_post_revisions($post_id, ['posts_per_page' => 1]);
        if (!empty($revisions)) {
            $latest = array_shift($revisions);

            delete_post_meta($post_id, self::META_SNAPSHOT);
            delete_post_meta($post_id, self::META_PROCESSED);

            wp_restore_post_revision($latest->ID);
            return true;
        }
        return false;
    }

    // ---------------------------------------------------------------------
    // Pipelines
    // ---------------------------------------------------------------------

    private static function pipeline_html_cleanup(string $c): string {
        $allow = class_exists('RTS_Learning_Cache') ? (array) RTS_Learning_Cache::get_patterns('allow_html_style') : [];
        if (!empty($allow)) {
            return $c;
        }

        // Strict WCAG cleanup: strip inline styles and deprecated font tags.
        $c = preg_replace('/\s*style\s*=\s*["\'][^"\']*["\']/iu', '', $c);
        $c = preg_replace('/<(\/?)font[^>]*>/iu', '', $c);

        // Remove empty spans/divs
        $c = preg_replace('/<(span|div)[^>]*>\s*<\/\1>/iu', '', $c);

        return trim((string) $c);
    }

    private static function pipeline_heuristics(string $c): string {
        $ignore_caps  = class_exists('RTS_Learning_Cache') ? (array) RTS_Learning_Cache::get_patterns('ignore_cap') : [];
        $proper_nouns = class_exists('RTS_Learning_Cache') ? (array) RTS_Learning_Cache::get_patterns('proper_noun') : [];
        $ignore_space_before_punct = class_exists('RTS_Learning_Cache') ? (array) RTS_Learning_Cache::get_patterns('ignore_punct_space_before_punct') : [];
        $ignore_missing_space_after = class_exists('RTS_Learning_Cache') ? (array) RTS_Learning_Cache::get_patterns('ignore_punct_missing_space_after_punct') : [];

        // Normalize spacing
        $c = preg_replace('/\h+/u', ' ', $c);

        // Remove space(s) before punctuation, unless learned as an allowed style.
        $c = preg_replace_callback('/\s+([.,!?;:])/u', function ($m) use ($ignore_space_before_punct) {
            // $m[0] includes the whitespace + punctuation, e.g. " ,"
            if (!empty($ignore_space_before_punct) && in_array($m[0], $ignore_space_before_punct, true)) {
                return $m[0];
            }
            return $m[1];
        }, (string) $c);

        // Ensure space after punctuation, unless learned as an allowed style.
        $c = preg_replace_callback('/([.,!?;:])([^\s\d])/u', function ($m) use ($ignore_missing_space_after) {
            // $m[0] is the full match, e.g. ".W"
            if (!empty($ignore_missing_space_after) && in_array($m[0], $ignore_missing_space_after, true)) {
                return $m[0];
            }
            return $m[1] . ' ' . $m[2];
        }, (string) $c);

        // Adaptive capitalization at sentence starts
        $c = preg_replace_callback('/(?:^|[\.\!\?]\s+)(\p{L}+)/u', function ($m) use ($ignore_caps, $proper_nouns) {
            $w = $m[1];

            foreach ($ignore_caps as $ex) {
                if (mb_strtolower($w) === mb_strtolower($ex)) return $w;
            }
            foreach ($proper_nouns as $pn) {
                if (mb_strtolower($w) === mb_strtolower($pn)) return $pn;
            }

            return mb_strtoupper(mb_substr($w, 0, 1)) . mb_substr($w, 1);
        }, $c);

        return (string) $c;
    }

    private static function pipeline_structure(string $c): string {
        // "Dear stranger" greeting logic:
        // Check first 100 chars with strict word boundary (\b) to avoid matching Today/Tomorrow.
        $check = mb_strtolower(substr(wp_strip_all_tags($c), 0, 100));
        if (!preg_match('/^\s*(dear|dearest|hi|hello|hey|greetings|salutations|to)\b/iu', $check)) {
            $c = "Dear stranger,\n\n" . $c;
        }

        return wpautop($c);
    }
}
