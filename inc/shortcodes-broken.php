<?php
/**
 * Reasons to Stay - Shortcodes (FIXED - Server-side rendering)
 * Provides shortcodes for onboarding, letter viewer, and submission form
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RTS_Shortcodes')) {
    
class RTS_Shortcodes {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Register shortcodes
        add_shortcode('rts_onboarding', [$this, 'render_onboarding']);
        add_shortcode('rts_letter_viewer', [$this, 'render_letter_viewer']);
        add_shortcode('rts_submit_form', [$this, 'render_submit_form']);
        
        // Add inline CSS for onboarding modal (hidden by default)
        add_action('wp_head', [$this, 'add_onboarding_styles'], 1);
    }
    
    /**
     * Add styles to hide onboarding modal by default
     */
    public function add_onboarding_styles() {
        ?>
        <style>
        #rts-onboarding-modal {
            display: none !important;
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            width: 100% !important;
            height: 100% !important;
            z-index: 999999 !important;
            visibility: hidden !important;
            opacity: 0 !important;
        }
        #rts-onboarding-modal.active {
            display: flex !important;
            visibility: visible !important;
            opacity: 1 !important;
            align-items: center !important;
            justify-content: center !important;
        }
        .rts-onboarding-container {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 999999;
            background: rgba(0,0,0,0.8);
        }
        .rts-onboarding-placeholder {
            display: none !important;
        }
        </style>
        <?php
    }
    
    /**
     * Onboarding Preference Modal Shortcode
     * [rts_onboarding]
     */
    public function render_onboarding($atts) {
        $atts = shortcode_atts([], $atts, 'rts_onboarding');
        
        ob_start();
        ?>
        <div id="rts-onboarding-modal" class="rts-onboarding-container" style="display:none;">
            <!-- Onboarding modal content will be rendered by JavaScript -->
            <div class="rts-onboarding-content">
                <!-- JS will populate this -->
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Letter Viewer Shortcode - SERVER-SIDE RENDERING
     * [rts_letter_viewer show_filters="no" show_helpful="yes" show_share="yes" show_next="yes"]
     */
    public function render_letter_viewer($atts) {
        $atts = shortcode_atts([
            'show_filters' => 'no',  // Changed default to 'no'
            'show_helpful' => 'yes',
            'show_share' => 'yes',
            'show_next' => 'yes',
            'initial_feeling' => '',
            'initial_tone' => '',
        ], $atts, 'rts_letter_viewer');
        
        // Convert yes/no to boolean
        $show_filters = in_array(strtolower($atts['show_filters']), ['yes', '1', 'true']);
        $show_helpful = in_array(strtolower($atts['show_helpful']), ['yes', '1', 'true']);
        $show_share = in_array(strtolower($atts['show_share']), ['yes', '1', 'true']);
        $show_next = in_array(strtolower($atts['show_next']), ['yes', '1', 'true']);
        
        // Fetch initial letter server-side
        $letter = $this->fetch_random_letter($atts['initial_feeling'], $atts['initial_tone']);
        
        ob_start();
        ?>
        <div class="rts-letter-viewer-container" 
             data-show-filters="<?php echo $show_filters ? '1' : '0'; ?>"
             data-initial-feeling="<?php echo esc_attr($atts['initial_feeling']); ?>"
             data-initial-tone="<?php echo esc_attr($atts['initial_tone']); ?>">
            
            <?php if ($show_filters): ?>
            <div class="rts-filters" style="text-align: right; margin-bottom: 2rem;">
                <div class="rts-filter-group" style="display: inline-block; margin: 0 10px;">
                    <label for="rts-feeling-filter" style="margin-right: 8px;">I'm feeling:</label>
                    <select id="rts-feeling-filter" class="rts-filter-select" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">Any feeling</option>
                        <?php
                        $feelings = get_terms([
                            'taxonomy' => 'letter_feeling',
                            'hide_empty' => false,
                        ]);
                        
                        if (!is_wp_error($feelings) && !empty($feelings)) {
                            foreach ($feelings as $feeling) {
                                $selected = ($atts['initial_feeling'] === $feeling->slug) ? ' selected' : '';
                                echo '<option value="' . esc_attr($feeling->slug) . '"' . $selected . '>' . esc_html(ucfirst($feeling->name)) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
                
                <div class="rts-filter-group" style="display: inline-block; margin: 0 10px;">
                    <label for="rts-tone-filter" style="margin-right: 8px;">I need:</label>
                    <select id="rts-tone-filter" class="rts-filter-select" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">Any tone</option>
                        <?php
                        $tones = get_terms([
                            'taxonomy' => 'letter_tone',
                            'hide_empty' => false,
                        ]);
                        
                        if (!is_wp_error($tones) && !empty($tones)) {
                            foreach ($tones as $tone) {
                                $selected = ($atts['initial_tone'] === $tone->slug) ? ' selected' : '';
                                echo '<option value="' . esc_attr($tone->slug) . '"' . $selected . '>' . esc_html(ucfirst($tone->name)) . '</option>';
                            }
                        }
                        ?>
                    </select>
                </div>
            </div>
            <?php endif; ?>
            
            <div id="rts-letter-display" class="rts-letter-display">
                <?php if ($letter): ?>
                    <?php $this->render_letter_content($letter); ?>
                <?php else: ?>
                    <div style="padding: 3rem; text-align: center; background: #f8f9fa; border-radius: 8px;">
                        <p style="font-size: 1.2rem; color: #666; margin-bottom: 1rem;">No letters found.</p>
                        <p style="color: #999;">
                            <?php 
                            $total = wp_count_posts('letter');
                            echo 'Total letters in database: ' . ($total->publish + $total->pending);
                            ?>
                        </p>
                        <?php if ($total->pending > 0 && $total->publish == 0): ?>
                        <p style="color: #d63638; font-weight: bold;">
                            You have <?php echo $total->pending; ?> pending letters. 
                            <a href="<?php echo admin_url('edit.php?post_type=letter&post_status=pending'); ?>" style="color: #d63638;">Approve them to display here →</a>
                        </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="rts-letter-actions" style="text-align: center; margin-top: 2rem; display: <?php echo ($show_next || $show_share || $show_helpful) ? 'block' : 'none'; ?>;">
                <?php if ($show_next): ?>
                <button id="rts-next-letter" class="rts-btn rts-btn-primary" style="padding: 12px 32px; background: #070C13; color: white; border: none; border-radius: 4px; font-size: 1rem; margin: 0 8px; cursor: pointer;">
                    Next Letter
                </button>
                <?php endif; ?>
                
                <?php if ($show_share): ?>
                <button id="rts-share-letter" class="rts-btn rts-btn-secondary" style="padding: 12px 32px; background: #fff; color: #070C13; border: 2px solid #070C13; border-radius: 4px; font-size: 1rem; margin: 0 8px; cursor: pointer;">
                    Share This Letter
                </button>
                <?php endif; ?>
                
                <?php if ($show_helpful): ?>
                <div id="rts-helpful-feedback" style="margin-top: 1.5rem;">
                    <p style="margin-bottom: 0.5rem; color: #666;">Was this letter helpful?</p>
                    <button class="rts-btn rts-btn-helpful" data-vote="yes" style="padding: 8px 24px; background: #4CAF50; color: white; border: none; border-radius: 4px; margin: 0 5px; cursor: pointer;">
                        👍 Yes
                    </button>
                    <button class="rts-btn rts-btn-helpful" data-vote="no" style="padding: 8px 24px; background: #666; color: white; border: none; border-radius: 4px; margin: 0 5px; cursor: pointer;">
                        👎 Not really
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        .rts-letter-viewer-container {
            max-width: 900px;
            margin: 0 auto;
        }
        
        .rts-letter-display {
            background: white;
            border-radius: 8px;
            padding: 3rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            min-height: 400px;
        }
        
        .rts-letter-content {
            font-family: 'Special Elite', 'Courier New', monospace;
            line-height: 1.8;
            color: #070C13;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .rts-letter-intro {
            text-align: right;
            font-style: italic;
            color: #666;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eee;
        }
        
        .rts-btn:hover {
            opacity: 0.9;
            transform: translateY(-1px);
            transition: all 0.2s;
        }
        
        .rts-loading {
            text-align: center;
            padding: 3rem;
        }
        
        .rts-spinner {
            border: 4px solid #f3f3f3;
            border-top: 4px solid #FCA311;
            border-radius: 50%;
            width: 40px;
            height: 40px;
            animation: spin 1s linear infinite;
            margin: 0 auto 1rem;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @media (max-width: 768px) {
            .rts-letter-display {
                padding: 1.5rem;
            }
            
            .rts-filters {
                text-align: center !important;
            }
            
            .rts-filter-group {
                display: block !important;
                margin: 10px 0 !important;
            }
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Fetch a random letter based on filters
     */
    private function fetch_random_letter($feeling = '', $tone = '') {
        // Try published letters first
        $args = [
            'post_type' => 'letter',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'orderby' => 'rand',
            'no_found_rows' => true,
        ];
        
        // Add taxonomy filters if provided
        if ($feeling || $tone) {
            $args['tax_query'] = ['relation' => 'AND'];
            
            if ($feeling) {
                $args['tax_query'][] = [
                    'taxonomy' => 'letter_feeling',
                    'field' => 'slug',
                    'terms' => $feeling,
                ];
            }
            
            if ($tone) {
                $args['tax_query'][] = [
                    'taxonomy' => 'letter_tone',
                    'field' => 'slug',
                    'terms' => $tone,
                ];
            }
        }
        
        $query = new WP_Query($args);
        
        // If no published letters found, try pending (for new sites)
        if (!$query->have_posts()) {
            $args['post_status'] = 'pending';
            $query = new WP_Query($args);
        }
        
        if ($query->have_posts()) {
            $query->the_post();
            $letter = get_post();
            wp_reset_postdata();
            
            // Increment view count
            $view_count = get_post_meta($letter->ID, 'view_count', true);
            update_post_meta($letter->ID, 'view_count', intval($view_count) + 1);
            
            return $letter;
        }
        
        return null;
    }
    
    /**
     * Render letter content HTML
     */
    private function render_letter_content($letter) {
        if (!$letter || empty($letter->post_content)) {
            ?>
            <div style="padding: 3rem; text-align: center; background: #f8f9fa; border-radius: 8px;">
                <p style="font-size: 1.2rem; color: #666;">This letter has no content. Please check the database.</p>
            </div>
            <?php
            return;
        }
        ?>
        <div class="rts-letter-content" data-letter-id="<?php echo esc_attr($letter->ID); ?>">
            <div class="rts-letter-intro">
                <?php echo esc_html($this->get_letter_intro_text()); ?>
            </div>
            
            <div class="rts-letter-body">
                <?php 
                // Apply the_content filters to ensure proper rendering
                $content = apply_filters('the_content', $letter->post_content);
                echo $content;
                ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Get random intro text
     */
    private function get_letter_intro_text() {
        $intros = [
            'This letter was written by someone in the world that cares. It was delivered to you at random when you opened this page.',
            'A stranger wrote this for you. They wanted you to know you matter.',
            'This letter found you for a reason. Someone took the time to write it, hoping it would help.',
            'These words were written by someone who understands. They are meant for you today.',
        ];
        
        return $intros[array_rand($intros)];
    }
    
    /**
     * Submit Form Shortcode
     * [rts_submit_form]
     */
    public function render_submit_form($atts) {
        $atts = shortcode_atts([
            'show_instructions' => 'yes',
            'require_email' => 'no',
        ], $atts, 'rts_submit_form');
        
        $show_instructions = in_array(strtolower($atts['show_instructions']), ['yes', '1', 'true']);
        $require_email = in_array(strtolower($atts['require_email']), ['yes', '1', 'true']);
        
        ob_start();
        ?>
        <div class="rts-submit-form-container">
            
            <?php if ($show_instructions): ?>
            <div class="rts-form-instructions">
                <h3>Write Your Letter</h3>
                <p>Share what you're going through. Your words might be exactly what someone else needs to hear.</p>
                <ul class="rts-guidelines">
                    <li>Be honest and authentic</li>
                    <li>Share what helped you</li>
                    <li>Offer hope without minimizing pain</li>
                    <li>Keep it focused (200-500 words works best)</li>
                </ul>
            </div>
            <?php endif; ?>
            
            <form id="rts-submit-form" class="rts-form">
                
                <div class="rts-form-group">
                    <label for="rts-letter-content" class="rts-required">Your Letter</label>
                    <textarea 
                        id="rts-letter-content" 
                        name="letter_content" 
                        class="rts-textarea"
                        placeholder="Dear Friend,&#10;&#10;I know how hard it is right now..."
                        rows="12"
                        required
                    ></textarea>
                    <div class="rts-char-counter">
                        <span id="rts-char-count">0</span> characters
                    </div>
                </div>
                
                <div class="rts-form-row">
                    <div class="rts-form-group">
                        <label for="rts-letter-feeling">I'm writing to someone who feels:</label>
                        <select id="rts-letter-feeling" name="letter_feeling" class="rts-select">
                            <option value="">Select a feeling (optional)</option>
                            <?php
                            $feelings = get_terms([
                                'taxonomy' => 'letter_feeling',
                                'hide_empty' => false,
                            ]);
                            
                            if (!is_wp_error($feelings) && !empty($feelings)) {
                                foreach ($feelings as $feeling) {
                                    echo '<option value="' . esc_attr($feeling->slug) . '">' . esc_html(ucfirst($feeling->name)) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="rts-form-group">
                        <label for="rts-letter-tone">My tone is:</label>
                        <select id="rts-letter-tone" name="letter_tone" class="rts-select">
                            <option value="">Select a tone (optional)</option>
                            <?php
                            $tones = get_terms([
                                'taxonomy' => 'letter_tone',
                                'hide_empty' => false,
                            ]);
                            
                            if (!is_wp_error($tones) && !empty($tones)) {
                                foreach ($tones as $tone) {
                                    echo '<option value="' . esc_attr($tone->slug) . '">' . esc_html(ucfirst($tone->name)) . '</option>';
                                }
                            }
                            ?>
                        </select>
                    </div>
                </div>
                
                <div class="rts-form-group">
                    <label for="rts-letter-email">
                        Email (optional<?php echo $require_email ? ', but recommended' : ''; ?>)
                    </label>
                    <input 
                        type="email" 
                        id="rts-letter-email" 
                        name="letter_email" 
                        class="rts-input"
                        placeholder="your@email.com"
                        <?php echo $require_email ? 'required' : ''; ?>
                    />
                    <p class="rts-field-note">We'll only use this to notify you if your letter helps someone</p>
                </div>
                
                <!-- Honeypot (hidden anti-spam field) -->
                <div class="rts-hp-field" style="position:absolute;left:-9999px;" aria-hidden="true">
                    <input type="text" name="website_url" tabindex="-1" autocomplete="off" />
                </div>
                
                <div class="rts-form-actions">
                    <button type="submit" id="rts-submit-btn" class="rts-btn rts-btn-primary rts-btn-large">
                        <span class="rts-btn-text">Submit Letter</span>
                        <span class="rts-btn-spinner" style="display:none;">
                            <span class="spinner"></span> Submitting...
                        </span>
                    </button>
                </div>
                
                <div id="rts-submit-feedback" class="rts-feedback" style="display:none;"></div>
                
            </form>
        </div>
        
        <style>
        .rts-submit-form-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 2rem;
        }
        
        .rts-form-instructions {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: #f8f9fa;
            border-left: 4px solid #FCA311;
            border-radius: 4px;
        }
        
        .rts-form-instructions h3 {
            margin-top: 0;
            color: #070C13;
        }
        
        .rts-guidelines {
            margin: 1rem 0 0 1.5rem;
        }
        
        .rts-guidelines li {
            margin: 0.5rem 0;
            color: #495057;
        }
        
        .rts-form-group {
            margin-bottom: 1.5rem;
        }
        
        .rts-form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #070C13;
        }
        
        .rts-required::after {
            content: " *";
            color: #dc3545;
        }
        
        .rts-textarea,
        .rts-input,
        .rts-select {
            width: 100%;
            padding: 0.75rem;
            border: 2px solid #dee2e6;
            border-radius: 4px;
            font-size: 1rem;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        
        .rts-textarea:focus,
        .rts-input:focus,
        .rts-select:focus {
            outline: none;
            border-color: #FCA311;
        }
        
        .rts-char-counter {
            text-align: right;
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
        
        .rts-form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        
        @media (max-width: 768px) {
            .rts-form-row {
                grid-template-columns: 1fr;
            }
        }
        
        .rts-field-note {
            font-size: 0.875rem;
            color: #6c757d;
            margin-top: 0.25rem;
        }
        
        .rts-btn {
            padding: 0.75rem 1.5rem;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .rts-btn-primary {
            background: #FCA311;
            color: #070C13;
        }
        
        .rts-btn-primary:hover {
            background: #e8920f;
        }
        
        .rts-btn-large {
            padding: 1rem 2rem;
            font-size: 1.125rem;
        }
        
        .rts-feedback {
            margin-top: 1rem;
            padding: 1rem;
            border-radius: 4px;
        }
        
        .rts-feedback.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .rts-feedback.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        </style>
        <?php
        return ob_get_clean();
    }
}

// Initialize
RTS_Shortcodes::get_instance();

} // end class_exists check
