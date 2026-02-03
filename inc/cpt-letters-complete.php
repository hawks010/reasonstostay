<?php
/**
 * RTS Letters CPT - COMPLETE OVERHAUL
 * Dashboard stats at top, smart filters, clean columns, auto-processing controls
 * FIXED: Row actions, AJAX loop, SQL Security, Caching, Capabilities, Bulk Actions.
 * POLISHED: Helper methods, Logging, Dynamic Throttling, Memory Management.
 * UPDATED: Added sorting logic, enhanced security checks, relaxed permissions, and URL escaping.
 * LATEST FIX: Added 'map_meta_cap' to fix Trash/Bin failure. Removed aggressive cache flushing.
 * DASHBOARD INTEGRATION: Added clickable stat cards and quick filters for Inbox/Quarantine.
 * RENAMED: Class changed to RTS_CPT_Letters_System to avoid conflicts with legacy files.
 */

if (!defined('ABSPATH')) exit;

// Prevent Fatal Error: Check for new class name
if ( ! class_exists( 'RTS_CPT_Letters_System' ) ) :

class RTS_CPT_Letters_System {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_taxonomies']);
        
        // Meta boxes
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_letter', [$this, 'save_meta_boxes']);

        // CLEAN columns
        add_filter('manage_letter_posts_columns', [$this, 'set_columns']);
        add_action('manage_letter_posts_custom_column', [$this, 'render_column'], 10, 2);
        add_filter('manage_edit-letter_sortable_columns', [$this, 'sortable_columns']);
        
        // Sorting Logic (Fix: Actually handle the sorting)
        add_action('pre_get_posts', [$this, 'handle_sorting']);
        
        // Quick Filters (Inbox / Quarantine)
        add_action('pre_get_posts', [$this, 'apply_quick_filters']);

        // Dashboard/legacy processing UI removed (migrated to RTS Dashboard).
        // New: lightweight analytics header on the Letters list screen (powered by the Moderation Engine).
        add_action('all_admin_notices', [$this, 'show_letters_analytics_header']);

        // Ensure buttons in the analytics header work on Letters admin screens.
        add_action('admin_enqueue_scripts', [$this, 'enqueue_letters_admin_assets']);
        // Admin actions
        add_action('admin_post_rts_run_analytics_now', [$this, 'handle_run_analytics_now']);
        add_action('admin_post_rts_rescan_letters', [$this, 'handle_rescan_letters']);
        // Back-compat: analytics header button (Scan Pending & Quarantine)
        add_action('admin_post_rts_rescan_pending_letters', [$this, 'handle_rescan_pending_letters']);
        // Quick unflag from edit screen
        add_action('admin_post_rts_quick_unflag', [$this, 'handle_quick_unflag']);
        // Quick approve from list table
        add_action('admin_post_rts_quick_approve', [$this, 'handle_quick_approve']);
        
        // Smart filters
        add_action('restrict_manage_posts', [$this, 'add_smart_filters']);
        add_filter('parse_query', [$this, 'filter_by_custom_fields']);
        
        // Custom views (Triggered, Low Quality, etc.)
        add_filter('views_edit-letter', [$this, 'add_custom_views']);
        
        // Row actions
        add_filter('post_row_actions', [$this, 'row_actions'], 10, 2);
        
        // Bulk Actions
        add_filter('bulk_actions-edit-letter', [$this, 'register_bulk_actions']);
        add_filter('handle_bulk_actions-edit-letter', [$this, 'handle_bulk_actions'], 10, 3);
        // Legacy AJAX processing removed (handled by RTS Moderation Engine via Action Scheduler).
        // Background bulk processing handler (Action Scheduler / WP-Cron).
        add_action('rts_bulk_process_letters', [$this, 'process_bulk_action_batch'], 10, 3);
        
        // Custom CSS
        add_action('admin_head', [$this, 'admin_css']);
        // Legacy quick-approve removed.
    }
    
    public function register_post_type() {
        // One source of truth: if something else already registered the post type,
        // don't register it again (prevents duplicate menus / conflicting args).
        if (post_type_exists('letter')) {
            return;
        }

        register_post_type('letter', [
            'labels' => [
                'name' => 'Letters',
                'singular_name' => 'Letter',
                'add_new' => 'Add Letter',
                'add_new_item' => 'Add New Letter',
                'edit_item' => 'Edit Letter',
                'new_item' => 'New Letter',
                'view_item' => 'View Letter',
                'search_items' => 'Search Letters',
                'not_found' => 'No letters found',
                'not_found_in_trash' => 'No letters found in Trash',
                'menu_name' => 'Letters'
            ],
            'public' => true,
            'show_ui' => true,
            'menu_position' => 5,
            'menu_icon' => 'dashicons-email-alt',
            'supports' => ['title', 'editor', 'author', 'custom-fields', 'thumbnail'],
            'capability_type' => 'post',
            // CRITICAL FIX: map_meta_cap is required for capability_type='post' to work correctly
            // This ensures 'delete_post' maps to 'delete_posts' so admins can actually Trash items.
            'map_meta_cap' => true,
        ]);
        
        // Register custom post status for quarantine (completely separate from WP's draft)
        register_post_status('rts-quarantine', [
            'label'                     => _x('Quarantined', 'post status', 'rts'),
            'public'                    => false,
            'internal'                  => true,
            'exclude_from_search'       => true,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'Quarantined <span class="count">(%s)</span>',
                'Quarantined <span class="count">(%s)</span>',
                'rts'
            ),
        ]);
    }
    
    public function register_taxonomies() {
        register_taxonomy('letter_feeling', 'letter', [
            'label' => 'Feelings',
            'labels' => [
                'name' => 'Feelings',
                'singular_name' => 'Feeling',
                'all_items' => 'All Feelings',
                'edit_item' => 'Edit Feeling',
                'add_new_item' => 'Add New Feeling',
            ],
            'public' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'hierarchical' => true,
            'show_in_quick_edit' => true,
            'meta_box_cb' => 'post_categories_meta_box',
        ]);
        
        register_taxonomy('letter_tone', 'letter', [
            'label' => 'Tone',
            'labels' => [
                'name' => 'Tone',
                'singular_name' => 'Tone',
                'all_items' => 'All Tones',
                'edit_item' => 'Edit Tone',
                'add_new_item' => 'Add New Tone',
            ],
            'public' => false,
            'show_ui' => true,
            'show_admin_column' => true,
            'hierarchical' => false,
            'show_in_quick_edit' => true,
        ]);
    }
    /**
     * Meta Boxes for Author, Reading Time, and Manual Stats
     */
    public function add_meta_boxes() {
        add_meta_box(
            'rts_letter_meta',
            'Letter Details',
            [$this, 'render_meta_box'],
            'letter',
            'side',
            'default'
        );
        
        // Quick approve/unflag meta box (only shows for quarantined letters)
        add_meta_box(
            'rts_quick_approve',
            'Quarantine Status',
            [$this, 'render_quick_approve_box'],
            'letter',
            'side',
            'high'
        );
    }

    public function render_meta_box($post) {
        wp_nonce_field('rts_letter_meta_save', 'rts_letter_meta_nonce');

        $author_name  = get_post_meta($post->ID, 'author_name', true);
        $author_email = get_post_meta($post->ID, 'author_email', true);
        $reading_time = get_post_meta($post->ID, 'reading_time', true);
        $view_count   = get_post_meta($post->ID, 'view_count', true);
        $help_count   = get_post_meta($post->ID, 'help_count', true);

        $reading_opts = [
            '' => 'Auto',
            'short' => 'Short (under 1 min)',
            'medium' => 'Medium (1-3 mins)',
            'long' => 'Long (3+ mins)',
        ];

        echo '<p><label for="rts_author_name"><strong>Author name</strong></label><br>';
        echo '<input type="text" id="rts_author_name" name="rts_author_name" value="' . esc_attr($author_name) . '" style="width:100%;" /></p>';

        echo '<p><label for="rts_author_email"><strong>Author email</strong></label><br>';
        echo '<input type="email" id="rts_author_email" name="rts_author_email" value="' . esc_attr($author_email) . '" style="width:100%;" /></p>';

        echo '<p><label for="rts_reading_time"><strong>Reading time</strong></label><br>';
        echo '<select id="rts_reading_time" name="rts_reading_time" style="width:100%;">';
        foreach ($reading_opts as $val => $label) {
            echo '<option value="' . esc_attr($val) . '"' . selected($reading_time, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p>';

        echo '<p style="font-size:12px;color:#666;">If Reading time is set to Auto, the system will estimate it from the letter length.</p>';

        echo '<hr style="margin: 15px 0; border: 0; border-top: 1px solid #ddd;">';

        echo '<p style="margin-bottom: 10px;"><strong>Manual Stats Override</strong></p>';
        echo '<p style="font-size:12px;color:#666;margin-bottom:10px;">Use these fields when importing letters from Wix to preserve view/help counts. Leave blank for new letters.</p>';

        echo '<p><label for="rts_view_count"><strong>View Count</strong></label><br>';
        echo '<input type="number" id="rts_view_count" name="rts_view_count" value="' . esc_attr($view_count) . '" min="0" style="width:100%;" placeholder="0" />';
        echo '<span style="font-size:11px;color:#999;">Number of times this letter was viewed</span></p>';

        echo '<p><label for="rts_help_count"><strong>Help Count</strong></label><br>';
        echo '<input type="number" id="rts_help_count" name="rts_help_count" value="' . esc_attr($help_count) . '" min="0" style="width:100%;" placeholder="0" />';
        echo '<span style="font-size:11px;color:#999;">Number of "This helped" clicks</span></p>';
    }

    public function save_meta_boxes($post_id) {
        if (!isset($_POST['rts_letter_meta_nonce']) || !wp_verify_nonce($_POST['rts_letter_meta_nonce'], 'rts_letter_meta_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $author_name  = isset($_POST['rts_author_name']) ? sanitize_text_field($_POST['rts_author_name']) : '';
        $author_email = isset($_POST['rts_author_email']) ? sanitize_email($_POST['rts_author_email']) : '';
        $reading_time = isset($_POST['rts_reading_time']) ? sanitize_key($_POST['rts_reading_time']) : '';

        if ($author_email && !is_email($author_email)) $author_email = '';

        update_post_meta($post_id, 'author_name', $author_name);
        update_post_meta($post_id, 'author_email', $author_email);

        // If set explicitly, store it. If blank/auto, allow other systems to set it.
        if ($reading_time === '') {
            delete_post_meta($post_id, 'reading_time');
        } else {
            update_post_meta($post_id, 'reading_time', $reading_time);
        }

        // Manual stats override (for Wix imports)
        if (isset($_POST['rts_view_count'])) {
            $view_count = intval($_POST['rts_view_count']);
            if ($view_count >= 0) {
                update_post_meta($post_id, 'view_count', $view_count);
            }
        }

        if (isset($_POST['rts_help_count'])) {
            $help_count = intval($_POST['rts_help_count']);
            if ($help_count >= 0) {
                update_post_meta($post_id, 'help_count', $help_count);
            }
        }
    }

    /**
     * Render Quick Approve Box for Quarantined Letters
     */
    public function render_quick_approve_box($post) {
        $needs_review = get_post_meta($post->ID, 'needs_review', true);
        $is_quarantined = ($needs_review === '1' && $post->post_status === 'draft');
        
        if (!$is_quarantined) {
            echo '<p style="color:#155724;background:#d4edda;padding:10px;border-radius:4px;margin:0;">✓ This letter is not quarantined.</p>';
            return;
        }
        
        $flag_reasons = get_post_meta($post->ID, 'rts_flag_reasons', true);
        $reasons = $flag_reasons ? json_decode($flag_reasons, true) : [];
        
        echo '<div style="background:#fff3cd;padding:10px;border-left:3px solid #856404;margin-bottom:10px;">';
        echo '<p style="margin:0 0 5px 0;font-weight:600;color:#856404;">⚠ Quarantined</p>';
        
        if (!empty($reasons)) {
            echo '<p style="margin:0;font-size:12px;color:#666;">Flagged for:</p>';
            echo '<ul style="margin:5px 0 0 0;padding-left:20px;font-size:11px;color:#666;">';
            foreach (array_slice($reasons, 0, 5) as $reason) {
                echo '<li>' . esc_html($reason) . '</li>';
            }
            if (count($reasons) > 5) {
                echo '<li><em>+' . (count($reasons) - 5) . ' more...</em></li>';
            }
            echo '</ul>';
        }
        echo '</div>';
        
        // Quick approve button
        $approve_url = wp_nonce_url(
            admin_url('admin-post.php?action=rts_quick_unflag&post=' . $post->ID),
            'unflag_' . $post->ID
        );
        
        echo '<a href="' . esc_url($approve_url) . '" class="button button-primary" style="width:100%;text-align:center;margin-bottom:8px;">';
        echo '✓ Clear Quarantine & Re-scan';
        echo '</a>';
        
        echo '<p style="font-size:11px;color:#666;margin:8px 0 0 0;line-height:1.4;">';
        echo 'This will clear the quarantine flag, set status to pending, and queue the letter for a fresh scan.';
        echo '</p>';
        
        echo '<hr style="margin:12px 0;border:0;border-top:1px solid #ddd;">';
        
        echo '<a href="' . esc_url(admin_url('edit.php?post_type=letter&rts_quarantine=1')) . '" class="button" style="width:100%;text-align:center;">';
        echo 'View All Quarantined';
        echo '</a>';
    }

    /**
     * Helper: Generate consistent cache key
     */
    private function get_cache_key($type) {
        return 'rts_count_' . $type . '_' . get_current_user_id();
    }

    /**
     * Helper: Get cached counts safely (Fixes #2, #3, #4, #5, #6)
     */
    private function get_count($type) {
        global $wpdb;
        $cache_key = $this->get_cache_key($type);
        $count = get_transient($cache_key);

        if ($count === false) {
            $query = '';
            switch ($type) {
                case 'unrated':
                    $query = $wpdb->prepare(
                        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s WHERE p.post_type = %s AND p.post_status IN ('publish', 'pending') AND pm.meta_id IS NULL",
                        'quality_score', 'letter'
                    );
                    break;
                case 'needs_review':
                    $min_score = (int) get_option('rts_min_quality_score', 25);
                    $min_score = max(0, min(100, $min_score));
                    $query = $wpdb->prepare(
                        "SELECT COUNT(DISTINCT p.ID)
                         FROM {$wpdb->posts} p
                         LEFT JOIN {$wpdb->postmeta} q ON p.ID = q.post_id AND q.meta_key = %s
                         LEFT JOIN {$wpdb->postmeta} n ON p.ID = n.post_id AND n.meta_key = %s
                         WHERE p.post_type = %s
                        AND p.post_status IN ('draft', 'rts-quarantine')
                        AND (
                            (n.meta_value = %s)
                            OR (q.meta_id IS NOT NULL AND CAST(q.meta_value AS UNSIGNED) < %d)
                         )",
                        'quality_score', 'needs_review', 'letter', 'draft', '1', $min_score
                    );
                    break;
                case 'flagged':
                    $query = $wpdb->prepare(
                        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s WHERE p.post_type = %s AND p.post_status IN ('publish', 'pending') AND pm.meta_value = %s",
                        'needs_review', 'letter', '1'
                    );
                    break;
                case 'low_quality':
                    // Back-compat: low_quality is now part of needs_review.
                    return $this->get_count('needs_review');
                default:
                    return 0;
            }
            
            $result = $wpdb->get_var($query);
            
            if ($wpdb->last_error) {
                error_log("RTS CPT: Database error for $type count: " . $wpdb->last_error);
                return 0; // Fail gracefully on DB error
            }
            
            $count = (int) $result;
            set_transient($cache_key, $count, 5 * MINUTE_IN_SECONDS);
        }
        return $count;
    }
    
    /**
     * DASHBOARD STATS + PROCESS BUTTON at top of All Letters
     */

    public function show_letters_analytics_header() {
        if (!is_admin()) return;
        if (!current_user_can('manage_options')) return;
        if (!$this->is_letters_screen()) return;
		// Source of truth: live counts (avoid cache drift between screens)
		$counts            = wp_count_posts('letter');
		$letters_published = (int) ($counts->publish ?? 0);
		$letters_pending   = (int) ($counts->pending ?? 0);
		$letters_draft     = (int) ($counts->draft ?? 0);
		$letters_future    = (int) ($counts->future ?? 0);
		$letters_private   = (int) ($counts->private ?? 0);
		$letters_quarantine_status = (int) ($counts->{'rts-quarantine'} ?? 0);
        $needs_review      = (int) $this->count_needs_review_live();
		$letters_quarantine = $needs_review;
		// Match RTS Moderation Engine "Total" across admin screens.
		$letters_total     = $letters_published + $letters_pending + $letters_draft + $letters_future + $letters_private;
        $feedback_total    = (int) wp_count_posts('rts_feedback')->publish + (int) wp_count_posts('rts_feedback')->pending;
        $generated_gmt     = '';

        $true_inbox        = max(0, $letters_pending - $needs_review);

        $run_analytics_url = wp_nonce_url(admin_url('admin-post.php?action=rts_run_analytics_now'), 'rts_run_analytics_now');
        $rescan_url        = wp_nonce_url(admin_url('admin-post.php?action=rts_rescan_pending_letters'), 'rts_rescan_pending_letters');
        $rts_dashboard_url = admin_url('admin.php?page=rts-dashboard');

        ?>
        <style>
            .rts-analytics-container {
                margin: 16px 0 10px;
            }
            .rts-analytics-box {
                background: #fff;
                border: 1px solid #dcdcde;
                border-radius: 12px;
                padding: 20px;
                max-width: 1400px;
            }
            .rts-analytics-header {
                display: flex;
                gap: 16px;
                flex-wrap: wrap;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 20px;
            }
            .rts-analytics-title {
                font-size: 18px;
                font-weight: 700;
                line-height: 1.2;
                color: #1d2327;
            }
            .rts-analytics-subtitle {
                color: #646970;
                font-size: 12px;
                margin-top: 4px;
            }
            .rts-analytics-actions {
                display: flex;
                gap: 10px;
                flex-wrap: wrap;
                align-items: center;
            }
            .rts-analytics-actions .button {
                height: 36px;
                line-height: 34px;
                padding: 0 16px;
                font-size: 13px;
                font-weight: 500;
                border-radius: 6px;
                text-decoration: none;
                transition: all 0.2s ease;
            }
            .rts-analytics-actions .button:hover {
                transform: translateY(-1px);
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }
            .rts-analytics-actions .button-primary {
                background: #2271b1;
                border-color: #2271b1;
                color: #fff;
            }
            .rts-analytics-actions .button-primary:hover {
                background: #135e96;
                border-color: #135e96;
            }
            .rts-analytics-actions .button-secondary {
                background: #f6f7f7;
                border-color: #dcdcde;
                color: #2c3338;
            }
            .rts-analytics-actions .button-secondary:hover {
                background: #f0f0f1;
                border-color: #8c8f94;
            }
            .rts-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
                gap: 14px;
            }
            @media (min-width: 1200px) {
                .rts-stats-grid {
                    grid-template-columns: repeat(5, 1fr);
                }
            }
            .rts-stat-box {
                background: linear-gradient(135deg, var(--stat-bg-start), var(--stat-bg-end));
                border: 1px solid var(--stat-border);
                border-radius: 10px;
                padding: 18px 16px;
                text-align: center;
                transition: all 0.2s ease;
                cursor: pointer;
                display: block;
                text-decoration: none;
            }
            .rts-stat-box:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            }
            .rts-stat-label {
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: var(--stat-label-color);
                margin-bottom: 8px;
                opacity: 0.85;
            }
            .rts-stat-value {
                font-size: 32px;
                font-weight: 800;
                line-height: 1;
                color: var(--stat-value-color);
                font-variant-numeric: tabular-nums;
            }
        </style>
        <div class="rts-analytics-container">
            <div class="rts-analytics-box">
                <div class="rts-analytics-header rts-letters-analytics">
                    <div>
                        <div class="rts-analytics-title">Letters Analytics</div>
                        <div class="rts-analytics-subtitle">Powered by RTS Moderation Engine<?php echo $generated_gmt ? ' • cached ' . esc_html($generated_gmt) : ''; ?></div>
                    </div>
                    <div class="rts-analytics-actions">
                        <a class="button" href="<?php echo esc_url($rts_dashboard_url); ?>">
                            <span class="dashicons dashicons-chart-bar" aria-hidden="true"></span>
                            Open Dashboard
                        </a>
                        <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=letter&rts_inbox=1')); ?>">
                            <span class="dashicons dashicons-email" aria-hidden="true"></span>
                            Review Inbox
                        </a>
                        <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=letter&rts_quarantine=1')); ?>">
                            <span class="dashicons dashicons-shield" aria-hidden="true"></span>
                            View Quarantine
                        </a>
                        <button type="button" class="button button-primary" id="rts-scan-inbox-btn">
                            <span class="dashicons dashicons-search" aria-hidden="true"></span>
                            Scan Inbox Now
                        </button>
                        <button type="button" class="button" id="rts-rescan-quarantine-btn">
                            <span class="dashicons dashicons-update" aria-hidden="true"></span>
                            Rescan Quarantine
                        </button>
                        <button type="button" class="button button-secondary" id="rts-refresh-status-btn">
                            <span class="dashicons dashicons-update" aria-hidden="true"></span>
                            Refresh Status
                        </button>
                    </div>
                </div>

                <div class="rts-stats-grid">
                    <?php $this->render_stat_box('Total', $letters_total, admin_url('edit.php?post_type=letter'), '#1d2327', '#f0f0f1', '#dcdcde'); ?>
                    <?php $this->render_stat_box('Published', $letters_published, admin_url('edit.php?post_type=letter&post_status=publish'), '#0B3D2E', '#E7F7EF', '#b8e6d0'); ?>
                    <?php $this->render_stat_box('Inbox', $true_inbox, admin_url('edit.php?post_type=letter&rts_inbox=1'), '#6B4E00', '#FFF4CC', '#f5e5a3'); ?>
                    <?php $this->render_stat_box('Quarantined', $needs_review, admin_url('edit.php?post_type=letter&rts_quarantine=1'), '#5B1B1B', '#FCE8E8', '#f5c2c2'); ?>
                    <?php $this->render_stat_box('Feedback', $feedback_total, admin_url('edit.php?post_type=rts_feedback'), '#1B3D5B', '#E8F3FC', '#b8d9f2'); ?>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Detect whether we are on the Letters list table screen.
     *
     * WordPress screen IDs:
     * - edit-letter: list table (edit.php?post_type=letter)
     * - letter: single edit screen (post.php?post=ID&action=edit)
     *
     * We only want the analytics header on the list table.
     */
    private function is_letters_screen(): bool {
        if (!is_admin()) {
            return false;
        }

        // get_current_screen() is not available on some early hooks.
        if (function_exists('get_current_screen')) {
            $screen = get_current_screen();
            if ($screen && !empty($screen->id) && $screen->id === 'edit-letter') {
                return true;
            }
        }

        // Fallback detection (works on most admin requests).
        global $pagenow;
        $post_type = isset($_GET['post_type']) ? sanitize_key((string) $_GET['post_type']) : '';

        // Only render the analytics header on Letters admin screens.
        if ($pagenow !== 'edit.php' || $post_type !== 'letter') {
            return false;
        }

        // Do NOT render the analytics header on the Moderation Dashboard itself (prevents duplicate stats blocks).
        $page = isset($_GET['page']) ? sanitize_key((string) $_GET['page']) : '';
        if ($page === 'rts-dashboard') {
            return false;
        }

        return true;
    }

    /**
     * Enqueue the Moderation Dashboard JS/CSS on Letters admin screens so the
     * analytics header buttons (scan/rescan/refresh) work consistently.
     */
    public function enqueue_letters_admin_assets(string $hook): void {
        if (!$this->is_letters_screen()) {
            return;
        }

        // IMPORTANT: Do NOT enqueue the heavy rts-admin.css here.
        // This list screen should keep WordPress' native table styles.
        // The analytics header above already includes its own scoped inline CSS.

        $js_path  = get_stylesheet_directory() . '/assets/js/rts-dashboard.js';
        if (file_exists($js_path)) {
            $ver = (string) filemtime($js_path);
            wp_enqueue_script('rts-dashboard-js', get_stylesheet_directory_uri() . '/assets/js/rts-dashboard.js', ['jquery'], $ver, true);
            wp_localize_script('rts-dashboard-js', 'rtsDashboard', [
                'ajaxurl' => admin_url('admin-ajax.php'),
                'resturl' => rest_url('rts/v1/'),
                'nonce'   => wp_create_nonce('wp_rest'),
                'dashboard_nonce' => wp_create_nonce('rts_dashboard_nonce')
            ]);
        }
    }

    private function count_needs_review_live(): int {
        global $wpdb;
        try {
            // Count quarantined letters (draft or rts-quarantine status with needs_review flag).
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = %s
                   AND p.post_status IN ('draft', 'rts-quarantine')
                   AND pm.meta_key = %s
                   AND pm.meta_value = %s",
                'letter',
                'draft',
                'needs_review',
                '1'
            ));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function render_stat_box(string $label, int $val, string $url, string $value_color, string $bg_start, string $border): void {
        $bg_end = $bg_start; // Can be different for gradient effect
        ?>
        <a href="<?php echo esc_url($url); ?>" class="rts-stat-box" style="--stat-bg-start:<?php echo esc_attr($bg_start); ?>;--stat-bg-end:<?php echo esc_attr($bg_end); ?>;--stat-border:<?php echo esc_attr($border); ?>;--stat-label-color:<?php echo esc_attr($value_color); ?>;--stat-value-color:<?php echo esc_attr($value_color); ?>;">
            <div class="rts-stat-label"><?php echo esc_html($label); ?></div>
            <div class="rts-stat-value"><?php echo esc_html(number_format_i18n($val)); ?></div>
        </a>
        <?php
    }
    public function handle_run_analytics_now(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('rts_run_analytics_now');

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('rts_aggregate_analytics', [], 'rts');
        } else {
            // Safe fallback: run inline (fast) if Action Scheduler is missing.
            if (class_exists('RTS_Analytics_Aggregator')) {
                RTS_Analytics_Aggregator::aggregate();
            }
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=letter'));
        exit;
    }

    /**
     * Admin-post handler for the "Scan Pending & Quarantine" button in the Letters list header.
     *
     * Why this exists:
     * - The analytics header builds a URL to admin-post.php?action=rts_rescan_pending_letters
     * - On some builds the action hook was never registered, so WP core falls back to wp_die() (admin-post.php:71)
     *
     * This handler is intentionally lightweight: it only queues work (Action Scheduler when available,
     * WP-Cron fallback) and then redirects back.
     */
    public function handle_rescan_pending_letters(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('rts_rescan_pending_letters');

        // Use the engine batch size when present, otherwise sane default.
        $batch = 50;
        if (class_exists('RTS_Engine_Dashboard')) {
            $batch = (int) get_option(RTS_Engine_Dashboard::OPTION_AUTO_BATCH, 50);
        }
        $batch = max(1, min(250, $batch));

        // Queue pending letters + needs_review letters.
        $q_args = [
            'post_type'      => 'letter',
            'posts_per_page' => $batch,
            'fields'         => 'ids',
            'orderby'        => 'date',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ];

        $pending = new \WP_Query(array_merge($q_args, ['post_status' => 'pending']));
        if (!empty($pending->posts)) {
            foreach ($pending->posts as $id) {
                $this->queue_letter_for_processing((int) $id);
            }
        }

        $needs = new \WP_Query(array_merge($q_args, [
            'post_status' => ['publish', 'pending', 'draft'],
            'meta_query'  => [
                [
                    'key'   => 'needs_review',
                    'value' => '1',
                ],
            ],
        ]));
        if (!empty($needs->posts)) {
            foreach ($needs->posts as $id) {
                $this->queue_letter_for_processing((int) $id);
            }
        }

        // Kick quarantine loop as well (runs in chunks).
        $this->kick_quarantine_loop($batch);

        // Refresh analytics after queueing.
        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action('rts_aggregate_analytics', [], 'rts');
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=letter'));
        exit;
    }

    /**
     * Queue a single letter for processing (Action Scheduler preferred, WP-Cron fallback).
     */
    private function queue_letter_for_processing(int $post_id): void {
        if ($post_id <= 0 || get_post_type($post_id) !== 'letter') {
            return;
        }

        // Prefer Action Scheduler when available.
        if ($this->as_available()) {
            if (!$this->has_scheduled_action('rts_process_letter', [$post_id])) {
                as_schedule_single_action(time() + 2, 'rts_process_letter', [$post_id], 'rts');
            }
            return;
        }

        // Fallback: WP-Cron single event.
        if (!wp_next_scheduled('rts_wpcron_process_letter', [$post_id])) {
            wp_schedule_single_event(time() + 10, 'rts_wpcron_process_letter', [$post_id]);
        }
    }

    /**
     * Start / continue the quarantine rescan loop.
     */
    private function kick_quarantine_loop(int $batch): void {
        $batch = max(1, min(250, $batch));

        if ($this->as_available()) {
            if (!$this->has_scheduled_action('rts_rescan_quarantine_loop', [0, $batch])) {
                as_schedule_single_action(time() + 5, 'rts_rescan_quarantine_loop', [0, $batch], 'rts');
            }
            return;
        }

        if (!wp_next_scheduled('rts_rescan_quarantine_loop', [0, $batch])) {
            wp_schedule_single_event(time() + 15, 'rts_rescan_quarantine_loop', [0, $batch]);
        }
    }

    public function handle_enqueue_letter_rescan(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }
        check_admin_referer('rts_enqueue_letter_rescan');

        if (!function_exists('as_schedule_single_action')) {
            wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=letter'));
            exit;
        }

        global $wpdb;

        // Rescan up to 300 letters that are pending or marked needs_review=1.
        $limit = 300;

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT p.ID
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm
                   ON pm.post_id = p.ID AND pm.meta_key = 'needs_review'
                 WHERE p.post_type = %s
                   AND (p.post_status = %s OR pm.meta_value = %s)
                 ORDER BY p.ID DESC
                 LIMIT %d",
                'letter',
                'pending',
                '1',
                $limit
            )
        );

        if (is_array($ids)) {
            $scheduled = 0;
            foreach ($ids as $id) {
                $id = (int) $id;
                if ($id <= 0) continue;

                // Avoid duplicates
                $already = function_exists('as_next_scheduled_action')
                    ? as_next_scheduled_action('rts_process_letter', [$id], 'rts')
                    : false;

                if (!$already) {
                    as_schedule_single_action(time() + 5, 'rts_process_letter', [$id], 'rts');
                    $scheduled++;
                }
            }

            // Also refresh analytics after rescans start.
            as_enqueue_async_action('rts_aggregate_analytics', [], 'rts');

            update_option('rts_last_admin_action', [
                'time' => gmdate('c'),
                'action' => 'rescan_pending_letters',
                'scheduled' => (int) $scheduled,
            ], false);
        }

        wp_safe_redirect(wp_get_referer() ?: admin_url('edit.php?post_type=letter'));
        exit;
    }


    /**
     * AJAX HANDLER (Fixed #1, #5, #9, #10)
     */
    public function ajax_process_batch() {
        check_ajax_referer('rts_process_unrated', 'nonce');
        
        // Relaxed permission: allow editors to run process
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        if (!class_exists('RTS_Cron_Processing')) {
             wp_send_json_error(['message' => 'Processing system missing']);
        }

        // Performance Fix: Removed aggressive wp_cache_flush() to prevent global site slowdowns
        // Note: Use 'rts_memory_limit' filter in cron-processing.php if memory is an issue

        $batch_size = (int) get_option('rts_auto_processing_batch_size', 50);
        $ui_batch = min($batch_size, 10); // Cap at 10 for UI responsiveness

        $result = RTS_Cron_Processing::process_letters_batch('unrated', $ui_batch, 'dashboard_manual');
        
        if (isset($result['error'])) {
             wp_send_json_error(['message' => $result['error']]);
        }
        
        // Clear user specific caches using helper
        delete_transient($this->get_cache_key('unrated'));
        delete_transient($this->get_cache_key('flagged'));
        delete_transient($this->get_cache_key('low_quality'));
        
        $remaining = $this->get_count('unrated');
        
        wp_send_json_success([
            'processed' => $result['processed'],
            'remaining' => $remaining,
            'done'      => ($remaining === 0)
        ]);
    }
    
    public function add_smart_filters($post_type) {
        if ($post_type !== 'letter') return;
        
        $quality_filter = isset($_GET['quality_filter']) ? sanitize_text_field($_GET['quality_filter']) : '';
        ?>
        <select name="quality_filter">
            <option value="">All Quality Levels</option>
            <option value="high" <?php selected($quality_filter, 'high'); ?>>High Quality (70+)</option>
            <option value="medium" <?php selected($quality_filter, 'medium'); ?>>Medium Quality (50-69)</option>
            <option value="low" <?php selected($quality_filter, 'low'); ?>>Low Quality (<50)</option>
            <option value="unrated" <?php selected($quality_filter, 'unrated'); ?>>Unrated</option>
        </select>
        
        <?php
        $safety_filter = isset($_GET['safety_status']) ? sanitize_text_field($_GET['safety_status']) : '';
        ?>
        <select name="safety_status">
            <option value="">All Safety Levels</option>
            <option value="safe" <?php selected($safety_filter, 'safe'); ?>>✓ Safe</option>
            <option value="flagged" <?php selected($safety_filter, 'flagged'); ?>>⚠️ Flagged</option>
        </select>
        <?php
    }
    
    public function apply_quick_filters($query): void {
        if (!is_admin() || !$query->is_main_query()) return;

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'letter' || $screen->base !== 'edit') return;

        if (!empty($_GET['rts_quarantine'])) {
            $query->set('post_status', 'draft');
            $query->set('meta_query', [
                [ 'key' => 'needs_review', 'value' => '1' ],
            ]);
            return;
        }

        if (!empty($_GET['rts_inbox'])) {
            $query->set('post_status', 'pending');
            $query->set('meta_query', [
                'relation' => 'OR',
                [ 'key' => 'needs_review', 'compare' => 'NOT EXISTS' ],
                [ 'key' => 'needs_review', 'value' => '1', 'compare' => '!=' ],
            ]);
            return;
        }
    }

    public function filter_by_custom_fields($query) {
        global $pagenow, $typenow;
        
        if ($pagenow !== 'edit.php' || $typenow !== 'letter' || !is_admin()) return;
        
        $meta_query = [];
        
        if (isset($_GET['quality_filter']) && $_GET['quality_filter'] !== '') {
            $filter = sanitize_text_field($_GET['quality_filter']);
            
            if ($filter === 'unrated') {
                $meta_query[] = [
                    'key' => 'quality_score',
                    'compare' => 'NOT EXISTS'
                ];
            } elseif ($filter === 'high') {
                $meta_query[] = [
                    'key' => 'quality_score',
                    'value' => 70,
                    'compare' => '>=',
                    'type' => 'NUMERIC'
                ];
            } elseif ($filter === 'medium') {
                $meta_query[] = [
                    'relation' => 'AND',
                    [
                        'key' => 'quality_score',
                        'value' => 50,
                        'compare' => '>=',
                        'type' => 'NUMERIC'
                    ],
                    [
                        'key' => 'quality_score',
                        'value' => 70,
                        'compare' => '<',
                        'type' => 'NUMERIC'
                    ]
                ];
            } elseif ($filter === 'low') {
                $meta_query[] = [
                    'key' => 'quality_score',
                    'value' => 50,
                    'compare' => '<',
                    'type' => 'NUMERIC'
                ];
            }
        }
        
        if (isset($_GET['review_status']) && $_GET['review_status'] !== '') {
            $rv = sanitize_text_field($_GET['review_status']);
            if ($rv === 'needs_review') {
                $min_score = (int) get_option('rts_min_quality_score', 25);
                $min_score = max(0, min(100, $min_score));
                $meta_query[] = [
                    'relation' => 'OR',
                    [ 'key' => 'needs_review', 'value' => '1' ],
                    [ 'key' => 'quality_score', 'value' => $min_score, 'compare' => '<', 'type' => 'NUMERIC' ],
                ];
            }
        }

        if (isset($_GET['safety_status']) && $_GET['safety_status'] !== '') {
            $safety = sanitize_text_field($_GET['safety_status']);
            
            if ($safety === 'flagged') {
                $meta_query[] = [
                    'key' => 'needs_review',
                    'value' => '1'
                ];
            } elseif ($safety === 'safe') {
                $meta_query[] = [
                    'relation' => 'OR',
                    [
                        'key' => 'needs_review',
                        'value' => '0'
                    ],
                    [
                        'key' => 'needs_review',
                        'compare' => 'NOT EXISTS'
                    ]
                ];
            }
        }

        if (!empty($meta_query)) {
            $meta_query['relation'] = 'AND';
            $query->set('meta_query', $meta_query);
        }
    }
    
    public function add_custom_views($views) {
        $needs_review_count = $this->get_count('needs_review');

        if ($needs_review_count > 0) {
            $current = (isset($_GET['review_status']) && $_GET['review_status'] === 'needs_review') ? 'class="current"' : '';
            $views['needs_review'] = sprintf(
                '<a href="%s" %s>Quarantined <span class="count">(%d)</span></a>',
                esc_url(admin_url('edit.php?post_type=letter&review_status=needs_review')),
                $current,
                $needs_review_count
            );
        }

        return $views;
    }
    
    public function set_columns($columns) {
        return [
            'cb' => $columns['cb'],
            'title' => 'Letter',
            'letter_quality' => 'Quality',
            'letter_safety' => 'Safety',
            'letter_rating' => 'User Rating',
            'letter_tags' => 'Tags',
            'date' => 'Date'
        ];
    }
    
    public function render_column($column, $post_id) {
        switch ($column) {
            case 'letter_quality':
                $score = get_post_meta($post_id, 'quality_score', true);
                if ($score) {
                    if ($score >= 70) {
                        echo '<span class="rts-score rts-score-good">' . $score . '</span>';
                    } elseif ($score >= 50) {
                        echo '<span class="rts-score rts-score-ok">' . $score . '</span>';
                    } else {
                        echo '<span class="rts-score rts-score-low">' . $score . '</span>';
                    }
                } else {
                    echo '<span class="rts-unrated">Unrated</span>';
                }
                break;
                
            case 'letter_safety':
                $needs_review = get_post_meta($post_id, 'needs_review', true);
                if ($needs_review) {
					echo '<span class="rts-safety rts-safety-flag">⚠️ Review</span>';

					// Show why this letter is flagged (helps diagnose false positives).
					$flags_json   = (string) get_post_meta($post_id, 'rts_flagged_keywords', true);
					$reasons_json = (string) get_post_meta($post_id, 'rts_flag_reasons', true);

					$out = [];
					if ($reasons_json !== '') {
						$decoded = json_decode($reasons_json, true);
						if (is_array($decoded)) { $out = array_merge($out, $decoded); }
					}
					if ($flags_json !== '') {
						$decoded = json_decode($flags_json, true);
						if (is_array($decoded)) { $out = array_merge($out, $decoded); }
					}
					$out = array_values(array_unique(array_filter(array_map('strval', $out))));

					if (!empty($out)) {
						echo '<div class="rts-flag-reasons" style="font-size:10px; color:#d63638; margin-top:4px; line-height:1.2; max-width:220px;">';
						echo esc_html(implode(', ', array_slice($out, 0, 12)));
						echo '</div>';
					}
                } else {
                    echo '<span class="rts-safety rts-safety-ok">✓ Safe</span>';
                }
                break;
                
            case 'letter_rating':
                $thumbs_up = (int) get_post_meta($post_id, 'thumbs_up', true);
                $thumbs_down = (int) get_post_meta($post_id, 'thumbs_down', true);
                $total = $thumbs_up + $thumbs_down;
                
                if ($total > 0) {
                    // Fix #14: Division by zero logic protected by 'if'
                    $percent = round(($thumbs_up / $total) * 100);
                    
                    if ($percent >= 70) {
                        echo '<span class="rts-rating rts-rating-good">' . $percent . '%</span>';
                    } elseif ($percent >= 50) {
                        echo '<span class="rts-rating rts-rating-ok">' . $percent . '%</span>';
                    } else {
                        echo '<span class="rts-rating rts-rating-low">' . $percent . '%</span>';
                    }
                    echo '<div class="rts-votes">' . $total . ' votes</div>';
                } else {
                    echo '<span class="rts-no-rating">No ratings yet</span>';
                }
                break;
                
            case 'letter_tags':
                $feelings = wp_get_post_terms($post_id, 'letter_feeling', ['fields' => 'names']);
                $tone = wp_get_post_terms($post_id, 'letter_tone', ['fields' => 'names']);
                
                if (!empty($feelings)) {
                    echo '<div class="rts-tags">';
                    foreach (array_slice($feelings, 0, 3) as $feeling) {
                        echo '<span class="rts-tag">' . esc_html($feeling) . '</span>';
                    }
                    if (count($feelings) > 3) {
                        echo '<span class="rts-tag-more">+' . (count($feelings) - 3) . '</span>';
                    }
                    echo '</div>';
                }
                
                if (!empty($tone)) {
                    echo '<div class="rts-tone">' . esc_html($tone[0]) . '</div>';
                }
                
                if (empty($feelings) && empty($tone)) {
                    echo '<span class="rts-no-tags">Not tagged yet</span>';
                }
                break;
        }
    }
    
    public function sortable_columns($columns) {
        $columns['letter_quality'] = 'quality_score';
        $columns['letter_rating'] = 'thumbs_up';
        return $columns;
    }

    /**
     * Handle Sorting by custom meta keys
     */
    public function handle_sorting($query) {
        if (!is_admin() || !$query->is_main_query() || $query->get('post_type') !== 'letter') {
            return;
        }

        $orderby = $query->get('orderby');

        if ($orderby === 'quality_score') {
            $query->set('meta_key', 'quality_score');
            $query->set('orderby', 'meta_value_num');
        } elseif ($orderby === 'thumbs_up') {
            $query->set('meta_key', 'thumbs_up');
            $query->set('orderby', 'meta_value_num');
        }
    }
    
    public function row_actions($actions, $post) {
        if ($post->post_type !== 'letter') return $actions;
        
        if ($post->post_status === 'pending') {
            $approve_link = '<a href="' . wp_nonce_url(admin_url('admin-post.php?action=rts_quick_approve&post=' . $post->ID), 'approve_' . $post->ID) . '">✓ Approve</a>';
            
            if (isset($actions['edit'])) {
                $new_actions = [];
                foreach ($actions as $key => $val) {
                    $new_actions[$key] = $val;
                    if ($key === 'edit') {
                        $new_actions['approve'] = $approve_link;
                    }
                }
                return $new_actions;
            } else {
                $actions['approve'] = $approve_link;
            }
        }
        
        return $actions;
    }
    
    public function register_bulk_actions($bulk_actions) {
        $bulk_actions['mark_safe'] = 'Mark as Safe';
        $bulk_actions['mark_review'] = 'Mark for Review';
        $bulk_actions['clear_quarantine_rescan'] = 'Clear Quarantine & Re-scan';
        return $bulk_actions;
    }

    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        if (!in_array($doaction, ['mark_safe', 'mark_review', 'clear_quarantine_rescan'], true)) {
            return $redirect_to;
        }

        if (!current_user_can('edit_others_posts')) {
            return $redirect_to;
        }

        check_admin_referer('bulk-posts');

        $post_ids = array_values(array_filter(array_map('absint', (array) $post_ids)));
        if (empty($post_ids)) {
            return $redirect_to;
        }

        $scheduled = $this->schedule_bulk_action($doaction, $post_ids);

        return add_query_arg('bulk_scheduled', $scheduled, $redirect_to);
    }
    
    public function admin_css() {
        $screen = get_current_screen();
        // Fix #7: Screen restriction
        if (!$screen || $screen->post_type !== 'letter' || $screen->base !== 'edit') return;
        ?>
        <style>
        .rts-score {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-weight: 700;
            font-size: 16px;
        }
        .rts-score-good { background: #d4edda; color: #155724; }
        .rts-score-ok { background: #fff3cd; color: #856404; }
        .rts-score-low { background: #f8d7da; color: #721c24; }
        
        .rts-unrated { color: #999; font-style: italic; font-size: 13px; }
        
        .rts-safety {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 13px;
            font-weight: 600;
        }
        .rts-safety-ok { background: #d4edda; color: #155724; }
        .rts-safety-flag { background: #fff3cd; color: #856404; }
        
        .rts-rating {
            display: block;
            font-size: 18px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        .rts-rating-good { color: #00a32a; }
        .rts-rating-ok { color: #f0b849; }
        .rts-rating-low { color: #d63638; }
        
        .rts-votes { font-size: 11px; color: #999; }
        .rts-no-rating { color: #999; font-size: 13px; }
        
        .rts-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-bottom: 4px; }
        .rts-tag {
            display: inline-block;
            padding: 2px 8px;
            background: #f0f0f0;
            border-radius: 3px;
            font-size: 11px;
            color: #555;
        }
        .rts-tag-more {
            display: inline-block;
            padding: 2px 8px;
            background: #e0e0e0;
            border-radius: 3px;
            font-size: 11px;
            color: #666;
            font-weight: 600;
        }
        .rts-tone {
            display: inline-block;
            padding: 2px 8px;
            background: #e3f2fd;
            border-radius: 3px;
            font-size: 11px;
            color: #1565c0;
            margin-top: 4px;
        }
        .rts-no-tags { color: #999; font-size: 12px; font-style: italic; }
        
        .wp-list-table .column-letter_quality { width: 100px; }
        .wp-list-table .column-letter_safety { width: 100px; }
        .wp-list-table .column-letter_rating { width: 120px; }
        .wp-list-table .column-letter_tags { width: 200px; }
        </style>
        <?php
    }

    public function handle_quick_approve() {
        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if (!$post_id) {
            wp_die('Invalid request');
        }
        check_admin_referer('approve_' . $post_id);

        // Security check: ensure this is actually a letter post type
        if (get_post_type($post_id) !== 'letter') {
            wp_die('Invalid post type.');
        }

        // Security check: ensure user can edit this specific post
        if (!current_user_can('edit_post', $post_id)) {
            wp_die('Permission denied.');
        }
        
        $result = wp_update_post([
            'ID' => $post_id,
            'post_status' => 'publish'
        ], true);

        if (is_wp_error($result)) {
            wp_die('Error approving post: ' . $result->get_error_message());
        }
        
        delete_post_meta($post_id, 'needs_review');
        delete_post_meta($post_id, 'rts_flagged');
        
        wp_redirect(esc_url_raw(admin_url('edit.php?post_type=letter')));
        exit;
    }
    
    /**
     * Handle quick unflag from edit screen meta box
     * Clears quarantine flag, sets to pending, and queues for re-scan
     */
    public function handle_quick_unflag() {
        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if (!$post_id) {
            wp_die('Invalid request');
        }
        check_admin_referer('unflag_' . $post_id);

        // Security check: ensure this is actually a letter post type
        if (get_post_type($post_id) !== 'letter') {
            wp_die('Invalid post type.');
        }

        // Security check: ensure user can edit this specific post
        if (!current_user_can('edit_post', $post_id)) {
            wp_die('Permission denied.');
        }
        
        // Clear quarantine flags
        delete_post_meta($post_id, 'needs_review');
        delete_post_meta($post_id, 'rts_flagged');
        delete_post_meta($post_id, 'rts_flag_reasons');
        delete_post_meta($post_id, 'rts_moderation_reasons');
        delete_post_meta($post_id, 'rts_flagged_keywords');
        
        // Set to pending so it goes to inbox
        $result = wp_update_post([
            'ID' => $post_id,
            'post_status' => 'pending'
        ], true);

        if (is_wp_error($result)) {
            wp_die('Error updating post: ' . $result->get_error_message());
        }
        
        // Queue for fresh scan if Action Scheduler is available
        if ($this->as_available() && !$this->has_scheduled_action('rts_process_letter', [$post_id])) {
            as_schedule_single_action(time() + 5, 'rts_process_letter', [$post_id], 'rts');
        }
        
        // Redirect back to the edit screen with success message
        wp_redirect(esc_url_raw(add_query_arg('message', 'unflagger', admin_url('post.php?post=' . $post_id . '&action=edit'))));
        exit;
    }

    private function as_available(): bool {
        return function_exists('as_schedule_single_action') && function_exists('as_next_scheduled_action');
    }

    private function has_scheduled_action(string $hook, array $args = []): bool {
        if (function_exists('as_has_scheduled_action')) {
            return (bool) as_has_scheduled_action($hook, $args, 'rts');
        }

        if (function_exists('as_next_scheduled_action')) {
            return (bool) as_next_scheduled_action($hook, $args, 'rts');
        }

        return false;
    }

    private function schedule_bulk_action(string $doaction, array $post_ids): int {
        $chunks = array_chunk($post_ids, 50);
        $scheduled = 0;

        foreach ($chunks as $chunk) {
            if ($this->as_available()) {
                as_schedule_single_action(time() + 3, 'rts_bulk_process_letters', [$doaction, $chunk, get_current_user_id()], 'rts');
                $scheduled++;
                continue;
            }

            if (!wp_next_scheduled('rts_bulk_process_letters', [$doaction, $chunk, get_current_user_id()])) {
                wp_schedule_single_event(time() + 15, 'rts_bulk_process_letters', [$doaction, $chunk, get_current_user_id()]);
                $scheduled++;
            }
        }

        return $scheduled;
    }

    public function process_bulk_action_batch(string $doaction, array $post_ids, int $user_id = 0): void {
        if (!in_array($doaction, ['mark_safe', 'mark_review', 'clear_quarantine_rescan'], true)) {
            return;
        }

        $post_ids = array_values(array_filter(array_map('absint', (array) $post_ids)));
        if (empty($post_ids)) {
            return;
        }

        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'letter') {
                continue;
            }

            if ($this->is_locked($post_id)) {
                continue;
            }

            $this->lock_letter($post_id);

            if ($doaction === 'mark_safe') {
                delete_post_meta($post_id, 'needs_review');
                delete_post_meta($post_id, 'rts_flagged');
            } elseif ($doaction === 'mark_review') {
                update_post_meta($post_id, 'needs_review', '1');
                update_post_meta($post_id, 'rts_flagged', '1');
                wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
            } elseif ($doaction === 'clear_quarantine_rescan') {
                delete_post_meta($post_id, 'needs_review');
                delete_post_meta($post_id, 'rts_flagged');
                delete_post_meta($post_id, 'rts_flag_reasons');
                delete_post_meta($post_id, 'rts_moderation_reasons');
                delete_post_meta($post_id, 'rts_flagged_keywords');

                wp_update_post(['ID' => $post_id, 'post_status' => 'pending']);

                if ($this->as_available() && !$this->has_scheduled_action('rts_process_letter', [$post_id])) {
                    as_schedule_single_action(time() + 5, 'rts_process_letter', [$post_id], 'rts');
                }
            }

            $this->unlock_letter($post_id);
        }
    }

    private function lock_letter(int $post_id): void {
        set_transient('rts_lock_' . $post_id, time(), 300);
    }

    private function is_locked(int $post_id): bool {
        return (bool) get_transient('rts_lock_' . $post_id);
    }

    private function unlock_letter(int $post_id): void {
        delete_transient('rts_lock_' . $post_id);
    }
}

// Initialize
RTS_CPT_Letters_System::get_instance();

endif;
