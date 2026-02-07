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
    }

    /**
     * [rts_share_buttons] - Share buttons block (standalone)
     * Keeps the same classes/data-attributes as the letter viewer so existing JS continues to work.
     *
     * Usage:
     * [rts_share_buttons label="Help us reach more people by sharing this site:"]
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
                <a href="#" class="share-btn rts-share-btn" data-platform="facebook" target="_blank" rel="noopener noreferrer" aria-label="Share on Facebook (opens in new tab)">
                    <i class="fab fa-facebook-f" aria-hidden="true"></i>
                    <span>Facebook</span>
                </a>
                <a href="#" class="share-btn rts-share-btn" data-platform="x" target="_blank" rel="noopener noreferrer" aria-label="Share on X (opens in new tab)">
                    <i class="fab fa-x-twitter" aria-hidden="true"></i>
                    <span>X</span>
                </a>
                <a href="#" class="share-btn rts-share-btn" data-platform="whatsapp" target="_blank" rel="noopener noreferrer" aria-label="Share on WhatsApp (opens in new tab)">
                    <i class="fab fa-whatsapp" aria-hidden="true"></i>
                    <span>WhatsApp</span>
                </a>
                <a href="#" class="share-btn rts-share-btn" data-platform="reddit" target="_blank" rel="noopener noreferrer" aria-label="Share on Reddit (opens in new tab)">
                    <i class="fab fa-reddit-alien" aria-hidden="true"></i>
                    <span>Reddit</span>
                </a>
                <a href="#" class="share-btn rts-share-btn" data-platform="threads" target="_blank" rel="noopener noreferrer" aria-label="Share on Threads (opens in new tab)">
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
            
            <!-- Loading state -->
            <div class="rts-loading">
                <div class="rts-spinner"></div>
                <p>Finding a letter for you...</p>
            </div>
            
            <!-- Letter display (populated by JS) -->
            <div class="rts-letter-display" style="display:none;" hidden aria-hidden="true" aria-live="polite">
                <div class="rts-letter-card">
                    <div class="rts-letter-tabs" aria-label="Letter options">
                        <button type="button"
                                class="rts-feedback-tab rts-feedback-open"
                                aria-haspopup="dialog"
                                aria-controls="rts-feedback-modal"
                                aria-label="Give feedback on this letter">
                            Feedback
                        </button>
                    </div>
                    <div class="rts-letter-content">
                        <div class="rts-letter-body" tabindex="-1"></div>
                        <div class="rts-letter-footer" aria-label="Letter actions">
                            <?php if ($atts['show_next'] === 'yes') : ?>
                            <button class="rts-report-link rts-trigger-open" type="button" data-rts-trigger="1" aria-label="Report about this letter">
                                Report
                            </button>
                            <button class="rts-btn rts-btn-next" type="button" aria-label="Read another letter">
                                Read Another Letter
                            </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    
                </div>
                
                
                <div class="rts-letter-rate-wrap" aria-label="Rate this letter">
                    <?php if ($atts['show_helpful'] === 'yes') : ?>
                    <div class="rts-rate-prompt" hidden aria-hidden="true" style="display:none" aria-live="polite" aria-label="Rate this letter before continuing">
                        <span class="rts-rate-prompt-text">Before the next letter, how was that one?</span>
                        <div class="rts-rate-prompt-actions" role="group" aria-label="Rate this letter">
                            <button type="button" class="rts-rate-btn rts-rate-up" aria-label="This helped">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M14 9V5a3 3 0 0 0-3-3l-1 7-4 5v9h11a2 2 0 0 0 2-1.6l1-9A2 2 0 0 0 18 10h-4z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                    <path d="M6 23H4a2 2 0 0 1-2-2v-7a2 2 0 0 1 2-2h2" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                </svg>
                                <span>Helped</span>
                            </button>
                            <button type="button" class="rts-rate-btn rts-rate-down" aria-label="Not for me">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <path d="M10 15v4a3 3 0 0 0 3 3l1-7 4-5V1H7a2 2 0 0 0-2 1.6l-1 9A2 2 0 0 0 6 14h4z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                    <path d="M18 1h2a2 2 0 0 1 2 2v7a2 2 0 0 1-2 2h-2" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>
                                </svg>
                                <span>Not for me</span>
                            </button>
                            <button type="button" class="rts-rate-skip" aria-label="Skip rating">
                                Skip
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
                
                
                <!-- Feedback modal (subtle, internal, letter-linked) -->
                <div class="rts-feedback-modal rts-modal" id="rts-feedback-modal" role="dialog" aria-modal="true" aria-labelledby="rts-feedback-title" aria-hidden="true" hidden style="display:none;">
                    <div class="rts-modal-backdrop" data-rts-close></div>
                    <div class="rts-modal-panel" role="document">
                        <div class="rts-modal-header">
                            <h3 id="rts-feedback-title">Feedback</h3>
                            <button type="button" class="rts-modal-close" aria-label="Close feedback" data-rts-close>×</button>
                        </div>

                        <form class="rts-feedback-form" autocomplete="on">
                            <input type="hidden" name="letter_id" value="">
                            <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;opacity:0;height:0;width:0;">

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
                                <textarea id="rts-feedback-comment" name="comment" rows="4" placeholder="What worked, what didn’t, or anything we should know (optional)"></textarea>
                            </div>

                            <div class="rts-field">
                                <label for="rts-feedback-email">Email (optional)</label>
                                <input id="rts-feedback-email" type="email" name="email" placeholder="Only if you want a reply">
                            </div>

                            <div class="rts-actions">
                                <button type="submit" class="rts-btn rts-btn-primary">Send feedback</button>
                                <button type="button" class="rts-btn rts-btn-ghost" data-rts-close>Cancel</button>
                            </div>

                            <p class="rts-muted">
                                Feedback is private and linked to the letter you just read. If you’re in immediate danger, please use the emergency links above.
                            </p>
                        </form>
                    </div>
                </div>

<?php if ($atts['show_share'] === 'yes') : ?>
                <?php echo do_shortcode('[rts_share_buttons]'); ?>
<?php endif; ?>
            </div>
            
            <!-- Helpful confirmation toast -->
            <div class="rts-helpful-toast" style="display:none;" role="alert">
                <p>✓ Thank you for letting us know</p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * [rts_onboarding] - Preference selector
     */
    public function onboarding($atts) {
        // Prevent duplicate rendering if called multiple times or via different shortcodes
        if (self::$onboarding_rendered) {
            return '';
        }
        self::$onboarding_rendered = true;

        ob_start();
        ?>
	        <div class="rts-onboarding-overlay" style="display:none;" aria-hidden="true"></div>
	        <div class="rts-onboarding-modal" role="dialog" aria-modal="true" aria-labelledby="rts-onboarding-title" aria-describedby="rts-onboarding-desc" tabindex="-1" style="display:none;">
	            <!-- Skip button container (desktop: right vertical tab, mobile: top button) -->
	            <div class="rts-skip-container">
	                <button class="rts-btn-skip" type="button" aria-label="Skip onboarding">Skip</button>
	            </div>
	
	            <!-- Inner wrapper handles scrolling -->
	            <div class="rts-onboarding-scroll-wrapper">
	                <div class="rts-onboarding-content">
	                    <!-- Dynamic Header -->
	                    <div id="intro-text">
	                        <h2 id="rts-onboarding-title">Would you like a letter chosen just for you?</h2>
	                        <p id="rts-onboarding-desc">Answer a few quick questions to help us find the right letter, or skip to read any letter.</p>
	                    </div>
	
	                    <!-- Step 1: Feelings -->
	                    <div class="rts-onboarding-step" data-step="1">
	                        <h3>What are you feeling right now?</h3>
	                        <p class="rts-step-subtitle">Select all that apply</p>
	
	                        <div class="rts-checkbox-group">
	                            <label class="rts-checkbox-label">
	                                <input type="checkbox" name="feelings[]" value="hopeless">
	                                <span>Hopeless</span>
	                            </label>
	                            <label class="rts-checkbox-label">
	                                <input type="checkbox" name="feelings[]" value="alone">
	                                <span>Alone</span>
	                            </label>
	                            <label class="rts-checkbox-label">
	                                <input type="checkbox" name="feelings[]" value="anxious">
	                                <span>Anxious</span>
	                            </label>
	                            <label class="rts-checkbox-label">
	                                <input type="checkbox" name="feelings[]" value="grieving">
	                                <span>Grieving</span>
	                            </label>
	                            <label class="rts-checkbox-label">
	                                <input type="checkbox" name="feelings[]" value="tired">
	                                <span>Tired of fighting</span>
	                            </label>
	                            <label class="rts-checkbox-label">
	                                <input type="checkbox" name="feelings[]" value="struggling">
	                                <span>Just struggling</span>
	                            </label>
	                        </div>
	
	                        <div class="rts-step-footer">
	                            <div class="rts-progress-dots" aria-hidden="true">
	                                <div class="rts-dot active"></div>
	                                <div class="rts-dot"></div>
	                                <div class="rts-dot"></div>
	                            </div>
	                            <button class="rts-btn rts-btn-next-step" type="button">Next</button>
	                        </div>
	                    </div>
	
	                    <!-- Step 2: Reading time -->
	                    <div class="rts-onboarding-step" data-step="2" style="display:none;">
	                        <h3>How much time do you have?</h3>
	
	                        <div class="rts-radio-group">
	                            <label class="rts-radio-label">
	                                <input type="radio" name="readingTime" value="short">
	                                <span>Just a minute</span>
	                            </label>
	                            <label class="rts-radio-label">
	                                <input type="radio" name="readingTime" value="medium">
	                                <span>A few minutes</span>
	                            </label>
	                            <label class="rts-radio-label">
	                                <input type="radio" name="readingTime" value="long" checked>
	                                <span>I can read for a bit</span>
	                            </label>
	                        </div>
	
	                        <div class="rts-step-footer">
	                            <div class="rts-progress-dots" aria-hidden="true">
	                                <div class="rts-dot"></div>
	                                <div class="rts-dot active"></div>
	                                <div class="rts-dot"></div>
	                            </div>
	                            <button class="rts-btn rts-btn-next-step" type="button">Next</button>
	                        </div>
	                    </div>
	
	                    <!-- Step 3: Tone -->
	                    <div class="rts-onboarding-step" data-step="3" style="display:none;">
	                        <h3>What kind of voice helps you?</h3>
	
	                        <div class="rts-radio-group">
	                            <label class="rts-radio-label">
	                                <input type="radio" name="tone" value="gentle">
	                                <span>Warm and gentle</span>
	                            </label>
	                            <label class="rts-radio-label">
	                                <input type="radio" name="tone" value="real">
	                                <span>Straight-talking and real</span>
	                            </label>
	                            <label class="rts-radio-label">
	                                <input type="radio" name="tone" value="hopeful">
	                                <span>Hopeful and uplifting</span>
	                            </label>
	                            <label class="rts-radio-label">
	                                <input type="radio" name="tone" value="any" checked>
	                                <span>Surprise me</span>
	                            </label>
	                        </div>
	
	                        <div class="rts-step-footer">
	                            <div class="rts-progress-dots" aria-hidden="true">
	                                <div class="rts-dot"></div>
	                                <div class="rts-dot"></div>
	                                <div class="rts-dot active"></div>
	                            </div>
	                            <button class="rts-btn rts-btn-complete" type="button">Find My Letter</button>
	                        </div>
	                    </div>
	                </div>
	            </div>
	        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * [rts_submit_form] - Letter submission form
     */
    public function submit_form($atts) {
        $atts = shortcode_atts([], $atts);

        ob_start();
        ?>
        <div class="rts-submit-form-wrapper">
            
            <!-- 2fr 1fr Grid: Form left, Instructions right -->
            <div class="rts-submit-grid">
                
                <!-- LEFT: Form (2fr) -->
                <form id="rts-submit-form" class="rts-submit-form rts-form" novalidate>
                    
                    <div class="rts-form-field">
                        <label for="rts-author-name">Your first name <span class="rts-optional">(optional)</span></label>
                        <input 
                            type="text" 
                            id="rts-author-name" 
                            name="author_name" 
                            maxlength="50"
                            autocomplete="given-name"
                        >
                    </div>
                    
                    <div class="rts-form-field">
                        <label for="rts-letter-text">Your letter <span class="rts-required">*</span></label>
                        <textarea 
                            id="rts-letter-text" 
                            name="letter_text" 
                            required
                            minlength="50"
                            rows="12"
                            placeholder="Write from the heart..."
                            aria-required="true"
                        ></textarea>
                        <span class="rts-char-count" aria-live="polite">0 characters (minimum 50)</span>
                    </div>
                    
                    <div class="rts-form-field">
                        <label for="rts-author-email">Your email <span class="rts-required">*</span></label>
                        <input 
                            type="email" 
                            id="rts-author-email" 
                            name="author_email" 
                            required
                            autocomplete="email"
                            aria-required="true"
                        >
                        <span class="rts-field-help">For moderation contact only - will never be published</span>
                    </div>
                    
                    <!-- Enhanced honeypot (multiple traps) -->
                    <input type="text" name="website" style="position:absolute;left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off">
                    <input type="text" name="company" style="position:absolute;left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off">
                    <input type="email" name="confirm_email" style="position:absolute;left:-9999px;" tabindex="-1" aria-hidden="true" autocomplete="off">
                    <input type="hidden" name="rts_timestamp" id="rts-timestamp" value="">
                    <input type="hidden" name="rts_token" id="rts-token" value="<?php echo wp_create_nonce('rts_submit_letter'); ?>">
                    
                    <div class="rts-form-field rts-checkbox-field">
                        <label class="rts-checkbox-label">
                            <input type="checkbox" id="rts-consent" required aria-required="true">
                            <span>I understand my letter will be reviewed before publishing <span class="rts-required">*</span></span>
                        </label>
                    </div>
                    
                    <div class="rts-form-actions">
                        <button type="submit" class="rts-btn rts-btn-submit">
                            <span class="rts-btn-text">Submit Letter</span>
                            <span class="rts-btn-spinner" style="display:none;">Submitting...</span>
                        </button>
                    </div>
                    
                    <!-- Response messages -->
                    <div class="rts-form-response" role="alert" aria-live="polite"></div>
                </form>
                
                <!-- RIGHT: Instructions (1fr) -->
                <div class="rts-submit-instructions">
                    <h3>Writing Guidelines</h3>
                    
                    <div class="rts-instruction-block">
                        <h4>✍️ Write from the Heart</h4>
                        <p>Focus on writing something warm and supportive. If you need inspiration, you can read existing letters on the site.</p>
                    </div>
                    
                    <div class="rts-instruction-block">
                        <h4>📝 Don't Worry About Perfect</h4>
                        <p>All submissions are reviewed and may be lightly edited to make sure they're ready to be published and delivered to someone. So don't worry about making it perfect.</p>
                    </div>
                    
                    <div class="rts-instruction-block">
                        <h4>💚 Your Impact</h4>
                        <p>Your letter will be randomly delivered to someone who needs hope. You could make a real difference in someone's life today.</p>
                    </div>
                </div>
            
            </div>
            
            <!-- Success message (hidden by default) -->
            <div class="rts-submit-success" style="display:none;">
                <div class="rts-success-icon">✓</div>
                <h3>Thank you for writing!</h3>
                <p>Your letter has been submitted and will be reviewed shortly. Once approved, it will be published and help someone who needs it.</p>
                <button class="rts-btn" onclick="location.reload()">Write Another Letter</button>
            </div>
        </div>
        
        <style>
        /* 2fr 1fr Grid Layout */
        .rts-submit-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 25px;
            align-items: start;
        }
        
        /* Instructions Panel */
        .rts-submit-instructions {
            background: #182437;
            color: #F1E3D3;
            padding: 30px;
            border-radius: 12px;
            position: sticky;
            top: 20px;
        }
        
        .rts-submit-instructions h3 {
            font-family: 'Special Elite', 'Courier New', monospace;
            font-size: 1.4rem;
            color: #FCA311;
            margin: 0 0 25px 0;
            border-bottom: 2px solid rgba(252, 163, 17, 0.3);
            padding-bottom: 15px;
        }
        
        .rts-instruction-block {
            margin-bottom: 25px;
        }
        
        .rts-instruction-block:last-child {
            margin-bottom: 0;
        }
        
        .rts-instruction-block h4 {
            font-size: 1.1rem;
            color: #FCA311;
            margin: 0 0 10px 0;
            font-weight: 700;
        }
        
        .rts-instruction-block p {
            font-size: 0.95rem;
            line-height: 1.6;
            margin: 0;
            color: #F1E3D3;
        }
        
        /* Mobile: Stack vertically */
        @media (max-width: 968px) {
            .rts-submit-grid {
                grid-template-columns: 1fr;
                gap: 30px;
            }
            
            .rts-submit-instructions {
                position: static;
                order: -1; /* Show instructions first on mobile */
            }
        }
        
        /* Tablet: Adjust ratio */
        @media (min-width: 969px) and (max-width: 1200px) {
            .rts-submit-grid {
                grid-template-columns: 3fr 2fr;
            }
        }
        </style>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Site Stats Row - [rts_site_stats_row]
     * Displays 3 stats: Letters delivered, Feel better %, Submitted letters
     * Pulls real data from database with manual override support
     * v2.0.17: Optimized with external CSS/JS
     */
    /**
     * [rts_site_stats_row]
     */
    public function site_stats_row($atts) {
        // Enqueue styles cleanly when shortcode is used
        wp_enqueue_style(
            'rts-stats-row',
            get_stylesheet_directory_uri() . '/assets/css/rts-stats-row.css',
            [],
            '2.0.17'
        );

        $stats = $this->get_site_stats();

        ob_start();
        ?>
        <div class="rts-stats-row" role="group" aria-label="Site statistics">
            <div class="rts-stat">
                <div class="rts-stat-number" id="rts-letters-delivered">
                    <?php echo esc_html(number_format($stats['letters_delivered'])); ?>
                </div>
                <div class="rts-stat-label">letters delivered to site visitors.</div>
            </div>

            <div class="rts-stat">
                <div class="rts-stat-number">
                    <?php echo esc_html((int) $stats['feel_better_percent']); ?>%
                </div>
                <div class="rts-stat-label">say reading a letter made them feel “much better”.</div>
            </div>

            <div class="rts-stat">
                <div class="rts-stat-number">
                    <?php echo esc_html(number_format($stats['letters_submitted'])); ?>
                </div>
                <div class="rts-stat-label">submitted letters to the site.</div>
            </div>
        </div>

        <script>
        (function(){
            var el = document.getElementById('rts-letters-delivered');
            if(!el || !window.fetch) return;
            var api = "<?php echo esc_url(rest_url('rts/v1/site-stats')); ?>";
            fetch(api, { credentials: 'same-origin' })
                .then(function(r){ return r.ok ? r.json() : null; })
                .then(function(d){
                    if(d && typeof d.letters_delivered !== 'undefined'){
                        el.textContent = new Intl.NumberFormat().format(d.letters_delivered);
                    }
                })
                .catch(function(){});
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Helper to get stats (Private)
     */
    private function get_site_stats() {
        // Prefer the shared helper in functions.php so stats stay "one source of truth"
        if (function_exists('rts_get_site_stats')) {
            $raw = rts_get_site_stats();

            return [
                'letters_delivered'   => (int) ($raw['letters_delivered'] ?? 0),
                'feel_better_percent' => (int) round((float) ($raw['feel_better_percent'] ?? 0)),
                'letters_submitted'   => (int) ($raw['total_letters'] ?? 0),
            ];
        }

        // Safe fallback if helper is unavailable for any reason
        global $wpdb;
        $total_letters = (int) (wp_count_posts('letter')->publish ?? 0);
        $letters_delivered = $wpdb->get_var("SELECT SUM(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = 'view_count'");
        $feel_better_percent = (float) get_option('rts_feel_better_percentage', 0);

        return [
            'letters_delivered'   => (int) ($letters_delivered ?? 0),
            'feel_better_percent' => (int) round($feel_better_percent),
            'letters_submitted'   => $total_letters,
        ];
    }
}

// Initialize
new RTS_Shortcodes();

/**
 * REST API endpoint for site stats
 * v2.0.17: Added rate limiting and security improvements
 */
add_action('rest_api_init', function() {
    register_rest_route('rts/v1', '/site-stats', [
        'methods' => 'GET',
        'permission_callback' => function($request) {
            // Basic rate limiting: 60 requests per hour per IP
            $ip = $request->get_header('x-forwarded-for');
            if (empty($ip)) {
                $ip = $_SERVER['REMOTE_ADDR'];
            }
            
            $rate_key = 'rts_stats_rate_' . md5($ip . date('Y-m-d-H'));
            $count = get_transient($rate_key);
            
            if ($count === false) {
                $count = 0;
            }
            
            if ($count >= 60) {
                return new WP_Error('rate_limit_exceeded', 'Too many requests. Please try again later.', ['status' => 429]);
            }
            
            set_transient($rate_key, $count + 1, HOUR_IN_SECONDS);
            
            return true;
        },
        'callback' => function() {
            $shortcodes = new RTS_Shortcodes();
            // Use reflection to call private method
            $reflection = new ReflectionClass($shortcodes);
            $method = $reflection->getMethod('get_site_stats');
            $method->setAccessible(true);
            $stats = $method->invoke($shortcodes);
            
            // Ensure all values are integers
            return [
                'letters_delivered' => (int) $stats['letters_delivered'],
                'feel_better_percent' => (int) $stats['feel_better_percent'],
                'letters_submitted' => (int) $stats['letters_submitted']
            ];
        }
    ]);
});