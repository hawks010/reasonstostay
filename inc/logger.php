<?php
/**
 * Reasons to Stay - Lightweight Logger
 * Centralized logging system for RTS events and errors
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RTS_Logger')) {
    
class RTS_Logger {
    
    // Constants for options
    const OPTION_LAST_ERROR = 'rts_last_error_time';
    const TABLE_NAME = 'rts_logs';
    
    private static $instance = null;
    private $log_file;
    private $max_log_size = 5242880; // 5MB
    private $table_name;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . self::TABLE_NAME;
        
        // Set log file path
        $upload_dir = wp_upload_dir();
        $log_dir = $upload_dir['basedir'] . '/rts-logs';
        
        // Create logs directory if it doesn't exist
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            
            // Add .htaccess to protect logs
            $htaccess = $log_dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Deny from all\n");
            }
        }
        
        $this->log_file = $log_dir . '/rts.log';
        
        // Create log file if it doesn't exist
        if (!file_exists($this->log_file)) {
            touch($this->log_file);
        }
        
        // Create database table immediately if in admin
        if (is_admin()) {
            $this->maybe_create_table();
        } else {
            // Otherwise, create on admin_init hook
            add_action('admin_init', [$this, 'maybe_create_table'], 5);
        }
        
        // Schedule log rotation
        add_action('admin_init', [$this, 'schedule_log_rotation']);
        add_action('rts_rotate_logs', [$this, 'rotate_logs']);
        
        // Write initialization log
        $this->write_to_file("[" . current_time('mysql') . "] [INFO] RTS Logger initialized\n");
    }
    
    /**
     * Create logs table if it doesn't exist
     */
    public function maybe_create_table() {
        global $wpdb;
        
        // Check if table already exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$this->table_name}'");
        
        if ($table_exists === $this->table_name) {
            return; // Table already exists
        }
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            time datetime NOT NULL,
            level varchar(20) NOT NULL,
            message text NOT NULL,
            source varchar(50) DEFAULT 'core',
            context text,
            PRIMARY KEY  (id),
            KEY time (time),
            KEY level (level)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Log table creation
        $this->write_to_file("[" . current_time('mysql') . "] [INFO] RTS logs database table created\n");
    }
    
    /**
     * Schedule weekly log rotation
     */
    public function schedule_log_rotation() {
        if (!wp_next_scheduled('rts_rotate_logs')) {
            wp_schedule_event(time(), 'weekly', 'rts_rotate_logs');
        }
    }
    
    /**
     * Rotate logs if they get too large
     */
    public function rotate_logs() {
        if (!file_exists($this->log_file)) {
            return;
        }
        
        $file_size = filesize($this->log_file);
        
        if ($file_size > $this->max_log_size) {
            // Archive old log
            $archive_name = $this->log_file . '.' . date('Y-m-d-His') . '.old';
            rename($this->log_file, $archive_name);
            
            // Create new log file
            touch($this->log_file);
            
            // Clean up old archives (keep last 3)
            $this->cleanup_old_archives();
        }
    }
    
    /**
     * Clean up old log archives
     */
    private function cleanup_old_archives() {
        $log_dir = dirname($this->log_file);
        $archives = glob($log_dir . '/rts-system.log.*.old');
        
        if (count($archives) > 3) {
            // Sort by modification time
            usort($archives, function($a, $b) {
                return filemtime($a) - filemtime($b);
            });
            
            // Remove oldest archives
            $to_remove = array_slice($archives, 0, count($archives) - 3);
            foreach ($to_remove as $file) {
                @unlink($file);
            }
        }
    }
    
    /**
     * Log a message
     * 
     * @param string $level - info, warning, error, critical
     * @param string $message - The log message
     * @param array $context - Additional context data
     */
    public function log($level, $message, $context = []) {
        global $wpdb;
        
        $timestamp = current_time('mysql');
        $level_normalized = strtolower($level);
        
        // Map level names
        $level_map = [
            'critical' => 'error',
            'warning' => 'warn',
            'info' => 'info',
            'error' => 'error'
        ];
        
        $db_level = $level_map[$level_normalized] ?? 'info';
        
        // Extract source from context
        $source = isset($context['source']) ? $context['source'] : 'core';
        unset($context['source']);
        
        // Format context for database
        $context_json = !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES) : null;
        
        // Insert into database
        $wpdb->insert(
            $this->table_name,
            [
                'time' => $timestamp,
                'level' => $db_level,
                'message' => $message,
                'source' => $source,
                'context' => $context_json
            ],
            ['%s', '%s', '%s', '%s', '%s']
        );
        
        // Also write to file
        $level_upper = strtoupper($level);
        $context_str = !empty($context) ? ' | ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';
        $log_entry = sprintf(
            "[%s] [%s] %s%s\n",
            $timestamp,
            $level_upper,
            $message,
            $context_str
        );
        
        $this->write_to_file($log_entry);
        
        // Track last error time
        if ($db_level === 'error') {
            update_option(self::OPTION_LAST_ERROR, time());
        }
        
        // Also write critical errors to WordPress debug log
        if ($level_upper === 'CRITICAL' || $level_upper === 'ERROR') {
            error_log('RTS ' . $level_upper . ': ' . $message);
        }
    }
    
    /**
     * Convenience methods for different log levels
     */
    public function info($message, $context = []) {
        $this->log('info', $message, $context);
    }
    
    public function warning($message, $context = []) {
        $this->log('warning', $message, $context);
    }
    
    public function error($message, $context = []) {
        $this->log('error', $message, $context);
    }
    
    public function critical($message, $context = []) {
        $this->log('critical', $message, $context);
    }
    
    /**
     * Log letter submission
     */
    public function log_submission($letter_id, $status, $details = []) {
        $message = sprintf(
            'Letter submission - ID: %d, Status: %s',
            $letter_id,
            $status
        );
        
        $this->info($message, array_merge([
            'type' => 'submission',
            'letter_id' => $letter_id,
            'status' => $status
        ], $details));
    }
    
    /**
     * Log letter view
     */
    public function log_view($letter_id, $details = []) {
        $message = sprintf('Letter viewed - ID: %d', $letter_id);
        
        $this->info($message, array_merge([
            'type' => 'view',
            'letter_id' => $letter_id
        ], $details));
    }
    
    /**
     * Log security event
     */
    public function log_security($event_type, $details = []) {
        $message = sprintf('Security event: %s', $event_type);
        
        $this->warning($message, array_merge([
            'type' => 'security',
            'event' => $event_type,
            'ip' => $this->get_client_ip()
        ], $details));
    }
    
    /**
     * Log API error
     */
    public function log_api_error($endpoint, $error_message, $details = []) {
        $message = sprintf(
            'API Error - Endpoint: %s, Error: %s',
            $endpoint,
            $error_message
        );
        
        $this->error($message, array_merge([
            'type' => 'api_error',
            'endpoint' => $endpoint
        ], $details));
    }
    
    /**
     * Write to log file
     */
    private function write_to_file($entry) {
        // Check file size before writing
        if (file_exists($this->log_file) && filesize($this->log_file) > $this->max_log_size) {
            $this->rotate_logs();
        }
        
        // Write with error suppression
        @file_put_contents($this->log_file, $entry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Get client IP address
     */
    private function get_client_ip() {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }
    
    /**
     * Get recent logs for admin display
     * 
     * @param int $limit - Number of log entries to retrieve
     * @return array - Array of log entries with keys: time, level, message, source
     */
    public static function get_recent($limit = 100) {
        global $wpdb;
        $instance = self::get_instance();
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE_FORMAT(time, '%%Y-%%m-%%d %%H:%%i:%%s') as time, level, message, source 
                FROM {$instance->table_name} 
                ORDER BY id DESC 
                LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        
        return $results ?: [];
    }
    
    /**
     * Delete old logs (static method for admin-settings.php)
     * 
     * @param int $days - Delete logs older than this many days
     * @return int - Number of rows deleted
     */
    public static function delete_old($days = 30) {
        global $wpdb;
        $instance = self::get_instance();
        
        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$instance->table_name} WHERE time < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days
            )
        );
        
        return $deleted !== false ? $deleted : 0;
    }
    
    /**
     * Clear recent logs (static method for admin-settings.php)
     * Clears logs from the last 7 days
     * 
     * @return int - Number of rows deleted
     */
    public static function clear_recent() {
        global $wpdb;
        $instance = self::get_instance();
        
        // Clear last 7 days only (safety measure)
        $deleted = $wpdb->query(
            "DELETE FROM {$instance->table_name} WHERE time >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );
        
        // Also truncate the file
        $instance->clear_logs();
        
        return $deleted !== false ? $deleted : 0;
    }
    
    /**
     * Get recent logs for admin display (instance method, kept for compatibility)
     * 
     * @param int $lines - Number of lines to retrieve
     * @return array
     */
    public function get_recent_logs($lines = 100) {
        return self::get_recent($lines);
    }
    
    /**
     * Clear all logs (admin function)
     */
    public function clear_logs() {
        if (file_exists($this->log_file)) {
            @unlink($this->log_file);
            touch($this->log_file);
            return true;
        }
        return false;
    }
    
    /**
     * Get log statistics
     */
    public function get_stats() {
        if (!file_exists($this->log_file)) {
            return [
                'size' => 0,
                'lines' => 0,
                'last_modified' => null
            ];
        }
        
        $lines = @file($this->log_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        
        return [
            'size' => filesize($this->log_file),
            'size_formatted' => size_format(filesize($this->log_file)),
            'lines' => $lines ? count($lines) : 0,
            'last_modified' => filemtime($this->log_file)
        ];
    }
}

// Initialize
RTS_Logger::get_instance();

// Write a test log entry if this is the first initialization
if (is_admin() && !get_transient('rts_logger_initialized')) {
    set_transient('rts_logger_initialized', true, WEEK_IN_SECONDS);
    RTS_Logger::get_instance()->info('RTS Logger system activated', ['source' => 'system']);
}

} // end class_exists check

/**
 * Helper function for quick logging
 */
if (!function_exists('rts_log')) {
    function rts_log($level, $message, $context = []) {
        if (class_exists('RTS_Logger')) {
            RTS_Logger::get_instance()->log($level, $message, $context);
        }
    }
}
