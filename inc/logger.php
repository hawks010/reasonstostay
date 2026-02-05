<?php
/**
 * Reasons to Stay - Lightweight Logger
 * Centralized logging system for RTS events and errors
 * * Features:
 * - Dual logging (Database + File)
 * - Atomic file writes with locking
 * - Automatic rotation and retention policies
 * - Secure context handling
 */

if (!defined('ABSPATH')) { exit; }

if (!class_exists('RTS_Logger')) {
    
class RTS_Logger {
    
    // Constants for options
    const OPTION_LAST_ERROR = 'rts_last_error_time';
    const TABLE_NAME = 'rts_logs';

    // Logging controls
    const OPTION_MIN_LEVEL      = 'rts_log_level_min';          // info|warning|error|critical
    const OPTION_DB_MIN_LEVEL   = 'rts_log_db_min_level';       // default: error
    const OPTION_INFO_SAMPLE    = 'rts_log_info_sample_rate';   // int, default: 50 (1 in 50)
    const OPTION_DEDUPE_WINDOW  = 'rts_log_dedupe_window';      // seconds, default: 60
    const OPTION_RETENTION_DB   = 'rts_log_retention_days_db';  // default: 7
    const OPTION_FILE_DAYS      = 'rts_log_file_retention_days';// default: 7
    const OPTION_LAST_DAILY_ROT = 'rts_log_last_daily_rotation';
    
    private static $instance = null;
    private $log_file;
    private $max_log_size = 5242880; // 5MB
    private $table_name;
    private $retention_days = 30;
    
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
        
        // Ensure log file permission safety
        $this->check_log_file_permissions();
        
        // Create database table immediately if in admin
        if (is_admin()) {
            $this->maybe_create_table();
        } else {
            // Otherwise, create on admin_init hook
            add_action('admin_init', [$this, 'maybe_create_table'], 5);
        }
        
        // Schedule log rotation and cleanup
        add_action('admin_init', [$this, 'schedule_maintenance']);
        add_action('rts_rotate_logs', [$this, 'perform_maintenance']);
        add_action('rts_log_dedupe_flush', [$this, 'flush_dedupe_summary']);
        
        // Write initialization log only once per request/session if needed
        if (!defined('RTS_LOGGER_INIT')) {
            define('RTS_LOGGER_INIT', true);
            // Optional: minimal startup log
        }
    }

    /**
     * Map levels to priorities.
     */
    private function level_priority(string $level): int {
        $level = strtolower($level);
        switch ($level) {
            case 'critical': return 4;
            case 'error':    return 3;
            case 'warning':  return 2;
            case 'info':
            default:         return 1;
        }
    }

    /**
     * Get minimum log level for this request (supports high-load circuit breaker).
     */
    private function get_min_level(): string {
        if (get_transient('rts_high_load')) {
            return 'error';
        }
        $min = (string) get_option(self::OPTION_MIN_LEVEL, 'warning');
        $min = strtolower($min);
        return in_array($min, ['info','warning','error','critical'], true) ? $min : 'warning';
    }

    /**
     * Get minimum DB log level.
     */
    private function get_db_min_level(): string {
        $min = (string) get_option(self::OPTION_DB_MIN_LEVEL, 'error');
        $min = strtolower($min);
        return in_array($min, ['info','warning','error','critical'], true) ? $min : 'error';
    }

    /**
     * Sample rate for info logs (1 in N). Use 1 to log everything.
     */
    private function get_info_sample_rate(): int {
        $n = (int) get_option(self::OPTION_INFO_SAMPLE, 50);
        return $n < 1 ? 1 : $n;
    }

    /**
     * Dedupe window in seconds.
     */
    private function get_dedupe_window(): int {
        $w = (int) get_option(self::OPTION_DEDUPE_WINDOW, 60);
        return $w < 10 ? 10 : $w;
    }

    /**
     * Write a dedupe summary log after the window expires.
     */
    public function flush_dedupe_summary(string $fingerprint): void {
        $key = 'rts_log_dedupe_' . $fingerprint;
        $data = get_transient($key);
        if (!is_array($data)) {
            return;
        }
        $count = isset($data['count']) ? (int) $data['count'] : 0;
        if ($count > 1) {
            $msg = sprintf('Previous event repeated %d times in %ds', $count, (int) ($data['window'] ?? $this->get_dedupe_window()));
            $ctx = [
                'source' => $data['source'] ?? 'dedupe',
                'fingerprint' => $fingerprint,
                'original_level' => $data['level'] ?? 'info',
            ];
            // Write summary at info level (file always, DB respects db_min_level).
            $this->log('info', $msg, $ctx);
        }
        delete_transient($key);
    }

    
    /**
     * Create logs table if it doesn't exist
     */
    public function maybe_create_table() {
        global $wpdb;
        
        // Check if table already exists using prepare for safety
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->table_name));
        
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
            context mediumtext,
            PRIMARY KEY  (id),
            KEY time (time),
            KEY level (level)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Log table creation
        $this->write_to_file(sprintf("[%s] [INFO] RTS logs database table created\n", current_time('mysql')));
    }
    
    /**
     * Ensure log file has restricted permissions
     */
    private function check_log_file_permissions() {
        if (file_exists($this->log_file)) {
            $perms = substr(sprintf('%o', fileperms($this->log_file)), -4);
            if ($perms !== '0640' && $perms !== '0644') {
                @chmod($this->log_file, 0640);
            }
        } else {
            // Create if missing
            touch($this->log_file);
            @chmod($this->log_file, 0640);
        }
    }
    
    /**
     * Schedule maintenance (rotation + db cleanup)
     */
    public function schedule_maintenance() {
        if (!wp_next_scheduled('rts_rotate_logs')) {
            wp_schedule_event(time(), 'daily', 'rts_rotate_logs');
        }
    }
    
    /**
     * Perform all maintenance tasks
     */
    public function perform_maintenance() {
        $this->rotate_daily();
        $this->rotate_logs();
        $this->cleanup_old_db_entries();
    }
    
    /**
     * Rotate logs daily (in addition to size-based rotation).
     */
    public function rotate_daily() {
        $today = gmdate('Y-m-d');
        $last = (string) get_option(self::OPTION_LAST_DAILY_ROT, '');
        if ($last === $today) {
            return;
        }

        if (file_exists($this->log_file) && filesize($this->log_file) > 0) {
            $archive_name = $this->log_file . '.' . $today . '.old';
            @rename($this->log_file, $archive_name);
            touch($this->log_file);
            @chmod($this->log_file, 0640);
            $this->cleanup_old_archives();
        }

        update_option(self::OPTION_LAST_DAILY_ROT, $today, false);
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
            @rename($this->log_file, $archive_name);
            
            // Create new log file
            touch($this->log_file);
            @chmod($this->log_file, 0640);
            
            // Clean up old archives (keep last 5)
            $this->cleanup_old_archives();
        }
    }
    
    /**
     * Clean up old log archives
     */
    private function cleanup_old_archives() {
        $log_dir = dirname($this->log_file);
        $archives = glob($log_dir . '/rts.log.*.old') ?: [];

        $keep_days = (int) get_option(self::OPTION_FILE_DAYS, 7);
        if ($keep_days < 1) { $keep_days = 1; }
        $cutoff = time() - ($keep_days * DAY_IN_SECONDS);

        // Remove anything older than keep_days.
        foreach ($archives as $file) {
            $mt = @filemtime($file);
            if ($mt && $mt < $cutoff) {
                @unlink($file);
            }
        }

        // Cap archive count as a second safety valve.
        $archives = glob($log_dir . '/rts.log.*.old') ?: [];
        if (count($archives) > 50) {
            usort($archives, function($a, $b) {
                return (int) filemtime($a) - (int) filemtime($b);
            });
            $to_remove = array_slice($archives, 0, count($archives) - 50);
            foreach ($to_remove as $file) {
                @unlink($file);
            }
        }
    }
    
    /**
     * Clean up old database entries
     */
    private function cleanup_old_db_entries() {
        global $wpdb;
        
        $days = (int) get_option(self::OPTION_RETENTION_DB, $this->retention_days);
        if ($days < 1) { $days = 1; }
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE time < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
    }
    
    /**
     * Log a message
     * * @param string $level - info, warning, error, critical
     * @param string $message - The log message
     * @param array $context - Additional context data
     */
    public function log($level, $message, $context = []) {
        global $wpdb;
        
        $timestamp = current_time('mysql');
        $level_normalized = strtolower($level);
        
        // Consistent level mapping
        $level_map = [
            'critical' => 'critical',
            'warning'  => 'warning',
            'info'     => 'info',
            'error'    => 'error'
        ];
        
        $db_level = $level_map[$level_normalized] ?? 'info';
        
        // Minimum-level threshold (production default: warning).
        $min_level = $this->get_min_level();
        if ($this->level_priority($db_level) < $this->level_priority($min_level)) {
            // Sample info logs even if min level permits them (keeps noise down).
            if ($db_level === 'info') {
                $n = $this->get_info_sample_rate();
                if ($n > 1 && wp_rand(1, $n) !== 1) {
                    return;
                }
            } else {
                return;
            }
        }

        // Dedupe identical events within a short window to protect DB/disk on spikes.
        $window = $this->get_dedupe_window();
        $fingerprint = substr(sha1($db_level . '|' . (string) $message . '|' . (isset($context['source']) ? (string) $context['source'] : 'core')), 0, 16);
        $dedupe_key = 'rts_log_dedupe_' . $fingerprint;
        $dedupe = get_transient($dedupe_key);
        if (is_array($dedupe)) {
            $dedupe['count'] = isset($dedupe['count']) ? ((int) $dedupe['count'] + 1) : 2;
            set_transient($dedupe_key, $dedupe, $window + 10);
            return;
        } else {
            // First occurrence: store and schedule summary flush.
            set_transient($dedupe_key, [
                'count'  => 1,
                'window' => $window,
                'source' => isset($context['source']) ? sanitize_key((string) $context['source']) : 'core',
                'level'  => $db_level,
            ], $window + 10);

            if (!wp_next_scheduled('rts_log_dedupe_flush', [$fingerprint])) {
                wp_schedule_single_event(time() + $window + 5, 'rts_log_dedupe_flush', [$fingerprint]);
            }
        }

        // DB logging threshold (production default: error+ only).
        $db_min = $this->get_db_min_level();
        $write_db = $this->level_priority($db_level) >= $this->level_priority($db_min);

        
        // Extract and sanitize source
        $source = isset($context['source']) ? sanitize_key($context['source']) : 'core';
        unset($context['source']);
        
        // Sanitize message
        $message = wp_strip_all_tags($message);
        // Truncate overly long messages
        if (strlen($message) > 5000) {
            $message = substr($message, 0, 5000) . '... [truncated]';
        }
        
        // Safe context encoding with better error handling
        $context_json = null;
        if (!empty($context)) {
            $context_json = wp_json_encode($context, JSON_UNESCAPED_SLASHES);
            if (json_last_error() !== JSON_ERROR_NONE || $context_json === false) {
                $context_json = json_encode(['error' => 'Context encoding failed: ' . json_last_error_msg()]);
            }
        }
        
        // Insert into database with error check
        $inserted = null;
        if ($write_db) {
            $inserted = $wpdb->insert(
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
        }
        
        // Log to file
        $level_upper = strtoupper($db_level);
        $context_str = !empty($context) ? ' | ' . $context_json : '';
        $log_entry = sprintf(
            "[%s] [%s] [%s] %s%s\n",
            $timestamp,
            $level_upper,
            $source,
            $message,
            $context_str
        );
        
        $this->write_to_file($log_entry);
        
        // Handle database insertion failures fallback
        if ($write_db && $inserted === false) {
            $db_error_entry = sprintf(
                "[%s] [ERROR] [system] DB Log Insert Failed: %s\n",
                $timestamp,
                $wpdb->last_error
            );
            $this->write_to_file($db_error_entry);
        }
        
        // Track last error time for monitoring
        if ($db_level === 'error' || $db_level === 'critical') {
            update_option(self::OPTION_LAST_ERROR, time());
        }
        
        // Critical errors also go to PHP error log for server-level visibility
        if ($level_normalized === 'critical') {
            error_log('RTS CRITICAL: ' . $message);
        }
    }
    
    /**
     * Write to log file with atomic locking and rotation check
     */
    private function write_to_file($entry) {
        $fp = @fopen($this->log_file, 'a');
        
        // If file open fails, try to fix permissions and retry once
        if (!$fp) {
            $this->check_log_file_permissions();
            $fp = @fopen($this->log_file, 'a');
            if (!$fp) return;
        }

        // Acquire exclusive lock
        if (flock($fp, LOCK_EX)) {
            // Check size while locked using fstat to avoid clearstatcache
            $stat = fstat($fp);
            
            if ($stat && $stat['size'] > $this->max_log_size) {
                // Release, close, rotate
                flock($fp, LOCK_UN);
                fclose($fp);
                
                $this->rotate_logs();
                
                // Reopen new file
                $fp = @fopen($this->log_file, 'a');
                if (!$fp) return;
                flock($fp, LOCK_EX);
            }
            
            fwrite($fp, $entry);
            flock($fp, LOCK_UN);
        }
        
        fclose($fp);
    }
    
    /**
     * Safely get client IP address (Utility method)
     */
    private function get_client_ip() {
        $ip_keys = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR'
        ];
        
        foreach ($ip_keys as $key) {
            if (isset($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return 'unknown';
    }
    
    /**
     * Helper methods for log levels
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
     * Log API error specific helper
     */
    public function log_api_error($endpoint, $error_message, $details = []) {
        $this->error(
            sprintf('API Error: %s', $error_message), 
            array_merge(['source' => 'api', 'endpoint' => $endpoint], $details)
        );
    }
    
    /**
     * Get recent logs for admin display
     * * @param int $limit
     * @return array
     */
    public static function get_recent($limit = 100) {
        global $wpdb;
        $instance = self::get_instance();
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE_FORMAT(time, '%%Y-%%m-%%d %%H:%%i:%%s') as time, level, message, source, context 
                FROM {$instance->table_name} 
                ORDER BY id DESC 
                LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        
        // Decode context for display
        if (!empty($results)) {
            foreach ($results as &$row) {
                if (!empty($row['context'])) {
                    $row['context'] = json_decode($row['context'], true);
                }
            }
        }
        
        return $results ?: [];
    }
    
    /**
     * Search logs
     */
    public static function search_logs($search_term, $limit = 100) {
        global $wpdb;
        $instance = self::get_instance();
        $like = '%' . $wpdb->esc_like($search_term) . '%';
        
        // Search in message or context (handling NULL context safely)
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$instance->table_name} 
                WHERE message LIKE %s 
                OR (context IS NOT NULL AND context LIKE %s)
                ORDER BY id DESC 
                LIMIT %d",
                $like, $like, $limit
            ),
            ARRAY_A
        );
        
        if (!empty($results)) {
            foreach ($results as &$row) {
                if (!empty($row['context'])) {
                    $row['context'] = json_decode($row['context'], true);
                }
            }
        }
        
        return $results ?: [];
    }
    
    /**
     * Get log statistics (Memory Efficient)
     */
    public function get_stats() {
        if (!file_exists($this->log_file)) {
            return [
                'size' => 0,
                'lines' => 0,
                'last_modified' => null
            ];
        }
        
        $size = filesize($this->log_file);
        $lines = 0;
        
        // Efficient line counting without loading file into memory
        $fh = @fopen($this->log_file, 'r');
        if ($fh) {
            while (!feof($fh)) {
                $chunk = fread($fh, 8192);
                $lines += substr_count($chunk, "\n");
            }
            
            // Handle file not ending in newline
            if ($size > 0) {
                fseek($fh, -1, SEEK_END);
                $last_char = fread($fh, 1);
                if ($last_char !== "\n" && $last_char !== "\r") {
                    $lines++;
                }
            }
            fclose($fh);
        }
        
        return [
            'size' => $size,
            'size_formatted' => size_format($size),
            'lines' => $lines,
            'last_modified' => filemtime($this->log_file)
        ];
    }
    
    /**
     * Clear all logs (admin function)
     */
    public function clear_logs() {
        // Clear file
        if (file_exists($this->log_file)) {
            // Atomic clear
            $fp = @fopen($this->log_file, 'w');
            if ($fp) {
                flock($fp, LOCK_EX);
                ftruncate($fp, 0);
                flock($fp, LOCK_UN);
                fclose($fp);
            }
        }
        
        // Clear DB
        global $wpdb;
        $wpdb->query("TRUNCATE TABLE {$this->table_name}");
        
        return true;
    }
}

// Initialize
RTS_Logger::get_instance();

} // end class_exists check

/**
 * Global helper function
 */
if (!function_exists('rts_log')) {
    function rts_log($level, $message, $context = []) {
        if (class_exists('RTS_Logger')) {
            RTS_Logger::get_instance()->log($level, $message, $context);
        }
    }
}