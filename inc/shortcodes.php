<?php
/**
 * Reasons to Stay - Shortcodes
 *
 * Notes:
 * - Markup matches assets/js/rts-system.js (IDs/classes).
 * - First letter is rendered server-side for reliability.
 * - We do NOT add a separate signature line (letter body already contains it).
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
        add_shortcode('rts_onboarding', [$this, 'render_onboarding']);
        add_shortcode('rts_letter_viewer', [$this, 'render_letter_viewer']);

        // Keep backwards compatibility with older pages
        add_shortcode('rts_submit_form', [$this, 'render_write_letter']);
        add_shortcode('rts_write_letter', [$this, 'render_write_letter']);
        add_shortcode('rts_write_letter_form', [$this, 'render_write_letter']);
    }

    public function render_onboarding() {
        return '<div id="rts-onboarding-modal" class="rts-onboarding" style="display:none" aria-hidden="true"></div>';
    }

    /**
     * [rts_letter_viewer show_next="yes" show_share="yes" show_helpful="yes"]
     */
    public function render_letter_viewer($atts) {
        $atts = shortcode_atts([
            'show_next' => 'yes',
            'show_share' => 'yes',
            'show_helpful' => 'yes',
        ], $atts, 'rts_letter_viewer');

        $show_next = in_array(strtolower($atts['show_next']), ['1','yes','true'], true);
        $show_share = in_array(strtolower($atts['show_share']), ['1','yes','true'], true);
        $show_helpful = in_array(strtolower($atts['show_helpful']), ['1','yes','true'], true);

        $letter = $this->get_random_published_letter();

        ob_start();
        ?>
        <style>
        /* Letter viewer styling */
        .rts-btn-next {
            background: #2c3e50 !important;
            color: white !important;
            border: none !important;
            padding: 12px 30px !important;
            font-size: 16px !important;
            font-weight: 600 !important;
            border-radius: 4px !important;
            cursor: pointer !important;
            transition: all 0.3s !important;
            float: right !important;
            margin-top: 20px !important;
        }
        
        .rts-btn-next:hover {
            background: #f0b849 !important;
            color: #2c3e50 !important;
        }
        
        /* Feedback and Report tabs */
        .rts-feedback-tab,
        .rts-report-tab {
            position: absolute !important;
            top: 0 !important;
            left: 0 !important;
            background: #2c3e50 !important;
            color: white !important;
            padding: 10px 20px !important;
            border: none !important;
            cursor: pointer !important;
            transition: all 0.3s !important;
            font-size: 14px !important;
            font-weight: 600 !important;
            z-index: 10 !important;
        }
        
        .rts-report-tab {
            left: auto !important;
            right: 0 !important;
        }
        
        .rts-feedback-tab:hover,
        .rts-report-tab:hover {
            background: #f0b849 !important;
            color: #2c3e50 !important;
        }
        
        .rts-letter-card {
            position: relative !important;
            background: var(--rts-white, #fff);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06), 0 4px 8px rgba(0, 0, 0, 0.04), 0 0 0 1px rgba(0, 0, 0, 0.02);
            border-radius: 2px;
            padding: 40px 30px 30px 30px !important;
        }
        </style>
        
        <div class="rts-letter-viewer" data-rts-viewer="1">
            <div id="rts-letter-stage" class="rts-letter-stage">
                <?php if ($letter): ?>
                    <?php echo $this->render_letter_card($letter, $show_next, $show_share, $show_helpful); ?>
                <?php else: ?>
                    <div class="rts-letter-card" style="padding:24px;">
                        <p>No letters found yet.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Write a Letter (front-end) form
     * - Must match rts-system.js selectors:
     *   #rts-submit-form, #rts-letter-text, .rts-char-count
     */
    public function render_write_letter($atts) {
        $atts = shortcode_atts([
            'show_guidelines' => 'yes',
        ], $atts, 'rts_write_letter');

        $show_guidelines = in_array(strtolower($atts['show_guidelines']), ['1','yes','true'], true);

        ob_start();
        ?>
        <style>
        /* Paper effect for write letter form */
        .rts-submit-form {
            background: var(--rts-white, #fff);
            position: relative;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.06), 0 4px 8px rgba(0, 0, 0, 0.04), 0 0 0 1px rgba(0, 0, 0, 0.02);
            border-radius: 2px;
            padding: 30px;
        }
        
        .rts-submit-btn {
            background: #2c3e50 !important;
            color: white !important;
            border: none !important;
            padding: 12px 30px !important;
            font-size: 16px !important;
            font-weight: 600 !important;
            border-radius: 4px !important;
            cursor: pointer !important;
            transition: background 0.3s !important;
        }
        
        .rts-submit-btn:hover {
            background: #f0b849 !important;
            color: #2c3e50 !important;
        }
        </style>
        
        <div class="rts-submit-wrap">
            <div class="rts-submit-grid">
                <div class="rts-submit-panel">
                    <form id="rts-submit-form" class="rts-submit-form" novalidate>

                        <label for="rts-letter-text" class="rts-field-label">Your letter <span aria-hidden="true">*</span></label>
                        <textarea id="rts-letter-text" name="letter" rows="12" required minlength="50" placeholder="Dear Friend,\n\n..."></textarea>
                        <div class="rts-char-count" aria-live="polite">0 characters (minimum 50)</div>

                        <div class="rts-field">
                            <label for="rts-email" class="rts-field-label">Your email <span class="rts-muted">*</span></label>
                            <input id="rts-email" name="email" type="email" required autocomplete="email" />
                            <div class="rts-help">For moderation contact only, will never be published</div>
                        </div>

                        <label class="rts-consent">
                            <input type="checkbox" name="consent" value="1" required />
                            <span>I understand my letter will be reviewed before publishing <span aria-hidden="true">*</span></span>
                        </label>

                        <button type="submit" class="rts-submit-btn">
                            <span class="rts-btn-text">Submit Letter</span>
                            <span class="rts-btn-spinner" aria-hidden="true" style="display:none">…</span>
                        </button>

                        <div class="rts-submit-response" aria-live="polite"></div>
                    </form>
                </div>

                <?php if ($show_guidelines): ?>
                <aside class="rts-submit-instructions" id="rts-writing-guidelines">
                    <h3>Writing Guidelines</h3>
                    <div class="rts-guideline">
                        <h4>✍️ Write from the Heart</h4>
                        <p>Focus on writing something warm and supportive. If you need inspiration, you can read existing letters on the site.</p>
                    </div>
                    <div class="rts-guideline">
                        <h4>📝 Don't Worry About Perfect</h4>
                        <p>All submissions are reviewed and may be lightly edited to make sure they're ready to be published and delivered to someone. So don't worry about making it perfect.</p>
                    </div>
                    <div class="rts-guideline">
                        <h4>💚 Your Impact</h4>
                        <p>Your letter will be randomly delivered to someone who needs hope. You could make a real difference in someone's life today.</p>
                    </div>
                </aside>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_term_options($taxonomy) {
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
        ]);
        if (is_wp_error($terms) || empty($terms)) return '';

        $out = '';
        foreach ($terms as $t) {
            $out .= '<option value="' . esc_attr($t->slug) . '">' . esc_html($t->name) . '</option>';
        }
        return $out;
    }

    private function get_random_published_letter() {
        $q = new WP_Query([
            'post_type' => 'letter',
            'post_status' => 'publish',
            'posts_per_page' => 1,
            'orderby' => 'rand',
            'no_found_rows' => true,
        ]);
        return ($q->have_posts()) ? $q->posts[0] : null;
    }

    private function render_letter_card($letter, $show_next, $show_share, $show_helpful) {
        $letter_id = (int) $letter->ID;
        $content = apply_filters('the_content', $letter->post_content);

        ob_start();
        ?>
        <article class="rts-letter-card" id="rts-letter-card" data-letter-id="<?php echo esc_attr($letter_id); ?>">
            <div class="rts-letter-meta">
                <p class="rts-letter-intro">This letter was written by someone in the world that cares. It was delivered to you at random when you opened this page.</p>
            </div>

            <div class="rts-letter-body" id="rts-letter-content">
                <?php echo $content; ?>
            </div>

            <!-- Top-left tag icons (Feedback / Report) -->
            <div class="rts-letter-top-tags">
                <button type="button" class="rts-tag-btn rts-feedback-open" data-tooltip="Give feedback" aria-label="Give feedback">
                    <i class="dashicons dashicons-admin-comments"></i>
                </button>
                <button type="button" class="rts-tag-btn rts-trigger-open" data-tooltip="Report a concern" aria-label="Report a concern">
                    <i class="dashicons dashicons-flag"></i>
                </button>
            </div>

            <!-- Card footer with Read Another Letter button -->
            <div class="rts-letter-card-footer" style="display:<?php echo ($show_next ? 'flex':'none'); ?>;">
                <button type="button" class="rts-btn rts-btn-next">Read Another Letter</button>
            </div>

            <?php if ($show_helpful || $show_share): ?>
            <div class="rts-letter-share" style="display:none" aria-hidden="true"></div>
            <?php endif; ?>
        </article>
        <?php
        return ob_get_clean();
    }
}

} // class_exists

RTS_Shortcodes::get_instance();
