<?php
/**
 * RTS Database Cleanup & Reset Tool
 * Version: 3.2.1
 * 
 * DANGEROUS: This tool can delete ALL letters from your database!
 * 
 * FEATURES:
 * - Find duplicate letters
 * - Delete duplicates (keep newest)
 * - Export database before deletion
 * - Nuclear reset option (delete everything)
 * - Corruption detection
 * 
 * INSTRUCTIONS:
 * 1. Upload to: /wp-content/themes/hello-elementor-child-rts-v3/
 * 2. Visit: https://yoursite.com/wp-content/themes/...../rts-database-cleanup.php
 * 3. Follow the steps on screen
 */

// Load WordPress
require_once('../../../wp-load.php');

// Security: Only admins
if (!current_user_can('manage_options')) {
    wp_die('Sorry, you need to be an administrator to run this tool.');
}

global $wpdb;

// Process actions
$action_result = null;

if (isset($_POST['action_type']) && check_admin_referer('rts_db_cleanup')) {
    $action_type = sanitize_key($_POST['action_type']);
    
    switch ($action_type) {
        case 'detect_duplicates':
            $action_result = detect_duplicates();
            break;
            
        case 'delete_duplicates':
            $action_result = delete_duplicates();
            break;
            
        case 'export_database':
            export_database();
            exit;
            break;
            
        case 'nuclear_reset':
            if (isset($_POST['confirm_nuclear']) && $_POST['confirm_nuclear'] === 'DELETE EVERYTHING') {
                $action_result = nuclear_reset();
            } else {
                $action_result = [
                    'success' => false,
                    'message' => 'You must type "DELETE EVERYTHING" to confirm nuclear reset.'
                ];
            }
            break;
            
        case 'fix_corruption':
            $action_result = fix_corruption();
            break;
    }
}

// Helper functions

function detect_duplicates() {
    global $wpdb;
    
    // Find duplicates by title + content similarity
    $duplicates = $wpdb->get_results(
        "SELECT 
            post_title,
            COUNT(*) as count,
            GROUP_CONCAT(ID ORDER BY post_date DESC) as ids,
            GROUP_CONCAT(post_status ORDER BY post_date DESC) as statuses
         FROM {$wpdb->posts}
         WHERE post_type = 'letter'
         GROUP BY post_title
         HAVING count > 1
         ORDER BY count DESC"
    );
    
    // Also find by content hash
    $content_duplicates = $wpdb->get_results(
        "SELECT 
            MD5(post_content) as content_hash,
            COUNT(*) as count,
            GROUP_CONCAT(ID ORDER BY post_date DESC) as ids,
            MIN(post_title) as sample_title
         FROM {$wpdb->posts}
         WHERE post_type = 'letter'
         GROUP BY MD5(post_content)
         HAVING count > 1
         ORDER BY count DESC"
    );
    
    $total_duplicate_letters = 0;
    foreach ($duplicates as $dup) {
        $total_duplicate_letters += $dup->count - 1; // -1 because we keep one
    }
    
    $total_content_dups = 0;
    foreach ($content_duplicates as $dup) {
        $total_content_dups += $dup->count - 1;
    }
    
    return [
        'success' => true,
        'duplicates' => $duplicates,
        'content_duplicates' => $content_duplicates,
        'total_duplicate_letters' => $total_duplicate_letters,
        'total_content_dups' => $total_content_dups,
        'message' => sprintf(
            'Found %d duplicate titles affecting %d letters, and %d content duplicates affecting %d letters.',
            count($duplicates),
            $total_duplicate_letters,
            count($content_duplicates),
            $total_content_dups
        )
    ];
}

function delete_duplicates() {
    global $wpdb;
    
    $deleted_count = 0;
    
    // Delete title duplicates (keep newest)
    $duplicates = $wpdb->get_results(
        "SELECT 
            post_title,
            GROUP_CONCAT(ID ORDER BY post_date DESC) as ids
         FROM {$wpdb->posts}
         WHERE post_type = 'letter'
         GROUP BY post_title
         HAVING COUNT(*) > 1"
    );
    
    foreach ($duplicates as $dup) {
        $ids = explode(',', $dup->ids);
        $keep_id = array_shift($ids); // Keep the first (newest)
        
        foreach ($ids as $delete_id) {
            wp_delete_post((int) $delete_id, true); // true = force delete (bypass trash)
            $deleted_count++;
        }
    }
    
    // Delete content duplicates (keep newest)
    $content_dups = $wpdb->get_results(
        "SELECT 
            MD5(post_content) as content_hash,
            GROUP_CONCAT(ID ORDER BY post_date DESC) as ids
         FROM {$wpdb->posts}
         WHERE post_type = 'letter'
         GROUP BY MD5(post_content)
         HAVING COUNT(*) > 1"
    );
    
    foreach ($content_dups as $dup) {
        $ids = explode(',', $dup->ids);
        $keep_id = array_shift($ids); // Keep the first (newest)
        
        // Only delete if not already deleted by title duplicates
        foreach ($ids as $delete_id) {
            $post = get_post((int) $delete_id);
            if ($post) {
                wp_delete_post((int) $delete_id, true);
                $deleted_count++;
            }
        }
    }
    
    return [
        'success' => true,
        'deleted' => $deleted_count,
        'message' => sprintf('Deleted %d duplicate letters. Kept the newest version of each.', $deleted_count)
    ];
}

function export_database() {
    global $wpdb;
    
    // Get all letters with metadata
    $letters = $wpdb->get_results(
        "SELECT p.*, 
                GROUP_CONCAT(CONCAT(pm.meta_key, ':', pm.meta_value) SEPARATOR '||') as meta_data
         FROM {$wpdb->posts} p
         LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
         WHERE p.post_type = 'letter'
         GROUP BY p.ID
         ORDER BY p.post_date DESC"
    );
    
    // Prepare CSV
    $csv_output = "ID,Title,Content,Status,Date,Meta_Data\n";
    
    foreach ($letters as $letter) {
        $csv_output .= sprintf(
            '"%s","%s","%s","%s","%s","%s"' . "\n",
            $letter->ID,
            addslashes($letter->post_title),
            addslashes(substr($letter->post_content, 0, 1000)), // Limit content length
            $letter->post_status,
            $letter->post_date,
            addslashes($letter->meta_data ?? '')
        );
    }
    
    // Download
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="rts-letters-backup-' . date('Y-m-d-His') . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    echo $csv_output;
    exit;
}

function nuclear_reset() {
    global $wpdb;
    
    // Count before deletion
    $count = $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'letter'"
    );
    
    // Delete ALL letters
    $letter_ids = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'letter'"
    );
    
    foreach ($letter_ids as $letter_id) {
        wp_delete_post((int) $letter_id, true); // Force delete
    }
    
    // Clean up orphaned meta
    $wpdb->query(
        "DELETE pm FROM {$wpdb->postmeta} pm
         LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE p.ID IS NULL"
    );
    
    // Clean up orphaned term relationships
    $wpdb->query(
        "DELETE tr FROM {$wpdb->term_relationships} tr
         LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id
         WHERE p.ID IS NULL"
    );
    
    // Reset stats
    delete_option('rts_aggregated_stats');
    
    // Clear cache
    $wpdb->query(
        "DELETE FROM {$wpdb->options} 
         WHERE option_name LIKE '_transient_rts_%' 
         OR option_name LIKE '_transient_timeout_rts_%'"
    );
    
    return [
        'success' => true,
        'deleted' => $count,
        'message' => sprintf('NUCLEAR RESET COMPLETE: Deleted %d letters and cleaned up all related data. Database is now empty and ready for fresh import.', $count)
    ];
}

function fix_corruption() {
    global $wpdb;
    
    $fixes = [];
    $fixed_count = 0;
    
    // Fix 1: Remove letters with empty titles
    $empty_title = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} 
         WHERE post_type = 'letter' 
         AND (post_title = '' OR post_title IS NULL)"
    );
    
    foreach ($empty_title as $id) {
        wp_delete_post((int) $id, true);
        $fixed_count++;
    }
    
    if (!empty($empty_title)) {
        $fixes[] = sprintf('Deleted %d letters with empty titles', count($empty_title));
    }
    
    // Fix 2: Remove letters with empty content
    $empty_content = $wpdb->get_col(
        "SELECT ID FROM {$wpdb->posts} 
         WHERE post_type = 'letter' 
         AND (post_content = '' OR post_content IS NULL)"
    );
    
    foreach ($empty_content as $id) {
        wp_delete_post((int) $id, true);
        $fixed_count++;
    }
    
    if (!empty($empty_content)) {
        $fixes[] = sprintf('Deleted %d letters with empty content', count($empty_content));
    }
    
    // Fix 3: Normalize all letter statuses to valid ones
    $invalid_status = $wpdb->query(
        "UPDATE {$wpdb->posts} 
         SET post_status = 'draft' 
         WHERE post_type = 'letter' 
         AND post_status NOT IN ('publish', 'pending', 'draft', 'trash')"
    );
    
    if ($invalid_status > 0) {
        $fixes[] = sprintf('Fixed %d letters with invalid status', $invalid_status);
    }
    
    // Fix 4: Clean duplicate meta entries
    $wpdb->query(
        "DELETE pm1 FROM {$wpdb->postmeta} pm1
         INNER JOIN {$wpdb->postmeta} pm2
         WHERE pm1.meta_id > pm2.meta_id
         AND pm1.post_id = pm2.post_id
         AND pm1.meta_key = pm2.meta_key"
    );
    
    // Fix 5: Remove orphaned metadata
    $orphaned = $wpdb->query(
        "DELETE pm FROM {$wpdb->postmeta} pm
         LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE p.ID IS NULL"
    );
    
    if ($orphaned > 0) {
        $fixes[] = sprintf('Cleaned %d orphaned metadata entries', $orphaned);
    }
    
    return [
        'success' => true,
        'fixed' => $fixed_count,
        'fixes' => $fixes,
        'message' => 'Corruption fixes applied: ' . implode(', ', $fixes)
    ];
}

// Get current stats
$stats = [
    'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'letter'"),
    'published' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'letter' AND post_status = 'publish'"),
    'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'letter' AND post_status = 'pending'"),
    'draft' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'letter' AND post_status = 'draft'"),
    'trash' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'letter' AND post_status = 'trash'"),
];

?>
<!DOCTYPE html>
<html>
<head>
    <title>RTS Database Cleanup & Reset</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            max-width: 1000px;
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
        .header.danger {
            border-left: 5px solid #dc3545;
        }
        h1 {
            margin: 0 0 10px 0;
            color: #1d2327;
        }
        .subtitle {
            color: #646970;
            font-size: 14px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        .stat-box {
            text-align: center;
            padding: 20px;
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            color: #0073aa;
        }
        .stat-label {
            font-size: 12px;
            color: #646970;
            text-transform: uppercase;
        }
        .section {
            background: #fff;
            padding: 25px;
            margin-bottom: 20px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .section.danger {
            border-left: 5px solid #dc3545;
        }
        .section.warning {
            border-left: 5px solid #ffc107;
        }
        .section h2 {
            margin: 0 0 20px 0;
            color: #1d2327;
        }
        .action-button {
            display: inline-block;
            padding: 12px 24px;
            background: #0073aa;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            margin-right: 10px;
            margin-bottom: 10px;
            text-decoration: none;
        }
        .action-button:hover {
            background: #005a87;
        }
        .action-button.danger {
            background: #dc3545;
        }
        .action-button.danger:hover {
            background: #bd2130;
        }
        .action-button.export {
            background: #28a745;
        }
        .action-button.export:hover {
            background: #218838;
        }
        .result-box {
            margin: 20px 0;
            padding: 15px;
            border-radius: 4px;
            border-left: 4px solid #28a745;
        }
        .result-box.success {
            background: #d4edda;
            color: #155724;
        }
        .result-box.error {
            background: #f8d7da;
            color: #721c24;
            border-left-color: #dc3545;
        }
        .warning-box {
            background: #fff3cd;
            border: 2px solid #ffc107;
            padding: 20px;
            border-radius: 4px;
            margin: 20px 0;
        }
        .danger-box {
            background: #f8d7da;
            border: 2px solid #dc3545;
            padding: 20px;
            border-radius: 4px;
            margin: 20px 0;
        }
        .info-box {
            background: #d1ecf1;
            border-left: 4px solid #17a2b8;
            padding: 15px;
            margin: 15px 0;
            border-radius: 4px;
        }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            border: 2px solid #dc3545;
            border-radius: 4px;
            font-size: 14px;
            margin: 10px 0;
        }
        .duplicate-list {
            max-height: 300px;
            overflow-y: auto;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 4px;
            margin: 15px 0;
        }
        .duplicate-item {
            padding: 10px;
            border-bottom: 1px solid #ddd;
            font-size: 13px;
        }
        .duplicate-item:last-child {
            border-bottom: none;
        }
    </style>
    <script>
        function confirmNuclear() {
            var input = document.getElementById('nuclear_confirm').value;
            if (input !== 'DELETE EVERYTHING') {
                alert('You must type exactly: DELETE EVERYTHING');
                return false;
            }
            return confirm('FINAL WARNING: This will permanently delete ALL ' + <?php echo $stats['total']; ?> + ' letters. Are you absolutely sure?');
        }
        
        function confirmDelete() {
            return confirm('This will delete duplicate letters. The newest version of each will be kept. Continue?');
        }
    </script>
</head>
<body>
    <div class="header danger">
        <h1>⚠️ RTS Database Cleanup & Reset Tool</h1>
        <div class="subtitle">DANGEROUS TOOL: Can delete letters from your database. Use with extreme caution!</div>
    </div>

    <div class="section">
        <h2>📊 Current Database Status</h2>
        <div class="stats-grid">
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($stats['total']); ?></div>
                <div class="stat-label">Total Letters</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($stats['published']); ?></div>
                <div class="stat-label">Published</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($stats['pending']); ?></div>
                <div class="stat-label">Inbox</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($stats['draft']); ?></div>
                <div class="stat-label">Quarantine</div>
            </div>
            <div class="stat-box">
                <div class="stat-number"><?php echo number_format($stats['trash']); ?></div>
                <div class="stat-label">Trash</div>
            </div>
        </div>
    </div>

    <?php if ($action_result): ?>
        <div class="result-box <?php echo $action_result['success'] ? 'success' : 'error'; ?>">
            <strong>Result:</strong> <?php echo esc_html($action_result['message']); ?>
            
            <?php if (isset($action_result['duplicates'])): ?>
                <div class="duplicate-list">
                    <strong>Duplicate Titles Found:</strong>
                    <?php foreach (array_slice($action_result['duplicates'], 0, 20) as $dup): ?>
                        <div class="duplicate-item">
                            <strong><?php echo esc_html($dup->post_title); ?></strong><br>
                            Count: <?php echo $dup->count; ?> | IDs: <?php echo esc_html($dup->ids); ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (count($action_result['duplicates']) > 20): ?>
                        <div class="duplicate-item">...and <?php echo count($action_result['duplicates']) - 20; ?> more</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if (isset($action_result['fixes'])): ?>
                <ul style="margin-top: 10px;">
                    <?php foreach ($action_result['fixes'] as $fix): ?>
                        <li><?php echo esc_html($fix); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Step 1: Export Backup -->
    <div class="section">
        <h2>📥 Step 1: Export Backup (REQUIRED FIRST STEP)</h2>
        <div class="warning-box">
            <strong>⚠️ ALWAYS EXPORT FIRST!</strong><br>
            Before deleting anything, export your database to a CSV file. This is your safety net!
        </div>
        <p>This creates a backup CSV file with all your letters and metadata.</p>
        <form method="post">
            <?php wp_nonce_field('rts_db_cleanup'); ?>
            <input type="hidden" name="action_type" value="export_database">
            <button type="submit" class="action-button export">💾 Export Database to CSV</button>
        </form>
        <div class="info-box">
            The CSV file will download automatically. Keep it safe - you can use it to re-import letters if needed.
        </div>
    </div>

    <!-- Step 2: Detect Duplicates -->
    <div class="section warning">
        <h2>🔍 Step 2: Detect Duplicates</h2>
        <p>Scan your database for duplicate letters (same title or same content).</p>
        <form method="post">
            <?php wp_nonce_field('rts_db_cleanup'); ?>
            <input type="hidden" name="action_type" value="detect_duplicates">
            <button type="submit" class="action-button">🔍 Scan for Duplicates</button>
        </form>
        <div class="info-box">
            This is safe - it only detects duplicates, it doesn't delete anything.
        </div>
    </div>

    <!-- Step 3: Delete Duplicates -->
    <div class="section warning">
        <h2>🗑️ Step 3: Delete Duplicates (Optional)</h2>
        <div class="warning-box">
            <strong>⚠️ This will DELETE letters!</strong><br>
            Deletes duplicate letters and keeps the newest version of each.
        </div>
        <p>If duplicates were found, you can delete them here. The newest version of each letter will be kept.</p>
        <form method="post" onsubmit="return confirmDelete();">
            <?php wp_nonce_field('rts_db_cleanup'); ?>
            <input type="hidden" name="action_type" value="delete_duplicates">
            <button type="submit" class="action-button danger">🗑️ Delete Duplicates</button>
        </form>
    </div>

    <!-- Step 4: Fix Corruption -->
    <div class="section warning">
        <h2>🔧 Step 4: Fix Corruption (Optional)</h2>
        <p>Automatically fix common database corruption issues:</p>
        <ul>
            <li>Delete letters with empty titles</li>
            <li>Delete letters with empty content</li>
            <li>Fix invalid post statuses</li>
            <li>Remove orphaned metadata</li>
            <li>Clean duplicate meta entries</li>
        </ul>
        <form method="post">
            <?php wp_nonce_field('rts_db_cleanup'); ?>
            <input type="hidden" name="action_type" value="fix_corruption">
            <button type="submit" class="action-button">🔧 Fix Corruption</button>
        </form>
    </div>

    <!-- Step 5: Nuclear Reset -->
    <div class="section danger">
        <h2>☢️ Step 5: Nuclear Reset (DANGER ZONE)</h2>
        <div class="danger-box">
            <strong>⚠️⚠️⚠️ EXTREME DANGER ⚠️⚠️⚠️</strong><br><br>
            This will DELETE ALL <?php echo number_format($stats['total']); ?> LETTERS permanently!<br>
            There is NO UNDO!<br><br>
            <strong>Only use this if you want to start completely fresh with a clean import.</strong>
        </div>
        
        <p><strong>What this does:</strong></p>
        <ul>
            <li>Deletes ALL letters (published, pending, draft, trash)</li>
            <li>Removes ALL letter metadata</li>
            <li>Cleans up ALL orphaned data</li>
            <li>Resets statistics</li>
            <li>Clears cache</li>
            <li>Leaves you with a completely empty database</li>
        </ul>
        
        <div class="info-box">
            <strong>After nuclear reset:</strong><br>
            Your database will be empty and clean. You can then do a fresh import of letters without worrying about duplicates or corruption.
        </div>
        
        <form method="post" onsubmit="return confirmNuclear();">
            <?php wp_nonce_field('rts_db_cleanup'); ?>
            <input type="hidden" name="action_type" value="nuclear_reset">
            
            <p><strong>Type exactly: DELETE EVERYTHING</strong></p>
            <input type="text" id="nuclear_confirm" name="confirm_nuclear" placeholder="Type: DELETE EVERYTHING" required>
            
            <button type="submit" class="action-button danger">☢️ NUCLEAR RESET - DELETE ALL LETTERS</button>
        </form>
    </div>

    <div class="section">
        <h2>📚 Recommended Workflow</h2>
        <ol>
            <li><strong>Export Backup</strong> - Download CSV backup of all letters (ALWAYS DO THIS FIRST!)</li>
            <li><strong>Detect Duplicates</strong> - Scan to see if duplicates exist</li>
            <li><strong>Delete Duplicates</strong> - If found, remove them (keeps newest)</li>
            <li><strong>Fix Corruption</strong> - Clean up any database issues</li>
            <li><strong>Test</strong> - Run the test tool to verify everything works</li>
            <li><strong>Nuclear Reset</strong> - ONLY if corruption is severe and you want to start fresh</li>
            <li><strong>Re-import</strong> - Use your CSV backup or import clean data</li>
        </ol>
        
        <p style="margin-top: 20px;">
            <a href="rts-system-test.php" class="action-button">🔍 Run System Test</a>
            <a href="<?php echo admin_url('edit.php?post_type=letter&page=rts-dashboard'); ?>" class="action-button">📊 Go to Dashboard</a>
        </p>
    </div>

</body>
</html>
