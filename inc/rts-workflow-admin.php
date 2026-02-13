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
        add_action('admin_menu', [__CLASS__, 'curate_letters_menu'], 999);
        add_action('admin_notices', [__CLASS__, 'notices']);
        add_action('admin_notices', [__CLASS__, 'render_universal_stats_strip'], 4);

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
        add_action('admin_post_rts_terminate_stage_queue', [__CLASS__, 'terminate_stage_queue']);
        add_action(self::BACKFILL_HOOK, [__CLASS__, 'backfill_batch']);

        // Review console save.
        add_action('admin_post_rts_review_console_save', [__CLASS__, 'review_console_save']);

        // Rescan stuck processing (stale lock reset).
        add_action('admin_post_rts_rescan_stuck_processing', [__CLASS__, 'rescan_stuck_processing']);
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
        echo '<script>(function(){';
        echo 'var strip=document.getElementById("rts-workflow-strip-main");';
        echo 'if(!strip){return;}';
        echo 'function placeWorkflowStrip(){';
        echo 'var wrap=document.querySelector("#wpbody-content .wrap");';
        echo 'if(!wrap){return false;}';
        echo 'var commandCenter=wrap.querySelector("#rts-letter-command-center");';
        echo 'if(commandCenter&&commandCenter.parentNode){commandCenter.insertAdjacentElement("afterend",strip);return true;}';
        echo 'var titleAnchor=wrap.querySelector(".wp-header-end")||wrap.querySelector("h1.wp-heading-inline")||wrap.querySelector("h1")||wrap.querySelector("h2");';
        echo 'if(titleAnchor&&titleAnchor.parentNode){titleAnchor.insertAdjacentElement("afterend",strip);return true;}';
        echo 'return false;';
        echo '}';
        echo 'function queuePlacement(){';
        echo 'var attempts=0;';
        echo 'if(placeWorkflowStrip()){return;}';
        echo 'var timer=setInterval(function(){';
        echo 'attempts++;';
        echo 'if(placeWorkflowStrip()||attempts>=18){clearInterval(timer);}';
        echo '},120);';
        echo '}';
        echo 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",queuePlacement);}else{queuePlacement();}';
        echo 'window.addEventListener("load",queuePlacement);';
        echo '})();</script>';
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
        $order = ['unprocessed','processing','pending_review','quarantined','published','archived'];

        ob_start();

        // Clear floats so we never overlap the list table controls.
        echo '<div id="rts-workflow-strip-main" class="rts-card rts-workflow-strip" style="clear:both; width:100%;">';
        echo '<div class="rts-workflow-strip__row">';
        echo '<strong class="rts-workflow-strip__title">Workflow</strong>';

        foreach ($order as $stage) {
            $count = self::get_stage_total_count($stage);
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
            'Operations Hub',
            'Operations Hub',
            'manage_options',
            'rts-workflow-tools',
            [__CLASS__, 'page_tools']
        );

        // Keep legacy review console route registered for direct URLs.
        // We intentionally hide it from the left menu in curate_letters_menu().
        add_submenu_page(
            'edit.php?post_type=letter',
            'Manual Review',
            'Manual Review',
            'edit_others_posts',
            'rts-review-console',
            [__CLASS__, 'page_review_console']
        );
    }

    /**
     * Curate and relabel the Letters submenu so the IA stays friendly.
     * We keep advanced pages accessible by URL, but remove them from sidebar clutter.
     */
    public static function curate_letters_menu(): void {
        if (!is_admin()) return;
        global $submenu;
        $parent = 'edit.php?post_type=letter';
        if (empty($submenu[$parent]) || !is_array($submenu[$parent])) return;

        $items = $submenu[$parent];
        $rename = [
            'rts-dashboard' => 'Letters Dashboard',
            'edit.php?post_type=letter' => 'Letters',
            'post-new.php?post_type=letter' => 'Create Letter',
            'edit-tags.php?taxonomy=letter_feeling&post_type=letter' => 'Feelings',
            'edit-tags.php?taxonomy=letter_tone&post_type=letter' => 'Tone',
            'edit.php?post_type=rts_feedback' => 'Letter Feedback',
            'rts-workflow-tools' => 'Operations Hub',
        ];
        $hidden = [
            'rts-review-console',
            'rts_review_console',
            'rts_patterns',
            'rts-security-logs',
            'rts-subscribers-dashboard',
        ];

        $by_slug = [];
        foreach ($items as $item) {
            $slug = $item[2] ?? '';
            if ($slug === '') continue;
            if (isset($rename[$slug])) {
                $item[0] = $rename[$slug];
            }
            $by_slug[$slug] = $item;
        }

        $order = [
            'rts-dashboard',
            'edit.php?post_type=letter',
            'post-new.php?post_type=letter',
            'rts-workflow-tools',
            'edit.php?post_type=rts_feedback',
            'edit-tags.php?taxonomy=letter_feeling&post_type=letter',
            'edit-tags.php?taxonomy=letter_tone&post_type=letter',
        ];

        $new = [];
        foreach ($order as $slug) {
            if (isset($by_slug[$slug]) && !in_array($slug, $hidden, true)) {
                $new[] = $by_slug[$slug];
                unset($by_slug[$slug]);
            }
        }

        // Preserve any unrecognized items that were not explicitly hidden.
        foreach ($items as $item) {
            $slug = $item[2] ?? '';
            if ($slug === '' || in_array($slug, $hidden, true)) {
                continue;
            }
            if (!isset($by_slug[$slug])) {
                continue;
            }
            $new[] = $by_slug[$slug];
            unset($by_slug[$slug]);
        }

        $submenu[$parent] = $new;
    }

    /**
     * Unified command center for all letter admin endpoints.
     */
    public static function render_universal_stats_strip(): void {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return;

        // Limit to list/settings/taxonomy/dashboard shells, not individual post editors.
        if (in_array($screen->base, ['post', 'post-new'], true)) return;
        // Do not render this on taxonomy management screens (Feelings/Tone).
        if (in_array($screen->base, ['edit-tags', 'term'], true)) return;
        if (!empty($_GET['taxonomy'])) return;

        // The Letters Dashboard renders this in-page to keep placement predictable.
        $current_page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($screen->id === 'letter_page_rts-dashboard' || $current_page === 'rts-dashboard') return;

        $is_letters_shell = (
            $screen->post_type === 'letter'
            || $screen->post_type === 'rts_feedback'
            || strpos((string) $screen->id, 'letter_page_') === 0
            || (isset($_GET['post_type']) && sanitize_key((string) $_GET['post_type']) === 'letter')
        );
        if (!$is_letters_shell) return;

        self::render_letter_command_center(['move_under_title' => true]);
    }

    /**
     * Render the reusable command center block.
     *
     * @param array<string,mixed> $args
     */
    public static function render_letter_command_center(array $args = []): void {
        $stats = self::get_universal_letter_stats();
        $state = self::get_processing_status_snapshot();
        $move_under_title = !empty($args['move_under_title']);
        $panel_id = 'rts-letter-command-center';
        $refresh_url = self::get_current_admin_url();

        echo '<section id="' . esc_attr($panel_id) . '" class="rts-card rts-letter-command-center" style="clear:both;">';
        echo '<div class="rts-letter-command-center__header">';
        echo '<div class="rts-letter-command-center__title-wrap">';
        echo '<h2 class="rts-letter-command-center__title">Letter Dashboard</h2>';
        echo '<p class="rts-letter-command-center__subtitle">Workflow snapshot and quick actions for letter operations.</p>';
        echo '</div>';
        echo '<div class="rts-letter-command-center__header-actions">';
        echo '<span class="rts-letter-command-center__top-status rts-letter-command-center__top-status-' . esc_attr(strtolower($state['system'])) . '">';
        echo '<span class="dashicons dashicons-yes-alt"></span> System ' . esc_html($state['system']);
        echo '</span>';
        echo '<a class="button rts-letter-command-center__help-btn" href="' . esc_url(admin_url('admin.php?page=rts-site-manual')) . '">Help</a>';
        echo '</div>';
        echo '</div>';
        echo '<div class="rts-letter-command-center__grid">';
        echo '<div class="rts-letter-command-center__metrics">';

        $items = [
            [
                'label' => 'Unprocessed',
                'value' => $stats['queued'],
                'url'   => admin_url('edit.php?post_type=letter&rts_stage=unprocessed'),
            ],
            [
                'label' => 'Pending Review',
                'value' => $stats['pending_review'],
                'url'   => admin_url('edit.php?post_type=letter&rts_stage=pending_review'),
            ],
            [
                'label' => 'Quarantined',
                'value' => $stats['quarantined'],
                'url'   => admin_url('edit.php?post_type=letter&rts_stage=quarantined'),
            ],
            [
                'label' => 'Published',
                'value' => $stats['published'],
                'url'   => admin_url('edit.php?post_type=letter&rts_stage=published'),
            ],
        ];

        foreach ($items as $item) {
            echo '<a class="rts-letter-command-center__metric" href="' . esc_url($item['url']) . '">';
            echo '<span class="rts-letter-command-center__metric-label">' . esc_html((string) $item['label']) . '</span>';
            echo '<span class="rts-letter-command-center__metric-value">' . number_format_i18n((int) $item['value']) . '</span>';
            echo '</a>';
        }

        echo '</div>';
        echo '<aside class="rts-letter-command-center__status">';
        echo '<div class="rts-letter-command-center__status-head">';
        echo '<h3>Live Processing Status</h3>';
        echo '<span id="rts-scan-status" class="rts-letter-command-center__status-pill ' . esc_attr($state['scan_class']) . '">' . esc_html($state['scan_label']) . '</span>';
        echo '</div>';
        echo '<div class="rts-letter-command-center__status-list">';
        echo '<div class="rts-letter-command-center__status-item"><span>System</span><strong>' . esc_html($state['system']) . '</strong></div>';
        echo '<div class="rts-letter-command-center__status-item"><span>Background</span><strong>' . esc_html($state['background']) . '</strong></div>';
        echo '<div class="rts-letter-command-center__status-item"><span>Auto-scan</span><strong>' . esc_html($state['schedule']) . '</strong></div>';
        echo '<div class="rts-letter-command-center__status-item"><span>Active Scan</span><strong id="rts-active-scan">' . esc_html($state['active_scan']) . '</strong></div>';
        echo '<div class="rts-letter-command-center__status-item"><span>Unprocessed Letters</span><strong id="rts-queued-count">' . number_format_i18n((int) $stats['queued']) . '</strong></div>';
        echo '</div>';
        echo '</aside>';
        echo '</div>';
        echo '<div class="rts-letter-command-center__actions">';
        echo '<a class="button button-primary rts-action-btn" href="' . esc_url(admin_url('edit.php?post_type=letter&rts_stage=pending_review')) . '"><span class="dashicons dashicons-list-view"></span> Open Pending Review</a>';
        echo '<a class="button rts-action-btn" href="' . esc_url(admin_url('edit.php?post_type=letter&rts_stage=quarantined')) . '"><span class="dashicons dashicons-shield"></span> View Quarantined</a>';
        echo '<button type="button" class="button rts-action-btn rts-scan-btn rts-command-scan-btn" id="rts-scan-inbox-btn" data-fallback-url="' . esc_url(admin_url('edit.php?post_type=letter&page=rts-dashboard')) . '"><span class="dashicons dashicons-search"></span> <span class="btn-text">Scan Unprocessed</span><span class="spinner" style="float:none; margin:0 0 0 5px; display:none;"></span></button>';
        echo '<button type="button" class="button rts-action-btn rts-scan-btn rts-command-scan-btn" id="rts-rescan-quarantine-btn" data-fallback-url="' . esc_url(admin_url('edit.php?post_type=letter&page=rts-dashboard')) . '"><span class="dashicons dashicons-update"></span> <span class="btn-text">Recheck Quarantined</span><span class="spinner" style="float:none; margin:0 0 0 5px; display:none;"></span></button>';
        echo '<a class="button rts-action-btn" id="rts-refresh-status-btn" href="' . esc_url($refresh_url) . '"><span class="dashicons dashicons-update"></span> Refresh Snapshot</a>';
        echo '</div>';
        echo '</section>';

        echo '<script>(function(){';
        if ($move_under_title) {
            echo 'var panel=document.getElementById("' . esc_js($panel_id) . '");';
            echo 'function moveUnderTitle(){';
            echo 'if(!panel) return true;';
            echo 'var wrap=document.querySelector("#wpbody-content .wrap");';
            echo 'if(!wrap || wrap.classList.contains("rts-dashboard")) return false;';
            echo 'var anchor=wrap.querySelector(".wp-header-end")||wrap.querySelector("h1.wp-heading-inline")||wrap.querySelector("h1")||wrap.querySelector("h2");';
            echo 'if(!anchor||!anchor.parentNode){return false;}';
            echo 'anchor.insertAdjacentElement("afterend", panel);';
            echo 'return true;';
            echo '}';
            echo 'function queueMove(){';
            echo 'var attempts=0;';
            echo 'if(moveUnderTitle()) return;';
            echo 'var timer=setInterval(function(){';
            echo 'attempts++;';
            echo 'if(moveUnderTitle()||attempts>=14){clearInterval(timer);}';
            echo '},120);';
            echo '}';
            echo 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded", queueMove);}else{queueMove();}';
            echo 'window.addEventListener("load", queueMove);';
        }
        echo 'document.addEventListener("click",function(e){';
        echo 'var btn=e.target.closest(".rts-command-scan-btn");';
        echo 'if(!btn) return;';
        echo 'if(window.rtsDashboard && typeof window.ajaxurl!=="undefined") return;';
        echo 'var fallback=btn.getAttribute("data-fallback-url");';
        echo 'if(fallback){ window.location.href=fallback; }';
        echo '});';
        echo '})();</script>';
    }

    /**
     * Shared counts used by the command center.
     *
     * @return array<string,int>
     */
    private static function get_post_status_counts_live(string $post_type): array {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_status, COUNT(1) AS cnt FROM {$wpdb->posts} WHERE post_type=%s GROUP BY post_status",
                $post_type
            ),
            ARRAY_A
        );

        $counts = [
            'publish' => 0,
            'pending' => 0,
            'draft'   => 0,
            'future'  => 0,
            'private' => 0,
        ];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $status = isset($row['post_status']) ? (string) $row['post_status'] : '';
                if ($status === '') {
                    continue;
                }
                $counts[$status] = (int) ($row['cnt'] ?? 0);
            }
        }

        return $counts;
    }

    private static function get_universal_letter_stats(): array {
        if (!class_exists('RTS_Workflow')) {
            return [
                'current' => 0,
                'queued' => 0,
                'pending_review' => 0,
                'quarantined' => 0,
                'published' => 0,
                'archived' => 0,
            ];
        }

        // Non‑negotiable: stats are derived ONLY from rts_workflow_stage.
        $queued        = (int) RTS_Workflow::count_by_stage(RTS_Workflow::STAGE_UNPROCESSED);
        $pending_review = (int) RTS_Workflow::count_by_stage(RTS_Workflow::STAGE_PENDING_REVIEW);
        $quarantined   = (int) RTS_Workflow::count_by_stage(RTS_Workflow::STAGE_QUARANTINED);
        $published     = (int) RTS_Workflow::count_by_stage(RTS_Workflow::STAGE_PUBLISHED);
        $archived      = (int) RTS_Workflow::count_by_stage(RTS_Workflow::STAGE_ARCHIVED);
        $processing    = (int) RTS_Workflow::count_by_stage(RTS_Workflow::STAGE_PROCESSING);

        return [
            'current'       => ($queued + $processing + $pending_review + $quarantined + $published + $archived),
            'queued'        => $queued,
            'pending_review'=> $pending_review,
            'quarantined'   => $quarantined,
            'published'     => $published,
            'archived'      => $archived,
        ];
    }

    /**
     * @return array<string,string>
     */
    private static function get_processing_status_snapshot(): array {
        $as_ok = function_exists('rts_as_available') ? rts_as_available() : false;
        $auto_enabled = (string) get_option('rts_auto_processing_enabled', '1') !== '0';
        $auto_interval = max(60, (int) get_option('rts_auto_processing_interval', 300));
        $auto_minutes = max(1, (int) round($auto_interval / 60));
        $scan = get_option('rts_active_scan', []);

        $active_scan = 'Idle (Ready)';
        $scan_label = 'Idle';
        $scan_class = 'is-idle';
        if (is_array($scan) && ($scan['status'] ?? '') === 'running') {
            $type = sanitize_key((string) ($scan['type'] ?? 'inbox'));
            $label = ($type === 'quarantine') ? 'Quarantined' : 'Unprocessed';
            $active_scan = 'Running: ' . $label;
            $scan_label = 'Processing';
            $scan_class = 'is-busy';
        }

        return [
            'system' => $as_ok ? 'Online' : 'Offline',
            'background' => $as_ok ? 'Enabled' : 'Disabled',
            'schedule' => $auto_enabled ? ('Every ' . $auto_minutes . ' min') : 'Manual only',
            'active_scan' => $active_scan,
            'scan_label' => $scan_label,
            'scan_class' => $scan_class,
        ];
    }

    private static function get_current_admin_url(): string {
        if (empty($_SERVER['REQUEST_URI'])) {
            return admin_url('edit.php?post_type=letter');
        }
        return esc_url_raw(wp_unslash((string) $_SERVER['REQUEST_URI']));
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
        if ($stage === '') return;

        if (!class_exists('RTS_Workflow')) return;
        if (!in_array($stage, RTS_Workflow::valid_stages(), true)) return;

        $mq = (array) $query->get('meta_query');
        $mq[] = [
            'key'   => RTS_Workflow::META_STAGE,
            'value' => $stage,
        ];
        $query->set('meta_query', $mq);

        // Never rely on post_status for workflow selection.
        $query->set('post_status', 'any');
    }

public static function views(array $views): array {
        if (!class_exists('RTS_Workflow')) return $views;

        // Remove WordPress post_status-based tabs (Drafts, Pending, Published, Mine, etc).
        // Non‑negotiable: admin navigation must reflect ONLY rts_workflow_stage.
        foreach (['publish','draft','pending','future','private','mine','sticky'] as $kill) {
            if (isset($views[$kill])) {
                unset($views[$kill]);
            }
        }

        // Keep 'all' and 'trash' if WordPress provided them.
        $kept = [];
        if (isset($views['all'])) $kept['all'] = $views['all'];
        if (isset($views['trash'])) $kept['trash'] = $views['trash'];

        $base = admin_url('edit.php?post_type=letter');
        $current = isset($_GET['rts_stage']) ? sanitize_key((string) $_GET['rts_stage']) : '';

        $stages = [
            RTS_Workflow::STAGE_UNPROCESSED    => 'Unprocessed',
            RTS_Workflow::STAGE_PROCESSING     => 'Processing',
            RTS_Workflow::STAGE_PENDING_REVIEW => 'Pending Review',
            RTS_Workflow::STAGE_QUARANTINED    => 'Quarantined',
            RTS_Workflow::STAGE_PUBLISHED      => 'Published',
            RTS_Workflow::STAGE_ARCHIVED       => 'Archived',
        ];

        foreach ($stages as $key => $label) {
            $count = RTS_Workflow::count_by_stage($key);
            $url = add_query_arg('rts_stage', $key, $base);
            $class = ($current === $key) ? 'current' : '';
            $kept[$key] = sprintf('<a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
                esc_url($url),
                esc_attr($class),
                esc_html($label),
                (int) $count
            );
        }

        return $kept;
    }


    public static function render_dashboard_strip(string $which): void {
        if ($which !== 'top') return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-letter') return;
        if (!current_user_can('edit_others_posts')) return;

        if (!function_exists('rts_get_workflow_badge_config')) return;

        $cfg = rts_get_workflow_badge_config();
        $order = ['unprocessed','processing','pending_review','quarantined','published','archived'];

		// WP's list table "tablenav" area uses floats for filters/actions.
		// If we don't clear floats, the strip can overlap the filters/table header on smaller screens.
		echo '<div class="rts-card rts-workflow-strip" style="clear:both; width:100%;">';
		echo '<div class="rts-workflow-strip__row">';
		echo '<strong class="rts-workflow-strip__title">Workflow</strong>';

        foreach ($order as $stage) {
            $count = self::get_stage_total_count($stage);
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
        $termination_notice = (isset($_GET['rts_notice']) && sanitize_key((string) $_GET['rts_notice']) === 'stage_queue_terminated');
        $terminated_stage = isset($_GET['stage']) ? sanitize_key((string) $_GET['stage']) : '';
        $terminated_jobs = isset($_GET['jobs']) ? absint($_GET['jobs']) : 0;
        $terminated_cleared = isset($_GET['cleared']) ? absint($_GET['cleared']) : 0;
        $terminated_scan_stopped = isset($_GET['scan_stopped']) ? absint($_GET['scan_stopped']) : 0;

        echo '<div class="wrap rts-mail-admin rts-ui-wrapper rts-ops-hub">';
        echo '<h1>Operations Hub</h1>';
        echo '<p>Use this page to run the full letter lifecycle: moderation queues, audience delivery, safety checks, and maintenance.</p>';

        if ($termination_notice) {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Queue terminated:</strong> ' . esc_html(self::stage_label($terminated_stage)) . '. ';
            echo esc_html(number_format_i18n((int) $terminated_jobs)) . ' job(s) unscheduled, ';
            echo esc_html(number_format_i18n((int) $terminated_cleared)) . ' queued stamp(s) cleared';
            if ($terminated_scan_stopped > 0) {
                echo ', active scan stopped';
            }
            echo '.</p>';
            echo '</div>';
        }

        echo '<section class="rts-card rts-mail-card rts-ops-callout">';
        echo '<h2>Quick Start</h2>';
        echo '<p class="rts-ops-hint">If you are not sure where to start, follow this order: review pending, check flagged, then handle delivery or maintenance.</p>';
        echo '<div class="rts-mail-chip-row">';
        echo '<a class="button button-primary" href="' . esc_url(admin_url('edit.php?post_type=letter&rts_stage=pending_review')) . '">Open Pending Review</a>';
        echo '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=letter&rts_stage=quarantined')) . '">View Quarantined</a>';
        echo '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=letter&page=rts-dashboard')) . '">Open Letters Dashboard</a>';
        echo '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=rts_subscriber&page=rts-subscribers-dashboard')) . '">Open Audience Dashboard</a>';
        echo '</div>';
        echo '</section>';

        self::render_stage_pipeline_monitor();

        echo '<div class="rts-mail-grid-2 rts-ops-grid">';
        echo '<section class="rts-card rts-mail-card rts-ops-card">';
        echo '<h2>Letter Workflow</h2>';
        echo '<p class="rts-ops-hint">Review, approve, and publish letters safely.</p>';
        echo '<ul class="rts-mail-list rts-ops-list">';
        echo '<li><a href="' . esc_url(admin_url('edit.php?post_type=letter&page=rts-dashboard')) . '">Letters Dashboard</a><span>Full moderation console and live status.</span></li>';
        echo '<li><a href="' . esc_url(admin_url('edit.php?post_type=letter&rts_stage=pending_review')) . '">Pending Review</a><span>Letters waiting on human publishing decisions.</span></li>';
        echo '<li><a href="' . esc_url(admin_url('edit.php?post_type=letter&rts_stage=quarantined')) . '">Quarantined</a><span>Letters held for safety or quality checks. Review the reason, then recheck manually.</span></li>';
        echo '<li><a href="' . esc_url(admin_url('edit.php?post_type=letter&page=rts-review-console')) . '">Manual Review</a><span>Split-view reviewer for rapid moderation.</span></li>';
        echo '</ul>';
        echo '</section>';

        echo '<section class="rts-card rts-mail-card rts-ops-card">';
        echo '<h2>Audience &amp; Email</h2>';
        echo '<p class="rts-ops-hint">Control subscriber targeting and delivery systems.</p>';
        echo '<ul class="rts-mail-list rts-ops-list">';
        echo '<li><a href="' . esc_url(admin_url('edit.php?post_type=rts_subscriber&page=rts-subscribers-dashboard')) . '">Audience &amp; Email Hub</a><span>Subscriber and engagement overview.</span></li>';
        echo '<li><a href="' . esc_url(admin_url('edit.php?post_type=rts_subscriber&page=rts-subscriber-mailing')) . '">Letter Mailing</a><span>Newsletter queue and send operations.</span></li>';
        echo '<li><a href="' . esc_url(admin_url('edit.php?post_type=rts_subscriber&page=rts-email-settings')) . '">Email Settings</a><span>SMTP, sender identity, and delivery preferences.</span></li>';
        echo '</ul>';
        echo '</section>';

        echo '<section class="rts-card rts-mail-card rts-ops-card">';
        echo '<h2>Safety &amp; Feedback</h2>';
        echo '<p class="rts-ops-hint">Watch user feedback and moderation signals.</p>';
        echo '<ul class="rts-mail-list rts-ops-list">';
        echo '<li><a href="' . esc_url(admin_url('edit.php?post_type=rts_feedback')) . '">Letter Feedback</a><span>Reader reactions, mood change, and triggered reports.</span></li>';
        echo '<li><a href="' . esc_url(admin_url('edit.php?post_type=letter&page=rts-security-logs')) . '">Safety Logs</a><span>Moderation events and anomaly tracking.</span></li>';
        echo '<li><a href="' . esc_url(admin_url('edit.php?post_type=letter&page=rts_patterns')) . '">Content Insights</a><span>Pattern signals from AI and moderation trends.</span></li>';
        echo '</ul>';
        echo '</section>';

        echo '<section class="rts-card rts-mail-card rts-ops-card">';
        echo '<h2>Workflow Maintenance</h2>';
        echo '<p class="rts-ops-hint">Repair workflow metadata and reconcile pipelines.</p>';
        echo '<p style="margin-top:0;"><strong>Letters missing workflow stage:</strong> ' . (int) $missing . '</p>';

        if ($lock) {
            echo '<p><strong>Status:</strong> Backfill is currently running.</p>';
        } else {
            $url = wp_nonce_url(
                add_query_arg(
                    ['action' => 'rts_start_workflow_backfill'],
                    admin_url('admin-post.php')
                ),
                'rts_start_workflow_backfill'
            );
            echo '<p><a class="button button-primary" href="' . esc_url($url) . '">Start Backfill</a></p>';
        }

        echo '<p class="description">Metadata-only backfill. It does not rewrite letter content.</p>';
        echo '</section>';
        echo '</div>';

        echo '</div>';
    }

    /**
     * Stage monitor board: one square card per major moderation stage.
     */
    private static function render_stage_pipeline_monitor(): void {
        $cards = self::get_stage_pipeline_cards();

        echo '<section class="rts-card rts-mail-card rts-ops-stage-board">';
        echo '<h2>Moderation Pipeline Monitor</h2>';
        echo '<p class="rts-ops-hint">Live queue visibility by stage. Use terminate only when a stage is stuck.</p>';
        echo '<div class="rts-ops-stage-grid">';

        foreach ($cards as $card) {
            $stage = sanitize_key((string) ($card['stage'] ?? ''));
            $title = (string) ($card['title'] ?? '');
            $open_url = (string) ($card['open_url'] ?? admin_url('edit.php?post_type=letter'));
            $total = (int) ($card['total'] ?? 0);
            $queued = (int) ($card['queued'] ?? 0);
            $active = !empty($card['active']);
            $queue_items = (array) ($card['queue_items'] ?? []);

            echo '<article class="rts-ops-stage-card">';
            echo '<div class="rts-ops-stage-card__head">';
            echo '<h3>' . esc_html($title) . '</h3>';
            echo '<span class="rts-ops-stage-pill ' . ($active ? 'is-active' : 'is-idle') . '">' . ($active ? 'Running' : 'Idle') . '</span>';
            echo '</div>';

            echo '<div class="rts-ops-stage-metrics">';
            echo '<div><span>Total</span><strong>' . number_format_i18n($total) . '</strong></div>';
            echo '<div><span>Queued</span><strong>' . number_format_i18n($queued) . '</strong></div>';
            echo '</div>';

            echo '<div class="rts-ops-stage-queue">';
            echo '<p class="rts-ops-stage-queue-title">Processing Queue</p>';
            if (empty($queue_items)) {
                echo '<p class="rts-ops-stage-empty">No queued letters in this stage.</p>';
            } else {
                echo '<ul class="rts-ops-stage-list">';
                foreach ($queue_items as $item) {
                    $item_title = (string) ($item['title'] ?? 'Untitled');
                    $item_id = (int) ($item['id'] ?? 0);
                    $item_age = (string) ($item['age'] ?? '');
                    echo '<li><span class="rts-ops-stage-item-title">' . esc_html($item_title) . '</span>';
                    echo '<span class="rts-ops-stage-item-meta">#' . esc_html((string) $item_id) . ' · queued ' . esc_html($item_age) . '</span></li>';
                }
                echo '</ul>';
            }
            echo '</div>';

            echo '<div class="rts-ops-stage-actions">';
            echo '<a class="button" href="' . esc_url($open_url) . '">Open Stage</a>';
            if ($stage !== '') {
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'Terminate this stage queue? This removes scheduled processing jobs for this stage.\');">';
                echo '<input type="hidden" name="action" value="rts_terminate_stage_queue">';
                echo '<input type="hidden" name="stage" value="' . esc_attr($stage) . '">';
                wp_nonce_field('rts_terminate_stage_queue');
                echo '<button type="submit" class="button button-secondary">Terminate Queue</button>';
                echo '</form>';
            }
            echo '</div>';
            echo '</article>';
        }

        echo '</div>';
        echo '</section>';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private static function get_stage_pipeline_cards(): array {
        $scan = get_option('rts_active_scan', []);
        $scan_type = is_array($scan) ? sanitize_key((string) ($scan['type'] ?? '')) : '';
        $scan_running = is_array($scan) && (($scan['status'] ?? '') === 'running');

        $stages = [
            [
                'stage' => 'unprocessed',
                'title' => 'Unprocessed',
                'open_url' => admin_url('edit.php?post_type=letter&rts_stage=unprocessed'),
                'scan_type' => 'inbox',
            ],
            [
                'stage' => 'processing',
                'title' => 'Processing',
                'open_url' => admin_url('edit.php?post_type=letter&rts_stage=processing'),
                'scan_type' => 'inbox',
            ],
            [

                'stage' => 'pending_review',
                'title' => 'Pending Review',
                'open_url' => admin_url('edit.php?post_type=letter&rts_stage=pending_review'),
                'scan_type' => '',
            ],
            [
                'stage' => 'quarantined',
                'title' => 'Quarantined',
                'open_url' => admin_url('edit.php?post_type=letter&rts_stage=quarantined'),
                'scan_type' => 'quarantine',
            ],
            [
                'stage' => 'published',
                'title' => 'Published',
                'open_url' => admin_url('edit.php?post_type=letter&post_status=publish'),
                'scan_type' => '',
            ],
        ];

        $cards = [];
        foreach ($stages as $def) {
            $stage = (string) $def['stage'];
            $queue = self::get_stage_queue_snapshot($stage, 5);
            $cards[] = [
                'stage' => $stage,
                'title' => (string) $def['title'],
                'open_url' => (string) $def['open_url'],
                'total' => self::get_stage_total_count($stage),
                'queued' => (int) ($queue['count'] ?? 0),
                'queue_items' => (array) ($queue['items'] ?? []),
                'active' => ($scan_running && $def['scan_type'] !== '' && $scan_type === $def['scan_type']),
            ];
        }

        return $cards;
    }

    private static function get_stage_total_count(string $stage): int {
        $stage = sanitize_key($stage);

        if (class_exists('RTS_Workflow')) {
            if (in_array($stage, RTS_Workflow::valid_stages(), true)) {
                return (int) RTS_Workflow::count_by_stage($stage);
            }
            return 0;
        }

        // Fallback for unexpected bootstrap order (should be rare).
        $meta_key = '_rts_workflow_stage';
        $q = new WP_Query([
            'post_type'      => 'letter',
            'post_status'    => ['draft', 'pending', 'publish', 'future', 'private'],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_key'       => $meta_key,
            'meta_value'     => $stage,
            'no_found_rows'  => false,
        ]);

        return (int) $q->found_posts;
    }

    /**
     * @return array{count:int,items:array<int,array<string,mixed>>}
     */
    private static function get_stage_queue_snapshot(string $stage, int $limit = 5): array {
        $stage = sanitize_key($stage);
        $meta_key = class_exists('RTS_Workflow') ? RTS_Workflow::META_STAGE : '_rts_workflow_stage';

        $q = new WP_Query([
            'post_type'      => 'letter',
            'post_status'    => ['draft', 'pending', 'publish', 'future', 'private'],
            'posts_per_page' => max(1, absint($limit)),
            'fields'         => 'ids',
            'meta_key'       => 'rts_scan_queued_ts',
            'orderby'        => 'meta_value_num',
            'order'          => 'DESC',
            'no_found_rows'  => false,
            'meta_query'     => [
                [
                    'key'     => $meta_key,
                    'value'   => $stage,
                    'compare' => '=',
                ],
                [
                    'key'     => 'rts_scan_queued_ts',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        $items = [];
        $now = (int) current_time('timestamp');
        foreach ((array) $q->posts as $post_id) {
            $post_id = absint($post_id);
            $queued_ts = (int) get_post_meta($post_id, 'rts_scan_queued_ts', true);
            $age = $queued_ts > 0 ? human_time_diff($queued_ts, $now) . ' ago' : 'unknown';
            $items[] = [
                'id' => $post_id,
                'title' => get_the_title($post_id) ?: ('Letter #' . $post_id),
                'age' => $age,
            ];
        }

        wp_reset_postdata();

        return [
            'count' => (int) $q->found_posts,
            'items' => $items,
        ];
    }

    private static function stage_label(string $stage): string {
        $stage = sanitize_key($stage);
        $labels = [
            'unprocessed' => 'Unprocessed',
            'unprocessed' => 'Unprocessed',
            'processing' => 'Processing',
            'pending_review' => 'Pending Review',
            'quarantined' => 'Quarantined',
            'quarantined' => 'Quarantined',
            'published' => 'Published',
            'published' => 'Published',
            'published' => 'Published',
            'archived' => 'Archived',
        ];
        return $labels[$stage] ?? ucwords(str_replace('_', ' ', $stage));
    }

    public static function terminate_stage_queue(): void {
        if (!current_user_can('manage_options')) {
            wp_die('No permission');
        }
        check_admin_referer('rts_terminate_stage_queue');

        $stage = isset($_POST['stage']) ? sanitize_key((string) wp_unslash($_POST['stage'])) : '';
        $allowed_stages = ['unprocessed', 'pending_review', 'quarantined', 'published', 'published'];
        if (!in_array($stage, $allowed_stages, true)) {
            wp_safe_redirect(add_query_arg(['post_type' => 'letter', 'page' => 'rts-workflow-tools'], admin_url('edit.php')));
            exit;
        }

        $meta_key = class_exists('RTS_Workflow') ? RTS_Workflow::META_STAGE : '_rts_workflow_stage';
        $jobs_unscheduled = 0;
        $queued_cleared = 0;
        $scan_stopped = 0;

        // Unschedule pending stage-specific letter jobs.
        if (function_exists('as_get_scheduled_actions') && class_exists('ActionScheduler')) {
            $ids = as_get_scheduled_actions([
                'hook' => 'rts_process_letter',
                'status' => 'pending',
                'per_page' => 1000,
            ], 'ids');

            foreach ((array) $ids as $action_id) {
                $action = ActionScheduler::store()->fetch_action($action_id);
                if (!$action) {
                    continue;
                }
                $args = $action->get_args();
                $post_id = isset($args[0]) ? absint($args[0]) : 0;
                if (!$post_id || get_post_type($post_id) !== 'letter') {
                    continue;
                }

                $post_stage = sanitize_key((string) get_post_meta($post_id, $meta_key, true));
                if ($post_stage !== $stage) {
                    continue;
                }

                as_unschedule_action('rts_process_letter', [$post_id], 'rts');
                $jobs_unscheduled++;
                if (delete_post_meta($post_id, 'rts_scan_queued_ts')) {
                    $queued_cleared++;
                }
                delete_post_meta($post_id, 'rts_scan_queued_gmt');
            }
        }

        // Clear queued meta for stage items even if no Action Scheduler rows exist.
        $staged = get_posts([
            'post_type'      => 'letter',
            'post_status'    => ['draft', 'pending', 'publish', 'future', 'private'],
            'posts_per_page' => 300,
            'fields'         => 'ids',
            'meta_query'     => [
                ['key' => $meta_key, 'value' => $stage],
                ['key' => 'rts_scan_queued_ts', 'compare' => 'EXISTS'],
            ],
            'no_found_rows'  => true,
        ]);
        foreach ((array) $staged as $post_id) {
            $post_id = absint($post_id);
            if (!$post_id) {
                continue;
            }
            if (delete_post_meta($post_id, 'rts_scan_queued_ts')) {
                $queued_cleared++;
            }
            delete_post_meta($post_id, 'rts_scan_queued_gmt');
        }

        // If this stage is currently being actively scanned, stop the active scan marker.
        $scan = get_option('rts_active_scan', []);
        if (is_array($scan) && ($scan['status'] ?? '') === 'running') {
            $scan_type = sanitize_key((string) ($scan['type'] ?? ''));
            $matches_scan = (
                ($stage === 'unprocessed' && $scan_type === 'inbox')
                || ($stage === 'quarantined' && $scan_type === 'quarantine')
            );
            if ($matches_scan) {
                delete_option('rts_active_scan');
                delete_option('rts_scan_queued_ts');
                delete_transient('rts_pump_active');
                if (function_exists('as_unschedule_all_actions')) {
                    as_unschedule_all_actions('rts_scan_pump', [], 'rts');
                }
                $scan_stopped = 1;
            }
        }

        $redirect = add_query_arg([
            'post_type' => 'letter',
            'page' => 'rts-workflow-tools',
            'rts_notice' => 'stage_queue_terminated',
            'stage' => $stage,
            'jobs' => max(0, $jobs_unscheduled),
            'cleared' => max(0, $queued_cleared),
            'scan_stopped' => $scan_stopped,
        ], admin_url('edit.php'));

        wp_safe_redirect($redirect);
        exit;
    }

    public static function rescan_stuck_processing(): void {
        if (!current_user_can('manage_options')) {
            wp_die('No permission.');
        }

        if (!class_exists('RTS_Workflow')) {
            wp_die('Workflow not loaded.');
        }

        check_admin_referer('rts_rescan_stuck_processing');

        $threshold = (int) apply_filters('rts_processing_stale_seconds', 15 * MINUTE_IN_SECONDS);
        $cutoff_gmt = gmdate('Y-m-d H:i:s', time() - max(60, $threshold));

        $q = new WP_Query([
            'post_type'      => 'letter',
            'post_status'    => ['draft','pending','publish','private','future'],
            'fields'         => 'ids',
            'posts_per_page' => 1000,
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'   => RTS_Workflow::META_STAGE,
                    'value' => RTS_Workflow::STAGE_PROCESSING,
                ],
                [
                    'key'     => RTS_Workflow::META_PROCESSING_STARTED_AT,
                    'value'   => $cutoff_gmt,
                    'compare' => '<',
                    'type'    => 'DATETIME',
                ],
            ],
        ]);

        $reset = 0;
        if (!empty($q->posts)) {
            foreach ($q->posts as $post_id) {
                $post_id = (int) $post_id;
                if ($post_id <= 0) continue;

                // Reset stage + clear lock safely.
                $ok = RTS_Workflow::reset_stuck_to_unprocessed($post_id, 'Rescan stuck: stale processing lock reset');
                if ($ok) {
                    delete_post_meta($post_id, RTS_Workflow::META_PROCESSING_LOCK);
                    delete_post_meta($post_id, RTS_Workflow::META_PROCESSING_STARTED_AT);
                    $reset++;
                }
            }
        }

        $redirect = add_query_arg([
            'post_type'   => 'letter',
            'page'        => 'rts-workflow-tools',
            'rts_notice'  => 'processing_reset',
            'reset'       => $reset,
        ], admin_url('edit.php'));

        wp_safe_redirect($redirect);
        exit;
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
        if (!in_array($stage, ['pending_review','quarantined','unprocessed'], true)) {
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
        echo '<h1>Manual Review</h1>';

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
            echo '<button class="button" name="save_mode" value="pending">Save as Pending Review</button>';
            echo '<button class="button" name="save_mode" value="draft">Save as Unprocessed</button>';
            echo '<button class="button button-primary" name="save_mode" value="publish">Publish</button>';
            echo '</p>';

            echo '</form>';
        }

        echo '</div>';
        echo '</div>';
        echo '</div>';
    }

    public static function review_console_save(): void {
        $post_id = isset($_POST['letter_id']) ? absint(wp_unslash($_POST['letter_id'])) : 0;
        if (!$post_id || get_post_type($post_id) !== 'letter') wp_die('Invalid letter.');

        if (!current_user_can('edit_post', $post_id)) wp_die('No permission.');

        $nonce = isset($_POST['rts_nonce']) ? sanitize_text_field(wp_unslash((string) $_POST['rts_nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'rts_review_console_save_' . $post_id)) {
            wp_die('Invalid nonce.');
        }

        $title = isset($_POST['letter_title']) ? sanitize_text_field((string) wp_unslash($_POST['letter_title'])) : '';
        $content = isset($_POST['letter_content']) ? (string) wp_unslash($_POST['letter_content']) : '';
        $mode = isset($_POST['save_mode']) ? sanitize_key((string) wp_unslash($_POST['save_mode'])) : 'pending';

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
                RTS_Workflow::set_stage($post_id, 'published', 'review_console:publish', false);
            } elseif ($status === 'draft') {
                RTS_Workflow::set_stage($post_id, RTS_Workflow::is_flagged($post_id) ? 'quarantined' : 'unprocessed', 'review_console:draft', false);
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



/**
 * Bulk actions + quick actions for workflow stages (stage-only, no post_status decisions).
 */
add_filter('bulk_actions-edit-letter', function($actions) {
    // Keep native bulk actions if any, but add workflow ones.
    $actions['rts_to_pending_review'] = 'RTS: Move to Pending Review';
    $actions['rts_to_archived']       = 'RTS: Archive';
    $actions['rts_to_published']      = 'RTS: Publish (from Pending Review)';
    return $actions;
});

add_filter('handle_bulk_actions-edit-letter', function($redirect_url, $action, $post_ids) {
    if (!current_user_can('manage_options')) return $redirect_url;

    $moved = 0;
    $skipped = 0;

    foreach ((array)$post_ids as $pid) {
        $pid = absint($pid);
        if (!$pid) continue;

        $stage = (string) get_post_meta($pid, RTS_Workflow::META_STAGE, true);

        if ($action === 'rts_to_pending_review') {
            // Strict: Only allow quarantined -> pending_review via manual override.
            if ($stage !== RTS_Workflow::STAGE_QUARANTINED) { $skipped++; continue; }
            if (RTS_Workflow::set_stage($pid, RTS_Workflow::STAGE_PENDING_REVIEW, 'Bulk override: moved from quarantined')) $moved++;
            else $skipped++;
            continue;
        }

        if ($action === 'rts_to_archived') {
            // Allow archiving from any stage except already archived.
            if ($stage === RTS_Workflow::STAGE_ARCHIVED) { $skipped++; continue; }
            if (RTS_Workflow::set_stage($pid, RTS_Workflow::STAGE_ARCHIVED, 'Bulk action: archived')) $moved++;
            else $skipped++;
            continue;
        }

        if ($action === 'rts_to_published') {
            // Strict: Only pending_review -> published
            if ($stage !== RTS_Workflow::STAGE_PENDING_REVIEW) { $skipped++; continue; }
            if (RTS_Workflow::set_stage($pid, RTS_Workflow::STAGE_PUBLISHED, 'Bulk action: published')) $moved++;
            else $skipped++;
            continue;
        }

        $skipped++;
    }

    return add_query_arg([
        'rts_bulk_action' => $action,
        'rts_moved'       => $moved,
        'rts_skipped'     => $skipped,
    ], $redirect_url);
}, 10, 3);

add_action('admin_notices', function() {
    if (!is_admin()) return;
    if (empty($_GET['rts_bulk_action'])) return;
    if (!current_user_can('manage_options')) return;

    $action  = sanitize_text_field(wp_unslash($_GET['rts_bulk_action']));
    $moved   = isset($_GET['rts_moved']) ? absint($_GET['rts_moved']) : 0;
    $skipped = isset($_GET['rts_skipped']) ? absint($_GET['rts_skipped']) : 0;

    echo '<div class="notice notice-success is-dismissible"><p>';
    echo 'RTS bulk action completed. Moved: ' . esc_html($moved) . '. Skipped: ' . esc_html($skipped) . '.';
    echo '</p></div>';
});

/**
 * Row actions for faster triage at scale.
 */
add_filter('post_row_actions', function($actions, $post) {
    if (!is_admin() || !$post || $post->post_type !== 'letter') return $actions;
    if (!current_user_can('manage_options')) return $actions;

    $stage = (string) get_post_meta($post->ID, RTS_Workflow::META_STAGE, true);

    // Quick publish from pending review
    if ($stage === RTS_Workflow::STAGE_PENDING_REVIEW) {
        $url = wp_nonce_url(add_query_arg([
            'rts_quick_action' => 'publish',
            'post_id' => $post->ID,
        ], admin_url('edit.php?post_type=letter')), 'rts_quick_action');
        $actions['rts_publish'] = '<a href="' . esc_url($url) . '">RTS: Publish</a>';
    }

    // Quick override for quarantined
    if ($stage === RTS_Workflow::STAGE_QUARANTINED) {
        $url = wp_nonce_url(add_query_arg([
            'rts_quick_action' => 'to_pending_review',
            'post_id' => $post->ID,
        ], admin_url('edit.php?post_type=letter')), 'rts_quick_action');
        $actions['rts_to_pending_review'] = '<a href="' . esc_url($url) . '">RTS: Move to Pending Review</a>';
    }

    return $actions;
}, 10, 2);

add_action('admin_init', function() {
    if (!is_admin() || empty($_GET['rts_quick_action']) || empty($_GET['post_id'])) return;
    if (!current_user_can('manage_options')) return;
    check_admin_referer('rts_quick_action');

    $action = sanitize_key(wp_unslash($_GET['rts_quick_action']));
    $pid = absint($_GET['post_id']);

    if (!$pid || get_post_type($pid) !== 'letter') return;

    $stage = (string) get_post_meta($pid, RTS_Workflow::META_STAGE, true);

    if ($action === 'publish') {
        if ($stage === RTS_Workflow::STAGE_PENDING_REVIEW) {
            RTS_Workflow::set_stage($pid, RTS_Workflow::STAGE_PUBLISHED, 'Quick action: published from pending review');
        }
    }

    if ($action === 'to_pending_review') {
        if ($stage === RTS_Workflow::STAGE_QUARANTINED) {
            RTS_Workflow::set_stage($pid, RTS_Workflow::STAGE_PENDING_REVIEW, 'Quick action: moved from quarantined');
        }
    }

    wp_safe_redirect(remove_query_arg(['rts_quick_action','post_id','_wpnonce']));
    exit;
});
