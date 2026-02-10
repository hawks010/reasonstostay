<?php

class RTS_Shortcodes {

    // Track if onboarding has been rendered to prevent duplicates
    private static $onboarding_rendered = false;

    /**
     * Constructor: Registers all shortcodes
     */
    public function __construct() {
        add_shortcode('rts_share_buttons', [$this, 'share_buttons']);
        add_shortcode('rts_letter_viewer', [$this, 'letter_viewer']);
        add_shortcode('rts_onboarding', [$this, 'onboarding']);
        add_shortcode('rts_submit_form', [$this, 'submit_form']);
        add_shortcode('rts_site_stats_row', [$this, 'site_stats_row']);
        add_action('rest_api_init', [$this, 'register_site_stats_route']);
        // REST endpoint used by rts-system.js for front-end letter submissions
        add_action('rest_api_init', [$this, 'register_letter_submit_route']);
        
        // Front-end letter submission handler (AJAX fallback when REST is blocked)
        add_action('wp_ajax_nopriv_rts_submit_letter', [$this, 'ajax_submit_letter']);
        add_action('wp_ajax_rts_submit_letter', [$this, 'ajax_submit_letter']);

        // Add cache invalidation hooks
        add_action('save_post_letter', [$this, 'clear_site_stats_cache']);
        add_action('delete_post', [$this, 'maybe_clear_site_stats_cache']);
    }

    /**
     * REST: Register /rts/v1/letter/submit.
     * This is the primary endpoint used by assets/js/rts-system.js.
     */
    public function register_letter_submit_route(): void {
        register_rest_route('rts/v1', '/letter/submit', [
            'methods'             => 'POST',
            'callback'            => [$this, 'rest_submit_letter'],
            // Public submission is allowed, we enforce nonces + bot checks inside.
            'permission_callback' => '__return_true',
            'args'                => [],
        ]);
    }

    /**
     * REST: Handle front-end letter submissions.
     */
    public function rest_submit_letter(\WP_REST_Request $request) {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new \WP_REST_Response(['success' => false, 'message' => 'Invalid request.'], 400);
        }

        // Accept nonce from header (X-WP-Nonce) or payload (rts_token)
        $header_nonce = $request->get_header('X-WP-Nonce');
        if ($header_nonce && empty($payload['rts_token'])) {
            $payload['rts_token'] = $header_nonce;
        }

        $result = $this->process_letter_submission($payload);
        $status = isset($result['status']) ? (int) $result['status'] : 200;
        unset($result['status']);
        return new \WP_REST_Response($result, $status);
    }

    /**
     * Clear site stats cache when letters are saved
     */
    public function clear_site_stats_cache($post_id) {
        if (get_post_type($post_id) === 'letter') {
            $this->clear_all_site_stats_cache();
        }
    }

    /**
     * Clear site stats cache when letters are deleted
     */
    public function maybe_clear_site_stats_cache($post_id) {
        if (get_post_type($post_id) === 'letter') {
            $this->clear_all_site_stats_cache();
        }
    }

    /**
     * Clear all site stats cache
     */
    private function clear_all_site_stats_cache() {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE %s 
            OR option_name LIKE %s",
            '_transient_rts_site_stats%',
            '_transient_timeout_rts_site_stats%'
        ));
    }

    /**
     * Reset onboarding render state (useful for testing or specific flows)
     */
    public static function reset_onboarding() {
        self::$onboarding_rendered = false;
    }

    /**
     * [rts_share_buttons] - Share buttons block (standalone)
     */
    public function share_buttons($atts) {
        $atts = shortcode_atts([
            'label' => 'Help us reach more people by sharing this site:'
        ], $atts);

        ob_start();
        ?>
        <div class="rts-letter-share">
            <p class="rts-share-label"><?php echo esc_html($atts['label']); ?></p>
            <div class="rts-share-buttons">
                <a href="#" class="share-btn rts-share-btn" data-platform="facebook" target="_blank" rel="noopener noreferrer" aria-label="Share on Facebook">
                    <i class="fab fa-facebook-f" aria-hidden="true"></i>
                    <span>Facebook</span>
                </a>
                <a href="#" class="share-btn rts-share-btn" data-platform="x" target="_blank" rel="noopener noreferrer" aria-label="Share on X">
                    <i class="fab fa-x-twitter" aria-hidden="true"></i>
                    <span>X</span>
                </a>
                <a href="#" class="share-btn rts-share-btn" data-platform="whatsapp" target="_blank" rel="noopener noreferrer" aria-label="Share on WhatsApp">
                    <i class="fab fa-whatsapp" aria-hidden="true"></i>
                    <span>WhatsApp</span>
                </a>
                <a href="#" class="share-btn rts-share-btn" data-platform="reddit" target="_blank" rel="noopener noreferrer" aria-label="Share on Reddit">
                    <i class="fab fa-reddit-alien" aria-hidden="true"></i>
                    <span>Reddit</span>
                </a>
                <a href="#" class="share-btn rts-share-btn" data-platform="threads" target="_blank" rel="noopener noreferrer" aria-label="Share on Threads">
                    <i class="fab fa-threads" aria-hidden="true"></i>
                    <span>Threads</span>
                </a>
                <button class="share-btn rts-share-btn" type="button" data-platform="copy" aria-label="Copy link">
                    <i class="fas fa-link" aria-hidden="true"></i>
                    <span>Copy link</span>
                </button>
                <a href="#" class="share-btn rts-share-btn" data-platform="email" aria-label="Share via email">
                    <i class="fas fa-envelope" aria-hidden="true"></i>
                    <span>Email</span>
                </a>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * [rts_letter_viewer] - Main letter display
     */
    public function letter_viewer($atts) {
      try {
        $atts = shortcode_atts([
            'show_helpful' => 'yes',
            'show_share' => 'yes',
            'show_next' => 'yes',
            'show_onboarding' => 'yes'], $atts);

        ob_start();
        
        // Only show onboarding if enabled
        if ($atts['show_onboarding'] === 'yes') {
            echo $this->onboarding([]);
        }
        ?>
        <div class="rts-letter-viewer" data-component="viewer">
            <div class="rts-loading">
                <div class="rts-spinner"></div>
                <p>Finding a letter for you...</p>
            </div>
            
            <div class="rts-letter-display" style="display:none;">
                <div class="rts-letter-card">
                    <div class="rts-letter-tabs" aria-label="Letter options">
                        <button type="button" class="rts-feedback-tab rts-feedback-open" aria-haspopup="dialog" aria-controls="rts-feedback-modal">
                            Feedback
                        </button>
                    </div>
                    <div class="rts-letter-content">
                        <div class="rts-letter-body" tabindex="-1"></div>
                        <div class="rts-letter-footer" aria-label="Letter actions">
                            <?php if ($atts['show_next'] === 'yes') : ?>
                            <button class="rts-report-link rts-trigger-open" type="button" data-rts-trigger="1">Report</button>
                            <button class="rts-btn rts-btn-next" type="button">Read Another Letter</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="rts-letter-rate-wrap" aria-label="Rate this letter">
                    <?php if ($atts['show_helpful'] === 'yes') : ?>
                    <div class="rts-rate-prompt" hidden aria-live="polite">
                        <span class="rts-rate-prompt-text">Before the next letter, how was that one?</span>
                        <div class="rts-rate-prompt-actions" role="group">
                            <button type="button" class="rts-rate-btn rts-rate-up" aria-label="This helped">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 9V5a3 3 0 0 0-3-3l-1 7-4 5v9h11a2 2 0 0 0 2-1.6l1-9A2 2 0 0 0 18 10h-4z"/><path d="M6 23H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h2"/></svg>
                                <span>Helped</span>
                            </button>
                            <button type="button" class="rts-rate-btn rts-rate-down" aria-label="Not for me">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10 15v4a3 3 0 0 0 3 3l1-7 4-5V1H7a2 2 0 0 0-2 1.6l-1 9A2 2 0 0 0 6 14h4z"/><path d="M18 1h2a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-2"/></svg>
                                <span>Not for me</span>
                            </button>
                            <button type="button" class="rts-rate-skip">Skip</button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Feedback modal -->
                <div class="rts-modal" id="rts-feedback-modal" role="dialog" aria-modal="true" aria-hidden="true">
                    <div class="rts-modal-backdrop" data-rts-close></div>
                    <div class="rts-modal-panel" role="document">
                        <div class="rts-modal-header">
                            <h3 id="rts-feedback-title">Feedback</h3>
                            <button type="button" class="rts-modal-close" data-rts-close>×</button>
                        </div>
                        <form class="rts-feedback-form" autocomplete="on">
                            <input type="hidden" name="letter_id" value="">
                            <?php $this->render_honeypot_fields(); ?>
                            <div class="rts-field">
                                <label for="rts-feedback-rating">Overall</label>
                                <select id="rts-feedback-rating" name="rating">
                                    <option value="neutral">No reaction</option>
                                    <option value="up">👍 Helped</option>
                                    <option value="down">👎 Didn’t help</option>
                                </select>
                            </div>
                            <div class="rts-field">
                                <label for="rts-feedback-mood-change">How did reading the letter make you feel?</label>
                                <select id="rts-feedback-mood-change" name="mood_change" required>
                                    <option value="" selected disabled>Select an option</option>
                                    <option value="much_better">Much better</option>
                                    <option value="little_better">A little better</option>
                                    <option value="no_change">No change</option>
                                    <option value="little_worse">A little worse</option>
                                    <option value="much_worse">Much worse</option>
                                </select>
                            </div>
                            <div class="rts-field rts-field-inline">
                                <input id="rts-feedback-triggered" type="checkbox" name="triggered" value="1">
                                <label for="rts-feedback-triggered">This letter felt triggering or unsafe</label>
                            </div>
                            <div class="rts-field">
                                <label for="rts-feedback-comment">Optional note</label>
                                <textarea id="rts-feedback-comment" name="comment" rows="4"></textarea>
                            </div>
                            <div class="rts-actions">
                                <button type="submit" class="rts-btn rts-btn-primary">Send feedback</button>
                                <button type="button" class="rts-btn rts-btn-ghost" data-rts-close>Cancel</button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php 
                if ($atts['show_share'] === 'yes') { 
                    echo wp_kses_post(do_shortcode('[rts_share_buttons]')); 
                } 
                ?>
            </div>
            <!-- Helpful confirmation toast -->
            <div class="rts-helpful-toast" style="display:none;" role="alert">
                <p>✓ Thank you for letting us know</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
      } catch (\Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('RTS letter_viewer shortcode error: ' . $e->getMessage());
        }
        return '<div class="rts-letter-viewer"><p>Unable to load the letter viewer. Please refresh the page.</p></div>';
      }
    }

    /**
     * [rts_onboarding] - CSS3 Implementation (Brand Aligned)
     */
    public function onboarding($atts) {
        // Check if onboarder is enabled
        if ( ! get_option( 'rts_onboarder_enabled', true ) ) {
            return '';
        }

        if (self::$onboarding_rendered) {
            return '';
        }
        self::$onboarding_rendered = true;

        ob_start();
        ?>
        <div class="rts-onboarding-wrapper">
        <div class="rts-onboarding-overlay" style="display:none;" aria-hidden="true">
            <link href="https://fonts.googleapis.com/css2?family=Special+Elite&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">

            <!-- Onboarding styles consolidated into assets/css/rts-system.css -->
            <style>
                /* CSS variables kept inline because they define the onboarding colour tokens */
                :root {
                    --brand-cream: #F1E3D3;
                    --brand-gold: #FCA311;
                    --off-white: #FFFFFF;
                    --black: #1A1A1A;
                    --dark-grey: #374151;
                    --medium-grey: #4B5563;
                    --disabled-grey: #9CA3AF;
                }
                /* Remaining onboarding styles now live in assets/css/rts-system.css */
            </style>


            <div class="rts-onboarding-modal" role="dialog" aria-modal="true" aria-labelledby="rts-onboarding-title" aria-describedby="rts-onboarding-desc" tabindex="-1">
                
                <!-- Skip button -->
                <div class="rts-skip-container">
                    <button class="rts-btn-skip rts-skip-trigger" type="button" aria-label="Skip onboarding" onclick="(function(){var w=document.querySelector('.rts-onboarding-wrapper'); if(w) w.style.display='none';})();">
                        Skip
                    </button>
                </div>
            
                <!-- Inner content wrapper -->
                <div class="rts-onboarding-scroll-wrapper">
                    <div class="rts-onboarding-content">
                        
                        <div id="intro-text">
                            <h2 id="rts-onboarding-title">Would you like a letter chosen just for you?</h2>
                            <p id="rts-onboarding-desc">Answer a few quick questions to help us find the right letter, or skip to read any letter.</p>
                        </div>

                        <!-- Step 1 -->
                        <div class="rts-onboarding-step" data-step="1">
                            <h3>What are you feeling right now?</h3>
                            <p class="rts-step-subtitle">Select all that apply</p>
                            
                            <div class="rts-checkbox-group">
                                <label class="rts-checkbox-label"><input type="checkbox" name="feelings[]" value="hopeless"><span>Hopeless</span></label>
                                <label class="rts-checkbox-label"><input type="checkbox" name="feelings[]" value="alone"><span>Alone</span></label>
                                <label class="rts-checkbox-label"><input type="checkbox" name="feelings[]" value="anxious"><span>Anxious</span></label>
                                <label class="rts-checkbox-label"><input type="checkbox" name="feelings[]" value="grieving"><span>Grieving</span></label>
                                <label class="rts-checkbox-label"><input type="checkbox" name="feelings[]" value="tired"><span>Tired of fighting</span></label>
                                <label class="rts-checkbox-label"><input type="checkbox" name="feelings[]" value="struggling"><span>Just struggling</span></label>
                            </div>
                            
                            <div class="rts-step-footer">
                                <div class="rts-progress-dots" aria-hidden="true">
                                    <div class="rts-dot active"></div><div class="rts-dot"></div><div class="rts-dot"></div>
                                </div>
                                <button class="rts-btn rts-btn-next-step" type="button">Next</button>
                            </div>
                        </div>
                        
                        <!-- Step 2 -->
                        <div class="rts-onboarding-step" data-step="2" style="display:none;">
                            <h3>How much time do you have?</h3>
                            <div class="rts-radio-group">
                                <label class="rts-radio-label"><input type="radio" name="readingTime" value="short"><span>Just a minute</span></label>
                                <label class="rts-radio-label"><input type="radio" name="readingTime" value="medium"><span>A few minutes</span></label>
                                <label class="rts-radio-label"><input type="radio" name="readingTime" value="long" checked><span>I can read for a bit</span></label>
                            </div>
                            <div class="rts-step-footer">
                                <div class="rts-progress-dots" aria-hidden="true">
                                    <div class="rts-dot"></div><div class="rts-dot active"></div><div class="rts-dot"></div>
                                </div>
                                <button class="rts-btn rts-btn-next-step" type="button">Next</button>
                            </div>
                        </div>
                        
                        <!-- Step 3 -->
                        <div class="rts-onboarding-step" data-step="3" style="display:none;">
                            <h3>What kind of voice helps you?</h3>
                            <div class="rts-radio-group">
                                <label class="rts-radio-label"><input type="radio" name="tone" value="gentle"><span>Warm and gentle</span></label>
                                <label class="rts-radio-label"><input type="radio" name="tone" value="real"><span>Straight-talking and real</span></label>
                                <label class="rts-radio-label"><input type="radio" name="tone" value="hopeful"><span>Hopeful and uplifting</span></label>
                                <label class="rts-radio-label"><input type="radio" name="tone" value="any" checked><span>Surprise me</span></label>
                            </div>
                            <div class="rts-step-footer">
                                <div class="rts-progress-dots" aria-hidden="true">
                                    <div class="rts-dot"></div><div class="rts-dot"></div><div class="rts-dot active"></div>
                                </div>
                                <button class="rts-btn rts-btn-complete" type="button">Find My Letter</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <script>
            (function() {
                // Inline script to handle visual states (Active/Selected) for inputs
                // Navigation logic is handled globally by rts-system.js
                function initOnboarding() {
                    const wrapper = document.querySelector('.rts-onboarding-overlay');
                    if(!wrapper) return;

                    wrapper.querySelectorAll('input').forEach(input => {
                        if(input.checked) input.closest('label').classList.add('selected');
                        
                        input.addEventListener('change', function() {
                            if(this.type === 'radio') {
                                wrapper.querySelectorAll(`input[name="${this.name}"]`).forEach(el => {
                                    el.closest('label').classList.remove('selected');
                                });
                            }
                            if(this.checked) {
                                this.closest('label').classList.add('selected');
                            } else {
                                this.closest('label').classList.remove('selected');
                            }
                        });
                    });
                }

                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initOnboarding);
                } else {
                    initOnboarding();
                }
            })();
            </script>
        </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * [rts_submit_form]
     */
    public function submit_form($atts) {
      try {
        ob_start();
        ?>
        <div class="rts-submit-form-wrapper">
            <div class="rts-submit-grid">
                <form id="rts-submit-form" class="rts-form" novalidate data-rts-inline-handler="1">
                    <div class="rts-form-header">
                        <h2 class="rts-title">Write a letter</h2>
                        <p class="rts-subtitle">Write something warm and supportive for someone who needs a reason to stay.</p>
                    </div>

                    <div class="rts-field">
                        <label for="author-name">Your first name <span class="rts-optional">(optional)</span></label>
                        <input type="text" id="author-name" name="author_name" placeholder="Enter your first name or a pseudonym..." autocomplete="name">
                    </div>

                    <div class="rts-field">
                        <label for="rts-letter-text">Your letter <span class="rts-required">*</span></label>
                        <textarea id="rts-letter-text" name="letter_text" rows="10" required minlength="50" placeholder="Here you can write a letter to a stranger who's looking for a reason to stay..."></textarea>
                        <small class="rts-help-text"><span class="rts-char-count">0</span> characters (minimum 50)</small>
                    </div>

                    <div class="rts-field">
                        <label for="author-email">Your email <span class="rts-required">*</span></label>
                        <input type="email" id="author-email" name="author_email" required placeholder="Enter your email (for moderation contact only - never published)" autocomplete="email">
                        <small class="rts-help-text">For moderation contact only - will never be published</small>
                    </div>

                    <div class="rts-field rts-consent-field">
                        <label class="rts-consent-label" for="rts-consent">
                            <input type="checkbox" id="rts-consent" name="consent" value="1" required>
                            <span>I understand my letter will be reviewed before publishing <span class="rts-required">*</span></span>
                        </label>
                    </div>

                    <div class="rts-field rts-consent-field">
                        <label class="rts-consent-label" for="rts-subscribe-opt-in">
                            <input type="checkbox" id="rts-subscribe-opt-in" name="subscribe_opt_in" value="1">
                            <span>Yes, send me letters including updates, occasional podcasts and other promos.</span>
                        </label>
                        <small class="rts-help-text">Optional: Get supportive letters plus news on helpful content we create</small>
                    </div>

                    <!-- anti-bot honeypots (must remain empty) -->
                    <div class="rts-honeypot" aria-hidden="true">
                        <label>Website</label>
                        <input type="text" id="rts-website" name="website" tabindex="-1" autocomplete="off">
                        <label>Company</label>
                        <input type="text" id="rts-company" name="company" tabindex="-1" autocomplete="off">
                        <label>Confirm Email</label>
                        <input type="text" id="rts-confirm-email" name="confirm_email" tabindex="-1" autocomplete="off">
                    </div>

                    <input type="hidden" name="rts_token" id="rts-token" value="<?php echo wp_create_nonce('rts_submit_letter'); ?>">
                    <input type="hidden" name="rts_timestamp" id="rts-timestamp" value="">

                    <button type="submit" id="rts-submit-btn" class="rts-btn rts-btn-primary">Submit Letter</button>

                    <div id="rts-form-message" class="rts-form-message" aria-live="polite"></div>

                    <div id="rts-submit-success" class="rts-submit-success" role="status" aria-live="polite" style="display:none;">
                        <h3 class="rts-success-title">Thank you for your letter 💛</h3>
                        <p class="rts-success-text" id="rts-success-message">Your letter has been received and will be reviewed before it can be published and delivered to someone who needs it.</p>
                        <p class="rts-success-text" id="rts-subscription-message" style="display:none;"></p>
                        <div class="rts-success-share">
                            <p class="rts-success-text"><strong>Want to help more people find Reasons to Stay?</strong> Sharing the site is a huge gift.</p>
                            <div class="rts-share-buttons" role="group" aria-label="Share Reasons to Stay">
                                <a class="rts-share-btn" href="#" data-share="copy">Copy link</a>
                                <a class="rts-share-btn" href="#" data-share="facebook">Facebook</a>
                                <a class="rts-share-btn" href="#" data-share="x">X</a>
                                <a class="rts-share-btn" href="#" data-share="whatsapp">WhatsApp</a>
                            </div>
                        </div>
                    </div>
                </form>

                <aside class="rts-submit-instructions" aria-label="Writing guidelines">
                    <h3>Writing Guidelines</h3>
                    <div class="rts-instructions-divider" aria-hidden="true"></div>

                    <div class="rts-guideline">
                        <div class="rts-guideline-title"><span class="rts-emoji" aria-hidden="true">✍️</span> Write from the heart</div>
                        <div class="rts-guideline-text">
                            Focus on writing something warm and supportive. If you need, you can use existing letters as inspiration.
                            Aim for something that helps a stranger feel seen, understood, and less alone.
                        </div>
                    </div>

                    <div class="rts-guideline">
                        <div class="rts-guideline-title"><span class="rts-emoji" aria-hidden="true">📝</span> Don’t worry about perfect</div>
                        <div class="rts-guideline-text">
                            All submissions are reviewed and may be lightly edited to make sure they’re ready to be published and delivered to someone.
                            So don’t worry about making it perfect.
                        </div>
                    </div>

                    <div class="rts-guideline">
                        <div class="rts-guideline-title"><span class="rts-emoji" aria-hidden="true">💛</span> Thank you</div>
                        <div class="rts-guideline-text">
                            Thank you so much for taking the time to write something. Your words can genuinely change someone’s day.
                        </div>
                    </div>
                </aside>
            </div>
        </div>

        <!-- Submit form styles consolidated into assets/css/rts-system.css -->

        <script>
        (function(){
            function ready(fn){ if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',fn);} else {fn();} }

            ready(function(){
                var form = document.getElementById('rts-submit-form');
                if(!form) return;
                // Guard: only bind once (Rocket Loader / deferred scripts may re-execute).
                if(form.getAttribute('data-rts-bound') === '1') return;
                form.setAttribute('data-rts-bound', '1');

                var textEl = document.getElementById('rts-letter-text');
                var countEl = form.querySelector('.rts-char-count');
                var msgEl = document.getElementById('rts-form-message');
                var btn = document.getElementById('rts-submit-btn');
                var tsEl = document.getElementById('rts-timestamp');
                var successBox = document.getElementById('rts-submit-success');
                var sidebar = document.querySelector('.rts-submit-instructions');
                var formHeader = form.querySelector('.rts-form-header');
                var submitting = false; // lock flag

                // Timestamp for lightweight bot check.
                if(tsEl) tsEl.value = String(Date.now());

                // Character counter (bound once via passive input listener).
                function updateCount(){
                    if(!textEl || !countEl) return;
                    countEl.textContent = String((textEl.value || '').length);
                }
                if(textEl){
                    updateCount();
                    textEl.addEventListener('input', updateCount, {passive:true});
                }

                // Share helpers
                function getShareUrl(){ return window.location.origin + '/'; }
                function openShare(url){ window.open(url, '_blank', 'noopener,noreferrer'); }
                function setupShareButtons(){
                    if(!successBox) return;
                    var buttons = successBox.querySelectorAll('[data-share]');
                    if(!buttons.length) return;

                    var shareUrl = getShareUrl();
                    var shareText = 'Reasons to Stay - supportive letters when you need a reason to stay.';

                    buttons.forEach(function(a){
                        a.addEventListener('click', function(e){
                            e.preventDefault();
                            var type = a.getAttribute('data-share');
                            if(type === 'copy'){
                                if(navigator.clipboard && navigator.clipboard.writeText){
                                    navigator.clipboard.writeText(shareUrl).then(function(){
                                        a.textContent = 'Copied ✅';
                                        setTimeout(function(){ a.textContent = 'Copy link'; }, 1800);
                                    }).catch(function(){
                                        window.prompt('Copy this link:', shareUrl);
                                    });
                                } else {
                                    window.prompt('Copy this link:', shareUrl);
                                }
                                return;
                            }
                            if(type === 'facebook'){
                                openShare('https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(shareUrl));
                                return;
                            }
                            if(type === 'x'){
                                openShare('https://twitter.com/intent/tweet?text=' + encodeURIComponent(shareText) + '&url=' + encodeURIComponent(shareUrl));
                                return;
                            }
                            if(type === 'whatsapp'){
                                openShare('https://wa.me/?text=' + encodeURIComponent(shareText + ' ' + shareUrl));
                            }
                        });
                    });
                }

                function setMessage(type, text){
                    if(!msgEl) return;
                    msgEl.classList.remove('success','error');
                    if(type) msgEl.classList.add(type);
                    msgEl.textContent = text || '';
                }

                // REST submission with AJAX fallback (WAF-safe).
                async function submitViaRest(payload){
                    var endpoint = (window.rts_theme_data && window.rts_theme_data.rest_url)
                        ? window.rts_theme_data.rest_url.replace(/\/$/, '') + '/rts/v1/letter/submit'
                        : (window.location.origin + '/wp-json/rts/v1/letter/submit');

                    var headers = {'Content-Type':'application/json'};
                    if(window.wpApiSettings && window.wpApiSettings.nonce){ headers['X-WP-Nonce'] = window.wpApiSettings.nonce; }

                    var res, data;
                    try {
                        res = await fetch(endpoint, {method:'POST', credentials:'same-origin', headers:headers, body: JSON.stringify(payload)});
                        data = await res.json().catch(function(){ return {}; });
                    } catch(_networkErr) {
                        // REST failed (network error) - try AJAX fallback.
                        return await submitViaAjax(payload);
                    }

                    // WAF / security block: fall back to admin-ajax.
                    if(!res.ok && (res.status === 403 || res.status === 404 || res.status === 405)){
                        return await submitViaAjax(payload);
                    }
                    if(res.ok && data && data.success) return data;
                    if(!res.ok || !data || !data.success){
                        var msg = (data && data.message) ? data.message : 'Something went wrong. Please try again.';
                        throw new Error(msg);
                    }
                    return data;
                }

                // AJAX fallback for environments where /wp-json is blocked.
                async function submitViaAjax(payload){
                    var ajaxUrl = (window.RTS_CONFIG && window.RTS_CONFIG.ajaxUrl)
                        ? window.RTS_CONFIG.ajaxUrl
                        : (window.rts_theme_data && window.rts_theme_data.ajax_url)
                            ? window.rts_theme_data.ajax_url
                            : '/wp-admin/admin-ajax.php';

                    var params = new URLSearchParams();
                    params.set('action', 'rts_submit_letter');
                    params.set('payload', JSON.stringify(payload));

                    var res = await fetch(ajaxUrl, {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'}, body: params.toString()});
                    var data = {};
                    try{ data = await res.json(); } catch(_e) {}
                    // admin-ajax wraps in {success:bool, data:{...}}
                    if(data && data.success && data.data) return data.data;
                    if(data && data.success) return data;
                    var msg = (data && data.data && data.data.message) ? data.data.message : 'Something went wrong. Please try again.';
                    throw new Error(msg);
                }

                form.addEventListener('submit', function(e){
                    e.preventDefault();
                    // Prevent double submits.
                    if(submitting) return;
                    submitting = true;

                    setMessage('', '');
                    if(btn){ btn.disabled = true; btn.textContent = 'Submitting...'; }

                    var payload = {
                        author_name: (document.getElementById('author-name')||{}).value || '',
                        author_email: (document.getElementById('author-email')||{}).value || '',
                        letter_text: (textEl||{}).value || '',
                        consent: (document.getElementById('rts-consent')||{}).checked ? 1 : 0,
                        subscribe_opt_in: (document.getElementById('rts-subscribe-opt-in')||{}).checked ? 1 : 0,
                        website: (document.getElementById('rts-website')||{}).value || '',
                        company: (document.getElementById('rts-company')||{}).value || '',
                        confirm_email: (document.getElementById('rts-confirm-email')||{}).value || '',
                        rts_token: (document.getElementById('rts-token')||{}).value || '',
                        rts_timestamp: (document.getElementById('rts-timestamp')||{}).value || ''
                    };

                    // Quick client-side validation for nicer UX.
                    if(!payload.consent){
                        setMessage('error','Please confirm your letter can be reviewed before publishing.');
                        if(btn){ btn.disabled=false; btn.textContent='Submit Letter'; }
                        submitting = false;
                        return;
                    }
                    if((payload.letter_text || '').replace(/<[^>]*>/g,'').trim().length < 50){
                        setMessage('error','Your letter needs to be at least 50 characters.');
                        if(btn){ btn.disabled=false; btn.textContent='Submit Letter'; }
                        submitting = false;
                        return;
                    }

                    submitViaRest(payload).then(function(data){
                        // Hide form fields and header, but keep the form container visible
                        // so the success box (which is inside the form) stays visible.
                        Array.prototype.forEach.call(form.querySelectorAll('.rts-field, .rts-honeypot, .rts-consent-field, #rts-submit-btn, #rts-form-message'), function(el){ el.style.display='none'; });
                        if(formHeader) formHeader.style.display = 'none';
                        if(successBox){
                            successBox.style.display = 'block';
                            setupShareButtons();

                            // Update subscription message if user opted in
                            var subMessage = document.getElementById('rts-subscription-message');
                            var optedIn = (document.getElementById('rts-subscribe-opt-in')||{}).checked;
                            if(subMessage && optedIn && data && data.subscribed){
                                subMessage.textContent = "You're subscribed and will receive letters and occasional updates.";
                                subMessage.style.display = 'block';
                            }
                        }

                        // Guidelines sidebar stays visible beneath the success box.
                        if(sidebar){
                            var h3 = sidebar.querySelector('h3');
                            if(h3) h3.textContent = 'What happens next';
                        }

                        // Keep submit button hidden (form is done).
                        if(btn) btn.style.display = 'none';
                    }).catch(function(err){
                        setMessage('error', err && err.message ? err.message : 'Could not submit your letter. Please try again.');
                        if(btn){ btn.disabled=false; btn.textContent='Submit Letter'; }
                        submitting = false;
                        if(tsEl) tsEl.value = String(Date.now());
                    });
                });
            });
        })();
        </script>
<?php
        return ob_get_clean();
      } catch (\Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('RTS submit_form shortcode error: ' . $e->getMessage());
        }
        return '<div class="rts-submit-form-wrapper"><p>Unable to load the submission form. Please refresh the page.</p></div>';
      }
    }

    /**
     * Shared: validate + create a quarantined Letter (pending + needs_review=1), queue moderation scan,
     * and optionally send a branded thank-you email.
     *
     * Idempotency: A SHA-256 hash of (email + stripped content + 5-minute time bucket) is stored in
     * post meta (_rts_submission_hash). If a post with the same hash already exists, we return the
     * existing post_id without creating a duplicate.
     *
     * @return array{success:bool,message?:string,letter_id?:int,status?:int}
     */
    private function process_letter_submission(array $payload): array {
        // Nonce (must match wp_create_nonce('rts_submit_letter'))
        $nonce = isset($payload['rts_token']) ? (string) $payload['rts_token'] : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'rts_submit_letter')) {
            return ['success' => false, 'message' => 'Security check failed. Please refresh and try again.', 'status' => 403];
        }

        // Lightweight bot checks (honeypots + time)
        $honeypots = [
            'website'       => isset($payload['website']) ? (string) $payload['website'] : '',
            'company'       => isset($payload['company']) ? (string) $payload['company'] : '',
            'confirm_email' => isset($payload['confirm_email']) ? (string) $payload['confirm_email'] : '',
        ];
        foreach ($honeypots as $v) {
            if (trim((string) $v) !== '') {
                return ['success' => false, 'message' => 'Submission blocked.', 'status' => 400];
            }
        }

        $ts = isset($payload['rts_timestamp']) ? preg_replace('/[^0-9]/', '', (string) $payload['rts_timestamp']) : '';
        if ($ts) {
            $age = time() - (int) floor(((int) $ts) / 1000);
            // If submitted unrealistically fast, treat as bot (allow very slow submissions).
            if ($age < 2) {
                return ['success' => false, 'message' => 'Please take a moment and try again.', 'status' => 400];
            }
        }

        $author_name  = isset($payload['author_name']) ? sanitize_text_field((string) $payload['author_name']) : '';
        $letter_text  = isset($payload['letter_text']) ? wp_kses_post((string) $payload['letter_text']) : '';
        $author_email = isset($payload['author_email']) ? sanitize_email((string) $payload['author_email']) : '';

        $letter_text_stripped = wp_strip_all_tags($letter_text);
        $len = function_exists('mb_strlen') ? mb_strlen($letter_text_stripped) : strlen($letter_text_stripped);
        if ($len < 50) {
            return ['success' => false, 'message' => 'Letter must be at least 50 characters.', 'status' => 400];
        }

        // --- Idempotency guard ---
        // Prevent duplicate letters if the browser retries, a CDN replays the request, or both REST + AJAX fire.
        // We use a stable hash (no short time bucket), then only allow the same hash once within a reasonable window.
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
        $submission_hash = hash('sha256', strtolower(trim($author_email)) . '|' . trim($letter_text_stripped) . '|' . $ip);

        $existing = get_posts([
            'post_type'      => 'letter',
            'post_status'    => ['pending', 'draft', 'publish'],
            'meta_key'       => '_rts_submission_hash',
            'meta_value'     => $submission_hash,
            'date_query'     => [
                [
                    'after'     => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS * 30),
                    'inclusive' => true,
                ],
            ],
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ]);

        if (!empty($existing)) {
            // Duplicate detected: return success with existing ID (idempotent).
            return ['success' => true, 'message' => 'Letter received.', 'letter_id' => (int) $existing[0], 'status' => 200];
        }

        // Flag to prevent the save_post_letter hook from also queueing a moderation scan.
        // We queue it explicitly below; the flag is checked by on_save_post_letter.
        if (!defined('RTS_FRONTEND_SUBMISSION_IN_PROGRESS')) {
            define('RTS_FRONTEND_SUBMISSION_IN_PROGRESS', true);
        }

        // Create a quarantined letter.
        $title_seed = $author_name ? "Letter from {$author_name}" : 'New Letter Submission';
        $post_title = wp_trim_words($title_seed, 10, '');
        $post_id = wp_insert_post([
            'post_type'    => 'letter',
            'post_status'  => 'pending', // queued for moderation review
            'post_title'   => $post_title,
            'post_content' => $letter_text,
        ], true);

        if (is_wp_error($post_id) || !$post_id) {
            return ['success' => false, 'message' => 'Could not save your letter. Please try again.', 'status' => 500];
        }

        // Meta used by moderation/admin
        update_post_meta($post_id, 'needs_review', '1');
        update_post_meta($post_id, 'rts_moderation_status', 'pending_review');
        update_post_meta($post_id, 'rts_submission_source', 'frontend');
        update_post_meta($post_id, 'rts_author_name', $author_name);
        update_post_meta($post_id, '_rts_submission_hash', $submission_hash);
        if ($author_email && is_email($author_email)) {
            update_post_meta($post_id, 'rts_author_email', $author_email);
        }

        // Queue moderation scan exactly once (meta lock prevents on_save_post_letter from re-queuing).
        update_post_meta($post_id, '_rts_moderation_job_scheduled', '1');
        if (class_exists('RTS_Engine_Dashboard') && method_exists('RTS_Engine_Dashboard', 'queue_letter_scan')) {
            try {
                RTS_Engine_Dashboard::queue_letter_scan((int) $post_id);
            } catch (\Throwable $e) {
                // No-op: submission still succeeds.
            }
        }

        // Thank-you email (optional)
        if ($author_email && is_email($author_email)) {
            $this->send_letter_thank_you_email($author_email, $author_name);
        }

        // Handle subscription opt-in
        $subscribed = false;
        $subscribe_opt_in = isset($payload['subscribe_opt_in']) ? (int) $payload['subscribe_opt_in'] : 0;
        if ($subscribe_opt_in && $author_email && is_email($author_email)) {
            $subscribed = $this->add_letter_writer_to_subscribers($author_email, $ip);
        }

        return [
            'success' => true,
            'letter_id' => (int) $post_id,
            'subscribed' => $subscribed,
            'status' => 200
        ];
    }

    /**
     * AJAX: fallback handler for front-end letter submissions.
     * Used when /wp-json is blocked by WAF/security.
     */
    public function ajax_submit_letter(): void {
        $raw = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
        $payload = json_decode((string) $raw, true);

        if (!is_array($payload)) {
            wp_send_json_error(['message' => 'Invalid request.'], 400);
        }

        $result = $this->process_letter_submission($payload);
        $status = isset($result['status']) ? (int) $result['status'] : 200;
        unset($result['status']);

        if (!empty($result['success'])) {
            wp_send_json_success($result, $status);
        }
        wp_send_json_error($result, $status);
    }

    /**
     * Add letter writer to subscriber list.
     *
     * @param string $email Email address
     * @param string $ip IP address for consent logging
     * @return bool True if subscribed successfully, false otherwise
     */
    private function add_letter_writer_to_subscribers(string $email, string $ip = ''): bool {
        // Get the subscriber system instance
        $subscriber_system = class_exists('RTS_Subscriber_System') ? RTS_Subscriber_System::get_instance() : null;
        if (!$subscriber_system || !isset($subscriber_system->subscriber_cpt)) {
            return false;
        }

        $subscriber_cpt = $subscriber_system->subscriber_cpt;

        // Check if already subscribed (don't create duplicates)
        if (method_exists($subscriber_cpt, 'get_subscriber_by_email')) {
            $existing = $subscriber_cpt->get_subscriber_by_email($email);
            if ($existing) {
                // Already subscribed - update consent log with new write_a_letter source
                if (method_exists($subscriber_cpt, 'update_consent_log')) {
                    $subscriber_cpt->update_consent_log($existing, 'write_a_letter_form', [
                        'timestamp'  => current_time('mysql'),
                        'ip'         => $ip,
                        'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
                        'via'        => 'write_a_letter'
                    ]);
                }
                return true; // Already subscribed, so return true
            }
        }

        // Create new subscriber
        if (!method_exists($subscriber_cpt, 'create_subscriber')) {
            return false;
        }

        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $subscriber_id = $subscriber_cpt->create_subscriber(
            $email,
            'monthly', // Default to monthly frequency (safe default)
            'write_a_letter', // Source for tracking
            [
                'ip_address'       => $ip,
                'user_agent'       => $user_agent,
                'pref_letters'     => 1, // Enable letters
                'pref_newsletters' => 1, // Enable newsletters
            ]
        );

        if (is_wp_error($subscriber_id) || !$subscriber_id) {
            return false;
        }

        // Store explicit preferences
        update_post_meta($subscriber_id, '_rts_pref_letters', 1);
        update_post_meta($subscriber_id, '_rts_pref_newsletters', 1);

        // Sync to rts_subscribers table for drip scheduling
        if (method_exists($subscriber_system, 'sync_subscriber_to_table')) {
            $subscriber_system->sync_subscriber_to_table($subscriber_id, $email, 'monthly', ['letters', 'newsletters']);
        }

        // Send verification or welcome email
        $require_verification = (bool) get_option('rts_require_email_verification', true);
        if ($require_verification && isset($subscriber_system->email_engine) && method_exists($subscriber_system->email_engine, 'send_verification_email')) {
            $subscriber_system->email_engine->send_verification_email($subscriber_id);
        } elseif (isset($subscriber_system->email_engine) && method_exists($subscriber_system->email_engine, 'send_welcome_email')) {
            $subscriber_system->email_engine->send_welcome_email($subscriber_id);
        }

        return true;
    }

    /**
     * Branded thank-you email after submission.
     */
    private function send_letter_thank_you_email(string $to_email, string $name = ''): void {
        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $home      = home_url('/');
        $subject   = "Thank you for your letter - {$site_name}";

        $logo_url = '';
        $custom_logo_id = (int) get_theme_mod('custom_logo');
        if ($custom_logo_id) {
            $src = wp_get_attachment_image_src($custom_logo_id, 'full');
            if (is_array($src) && !empty($src[0])) {
                $logo_url = esc_url($src[0]);
            }
        }
        if (!$logo_url) {
            $icon = get_site_icon_url(256);
            if ($icon) {
                $logo_url = esc_url($icon);
            }
        }

        $greeting = $name ? "Hi " . esc_html($name) . "," : "Hi there,";

        $body = '
<!doctype html>
<html>
  <head><meta charset="utf-8"></head>
  <body style="margin:0;padding:0;background:#F1E3D3;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F1E3D3;padding:24px 12px;">
      <tr>
        <td align="center">
          <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="max-width:640px;width:100%;background:#FFFFFF;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(0,0,0,0.12);">
            <tr>
              <td style="background:#1A1A1A;padding:18px 22px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                  <tr>
                    <td align="left" style="color:#F1E3D3;font-family:Arial,Helvetica,sans-serif;font-size:14px;letter-spacing:1px;text-transform:uppercase;">
                      Reasons to Stay
                    </td>
                    <td align="right">';

        if ($logo_url) {
            $body .= '<img src="' . $logo_url . '" alt="' . esc_attr($site_name) . '" width="40" height="40" style="display:block;border-radius:8px;">';
        }

        $body .= '</td>
                  </tr>
                </table>
              </td>
            </tr>

            <tr>
              <td style="padding:26px 22px 8px 22px;font-family:Arial,Helvetica,sans-serif;color:#1A1A1A;">
                <h1 style="margin:0 0 10px 0;font-size:22px;line-height:1.25;">' . $greeting . '</h1>
                <p style="margin:0 0 14px 0;font-size:15px;line-height:1.5;">
                  Thank you so much for taking the time to write something. Your letter has been received and will be reviewed before it can be published.
                </p>

                <p style="margin:0 0 16px 0;font-size:15px;line-height:1.5;">
                  If you want to help more people find the site, sharing is a huge gift.
                </p>

                <p style="margin:0 0 22px 0;">
                  <a href="' . esc_url($home) . '" style="display:inline-block;background:#FCA311;color:#1A1A1A;text-decoration:none;font-weight:700;padding:12px 18px;border-radius:10px;">
                    Visit Reasons to Stay
                  </a>
                </p>

                <p style="margin:0 0 10px 0;font-size:13px;line-height:1.5;color:#374151;">
                  This email is just a confirmation. Your letter will not be published with your email address.
                </p>
              </td>
            </tr>

            <tr>
              <td style="padding:14px 22px 22px 22px;font-family:Arial,Helvetica,sans-serif;color:#4B5563;font-size:12px;line-height:1.4;">
                <div style="border-top:1px solid #E5E7EB;padding-top:12px;">
                  ' . esc_html($site_name) . ' · <a href="' . esc_url($home) . '" style="color:#1A1A1A;">' . esc_url($home) . '</a>
                </div>
              </td>
            </tr>
          </table>
        </td>
      </tr>
    </table>
  </body>
</html>';

        $headers = ['Content-Type: text/html; charset=UTF-8'];

        // Suppress errors from hosts that block outbound email; the submission still succeeds.
        try {
            wp_mail($to_email, $subject, $body, $headers);
        } catch (\Throwable $e) {
            // no-op
        }
    }

    /**
     * [rts_site_stats_row]
     */
    public function site_stats_row($atts) {
      try {
        $atts = shortcode_atts([
            'offset_delivered'  => 0,
            'offset_feelbetter' => 0,
            'offset_submitted'  => 0,
        ], $atts, 'rts_site_stats_row');

        // Validation
        $atts = array_map('intval', $atts);

        // Enqueue styles cleanly
        wp_enqueue_style('rts-special-elite', 'https://fonts.googleapis.com/css2?family=Special+Elite&display=swap', [], null);
        wp_enqueue_style('rts-stats-row', get_stylesheet_directory_uri() . '/assets/css/rts-stats-row.css', ['rts-special-elite'], '2.4.1');

        $stats = $this->get_site_stats([
            'offset_delivered'  => $atts['offset_delivered'],
            'offset_feelbetter' => $atts['offset_feelbetter'],
            'offset_submitted'  => $atts['offset_submitted'],
        ]);

        $uid = 'rts-stats-' . uniqid();

        ob_start();
        ?>
        <div class="rts-stats-row" id="<?php echo esc_attr($uid); ?>">
            <div class="rts-stat">
                <div class="rts-stat-number" data-stat="letters_delivered"><?php echo esc_html($stats['letters_delivered']); ?></div>
                <div class="rts-stat-label">Letters delivered to site visitors</div>
            </div>
            <div class="rts-stat">
                <div class="rts-stat-number" data-stat="feel_better_percent"><?php echo esc_html($stats['feel_better_percent']); ?>%</div>
                <div class="rts-stat-label">Reading a letter made them feel “much better”</div>
            </div>
            <div class="rts-stat">
                <div class="rts-stat-number" data-stat="letters_submitted"><?php echo esc_html($stats['letters_submitted']); ?></div>
                <div class="rts-stat-label">Letters submitted</div>
            </div>
        </div>
        <script>
        (function(){
            var uid = "<?php echo esc_js($uid); ?>";
            var url = "<?php echo esc_url_raw(rest_url('rts/v1/site-stats')); ?>";
            if('fetch' in window) {
                fetch(url).then(r=>r.json()).then(d=>{
                    var row = document.getElementById(uid);
                    if(row && typeof d.letters_delivered !== 'undefined') {
                        var el = row.querySelector('[data-stat="letters_delivered"]');
                        if(el) el.textContent = new Intl.NumberFormat().format(d.letters_delivered);
                        var el2 = row.querySelector('[data-stat="feel_better_percent"]');
                        if(el2) el2.textContent = d.feel_better_percent + '%';
                        var el3 = row.querySelector('[data-stat="letters_submitted"]');
                        if(el3) el3.textContent = new Intl.NumberFormat().format(d.letters_submitted);
                    }
                }).catch(e=>{});
            }
        })();
        </script>
        <?php
        return ob_get_clean();
      } catch (\Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('RTS site_stats_row shortcode error: ' . $e->getMessage());
        }
        return '';
      }
    }

    /**
     * Helper to render honeypot fields
     */
    private function render_honeypot_fields() {
        ?>
        <input type="text" name="website" style="position:absolute;left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off">
        <input type="text" name="company" style="position:absolute;left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off">
        <input type="email" name="confirm_email" style="position:absolute;left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off">
        <input type="hidden" name="rts_timestamp" id="rts-timestamp" value="<?php echo time(); ?>">
        <input type="hidden" name="rts_token" id="rts-token" value="<?php echo wp_create_nonce('rts_submit_letter'); ?>">
        <?php
    }

    /**
     * Helper to get stats
     */
    public function get_site_stats(array $overrides = []): array {
        // Admin offsets (single source of truth)
        $admin_overrides = get_option('rts_stats_override', []);
        if (!is_array($admin_overrides)) {
            $admin_overrides = [];
        }

        $admin_overrides = wp_parse_args($admin_overrides, [
            'enabled' => 0,
            'letters_delivered' => 0,
            'letters_submitted' => 0,
            'helps' => 0,
            'feel_better_percent' => '',
        ]);

        $use_admin_offsets = !empty($admin_overrides['enabled']);

        // Allow forcing bypass of cache for admins / debugging
        $force = !empty($overrides['force']) || (is_user_logged_in() && current_user_can('manage_options'));

        // Back-compat: shortcode-level offsets (optional)
        $short_offset_submitted  = (int) ($overrides['offset_submitted'] ?? 0);
        $short_offset_delivered  = (int) ($overrides['offset_delivered'] ?? 0);
        $short_offset_feelbetter = (int) ($overrides['offset_feelbetter'] ?? 0);

        $cache_key_parts = [
            'admin_enabled' => (int) $use_admin_offsets,
            'admin_d' => (int) $admin_overrides['letters_delivered'],
            'admin_s' => (int) $admin_overrides['letters_submitted'],
            'admin_h' => (int) $admin_overrides['helps'],
            'admin_p' => (string) $admin_overrides['feel_better_percent'],
            'short_d' => $short_offset_delivered,
            'short_s' => $short_offset_submitted,
            'short_f' => $short_offset_feelbetter,
        ];
        $cache_key = 'rts_site_stats_' . md5(wp_json_encode($cache_key_parts));

        if (!$force) {
            $cached = get_transient($cache_key);
            if ($cached !== false) {
                return $cached;
            }
        }

        try {
            global $wpdb;

            // 1) Letters Submitted: Count everything that exists in the system (submitted,
            // imported, queued for review, etc.). Exclude auto-drafts and trash.
            $letters_submitted = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'letter' AND post_status NOT IN ('trash','auto-draft')"
            );

            // 2) Letters Delivered: Sum of per-letter view counters.
            // Canonical key used by the viewer is `rts_views` (REST: /rts/v1/track/view).
            // Legacy installs may have `view_count`.
            $sum_rts_views = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(CAST(meta_value AS UNSIGNED)), 0) FROM {$wpdb->postmeta} WHERE meta_key = %s",
                'rts_views'
            ));
            $sum_view_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(CAST(meta_value AS UNSIGNED)), 0) FROM {$wpdb->postmeta} WHERE meta_key = %s",
                'view_count'
            ));
            // Prefer live metric. If rts_views is still zero, fall back to legacy.
            $letters_delivered = ($sum_rts_views > 0) ? $sum_rts_views : $sum_view_count;

            // 3) Helpful count: sum help_count meta
            $help_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(meta_value), 0) FROM {$wpdb->postmeta} WHERE meta_key = %s",
                'help_count'
            ));

            // Apply admin offsets if enabled
            if ($use_admin_offsets) {
                $letters_delivered += (int) $admin_overrides['letters_delivered'];
                $letters_submitted += (int) $admin_overrides['letters_submitted'];
                $help_count        += (int) $admin_overrides['helps'];
            }

            // Apply shortcode offsets (optional/back-compat)
            $letters_delivered += $short_offset_delivered;
            $letters_submitted += $short_offset_submitted;

            // Calculate percentage from (possibly offset) counts
            $feel_better_percent = 0;
            if ($letters_delivered > 0) {
                $feel_better_percent = (int) min(100, round(($help_count / $letters_delivered) * 100));
            }

            // Optional admin override for percent (blank => calculated)
            if ($use_admin_offsets && $admin_overrides['feel_better_percent'] !== '' && $admin_overrides['feel_better_percent'] !== null) {
                $feel_better_percent = (int) max(0, min(100, (int) $admin_overrides['feel_better_percent']));
            }

            // Optional shortcode feelbetter offset (back-compat)
            if ($short_offset_feelbetter !== 0) {
                $feel_better_percent = (int) max(0, min(100, $feel_better_percent + $short_offset_feelbetter));
            }

            $stats = [
                'letters_submitted'   => max(0, (int) $letters_submitted),
                'letters_delivered'   => max(0, (int) $letters_delivered),
                'feel_better_percent' => max(0, min(100, (int) $feel_better_percent)),
            ];

            // Cache for 5 minutes (matches subscriber analytics freshness)
            set_transient($cache_key, $stats, 300);
            return $stats;

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('RTS Stats Error: ' . $e->getMessage());
            }
            return [
                'letters_submitted'   => 0,
                'letters_delivered'   => 0,
                'feel_better_percent' => 0,
            ];
        }
    }
    
    public function rest_site_stats($request) {
        try {
            $force = false;
            if (is_object($request) && method_exists($request, 'get_param')) {
                $force = (bool) $request->get_param('force');
            }

            $stats = $this->get_site_stats([
                'force' => $force,
            ]);

            return rest_ensure_response($stats);
        } catch (Exception $e) {
            return new WP_Error(
                'rts_stats_error',
                'Unable to retrieve statistics',
                ['status' => 500]
            );
        }
    }

    public function register_site_stats_route() {
        register_rest_route('rts/v1', '/site-stats', [
            'methods' => 'GET',
            'callback' => [$this, 'rest_site_stats'],
            'permission_callback' => '__return_true',
        ]);
    }
}

new RTS_Shortcodes();