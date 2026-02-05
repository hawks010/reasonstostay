<?php
/**
 * RTS Subscription Form
 * Handles frontend subscription form with security, rate limiting, and AJAX.
 *
 * @package    RTS_Subscriber_System
 * @subpackage Frontend
 * @version    1.0.6
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_Subscription_Form {
    
    /**
     * @var RTS_Subscriber_CPT|null
     */
    private $subscriber_cpt;

    /**
     * @var RTS_Email_Engine|null
     */
    private $email_engine;
    
    // Hooks separated so class can be instantiated for utility
    public function init_hooks() {
        add_shortcode('rts_subscribe', array($this, 'render_form'));
        
        // AJAX hooks
        add_action('wp_ajax_rts_subscribe', array($this, 'handle_subscription'));
        add_action('wp_ajax_nopriv_rts_subscribe', array($this, 'handle_subscription'));
        
        // Scripts
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Cron
        add_action('rts_cleanup_rate_limits', array($this, 'cleanup_rate_limits'));

        // Only check schedule in admin to save frontend performance
        if (is_admin()) {
            add_action('admin_init', array($this, 'schedule_events'));
        }
    }

    /**
     * Schedule necessary cron events.
     */
    public function schedule_events() {
        if (!wp_next_scheduled('rts_cleanup_rate_limits')) {
            wp_schedule_event(time(), 'hourly', 'rts_cleanup_rate_limits');
        }
    }

    /**
     * Cron callback to clean up expired rate limits and tokens.
     */
    public function cleanup_rate_limits() {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_rate_limits';
        
        // Clean rate limits
        if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
            $wpdb->query("DELETE FROM $table WHERE expires < NOW()");
        }

        // Cleanup expired form token transients (best effort)
        // Helps keep the options table clean if object cache is not used
        if (!wp_using_ext_object_cache()) {
            $wpdb->query(
                "DELETE FROM {$wpdb->options} 
                 WHERE option_name LIKE '_transient_rts_tok_%' 
                 AND option_value < UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 2 HOUR))"
            );
        }
    }
    
    /**
     * Load dependencies on demand.
     */
    private function load_dependencies() {
        if ($this->subscriber_cpt && $this->email_engine) {
            return true;
        }

        if (!class_exists('RTS_Subscriber_CPT')) {
            if (file_exists(plugin_dir_path(__FILE__) . 'class-subscriber-cpt.php')) {
                require_once plugin_dir_path(__FILE__) . 'class-subscriber-cpt.php';
            }
        }

        if (!class_exists('RTS_Email_Engine')) {
            if (file_exists(plugin_dir_path(__FILE__) . 'class-email-engine.php')) {
                require_once plugin_dir_path(__FILE__) . 'class-email-engine.php';
            }
        }

        if (class_exists('RTS_Subscriber_CPT') && class_exists('RTS_Email_Engine')) {
            $this->subscriber_cpt = new RTS_Subscriber_CPT();
            $this->email_engine = new RTS_Email_Engine();
            return true;
        }

        return false;
    }

    /**
     * Enqueue scripts and styles.
     */
    public function enqueue_scripts() {
        // Enqueue Google reCAPTCHA if enabled
        if (get_option('rts_recaptcha_enabled') && get_option('rts_recaptcha_site_key')) {
            wp_enqueue_script('google-recaptcha', 'https://www.google.com/recaptcha/api.js', array(), null, true);
        }

        // Register dummy handle for our inline script
        wp_register_script('rts-subscription', false, array('jquery'), '1.0.5', true);
        
        // Localize data for JS - Securely passing strings
        wp_localize_script('rts-subscription', 'rts_subscription', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('rts_subscribe_action'),
            'messages' => array(
                'invalid_email' => __('Please enter a valid email address.', 'rts-subscriber-system'),
                'success'       => __('Thank you for subscribing!', 'rts-subscriber-system'),
                'error'         => __('An error occurred. Please try again.', 'rts-subscriber-system'),
                'rate_limited'  => __('Too many attempts. Please try again later.', 'rts-subscriber-system'),
                'loading'       => __('Subscribing...', 'rts-subscriber-system'),
                'privacy'       => __('You must agree to the privacy policy.', 'rts-subscriber-system')
            )
        ));
        
        // Inline JS logic
        $js = "
        jQuery(document).ready(function($) {
            // Enhanced Browser Fingerprinting
            var fpField = $('#rts-client-fingerprint');
            if(fpField.length) {
                var components = {
                    screen: window.screen.width + 'x' + window.screen.height + 'x' + window.screen.colorDepth,
                    timezone: new Date().getTimezoneOffset(),
                    language: navigator.language || navigator.userLanguage,
                    platform: navigator.platform
                };
                // Base64 encode safely (handle Unicode)
                fpField.val(window.btoa(encodeURIComponent(JSON.stringify(components))));
            }

            $('.rts-subscribe-form').on('submit', function(e) {
                e.preventDefault();
                var form = $(this);
                var btn = form.find('.rts-form-submit');
                var msg = form.find('.rts-form-message');
                var originalText = btn.text();
                
                // Basic client-side validation
                var email = form.find('input[name=\"email\"]').val();
                if (!email || email.indexOf('@') === -1) {
                    showMessage(msg, rts_subscription.messages.invalid_email, 'error');
                    return;
                }

                // Check privacy checkbox if present
                var privacy = form.find('input[name=\"privacy_consent\"]');
                if (privacy.length > 0 && !privacy.is(':checked')) {
                    showMessage(msg, rts_subscription.messages.privacy, 'error');
                    return;
                }
                
                // Disable UI
                btn.prop('disabled', true).text(rts_subscription.messages.loading);
                msg.html('').removeClass('rts-success rts-error').css('display', 'none');
                
                // Collect data
                var data = form.serialize();
                data += '&action=rts_subscribe&nonce=' + rts_subscription.nonce;
                
                $.post(rts_subscription.ajax_url, data, function(response) {
                    if (response.success) {
                        showMessage(msg, response.data.message, 'success');
                        form[0].reset();
                    } else {
                        showMessage(msg, response.data.message || rts_subscription.messages.error, 'error');
                    }
                }).fail(function() {
                    showMessage(msg, rts_subscription.messages.error, 'error');
                }).always(function() {
                    btn.prop('disabled', false).text(originalText);
                });
            });
            
            function showMessage(el, text, type) {
                el.html(text).removeClass('rts-success rts-error').addClass('rts-' + type).css('display', 'block');
            }
        });
        ";
        
        wp_add_inline_script('rts-subscription', $js);

        // Register and enqueue CSS handle for inline styles
        wp_register_style('rts-subscription-css', false);
        wp_enqueue_style('rts-subscription-css');

        // Add Inline CSS (CSP Safe)
        $css = "
            .rts-honeypot { display:none !important; visibility:hidden !important; height:0; opacity:0; pointer-events:none; }
            .rts-form-message { display:none; margin-top: 10px; padding: 10px; border-radius: 4px; }
            .rts-error { color: #721c24; background-color: #f8d7da; border: 1px solid #f5c6cb; }
            .rts-success { color: #155724; background-color: #d4edda; border: 1px solid #c3e6cb; }
        ";
        wp_add_inline_style('rts-subscription-css', $css);

        wp_enqueue_script('rts-subscription');
    }
    
    /**
     * Render the subscription form shortcode.
     */
    public function render_form($atts) {
        $atts = shortcode_atts(array(
            'title'            => __('Stay Connected', 'rts-subscriber-system'),
            'button_text'      => __('Subscribe', 'rts-subscriber-system'),
            'show_frequency'   => true,
            'show_preferences' => true,
            'require_privacy'  => true,
        ), $atts);
        
        // Generate Transient Token for CSRF protection (Session-less)
        $form_token = $this->generate_form_token();
        
        ob_start();
        ?>
        <div class="rts-subscribe-wrapper" id="rts-subscribe-<?php echo esc_attr(uniqid()); ?>">
            <form class="rts-subscribe-form" method="post" novalidate>
                <input type="hidden" name="form_token" value="<?php echo esc_attr($form_token); ?>">
                <!-- Fingerprint Field -->
                <input type="hidden" name="client_fingerprint" id="rts-client-fingerprint">
                
                <?php if ($atts['title']): ?>
                    <h3 class="rts-subscribe-title"><?php echo esc_html($atts['title']); ?></h3>
                <?php endif; ?>
                
                <div class="rts-form-group">
                    <label for="rts-email" class="rts-form-label"><?php _e('Email Address', 'rts-subscriber-system'); ?></label>
                    <input type="email" id="rts-email" name="email" class="rts-form-input" required 
                           placeholder="<?php esc_attr_e('your@email.com', 'rts-subscriber-system'); ?>"
                           style="width: 100%; padding: 8px; margin-bottom: 10px;">
                </div>
                
                <?php if ($atts['show_frequency']): ?>
                <div class="rts-form-group" style="margin-bottom: 15px;">
                    <label for="rts-frequency" class="rts-form-label" style="display:block; margin-bottom:5px;"><?php _e('How often?', 'rts-subscriber-system'); ?></label>
                    <select id="rts-frequency" name="frequency" class="rts-form-select" style="width: 100%; padding: 8px;">
                        <option value="weekly" selected><?php _e('Weekly (recommended)', 'rts-subscriber-system'); ?></option>
                        <option value="daily"><?php _e('Daily', 'rts-subscriber-system'); ?></option>
                        <option value="monthly"><?php _e('Monthly', 'rts-subscriber-system'); ?></option>
                    </select>
                </div>
                <?php endif; ?>
                
                <?php if ($atts['show_preferences']): ?>
                <div class="rts-form-group" style="margin-bottom: 15px;">
                    <p class="rts-form-label" style="margin-bottom:5px; font-weight:bold;"><?php _e('What would you like to receive?', 'rts-subscriber-system'); ?></p>
                    <label class="rts-checkbox-label" style="display:block; margin-bottom:5px;">
                        <input type="checkbox" name="prefs[]" value="letters" checked>
                        <span><?php _e('Letters', 'rts-subscriber-system'); ?></span>
                    </label>
                    <label class="rts-checkbox-label" style="display:block;">
                        <input type="checkbox" name="prefs[]" value="newsletters" checked>
                        <span><?php _e('Newsletters', 'rts-subscriber-system'); ?></span>
                    </label>
                </div>
                <?php endif; ?>
                
                <?php if ($atts['require_privacy']): ?>
                <div class="rts-form-group" style="margin-bottom: 15px;">
                    <label class="rts-checkbox-label">
                        <input type="checkbox" name="privacy_consent" required>
                        <span style="font-size: 0.9em;"><?php 
                            printf(
                                __('I agree to the %sPrivacy Policy%s and consent to receive emails.', 'rts-subscriber-system'),
                                '<a href="' . esc_url(get_privacy_policy_url()) . '" target="_blank">',
                                '</a>'
                            ); 
                        ?></span>
                    </label>
                </div>
                <?php endif; ?>
                
                <!-- Honeypot field for bots -->
                <div class="rts-honeypot" aria-hidden="true">
                    <input type="text" name="rts_website" tabindex="-1" autocomplete="off">
                </div>
                
                <?php if (get_option('rts_recaptcha_enabled') && get_option('rts_recaptcha_site_key')): ?>
                <div class="rts-form-group" style="margin-bottom: 15px;">
                    <div class="g-recaptcha" data-sitekey="<?php echo esc_attr(get_option('rts_recaptcha_site_key')); ?>"></div>
                </div>
                <?php endif; ?>
                
                <button type="submit" class="rts-form-submit button" style="width:100%; padding: 10px;">
                    <?php echo esc_html($atts['button_text']); ?>
                </button>
                
                <div class="rts-form-message" role="alert" aria-live="polite"></div>
                
                <p class="rts-privacy-notice" style="margin-top: 10px; font-size: 0.8em; text-align: center; color: #666;">
                    <?php _e('We respect your privacy. Unsubscribe anytime.', 'rts-subscriber-system'); ?>
                </p>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Handle AJAX subscription request.
     */
    public function handle_subscription() {
        try {
            // Verify nonce
            if (!check_ajax_referer('rts_subscribe_action', 'nonce', false)) {
                throw new Exception(__('Security check failed.', 'rts-subscriber-system'));
            }
            
            // Rate limiting
            if ($this->is_rate_limited()) {
                throw new Exception(__('Too many subscription attempts. Please try again later.', 'rts-subscriber-system'));
            }
            
            // Validate submission
            $data = $this->validate_submission($_POST);
            
            // Explicitly check uniqueness (before loading CPT for speed/clarity)
            if ($this->email_exists($data['email'])) {
                throw new Exception(__('This email is already subscribed.', 'rts-subscriber-system'));
            }

            if (!$this->load_dependencies()) {
                throw new Exception(__('System error: Dependencies could not be loaded.', 'rts-subscriber-system'));
            }
            
            // Create subscriber
            $subscriber_id = $this->subscriber_cpt->create_subscriber(
                $data['email'],
                $data['frequency'],
                'website',
                array(
                    'ip_address'       => $this->get_client_ip(),
                    'user_agent'       => sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? ''),
                    'pref_letters'     => in_array('letters', $data['prefs']),
                    'pref_newsletters' => in_array('newsletters', $data['prefs']),
                )
            );
            
            if (is_wp_error($subscriber_id)) {
                throw new Exception($subscriber_id->get_error_message());
            }
            
            // Update rate limit
            $this->increment_rate_limit();
            
            // Send appropriate email
            $message = $this->send_subscription_email($subscriber_id);
            
            wp_send_json_success(array(
                'message'       => $message,
                'subscriber_id' => $subscriber_id
            ));
            
        } catch (Exception $e) {
            error_log('RTS Subscription Error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => $e->getMessage()
            ));
        }
    }
    
    private function validate_submission($post_data) {
        // Honeypot check
        if (!empty($post_data['rts_website'])) {
            throw new Exception(__('Invalid submission detected.', 'rts-subscriber-system'));
        }
        
        // Form token validation (Session-less)
        if (!$this->validate_form_token($post_data['form_token'] ?? '')) {
            throw new Exception(__('Form session expired. Please refresh the page.', 'rts-subscriber-system'));
        }
        
        // Email validation
        $email = sanitize_email($post_data['email'] ?? '');
        if (!is_email($email)) {
            throw new Exception(__('Please enter a valid email address.', 'rts-subscriber-system'));
        }
        
        // Frequency validation
        $frequency = sanitize_text_field($post_data['frequency'] ?? 'weekly');
        if (!in_array($frequency, array('daily', 'weekly', 'monthly'), true)) {
            $frequency = 'weekly';
        }
        
        // Preferences validation
        $prefs = array();
        if (!empty($post_data['prefs']) && is_array($post_data['prefs'])) {
            $allowed_prefs = array('letters', 'newsletters');
            foreach ($post_data['prefs'] as $pref) {
                if (in_array($pref, $allowed_prefs, true)) {
                    $prefs[] = $pref;
                }
            }
        }
        if (empty($prefs)) {
            $prefs = array('letters', 'newsletters'); // Default if nothing selected
        }
        
        // Privacy consent (if required)
        if (get_option('rts_require_privacy_consent', true) && empty($post_data['privacy_consent'])) {
            throw new Exception(__('You must agree to the privacy policy to subscribe.', 'rts-subscriber-system'));
        }
        
        // reCAPTCHA validation
        if (get_option('rts_recaptcha_enabled') && !empty($post_data['g-recaptcha-response'])) {
            if (!$this->validate_recaptcha($post_data['g-recaptcha-response'])) {
                throw new Exception(__('reCAPTCHA verification failed.', 'rts-subscriber-system'));
            }
        }
        
        return array(
            'email'     => $email,
            'frequency' => $frequency,
            'prefs'     => $prefs,
        );
    }
    
    private function send_subscription_email($subscriber_id) {
        $require_verification = (bool) apply_filters('rts_require_email_verification', (bool) get_option('rts_require_email_verification', true));
        
        if ($require_verification) {
            $this->email_engine->send_verification_email($subscriber_id);
            return __('Success! Please check your email to verify your subscription.', 'rts-subscriber-system');
        } else {
            $this->email_engine->send_welcome_email($subscriber_id);
            return __('Thank you for subscribing! Check your inbox for a welcome message.', 'rts-subscriber-system');
        }
    }
    
    private function validate_recaptcha($response) {
        $secret_key = get_option('rts_recaptcha_secret_key');
        if (!$secret_key) return true; // Fail open if misconfigured
        
        $verify = wp_remote_post('https://www.google.com/recaptcha/api/siteverify', array(
            'body' => array(
                'secret'   => $secret_key,
                'response' => $response,
                'remoteip' => $this->get_client_ip()
            ),
            'timeout' => 5 // 5 second timeout to prevent hanging
        ));
        
        if (is_wp_error($verify)) {
            error_log('RTS reCAPTCHA Error: ' . $verify->get_error_message());
            return false;
        }
        
        $result = json_decode(wp_remote_retrieve_body($verify));
        return isset($result->success) && $result->success;
    }
    
    private function is_rate_limited() {
        if (!get_option('rts_email_rate_limit_enabled', true)) {
            return false;
        }
        
        // Mix IP + User Agent + Fingerprint for a robust key
        $key_part = $this->get_client_ip() . ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_POST['client_fingerprint'] ?? '');
        $key = 'rts_rate_' . md5($key_part);
        $limit = get_option('rts_email_rate_limit_per_hour', 5);

        // Check using object cache or DB depending on env
        if (wp_using_ext_object_cache()) {
            $attempts = wp_cache_get($key, 'rts_rate_limits');
        } else {
            global $wpdb;
            $table = $wpdb->prefix . 'rts_rate_limits';
            // Only query if table exists
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") === $table) {
                $attempts = $wpdb->get_var($wpdb->prepare("SELECT attempts FROM $table WHERE id = %s AND expires > NOW()", $key));
            } else {
                $attempts = 0;
            }
        }
        
        return $attempts && $attempts >= $limit;
    }
    
    private function increment_rate_limit() {
        // Mix IP + User Agent + Fingerprint
        $key_part = $this->get_client_ip() . ($_SERVER['HTTP_USER_AGENT'] ?? '') . ($_POST['client_fingerprint'] ?? '');
        $key = 'rts_rate_' . md5($key_part);

        if (wp_using_ext_object_cache()) {
            $attempts = wp_cache_incr($key, 1, 'rts_rate_limits');
            if ($attempts === false) {
                wp_cache_set($key, 1, 'rts_rate_limits', HOUR_IN_SECONDS);
            }
        } else {
            // Atomic DB UPSERT
            global $wpdb;
            $table = $wpdb->prefix . 'rts_rate_limits';
            
            // Create table on the fly if missing (rare but safe)
            if ($wpdb->get_var("SHOW TABLES LIKE '$table'") !== $table) {
                $this->create_tables(); // Fallback if installer failed
            }

            // Using ON DUPLICATE KEY UPDATE for atomicity
            $wpdb->query($wpdb->prepare(
                "INSERT INTO $table (id, attempts, expires) 
                 VALUES (%s, 1, DATE_ADD(NOW(), INTERVAL 1 HOUR))
                 ON DUPLICATE KEY UPDATE 
                 attempts = attempts + 1, 
                 expires = DATE_ADD(NOW(), INTERVAL 1 HOUR)",
                $key
            ));
        }
    }

    /**
     * Fallback table creation if installer missed it
     */
    private function create_tables() {
        if (class_exists('RTS_Database_Installer')) {
            RTS_Database_Installer::install();
        }
    }

    /**
     * Check if email exists in subscriber records.
     */
    private function email_exists($email) {
        $cache_key = 'rts_email_exists_' . md5($email);
        $cached = wp_cache_get($cache_key, 'rts_subscribers');
        if ($cached !== false) {
            return (bool) $cached;
        }

        global $wpdb;
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p 
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
             WHERE p.post_type = 'rts_subscriber' 
             AND p.post_status = 'publish' 
             AND pm.meta_key = '_rts_subscriber_email' 
             AND pm.meta_value = %s",
            $email
        ));
        
        $exists = $count > 0;
        wp_cache_set($cache_key, (int) $exists, 'rts_subscribers', HOUR_IN_SECONDS);
        return $exists;
    }

    /**
     * Generate session-less form token.
     */
    private function generate_form_token() {
        // Shortened key prefix to avoid excessive length (limit ~45-50 chars helpful for memcached keys sometimes)
        $hash = md5($this->get_client_ip() . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $key = 'rts_tok_' . substr($hash, 0, 12);
        
        // Re-use valid token if exists
        if (wp_using_ext_object_cache()) {
            $token = wp_cache_get($key, 'rts_tokens');
            if ($token) return $token;
        } else {
            $token = get_transient($key);
            if ($token) return $token;
        }
        
        $token = bin2hex(random_bytes(32));
        
        if (wp_using_ext_object_cache()) {
            wp_cache_set($key, $token, 'rts_tokens', 1800); // 30 mins
        } else {
            set_transient($key, $token, 1800);
        }
        
        return $token;
    }

    /**
     * Validate session-less form token.
     */
    private function validate_form_token($token) {
        if (empty($token)) return false;
        
        $hash = md5($this->get_client_ip() . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $key = 'rts_tok_' . substr($hash, 0, 12);
        
        if (wp_using_ext_object_cache()) {
            $stored_token = wp_cache_get($key, 'rts_tokens');
        } else {
            $stored_token = get_transient($key);
        }
        
        return $stored_token && hash_equals($stored_token, $token);
    }
    
    /**
     * Decode the base64/url-encoded fingerprint from frontend.
     * Useful for debugging or advanced validation.
     */
    private function decode_fingerprint($encoded) {
        if (empty($encoded)) return array();
        try {
            $decoded = base64_decode($encoded);
            if ($decoded === false) return array();
            $json = urldecode($decoded);
            return json_decode($json, true) ?: array();
        } catch (Exception $e) {
            return array();
        }
    }
    
    private function get_client_ip() {
        $ip_keys = array(
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        );
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return sanitize_text_field($ip);
                }
            }
        }
        
        return '0.0.0.0';
    }
}