<?php
/**
 * RTS SMTP Configuration
 *
 * Handles SMTP settings, password encryption, and WordPress mail integration.
 * Settings UI is grouped into .rts-card containers:
 *   - Sender Identity
 *   - Social Links
 *   - Branding
 *
 * @package    RTS_Subscriber_System
 * @subpackage SMTP
 * @version    3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_SMTP_Settings {

    const OPTION_GROUP = 'rts_smtp_settings_group';
    const PAGE_SLUG    = 'rts-smtp-settings';

    public function __construct() {
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('admin_init', array($this, 'maybe_redirect_legacy_smtp_page'), 1);
            add_action('wp_ajax_rts_test_smtp', array($this, 'ajax_test_smtp'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
        }

        add_action('phpmailer_init', array($this, 'configure_smtp'));
    }

    /**
     * Legacy page slug redirect (SMTP now lives inside the Subscribers Dashboard).
     */
    public function maybe_redirect_legacy_smtp_page() {
        if (!is_admin()) return;
        $page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : '';
        if ($page !== self::PAGE_SLUG) return;
        if (!current_user_can('manage_options')) return;
        wp_safe_redirect(admin_url('edit.php?post_type=rts_subscriber&page=rts-email-settings'));
        exit;
    }

    /**
     * Enqueue JS for the test button.
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, 'rts-email-settings') === false && strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }

        wp_register_script('rts-smtp-admin', false, array('jquery'), null, true);
        wp_enqueue_script('rts-smtp-admin');

        wp_localize_script('rts-smtp-admin', 'rtsSMTP', array(
            'nonce'    => wp_create_nonce('rts_test_smtp'),
            'ajax_url' => admin_url('admin-ajax.php')
        ));

        wp_add_inline_script('rts-smtp-admin', "
            jQuery(document).ready(function($) {
                $('#rts_test_smtp_btn').on('click', function(e) {
                    e.preventDefault();
                    var email = $('#rts_test_email').val();
                    var btn = $(this);
                    var msg = $('#rts_test_message');

                    if(!email) {
                        alert('Please enter an email address.');
                        return;
                    }

                    btn.prop('disabled', true).text('Sending...');
                    msg.html('').removeClass('notice-success notice-error');

                    $.post(rtsSMTP.ajax_url, {
                        action: 'rts_test_smtp',
                        test_email: email,
                        nonce: rtsSMTP.nonce
                    }, function(response) {
                        btn.prop('disabled', false).text('Send Test Email');
                        if(response.success) {
                            msg.html('<p style=\"padding:10px; background:#1e293b; border-left:4px solid #22c55e; color:#f8fafc;\">'+response.data.message+'</p>');
                        } else {
                            msg.html('<p style=\"padding:10px; background:#1e293b; border-left:4px solid #ef4444; color:#f8fafc;\">'+response.data.message+'</p>');
                        }
                    });
                });
            });
        ");
    }

    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('edit.php?post_type=rts_subscriber&page=rts-email-settings') . '">SMTP Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Menu removed: SMTP now lives on the Subscribers Dashboard for client clarity.
     */
    public function add_admin_menu() {
        // Keep settings + hooks active.
    }

    /**
     * Register settings, sections, and fields.
     */
    public function register_settings() {
        // Connection Settings
        register_setting(self::OPTION_GROUP, 'rts_smtp_enabled', array(
            'type' => 'boolean',
            'default' => false,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ));
        register_setting(self::OPTION_GROUP, 'rts_smtp_host', array(
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting(self::OPTION_GROUP, 'rts_smtp_port', array(
            'type' => 'integer',
            'default' => 587,
            'sanitize_callback' => 'absint'
        ));
        register_setting(self::OPTION_GROUP, 'rts_smtp_encryption', array(
            'type' => 'string',
            'default' => 'tls',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting(self::OPTION_GROUP, 'rts_smtp_auth', array(
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ));
        register_setting(self::OPTION_GROUP, 'rts_smtp_user', array(
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting(self::OPTION_GROUP, 'rts_smtp_pass', array(
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => array($this, 'sanitize_password')
        ));

        // Sender Settings
        register_setting(self::OPTION_GROUP, 'rts_smtp_from_email', array(
            'type' => 'string',
            'default' => get_option('admin_email'),
            'sanitize_callback' => 'sanitize_email'
        ));
        register_setting(self::OPTION_GROUP, 'rts_smtp_from_name', array(
            'type' => 'string',
            'default' => get_bloginfo('name'),
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting(self::OPTION_GROUP, 'rts_smtp_reply_to', array(
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_email'
        ));
        register_setting(self::OPTION_GROUP, 'rts_smtp_cc_email', array(
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'sanitize_email'
        ));
        register_setting(self::OPTION_GROUP, 'rts_smtp_debug', array(
            'type' => 'boolean',
            'default' => false,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ));

        // Social Links
        register_setting(self::OPTION_GROUP, 'rts_social_facebook', array(
            'type' => 'string',
            'default' => 'https://www.facebook.com/ben.west.56884',
            'sanitize_callback' => 'esc_url_raw'
        ));
        register_setting(self::OPTION_GROUP, 'rts_social_instagram', array(
            'type' => 'string',
            'default' => 'https://www.instagram.com/iambenwest/',
            'sanitize_callback' => 'esc_url_raw'
        ));
        register_setting(self::OPTION_GROUP, 'rts_social_linkedin', array(
            'type' => 'string',
            'default' => 'https://www.linkedin.com/in/benwest2/',
            'sanitize_callback' => 'esc_url_raw'
        ));
        register_setting(self::OPTION_GROUP, 'rts_social_linktree', array(
            'type' => 'string',
            'default' => 'https://linktr.ee/iambenwest',
            'sanitize_callback' => 'esc_url_raw'
        ));

        // Email Sending Controls
        register_setting(self::OPTION_GROUP, 'rts_email_sending_enabled', array(
            'type' => 'boolean',
            'default' => false,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ));
        register_setting(self::OPTION_GROUP, 'rts_email_demo_mode', array(
            'type' => 'boolean',
            'default' => false,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ));
        register_setting(self::OPTION_GROUP, 'rts_email_reconsent_required', array(
            'type' => 'boolean',
            'default' => false,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ));
        register_setting(self::OPTION_GROUP, 'rts_email_daily_time', array(
            'type' => 'string',
            'default' => '09:00',
            'sanitize_callback' => 'sanitize_text_field'
        ));
        register_setting(self::OPTION_GROUP, 'rts_email_batch_size', array(
            'type' => 'integer',
            'default' => 100,
            'sanitize_callback' => 'absint'
        ));

        // Branding
        register_setting(self::OPTION_GROUP, 'rts_email_logo_url', array(
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'esc_url_raw'
        ));
        register_setting(self::OPTION_GROUP, 'rts_privacy_url', array(
            'type' => 'string',
            'default' => '',
            'sanitize_callback' => 'esc_url_raw'
        ));

        // Letter Settings
        register_setting(self::OPTION_GROUP, 'rts_onboarder_enabled', array(
            'type' => 'boolean',
            'default' => true,
            'sanitize_callback' => 'rest_sanitize_boolean'
        ));

        // Sections
        add_settings_section('rts_smtp_conn', 'Connection Configuration', '__return_empty_string', self::PAGE_SLUG);
        add_settings_section('rts_smtp_sender', 'Sender Details', '__return_empty_string', self::PAGE_SLUG);

        // Fields - Connection
        add_settings_field('rts_smtp_enabled', 'Enable SMTP', array($this, 'render_checkbox'), self::PAGE_SLUG, 'rts_smtp_conn', array('label_for' => 'rts_smtp_enabled'));
        add_settings_field('rts_smtp_host', 'SMTP Host', array($this, 'render_input'), self::PAGE_SLUG, 'rts_smtp_conn', array('label_for' => 'rts_smtp_host'));
        add_settings_field('rts_smtp_port', 'SMTP Port', array($this, 'render_input'), self::PAGE_SLUG, 'rts_smtp_conn', array('label_for' => 'rts_smtp_port', 'type' => 'number'));
        add_settings_field('rts_smtp_encryption', 'Encryption', array($this, 'render_encryption_select'), self::PAGE_SLUG, 'rts_smtp_conn', array('label_for' => 'rts_smtp_encryption'));
        add_settings_field('rts_smtp_auth', 'Authentication', array($this, 'render_checkbox'), self::PAGE_SLUG, 'rts_smtp_conn', array('label_for' => 'rts_smtp_auth'));
        add_settings_field('rts_smtp_user', 'Username', array($this, 'render_input'), self::PAGE_SLUG, 'rts_smtp_conn', array('label_for' => 'rts_smtp_user'));
        add_settings_field('rts_smtp_pass', 'Password', array($this, 'render_password'), self::PAGE_SLUG, 'rts_smtp_conn', array('label_for' => 'rts_smtp_pass'));

        // Fields - Sender
        add_settings_field('rts_smtp_from_email', 'From Email', array($this, 'render_input'), self::PAGE_SLUG, 'rts_smtp_sender', array('label_for' => 'rts_smtp_from_email'));
        add_settings_field('rts_smtp_from_name', 'From Name', array($this, 'render_input'), self::PAGE_SLUG, 'rts_smtp_sender', array('label_for' => 'rts_smtp_from_name'));
        add_settings_field('rts_smtp_reply_to', 'Reply-To Email', array($this, 'render_input'), self::PAGE_SLUG, 'rts_smtp_sender', array('label_for' => 'rts_smtp_reply_to'));
        add_settings_field('rts_smtp_debug', 'Enable Debug Logging', array($this, 'render_checkbox'), self::PAGE_SLUG, 'rts_smtp_sender', array('label_for' => 'rts_smtp_debug'));
    }

    /**
     * Render the settings page HTML using .rts-card containers.
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        wp_safe_redirect(admin_url('edit.php?post_type=rts_subscriber&page=rts-email-settings'));
        exit;
    }

    // --- Render Helpers ---

    public function render_input($args) {
        $option = get_option($args['label_for']);
        $type = isset($args['type']) ? $args['type'] : 'text';
        echo '<input type="' . esc_attr($type) . '" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="' . esc_attr($option) . '" class="rts-form-input">';
    }

    public function render_checkbox($args) {
        $option = get_option($args['label_for']);
        echo '<input type="checkbox" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="1" ' . checked(1, $option, false) . '>';
        if ($args['label_for'] === 'rts_smtp_debug') {
            echo ' <span class="rts-form-description">Logs SMTP conversation (Client + Server) to <code>error_log</code>. Requires <code>WP_DEBUG</code>.</span>';
        }
    }

    public function render_encryption_select($args) {
        $option = get_option($args['label_for']);
        $items = array('tls' => 'TLS (Recommended)', 'ssl' => 'SSL', '' => 'None');
        echo '<select name="' . esc_attr($args['label_for']) . '" id="' . esc_attr($args['label_for']) . '" class="rts-form-select">';
        foreach ($items as $val => $label) {
            echo '<option value="' . esc_attr($val) . '" ' . selected($val, $option, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function render_password($args) {
        $option = get_option($args['label_for']);
        $val = !empty($option) ? '********' : '';
        echo '<input type="password" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="' . esc_attr($val) . '" class="rts-form-input">';
        echo '<p class="rts-form-description">Leave blank to keep existing password.</p>';
    }

    // --- Logic & Sanitization ---

    public function sanitize_password($value) {
        if (empty($value)) {
            return get_option('rts_smtp_pass');
        }
        if (trim($value) === '********') {
            return get_option('rts_smtp_pass');
        }
        return $this->encrypt($value);
    }

    private function encrypt($string) {
        if (!extension_loaded('openssl')) return $string;
        $method = 'aes-256-cbc';
        $key = wp_salt('auth');
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
        $encrypted = openssl_encrypt($string, $method, $key, 0, $iv);
        return base64_encode($iv . $encrypted);
    }

    private function decrypt($string) {
        if (!extension_loaded('openssl')) return $string;
        $method = 'aes-256-cbc';
        $key = wp_salt('auth');
        $data = base64_decode($string);
        $iv_len = openssl_cipher_iv_length($method);
        if (strlen($data) < $iv_len) return '';
        $iv = substr($data, 0, $iv_len);
        $ciphertext = substr($data, $iv_len);
        return openssl_decrypt($ciphertext, $method, $key, 0, $iv);
    }

    /**
     * Apply SMTP settings to PHPMailer.
     */
    public function configure_smtp($phpmailer) {
        if (!get_option('rts_smtp_enabled')) {
            return;
        }

        $phpmailer->isSMTP();
        $phpmailer->Host       = get_option('rts_smtp_host');
        $phpmailer->Port       = get_option('rts_smtp_port', 587);
        $phpmailer->SMTPSecure = get_option('rts_smtp_encryption', 'tls');

        if (get_option('rts_smtp_auth')) {
            $phpmailer->SMTPAuth = true;
            $phpmailer->Username = get_option('rts_smtp_user');
            $phpmailer->Password = $this->decrypt(get_option('rts_smtp_pass'));
        }

        $from_email = get_option('rts_smtp_from_email');
        $from_name  = get_option('rts_smtp_from_name');

        if ($from_email && is_email($from_email)) {
            $phpmailer->From = $from_email;
            if ($from_name) {
                $phpmailer->FromName = $from_name;
            }
        }

        $reply_to = get_option('rts_smtp_reply_to');
        if ($reply_to && is_email($reply_to)) {
            $phpmailer->addReplyTo($reply_to);
        }

        $cc = get_option('rts_smtp_cc_email');
        if ($cc && is_email($cc)) {
            $phpmailer->addCC($cc);
        }

        if (get_option('rts_smtp_debug') && defined('WP_DEBUG') && WP_DEBUG) {
            $phpmailer->SMTPDebug = 2;
            $phpmailer->Debugoutput = function($str, $level) {
                error_log("RTS SMTP Debug [$level]: $str");
            };
        }
    }

    /**
     * AJAX Handler for Test Email.
     */
    public function ajax_test_smtp() {
        if (!check_ajax_referer('rts_test_smtp', 'nonce', false) || !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Security check failed.'));
        }

        $to = isset($_POST['test_email']) ? sanitize_email(wp_unslash($_POST['test_email'])) : '';
        if (empty($to)) {
            $to = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        }

        if (empty($to) || !is_email($to)) {
            wp_send_json_error(array('message' => 'Please provide a valid email address.'));
        }

        add_action('phpmailer_init', function($phpmailer) {
            $phpmailer->isHTML(true);
        }, 999);

        $subject = 'RTS SMTP Test: ' . get_bloginfo('name');
        $message = "<strong>SMTP Configuration Test</strong><br><br>";
        $message .= "This is a test email from the RTS Subscriber System.<br>";
        $message .= "If you are reading this, your SMTP settings are configured correctly.<br><br>";
        $message .= "Time: " . current_time('mysql');

        $headers = array('Content-Type: text/html; charset=UTF-8');

        $result = false;
        try {
            $result = wp_mail($to, $subject, $message, $headers);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        }

        if ($result) {
            wp_send_json_success(array('message' => 'Test email sent successfully! Check your inbox.'));
        }

        global $phpmailer;
        $error_info = isset($phpmailer->ErrorInfo) ? $phpmailer->ErrorInfo : 'Unknown error';
        wp_send_json_error(array('message' => 'Sending failed. Error Info: ' . esc_html($error_info)));
    }

    /**
     * Lightweight health check (no email sent).
     */
    public function test_smtp_connection() {
        $host = get_option('rts_smtp_host', 'mail.smtp2go.com');
        $port = (int) get_option('rts_smtp_port', 2525);
        $timeout = 3;
        $errno = 0;
        $errstr = '';

        $fp = @fsockopen($host, $port, $errno, $errstr, $timeout);
        if ($fp) {
            fclose($fp);
            return array('ok' => true, 'message' => 'Socket connection OK');
        }

        $msg = $errstr ? $errstr : 'Unable to connect';
        return array('ok' => false, 'message' => $msg . ' (errno ' . intval($errno) . ')');
    }
}
