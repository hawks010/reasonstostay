<?php
/**
 * Reasons to Stay - Enterprise Monitoring & Error Handling
 * Monitors system health, logs errors, tracks performance, and sends diagnostic emails.
 * Enhanced for high-traffic environments with Admin UI, Webhooks, Auto-Recovery, and Emergency Escalation.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RTS_Enterprise_Monitor')) {
    
class RTS_Enterprise_Monitor {
    
    private static $instance = null;
    
    // Default Configuration
    private $config = [
        'error_threshold' => 5,
        'webhook_url' => '',
        'anonymize_ip' => false,
        'emergency_email' => '',
        'sms_webhook' => ''
    ];

    // Severity Levels
    private $error_severity_map = [
        'PHP Fatal Error' => 'critical',
        'Database Error' => 'critical',
        'REST API Error' => 'warning',
        'Slow REST API Response' => 'warning',
        'Slow Page Load' => 'warning',
        'Health Check Failed' => 'critical',
        'REST API Auto-Recovery Failed' => 'critical',
        'High System Load' => 'warning'
    ];

    // Alert Cooldowns (Seconds)
    private $alert_cooldowns = [
        'critical' => 300,    // 5 minutes
        'warning'  => 1800,   // 30 minutes
        'info'     => 3600    // 1 hour
    ];
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // 1. Check if Monitoring is Disabled (Performance Optimization)
        if (get_option('rts_monitoring_disabled', false)) {
            if (is_admin()) {
                add_action('admin_menu', [$this, 'register_admin_page']);
                add_action('admin_init', [$this, 'handle_admin_actions']);
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-warning"><p><strong>RTS Monitoring is currently DISABLED.</strong> System is not tracking errors.</p></div>';
                });
            }
            return;
        }

        // Load config
        $saved_config = get_option('rts_monitor_config', []);
        $this->config = array_merge($this->config, $saved_config);

        // Define start time
        if (!defined('RTS_START_TIME')) {
            define('RTS_START_TIME', microtime(true));
        }

        // Register Public Health Endpoint
        add_action('rest_api_init', [$this, 'register_health_endpoint']);

        // Monitor REST API Performance & Errors
        add_filter('rest_request_before_callbacks', [$this, 'monitor_rest_request_start'], 10, 3);
        add_filter('rest_request_after_callbacks', [$this, 'monitor_rest_request_end'], 10, 3);
        
        // Daily health check
        add_action('rts_daily_health_check', [$this, 'run_health_check']);
        if (!wp_next_scheduled('rts_daily_health_check')) {
            wp_schedule_event(time(), 'daily', 'rts_daily_health_check');
        }

        // Log Rotation Schedule
        add_action('rts_cleanup_old_logs', [$this, 'cleanup_old_logs']);
        if (!wp_next_scheduled('rts_cleanup_old_logs')) {
            wp_schedule_event(time(), 'weekly', 'rts_cleanup_old_logs');
        }
        
        // Log critical PHP errors (Shutdown handler)
        add_action('shutdown', [$this, 'check_for_fatal_errors']);

        // Track Page Load Performance (Front-end)
        add_action('shutdown', [$this, 'track_page_load_time']);

        // Admin UI & Actions
        add_action('admin_menu', [$this, 'register_admin_page']);
        add_action('admin_init', [$this, 'handle_admin_actions']);
        
        // Admin Bar Status
        add_action('admin_bar_menu', [$this, 'add_admin_bar_status'], 999);

        // Initialize Defaults
        add_action('init', [$this, 'init_defaults']);

        // Help Tabs
        add_action('load-toplevel_page_rts-monitor', [$this, 'add_help_tabs']);
    }

    /**
     * Initialize default options if missing
     */
    public function init_defaults() {
        if (!get_option('rts_monitor_config')) {
            update_option('rts_monitor_config', [
                'error_threshold' => 5,
                'anonymize_ip' => true
            ], false);
        }
    }

    /**
     * Add Contextual Help
     */
    public function add_help_tabs() {
        $screen = get_current_screen();
        if (!$screen) return;

        $screen->add_help_tab([
            'id' => 'rts_monitor_guide',
            'title' => 'Monitoring Guide',
            'content' => '<p><strong>Overview:</strong> This system monitors PHP errors, database connectivity, REST API latency, and disk space.</p>' .
                         '<p><strong>Alerts:</strong> Configure webhooks (Slack/Teams) and emergency emails to receive notifications when error thresholds are exceeded.</p>' .
                         '<p><strong>Escalation:</strong> Critical errors trigger immediate alerts to the Emergency Email address.</p>'
        ]);
    }

    /**
     * Register a lightweight health check endpoint
     */
    public function register_health_endpoint() {
        register_rest_route('rts/v1', '/health', [
            'methods' => 'GET',
            'callback' => function() {
                // Add CORS headers for external monitoring
                header('Access-Control-Allow-Origin: *');
                header('Access-Control-Allow-Methods: GET');
                
                return [
                    'status' => 'ok', 
                    'timestamp' => time(),
                    'db_ok' => $this->test_database()
                ];
            },
            'permission_callback' => '__return_true'
        ]);
    }

    public function register_admin_page() {
        add_submenu_page(
            'edit.php?post_type=letter',
            'System Monitor',
            'Monitor',
            'manage_options',
            'rts-monitor',
            [$this, 'render_dashboard']
        );
    }

    /**
     * Admin Bar Status Indicator
     */
    public function add_admin_bar_status($wp_admin_bar) {
        if (!current_user_can('manage_options') || get_option('rts_monitoring_disabled', false)) return;
        
        $errors = $this->count_recent_errors(3600);
        $title = $errors > 0 ? "⚠ $errors errors/hr" : "✓ RTS OK";
        $color = $errors > 10 ? '#d63638' : ($errors > 0 ? '#f0b849' : '#46b450');
        
        $wp_admin_bar->add_node([
            'id' => 'rts-monitor-status',
            'title' => $title,
            'href' => admin_url('edit.php?post_type=letter&page=rts-monitor'),
            'meta' => ['style' => "background-color: $color !important; color: white !important;"]
        ]);
    }

    /**
     * Handle Admin Actions (Clear Log, Export, Save Config)
     */
    public function handle_admin_actions() {
        if (!current_user_can('manage_options')) return;

        // Save Configuration
        if (isset($_POST['rts_save_monitor_config']) && check_admin_referer('rts_monitor_config')) {
            $new_config = [
                'error_threshold' => absint($_POST['error_threshold']),
                'webhook_url' => esc_url_raw($_POST['webhook_url']),
                'anonymize_ip' => isset($_POST['anonymize_ip']),
                'emergency_email' => !empty($_POST['emergency_email']) ? sanitize_email($_POST['emergency_email']) : '',
                'sms_webhook' => esc_url_raw($_POST['sms_webhook'])
            ];
            update_option('rts_monitor_config', $new_config, false);
            $this->config = $new_config;

            // Save External Monitors
            $monitors = array_filter(array_map('trim', explode("\n", $_POST['external_monitors'])));
            $monitors = array_filter($monitors, function($url) { return filter_var($url, FILTER_VALIDATE_URL); });
            update_option('rts_external_monitors', $monitors, false);

            // Save Ignore List
            $ignore = array_filter(array_map('trim', explode(',', $_POST['ignore_errors'])));
            update_option('rts_ignore_errors', $ignore, false);

            add_settings_error('rts_monitor', 'config_saved', 'Configuration saved.', 'updated');
        }

        // Toggle Monitoring
        if (isset($_POST['rts_toggle_monitoring']) && check_admin_referer('rts_toggle_monitoring')) {
            $current = get_option('rts_monitoring_disabled', false);
            update_option('rts_monitoring_disabled', !$current);
            wp_safe_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
            exit;
        }

        // Clear Logs
        if (isset($_POST['rts_clear_logs']) && check_admin_referer('rts_clear_logs','rts_clear_logs_nonce')) {
            $this->clear_error_log();
            add_settings_error('rts_monitor', 'logs_cleared', 'Error logs cleared.', 'updated');
        }

        // Export Logs CSV
        if (isset($_POST['rts_export_logs']) && check_admin_referer('rts_export_logs','rts_export_logs_nonce')) {
            $this->export_logs_csv();
        }

        // Export Logs JSON
        if (isset($_POST['rts_export_logs_json']) && check_admin_referer('rts_export_logs_json','rts_export_logs_json_nonce')) {
            $this->export_logs_json();
        }
        
        // Manual External Ping
        if (isset($_POST['rts_ping_monitors']) && check_admin_referer('rts_ping_monitors')) {
            $this->ping_external_monitor();
            add_settings_error('rts_monitor', 'ping_sent', 'External monitors pinged.', 'updated');
        }

        // Run Manual Health Check
        if (isset($_POST['rts_run_health_check']) && check_admin_referer('rts_run_health_check_nonce')) {
            $this->run_health_check();
            add_settings_error('rts_monitor', 'health_check_run', 'Health check triggered manually.', 'updated');
        }
    }

    /**
     * Start timer for REST requests
     */
    public function monitor_rest_request_start($response, $handler, $request) {
        if (strpos($request->get_route(), '/rts/v1/') === 0) {
            $request->set_param('_rts_start', microtime(true));
        }
        return $response;
    }

    /**
     * End timer, check errors, update average performance metrics
     */
    public function monitor_rest_request_end($response, $handler, $request) {
        if (strpos($request->get_route(), '/rts/v1/') !== 0) {
            return $response;
        }
        
        $start = $request->get_param('_rts_start');
        $duration = $start ? round((microtime(true) - $start) * 1000, 2) : 0; 

        // Update Average Response Time (Rolling Average)
        $this->update_average_performance($duration);

        if (is_wp_error($response)) {
            $this->log_error('REST API Error', [
                'endpoint' => $request->get_route(),
                'method' => $request->get_method(),
                'error_code' => $response->get_error_code(),
                'error_message' => $response->get_error_message(),
                'duration' => $duration . 'ms',
                'user_ip' => $this->get_client_ip()
            ]);
        }
        
        if ($duration > 1000) {
            $this->log_error('Slow REST API Response', [
                'endpoint' => $request->get_route(),
                'duration' => $duration . 'ms',
                'params' => $request->get_params()
            ]);
        }
        
        return $response;
    }

    /**
     * Update rolling average for API performance
     */
    private function update_average_performance($duration) {
        $stats = get_option('rts_api_stats', ['count' => 0, 'total_ms' => 0, 'avg_ms' => 0]);
        
        $stats['count']++;
        $stats['total_ms'] += $duration;
        
        // Maintain rolling window to prevent overflow
        if ($stats['count'] > 10000) {
            $stats['count'] = 5000;
            $stats['total_ms'] = $stats['avg_ms'] * 5000;
        }
        
        $stats['avg_ms'] = ($stats['count'] > 0) ? round($stats['total_ms'] / $stats['count'], 2) : 0;
        
        update_option('rts_api_stats', $stats, false);
    }
    
    /**
     * Log error with thresholds and alerts
     */
    public function log_error($error_type, $context = []) {
        // Check Ignore List
        $ignore_list = get_option('rts_ignore_errors', []);
        if (in_array($error_type, $ignore_list)) {
            return; 
        }

        $errors = get_option('rts_error_log', []);
        
        // Limit context size to prevent huge logs
        if (strlen(json_encode($context)) > 5000) {
            $context = ['error' => 'Context too large', 'original_error' => $error_type, 'truncated' => true];
        }

        // Add new error
        $errors[] = [
            'type' => $error_type,
            'context' => $context,
            'timestamp' => time()
        ];
        
        // Check Memory Usage relative to limit before processing large logs
        $limit = 100;
        if (count($errors) > 100) {
            $memory_limit = ini_get('memory_limit');
            $current_usage = memory_get_usage(true);
            $limit_bytes = wp_convert_hr_to_bytes($memory_limit);
            
            // If using >80% of available memory, be aggressive
            if ($limit_bytes > 0 && $current_usage > ($limit_bytes * 0.8)) {
                $limit = 50;
            }
            $errors = array_slice($errors, -$limit);
        }
        
        update_option('rts_error_log', $errors, false);
        
        // Alert check
        $recent_errors = $this->count_recent_errors(300); // 5 mins
        if ($recent_errors >= $this->config['error_threshold']) {
            $this->trigger_alert($error_type, $recent_errors);
        }

        // Critical Escalation
        if (isset($this->error_severity_map[$error_type]) && $this->error_severity_map[$error_type] === 'critical') {
            $this->send_emergency_alert($error_type);
        }
    }
    
    public function check_for_fatal_errors() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $this->log_error('PHP Fatal Error', [
                'message' => $error['message'],
                'file' => $error['file'],
                'line' => $error['line']
            ]);
        }

        // Check for DB errors on shutdown if any occurred during request
        global $wpdb;
        if (!empty($wpdb->last_error)) {
            $this->log_error('Database Error', [
                'error' => $wpdb->last_error,
                'query' => $wpdb->last_query
            ]);
        }
    }

    public function track_page_load_time() {
        if (is_admin() || defined('DOING_AJAX') || defined('REST_REQUEST')) return;

        $duration = microtime(true) - RTS_START_TIME;
        
        if ($duration > 3.0) {
            $this->log_error('Slow Page Load', [
                'url' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                'duration' => round($duration, 2) . 's',
                'memory' => size_format(memory_get_peak_usage(true))
            ]);
        }
    }
    
    private function count_recent_errors($seconds) {
        $errors = get_option('rts_error_log', []);
        $cutoff = time() - $seconds;
        $count = 0;
        foreach ($errors as $error) {
            if ($error['timestamp'] > $cutoff) $count++;
        }
        return $count;
    }
    
    /**
     * Trigger Alerts (Email + Webhook) with Throttling
     */
    private function trigger_alert($type, $count) {
        $severity = $this->error_severity_map[$type] ?? 'info';
        $cooldown = $this->alert_cooldowns[$severity] ?? 3600;
        
        $last_alerts = get_option('rts_last_alerts', []);
        $last_sent = $last_alerts[$severity] ?? 0;

        if ((time() - $last_sent) < $cooldown) {
            return; 
        }
        
        $last_alerts[$severity] = time();
        update_option('rts_last_alerts', $last_alerts, false);
        
        // Email
        $to = get_option('rts_notify_email', get_option('admin_email'));
        
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            error_log('RTS Alert: Invalid notification email address: ' . $to);
            return;
        }

        $subject = "[ALERT][$severity] $type detected ($count recent errors)";
        $message = "Multiple errors detected on " . get_site_url() . "\nType: $type\nSeverity: $severity\nCount (5m): $count";
        
        $email_sent = wp_mail($to, $subject, $message);
        
        if (!$email_sent) {
            // Log locally but don't re-trigger alert loop
            error_log('RTS Alert: Failed to send email notification to ' . $to);
            return; 
        }
        
        // Webhook
        if (!empty($this->config['webhook_url'])) {
            if (filter_var($this->config['webhook_url'], FILTER_VALIDATE_URL)) {
                wp_remote_post($this->config['webhook_url'], [
                    'body' => json_encode([
                        'text' => "🚨 *System Alert*: $type detected on " . get_bloginfo('name'),
                        'attachments' => [[
                            'color' => ($severity === 'critical' ? '#d63638' : '#f0b849'),
                            'fields' => [
                                ['title' => 'Error Type', 'value' => $type, 'short' => true],
                                ['title' => 'Severity', 'value' => strtoupper($severity), 'short' => true],
                                ['title' => 'Recent Count', 'value' => $count, 'short' => true],
                                ['title' => 'Time', 'value' => current_time('mysql'), 'short' => true]
                            ]
                        ]]
                    ]),
                    'headers' => ['Content-Type' => 'application/json'],
                    'blocking' => false
                ]);
            } else {
                $this->log_error('Invalid Webhook URL', ['url' => $this->config['webhook_url']]);
            }
        }
    }

    /**
     * Send Emergency Alert (Escalation)
     */
    private function send_emergency_alert($error_type) {
        // Check if already sent recently (prevent spam)
        $last_sms = get_option('rts_last_sms_alert', 0);
        if ((time() - $last_sms) < 300) { // 5 minutes
            return;
        }

        if (!empty($this->config['emergency_email'])) {
            wp_mail(
                $this->config['emergency_email'], 
                '[CRITICAL ESCALATION] ' . $error_type, 
                "Critical error detected on " . get_site_url() . ". Please investigate immediately."
            );
        }

        if (!empty($this->config['sms_webhook'])) {
            update_option('rts_last_sms_alert', time());

            $sms_data = [
                'To' => '+1234567890', // Configurable placeholder
                'Body' => "[CRITICAL] $error_type on " . get_bloginfo('name') . " - " . home_url()
            ];
            wp_remote_post($this->config['sms_webhook'], [
                'body' => json_encode($sms_data),
                'blocking' => false,
                'headers' => ['Content-Type' => 'application/json'],
                'timeout' => 3
            ]);
        }
    }

    /**
     * Render Admin Dashboard
     */
    public function render_dashboard() {
        if (!current_user_can('manage_options')) return;
        
        $is_disabled = get_option('rts_monitoring_disabled', false);
        settings_errors('rts_monitor');
        
        $stats = $this->get_system_stats();
        $db_size = $this->get_database_size();
        $errors = $this->get_error_log(100);
        $api_stats = get_option('rts_api_stats', ['count' => 0, 'avg_ms' => 0]);
        $backup_status = $this->check_backup_status();
        $trends = $this->analyze_error_trends();
        ?>
        <div class="wrap">
            <h1>System Monitor</h1>
            
            <?php if ($is_disabled): ?>
                <div class="notice notice-warning inline"><p>Monitoring is currently <strong>DISABLED</strong>.</p></div>
            <?php endif; ?>

            <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px;">
                <!-- Main Column -->
                <div>
                    <!-- Stats Cards -->
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 20px;">
                        <div class="card" style="padding: 15px; border-left: 4px solid #2271b1;">
                            <h3>Total Views</h3>
                            <p style="font-size: 24px; margin: 0;"><?php echo number_format($stats['views']); ?></p>
                        </div>
                        <div class="card" style="padding: 15px; border-left: 4px solid <?php echo $stats['disk_space']['healthy'] ? '#46b450' : '#d63638'; ?>;">
                            <h3>Disk Space</h3>
                            <p style="font-size: 14px; margin: 0;"><?php echo esc_html($stats['disk_space']['message']); ?></p>
                        </div>
                        <div class="card" style="padding: 15px; border-left: 4px solid #f0b849;">
                            <h3>DB Size</h3>
                            <p style="font-size: 24px; margin: 0;"><?php echo esc_html($db_size); ?></p>
                        </div>
                        <div class="card" style="padding: 15px; border-left: 4px solid #9b59b6;">
                            <h3>Avg API Time</h3>
                            <p style="font-size: 24px; margin: 0;"><?php echo esc_html($api_stats['avg_ms']); ?>ms</p>
                        </div>
                    </div>

                    <!-- Trends -->
                    <?php if (!empty($trends['most_common'])): ?>
                    <div class="card" style="padding: 15px; margin-bottom: 20px;">
                        <h3>Error Trends</h3>
                        <p><strong>Errors Last Hour:</strong> <?php echo intval($trends['hourly_rate']); ?></p>
                        <p><strong>Critical Errors:</strong> <span style="color:#d63638;"><?php echo intval($trends['critical_count']); ?></span></p>
                        <p><strong>Avg Response:</strong> <?php echo esc_html($trends['avg_response_time']); ?></p>
                        <p><strong>Total Requests:</strong> <?php echo intval($trends['total_requests']); ?></p>
                        <p><strong>Top Issues:</strong> 
                            <?php 
                            $top = array_slice($trends['most_common'], 0, 3);
                            foreach($top as $k => $v) echo esc_html("$k ($v), ");
                            ?>
                        </p>
                    </div>
                    <?php endif; ?>

                    <!-- Error Log -->
                    <div class="card" style="padding: 0;">
                        <div style="padding: 15px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center;">
                            <h2 style="margin: 0;">Recent Errors</h2>
                            <div>
                                <form method="post" style="display: inline;">
                                    <?php wp_nonce_field('rts_export_logs', 'rts_export_logs_nonce'); ?>
                                    <button name="rts_export_logs" class="button button-secondary">Export CSV</button>
                                </form>
                                <form method="post" style="display: inline; margin-left: 5px;">
                                    <?php wp_nonce_field('rts_export_logs_json', 'rts_export_logs_json_nonce'); ?>
                                    <button name="rts_export_logs_json" class="button button-secondary">Export JSON</button>
                                </form>
                                <form method="post" style="display: inline; margin-left: 5px;">
                                    <?php wp_nonce_field('rts_clear_logs', 'rts_clear_logs_nonce'); ?>
                                    <button name="rts_clear_logs" class="button" onclick="return confirm('Clear all logs?')">Clear</button>
                                </form>
                            </div>
                        </div>
                        <table class="widefat striped" style="border: none;">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>Type</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($errors)): ?>
                                    <tr><td colspan="3" style="padding: 20px; text-align: center;">No errors logged. System healthy.</td></tr>
                                <?php else: ?>
                                    <?php foreach (array_reverse($errors) as $error): ?>
                                    <tr>
                                        <td style="white-space: nowrap;"><?php echo date('Y-m-d H:i:s', $error['timestamp']); ?></td>
                                        <td><strong><?php echo esc_html($error['type']); ?></strong></td>
                                        <td>
                                            <?php 
                                            if (!empty($error['context'])) {
                                                echo '<details><summary style="cursor: pointer; color: #2271b1;">View Context</summary>';
                                                echo '<pre style="background: #f0f0f1; padding: 10px; overflow: auto; margin-top: 5px;">';
                                                
                                                // Validate JSON before output
                                                $json_data = json_encode($error['context'], JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                                                if (json_last_error() === JSON_ERROR_NONE) {
                                                    echo esc_html(strip_tags($json_data));
                                                } else {
                                                    echo 'Invalid Data';
                                                }
                                                
                                                echo '</pre></details>';
                                            }
                                            ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Sidebar -->
                <div>
                    <!-- Status Toggle -->
                    <div class="card" style="padding: 15px; margin-top: 0; background: <?php echo $is_disabled ? '#fff' : '#e7f7ed'; ?>; border: 1px solid #c3c4c7;">
                        <form method="post">
                            <?php wp_nonce_field('rts_toggle_monitoring'); ?>
                            <h3 style="margin-top:0;">Status: <?php echo $is_disabled ? '🔴 Disabled' : '🟢 Enabled'; ?></h3>
                            <button class="button <?php echo $is_disabled ? 'button-primary' : ''; ?>" name="rts_toggle_monitoring">
                                <?php echo $is_disabled ? 'Enable Monitoring' : 'Disable Monitoring'; ?>
                            </button>
                        </form>
                    </div>

                    <!-- Quick Actions -->
                    <div class="card" style="padding: 15px; margin-top: 20px;">
                        <h3 style="margin-top:0;">Quick Actions</h3>
                        <form method="post">
                            <?php wp_nonce_field('rts_run_health_check_nonce', '_wpnonce'); ?>
                            <button name="rts_run_health_check" class="button button-secondary" style="width:100%; margin-bottom:10px;">
                                Run Health Check Now
                            </button>
                        </form>
                        <a href="<?php echo admin_url('tools.php?page=site-health'); ?>" class="button button-secondary" style="width:100%;">
                            WordPress Site Health
                        </a>
                    </div>

                    <!-- Configuration -->
                    <div class="card" style="padding: 15px; margin-top: 20px;">
                        <h2 style="margin-top: 0;">Configuration</h2>
                        <form method="post">
                            <?php wp_nonce_field('rts_monitor_config'); ?>
                            <p>
                                <label>Error Threshold (5min)</label><br>
                                <input type="number" name="error_threshold" value="<?php echo esc_attr($this->config['error_threshold']); ?>" class="widefat">
                            </p>
                            <p>
                                <label>Webhook URL (Slack/Teams)</label><br>
                                <input type="url" name="webhook_url" value="<?php echo esc_attr($this->config['webhook_url']); ?>" class="widefat">
                            </p>
                            <p>
                                <label>Emergency Email (Escalation)</label><br>
                                <input type="email" name="emergency_email" value="<?php echo esc_attr($this->config['emergency_email'] ?? ''); ?>" class="widefat">
                            </p>
                            <p>
                                <label>SMS Webhook (Twilio/etc)</label><br>
                                <input type="url" name="sms_webhook" value="<?php echo esc_attr($this->config['sms_webhook'] ?? ''); ?>" class="widefat">
                            </p>
                            <p>
                                <label>External Monitors (One per line)</label><br>
                                <textarea name="external_monitors" rows="3" class="widefat"><?php 
                                    echo esc_textarea(implode("\n", get_option('rts_external_monitors', []))); 
                                ?></textarea>
                            </p>
                            <p>
                                <label>Ignore Errors (Comma separated)</label><br>
                                <input type="text" name="ignore_errors" value="<?php echo esc_attr(implode(', ', get_option('rts_ignore_errors', []))); ?>" class="widefat">
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" name="anonymize_ip" <?php checked($this->config['anonymize_ip']); ?>>
                                    Anonymize IPs in Logs
                                </label>
                            </p>
                            <button name="rts_save_monitor_config" class="button button-primary">Save Config</button>
                        </form>
                        
                        <hr>
                        
                        <form method="post">
                            <?php wp_nonce_field('rts_ping_monitors'); ?>
                            <button name="rts_ping_monitors" class="button button-secondary">Ping External Monitors</button>
                        </form>
                    </div>

                    <!-- Health Check Status -->
                    <div class="card" style="padding: 15px; margin-top: 20px;">
                        <h2 style="margin-top: 0;">Health Status</h2>
                        <ul style="list-style: none; margin: 0; padding: 0;">
                            <li style="margin-bottom: 10px; display: flex; justify-content: space-between;">
                                <span>REST API</span>
                                <?php echo $this->test_rest_api() ? '<span style="color: #46b450;">✔ OK</span>' : '<span style="color: #d63638;">✘ Fail</span>'; ?>
                            </li>
                            <li style="margin-bottom: 10px; display: flex; justify-content: space-between;">
                                <span>Database</span>
                                <?php echo $this->test_database() ? '<span style="color: #46b450;">✔ OK</span>' : '<span style="color: #d63638;">✘ Fail</span>'; ?>
                            </li>
                            <li style="margin-bottom: 10px; display: flex; justify-content: space-between;">
                                <span>Backup Status</span>
                                <?php echo $backup_status['ok'] ? '<span style="color: #46b450;">✔ Active</span>' : '<span style="color: #f0b849;">⚠ Unknown</span>'; ?>
                            </li>
                            <li style="margin-bottom: 10px; display: flex; justify-content: space-between;">
                                <span>PHP Version</span>
                                <span><?php echo PHP_VERSION; ?></span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Export Logs to CSV
     */
    private function export_logs_csv() {
        if (!wp_verify_nonce($_REQUEST['rts_export_logs_nonce'] ?? '', 'rts_export_logs')) {
            wp_die('Security check failed');
        }

        // Limit to last 1000 errors to prevent memory exhaustion
        $errors = array_slice(get_option('rts_error_log', []), -1000);
        $filename = 'rts-error-log-' . date('Y-m-d') . '.csv';
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['Timestamp', 'Type', 'Context']);
        
        foreach ($errors as $error) {
            fputcsv($fp, [
                date('Y-m-d H:i:s', $error['timestamp']),
                sanitize_text_field($error['type']),
                wp_json_encode($error['context'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)
            ]);
        }
        
        fclose($fp);
        exit;
    }

    /**
     * Export Logs to JSON
     */
    private function export_logs_json() {
        if (!wp_verify_nonce($_REQUEST['rts_export_logs_json_nonce'] ?? '', 'rts_export_logs_json')) {
            wp_die('Security check failed');
        }

        $errors = array_slice(get_option('rts_error_log', []), -1000);
        $filename = 'rts-error-log-' . date('Y-m-d') . '.json';
        
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo wp_json_encode($errors, JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        exit;
    }

    private function get_database_size() {
        global $wpdb;
        $size = wp_cache_get('rts_database_size');
        if (false === $size) {
            $size = $wpdb->get_var("
                SELECT SUM(data_length + index_length) 
                FROM information_schema.TABLES 
                WHERE table_schema = '{$wpdb->dbname}'
                GROUP BY table_schema
            ");
            wp_cache_set('rts_database_size', $size, '', 3600); // Cache for 1 hour
        }
        return $size ? size_format($size) : 'Unknown';
    }

    private function check_database_growth() {
        global $wpdb;
        $size = $wpdb->get_var("SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = '{$wpdb->dbname}'");
        
        // Log warning if DB > 100MB
        if ($size > 100 * 1024 * 1024) {
            $this->log_error('Database Large', ['size' => size_format($size)]);
        }
    }

    private function check_system_load() {
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            if ($load[0] > 4.0) { // Load average > 4
                $this->log_error('High System Load', ['load' => $load]);
            }
        }
    }

    private function test_rest_api() {
        // Check transient cache first
        $cache_key = 'rts_rest_api_health';
        $cached = get_transient($cache_key);
        if (false !== $cached) {
            return $cached === 'ok';
        }

        // Use local health endpoint with cache busting
        $url = rest_url('rts/v1/health');
        $args = ['timeout' => 5, 'sslverify' => apply_filters('https_local_ssl_verify', false)];
        // Add cache buster
        $url = add_query_arg('t', time(), $url);
        
        $response = wp_remote_get($url, $args);
        $result = !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
        
        // Cache result for 1 minute
        set_transient($cache_key, $result ? 'ok' : 'fail', 60);
        
        return $result;
    }
    
    private function test_database() {
        global $wpdb;
        return (bool) $wpdb->get_var("SELECT 1");
    }

    private function attempt_rest_api_recovery() {
        flush_rewrite_rules(false);
        delete_transient('rts_rest_api_test');
        delete_transient('rts_rest_api_health');
        
        if ($this->test_rest_api()) {
            $this->log_error('REST API Auto-Recovery Successful', []);
        } else {
            $this->log_error('REST API Auto-Recovery Failed', []);
        }
    }

    /**
     * Check for common backup plugins status
     */
    private function check_backup_status() {
        $active_backup = false;
        $last_run = 'Never';

        // 1. Check UpdraftPlus
        $updraft = get_option('updraft_last_backup');
        if (!empty($updraft)) {
            $active_backup = true;
            $last_run = 'Updraft Active'; 
        }

        return ['ok' => $active_backup, 'info' => $last_run];
    }

    private function check_disk_space() {
        $path = ABSPATH;
        if (!function_exists('disk_free_space')) return ['healthy' => true, 'message' => 'Unknown'];
        
        $free = disk_free_space($path);
        $total = disk_total_space($path);
        
        if ($free === false || $total === false) return ['healthy' => true, 'message' => 'Unknown'];
        
        $percent_free = round(($free / $total) * 100);
        $free_gb = round($free / 1024 / 1024 / 1024, 2);
        
        $status = "{$free_gb}GB Free ({$percent_free}%)";
        return ['healthy' => $percent_free > 10, 'message' => $status];
    }
    
    private function get_total_views() {
        global $wpdb;
        // Cache this expensive query
        $cache_key = 'rts_total_views';
        $cached = wp_cache_get($cache_key);
        if (false !== $cached) {
            return $cached;
        }

        $result = $wpdb->get_var("SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = 'view_count'");
        $total = (int) $result;
        
        wp_cache_set($cache_key, $total, '', 3600);
        return $total;
    }
    
    public function run_health_check() {
        $start_time = microtime(true);
        $issues = [];
        
        // Infrastructure Checks
        if (!$this->test_rest_api()) {
            $issues[] = "REST API unreachable.";
            $this->attempt_rest_api_recovery();
        }
        if (!$this->test_database()) $issues[] = "DB connection issues.";
        
        $backup = $this->check_backup_status();
        if (!$backup['ok']) $issues[] = "No active backup system detected.";

        $this->check_database_growth();
        $this->check_system_load();

        // Track health check duration
        $duration = round(microtime(true) - $start_time, 3);
        if ($duration > 10.0) {
            $this->log_error('Slow Health Check', [
                'duration' => $duration . 's',
                'issues_count' => count($issues)
            ]);
        }

        // Send Report if Issues
        if (!empty($issues)) {
            $severity = 'warning';
            $subject = "[HEALTH CHECK][$severity] Issues Found on " . get_site_url();
            $message = "Health check failed at " . current_time('mysql') . ":\n\n";
            $message .= implode("\n", $issues);
            $message .= "\n\nSystem URL: " . admin_url('admin.php?page=rts-monitor');
            
            wp_mail(get_option('admin_email'), $subject, $message);
            $this->trigger_alert('Health Check Failed', count($issues));
        }
    }
    
    private function get_client_ip() {
        if ($this->config['anonymize_ip']) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $salt = defined('AUTH_SALT') ? AUTH_SALT : 'rts_monitor_salt';
            return 'anonymized_' . substr(hash('sha256', $ip . $salt), 0, 16);
        }
        
        if (isset($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) return explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
    
    public function get_error_log($limit = 50) {
        $errors = get_option('rts_error_log', []);
        return array_slice($errors, -$limit);
    }
    
    public function clear_error_log() {
        delete_option('rts_error_log');
        delete_option('rts_last_alert_sent');
        delete_option('rts_last_alerts');
    }

    public function cleanup_old_logs() {
        $errors = get_option('rts_error_log', []);
        $cutoff = time() - (30 * DAY_IN_SECONDS);
        $new_errors = [];
        foreach ($errors as $error) {
            if ($error['timestamp'] > $cutoff) {
                $new_errors[] = $error;
            }
        }
        if (count($new_errors) < count($errors)) {
            update_option('rts_error_log', $new_errors, false);
        }
    }

    public function analyze_error_trends() {
        $errors = $this->get_error_log(1000);
        $trends = [
            'hourly_rate' => $this->count_recent_errors(3600),
            'daily_rate' => $this->count_recent_errors(86400),
            'most_common' => [],
            'critical_count' => 0,
            'avg_response_time' => 0,
            'total_requests' => 0
        ];
        
        if (!empty($errors)) {
            $types = array_column($errors, 'type');
            $trends['most_common'] = array_count_values($types);
            arsort($trends['most_common']);
            
            // Count critical errors
            foreach ($errors as $error) {
                $severity = $this->error_severity_map[$error['type']] ?? 'info';
                if ($severity === 'critical') $trends['critical_count']++;
            }
        }

        // Add performance stats
        $api_stats = get_option('rts_api_stats', ['count' => 0, 'avg_ms' => 0]);
        $trends['avg_response_time'] = $api_stats['avg_ms'] . 'ms';
        $trends['total_requests'] = $api_stats['count'];

        return $trends;
    }

    private function ping_external_monitor() {
        $monitors = get_option('rts_external_monitors', []);
        foreach ($monitors as $url) {
            wp_remote_get($url, ['blocking' => false, 'timeout' => 1]);
        }
    }

    public function get_system_stats() {
        return [
            'views' => $this->get_total_views(),
            'disk_space' => $this->check_disk_space()
        ];
    }

    /**
     * Static uninstall method for cleanup
     * Call this from main plugin uninstall.php if needed
     */
    public static function uninstall() {
        delete_option('rts_monitor_config');
        delete_option('rts_error_log');
        delete_option('rts_api_stats');
        delete_option('rts_monitoring_disabled');
        delete_option('rts_last_alert_sent');
        delete_option('rts_last_alerts');
        delete_option('rts_external_monitors');
        delete_option('rts_ignore_errors');
        delete_option('rts_last_sms_alert');
        wp_clear_scheduled_hook('rts_daily_health_check');
        wp_clear_scheduled_hook('rts_cleanup_old_logs');
    }
}

// Initialize
RTS_Enterprise_Monitor::get_instance();

} // end class_exists check
