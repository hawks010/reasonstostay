<?php
/**
 * RTS Workflow Admin
 * - Workflow dashboard strip + persistent filters
 * - Backfill tool (Action Scheduler batched)
 * - Review Console (split view)
 */

if (!defined('ABSPATH')) exit;

final class RTS_Workflow_Admin {

    const BACKFILL_LOCK = 'rts_workflow_backfill_lock';
    const BACKFILL_HOOK = 'rts_workflow_backfill_batch';

    public static function init(): void {
        add_action('admin_menu', [__CLASS__, 'menus']);
        add_action('admin_notices', [__CLASS__, 'notices']);

        // Stage filter: ?rts_stage=pending_review
        add_action('pre_get_posts', [__CLASS__, 'apply_stage_filter']);

        // Workflow strip for the Letters list.
        // We render this as a normal admin notice block (not inside the tablenav),
        // because injecting custom UI into the tablenav causes overlap and
        // responsiveness issues on smaller viewports.
        add_action('admin_notices', [__CLASS__, 'render_letters_list_strip']);

        // Persistent stage views (WP list table tabs).
        add_filter('views_edit-letter', [__CLASS__, 'views']);

        // Backfill actions.
        add_action('admin_post_rts_start_workflow_backfill', [__CLASS__, 'start_backfill']);
        add_action(self::BACKFILL_HOOK, [__CLASS__, 'backfill_batch']);

        // Review console save.
        add_action('admin_post_rts_review_console_save', [__CLASS__, 'review_console_save']);
    }

    /**
     * Render the workflow strip on the Letters list screen.
     */
    public static function render_letters_list_strip(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return;

        // Only on the Letters list.
        if ($screen->base !== 'edit' || $screen->post_type !== 'letter') return;

        echo self::get_workflow_strip_html();
    }

    /**
     * Return the workflow strip HTML for the Letters list screen.
     *
     * NOTE: This must return a string (not echo) because it is used from
     * admin_notices where output ordering matters.
     */
    private static function get_workflow_strip_html(): string {
        if (!current_user_can('edit_others_posts')) return '';
        if (!function_exists('rts_get_workflow_badge_config')) return '';

        $cfg   = rts_get_workflow_badge_config();
        $order = ['pending_review','flagged_draft','ingested','approved_published','skipped_published'];

        ob_start();

        // Clear floats so we never overlap the list table controls.
        echo '<div class="rts-card rts-workflow-strip" style="clear:both; width:100%;">';
        echo '<div class="rts-workflow-strip__row">';
        echo '<strong class="rts-workflow-strip__title">Workflow</strong>';

        foreach ($order as $stage) {
            $count = class_exists('RTS_Workflow') ? RTS_Workflow::count_by_stage($stage) : 0;
            $badge = $cfg[$stage] ?? [
                'label' => ucwords(str_replace('_',' ', $stage)),
                'color' => 'gray',
                'icon'  => 'dashicons-marker',
            ];

            $url = add_query_arg(
                ['post_type' => 'letter', 'rts_stage' => $stage],
                admin_url('edit.php')
            );

            echo '<a href="' . esc_url($url) . '" class="rts-workflow-strip__link">';
            echo '<span class="rts-badge rts-badge-' . esc_attr($badge['color']) . '">';
            echo '<span class="dashicons ' . esc_attr($badge['icon']) . '"></span> ';
            echo esc_html($badge['label']) . ' (' . (int) $count . ')';
            echo '</span></a>';
        }

        // Backfill status indicator.
        if (get_transient(self::BACKFILL_LOCK)) {
            echo '<span class="rts-workflow-strip__status">Backfill running…</span>';
        }

        echo '</div></div>';

        return (string) ob_get_clean();
    }

    public static function menus(): void {
        add_submenu_page(
            'edit.php?post_type=letter',
            'Workflow Tools',
            'Workflow Tools',
            'manage_options',
            'rts-workflow-tools',
            [__CLASS__, 'page_tools']
        );

        add_submenu_page(
            'edit.php?post_type=letter',
            'Review Console',
            'Review Console',
            'edit_others_posts',
            'rts-review-console',
            [__CLASS__, 'page_review_console']
        );
    }

    public static function notices(): void {
        if (empty($_GET['rts_notice'])) return;
        $k = sanitize_key((string) $_GET['rts_notice']);
        $map = [
            'backfill_started' => '✅ Workflow backfill started. It will process in the background.',
            'backfill_running' => '⏳ Workflow backfill is already running.',
            'backfill_done'    => '🎉 Workflow backfill completed.',
            'saved'            => '✅ Letter saved.',
            'nonce_failed'     => '⚠️ That backfill link expired. Please refresh the dashboard and try again.',
        ];
        if (!isset($map[$k])) return;
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($map[$k]) . '</p></div>';
    }

    public static function apply_stage_filter($query): void {
        if (!is_admin() || !$query->is_main_query()) return;
        if ($query->get('post_type') !== 'letter') return;

        $stage = isset($_GET['rts_stage']) ? sanitize_key((string) $_GET['rts_stage']) : '';
        if (!$stage) return;

        $mq = (array) $query->get('meta_query');
        $mq[] = [
            'key'   => RTS_Workflow::META_STAGE,
            'value' => $stage,
        ];
        $query->set('meta_query', $mq);

        // Helpful: align the WP status bucket if the stage implies one.
        if ($stage === 'pending_review') {
            $query->set('post_status', 'pending');
        } elseif ($stage === 'flagged_draft' || $stage === 'ingested') {
            $query->set('post_status', ['draft','pending','publish']);
        }
    }

    public static function views(array $views): array {
        if (!class_exists('RTS_Workflow')) return $views;

        $stages = ['pending_review','flagged_draft','ingested','approved_published','skipped_published'];
        $cfg = function_exists('rts_get_workflow_badge_config') ? rts_get_workflow_badge_config() : [];

        foreach ($stages as $stage) {
            $count = RTS_Workflow::count_by_stage($stage);
            $label = isset($cfg[$stage]['label']) ? $cfg[$stage]['label'] : ucwords(str_replace('_',' ', $stage));
            $url = add_query_arg(['post_type' => 'letter', 'rts_stage' => $stage], admin_url('edit.php'));
            $current = (isset($_GET['rts_stage']) && sanitize_key((string) $_GET['rts_stage']) === $stage);
            $views['rts_stage_' . $stage] = sprintf(
                '<a href="%s"%s>%s <span class="count">(%d)</span></a>',
                esc_url($url),
                $current ? ' class="current"' : '',
                esc_html($label),
                (int) $count
            );
        }

        return $views;
    }

    public static function render_dashboard_strip(string $which): void {
        if ($which !== 'top') return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-letter') return;
        if (!current_user_can('edit_others_posts')) return;

        if (!function_exists('rts_get_workflow_badge_config')) return;

        $cfg = rts_get_workflow_badge_config();
        $order = ['pending_review','flagged_draft','ingested','approved_published','skipped_published'];

		// WP's list table "tablenav" area uses floats for filters/actions.
		// If we don't clear floats, the strip can overlap the filters/table header on smaller screens.
		echo '<div class="rts-card rts-workflow-strip" style="clear:both; width:100%;">';
		echo '<div class="rts-workflow-strip__row">';
		echo '<strong class="rts-workflow-strip__title">Workflow</strong>';

        foreach ($order as $stage) {
            $count = class_exists('RTS_Workflow') ? RTS_Workflow::count_by_stage($stage) : 0;
            $badge = $cfg[$stage] ?? ['label' => ucwords(str_replace('_',' ', $stage)), 'color' => 'gray', 'icon' => 'dashicons-marker'];
            $url = add_query_arg(['post_type' => 'letter', 'rts_stage' => $stage], admin_url('edit.php'));

			echo '<a href="' . esc_url($url) . '" class="rts-workflow-strip__link">';
			echo '<span class="rts-badge rts-badge-' . esc_attr($badge['color']) . '">';
            echo '<span class="dashicons ' . esc_attr($badge['icon']) . '"></span> ';
            echo esc_html($badge['label']) . ' (' . (int) $count . ')';
            echo '</span></a>';
        }

        // Backfill status indicator.
        $lock = get_transient(self::BACKFILL_LOCK);
		if ($lock) {
			echo '<span class="rts-workflow-strip__status">Backfill running…</span>';
		}

		echo '</div></div>';
    }

    public static function page_tools(): void {
        if (!current_user_can('manage_options')) return;

        $missing = self::count_missing_stage();
        $lock = get_transient(self::BACKFILL_LOCK);

        echo '<div class="wrap">';
        echo '<h1>Workflow Tools</h1>';
        echo '<p>This is metadata-only. It does not change content. It stamps workflow stages on existing letters so the admin UI is accurate.</p>';

		echo '<div class="rts-card rts-max-820">';
        echo '<p><strong>Letters missing workflow stage:</strong> ' . (int) $missing . '</p>';

        if ($lock) {
            echo '<p><strong>Status:</strong> Backfill is currently running.</p>';
        } else {
            // Use WordPress' conventional nonce param to avoid triggering core's "link expired" flow.
            $url = wp_nonce_url(
                add_query_arg(
                    ['action' => 'rts_start_workflow_backfill'],
                    admin_url('admin-post.php')
                ),
                'rts_start_workflow_backfill'
            );
            echo '<p><a class="button button-primary" href="' . esc_url($url) . '">Start Backfill</a></p>';
        }

		echo '<p class="description">Recommended: run this on staging first, then production. It runs in batches via Action Scheduler.</p>';
        echo '</div>';

        echo '</div>';
    }

    public static function start_backfill(): void {
        if (!current_user_can('manage_options')) wp_die('No permission');
                $nonce = isset($_REQUEST['rts_nonce']) ? sanitize_text_field(wp_unslash($_REQUEST['rts_nonce'])) : (isset($_REQUEST['_wpnonce']) ? sanitize_text_field(wp_unslash($_REQUEST['_wpnonce'])) : '');
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'rts_start_workflow_backfill' ) ) {
            // Nonce failed (often from cached admin HTML). Redirect back with a notice instead of dying.
            wp_safe_redirect( add_query_arg( ['rts_notice' => 'nonce_failed'], admin_url( 'edit.php?post_type=letter&page=rts-dashboard' ) ) );
            exit;
        }

        if (get_transient(self::BACKFILL_LOCK)) {
            wp_safe_redirect(add_query_arg(['post_type' => 'letter', 'page' => 'rts-workflow-tools', 'rts_notice' => 'backfill_running'], admin_url('edit.php')));
            exit;
        }

        if (!function_exists('as_schedule_single_action')) {
            wp_die('Action Scheduler is required for backfill.');
        }

        set_transient(self::BACKFILL_LOCK, [
            'user' => get_current_user_id(),
            'started' => current_time('mysql', true),
        ], HOUR_IN_SECONDS);

        if (!as_next_scheduled_action(self::BACKFILL_HOOK, [], 'rts')) {
            as_schedule_single_action(time() + 5, self::BACKFILL_HOOK, [], 'rts');
        }

        wp_safe_redirect(add_query_arg(['post_type' => 'letter', 'page' => 'rts-workflow-tools', 'rts_notice' => 'backfill_started'], admin_url('edit.php')));
        exit;
    }

    public static function backfill_batch(): void {
        if (!class_exists('RTS_Workflow')) return;
        if (!function_exists('as_schedule_single_action')) return;

        // Process in small chunks.
        $batch = 200;

        $q = new WP_Query([
            'post_type'      => 'letter',
            'post_status'    => ['publish','pending','draft','future','private'],
            'posts_per_page' => $batch,
            'fields'         => 'ids',
            'meta_query'     => [[
                'key'     => RTS_Workflow::META_STAGE,
                'compare' => 'NOT EXISTS',
            ]],
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ]);

        if (empty($q->posts)) {
            delete_transient(self::BACKFILL_LOCK);
            return;
        }

        foreach ($q->posts as $post_id) {
            $post_id = absint($post_id);
            if (!$post_id) continue;
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'letter') continue;

            $stage = RTS_Workflow::infer_stage_from_post($post_id, $post);
            RTS_Workflow::set_stage($post_id, $stage, 'backfill', false);
            clean_post_cache($post_id);
        }

        // Reschedule immediately until done.
        as_schedule_single_action(time() + 5, self::BACKFILL_HOOK, [], 'rts');
    }

    private static function count_missing_stage(): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(p.ID) FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm
               ON pm.post_id = p.ID AND pm.meta_key = %s
             WHERE p.post_type = %s AND pm.post_id IS NULL",
            RTS_Workflow::META_STAGE,
            'letter'
        ));
    }

    public static function page_review_console(): void {
        if (!current_user_can('edit_others_posts')) return;

        $stage = isset($_GET['stage']) ? sanitize_key((string) $_GET['stage']) : 'pending_review';
        if (!in_array($stage, ['pending_review','flagged_draft','ingested'], true)) {
            $stage = 'pending_review';
        }

        $selected = isset($_GET['letter_id']) ? absint($_GET['letter_id']) : 0;

        // Fetch a stable page of IDs for navigation.
        $ids = get_posts([
            'post_type'      => 'letter',
            'post_status'    => ['pending','draft','publish'],
            'posts_per_page' => 50,
            'fields'         => 'ids',
            'meta_key'       => RTS_Workflow::META_STAGE,
            'meta_value'     => $stage,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        if (!$selected && !empty($ids)) {
            $selected = (int) $ids[0];
        }

        $idx = array_search($selected, $ids, true);
        $prev = ($idx !== false && $idx > 0) ? (int) $ids[$idx - 1] : 0;
        $next = ($idx !== false && $idx < (count($ids) - 1)) ? (int) $ids[$idx + 1] : 0;

        $post = $selected ? get_post($selected) : null;

        echo '<div class="wrap rts-review-console">';
        echo '<h1>Review Console</h1>';

        echo '<div class="rts-review-split">';

		// Left list
			echo '<div class="rts-review-list">';
		echo '<div class="rts-review-toolbar">';
		echo '<strong>Queue</strong>';
		echo '<span class="rts-review-pill">' . esc_html($stage) . '</span>';
		echo '</div>';

		if (empty($ids)) {
			echo '<div class="rts-review-empty"><p class="description">No letters found.</p></div>';
		} else {
			echo '<ul class="rts-review-items">';
            foreach ($ids as $id) {
                $is_current = ((int) $id === (int) $selected);
                $t = get_the_title($id);
                $url = add_query_arg(['post_type' => 'letter', 'page' => 'rts-review-console', 'stage' => $stage, 'letter_id' => $id], admin_url('edit.php'));
				echo '<li class="rts-review-item-wrap">';
				echo '<a href="' . esc_url($url) . '" class="rts-review-item' . ($is_current ? ' is-current' : '') . '">';
				echo '<div class="rts-review-title">' . esc_html($t ?: ('Letter #' . $id)) . '</div>';
					echo '<div class="rts-review-item-meta">ID ' . (int) $id . '</div>';
                echo '</a>';
                echo '</li>';
            }
            echo '</ul>';
        }

        echo '</div>';

		// Right editor pane
		echo '<div class="rts-review-editor">';

		if (!$post) {
			echo '<div class="rts-review-empty"><p>Select a letter to review.</p></div>';
        } else {
			echo '<div class="rts-review-header">';
            echo '<div><strong>Editing:</strong> ' . esc_html(get_the_title($post) ?: ('Letter #' . $post->ID)) . '</div>';
			echo '<div class="rts-review-actions">';

            if ($prev) {
                $prev_url = add_query_arg(['post_type' => 'letter', 'page' => 'rts-review-console', 'stage' => $stage, 'letter_id' => $prev], admin_url('edit.php'));
                echo '<a class="button" href="' . esc_url($prev_url) . '">← Prev</a>';
            }
            if ($next) {
                $next_url = add_query_arg(['post_type' => 'letter', 'page' => 'rts-review-console', 'stage' => $stage, 'letter_id' => $next], admin_url('edit.php'));
                echo '<a class="button" href="' . esc_url($next_url) . '">Next →</a>';
            }

            $edit_url = get_edit_post_link($post->ID, '');
            echo '<a class="button" href="' . esc_url($edit_url) . '">Open Full Editor</a>';
            echo '</div></div><hr>';

            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="rts_review_console_save">';
            echo '<input type="hidden" name="letter_id" value="' . (int) $post->ID . '">';
            echo '<input type="hidden" name="stage" value="' . esc_attr($stage) . '">';
            wp_nonce_field('rts_review_console_save_' . (int) $post->ID, 'rts_nonce');

            echo '<p><label><strong>Title</strong></label><br>';
	            echo '<input type="text" name="letter_title" value="' . esc_attr(get_the_title($post)) . '" class="regular-text" style="width:100%; max-width:900px;">';
            echo '</p>';

            echo '<p><label><strong>Content</strong></label></p>';
            wp_editor($post->post_content, 'letter_content', [
                'textarea_name' => 'letter_content',
                'media_buttons' => false,
                'teeny'         => true,
                'textarea_rows' => 18,
            ]);

	            echo '<p style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">';
            echo '<button class="button" name="save_mode" value="pending">Save Pending</button>';
            echo '<button class="button" name="save_mode" value="draft">Save Draft</button>';
            echo '<button class="button button-primary" name="save_mode" value="publish">Publish</button>';
            echo '</p>';

            echo '</form>';
        }

        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    public static function review_console_save(): void {
        $post_id = isset($_POST['letter_id']) ? absint($_POST['letter_id']) : 0;
        if (!$post_id || get_post_type($post_id) !== 'letter') wp_die('Invalid letter.');

        if (!current_user_can('edit_post', $post_id)) wp_die('No permission.');

        $nonce = isset($_POST['rts_nonce']) ? sanitize_text_field((string) $_POST['rts_nonce']) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'rts_review_console_save_' . $post_id)) {
            wp_die('Invalid nonce.');
        }

        $title = isset($_POST['letter_title']) ? sanitize_text_field((string) wp_unslash($_POST['letter_title'])) : '';
        $content = isset($_POST['letter_content']) ? (string) wp_unslash($_POST['letter_content']) : '';
        $mode = isset($_POST['save_mode']) ? sanitize_key((string) $_POST['save_mode']) : 'pending';

        $status = 'pending';
        if ($mode === 'draft') $status = 'draft';
        if ($mode === 'publish') $status = 'publish';

        wp_update_post([
            'ID'           => $post_id,
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $status,
        ]);

        if (class_exists('RTS_Workflow')) {
            // Keep stage in sync with the reviewer action.
            if ($status === 'publish') {
                RTS_Workflow::set_stage($post_id, 'approved_published', 'review_console:publish', false);
            } elseif ($status === 'draft') {
                RTS_Workflow::set_stage($post_id, RTS_Workflow::is_flagged($post_id) ? 'flagged_draft' : 'ingested', 'review_console:draft', false);
            } else {
                RTS_Workflow::set_stage($post_id, 'pending_review', 'review_console:pending', false);
            }
        }

        $stage = isset($_POST['stage']) ? sanitize_key((string) $_POST['stage']) : 'pending_review';
        $url = add_query_arg(['post_type' => 'letter', 'page' => 'rts-review-console', 'stage' => $stage, 'letter_id' => $post_id, 'rts_notice' => 'saved'], admin_url('edit.php'));
        wp_safe_redirect($url);
        exit;
    }
}

add_action('init', ['RTS_Workflow_Admin', 'init'], 20);
