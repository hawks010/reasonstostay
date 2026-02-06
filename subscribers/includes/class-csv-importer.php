<?php
/**
 * RTS CSV Importer
 *
 * Handles bulk import of subscribers via CSV with background processing.
 *
 * @package    RTS_Subscriber_System
 * @subpackage Import
 * @version    1.0.3
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_CSV_Importer {

    const BATCH_SIZE = 500; // Reduced batch size for reliability
    const SESSION_TTL = 21600; // 6 hours (6 * HOUR_IN_SECONDS)
    const MAX_FILE_SIZE = 10485760; // 10MB
    const LOCK_TIMEOUT = 300; // 5 minutes
    const MAX_ROWS = 50000; // Hard limit to prevent abuse

    public function __construct() {
        if (is_admin()) {
			add_action('admin_post_rts_import_csv', array($this, 'handle_import'));
			add_action('admin_post_rts_export_csv', array($this, 'handle_export'));
            add_action('wp_ajax_rts_import_progress', array($this, 'ajax_get_import_progress'));
            add_action('wp_ajax_rts_cancel_import', array($this, 'ajax_cancel_import'));
        }

        // Background processing hook (WP-Cron single events)
        add_action('rts_process_import_chunk', array($this, 'process_import_chunk'));
        
        // Cleanup hook
        add_action('rts_cleanup_import_sessions', array($this, 'cleanup_expired_sessions'));

        // Schedule cleanup if not exists
        if (!wp_next_scheduled('rts_cleanup_import_sessions')) {
            wp_schedule_event(time(), 'hourly', 'rts_cleanup_import_sessions');
        }
    }

    /**
     * Handle CSV upload and start a background import session.
     */
    public function handle_import() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized action.', 'rts-subscriber-system'));
        }

        check_admin_referer('rts_import_csv');

        // Attempt to raise memory limit for the upload handling phase
        @ini_set('memory_limit', '256M');

        if (empty($_FILES['csv_file']) || empty($_FILES['csv_file']['tmp_name'])) {
            wp_die(__('No file uploaded.', 'rts-subscriber-system'));
        }

        $file = $_FILES['csv_file'];

        // 1. Validate File Size
        if ($file['size'] > self::MAX_FILE_SIZE) {
            wp_die(__('File too large. Maximum size is 10MB.', 'rts-subscriber-system'));
        }

        // 2. Validate MIME Type
        $allowed_mimes = array('text/csv', 'application/vnd.ms-excel', 'text/plain', 'application/csv');
        $file_mime = '';
        
        if (function_exists('mime_content_type')) {
            $file_mime = mime_content_type($file['tmp_name']) ?: '';
        }
        
        // Fallback check: if mime detection fails or returns generic text/plain, ensure extension is csv
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        // If we found a mime type but it's not in our list, AND it's not just a generic text file which CSVs often are
        if ($file_mime && !in_array($file_mime, $allowed_mimes, true)) {
             wp_die(__('Invalid file type. Please upload a CSV file.', 'rts-subscriber-system'));
        }
        
        // Strict extension check as final guard
        if ($file_ext !== 'csv') {
            wp_die(__('Invalid file type. Please upload a CSV file.', 'rts-subscriber-system'));
        }

        $default_frequency = isset($_POST['default_frequency']) ? sanitize_text_field($_POST['default_frequency']) : 'weekly';
        if (!in_array($default_frequency, array('daily','weekly','monthly'), true)) {
            $default_frequency = 'weekly';
        }

        // 3. Handle Upload Securely
        require_once ABSPATH . 'wp-admin/includes/file.php';
        $overrides = array(
            'test_form' => false, 
            'mimes' => array('csv' => 'text/csv')
        );
        
        $uploaded = wp_handle_upload($file, $overrides);

        if (isset($uploaded['error'])) {
            error_log('RTS Import Upload Error: ' . $uploaded['error']);
            wp_die(__('Upload failed. Please try again.', 'rts-subscriber-system'));
        }

        $file_path = $uploaded['file'];
        
        // 4. Generate Secure Session ID
        $session_id = $this->generate_session_id();

        // Count rows efficiently (memory safe)
        $total_rows = $this->count_csv_rows($file_path);

        if ($total_rows > self::MAX_ROWS) {
             @unlink($file_path);
             wp_die(sprintf(__('File contains too many rows. Limit is %d.', 'rts-subscriber-system'), self::MAX_ROWS));
        }

        $session = array(
            'session_id'        => $session_id,
            'file_path'         => $file_path,
            'byte_offset'       => 0,
            'total_rows'        => max(0, $total_rows - 1), // exclude header
            'processed'         => 0,
            'imported'          => 0,
            'skipped_duplicate' => 0,
            'skipped_invalid'   => 0,
            'skipped_other'     => 0,
            'default_frequency' => $default_frequency,
            'status'            => 'processing',
            'started_at'        => current_time('mysql'),
            'updated_at'        => current_time('mysql'),
            'lock_ts'           => 0, // For race condition handling
            'last_error'        => '',
            'summary'           => array(),
        );

        set_transient('rts_import_session_' . $session_id, $session, self::SESSION_TTL);

        // Kick off background job immediately
        wp_schedule_single_event(time(), 'rts_process_import_chunk', array($session_id));

        // Redirect back to the consolidated Subscribers Dashboard (shows progress card).
        wp_redirect(add_query_arg(array(
            'post_type'  => 'rts_subscriber',
            'page'       => 'rts-subscribers-dashboard',
            'session'    => $session_id,
            'processing' => 1,
        ), admin_url('edit.php')));
        exit;
    }

    /**
     * Export subscribers to CSV.
     * Streams output for large lists.
     */
    public function handle_export() {
        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized action.', 'rts-subscriber-system'));
        }
        check_admin_referer('rts_export_csv');

        $scope = isset($_POST['export_scope']) ? sanitize_key(wp_unslash($_POST['export_scope'])) : 'all';
        if (!in_array($scope, array('all','active','bounced','unsubscribed'), true)) {
            $scope = 'all';
        }

        @ini_set('memory_limit', '256M');
        @set_time_limit(0);

        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=rts-subscribers-' . $scope . '-' . gmdate('Y-m-d-His') . '.csv');

        $out = fopen('php://output', 'w');
        if (!$out) {
            wp_die(__('Unable to open output stream.', 'rts-subscriber-system'));
        }

        // Header
        fputcsv($out, array('email','status','frequency','pref_letters','pref_newsletters'));

        $paged = 1;
        $per_page = 500;

        // Filter by status via meta.
        $meta_query = array();
        if ($scope !== 'all') {
            $meta_query[] = array(
                'key'   => '_rts_subscriber_status',
                'value' => $scope,
            );
        }

        do {
            $q = new WP_Query(array(
                'post_type'              => 'rts_subscriber',
                'post_status'            => 'publish',
                'posts_per_page'         => $per_page,
                'paged'                  => $paged,
                'fields'                 => 'ids',
                'orderby'                => 'ID',
                'order'                  => 'ASC',
                'no_found_rows'          => false,
                'update_post_meta_cache' => true,
                'update_post_term_cache' => false,
                'meta_query'             => $meta_query,
            ));

            if (empty($q->posts)) {
                break;
            }

            foreach ($q->posts as $subscriber_id) {
                $email = get_post_field('post_title', $subscriber_id);
                $status = get_post_meta($subscriber_id, '_rts_subscriber_status', true);
                $frequency = get_post_meta($subscriber_id, '_rts_subscriber_frequency', true);
                $pref_letters = (int) get_post_meta($subscriber_id, '_rts_pref_letters', true);
                $pref_newsletters = (int) get_post_meta($subscriber_id, '_rts_pref_newsletters', true);

                fputcsv($out, array($email, $status, $frequency, $pref_letters, $pref_newsletters));
            }

            $paged++;

            // Flush each page for huge exports.
            if (function_exists('flush')) {
                flush();
            }
        } while ($paged <= (int) $q->max_num_pages);

        fclose($out);
        exit;
    }

    /**
     * Process one chunk for a given import session.
     *
     * @param string $session_id
     */
    public function process_import_chunk($session_id) {
        // Raise memory limit for processing
        @ini_set('memory_limit', '256M');

        $session_id = sanitize_text_field($session_id);
        $transient_key = 'rts_import_session_' . $session_id;
        $session = get_transient($transient_key);

        // Basic Validation
        if (!$session || empty($session['file_path']) || !file_exists($session['file_path'])) {
            return;
        }

        if ($session['status'] !== 'processing') {
            return;
        }

        // Check Lock (Prevent Race Conditions)
        if (!empty($session['lock_ts']) && (time() - $session['lock_ts']) < self::LOCK_TIMEOUT) {
            return; // Another process is running this chunk
        }

        // Set Lock
        $session['lock_ts'] = time();
        set_transient($transient_key, $session, self::SESSION_TTL);

        // Open File (supress warnings)
        $handle = @fopen($session['file_path'], 'rb');
        if ($handle === false) {
            $session['status'] = 'error';
            $session['last_error'] = 'Could not open CSV file.';
            $session['lock_ts'] = 0; // Release lock so we don't block forever
            set_transient($transient_key, $session, self::SESSION_TTL);
            return;
        }

        // Initialize or Seek
        if (empty($session['column_map'])) {
            $header = fgetcsv($handle);
            if ($header) {
                // Sanitize header keys and handle potential encoding issues
                $header = array_map(array($this, 'sanitize_header_cell'), $header);
                $session['column_map'] = $this->build_column_map($header);
            } else {
                 $session['status'] = 'error';
                 $session['last_error'] = 'Empty CSV file.';
                 $session['lock_ts'] = 0;
                 set_transient($transient_key, $session, self::SESSION_TTL);
                 fclose($handle);
                 return;
            }
            $session['byte_offset'] = ftell($handle);
        } else {
            fseek($handle, intval($session['byte_offset']));
        }

        // Dependency Check & Load
        if (!class_exists('RTS_Subscriber_CPT')) {
            // Assume it's in the same directory
            if (file_exists(plugin_dir_path(__FILE__) . 'class-subscriber-cpt.php')) {
                require_once plugin_dir_path(__FILE__) . 'class-subscriber-cpt.php';
            }
        }
        
        if (!class_exists('RTS_Subscriber_CPT')) {
             $session['status'] = 'error';
             $session['last_error'] = 'Subscriber CPT class missing.';
             $session['lock_ts'] = 0;
             set_transient($transient_key, $session, self::SESSION_TTL);
             fclose($handle);
             return;
        }

        $cpt = new RTS_Subscriber_CPT();
        $map = $session['column_map'];
        $rows_processed = 0;

        // Process Rows
        while ($rows_processed < self::BATCH_SIZE && ($row = fgetcsv($handle)) !== false) {
            $rows_processed++;
            $session['processed']++;

            // Sanitize Row Data (Prevent CSV Injection)
            $row = array_map(array($this, 'sanitize_csv_cell'), $row);

            $email_raw = $this->get_col($row, $map, 'email');
            $email = $this->normalize_email($email_raw);

            if (!$email || !is_email($email)) {
                $session['skipped_invalid']++;
                continue;
            }

            // Frequency
            $freq = $this->get_col($row, $map, 'frequency');
            $freq = $freq ? sanitize_text_field($freq) : $session['default_frequency'];
            if (!in_array($freq, array('daily','weekly','monthly'), true)) {
                $freq = $session['default_frequency'];
            }

            // Source
            $source = $this->get_col($row, $map, 'source');
            $source = $source ? sanitize_text_field($source) : 'import';

            // Meta
            $created = $this->get_col($row, $map, 'created_date');
            $status_col = $this->get_col($row, $map, 'status');

            $args = array(
                'ip_address' => '', 
                'user_agent' => 'Import'
            );
            
            if ($created) {
                // Parse date - strtotime handles most CSV formats nicely
                $ts = strtotime($created);
                if ($ts) {
                    $args['subscribed_date'] = gmdate('Y-m-d H:i:s', $ts);
                }
            }

            // Create Subscriber
            $result = $cpt->create_subscriber($email, $freq, $source, $args);

            if (is_wp_error($result)) {
                if ($result->get_error_code() === 'duplicate_email') {
                    $session['skipped_duplicate']++;
                } else {
                    $session['skipped_other']++;
                }
            } else {
                $session['imported']++;
                
                // Handle Status
                $verified = true; 
                if ($status_col) {
                     $s = strtoupper(trim($status_col));
                     if (in_array($s, array('UNCONFIRMED', 'PENDING', 'UNVERIFIED'), true)) {
                         $verified = false;
                     }
                }
                update_post_meta($result, '_rts_subscriber_verified', $verified ? 1 : 0);
                
                if (!empty($args['subscribed_date'])) {
                    update_post_meta($result, '_rts_subscriber_subscribed_date', $args['subscribed_date']);
                }
            }
        }

        // Update Session State
        $session['byte_offset'] = ftell($handle);
        $session['updated_at'] = current_time('mysql');
        $session['lock_ts'] = 0; // Release lock

        fclose($handle);

        // Check Completion
        if ($rows_processed < self::BATCH_SIZE) {
            $session['status'] = 'complete';
            
            // Generate Summary Stats
            $session['summary'] = array(
                'success_rate' => $session['total_rows'] > 0 ? 
                    round(($session['imported'] / $session['total_rows']) * 100, 1) : 0,
                'duration' => human_time_diff(
                    strtotime($session['started_at']), 
                    current_time('timestamp')
                ),
            );

            // Cleanup file
            if (file_exists($session['file_path'])) {
                @unlink($session['file_path']);
            }
        } else {
             // Schedule next chunk immediately
             wp_schedule_single_event(time(), 'rts_process_import_chunk', array($session_id));
        }

        set_transient($transient_key, $session, self::SESSION_TTL);
    }

    /**
     * AJAX: Get Progress
     */
    public function ajax_get_import_progress() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        check_ajax_referer('rts_import_progress', 'nonce');

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        if (!$session_id) wp_send_json_error(array('message' => 'Missing ID'));

        $session = get_transient('rts_import_session_' . $session_id);
        if (!$session) wp_send_json_error(array('message' => 'Session not found'));

        wp_send_json_success($session);
    }

    /**
     * AJAX: Cancel Import
     */
    public function ajax_cancel_import() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        check_ajax_referer('rts_import_progress', 'nonce');

        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $transient_key = 'rts_import_session_' . $session_id;
        $session = get_transient($transient_key);

        if ($session) {
            $session['status'] = 'cancelled';
            if (!empty($session['file_path']) && file_exists($session['file_path'])) {
                @unlink($session['file_path']);
            }
            delete_transient($transient_key);
        }
        
        wp_send_json_success();
    }

    /**
     * Cron: Cleanup hook place holder.
     * Most cleanup is handled by completion/cancellation or transient expiration.
     */
    public function cleanup_expired_sessions() {
        // Fallback: Could iterate options table for stale transients if needed, 
        // but WP transients handle self-expiration.
    }

    /**
     * Helper: Count rows memory-efficiently.
     */
    private function count_csv_rows($file_path) {
        $count = 0;
        $handle = @fopen($file_path, 'rb');
        if ($handle === false) return 0;
        while (!feof($handle)) {
            if (fgets($handle) !== false) $count++; 
        }
        fclose($handle);
        return $count;
    }

    /**
     * Helper: Map column headers to keys.
     */
    private function build_column_map($header) {
        $map = array();
        if (!is_array($header)) return $map;

        $lower = array();
        foreach ($header as $i => $name) {
            $key = strtolower($name);
            $lower[$key] = $i;
        }

        // Mapping logic
        $aliases = array(
            'email' => array('email', 'e-mail', 'mail', 'email address'),
            'created_date' => array('created_date', 'created date', 'joined', 'subscribed'),
            'status' => array('status', 'state'),
            'frequency' => array('frequency', 'freq'),
            'source' => array('source', 'origin')
        );

        foreach ($aliases as $target => $possible_names) {
            foreach ($possible_names as $name) {
                if (isset($lower[$name])) {
                    $map[$target] = $lower[$name];
                    break;
                }
            }
        }

        return $map;
    }

    private function get_col($row, $map, $key) {
        if (!isset($map[$key])) return '';
        $idx = intval($map[$key]);
        return isset($row[$idx]) ? (string)$row[$idx] : '';
    }

    private function normalize_email($value) {
        $email = trim((string)$value);
        if ($email === '') return '';

        if (preg_match('/<([^>]+)>/', $email, $m)) {
            $email = $m[1];
        }
        $email = trim($email, " \t\n\r\0\x0B\"\'");
        $email = str_replace(' ', '', $email);
        return strtolower($email);
    }

    /**
     * Sanitize header cell (BOM removal + Trimming)
     */
    private function sanitize_header_cell($value) {
        // Remove BOM if present
        $value = preg_replace('/\x{FEFF}/u', '', (string)$value);
        return trim($value);
    }

    /**
     * Prevent CSV Injection (Formula Injection) + Encoding Check
     * Triggers when cell starts with =, +, -, or @
     */
    private function sanitize_csv_cell($value) {
        $value = (string)$value;
        
        // Basic encoding check - ensure UTF-8
        if (function_exists('mb_check_encoding') && !mb_check_encoding($value, 'UTF-8')) {
            if (function_exists('mb_convert_encoding')) {
                $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1, WINDOWS-1252, AUTO');
            }
        }
        
        $value = trim($value);
        if ($value === '') return '';
        
        // If it starts with a formula trigger, escape it with a single quote
        if (in_array($value[0], array('=', '+', '-', '@'), true)) {
            return "'" . $value;
        }
        return $value;
    }

    /**
     * Secure Session ID Generation
     */
    private function generate_session_id() {
        if (function_exists('wp_generate_uuid4')) {
            return 'rtsimport_' . str_replace('-', '', wp_generate_uuid4());
        }
        return 'rtsimport_' . bin2hex(random_bytes(16));
    }
}