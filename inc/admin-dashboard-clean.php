<?php
/**
 * RTS Letters - Clean Admin Dashboard (Client-Ready)
 * Simple, focused interface for managing letters
 */

if (!defined('ABSPATH')) exit;

class RTS_Admin_Dashboard_Clean {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', [$this, 'add_menu'], 25);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_styles']);
    }
    
    /**
     * Add clean dashboard menu
     */
    public function add_menu() {
        add_submenu_page(
            'edit.php?post_type=letter',
            'Dashboard',
            '📊 Dashboard',
            'edit_posts',
            'rts-dashboard',
            [$this, 'render_page']
        );
    }
    
    /**
     * Enqueue admin styles
     */
    public function enqueue_styles($hook) {
        if ($hook !== 'letter_page_rts-dashboard') return;
        
        wp_add_inline_style('wp-admin', '
            .rts-dashboard {
                max-width: 1200px;
                margin: 20px auto;
                padding: 0 20px;
            }
            .rts-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
                margin: 30px 0;
            }
            .rts-stat-card {
                background: white;
                border: 1px solid #ddd;
                border-radius: 8px;
                padding: 24px;
                text-align: center;
            }
            .rts-stat-number {
                font-size: 3rem;
                font-weight: 700;
                color: #2271b1;
                margin: 10px 0;
            }
            .rts-stat-label {
                font-size: 1rem;
                color: #666;
            }
            .rts-quick-actions {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin: 30px 0;
            }
            .rts-action-btn {
                display: block;
                background: #2271b1;
                color: white;
                padding: 15px 20px;
                border-radius: 8px;
                text-align: center;
                text-decoration: none;
                font-weight: 600;
                transition: background 0.2s;
            }
            .rts-action-btn:hover {
                background: #135e96;
                color: white;
            }
            .rts-action-btn .dashicons {
                margin-right: 5px;
            }
        ');
    }
    
    /**
     * Render dashboard page
     */
    public function render_page() {
        global $wpdb;
        
        // Get statistics
        $total_letters = wp_count_posts('letter');
        $published = $total_letters->publish;
        $pending = $total_letters->pending;
        
        // Get unrated count
        $unrated = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'quality_score'
            WHERE p.post_type = 'letter'
            AND p.post_status IN ('publish', 'pending')
            AND pm.meta_id IS NULL
        ");
        
        // Get flagged count
        $flagged = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'needs_review'
            WHERE p.post_type = 'letter'
            AND p.post_status IN ('publish', 'pending')
            AND pm.meta_value = '1'
        ");
        
        ?>
        <div class="wrap rts-dashboard">
            <h1>📊 Letters Dashboard</h1>
            <p style="font-size: 1.1rem; color: #666;">Quick overview of your letters</p>
            
            <!-- Statistics Cards -->
            <div class="rts-stats-grid">
                <div class="rts-stat-card">
                    <div class="rts-stat-label">Published Letters</div>
                    <div class="rts-stat-number"><?php echo number_format($published); ?></div>
                    <a href="<?php echo admin_url('edit.php?post_type=letter&post_status=publish'); ?>">View all →</a>
                </div>
                
                <div class="rts-stat-card">
                    <div class="rts-stat-label">Pending Review</div>
                    <div class="rts-stat-number" style="color: <?php echo $pending > 0 ? '#d63638' : '#00a32a'; ?>"><?php echo number_format($pending); ?></div>
                    <a href="<?php echo admin_url('edit.php?post_type=letter&post_status=pending'); ?>">Review now →</a>
                </div>
                
                <div class="rts-stat-card">
                    <div class="rts-stat-label">Need Processing</div>
                    <div class="rts-stat-number" style="color: <?php echo $unrated > 0 ? '#dba617' : '#00a32a'; ?>"><?php echo number_format($unrated); ?></div>
                    <a href="<?php echo admin_url('edit.php?post_type=letter'); ?>">Process now →</a>
                </div>
                
                <div class="rts-stat-card">
                    <div class="rts-stat-label">Flagged for Review</div>
                    <div class="rts-stat-number" style="color: <?php echo $flagged > 0 ? '#d63638' : '#00a32a'; ?>"><?php echo number_format($flagged); ?></div>
                    <?php if ($flagged > 0): ?>
                    <a href="<?php echo admin_url('edit.php?post_type=letter&meta_key=needs_review&meta_value=1'); ?>">Review now →</a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <h2 style="margin-top: 40px;">Quick Actions</h2>
            <div class="rts-quick-actions">
                <a href="<?php echo admin_url('post-new.php?post_type=letter'); ?>" class="rts-action-btn">
                    <span class="dashicons dashicons-edit"></span>
                    Write New Letter
                </a>
                
                <a href="<?php echo admin_url('edit.php?post_type=letter&post_status=pending'); ?>" class="rts-action-btn">
                    <span class="dashicons dashicons-visibility"></span>
                    Review Pending
                </a>
                
                <a href="<?php echo admin_url('admin.php?page=rts-letter-management'); ?>" class="rts-action-btn">
                    <span class="dashicons dashicons-admin-tools"></span>
                    Letter Tools
                </a>
                
                <a href="<?php echo admin_url('admin.php?page=rts-import-export'); ?>" class="rts-action-btn">
                    <span class="dashicons dashicons-upload"></span>
                    Import Letters
                </a>
            </div>
            
            <?php if ($unrated > 0): ?>
            <!-- Processing Notice -->
            <div class="notice notice-info" style="margin-top: 30px;">
                <p>
                    <strong>ℹ️ Auto-Processing Enabled</strong><br>
                    Your letters are being automatically processed every 5 minutes. The <?php echo number_format($unrated); ?> unprocessed letter(s) will be analyzed soon.
                    <br><br>
                    Want to speed it up? Go to <a href="<?php echo admin_url('edit.php?post_type=letter'); ?>">All Letters</a> and click "Process Now".
                </p>
            </div>
            <?php endif; ?>
            
            <?php if ($flagged > 0): ?>
            <!-- Safety Notice -->
            <div class="notice notice-warning" style="margin-top: 20px;">
                <p>
                    <strong>⚠️ <?php echo number_format($flagged); ?> letter(s) flagged for safety review</strong><br>
                    These letters contain content that may need your attention. Please review them when you can.
                </p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

// Initialize
RTS_Admin_Dashboard_Clean::get_instance();
