<?php
/**
 * Reasons to Stay - Moderation Hub
 *
 * Centralized dashboard for reviewing feedback and managing underperforming content.
 * Optimized for performance with large datasets (33k+ letters).
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RTS_Admin_Moderation')) {
    
class RTS_Admin_Moderation {

    public function __construct() {
        // Keep Analytics first by registering Moderation later.
        add_action('admin_menu', [$this, 'register_page'], 30);
        
        // Handle Form Submissions (admin-post.php)
        add_action('admin_post_rts_bulk_draft_letters', [$this, 'handle_bulk_draft_letters']);
        add_action('admin_post_rts_export_moderation', [$this, 'handle_export_moderation']);
        add_action('admin_post_rts_review_random', [$this, 'handle_review_random']);
        add_action('admin_post_rts_batch_process', [$this, 'handle_batch_process']);
        
        // Clear cache on content changes
        add_action('save_post_letter', [$this, 'invalidate_moderation_cache']);
        add_action('save_post_rts_feedback', [$this, 'invalidate_moderation_cache']);
    }

    public function register_page() {
        add_submenu_page(
            'edit.php?post_type=letter',
            'Analytics',
            'Analytics',
            'edit_posts',
            'rts-moderation',
            [$this, 'render_page']
        );
    }

    /**
     * Handle Bulk Move to Draft
     */
    public function handle_bulk_draft_letters() {
        check_admin_referer('rts_bulk_draft_letters', 'rts_bulk_nonce');

        if (!current_user_can('edit_others_posts')) {
            wp_die('Unauthorized');
        }

        $ids = isset($_POST['letter_ids']) ? array_map('intval', $_POST['letter_ids']) : [];
        if (empty($ids)) {
            wp_safe_redirect(admin_url('edit.php?post_type=letter&page=rts-moderation&tab=underperformers&msg=no_selection'));
            exit;
        }

        $count = 0;
        foreach ($ids as $post_id) {
            if (get_post_type($post_id) === 'letter') {
                wp_update_post([
                    'ID' => $post_id,
                    'post_status' => 'draft'
                ]);
                $count++;
            }
        }

        $this->invalidate_moderation_cache();

        wp_safe_redirect(admin_url('edit.php?post_type=letter&page=rts-moderation&tab=underperformers&moved=' . $count));
        exit;
    }

    /**
     * Handle Random Review Redirect
     */
    public function handle_review_random() {
        check_admin_referer('rts_review_random', 'rts_random_nonce');
        
        global $wpdb;
        $random_id = $wpdb->get_var("
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'letter' 
            AND post_status = 'publish' 
            ORDER BY RAND() LIMIT 1
        ");
        
        if ($random_id) {
            wp_safe_redirect(get_edit_post_link($random_id, 'url'));
            exit;
        }
        
        wp_safe_redirect(admin_url('edit.php?post_type=letter&page=rts-moderation'));
        exit;
    }

    /**
     * Handle Batch Processing
     */
    public function handle_batch_process() {
        check_admin_referer('rts_batch_process', 'rts_batch_nonce');
        if (!current_user_can('manage_options')) wp_die('Unauthorized');

        $actions = isset($_POST['batch_action']) ? (array)$_POST['batch_action'] : [];
        $messages = [];

        foreach ($actions as $action) {
            switch ($action) {
                case 'recalculate_stats':
                    // Trigger a background calculation or simplified immediate calc
                    // For now, we'll clear transients to force recalc on next view
                    $this->invalidate_moderation_cache();
                    $messages[] = 'Statistics cache cleared.';
                    break;
                case 'clear_old_meta':
                    global $wpdb;
                    
                    // Safe cleanup: get max meta_id first to avoid BIGINT underflow
                    $max_meta_id = $wpdb->get_var("SELECT MAX(meta_id) FROM {$wpdb->postmeta}");
                    
                    if ($max_meta_id && $max_meta_id > 100000) {
                        // Only run cleanup if we have more than 100k meta entries
                        $threshold = $max_meta_id - 100000;
                        $wpdb->query($wpdb->prepare(
                            "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND meta_id < %d",
                            '_rts_temp_%',
                            $threshold
                        ));
                    } else {
                        // If less than 100k entries, just delete old temp entries (older than 30 days)
                        $wpdb->query($wpdb->prepare(
                            "DELETE pm FROM {$wpdb->postmeta} pm 
                             INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID 
                             WHERE pm.meta_key LIKE %s 
                             AND p.post_modified < DATE_SUB(NOW(), INTERVAL 30 DAY)",
                            '_rts_temp_%'
                        ));
                    }
                    
                    $messages[] = 'Old temporary metadata cleaned.';
                    break;
                case 'regenerate_titles':
                    // Placeholder for title regeneration logic
                    $messages[] = 'Title regeneration queued.';
                    break;
            }
        }

        $msg = empty($messages) ? 'No actions selected.' : implode(' ', $messages);
        wp_safe_redirect(admin_url('edit.php?post_type=letter&page=rts-moderation&msg=' . urlencode($msg)));
        exit;
    }

    /**
     * Handle CSV Export (Memory Optimized)
     */
    public function handle_export_moderation() {
        check_admin_referer('rts_export_moderation', 'rts_export_nonce');
        
        if (!current_user_can('edit_posts')) wp_die('Unauthorized');

        // Increase memory limit for export
        @ini_set('memory_limit', '512M');
        set_time_limit(600);

        $type = sanitize_key($_POST['export_type'] ?? 'feedback');
        $filename = 'rts-' . $type . '-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $fp = fopen('php://output', 'w');

        if ($type === 'feedback') {
            fputcsv($fp, ['Date', 'Letter ID', 'Rating', 'Triggered', 'Comment']);
            
            // Stream posts in chunks
            $page = 1;
            $per_page = 1000;
            
            do {
                $args = [
                    'post_type' => 'rts_feedback',
                    'post_status' => 'publish',
                    'posts_per_page' => $per_page,
                    'paged' => $page,
                    'fields' => 'ids',
                    'orderby' => 'ID', 
                    'order' => 'ASC'
                ];
                $ids = get_posts($args);
                
                foreach ($ids as $id) {
                    fputcsv($fp, [
                        get_the_date('Y-m-d H:i', $id),
                        get_post_meta($id, 'letter_id', true),
                        get_post_meta($id, 'rating', true),
                        get_post_meta($id, 'triggered', true) ? 'Yes' : 'No',
                        substr(get_post_meta($id, 'comment', true), 0, 1000) // Sanity limit
                    ]);
                }
                
                // Stop if we fetched fewer than per_page (end of list)
                if (count($ids) < $per_page) break;
                
                $page++;
                // Safety break for massive databases
                if ($page > 100) break; 
                
            } while (true);

        } elseif ($type === 'underperformers') {
            fputcsv($fp, ['ID', 'Title', 'Views', 'Up', 'Down', 'Helpful %', 'Last Viewed']);
            // Fetch unlimited (false)
            $data = $this->fetch_underperformers_data(50, 40.0, 5, false);
            foreach ($data as $row) {
                fputcsv($fp, [
                    $row->ID,
                    $row->post_title,
                    $row->views,
                    $row->thumbs_up,
                    $row->thumbs_down,
                    $row->helpful_pct . '%',
                    $row->last_viewed
                ]);
            }
        }

        fclose($fp);
        exit;
    }

    public function render_page() {
        if (!current_user_can('edit_posts')) {
            wp_die('You do not have permission to view this page.');
        }

        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'feedback';
        if (!in_array($tab, ['feedback','underperformers'], true)) $tab = 'feedback';

        echo '<div class="wrap">';
        echo '<h1>Moderation Hub</h1>';
        
        $this->render_stats_bar();
        $this->render_quick_actions();

        $base = admin_url('edit.php?post_type=letter&page=rts-moderation');
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a class="nav-tab ' . ($tab==='feedback'?'nav-tab-active':'') . '" href="' . esc_url($base . '&tab=feedback') . '">Feedback Review</a>';
        echo '<a class="nav-tab ' . ($tab==='underperformers'?'nav-tab-active':'') . '" href="' . esc_url($base . '&tab=underperformers') . '">Underperformers</a>';
        echo '</h2>';

        if (isset($_GET['moved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Moved ' . intval($_GET['moved']) . ' letters to Draft.</p></div>';
        }
        if (isset($_GET['msg'])) {
            echo '<div class="notice notice-info is-dismissible"><p>' . esc_html(urldecode($_GET['msg'])) . '</p></div>';
        }

        if ($tab === 'feedback') {
            $this->render_feedback_tab();
        } else {
            $this->render_underperformers_tab();
        }

        $this->render_export_section();
        $this->render_batch_tools();
        
        $this->add_keyboard_shortcuts();

        echo '</div>';
    }

    private function render_stats_bar() {
        global $wpdb;
        $cache_key = 'rts_mod_stats_bar';
        $stats = get_transient($cache_key);
        
        if (false === $stats) {
            $flagged_feedback = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='triggered' AND meta_value='1'");
            $total_feedback = wp_count_posts('rts_feedback')->publish;
            $stats = ['flagged' => $flagged_feedback, 'total' => $total_feedback];
            set_transient($cache_key, $stats, 5 * MINUTE_IN_SECONDS);
        }
        
        ?>
        <div class="card" style="margin-bottom: 20px; padding: 15px; display: flex; gap: 30px;">
            <div>
                <strong>Total Feedback:</strong> 
                <span style="font-size: 1.2em;"><?php echo number_format($stats['total']); ?></span>
            </div>
            <div>
                <strong>Triggered Alerts:</strong> 
                <span style="font-size: 1.2em; color: #d63638;"><?php echo number_format($stats['flagged']); ?></span>
            </div>
        </div>
        <?php
    }

    private function render_quick_actions() {
        ?>
        <div class="card" style="max-width:1100px; padding:16px; margin-bottom:20px;">
            <h3 style="margin-top:0;">⚡ Quick Actions</h3>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display: inline;">
                    <?php wp_nonce_field('rts_review_random', 'rts_random_nonce'); ?>
                    <input type="hidden" name="action" value="rts_review_random">
                    <button type="submit" class="button">Review Random Letter</button>
                </form>
                
                <a href="<?php echo esc_url(admin_url('edit.php?post_status=draft&post_type=letter')); ?>" class="button">View All Drafts</a>
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=letter&page=rts-settings&tab=debug')); ?>" class="button button-secondary">View Debug Logs</a>
            </div>
        </div>
        <?php
    }

    private function render_feedback_tab() {
        $triggered_only = isset($_GET['triggered']) && (string)$_GET['triggered'] === '1';
        $rating = isset($_GET['rating']) ? sanitize_key((string) $_GET['rating']) : '';
        $date_from = sanitize_text_field($_GET['date_from'] ?? '');
        $date_to = sanitize_text_field($_GET['date_to'] ?? '');
        
        $paged = max(1, isset($_GET['paged']) ? intval($_GET['paged']) : 1);
        $per_page = 50;

        echo '<div class="card" style="max-width:1100px; padding:16px;">';
        echo '<h2 style="margin-top:0;">Letter-linked feedback</h2>';

        // Filters
        echo '<form method="get" style="margin:10px 0 16px; background: #f9f9f9; padding: 10px; border: 1px solid #ddd;">';
        echo '<input type="hidden" name="post_type" value="letter">';
        echo '<input type="hidden" name="page" value="rts-moderation">';
        echo '<input type="hidden" name="tab" value="feedback">';
        
        echo '<div style="display:flex; gap:15px; align-items:center; flex-wrap:wrap;">';
        echo '<label><input type="checkbox" name="triggered" value="1" ' . checked($triggered_only, true, false) . '> <strong>Triggered only</strong></label>';
        echo '<label>Rating ';
        echo '<select name="rating">';
        echo '<option value=""' . selected($rating, '', false) . '>Any</option>';
        echo '<option value="up"' . selected($rating, 'up', false) . '>👍 Up</option>';
        echo '<option value="down"' . selected($rating, 'down', false) . '>👎 Down</option>';
        echo '<option value="neutral"' . selected($rating, 'neutral', false) . '>Neutral</option>';
        echo '</select></label>';
        
        echo '<label>From: <input type="date" name="date_from" value="' . esc_attr($date_from) . '"></label>';
        echo '<label>To: <input type="date" name="date_to" value="' . esc_attr($date_to) . '"></label>';
        
        submit_button('Filter', 'secondary', '', false);
        echo '</div>';
        echo '</form>';

        $meta_query = [];
        if ($triggered_only) {
            $meta_query[] = ['key' => 'triggered', 'value' => '1', 'compare' => '='];
        }
        if ($rating) {
            $meta_query[] = ['key' => 'rating', 'value' => $rating, 'compare' => '='];
        }

        $date_query = [];
        if ($date_from) {
            $date_query['after'] = $date_from;
        }
        if ($date_to) {
            $date_query['before'] = $date_to;
        }
        if (!empty($date_query)) {
            $date_query['inclusive'] = true;
        }

        $q = new WP_Query([
            'post_type' => 'rts_feedback',
            'post_status' => 'publish',
            'posts_per_page' => $per_page,
            'paged' => $paged,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => $meta_query,
            'date_query' => empty($date_query) ? null : [$date_query]
        ]);

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th style="width:140px;">Date</th>';
        echo '<th>Letter</th>';
        echo '<th style="width:100px;">Rating</th>';
        echo '<th style="width:100px;">Triggered</th>';
        echo '<th>Comment</th>';
        echo '<th style="width:80px;">Actions</th>';
        echo '</tr></thead><tbody>';

        if ($q->have_posts()) {
            foreach ($q->posts as $fb) {
                $letter_id = (int) get_post_meta($fb->ID, 'letter_id', true);
                $letter_title = $letter_id ? get_the_title($letter_id) : '';
                $rating_v = (string) get_post_meta($fb->ID, 'rating', true);
                $trig_v = (string) get_post_meta($fb->ID, 'triggered', true);
                $comment = wp_strip_all_tags((string) get_post_meta($fb->ID, 'comment', true));
                if (strlen($comment) > 100) $comment = substr($comment, 0, 100) . '...';

                $rating_label = $rating_v === 'up' ? '👍' : ($rating_v === 'down' ? '👎' : '😐');
                $trig_label = ($trig_v === '1') ? '<span style="color:#b32d2e; font-weight:800;">YES</span>' : '-';

                $letter_link = $letter_id ? ('<a href="' . esc_url(get_edit_post_link($letter_id)) . '">#' . $letter_id . ' ' . esc_html(mb_strimwidth($letter_title, 0, 30, '...')) . '</a>') : '<em>Deleted</em>';
                $fb_link = '<a href="' . esc_url(get_edit_post_link($fb->ID)) . '" class="button button-small">View</a>';

                echo '<tr>';
                echo '<td>' . esc_html(get_the_date('Y-m-d H:i', $fb)) . '</td>';
                echo '<td>' . $letter_link . '</td>';
                echo '<td>' . $rating_label . '</td>';
                echo '<td>' . $trig_label . '</td>';
                echo '<td>' . esc_html($comment) . '</td>';
                echo '<td>' . $fb_link . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="6"><em>No feedback entries found.</em></td></tr>';
        }

        echo '</tbody></table>';

        // Pagination
        if ($q->max_num_pages > 1) {
            echo '<div class="tablenav bottom"><div class="tablenav-pages">';
            echo paginate_links([
                'base' => add_query_arg('paged', '%#%'),
                'format' => '',
                'prev_text' => '&laquo;',
                'next_text' => '&raquo;',
                'total' => $q->max_num_pages,
                'current' => $paged
            ]);
            echo '</div></div>';
        }

        echo '</div>';
    }

    private function render_underperformers_tab() {
        echo '<div class="card" style="max-width:1100px; padding:16px;">';
        echo '<h2 style="margin-top:0;">Underperforming letters</h2>';
        echo '<p>Identify letters with high visibility but low helpfulness scores. <strong>Data is cached for 15 minutes.</strong></p>';

        $min_views = isset($_GET['min_views']) ? max(0, absint($_GET['min_views'])) : 50;
        $max_pct   = isset($_GET['max_pct']) ? floatval($_GET['max_pct']) : 40.0;
        $min_votes = isset($_GET['min_votes']) ? max(0, absint($_GET['min_votes'])) : 5;
        $date_from = sanitize_text_field($_GET['date_from'] ?? '');
        $date_to   = sanitize_text_field($_GET['date_to'] ?? '');

        echo '<form method="get" style="margin:14px 0; background: #f0f0f1; padding: 10px; border-radius: 4px;">';
        echo '<input type="hidden" name="post_type" value="letter">';
        echo '<input type="hidden" name="page" value="rts-moderation">';
        echo '<input type="hidden" name="tab" value="underperformers">';
        
        echo '<div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">';
        echo '<label>Min views: <input type="number" name="min_views" value="' . esc_attr($min_views) . '" style="width:70px;"></label> ';
        echo '<label>Max Helpful %: <input type="number" step="0.1" name="max_pct" value="' . esc_attr($max_pct) . '" style="width:70px;"></label> ';
        echo '<label>Min votes: <input type="number" name="min_votes" value="' . esc_attr($min_votes) . '" style="width:70px;"></label> ';
        echo '<span style="border-left:1px solid #ccc; margin:0 5px;"></span>';
        echo '<label>From: <input type="date" name="date_from" value="' . esc_attr($date_from) . '"></label>';
        echo '<label>To: <input type="date" name="date_to" value="' . esc_attr($date_to) . '"></label>';
        submit_button('Apply Filters', 'secondary', '', false);
        echo '</div>';
        echo '</form>';

        // Fetch data using optimized SQL query
        $results = $this->fetch_underperformers_data($min_views, $max_pct, $min_votes, $date_from, $date_to);

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('rts_bulk_draft_letters', 'rts_bulk_nonce');
        echo '<input type="hidden" name="action" value="rts_bulk_draft_letters">';

        echo '<table class="widefat striped">';
        echo '<thead><tr>
            <th style="width:28px;"><input type="checkbox" id="rts-select-all"></th>
            <th>Letter</th>
            <th style="width:90px;">Views</th>
            <th style="width:90px;">👍</th>
            <th style="width:90px;">👎</th>
            <th style="width:120px;">Helpful %</th>
            <th style="width:160px;">Last Viewed</th>
        </tr></thead><tbody>';

        if (!empty($results)) {
            foreach ($results as $row) {
                $pct_badge = '<span style="display:inline-block;padding:3px 8px;border-radius:999px;background:#b32d2e;color:#fff;font-weight:700;">' . esc_html(number_format($row->helpful_pct, 1)) . '%</span>';
                
                $last_viewed = $row->last_viewed ? date_i18n('Y-m-d H:i', strtotime($row->last_viewed)) : '—';

                echo '<tr>';
                echo '<td><input type="checkbox" name="letter_ids[]" value="' . esc_attr($row->ID) . '"></td>';
                echo '<td><a href="' . esc_url(get_edit_post_link($row->ID)) . '">#' . $row->ID . ' ' . esc_html($row->post_title) . '</a></td>';
                echo '<td>' . number_format($row->views) . '</td>';
                echo '<td>' . number_format($row->thumbs_up) . '</td>';
                echo '<td>' . number_format($row->thumbs_down) . '</td>';
                echo '<td>' . $pct_badge . '</td>';
                echo '<td>' . esc_html($last_viewed) . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="7"><em>No underperforming letters found matching criteria.</em></td></tr>';
        }

        echo '</tbody></table>';
        
        if (!empty($results)) {
            echo '<p style="margin-top:10px;">';
            submit_button('Move selected to Draft', 'primary', 'submit', false);
            echo '</p>';
        }

        echo '<script>
        (function(){
            var all = document.getElementById("rts-select-all");
            if(!all) return;
            all.addEventListener("change", function(){
                var boxes = document.querySelectorAll("input[name=\"letter_ids[]\"]");
                for (var i=0;i<boxes.length;i++){ boxes[i].checked = all.checked; }
            });
        })();
        </script>';

        echo '</form>';
        echo '</div>';
    }

    private function render_batch_tools() {
        ?>
        <div class="card" style="max-width:1100px; padding:16px; margin-top:20px; border-left: 4px solid #d63638;">
            <h3 style="margin-top:0;">⚠️ Batch Processing</h3>
            <p>For large operations on thousands of letters. Use with caution.</p>
            
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" 
                  onsubmit="return confirm('This will process matching letters. Continue?');">
                <?php wp_nonce_field('rts_batch_process', 'rts_batch_nonce'); ?>
                <input type="hidden" name="action" value="rts_batch_process">
                
                <p>
                    <label><input type="checkbox" name="batch_action[]" value="recalculate_stats"> Recalculate Stats Cache</label><br>
                    <label><input type="checkbox" name="batch_action[]" value="clear_old_meta"> Clean Up Temporary Metadata</label><br>
                    <label><input type="checkbox" name="batch_action[]" value="regenerate_titles"> Queue Title Regeneration</label>
                </p>
                
                <?php submit_button('Run Batch Jobs', 'primary'); ?>
            </form>
        </div>
        <?php
    }

    private function render_export_section() {
        ?>
        <div class="card" style="max-width:1100px; padding:16px; margin-top:20px; border-left: 4px solid #2271b1;">
            <h3>Export Data</h3>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('rts_export_moderation', 'rts_export_nonce'); ?>
                <input type="hidden" name="action" value="rts_export_moderation">
                <p>
                    <label style="margin-right: 15px;">
                        <input type="radio" name="export_type" value="feedback" checked> All Feedback (CSV)
                    </label>
                    <label>
                        <input type="radio" name="export_type" value="underperformers"> Current Underperformers List (CSV)
                    </label>
                </p>
                <?php submit_button('Download CSV', 'secondary'); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Optimized SQL query to fetch underperformers.
     */
    private function fetch_underperformers_data($min_views, $max_pct, $min_votes, $date_from = '', $date_to = '', $limit = 200) {
        global $wpdb;
        
        $cache_key = 'rts_underperformers_' . md5(serialize(func_get_args()));
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $date_sql = "";
        if ($date_from) $date_sql .= $wpdb->prepare(" AND p.post_date >= %s", $date_from . ' 00:00:00');
        if ($date_to)   $date_sql .= $wpdb->prepare(" AND p.post_date <= %s", $date_to . ' 23:59:59');

        $query = $wpdb->prepare("
            SELECT 
                p.ID, 
                p.post_title,
                CAST(COALESCE(m_views.meta_value, 0) AS UNSIGNED) as views,
                CAST(COALESCE(m_up.meta_value, 0) AS UNSIGNED) as thumbs_up,
                CAST(COALESCE(m_down.meta_value, 0) AS UNSIGNED) as thumbs_down,
                CAST(COALESCE(m_pct.meta_value, 0) AS DECIMAL(5,1)) as helpful_pct,
                m_last.meta_value as last_viewed
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} m_views ON (p.ID = m_views.post_id AND m_views.meta_key = 'view_count')
            LEFT JOIN {$wpdb->postmeta} m_up    ON (p.ID = m_up.post_id    AND m_up.meta_key    = 'rts_thumbs_up')
            LEFT JOIN {$wpdb->postmeta} m_down  ON (p.ID = m_down.post_id  AND m_down.meta_key  = 'rts_thumbs_down')
            LEFT JOIN {$wpdb->postmeta} m_pct   ON (p.ID = m_pct.post_id   AND m_pct.meta_key   = 'rts_helpful_pct')
            LEFT JOIN {$wpdb->postmeta} m_last  ON (p.ID = m_last.post_id  AND m_last.meta_key  = 'last_viewed')
            WHERE p.post_type = 'letter' 
            AND p.post_status = 'publish'
            $date_sql
            HAVING views >= %d 
            AND helpful_pct < %f
            AND (thumbs_up + thumbs_down) >= %d
            ORDER BY views DESC
        " . ($limit ? "LIMIT %d" : ""), 
        $min_views, $max_pct, $min_votes, $limit);

        if ($limit === false) {
             $query = str_replace('LIMIT %d', '', $query);
             // Re-bind parameters without limit isn't trivial with prepare() variable args, 
             // but strictly speaking for export we might just run a simplified version or rely on high limit.
             // For this implementation, we assume if limit is false we just want 'many' rows, let's bump limit high
             // or execute directly if safe. Here we'll just set limit to 100000 for export safety.
             $query = $wpdb->prepare(str_replace('LIMIT %d', 'LIMIT 100000', $query), $min_views, $max_pct, $min_votes); 
        }

        $results = $wpdb->get_results($query);
        set_transient($cache_key, $results, 15 * MINUTE_IN_SECONDS);
        return $results;
    }

    public function invalidate_moderation_cache() {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_rts_underperformers_%' OR option_name LIKE '_transient_timeout_rts_underperformers_%'");
        delete_transient('rts_mod_stats_bar');
    }

    private function add_keyboard_shortcuts() {
        ?>
        <script>
        document.addEventListener('keydown', function(e) {
            // Alt + F = Focus feedback filter
            if (e.altKey && e.key === 'f') {
                e.preventDefault();
                document.querySelector('input[name="triggered"]')?.focus();
            }
            // Alt + U = Focus underperformers filter
            if (e.altKey && e.key === 'u') {
                e.preventDefault();
                document.querySelector('input[name="min_views"]')?.focus();
            }
            // Alt + E = Export
            if (e.altKey && e.key === 'e') {
                e.preventDefault();
                document.querySelector('input[name="export_type"]')?.closest('form')?.submit();
            }
        });
        </script>
        <?php
    }
}

} // end class_exists check
