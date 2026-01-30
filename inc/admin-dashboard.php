<?php
/**
 * Reasons to Stay - Admin Dashboard
 * Custom dashboard for Ben to view stats and analytics
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RTS_Admin_Dashboard')) {
    
class RTS_Admin_Dashboard {
    
    public function __construct() {
        add_action('admin_menu', [$this, 'add_dashboard_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('wp_dashboard_setup', [$this, 'add_dashboard_widget']);
    }
    
    /**
     * Add dashboard page to admin menu
     */
    public function add_dashboard_page() {
        add_submenu_page(
            'edit.php?post_type=letter',
            'Stats Overview',
            'Stats Overview',
            'edit_posts',
            'rts-analytics',
            [$this, 'render_dashboard_page']
        );
    }
    
    /**
     * Enqueue admin styles
     */
    public function enqueue_admin_assets($hook) {
        if (strpos($hook, 'rts-analytics') !== false || $hook === 'index.php') {
            wp_enqueue_style('rts-admin', get_stylesheet_directory_uri() . '/assets/css/rts-admin.css', [], '1.0');
        }
    }
    
    /**
     * Add dashboard widget to main WP dashboard
     */
    public function add_dashboard_widget() {
        // Full-width, high-priority widget on the main WP Dashboard
        add_meta_box(
            'rts_overview_widget',
            'Reasons to Stay - Analytics Overview',
            [$this, 'render_dashboard_widget'],
            'dashboard',
            'normal',
            'high'
        );

        // Move our widget to the top of the 'normal' dashboard column
        add_action('admin_head-index.php', function () {
            global $wp_meta_boxes;

            if (empty($wp_meta_boxes['dashboard']['normal']['high']['rts_overview_widget'])) {
                return;
            }

            $widget = $wp_meta_boxes['dashboard']['normal']['high']['rts_overview_widget'];
            unset($wp_meta_boxes['dashboard']['normal']['high']['rts_overview_widget']);

            // Prepend our widget
            $wp_meta_boxes['dashboard']['normal']['high'] =
                ['rts_overview_widget' => $widget] + $wp_meta_boxes['dashboard']['normal']['high'];
        });
    }
    
    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget() {
        $pending_count = wp_count_posts('letter')->pending;
        $published_count = wp_count_posts('letter')->publish;
        $stats = $this->get_stats();
        
        ?>
        <div class="rts-widget">
            <div class="rts-widget-stats">
                <div class="rts-stat-box rts-stat-pending">
                    <div class="rts-stat-number"><?php echo number_format($pending_count); ?></div>
                    <div class="rts-stat-label">Awaiting Moderation</div>
                    <?php if ($pending_count > 0): ?>
                        <a href="<?php echo admin_url('edit.php?post_status=pending&post_type=letter'); ?>" class="rts-stat-link">Review Now →</a>
                    <?php endif; ?>
                </div>
                
                <div class="rts-stat-box">
                    <div class="rts-stat-number"><?php echo number_format($published_count); ?></div>
                    <div class="rts-stat-label">Published Letters</div>
                </div>
                
                <div class="rts-stat-box">
                    <div class="rts-stat-number"><?php echo number_format($stats['today_views']); ?></div>
                    <div class="rts-stat-label">Views Today</div>
                </div>
            </div>
            
            <a href="<?php echo admin_url('edit.php?post_type=letter&page=rts-analytics'); ?>" class="button button-primary" style="margin-top:15px;">
                View Full Analytics →
            </a>
        </div>
        <?php
    }
    
    /**
     * Render full dashboard page
     */
    public function render_dashboard_page() {
        $stats = $this->get_stats();
        $top_letters = $this->get_top_letters(10);
        
        ?>
        <div class="wrap rts-dashboard">
            <h1>Reasons to Stay - Analytics Dashboard</h1>
            
            <!-- Quick Stats Grid -->
            <div class="rts-stats-grid">
                <div class="rts-card">
                    <h3>Published Letters</h3>
                    <div class="rts-big-number"><?php echo number_format($stats['published']); ?></div>
                </div>
                
                <div class="rts-card">
                    <h3>Pending Review</h3>
                    <div class="rts-big-number rts-pending"><?php echo number_format($stats['pending']); ?></div>
                    <?php if ($stats['pending'] > 0): ?>
                        <a href="<?php echo admin_url('edit.php?post_status=pending&post_type=letter'); ?>" class="rts-card-link">Review Now</a>
                    <?php endif; ?>
                </div>

                <div class="rts-card">
                    <h3>Draft Letters</h3>
                    <div class="rts-big-number"><?php echo number_format($stats['draft']); ?></div>
                    <?php if ($stats['draft'] > 0): ?>
                        <a href="<?php echo admin_url('edit.php?post_status=draft&post_type=letter'); ?>" class="rts-card-link">View Drafts</a>
                    <?php endif; ?>
                </div>

                <div class="rts-card">
                    <h3>Needs Review</h3>
                    <div class="rts-big-number rts-pending"><?php echo number_format($stats['triggered_feedback']); ?></div>
                    <div class="rts-stat-meta">Triggered feedback items</div>
                    <?php if ($stats['triggered_feedback'] > 0): ?>
                        <a href="<?php echo admin_url('edit.php?post_type=letter&rts_review=triggered_feedback'); ?>" class="rts-card-link">Open Queue</a>
                    <?php endif; ?>
                </div>

                <div class="rts-card">
                    <h3>Letters Received</h3>
                    <div class="rts-big-number"><?php echo number_format($stats['received_today']); ?></div>
                    <div class="rts-stat-meta">
                        Today: <?php echo number_format($stats['received_today']); ?>
                        · Week: <?php echo number_format($stats['received_week']); ?>
                        · Month: <?php echo number_format($stats['received_month']); ?>
                        · Year: <?php echo number_format($stats['received_year']); ?>
                    </div>
                    <a href="<?php echo admin_url('edit.php?post_type=letter'); ?>" class="rts-card-link">View Letters</a>
                </div>
                
                <div class="rts-card">
                    <h3>Total Views</h3>
                    <div class="rts-big-number"><?php echo number_format($stats['total_views']); ?></div>
                    <div class="rts-stat-meta">Today: <?php echo number_format($stats['today_views']); ?></div>
                </div>
                
                <div class="rts-card">
                    <h3>"This Helped" Clicks</h3>
                    <div class="rts-big-number"><?php echo number_format($stats['total_helps']); ?></div>
                    <div class="rts-stat-meta"><?php echo $stats['help_rate']; ?>% help rate</div>
                </div>
            </div>
            
            <!-- Recent Activity -->
            <div class="rts-section">
                <h2>30-Day Activity</h2>
                <div class="rts-stats-grid rts-stats-grid-4">
                    <div class="rts-card">
                        <h4>Views (30 days)</h4>
                        <div class="rts-number"><?php echo number_format($stats['views_30d']); ?></div>
                    </div>
                    <div class="rts-card">
                        <h4>Helped (30 days)</h4>
                        <div class="rts-number"><?php echo number_format($stats['helps_30d']); ?></div>
                    </div>
                    <div class="rts-card">
                        <h4>Submissions</h4>
                        <div class="rts-number"><?php echo number_format($stats['submissions_30d']); ?></div>
                    </div>
                    <div class="rts-card">
                        <h4>Total Shares</h4>
                        <div class="rts-number"><?php echo number_format($stats['shares_30d']); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Share Breakdown -->
            <div class="rts-section">
                <h2>Social Shares (Last 30 Days)</h2>
                <div class="rts-share-breakdown">
                    <div class="rts-share-item">
                        <span class="rts-share-platform">Facebook</span>
                        <span class="rts-share-count"><?php echo number_format($stats['shares_facebook']); ?></span>
                    </div>
                    <div class="rts-share-item">
                        <span class="rts-share-platform">X (Twitter)</span>
                        <span class="rts-share-count"><?php echo number_format($stats['shares_x']); ?></span>
                    </div>
                    <div class="rts-share-item">
                        <span class="rts-share-platform">WhatsApp</span>
                        <span class="rts-share-count"><?php echo number_format($stats['shares_whatsapp']); ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Top Performing Letters -->
            <div class="rts-section">
                <h2>Top Performing Letters</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Letter Preview</th>
                            <th>Author</th>
                            <th>Views</th>
                            <th>Helped</th>
                            <th>Help Rate</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($top_letters as $letter): ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html(wp_trim_words($letter['content'], 10)); ?></strong>
                            </td>
                            <td><?php echo esc_html($letter['author'] ?: '—'); ?></td>
                            <td><?php echo number_format($letter['views']); ?></td>
                            <td><?php echo number_format($letter['helps']); ?></td>
                            <td><?php echo $letter['help_rate']; ?>%</td>
                            <td>
                                <a href="<?php echo get_edit_post_link($letter['id']); ?>">Edit</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($top_letters)): ?>
                        <tr>
                            <td colspan="6" style="text-align:center;padding:20px;">
                                No published letters yet. <a href="<?php echo admin_url('post-new.php?post_type=letter'); ?>">Add your first letter</a>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get all statistics
     */
    private function get_stats() {
        $post_counts = wp_count_posts('letter');
        $daily_stats = get_option('rts_daily_stats', []);
        
        // Calculate totals
        $total_views = 0;
        $total_helps = 0;
        $today_views = 0;
        $views_30d = 0;
        $helps_30d = 0;
        $submissions_30d = 0;
        $shares_30d = 0;
        $shares_facebook = 0;
        $shares_x = 0;
        $shares_whatsapp = 0;
        
        $today = current_time('Y-m-d');
        $thirty_days_ago = date('Y-m-d', strtotime('-30 days'));
        
        foreach ($daily_stats as $date => $stats) {
            if ($date >= $thirty_days_ago) {
                $views_30d += $stats['total_views'] ?? 0;
                $helps_30d += $stats['help_clicks'] ?? 0;
                $submissions_30d += $stats['letters_submitted'] ?? 0;
                $shares_30d += ($stats['shares_facebook'] ?? 0) + ($stats['shares_x'] ?? 0) + ($stats['shares_whatsapp'] ?? 0);
                $shares_facebook += $stats['shares_facebook'] ?? 0;
                $shares_x += $stats['shares_x'] ?? 0;
                $shares_whatsapp += $stats['shares_whatsapp'] ?? 0;
            }
            
            if ($date === $today) {
                $today_views = $stats['total_views'] ?? 0;
            }
        }
        
        // Get totals from all letters
        global $wpdb;
        $view_totals = $wpdb->get_row("
            SELECT 
                SUM(CAST(meta_value AS UNSIGNED)) as total_views
            FROM {$wpdb->postmeta}
            WHERE meta_key = 'view_count'
        ");
        
        $help_totals = $wpdb->get_row("
            SELECT 
                SUM(CAST(meta_value AS UNSIGNED)) as total_helps
            FROM {$wpdb->postmeta}
            WHERE meta_key = 'help_count'
        ");
        
        $total_views = intval($view_totals->total_views ?? 0);
        $total_helps = intval($help_totals->total_helps ?? 0);
        $help_rate = $total_views > 0 ? round(($total_helps / $total_views) * 100, 1) : 0;

        // Draft count (useful for moderation workflows)
        $draft_count = intval($post_counts->draft);

        // Time-based submission counts
        $tz = wp_timezone();
        $now = new DateTime('now', $tz);
        $start_today = (clone $now)->setTime(0, 0, 0);
        $start_week = (clone $now)->modify('monday this week')->setTime(0, 0, 0);
        $start_month = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
        $start_year = (clone $now)->setDate((int) $now->format('Y'), 1, 1)->setTime(0, 0, 0);

        $submissions_today = $this->count_letters_since($start_today);
        $submissions_week = $this->count_letters_since($start_week);
        $submissions_month = $this->count_letters_since($start_month);
        $submissions_year = $this->count_letters_since($start_year);

        // Feedback review counts (triggered + negative)
        $triggered_feedback = $this->count_feedback(['triggered' => '1']);
        $down_feedback = $this->count_feedback(['rating' => 'down']);
        
        return [
            'published' => intval($post_counts->publish),
            'pending' => intval($post_counts->pending),
            'draft' => $draft_count,
            'total_views' => $total_views,
            'total_helps' => $total_helps,
            'help_rate' => $help_rate,
            'today_views' => $today_views,
            'views_30d' => $views_30d,
            'helps_30d' => $helps_30d,
            'submissions_30d' => $submissions_30d,
            'submissions_today' => $submissions_today,
            'submissions_week' => $submissions_week,
            'submissions_month' => $submissions_month,
            'submissions_year' => $submissions_year,
            'feedback_triggered' => $triggered_feedback,
            'feedback_down' => $down_feedback,
            'shares_30d' => $shares_30d,
            'shares_facebook' => $shares_facebook,
            'shares_x' => $shares_x,
            'shares_whatsapp' => $shares_whatsapp,
        ];
    }

    /**
     * Count letters created since a DateTime in site timezone.
     */
    private function count_letters_since(DateTime $since): int {
        $q = new WP_Query([
            'post_type' => 'letter',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'date_query' => [
                [
                    'after' => $since->format('Y-m-d H:i:s'),
                    'inclusive' => true,
                ]
            ],
        ]);
        return (int) $q->found_posts;
    }

    /**
     * Count feedback posts by meta filters.
     */
    private function count_feedback(array $meta): int {
        if (!post_type_exists('rts_feedback')) {
            return 0;
        }

        $meta_query = ['relation' => 'AND'];
        foreach ($meta as $k => $v) {
            $meta_query[] = [
                'key' => $k,
                'value' => (string) $v,
                'compare' => '=',
            ];
        }

        $q = new WP_Query([
            'post_type' => 'rts_feedback',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'no_found_rows' => false,
            'meta_query' => $meta_query,
        ]);
        return (int) $q->found_posts;
    }
    
    /**
     * Get top performing letters
     */
    private function get_top_letters($limit = 10) {
        $query = new WP_Query([
            'post_type' => 'letter',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'meta_key' => 'view_count',
            'orderby' => 'meta_value_num',
            'order' => 'DESC',
            'meta_query' => [
                [
                    'key' => 'view_count',
                    'value' => 0,
                    'compare' => '>',
                    'type' => 'NUMERIC'
                ]
            ]
        ]);
        
        $letters = [];
        while ($query->have_posts()) {
            $query->the_post();
            $id = get_the_ID();
            $views = intval(get_post_meta($id, 'view_count', true));
            $helps = intval(get_post_meta($id, 'help_count', true));
            $help_rate = $views > 0 ? round(($helps / $views) * 100, 1) : 0;
            
            $letters[] = [
                'id' => $id,
                'content' => get_the_content(),
                'author' => get_post_meta($id, 'author_name', true),
                'views' => $views,
                'helps' => $helps,
                'help_rate' => $help_rate
            ];
        }
        wp_reset_postdata();
        
        return $letters;
    }
}

// Initialize
new RTS_Admin_Dashboard();

} // end class_exists check
