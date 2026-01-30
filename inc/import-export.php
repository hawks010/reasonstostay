<?php
/**
 * Reasons to Stay - Import/Export System
 * Robust system for importing letters from Wix, CSV, JSON
 */

if (!defined('ABSPATH')) exit;

class RTS_Import_Export {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Admin UI is now consolidated under Letters → Settings (Import/Export tab)
        // but we keep a dedicated submenu page for compatibility with legacy
        // redirects/bookmarks (and to avoid "not allowed to access" errors
        // when a redirect hits a page slug that isn't registered).
        add_action('admin_menu', [$this, 'register_menu'], 50);
        
        // Handle file uploads
        add_action('admin_init', [$this, 'handle_import']);
        add_action('admin_init', [$this, 'handle_export']);
        
        // AJAX handlers for chunked import
        add_action('wp_ajax_rts_import_start', [$this, 'ajax_import_start']);
        add_action('wp_ajax_rts_import_chunk', [$this, 'ajax_import_chunk']);
    }

    /**
     * Register legacy Import/Export submenu under Letters.
     *
     * URL: /wp-admin/edit.php?post_type=letter&page=rts-import-export
     */
    public function register_menu() {
        if (!is_admin()) return;
        if (!current_user_can('manage_options')) return;

        // DISABLED: Import/Export is now a TAB in Settings page to reduce menu clutter
        /*
        add_submenu_page(
            'edit.php?post_type=letter',
            __('Import/Export', 'rts'),
            __('Import/Export', 'rts'),
            'manage_options',
            'rts-import-export',
            [$this, 'render_import_export_page']
        );
        */
    }
    
    /**
     * Render import/export page
     */
    public function render_import_export_page() {
        $published_count = wp_count_posts('letter')->publish;
        $pending_count = wp_count_posts('letter')->pending;
        
        ?>
        <div class="wrap">
            <h1>📦 Import/Export Letters</h1>
            
            <div class="card" style="max-width: 900px;">
                <h2>Current Letter Count</h2>
                <p style="font-size: 18px;">
                    <strong><?php echo number_format($published_count); ?></strong> published letters<br>
                    <strong><?php echo number_format($pending_count); ?></strong> pending letters
                </p>
            </div>
            
            <!-- IMPORT SECTION -->
            <div class="card" style="max-width: 900px; margin-top: 20px;">
                <h2>📥 Import Letters</h2>
                
                <div class="notice notice-info inline">
                    <p><strong>Supported Formats:</strong></p>
                    <ul style="margin-left: 20px;">
                        <li><strong>CSV</strong> - Universal format (Excel, Google Sheets, Wix export)</li>
                        <li><strong>JSON</strong> - Complete data preservation</li>
                    </ul>
                </div>
                
                <h3>CSV Format (Required Columns):</h3>
                <table class="widefat" style="max-width: 100%; margin-bottom: 20px;">
                    <thead>
                        <tr>
                            <th>Column Name</th>
                            <th>Required?</th>
                            <th>Example</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><code>letter_text</code></td>
                            <td><span style="color: #d63638;">✓ Required</span></td>
                            <td>Hey friend, I want you to know...</td>
                        </tr>
                        <tr>
                            <td><code>author_name</code></td>
                            <td>Optional</td>
                            <td>Alex</td>
                        </tr>
                        <tr>
                            <td><code>author_email</code></td>
                            <td>Optional</td>
                            <td>alex@example.com</td>
                        </tr>
                        <tr>
                            <td><code>submitted_at</code></td>
                            <td>Optional</td>
                            <td>2024-01-15 14:30:00</td>
                        </tr>
                        <tr>
                            <td><code>feelings</code></td>
                            <td>Optional</td>
                            <td>hopeless,alone (comma-separated)</td>
                        </tr>
                        <tr>
                            <td><code>tone</code></td>
                            <td>Optional</td>
                            <td>gentle (or real, hopeful)</td>
                        </tr>
                        <tr>
                            <td><code>status</code></td>
                            <td>Optional</td>
                            <td>publish (or pending, draft)</td>
                        </tr>
                    </tbody>
                </table>
                
                <h3>Download CSV Template:</h3>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=rts-import-export&action=download_template'); ?>" class="button">
                        📄 Download CSV Template
                    </a>
                    <span class="description">Use this as a starting point for your import</span>
                </p>
                
                <hr>
                
                <h3>Import File:</h3>
                <form method="post" enctype="multipart/form-data" id="rts-import-form">
                    <?php wp_nonce_field('rts_import', 'rts_import_nonce'); ?>
                    
                    <p>
                        <input type="file" name="rts_import_file" accept=".csv,.json" required style="margin-bottom: 10px;">
                    </p>
                    
                    <p>
                        <label>
                            <input type="checkbox" name="rts_auto_tag" value="1" checked>
                            <strong>Auto-tag letters</strong> (detect feelings & tone automatically)
                        </label>
                    </p>
                    
                    <p>
                        <label>
                            <input type="checkbox" name="rts_skip_duplicates" value="1" checked>
                            <strong>Skip duplicates</strong> (based on letter content)
                        </label>
                    </p>
                    
                    <p>
                        <label>
                            Default status:
                            <select name="rts_default_status">
                                <option value="pending">Pending Review</option>
                                <option value="publish">Published</option>
                                <option value="draft">Draft</option>
                            </select>
                        </label>
                    </p>
                    
                    <p>
                        <button type="submit" name="rts_start_import" class="button button-primary button-large">
                            📥 Start Import
                        </button>
                    </p>
                </form>
                
                <div id="rts-import-progress" style="display: none; margin-top: 20px;">
                    <h3>Import Progress:</h3>
                    <div style="background: #f0f0f0; border: 1px solid #ccc; height: 30px; border-radius: 3px; overflow: hidden;">
                        <div id="rts-progress-bar" style="background: #2271b1; height: 100%; width: 0%; transition: width 0.3s;"></div>
                    </div>
                    <p id="rts-progress-text" style="margin-top: 10px;">Processing...</p>
                </div>
            </div>
            
            <!-- EXPORT SECTION -->
            <div class="card" style="max-width: 900px; margin-top: 20px;">
                <h2>📤 Export Letters</h2>
                
                <p>Export all your letters to CSV or JSON format for backup or migration.</p>
                
                <form method="post">
                    <?php wp_nonce_field('rts_export', 'rts_export_nonce'); ?>
                    
                    <h3>Export Options:</h3>
                    
                    <p>
                        <label>
                            <input type="radio" name="rts_export_format" value="csv" checked>
                            <strong>CSV</strong> - Universal format (open in Excel, Google Sheets)
                        </label>
                    </p>
                    
                    <p>
                        <label>
                            <input type="radio" name="rts_export_format" value="json">
                            <strong>JSON</strong> - Complete data with all metadata
                        </label>
                    </p>
                    
                    <h3>Filter by Status:</h3>
                    <p>
                        <label><input type="checkbox" name="rts_export_status[]" value="publish" checked> Published</label><br>
                        <label><input type="checkbox" name="rts_export_status[]" value="pending" checked> Pending</label><br>
                        <label><input type="checkbox" name="rts_export_status[]" value="draft"> Drafts</label>
                    </p>
                    
                    <p>
                        <button type="submit" name="rts_start_export" class="button button-primary button-large">
                            📤 Export Letters
                        </button>
                    </p>
                </form>
            </div>
            
            <!-- BULK OPERATIONS -->
            <div class="card" style="max-width: 900px; margin-top: 20px;">
                <h2>⚙️ Bulk Operations</h2>
                
                <form method="post" onsubmit="return confirm('This will affect ALL letters. Are you sure?');">
                    <?php wp_nonce_field('rts_bulk_operation', 'rts_bulk_nonce'); ?>
                    
                    <p>
                        <button type="submit" name="rts_bulk_autotag" class="button">
                            🏷️ Auto-Tag All Letters
                        </button>
                        <span class="description">Detect feelings & tone for all letters</span>
                    </p>
                    
                    <p>
                        <button type="submit" name="rts_bulk_generate_images" class="button">
                            🖼️ Generate All Social Images
                        </button>
                        <span class="description">Create OG images for all published letters</span>
                    </p>
                    
                    <p>
                        <button type="submit" name="rts_bulk_scan" class="button">
                            🔍 Scan All for Problems
                        </button>
                        <span class="description">Check all letters for problematic content</span>
                    </p>
                </form>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var ajaxUrl = (typeof ajaxurl !== 'undefined') ? ajaxurl : '<?php echo esc_js(admin_url('admin-ajax.php')); ?>';

            function setProgress(pct, text) {
                $('#rts-import-progress').show();
                $('#rts-progress-bar').css('width', Math.max(0, Math.min(100, pct)) + '%');
                $('#rts-progress-text').text(text || '');
            }

            function showDone(message) {
                setProgress(100, message || 'Import complete.');
                $('#rts-import-form button[type="submit"]').prop('disabled', false).text('📥 Start Import');
            }

            $('#rts-import-form').on
    /**
     * AJAX: Start chunked import (uploads file + creates job)
     */
    public function ajax_import_start() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'rts_import')) {
            wp_send_json_error(['message' => 'Security check failed. Please refresh and try again.'], 403);
        }

        if (empty($_FILES['rts_import_file']) || empty($_FILES['rts_import_file']['tmp_name'])) {
            wp_send_json_error(['message' => 'No import file received.'], 400);
        }

        $auto_tag = !empty($_POST['rts_auto_tag']);
        $skip_duplicates = !empty($_POST['rts_skip_duplicates']);
        $default_status = in_array($_POST['rts_default_status'] ?? 'pending', ['pending', 'publish', 'draft'], true)
            ? sanitize_key($_POST['rts_default_status'])
            : 'pending';

        $uploaded = $_FILES['rts_import_file'];
        $file_ext = strtolower(pathinfo($uploaded['name'], PATHINFO_EXTENSION));

        if (!in_array($file_ext, ['csv', 'json'], true)) {
            wp_send_json_error(['message' => 'Invalid file type. Please upload CSV or JSON.'], 400);
        }

        $upload_dir = wp_upload_dir();
        $imports_dir = trailingslashit($upload_dir['basedir']) . 'rts-imports/';
        if (!file_exists($imports_dir)) {
            wp_mkdir_p($imports_dir);
        }

        $import_id = wp_generate_uuid4();
        $dest = $imports_dir . 'rts-import-' . $import_id . '.' . $file_ext;

        if (!@move_uploaded_file($uploaded['tmp_name'], $dest)) {
            wp_send_json_error(['message' => 'Failed to store uploaded file.'], 500);
        }

        // Count items + read headers (CSV)
        $total = 0;
        $headers = [];

        if ($file_ext === 'csv') {
            $fh = fopen($dest, 'r');
            if (!$fh) {
                wp_send_json_error(['message' => 'Could not read CSV file.'], 500);
            }
            $headers = fgetcsv($fh);
            if (!is_array($headers) || !in_array('letter_text', $headers, true)) {                wp_send_json_error(['message' => 'Missing required column: letter_text'], 400);
            }
            // Count remaining lines
            while (fgetcsv($fh) !== false) {
                $total++;
            }
            // Save byte position for next chunk (prevents CSV drift slowdown)
            $job['file_pointer'] = ftell($fh);

            fclose($fh);
        } else {
            $json = file_get_contents($dest);
            $letters = json_decode($json, true);
            if (!is_array($letters)) {
                wp_send_json_error(['message' => 'Invalid JSON format.'], 400);
            }
            $total = count($letters);
        }

        // Enable import mode protection so hooks (images/tags/scans) can be skipped
        set_transient('rts_import_mode', 'active', 2 * HOUR_IN_SECONDS);

        $job = [
            'id' => $import_id,
            'path' => $dest,
            'ext' => $file_ext,
            'headers' => $headers,
            'offset' => 0,
            'file_pointer' => 0, // CSV byte position for fast seeking
 // CSV offset counts data rows processed; header row already read
            'chunk_size' => 200,
            'total' => (int) $total,
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'options' => [
                'auto_tag' => $auto_tag,
                'skip_duplicates' => $skip_duplicates,
                'default_status' => $default_status,
            ],
        ];

        set_transient('rts_import_job_' . $import_id, $job, 2 * HOUR_IN_SECONDS);

        wp_send_json_success([
            'import_id' => $import_id,
            'total' => (int) $total,
            'chunk_size' => (int) $job['chunk_size'],
        ]);
    }

    /**
     * AJAX: Process next chunk of an import job
     */
    public function ajax_import_chunk() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Insufficient permissions.'], 403);
        }

        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (!wp_verify_nonce($nonce, 'rts_import')) {
            wp_send_json_error(['message' => 'Security check failed. Please refresh and try again.'], 403);
        }

        $import_id = isset($_POST['import_id']) ? sanitize_text_field($_POST['import_id']) : '';
        if (empty($import_id)) {
            wp_send_json_error(['message' => 'Missing import_id.'], 400);
        }

        $job = get_transient('rts_import_job_' . $import_id);
        if (empty($job) || empty($job['path']) || !file_exists($job['path'])) {
            wp_send_json_error(['message' => 'Import job not found or expired. Please start again.'], 404);
        }

        $chunk_size = isset($job['chunk_size']) ? (int) $job['chunk_size'] : 50;
        $processed_this = 0;

        if ($job['ext'] === 'csv') {
            $fh = fopen($job['path'], 'r');
            if (!$fh) {
                wp_send_json_error(['message' => 'Could not read CSV file.'], 500);
            }

            // Fast CSV resume: seek to last byte position instead of skipping rows
            if (!empty($job['file_pointer']) && is_numeric($job['file_pointer']) && (int) $job['file_pointer'] > 0) {
                // Resume from last saved position
                fseek($fh, (int) $job['file_pointer']);
                $headers = $job['headers'];
            } else {
                // First run: read and store headers, then store the starting position
                $headers = $job['headers'] ?: fgetcsv($fh);
                if (!$job['headers']) {
                    $job['headers'] = $headers;
                }
                $job['file_pointer'] = ftell($fh); // position after header row
            }

            while ($processed_this < $chunk_size && ($row = fgetcsv($fh)) !== false) {
                if (!is_array($row) || count($row) < 1) continue;
                $data = array_combine($headers, array_pad($row, count($headers), ''));
                $result = $this->import_single_letter($data, $job['options']);
                if ($result === 'imported') $job['imported']++;
                elseif ($result === 'skipped') $job['skipped']++;
                else $job['errors']++;

                $processed_this++;
                $job['offset']++;
            }

            fclose($fh);
        } else {
            $json = file_get_contents($job['path']);
            $letters = json_decode($json, true);
            if (!is_array($letters)) {
                wp_send_json_error(['message' => 'Invalid JSON format.'], 400);
            }

            $start = (int) $job['offset'];
            $end = min($start + $chunk_size, count($letters));

            for ($i = $start; $i < $end; $i++) {
                $result = $this->import_single_letter((array)$letters[$i], $job['options']);
                if ($result === 'imported') $job['imported']++;
                elseif ($result === 'skipped') $job['skipped']++;
                else $job['errors']++;

                $processed_this++;
                $job['offset']++;
            }
        }

        $done = ($job['offset'] >= $job['total']);

        if ($done) {
            // Cleanup
            delete_transient('rts_import_job_' . $import_id);
            delete_transient('rts_import_mode');
            if (!empty($job['path']) && file_exists($job['path'])) {
                @unlink($job['path']);
            }
        } else {
            set_transient('rts_import_job_' . $import_id, $job, 2 * HOUR_IN_SECONDS);
        }

        wp_send_json_success([
            'processed' => (int) $job['offset'],
            'total' => (int) $job['total'],
            'imported' => (int) $job['imported'],
            'skipped' => (int) $job['skipped'],
            'errors' => (int) $job['errors'],
            'done' => (bool) $done,
        ]);
    }


('submit', function(e) {
                e.preventDefault();

                var $btn = $('#rts-import-form button[type="submit"]');
                $btn.prop('disabled', true).text('Starting…');

                var nonce = $('input[name="rts_import_nonce"]').val() || '';
                var fileInput = $('input[name="rts_import_file"]')[0];

                if (!fileInput || !fileInput.files || !fileInput.files[0]) {
                    alert('Please choose a CSV or JSON file first.');
                    $btn.prop('disabled', false).text('📥 Start Import');
                    return;
                }

                var formData = new FormData();
                formData.append('action', 'rts_import_start');
                formData.append('nonce', nonce);
                formData.append('rts_import_file', fileInput.files[0]);
                formData.append('rts_auto_tag', $('input[name="rts_auto_tag"]').is(':checked') ? '1' : '0');
                formData.append('rts_skip_duplicates', $('input[name="rts_skip_duplicates"]').is(':checked') ? '1' : '0');
                formData.append('rts_default_status', $('select[name="rts_default_status"]').val() || 'pending');

                setProgress(1, 'Uploading file & preparing import…');

                $.ajax({
                    url: ajaxUrl,
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    timeout: 60000
                }).done(function(res) {
                    if (!res || !res.success || !res.data || !res.data.import_id) {
                        var msg = (res && res.data && res.data.message) ? res.data.message : 'Import start failed.';
                        alert(msg);
                        $btn.prop('disabled', false).text('📥 Start Import');
                        return;
                    }

                    var importId = res.data.import_id;
                    var total = parseInt(res.data.total || 0, 10);
                    var chunkSize = parseInt(res.data.chunk_size || 200, 10);

                    if (!total || total < 1) total = 1;

                    var imported = 0, skipped = 0, errors = 0, processed = 0;

                    function runChunk() {
                        $.ajax({
                            url: ajaxUrl,
                            method: 'POST',
                            dataType: 'json',
                            timeout: 60000,
                            data: {
                                action: 'rts_import_chunk',
                                nonce: nonce,
                                import_id: importId
                            }
                        }).done(function(r) {
                            if (!r || !r.success || !r.data) {
                                var msg = (r && r.data && r.data.message) ? r.data.message : 'Import chunk failed.';
                                alert(msg);
                                $btn.prop('disabled', false).text('📥 Start Import');
                                return;
                            }

                            imported = parseInt(r.data.imported || imported, 10);
                            skipped  = parseInt(r.data.skipped || skipped, 10);
                            errors   = parseInt(r.data.errors || errors, 10);
                            processed = parseInt(r.data.processed || processed, 10);

                            var pct = Math.round((processed / total) * 100);
                            setProgress(pct, 'Imported: ' + imported + '  |  Skipped: ' + skipped + '  |  Errors: ' + errors + '  |  Progress: ' + processed + '/' + total);

                            if (r.data.done) {
                                showDone('✅ Import finished. Imported: ' + imported + '  |  Skipped: ' + skipped + '  |  Errors: ' + errors + '. You can now use “Bulk Processor” for post-import operations.');
                                return;
                            }

                            // small breathing space to keep admin responsive
                            setTimeout(runChunk, 250);
                        }).fail(function(xhr) {
                            alert('Import chunk request failed. Please try again. (HTTP ' + (xhr.status || '?') + ')');
                            $btn.prop('disabled', false).text('📥 Start Import');
                        });
                    }

                    $btn.text('Importing…');
                    setProgress(2, 'Starting import…');
                    runChunk();

                }).fail(function(xhr) {
                    alert('Import start request failed. Please try again. (HTTP ' + (xhr.status || '?') + ')');
                    $btn.prop('disabled', false).text('📥 Start Import');
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Handle CSV template download
     */
    public function handle_export() {
        // Handle template download
        if (isset($_GET['action']) && $_GET['action'] === 'download_template' && 
            isset($_GET['page']) && $_GET['page'] === 'rts-import-export') {
            
            if (!current_user_can('manage_options')) {
                wp_die('Insufficient permissions');
            }
            
            // Generate CSV template
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="rts-import-template-' . date('Y-m-d') . '.csv"');
            
            $output = fopen('php://output', 'w');
            
            // UTF-8 BOM for Excel compatibility
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // Headers
            fputcsv($output, [
                'letter_text',
                'author_name', 
                'author_email',
                'submitted_at',
                'feelings',
                'tone',
                'status'
            ]);
            
            // Example rows
            fputcsv($output, [
                'Hey friend, I want you to know that you matter. Your life has value and meaning, even when it doesn\'t feel that way. Please reach out to someone - a friend, family member, or crisis helpline. You don\'t have to face this alone.',
                'Alex',
                'alex@example.com',
                date('Y-m-d H:i:s'),
                'hopeless,alone',
                'gentle',
                'publish'
            ]);
            
            fputcsv($output, [
                'Write your letter text here... Keep it sincere and supportive.',
                'Your Name',
                'email@example.com',
                '',
                '',
                '',
                'pending'
            ]);
            
            fputcsv($output, [
                'Another example letter...',
                '',
                '',
                '',
                'struggling,anxious',
                'real',
                'publish'
            ]);
            
            fclose($output);
            exit;
        }
        
        // Handle full export
        if (!isset($_POST['rts_start_export'])) {
            return;
        }
        
        if (!wp_verify_nonce($_POST['rts_export_nonce'], 'rts_export')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        $format = sanitize_text_field($_POST['rts_export_format']);
        $statuses = isset($_POST['rts_export_status']) ? array_map('sanitize_text_field', $_POST['rts_export_status']) : ['publish'];
        
        if ($format === 'csv') {
            $this->export_csv($statuses);
        } else {
            $this->export_json($statuses);
        }
    }
    
    /**
     * Export to CSV
     */
    private function export_csv($statuses) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rts-export-' . date('Y-m-d') . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // UTF-8 BOM for Excel compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Headers
        fputcsv($output, [
            'letter_id',
            'letter_text',
            'author_name',
            'author_email',
            'submitted_at',
            'published_at',
            'feelings',
            'tone',
            'reading_time',
            'view_count',
            'help_count',
            'status'
        ]);
        
        // Query letters
        $letters = new WP_Query([
            'post_type' => 'letter',
            'post_status' => $statuses,
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);
        
        while ($letters->have_posts()) {
            $letters->the_post();
            $post_id = get_the_ID();
            
            // Get taxonomies
            $feelings = wp_get_post_terms($post_id, 'letter_feeling', ['fields' => 'slugs']);
            $tones = wp_get_post_terms($post_id, 'letter_tone', ['fields' => 'slugs']);
            
            fputcsv($output, [
                $post_id,
                get_the_content(),
                get_post_meta($post_id, 'author_name', true),
                get_post_meta($post_id, 'author_email', true),
                get_post_meta($post_id, 'submitted_at', true),
                get_the_date('Y-m-d H:i:s'),
                implode(',', $feelings),
                !empty($tones) ? $tones[0] : '',
                get_post_meta($post_id, 'reading_time', true),
                get_post_meta($post_id, 'view_count', true),
                get_post_meta($post_id, 'help_count', true),
                get_post_status()
            ]);
        }
        
        wp_reset_postdata();
        fclose($output);
        exit;
    }
    
    /**
     * Export to JSON
     */
    private function export_json($statuses) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="rts-export-' . date('Y-m-d') . '.json"');
        
        $export = [];
        
        $letters = new WP_Query([
            'post_type' => 'letter',
            'post_status' => $statuses,
            'posts_per_page' => -1,
            'orderby' => 'date',
            'order' => 'DESC'
        ]);
        
        while ($letters->have_posts()) {
            $letters->the_post();
            $post_id = get_the_ID();
            
            $feelings = wp_get_post_terms($post_id, 'letter_feeling', ['fields' => 'slugs']);
            $tones = wp_get_post_terms($post_id, 'letter_tone', ['fields' => 'slugs']);
            
            $export[] = [
                'id' => $post_id,
                'letter_text' => get_the_content(),
                'author_name' => get_post_meta($post_id, 'author_name', true),
                'author_email' => get_post_meta($post_id, 'author_email', true),
                'submitted_at' => get_post_meta($post_id, 'submitted_at', true),
                'published_at' => get_the_date('Y-m-d H:i:s'),
                'feelings' => $feelings,
                'tone' => !empty($tones) ? $tones[0] : '',
                'reading_time' => get_post_meta($post_id, 'reading_time', true),
                'view_count' => intval(get_post_meta($post_id, 'view_count', true)),
                'help_count' => intval(get_post_meta($post_id, 'help_count', true)),
                'status' => get_post_status()
            ];
        }
        
        wp_reset_postdata();
        
        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    /**
     * Handle import
     */
    public function handle_import() {
        if (!isset($_POST['rts_start_import'])) {
            return;
        }
        
        if (!wp_verify_nonce($_POST['rts_import_nonce'], 'rts_import')) {
            wp_die('Security check failed');
        }
        
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions');
        }
        
        if (!isset($_FILES['rts_import_file'])) {
            wp_die('No file uploaded');
        }
        
        $file = $_FILES['rts_import_file'];
        $file_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if (!in_array($file_ext, ['csv', 'json'])) {
            wp_die('Invalid file format. Please upload CSV or JSON.');
        }
        
        $options = [
            'auto_tag' => isset($_POST['rts_auto_tag']),
            'skip_duplicates' => isset($_POST['rts_skip_duplicates']),
            'default_status' => sanitize_text_field($_POST['rts_default_status'])
        ];
        
        // CRITICAL: Set import mode to disable automatic processing
        // This prevents 33k letters from auto-generating images during import
        set_transient('rts_import_mode', 'active', HOUR_IN_SECONDS);
        
        if ($file_ext === 'csv') {
            $result = $this->import_csv($file['tmp_name'], $options);
        } else {
            $result = $this->import_json($file['tmp_name'], $options);
        }
        
        // Clear import mode
        delete_transient('rts_import_mode');
        
        // Show message about background processing
        $message = "imported={$result['imported']}&skipped={$result['skipped']}&errors={$result['errors']}";
        
        if ($result['imported'] > 100) {
            $message .= '&needs_processing=1';
        }
        
        wp_redirect(admin_url('edit.php?post_type=letter&page=rts-import-export&' . $message));
        exit;
    }
    
    /**
     * Import from CSV
     */
    private function import_csv($file_path, $options) {
        $imported = 0;
        $skipped = 0;
        $errors = 0;
        
        if (($handle = fopen($file_path, 'r')) !== false) {
            $headers = fgetcsv($handle);
            
            // Validate headers
            if (!in_array('letter_text', $headers)) {
                return ['imported' => 0, 'skipped' => 0, 'errors' => 1, 'message' => 'Missing required column: letter_text'];
            }
            
            while (($row = fgetcsv($handle)) !== false) {
                $data = array_combine($headers, $row);
                
                $result = $this->import_single_letter($data, $options);
                
                if ($result === 'imported') {
                    $imported++;
                } elseif ($result === 'skipped') {
                    $skipped++;
                } else {
                    $errors++;
                }
            }
            
            fclose($handle);
        }
        
        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }
    
    /**
     * Import from JSON
     */
    private function import_json($file_path, $options) {
        $json = file_get_contents($file_path);
        $letters = json_decode($json, true);
        
        if (!is_array($letters)) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => 1, 'message' => 'Invalid JSON format'];
        }
        
        $imported = 0;
        $skipped = 0;
        $errors = 0;
        
        foreach ($letters as $data) {
            $result = $this->import_single_letter($data, $options);
            
            if ($result === 'imported') {
                $imported++;
            } elseif ($result === 'skipped') {
                $skipped++;
            } else {
                $errors++;
            }
        }
        
        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }
    
    /**
     * Import single letter
     */
    private function import_single_letter($data, $options) {
        $letter_text = sanitize_textarea_field($data['letter_text'] ?? '');
        
        if (empty($letter_text)) {
            return 'error';
        }
        
        // Compute a stable hash for duplicate detection
        $hash = md5(trim(strtolower($letter_text)));

        // Check for duplicates (fast meta lookup)
        if ($options['skip_duplicates']) {
            if ($this->is_duplicate($hash)) {
                return 'skipped';
            }
        }
        
        // Create letter
        $post_data = [
            'post_type' => 'letter',
            'post_content' => $letter_text,
            'post_status' => $options['default_status'],
            'post_title' => 'Letter - ' . date('M j, Y'),
            'meta_input' => [
                '_skip_auto_approval' => '1' // Flag to skip auto-approval during bulk import
            ]
        ];
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            return 'error';
        }

        // Store hash for fast duplicate checks
        update_post_meta($post_id, 'rts_letter_hash', $hash);
        
        // Remove skip flag after import
        delete_post_meta($post_id, '_skip_auto_approval');
        
        // Add meta data
        if (!empty($data['author_name'])) {
            update_post_meta($post_id, 'author_name', sanitize_text_field($data['author_name']));
        }
        
        if (!empty($data['author_email'])) {
            update_post_meta($post_id, 'author_email', sanitize_email($data['author_email']));
        }
        
        if (!empty($data['submitted_at'])) {
            update_post_meta($post_id, 'submitted_at', sanitize_text_field($data['submitted_at']));
        }
        
        // Add taxonomies
        if (!empty($data['feelings'])) {
            $feelings = array_map('trim', explode(',', $data['feelings']));
            wp_set_post_terms($post_id, $feelings, 'letter_feeling');
        }
        
        if (!empty($data['tone'])) {
            wp_set_post_terms($post_id, [trim($data['tone'])], 'letter_tone');
        }
        
        // ALWAYS run content analyzer on imported letters
        if (class_exists('RTS_Content_Analyzer')) {
            RTS_Content_Analyzer::get_instance()->analyze_and_tag($post_id);
        }
        
        return 'imported';
    }
    
    /**
     * Check if letter is duplicate
     */
    private function is_duplicate($hash) {
        $hash = sanitize_text_field((string) $hash);
        if (empty($hash)) return false;

        $q = new WP_Query([
            'post_type' => 'letter',
            'post_status' => 'any',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'meta_query' => [
                [
                    'key' => 'rts_letter_hash',
                    'value' => $hash,
                    'compare' => '='
                ]
            ]
        ]);

        return $q->have_posts();
    }
}

// Initialize
RTS_Import_Export::get_instance();
