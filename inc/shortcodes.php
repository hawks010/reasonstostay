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
        <div class="rts-submit-wrap">
            <div class="rts-submit-grid">
                <div class="rts-submit-panel">
                    <form id="rts-submit-form" class="rts-submit-form" novalidate>

                        <label for="rts-letter-text" class="rts-field-label">Your letter <span aria-hidden="true">*</span></label>
                        <textarea id="rts-letter-text" name="letter" rows="12" required minlength="50" placeholder="Dear Friend,"></textarea>
                        <div class="rts-char-count" aria-live="polite">0 characters (minimum 50)</div>

                        <div class="rts-field">
                            <label for="rts-email" class="rts-field-label">Your email <span class="rts-muted">*</span></label>
                            <input id="rts-email" name="email" type="email" required autocomplete="email" />
                            <div class="rts-help">For moderation contact only, will never be published</div>
                        </div>


                        <div class="rts-consent-row">
                            <input id="rts-consent" type="checkbox" name="consent" value="1" required />
                            <label for="rts-consent">I understand my letter will be reviewed before publishing <span aria-hidden="true">*</span></label>
                        </div>

                        <div class="rts-submit-actions">
                            <button type="submit" class="rts-submit-btn">Submit Letter</button>
                        </div>


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
        static $rts_modal_rendered = false;
        if (!$rts_modal_rendered):
            $rts_modal_rendered = true;
        ?>
        <div id="rts-feedback-modal" class="rts-modal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="rts-feedback-title">
            <div class="rts-modal-backdrop" data-rts-close="1"></div>
            <div class="rts-modal-panel" role="document">
                <div class="rts-modal-header">
                    <h3 id="rts-feedback-title">Feedback</h3>
                    <button type="button" class="rts-modal-close" data-rts-close="1" aria-label="Close feedback">×</button>
                </div>

                <form class="rts-feedback-form" novalidate>
                    <input type="hidden" name="letter_id" value="">
                    <input type="text" name="website" value="" autocomplete="off" tabindex="-1" style="position:absolute;left:-9999px;opacity:0;" aria-hidden="true">

                    <div class="rts-field">
                        <label for="rts-feedback-rating">How did this letter land?</label>
                        <select id="rts-feedback-rating" name="rating" required>
                            <option value="neutral">Not sure</option>
                            <option value="up">It helped</option>
                            <option value="down">It did not help / Concern</option>
                        </select>
                    </div>

                    <div class="rts-field">
                        <label for="rts-feedback-comment">Your message (optional)</label>
                        <textarea id="rts-feedback-comment" name="comment" rows="5" placeholder="Tell us what you think. If something feels unsafe, say why."></textarea>
                    </div>

                    <div class="rts-field">
                        <label for="rts-feedback-email">Email (optional)</label>
                        <input id="rts-feedback-email" type="email" name="email" placeholder="Only if you want a follow-up">
                    </div>

                    <div class="rts-field rts-field-inline">
                        <input id="rts-feedback-triggered" type="checkbox" name="triggered" value="1">
                        <label for="rts-feedback-triggered">This letter triggered me or I am worried about its safety</label>
                    </div>

                    <div class="rts-modal-actions" style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px;">
                        <button type="button" class="rts-btn rts-btn-secondary" data-rts-close="1">Cancel</button>
                        <button type="submit" class="rts-btn rts-btn-primary">Send feedback</button>
                    </div>

                    <p class="rts-feedback-status" aria-live="polite" style="margin:12px 0 0 0;"></p>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php
        return ob_get_clean();
    }
}

} // class_exists

RTS_Shortcodes::get_instance();
