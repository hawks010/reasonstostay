<?php
/**
 * RTS Admin Menu - Enhanced with Email Templates
 * Modern card-based design, 35px radius, 35px padding
 */

if (!defined('ABSPATH')) exit;

class RTS_Admin_Menu {
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menus'));
        add_action('admin_init', array($this, 'redirect_subscriber_post_new'));

        add_action('admin_post_rts_save_template', array($this, 'handle_save_template'));
        add_action('admin_post_rts_test_template', array($this, 'handle_test_template'));
        add_action('admin_post_rts_add_subscriber', array($this, 'handle_add_subscriber'));
    }
    
    public function add_menus() {
        // Replace the default "Add New" subscriber screen with a simple, purpose-built form.
        remove_submenu_page('edit.php?post_type=rts_subscriber', 'post-new.php?post_type=rts_subscriber');

        add_submenu_page(
            'edit.php?post_type=rts_subscriber',
            'Add Subscriber',
            'Add Subscriber',
            'manage_options',
            'rts-add-subscriber',
            array($this, 'render_add_subscriber')
        );

        add_submenu_page(
            'edit.php?post_type=rts_subscriber',
            'Email Settings',
            'Settings',
            'manage_options',
            'rts-email-settings',
            array($this, 'render_settings')
        );
        
        add_submenu_page(
            'edit.php?post_type=rts_subscriber',
            'Email Templates',
            'Email Templates',
            'manage_options',
            'rts-email-templates',
            array($this, 'render_templates')
        );
        
        // NOTE: Newsletters is a CPT with show_in_menu => 'edit.php?post_type=rts_subscriber'
        // so WordPress will automatically add the submenu item. Adding it here duplicates the menu.

        add_submenu_page(
            'edit.php?post_type=rts_subscriber',
            'Analytics',
            'Analytics',
            'manage_options',
            'rts-email-analytics',
            array($this, 'render_analytics')
        );
        
        add_submenu_page(
            'edit.php?post_type=rts_subscriber',
            'Import Subscribers',
            'Import',
            'manage_options',
            'rts-import',
            array($this, 'render_import')
        );
    }
    
    /**
     * Render Settings Page with Modern Card Design
     */
    public function render_settings() {
        ?>
        <div class="wrap rts-settings-page">
            <div class="rts-page-header">
                <h1><span class="dashicons dashicons-admin-settings"></span>Email Settings</h1>
                <p class="rts-page-description">Configure SMTP settings and email preferences</p>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('rts_smtp_settings'); ?>
                
                <div class="rts-form-section">
                    <h3><span class="dashicons dashicons-email"></span> SMTP Configuration</h3>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">From Email</label>
                        <input type="email" name="rts_smtp_from_email" 
                               value="<?php echo esc_attr(get_option('rts_smtp_from_email')); ?>" 
                               class="rts-form-input" required>
                        <span class="rts-form-description">The email address emails will be sent from</span>
                    </div>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">From Name</label>
                        <input type="text" name="rts_smtp_from_name" 
                               value="<?php echo esc_attr(get_option('rts_smtp_from_name')); ?>" 
                               class="rts-form-input" required>
                        <span class="rts-form-description">The sender name subscribers will see</span>
                    </div>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">Reply-To Email</label>
                        <input type="email" name="rts_smtp_reply_to" 
                               value="<?php echo esc_attr(get_option('rts_smtp_reply_to')); ?>" 
                               class="rts-form-input">
                        <span class="rts-form-description">Where replies will be sent (optional)</span>
                    </div>
                </div>
                
                
                <div class="rts-form-section">
                    <h3><span class="dashicons dashicons-shield"></span> Email Sending (Safety)</h3>

                    <div class="rts-form-row">
                        <label class="rts-form-label">Enable Sending</label>
                        <label style="display:flex;align-items:center;gap:10px;">
                            <input type="checkbox" name="rts_email_sending_enabled" value="1" <?php checked(get_option('rts_email_sending_enabled'), 1); ?>>
                            <span style="color:#ffffff;">Allow the system to queue and send emails</span>
                        </label>
                        <span class="rts-form-description">Default is OFF until you are ready.</span>
                    </div>

                    <div class="rts-form-row">
                        <label class="rts-form-label">Demo Mode</label>
                        <label style="display:flex;align-items:center;gap:10px;">
                            <input type="checkbox" name="rts_email_demo_mode" value="1" <?php checked(get_option('rts_email_demo_mode'), 1); ?>>
                            <span style="color:#F4C946;font-weight:700;">Demo mode ON = nothing gets sent to real subscribers</span>
                        </label>
                        <span class="rts-form-description">Emails are cancelled with a log entry so you can safely test flows.</span>
                    </div>

                    <div class="rts-form-row">
                        <label class="rts-form-label">Require Re-consent</label>
                        <label style="display:flex;align-items:center;gap:10px;">
                            <input type="checkbox" name="rts_email_reconsent_required" value="1" <?php checked(get_option('rts_email_reconsent_required'), 1); ?>>
                            <span style="color:#ffffff;">Only email subscribers after they confirm preferences</span>
                        </label>
                        <span class="rts-form-description">Recommended when importing from another platform (GDPR).</span>
                    </div>
                </div>

                <div class="rts-form-section">
                    <h3><span class="dashicons dashicons-clock"></span> Sending Schedule</h3>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">Daily Digest Time</label>
                        <input type="time" name="rts_email_daily_time" 
                               value="<?php echo esc_attr(get_option('rts_email_daily_time', '09:00')); ?>" 
                               class="rts-form-input">
                        <span class="rts-form-description">What time to send daily digests (24-hour format)</span>
                    </div>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">Batch Size</label>
                        <input type="number" name="rts_email_batch_size" min="10" max="500"
                               value="<?php echo esc_attr(get_option('rts_email_batch_size', 100)); ?>" 
                               class="rts-form-input">
                        <span class="rts-form-description">Number of emails to send per batch (default: 100)</span>
                    </div>
                </div>
                
                <div class="rts-button-group">
                    <button type="submit" class="rts-button success">
                        <span class="dashicons dashicons-yes"></span> Save Settings
                    </button>
                </div>
            </form>
            

            <div class="rts-form-section" style="margin-top:20px;">
                <h3><span class="dashicons dashicons-megaphone"></span> Subscriber Re-consent</h3>
                <p style="color:#ffffff;margin-top:0;">Send a one-time email asking subscribers what they want to receive (letters and/or newsletters). This updates their saved preferences and creates an audit trail.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="rts_send_reconsent">
                    <?php wp_nonce_field('rts_send_reconsent'); ?>
                    <button type="submit" class="rts-button warning">
                        <span class="dashicons dashicons-email-alt"></span> Send Re-consent Email to All Subscribers
                    </button>
                </form>
            </div>

            <div class="rts-info-box">
                <h4><span class="dashicons dashicons-info"></span> SMTP Plugin Required</h4>
                <p>These settings work with any SMTP plugin like WP Mail SMTP, Easy WP SMTP, or Post SMTP. Install and configure an SMTP plugin to enable email sending.</p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render Email Templates Page - NEW!
     */
    public function render_templates() {
        // Check if editing a template
        if (isset($_GET['edit'])) {
            $this->render_template_editor($_GET['edit']);
            return;
        }
        
        $templates = $this->get_email_templates();
        ?>
        <div class="wrap rts-templates-page">
            <div class="rts-page-header">
                <h1><span class="dashicons dashicons-email-alt"></span>Email Templates</h1>
                <p class="rts-page-description">Customize email content and styling - click any template to edit</p>
            </div>
            
            <div class="rts-info-box">
                <h4><span class="dashicons dashicons-info"></span> How Email Templates Work</h4>
                <p>Emails are sent in this order when subscribers interact with your system:</p>
                <ul style="margin:10px 0 0 20px;">
                    <li><strong>Verification</strong> - Sent immediately after subscription (if verification enabled)</li>
                    <li><strong>Welcome</strong> - Sent after email verification or immediately if verification disabled</li>
                    <li><strong>Daily/Weekly/Monthly Digest</strong> - Sent automatically based on subscriber preference</li>
                    <li><strong>Frequency Changed</strong> - Sent when subscriber changes their email frequency</li>
                    <li><strong>Unsubscribe</strong> - Sent when subscriber unsubscribes</li>
                </ul>
            </div>
            
            <div class="rts-card">
                <h3><span class="dashicons dashicons-email-alt2"></span> Letter Integration</h3>
                <p>Digest emails automatically pull published letters with:</p>
                <ul style="margin:10px 0 0 20px;color:#cbd5e1;">
                    <li>Quality Score ≥ 70 (from moderation system)</li>
                    <li>Status: Published</li>
                    <li>Meta field: <code>_rts_email_ready = true</code></li>
                </ul>
                <p style="margin-top:15px;">Letters are selected randomly from this pool and formatted into the digest template.</p>
            </div>
            
            <div class="rts-template-list">
                <?php foreach ($templates as $key => $template): ?>
                    <div class="rts-template-item">
                        <div class="rts-template-info">
                            <div class="rts-template-name">
                                <span><?php echo esc_html($template['name']); ?></span>
                                <span class="rts-template-badge order-<?php echo $template['order']; ?>">
                                    <?php echo $template['when']; ?>
                                </span>
                            </div>
                            <p class="rts-template-description"><?php echo esc_html($template['description']); ?></p>
                            <div class="rts-template-meta">
                                <span><span class="dashicons dashicons-editor-code"></span> <?php echo $template['variables']; ?> variables</span>
                                <span><span class="dashicons dashicons-admin-users"></span> <?php echo $template['audience']; ?></span>
                            </div>
                        </div>
                        <div class="rts-template-actions">
                            <a href="<?php echo admin_url('admin.php?page=rts-email-templates&edit=' . $key); ?>" 
                               class="rts-button">
                                <span class="dashicons dashicons-edit"></span> Edit Template
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render Template Editor
     */
    private function render_template_editor($template_key) {
        $templates = $this->get_email_templates();
        if (!isset($templates[$template_key])) {
            wp_die('Invalid template');
        }
        
        $template = $templates[$template_key];
        $current_subject = get_option('rts_email_template_' . $template_key . '_subject', $template['default_subject']);
        $current_body = get_option('rts_email_template_' . $template_key . '_body', $template['default_body']);
        
        ?>
        <div class="wrap rts-templates-page">
            <div class="rts-page-header">
                <h1>
                    <a href="<?php echo admin_url('admin.php?page=rts-email-templates'); ?>" 
                       class="dashicons dashicons-arrow-left-alt2" style="text-decoration:none;"></a>
                    Edit: <?php echo esc_html($template['name']); ?>
                </h1>
                <p class="rts-page-description"><?php echo esc_html($template['description']); ?></p>
            </div>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('rts_save_template'); ?>
                <input type="hidden" name="action" value="rts_save_template">
                <input type="hidden" name="template_key" value="<?php echo esc_attr($template_key); ?>">
                
                <div class="rts-form-section">
                    <h3><span class="dashicons dashicons-email-alt"></span> Email Content</h3>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">Subject Line</label>
                        <input type="text" name="template_subject" 
                               value="<?php echo esc_attr($current_subject); ?>" 
                               class="rts-form-input" required>
                        <span class="rts-form-description">The email subject line subscribers will see</span>
                    </div>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">Email Body (HTML)</label>
                        <textarea name="template_body" class="rts-code-editor" required><?php 
                            echo esc_textarea($current_body); 
                        ?></textarea>
                        <span class="rts-form-description">Use HTML for formatting. Click variables below to copy.</span>
                    </div>
                </div>
                
                <div class="rts-card">
                    <h3><span class="dashicons dashicons-editor-code"></span> Available Variables</h3>
                    <p style="margin-bottom:20px;">Click any variable to copy it to your clipboard:</p>
                    <div class="rts-variables-list">
                        <?php foreach ($template['available_variables'] as $var): ?>
                            <code class="rts-variable-tag" onclick="navigator.clipboard.writeText('<?php echo esc_js($var); ?>');alert('Copied!');">
                                <?php echo esc_html($var); ?>
                            </code>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="rts-info-box">
                    <h4><span class="dashicons dashicons-lightbulb"></span> Template Tips</h4>
                    <ul style="margin:10px 0 0 20px;">
                        <li>Use <code>{subscriber_email}</code> for personalization</li>
                        <li>Always include <code>{unsubscribe_url}</code> for compliance</li>
                        <li>Use standard HTML tags: &lt;h2&gt;, &lt;p&gt;, &lt;strong&gt;, &lt;a&gt;</li>
                        <li>Keep designs simple - many email clients strip complex CSS</li>
                    </ul>
                </div>
                
                <div class="rts-button-group">
                    <button type="submit" class="rts-button success">
                        <span class="dashicons dashicons-yes"></span> Save Template
                    </button>
                    <a href="<?php echo admin_url('admin.php?page=rts-email-templates'); ?>" 
                       class="rts-button secondary">
                        <span class="dashicons dashicons-no-alt"></span> Cancel
                    </a>
                    <button type="button" class="rts-button" onclick="if(confirm('Send test email to <?php echo esc_js(get_option('admin_email')); ?>?')) { document.getElementById('test-form').submit(); }">
                        <span class="dashicons dashicons-email"></span> Send Test Email
                    </button>
                </div>
            </form>
            
            <form id="test-form" method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:none;">
                <?php wp_nonce_field('rts_test_template'); ?>
                <input type="hidden" name="action" value="rts_test_template">
                <input type="hidden" name="template_key" value="<?php echo esc_attr($template_key); ?>">
            </form>
        </div>
        <?php
    }
    
    /**
     * Get all email templates with metadata
     */
    private function get_email_templates() {
        return array(
            'verification' => array(
                'name' => 'Email Verification',
                'description' => 'Sent immediately after subscription to verify email address',
                'order' => 1,
                'when' => 'First',
                'audience' => 'New subscribers',
                'variables' => 5,
                'default_subject' => 'Please verify your email - Reasons to Stay',
                'default_body' => '<h2>Verify Your Email</h2><p>Thanks for subscribing to Reasons to Stay! Please verify your email address to start receiving letters of hope.</p><p><a href="{verify_url}" style="background:#3b82f6;color:#fff;padding:12px 24px;text-decoration:none;border-radius:8px;display:inline-block;">Verify Email Address</a></p><p style="font-size:12px;color:#666;">Or copy this link: {verify_url}</p>',
                'available_variables' => array('{subscriber_email}', '{verify_url}', '{site_name}', '{site_url}', '{unsubscribe_url}')
            ),
            'welcome' => array(
                'name' => 'Welcome Email',
                'description' => 'Sent after email verification or immediately if verification is disabled',
                'order' => 2,
                'when' => 'Second',
                'audience' => 'Verified subscribers',
                'variables' => 6,
                'default_subject' => 'Welcome to Reasons to Stay',
                'default_body' => '<h2>Welcome to Reasons to Stay!</h2><p>Thank you for subscribing. You\'ll receive {frequency} emails with letters of hope and encouragement.</p><p>Our letters come from real people sharing their reasons to stay - authentic stories that might help during difficult times.</p><p><a href="{site_url}">Visit our website</a> | <a href="{change_frequency_url}">Change frequency</a></p><p style="font-size:11px;color:#666;"><a href="{unsubscribe_url}">Unsubscribe</a></p>',
                'available_variables' => array('{subscriber_email}', '{frequency}', '{site_name}', '{site_url}', '{change_frequency_url}', '{unsubscribe_url}')
            ),
            'daily_digest' => array(
                'name' => 'Daily Digest',
                'description' => 'Contains 1 random letter, sent daily at 9am to daily subscribers',
                'order' => 3,
                'when' => 'Daily 9am',
                'audience' => 'Daily subscribers',
                'variables' => 8,
                'default_subject' => 'Your daily letter - {current_date}',
                'default_body' => '<h2>Today\'s Letter</h2>{letter_content}<hr><p style="font-size:12px;color:#666;"><a href="{site_url}">Read more letters</a> | <a href="{change_frequency_url}">Change frequency</a> | <a href="{unsubscribe_url}">Unsubscribe</a></p>',
                'available_variables' => array('{letter_title}', '{letter_content}', '{letter_url}', '{subscriber_email}', '{site_name}', '{site_url}', '{change_frequency_url}', '{unsubscribe_url}')
            ),
            'weekly_digest' => array(
                'name' => 'Weekly Digest',
                'description' => 'Contains 5 random letters, sent Mondays at 9am to weekly subscribers',
                'order' => 3,
                'when' => 'Mon 9am',
                'audience' => 'Weekly subscribers',
                'variables' => 7,
                'default_subject' => 'Your weekly letters - {current_date}',
                'default_body' => '<h2>This Week\'s Letters</h2>{letters_list}<hr><p style="font-size:12px;color:#666;"><a href="{site_url}">Read more</a> | <a href="{change_frequency_url}">Change frequency</a> | <a href="{unsubscribe_url}">Unsubscribe</a></p>',
                'available_variables' => array('{letters_list}', '{subscriber_email}', '{site_name}', '{site_url}', '{change_frequency_url}', '{unsubscribe_url}', '{current_date}')
            ),
            'monthly_digest' => array(
                'name' => 'Monthly Digest',
                'description' => 'Contains 5 curated letters, sent 1st of month at 9am to monthly subscribers',
                'order' => 3,
                'when' => '1st 9am',
                'audience' => 'Monthly subscribers',
                'variables' => 7,
                'default_subject' => 'Your monthly letters - {current_date}',
                'default_body' => '<h2>This Month\'s Letters</h2>{letters_list}<hr><p style="font-size:12px;color:#666;"><a href="{site_url}">Read more</a> | <a href="{change_frequency_url}">Change frequency</a> | <a href="{unsubscribe_url}">Unsubscribe</a></p>',
                'available_variables' => array('{letters_list}', '{subscriber_email}', '{site_name}', '{site_url}', '{change_frequency_url}', '{unsubscribe_url}', '{current_date}')
            ),
            'frequency_changed' => array(
                'name' => 'Frequency Changed',
                'description' => 'Confirmation sent when subscriber changes their email frequency',
                'order' => 2,
                'when' => 'On change',
                'audience' => 'Active subscribers',
                'variables' => 6,
                'default_subject' => 'Email frequency updated - Reasons to Stay',
                'default_body' => '<h2>Frequency Updated</h2><p>Your email frequency has been changed to <strong>{frequency}</strong>. You\'ll now receive letters {frequency}.</p><p>You can change this anytime by clicking the link in any email.</p><p style="font-size:11px;color:#666;"><a href="{unsubscribe_url}">Unsubscribe</a></p>',
                'available_variables' => array('{subscriber_email}', '{frequency}', '{site_name}', '{site_url}', '{change_frequency_url}', '{unsubscribe_url}')
            ),
            'unsubscribe' => array(
                'name' => 'Unsubscribe Confirmation',
                'description' => 'Sent when subscriber unsubscribes (goodbye message)',
                'order' => 3,
                'when' => 'On unsub',
                'audience' => 'Unsubscribed users',
                'variables' => 4,
                'default_subject' => 'You\'ve been unsubscribed',
                'default_body' => '<h2>Goodbye for now</h2><p>You\'ve been successfully unsubscribed from Reasons to Stay emails.</p><p>If you change your mind, you can always re-subscribe at <a href="{site_url}">{site_name}</a>.</p><p>Take care of yourself.</p>',
                'available_variables' => array('{subscriber_email}', '{site_name}', '{site_url}', '{current_date}')
            ),
        );
    }
    
    /**
     * Handle template save
     */
    public function handle_save_template() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('rts_save_template');
        
        $template_key = sanitize_text_field($_POST['template_key']);
        $subject = sanitize_text_field($_POST['template_subject']);
        $body = wp_kses_post($_POST['template_body']);
        
        update_option('rts_email_template_' . $template_key . '_subject', $subject);
        update_option('rts_email_template_' . $template_key . '_body', $body);
        
        wp_redirect(add_query_arg(array(
            'page' => 'rts-email-templates',
            'saved' => '1'
        ), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Handle test email
     */
    public function handle_test_template() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('rts_test_template');
        
        $template_key = sanitize_text_field($_POST['template_key']);
        $to = get_option('admin_email');
        
        // Get current template
        $subject = get_option('rts_email_template_' . $template_key . '_subject', 'Test Email');
        $body = get_option('rts_email_template_' . $template_key . '_body', '<p>Test</p>');
        
        // Replace variables with test data
        $body = str_replace('{subscriber_email}', $to, $body);
        $body = str_replace('{site_name}', get_bloginfo('name'), $body);
        $body = str_replace('{site_url}', home_url(), $body);
        $body = str_replace('{verify_url}', home_url('?test=verify'), $body);
        $body = str_replace('{unsubscribe_url}', home_url('?test=unsubscribe'), $body);
        
        wp_mail($to, '[TEST] ' . $subject, $body, array('Content-Type: text/html'));
        
        wp_redirect(add_query_arg(array(
            'page' => 'rts-email-templates',
            'edit' => $template_key,
            'test_sent' => '1'
        ), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Render Analytics Page with Modern Cards
     */
    public function render_analytics() {
        $analytics = new RTS_Analytics();
        $stats = $analytics->get_subscriber_stats();
        ?>
        <div class="wrap rts-analytics-page">
            <div class="rts-page-header">
                <h1><span class="dashicons dashicons-chart-area"></span>Subscriber Analytics</h1>
                <p class="rts-page-description">Overview of your subscriber base and email performance</p>
            </div>
            
            <div class="rts-metrics-grid">
                <div class="rts-metric-card">
                    <span class="rts-metric-label">Total Subscribers</span>
                    <span class="rts-metric-value"><?php echo number_format($stats['total']); ?></span>
                    <span class="rts-metric-subtitle">All time</span>
                </div>
                
                <div class="rts-metric-card">
                    <span class="rts-metric-label">Active Subscribers</span>
                    <span class="rts-metric-value"><?php echo number_format($stats['active']); ?></span>
                    <span class="rts-metric-subtitle">Currently receiving emails</span>
                </div>
                
                <div class="rts-metric-card">
                    <span class="rts-metric-label">Engagement Rate</span>
                    <span class="rts-metric-value"><?php 
                        echo $stats['total'] > 0 ? number_format(($stats['active'] / $stats['total']) * 100, 1) : '0'; 
                    ?>%</span>
                    <span class="rts-metric-subtitle">Active / Total</span>
                </div>
            </div>
            
            <div class="rts-card">
                <h3><span class="dashicons dashicons-admin-tools"></span> Quick Actions</h3>
                <div class="rts-button-group">
                    <a href="<?php echo admin_url('edit.php?post_type=rts_subscriber'); ?>" class="rts-button">
                        <span class="dashicons dashicons-admin-users"></span> View All Subscribers
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=rts-import'); ?>" class="rts-button secondary">
                        <span class="dashicons dashicons-upload"></span> Import More
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=rts-email-templates'); ?>" class="rts-button secondary">
                        <span class="dashicons dashicons-email-alt"></span> Edit Templates
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render Import Page with Modern Cards
     */
    public function render_import() {
        ?>
        <div class="wrap rts-import-page">
            <div class="rts-page-header">
                <h1><span class="dashicons dashicons-upload"></span>Import Subscribers</h1>
                <p class="rts-page-description">Upload a CSV file to import subscribers in bulk</p>
            </div>
            
            <?php
            $rts_session_id = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '';
            if ($rts_session_id) :
                $rts_nonce = wp_create_nonce('rts_import_progress');
            ?>
                <div class="rts-card" id="rts-import-progress" style="margin-bottom:20px;">
                    <h3><span class="dashicons dashicons-update"></span> Import Progress</h3>
                    <p id="rts-import-status" style="margin:8px 0 0 0;color:#cbd5e1;">Loading progress...</p>
                    <div style="margin-top:15px;">
                        <div style="height:10px;background:#0f172a;border:1px solid #334155;border-radius:999px;overflow:hidden;">
                            <div id="rts-import-bar" style="height:10px;width:0%;background:#22c55e;"></div>
                        </div>
                        <p id="rts-import-counts" style="margin:10px 0 0 0;color:#cbd5e1;"></p>
                    </div>
                </div>
                <script>
                (function(){
                    var sessionId = <?php echo json_encode($rts_session_id); ?>;
                    var nonce = <?php echo json_encode($rts_nonce); ?>;
                    var bar = document.getElementById('rts-import-bar');
                    var statusEl = document.getElementById('rts-import-status');
                    var countsEl = document.getElementById('rts-import-counts');

                    function pct(part, total){
                        if(!total || total <= 0) return 0;
                        return Math.min(100, Math.max(0, Math.round((part/total)*100)));
                    }

                    function tick(){
                        var data = new FormData();
                        data.append('action','rts_import_progress');
                        data.append('nonce', nonce);
                        data.append('session_id', sessionId);

                        fetch(ajaxurl, {method:'POST', body:data, credentials:'same-origin'})
                          .then(function(r){ return r.json(); })
                          .then(function(resp){
                              if(!resp || !resp.success){
                                  statusEl.textContent = 'Progress unavailable.';
                                  return;
                              }
                              var s = resp.data;
                              var total = parseInt(s.total_rows || 0, 10);
                              var processed = parseInt(s.processed || 0, 10);
                              var imported = parseInt(s.imported || 0, 10);
                              var dup = parseInt(s.skipped_duplicate || 0, 10);
                              var inv = parseInt(s.skipped_invalid || 0, 10);
                              var oth = parseInt(s.skipped_other || 0, 10);
                              var st = (s.status || 'processing');

                              var p = pct(processed, total);
                              bar.style.width = p + '%';

                              statusEl.textContent = (st === 'complete')
                                  ? ('Complete. Imported ' + imported + ' subscribers.')
                                  : ('Processing... ' + p + '%');

                              countsEl.textContent =
                                  'Processed: ' + processed + ' / ' + total +
                                  ' | Imported: ' + imported +
                                  ' | Duplicates: ' + dup +
                                  ' | Invalid: ' + inv +
                                  ' | Other skipped: ' + oth;

                              if(st !== 'complete' && st !== 'error'){
                                  setTimeout(tick, 2000);
                              }
                          })
                          .catch(function(){
                              statusEl.textContent = 'Progress check failed. Refresh to retry.';
                          });
                    }
                    tick();
                })();
                </script>
            <?php endif; ?>

<?php if (isset($_GET['imported'])): ?>
                <div class="rts-info-box success">
                    <h4><span class="dashicons dashicons-yes"></span> Import Successful</h4>
                    <p>Successfully imported <?php echo intval($_GET['imported']); ?> subscribers!</p>
                </div>
            <?php endif; ?>
            
            <div class="rts-card">
                <h3><span class="dashicons dashicons-media-spreadsheet"></span> CSV Format Requirements</h3>
                <p>Your CSV file must have at minimum an <code>email</code> column. Optional columns:</p>
                <ul style="margin:10px 0 0 20px;color:#cbd5e1;">
                    <li><code>email</code> - Required, subscriber email address</li>
                    <li><code>frequency</code> - Optional: daily, weekly, or monthly (default: weekly)</li>
                    <li><code>source</code> - Optional: where subscriber came from (default: import)</li>
                </ul>
            </div>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('rts_import_csv'); ?>
                <input type="hidden" name="action" value="rts_import_csv">
                
                <div class="rts-form-section">
                    <h3><span class="dashicons dashicons-upload"></span> Upload CSV File</h3>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">Select CSV File</label>
                        <input type="file" name="csv_file" accept=".csv" required 
                               style="display:block;padding:10px;background:#0f172a;border:2px solid #334155;border-radius:10px;color:#f1f5f9;">
                        <span class="rts-form-description">Maximum file size: 5MB</span>
                    </div>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">Default Frequency (if not in CSV)</label>
                        <select name="default_frequency" class="rts-form-select">
                            <option value="weekly">Weekly (recommended)</option>
                            <option value="daily">Daily</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                </div>
                
                <div class="rts-button-group">
                    <button type="submit" class="rts-button success">
                        <span class="dashicons dashicons-upload"></span> Import Subscribers
                    </button>
                </div>
            </form>
            
            <div class="rts-info-box">
                <h4><span class="dashicons dashicons-info"></span> Import Notes</h4>
                <ul style="margin:10px 0 0 20px;">
                    <li>Duplicate emails will be skipped automatically</li>
                    <li>Invalid emails will be logged and skipped</li>
                    <li>Import runs in batches of 1000 for performance</li>
                    <li>All imported subscribers will be marked as "verified" by default</li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Redirect the default post editor for rts_subscriber to our custom form.
     */
    public function redirect_subscriber_post_new() {
        if (!is_admin()) {
            return;
        }
        global $pagenow;
        if ($pagenow === 'post-new.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'rts_subscriber') {
            wp_safe_redirect(admin_url('edit.php?post_type=rts_subscriber&page=rts-add-subscriber'));
            exit;
        }
    }

    public function render_add_subscriber() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $notice = '';
        if (isset($_GET['rts_added']) && $_GET['rts_added'] === '1') {
            $notice = '<div class="notice notice-success is-dismissible"><p>Subscriber added.</p></div>';
        }

        ?>
        <div class="wrap rts-admin-wrap">
            <h1 style="margin-bottom:16px;">Add Subscriber</h1>
            <?php echo $notice; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="rts-form">
                <input type="hidden" name="action" value="rts_add_subscriber" />
                <?php wp_nonce_field('rts_add_subscriber', '_wpnonce'); ?>

                <div class="rts-form-section">
                    <h3><span class="dashicons dashicons-email"></span> Email Address</h3>
                    <div class="rts-form-row">
                        <label class="rts-form-label" for="rts_email">Email</label>
                        <input id="rts_email" type="email" name="email" required placeholder="name@example.com"
                               style="display:block;width:100%;max-width:520px;padding:12px 14px;background:#0f172a;border:2px solid #334155;border-radius:14px;color:#f1f5f9;" />
                        <span class="rts-form-description">This creates (or updates) a subscriber record.</span>
                    </div>
                </div>

                <div class="rts-form-section">
                    <h3><span class="dashicons dashicons-list-view"></span> Subscriptions</h3>
                    <div class="rts-form-row">
                        <label style="display:block;margin-bottom:10px;">
                            <input type="checkbox" name="pref_letters" value="1" /> Receive letter emails
                        </label>
                        <label style="display:block;margin-bottom:10px;">
                            <input type="checkbox" name="pref_newsletters" value="1" /> Receive newsletters
                        </label>
                        <div style="opacity:0.9; font-size:13px;">If <strong>Re-consent Required</strong> is enabled, subscribers will not receive anything until they confirm in the preference centre.</div>
                    </div>
                </div>

                <div class="rts-form-section">
                    <h3><span class="dashicons dashicons-clock"></span> Frequency</h3>
                    <div class="rts-form-row">
                        <label class="rts-form-label" for="rts_frequency">Digest Frequency</label>
                        <select id="rts_frequency" name="frequency" class="rts-form-select" style="max-width:240px;">
                            <option value="weekly">Weekly</option>
                            <option value="daily">Daily</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                </div>

                <div class="rts-button-group">
                    <button type="submit" class="rts-button success">
                        <span class="dashicons dashicons-yes"></span> Save Subscriber
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    public function handle_add_subscriber() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        check_admin_referer('rts_add_subscriber', '_wpnonce');

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (!$email || !is_email($email)) {
            wp_safe_redirect(add_query_arg(array('post_type' => 'rts_subscriber', 'page' => 'rts-add-subscriber', 'rts_added' => '0'), admin_url('edit.php')));
            exit;
        }

        // Find existing subscriber by email (title) first.
        $existing = get_page_by_title($email, OBJECT, 'rts_subscriber');
        $subscriber_id = $existing ? (int) $existing->ID : 0;

        if (!$subscriber_id) {
            $subscriber_id = wp_insert_post(array(
                'post_type' => 'rts_subscriber',
                'post_status' => 'publish',
                'post_title' => $email,
            ));
        }

        if (!is_wp_error($subscriber_id) && $subscriber_id) {
            update_post_meta($subscriber_id, '_rts_subscriber_status', 'active');
            update_post_meta($subscriber_id, '_rts_subscriber_frequency', sanitize_key($_POST['frequency'] ?? 'weekly'));
            update_post_meta($subscriber_id, '_rts_pref_letters', isset($_POST['pref_letters']) ? 1 : 0);
            update_post_meta($subscriber_id, '_rts_pref_newsletters', isset($_POST['pref_newsletters']) ? 1 : 0);
            // Do NOT auto-mark consent confirmed here. Consent should come via the preference centre.
        }

        wp_safe_redirect(admin_url('edit.php?post_type=rts_subscriber&page=rts-add-subscriber&rts_added=1'));
        exit;
    }
}
