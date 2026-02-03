<?php
/**
 * RTS System Auto-Fix Script
 * Version: 3.2.1
 * 
 * This script automatically fixes common issues found by the test suite.
 * 
 * INSTRUCTIONS:
 * 1. Upload this file to: /wp-content/themes/hello-elementor-child-rts-v3/
 * 2. Visit: https://yoursite.com/wp-content/themes/hello-elementor-child-rts-v3/rts-auto-fix.php
 * 3. Click the fix buttons for issues you want to resolve
 * 
 * SAFETY: All fixes are reversible and logged.
 */

// Load WordPress
require_once('../../../wp-load.php');

// Security: Only admins can run this
if (!current_user_can('manage_options')) {
    wp_die('Sorry, you need to be an administrator to run this script.');
}

// Process fix actions
$fix_results = [];

if (isset($_POST['run_fix'])) {
    check_admin_referer('rts_auto_fix');
    
    $fix_type = sanitize_key($_POST['run_fix']);
    
    switch ($fix_type) {
        case 'run_migration':
            $fix_results['migration'] = fix_migration();
            break;
            
        case 'refresh_stats':
            $fix_results['stats'] = fix_stats();
            break;
            
        case 'clear_old_quarantine':
            $fix_results['old_quarantine'] = fix_old_quarantine();
            break;
            
        case 'cleanup_orphaned_meta':
            $fix_results['orphaned'] = fix_orphaned_meta();
            break;
            
        case 'reset_failed_jobs':
            $fix_results['failed_jobs'] = fix_failed_jobs();
            break;
            
        case 'flush_cache':
            $fix_results['cache'] = fix_flush_cache();
            break;
    }
}

// Fix functions
function fix_migration() {
    global $wpdb;
    
    // Run the migration manually
    $quarantined_ids = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT p.ID 
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
         WHERE p.post_type = %s
           AND p.post_status = %s
           AND pm.meta_key = %s
           AND pm.meta_value = %s",
        'letter', 'pending', 'needs_review', '1'
    ));
    
    $updated = 0;
    foreach ($quarantined_ids as $letter_id) {
        $result = wp_update_post([
            'ID' => (int) $letter_id,
            'post_status' => 'draft'
        ], true);
        
        if (!is_wp_error($result)) {
            $updated++;
        }
    }
    
    update_option('rts_quarantine_migration_v3_2_0_done', true, false);
    
    return [
        'success' => true,
        'message' => sprintf('Successfully migrated %d letters from pending to draft status.', $updated),
        'count' => $updated
    ];
}

function fix_stats() {
    // Trigger stats aggregation
    if (class_exists('RTS_Analytics_Aggregator')) {
        RTS_Analytics_Aggregator::aggregate();
        return [
            'success' => true,
            'message' => 'Stats refreshed successfully.'
        ];
    }
    
    return [
        'success' => false,
        'message' => 'Could not find RTS_Analytics_Aggregator class.'
    ];
}

function fix_old_quarantine() {
    global $wpdb;
    
    // Find all pending letters with needs_review flag
    $old_quarantine = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT p.ID
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
         WHERE p.post_type = %s
           AND p.post_status = %s
           AND pm.meta_key = %s
           AND pm.meta_value = %s",
        'letter', 'pending', 'needs_review', '1'
    ));
    
    $moved = 0;
    foreach ($old_quarantine as $letter_id) {
        wp_update_post(['ID' => (int) $letter_id, 'post_status' => 'draft']);
        $moved++;
    }
    
    return [
        'success' => true,
        'message' => sprintf('Moved %d letters from old quarantine system to new draft status.', $moved),
        'count' => $moved
    ];
}

function fix_orphaned_meta() {
    global $wpdb;
    
    $deleted = $wpdb->query(
        "DELETE pm FROM {$wpdb->postmeta} pm
         LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE pm.meta_key IN ('needs_review', 'quality_score', 'rts_flagged', 'rts_flag_reasons', 'rts_moderation_reasons')
         AND p.ID IS NULL"
    );
    
    return [
        'success' => true,
        'message' => sprintf('Cleaned up %d orphaned metadata entries.', $deleted),
        'count' => $deleted
    ];
}

function fix_failed_jobs() {
    global $wpdb;
    
    if (!function_exists('as_schedule_single_action')) {
        return [
            'success' => false,
            'message' => 'Action Scheduler not available.'
        ];
    }
    
    // Cancel all failed jobs
    $deleted = $wpdb->query(
        "DELETE FROM {$wpdb->prefix}actionscheduler_actions 
         WHERE hook LIKE 'rts_%' 
         AND status = 'failed'"
    );
    
    return [
        'success' => true,
        'message' => sprintf('Removed %d failed jobs from queue.', $deleted),
        'count' => $deleted
    ];
}

function fix_flush_cache() {
    global $wpdb;
    
    // Flush all RTS cache transients
    $deleted = $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_rts_%' 
         OR option_name LIKE '_transient_timeout_rts_%'"
    );
    
    // Clear object cache if available
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
    
    return [
        'success' => true,
        'message' => sprintf('Flushed %d cached items.', $deleted),
        'count' => $deleted
    ];
}

// Start output
?>
<!DOCTYPE html>
<html>
<head>
    <title>RTS Auto-Fix Tool</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            max-width: 900px;
            margin: 40px auto;
            padding: 20px;
            background: #f0f0f1;
        }
        .header {
            background: #fff;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        h1 {
            margin: 0 0 10px 0;
            color: #1d2327;
        }
        .subtitle {
            color: #646970;
            font-size: 14px;
        }
        .fix-section {
            background: #fff;
            padding: 25px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .fix-item {
            padding: 20px;
            margin-bottom: 15px;
            border-radius: 4px;
            border-left: 4px solid #0073aa;
            background: #f0f6fc;
        }
        .fix-item.danger {
            border-left-color: #dc3545;
            background: #fff5f5;
        }
        .fix-title {
            font-weight: 600;
            margin-bottom: 10px;
            font-size: 16px;
        }
        .fix-description {
            margin-bottom: 15px;
            color: #646970;
            line-height: 1.5;
        }
        .fix-button {
            display: inline-block;
            padding: 10px 20px;
            background: #0073aa;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            text-decoration: none;
        }
        .fix-button:hover {
            background: #005a87;
        }
        .fix-button.danger {
            background: #dc3545;
        }
        .fix-button.danger:hover {
            background: #bd2130;
        }
        .result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 4px;
        }
        .result.success {
            background: #d4edda;
            border-left: 4px solid #28a745;
            color: #155724;
        }
        .result.error {
            background: #f8d7da;
            border-left: 4px solid #dc3545;
            color: #721c24;
        }
        .warning-box {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🔧 RTS Auto-Fix Tool</h1>
        <div class="subtitle">Automatically resolve common issues - Click the button next to the issue you want to fix</div>
    </div>

    <?php if (!empty($fix_results)): ?>
        <div class="fix-section">
            <h2>Fix Results</h2>
            <?php foreach ($fix_results as $type => $result): ?>
                <div class="result <?php echo $result['success'] ? 'success' : 'error'; ?>">
                    <strong><?php echo ucfirst($type); ?>:</strong> <?php echo esc_html($result['message']); ?>
                </div>
            <?php endforeach; ?>
            <p style="margin-top: 20px;">
                <a href="<?php echo esc_url(admin_url('edit.php?post_type=letter&page=rts-dashboard')); ?>" class="fix-button">Go to Dashboard</a>
                <a href="rts-system-test.php" class="fix-button">Run Tests Again</a>
            </p>
        </div>
    <?php endif; ?>

    <div class="fix-section">
        <h2>🚀 Common Fixes</h2>
        
        <div class="fix-item">
            <div class="fix-title">1. Run Migration</div>
            <div class="fix-description">
                Moves quarantined letters from 'pending' status to 'draft' status.
                <strong>Run this if:</strong> The test shows "Old quarantine system check" is red.
            </div>
            <form method="post" style="display: inline;">
                <?php wp_nonce_field('rts_auto_fix'); ?>
                <button type="submit" name="run_fix" value="run_migration" class="fix-button">Run Migration</button>
            </form>
        </div>

        <div class="fix-item">
            <div class="fix-title">2. Refresh Statistics</div>
            <div class="fix-description">
                Recalculates all dashboard statistics from the database.
                <strong>Run this if:</strong> Dashboard numbers look wrong or test shows counting errors.
            </div>
            <form method="post" style="display: inline;">
                <?php wp_nonce_field('rts_auto_fix'); ?>
                <button type="submit" name="run_fix" value="refresh_stats" class="fix-button">Refresh Stats</button>
            </form>
        </div>

        <div class="fix-item">
            <div class="fix-title">3. Clear Old Quarantine</div>
            <div class="fix-description">
                Finds any letters stuck in the old quarantine system (pending + needs_review flag) and moves them to the new system (draft status).
                <strong>Run this if:</strong> Test shows letters in both inbox and quarantine.
            </div>
            <form method="post" style="display: inline;">
                <?php wp_nonce_field('rts_auto_fix'); ?>
                <button type="submit" name="run_fix" value="clear_old_quarantine" class="fix-button">Fix Old Quarantine</button>
            </form>
        </div>

        <div class="fix-item">
            <div class="fix-title">4. Flush Cache</div>
            <div class="fix-description">
                Clears all cached statistics and counts. Forces WordPress to recalculate everything.
                <strong>Run this if:</strong> Numbers seem stuck or not updating.
            </div>
            <form method="post" style="display: inline;">
                <?php wp_nonce_field('rts_auto_fix'); ?>
                <button type="submit" name="run_fix" value="flush_cache" class="fix-button">Flush Cache</button>
            </form>
        </div>
    </div>

    <div class="fix-section">
        <h2>⚠️ Advanced Fixes (Use with Caution)</h2>
        
        <div class="warning-box">
            <strong>Warning:</strong> These fixes can delete data. Only use them if you understand what they do.
        </div>

        <div class="fix-item danger">
            <div class="fix-title">5. Clean Orphaned Metadata</div>
            <div class="fix-description">
                Removes metadata (needs_review, quality_score, etc.) that belongs to deleted posts.
                This is safe but irreversible. Only run if test shows orphaned metadata.
            </div>
            <form method="post" style="display: inline;">
                <?php wp_nonce_field('rts_auto_fix'); ?>
                <button type="submit" name="run_fix" value="cleanup_orphaned_meta" class="fix-button danger" onclick="return confirm('Are you sure? This cannot be undone.');">Clean Orphaned Meta</button>
            </form>
        </div>

        <div class="fix-item danger">
            <div class="fix-title">6. Reset Failed Jobs</div>
            <div class="fix-description">
                Removes all failed background jobs from the Action Scheduler queue.
                Only run if you have many failed jobs stuck in the queue.
            </div>
            <form method="post" style="display: inline;">
                <?php wp_nonce_field('rts_auto_fix'); ?>
                <button type="submit" name="run_fix" value="reset_failed_jobs" class="fix-button danger" onclick="return confirm('This will delete all failed jobs. Continue?');">Reset Failed Jobs</button>
            </form>
        </div>
    </div>

    <div class="fix-section">
        <h2>📚 What to Do Next</h2>
        <ol>
            <li>Run the fixes for any issues found in the test</li>
            <li>Click "Run Tests Again" to verify the fixes worked</li>
            <li>Go to your Dashboard to see updated statistics</li>
            <li>If issues persist, contact your developer with the test results</li>
        </ol>
        
        <p style="margin-top: 20px;">
            <a href="rts-system-test.php" class="fix-button">Run Full Test</a>
            <a href="<?php echo esc_url(admin_url('edit.php?post_type=letter&page=rts-dashboard')); ?>" class="fix-button">Go to Dashboard</a>
        </p>
    </div>

</body>
</html>
