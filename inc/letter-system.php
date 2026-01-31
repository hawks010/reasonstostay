<?php
/**
 * Reasons to Stay - Letter System Core
 * Handles smart matching, retrieval, and API endpoints
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RTS_Letter_System')) {
    
class RTS_Letter_System {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('rest_api_init', [$this, 'register_endpoints']);

        // AJAX fallback for hosts / security layers that block WP REST POSTs.
        add_action('wp_ajax_nopriv_rts_get_next_letter', [$this, 'ajax_get_next_letter']);
        add_action('wp_ajax_rts_get_next_letter', [$this, 'ajax_get_next_letter']);

        // AJAX fallbacks for tracking + submission (same reason: some environments block /wp-json).
        add_action('wp_ajax_nopriv_rts_track_view', [$this, 'ajax_track_view']);
        add_action('wp_ajax_rts_track_view', [$this, 'ajax_track_view']);

        add_action('wp_ajax_nopriv_rts_track_helpful', [$this, 'ajax_track_helpful']);
        add_action('wp_ajax_rts_track_helpful', [$this, 'ajax_track_helpful']);

        add_action('wp_ajax_nopriv_rts_track_rate', [$this, 'ajax_track_rate']);
        add_action('wp_ajax_rts_track_rate', [$this, 'ajax_track_rate']);

        add_action('wp_ajax_nopriv_rts_track_share', [$this, 'ajax_track_share']);
        add_action('wp_ajax_rts_track_share', [$this, 'ajax_track_share']);

        add_action('wp_ajax_nopriv_rts_submit_letter', [$this, 'ajax_submit_letter']);
        add_action('wp_ajax_rts_submit_letter', [$this, 'ajax_submit_letter']);
    }
    
    /**
     * Register REST API endpoints
     */
    public function register_endpoints() {
        // Get next letter
        register_rest_route('rts/v1', '/letter/next', [
            'methods' => 'POST',
            'callback' => [$this, 'get_next_letter'],
            'permission_callback' => '__return_true'
        ]);
        
        // Track view
        register_rest_route('rts/v1', '/track/view', [
            'methods' => 'POST',
            'callback' => [$this, 'track_view'],
            'permission_callback' => '__return_true'
        ]);
        
        // Track helpful click
        register_rest_route('rts/v1', '/track/helpful', [
            'methods' => 'POST',
            'callback' => [$this, 'track_helpful'],
            'permission_callback' => '__return_true'
        ]);
        
        
        // Track rating (thumbs up / thumbs down)
        register_rest_route('rts/v1', '/track/rate', [
            'methods' => 'POST',
            'callback' => [$this, 'track_rate'],
            'permission_callback' => '__return_true'
        ]);

        // Track unhelpful (legacy-style endpoint for thumbs down)
        register_rest_route('rts/v1', '/track/unhelpful', [
            'methods' => 'POST',
            'callback' => [$this, 'track_unhelpful'],
            'permission_callback' => '__return_true'
        ]);
        // Track share
        register_rest_route('rts/v1', '/track/share', [
            'methods' => 'POST',
            'callback' => [$this, 'track_share'],
            'permission_callback' => '__return_true'
        ]);
        
        // Submit letter
        register_rest_route('rts/v1', '/letter/submit', [
            'methods' => 'POST',
            'callback' => [$this, 'submit_letter'],
            'permission_callback' => '__return_true'
        ]);
    }
    
    /**
     * Smart letter matching algorithm
     */
    public function get_next_letter($request) {
        $params = $request->get_json_params();
        $preferences = $params['preferences'] ?? [];
        $viewed = $params['viewed'] ?? [];

        // Normalise/validate preferences (prevents "no results" + slow fallback queries)
        $allowed_tones = ['gentle', 'real', 'hopeful'];
        $allowed_times = ['short', 'medium', 'long'];

        $prefs_feelings = (isset($preferences['feelings']) && is_array($preferences['feelings']))
            ? array_values(array_filter(array_map('sanitize_key', $preferences['feelings'])))
            : [];

        $prefs_tone = isset($preferences['tone']) ? sanitize_key((string) $preferences['tone']) : '';
        if ($prefs_tone === 'any' || !in_array($prefs_tone, $allowed_tones, true)) {
            $prefs_tone = '';
        }

        $prefs_time = isset($preferences['readingTime']) ? sanitize_key((string) $preferences['readingTime']) : '';
        if ($prefs_time === 'any' || !in_array($prefs_time, $allowed_times, true)) {
            $prefs_time = '';
        }
        
        // Build base query (avoid SQL ORDER BY RAND() - it melts big datasets)
        $args = [
            'post_type' => 'letter',
            'post_status' => 'publish',
            'posts_per_page' => 200, // Candidate pool size
            'post__not_in' => $viewed,
            'no_found_rows' => true,
            'orderby' => 'ID',
            'order' => 'DESC',
        ];
        
        // Apply preference filters ONLY if they might actually match something
        $tax_query = [];
        
        // Only filter by feelings if user selected specific ones
        if (!empty($prefs_feelings)) {
            $tax_query[] = [
                'taxonomy' => 'letter_feeling',
                'field' => 'slug',
                'terms' => $prefs_feelings,
                'operator' => 'IN'
            ];
        }
        
        // Only filter by tone if user selected a specific one (not "any")
        if (!empty($prefs_tone)) {
            $tax_query[] = [
                'taxonomy' => 'letter_tone',
                'field' => 'slug',
                'terms' => $prefs_tone
            ];
        }
        
        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
            if (count($tax_query) > 1) {
                $args['tax_query']['relation'] = 'AND';
            }
        }
        
        // Apply reading time filter ONLY if user selected specific time
        if (!empty($prefs_time)) {
            // Include letters missing the meta key (common after imports) to avoid empty results.
            $args['meta_query'] = [
                'relation' => 'OR',
                [
                    'key' => 'reading_time',
                    'value' => $prefs_time,
                    'compare' => '='
                ],
                [
                    'key' => 'reading_time',
                    'compare' => 'NOT EXISTS'
                ]
            ];
        }
        
                // =========================================================
        // PERFORMANCE: avoid ORDER BY RAND() for big datasets.
        // We build a small cached pool (IDs) and score within that.
        // =========================================================
        $pool_key = 'rts_letter_pool_' . md5(wp_json_encode([
            'feelings' => $prefs_feelings,
            'tone' => $prefs_tone,
            'time' => $prefs_time,
        ]));
        $pool_ids = get_transient($pool_key);

        if (is_array($pool_ids) && !empty($pool_ids)) {
            $args['post__in'] = array_values(array_map('intval', $pool_ids));
            $args['orderby'] = 'post__in';
            $args['posts_per_page'] = count($args['post__in']);
        } else {
            $counts = wp_count_posts('letter');
            $total = isset($counts->publish) ? (int) $counts->publish : 0;
            $max_offset = max(0, $total - (int) $args['posts_per_page']);
            $args['offset'] = ($max_offset > 0) ? random_int(0, $max_offset) : 0;
        }

$letters = new WP_Query($args);
        
        // Store fresh pool (5 minutes) if we just generated one
        if (!is_array($pool_ids) || empty($pool_ids)) {
            $fresh_ids = [];
            if (!empty($letters->posts)) {
                foreach ($letters->posts as $p) {
                    if (isset($p->ID)) $fresh_ids[] = (int) $p->ID;
                }
            }
            if (!empty($fresh_ids)) {
                shuffle($fresh_ids);
                // Cap pool size to keep it lightweight
                $fresh_ids = array_slice($fresh_ids, 0, 200);
                set_transient($pool_key, $fresh_ids, 5 * MINUTE_IN_SECONDS);
            }
        }


        // Fallback: If no matches with preferences, get ANY published letters not viewed
        // This ensures letters without tags/meta still show up
        if (!$letters->have_posts()) {
            $args = [
                'post_type' => 'letter',
                'post_status' => 'publish',
                'posts_per_page' => 50,
                'post__not_in' => $viewed,
                'orderby' => 'rand'
            ];
            $letters = new WP_Query($args);
        }
        
        // Final fallback: Include ALL letters if viewing history is blocking everything
        // This happens when user has seen all available letters
        if (!$letters->have_posts() && !empty($viewed)) {
            $args = [
                'post_type' => 'letter',
                'post_status' => 'publish',
                'posts_per_page' => 50,
                'orderby' => 'rand'
            ];
            $letters = new WP_Query($args);
        }
        
        // Score and rank letters
        $scored = [];
        while ($letters->have_posts()) {
            $letters->the_post();
            $letter_id = get_the_ID();

            // Ensure reading_time meta exists for future faster matching
            $this->ensure_reading_time_meta($letter_id);
            
            $score = 0;
            
            // Boost for new letters (< 30 days)
            $days_old = (time() - strtotime(get_the_date('c'))) / DAY_IN_SECONDS;
            if ($days_old < 30) {
                $score += 20;
            }
            
            // Boost for low view count
            $views = intval(get_post_meta($letter_id, 'view_count', true));
            if ($views < 100) {
                $score += 15;
            } elseif ($views < 500) {
                $score += 10;
            }
            
            // Boost for high "helpful" rate
            $helps = intval(get_post_meta($letter_id, 'help_count', true));
            $help_rate = $views > 0 ? ($helps / $views) : 0;
            $score += ($help_rate * 25);
            
            // Random factor (prevents same letter always winning)
            $score += rand(0, 10);
            
            $scored[] = [
                'id' => $letter_id,
                'content' => $this->format_letter_content(get_the_content()),
                'author' => get_post_meta($letter_id, 'author_name', true),
                'score' => $score,
                'views' => $views,
                'helps' => $helps,
                'date' => get_the_date('c')
            ];
        }
        wp_reset_postdata();
        
        // Sort by score, return highest
        if (!empty($scored)) {
            usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
            $letter = $scored[0];
        } else {
            $letter = null;
        }
        
        if (!$letter) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'No letters available right now. Please refresh in a moment.',
            ], 404);
        }

        return new WP_REST_Response([
            'success' => true,
            'letter' => $letter
        ]);
    }

    /**
     * AJAX fallback for next letter (public)
     * Used when REST POSTs are blocked by hosting / security layers.
     */
    public function ajax_get_next_letter() {
        // Optional nonce check. Public endpoints work without it, but some installs pass it.
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if ($nonce && !wp_verify_nonce($nonce, 'wp_rest')) {
            wp_send_json([
                'success' => false,
                'message' => 'Invalid security token. Please refresh and try again.'
            ], 403);
        }

        $raw_payload = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
        $payload = [];
        if (is_string($raw_payload) && $raw_payload !== '') {
            $decoded = json_decode($raw_payload, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $preferences = isset($payload['preferences']) && is_array($payload['preferences']) ? $payload['preferences'] : [];
        $viewed      = isset($payload['viewed']) && is_array($payload['viewed']) ? $payload['viewed'] : [];

        // Create a tiny request-like object so we can reuse the REST handler logic safely.
        $fake_request = new class($preferences, $viewed) {
            private $p;
            public function __construct($preferences, $viewed) {
                $this->p = ['preferences' => $preferences, 'viewed' => $viewed];
            }
            public function get_json_params() {
                return $this->p;
            }
        };

        $response = $this->get_next_letter($fake_request);

        if ($response instanceof WP_REST_Response) {
            wp_send_json($response->get_data(), (int) $response->get_status());
        }

        // Fallback safety
        wp_send_json([
            'success' => false,
            'message' => 'Unable to load letter. Please refresh and try again.'
        ], 500);
    }

    /**
     * AJAX fallback: Track view/helpful/rate/share (public)
     */
    public function ajax_track_view() {
        $this->ajax_bridge_track('view');
    }

    public function ajax_track_helpful() {
        $this->ajax_bridge_track('helpful');
    }

    public function ajax_track_rate() {
        $this->ajax_bridge_track('rate');
    }

    public function ajax_track_share() {
        $this->ajax_bridge_track('share');
    }

    /**
     * Shared AJAX bridge for tracking actions.
     * Keeps the REST handlers as source of truth.
     */
    private function ajax_bridge_track(string $type): void {
        // Optional nonce check. Public endpoints work without it, but some installs pass it.
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if ($nonce && !wp_verify_nonce($nonce, 'wp_rest')) {
            wp_send_json([
                'success' => false,
                'message' => 'Invalid security token. Please refresh and try again.'
            ], 403);
        }

        $raw_payload = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
        $payload = [];
        if (is_string($raw_payload) && $raw_payload !== '') {
            $decoded = json_decode($raw_payload, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $letter_id = isset($payload['letter_id']) ? absint($payload['letter_id']) : 0;
        $platform  = isset($payload['platform']) ? sanitize_text_field((string) $payload['platform']) : '';
        $value     = isset($payload['value']) ? sanitize_text_field((string) $payload['value']) : '';

        $fake_request = new class($letter_id, $platform, $value) {
            private $p;
            public function __construct($letter_id, $platform, $value) {
                $this->p = [
                    'letter_id' => $letter_id,
                    'platform'  => $platform,
                    'value'     => $value,
                ];
            }
            public function get_param($key) {
                return $this->p[$key] ?? null;
            }
        };

        switch ($type) {
            case 'view':
                $response = $this->track_view($fake_request);
                break;
            case 'helpful':
                $response = $this->track_helpful($fake_request);
                break;
            case 'rate':
                $response = $this->track_rate($fake_request);
                break;
            case 'share':
                $response = $this->track_share($fake_request);
                break;
            default:
                $response = new WP_REST_Response(['success' => false, 'message' => 'Invalid action'], 400);
        }

        if ($response instanceof WP_REST_Response) {
            wp_send_json($response->get_data(), (int) $response->get_status());
        }

        wp_send_json(['success' => false, 'message' => 'Request failed'], 500);
    }

    /**
     * AJAX fallback: Submit letter (public)
     */
    public function ajax_submit_letter() {
        // Optional nonce check.
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if ($nonce && !wp_verify_nonce($nonce, 'wp_rest')) {
            wp_send_json([
                'success' => false,
                'message' => 'Invalid security token. Please refresh and try again.'
            ], 403);
        }

        $raw_payload = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
        $payload = [];
        if (is_string($raw_payload) && $raw_payload !== '') {
            $decoded = json_decode($raw_payload, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $fake_request = new class($payload) {
            private $p;
            public function __construct($payload) {
                $this->p = is_array($payload) ? $payload : [];
            }
            public function get_json_params() {
                return $this->p;
            }
        };

        $response = $this->submit_letter($fake_request);
        if ($response instanceof WP_REST_Response) {
            wp_send_json($response->get_data(), (int) $response->get_status());
        }

        wp_send_json([
            'success' => false,
            'message' => 'Unable to submit letter. Please try again.'
        ], 500);
    }

    /**
     * Ensure reading_time meta exists (prevents slow fallbacks and "no match" behaviour)
     */
    private function ensure_reading_time_meta(int $post_id): void {
        $existing = get_post_meta($post_id, 'reading_time', true);
        if (!empty($existing)) {
            return;
        }

        $content = (string) get_post_field('post_content', $post_id);
        $word_count = str_word_count(strip_tags($content));

        if ($word_count < 100) {
            update_post_meta($post_id, 'reading_time', 'short');
        } elseif ($word_count < 200) {
            update_post_meta($post_id, 'reading_time', 'medium');
        } else {
            update_post_meta($post_id, 'reading_time', 'long');
        }
    }
    
    /**
     * Format letter content for display
     */
    private function format_letter_content($content) {
        // Remove any shortcodes
        $content = strip_shortcodes($content);
        
        // Convert line breaks
        $content = wpautop($content);
        
        // Clean up
        $content = wp_kses_post($content);
        
        return $content;
    }
    
    /**
     * Track letter view
     */
    public function track_view($request) {
        $letter_id = $request->get_param('letter_id');
        
        if (!$letter_id || get_post_type($letter_id) !== 'letter') {
            return new WP_REST_Response(['success' => false], 400);
        } 
        
        // Update global stats
        $this->increment_daily_stat('total_views');
        
        return new WP_REST_Response(['success' => true]);
    }
    
    /**
     * Track "this helped" click
     */
    public function track_helpful($request) {
        $letter_id = (int) $request->get_param('letter_id');

        if (!$letter_id || get_post_type($letter_id) !== 'letter') {
            return new WP_REST_Response(['success' => false], 400);
        }

        $this->update_letter_rating($letter_id, true);

        // Keep existing global stats key for backwards compatibility
        $this->increment_daily_stat('help_clicks');

        return new WP_REST_Response(['success' => true]);
    }

    /**
     * Track "Not for me" / thumbs down (legacy convenience endpoint)
     */
    public function track_unhelpful($request) {
        $letter_id = (int) $request->get_param('letter_id');

        if (!$letter_id || get_post_type($letter_id) !== 'letter') {
            return new WP_REST_Response(['success' => false], 400);
        }

        $this->update_letter_rating($letter_id, false);
        $this->increment_daily_stat('unhelpful_clicks');

        return new WP_REST_Response(['success' => true]);
    }

    /**
     * Track rating (up/down) in a single endpoint.
     * Expects: letter_id, value = "up" | "down"
     */
    public function track_rate($request) {
        $letter_id = (int) $request->get_param('letter_id');
        $value = (string) $request->get_param('value');

        if (!$letter_id || get_post_type($letter_id) !== 'letter') {
            return new WP_REST_Response(['success' => false], 400);
        }

        if ($value !== 'up' && $value !== 'down') {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid rating value'], 400);
        }

        $helpful = ($value === 'up');
        $this->update_letter_rating($letter_id, $helpful);

        // Stats
        $this->increment_daily_stat($helpful ? 'help_clicks' : 'unhelpful_clicks');

        return new WP_REST_Response(['success' => true]);
    }

    /**
     * Update per-letter rating meta and helpful percentage.
     */
    private function update_letter_rating(int $letter_id, bool $helpful): void {
        $up_key = 'rts_thumbs_up';
        $down_key = 'rts_thumbs_down';

        $ups = (int) get_post_meta($letter_id, $up_key, true);
        $downs = (int) get_post_meta($letter_id, $down_key, true);

        if ($helpful) {
            $ups++;
            update_post_meta($letter_id, $up_key, $ups);

            // Backwards compatibility: help_count meta
            update_post_meta($letter_id, 'help_count', $ups);
        } else {
            $downs++;
            update_post_meta($letter_id, $down_key, $downs);
        }

        $total = $ups + $downs;
        $pct = $total > 0 ? round(($ups / $total) * 100, 1) : 0.0;
        update_post_meta($letter_id, 'rts_helpful_pct', $pct);
    }

    function track_share($request) {
        $letter_id = $request->get_param('letter_id');
        $platform = $request->get_param('platform');
        
        if (!$letter_id || !$platform) {
            return new WP_REST_Response(['success' => false], 400);
        }
        
        // Increment platform-specific share count
        $meta_key = 'share_count_' . sanitize_key($platform);
        $current = intval(get_post_meta($letter_id, $meta_key, true));
        update_post_meta($letter_id, $meta_key, $current + 1);
        
        // Update total share count
        $total_meta_key = 'share_count_total';
        $total_current = intval(get_post_meta($letter_id, $total_meta_key, true));
        update_post_meta($letter_id, $total_meta_key, $total_current + 1);
        
        // Update global stats
        $this->increment_daily_stat('shares_' . $platform);
        
        return new WP_REST_Response(['success' => true]);
    }
    
    /**
     * Submit new letter
     */
    public function submit_letter($request) {
        $author_name = sanitize_text_field($request->get_param('author_name'));
        $letter_text = wp_kses_post($request->get_param('letter_text'));
        $author_email = sanitize_email($request->get_param('author_email'));
        
        // Enhanced spam checks (lightweight, non-intrusive)
        
        // Check 1: Multiple honeypot fields
        $honeypot_website = $request->get_param('website');
        $honeypot_company = $request->get_param('company');
        $honeypot_confirm = $request->get_param('confirm_email');
        
        if (!empty($honeypot_website) || !empty($honeypot_company) || !empty($honeypot_confirm)) {
            // Silent rejection for bots
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Submission rejected.'
            ], 400);
        }
        
        // Check 2: Nonce verification
        $nonce = $request->get_param('rts_token');
        if (!$nonce || !wp_verify_nonce($nonce, 'rts_submit_letter')) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Security check failed. Please refresh the page and try again.'
            ], 403);
        }
        
        // Check 3: Timing verification (lightweight bot detection)
        $timestamp = intval($request->get_param('rts_timestamp'));
        if ($timestamp > 0) {
            $time_diff = (time() * 1000) - $timestamp;
            
            // Too fast (< 3 seconds) - likely bot
            if ($time_diff < 3000) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Please take a moment to write your letter.'
                ], 400);
            }
            
            // Too slow (> 2 hours) - session expired or replay attack
            if ($time_diff > 7200000) {
                return new WP_REST_Response([
                    'success' => false,
                    'message' => 'Session expired. Please refresh the page and try again.'
                ], 400);
            }
        }
        
        // Check 4: Basic rate limiting (per email, 5 per day max)
        $submission_count = $this->get_daily_submissions_by_email($author_email);
        if ($submission_count >= 5) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'You have reached the daily submission limit (5 letters). Please try again tomorrow.'
            ], 429);
        }
        
        // Standard validation
        if (empty($letter_text)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Please write a letter before submitting.'
            ], 400);
        }
        
        if (strlen($letter_text) < 50) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Your letter must be at least 50 characters long.'
            ], 400);
        }
        
        if (empty($author_email) || !is_email($author_email)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Please provide a valid email address for moderation purposes.'
            ], 400);
        }
        
        // Create pending post
        $post_id = wp_insert_post([
            'post_type' => 'letter',
            'post_title' => $this->generate_letter_title($letter_text),
            'post_content' => $letter_text,
            'post_status' => 'pending',
            'meta_input' => [
                'author_name' => $author_name,
                'author_email' => $author_email,
                'submitted_at' => current_time('mysql'),
                'view_count' => 0,
                'help_count' => 0,
                'rts_letter_hash' => md5(trim(strtolower($letter_text)))
            ]
        ]);
        
        if (is_wp_error($post_id)) {
            return new WP_REST_Response([
                'success' => false,
                'message' => 'Failed to submit letter. Please try again.'
            ], 500);
        }

        // Ensure no social/featured image is attached for pending submissions.
        // Social images are generated on publish; attaching a failed/blank image here confuses admins.
        if (has_post_thumbnail($post_id)) {
            delete_post_thumbnail($post_id);
        }

        // Moderation/analysis is handled by the RTS Moderation Engine via save_post and background processing.
        
        // Send notification email
        $this->notify_admin_new_submission($post_id);
        
        // Update stats
        $this->increment_daily_stat('letters_submitted');
        
        return new WP_REST_Response([
            'success' => true,
            'message' => 'Thank you for writing. Your letter will be reviewed shortly and published soon.'
        ]);
    }
    
    /**
     * Get daily submission count by email (lightweight rate limiting)
     */
    private function get_daily_submissions_by_email($email) {
        $today_start = strtotime('today midnight');
        $today_end = strtotime('tomorrow midnight') - 1;
        
        $args = [
            'post_type' => 'letter',
            'post_status' => ['publish', 'pending', 'draft'],
            'meta_query' => [
                [
                    'key' => 'author_email',
                    'value' => $email,
                    'compare' => '='
                ],
                [
                    'key' => 'submitted_at',
                    'value' => [
                        date('Y-m-d H:i:s', $today_start),
                        date('Y-m-d H:i:s', $today_end)
                    ],
                    'compare' => 'BETWEEN',
                    'type' => 'DATETIME'
                ]
            ],
            'fields' => 'ids'
        ];
        
        $query = new WP_Query($args);
        return $query->found_posts;
    }
    
    /**
     * Generate a title from letter content
     */
    private function generate_letter_title($content) {
        $clean = strip_tags($content);
        $words = str_word_count($clean, 2);
        $first_words = array_slice($words, 0, 8);
        $title = implode(' ', $first_words);
        return wp_trim_words($title, 8, '...');
    }
    
    /**
     * Send email notification for new submission
     */
    private function notify_admin_new_submission($post_id) {
        $admin_email = get_option('rts_notify_email', get_option('admin_email'));
        $edit_link = admin_url("post.php?post={$post_id}&action=edit");
        
        $subject = '[Reasons to Stay] New Letter Awaiting Moderation';
        
        $message = "A new letter has been submitted to Reasons to Stay.\n\n";
        $message .= "Review and approve: {$edit_link}\n\n";
        $message .= "This letter will remain pending until you approve it.";
        
        wp_mail($admin_email, $subject, $message);
    }
    
    /**
     * Increment daily analytics stat
     */
    private function increment_daily_stat($stat_key) {
        $today = current_time('Y-m-d');
        $stats = get_option('rts_daily_stats', []);
        
        if (!isset($stats[$today])) {
            $stats[$today] = [];
        }
        
        $stats[$today][$stat_key] = ($stats[$today][$stat_key] ?? 0) + 1;
        
        // Keep only last 90 days
        if (count($stats) > 90) {
            $stats = array_slice($stats, -90, null, true);
        }
        
        update_option('rts_daily_stats', $stats);
    }
}

// Initialize
RTS_Letter_System::get_instance();

} // end class_exists check
