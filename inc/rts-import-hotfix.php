<?php
/**
 * RTS Import Hotfix (v2)
 *
 * Stabilizes CSV/JSON/NDJSON imports on production:
 * - Rewires upload handler to a robust importer
 * - Stores batch payloads in files (not huge Action Scheduler args)
 * - Tracks progress reliably (processed includes skipped duplicates)
 * - Adds duplicate skipping by exact normalized content
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('RTS_Import_Hotfix_V2')) {
    final class RTS_Import_Hotfix_V2 {
        private const GROUP = 'rts';
        private const BATCH_SIZE = 50;
                private const IMPORT_HOOK = 'rts_process_import_batch';
        private const BUILD_HOOK = 'rts_import_build_batches';

        public static function init(): void {
            add_action('init', [__CLASS__, 'wire_hooks'], 30);
                        add_action(self::IMPORT_HOOK, [__CLASS__, 'handle_import_batch'], 10, 2);
            add_action(self::BUILD_HOOK, [__CLASS__, 'build_batches'], 10, 4);
}

        public static function wire_hooks(): void {
            // Replace fragile legacy upload path with hotfix handler.
            // Remove the importer handler from whichever dashboard class registered it.
            // Older builds used RTS_Engine_Dashboard; current builds use RTS_Moderation_Engine.
            remove_action('admin_post_rts_import_letters', ['RTS_Engine_Dashboard', 'handle_import_upload']);
            remove_action('admin_post_rts_import_letters', ['RTS_Moderation_Engine', 'handle_import_upload']);
            add_action('admin_post_rts_import_letters', [__CLASS__, 'handle_import_upload']);

            // Replace legacy import-batch bridge to ensure our batch-file loader is used.
            remove_action(self::IMPORT_HOOK, ['RTS_Moderation_Bootstrap', 'handle_import_batch'], 10);
        }

        public static function handle_import_upload(): void {
            if (!current_user_can('manage_options')) {
                wp_die('Access denied');
            }
            check_admin_referer('rts_import_letters');

            $redirect_tab = admin_url('edit.php?post_type=letter&page=rts-dashboard&tab=letters');

            if (empty($_FILES['rts_import_file']) || !is_array($_FILES['rts_import_file'])) {
                wp_safe_redirect(add_query_arg('rts_msg', 'import_missing', $redirect_tab));
                exit;
            }

            $file = $_FILES['rts_import_file'];
            if (!empty($file['error'])) {
                wp_safe_redirect(add_query_arg('rts_msg', 'import_upload_error', $redirect_tab));
                exit;
            }

            $original_name = isset($file['name']) ? (string) $file['name'] : 'import';
            $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'json', 'ndjson'], true)) {
                wp_safe_redirect(add_query_arg('rts_msg', 'import_bad_type', $redirect_tab));
                exit;
            }

            $uploads = wp_upload_dir();
            $dir = trailingslashit($uploads['basedir']) . 'rts-imports';
            if (!wp_mkdir_p($dir)) {
                wp_safe_redirect(add_query_arg('rts_msg', 'import_mkdir_failed', $redirect_tab));
                exit;
            }

            $filename = 'letters_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false) . '.' . $ext;
            $dest = trailingslashit($dir) . $filename;

            if (!@move_uploaded_file((string) $file['tmp_name'], $dest)) {
                wp_safe_redirect(add_query_arg('rts_msg', 'import_move_failed', $redirect_tab));
                exit;
            }

            $res = self::start_import($dest);
            if (empty($res['ok'])) {
                wp_safe_redirect(add_query_arg('rts_msg', 'import_failed', $redirect_tab));
                exit;
            }

            wp_safe_redirect(add_query_arg('rts_msg', 'import_started', $redirect_tab));
            exit;
        }

        public static function start_import(string $file_path): array {
            if (!function_exists('as_schedule_single_action')) {
                return ['ok' => false, 'error' => 'action_scheduler_missing'];
            }
            $file_path = wp_normalize_path($file_path);
            if ($file_path === '' || !file_exists($file_path) || !is_readable($file_path)) {
                return ['ok' => false, 'error' => 'file_not_readable'];
            }

            $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'json', 'ndjson'], true)) {
                return ['ok' => false, 'error' => 'unsupported_format'];
            }

            $job_id = 'job_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false);
            $job_dir = self::job_dir($job_id);
            if (!wp_mkdir_p($job_dir)) {
                return ['ok' => false, 'error' => 'job_dir_failed'];
            }

            // IMPORTANT: Do NOT parse the whole file in this request.
            // Build batch files asynchronously to avoid gateway timeouts.
            $status = [
                'job_id' => $job_id,
                'total' => 0,                  // Will be discovered while batching
                'processed' => 0,
                'errors' => 0,
                'skipped_duplicates' => 0,
                'scheduled_batches' => 0,
                'completed_batches' => 0,
                'status' => 'running',
                'format' => $ext,
                'started_gmt' => gmdate('c'),
                'file' => basename($file_path),
                'file_path' => $file_path,
                'cursor' => 0,                 // byte offset (csv/ndjson); for json we keep 0
                'batching_done' => 0,
            ];
            update_option('rts_import_job_status', $status, false);

            // Kick off background batching.
            $action_id = as_schedule_single_action(time() + 2, self::BUILD_HOOK, [$job_id, $file_path, $ext, 0], self::GROUP);
            if (!$action_id) {
                return ['ok' => false, 'error' => 'schedule_failed'];
            }

            return [
                'ok' => true,
                'job_id' => $job_id,
                'scheduled_batches' => 0,
                'total' => 0,
            ];
        }

        /**
         * Background step: read the import file incrementally and write batch payloads to disk.
         * This avoids long-running admin_post requests (gateway timeout).
         *
         * @param string $job_id
         * @param string $file_path
         * @param string $ext
         * @param int    $unused_legacy_cursor  (kept for signature stability)
         */
        public static function build_batches(string $job_id, string $file_path, string $ext, int $unused_legacy_cursor = 0): void {
            if (!function_exists('as_schedule_single_action')) {
                return;
            }

            $status = get_option('rts_import_job_status');
            if (!is_array($status) || empty($status['job_id']) || $status['job_id'] !== $job_id) {
                return;
            }
            if (!empty($status['batching_done'])) {
                return;
            }

            $file_path = wp_normalize_path($file_path);
            if ($file_path === '' || !file_exists($file_path) || !is_readable($file_path)) {
                $status['status'] = 'failed';
                $status['error']  = 'file_not_readable';
                $status['finished_gmt'] = gmdate('c');
                update_option('rts_import_job_status', $status, false);
                return;
            }

            // Safety: prevent timeouts in this worker context.
            if (function_exists('set_time_limit')) {
                @set_time_limit(0);
            }
            @ini_set('max_execution_time', '0');

            $job_dir = self::job_dir($job_id);

            // Tune these to keep each action fast on shared hosts.
            $max_items_per_run = max(200, self::BATCH_SIZE * 10); // default 500 items/run for BATCH_SIZE=50
            $items_read = 0;

            $buffer = [];
            $batch_num = (int) ($status['scheduled_batches'] ?? 0);

            // JSON (single blob) isn't stream-friendly here; for safety, do a one-time decode only if reasonably sized.
            if ($ext === 'json') {
                $raw = file_get_contents($file_path);
                $obj = json_decode((string) $raw, true);
                $items = [];
                if (is_array($obj)) {
                    // Accept either {letters:[...]} or a direct list
                    if (isset($obj['letters']) && is_array($obj['letters'])) {
                        $items = $obj['letters'];
                    } else {
                        $items = $obj;
                    }
                }

                foreach ($items as $itemObj) {
                    if ($items_read >= $max_items_per_run) {
                        break;
                    }
                    if (!is_array($itemObj)) {
                        continue;
                    }
                    $item = self::map_obj($itemObj);
                    if (empty($item['content'])) {
                        self::bump_status('errors', 1);
                        continue;
                    }
                    $buffer[] = $item;
                    $items_read++;
                    $status['total'] = (int) ($status['total'] ?? 0) + 1;

                    if (count($buffer) >= self::BATCH_SIZE) {
                        $batch_num++;
                        self::dispatch_batch($job_id, $batch_num, $buffer);
                        $buffer = [];
                        $status['scheduled_batches'] = $batch_num;
                        update_option('rts_import_job_status', $status, false);
                    }
                }

                if (!empty($buffer)) {
                    $batch_num++;
                    self::dispatch_batch($job_id, $batch_num, $buffer);
                    $status['scheduled_batches'] = $batch_num;
                }

                // JSON handled in one pass
                $status['batching_done'] = 1;
                update_option('rts_import_job_status', $status, false);
                return;
            }

            // CSV / NDJSON: stream using byte cursor.
            $cursor = (int) ($status['cursor'] ?? 0);
            $fh = @fopen($file_path, 'rb');
            if (!$fh) {
                $status['status'] = 'failed';
                $status['error']  = 'cannot_open_file';
                $status['finished_gmt'] = gmdate('c');
                update_option('rts_import_job_status', $status, false);
                return;
            }

            // If starting fresh, load/store CSV header and move cursor past it.
            $header = null;
            if ($ext === 'csv') {
                $header_file = trailingslashit($job_dir) . 'header.json';
                if (file_exists($header_file)) {
                    $decoded = json_decode((string) file_get_contents($header_file), true);
                    if (is_array($decoded)) {
                        $header = $decoded;
                    }
                }
                if ($cursor <= 0 && $header === null) {
                    $first = fgetcsv($fh);
                    if (is_array($first)) {
                        $header = self::normalize_header($first);
                        @file_put_contents($header_file, wp_json_encode($header));
                    }
                    $cursor = (int) ftell($fh);
                }
            }

            if ($cursor > 0) {
                @fseek($fh, $cursor);
                // If we land mid-line for NDJSON/CSV, discard until next newline.
                if ($cursor > 0) {
                    fgets($fh);
                }
            }

            while (!feof($fh) && $items_read < $max_items_per_run) {
                if ($ext === 'ndjson') {
                    $line = fgets($fh);
                    if ($line === false) {
                        break;
                    }
                    $line = trim((string) $line);
                    if ($line === '') {
                        continue;
                    }
                    $obj = json_decode($line, true);
                    if (!is_array($obj)) {
                        self::bump_status('errors', 1);
                        continue;
                    }
                    $item = self::map_obj($obj);
                } else { // csv
                    $row = fgetcsv($fh);
                    if ($row === false || $row === null) {
                        break;
                    }
                    if (!is_array($row) || count($row) === 0) {
                        continue;
                    }
                    if ($header === null) {
                        // Fallback: treat first read row as header.
                        $header = self::normalize_header($row);
                        @file_put_contents(trailingslashit($job_dir) . 'header.json', wp_json_encode($header));
                        continue;
                    }
                    $item = self::map_row($header, $row);
                }

                if (empty($item['content'])) {
                    self::bump_status('errors', 1);
                    continue;
                }

                $buffer[] = $item;
                $items_read++;
                $status['total'] = (int) ($status['total'] ?? 0) + 1;

                if (count($buffer) >= self::BATCH_SIZE) {
                    $batch_num++;
                    self::dispatch_batch($job_id, $batch_num, $buffer);
                    $buffer = [];
                    $status['scheduled_batches'] = $batch_num;
                    update_option('rts_import_job_status', $status, false);
                }
            }

            $cursor = (int) ftell($fh);
            fclose($fh);

            if (!empty($buffer)) {
                $batch_num++;
                self::dispatch_batch($job_id, $batch_num, $buffer);
                $status['scheduled_batches'] = $batch_num;
                $buffer = [];
            }

            $status['cursor'] = $cursor;

            // If we're at EOF, mark batching done.
            if ($items_read < $max_items_per_run) {
                $status['batching_done'] = 1;
            }

            update_option('rts_import_job_status', $status, false);

            // If not done, reschedule ourselves quickly.
            if (empty($status['batching_done'])) {
                as_schedule_single_action(time() + 2, self::BUILD_HOOK, [$job_id, $file_path, $ext, 0], self::GROUP);
            }
        }


        private static function dispatch_batch(string $job_id, int $batch_num, array $batch): void {
            $ref = 'batch_' . str_pad((string) $batch_num, 6, '0', STR_PAD_LEFT);
            $batch_file = self::job_dir($job_id) . '/' . $ref . '.json';
            file_put_contents($batch_file, wp_json_encode($batch, JSON_UNESCAPED_UNICODE));

            $action_id = as_schedule_single_action(time() + 1, self::IMPORT_HOOK, [$job_id, $ref], self::GROUP);
            if (!$action_id) {
                // Fallback: process inline if scheduler fails to persist action.
                self::handle_import_batch($job_id, $ref);
            }
        }

        public static function handle_import_batch($job_id, $batch_ref): void {
            $job_id = (string) $job_id;
            if ($job_id === '') {
                return;
            }

            $status = get_option('rts_import_job_status', []);
            if (!is_array($status) || ($status['job_id'] ?? '') !== $job_id) {
                return;
            }

            $batch = self::load_batch($job_id, $batch_ref);
            if (empty($batch) || !is_array($batch)) {
                self::bump_status('errors', 1);
                self::bump_status('completed_batches', 1);
                self::maybe_finish_job();
                return;
            }

            global $wpdb;
            $processed = 0;
            $errors = 0;
            $skipped = 0;

            foreach ($batch as $item) {
                try {
                    $content = trim((string) ($item['content'] ?? ''));
                    if ($content === '') {
                        $errors++;
                        continue;
                    }

                    if (self::is_duplicate_letter($content)) {
                        $processed++;
                        $skipped++;
                        continue;
                    }

                    $title = trim((string) ($item['title'] ?? ''));
                    if ($title === '') {
                        $title = 'Letter ' . current_time('Y-m-d') . ' #0';
                    }

                    $post_id = wp_insert_post([
                        'post_type' => 'letter',
                        'post_title' => sanitize_text_field($title),
                        'post_content' => $content,
                        'post_status' => 'draft',
                    ], true);

                    if (is_wp_error($post_id) || !$post_id) {
                        $errors++;
                        continue;
                    }

                    $post_id = (int) $post_id;

                    // Workflow: imported letters must start unprocessed (authoritative stage meta).
                    if (class_exists('RTS_Workflow')) {
                        RTS_Workflow::set_stage($post_id, RTS_Workflow::STAGE_UNPROCESSED, 'Imported via CSV/JSON');
                        // Ensure post_status remains draft per import contract.
                        wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
                    } else {
                        // Fail-closed: do not leave orphaned imports without workflow.
                        wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
                        update_post_meta($post_id, 'rts_workflow_stage', 'unprocessed');
                    }

                    $date_seed = current_time('Y-m-d');
                    $seed_post_date = (string) get_post_field('post_date', $post_id);
                    if ($seed_post_date !== '' && $seed_post_date !== '0000-00-00 00:00:00') {
                        $seed_ts = strtotime($seed_post_date);
                        if ($seed_ts !== false) {
                            $date_seed = wp_date('Y-m-d', $seed_ts);
                        }
                    }
                    $canonical_title = sprintf('Letter %s #%d', $date_seed, $post_id);
                    update_post_meta($post_id, '_rts_internal_moderation_update', '1');
                    wp_update_post(['ID' => $post_id, 'post_title' => $canonical_title]);
                    update_post_meta($post_id, 'rts_import_content_hash', sha1(self::normalize_content($content)));

                    $ip = isset($item['submission_ip']) ? trim((string) $item['submission_ip']) : '';
                    if ($ip !== '' && class_exists('RTS_IP_Utils') && RTS_IP_Utils::is_valid_ip($ip)) {
                        update_post_meta($post_id, 'rts_submission_ip', $ip);
                    }

                    as_schedule_single_action(time() + 1, 'rts_process_letter', [$post_id], self::GROUP);
                    $processed++;
                } catch (\Throwable $e) {
                    $errors++;
                }
            }

            self::bump_status('processed', $processed);
            self::bump_status('errors', $errors);
            self::bump_status('skipped_duplicates', $skipped);
            self::bump_status('completed_batches', 1);
            self::maybe_finish_job();

            // Cleanup processed batch file.
            if (is_string($batch_ref)) {
                $file = self::job_dir($job_id) . '/' . $batch_ref . '.json';
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        }

        private static function maybe_finish_job(): void {
            $status = get_option('rts_import_job_status', []);
            if (!is_array($status)) {
                return;
            }

            $completed = (int) ($status['completed_batches'] ?? 0);
            $scheduled = (int) ($status['scheduled_batches'] ?? 0);
            $processed = (int) ($status['processed'] ?? 0);
            $total = (int) ($status['total'] ?? 0);

            if (($scheduled > 0 && $completed >= $scheduled) || ($total > 0 && $processed >= $total)) {
                $status['status'] = 'complete';
                $status['finished_gmt'] = gmdate('c');
                update_option('rts_import_job_status', $status, false);
            }
        }

        
        /**
         * Manual pump for hosts where Action Scheduler runner/WP-Cron is blocked.
         * Safe to call repeatedly (does small bounded work).
         *
         * Strategy:
         * - If batching isn't finished yet, run a batching step (writes batch files).
         * - If batches exist but haven't been executed, process ONE batch inline.
         *
         * This is intentionally conservative so it can run during REST polling.
         */
        public static function manual_tick(): void {
            if (!current_user_can('manage_options')) {
                return;
            }

            $status = get_option('rts_import_job_status', []);
            if (!is_array($status) || empty($status['job_id'])) {
                return;
            }
            if (($status['status'] ?? '') !== 'running') {
                return;
            }

            $job_id = (string) $status['job_id'];
            $ext = (string) ($status['format'] ?? '');
            $file_path = (string) ($status['file_path'] ?? '');

            // 1) Ensure batches are being built.
            if (empty($status['batching_done'])) {
                self::build_batches($job_id, $file_path, $ext, 0);
                // reload latest status
                $status = get_option('rts_import_job_status', []);
                if (!is_array($status) || ($status['job_id'] ?? '') !== $job_id) {
                    return;
                }
            }

            // 2) If there are batches scheduled but none are being executed, process one inline.
            $scheduled = (int) ($status['scheduled_batches'] ?? 0);
            $completed = (int) ($status['completed_batches'] ?? 0);

            if ($scheduled <= 0) {
                return;
            }
            if ($completed >= $scheduled) {
                self::maybe_finish_job();
                return;
            }

            $next_num = $completed + 1;
            $ref = 'batch_' . str_pad((string) $next_num, 6, '0', STR_PAD_LEFT);

            // Process inline (do NOT reschedule; that can stall again if runner is blocked).
            self::handle_import_batch($job_id, $ref);
        }

private static function load_batch(string $job_id, $batch_ref): array {
            if (is_array($batch_ref)) {
                return $batch_ref;
            }
            $ref = is_scalar($batch_ref) ? (string) $batch_ref : '';
            if ($ref === '') {
                return [];
            }
            $file = self::job_dir($job_id) . '/' . $ref . '.json';
            if (!file_exists($file)) {
                return [];
            }
            $json = file_get_contents($file);
            $data = json_decode((string) $json, true);
            return is_array($data) ? $data : [];
        }

        private static function is_duplicate_letter(string $content): bool {
            global $wpdb;
            $norm = self::normalize_content($content);
            if ($norm === '') {
                return true;
            }

            static $request_seen = [];
            $hash = sha1($norm);
            if (isset($request_seen[$hash])) {
                return true;
            }
            $request_seen[$hash] = true;

            $sql = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type=%s AND post_status IN ('publish','pending','draft','private') AND post_content=%s LIMIT 1",
                'letter',
                $content
            );
            $exists = (int) $wpdb->get_var($sql);
            return $exists > 0;
        }

        private static function normalize_content(string $content): string {
            $content = wp_strip_all_tags($content);
            $content = preg_replace('/\s+/u', ' ', trim($content));
            return mb_strtolower((string) $content, 'UTF-8');
        }

        private static function bump_status(string $key, int $by): void {
            if ($by <= 0) {
                return;
            }
            $status = get_option('rts_import_job_status', []);
            if (!is_array($status)) {
                $status = [];
            }
            $status[$key] = (int) ($status[$key] ?? 0) + $by;
            update_option('rts_import_job_status', $status, false);
        }

        private static function count_items(string $file_path, string $ext): int {
            if ($ext === 'csv') {
                return self::count_csv_rows($file_path);
            }
            if ($ext === 'ndjson') {
                return self::count_lines($file_path);
            }
            $data = json_decode((string) file_get_contents($file_path), true);
            return is_array($data) ? count($data) : 0;
        }

        private static function count_lines(string $file_path): int {
            $count = 0;
            $fh = new SplFileObject($file_path, 'r');
            foreach ($fh as $line) {
                if (trim((string) $line) !== '') {
                    $count++;
                }
            }
            return $count;
        }

        private static function count_csv_rows(string $file_path): int {
            $f = new SplFileObject($file_path, 'r');
            $f->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
            $header_seen = false;
            $count = 0;
            foreach ($f as $row) {
                if (!is_array($row) || count($row) === 0) {
                    continue;
                }
                $has_value = false;
                foreach ($row as $cell) {
                    if (trim((string) $cell) !== '') {
                        $has_value = true;
                        break;
                    }
                }
                if (!$has_value) {
                    continue;
                }
                if (!$header_seen) {
                    $header_seen = true;
                    continue;
                }
                $count++;
            }
            return max(0, $count);
        }

        private static function normalize_header(array $row): array {
            $out = [];
            foreach ($row as $cell) {
                $out[] = sanitize_key(is_string($cell) ? trim($cell) : '');
            }
            return $out;
        }

        private static function map_row(array $header, array $row): array {
            $data = [];
            foreach ($header as $i => $key) {
                if ($key !== '') {
                    $data[$key] = isset($row[$i]) ? (string) $row[$i] : '';
                }
            }
            return self::map_obj($data);
        }

        private static function map_obj(array $obj): array {
            $content = '';
            foreach (['content', 'letter', 'message', 'body'] as $k) {
                if (!empty($obj[$k])) {
                    $content = (string) $obj[$k];
                    break;
                }
            }
            $title = '';
            foreach (['title', 'subject', 'name', 'first_name'] as $k) {
                if (!empty($obj[$k])) {
                    $title = (string) $obj[$k];
                    break;
                }
            }
            $submission_ip = '';
            foreach (['submission_ip', 'ip', 'rts_submission_ip'] as $k) {
                if (!empty($obj[$k])) {
                    $submission_ip = (string) $obj[$k];
                    break;
                }
            }
            return [
                'content' => trim($content),
                'title' => trim($title),
                'submission_ip' => trim($submission_ip),
            ];
        }

        private static function yield_items(string $file_path, string $ext): Generator {
            if ($ext === 'csv') {
                $fh = new SplFileObject($file_path, 'r');
                $fh->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
                $header = null;
                foreach ($fh as $row) {
                    if (!is_array($row) || count($row) === 0) {
                        continue;
                    }
                    if ($header === null) {
                        $header = self::normalize_header($row);
                        continue;
                    }
                    yield self::map_row($header, $row);
                }
                return;
            }

            if ($ext === 'ndjson') {
                $fh = new SplFileObject($file_path, 'r');
                foreach ($fh as $line) {
                    $line = trim((string) $line);
                    if ($line === '') {
                        continue;
                    }
                    $obj = json_decode($line, true);
                    if (is_array($obj)) {
                        yield self::map_obj($obj);
                    }
                }
                return;
            }

            $data = json_decode((string) file_get_contents($file_path), true);
            if (is_array($data)) {
                foreach ($data as $obj) {
                    if (is_array($obj)) {
                        yield self::map_obj($obj);
                    }
                }
            }
        }

        private static function job_dir(string $job_id): string {
            $uploads = wp_upload_dir();
            return trailingslashit($uploads['basedir']) . 'rts-imports/jobs/' . sanitize_file_name($job_id);
        }
    }

    RTS_Import_Hotfix_V2::init();
}
