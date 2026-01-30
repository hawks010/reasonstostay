<?php
/**
 * RTS Auto-Approval System
 * Automatically publishes safe letters while flagging problematic ones for manual review
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RTS_Auto_Approval')) {

class RTS_Auto_Approval {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Auto-process on letter submission
        add_action('save_post_letter', [$this, 'maybe_auto_approve'], 20, 2);
        
        // Bulk actions
        add_filter('bulk_actions-edit-letter', [$this, 'add_bulk_approve_action']);
        add_filter('handle_bulk_actions-edit-letter', [$this, 'handle_bulk_approve'], 10, 3);
        
        // Admin notices
        add_action('admin_notices', [$this, 'bulk_approval_notice']);
        
        // Add admin menu
        add_action('admin_menu', [$this, 'add_review_queue_page']);
    }
    
    /**
     * Auto-approve letter if it passes safety checks
     */
    public function maybe_auto_approve($post_id, $post) {
        // Skip if auto-approval is disabled
        if (!get_option('rts_auto_approval_enabled', 0)) {
            return;
        }
        
        // Skip if flagged to skip (during bulk import)
        if (get_post_meta($post_id, '_skip_auto_approval', true)) {
            return;
        }
        
        // Skip if already published
        if ($post->post_status === 'publish') {
            return;
        }
        
        // Skip if manually set to pending (admin override)
        if (get_post_meta($post_id, '_manual_review_required', true)) {
            return;
        }
        
        // Skip autosaves and revisions
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        
        // Run safety checks
        $safety_result = $this->check_letter_safety($post_id, $post->post_content);
        
        if ($safety_result['safe']) {
            // Auto-approve: Change status to publish
            remove_action('save_post_letter', [$this, 'maybe_auto_approve'], 20);
            
            wp_update_post([
                'ID' => $post_id,
                'post_status' => 'publish'
            ]);
            
            add_action('save_post_letter', [$this, 'maybe_auto_approve'], 20, 2);
            
            // Log approval
            update_post_meta($post_id, '_auto_approved', current_time('mysql'));
            update_post_meta($post_id, '_approval_score', $safety_result['score']);
            
            if (class_exists('RTS_Logger')) {
                RTS_Logger::get_instance()->info('Letter auto-approved', [
                    'letter_id' => $post_id,
                    'score' => $safety_result['score'],
                    'source' => 'auto-approval'
                ]);
            }
            
        } else {
            // Flag for manual review
            update_post_meta($post_id, 'flagged_at', current_time('mysql'));
            update_post_meta($post_id, '_flag_reason', $safety_result['reason']);
            update_post_meta($post_id, '_approval_score', $safety_result['score']);
            
            // Keep as pending
            remove_action('save_post_letter', [$this, 'maybe_auto_approve'], 20);
            
            wp_update_post([
                'ID' => $post_id,
                'post_status' => 'pending'
            ]);
            
            add_action('save_post_letter', [$this, 'maybe_auto_approve'], 20, 2);
            
            if (class_exists('RTS_Logger')) {
                RTS_Logger::get_instance()->warning('Letter flagged for review', [
                    'letter_id' => $post_id,
                    'reason' => $safety_result['reason'],
                    'score' => $safety_result['score'],
                    'source' => 'auto-approval'
                ]);
            }
            
            // Send notification to admin
            $this->send_flagged_notification($post_id, $safety_result['reason']);
        }
    }
    
    /**
     * Check if letter is safe to auto-approve
     */
    private function check_letter_safety($post_id, $content) {
        $score = 100;
        $flags = [];
        
        // Get safety thresholds
        $min_score = get_option('rts_auto_approval_min_score', 70);
        
        // Check 1: Minimum length
        $word_count = str_word_count(strip_tags($content));
        if ($word_count < 20) {
            $score -= 30;
            $flags[] = 'Too short (' . $word_count . ' words)';
        }
        
        // Check 2: Maximum length
        if ($word_count > 2000) {
            $score -= 20;
            $flags[] = 'Too long (' . $word_count . ' words)';
        }
        
        // Check 3: Excessive URLs
        $url_count = preg_match_all('/https?:\/\/[^\s]+/', $content);
        if ($url_count > 2) {
            $score -= 40;
            $flags[] = 'Multiple URLs detected';
        }
        
        // Check 4: Contact information (phone/email)
        if (preg_match('/\b\d{3}[-.]?\d{3}[-.]?\d{4}\b/', $content)) {
            $score -= 35;
            $flags[] = 'Phone number detected';
        }
        
        if (preg_match('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $content)) {
            $score -= 35;
            $flags[] = 'Email address detected';
        }
        
        // Check 5: Dangerous content patterns (from content analyzer)
        $dangerous_patterns = [
            'method' => ['overdose', 'pills', 'jump', 'cut', 'hang', 'gun'],
            'immediate_danger' => ['tonight', 'today', 'right now', 'going to', 'plan to']
        ];
        
        foreach ($dangerous_patterns as $category => $words) {
            foreach ($words as $word) {
                if (stripos($content, $word) !== false) {
                    $score = 0; // Instant fail
                    $flags[] = 'Dangerous content: ' . $category;
                    break 2;
                }
            }
        }
        
        // Check 6: Spam patterns
        $spam_patterns = ['buy now', 'click here', 'limited time', 'act now', '$$$'];
        foreach ($spam_patterns as $pattern) {
            if (stripos($content, $pattern) !== false) {
                $score -= 50;
                $flags[] = 'Spam pattern detected';
                break;
            }
        }
        
        // Check 7: Excessive caps
        $caps_ratio = strlen(preg_replace('/[^A-Z]/', '', $content)) / max(strlen($content), 1);
        if ($caps_ratio > 0.3) {
            $score -= 25;
            $flags[] = 'Excessive capitals';
        }
        
        // Check 8: Already flagged by content analyzer
        if (get_post_meta($post_id, 'flagged_at', true)) {
            $score -= 50;
            $flags[] = 'Previously flagged by content analyzer';
        }
        
        // Determine if safe
        $is_safe = ($score >= $min_score && empty(array_filter($flags, function($flag) {
            return strpos($flag, 'Dangerous content') !== false;
        })));
        
        return [
            'safe' => $is_safe,
            'score' => max(0, $score),
            'reason' => !$is_safe ? implode(', ', $flags) : 'Passed all checks'
        ];
    }
    
    /**
     * Send notification when letter is flagged
     */
    private function send_flagged_notification($post_id, $reason) {
        if (!get_option('rts_notify_on_flag', 1)) {
            return;
        }
        
        $notify_email = get_option('rts_notify_email', get_option('admin_email'));
        $letter_url = admin_url('post.php?post=' . $post_id . '&action=edit');
        
        $subject = 'RTS: Letter Flagged for Review';
        $message = "A letter has been flagged and needs manual review.\n\n";
        $message .= "Reason: " . $reason . "\n\n";
        $message .= "Review here: " . $letter_url . "\n\n";
        $message .= "View all flagged letters: " . admin_url('edit.php?post_type=letter&page=rts-review-queue');
        
        wp_mail($notify_email, $subject, $message);
    }
    
    /**
     * Add bulk approve action
     */
    public function add_bulk_approve_action($actions) {
        $actions['rts_bulk_approve'] = 'Approve & Publish Selected';
        $actions['rts_bulk_approve_all_pending'] = 'Approve All Pending (Use Carefully!)';
        return $actions;
    }
    
    /**
     * Handle bulk approval
     */
    public function handle_bulk_approve($redirect_to, $action, $post_ids) {
        if ($action === 'rts_bulk_approve') {
            $approved = 0;
            
            foreach ($post_ids as $post_id) {
                // Change to publish
                wp_update_post([
                    'ID' => $post_id,
                    'post_status' => 'publish'
                ]);
                
                // Remove flag
                delete_post_meta($post_id, 'flagged_at');
                delete_post_meta($post_id, '_flag_reason');
                
                // Mark as manually approved
                update_post_meta($post_id, '_manually_approved', current_time('mysql'));
                
                $approved++;
            }
            
            $redirect_to = add_query_arg('bulk_approved', $approved, $redirect_to);
            
        } elseif ($action === 'rts_bulk_approve_all_pending') {
            // Get all pending letters
            $pending = get_posts([
                'post_type' => 'letter',
                'post_status' => 'pending',
                'posts_per_page' => -1,
                'fields' => 'ids'
            ]);
            
            $approved = 0;
            
            foreach ($pending as $post_id) {
                wp_update_post([
                    'ID' => $post_id,
                    'post_status' => 'publish'
                ]);
                
                delete_post_meta($post_id, 'flagged_at');
                delete_post_meta($post_id, '_flag_reason');
                update_post_meta($post_id, '_bulk_approved', current_time('mysql'));
                
                $approved++;
            }
            
            $redirect_to = add_query_arg('bulk_approved_all', $approved, $redirect_to);
        }
        
        return $redirect_to;
    }
    
    /**
     * Bulk approval admin notice
     */
    public function bulk_approval_notice() {
        if (!empty($_GET['bulk_approved'])) {
            $count = intval($_GET['bulk_approved']);
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>RTS:</strong> ' . sprintf(_n('%s letter approved and published.', '%s letters approved and published.', $count), number_format($count)) . '</p>';
            echo '</div>';
        }
        
        if (!empty($_GET['bulk_approved_all'])) {
            $count = intval($_GET['bulk_approved_all']);
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>RTS:</strong> All ' . number_format($count) . ' pending letters have been approved and published!</p>';
            echo '</div>';
        }
    }
    
    /**
     * Add review queue page
     */
    public function add_review_queue_page() {
        add_submenu_page(
            'edit.php?post_type=letter',
            'Review Queue',
            'Review Queue',
            'edit_posts',
            'rts-review-queue',
            [$this, 'render_review_queue']
        );
    }
    
    /**
     * Get count of flagged letters
     */
    private function get_flagged_count() {
        global $wpdb;
        
        $count = $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = 'flagged_at'
        ");
        
        return intval($count);
    }
    
    /**
     * Render review queue page
     */
    public function render_review_queue() {
        global $wpdb;
        
        // Get flagged letters
        $flagged_ids = $wpdb->get_col("
            SELECT DISTINCT post_id 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = 'flagged_at'
            ORDER BY meta_value DESC
        ");
        
        ?>
        <div class="wrap">
            <h1>🚨 Review Queue - Flagged Letters</h1>
            
            <?php if (empty($flagged_ids)): ?>
                <div class="notice notice-success">
                    <p><strong>✅ All clear!</strong> No letters are currently flagged for review.</p>
                    <p><a href="<?php echo admin_url('edit.php?post_type=letter&post_status=pending'); ?>" class="button">View All Pending Letters →</a></p>
                </div>
            <?php else: ?>
                <div class="notice notice-info">
                    <p><strong><?php echo count($flagged_ids); ?> letters</strong> need manual review before they can be published.</p>
                </div>
                
                <table class="wp-list-table widefat fixed striped" style="margin-top: 20px;">
                    <thead>
                        <tr>
                            <th style="width: 60px;">ID</th>
                            <th>Letter Preview</th>
                            <th style="width: 200px;">Flag Reason</th>
                            <th style="width: 80px;">Score</th>
                            <th style="width: 120px;">Flagged</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($flagged_ids as $post_id): 
                            $post = get_post($post_id);
                            if (!$post) continue;
                            
                            $reason = get_post_meta($post_id, '_flag_reason', true);
                            $score = get_post_meta($post_id, '_approval_score', true);
                            $flagged_at = get_post_meta($post_id, 'flagged_at', true);
                            $preview = wp_trim_words($post->post_content, 30);
                        ?>
                        <tr>
                            <td><?php echo $post_id; ?></td>
                            <td>
                                <?php echo esc_html($preview); ?>
                                <div style="margin-top: 5px;">
                                    <small style="color: #666;">
                                        <?php echo str_word_count(strip_tags($post->post_content)); ?> words
                                    </small>
                                </div>
                            </td>
                            <td>
                                <span style="color: #d63638; font-weight: 600;">
                                    <?php echo esc_html($reason ?: 'Unknown'); ?>
                                </span>
                            </td>
                            <td>
                                <strong style="color: <?php echo $score < 50 ? '#d63638' : ($score < 70 ? '#f0b849' : '#666'); ?>">
                                    <?php echo $score; ?>/100
                                </strong>
                            </td>
                            <td>
                                <small><?php echo human_time_diff(strtotime($flagged_at), current_time('timestamp')); ?> ago</small>
                            </td>
                            <td>
                                <a href="<?php echo admin_url('post.php?post=' . $post_id . '&action=edit'); ?>" class="button button-small">
                                    Review
                                </a>
                                <form method="post" style="display: inline; margin-left: 5px;">
                                    <?php wp_nonce_field('approve_' . $post_id); ?>
                                    <input type="hidden" name="approve_letter_id" value="<?php echo $post_id; ?>" />
                                    <button type="submit" class="button button-primary button-small" onclick="return confirm('Approve and publish this letter?');">
                                        ✓ Approve
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <div style="margin-top: 20px; padding: 15px; background: #fff3cd; border-left: 4px solid #f0ad4e;">
                    <p style="margin: 0;"><strong>💡 Quick Tip:</strong> You can bulk approve letters from the main Letters list by selecting them and choosing "Approve & Publish Selected".</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
        
        // Handle inline approvals
        if (isset($_POST['approve_letter_id'])) {
            $post_id = intval($_POST['approve_letter_id']);
            
            if (wp_verify_nonce($_POST['_wpnonce'], 'approve_' . $post_id)) {
                wp_update_post([
                    'ID' => $post_id,
                    'post_status' => 'publish'
                ]);
                
                delete_post_meta($post_id, 'flagged_at');
                delete_post_meta($post_id, '_flag_reason');
                update_post_meta($post_id, '_manually_approved', current_time('mysql'));
                
                echo '<div class="notice notice-success"><p>Letter approved and published!</p></div>';
                echo '<script>setTimeout(function(){ window.location.reload(); }, 1500);</script>';
            }
        }
    }
}

// Initialize
RTS_Auto_Approval::get_instance();

} // end class_exists check
