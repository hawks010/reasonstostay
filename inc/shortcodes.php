<?php
/**
 * Reasons to Stay - Shortcodes
 * Main shortcodes for letter system
 */

if (!defined('ABSPATH')) exit;

class RTS_Shortcodes {
    
    // Flag to prevent duplicate onboarding modals on the same page
    private static $onboarding_rendered = false;
    
    public function __construct() {
        add_shortcode('rts_letter_viewer', [$this, 'letter_viewer']);
        add_shortcode('rts_onboarding', [$this, 'onboarding']);
        add_shortcode('rts_submit_form', [$this, 'submit_form']);
        add_shortcode('rts_site_stats_row', [$this, 'site_stats_row']);
    }
    
    /**
     * [rts_letter_viewer] - Main letter display
     */
    public function letter_viewer($atts) {
        $atts = shortcode_atts([
            'show_helpful' => 'yes',
            'show_share' => 'yes',
            'show_next' => 'yes',
            'show_onboarding' => 'yes'
        ], $atts);
        
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
            <div class="rts-letter-display" style="display:none;">
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
                    <div class="rts-rate-prompt" hidden aria-live="polite" aria-label="Rate this letter before continuing">
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
                <div class="rts-modal" id="rts-feedback-modal" role="dialog" aria-modal="true" aria-labelledby="rts-feedback-title" aria-hidden="true">
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
                <!-- Share section -->
                <div class="rts-letter-share">
                    <p class="rts-share-label">Help us reach more people by sharing this site:</p>
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
        <div class="rts-onboarding-overlay" style="display:none;">
            <div class="rts-onboarding-modal">
                <!-- Side Tag for exiting immediately (Hangs outside modal) -->
                <button class="rts-skip-tag" type="button" aria-label="Exit onboarding">
                    EXIT / SKIP
                </button>
            
                <!-- NEW: Inner wrapper handles scrolling so tag is not clipped -->
                <div class="rts-onboarding-scroll-wrapper">
                    <div class="rts-onboarding-content">
                        <h2>Would you like a letter chosen just for you?</h2>
                        <p>Answer a few quick questions to help us find the right letter, or skip to read any letter.</p>
                        <!-- Debug logging for localhost -->
                        <script>
                        if (window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
                            console.log('RTS Onboarding Modal Loaded');
                            document.addEventListener('DOMContentLoaded', function() {
                                console.log('Steps found:', document.querySelectorAll('.rts-onboarding-step').length);
                            });
                        }
                        </script>
                        
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
                            
                            <button class="rts-btn rts-btn-next-step" type="button">Next</button>
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
                            
                            <button class="rts-btn rts-btn-next-step" type="button">Next</button>
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
        ob_start();
        ?>
        <div class="rts-submit-form-wrapper">
            
            <!-- 2fr 1fr Grid: Form left, Instructions right -->
            <div class="rts-submit-grid">
                
                <!-- LEFT: Form (2fr) -->
                <form id="rts-submit-form" class="rts-form" novalidate>
                    
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
     */
    public function site_stats_row($atts) {
        // Get real stats from database
        $stats = $this->get_site_stats();
        
        ob_start();
        ?>
        <div class="rts-stats-row" role="group" aria-label="Site statistics">
            <div class="rts-stat">
                <div class="rts-stat-number" id="rts-letters-delivered">
                    <?php echo number_format($stats['letters_delivered']); ?>
                </div>
                <div class="rts-stat-label">
                    letters delivered to site visitors.
                </div>
            </div>
            
            <div class="rts-stat">
                <div class="rts-stat-number" id="rts-feel-better">
                    <?php echo $stats['feel_better_percent']; ?>%
                </div>
                <div class="rts-stat-label">
                    say reading a letter made them feel "much better".
                </div>
            </div>
            
            <div class="rts-stat">
                <div class="rts-stat-number" id="rts-letters-submitted">
                    <?php echo number_format($stats['letters_submitted']); ?>
                </div>
                <div class="rts-stat-label">
                    submitted letters to the site.
                </div>
            </div>
        </div>
        
        <style>
        .rts-stats-row {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 32px;
            text-align: center;
            padding: 20px 0;
        }
        
        .rts-stat {
            min-width: 200px;
            max-width: 300px;
        }
        
        .rts-stat-number {
            font-family: 'Special Elite', 'Courier New', monospace;
            font-size: 2.8rem;
            line-height: 1;
            font-weight: 700;
            color: var(--rts-black, #2A2A2A);
        }
        
        .rts-stat-label {
            margin-top: 10px;
            font-size: 1rem;
            line-height: 1.5;
            color: var(--rts-gray-mid, #666);
        }
        
        @media (max-width: 768px) {
            .rts-stats-row {
                gap: 24px;
            }
            
            .rts-stat {
                min-width: 150px;
            }
            
            .rts-stat-number {
                font-size: 2.2rem;
            }
        }
        </style>
        
        <script>
        // Update stats dynamically via REST API
        (function() {
            function updateStats() {
                fetch('<?php echo esc_url(rest_url('rts/v1/site-stats')); ?>')
                    .then(r => r.json())
                    .then(data => {
                        if (data.letters_delivered !== undefined) {
                            document.getElementById('rts-letters-delivered').textContent = 
                                new Intl.NumberFormat().format(data.letters_delivered);
                        }
                        if (data.feel_better_percent !== undefined) {
                            document.getElementById('rts-feel-better').textContent = 
                                String(data.feel_better_percent) + '%';
                        }
                        if (data.letters_submitted !== undefined) {
                            document.getElementById('rts-letters-submitted').textContent = 
                                new Intl.NumberFormat().format(data.letters_submitted);
                        }
                    })
                    .catch(() => {}); // Silent fail
            }
            
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', updateStats);
            } else {
                updateStats();
            }
        })();
        </script>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Get real site stats from database
     */
    private function get_site_stats() {
        global $wpdb;

        // Optional additive offsets (keeps stats "live" while allowing a migration bump)
        $override_raw = get_option('rts_stats_override', []);
        if (!is_array($override_raw)) {
            $override_raw = [];
        }
        $override = wp_parse_args($override_raw, [
            'enabled' => 0,
            // Interpreted as additive offsets when enabled
            'letters_delivered' => 0,
            'letters_submitted' => 0,
            // Optional: add to helpful/"feel better" numerator
            'helps' => 0,
            // Optional: if set (0-100), override computed percentage
            'feel_better_percent' => '',
            // Legacy key from earlier UI versions (treated as letters_submitted offset)
            'total_letters' => 0,
        ]);

        $use_offsets = !empty($override['enabled']);
        $offset_delivered = $use_offsets ? max(0, intval($override['letters_delivered'])) : 0;
        $legacy_submitted  = $use_offsets ? max(0, intval($override['total_letters'])) : 0;
        $offset_submitted  = $use_offsets ? max(0, intval($override['letters_submitted'])) : 0;
        $offset_submitted  = max($offset_submitted, $legacy_submitted);
        $offset_helps      = $use_offsets ? max(0, intval($override['helps'])) : 0;
        
        // Total letters delivered (sum of all view_count)
        $letters_delivered = $wpdb->get_var("
            SELECT SUM(CAST(meta_value AS UNSIGNED))
            FROM {$wpdb->postmeta}
            WHERE meta_key = 'view_count'
        ");
        $letters_delivered = intval($letters_delivered);
        
        // Total helps (sum of all help_count)
        $total_helps = $wpdb->get_var("
            SELECT SUM(CAST(meta_value AS UNSIGNED))
            FROM {$wpdb->postmeta}
            WHERE meta_key = 'help_count'
        ");
        $total_helps = intval($total_helps);

        // Apply offsets (migration bump) while keeping stats live
        $letters_delivered_total = $letters_delivered + $offset_delivered;
        $total_helps_total       = $total_helps + $offset_helps;
        
        // Calculate (or override) feel better percentage
        $feel_better_percent = 0;
        $manual_percent = $use_offsets ? $override['feel_better_percent'] : '';
        if ($manual_percent !== '' && is_numeric($manual_percent)) {
            $feel_better_percent = max(0, min(100, (int) round(floatval($manual_percent))));
        } else {
            if ($letters_delivered_total > 0) {
                $feel_better_percent = round(($total_helps_total / $letters_delivered_total) * 100);
            }
            // Keep within sane bounds (helps can exceed views if a user clicks multiple times)
            $feel_better_percent = max(0, min(100, intval($feel_better_percent)));
        }
        
        // Total submitted letters (published + pending)
        $letters_submitted = wp_count_posts('letter');
        $total_submitted = intval($letters_submitted->publish) + intval($letters_submitted->pending);

        $total_submitted = $total_submitted + $offset_submitted;
        
        return [
            'letters_delivered' => $letters_delivered_total,
            'feel_better_percent' => $feel_better_percent,
            'letters_submitted' => $total_submitted
        ];
    }
}

// Initialize
new RTS_Shortcodes();

/**
 * REST API endpoint for site stats
 */
add_action('rest_api_init', function() {
    register_rest_route('rts/v1', '/site-stats', [
        'methods' => 'GET',
        'permission_callback' => '__return_true',
        'callback' => function() {
            $shortcodes = new RTS_Shortcodes();
            // Use reflection to call private method
            $reflection = new ReflectionClass($shortcodes);
            $method = $reflection->getMethod('get_site_stats');
            $method->setAccessible(true);
            return $method->invoke($shortcodes);
        }
    ]);
});