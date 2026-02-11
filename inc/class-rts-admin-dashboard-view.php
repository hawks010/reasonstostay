<?php
/**
 * RTS Admin V2 — Command Center Dashboard
 *
 * Unified overview combining Letter Ecosystem stats, Audience metrics,
 * email-queue traffic control, and system-health checks.
 *
 * Also re-parents existing RTS admin pages under a single top-level
 * "Command Center" menu so there is one coherent entry-point.
 *
 * @package  Hello-Elementor-Child-RTS
 * @version  1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class RTS_Admin_Dashboard_View {

    /* =================================================================
     *  BOOTSTRAP
     * ================================================================*/

    /**
     * Wire everything into WordPress.
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menus' ), 9 );
        add_action( 'admin_menu', array( __CLASS__, 'reorganise_menus' ), 999 );
    }

    /* =================================================================
     *  MENU REGISTRATION
     * ================================================================*/

    /**
     * Register the top-level Command Center page and its submenus.
     */
    public static function register_menus() {

        // ── Top-level hub ─────────────────────────────────────────────
        add_menu_page(
            'RTS Command Center',
            'Command Center',
            'manage_options',
            'rts-command-center',
            array( __CLASS__, 'render' ),
            'dashicons-superhero-alt',
            2
        );

        // ── Default sub = Overview (shares slug with parent) ──────────
        add_submenu_page(
            'rts-command-center',
            'Overview',
            'Overview',
            'manage_options',
            'rts-command-center'
        );

        // ── Navigation links to existing pages ───────────────────────
        add_submenu_page(
            'rts-command-center',
            'Letters',
            'Letters',
            'edit_posts',
            'edit.php?post_type=letter'
        );

        add_submenu_page(
            'rts-command-center',
            'Moderation Queue',
            'Moderation Queue',
            'edit_others_posts',
            'edit.php?post_type=letter&page=rts-review-console'
        );

        add_submenu_page(
            'rts-command-center',
            'Workflow & Labels',
            'Workflow & Labels',
            'manage_options',
            'edit.php?post_type=letter&page=rts-workflow-tools'
        );

        add_submenu_page(
            'rts-command-center',
            'Audience',
            'Audience',
            'manage_options',
            'edit.php?post_type=rts_subscriber'
        );

        add_submenu_page(
            'rts-command-center',
            'Newsletter Builder',
            'Newsletter Builder',
            'manage_options',
            'edit.php?post_type=rts_newsletter'
        );

        add_submenu_page(
            'rts-command-center',
            'Email Templates',
            'Email Templates',
            'manage_options',
            'edit.php?post_type=rts_subscriber&page=rts-email-templates'
        );

        add_submenu_page(
            'rts-command-center',
            'Sending & SMTP',
            'Sending & SMTP',
            'manage_options',
            'edit.php?post_type=rts_subscriber&page=rts-email-settings'
        );

        add_submenu_page(
            'rts-command-center',
            'Security Logs',
            'Security Logs',
            'manage_options',
            'edit.php?post_type=letter&page=rts-security-logs'
        );

        add_submenu_page(
            'rts-command-center',
            'System Tools',
            'System Tools',
            'manage_options',
            'edit.php?post_type=letter&page=rts_patterns'
        );

        add_submenu_page(
            'rts-command-center',
            'Help',
            'Help',
            'manage_options',
            'admin.php?page=rts-site-manual'
        );
    }

    /**
     * Late-pass: hide duplicate / redundant menu entries.
     *
     * Keeps the native CPT list-table menus functional but removes
     * the old dashboard sub-pages and the Site Manual top-level item.
     */
    public static function reorganise_menus() {

        // Remove old dashboards from their CPT parents.
        remove_submenu_page( 'edit.php?post_type=letter', 'rts-dashboard' );
        remove_submenu_page( 'edit.php?post_type=rts_subscriber', 'rts-subscribers-dashboard' );

        // Remove Site Manual top-level (now accessible via Help submenu).
        remove_menu_page( 'rts-site-manual' );
    }

    /* =================================================================
     *  DATA LAYER
     * ================================================================*/

    /**
     * Fetch live stats from the database.
     *
     * Every query is wrapped in a table-existence check so the
     * dashboard never crashes during migrations or on a fresh install.
     *
     * @return array<string,mixed>
     */
    private static function get_live_stats() {
        global $wpdb;

        // ----- 1. EMAIL QUEUE ------------------------------------------------
        $table_queue = $wpdb->prefix . 'rts_email_queue';
        $queue_has_table = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_queue ) ) === $table_queue );

        $queue = array(
            'pending'    => 0,
            'processing' => 0,
            'failed'     => 0,
            'sent'       => 0,
            'total'      => 0,   // non-sent, non-cancelled
        );

        $sent_today_queue = 0;

        if ( $queue_has_table ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results( "SELECT status, COUNT(*) AS cnt FROM {$table_queue} GROUP BY status" );
            foreach ( $rows as $r ) {
                $s = strtolower( $r->status );
                if ( isset( $queue[ $s ] ) ) {
                    $queue[ $s ] = (int) $r->cnt;
                }
                if ( ! in_array( $s, array( 'sent', 'cancelled' ), true ) ) {
                    $queue['total'] += (int) $r->cnt;
                }
            }

            $sent_today_queue = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_queue} WHERE status = %s AND sent_at >= CURDATE()",
                'sent'
            ) );
        }

        // ----- 2. SENT TODAY (fallback to logs) ------------------------------
        $table_logs = $wpdb->prefix . 'rts_email_logs';
        $sent_today_logs = 0;

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_logs ) ) === $table_logs ) {
            $sent_today_logs = (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table_logs} WHERE sent_at >= CURDATE()"
            );
        }

        $sent_today = max( $sent_today_queue, $sent_today_logs );

        // ----- 3. SUBSCRIBERS (custom table = primary) -----------------------
        $table_subs = $wpdb->prefix . 'rts_subscribers';
        $subs_active = 0;

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_subs ) ) === $table_subs ) {
            $subs_active = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_subs} WHERE status = %s",
                'active'
            ) );
        }

        // Bounced count from CPT meta (more reliable for per-subscriber state).
        $subs_bounced = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
               FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
              WHERE p.post_type   = %s
                AND p.post_status = %s
                AND pm.meta_key   = %s
                AND pm.meta_value = %s",
            'rts_subscriber',
            'publish',
            '_rts_subscriber_status',
            'bounced'
        ) );

        // ----- 4. LETTER ECOSYSTEM ------------------------------------------
        $letter_counts   = wp_count_posts( 'letter' );
        $letters_live    = isset( $letter_counts->publish ) ? (int) $letter_counts->publish : 0;
        $letters_pending = isset( $letter_counts->pending ) ? (int) $letter_counts->pending : 0;
        $letters_draft   = isset( $letter_counts->draft )   ? (int) $letter_counts->draft   : 0;

        // Quarantined = draft + needs_review=1
        $letters_flagged = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
               FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
              WHERE p.post_type   = %s
                AND p.post_status = %s
                AND pm.meta_key   = %s
                AND pm.meta_value = %s",
            'letter',
            'draft',
            'needs_review',
            '1'
        ) );

        // AI Refined = has _rts_refined meta (any truthy value)
        $letters_refined = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
               FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
              WHERE p.post_type = %s
                AND pm.meta_key = %s
                AND pm.meta_value != ''",
            'letter',
            '_rts_refined'
        ) );

        // New/Ingested = all drafts minus quarantined ones.
        $letters_new = max( 0, $letters_draft - $letters_flagged );

        // ----- 5. VIEWS TODAY ------------------------------------------------
        $daily = get_option( 'rts_views_daily', array( 'date' => '', 'count' => 0 ) );
        $views_today = 0;
        if ( isset( $daily['date'] ) && $daily['date'] === current_time( 'Y-m-d' ) ) {
            $views_today = (int) ( $daily['count'] ?? 0 );
        }

        // ----- 6. OPEN RATE (30-day) ----------------------------------------
        $table_tracking = $wpdb->prefix . 'rts_email_tracking';
        $open_rate = 0;

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_tracking ) ) === $table_tracking ) {
            $rate = $wpdb->get_var(
                "SELECT ROUND( SUM(opened) / NULLIF(COUNT(*), 0) * 100, 1 )
                   FROM {$table_tracking}
                  WHERE created_at >= DATE_SUB( CURDATE(), INTERVAL 30 DAY )"
            );
            $open_rate = $rate !== null ? (float) $rate : 0;
        }

        // ----- 7. SYSTEM HEALTH ---------------------------------------------
        $batch_size = (int) get_option( 'rts_email_batch_size', 100 );
        $smtp_host  = get_option( 'rts_smtp_host', 'Not Configured' );
        $pause_all  = (int) get_option( 'rts_pause_all_sending', 0 );

        // SMTP connection test (cached in a short transient to avoid
        // hammering SMTP on every dashboard load).
        $smtp_ok  = false;
        $smtp_msg = '';
        $smtp_cache = get_transient( 'rts_cc_smtp_health' );
        if ( false !== $smtp_cache ) {
            $smtp_ok  = ! empty( $smtp_cache['ok'] );
            $smtp_msg = $smtp_cache['message'] ?? '';
        } elseif ( class_exists( 'RTS_SMTP_Settings' ) ) {
            $smtp     = new RTS_SMTP_Settings();
            $res      = $smtp->test_smtp_connection();
            $smtp_ok  = ! empty( $res['ok'] );
            $smtp_msg = ! empty( $res['message'] ) ? (string) $res['message'] : '';
            set_transient( 'rts_cc_smtp_health', array(
                'ok'      => $smtp_ok,
                'message' => $smtp_msg,
            ), 5 * MINUTE_IN_SECONDS );
        }

        // Cron
        $cron_next = wp_next_scheduled( 'rts_process_email_queue' );

        return array(
            // Letters
            'letters_live'     => $letters_live,
            'letters_pending'  => $letters_pending,
            'letters_flagged'  => $letters_flagged,
            'letters_new'      => $letters_new,
            'letters_refined'  => $letters_refined,
            'views_today'      => $views_today,

            // Subscribers
            'subs_active'      => $subs_active,
            'subs_bounced'     => $subs_bounced,
            'open_rate'        => $open_rate,

            // Queue
            'sent_today'       => $sent_today,
            'queue_total'      => $queue['total'],
            'queue_pending'    => $queue['pending'],
            'queue_processing' => $queue['processing'],
            'queue_failed'     => $queue['failed'],
            'batch_size'       => $batch_size,

            // System
            'smtp_host'        => $smtp_host,
            'smtp_ok'          => $smtp_ok,
            'smtp_msg'         => $smtp_msg,
            'cron_next'        => $cron_next,
            'pause_all'        => $pause_all,
        );
    }

    /* =================================================================
     *  RENDER
     * ================================================================*/

    /**
     * Output the Command Center dashboard.
     */
    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'rts' ) );
        }

        $stats = self::get_live_stats();

        // Pre-compute queue-bar percentages.
        $total_vol = $stats['queue_total'] + $stats['sent_today'];
        $sent_pct  = $total_vol > 0 ? ( $stats['sent_today'] / $total_vol ) * 100 : 0;
        $queue_pct = $total_vol > 0 ? ( $stats['queue_total'] / $total_vol ) * 100 : 0;

        // Cron label.
        $cron_ok    = (bool) $stats['cron_next'];
        $cron_label = $cron_ok
            ? date_i18n( 'j M Y, H:i', $stats['cron_next'] )
            : __( 'Not Scheduled', 'rts' );

        // Pause-aware status string.
        $system_label = $stats['pause_all']
            ? 'System Paused'
            : 'System Active: Batching ' . (int) $stats['batch_size'] . '/run';
        $system_color = $stats['pause_all'] ? 'var(--rts-orange)' : 'var(--rts-green)';
        ?>
        <div class="rts-app-wrapper">

            <!-- ─── GLOBAL HEADER ────────────────────────────────────────── -->
            <div class="rts-header">
                <div>
                    <h1 class="rts-main-title">RTS Command Center</h1>
                    <p class="rts-subtitle">System Overview &amp; Live Operations</p>
                </div>
                <div style="display:flex;gap:10px;">
                    <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=letter&page=rts-security-logs' ) ); ?>" class="rts-btn rts-btn-secondary">View Logs</a>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
                        <input type="hidden" name="action" value="rts_toggle_pause_sending">
                        <?php wp_nonce_field( 'rts_toggle_pause_sending' ); ?>
                        <button type="submit" class="rts-btn <?php echo $stats['pause_all'] ? 'rts-btn-primary' : 'rts-btn-danger'; ?>">
                            <?php echo $stats['pause_all'] ? 'Resume Sending' : 'Pause All Sending'; ?>
                        </button>
                    </form>
                </div>
            </div>

            <!-- ─── ROW 1: SPLIT ECOSYSTEM ───────────────────────────────── -->
            <div class="rts-split-panel">

                <!-- LEFT: Letter Ecosystem -->
                <div>
                    <div class="rts-section-title">The Letter Ecosystem</div>

                    <div class="rts-grid-4">
                        <div class="rts-card" style="padding:15px;">
                            <div class="rts-card-sub">Published</div>
                            <div class="rts-card-value"><?php echo esc_html( number_format( $stats['letters_live'] ) ); ?></div>
                            <div style="font-size:11px;color:var(--rts-green);">Live on site</div>
                        </div>
                        <div class="rts-card" style="padding:15px;">
                            <div class="rts-card-sub">Views Today</div>
                            <div class="rts-card-value"><?php echo esc_html( number_format( $stats['views_today'] ) ); ?></div>
                            <div style="font-size:11px;color:var(--rts-txt-mute);">across all letters</div>
                        </div>
                        <div class="rts-card" style="padding:15px;">
                            <div class="rts-card-sub">Inbox</div>
                            <div class="rts-card-value" style="color:var(--rts-cyan);"><?php echo esc_html( number_format( $stats['letters_pending'] ) ); ?></div>
                            <div style="font-size:11px;color:var(--rts-txt-mute);">Pending Review</div>
                        </div>
                        <div class="rts-card" style="padding:15px;">
                            <div class="rts-card-sub">Quarantine</div>
                            <div class="rts-card-value" style="color:var(--rts-red);"><?php echo esc_html( number_format( $stats['letters_flagged'] ) ); ?></div>
                            <div style="font-size:11px;color:var(--rts-txt-mute);">Needs Action</div>
                        </div>
                    </div>

                    <!-- Workflow Pipeline -->
                    <div class="rts-card">
                        <div class="rts-card-header">Workflow Pipeline</div>
                        <div class="metric-row">
                            <span class="metric-label">Ingested (Raw)</span>
                            <span class="wf-badge inbox"><?php echo (int) $stats['letters_new']; ?> Letters</span>
                        </div>
                        <div class="metric-row">
                            <span class="metric-label">AI Refined (Processed)</span>
                            <span class="wf-badge inbox"><?php echo (int) $stats['letters_refined']; ?> Letters</span>
                        </div>
                        <div class="metric-row">
                            <span class="metric-label">Flagged (Safety)</span>
                            <span class="wf-badge quarantine"><?php echo (int) $stats['letters_flagged']; ?> Letters</span>
                        </div>
                        <div class="metric-row">
                            <span class="metric-label">Live on Site</span>
                            <span class="wf-badge live"><?php echo esc_html( number_format( $stats['letters_live'] ) ); ?> Letters</span>
                        </div>
                        <div style="margin-top:15px;padding-top:15px;border-top:1px solid var(--rts-line);">
                            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=letter' ) ); ?>" class="rts-btn rts-btn-primary" style="width:100%;justify-content:center;">Open Letter Studio</a>
                        </div>
                    </div>
                </div>

                <!-- RIGHT: Audience & Communications -->
                <div>
                    <div class="rts-section-title">Audience &amp; Communications</div>

                    <div class="rts-grid-4">
                        <div class="rts-card" style="padding:15px;">
                            <div class="rts-card-sub">Subscribers</div>
                            <div class="rts-card-value"><?php echo esc_html( number_format( $stats['subs_active'] ) ); ?></div>
                            <div style="font-size:11px;color:var(--rts-green);">Total Active</div>
                        </div>
                        <div class="rts-card" style="padding:15px;">
                            <div class="rts-card-sub">Open Rate</div>
                            <div class="rts-card-value"><?php echo esc_html( $stats['open_rate'] ); ?>%</div>
                            <div style="font-size:11px;color:var(--rts-txt-mute);">Average (30d)</div>
                        </div>
                        <div class="rts-card" style="padding:15px;">
                            <div class="rts-card-sub">Sent Today</div>
                            <div class="rts-card-value"><?php echo esc_html( number_format( $stats['sent_today'] ) ); ?></div>
                            <div style="font-size:11px;color:var(--rts-txt-mute);">Emails Delivered</div>
                        </div>
                        <div class="rts-card" style="padding:15px;">
                            <div class="rts-card-sub">Bounced</div>
                            <div class="rts-card-value" style="color:var(--rts-orange);"><?php echo esc_html( $stats['subs_bounced'] ); ?></div>
                            <div style="font-size:11px;color:var(--rts-txt-mute);">Requires clean</div>
                        </div>
                    </div>

                    <!-- Communication Tools -->
                    <div class="rts-card">
                        <div class="rts-card-header">Communication Tools</div>
                        <div class="metric-row">
                            <span class="metric-label">Newsletter Builder</span>
                            <span class="metric-val">Ready</span>
                        </div>
                        <div class="metric-row">
                            <span class="metric-label">SMTP Gateway (<?php echo esc_html( $stats['smtp_host'] ); ?>)</span>
                            <span class="metric-val">
                                <span class="status-dot <?php echo $stats['smtp_ok'] ? 'bg-green' : 'bg-red'; ?>"></span>
                                <?php echo $stats['smtp_ok'] ? 'Connected' : 'Failing'; ?>
                            </span>
                        </div>
                        <?php if ( $stats['smtp_msg'] ) : ?>
                        <div class="metric-row">
                            <span class="metric-label">SMTP Status</span>
                            <span class="metric-val" style="font-size:11px;"><?php echo esc_html( $stats['smtp_msg'] ); ?></span>
                        </div>
                        <?php endif; ?>
                        <div style="margin-top:15px;padding-top:15px;border-top:1px solid var(--rts-line);display:flex;gap:10px;">
                            <a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=rts_newsletter' ) ); ?>" class="rts-btn rts-btn-secondary" style="flex:1;justify-content:center;">Draft Newsletter</a>
                            <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=rts_subscriber' ) ); ?>" class="rts-btn rts-btn-secondary" style="flex:1;justify-content:center;">Manage Subs</a>
                        </div>
                    </div>
                </div>

            </div><!-- .rts-split-panel -->

            <!-- ─── ROW 2: TRAFFIC CONTROL ───────────────────────────────── -->
            <div class="rts-full-panel">
                <div class="rts-section-title">Traffic Control: Staggered Email Queue</div>

                <div class="queue-visualizer">
                    <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
                        <span style="color:var(--rts-txt);font-weight:700;">Live Outbound Volume</span>
                        <span style="color:<?php echo esc_attr( $system_color ); ?>;"><?php echo esc_html( $system_label ); ?></span>
                    </div>

                    <div class="queue-bar-container">
                        <?php if ( $total_vol > 0 ) : ?>
                            <div class="q-segment q-sent" style="width:<?php echo esc_attr( max( 2, $sent_pct ) ); ?>%;">SENT (<?php echo esc_html( number_format( $stats['sent_today'] ) ); ?>)</div>
                            <div class="q-segment q-queued" style="width:<?php echo esc_attr( max( 2, $queue_pct ) ); ?>%;">QUEUED (<?php echo esc_html( number_format( $stats['queue_total'] ) ); ?>)</div>
                        <?php else : ?>
                            <div class="q-segment q-empty" style="width:100%;">No queue activity</div>
                        <?php endif; ?>
                    </div>

                    <div class="queue-stats">
                        <div class="q-stat-item">
                            <span class="q-stat-label">Queue Total</span>
                            <span class="q-stat-val"><?php echo esc_html( number_format( $stats['queue_total'] ) ); ?></span>
                        </div>
                        <div class="q-stat-item">
                            <span class="q-stat-label">Pending</span>
                            <span class="q-stat-val" style="color:var(--rts-orange);"><?php echo esc_html( number_format( $stats['queue_pending'] ) ); ?></span>
                        </div>
                        <div class="q-stat-item">
                            <span class="q-stat-label">In-Progress</span>
                            <span class="q-stat-val" style="color:var(--rts-cyan);"><?php echo esc_html( number_format( $stats['queue_processing'] ) ); ?></span>
                        </div>
                        <div class="q-stat-item">
                            <span class="q-stat-label">Failed / Retry</span>
                            <span class="q-stat-val" style="color:var(--rts-red);"><?php echo esc_html( number_format( $stats['queue_failed'] ) ); ?></span>
                        </div>
                        <div class="q-stat-item" style="margin-left:auto;">
                            <span class="q-stat-label">Cron Status</span>
                            <span class="q-stat-val">
                                <span class="status-dot <?php echo $cron_ok ? 'bg-green' : 'bg-red'; ?>"></span>
                                <?php echo esc_html( $cron_label ); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- .rts-app-wrapper -->
        <?php
    }
}

// Self-bootstrap (matches existing inc/ pattern).
RTS_Admin_Dashboard_View::init();
