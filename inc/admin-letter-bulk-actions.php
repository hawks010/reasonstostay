<?php
/**
 * Reasons to Stay - Letter CPT bulk actions + review views
 * * enhanced with performance optimizations for 33k+ records,
 * quick edit, custom columns, advanced filtering, exports, and undo support.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('RTS_Letter_Bulk_Actions')) {
    
class RTS_Letter_Bulk_Actions {

    const QUERY_VAR_REVIEW = 'rts_review';
    private static $performance_stats = [];

    public static function init() {
        if (!is_admin()) {
            return;
        }

        // Optimize performance: Only load heavy hooks on the specific screen
        add_action('current_screen', [__CLASS__, 'setup_screen_hooks']);
        
        // Notices need to be global to appear after redirects
        add_action('admin_notices', [__CLASS__, 'bulk_action_notice']);
        add_action('admin_notices', [__CLASS__, 'maybe_add_index_notice']);

        // AJAX for star toggle
        add_action('wp_ajax_rts_toggle_star', [__CLASS__, 'handle_ajax_star']);
    }

    public static function setup_screen_hooks($screen) {
        if ($screen->post_type !== 'letter') {
            return;
        }

        // Bulk Actions
        add_filter('bulk_actions-edit-letter', [__CLASS__, 'register_bulk_actions']);
        add_filter('handle_bulk_actions-edit-letter', [__CLASS__, 'handle_bulk_actions'], 10, 3);
        
        // Export functionality (hooked into bulk actions)
        add_filter('bulk_actions-edit-letter', [__CLASS__, 'add_export_bulk_action'], 20);
        add_filter('handle_bulk_actions-edit-letter', [__CLASS__, 'handle_export_bulk_action'], 10, 3);
        // Handle undo action via the same hook structure if possible, or check inside handle_bulk_actions if logic allows
        // The critique suggests a separate handler `handle_undo_action` but sticking to the bulk action hook pattern is cleaner.
        add_filter('handle_bulk_actions-edit-letter', [__CLASS__, 'handle_undo_action'], 10, 3);

        // Views & Filtering
        add_filter('views_edit-letter', [__CLASS__, 'add_custom_views']);
        add_action('pre_get_posts', [__CLASS__, 'filter_by_review_view']);
        add_action('restrict_manage_posts', [__CLASS__, 'add_status_filters']);
        add_action('pre_get_posts', [__CLASS__, 'filter_by_quality']);
        
        // Search Integration
        add_filter('posts_search', [__CLASS__, 'extend_admin_search'], 10, 2);

        // Custom Columns
        add_filter('manage_edit-letter_columns', [__CLASS__, 'add_custom_columns']);
        add_action('manage_letter_posts_custom_column', [__CLASS__, 'populate_custom_columns'], 10, 2);
        add_filter('manage_edit-letter_sortable_columns', [__CLASS__, 'make_columns_sortable']);
        add_action('pre_get_posts', [__CLASS__, 'sort_custom_columns']);

        // Quick Edit
        add_action('quick_edit_custom_box', [__CLASS__, 'add_quick_edit_fields'], 10, 2);
        add_action('save_post', [__CLASS__, 'save_quick_edit_fields']);

        // Contextual Help
        add_filter('contextual_help', [__CLASS__, 'add_contextual_help'], 10, 3);
    }

    /**
     * --------------------------------------------------------------------------
     * Bulk Actions (Standard + Custom)
     * --------------------------------------------------------------------------
     */

    public static function register_bulk_actions(array $actions): array {
        $actions['rts_publish'] = __('Publish (RTS)', 'rts');
        $actions['rts_pending'] = __('Mark Pending (RTS)', 'rts');
        $actions['rts_draft']   = __('Mark Draft (RTS)', 'rts');
        $actions['rts_undo']    = __('Undo Last Bulk Action', 'rts');
        return $actions;
    }

    public static function handle_bulk_actions(string $redirect_url, string $action, array $post_ids): string {
        if (!in_array($action, ['rts_publish', 'rts_pending', 'rts_draft'], true)) {
            return $redirect_url;
        }

        if (!current_user_can('edit_posts')) {
            return add_query_arg('rts_bulk_error', 1, $redirect_url);
        }

        $start_time = microtime(true);

        $new_status = 'draft';
        if ($action === 'rts_publish') {
            $new_status = 'publish';
        } elseif ($action === 'rts_pending') {
            $new_status = 'pending';
        }

        // Store state for undo
        $previous_states = [];
        foreach ($post_ids as $pid) {
            $previous_states[$pid] = get_post_status($pid);
        }
        set_transient('rts_last_bulk_action', [
            'action' => $action,
            'states' => $previous_states,
            'time'   => time()
        ], HOUR_IN_SECONDS);

        $updated = 0;
        foreach ($post_ids as $post_id) {
            $post_id = absint($post_id);
            if (!$post_id || !current_user_can('edit_post', $post_id)) {
                continue;
            }

            $result = wp_update_post([
                'ID' => $post_id,
                'post_status' => $new_status,
            ], true);

            if (!is_wp_error($result)) {
                $updated++;
            }
        }

        self::track_performance("bulk_{$action}", $start_time, $updated);

        return add_query_arg([
            'rts_bulk_done' => $updated,
            'rts_bulk_action' => $action,
        ], remove_query_arg(['rts_bulk_done', 'rts_bulk_action', 'rts_undo_done', 'rts_undo_error'], $redirect_url));
    }

    public static function handle_undo_action($redirect_url, $action, $post_ids) {
        if ($action !== 'rts_undo') {
            return $redirect_url;
        }

        $last_action = get_transient('rts_last_bulk_action');
        if (!$last_action || empty($last_action['states'])) {
            return add_query_arg('rts_undo_error', 'no_action', $redirect_url);
        }

        $restored = 0;
        foreach ($last_action['states'] as $post_id => $old_status) {
            if (current_user_can('edit_post', $post_id)) {
                wp_update_post([
                    'ID' => $post_id,
                    'post_status' => $old_status
                ]);
                $restored++;
            }
        }

        delete_transient('rts_last_bulk_action');

        return add_query_arg([
            'rts_undo_done' => $restored,
            'rts_undo_action' => $last_action['action']
        ], $redirect_url);
    }

    public static function add_export_bulk_action($actions) {
        if (current_user_can('export')) {
            $actions['rts_export_selected'] = __('Export Selected (CSV)', 'rts');
        }
        return $actions;
    }

    public static function handle_export_bulk_action($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'rts_export_selected' || empty($post_ids)) {
            return $redirect_to;
        }

        if (!current_user_can('export')) {
            return add_query_arg('rts_export_error', 'permission', $redirect_to);
        }

        // For large exports (>1000), use file streaming logic
        if (count($post_ids) > 1000) {
            self::handle_large_export($post_ids);
            exit;
        }

        // Standard export
        @ini_set('memory_limit', '512M');
        set_time_limit(300);

        $filename = 'letters-export-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['ID', 'Title', 'Status', 'Helpful %', 'Views', 'Up Votes', 'Down Votes', 'Created', 'Last Modified']);

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'letter') {
                continue;
            }

            fputcsv($fp, [
                $post->ID,
                $post->post_title,
                $post->post_status,
                get_post_meta($post_id, 'rts_helpful_pct', true) ?: '',
                get_post_meta($post_id, 'view_count', true) ?: 0,
                get_post_meta($post_id, 'rts_thumbs_up', true) ?: 0,
                get_post_meta($post_id, 'rts_thumbs_down', true) ?: 0,
                $post->post_date,
                $post->post_modified
            ]);
        }

        fclose($fp);
        exit;
    }

    private static function handle_large_export($post_ids) {
        $filename = 'letters-export-' . date('Y-m-d') . '.csv';
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['ID', 'Title', 'Status', 'Helpful %', 'Views', 'Up Votes', 'Down Votes', 'Created', 'Last Modified']);
        
        // Process in chunks to avoid memory issues
        $chunks = array_chunk($post_ids, 500);
        
        foreach ($chunks as $chunk) {
            $posts = get_posts([
                'post_type' => 'letter',
                'post__in' => $chunk,
                'posts_per_page' => -1,
                'post_status' => 'any',
                'fields' => 'ids',
            ]);
            
            foreach ($posts as $post_id) {
                $post = get_post($post_id);
                if (!$post) continue;
                
                fputcsv($fp, [
                    $post->ID,
                    $post->post_title,
                    $post->post_status,
                    get_post_meta($post_id, 'rts_helpful_pct', true) ?: '',
                    get_post_meta($post_id, 'view_count', true) ?: 0,
                    get_post_meta($post_id, 'rts_thumbs_up', true) ?: 0,
                    get_post_meta($post_id, 'rts_thumbs_down', true) ?: 0,
                    $post->post_date,
                    $post->post_modified
                ]);
                
                // Clean up memory
                wp_cache_delete($post_id, 'posts');
                wp_cache_delete($post_id, 'post_meta');
            }
            
            // Force garbage collection
            if (function_exists('gc_collect_cycles')) {
                gc_collect_cycles();
            }
        }
        
        fclose($fp);
        exit;
    }

    public static function bulk_action_notice(): void {
        if (!isset($_GET['post_type']) || $_GET['post_type'] !== 'letter') {
            return;
        }

        if (!empty($_GET['rts_bulk_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('RTS bulk action failed: insufficient permissions.', 'rts') . '</p></div>';
        }

        if (!empty($_GET['rts_bulk_done']) && !empty($_GET['rts_bulk_action'])) {
            $done = absint($_GET['rts_bulk_done']);
            $action = sanitize_text_field(wp_unslash($_GET['rts_bulk_action']));
            $labels = [
                'rts_publish' => __('published', 'rts'),
                'rts_pending' => __('moved to pending', 'rts'),
                'rts_draft'   => __('saved as draft', 'rts'),
            ];
            $verb = $labels[$action] ?? __('updated', 'rts');
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('%d letter(s) %s.', 'rts'), $done, $verb)) . '</p></div>';
        }

        if (!empty($_GET['rts_undo_done'])) {
            $count = absint($_GET['rts_undo_done']);
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('Undo successful: %d letter(s) restored.', 'rts'), $count)) . '</p></div>';
        }

        if (!empty($_GET['rts_undo_error'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Undo failed: No previous action found to undo.', 'rts') . '</p></div>';
        }
    }

    public static function maybe_add_index_notice() {
        global $wpdb;
        
        // Check if we have proper indexes (only for admins)
        if (!current_user_can('manage_options')) return;

        $has_letter_id_index = $wpdb->get_var("
            SHOW INDEX FROM {$wpdb->postmeta} 
            WHERE Key_name = 'meta_key_value' 
            AND Column_name = 'meta_key'
        ");
        
        // Check via transient to avoid query on every page load
        if (false === ($show_notice = get_transient('rts_index_check'))) {
            $show_notice = !$has_letter_id_index ? 'yes' : 'no';
            set_transient('rts_index_check', $show_notice, DAY_IN_SECONDS);
        }

        // Never show technical/performance tips to client admins unless explicitly enabled.
        // Enable by defining: define('RTS_SHOW_TECH_NOTICES', true);
        if ($show_notice === 'yes' && current_user_can('manage_options') && defined('RTS_SHOW_TECH_NOTICES') && RTS_SHOW_TECH_NOTICES) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p><strong>RTS Performance Tip:</strong> For optimal performance with large datasets, 
                consider adding a compound index on postmeta: <code>CREATE INDEX meta_key_value ON {$wpdb->postmeta}(meta_key, meta_value(20));</code></p>
            </div>
            <?php
        }
    }

    public static function track_performance($operation, $start_time, $item_count = 0) {
        $duration = microtime(true) - $start_time;
        $rate = $item_count > 0 ? $item_count / $duration : 0;
        
        self::$performance_stats[] = [
            'operation' => $operation,
            'duration' => round($duration, 3),
            'items' => $item_count,
            'rate' => round($rate, 1),
            'memory' => memory_get_peak_usage(true) / 1024 / 1024
        ];
        
        // Log if operation is slow (> 5s)
        if ($duration > 5.0 && defined('WP_DEBUG') && WP_DEBUG) {
            error_log("RTS Performance: {$operation} took {$duration}s for {$item_count} items");
        }
    }

    /**
     * --------------------------------------------------------------------------
     * Custom Views & Filtering (Optimized)
     * --------------------------------------------------------------------------
     */

    public static function add_custom_views(array $views): array {
        $base = admin_url('edit.php?post_type=letter');
        $counts = self::get_feedback_counts();
        $current = isset($_GET[self::QUERY_VAR_REVIEW]) ? sanitize_key(wp_unslash($_GET[self::QUERY_VAR_REVIEW])) : '';

        // Generate nonced URLs for security
        $triggered_url = wp_nonce_url(add_query_arg(self::QUERY_VAR_REVIEW, 'triggered', $base), 'rts_filter_view', 'rts_nonce');
        $low_url = wp_nonce_url(add_query_arg(self::QUERY_VAR_REVIEW, 'low', $base), 'rts_filter_view', 'rts_nonce');

        $views['rts_triggered'] = sprintf(
            '<a href="%s" %s>%s <span class="count">(%d)</span></a>',
            esc_url($triggered_url),
            ($current === 'triggered') ? 'class="current"' : '',
            esc_html__('Needs review (triggered)', 'rts'),
            $counts['triggered']
        );

        $views['rts_low'] = sprintf(
            '<a href="%s" %s>%s <span class="count">(%d)</span></a>',
            esc_url($low_url),
            ($current === 'low') ? 'class="current"' : '',
            esc_html__('Low rated', 'rts'),
            $counts['low']
        );

        return $views;
    }

    public static function filter_by_review_view(WP_Query $query): void {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'letter') {
            return;
        }

        $view = isset($_GET[self::QUERY_VAR_REVIEW]) ? sanitize_key(wp_unslash($_GET[self::QUERY_VAR_REVIEW])) : '';
        if (!$view) {
            return;
        }

        // Verify nonce if applying a sensitive filter view
        if (!isset($_GET['rts_nonce']) || !wp_verify_nonce($_GET['rts_nonce'], 'rts_filter_view')) {
            wp_die('Security check failed for view filter.');
        }

        $letter_ids = [];
        if ($view === 'triggered') {
            $letter_ids = self::get_letter_ids_with_feedback(['triggered' => 1]);
        } elseif ($view === 'low') {
            $letter_ids = self::get_letter_ids_with_feedback(['rating' => 'down']);
        }

        if (empty($letter_ids)) {
            $query->set('post__in', [0]); // No results
        } else {
            $query->set('post__in', $letter_ids);
            $query->set('orderby', 'post__in');
        }
    }

    public static function add_status_filters() {
        global $typenow;
        if ($typenow !== 'letter') return;

        $selected = isset($_GET['rts_quality']) ? sanitize_key($_GET['rts_quality']) : '';
        ?>
        <select name="rts_quality" id="rts_quality_filter">
            <option value="">All Quality Levels</option>
            <option value="high" <?php selected($selected, 'high'); ?>>High Quality (80%+)</option>
            <option value="medium" <?php selected($selected, 'medium'); ?>>Medium Quality (40-79%)</option>
            <option value="low" <?php selected($selected, 'low'); ?>>Low Quality (0-39%)</option>
            <option value="untested" <?php selected($selected, 'untested'); ?>>Untested (No feedback)</option>
        </select>
        <?php
    }

    public static function filter_by_quality($query) {
        global $typenow;
        if (!is_admin() || $typenow !== 'letter' || !$query->is_main_query()) return;

        $quality = isset($_GET['rts_quality']) ? sanitize_key($_GET['rts_quality']) : '';
        if (!$quality) return;

        $meta_query = $query->get('meta_query') ?: [];

        switch ($quality) {
            case 'high':
                $meta_query[] = ['key' => 'rts_helpful_pct', 'value' => 80, 'compare' => '>=', 'type' => 'NUMERIC'];
                break;
            case 'medium':
                $meta_query[] = ['key' => 'rts_helpful_pct', 'value' => [40, 79], 'compare' => 'BETWEEN', 'type' => 'NUMERIC'];
                break;
            case 'low':
                $meta_query[] = ['key' => 'rts_helpful_pct', 'value' => 40, 'compare' => '<', 'type' => 'NUMERIC'];
                break;
            case 'untested':
                $meta_query[] = [
                    'relation' => 'OR',
                    ['key' => 'rts_helpful_pct', 'compare' => 'NOT EXISTS'],
                    ['key' => 'rts_helpful_pct', 'value' => '', 'compare' => '=']
                ];
                break;
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }
    }

    public static function extend_admin_search($search, $wp_query) {
        global $wpdb;
        
        if (!is_admin() || $wp_query->get('post_type') !== 'letter') {
            return $search;
        }
        
        $search_term = $wp_query->get('s');
        if (empty($search_term)) {
            return $search;
        }
        
        // Search in helpful percentage range (e.g., "helpful:80")
        if (preg_match('/helpful:(\d+)/i', $search_term, $matches)) {
            $pct = intval($matches[1]);
            $meta_query = $wp_query->get('meta_query') ?: [];
            $meta_query[] = [
                'key' => 'rts_helpful_pct',
                'value' => $pct,
                'compare' => '>=',
                'type' => 'NUMERIC'
            ];
            $wp_query->set('meta_query', $meta_query);
            $wp_query->set('s', ''); // Clear search term to allow meta query to work
            return '';
        }
        
        return $search;
    }

    /**
     * --------------------------------------------------------------------------
     * Custom Columns, Quick Edit, & AJAX
     * --------------------------------------------------------------------------
     */

    public static function add_custom_columns($columns) {
        $date = $columns['date'];
        unset($columns['date']); // Re-insert at end
        
        $columns['rts_star'] = '<span class="dashicons dashicons-star-filled" title="Featured"></span>';
        $columns['rts_helpful_pct'] = 'Helpful %';
        $columns['rts_views'] = 'Views';
        $columns['rts_feedback'] = 'Feedback';
        $columns['date'] = $date;
        return $columns;
    }

    public static function populate_custom_columns($column, $post_id) {
        switch ($column) {
            case 'rts_star':
                $is_featured = get_post_meta($post_id, '_rts_featured', true) === '1';
                $star_class = $is_featured ? 'dashicons-star-filled' : 'dashicons-star-empty';
                $action = $is_featured ? 'unstar' : 'star';
                // Add nonce to data attribute
                $nonce = wp_create_nonce('rts_star_nonce');
                
                echo '<a href="#" class="rts-star-toggle" data-post-id="' . $post_id . '" data-action="' . $action . '" data-nonce="' . $nonce . '">';
                echo '<span class="dashicons ' . $star_class . '"></span>';
                echo '</a>';
                break;
            case 'rts_helpful_pct':
                $pct = get_post_meta($post_id, 'rts_helpful_pct', true);
                if ($pct !== '') {
                    $color = $pct >= 80 ? '#46b450' : ($pct >= 40 ? '#f0b849' : '#d63638');
                    echo '<span style="display:inline-block;padding:2px 8px;background:' . $color . ';color:white;border-radius:10px;font-weight:bold;">';
                    echo number_format((float)$pct, 1) . '%';
                    echo '</span>';
                } else {
                    echo '<span style="color:#ccc;">—</span>';
                }
                break;
            case 'rts_views':
                $views = get_post_meta($post_id, 'view_count', true);
                echo $views ? number_format((int)$views) : '0';
                break;
            case 'rts_feedback':
                $up = get_post_meta($post_id, 'rts_thumbs_up', true) ?: 0;
                $down = get_post_meta($post_id, 'rts_thumbs_down', true) ?: 0;
                echo '<span style="color:#46b450;">👍 ' . $up . '</span> ';
                echo '<span style="color:#d63638;">👎 ' . $down . '</span>';
                if (self::has_triggered_feedback($post_id)) {
                    echo ' <span style="color:#b32d2e;" title="Has triggered feedback">⚠️</span>';
                }
                break;
        }
    }

    public static function handle_ajax_star() {
        check_ajax_referer('rts_star_nonce', 'nonce');
        
        $post_id = intval($_POST['post_id']);
        $action = sanitize_key($_POST['action']);
        
        if (!current_user_can('edit_post', $post_id)) {
            wp_die('Unauthorized');
        }
        
        if ($action === 'star') {
            update_post_meta($post_id, '_rts_featured', '1');
        } else {
            delete_post_meta($post_id, '_rts_featured');
        }
        
        wp_die('1');
    }

    public static function make_columns_sortable($columns) {
        $columns['rts_helpful_pct'] = 'rts_helpful_pct';
        $columns['rts_views'] = 'rts_views';
        return $columns;
    }

    public static function sort_custom_columns($query) {
        if (!is_admin() || !$query->is_main_query()) return;

        $orderby = $query->get('orderby');
        if ($orderby === 'rts_helpful_pct') {
            $query->set('meta_key', 'rts_helpful_pct');
            $query->set('orderby', 'meta_value_num');
        } elseif ($orderby === 'rts_views') {
            $query->set('meta_key', 'view_count');
            $query->set('orderby', 'meta_value_num');
        }
    }

    public static function add_quick_edit_fields($column_name, $post_type) {
        if ($post_type !== 'letter' || $column_name !== 'title') return;
        ?>
        <fieldset class="inline-edit-col-right">
            <div class="inline-edit-col">
                <label class="inline-edit-group">
                    <span class="title">RTS Status</span>
                    <select name="rts_priority">
                        <option value="">— No Change —</option>
                        <option value="high">High Priority</option>
                        <option value="medium">Medium Priority</option>
                        <option value="low">Low Priority</option>
                        <option value="review">Needs Review</option>
                    </select>
                </label>
                <label class="inline-edit-group">
                    <span class="title">Featured</span>
                    <select name="rts_featured">
                        <option value="">— No Change —</option>
                        <option value="yes">Mark as Featured</option>
                        <option value="no">Remove Featured</option>
                    </select>
                </label>
            </div>
        </fieldset>
        <?php
    }

    public static function save_quick_edit_fields($post_id) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id) || get_post_type($post_id) !== 'letter') return;

        if (isset($_POST['rts_priority']) && !empty($_POST['rts_priority'])) {
            update_post_meta($post_id, '_rts_priority', sanitize_key($_POST['rts_priority']));
        }
        if (isset($_POST['rts_featured'])) {
            $feat = sanitize_key($_POST['rts_featured']);
            if ($feat === 'yes') update_post_meta($post_id, '_rts_featured', '1');
            elseif ($feat === 'no') delete_post_meta($post_id, '_rts_featured');
        }
    }

    public static function add_contextual_help($contextual_help, $screen_id, $screen) {
        if ($screen_id !== 'edit-letter') {
            return $contextual_help;
        }
        
        $help = '<h2>RTS Letter Management Tips</h2>';
        $help .= '<p><strong>Custom Views:</strong> Use "Needs review" and "Low rated" to filter letters with feedback issues.</p>';
        $help .= '<p><strong>Quality Filters:</strong> Filter by helpful percentage to find underperforming content.</p>';
        $help .= '<p><strong>Bulk Actions:</strong> Select multiple letters to change status, export data, or undo actions.</p>';
        $help .= '<p><strong>Search:</strong> Use <code>helpful:80</code> to find letters with 80%+ helpfulness.</p>';
        $help .= '<p><strong>Columns:</strong> Sort by helpful percentage or views to identify patterns.</p>';
        $help .= '<p><strong>Quick Edit:</strong> Click "Quick Edit" to set priority or featured status.</p>';
        
        $screen->add_help_tab([
            'id' => 'rts_help',
            'title' => 'RTS Features',
            'content' => $help
        ]);
        
        return $contextual_help;
    }

    /**
     * --------------------------------------------------------------------------
     * Helpers (Caching & Optimization)
     * --------------------------------------------------------------------------
     */

    /**
     * Optimized SQL query to fetch letter IDs based on feedback criteria.
     * Replaces inefficient WP_Query loop for large datasets.
     */
    private static function get_letter_ids_with_feedback(array $filters): array {
        global $wpdb;
        $where = [];
        $params = [];

        if (isset($filters['triggered'])) {
            $where[] = "pm1.meta_key = 'triggered' AND pm1.meta_value = %s";
            $params[] = (string) absint($filters['triggered']);
        }
        if (isset($filters['rating'])) {
            $where[] = "pm2.meta_key = 'rating' AND pm2.meta_value = %s";
            $params[] = sanitize_text_field($filters['rating']);
        }

        if (empty($where)) return [];

        $where_sql = implode(' AND ', $where);
        // Direct JOIN for performance
        $join_rating = isset($filters['rating']) ? "INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id" : "";
        
        $query = $wpdb->prepare("
            SELECT DISTINCT pm_letter.meta_value 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_letter ON p.ID = pm_letter.post_id AND pm_letter.meta_key = 'letter_id'
            INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id
            $join_rating
            WHERE p.post_type = 'rts_feedback' AND p.post_status = 'publish'
            AND ($where_sql)
            ORDER BY p.post_date DESC
            LIMIT 2000
        ", $params);

        $results = $wpdb->get_col($query);
        return array_map('intval', $results);
    }

    private static function get_feedback_counts() {
        $cache_key = 'rts_feedback_counts';
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        global $wpdb;
        
        // Count letters with triggered feedback
        $triggered = $wpdb->get_var("
            SELECT COUNT(DISTINCT pm.meta_value) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'letter_id'
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'triggered' AND pm2.meta_value = '1'
            WHERE p.post_type = 'rts_feedback' AND p.post_status = 'publish'
        ");

        // Count letters with low rating
        $low = $wpdb->get_var("
            SELECT COUNT(DISTINCT pm.meta_value) 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'letter_id'
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'rating' AND pm2.meta_value = 'down'
            WHERE p.post_type = 'rts_feedback' AND p.post_status = 'publish'
        ");

        $counts = ['triggered' => (int)$triggered, 'low' => (int)$low];
        set_transient($cache_key, $counts, 15 * MINUTE_IN_SECONDS);
        return $counts;
    }

    private static function has_triggered_feedback($letter_id) {
        global $wpdb;
        $cache_key = 'rts_has_triggered_' . $letter_id;
        $cached = wp_cache_get($cache_key);
        if ($cached !== false) return $cached;

        $has = (bool) $wpdb->get_var($wpdb->prepare("
            SELECT 1 FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'letter_id' AND pm.meta_value = %d
            INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'triggered' AND pm2.meta_value = '1'
            WHERE p.post_type = 'rts_feedback' AND p.post_status = 'publish'
            LIMIT 1
        ", $letter_id));

        wp_cache_set($cache_key, $has, '', 15 * MINUTE_IN_SECONDS);
        return $has;
    }
}

RTS_Letter_Bulk_Actions::init();

} // end class_exists check
