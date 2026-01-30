<?php
/**
 * RTS Letters CPT - COMPLETE OVERHAUL
 * Dashboard stats at top, smart filters, clean columns, auto-processing controls
 * FIXED: Row actions, AJAX loop, SQL Security, Caching, Capabilities, Bulk Actions.
 * POLISHED: Helper methods, Logging, Dynamic Throttling, Memory Management.
 * UPDATED: Added sorting logic, enhanced security checks, relaxed permissions, and URL escaping.
 * LATEST FIX: Added 'map_meta_cap' to fix Trash/Bin failure. Removed aggressive cache flushing.
 */

if (!defined('ABSPATH')) exit;

class RTS_CPT_Letters_Complete {
    
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
        
        // DASHBOARD at top + BIG process button + smart filters
        add_action('admin_notices', [$this, 'show_dashboard_and_controls']);
        
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
        
        // AJAX Handler for Dashboard Button
        add_action('wp_ajax_rts_process_unrated_letters', [$this, 'ajax_process_batch']);
        
        // Custom CSS
        add_action('admin_head', [$this, 'admin_css']);
        
        // Register Quick Approve Handler
        add_action('admin_post_rts_quick_approve', [$this, 'handle_quick_approve']);
    }
    
    public function register_post_type() {
        register_post_type('letter', [
            'labels' => [
                'name' => 'Letters',
                'singular_name' => 'Letter',
                'add_new' => 'Add New',
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
    }
    
    public function register_taxonomies() {
        register_taxonomy('letter_feeling', 'letter', [
            'label' => 'Feelings',
            'public' => false,
            'show_ui' => true,
            'hierarchical' => true,
        ]);
        
        register_taxonomy('letter_tone', 'letter', [
            'label' => 'Tone',
            'public' => false,
            'show_ui' => true,
            'hierarchical' => false,
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
                case 'flagged':
                    $query = $wpdb->prepare(
                        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s WHERE p.post_type = %s AND p.post_status IN ('publish', 'pending') AND pm.meta_value = %s",
                        'needs_review', 'letter', '1'
                    );
                    break;
                case 'low_quality':
                    $query = $wpdb->prepare(
                        "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s WHERE p.post_type = %s AND p.post_status IN ('publish', 'pending') AND CAST(pm.meta_value AS UNSIGNED) < 50",
                        'quality_score', 'letter'
                    );
                    break;
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
    public function show_dashboard_and_controls() {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'letter' || $screen->base !== 'edit') return;
        
        // Relaxed permission check: allow editors to see dashboard
        if (!current_user_can('edit_posts')) return;
        
        $total_letters = wp_count_posts('letter');
        $published = $total_letters->publish;
        $pending = $total_letters->pending;
        
        $unrated = $this->get_count('unrated');
        $flagged = $this->get_count('flagged');
        $low_quality = $this->get_count('low_quality');
        
        $auto_enabled = get_option('rts_auto_processing_enabled', '1') === '1';
        $batch_size = get_option('rts_auto_processing_batch_size', 50);
        
        ?>
        <style>
        .rts-dashboard-header {
            background: white;
            border: 1px solid #c3c4c7;
            border-radius: 4px;
            padding: 20px;
            margin: 15px 0 20px 0;
        }
        .rts-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .rts-stat-card {
            text-align: center;
            padding: 15px;
            background: #f6f7f7;
            border-radius: 4px;
        }
        .rts-stat-number {
            font-size: 36px;
            font-weight: 700;
            margin: 5px 0;
        }
        .rts-stat-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #666;
        }
        .rts-stat-link {
            font-size: 12px;
            margin-top: 5px;
        }
        .rts-controls-row {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }
        .rts-control-card {
            flex: 1;
            min-width: 300px;
            background: #f9f9f9;
            padding: 15px;
            border-radius: 4px;
            border-left: 4px solid #2271b1;
        }
        </style>
        
        <div class="rts-dashboard-header">
            <h2 style="margin:0 0 15px 0;font-size:18px;">📊 Letters Overview</h2>
            
            <div class="rts-stats-grid">
                <div class="rts-stat-card">
                    <div class="rts-stat-number" style="color:#00a32a;"><?php echo number_format($published); ?></div>
                    <div class="rts-stat-label">Published</div>
                    <div class="rts-stat-link"><a href="<?php echo esc_url(admin_url('edit.php?post_type=letter&post_status=publish')); ?>">View →</a></div>
                </div>
                
                <div class="rts-stat-card">
                    <div class="rts-stat-number" style="color:<?php echo $pending > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo number_format($pending); ?></div>
                    <div class="rts-stat-label">Pending Review</div>
                    <?php if ($pending > 0): ?>
                    <div class="rts-stat-link"><a href="<?php echo esc_url(admin_url('edit.php?post_type=letter&post_status=pending')); ?>">Review →</a></div>
                    <?php endif; ?>
                </div>
                
                <div class="rts-stat-card">
                    <div class="rts-stat-number" style="color:<?php echo $unrated > 0 ? '#f0b849' : '#00a32a'; ?>;"><?php echo number_format($unrated); ?></div>
                    <div class="rts-stat-label">Need Processing</div>
                </div>
                
                <div class="rts-stat-card">
                    <div class="rts-stat-number" style="color:<?php echo $flagged > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo number_format($flagged); ?></div>
                    <div class="rts-stat-label">Flagged</div>
                    <?php if ($flagged > 0): ?>
                    <div class="rts-stat-link"><a href="<?php echo esc_url(admin_url('edit.php?post_type=letter&safety_status=flagged')); ?>">Review →</a></div>
                    <?php endif; ?>
                </div>
                
                <div class="rts-stat-card">
                    <div class="rts-stat-number" style="color:<?php echo $low_quality > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo number_format($low_quality); ?></div>
                    <div class="rts-stat-label">Low Quality</div>
                    <?php if ($low_quality > 0): ?>
                    <div class="rts-stat-link"><a href="<?php echo esc_url(admin_url('edit.php?post_type=letter&quality_filter=low')); ?>">Review →</a></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="rts-controls-row">
                <?php if ($unrated > 0): ?>
                <div class="rts-control-card">
                    <h3 style="margin:0 0 10px 0;font-size:14px;">⚡ Process Unrated Letters</h3>
                    <p style="margin:0 0 10px 0;font-size:13px;color:#666;">
                        <?php echo number_format($unrated); ?> letters need quality analysis and tagging.
                    </p>
                    <button type="button" class="button button-primary" id="rts-process-btn">
                        🚀 Process All Now
                    </button>
                    <span id="rts-process-status" style="margin-left:10px;font-size:12px;"></span>
                </div>
                <?php endif; ?>
                
                <div class="rts-control-card" style="border-left-color:<?php echo $auto_enabled ? '#00a32a' : '#d63638'; ?>;">
                    <h3 style="margin:0 0 10px 0;font-size:14px;">
                        <?php echo $auto_enabled ? '✓' : '⚠'; ?> Auto-Processing
                    </h3>
                    <p style="margin:0 0 10px 0;font-size:13px;color:#666;">
                        <?php if ($auto_enabled): ?>
                            <strong>Enabled</strong> - Processes <?php echo $batch_size; ?> letters every 5 minutes
                        <?php else: ?>
                            <strong>Disabled</strong> - Letters won't be processed automatically
                        <?php endif; ?>
                    </p>
                    <a href="<?php echo apply_filters('rts_settings_page_url', esc_url(admin_url('admin.php?page=rts-settings'))); ?>" class="button">
                        ⚙️ Settings
                    </a>
                </div>
            </div>
        </div>
        
        <?php if ($unrated > 0): ?>
        <!-- Progress indicator (hidden initially) -->
        <div id="rts-process-progress" style="display:none;margin:-10px 0 20px 0;background:white;border:1px solid #c3c4c7;border-radius:4px;padding:15px;">
            <div style="background:#f0f0f0;border-radius:8px;height:30px;overflow:hidden;position:relative;">
                <div id="rts-process-bar" style="background:#2271b1;height:100%;width:0%;transition:width 0.3s;display:flex;align-items:center;justify-content:center;">
                    <strong id="rts-process-text" style="color:white;font-size:13px;"></strong>
                </div>
            </div>
            <p id="rts-process-detail" style="margin:10px 0 0 0;font-size:13px;color:#666;"></p>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#rts-process-btn').on('click', function() {
                var btn = $(this);
                var status = $('#rts-process-status');
                var progress = $('#rts-process-progress');
                var bar = $('#rts-process-bar');
                var text = $('#rts-process-text');
                var detail = $('#rts-process-detail');
                
                btn.prop('disabled', true).text('Processing...');
                status.text('Starting...');
                progress.show();
                
                var total = <?php echo $unrated; ?>;
                var ui_batch = 10; // Matches PHP limit
                
                function processChunk() {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'rts_process_unrated_letters',
                            nonce: '<?php echo wp_create_nonce('rts_process_unrated'); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                var remaining = response.data.remaining;
                                var processedInChunk = response.data.processed;
                                
                                // Calculate total progress
                                var currentProcessed = total - remaining;
                                if (currentProcessed < 0) currentProcessed = 0;
                                if (currentProcessed > total) currentProcessed = total;

                                var percent = Math.round((currentProcessed / total) * 100);
                                if (percent > 100) percent = 100;
                                
                                bar.css('width', percent + '%');
                                text.text(currentProcessed + ' / ' + total);
                                detail.text('Processing... ' + percent + '% complete (' + remaining + ' remaining)');
                                status.text(percent + '% done');
                                
                                if (response.data.done || remaining <= 0) {
                                    bar.css('background', '#00a32a').css('width', '100%');
                                    text.text('✓ Complete!');
                                    detail.html('<strong style="color:#00a32a;">All done! Refreshing page...</strong>');
                                    status.html('<strong style="color:#00a32a;">✓ Complete</strong>');
                                    setTimeout(function() { location.reload(); }, 2000);
                                } else {
                                    // Smart Throttling: If we processed fewer than max, server might be slow
                                    var delay = 500;
                                    if (processedInChunk < ui_batch / 2) {
                                        delay = 1000;
                                    }
                                    setTimeout(processChunk, delay); 
                                }
                            } else {
                                detail.html('<strong style="color:red;">Error: ' + response.data.message + '</strong>');
                                btn.prop('disabled', false).text('Try Again');
                            }
                        },
                        error: function() {
                             detail.html('<strong style="color:red;">Connection Error. Please refresh.</strong>');
                        }
                    });
                }
                
                processChunk();
            });
        });
        </script>
        <?php
        endif;
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

        $result = RTS_Cron_Processing::process_letters_batch('pending', $ui_batch, 'dashboard_manual');
        
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
        $flagged_count = $this->get_count('flagged');
        $low_quality_count = $this->get_count('low_quality');
        
        if ($flagged_count > 0) {
            $current = (isset($_GET['safety_status']) && $_GET['safety_status'] === 'flagged') ? 'class="current"' : '';
            $views['flagged'] = sprintf(
                '<a href="%s" %s>⚠️ Flagged <span class="count">(%d)</span></a>',
                esc_url(admin_url('edit.php?post_type=letter&safety_status=flagged')),
                $current,
                $flagged_count
            );
        }
        
        if ($low_quality_count > 0) {
            $current = (isset($_GET['quality_filter']) && $_GET['quality_filter'] === 'low') ? 'class="current"' : '';
            $views['low_quality'] = sprintf(
                '<a href="%s" %s>Low Quality <span class="count">(%d)</span></a>',
                esc_url(admin_url('edit.php?post_type=letter&quality_filter=low')),
                $current,
                $low_quality_count
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
        return $bulk_actions;
    }

    public function handle_bulk_actions($redirect_to, $doaction, $post_ids) {
        if ($doaction !== 'mark_safe' && $doaction !== 'mark_review') {
            return $redirect_to;
        }

        // Fix #10: Check post type
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'letter') continue;

            if ($doaction === 'mark_safe') {
                delete_post_meta($post_id, 'needs_review');
                delete_post_meta($post_id, 'rts_flagged');
            } elseif ($doaction === 'mark_review') {
                update_post_meta($post_id, 'needs_review', 1);
                update_post_meta($post_id, 'rts_flagged', 1);
            }
        }

        return add_query_arg('bulk_processed', count($post_ids), $redirect_to);
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
        if (!isset($_GET['post']) || !wp_verify_nonce($_GET['_wpnonce'], 'approve_' . $_GET['post'])) {
            wp_die('Invalid request');
        }
        
        $post_id = intval($_GET['post']);

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
}

// Initialize
RTS_CPT_Letters_Complete::get_instance();