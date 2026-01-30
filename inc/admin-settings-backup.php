<?php
/**
 * Reasons to Stay - Admin Settings
 * Consolidated settings for novice admins: email routing, CC testing, debug logs.
 * Security hardened and optimized.
 */

if (!defined('ABSPATH')) exit;

class RTS_Admin_Settings {

    public function __construct() {
        // Register after Analytics + Moderation so menu order stays clean.
        add_action('admin_menu', [$this, 'add_settings_page'], 40);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_notices', [$this, 'maybe_show_error_notice']);

        // Handle POST actions (Exports, Bulk Actions) early
        add_action('admin_init', [$this, 'handle_post_actions']);

        // CC + routing for outgoing mail
        add_filter('wp_mail', [$this, 'filter_wp_mail']);
    }

    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=letter',
            'Reasons to Stay Settings',
            'Settings',
            'manage_options',
            'rts-settings',
            [$this, 'render_settings_page']
        );
    }

    public function register_settings() {
        // General Settings Validation
        register_setting('rts_settings', 'rts_notify_email', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => get_option('admin_email'),
        ]);

        register_setting('rts_settings', 'rts_cc_enabled', [
            'type' => 'boolean',
            'sanitize_callback' => function($v){ return (int) (bool) $v; },
            'default' => 1,
        ]);

        // Custom validation for CC email
        register_setting('rts_settings', 'rts_cc_email', [
            'type' => 'string',
            'sanitize_callback' => function($value) {
                $email = sanitize_email($value);
                if (!is_email($email) && !empty($value)) {
                    add_settings_error(
                        'rts_cc_email',
                        'invalid_email',
                        'Please enter a valid email address for CC.',
                        'error'
                    );
                    return get_option('rts_cc_email', 'webmaster@inkfire.co.uk');
                }
                return $email;
            },
            'default' => 'webmaster@inkfire.co.uk',
        ]);

        register_setting('rts_settings', 'rts_debug_logging', [
            'type' => 'boolean',
            'sanitize_callback' => function($v){ return (int) (bool) $v; },
            'default' => 1,
        ]);

        register_setting('rts_settings', 'rts_test_mode', [
            'type' => 'boolean',
            'sanitize_callback' => function($v){ return (int) (bool) $v; },
            'default' => 0,
        ]);
    }

    public function handle_post_actions() {
        if (!current_user_can('manage_options')) return;

        // 1. Export Settings
        if (isset($_POST['rts_export_settings']) && check_admin_referer('rts_export_settings')) {
            $settings = [
                'rts_notify_email' => get_option('rts_notify_email'),
                'rts_cc_enabled' => get_option('rts_cc_enabled'),
                'rts_cc_email' => get_option('rts_cc_email'),
                'rts_debug_logging' => get_option('rts_debug_logging'),
                'rts_test_mode' => get_option('rts_test_mode'),
            ];
            
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="rts-settings-' . date('Y-m-d') . '.json"');
            echo json_encode($settings, JSON_PRETTY_PRINT);
            exit;
        }

        // 2. Import Settings (Hardened)
        if (isset($_POST['rts_restore_settings']) && check_admin_referer('rts_settings_restore')) {
            if (!empty($_FILES['rts_settings_file']['tmp_name'])) {
                $file = $_FILES['rts_settings_file'];
                
                // Validate Size (Max 1MB)
                if ($file['size'] > 1024 * 1024) {
                    add_settings_error('rts_settings', 'rts_import_fail', 'File too large (Max 1MB).', 'error');
                    return;
                }

                // Validate Type
                $allowed_mimes = ['application/json', 'text/json', 'text/plain'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $file['tmp_name']);
                finfo_close($finfo);

                if (!in_array($mime, $allowed_mimes)) {
                    add_settings_error('rts_settings', 'rts_import_fail', 'Invalid file type. JSON required.', 'error');
                    return;
                }

                $json = file_get_contents($file['tmp_name']);
                $data = json_decode($json, true);
                if (is_array($data)) {
                    foreach ($data as $key => $val) {
                        if (strpos($key, 'rts_') === 0) {
                            update_option($key, $val);
                        }
                    }
                    add_settings_error('rts_settings', 'rts_imported', 'Settings restored successfully.', 'updated');
                } else {
                    add_settings_error('rts_settings', 'rts_import_fail', 'Invalid JSON structure.', 'error');
                }
            }
        }

        // 3. Reset Settings
        if (isset($_POST['rts_reset_settings']) && check_admin_referer('rts_reset_settings')) {
            $defaults = [
                'rts_notify_email' => get_option('admin_email'),
                'rts_cc_enabled' => 1,
                'rts_cc_email' => 'webmaster@inkfire.co.uk',
                'rts_debug_logging' => 1,
                'rts_test_mode' => 0,
            ];
            
            foreach ($defaults as $key => $value) {
                update_option($key, $value);
            }
            
            add_settings_error('rts_settings', 'rts_reset', 'Settings reset to defaults.', 'updated');
        }

        // 4. Test Email
        if (isset($_POST['rts_test_email']) && check_admin_referer('rts_test_email')) {
            $to = sanitize_email($_POST['test_email'] ?? get_option('admin_email'));
            $subject = 'RTS Test Email - ' . date('Y-m-d H:i:s');
            $message = "This is a test email from the Reasons to Stay settings page.\n\nIf you see this, email sending is working.\nCheck CC headers if enabled.";
            $headers = ['X-RTS-Context: settings-test'];
            
            $sent = wp_mail($to, $subject, $message, $headers);
            
            if ($sent) {
                add_settings_error('rts_settings', 'email_test_sent', 'Test email sent successfully.', 'updated');
            } else {
                add_settings_error('rts_settings', 'email_test_failed', 'Failed to send test email.', 'error');
            }
        }

        // 5. Migrate Old Settings
        if (isset($_POST['rts_migrate_old']) && check_admin_referer('rts_migrate_old')) {
            $map = [
                'rts_admin_email' => 'rts_notify_email',
                'rts_enable_logging' => 'rts_debug_logging',
                'rts_cc_address' => 'rts_cc_email'
            ];
            $migrated = 0;
            foreach ($map as $old => $new) {
                $val = get_option($old);
                if ($val !== false) {
                    update_option($new, $val);
                    delete_option($old);
                    $migrated++;
                }
            }
            add_settings_error('rts_settings', 'rts_migrated', "Migrated $migrated legacy settings.", 'updated');
        }

        // 6. Bulk Log Actions (Delete Old)
        if (isset($_POST['rts_log_bulk_action']) && check_admin_referer('rts_log_bulk')) {
            $action = sanitize_text_field($_POST['rts_log_bulk_action']);
            if ($action === 'delete_old') {
                if (class_exists('RTS_Logger')) {
                    RTS_Logger::delete_old(30);
                    add_settings_error('rts_settings', 'logs_cleaned', 'Old logs deleted (30+ days).', 'success');
                }
            }
        }

        // 7. Clear Recent Logs (with Race Condition Lock)
        if (isset($_POST['rts_clear_logs']) && check_admin_referer('rts_clear_logs')) {
             $lock_key = 'rts_log_clear_lock';
             if (!get_transient($lock_key)) {
                 set_transient($lock_key, true, 5); // 5 second lock
                 if (class_exists('RTS_Logger')) RTS_Logger::clear_recent();
                 add_settings_error('rts_settings', 'logs_cleared', 'Recent logs cleared.', 'updated');
             } else {
                 add_settings_error('rts_settings', 'logs_locked', 'Another clear operation is in progress.', 'warning');
             }
        }

        // 8. Download Logs
        if (isset($_POST['rts_download_logs']) && check_admin_referer('rts_download_logs')) {
             $upload_dir = wp_upload_dir();
             $file = trailingslashit($upload_dir['basedir']) . 'rts-logs/rts.log';
             if (file_exists($file)) {
                 header('Content-Type: text/plain');
                 header('Content-Disposition: attachment; filename="rts-debug.log"');
                 readfile($file);
                 exit;
             }
        }
    }

    public function filter_wp_mail($args) {
        $test_mode = (bool) get_option('rts_test_mode', 0);

        if (!$test_mode) {
            $subj = isset($args['subject']) ? (string)$args['subject'] : '';
            $is_rts = false;
            
            // Expanded detection patterns (Case Insensitive)
            $rts_patterns = [
                'reasons to stay', 'rts', 'letter', 'submission',
                'content analyzer', 'moderation', 'approval'
            ];
            
            foreach ($rts_patterns as $pattern) {
                if (stripos($subj, $pattern) !== false) {
                    $is_rts = true;
                    break;
                }
            }
            
            // Context header check
            $headers = $args['headers'] ?? [];
            if (is_array($headers)) {
                foreach ($headers as $header) {
                    if (strpos(strtolower($header), 'x-rts-context') !== false) {
                        $is_rts = true;
                        break;
                    }
                }
            }
            
            if (!$is_rts) return $args;
        }

        $cc_enabled = (bool) get_option('rts_cc_enabled', 1);
        $cc_email = sanitize_email((string) get_option('rts_cc_email', 'webmaster@inkfire.co.uk'));

        if ($cc_enabled && is_email($cc_email)) {
            $headers = $args['headers'] ?? [];
            // Normalize to array
            if (!is_array($headers)) {
                $headers = explode("\n", str_replace("\r\n", "\n", $headers));
            }

            // Clean empty entries and check for duplicate CC
            $normalized_headers = [];
            $cc_exists = false;
            
            foreach ($headers as $h) {
                $h = trim($h);
                if (empty($h)) continue;
                
                $normalized_headers[] = $h;
                
                if (stripos($h, 'Cc:') === 0) {
                    // Check if specific email is already there
                    if (stripos($h, $cc_email) !== false) {
                        $cc_exists = true;
                    }
                }
            }

            if (!$cc_exists) {
                $normalized_headers[] = 'Cc: ' . $cc_email;
            }
            
            $args['headers'] = $normalized_headers;
        }

        return $args;
    }

    public function maybe_show_error_notice() {
        if (class_exists('RTS_Logger')) {
            $last = (int) get_option(RTS_Logger::OPTION_LAST_ERROR, 0);
            if (!$last) return;
            if ($last < (time() - DAY_IN_SECONDS)) return;

            $url = admin_url('edit.php?post_type=letter&page=rts-settings&tab=debug');
            ?>
            <div class="notice notice-warning is-dismissible">
                <p><strong>Reasons to Stay:</strong> We logged an issue recently. If something isn’t working as expected, check <a href="<?php echo esc_url($url); ?>">Debug Logs</a>.</p>
            </div>
            <?php
        }
    }

    private function get_tabs() {
        return [
            'general' => [
                'label' => 'General',
                'icon' => 'dashicons-admin-generic'
            ],
            'preview' => [
                'label' => 'Preview',
                'class_exists' => 'RTS_Admin_Preview'
            ],
            'accessibility' => [
                'label' => 'Accessibility',
                'class_exists' => 'RTS_Accessibility_Toolkit'
            ],
            'import_export' => [
                'label' => 'Import/Export',
                'class_exists' => 'RTS_Import_Export'
            ],
            'bulk_processor' => [
                'label' => 'Bulk Processor',
                'class_exists' => 'RTS_Background_Processor'
            ],
            'backup' => [
                'label' => 'Backup & Restore',
            ],
            'debug' => [
                'label' => 'Debug',
            ]
        ];
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;

        // Check for old legacy settings
        $this->check_for_old_settings();

        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'general';
        $tabs = $this->get_tabs();
        
        if (!array_key_exists($current_tab, $tabs)) {
            $current_tab = 'general';
        }
        
        // Show settings errors/updates
        settings_errors('rts_settings');
        ?>
        <div class="wrap">
            <h1>Settings</h1>
            
            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $key => $tab) : ?>
                    <a class="nav-tab <?php echo ($current_tab === $key) ? 'nav-tab-active' : ''; ?>" 
                       href="<?php echo esc_url(admin_url('edit.php?post_type=letter&page=rts-settings&tab=' . $key)); ?>">
                        <?php echo esc_html($tab['label']); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <?php
            switch ($current_tab) {
                case 'general':
                    $this->render_general_tab();
                    break;
                case 'preview':
                    if (class_exists('RTS_Admin_Preview')) RTS_Admin_Preview::get_instance()->render_preview_page();
                    else $this->render_missing_module('Preview');
                    break;
                case 'accessibility':
                    if (class_exists('RTS_Accessibility_Toolkit')) RTS_Accessibility_Toolkit::get_instance()->render_settings_page();
                    else $this->render_missing_module('Accessibility');
                    break;
                case 'import_export':
                    if (class_exists('RTS_Import_Export')) RTS_Import_Export::get_instance()->render_import_export_page();
                    else $this->render_missing_module('Import/Export');
                    break;
                case 'bulk_processor':
                    if (class_exists('RTS_Background_Processor')) RTS_Background_Processor::get_instance()->render_processor_page();
                    else $this->render_missing_module('Bulk Processor');
                    break;
                case 'backup':
                    $this->render_backup_tab();
                    break;
                case 'debug':
                    $this->render_debug_tab();
                    break;
            }
            ?>
        </div>
        <?php
    }

    private function render_missing_module($name) {
        echo '<div class="notice notice-warning inline"><p>' . esc_html($name) . ' module is missing or not loaded.</p></div>';
    }

    private function check_for_old_settings() {
        $old_options = ['rts_admin_email', 'rts_enable_logging', 'rts_cc_address'];
        $found = [];
        
        foreach ($old_options as $old) {
            if (get_option($old, false) !== false) {
                $found[] = $old;
            }
        }
        
        if (!empty($found)) {
            ?>
            <div class="notice notice-info is-dismissible">
                <p><strong>Legacy settings detected:</strong> <?php echo implode(', ', $found); ?></p>
                <form method="post" style="display:inline;">
                    <?php wp_nonce_field('rts_migrate_old'); ?>
                    <button type="submit" name="rts_migrate_old" value="1" class="button button-small">Migrate to New Format</button>
                </form>
            </div>
            <?php
        }
    }

    private function render_general_tab() {
        $notify_email = esc_attr(get_option('rts_notify_email', get_option('admin_email')));
        $cc_enabled = (int) get_option('rts_cc_enabled', 1);
        $cc_email = esc_attr(get_option('rts_cc_email', 'webmaster@inkfire.co.uk'));
        $debug = (int) get_option('rts_debug_logging', 1);
        $test_mode = (int) get_option('rts_test_mode', 0);
        ?>
        <form method="post" action="options.php">
            <?php settings_fields('rts_settings'); ?>
            
            <h2>General Configuration</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="rts_notify_email">Notification Email</label></th>
                    <td>
                        <input type="email" id="rts_notify_email" name="rts_notify_email" value="<?php echo $notify_email; ?>" class="regular-text" />
                        <p class="description">Recipients for alerts (new letters, monitoring warnings).</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">CC Routing</th>
                    <td>
                        <fieldset>
                            <label>
                                <input type="checkbox" name="rts_cc_enabled" value="1" <?php checked($cc_enabled, 1); ?> />
                                Enable CC for outgoing RTS emails
                            </label>
                            <br><br>
                            <input type="email" name="rts_cc_email" value="<?php echo $cc_email; ?>" class="regular-text" placeholder="cc@example.com" />
                            <p class="description">Copy all system emails to this address for auditing.</p>
                        </fieldset>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Debug Mode</th>
                    <td>
                        <label>
                            <input type="checkbox" name="rts_debug_logging" value="1" <?php checked($debug, 1); ?> />
                            Enable File Logging
                        </label>
                        <p class="description">Writes errors to <code>/wp-content/uploads/rts-logs/rts.log</code>.</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Global Test Mode</th>
                    <td>
                        <label style="color: #d63638;">
                            <input type="checkbox" name="rts_test_mode" value="1" <?php checked($test_mode, 1); ?> />
                            <strong>Apply to ALL WordPress Emails</strong>
                        </label>
                        <p class="description">Warning: If checked, the CC logic will apply to <em>every</em> email sent by this site, not just RTS letters.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>

        <hr>
        
        <h3>Test Email</h3>
        <p>Send a test email to verify your CC and routing settings.</p>
        <form method="post">
            <?php wp_nonce_field('rts_test_email'); ?>
            <input type="email" name="test_email" value="<?php echo esc_attr(get_option('admin_email')); ?>" class="regular-text">
            <button type="submit" name="rts_test_email" value="1" class="button">Send Test</button>
        </form>

        <hr>

        <div style="margin-top:20px; border:1px solid #d63638; padding:15px; border-radius:5px;">
            <h3 style="color:#d63638; margin-top:0;">Danger Zone</h3>
            <p>Reset all settings to their default values.</p>
            <form method="post" onsubmit="return confirm('Are you sure? This cannot be undone.');">
                <?php wp_nonce_field('rts_reset_settings'); ?>
                <button type="submit" name="rts_reset_settings" value="1" class="button button-secondary" style="color:#d63638; border-color:#d63638;">Reset Defaults</button>
            </form>
        </div>
        <?php
    }

    private function render_backup_tab() {
        ?>
        <div class="card" style="max-width:800px; padding:20px;">
            <h2 style="margin-top:0;">Settings Backup & Restore</h2>
            <p>Export your configuration to JSON or restore from a previous backup.</p>
            
            <hr>
            
            <h3>Export</h3>
            <form method="post">
                <?php wp_nonce_field('rts_export_settings'); ?>
                <button type="submit" name="rts_export_settings" value="1" class="button button-primary">
                    Download Settings JSON
                </button>
            </form>
            
            <hr>
            
            <h3>Restore</h3>
            <form method="post" enctype="multipart/form-data">
                <?php wp_nonce_field('rts_settings_restore'); ?>
                <p>
                    <label for="rts_settings_file">Select JSON file:</label><br>
                    <input type="file" name="rts_settings_file" accept=".json" required>
                </p>
                <p>
                    <button type="submit" name="rts_restore_settings" value="1" class="button button-secondary">
                        Upload & Restore
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    private function render_debug_tab() {
        $recent = class_exists('RTS_Logger') ? RTS_Logger::get_recent(100) : [];
        ?>
        <div class="card" style="max-width:1100px; padding:16px;">
            <h2 style="margin-top:0;">System Status</h2>
            <table class="widefat striped" style="margin-top:15px;">
                <tr>
                    <td><strong>PHP Version</strong></td>
                    <td><?php echo PHP_VERSION; ?></td>
                    <td><?php echo version_compare(PHP_VERSION, '7.4', '>=') ? '✅' : '⚠️ 7.4+ recommended'; ?></td>
                </tr>
                <tr>
                    <td><strong>Memory Limit</strong></td>
                    <td><?php echo ini_get('memory_limit'); ?></td>
                    <td><?php echo wp_convert_hr_to_bytes(ini_get('memory_limit')) >= 268435456 ? '✅' : '⚠️ 256MB+ recommended'; ?></td>
                </tr>
                <tr>
                    <td><strong>Uploads Writable</strong></td>
                    <td><?php $u = wp_upload_dir(); echo is_writable($u['basedir']) ? 'Yes' : 'No'; ?></td>
                    <td><?php echo is_writable($u['basedir']) ? '✅' : '❌ Logging may fail'; ?></td>
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
                        <?php wp_nonce_field('rts_log_bulk'); ?>
                        <input type="hidden" name="rts_log_bulk_action" value="delete_old">
                        <button class="button" onclick="return confirm('Delete logs older than 30 days?');">Cleanup Old</button>
                    </form>
                    <form method="post" style="display:inline; margin-left:5px;">
                        <?php wp_nonce_field('rts_clear_logs'); ?>
                        <button class="button" name="rts_clear_logs" value="1">Clear Recent</button>
                    </form>
                </div>
            </div>

            <table class="widefat striped">
                <thead>
                    <tr>
                        <th style="width:160px;">Time</th>
                        <th style="width:80px;">Level</th>
                        <th>Message</th>
                        <th style="width:100px;">Context</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent)) : ?>
                        <tr><td colspan="4">No recent logs found.</td></tr>
                    <?php else : ?>
                        <?php foreach (array_reverse($recent) as $row) : 
                            $level_class = 'rts-level-' . strtolower($row['level'] ?? 'info');
                        ?>
                            <tr>
                                <td><?php echo esc_html($row['time'] ?? ''); ?></td>
                                <td><span class="rts-badge <?php echo esc_attr($level_class); ?>"><?php echo esc_html($row['level'] ?? ''); ?></span></td>
                                <td><?php echo esc_html($row['message'] ?? ''); ?></td>
                                <td><small><?php echo esc_html($row['source'] ?? 'core'); ?></small></td>
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
?>