<?php
/**
 * Reasons to Stay - Admin Preview Mode
 * Allows admins to test system with sample letters without publishing real content
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RTS_Admin_Preview')) {
    
class RTS_Admin_Preview {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Admin UI is now consolidated under Letters → Settings (Preview tab)
        
        // Handle preview mode toggle
        add_action('admin_init', [$this, 'handle_preview_toggle']);
        
        // Inject sample letters in preview mode
        add_filter('rts_get_letters_pool', [$this, 'add_sample_letters'], 10, 2);
    }
    
    /**
     * Add preview mode menu
     */
    public function add_preview_menu() {
        add_submenu_page(
            'edit.php?post_type=letter',
            'Preview Mode',
            'Preview Mode',
            'manage_options',
            'rts-preview-mode',
            [$this, 'render_preview_page']
        );
    }
    
    /**
     * Render preview mode page
     */
    public function render_preview_page() {
        $preview_enabled = get_option('rts_preview_mode_enabled', false);
        
        ?>
        <div class="wrap">
            <h1>🎯 Admin Preview Mode</h1>
            
            <?php if (isset($_GET['updated'])): ?>
            <div class="notice notice-success is-dismissible">
                <p><strong>Preview mode <?php echo $preview_enabled ? 'enabled' : 'disabled'; ?>!</strong></p>
            </div>
            <?php endif; ?>
            
            <div class="card" style="max-width: 800px;">
                <h2>What is Preview Mode?</h2>
                <p>Preview mode allows you to test the letter viewer with <strong>sample letters</strong> without publishing real content.</p>
                
                <h3>When Preview Mode is ON:</h3>
                <ul>
                    <li>✅ Admins see 5 sample test letters</li>
                    <li>✅ Sample letters appear in viewer rotation</li>
                    <li>✅ All features work (next, helpful, share)</li>
                    <li>✅ Real letters still work normally</li>
                    <li>⚠️ Non-admin visitors see ONLY real published letters</li>
                </ul>
                
                <h3>Current Status:</h3>
                <p style="font-size: 18px;">
                    Preview Mode: <strong><?php echo $preview_enabled ? '🟢 ENABLED' : '🔴 DISABLED'; ?></strong>
                </p>
                
                <form method="post" action="">
                    <?php wp_nonce_field('rts_toggle_preview', 'rts_preview_nonce'); ?>
                    
                    <?php if ($preview_enabled): ?>
                        <button type="submit" name="rts_disable_preview" class="button button-secondary">
                            Disable Preview Mode
                        </button>
                        <p class="description">Switch back to showing only real published letters.</p>
                    <?php else: ?>
                        <button type="submit" name="rts_enable_preview" class="button button-primary">
                            Enable Preview Mode
                        </button>
                        <p class="description">Add 5 sample letters for testing (admins only).</p>
                    <?php endif; ?>
                </form>
                
                <hr>
                
                <h3>Test the System:</h3>
                <ol>
                    <li>Enable Preview Mode above</li>
                    <li>Open your homepage in a new tab (while logged in)</li>
                    <li>You should see sample letters loading</li>
                    <li>Test "Next Letter", "This Helped", and Share buttons</li>
                    <li>When done testing, disable Preview Mode</li>
                </ol>
                
                <p style="background: #fff3cd; padding: 15px; border-left: 4px solid #ffc107;">
                    <strong>⚠️ Important:</strong> Preview mode only works for logged-in administrators. Regular visitors will never see sample letters.
                </p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Handle preview mode toggle
     */
    public function handle_preview_toggle() {
        $nonce = isset($_POST['rts_preview_nonce']) ? sanitize_text_field(wp_unslash($_POST['rts_preview_nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'rts_toggle_preview')) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            return;
        }
        
        if (isset($_POST['rts_enable_preview'])) {
            update_option('rts_preview_mode_enabled', true);
            wp_safe_redirect(admin_url('edit.php?post_type=letter&page=rts-preview-mode&updated=1'));
            exit;
        }
        
        if (isset($_POST['rts_disable_preview'])) {
            update_option('rts_preview_mode_enabled', false);
            wp_safe_redirect(admin_url('edit.php?post_type=letter&page=rts-preview-mode&updated=1'));
            exit;
        }
    }
    
    /**
     * Add sample letters to pool if preview mode enabled
     */
    public function add_sample_letters($letters, $preferences) {
        // Only add samples for logged-in admins
        if (!current_user_can('manage_options')) {
            return $letters;
        }
        
        // Only if preview mode enabled
        if (!get_option('rts_preview_mode_enabled', false)) {
            return $letters;
        }
        
        // Create sample letters
        $samples = $this->get_sample_letters();
        
        // Merge with real letters
        return array_merge($samples, $letters);
    }
    
    /**
     * Get sample test letters
     */
    private function get_sample_letters() {
        return [
            (object) [
                'ID' => 'SAMPLE_1',
                'post_content' => "Hey friend,\n\nI know things feel impossible right now. I've been exactly where you are - that dark place where you can't see any light ahead. But I'm writing this because I made it through, and I need you to know that you can too.\n\nThe pain you're feeling right now is temporary, even though it doesn't feel that way. I promise you, things can and will get better. Please reach out to someone - a friend, family member, counselor, or crisis helpline. You don't have to face this alone.\n\nYou matter. You are loved. And the world needs you in it.\n\nWith hope,\nSomeone who cares",
                'post_title' => 'Sample Letter #1 - Preview Mode',
                'post_status' => 'publish',
                'post_type' => 'letter',
                'meta' => [
                    'author_name' => 'Alex (Sample)',
                    'reading_time' => 'medium',
                    'view_count' => 42,
                    'help_count' => 38
                ],
                'terms' => [
                    'letter_feeling' => ['hopeless'],
                    'letter_tone' => ['gentle']
                ]
            ],
            (object) [
                'ID' => 'SAMPLE_2',
                'post_content' => "Listen, I'm not going to sugarcoat this - life can be brutal. I've had days where I didn't want to get out of bed. Days where everything felt pointless.\n\nBut here's the truth: you're stronger than you think. Every single day you've survived is proof of that. The fact that you're reading this means you're still fighting, even if you don't realize it.\n\nI found that talking to someone - really opening up - changed everything for me. It felt impossible at first, but it saved my life. Give yourself that chance.\n\nYou've got this. One day at a time.\n\n- Jordan (Sample)",
                'post_title' => 'Sample Letter #2 - Preview Mode',
                'post_status' => 'publish',
                'post_type' => 'letter',
                'meta' => [
                    'author_name' => 'Jordan (Sample)',
                    'reading_time' => 'medium',
                    'view_count' => 156,
                    'help_count' => 142
                ],
                'terms' => [
                    'letter_feeling' => ['tired', 'struggling'],
                    'letter_tone' => ['real']
                ]
            ],
            (object) [
                'ID' => 'SAMPLE_3',
                'post_content' => "I lost my best friend to suicide three years ago. Every single day I wish they had reached out, called someone, given themselves one more chance.\n\nPlease don't let that be your story. Please reach out. Call a helpline. Text a friend. Tell someone how you're really feeling.\n\nYour life has value. Your story isn't over. There are people who want to help - who NEED to help. Let them.\n\nThis isn't weakness. This is courage. Asking for help is one of the bravest things you can do.\n\nStay. Please stay.\n\n- Sam (Sample)",
                'post_title' => 'Sample Letter #3 - Preview Mode',
                'post_status' => 'publish',
                'post_type' => 'letter',
                'meta' => [
                    'author_name' => 'Sam (Sample)',
                    'reading_time' => 'short',
                    'view_count' => 89,
                    'help_count' => 76
                ],
                'terms' => [
                    'letter_feeling' => ['alone', 'grieving'],
                    'letter_tone' => ['real']
                ]
            ],
            (object) [
                'ID' => 'SAMPLE_4',
                'post_content' => "Tomorrow might be different. That's not a promise, it's a possibility - but possibilities matter when you're in the dark.\n\nI've learned that feelings aren't facts. When my brain tells me there's no hope, I've learned to question that. Sometimes it's lying to me. Sometimes depression is lying to all of us.\n\nThere are resources that can help. There are people trained to listen. There are medications that can help balance your brain chemistry. There are therapies that work.\n\nYou deserve to explore those possibilities. You deserve to give yourself that chance.\n\nPlease stay. Tomorrow might surprise you.\n\n- Taylor (Sample)",
                'post_title' => 'Sample Letter #4 - Preview Mode',
                'post_status' => 'publish',
                'post_type' => 'letter',
                'meta' => [
                    'author_name' => 'Taylor (Sample)',
                    'reading_time' => 'medium',
                    'view_count' => 234,
                    'help_count' => 198
                ],
                'terms' => [
                    'letter_feeling' => ['anxious', 'hopeless'],
                    'letter_tone' => ['hopeful']
                ]
            ],
            (object) [
                'ID' => 'SAMPLE_5',
                'post_content' => "You don't know me, but I know what you're going through. The isolation. The pain that won't stop. The feeling that nobody understands.\n\nI'm here to tell you: I understand. And you are not alone, even though it feels that way right now.\n\nThere's a whole community of people who've been where you are and made it through. We're here. We're rooting for you. And we believe in you, even when you can't believe in yourself.\n\nReach out. To anyone. A crisis line, a friend, a family member, even a stranger online. Just don't give up. Please.\n\nYour story matters. You matter.\n\n- Morgan (Sample)",
                'post_title' => 'Sample Letter #5 - Preview Mode',
                'post_status' => 'publish',
                'post_type' => 'letter',
                'meta' => [
                    'author_name' => 'Morgan (Sample)',
                    'reading_time' => 'short',
                    'view_count' => 67,
                    'help_count' => 59
                ],
                'terms' => [
                    'letter_feeling' => ['alone', 'struggling'],
                    'letter_tone' => ['gentle']
                ]
            ]
        ];
    }
}

// Initialize
RTS_Admin_Preview::get_instance();

} // end class_exists check
