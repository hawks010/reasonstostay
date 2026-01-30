<?php
/**
 * Reasons to Stay - User-Friendly Admin Settings
 * Simplified interface for non-technical admins
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RTS_Admin_Settings')) {

class RTS_Admin_Settings {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_settings_page'], 40);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_post_actions']);
        add_filter('wp_mail', [$this, 'filter_wp_mail']);
    }

    public function add_settings_page() {
        // DISABLED: Settings menu is now registered by admin-menu-consolidated.php
        // This prevents duplicate menu entries
        /*
        add_submenu_page(
            'edit.php?post_type=letter',
            'Settings',
            'Settings',
            // Client-friendly: allow trusted letter managers (Editors) to access settings.
            // Admin-only actions inside the page still require manage_options.
            'edit_posts',
            'rts-settings',
            [$this, 'render_settings_page']
        );
        */
    }

    public function register_settings() {
        // Email notifications
        register_setting('rts_settings', 'rts_notify_email', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => get_option('admin_email'),
        ]);

        register_setting('rts_settings', 'rts_cc_enabled', [
            'type' => 'boolean',
            'default' => 1,
        ]);

        register_setting('rts_settings', 'rts_cc_email', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
        ]);
        
        // Rate limits
        register_setting('rts_settings', 'rts_rate_limits', [
            'type' => 'array',
            'default' => [
                'submissions_per_hour' => 3,
                'submissions_per_day' => 10,
            ],
        ]);
        
        // Auto-approval settings
        register_setting('rts_settings', 'rts_auto_approval_enabled', [
            'type' => 'boolean',
            'default' => 0,
        ]);
        
        register_setting('rts_settings', 'rts_auto_approval_min_score', [
            'type' => 'integer',
            'default' => 70,
        ]);
        
        // NEW: Auto-publish settings (v2.0.10.90)
        register_setting('rts_settings', 'rts_auto_publish_enabled', [
            'type' => 'boolean',
            'default' => 1, // Enabled by default
        ]);
        
        register_setting('rts_settings', 'rts_min_quality_score', [
            'type' => 'integer',
            'default' => 70,
        ]);
        
        register_setting('rts_settings', 'rts_notify_on_flag', [
            'type' => 'boolean',
            'default' => 1,
        ]);
        
        // Manual stats override
        register_setting('rts_settings', 'rts_stats_override', [
            'type' => 'array',
            'default' => [
                'enabled' => 0,
                'total_letters' => 0,
                'letters_delivered' => 0,
                'feel_better_percent' => 0,
            ],
        ]);
    }

    public function handle_post_actions() {
        // Download logs (admin only)
        if (isset($_POST['rts_download_logs']) && check_admin_referer('rts_download_logs')) {
            if (!current_user_can('manage_options')) return;
            
            $upload_dir = wp_upload_dir();
            $log_file = $upload_dir['basedir'] . '/rts-logs/rts.log';
            
            if (file_exists($log_file)) {
                header('Content-Type: text/plain');
                header('Content-Disposition: attachment; filename="rts-logs-' . date('Y-m-d') . '.txt"');
                readfile($log_file);
                exit;
            }
        }

        // Clear recent logs
        if (isset($_POST['rts_clear_logs']) && check_admin_referer('rts_clear_logs')) {
            if (!current_user_can('manage_options')) return;
            
            $lock_key = 'rts_log_clear_lock';
            if (!get_transient($lock_key)) {
                set_transient($lock_key, true, 5);
                if (class_exists('RTS_Logger')) {
                    RTS_Logger::clear_recent();
                }
                add_settings_error('rts_settings', 'logs_cleared', 'Recent logs cleared.', 'success');
            }
        }

        // Test email
        if (isset($_POST['rts_test_email']) && check_admin_referer('rts_test_email')) {
            $test_email = sanitize_email($_POST['test_email_address']);
            if ($test_email) {
                $sent = wp_mail($test_email, 'RTS Test Email', 'This is a test email from Reasons to Stay. If you received this, email notifications are working correctly!');
                if ($sent) {
                    add_settings_error('rts_settings', 'email_sent', 'Test email sent successfully!', 'success');
                } else {
                    add_settings_error('rts_settings', 'email_failed', 'Failed to send test email. Check your email settings.', 'error');
                }
            }
        }
    }

    public function filter_wp_mail($args) {
        $cc_enabled = get_option('rts_cc_enabled', 1);
        $cc_email = get_option('rts_cc_email', '');

        if ($cc_enabled && !empty($cc_email) && is_email($cc_email)) {
            $headers = isset($args['headers']) ? (array) $args['headers'] : [];
            $headers[] = 'Cc: ' . $cc_email;
            $args['headers'] = $headers;
        }

        return $args;
    }

    public function render_settings_page() {
        if (!current_user_can('edit_posts')) {
            wp_die('You do not have permission to access this page.');
        }

        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'general';
        
        ?>
        <div class="wrap">
            <h1>💙 Reasons to Stay - Settings</h1>
            
            <?php settings_errors('rts_settings'); ?>
            
            <nav class="nav-tab-wrapper" style="margin: 20px 0;">
                <a href="?post_type=letter&page=rts-settings&tab=autoprocess" 
                   class="nav-tab <?php echo $active_tab === 'autoprocess' ? 'nav-tab-active' : ''; ?>">
                    ⚡ Auto Processing
                </a>
                <a href="?post_type=letter&page=rts-settings&tab=import" 
                   class="nav-tab <?php echo $active_tab === 'import' ? 'nav-tab-active' : ''; ?>">
                    📤 Import / Export
                </a>
                <a href="?post_type=letter&page=rts-settings&tab=general" 
                   class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    📧 Email & Notifications
                </a>
                <a href="?post_type=letter&page=rts-settings&tab=letters" 
                   class="nav-tab <?php echo $active_tab === 'letters' ? 'nav-tab-active' : ''; ?>">
                    ✉️ Letter Management
                </a>
                <a href="?post_type=letter&page=rts-settings&tab=tools" 
                   class="nav-tab <?php echo $active_tab === 'tools' ? 'nav-tab-active' : ''; ?>">
                    🔧 Tools
                </a>
                <a href="?post_type=letter&page=rts-settings&tab=debug" 
                   class="nav-tab <?php echo $active_tab === 'debug' ? 'nav-tab-active' : ''; ?>" 
                   style="color: #d63638;">
                    🐛 Debug
                </a>
            </nav>

            <?php
            switch ($active_tab) {
                case 'autoprocess':
                    $this->render_autoprocess_tab();
                    break;
                case 'import':
                    $this->render_import_tab();
                    break;
                case 'general':
                    $this->render_general_tab();
                    break;
                case 'letters':
                    $this->render_letters_tab();
                    break;
                case 'tools':
                    $this->render_tools_tab();
                    break;
                case 'debug':
                case 'advanced':
                    $this->render_advanced_tab();
                    break;
            }
            ?>
        </div>
        <?php
    }
    
    private function render_import_tab() {
        // Call the Import/Export class to render its content
        if (class_exists('RTS_Import_Export')) {
            $import_export = RTS_Import_Export::get_instance();
            $import_export->render_import_export_page();
        } else {
            echo '<div class="notice notice-error"><p>Import/Export module not available.</p></div>';
        }
    }

    private function render_general_tab() {
        $notify_email = get_option('rts_notify_email', get_option('admin_email'));
        $cc_enabled = get_option('rts_cc_enabled', 1);
        $cc_email = get_option('rts_cc_email', '');
        ?>
        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2 style="margin-top: 0;">📧 Email Notifications</h2>
            <p style="color: #666;">Configure where you'll receive notifications about new letters and important updates.</p>
            
            <form method="post" action="options.php">
                <?php settings_fields('rts_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="rts_notify_email">Primary Email Address</label>
                        </th>
                        <td>
                            <input type="email" 
                                   id="rts_notify_email" 
                                   name="rts_notify_email" 
                                   value="<?php echo esc_attr($notify_email); ?>" 
                                   class="regular-text"
                                   required />
                            <p class="description">You'll receive notifications when new letters are submitted for review.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="rts_cc_enabled">Copy Another Email</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="rts_cc_enabled" 
                                       name="rts_cc_enabled" 
                                       value="1" 
                                       <?php checked($cc_enabled, 1); ?> />
                                Send a copy of all notifications to another email address
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="rts_cc_email">CC Email Address</label>
                        </th>
                        <td>
                            <input type="email" 
                                   id="rts_cc_email" 
                                   name="rts_cc_email" 
                                   value="<?php echo esc_attr($cc_email); ?>" 
                                   class="regular-text" 
                                   placeholder="Optional" />
                            <p class="description">Example: your developer or a team member who should be kept in the loop.</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button('Save Email Settings'); ?>
            </form>
        </div>

        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2 style="margin-top: 0;">🧪 Test Email System</h2>
            <p style="color: #666;">Send a test email to verify everything is working correctly.</p>
            
            <form method="post">
                <?php wp_nonce_field('rts_test_email'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="test_email_address">Test Email Address</label>
                        </th>
                        <td>
                            <input type="email" 
                                   id="test_email_address" 
                                   name="test_email_address" 
                                   value="<?php echo esc_attr($notify_email); ?>" 
                                   class="regular-text"
                                   required />
                            <p class="description">We'll send a test message to this address.</p>
                        </td>
                    </tr>
                </table>
                
                <button type="submit" name="rts_test_email" class="button button-secondary">
                    Send Test Email
                </button>
            </form>
        </div>
        <?php
    }
    
    private function render_autoprocess_tab() {
        if (!current_user_can('manage_options')) {
            echo '<div class="notice notice-error"><p>You need administrator permissions to access auto-processing controls.</p></div>';
            return;
        }
        
        // Show notices
        if (isset($_GET['processed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            echo '<strong>✓ Processing complete!</strong> ';
            echo 'Processed: ' . intval($_GET['processed']) . ' letters. ';
            if (isset($_GET['published'])) echo 'Published: ' . intval($_GET['published']) . '. ';
            if (isset($_GET['flagged'])) echo 'Flagged: ' . intval($_GET['flagged']) . '.';
            echo '</p></div>';
        }
        
        if (isset($_GET['recheck_started'])) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>✓ Recheck started!</strong> Processing will continue in the background. Refresh this page to see progress.</p></div>';
        }
        
        if (isset($_GET['recheck_stopped'])) {
            echo '<div class="notice notice-info is-dismissible"><p><strong>Recheck stopped.</strong> You can resume it anytime.</p></div>';
        }
        
        if (isset($_GET['error'])) {
            echo '<div class="notice notice-error is-dismissible"><p><strong>Error:</strong> ' . esc_html(urldecode($_GET['error'])) . '</p></div>';
        }
        
        // Get status
        $status = RTS_Cron_Processing::get_status();
        $recheck_active = get_option('rts_recheck_all_active', 0);
        $auto_publish = get_option('rts_auto_publish_enabled', '1') === '1';
        $min_score = get_option('rts_min_quality_score', 70);
        
        // Determine system state
        if ($status['is_locked']) {
            $state = 'running';
            $state_label = '⏳ Running';
            $state_color = '#dba617';
            $state_bg = '#fff3cd';
        } elseif ($recheck_active) {
            $state = 'rechecking';
            $state_label = '🔄 Rechecking';
            $state_color = '#0969da';
            $state_bg = '#ddf4ff';
        } elseif ($status['last_error_message']) {
            $state = 'error';
            $state_label = '❌ Error';
            $state_color = '#d63638';
            $state_bg = '#fcf0f1';
        } else {
            $state = 'idle';
            $state_label = '✓ Idle';
            $state_color = '#00a32a';
            $state_bg = '#e7f5e9';
        }
        
        ?>
        <div class="card" style="max-width: 1000px; padding: 20px; margin-top: 20px;">
            
            <!-- System Status Badge -->
            <div style="background: <?php echo $state_bg; ?>; border-left: 4px solid <?php echo $state_color; ?>; padding: 15px; margin-bottom: 20px;">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <div style="font-size: 24px; font-weight: 700; color: <?php echo $state_color; ?>;">
                        <?php echo $state_label; ?>
                    </div>
                    <div style="flex: 1; color: #666;">
                        <?php if ($state === 'running'): ?>
                            Processing batch now...
                        <?php elseif ($state === 'rechecking'): ?>
                            Reanalyzing all letters - <?php echo $status['recheck_progress']; ?>
                        <?php elseif ($state === 'error'): ?>
                            <?php echo esc_html($status['last_error_message']); ?>
                        <?php else: ?>
                            System ready. Auto-processing runs every 5 minutes.
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <h2 style="margin-top: 0;">⚡ Auto Processing Status</h2>
            
            <div style="background: #f0f6fc; padding: 15px; border-left: 4px solid #0969da; margin: 20px 0;">
                <h3 style="margin-top: 0;">📊 Last Run Info</h3>
                <table style="width: 100%;">
                    <tr>
                        <td style="width: 200px;"><strong>Last Run:</strong></td>
                        <td><?php echo $status['last_run'] ? date('Y-m-d H:i:s', $status['last_run']) : 'Never'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Source:</strong></td>
                        <td><?php echo ucfirst($status['last_source']); ?></td>
                    </tr>
                    <tr>
                        <td><strong>Mode:</strong></td>
                        <td><?php echo $status['last_mode'] === 'recheck_all' ? 'Recheck All' : 'Pending Letters'; ?></td>
                    </tr>
                    <tr>
                        <td><strong>Processed:</strong></td>
                        <td><?php echo $status['last_processed']; ?> letters</td>
                    </tr>
                    <tr>
                        <td><strong>Published:</strong></td>
                        <td><?php echo $status['last_published']; ?> letters</td>
                    </tr>
                    <tr>
                        <td><strong>Flagged:</strong></td>
                        <td><?php echo $status['last_flagged']; ?> letters</td>
                    </tr>
                    <tr>
                        <td><strong>Status:</strong></td>
                        <td>
                            <?php if ($status['is_locked']): ?>
                                <span style="color: #dba617;">⏳ Running now...</span>
                            <?php elseif ($status['last_error_message']): ?>
                                <span style="color: #d63638;">✗ Error: <?php echo esc_html($status['last_error_message']); ?></span>
                            <?php else: ?>
                                <span style="color: #00a32a;">✓ OK</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php if ($recheck_active): ?>
                    <tr>
                        <td><strong>Recheck Progress:</strong></td>
                        <td>
                            <?php echo $status['recheck_progress']; ?>
                            <div style="background: #e0e0e0; height: 20px; margin-top: 5px; border-radius: 3px; overflow: hidden;">
                                <?php
                                $offset = get_option('rts_recheck_all_offset', 0);
                                $total = get_option('rts_recheck_all_total', 1);
                                $percent = round(($offset / $total) * 100);
                                ?>
                                <div style="width: <?php echo $percent; ?>%; background: #2271b1; height: 100%; transition: width 0.3s;"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                </table>
            </div>
            
            <!-- Manual Controls Box -->
            <div style="background: #fff; border: 2px solid #0969da; border-radius: 8px; padding: 20px; margin: 30px 0;">
                <h3 style="margin-top: 0; color: #0969da;">🎛️ Manual Controls</h3>
                <p style="margin-bottom: 20px;">Trigger processing manually when needed. Automatic processing continues every 5 minutes in the background.</p>
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <!-- Quick Process -->
                    <div style="background: #f6f7f7; padding: 15px; border-radius: 4px;">
                        <h4 style="margin: 0 0 10px 0;">▶️ Quick Process</h4>
                        <p style="margin: 0 0 10px 0; font-size: 13px; color: #666;">Process 25 pending letters right now.</p>
                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin: 0;">
                            <?php wp_nonce_field('rts_manual_controls'); ?>
                            <input type="hidden" name="action" value="rts_run_processing_now">
                            <button type="submit" class="button button-primary" style="width: 100%;">
                                Run Processing Now
                            </button>
                        </form>
                    </div>
                    
                    <!-- Recheck All -->
                    <div style="background: #f6f7f7; padding: 15px; border-radius: 4px;">
                        <h4 style="margin: 0 0 10px 0;">🔄 Recheck All</h4>
                        <p style="margin: 0 0 10px 0; font-size: 13px; color: #666;">Reanalyze all letters with current rules.</p>
                        <?php if ($recheck_active): ?>
                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin: 0;">
                                <?php wp_nonce_field('rts_manual_controls'); ?>
                                <input type="hidden" name="action" value="rts_stop_recheck">
                                <button type="submit" class="button" style="width: 100%;">
                                    ⏸️ Stop Recheck
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="margin: 0;">
                                <?php wp_nonce_field('rts_manual_controls'); ?>
                                <input type="hidden" name="action" value="rts_start_recheck_all">
                                <button type="submit" class="button" style="width: 100%;" onclick="return confirm('Reprocess ALL letters?');">
                                    Start Recheck All
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <h3>⚙️ Auto-Publish Settings</h3>
            <form method="post" action="options.php">
                <?php settings_fields('rts_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Auto-Publish Enabled</th>
                        <td>
                            <label>
                                <input type="checkbox" name="rts_auto_publish_enabled" value="1" <?php checked($auto_publish, true); ?>>
                                Automatically publish letters that meet quality threshold
                            </label>
                            <p class="description">If enabled, letters scoring above the minimum will be published automatically. If disabled, all letters stay pending for manual review.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Minimum Quality Score</th>
                        <td>
                            <input type="number" name="rts_min_quality_score" value="<?php echo $min_score; ?>" min="0" max="100" style="width: 80px;">
                            <p class="description">Letters must score at least this value to be auto-published (0-100). Recommended: 70</p>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary">Save Settings</button>
                </p>
            </form>
        </div>
        <?php
    }

    private function render_letters_tab() {
        // Some installs may have legacy/bad option shapes (string instead of array).
        // Always normalize to a safe array with defaults so the settings screen never fatals.
        $rate_limits_raw = get_option('rts_rate_limits', []);
        if (!is_array($rate_limits_raw)) {
            $rate_limits_raw = [];
        }
        $rate_limits = wp_parse_args($rate_limits_raw, [
            'submissions_per_hour' => 3,
            'submissions_per_day'  => 10,
        ]);
        $auto_approval_enabled = get_option('rts_auto_approval_enabled', 0);
        $auto_approval_min_score = get_option('rts_auto_approval_min_score', 70);
        $notify_on_flag = get_option('rts_notify_on_flag', 1);
        $stats_override_raw = get_option('rts_stats_override', []);
        if (!is_array($stats_override_raw)) {
            $stats_override_raw = [];
        }
        $stats_override = wp_parse_args($stats_override_raw, [
            'enabled'            => 0,
            'total_letters'      => 0,
            'letters_delivered'  => 0,
            'feel_better_percent'=> 0,
        ]);
        ?>
        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2 style="margin-top: 0;">🤖 Auto-Approval System</h2>
            <p style="color: #666;">Automatically publish safe letters and flag problematic ones for review.</p>
            
            <form method="post" action="options.php">
                <?php settings_fields('rts_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="rts_auto_approval_enabled">Enable Auto-Approval</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="rts_auto_approval_enabled" 
                                       name="rts_auto_approval_enabled" 
                                       value="1" 
                                       <?php checked($auto_approval_enabled, 1); ?> />
                                Automatically approve and publish letters that pass safety checks
                            </label>
                            <p class="description"><strong>This will save you hours of manual review!</strong> Safe letters publish instantly, problematic ones get flagged for review.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="rts_auto_approval_min_score">Safety Score Threshold</label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="rts_auto_approval_min_score" 
                                   name="rts_auto_approval_min_score" 
                                   value="<?php echo esc_attr($auto_approval_min_score); ?>" 
                                   min="50" 
                                   max="100" 
                                   class="small-text" />
                            <p class="description">Letters scoring <strong><?php echo $auto_approval_min_score; ?>/100</strong> or higher will auto-publish. Recommended: 70</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="rts_notify_on_flag">Email Notifications</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="rts_notify_on_flag" 
                                       name="rts_notify_on_flag" 
                                       value="1" 
                                       <?php checked($notify_on_flag, 1); ?> />
                                Email me when a letter is flagged for review
                            </label>
                        </td>
                    </tr>
                </table>
                
                <div style="background: #e7f5ff; padding: 15px; border-left: 4px solid #0073aa; margin-top: 20px;">
                    <p style="margin: 0;"><strong>✅ What gets auto-approved:</strong> Well-written letters without URLs, contact info, dangerous content, or spam patterns.</p>
                    <p style="margin: 10px 0 0 0;"><strong>🚨 What gets flagged:</strong> Letters with methods, immediate danger language, spam, or suspicious content.</p>
                </div>
                
                <?php submit_button('Save Auto-Approval Settings'); ?>
            </form>
        </div>
        
        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2 style="margin-top: 0;">✉️ Letter Submissions</h2>
            <p style="color: #666;">Control how many letters people can submit to prevent spam.</p>
            
            <form method="post" action="options.php">
                <?php settings_fields('rts_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="submissions_per_hour">Letters Per Hour</label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="submissions_per_hour" 
                                   name="rts_rate_limits[submissions_per_hour]" 
                                   value="<?php echo esc_attr($rate_limits['submissions_per_hour']); ?>" 
                                   min="1" 
                                   max="20" 
                                   class="small-text" />
                            <p class="description">Maximum letters one person can submit per hour. <strong>Recommended: 3</strong></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="submissions_per_day">Letters Per Day</label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="submissions_per_day" 
                                   name="rts_rate_limits[submissions_per_day]" 
                                   value="<?php echo esc_attr($rate_limits['submissions_per_day']); ?>" 
                                   min="1" 
                                   max="50" 
                                   class="small-text" />
                            <p class="description">Maximum letters one person can submit per day. <strong>Recommended: 10</strong></p>
                        </td>
                    </tr>
                </table>
                
                <div style="background: #e7f5ff; padding: 15px; border-left: 4px solid #0073aa; margin-top: 20px;">
                    <p style="margin: 0;"><strong>💡 Tip:</strong> These limits help prevent spam while still allowing genuine users to share multiple letters if needed.</p>
                </div>
                
                <?php submit_button('Save Letter Settings'); ?>
            </form>
        </div>
        
        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2 style="margin-top: 0;">📊 Homepage Stats Override</h2>
            <p style="color: #666;">Manually set homepage statistics to match your old website numbers.</p>
            
            <form method="post" action="options.php">
                <?php settings_fields('rts_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="stats_override_enabled">Use Manual Stats</label>
                        </th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       id="stats_override_enabled" 
                                       name="rts_stats_override[enabled]" 
                                       value="1" 
                                       <?php checked($stats_override['enabled'], 1); ?> />
                                Override automatic stats with manual numbers
                            </label>
                            <p class="description">When enabled, your homepage will show these numbers instead of real database counts.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="stats_total_letters">Total Letters</label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="stats_total_letters" 
                                   name="rts_stats_override[total_letters]" 
                                   value="<?php echo esc_attr($stats_override['total_letters']); ?>" 
                                   min="0" 
                                   class="regular-text" 
                                   placeholder="e.g., 8543" />
                            <p class="description">Total number of letters received (from old Wix site).</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="stats_letters_delivered">Letters Delivered</label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="stats_letters_delivered" 
                                   name="rts_stats_override[letters_delivered]" 
                                   value="<?php echo esc_attr($stats_override['letters_delivered']); ?>" 
                                   min="0" 
                                   class="regular-text" 
                                   placeholder="e.g., 125,780" />
                            <p class="description">Total views/deliveries across all letters.</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="stats_feel_better">Feel Better Percentage</label>
                        </th>
                        <td>
                            <input type="number" 
                                   id="stats_feel_better" 
                                   name="rts_stats_override[feel_better_percent]" 
                                   value="<?php echo esc_attr($stats_override['feel_better_percent']); ?>" 
                                   min="0" 
                                   max="100" 
                                   step="0.1"
                                   class="small-text" />%
                            <p class="description">Percentage of readers who reported feeling better. <strong>Example: 87.5</strong></p>
                        </td>
                    </tr>
                </table>
                
                <div style="background: #fff3cd; padding: 15px; border-left: 4px solid #f0ad4e; margin-top: 20px;">
                    <p style="margin: 0;"><strong>💡 Why use this?</strong> If you're migrating from another platform and want to maintain continuity with your existing stats, you can set these numbers to match your old site until your new letters catch up.</p>
                </div>
                
                <?php submit_button('Save Stats Override'); ?>
            </form>
        </div>

        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2 style="margin-top: 0;">📊 Quick Stats</h2>
            <?php
            $total_letters = wp_count_posts('letter')->publish;
            $pending_letters = wp_count_posts('letter')->pending;
            $flagged_count = 0;
            if (class_exists('RTS_Auto_Approval')) {
                global $wpdb;
                $flagged_count = $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = 'flagged_at'");
            }
            $today = date('Y-m-d');
            $today_count = get_posts([
                'post_type' => 'letter',
                'post_status' => 'any',
                'date_query' => [
                    ['after' => $today]
                ],
                'fields' => 'ids',
                'posts_per_page' => -1
            ]);
            ?>
            <table class="widefat" style="margin-top: 15px;">
                <tr>
                    <td style="padding: 12px;"><strong>Published Letters</strong></td>
                    <td style="padding: 12px;"><?php echo number_format($total_letters); ?></td>
                </tr>
                <tr style="background: #f9f9f9;">
                    <td style="padding: 12px;"><strong>Pending Review</strong></td>
                    <td style="padding: 12px;">
                        <?php echo number_format($pending_letters); ?>
                        <?php if ($pending_letters > 0): ?>
                            <a href="<?php echo admin_url('edit.php?post_type=letter&post_status=pending'); ?>" class="button button-small" style="margin-left: 10px;">Review Now</a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if ($flagged_count > 0): ?>
                <tr>
                    <td style="padding: 12px;"><strong style="color: #d63638;">🚨 Flagged for Review</strong></td>
                    <td style="padding: 12px;">
                        <strong style="color: #d63638;"><?php echo number_format($flagged_count); ?></strong>
                        <a href="<?php echo admin_url('edit.php?post_type=letter&page=rts-review-queue'); ?>" class="button button-small" style="margin-left: 10px;">Review Flagged →</a>
                    </td>
                </tr>
                <?php endif; ?>
                <tr style="background: #f9f9f9;">
                    <td style="padding: 12px;"><strong>Submitted Today</strong></td>
                    <td style="padding: 12px;"><?php echo count($today_count); ?></td>
                </tr>
            </table>
            
            <?php if ($pending_letters > 100): ?>
            <div style="margin-top: 20px; padding: 15px; background: #fcf3cd; border-left: 4px solid #f0b849;">
                <p style="margin: 0 0 10px 0;"><strong>⚡ You have <?php echo number_format($pending_letters); ?> letters pending!</strong></p>
                <p style="margin: 0;">Enable <strong>Auto-Approval</strong> above to automatically publish safe letters, or use the bulk actions:</p>
                <p style="margin: 10px 0 0 0;">
                    <a href="<?php echo admin_url('edit.php?post_type=letter&post_status=pending'); ?>" class="button button-primary">
                        Review & Bulk Approve →
                    </a>
                </p>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_tools_tab() {
        ?>
        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2 style="margin-top: 0;">🔧 Useful Tools</h2>
            <p style="color: #666;">Quick access to helpful tools and features.</p>
            
            <table class="widefat" style="margin-top: 20px;">
                <tr>
                    <td style="padding: 15px;">
                        <h3 style="margin: 0 0 5px 0;">📊 View Analytics</h3>
                        <p style="margin: 0 0 10px 0; color: #666;">See detailed statistics about your letters and site performance.</p>
                        <a href="<?php echo admin_url('edit.php?post_type=letter&page=rts-analytics'); ?>" class="button">View Analytics →</a>
                    </td>
                </tr>
                
                <tr style="background: #f9f9f9;">
                    <td style="padding: 15px;">
                        <h3 style="margin: 0 0 5px 0;">👁️ Preview Letters</h3>
                        <p style="margin: 0 0 10px 0; color: #666;">Preview how letters will look to visitors before publishing.</p>
                        <?php if (class_exists('RTS_Admin_Preview')): ?>
                            <a href="?post_type=letter&page=rts-settings&tab=advanced&subtab=preview" class="button">Open Preview Tool →</a>
                        <?php else: ?>
                            <span style="color: #d63638;">⚠️ Module not available</span>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <tr>
                    <td style="padding: 15px;">
                        <h3 style="margin: 0 0 5px 0;">📤 Backup Letters</h3>
                        <p style="margin: 0 0 10px 0; color: #666;">Export your letters as a backup or import letters from another system.</p>
                        <?php if (class_exists('RTS_Import_Export')): ?>
                            <a href="?post_type=letter&page=rts-settings&tab=advanced&subtab=import_export" class="button">Backup/Import Letters →</a>
                        <?php else: ?>
                            <span style="color: #d63638;">⚠️ Module not available</span>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <tr style="background: #f9f9f9;">
                    <td style="padding: 15px;">
                        <h3 style="margin: 0 0 5px 0;">♿ Accessibility Settings</h3>
                        <p style="margin: 0 0 10px 0; color: #666;">Configure the accessibility widget that helps visitors with different needs.</p>
                        <?php if (class_exists('RTS_Accessibility_Toolkit')): ?>
                            <a href="?post_type=letter&page=rts-settings&tab=advanced&subtab=accessibility" class="button">Configure Accessibility →</a>
                        <?php else: ?>
                            <span style="color: #d63638;">⚠️ Module not available</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>

        <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
            <h2 style="margin-top: 0;">💡 Quick Tips</h2>
            <ul style="line-height: 1.8; color: #666;">
                <li><strong>Need help?</strong> Your developer can access technical tools in the Advanced tab.</li>
                <li><strong>Pending letters?</strong> Review them from Letters > All Letters > Pending filter.</li>
                <li><strong>Something broken?</strong> Check the Advanced > System Logs for error messages.</li>
            </ul>
        </div>
        <?php
    }

    private function render_advanced_tab() {
        $subtab = isset($_GET['subtab']) ? sanitize_text_field($_GET['subtab']) : 'warning';
        
        if ($subtab === 'warning') {
            ?>
            <div class="card" style="max-width: 800px; padding: 30px; margin-top: 20px; border: 2px solid #d63638;">
                <h2 style="margin-top: 0; color: #d63638;">⚠️ Advanced Settings - Please Read</h2>
                
                <div style="background: #fcf3cd; padding: 20px; border-left: 4px solid #f0b849; margin: 20px 0;">
                    <p style="margin: 0 0 10px 0;"><strong>⚠️ Warning:</strong> These settings are for advanced users and developers only.</p>
                    <p style="margin: 0;">Making changes here without understanding what they do could break your website or cause data loss.</p>
                </div>
                
                <h3>What's in Advanced Settings:</h3>
                <ul style="line-height: 1.8;">
                    <li>♿ <strong>Accessibility Widget</strong> - Technical configuration</li>
                    <li>📤 <strong>Import/Export</strong> - Bulk data operations</li>
                    <li>👁️ <strong>Preview System</strong> - Letter preview tools</li>
                    <li>🔍 <strong>Debug Logs</strong> - Technical error logs</li>
                    <li>⚙️ <strong>System Configuration</strong> - Low-level settings</li>
                </ul>
                
                <div style="background: #fff3cd; padding: 20px; border-left: 4px solid #f0ad4e; margin: 20px 0;">
                    <p style="margin: 0;"><strong>💡 Recommendation:</strong> Unless you're troubleshooting a specific issue or were told to change something here by your developer, you probably don't need these settings.</p>
                </div>
                
                <p style="margin-top: 30px;">
                    <a href="?post_type=letter&page=rts-settings&tab=advanced&subtab=menu" class="button button-primary">
                        I Understand - Show Advanced Settings
                    </a>
                    <a href="?post_type=letter&page=rts-settings&tab=general" class="button">
                        ← Go Back to Safe Settings
                    </a>
                </p>
            </div>
            <?php
            return;
        }
        
        if ($subtab === 'menu') {
            ?>
            <div class="card" style="max-width: 800px; padding: 20px; margin-top: 20px;">
                <h2 style="margin-top: 0;">⚙️ Advanced Features</h2>
                <p style="color: #666;">Click on any item below to access advanced settings.</p>
                
                <table class="widefat" style="margin-top: 20px;">
                    <?php if (class_exists('RTS_Admin_Preview')): ?>
                    <tr>
                        <td style="padding: 15px;">
                            <h3 style="margin: 0 0 5px 0;">👁️ Preview System</h3>
                            <p style="margin: 0 0 10px 0; color: #666;">Preview and test letter displays.</p>
                            <a href="?post_type=letter&page=rts-settings&tab=advanced&subtab=preview" class="button">Open →</a>
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if (class_exists('RTS_Accessibility_Toolkit')): ?>
                    <tr style="background: #f9f9f9;">
                        <td style="padding: 15px;">
                            <h3 style="margin: 0 0 5px 0;">♿ Accessibility Toolkit Configuration</h3>
                            <p style="margin: 0 0 10px 0; color: #666;">Technical settings for the accessibility widget.</p>
                            <a href="?post_type=letter&page=rts-settings&tab=advanced&subtab=accessibility" class="button">Configure →</a>
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if (class_exists('RTS_Import_Export')): ?>
                    <tr>
                        <td style="padding: 15px;">
                            <h3 style="margin: 0 0 5px 0;">📤 Import / Export Data</h3>
                            <p style="margin: 0 0 10px 0; color: #666;">Bulk import or export letters and settings.</p>
                            <a href="?post_type=letter&page=rts-settings&tab=advanced&subtab=import_export" class="button">Open →</a>
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <?php if (class_exists('RTS_Background_Processor')): ?>
                    <tr style="background: #f9f9f9;">
                        <td style="padding: 15px;">
                            <h3 style="margin: 0 0 5px 0;">⚙️ Background Processor</h3>
                            <p style="margin: 0 0 10px 0; color: #666;">Manage bulk operations and background tasks.</p>
                            <a href="?post_type=letter&page=rts-settings&tab=advanced&subtab=bulk_processor" class="button">Open →</a>
                        </td>
                    </tr>
                    <?php endif; ?>
                    
                    <tr>
                        <td style="padding: 15px;">
                            <h3 style="margin: 0 0 5px 0;">🔍 Debug Logs & System Status</h3>
                            <p style="margin: 0 0 10px 0; color: #666;">View technical logs and system information.</p>
                            <a href="?post_type=letter&page=rts-settings&tab=advanced&subtab=debug" class="button">Open →</a>
                        </td>
                    </tr>
                </table>
                
                <p style="margin-top: 20px;">
                    <a href="?post_type=letter&page=rts-settings&tab=general" class="button">← Back to Main Settings</a>
                </p>
            </div>
            <?php
            return;
        }
        
        // Render specific advanced subtabs
        switch ($subtab) {
            case 'preview':
                if (class_exists('RTS_Admin_Preview')) {
                    echo '<p style="margin: 20px 0;"><a href="?post_type=letter&page=rts-settings&tab=advanced&subtab=menu" class="button">← Back to Advanced Menu</a></p>';
                    RTS_Admin_Preview::get_instance()->render_preview_page();
                }
                break;
                
            case 'accessibility':
                if (class_exists('RTS_Accessibility_Toolkit')) {
                    echo '<p style="margin: 20px 0;"><a href="?post_type=letter&page=rts-settings&tab=advanced&subtab=menu" class="button">← Back to Advanced Menu</a></p>';
                    RTS_Accessibility_Toolkit::get_instance()->render_settings_page();
                }
                break;
                
            case 'import_export':
                if (class_exists('RTS_Import_Export')) {
                    echo '<p style="margin: 20px 0;"><a href="?post_type=letter&page=rts-settings&tab=advanced&subtab=menu" class="button">← Back to Advanced Menu</a></p>';
                    RTS_Import_Export::get_instance()->render_import_export_page();
                }
                break;
                
            case 'bulk_processor':
                if (class_exists('RTS_Background_Processor')) {
                    echo '<p style="margin: 20px 0;"><a href="?post_type=letter&page=rts-settings&tab=advanced&subtab=menu" class="button">← Back to Advanced Menu</a></p>';
                    RTS_Background_Processor::get_instance()->render_processor_page();
                }
                break;
                
            case 'debug':
                echo '<p style="margin: 20px 0;"><a href="?post_type=letter&page=rts-settings&tab=advanced&subtab=menu" class="button">← Back to Advanced Menu</a></p>';
                $this->render_debug_tab();
                break;
        }
    }

    private function render_debug_tab() {
        $recent = class_exists('RTS_Logger') ? RTS_Logger::get_recent(100) : [];
        ?>
        <div class="card" style="max-width:1100px; padding:16px;">
            <h2 style="margin-top:0;">System Status</h2>
            <table class="widefat striped" style="margin-top:15px;">
                <tr>
                    <td style="padding: 12px;"><strong>PHP Version</strong></td>
                    <td style="padding: 12px;"><?php echo PHP_VERSION; ?></td>
                    <td style="padding: 12px;"><?php echo version_compare(PHP_VERSION, '7.4', '>=') ? '✅' : '⚠️ 7.4+ recommended'; ?></td>
                </tr>
                <tr>
                    <td style="padding: 12px;"><strong>Memory Limit</strong></td>
                    <td style="padding: 12px;"><?php echo ini_get('memory_limit'); ?></td>
                    <td style="padding: 12px;"><?php echo wp_convert_hr_to_bytes(ini_get('memory_limit')) >= 268435456 ? '✅' : '⚠️ 256MB+ recommended'; ?></td>
                </tr>
                <tr>
                    <td style="padding: 12px;"><strong>Uploads Writable</strong></td>
                    <td style="padding: 12px;"><?php $u = wp_upload_dir(); echo is_writable($u['basedir']) ? 'Yes' : 'No'; ?></td>
                    <td style="padding: 12px;"><?php echo is_writable($u['basedir']) ? '✅' : '❌ Logging may fail'; ?></td>
                </tr>
            </table>
        </div>

        <div class="card" style="max-width:1100px; padding:16px; margin-top:20px;">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                <h2 style="margin:0;">Activity Logs</h2>
                <div>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field('rts_download_logs'); ?>
                        <button class="button" name="rts_download_logs" value="1">Download Full Log</button>
                    </form>
                    <form method="post" style="display:inline; margin-left:5px;">
                        <?php wp_nonce_field('rts_clear_logs'); ?>
                        <button class="button" name="rts_clear_logs" value="1" onclick="return confirm('Clear recent logs? This cannot be undone.');">Clear Recent</button>
                    </form>
                </div>
            </div>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:160px; padding: 12px;">Time</th>
                        <th style="width:80px; padding: 12px;">Level</th>
                        <th style="padding: 12px;">Message</th>
                        <th style="width:100px; padding: 12px;">Context</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent)) : ?>
                        <tr><td colspan="4" style="padding: 12px;">No recent logs found.</td></tr>
                    <?php else : ?>
                        <?php foreach (array_reverse($recent) as $row) : 
                            $level_class = 'rts-level-' . strtolower($row['level'] ?? 'info');
                        ?>
                            <tr>
                                <td style="padding: 12px;"><?php echo esc_html($row['time'] ?? ''); ?></td>
                                <td style="padding: 12px;"><span class="rts-badge <?php echo esc_attr($level_class); ?>"><?php echo esc_html($row['level'] ?? ''); ?></span></td>
                                <td style="padding: 12px;"><?php echo esc_html($row['message'] ?? ''); ?></td>
                                <td style="padding: 12px;"><small><?php echo esc_html($row['source'] ?? 'core'); ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <style>
                .rts-badge { padding: 2px 6px; border-radius: 3px; font-weight: bold; font-size: 11px; }
                .rts-level-error { background: #d63638; color: white; }
                .rts-level-warn { background: #f0b849; color: black; }
                .rts-level-info { background: #e5e5e5; color: #3c434a; }
            </style>
        </div>

        <div class="card" style="max-width:1100px; padding:16px; margin-top:20px;">
            <h2 style="margin-top:0;">Raw Log Viewer</h2>
            <?php
            $upload_dir = wp_upload_dir();
            $log_file = trailingslashit($upload_dir['basedir']) . 'rts-logs/rts.log';
            
            if (file_exists($log_file)) {
                $content = file_get_contents($log_file);
                $lines = array_slice(explode("\n", $content), -100);
                echo '<div style="background:#1d2327; color:#f0f0f0; padding:15px; border-radius:5px; font-family:monospace; max-height:400px; overflow:auto; font-size:12px;">';
                foreach ($lines as $line) {
                    if (!empty(trim($line))) echo htmlspecialchars($line) . "<br>";
                }
                echo '</div>';
            } else {
                echo '<p>Log file not found.</p>';
            }
            ?>
        </div>
        <?php
    }
}

// Initialize
new RTS_Admin_Settings();

} // end class_exists check
