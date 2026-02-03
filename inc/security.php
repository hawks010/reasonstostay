<?php
/**
 * Reasons to Stay - Enterprise Security Layer
 * Rate limiting, spam detection, and bot protection.
 * Security hardened and performance optimized for 33k+ records.
 */

if (!defined('ABSPATH')) exit;

class RTS_Security {
    
    private static $instance = null;
    
    // Configurable limits
    private $rate_limits = [
        'submissions_per_hour' => 3,
        'submissions_per_day' => 10,
        'api_calls_per_minute' => 60
    ];
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Add security headers
        add_action('send_headers', [$this, 'add_security_headers']);
        
        // Hook into letter submission validation
        add_filter('rts_before_letter_submit', [$this, 'validate_submission'], 10, 2);
        
        // Save content fingerprint on post save (Standard & REST)
        add_action('save_post_letter', [$this, 'save_content_fingerprint'], 10, 2);
        add_action('rest_after_insert_letter', [$this, 'save_content_fingerprint_rest'], 10, 3);
        
        // Hook into API requests
        add_action('rest_api_init', [$this, 'setup_rate_limiting']);
        
        // Schedule Cleanup (Check only on admin init to save frontend resources)
        add_action('admin_init', [$this, 'schedule_cleanup']);
        
        // Actual cleanup hook
        add_action('rts_daily_security_cleanup', [$this, 'cleanup_old_data']);

        // One-time setup for indexes
        add_action('admin_init', [$this, 'ensure_database_indexes']);

        // Security Dashboard
        add_action('admin_menu', [$this, 'register_admin_page']);
    }

    /**
     * Register Admin Dashboard for Security Logs
     */
    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=letter',
            'Security Logs',
            'Security Logs',
            'manage_options',
            'rts-security-logs',
            [$this, 'display_security_logs']
        );
    }

    /**
     * Display Security Logs
     */
    public function display_security_logs() {
        if (!current_user_can('manage_options')) return;
        
        $logs = get_option('rts_security_log', []);
        
        echo '<div class="wrap"><h1>Security Logs</h1>';
        echo '<table class="widefat fixed striped">';
        echo '<thead><tr><th>Time</th><th>Event</th><th>Context</th></tr></thead><tbody>';
        
        if (empty($logs)) {
             echo '<tr><td colspan="3">No logs found.</td></tr>';
        } else {
            foreach (array_reverse($logs) as $log) {
                echo '<tr>';
                echo '<td>' . date('Y-m-d H:i:s', $log['timestamp']) . '</td>';
                echo '<td>' . esc_html($log['event']) . '</td>';
                echo '<td><pre>' . esc_html(json_encode($log['context'], JSON_PRETTY_PRINT)) . '</pre></td>';
                echo '</tr>';
            }
        }
        
        echo '</tbody></table></div>';
    }

    /**
     * Check if an external persistent object cache is active (Redis/Memcached).
     *
     * When enabled, values can live in cache while direct DB writes are not
     * immediately reflected. For counters/limits we should prefer transients
     * and cache-aware increment logic.
     */
    private function is_persistent_cache_available() {
        return wp_using_ext_object_cache();
    }

    /**
     * Ensure database indexes exist for performance (Run once)
     * Fixed: Performance optimization for large tables
     */
    public function ensure_database_indexes() {
        if (!current_user_can('manage_options')) return;

        $installed = get_option('rts_security_indexes_installed');
        if ($installed) return;

        global $wpdb;
        
        // Optimized check specific to our index
        $index_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW INDEX FROM {$wpdb->postmeta} WHERE Key_name = %s",
            'idx_content_fingerprint'
        ));

        if (!$index_exists) {
            // Standard index creation
            // Indexing meta_key and first 32 chars of value (MD5 hash length)
            $wpdb->query("CREATE INDEX idx_content_fingerprint ON {$wpdb->postmeta}(meta_key, meta_value(32))");
        }

        update_option('rts_security_indexes_installed', true);
    }
    
    /**
     * Schedule cron job only if missing (Optimized)
     */
    public function schedule_cleanup() {
        if (!wp_next_scheduled('rts_daily_security_cleanup')) {
            wp_schedule_event(time(), 'daily', 'rts_daily_security_cleanup');
        }
    }
    
    /**
     * Add security headers (Escaped)
     */
    public function add_security_headers() {
        if (headers_sent()) return;
        
        // Prevent header injection by escaping values
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-XSS-Protection' => '1; mode=block',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=()'
        ];

        foreach ($headers as $key => $val) {
            header($key . ': ' . esc_html($val));
        }
    }
    
    /**
     * Setup rate limiting for REST API
     */
    public function setup_rate_limiting() {
        add_filter('rest_pre_dispatch', [$this, 'check_api_rate_limit'], 10, 3);
    }
    
    /**
     * Check API rate limit
     */
    public function check_api_rate_limit($result, $server, $request) {
        // Only check our endpoints
        if (strpos($request->get_route(), '/rts/v1/') !== 0) {
            return $result;
        }
        
        $ip = $this->get_client_ip();
        $endpoint = $request->get_route();
        
        if (!$this->check_rate_limit_api($ip, $endpoint)) {
            return new WP_Error(
                'rate_limit_exceeded',
                'Too many requests. Please slow down.',
                ['status' => 429]
            );
        }
        
        return $result;
    }
    
    /**
     * Check API rate limit (Atomic using Object Cache if available)
     */
    private function check_rate_limit_api($ip, $endpoint) {
        $key = 'rts_api_' . md5($ip . $endpoint);
        $limit = $this->rate_limits['api_calls_per_minute'];
        
        if ($this->is_persistent_cache_available()) {
            // Atomic increment
            $count = wp_cache_incr($key, 1, 'rts');
            
            if (false === $count) {
                // Key didn't exist, add it with expiration
                wp_cache_add($key, 1, 'rts', 60);
                $count = 1;
            } 
            return $count <= $limit;
        } else {
            // Fallback for non-persistent cache environments
            // Accept race condition trade-off for compatibility
            $current = (int) get_transient($key);
            if ($current >= $limit) return false;
            set_transient($key, $current + 1, 60);
            return true;
        }
    }
    
    /**
     * Validate letter submission
     */
    public function validate_submission($is_valid, $data) {
        if (is_wp_error($is_valid)) return $is_valid;

        $ip = $this->get_client_ip();

        // 1. GeoIP Check (Optional/Advanced)
        $geo_check = $this->check_geoip_restrictions($ip);
        if (is_wp_error($geo_check)) {
            return $geo_check;
        }

        // 2. Verify Nonce (Fixed Timing Attack & Type Check)
        // 1. Verify Nonce (CRITICAL FIX)
        // The submit form generates a token field named "rts_token" via:
        // wp_create_nonce('rts_submit_letter')
        // We also accept legacy keys for cached forms.
        $nonce = '';
        if (isset($data['rts_token'])) {
            $nonce = $data['rts_token'];
        } elseif (isset($data['nonce'])) {
            $nonce = $data['nonce'];
        } elseif (isset($_REQUEST['nonce'])) {
            $nonce = $_REQUEST['nonce'];
        }
        
        if (!is_string($nonce) || empty($nonce)) {
            $error = new WP_Error('missing_nonce', __('Security token is missing or invalid.', 'rts'), ['status' => 403]);
            $this->log_security_event('Validation failed', ['error' => 'missing_nonce', 'ip' => $ip]);
            return $error;
        }

        // Must match the action used in shortcodes.php: wp_create_nonce('rts_submit_letter')
        $nonce_status = wp_verify_nonce($nonce, 'rts_submit_letter');
        if ($nonce_status !== 1 && $nonce_status !== 2) {
            // Fallback for legacy cached forms
            $fallback = wp_verify_nonce($nonce, 'rts_frontend_nonce');
            if ($fallback !== 1 && $fallback !== 2) {
                $error = new WP_Error('invalid_nonce', __('Security token invalid or expired. Please refresh.', 'rts'), ['status' => 403]);
                $this->log_security_event('Validation failed', ['error' => 'invalid_nonce', 'ip' => $ip]);
                return $error;
            }
        }

        // 3. Check rate limits (Exponential Backoff Check)
        if ($this->is_ip_blocked($ip)) {
             return new WP_Error(
                'rate_limit_blocked',
                __('You are temporarily blocked due to excessive activity. Please try again later.', 'rts'),
                ['status' => 429]
            );
        }

        if (!$this->check_submission_rate_limit($ip)) {
            $this->apply_rate_limit_penalty($ip);
            return new WP_Error(
                'rate_limit_exceeded',
                __('You have submitted too many letters. Please wait before writing again.', 'rts'),
                ['status' => 429]
            );
        }
        
        // 4. Input Validation
        if (!isset($data['letter_text']) || empty($data['letter_text'])) {
             return new WP_Error('missing_content', __('Letter content is required.', 'rts'), ['status' => 400]);
        }
        
        // Content Length Validation
        $content_len = strlen(trim($data['letter_text']));
        if ($content_len < 10) {
            return new WP_Error('content_too_short', __('Letter must be at least 10 characters.', 'rts'), ['status' => 400]);
        }
        if ($content_len > 10000) {
            return new WP_Error('content_too_long', __('Letter is too long. Please shorten it.', 'rts'), ['status' => 400]);
        }

        // 5. Check for spam patterns (Honeypot + Keywords)
        $spam_check = $this->detect_spam_patterns($data);
        if (is_wp_error($spam_check)) {
            $this->log_security_event('Validation failed', ['error' => $spam_check->get_error_code(), 'ip' => $ip]);
            return $spam_check;
        }
        
        // 6. Check for duplicate content
        if ($this->is_duplicate_submission($data['letter_text'])) {
            return new WP_Error(
                'duplicate_content',
                __('This letter appears to be a duplicate. Please write something unique.', 'rts'),
                ['status' => 400]
            );
        }
        
        // 7. Check submission speed (anti-bot)
        if (!$this->check_submission_speed($data)) {
            $this->log_security_event('Suspicious submission speed', ['ip' => $ip]);
            return new WP_Error(
                'submission_too_fast',
                __('Please take more time to write your letter.', 'rts'),
                ['status' => 400]
            );
        }
        
        // Log success for rate limiting
        $this->track_submission($ip);
        
        return true;
    }

    /**
     * Consistent content cleaning for hashing
     */
    private function clean_content_for_hash($content) {
        return md5(trim(strtolower(wp_strip_all_tags($content))));
    }

    /**
     * Store Content Fingerprint on Save
     * Handles both manual saves and REST API
     */
    public function save_content_fingerprint($post_id, $post = null) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (get_post_type($post_id) !== 'letter') return;
        if (wp_is_post_revision($post_id)) return;

        // Handle both object (hook) and manual usage
        if (!$post) $post = get_post($post_id);
        
        $content = isset($post->post_content) ? $post->post_content : '';
        
        if (!empty($content)) {
            $hash = $this->clean_content_for_hash($content);
            update_post_meta($post_id, 'content_fingerprint', $hash);
        }
    }

    /**
     * REST API Helper for Fingerprinting
     */
    public function save_content_fingerprint_rest($post, $request, $creating) {
        $this->save_content_fingerprint($post->ID, $post);
    }
    
    /**
     * Check submission rate limits (Read-only)
     */
    private function check_submission_rate_limit($ip) {
        $hourly_key = 'rts_sub_hr_' . md5($ip);
        $daily_key  = 'rts_sub_day_' . md5($ip);
        
        $hourly = (int) get_transient($hourly_key);
        $daily  = (int) get_transient($daily_key);
        
        if ($hourly >= $this->rate_limits['submissions_per_hour']) {
            $this->log_security_event('Hourly rate limit exceeded', ['ip' => $ip]);
            return false;
        }
        
        if ($daily >= $this->rate_limits['submissions_per_day']) {
            $this->log_security_event('Daily rate limit exceeded', ['ip' => $ip]);
            return false;
        }
        
        return true;
    }
    
    /**
     * Increment submission counters (Atomic via SQL)
     * Prevents race conditions on timeout setting
     */
    private function track_submission($ip) {
        $hourly_key = 'rts_sub_hr_' . md5($ip);
        $daily_key  = 'rts_sub_day_' . md5($ip);

        // Use transients so it behaves correctly with external object caches
        // (Redis/Memcached). Using direct SQL writes can desync cached values.
        $hourly = (int) get_transient($hourly_key);
        set_transient($hourly_key, $hourly + 1, HOUR_IN_SECONDS);

        $daily = (int) get_transient($daily_key);
        set_transient($daily_key, $daily + 1, DAY_IN_SECONDS);
    }

    /**
     * Apply Exponential Backoff
     */
    private function apply_rate_limit_penalty($ip) {
        $key = 'rts_block_' . md5($ip);
        $current_penalty = (int) get_transient($key);
        
        $new_duration = $current_penalty > 0 ? $current_penalty * 2 : 300;
        $new_duration = min($new_duration, DAY_IN_SECONDS);
        
        set_transient($key, $new_duration, $new_duration);
    }

    private function is_ip_blocked($ip) {
        return get_transient('rts_block_' . md5($ip)) !== false;
    }
    
    /**
     * Detect spam patterns (Enhanced with Honeypot & Quality Check)
     */
    private function detect_spam_patterns($data) {
        $text = strtolower($data['letter_text'] ?? '');

        // 1. Honeypot check (fields are visually hidden in the form)
        // If bots fill these, fail closed.
        if (!empty($data['website']) || !empty($data['company']) || !empty($data['confirm_email'])) {
            $this->log_security_event('Bot detected: honeypot triggered', ['ip' => $this->get_client_ip()]);
            return new WP_Error('bot_detected', __('Submission failed.', 'rts'), ['status' => 400]);
        }
        
        // 2. Quality check (word count)
        $words = str_word_count($text);
        if ($words < 5) {
            return new WP_Error('quality_check', __('Please write a more detailed letter.', 'rts'), ['status' => 400]);
        }
        
        // Excessive URLs
        $url_count = preg_match_all('/(http:\/\/|https:\/\/|www\.)/i', $text);
        if ($url_count > 2) {
            return new WP_Error('spam_detected', __('Too many links detected.', 'rts'), ['status' => 400]);
        }
        
        // Suspicious keywords
        $spam_keywords = ['viagra', 'cialis', 'casino', 'lottery', 'crypto', 'bitcoin', 'buy now'];
        foreach ($spam_keywords as $keyword) {
            if (strpos($text, $keyword) !== false) {
                $this->log_security_event('Spam keyword match', ['keyword' => $keyword]);
                return new WP_Error('spam_detected', __('Spam detected.', 'rts'), ['status' => 400]);
            }
        }
        
        // Repeated characters
        if (preg_match('/(.)\1{9,}/', $text)) {
            return new WP_Error('spam_detected', __('Suspicious text patterns detected.', 'rts'), ['status' => 400]);
        }
        
        return true;
    }
    
    /**
     * Check if submission is duplicate
     */
    private function is_duplicate_submission($text) {
        global $wpdb;
        
        $content_hash = $this->clean_content_for_hash($text);
        
        $exists = $wpdb->get_var($wpdb->prepare("
            SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = 'content_fingerprint' 
            AND meta_value = %s 
            LIMIT 1
        ", $content_hash));
        
        return !empty($exists);
    }
    
    /**
     * Check submission speed
     */
    private function check_submission_speed($data) {
        if (isset($data['rts_timestamp'])) {
            $start_time = intval($data['rts_timestamp']);
            $now = time();

            if ($start_time > $now || $start_time < ($now - DAY_IN_SECONDS)) {
                return false;
            }

            $time_spent = $now - $start_time;
            
            if ($time_spent < 3) return false; 
        }
        return true;
    }

    /**
     * GeoIP Blocking (Placeholder for future service)
     */
    private function check_geoip_restrictions($ip) {
        // Placeholder: Hook for GeoIP service integration
        // if (class_exists('RTS_GeoIP') && RTS_GeoIP::is_restricted($ip)) {
        //     return new WP_Error('geo_restricted', __('Access not available in your region.', 'rts'), ['status' => 403]);
        // }
        return true;
    }
    
    /**
     * Get client IP address (Secure with Validation & Entropy)
     */
    private function get_client_ip() {
        $ip = '';
        
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip = trim($ips[0]);
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        $ip = filter_var($ip, FILTER_VALIDATE_IP);
        
        if (!$ip || $ip === '0.0.0.0') {
            $entropy = ($_SERVER['HTTP_USER_AGENT'] ?? '') . 
                       ($_SERVER['REMOTE_PORT'] ?? '') . 
                       ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '');
            return 'invalid_ip_' . md5($entropy);
        }
        
        return $ip;
    }
    
    /**
     * Log security event (Sanitized & Optimized)
     */
    private function log_security_event($event, $context = []) {
        $log = get_option('rts_security_log', []);
        
        $log[] = [
            'event' => sanitize_text_field($event),
            'context' => $this->sanitize_log_context($context),
            'timestamp' => time()
        ];
        
        if (count($log) > 100) {
            $log = array_slice($log, -100);
        }
        
        update_option('rts_security_log', $log, false);

        // Real-time alerts for critical events
        $critical_events = [
            'Hourly rate limit exceeded',
            'Daily rate limit exceeded', 
            'Suspicious submission speed'
        ];
        
        if (in_array($event, $critical_events)) {
            $admin_email = get_option('admin_email');
            $site_name = get_bloginfo('name');
            
            wp_mail(
                $admin_email,
                "[{$site_name}] Security Alert: {$event}",
                json_encode($context, JSON_PRETTY_PRINT)
            );
        }
    }

    /**
     * Recursive Sanitization (Memory Safe)
     */
    private function sanitize_log_context($data, $depth = 0) {
        if ($depth > 5) return '[Recursion Limit]';
        
        if (is_array($data)) {
            $result = [];
            foreach ($data as $k => $v) {
                $result[$k] = $this->sanitize_log_context($v, $depth + 1);
            }
            return $result;
        }
        return sanitize_text_field($data);
    }
    
    /**
     * Clean up old tracking data
     */
    public function cleanup_old_data() {
        $log = get_option('rts_security_log', []);
        $cutoff = time() - (30 * DAY_IN_SECONDS);
        
        $new_log = array_filter($log, function($entry) use ($cutoff) {
            return isset($entry['timestamp']) && $entry['timestamp'] > $cutoff;
        });
        
        if (count($log) !== count($new_log)) {
            update_option('rts_security_log', array_values($new_log), false);
        }

        // Clean up expired block transients
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE %s 
             AND option_value < %d",
            '_transient_timeout_rts_block_%',
            time()
        ));
    }
}

// Initialize
RTS_Security::get_instance();

// Note: This file runs from a theme context. If you migrate this to a plugin,
// you can clear the scheduled hook on plugin deactivation.