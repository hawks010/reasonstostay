<?php
/**
 * RTS Unsubscribe + Preference Center + Verification Handler
 *
 * Public endpoints:
 * - ?rts_verify=<token>&sig=<sig>
 * - ?rts_unsubscribe=<token>&sig=<sig>
 * - ?rts_manage=<subscriber_token>&sig=<sig>
 *
 * Uses signatures to reduce token scraping risk and adds rate limiting.
 *
 * @package    RTS_Subscriber_System
 * @subpackage Unsubscribe
 * @version    1.0.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_Unsubscribe {

    public function __construct() {
        add_action('init', array($this, 'handle_requests'));
    }

    /**
     * Dispatch requests based on query vars.
     */
    public function handle_requests() {
        if (!empty($_GET['rts_verify'])) {
            $this->handle_verification();
        }
        if (!empty($_GET['rts_unsubscribe'])) {
            $this->handle_unsubscribe();
        }
        if (!empty($_GET['rts_manage'])) {
            $this->handle_manage();
        }
    }

    /**
     * Rate limit requests based on IP and Action.
     * @param string $action The action being performed.
     * @param int    $limit  Max attempts allowed.
     * @param int    $window Time window in seconds.
     * @return bool True if allowed, False if limit exceeded.
     */
    private function rate_limit($action, $limit = 20, $window = 300) {
        $ip = $this->get_client_ip();
        $key = 'rts_limit_' . $action . '_' . md5($ip);

        $transient = get_transient($key);
        $count = $transient ? (int) $transient : 0;

        if ($count >= $limit) {
            return false;
        }

        set_transient($key, $count + 1, $window);
        return true;
    }

    /**
     * Generate HMAC signature for a token and purpose.
     * Uses current date to enforce expiry windows.
     *
     * @param string $token
     * @param string $purpose
     * @param string|null $date_override Optional date string Y-m-d
     * @return string
     */
    private function generate_sig($token, $purpose, $date_override = null) {
        $date = $date_override ? $date_override : date('Y-m-d');
        return hash_hmac('sha256', $token . '|' . $purpose . '|' . $date, wp_salt('auth'));
    }

    /**
     * Verify HMAC signature with expiration window.
     *
     * @param string $token       The subscriber token.
     * @param string $purpose     The action (verify, unsubscribe, manage).
     * @param string $sig         The provided signature.
     * @param int    $expiry_days Days until link expires.
     * @return bool Valid or not.
     */
    private function verify_sig($token, $purpose, $sig, $expiry_days = 30) {
        // Allow current day
        $expected = $this->generate_sig($token, $purpose);
        if (hash_equals($expected, (string) $sig)) {
            return true;
        }

        // Check previous days based on expiry window
        for ($i = 1; $i <= $expiry_days; $i++) {
            $date = date('Y-m-d', time() - ($i * 86400));
            $expected_date = $this->generate_sig($token, $purpose, $date);
            if (hash_equals($expected_date, (string) $sig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send standard security and no-cache headers.
     */
    private function send_security_headers() {
        if (!headers_sent()) {
            nocache_headers();
            header('X-Frame-Options: SAMEORIGIN');
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('X-XSS-Protection: 1; mode=block');
        }
    }

    /**
     * Handle Email Verification logic.
     */
    private function handle_verification() {
        $token = sanitize_text_field(wp_unslash($_GET['rts_verify']));
        $sig   = isset($_GET['sig']) ? sanitize_text_field(wp_unslash($_GET['sig'])) : '';

        // Verify Signature (14 day expiry for verification links)
        if (!$this->verify_sig($token, 'verify', $sig, 14)) {
            $this->render_error_page('Invalid Link', 'This verification link is invalid or has expired.');
        }

        if (!$this->rate_limit('verify', 10, 300)) {
            $this->render_error_page('Rate Limit', 'Too many requests. Please try again later.');
        }

        // Find subscriber
        $subscriber_id = $this->get_subscriber_by_verification_token($token);
        if (!$subscriber_id) {
            $this->render_error_page('Invalid Token', 'Verification token not found or already used.');
        }

        // Perform Update
        update_post_meta($subscriber_id, '_rts_subscriber_verified', 1);
        update_post_meta($subscriber_id, '_rts_subscriber_status', 'active');
        delete_post_meta($subscriber_id, '_rts_subscriber_verification_token');

        // Log
        $this->log_action($subscriber_id, 'verified', array('method' => 'email_link'));

        // Queue welcome email
        if (class_exists('RTS_Email_Engine')) {
            $engine = new RTS_Email_Engine();
            $engine->send_welcome_email($subscriber_id);
        }

        // Render Success Page (Inline)
        $this->render_success_page(
            __('Email Verified!', 'rts-subscriber-system'),
            __('Thank you for verifying your email address. Your subscription is now active.', 'rts-subscriber-system')
        );
    }

    /**
     * Handle Unsubscribe logic.
     */
    private function handle_unsubscribe() {
        $token = sanitize_text_field(wp_unslash($_GET['rts_unsubscribe']));
        $sig   = isset($_GET['sig']) ? sanitize_text_field(wp_unslash($_GET['sig'])) : '';

        // Verify Signature (60 day expiry for ease of use)
        if (!$this->verify_sig($token, 'unsubscribe', $sig, 60)) {
            $this->render_error_page('Invalid Link', 'This unsubscribe link is invalid or has expired.');
        }

        if (!$this->rate_limit('unsubscribe', 10, 300)) {
            $this->render_error_page('Rate Limit', 'Too many requests. Please try again later.');
        }

        $subscriber_id = $this->get_subscriber_by_token($token);
        if (!$subscriber_id) {
            $this->render_error_page('Not Found', 'Subscriber not found.');
        }

        // Perform Update
        update_post_meta($subscriber_id, '_rts_subscriber_status', 'unsubscribed');
        update_post_meta($subscriber_id, '_rts_subscriber_unsubscribed_at', current_time('mysql'));

        // Log
        $this->log_action($subscriber_id, 'unsubscribed', array('method' => 'link'));

        // Render Success Page (Inline)
        $this->render_success_page(
            __('Unsubscribed', 'rts-subscriber-system'),
            __('You have been successfully unsubscribed from our list. We are sorry to see you go.', 'rts-subscriber-system'),
            true // Show re-subscribe/manage hint
        );
    }

    /**
     * Handle Preference Center logic.
     */
    private function handle_manage() {
        $token = sanitize_text_field(wp_unslash($_GET['rts_manage']));
        $sig   = isset($_GET['sig']) ? sanitize_text_field(wp_unslash($_GET['sig'])) : '';

        // Verify Signature (30 day expiry)
        if (!$this->verify_sig($token, 'manage', $sig, 30)) {
            $this->render_error_page('Invalid Link', 'This link has expired. Please request a new one via the subscription form.');
        }

        if (!$this->rate_limit('manage', 30, 300)) {
            $this->render_error_page('Rate Limit', 'Too many requests. Please try again later.');
        }

        $subscriber_id = $this->get_subscriber_by_token($token);
        if (!$subscriber_id) {
            $this->render_error_page('Not Found', 'Subscriber record not found.');
        }

        // Fetch current values
        $current_freq = get_post_meta($subscriber_id, '_rts_subscriber_frequency', true);
        $pref_letters = (int) get_post_meta($subscriber_id, '_rts_pref_letters', true);
        $pref_news    = (int) get_post_meta($subscriber_id, '_rts_pref_newsletters', true);
        $allowed_freq = array('daily', 'weekly', 'monthly');

        $updated = false;

        // Handle POST Update
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
            if (!wp_verify_nonce($nonce, 'rts_manage_' . $subscriber_id)) {
                wp_die('Security check failed.');
            }

            // Honeypot check
            if (!empty($_POST['rts_website'])) {
                $this->log_action($subscriber_id, 'bot_detected', array('field' => 'website'));
                wp_die('Invalid request.');
            }

            // Update Frequency
            $new_freq = isset($_POST['frequency']) ? sanitize_text_field(wp_unslash($_POST['frequency'])) : '';
            if (in_array($new_freq, $allowed_freq, true)) {
                update_post_meta($subscriber_id, '_rts_subscriber_frequency', $new_freq);
                $current_freq = $new_freq;
            }

            // Update Preferences
            $pref_letters = isset($_POST['pref_letters']) ? 1 : 0;
            $pref_news    = isset($_POST['pref_newsletters']) ? 1 : 0;
            
            update_post_meta($subscriber_id, '_rts_pref_letters', $pref_letters);
            update_post_meta($subscriber_id, '_rts_pref_newsletters', $pref_news);
            update_post_meta($subscriber_id, '_rts_pref_updated_at', current_time('mysql'));

            // Record Consent (Re-confirmation)
            $this->update_consent_log($subscriber_id, 'confirmed_preferences', array('via' => 'preference_center'));
            $this->log_action($subscriber_id, 'updated_preferences');

            // If user was unsubscribed/bounced, set back to active
            update_post_meta($subscriber_id, '_rts_subscriber_status', 'active');

            // Optional: Send confirmation email if engine supports it
            if (class_exists('RTS_Email_Engine')) {
                $engine = new RTS_Email_Engine();
                if (method_exists($engine, 'send_preferences_updated_email')) {
                    $engine->send_preferences_updated_email($subscriber_id);
                }
            }

            $updated = true;
        }

        // --- Render Inline Preference Center ---
        $this->send_security_headers();
        $title = esc_html(get_bloginfo('name'));
        
        // Generate valid signature using helper
        $unsubscribe_url = add_query_arg(array(
            'rts_unsubscribe' => $token, 
            'sig' => $this->generate_sig($token, 'unsubscribe')
        ), home_url('/'));

        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php esc_html_e('Manage Subscription', 'rts-subscriber-system'); ?> - <?php echo $title; ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; line-height: 1.5; color: #333; background: #f0f0f1; padding: 20px; margin: 0; }
                .container { max-width: 500px; margin: 40px auto; background: #fff; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
                h1 { margin-top: 0; font-size: 24px; color: #1d2327; }
                .success-msg { background: #d4edda; color: #155724; padding: 15px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #c3e6cb; }
                label { display: block; margin-bottom: 8px; font-weight: 600; }
                select { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; margin-bottom: 20px; font-size: 16px; }
                .checkbox-group { margin-bottom: 20px; background: #f9f9f9; padding: 15px; border-radius: 4px; }
                .checkbox-group label { display: flex; align-items: center; font-weight: normal; margin-bottom: 10px; cursor: pointer; }
                .checkbox-group input { margin-right: 10px; transform: scale(1.2); }
                button { background: #2271b1; color: white; border: none; padding: 12px 24px; border-radius: 4px; font-size: 16px; cursor: pointer; width: 100%; transition: background 0.2s; }
                button:hover { background: #135e96; }
                .footer { margin-top: 30px; border-top: 1px solid #eee; padding-top: 20px; font-size: 13px; color: #666; text-align: center; }
                .footer a { color: #d63638; text-decoration: none; }
                .footer a:hover { text-decoration: underline; }
                .honeypot { display:none; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1><?php esc_html_e('Manage Subscription', 'rts-subscriber-system'); ?></h1>
                
                <?php if ($updated): ?>
                    <div class="success-msg">
                        <?php esc_html_e('Preferences updated successfully.', 'rts-subscriber-system'); ?>
                    </div>
                <?php endif; ?>

                <form method="post">
                    <?php wp_nonce_field('rts_manage_' . $subscriber_id); ?>
                    
                    <div class="checkbox-group">
                        <p style="margin-top:0; margin-bottom:10px; font-weight:600;"><?php esc_html_e('I want to receive:', 'rts-subscriber-system'); ?></p>
                        <label>
                            <input type="checkbox" name="pref_letters" value="1" <?php checked(1, $pref_letters); ?>>
                            <?php esc_html_e('Letters (Articles & Updates)', 'rts-subscriber-system'); ?>
                        </label>
                        <label>
                            <input type="checkbox" name="pref_newsletters" value="1" <?php checked(1, $pref_news); ?>>
                            <?php esc_html_e('Newsletters (Curated Content)', 'rts-subscriber-system'); ?>
                        </label>
                    </div>

                    <label for="frequency"><?php esc_html_e('How often should we email you?', 'rts-subscriber-system'); ?></label>
                    <select name="frequency" id="frequency">
                        <?php foreach ($allowed_freq as $freq): ?>
                            <option value="<?php echo esc_attr($freq); ?>" <?php selected($current_freq, $freq); ?>>
                                <?php echo esc_html(ucfirst($freq)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="honeypot"><input type="text" name="rts_website" tabindex="-1" autocomplete="off"></div>

                    <button type="submit"><?php esc_html_e('Save Preferences', 'rts-subscriber-system'); ?></button>
                </form>

                <div class="footer">
                    <p><?php esc_html_e('No longer interested?', 'rts-subscriber-system'); ?></p>
                    <a href="<?php echo esc_url($unsubscribe_url); ?>"><?php esc_html_e('Unsubscribe from all emails', 'rts-subscriber-system'); ?></a>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    // --- Helpers & Views ---

    private function render_success_page($title, $message, $show_home_link = true) {
        $this->send_security_headers();
        $blog_name = get_bloginfo('name');
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($title . ' - ' . $blog_name); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f0f1; padding: 40px; text-align: center; }
                .card { background: white; max-width: 500px; margin: 0 auto; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
                h1 { color: #1d2327; margin-top: 0; }
                p { color: #3c434a; font-size: 16px; line-height: 1.5; }
                a { color: #2271b1; text-decoration: none; }
                a:hover { text-decoration: underline; }
                .icon { font-size: 48px; margin-bottom: 20px; display: block; }
            </style>
        </head>
        <body>
            <div class="card">
                <span class="icon">✅</span>
                <h1><?php echo esc_html($title); ?></h1>
                <p><?php echo esc_html($message); ?></p>
                <?php if ($show_home_link): ?>
                    <p style="margin-top:30px;"><a href="<?php echo esc_url(home_url('/')); ?>">&larr; <?php esc_html_e('Return to Home', 'rts-subscriber-system'); ?></a></p>
                <?php endif; ?>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    private function render_error_page($title, $message, $code = 403) {
        status_header($code);
        $this->send_security_headers();
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php echo esc_html($title); ?></title>
            <style>
                body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f0f0f1; padding: 40px; text-align: center; }
                .card { background: white; max-width: 500px; margin: 0 auto; padding: 40px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border-top: 4px solid #d63638; }
                h1 { color: #d63638; margin-top: 0; }
                p { color: #3c434a; font-size: 16px; }
            </style>
        </head>
        <body>
            <div class="card">
                <h1><?php echo esc_html($title); ?></h1>
                <p><?php echo esc_html($message); ?></p>
            </div>
        </body>
        </html>
        <?php
        exit;
    }

    private function get_subscriber_by_token($token) {
        $q = new WP_Query(array(
            'post_type'      => 'rts_subscriber',
            'post_status'    => array('publish', 'private'), // Exclude trash
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'   => '_rts_subscriber_token',
                    'value' => $token,
                )
            )
        ));
        return !empty($q->posts) ? intval($q->posts[0]) : 0;
    }

    private function get_subscriber_by_verification_token($token) {
        $q = new WP_Query(array(
            'post_type'      => 'rts_subscriber',
            'post_status'    => array('publish', 'private'), // Exclude trash
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'meta_query'     => array(
                array(
                    'key'   => '_rts_subscriber_verification_token',
                    'value' => $token,
                )
            )
        ));
        return !empty($q->posts) ? intval($q->posts[0]) : 0;
    }

    private function update_consent_log($subscriber_id, $action, $data = array()) {
        $log = get_post_meta($subscriber_id, '_rts_subscriber_consent_log', true) ?: array();
        $log[$action] = array_merge(array('timestamp' => current_time('mysql'), 'ip' => $this->get_client_ip()), $data);
        update_post_meta($subscriber_id, '_rts_subscriber_consent_log', $log);
    }

    private function log_action($subscriber_id, $action, $data = array()) {
        $log = get_post_meta($subscriber_id, '_rts_audit_log', true) ?: array();
        $log[] = array(
            'timestamp'  => current_time('mysql'),
            'action'     => $action,
            'ip'         => $this->get_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr(sanitize_text_field($_SERVER['HTTP_USER_AGENT']), 0, 255) : '',
            'data'       => $data
        );
        // Keep last 50 actions to prevent meta bloat
        if (count($log) > 50) {
            $log = array_slice($log, -50);
        }
        update_post_meta($subscriber_id, '_rts_audit_log', $log);
    }

    private function get_client_ip() {
        $keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($keys as $key) {
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