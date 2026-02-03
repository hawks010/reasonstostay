<?php
/**
 * RTS System Automated Test Suite
 * Version: 3.2.1
 * 
 * INSTRUCTIONS:
 * 1. Upload this file to: /wp-content/themes/hello-elementor-child-rts-v3/
 * 2. Visit: https://yoursite.com/wp-content/themes/hello-elementor-child-rts-v3/rts-system-test.php
 * 3. View results - all green = perfect, any red = needs fixing
 * 
 * NO TECHNICAL KNOWLEDGE REQUIRED!
 */

// Load WordPress
require_once('../../../wp-load.php');

// Security: Only admins can run this
if (!current_user_can('manage_options')) {
    wp_die('Sorry, you need to be an administrator to run this test.');
}

// Start output
?>
<!DOCTYPE html>
<html>
<head>
    <title>RTS System Test Results</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            max-width: 1200px;
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
        .test-section {
            background: #fff;
            padding: 25px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .test-section h2 {
            margin: 0 0 20px 0;
            padding-bottom: 10px;
            border-bottom: 2px solid #f0f0f1;
            color: #1d2327;
        }
        .test-item {
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 4px;
            border-left: 4px solid #ddd;
        }
        .test-item.pass {
            background: #d4edda;
            border-left-color: #28a745;
        }
        .test-item.fail {
            background: #f8d7da;
            border-left-color: #dc3545;
        }
        .test-item.warning {
            background: #fff3cd;
            border-left-color: #ffc107;
        }
        .test-item.info {
            background: #d1ecf1;
            border-left-color: #17a2b8;
        }
        .test-name {
            font-weight: 600;
            margin-bottom: 5px;
            display: flex;
            align-items: center;
        }
        .test-icon {
            margin-right: 10px;
            font-size: 20px;
        }
        .test-result {
            font-size: 13px;
            color: #646970;
            margin-top: 5px;
        }
        .test-action {
            margin-top: 10px;
            padding: 10px;
            background: rgba(0,0,0,0.05);
            border-radius: 4px;
            font-size: 13px;
        }
        .summary {
            background: #fff;
            padding: 25px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .summary-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin-top: 15px;
        }
        .summary-box {
            text-align: center;
            padding: 20px;
            border-radius: 4px;
        }
        .summary-box.pass { background: #d4edda; }
        .summary-box.fail { background: #f8d7da; }
        .summary-box.warning { background: #fff3cd; }
        .summary-box.info { background: #d1ecf1; }
        .summary-number {
            font-size: 36px;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .summary-label {
            font-size: 12px;
            text-transform: uppercase;
            color: #646970;
        }
        .code-block {
            background: #1d2327;
            color: #f0f0f1;
            padding: 15px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
            overflow-x: auto;
            margin-top: 10px;
        }
        .timestamp {
            color: #646970;
            font-size: 12px;
            margin-top: 20px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🔍 RTS System Test Results</h1>
        <div class="subtitle">Automated test suite for version 3.2.1 - No technical knowledge required!</div>
    </div>

<?php

// Initialize test results
$tests_passed = 0;
$tests_failed = 0;
$tests_warning = 0;
$total_tests = 0;

// Helper function to display test result
function display_test($name, $passed, $result, $action = '') {
    global $tests_passed, $tests_failed, $tests_warning, $total_tests;
    $total_tests++;
    
    if ($passed === true) {
        $tests_passed++;
        $class = 'pass';
        $icon = '✅';
    } elseif ($passed === 'warning') {
        $tests_warning++;
        $class = 'warning';
        $icon = '⚠️';
    } elseif ($passed === 'info') {
        $class = 'info';
        $icon = 'ℹ️';
    } else {
        $tests_failed++;
        $class = 'fail';
        $icon = '❌';
    }
    
    echo '<div class="test-item ' . $class . '">';
    echo '<div class="test-name"><span class="test-icon">' . $icon . '</span>' . esc_html($name) . '</div>';
    echo '<div class="test-result">' . $result . '</div>';
    if ($action) {
        echo '<div class="test-action"><strong>Action needed:</strong> ' . $action . '</div>';
    }
    echo '</div>';
}

global $wpdb;

// ============================================================================
// TEST 1: DATABASE STRUCTURE
// ============================================================================
echo '<div class="test-section">';
echo '<h2>📊 Database Structure Tests</h2>';

// Test 1.1: Check if letter post type exists
$letter_post_type = post_type_exists('letter');
display_test(
    'Letter post type registered',
    $letter_post_type,
    $letter_post_type ? 'Letter post type is properly registered in WordPress.' : 'Letter post type is NOT registered!',
    $letter_post_type ? '' : 'Check if cpt-letters-complete.php is loaded in functions.php'
);

// Test 1.2: Check post status counts
$status_counts = $wpdb->get_results(
    "SELECT post_status, COUNT(*) as count 
     FROM {$wpdb->posts} 
     WHERE post_type = 'letter' 
     GROUP BY post_status"
);

$status_data = [];
foreach ($status_counts as $row) {
    $status_data[$row->post_status] = (int) $row->count;
}

$total_letters = array_sum($status_data);
$published = $status_data['publish'] ?? 0;
$pending = $status_data['pending'] ?? 0;
$draft = $status_data['draft'] ?? 0;

display_test(
    'Post status distribution',
    'info',
    sprintf(
        'Total: %d letters | Published: %d | Inbox (pending): %d | Quarantine (draft): %d',
        $total_letters, $published, $pending, $draft
    )
);

// Test 1.3: Check for old overlapping quarantine (pending + needs_review)
$old_quarantine = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID)
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
         WHERE p.post_type = %s
           AND p.post_status = %s
           AND pm.meta_key = %s
           AND pm.meta_value = %s",
        'letter', 'pending', 'needs_review', '1'
    )
);

display_test(
    'Old quarantine system check (pending + needs_review)',
    $old_quarantine === 0,
    $old_quarantine === 0 
        ? 'Perfect! No letters stuck in old quarantine system.'
        : sprintf('Found %d letters still in old system (pending status with needs_review flag)', $old_quarantine),
    $old_quarantine > 0 ? 'Migration did not complete. Visit site homepage to trigger migration, then refresh this page.' : ''
);

// Test 1.4: Check new quarantine system (draft + needs_review)
$new_quarantine = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID)
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
         WHERE p.post_type = %s
           AND p.post_status = %s
           AND pm.meta_key = %s
           AND pm.meta_value = %s",
        'letter', 'draft', 'needs_review', '1'
    )
);

display_test(
    'New quarantine system check (draft + needs_review)',
    true,
    sprintf('Found %d letters correctly quarantined using new draft status system', $new_quarantine)
);

// Test 1.5: Check for overlap
$overlap_count = ($old_quarantine > 0 && $new_quarantine > 0) ? min($old_quarantine, $new_quarantine) : 0;
display_test(
    'Inbox/Quarantine separation',
    $old_quarantine === 0,
    $old_quarantine === 0 
        ? 'Perfect! Inbox and Quarantine are completely separated.'
        : sprintf('WARNING: %d letters may be counted in both Inbox and Quarantine!', $old_quarantine),
    $old_quarantine > 0 ? 'This is the bug you found. Migration needs to complete.' : ''
);

echo '</div>';

// ============================================================================
// TEST 2: MODERATION ENGINE
// ============================================================================
echo '<div class="test-section">';
echo '<h2>⚙️ Moderation Engine Tests</h2>';

// Test 2.1: Check if moderation engine class exists
$engine_exists = class_exists('RTS_Moderation_Engine');
display_test(
    'Moderation engine loaded',
    $engine_exists,
    $engine_exists ? 'RTS_Moderation_Engine class is loaded and available.' : 'Moderation engine class NOT found!',
    $engine_exists ? '' : 'Check if rts-moderation-engine.php is included in functions.php'
);

// Test 2.2: Check if Action Scheduler is available
$as_available = function_exists('as_schedule_single_action');
display_test(
    'Action Scheduler available',
    $as_available,
    $as_available ? 'Action Scheduler is installed and available for background processing.' : 'Action Scheduler is NOT available!',
    $as_available ? '' : 'Install Action Scheduler plugin or check if it\'s deactivated'
);

// Test 2.3: Check for pending Action Scheduler jobs
if ($as_available) {
    $pending_jobs = $wpdb->get_var(
        "SELECT COUNT(*) 
         FROM {$wpdb->prefix}actionscheduler_actions 
         WHERE hook LIKE 'rts_%' 
         AND status = 'pending'"
    );
    
    display_test(
        'Background processing queue',
        'info',
        sprintf('Found %d pending background jobs in the queue', $pending_jobs ?? 0)
    );
}

// Test 2.4: Check aggregated stats
$stats = get_option('rts_aggregated_stats');
$stats_generated_gmt = $stats['generated_gmt'] ?? null;
$stats_age_hours = null;
if (!empty($stats_generated_gmt)) {
    $generated_time = strtotime($stats_generated_gmt);
    if ($generated_time !== false) {
        $stats_age_hours = (time() - $generated_time) / 3600;
    }
}
$stats_stale = $stats_age_hours !== null && $stats_age_hours > 24;
display_test(
    'Stats aggregation',
    !empty($stats),
    !empty($stats) 
        ? sprintf('Last stats update: %s', $stats_generated_gmt ?? 'unknown')
        : 'Stats have never been aggregated',
    empty($stats) ? 'Visit Dashboard page to trigger first stats aggregation' : ''
);

// Test 2.5: Check migration flag
$migration_done = get_option('rts_quarantine_migration_v3_2_0_done');
display_test(
    'Migration status',
    $migration_done === '1' || $migration_done === true,
    $migration_done ? 'Migration to draft status completed' : 'Migration has NOT run yet',
    !$migration_done ? 'Visit site homepage or Dashboard to trigger migration' : ''
);

echo '</div>';

// ============================================================================
// TEST 3: LETTER COUNTING ACCURACY
// ============================================================================
echo '<div class="test-section">';
echo '<h2>🔢 Counting Accuracy Tests</h2>';

// Test 3.1: Manual count vs system count - Inbox
$manual_inbox = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s",
        'letter', 'pending'
    )
);

$system_inbox = isset($stats['pending']) ? (int) $stats['pending'] : 0;
$inbox_match = $manual_inbox === $system_inbox;
$inbox_status = $inbox_match || empty($stats) ? true : ($stats_stale ? 'warning' : false);
$inbox_action = (!$inbox_match && !empty($stats))
    ? ($stats_stale ? 'Counts don\'t match and stats are stale. Visit Dashboard to refresh stats.' : 'Counts don\'t match. Visit Dashboard to refresh stats.')
    : '';

display_test(
    'Inbox count accuracy',
    $inbox_status,
    sprintf('Manual count: %d | System reports: %d', $manual_inbox, $system_inbox),
    $inbox_action
);

// Test 3.2: Manual count vs system count - Quarantine
$manual_quarantine = (int) $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(DISTINCT p.ID)
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
         WHERE p.post_type = %s
           AND p.post_status = %s
           AND pm.meta_key = %s
           AND pm.meta_value = %s",
        'letter', 'draft', 'needs_review', '1'
    )
);

$system_quarantine = isset($stats['needs_review']) ? (int) $stats['needs_review'] : 0;
$quarantine_match = $manual_quarantine === $system_quarantine;
$quarantine_status = $quarantine_match || empty($stats) ? true : ($stats_stale ? 'warning' : false);
$quarantine_action = (!$quarantine_match && !empty($stats))
    ? ($stats_stale ? 'Counts don\'t match and stats are stale. Visit Dashboard to refresh stats.' : 'Counts don\'t match. Visit Dashboard to refresh stats.')
    : '';

display_test(
    'Quarantine count accuracy',
    $quarantine_status,
    sprintf('Manual count: %d | System reports: %d', $manual_quarantine, $system_quarantine),
    $quarantine_action
);

// Test 3.3: Check for duplicate meta entries
$duplicate_meta = $wpdb->get_var(
    "SELECT COUNT(*) 
     FROM (
         SELECT post_id, meta_key, COUNT(*) as cnt
         FROM {$wpdb->postmeta}
         WHERE meta_key IN ('needs_review', 'quality_score', 'rts_flagged')
         GROUP BY post_id, meta_key
         HAVING cnt > 1
     ) as duplicates"
);

display_test(
    'Meta data integrity',
    $duplicate_meta == 0,
    $duplicate_meta == 0 
        ? 'No duplicate metadata entries found' 
        : sprintf('Found %d duplicate metadata entries', $duplicate_meta),
    $duplicate_meta > 0 ? 'Consider running a cleanup query to remove duplicates' : ''
);

echo '</div>';

// ============================================================================
// TEST 4: UI/MENU TESTS
// ============================================================================
echo '<div class="test-section">';
echo '<h2>🎨 User Interface Tests</h2>';

// Test 4.1: Check CPT labels
$letter_post_type_object = get_post_type_object('letter');
$add_new_label = $letter_post_type_object->labels->add_new ?? 'Unknown';

display_test(
    'Menu label: Add Letter',
    $add_new_label === 'Add Letter',
    'Menu shows: "' . $add_new_label . '"',
    $add_new_label !== 'Add Letter' ? 'Label should be "Add Letter" not "' . $add_new_label . '"' : ''
);

// Test 4.2: Check submenu pages
global $submenu;
if (!did_action('admin_menu')) {
    do_action('admin_menu');
}
$letter_submenu = $submenu['edit.php?post_type=letter'] ?? [];
$dashboard_label = 'Not found';

foreach ($letter_submenu as $item) {
    if ($item[2] === 'rts-dashboard') {
        $dashboard_label = $item[0];
        break;
    }
}

if ($dashboard_label === 'Not found' && class_exists('RTS_Engine_Dashboard')) {
    RTS_Engine_Dashboard::register_menu();
    $letter_submenu = $submenu['edit.php?post_type=letter'] ?? [];
    foreach ($letter_submenu as $item) {
        if ($item[2] === 'rts-dashboard') {
            $dashboard_label = $item[0];
            break;
        }
    }
}

display_test(
    'Menu label: Dashboard',
    $dashboard_label === 'Dashboard',
    'Submenu shows: "' . $dashboard_label . '"',
    $dashboard_label !== 'Dashboard' && $dashboard_label !== 'Not found' ? 'Label should be "Dashboard" not "' . $dashboard_label . '"' : ''
);

// Test 4.3: Check bulk actions registration
$bulk_actions = apply_filters('bulk_actions-edit-letter', []);
$has_clear_quarantine = isset($bulk_actions['clear_quarantine_rescan']);

display_test(
    'Bulk action: Clear Quarantine & Re-scan',
    $has_clear_quarantine,
    $has_clear_quarantine 
        ? 'Bulk action is registered and available'
        : 'Bulk action NOT found',
    !$has_clear_quarantine ? 'Check if handle_bulk_actions is properly hooked' : ''
);

echo '</div>';

// ============================================================================
// TEST 5: SAMPLE LETTER PROCESSING
// ============================================================================
echo '<div class="test-section">';
echo '<h2>🧪 Sample Letter Processing Tests</h2>';

// Test 5.1: Get a sample quarantined letter
$sample_quarantined = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT p.* 
         FROM {$wpdb->posts} p
         INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
         WHERE p.post_type = %s
           AND p.post_status = %s
           AND pm.meta_key = %s
           AND pm.meta_value = %s
         LIMIT 1",
        'letter', 'draft', 'needs_review', '1'
    )
);

if ($sample_quarantined) {
    $flag_reasons = get_post_meta($sample_quarantined->ID, 'rts_flag_reasons', true);
    $reasons = $flag_reasons ? json_decode($flag_reasons, true) : [];
    
    display_test(
        'Sample quarantined letter check',
        true,
        sprintf(
            'Found letter #%d in quarantine | Status: %s | Reasons: %s',
            $sample_quarantined->ID,
            $sample_quarantined->post_status,
            !empty($reasons) ? implode(', ', array_slice($reasons, 0, 3)) : 'none recorded'
        )
    );
} else {
    display_test(
        'Sample quarantined letter check',
        'warning',
        'No quarantined letters found to test'
    );
}

// Test 5.2: Get a sample inbox letter
$sample_inbox = $wpdb->get_row(
    $wpdb->prepare(
        "SELECT * FROM {$wpdb->posts} 
         WHERE post_type = %s 
         AND post_status = %s 
         LIMIT 1",
        'letter', 'pending'
    )
);

if ($sample_inbox) {
    $has_queue = get_post_meta($sample_inbox->ID, 'rts_scan_queued_ts', true);
    
    display_test(
        'Sample inbox letter check',
        true,
        sprintf(
            'Found letter #%d in inbox | Status: %s | Queued for scan: %s',
            $sample_inbox->ID,
            $sample_inbox->post_status,
            $has_queue ? 'Yes' : 'Not yet'
        )
    );
} else {
    display_test(
        'Sample inbox letter check',
        'warning',
        'No inbox letters found to test'
    );
}

// Test 5.3: Check for orphaned meta (meta without post)
$orphaned_meta = $wpdb->get_var(
    "SELECT COUNT(*) 
     FROM {$wpdb->postmeta} pm
     LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
     WHERE pm.meta_key IN ('needs_review', 'quality_score', 'rts_flagged')
     AND p.ID IS NULL"
);

display_test(
    'Orphaned metadata check',
    $orphaned_meta == 0,
    $orphaned_meta == 0 
        ? 'No orphaned metadata found'
        : sprintf('Found %d orphaned metadata entries', $orphaned_meta),
    $orphaned_meta > 0 ? 'These are leftovers from deleted posts. Safe to clean up.' : ''
);

echo '</div>';

// ============================================================================
// TEST 6: ADMIN ACTIONS
// ============================================================================
echo '<div class="test-section">';
echo '<h2>🎛️ Admin Action Tests</h2>';

// Test 6.1: Check if admin actions are hooked
$has_quick_unflag = has_action('admin_post_rts_quick_unflag');
display_test(
    'Quick unflag action registered',
    $has_quick_unflag !== false,
    $has_quick_unflag !== false 
        ? 'Admin action is properly hooked'
        : 'Admin action NOT registered',
    $has_quick_unflag === false ? 'Check if handle_quick_unflag is hooked to admin_post_rts_quick_unflag' : ''
);

// Test 6.2: Check REST API endpoint
$rest_route = rest_get_server()->get_routes();
$has_rest_endpoint = isset($rest_route['/rts/v1/site-stats']);

display_test(
    'REST API endpoint',
    $has_rest_endpoint,
    $has_rest_endpoint 
        ? '/rts/v1/site-stats endpoint is registered'
        : 'REST endpoint NOT found',
    !$has_rest_endpoint ? 'Check if REST API initialization is running' : ''
);

echo '</div>';

// ============================================================================
// TEST 7: SECURITY CHECKS
// ============================================================================
echo '<div class="test-section">';
echo '<h2>🔒 Security Tests</h2>';

// Test 7.1: Check for proper capability checks
$sample_file = get_template_directory() . '/inc/rts-moderation-engine.php';
if (file_exists($sample_file)) {
    $file_content = file_get_contents($sample_file);
    $has_capability_checks = (strpos($file_content, 'current_user_can') !== false);
    $has_nonce_checks = (strpos($file_content, 'wp_verify_nonce') !== false || strpos($file_content, 'check_ajax_referer') !== false);
    
    display_test(
        'Capability checks present',
        $has_capability_checks,
        $has_capability_checks 
            ? 'current_user_can() checks found in code'
            : 'WARNING: No capability checks found!',
        !$has_capability_checks ? 'This is a security risk!' : ''
    );
    
    display_test(
        'Nonce verification present',
        $has_nonce_checks,
        $has_nonce_checks 
            ? 'Nonce verification found in code'
            : 'WARNING: No nonce checks found!',
        !$has_nonce_checks ? 'This is a security risk!' : ''
    );
}

// Test 7.2: Check if admin test file is accessible to non-admins
display_test(
    'Admin-only test access',
    current_user_can('manage_options'),
    current_user_can('manage_options') 
        ? 'This test file correctly requires admin access'
        : 'This should not happen - how did you get here?'
);

echo '</div>';

// ============================================================================
// TEST 8: PERFORMANCE CHECKS
// ============================================================================
echo '<div class="test-section">';
echo '<h2>⚡ Performance Tests</h2>';

// Test 8.1: Check if stats are cached
$stats_age = 'unknown';
if (!empty($stats['generated_gmt'])) {
    $generated_time = strtotime($stats['generated_gmt']);
    $age_seconds = time() - $generated_time;
    $age_hours = round($age_seconds / 3600, 1);
    $stats_age = $age_hours . ' hours ago';
}

display_test(
    'Stats caching',
    !empty($stats),
    'Stats were last generated: ' . $stats_age,
    empty($stats) ? 'Visit Dashboard to generate initial stats' : ''
);

// Test 8.2: Check Action Scheduler queue size
if ($as_available) {
    $queue_size = $wpdb->get_var(
        "SELECT COUNT(*) 
         FROM {$wpdb->prefix}actionscheduler_actions 
         WHERE hook LIKE 'rts_%' 
         AND status IN ('pending', 'in-progress')"
    );
    
    $queue_healthy = $queue_size < 1000;
    display_test(
        'Processing queue health',
        $queue_healthy ? true : 'warning',
        sprintf('Current queue size: %d jobs', $queue_size),
        !$queue_healthy ? 'Queue is large. Check if Action Scheduler is processing correctly.' : ''
    );
}

// Test 8.3: Check for failed jobs
if ($as_available) {
    $failed_jobs = $wpdb->get_var(
        "SELECT COUNT(*) 
         FROM {$wpdb->prefix}actionscheduler_actions 
         WHERE hook LIKE 'rts_%' 
         AND status = 'failed'"
    );
    
    display_test(
        'Failed background jobs',
        $failed_jobs == 0,
        $failed_jobs == 0 
            ? 'No failed jobs found'
            : sprintf('Found %d failed jobs', $failed_jobs),
        $failed_jobs > 0 ? 'Check Action Scheduler logs at Tools > Scheduled Actions' : ''
    );
}

echo '</div>';

// ============================================================================
// SUMMARY
// ============================================================================
echo '<div class="summary">';
echo '<h2>📋 Test Summary</h2>';
echo '<div class="summary-grid">';

echo '<div class="summary-box pass">';
echo '<div class="summary-number">' . $tests_passed . '</div>';
echo '<div class="summary-label">Passed</div>';
echo '</div>';

echo '<div class="summary-box fail">';
echo '<div class="summary-number">' . $tests_failed . '</div>';
echo '<div class="summary-label">Failed</div>';
echo '</div>';

echo '<div class="summary-box warning">';
echo '<div class="summary-number">' . $tests_warning . '</div>';
echo '<div class="summary-label">Warnings</div>';
echo '</div>';

echo '<div class="summary-box info">';
echo '<div class="summary-number">' . $total_tests . '</div>';
echo '<div class="summary-label">Total Tests</div>';
echo '</div>';

echo '</div>';

// Overall status
if ($tests_failed == 0 && $tests_warning == 0) {
    echo '<div class="test-item pass" style="margin-top: 20px;">';
    echo '<div class="test-name"><span class="test-icon">🎉</span>All Systems Operational!</div>';
    echo '<div class="test-result">Your RTS moderation system is working perfectly. No action needed.</div>';
    echo '</div>';
} elseif ($tests_failed == 0 && $tests_warning > 0) {
    echo '<div class="test-item warning" style="margin-top: 20px;">';
    echo '<div class="test-name"><span class="test-icon">⚠️</span>System Working With Warnings</div>';
    echo '<div class="test-result">Your system is functional but has ' . $tests_warning . ' warning(s). Review the warnings above.</div>';
    echo '</div>';
} else {
    echo '<div class="test-item fail" style="margin-top: 20px;">';
    echo '<div class="test-name"><span class="test-icon">❌</span>Issues Found</div>';
    echo '<div class="test-result">Found ' . $tests_failed . ' critical issue(s). Please address the failed tests above.</div>';
    echo '</div>';
}

// Quick action buttons
echo '<div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">';
echo '<strong>Quick Actions:</strong><br><br>';
echo '<a href="' . admin_url('edit.php?post_type=letter&page=rts-dashboard') . '" class="button button-primary">Go to Dashboard</a> ';
echo '<a href="' . admin_url('edit.php?post_type=letter') . '" class="button">View All Letters</a> ';
echo '<a href="' . admin_url('edit.php?post_type=letter&post_status=draft&meta_key=needs_review&meta_value=1') . '" class="button">View Quarantined</a> ';
echo '<a href="' . $_SERVER['REQUEST_URI'] . '" class="button">Re-run Tests</a>';
echo '</div>';

echo '<div class="timestamp">Test completed at: ' . current_time('mysql') . ' (server time)</div>';
echo '</div>';

// ============================================================================
// DIAGNOSTIC DATA EXPORT
// ============================================================================
echo '<div class="test-section">';
echo '<h2>📦 Diagnostic Data Export</h2>';
echo '<p>Copy this data if you need to share test results with support:</p>';

$diagnostic_data = [
    'test_date' => current_time('mysql'),
    'wp_version' => get_bloginfo('version'),
    'php_version' => PHP_VERSION,
    'theme_version' => wp_get_theme()->get('Version'),
    'total_letters' => $total_letters,
    'published' => $published,
    'inbox' => $pending,
    'quarantine' => $draft,
    'old_quarantine_count' => $old_quarantine,
    'new_quarantine_count' => $new_quarantine,
    'migration_done' => $migration_done ? 'yes' : 'no',
    'action_scheduler' => $as_available ? 'available' : 'not_available',
    'tests_passed' => $tests_passed,
    'tests_failed' => $tests_failed,
    'tests_warning' => $tests_warning,
];

echo '<div class="code-block">';
echo json_encode($diagnostic_data, JSON_PRETTY_PRINT);
echo '</div>';

echo '</div>';

?>

</body>
</html>
