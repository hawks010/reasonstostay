<?php
/**
 * RTS SMTP Configuration
 *
 * Handles SMTP settings, password encryption, and WordPress mail integration.
 *
 * @package    RTS_Subscriber_System
 * @subpackage SMTP
 * @version    1.0.2
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_SMTP_Settings {

    const OPTION_GROUP = 'rts_smtp_settings_group';
    const PAGE_SLUG    = 'rts-smtp-settings';

    public function __construct() {
        // Admin Hooks
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_init', array($this, 'register_settings'));
            add_action('wp_ajax_rts_test_smtp', array($this, 'ajax_test_smtp'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
            
            // Add settings link to plugins page
            add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_settings_link'));
        }

        // Core Mail Hook
        add_action('phpmailer_init', array($this, 'configure_smtp'));
    }

    /**
     * Enqueue JS for the test button using localized data.
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }
        
        // Register a dummy handle to attach data and scripts to
        wp_register_script('rts-smtp-admin', false, array('jquery'), null, true);
        wp_enqueue_script('rts-smtp-admin');

        // Pass PHP data to JS
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
                            msg.html('<p style=\"padding:10px; background:#fff; border-left:4px solid #46b450;\">'+response.data.message+'</p>');
                        } else {
                            msg.html('<p style=\"padding:10px; background:#fff; border-left:4px solid #dc3232;\">'+response.data.message+'</p>');
                        }
                    });
                });
            });
        ");
    }

    /**
     * Add settings link to plugins page.
     */
    public function add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('edit.php?post_type=rts_subscriber&page=rts-smtp-settings') . '">SMTP Settings</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Register Admin Menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=rts_subscriber',
            'SMTP Settings',
            'SMTP Settings',
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render_settings_page')
        );
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
        
        // Debug
        register_setting(self::OPTION_GROUP, 'rts_smtp_debug', array(
            'type' => 'boolean',
            'default' => false,
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
        
        // Fields - Debug
        add_settings_field('rts_smtp_debug', 'Enable Debug Logging', array($this, 'render_checkbox'), self::PAGE_SLUG, 'rts_smtp_sender', array('label_for' => 'rts_smtp_debug'));
    }

    /**
     * Render the settings page HTML.
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <?php if (!extension_loaded('openssl')) : ?>
                <div class="notice notice-warning inline">
                    <p><strong>Warning:</strong> The OpenSSL PHP extension is missing. SMTP passwords will be stored in plain text. Please enable OpenSSL on your server.</p>
                </div>
            <?php endif; ?>

            <form action="options.php" method="post">
                <?php
                settings_fields(self::OPTION_GROUP);
                do_settings_sections(self::PAGE_SLUG);
                submit_button();
                ?>
            </form>
            
            <hr style="margin-top: 40px;">
            <h2>Test Configuration</h2>
            <p>Save your settings above, then enter an email address to verify connectivity.</p>
            <table class="form-table">
                <tr>
                    <th scope="row">To Email</th>
                    <td>
                        <input type="email" id="rts_test_email" class="regular-text" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>">
                        <button type="button" id="rts_test_smtp_btn" class="button button-secondary">Send Test Email</button>
                    </td>
                </tr>
            </table>
            <div id="rts_test_message" style="margin-top: 15px; max-width: 600px;"></div>
        </div>
        <?php
    }

    // --- Render Helpers ---

    public function render_input($args) {
        $option = get_option($args['label_for']);
        $type = isset($args['type']) ? $args['type'] : 'text';
        echo '<input type="' . esc_attr($type) . '" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="' . esc_attr($option) . '" class="regular-text">';
    }

    public function render_checkbox($args) {
        $option = get_option($args['label_for']);
        echo '<input type="checkbox" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="1" ' . checked(1, $option, false) . '>';
        if ($args['label_for'] === 'rts_smtp_debug') {
            echo ' <span class="description">Logs SMTP conversation (Client + Server) to <code>error_log</code>. Requires <code>WP_DEBUG</code> to be enabled.</span>';
        }
    }

    public function render_encryption_select($args) {
        $option = get_option($args['label_for']);
        $items = array('tls' => 'TLS (Recommended)', 'ssl' => 'SSL', '' => 'None');
        echo '<select name="' . esc_attr($args['label_for']) . '" id="' . esc_attr($args['label_for']) . '">';
        foreach ($items as $val => $label) {
            echo '<option value="' . esc_attr($val) . '" ' . selected($val, $option, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public function render_password($args) {
        $option = get_option($args['label_for']);
        $val = !empty($option) ? '********' : '';
        echo '<input type="password" id="' . esc_attr($args['label_for']) . '" name="' . esc_attr($args['label_for']) . '" value="' . esc_attr($val) . '" class="regular-text">';
        echo '<p class="description">Leave blank to keep existing password.</p>';
    }

    // --- Logic & Sanitization ---

    public function sanitize_password($value) {
        // If empty, return existing value (preserve old password on save if field left blank)
        if (empty($value)) {
            return get_option('rts_smtp_pass');
        }
        // If placeholder, return existing (using trim to handle accidental spaces)
        if (trim($value) === '********') {
            return get_option('rts_smtp_pass');
        }
        // Encrypt new password
        return $this->encrypt($value);
    }

    /**
     * Encrypt string using AES-256-CBC and WP Salt.
     */
    private function encrypt($string) {
        if (!extension_loaded('openssl')) return $string;
        
        $method = 'aes-256-cbc';
        $key = wp_salt('auth');
        // Generate secure IV
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($method));
        $encrypted = openssl_encrypt($string, $method, $key, 0, $iv);
        
        // Store IV with ciphertext
        return base64_encode($iv . $encrypted);
    }

    /**
     * Decrypt string.
     */
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

        // Apply From Headers
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

        // Debug Logging - Check WP_DEBUG to prevent log spam in production
        if (get_option('rts_smtp_debug') && defined('WP_DEBUG') && WP_DEBUG) {
            $phpmailer->SMTPDebug = 2; // 2 = Client and Server messages
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
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $to = sanitize_email($_POST['test_email']);
        if (!$to) {
            wp_send_json_error(array('message' => 'Invalid email address.'));
        }

        // Ensure HTML content type is respected by PHPMailer for the test
        add_action('phpmailer_init', function($phpmailer) {
            $phpmailer->isHTML(true);
        }, 999); 

        $subject = 'RTS SMTP Test: ' . get_bloginfo('name');
        $message = "<strong>SMTP Configuration Test</strong><br><br>";
        $message .= "This is a test email from the RTS Subscriber System.<br>";
        $message .= "If you are reading this, your SMTP settings are configured correctly.<br><br>";
        $message .= "Time: " . current_time('mysql');
        
        $headers = array('Content-Type: text/html; charset=UTF-8');

        // Capture errors explicitly for the response
        $result = false;
        try {
            $result = wp_mail($to, $subject, $message, $headers);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Exception: ' . $e->getMessage()));
        }

        if ($result) {
            wp_send_json_success(array('message' => 'Test email sent successfully! Check your inbox.'));
        } else {
            global $phpmailer;
            $error_info = isset($phpmailer->ErrorInfo) ? $phpmailer->ErrorInfo : 'Unknown error';
            wp_send_json_error(array('message' => 'Sending failed. Error Info: ' . esc_html($error_info)));
        }
    }
}