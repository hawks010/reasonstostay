<?php
/**
 * RTS Bulk Letter Processor
 * Process unrated letters in batches with progress feedback
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RTS_Bulk_Letter_Processor')) {

class RTS_Bulk_Letter_Processor {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // AJAX endpoints
        add_action('wp_ajax_rts_process_unrated_letters', [$this, 'ajax_process_unrated']);
        
        // Admin notice to process unrated letters
        add_action('admin_notices', [$this, 'show_unrated_notice']);
    }
    
    /**
     * Show admin notice for unrated letters
     */
    public function show_unrated_notice() {
        $screen = get_current_screen();
        
        if ($screen && $screen->post_type === 'letter') {
            global $wpdb;
            
            // Count unrated letters (no quality_score meta)
            $unrated_count = $wpdb->get_var("
                SELECT COUNT(DISTINCT p.ID)
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'quality_score'
                WHERE p.post_type = 'letter'
                AND p.post_status IN ('publish', 'pending')
                AND pm.meta_id IS NULL
            ");
            
            if ($unrated_count > 0) {
                ?>
                <div class="notice notice-warning is-dismissible" id="rts-unrated-notice">
                    <p>
                        <strong>⚠️ <?php echo number_format($unrated_count); ?> letters need processing!</strong><br>
                        These letters haven't been analyzed for quality and safety yet.
                        <em style="margin-left:6px;opacity:.85;">(This also runs automatically in the background.)</em>
                        <button type="button" class="button button-primary" id="rts-process-unrated-btn" style="margin-left: 10px;">
                            Process Now
                        </button>
                        <span id="rts-process-progress" style="margin-left: 15px; display: none;">
                            <span class="spinner is-active" style="float: none; margin: 0 5px 0 0;"></span>
                            <strong id="rts-process-status">Processing...</strong> 
                            <span id="rts-process-count">0 / <?php echo $unrated_count; ?></span>
                        </span>
                    </p>
                </div>
                
                <script>
                jQuery(document).ready(function($) {
                    $('#rts-process-unrated-btn').on('click', function() {
                        var btn = $(this);
                        var progress = $('#rts-process-progress');
                        var status = $('#rts-process-status');
                        var count = $('#rts-process-count');
                        
                        btn.prop('disabled', true).text('Processing...');
                        progress.show();
                        
                        // Server returns cumulative totals (e.g. total_rated), so we never add.
                        // We just display what the server says and clamp to avoid weird UI.
                        var lastProcessed = 0;

                        // Lightweight progress bar for clear visual feedback.
                        if (!$('#rts-process-bar').length) {
                            progress.append('<div id="rts-process-bar" style="margin-top:8px;height:10px;background:rgba(0,0,0,.08);border-radius:999px;overflow:hidden;max-width:420px"><div id="rts-process-bar-inner" style="height:100%;width:0%;background:#2271b1"></div></div>');
                        }
                        var barInner = $('#rts-process-bar-inner');

                        function processChunk() {
                            $.ajax({
                                url: ajaxurl,
                                type: 'POST',
                                data: {
                                    action: 'rts_process_unrated_letters',
                                    nonce: '<?php echo wp_create_nonce('rts_process_unrated'); ?>'
                                },
                                success: function(response) {
                                    if (response.success) {
                                        var processed = parseInt(response.data.processed || 0, 10);
                                        var total = parseInt(response.data.total || 0, 10);
                                        var done = response.data.done;

                                        // Clamp and render (fixes the "15050 / 5231" bug)
                                        if (total > 0) {
                                            processed = Math.min(processed, total);
                                        }
                                        lastProcessed = processed;
                                        count.text(processed + ' / ' + total);
                                        if (total > 0) {
                                            var pct = Math.min(100, Math.round((processed / total) * 100));
                                            barInner.css('width', pct + '%');
                                        }
                                        
                                        if (done) {
                                            status.text('Complete!');
                                            btn.text('Done ✓').prop('disabled', false);
                                            setTimeout(function() {
                                                $('#rts-unrated-notice').fadeOut();
                                            }, 2000);
                                        } else {
                                            // Process next chunk
                                            setTimeout(processChunk, 500);
                                        }
                                    } else {
                                        status.text('Error: ' + (response.data || 'Unknown error'));
                                        btn.prop('disabled', false).text('Retry');
                                    }
                                },
                                error: function() {
                                    status.text('Network error');
                                    btn.prop('disabled', false).text('Retry');
                                }
                            });
                        }
                        
                        processChunk();
                    });
                });
                </script>
                <?php
            }
        }
    }
    
    /**
     * AJAX handler for processing unrated letters
     */
    public function ajax_process_unrated() {
        check_ajax_referer('rts_process_unrated', 'nonce');
        
        // Allow the site owner (often Editor on small sites) to run processing.
        // The page is still under wp-admin and protected by nonce.
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        global $wpdb;
        
        // Get unrated letters (batch of 25)
        $letters = $wpdb->get_col("
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'quality_score'
            WHERE p.post_type = 'letter'
            AND p.post_status IN ('publish', 'pending')
            AND pm.meta_id IS NULL
            LIMIT 25
        ");
        
        if (empty($letters)) {
            wp_send_json_success([
                'processed' => 0,
                'total' => 0,
                'done' => true
            ]);
        }
        
        // Process each letter
        $processed = 0;
        if (class_exists('RTS_Content_Analyzer')) {
            $analyzer = RTS_Content_Analyzer::get_instance();
            
            foreach ($letters as $letter_id) {
                // Force so previously queued/unprocessed items get handled.
                $analyzer->analyze_and_tag($letter_id, true);
                $processed++;
            }
        }
        
        // Count remaining unrated
        $remaining = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'quality_score'
            WHERE p.post_type = 'letter'
            AND p.post_status IN ('publish', 'pending')
            AND pm.meta_id IS NULL
        ");
        
        // Calculate total processed so far (all letters with quality_score)
        $total_rated = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = 'quality_score'
            WHERE p.post_type = 'letter'
            AND p.post_status IN ('publish', 'pending')
        ");
        
        $total = $total_rated + $remaining;
        
        wp_send_json_success([
            'processed' => $total_rated,
            'total' => $total,
            'done' => ($remaining == 0),
            'batch_size' => $processed
        ]);
    }
}

// Initialize
RTS_Bulk_Letter_Processor::get_instance();

} // end class_exists check
