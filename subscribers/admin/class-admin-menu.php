<?php
/**
 * RTS Admin Menu - Enhanced with Email Templates
 * Modern card-based design, 35px radius, 35px padding
 */

if (!defined('ABSPATH')) exit;

class RTS_Admin_Menu {
    
    public function __construct() {
        add_action('admin_post_rts_toggle_pause_sending', array($this, 'handle_toggle_pause_sending'));

        add_action('admin_menu', array($this, 'add_menus'));
        // Final menu order pass (after other classes add their submenu pages).
        add_action('admin_menu', array($this, 'reorder_subscriber_submenu'), 999);
        add_action('admin_init', array($this, 'redirect_subscriber_post_new'));

        // Inject "Add Subscriber" UI into the top of the Subscribers list screen.
        add_action('manage_posts_extra_tablenav', array($this, 'render_add_subscriber_above_list'), 5, 1);

        add_action('admin_post_rts_save_template', array($this, 'handle_save_template'));
        add_action('admin_post_rts_test_template', array($this, 'handle_test_template'));
        add_action('admin_post_rts_add_subscriber', array($this, 'handle_add_subscriber'));

        // Ensure subscriber admin pages get the modern dashboard styling.
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }
    
    public function add_menus() {
        // Replace the default "Add New" subscriber screen with a simple, purpose-built form.
        remove_submenu_page('edit.php?post_type=rts_subscriber', 'post-new.php?post_type=rts_subscriber');
        remove_submenu_page('edit.php?post_type=rts_subscriber', 'post-new.php?post_type=rts_newsletter');

        // Primary consolidated dashboard (Analytics + Import + Sending)
        add_submenu_page(
            'edit.php?post_type=rts_subscriber',
            'Dashboard',
            'Dashboard',
            'manage_options',
            'rts-subscribers-dashboard',
            array($this, 'render_dashboard')
        );

        add_submenu_page(
            'edit.php?post_type=rts_subscriber',
            'Email Templates',
            'Email Templates',
            'manage_options',
            'rts-email-templates',
            array($this, 'render_templates')
        );

        // Keep Email Settings as a dedicated endpoint (but the dashboard also surfaces key sending controls).
        add_submenu_page(
            'edit.php?post_type=rts_subscriber',
            'Email Settings',
            'Email Settings',
            'manage_options',
            'rts-email-settings',
            array($this, 'render_settings')
        );

        // Analytics + Import are now dashboard sections (not separate submenu endpoints).
    }

    /**
     * Enqueue the shared dark dashboard styles on subscriber admin pages.
     *
     * The subscriber system enqueues some assets already, but custom submenu pages
     * (Dashboard/Templates/Settings) must always receive the same styling.
     */
    public function enqueue_admin_assets($hook_suffix) {
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $page = isset($_GET['page']) ? (string) $_GET['page'] : '';
        $post_type = isset($_GET['post_type']) ? (string) $_GET['post_type'] : '';

        $is_subscriber_context = (
            $post_type === 'rts_subscriber'
            || $post_type === 'rts_newsletter'
            || in_array($page, array('rts-subscribers-dashboard', 'rts-email-templates', 'rts-email-settings'), true)
        );

        if (!$is_subscriber_context) {
            return;
        }

        $ver = defined('RTS_THEME_VERSION') ? RTS_THEME_VERSION : (string) time();

        // Shared admin styling (Letters dashboard look)
        $shared_css_path = get_stylesheet_directory() . '/assets/css/rts-admin.css';
        if (file_exists($shared_css_path)) {
            wp_enqueue_style('rts-admin-shared', get_stylesheet_directory_uri() . '/assets/css/rts-admin.css', array(), $ver);
        }

        // Subscriber-specific styling
        $subscriber_css_path = get_stylesheet_directory() . '/subscribers/assets/css/admin.css';
        if (file_exists($subscriber_css_path)) {
            $deps = wp_style_is('rts-admin-shared', 'enqueued') ? array('rts-admin-shared') : array();
            wp_enqueue_style('rts-subscriber-admin', get_stylesheet_directory_uri() . '/subscribers/assets/css/admin.css', $deps, $ver);
        }

        // Small JS enhancements for importer progress + UI polish
        $subscriber_js_path = get_stylesheet_directory() . '/subscribers/assets/js/admin.js';
        if (file_exists($subscriber_js_path)) {
            wp_enqueue_script('rts-subscriber-admin', get_stylesheet_directory_uri() . '/subscribers/assets/js/admin.js', array('jquery'), $ver, true);
        }
    }

    /**
     * Ensure the Subscribers submenu order is predictable for client handover.
     * Desired order:
     * 1) Dashboard
     * 2) Subscribers (default list)
     * 3) Newsletters
     * 4) Email Templates
     * 5) Email Settings
     */
    public function reorder_subscriber_submenu() {
        if (!is_admin()) {
            return;
        }
        global $submenu;
        $parent = 'edit.php?post_type=rts_subscriber';
        if (empty($submenu[$parent]) || !is_array($submenu[$parent])) {
            return;
        }

        $items = $submenu[$parent];
        $by_slug = array();
        foreach ($items as $it) {
            if (!isset($it[2])) {
                continue;
            }
            $by_slug[$it[2]] = $it;
        }

        $desired = array(
            'rts-subscribers-dashboard',
            'edit.php?post_type=rts_subscriber',
            'edit.php?post_type=rts_newsletter',
            'rts-email-templates',
            'rts-email-settings',
        );

        $new = array();
        foreach ($desired as $slug) {
            if (isset($by_slug[$slug])) {
                $new[] = $by_slug[$slug];
                unset($by_slug[$slug]);
            }
        }

        // Append anything else we didn't explicitly order (keeps compatibility).
        foreach ($items as $it) {
            $slug = $it[2] ?? '';
            if ($slug && isset($by_slug[$slug])) {
                $new[] = $by_slug[$slug];
                unset($by_slug[$slug]);
            }
        }

        $submenu[$parent] = $new;
    }

    /**
     * Render "Add Subscriber" as a card above the Subscribers list table.
     * Removes the need for a separate endpoint.
     */
    public function render_add_subscriber_above_list($which) {
        if ($which !== 'top') {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->base !== 'edit' || $screen->post_type !== 'rts_subscriber') {
            return;
        }

        $notice = '';
        if (isset($_GET['rts_added']) && $_GET['rts_added'] === '1') {
            $notice = '<div class="notice notice-success is-dismissible"><p>Subscriber added.</p></div>';
        } elseif (isset($_GET['rts_added']) && $_GET['rts_added'] === '0') {
            $notice = '<div class="notice notice-error is-dismissible"><p>Could not add subscriber. Please check the email address.</p></div>';
        }

        echo $notice;
        ?>
        <div class="rts-card" style="padding:25px;border-radius:35px;margin:0 0 20px 0;">
            <h2 style="margin:0 0 10px 0;">Add Subscriber</h2>
	            <p style="margin:0 0 15px 0;color:#ffffff;opacity:0.95;">Quickly add a subscriber without leaving this list.</p>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;">
                <input type="hidden" name="action" value="rts_add_subscriber" />
                <?php wp_nonce_field('rts_add_subscriber', '_wpnonce'); ?>

                <div style="flex:1;min-width:240px;">
                    <label class="rts-form-label" for="rts_email_inline" style="display:block;margin-bottom:6px;">Email</label>
                    <input id="rts_email_inline" type="email" name="email" required placeholder="name@example.com"
                           style="display:block;width:100%;padding:12px 14px;background:#0f172a;border:2px solid #334155;border-radius:14px;color:#f1f5f9;" />
                </div>

                <div style="min-width:180px;">
                    <label class="rts-form-label" for="rts_frequency_inline" style="display:block;margin-bottom:6px;">Frequency</label>
                    <select id="rts_frequency_inline" name="frequency" style="display:block;width:100%;padding:12px 14px;background:#0f172a;border:2px solid #334155;border-radius:14px;color:#f1f5f9;">
                        <option value="weekly">Weekly</option>
                        <option value="daily">Daily</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>

                <div style="min-width:220px;">
                    <label style="display:block;margin-bottom:6px;">Subscriptions</label>
                    <label style="display:inline-flex;align-items:center;gap:8px;margin-right:12px;">
                        <input type="checkbox" name="pref_letters" value="1" /> Letters
                    </label>
                    <label style="display:inline-flex;align-items:center;gap:8px;">
                        <input type="checkbox" name="pref_newsletters" value="1" /> Newsletters
                    </label>
                </div>

                <div style="min-width:160px;">
                    <button type="submit" class="rts-button success" style="margin:0;">
                        <span class="dashicons dashicons-yes"></span> Save Subscriber
                    </button>
                </div>
            </form>
	            <p style="margin:12px 0 0 0;color:#ffffff;opacity:0.9;font-size:13px;">If Re-consent Required is enabled, subscribers will not receive anything until they confirm in the preference centre.</p>
        </div>
        <?php
    }

    /**
     * Consolidated Subscriber Dashboard
     * - Top: Analytics summary (from rts-analytics-page)
     * - Bottom: 50/50 cards (Import Subscribers + SMTP / Sending essentials)
     */
    public function render_dashboard() {
        // Analytics block (reuse RTS_Analytics)
        $analytics = class_exists('RTS_Analytics') ? new RTS_Analytics() : null;
        $stats = $analytics ? $analytics->get_subscriber_stats() : array('total'=>0,'active'=>0,'bounced'=>0,'unsubscribed'=>0);

        // Letter email send counts (today / this week / this month / this year)
        $sent = $this->get_letter_email_sent_counts();

        // Subscriber churn chart (gained/lost per day, current month)
        $flow = $this->get_subscriber_flow_current_month();
        $days_in_month = (int) ($flow['days_in_month'] ?? 30);
        $gained_by_day = (array) ($flow['gained'] ?? array());
        $lost_by_day   = (array) ($flow['lost'] ?? array());
        $max_value     = (int) ($flow['max'] ?? 0);

        ?>
        <div class="wrap rts-dashboard-page rts-analytics-page rts-settings-page">
            <div class="rts-page-header">
                <h1><span class="dashicons dashicons-dashboard"></span>Subscriber Dashboard</h1>
                <p class="rts-page-description">A single place to manage sending, SMTP, and imports.</p>
            </div>

            <!-- Zone: Letter Emails Sent -->
            <div class="rts-card" style="padding:35px;border-radius:35px;margin-top:25px;">
                <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;">
                    <div>
                        <h2 style="margin:0;">Letters Sent</h2>
                        <p style="margin:6px 0 0 0;opacity:0.9;">Counts based on sent queue items linked to letters.</p>
                    </div>
                    <a class="button rts-button secondary" href="<?php echo esc_url(admin_url('edit.php?post_type=rts_newsletter')); ?>" style="margin:0;">View Newsletters</a>
                </div>

                <div class="rts-metrics-grid" style="margin-top:18px;" aria-label="Letters sent totals">
                    <div class="rts-metric-card">
                        <span class="rts-metric-label">Today</span>
                        <span class="rts-metric-value"><?php echo number_format((int)($sent['day'] ?? 0)); ?></span>
                        <span class="rts-metric-subtitle">Sent since midnight</span>
                    </div>
                    <div class="rts-metric-card">
                        <span class="rts-metric-label">This Week</span>
                        <span class="rts-metric-value"><?php echo number_format((int)($sent['week'] ?? 0)); ?></span>
                        <span class="rts-metric-subtitle">Mon to now</span>
                    </div>
                    <div class="rts-metric-card">
                        <span class="rts-metric-label">This Month</span>
                        <span class="rts-metric-value"><?php echo number_format((int)($sent['month'] ?? 0)); ?></span>
                        <span class="rts-metric-subtitle">Month to date</span>
                    </div>
                    <div class="rts-metric-card">
                        <span class="rts-metric-label">This Year</span>
                        <span class="rts-metric-value"><?php echo number_format((int)($sent['year'] ?? 0)); ?></span>
                        <span class="rts-metric-subtitle">Year to date</span>
                    </div>
                </div>
            </div>

            <!-- Zone: Subscriber flow chart (current month) -->
            <div class="rts-card" style="padding:35px;border-radius:35px;margin-top:25px;">
                <h2 style="margin:0;">Subscribers Gained vs Lost (This Month)</h2>
                <p style="margin:6px 0 0 0;opacity:0.9;">Daily changes for the current month. "Lost" is based on unsubscribe date.</p>

                <div class="rts-subscriber-bar-chart" role="img" aria-label="Bar chart showing subscribers gained and lost per day this month" style="margin-top:18px;">
                    <div class="rts-subscriber-bar-chart__legend" style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">
                        <span style="display:inline-flex;align-items:center;gap:8px;"><span style="width:12px;height:12px;border-radius:3px;background:#22c55e;display:inline-block;"></span> Gained</span>
                        <span style="display:inline-flex;align-items:center;gap:8px;"><span style="width:12px;height:12px;border-radius:3px;background:#ef4444;display:inline-block;"></span> Lost</span>
                    </div>

                    <div class="rts-subscriber-bar-chart__grid" style="display:flex;gap:8px;align-items:flex-end;overflow:auto;padding-bottom:10px;">
                        <?php
                        $max = max(1, $max_value);
                        for ($day = 1; $day <= $days_in_month; $day++) :
                            $g = (int) ($gained_by_day[$day] ?? 0);
                            $l = (int) ($lost_by_day[$day] ?? 0);
                            $g_h = (int) round(($g / $max) * 120);
                            $l_h = (int) round(($l / $max) * 120);
                        ?>
                            <div style="min-width:26px;flex:0 0 auto;display:flex;flex-direction:column;align-items:center;gap:6px;">
                                <div style="height:130px;width:26px;display:flex;align-items:flex-end;justify-content:center;gap:4px;">
                                    <span title="Day <?php echo (int)$day; ?> gained: <?php echo (int)$g; ?>" aria-label="Day <?php echo (int)$day; ?> gained <?php echo (int)$g; ?>" style="width:10px;height:<?php echo (int)$g_h; ?>px;background:#22c55e;border-radius:4px 4px 2px 2px;"></span>
                                    <span title="Day <?php echo (int)$day; ?> lost: <?php echo (int)$l; ?>" aria-label="Day <?php echo (int)$day; ?> lost <?php echo (int)$l; ?>" style="width:10px;height:<?php echo (int)$l_h; ?>px;background:#ef4444;border-radius:4px 4px 2px 2px;"></span>
                                </div>
                                <div style="font-size:11px;opacity:0.85;line-height:1;"><?php echo (int)$day; ?></div>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <details style="margin-top:12px;">
                        <summary style="cursor:pointer;">View as table</summary>
                        <div style="overflow:auto;margin-top:10px;">
                            <table class="widefat striped" style="background:#0b1220;color:#e2e8f0;border-color:#334155;min-width:520px;">
                                <thead>
                                    <tr>
                                        <th>Day</th>
                                        <th>Gained</th>
                                        <th>Lost</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php for ($day = 1; $day <= $days_in_month; $day++) : ?>
                                        <tr>
                                            <td><?php echo (int)$day; ?></td>
                                            <td><?php echo (int)($gained_by_day[$day] ?? 0); ?></td>
                                            <td><?php echo (int)($lost_by_day[$day] ?? 0); ?></td>
                                        </tr>
                                    <?php endfor; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                </div>
            </div>

            <!-- Zone: Analytics (moved from Analytics page) -->
            <div class="rts-metrics-grid" aria-label="Subscriber analytics summary">
                <div class="rts-metric-card">
                    <span class="rts-metric-label">Total Subscribers</span>
                    <span class="rts-metric-value"><?php echo number_format((int)($stats['total'] ?? 0)); ?></span>
                    <span class="rts-metric-subtitle">All time</span>
                </div>

                <div class="rts-metric-card">
                    <span class="rts-metric-label">Active Subscribers</span>
                    <span class="rts-metric-value"><?php echo number_format((int)($stats['active'] ?? 0)); ?></span>
                    <span class="rts-metric-subtitle">Currently receiving emails</span>
                </div>

                <div class="rts-metric-card">
                    <span class="rts-metric-label">Bounced</span>
                    <span class="rts-metric-value"><?php echo number_format((int)($stats['bounced'] ?? 0)); ?></span>
                    <span class="rts-metric-subtitle">Will not receive emails</span>
                </div>

                <div class="rts-metric-card">
                    <span class="rts-metric-label">Unsubscribed</span>
                    <span class="rts-metric-value"><?php echo number_format((int)($stats['unsubscribed'] ?? 0)); ?></span>
                    <span class="rts-metric-subtitle">Opted out</span>
                </div>
            </div>

            <!-- Zone: Letter email sending analytics -->
            <div class="rts-card" style="padding:35px;border-radius:35px;margin-top:25px;">
                <h2 style="margin:0 0 8px 0;">Letter Emails Sent</h2>
                <p style="margin:0 0 18px 0;opacity:0.9;">Counts are based on successfully sent queue items linked to a letter.</p>

                <div class="rts-metrics-grid" style="margin-top:0;">
                    <div class="rts-metric-card">
                        <span class="rts-metric-label">Today</span>
                        <span class="rts-metric-value"><?php echo number_format((int)($sent['day'] ?? 0)); ?></span>
                        <span class="rts-metric-subtitle">Sent since midnight</span>
                    </div>

                    <div class="rts-metric-card">
                        <span class="rts-metric-label">This Week</span>
                        <span class="rts-metric-value"><?php echo number_format((int)($sent['week'] ?? 0)); ?></span>
                        <span class="rts-metric-subtitle">Mon to now</span>
                    </div>

                    <div class="rts-metric-card">
                        <span class="rts-metric-label">This Month</span>
                        <span class="rts-metric-value"><?php echo number_format((int)($sent['month'] ?? 0)); ?></span>
                        <span class="rts-metric-subtitle">Current month</span>
                    </div>

                    <div class="rts-metric-card">
                        <span class="rts-metric-label">This Year</span>
                        <span class="rts-metric-value"><?php echo number_format((int)($sent['year'] ?? 0)); ?></span>
                        <span class="rts-metric-subtitle">Calendar year</span>
                    </div>
                </div>
            </div>

            <!-- Zone: Subscriber gained/lost bar chart -->
            <div class="rts-card" style="padding:35px;border-radius:35px;margin-top:25px;">
                <h2 style="margin:0 0 8px 0;">Subscribers This Month</h2>
                <p style="margin:0 0 18px 0;opacity:0.9;">Daily gained vs lost (unsubscribes) for the current month.</p>

                <?php if ($days_in_month <= 0) : ?>
                    <p style="margin:0;color:#cbd5e1;">No chart data available.</p>
                <?php else : ?>
                    <?php
                    $max_value = max(1, $max_value);
                    ?>
                    <div class="rts-subscriber-bars" role="img" aria-label="Bar chart showing subscribers gained and lost per day this month" style="display:flex;gap:6px;align-items:flex-end;overflow:auto;padding:10px 6px;border:1px solid #334155;border-radius:20px;background:#0b1220;">
                        <?php for ($d = 1; $d <= $days_in_month; $d++) :
                            $g = (int) ($gained_by_day[$d] ?? 0);
                            $l = (int) ($lost_by_day[$d] ?? 0);
                            $gh = (int) round(($g / $max_value) * 120);
                            $lh = (int) round(($l / $max_value) * 120);
                            $label = sprintf('Day %d: gained %d, lost %d', $d, $g, $l);
                            ?>
                            <div style="min-width:14px;display:flex;flex-direction:column;align-items:center;gap:6px;" aria-label="<?php echo esc_attr($label); ?>">
                                <div style="display:flex;gap:2px;align-items:flex-end;height:130px;">
                                    <div title="Gained: <?php echo esc_attr($g); ?>" style="width:6px;height:<?php echo esc_attr($gh); ?>px;border-radius:999px;background:#f59e0b;"></div>
                                    <div title="Lost: <?php echo esc_attr($l); ?>" style="width:6px;height:<?php echo esc_attr($lh); ?>px;border-radius:999px;background:#94a3b8;"></div>
                                </div>
                                <div style="font-size:10px;opacity:0.75;color:#cbd5e1;line-height:1;"><?php echo esc_html($d); ?></div>
                            </div>
                        <?php endfor; ?>
                    </div>

                    <div style="display:flex;gap:14px;flex-wrap:wrap;margin-top:12px;color:#cbd5e1;">
                        <span style="display:inline-flex;align-items:center;gap:8px;"><span style="width:14px;height:6px;border-radius:999px;background:#f59e0b;display:inline-block;"></span>Gained</span>
                        <span style="display:inline-flex;align-items:center;gap:8px;"><span style="width:14px;height:6px;border-radius:999px;background:#94a3b8;display:inline-block;"></span>Lost</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Zone: 50/50 cards -->
            <div class="rts-two-col" style="display:grid;grid-template-columns:1fr 1fr;gap:25px;align-items:start;margin-top:25px;">
                <div class="rts-card" id="rts-smtp-card" style="padding:35px;border-radius:35px;">
                    <h2 style="margin-top:0;">Import Subscribers</h2>
                    <p style="margin-top:6px;opacity:0.9;">Upload a CSV file to import subscribers in bulk.</p>
                    <?php $this->render_import_inner(); ?>
                </div>

                <div class="rts-card" style="padding:35px;border-radius:35px;">
                    <h2 style="margin-top:0;">SMTP and Sending</h2>
                    <p style="margin-top:6px;opacity:0.9;">Configure SMTP2GO, then enable sending when you are ready.</p>

                    <?php $this->render_smtp_and_sending_inner(); ?>
                </div>
            </div>

            <style>
                @media (max-width: 900px){
                    .rts-two-col{ grid-template-columns:1fr !important; }
                }
            </style>
        </div>
        <?php
    }

    /**
     * Count sent emails that are linked to letters (queue items with letter_id)
     * for key time windows: day, week, month, year.
     *
     * Uses the rts_email_queue table as the source of truth.
     */
    private function get_letter_email_sent_counts() {
        global $wpdb;

        $out = array('day' => 0, 'week' => 0, 'month' => 0, 'year' => 0);

        $table = $wpdb->prefix . (class_exists('RTS_Email_Queue') ? RTS_Email_Queue::QUEUE_TABLE : 'rts_email_queue');

        // If the queue table doesn't exist, fail gracefully.
        $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($exists !== $table) {
            return $out;
        }

        // Build local time windows, then convert to GMT for querying sent_at (stored as GMT).
        $tz = wp_timezone();
        $now_local = new DateTime('now', $tz);

        $start_day_local = (clone $now_local)->setTime(0, 0, 0);
        $start_week_local = (clone $now_local);
        // Week starts Monday for consistency with UK expectations.
        $dow = (int) $start_week_local->format('N');
        $start_week_local->modify('-' . ($dow - 1) . ' days')->setTime(0, 0, 0);

        $start_month_local = (clone $now_local)->modify('first day of this month')->setTime(0, 0, 0);
        $start_year_local  = (clone $now_local)->setDate((int)$now_local->format('Y'), 1, 1)->setTime(0, 0, 0);

        $end_local = (clone $now_local);

        $ranges = array(
            'day'   => array($start_day_local, $end_local),
            'week'  => array($start_week_local, $end_local),
            'month' => array($start_month_local, $end_local),
            'year'  => array($start_year_local, $end_local),
        );

        foreach ($ranges as $key => $pair) {
            list($s_local, $e_local) = $pair;
            $s_gmt = get_gmt_from_date($s_local->format('Y-m-d H:i:s'));
            $e_gmt = get_gmt_from_date($e_local->format('Y-m-d H:i:s'));

            $sql = $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE status = 'sent'
                 AND sent_at IS NOT NULL
                 AND (letter_id IS NOT NULL AND letter_id > 0)
                 AND sent_at >= %s AND sent_at <= %s",
                $s_gmt,
                $e_gmt
            );

            $out[$key] = (int) $wpdb->get_var($sql);
        }

        return $out;
    }

    /**
     * Compute gained/lost subscribers per day for the current month.
     * - Gained uses subscriber post_date (local time)
     * - Lost uses meta _rts_subscriber_unsubscribed_at (local time)
     */
    private function get_subscriber_flow_current_month() {
        global $wpdb;

        $tz = wp_timezone();
        $now = new DateTime('now', $tz);
        $start = (clone $now)->modify('first day of this month')->setTime(0, 0, 0);
        $end = (clone $start)->modify('first day of next month')->setTime(0, 0, 0);

        $days_in_month = (int) $start->format('t');
        $gained = array();
        $lost   = array();
        $max    = 0;
        for ($i = 1; $i <= $days_in_month; $i++) {
            $gained[$i] = 0;
            $lost[$i] = 0;
        }

        $posts = $wpdb->posts;
        $postmeta = $wpdb->postmeta;

        // Gains: group by day(post_date)
        $gain_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DAY(post_date) AS d, COUNT(ID) AS c
             FROM {$posts}
             WHERE post_type = 'rts_subscriber'
             AND post_status = 'publish'
             AND post_date >= %s AND post_date < %s
             GROUP BY DAY(post_date)",
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s')
        ));

        foreach ($gain_rows as $r) {
            $d = (int) ($r->d ?? 0);
            $c = (int) ($r->c ?? 0);
            if ($d >= 1 && $d <= $days_in_month) {
                $gained[$d] = $c;
                $max = max($max, $c);
            }
        }

        // Losses: group by day(unsubscribed_at)
        $loss_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT DAY(pm.meta_value) AS d, COUNT(pm.post_id) AS c
             FROM {$postmeta} pm
             INNER JOIN {$posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'rts_subscriber'
             AND p.post_status = 'publish'
             AND pm.meta_key = '_rts_subscriber_unsubscribed_at'
             AND pm.meta_value >= %s AND pm.meta_value < %s
             GROUP BY DAY(pm.meta_value)",
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s')
        ));

        foreach ($loss_rows as $r) {
            $d = (int) ($r->d ?? 0);
            $c = (int) ($r->c ?? 0);
            if ($d >= 1 && $d <= $days_in_month) {
                $lost[$d] = $c;
                $max = max($max, $c);
            }
        }

        return array(
            'days_in_month' => $days_in_month,
            'gained' => $gained,
            'lost' => $lost,
            'max' => $max,
        );
    }

    /**
     * Inner: Import UI (no header/wrap)
     */
    private function render_import_inner() {
        // Full importer UI (large imports + progress polling).
        $rts_session_id = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '';
        if ($rts_session_id) {
            $rts_nonce = wp_create_nonce('rts_import_progress');
        }

        // Progress card (if returning from an active import)
        if ($rts_session_id) : ?>
            <div class="rts-card" id="rts-import-progress" style="margin:0 0 18px 0;" data-session="<?php echo esc_attr($rts_session_id); ?>" data-nonce="<?php echo esc_attr($rts_nonce); ?>">
                <h3 style="margin:0 0 8px 0;"><span class="dashicons dashicons-update"></span> Import Progress</h3>
                <p id="rts-import-status" style="margin:0;color:#cbd5e1;">Loading progress...</p>
                <div style="margin-top:15px;">
                    <div style="height:10px;background:#0f172a;border:1px solid #334155;border-radius:999px;overflow:hidden;">
                        <div id="rts-import-bar" style="height:10px;width:0%;background:#22c55e;"></div>
                    </div>
                    <p id="rts-import-counts" style="margin:10px 0 0 0;color:#cbd5e1;"></p>
                </div>
                <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap;">
                    <button type="button" class="rts-button warning" id="rts-cancel-import-btn" style="margin:0;">
                        <span class="dashicons dashicons-no-alt"></span> Cancel Import
                    </button>
                    <a class="rts-button secondary" href="<?php echo esc_url(admin_url('edit.php?post_type=rts_subscriber')); ?>" style="margin:0;">
                        <span class="dashicons dashicons-admin-users"></span> View Subscribers
                    </a>
                </div>
            </div>
            <script>
            (function(){
                var wrap = document.getElementById('rts-import-progress');
                if(!wrap) return;
                var sessionId = wrap.getAttribute('data-session') || '';
                var nonce = wrap.getAttribute('data-nonce') || '';
                var bar = document.getElementById('rts-import-bar');
                var statusEl = document.getElementById('rts-import-status');
                var countsEl = document.getElementById('rts-import-counts');
                var cancelBtn = document.getElementById('rts-cancel-import-btn');

                function pct(part, total){
                    if(!total || total <= 0) return 0;
                    return Math.min(100, Math.max(0, Math.round((part/total)*100)));
                }

                function tick(){
                    var data = new FormData();
                    data.append('action','rts_import_progress');
                    data.append('nonce', nonce);
                    data.append('session_id', sessionId);
                    fetch(ajaxurl, {method:'POST', body:data, credentials:'same-origin'})
                        .then(function(r){ return r.json(); })
                        .then(function(resp){
                            if(!resp || !resp.success){
                                statusEl.textContent = 'Progress unavailable.';
                                return;
                            }
                            var s = resp.data || {};
                            var total = parseInt(s.total_rows || 0, 10);
                            var processed = parseInt(s.processed || 0, 10);
                            var imported = parseInt(s.imported || 0, 10);
                            var dup = parseInt(s.skipped_duplicate || 0, 10);
                            var inv = parseInt(s.skipped_invalid || 0, 10);
                            var oth = parseInt(s.skipped_other || 0, 10);
                            var st = (s.status || 'processing');
                            var p = pct(processed, total);
                            if (bar) bar.style.width = p + '%';
                            statusEl.textContent = (st === 'complete')
                                ? ('Complete. Imported ' + imported + ' subscribers.')
                                : (st === 'cancelled')
                                    ? ('Import cancelled. Imported ' + imported + ' subscribers.')
                                    : ('Processing... ' + p + '%');

                            countsEl.textContent =
                                'Processed: ' + processed + ' / ' + total +
                                ' | Imported: ' + imported +
                                ' | Duplicates: ' + dup +
                                ' | Invalid: ' + inv +
                                ' | Other skipped: ' + oth;

                            if(st !== 'complete' && st !== 'error' && st !== 'cancelled'){
                                setTimeout(tick, 2000);
                            }
                        })
                        .catch(function(){
                            statusEl.textContent = 'Progress check failed. Refresh to retry.';
                        });
                }

                if(cancelBtn){
                    cancelBtn.addEventListener('click', function(){
                        if(!confirm('Cancel this import? Already imported rows will remain.')) return;
                        cancelBtn.disabled = true;
                        var fd = new FormData();
                        fd.append('action','rts_cancel_import');
                        fd.append('nonce', nonce);
                        fd.append('session_id', sessionId);
                        fetch(ajaxurl, {method:'POST', body:fd, credentials:'same-origin'})
                            .then(function(r){ return r.json(); })
                            .then(function(){ cancelBtn.disabled = false; tick(); })
                            .catch(function(){ cancelBtn.disabled = false; });
                    });
                }

                tick();
            })();
            </script>
        <?php endif; ?>

        <div class="rts-card" style="margin:0 0 18px 0;">
            <h3 style="margin:0 0 10px 0;"><span class="dashicons dashicons-media-spreadsheet"></span> CSV Import / Export</h3>
            <p style="margin:0 0 12px 0;color:#cbd5e1;">Import subscribers in bulk (background batches). Export gives you a clean CSV for backups or handover.</p>

            <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-start;">
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="flex:1;min-width:280px;">
                    <?php wp_nonce_field('rts_import_csv'); ?>
                    <input type="hidden" name="action" value="rts_import_csv">

                    <div class="rts-form-row">
                        <label class="rts-form-label">Select CSV File</label>
                        <input type="file" name="csv_file" accept=".csv" required style="display:block;padding:10px;background:#0f172a;border:2px solid #334155;border-radius:10px;color:#f1f5f9;">
                        <span class="rts-form-description">Required column: <code>email</code>. Optional: <code>frequency</code>.</span>
                    </div>

                    <div class="rts-form-row">
                        <label class="rts-form-label">Default Frequency (if not in CSV)</label>
                        <select name="default_frequency" class="rts-form-select">
                            <option value="weekly">Weekly (recommended)</option>
                            <option value="daily">Daily</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>

                    <button type="submit" class="rts-button success" style="margin:0;">
                        <span class="dashicons dashicons-upload"></span> Import Subscribers
                    </button>
                </form>

                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="min-width:240px;">
                    <?php wp_nonce_field('rts_export_csv'); ?>
                    <input type="hidden" name="action" value="rts_export_csv">
                    <div class="rts-form-row">
                        <label class="rts-form-label">Export Subscribers</label>
                        <select name="export_scope" class="rts-form-select">
                            <option value="all">All subscribers</option>
                            <option value="active">Active only</option>
                            <option value="bounced">Bounced only</option>
                            <option value="unsubscribed">Unsubscribed only</option>
                        </select>
                        <span class="rts-form-description">Downloads a CSV file.</span>
                    </div>
                    <button type="submit" class="rts-button secondary" style="margin:0;">
                        <span class="dashicons dashicons-download"></span> Export CSV
                    </button>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Inner: Condensed SMTP + essential sending toggles (no huge settings page)
     */
    private function render_smtp_and_sending_inner() {
        // Socket test status (powers green dot)
        $smtp_ok = false;
        if (class_exists('RTS_SMTP_Settings')) {
            try {
                $smtp = new RTS_SMTP_Settings();
                if (method_exists($smtp, 'test_smtp_connection')) {
                    $smtp_ok = (bool) $smtp->test_smtp_connection();
                }
            } catch (\Throwable $e) {
                $smtp_ok = false;
            }
        }

        $dot = $smtp_ok ? '#22c55e' : '#ef4444';
        $label = $smtp_ok ? 'SMTP reachable (socket OK)' : 'SMTP unreachable (socket failed)';
        ?>
        <div style="display:flex;align-items:center;gap:10px;margin:8px 0 18px;">
            <span aria-hidden="true" style="width:10px;height:10px;border-radius:999px;background:<?php echo esc_attr($dot); ?>;display:inline-block;"></span>
            <strong><?php echo esc_html($label); ?></strong>
        </div>

        <form method="post" action="options.php">
            <?php settings_fields('rts_smtp_settings_group'); ?>

            <div class="rts-form-row">
                <label class="rts-form-label">Enable SMTP</label>
                <label style="display:inline-flex;align-items:center;gap:10px;">
                    <input type="checkbox" name="rts_smtp_enabled" value="1" <?php checked((bool)get_option('rts_smtp_enabled', false)); ?> />
                    <span style="opacity:0.9;">Route emails through SMTP2GO</span>
                </label>
            </div>

            <div class="rts-form-row">
                <label class="rts-form-label">SMTP Host</label>
                <input type="text" name="rts_smtp_host"
                       value="<?php echo esc_attr(get_option('rts_smtp_host', 'mail.smtp2go.com')); ?>"
                       class="rts-form-input" placeholder="mail.smtp2go.com" required>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="rts-form-row" style="margin:0;">
                    <label class="rts-form-label">SMTP Port</label>
                    <input type="number" name="rts_smtp_port"
                           value="<?php echo esc_attr((int)get_option('rts_smtp_port', 2525)); ?>"
                           class="rts-form-input" min="1" max="65535" required>
                </div>
                <div class="rts-form-row" style="margin:0;">
                    <label class="rts-form-label">Encryption</label>
                    <select name="rts_smtp_encryption" class="rts-form-select">
                        <option value="tls" <?php selected(get_option('rts_smtp_encryption', 'tls'), 'tls'); ?>>TLS (recommended)</option>
                        <option value="ssl" <?php selected(get_option('rts_smtp_encryption', 'tls'), 'ssl'); ?>>SSL</option>
                        <option value="none" <?php selected(get_option('rts_smtp_encryption', 'tls'), 'none'); ?>>None</option>
                    </select>
                </div>
            </div>

            <div class="rts-form-row">
                <label class="rts-form-label">Authentication</label>
                <label style="display:inline-flex;align-items:center;gap:10px;">
                    <input type="checkbox" name="rts_smtp_auth" value="1" <?php checked((bool)get_option('rts_smtp_auth', true)); ?> />
                    <span style="opacity:0.9;">Use username/password</span>
                </label>
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                <div class="rts-form-row" style="margin:0;">
                    <label class="rts-form-label">Username</label>
                    <input type="text" name="rts_smtp_user"
                           value="<?php echo esc_attr(get_option('rts_smtp_user', '')); ?>"
                           class="rts-form-input" placeholder="your-smtp2go-username">
                </div>
                <div class="rts-form-row" style="margin:0;">
                    <label class="rts-form-label">Password</label>
                    <input type="password" name="rts_smtp_pass"
                           value="" class="rts-form-input" placeholder="Leave blank to keep existing">
                    <span class="rts-form-description">Leave blank to keep existing password.</span>
                </div>
            </div>

            <div class="rts-form-row">
                <label class="rts-form-label">From Email</label>
                <input type="email" name="rts_smtp_from_email"
                       value="<?php echo esc_attr(get_option('rts_smtp_from_email')); ?>"
                       class="rts-form-input" required>
                <span class="rts-form-description">The email address emails will be sent from.</span>
            </div>

            <div class="rts-form-row">
                <label class="rts-form-label">From Name</label>
                <input type="text" name="rts_smtp_from_name"
                       value="<?php echo esc_attr(get_option('rts_smtp_from_name')); ?>"
                       class="rts-form-input" required>
                <span class="rts-form-description">The sender name subscribers will see.</span>
            </div>

            <div class="rts-form-row">
                <label class="rts-form-label">Reply-To Email</label>
                <input type="email" name="rts_smtp_reply_to"
                       value="<?php echo esc_attr(get_option('rts_smtp_reply_to')); ?>"
                       class="rts-form-input">
                <span class="rts-form-description" style="color:#ffffff;opacity:0.9;">Optional: where replies go.</span>
            </div>

            <div class="rts-form-row">
                <label class="rts-form-label">CC Email</label>
                <input type="email" name="rts_smtp_cc_email"
                       value="<?php echo esc_attr(get_option('rts_smtp_cc_email')); ?>"
                       class="rts-form-input" placeholder="Optional">
                <span class="rts-form-description" style="color:#ffffff;opacity:0.9;">Optional: add a CC on outgoing subscriber emails.</span>
            </div>

            <div class="rts-form-row">
                <label class="rts-form-label">Enable Sending</label>
                <label style="display:flex;align-items:center;gap:10px;">
                    <input type="checkbox" name="rts_email_sending_enabled" value="1" <?php checked(get_option('rts_email_sending_enabled'), 1); ?>>
                    <span style="color:#ffffff;">Allow the system to queue and send emails</span>
                </label>
                <span class="rts-form-description" style="color:#ffffff;opacity:0.9;">Keep OFF until SMTP is confirmed.</span>
            </div>

            <div class="rts-form-row">
                <label class="rts-form-label">Demo Mode</label>
                <label style="display:flex;align-items:center;gap:10px;">
                    <input type="checkbox" name="rts_email_demo_mode" value="1" <?php checked(get_option('rts_email_demo_mode'), 1); ?>>
                    <span style="color:#F4C946;font-weight:700;">Demo mode ON = nothing gets sent</span>
                </label>
                <span class="rts-form-description" style="color:#ffffff;opacity:0.9;">Cancels emails with a log entry for safe testing.</span>
            </div>

            <?php submit_button('Save SMTP + Sending Settings'); ?>
        </form>

        <?php if (current_user_can('manage_options')) : ?>
            <hr style="margin:18px 0;opacity:0.2;">
            <h3 style="margin:0 0 10px;">Send Test Email</h3>
            <p style="margin-top:0;opacity:0.9;">Send a test email using the current SMTP settings.</p>
            <div class="rts-form-row">
                <input type="email" id="rts_test_smtp_to" class="rts-form-input" placeholder="Test recipient email">
                <button type="button" class="button" id="rts_test_smtp_btn" style="margin-top:10px;">Send Test Email</button>
                <div id="rts_test_smtp_result" style="margin-top:10px;"></div>
            </div>
            <script>
            (function(){
                var btn = document.getElementById('rts_test_smtp_btn');
                if(!btn) return;
                btn.addEventListener('click', function(){
                    var email = document.getElementById('rts_test_smtp_to').value || '';
                    var out = document.getElementById('rts_test_smtp_result');
                    out.textContent = 'Sending...';
                    var data = new FormData();
                    data.append('action', 'rts_test_smtp');
                    data.append('email', email);
                    data.append('nonce', '<?php echo esc_js(wp_create_nonce('rts_test_smtp')); ?>');
                    fetch(ajaxurl, { method:'POST', credentials:'same-origin', body:data })
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            if(res && res.success){
                                out.textContent = '✅ Test email sent.';
                            } else {
                                out.textContent = '❌ Failed: ' + ((res && res.data && res.data.message) ? res.data.message : 'Unknown error');
                            }
                        })
                        .catch(function(){ out.textContent = '❌ Network error.'; });
                });
            })();
            </script>
        <?php endif; ?>
        <?php
    }

/**
     * Render Settings Page with Modern Card Design
     */
    public function render_settings() {
        ?>
        <div class="wrap rts-settings-page">
            <div class="rts-page-header">
                <h1><span class="dashicons dashicons-admin-settings"></span>Email Settings</h1>
                <p class="rts-page-description">Configure SMTP settings and email preferences</p>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('rts_smtp_settings'); ?>
                
                <div class="rts-form-section">
                    <h3><span class="dashicons dashicons-email"></span> SMTP Configuration</h3>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">From Email</label>
                        <input type="email" name="rts_smtp_from_email" 
                               value="<?php echo esc_attr(get_option('rts_smtp_from_email')); ?>" 
                               class="rts-form-input" required>
                        <span class="rts-form-description">The email address emails will be sent from</span>
                    </div>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">From Name</label>
                        <input type="text" name="rts_smtp_from_name" 
                               value="<?php echo esc_attr(get_option('rts_smtp_from_name')); ?>" 
                               class="rts-form-input" required>
                        <span class="rts-form-description">The sender name subscribers will see</span>
                    </div>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">Reply-To Email</label>
                        <input type="email" name="rts_smtp_reply_to" 
                               value="<?php echo esc_attr(get_option('rts_smtp_reply_to')); ?>" 
                               class="rts-form-input">
                        <span class="rts-form-description">Where replies will be sent (optional)</span>
                    </div>
                </div>
                
                
                <div class="rts-form-section">
                    <h3><span class="dashicons dashicons-shield"></span> Email Sending (Safety)</h3>

                    <div class="rts-form-row">
                        <label class="rts-form-label">Enable Sending</label>
                        <label style="display:flex;align-items:center;gap:10px;">
                            <input type="checkbox" name="rts_email_sending_enabled" value="1" <?php checked(get_option('rts_email_sending_enabled'), 1); ?>>
                            <span style="color:#ffffff;">Allow the system to queue and send emails</span>
                        </label>
                        <span class="rts-form-description">Default is OFF until you are ready.</span>
                    </div>

                    <div class="rts-form-row">
                        <label class="rts-form-label">Demo Mode</label>
                        <label style="display:flex;align-items:center;gap:10px;">
                            <input type="checkbox" name="rts_email_demo_mode" value="1" <?php checked(get_option('rts_email_demo_mode'), 1); ?>>
                            <span style="color:#F4C946;font-weight:700;">Demo mode ON = nothing gets sent to real subscribers</span>
                        </label>
                        <span class="rts-form-description">Emails are cancelled with a log entry so you can safely test flows.</span>
                    </div>

                    <div class="rts-form-row">
                        <label class="rts-form-label">Require Re-consent</label>
                        <label style="display:flex;align-items:center;gap:10px;">
                            <input type="checkbox" name="rts_email_reconsent_required" value="1" <?php checked(get_option('rts_email_reconsent_required'), 1); ?>>
                            <span style="color:#ffffff;">Only email subscribers after they confirm preferences</span>
                        </label>
                        <span class="rts-form-description">Recommended when importing from another platform (GDPR).</span>
                    </div>
                </div>

                <div class="rts-form-section">
                    <h3><span class="dashicons dashicons-clock"></span> Sending Schedule</h3>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">Daily Digest Time</label>
                        <input type="time" name="rts_email_daily_time" 
                               value="<?php echo esc_attr(get_option('rts_email_daily_time', '09:00')); ?>" 
                               class="rts-form-input">
                        <span class="rts-form-description">What time to send daily digests (24-hour format)</span>
                    </div>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">Batch Size</label>
                        <input type="number" name="rts_email_batch_size" min="10" max="500"
                               value="<?php echo esc_attr(get_option('rts_email_batch_size', 100)); ?>" 
                               class="rts-form-input">
                        <span class="rts-form-description">Number of emails to send per batch (default: 100)</span>
                    </div>
                </div>
                
                <div class="rts-button-group">
                    <button type="submit" class="rts-button success">
                        <span class="dashicons dashicons-yes"></span> Save Settings
                    </button>
                </div>
            </form>
            

            <div class="rts-form-section" style="margin-top:20px;">
                <h3><span class="dashicons dashicons-megaphone"></span> Subscriber Re-consent</h3>
                <p style="color:#ffffff;margin-top:0;">Send a one-time email asking subscribers what they want to receive (letters and/or newsletters). This updates their saved preferences and creates an audit trail.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="rts_send_reconsent">
                    <?php wp_nonce_field('rts_send_reconsent'); ?>
                    <button type="submit" class="rts-button warning">
                        <span class="dashicons dashicons-email-alt"></span> Send Re-consent Email to All Subscribers
                    </button>
                </form>
            </div>

            <div class="rts-info-box">
                <h4><span class="dashicons dashicons-info"></span> SMTP Plugin Required</h4>
                <p>These settings work with any SMTP plugin like WP Mail SMTP, Easy WP SMTP, or Post SMTP. Install and configure an SMTP plugin to enable email sending.</p>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render Email Templates Page - NEW!
     */
    public function render_templates() {
        // Check if editing a template
        if (isset($_GET['edit'])) {
            $this->render_template_editor($_GET['edit']);
            return;
        }
        
        $templates = $this->get_email_templates();
        ?>
        <div class="wrap rts-templates-page">
            <div class="rts-page-header">
                <h1><span class="dashicons dashicons-email-alt"></span>Email Templates</h1>
                <p class="rts-page-description">Customize email content and styling - click any template to edit</p>
            </div>
            
            <div class="rts-info-box">
                <h4><span class="dashicons dashicons-info"></span> How Email Templates Work</h4>
                <p>Emails are sent in this order when subscribers interact with your system:</p>
                <ul style="margin:10px 0 0 20px;">
                    <li><strong>Verification</strong> - Sent immediately after subscription (if verification enabled)</li>
                    <li><strong>Welcome</strong> - Sent after email verification or immediately if verification disabled</li>
                    <li><strong>Daily/Weekly/Monthly Digest</strong> - Sent automatically based on subscriber preference</li>
                    <li><strong>Frequency Changed</strong> - Sent when subscriber changes their email frequency</li>
                    <li><strong>Unsubscribe</strong> - Sent when subscriber unsubscribes</li>
                </ul>
            </div>
            
            <div class="rts-card">
                <h3><span class="dashicons dashicons-email-alt2"></span> Letter Integration</h3>
                <p>Digest emails automatically pull published letters with:</p>
                <ul style="margin:10px 0 0 20px;color:#cbd5e1;">
                    <li>Quality Score ≥ 70 (from moderation system)</li>
                    <li>Status: Published</li>
                    <li>Meta field: <code>_rts_email_ready = true</code></li>
                </ul>
                <p style="margin-top:15px;">Letters are selected randomly from this pool and formatted into the digest template.</p>
            </div>
            
            <div class="rts-template-list">
                <?php foreach ($templates as $key => $template): ?>
                    <div class="rts-template-item">
                        <div class="rts-template-info">
                            <div class="rts-template-name">
                                <span><?php echo esc_html($template['name']); ?></span>
                                <span class="rts-template-badge order-<?php echo $template['order']; ?>">
                                    <?php echo $template['when']; ?>
                                </span>
                            </div>
                            <p class="rts-template-description"><?php echo esc_html($template['description']); ?></p>
                            <div class="rts-template-meta">
                                <span><span class="dashicons dashicons-editor-code"></span> <?php echo $template['variables']; ?> variables</span>
                                <span><span class="dashicons dashicons-admin-users"></span> <?php echo $template['audience']; ?></span>
                            </div>
                        </div>
                        <div class="rts-template-actions">
                            <a href="<?php echo admin_url('admin.php?page=rts-email-templates&edit=' . $key); ?>" 
                               class="rts-button">
                                <span class="dashicons dashicons-edit"></span> Edit Template
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render Template Editor
     */
    private function render_template_editor($template_key) {
        $templates = $this->get_email_templates();
        if (!isset($templates[$template_key])) {
            wp_die('Invalid template');
        }
        
        $template = $templates[$template_key];
        $current_subject = get_option('rts_email_template_' . $template_key . '_subject', $template['default_subject']);
        $current_body = get_option('rts_email_template_' . $template_key . '_body', $template['default_body']);
        
        ?>
        <div class="wrap rts-templates-page">
            <div class="rts-page-header">
                <h1>
                    <a href="<?php echo admin_url('admin.php?page=rts-email-templates'); ?>" 
                       class="dashicons dashicons-arrow-left-alt2" style="text-decoration:none;"></a>
                    Edit: <?php echo esc_html($template['name']); ?>
                </h1>
                <p class="rts-page-description"><?php echo esc_html($template['description']); ?></p>
            </div>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <?php wp_nonce_field('rts_save_template'); ?>
                <input type="hidden" name="action" value="rts_save_template">
                <input type="hidden" name="template_key" value="<?php echo esc_attr($template_key); ?>">
                
                <div class="rts-form-section">
                    <h3><span class="dashicons dashicons-email-alt"></span> Email Content</h3>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">Subject Line</label>
                        <input type="text" name="template_subject" 
                               value="<?php echo esc_attr($current_subject); ?>" 
                               class="rts-form-input" required>
                        <span class="rts-form-description">The email subject line subscribers will see</span>
                    </div>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">Email Body (HTML)</label>
                        <textarea name="template_body" class="rts-code-editor" required><?php 
                            echo esc_textarea($current_body); 
                        ?></textarea>
                        <span class="rts-form-description">Use HTML for formatting. Click variables below to copy.</span>
                    </div>
                </div>
                
                <div class="rts-card">
                    <h3><span class="dashicons dashicons-editor-code"></span> Available Variables</h3>
                    <p style="margin-bottom:20px;">Click any variable to copy it to your clipboard:</p>
                    <div class="rts-variables-list">
                        <?php foreach ($template['available_variables'] as $var): ?>
                            <code class="rts-variable-tag" onclick="navigator.clipboard.writeText('<?php echo esc_js($var); ?>');alert('Copied!');">
                                <?php echo esc_html($var); ?>
                            </code>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <div class="rts-info-box">
                    <h4><span class="dashicons dashicons-lightbulb"></span> Template Tips</h4>
                    <ul style="margin:10px 0 0 20px;">
                        <li>Use <code>{subscriber_email}</code> for personalization</li>
                        <li>Always include <code>{unsubscribe_url}</code> for compliance</li>
                        <li>Use standard HTML tags: &lt;h2&gt;, &lt;p&gt;, &lt;strong&gt;, &lt;a&gt;</li>
                        <li>Keep designs simple - many email clients strip complex CSS</li>
                    </ul>
                </div>
                
                <div class="rts-button-group">
                    <button type="submit" class="rts-button success">
                        <span class="dashicons dashicons-yes"></span> Save Template
                    </button>
                    <a href="<?php echo admin_url('admin.php?page=rts-email-templates'); ?>" 
                       class="rts-button secondary">
                        <span class="dashicons dashicons-no-alt"></span> Cancel
                    </a>
                    <button type="button" class="rts-button" onclick="if(confirm('Send test email to <?php echo esc_js(get_option('admin_email')); ?>?')) { document.getElementById('test-form').submit(); }">
                        <span class="dashicons dashicons-email"></span> Send Test Email
                    </button>
                </div>
            </form>
            
            <form id="test-form" method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:none;">
                <?php wp_nonce_field('rts_test_template'); ?>
                <input type="hidden" name="action" value="rts_test_template">
                <input type="hidden" name="template_key" value="<?php echo esc_attr($template_key); ?>">
            </form>
        </div>
        <?php
    }
    
    /**
     * Get all email templates with metadata
     */
    private function get_email_templates() {
        return array(
            'verification' => array(
                'name' => 'Email Verification',
                'description' => 'Sent immediately after subscription to verify email address',
                'order' => 1,
                'when' => 'First',
                'audience' => 'New subscribers',
                'variables' => 5,
                'default_subject' => 'Please verify your email - Reasons to Stay',
                'default_body' => '<h2>Verify Your Email</h2><p>Thanks for subscribing to Reasons to Stay! Please verify your email address to start receiving letters of hope.</p><p><a href="{verify_url}" style="background:#3b82f6;color:#fff;padding:12px 24px;text-decoration:none;border-radius:8px;display:inline-block;">Verify Email Address</a></p><p style="font-size:12px;color:#666;">Or copy this link: {verify_url}</p>',
                'available_variables' => array('{subscriber_email}', '{verify_url}', '{site_name}', '{site_url}', '{unsubscribe_url}')
            ),
            'welcome' => array(
                'name' => 'Welcome Email',
                'description' => 'Sent after email verification or immediately if verification is disabled',
                'order' => 2,
                'when' => 'Second',
                'audience' => 'Verified subscribers',
                'variables' => 6,
                'default_subject' => 'Welcome to Reasons to Stay',
                'default_body' => '<h2>Welcome to Reasons to Stay!</h2><p>Thank you for subscribing. You\'ll receive {frequency} emails with letters of hope and encouragement.</p><p>Our letters come from real people sharing their reasons to stay - authentic stories that might help during difficult times.</p><p><a href="{site_url}">Visit our website</a> | <a href="{change_frequency_url}">Change frequency</a></p><p style="font-size:11px;color:#666;"><a href="{unsubscribe_url}">Unsubscribe</a></p>',
                'available_variables' => array('{subscriber_email}', '{frequency}', '{site_name}', '{site_url}', '{change_frequency_url}', '{unsubscribe_url}')
            ),
            'daily_digest' => array(
                'name' => 'Daily Digest',
                'description' => 'Contains 1 random letter, sent daily at 9am to daily subscribers',
                'order' => 3,
                'when' => 'Daily 9am',
                'audience' => 'Daily subscribers',
                'variables' => 8,
                'default_subject' => 'Your daily letter - {current_date}',
                'default_body' => '<h2>Today\'s Letter</h2>{letter_content}<hr><p style="font-size:12px;color:#666;"><a href="{site_url}">Read more letters</a> | <a href="{change_frequency_url}">Change frequency</a> | <a href="{unsubscribe_url}">Unsubscribe</a></p>',
                'available_variables' => array('{letter_title}', '{letter_content}', '{letter_url}', '{subscriber_email}', '{site_name}', '{site_url}', '{change_frequency_url}', '{unsubscribe_url}')
            ),
            'weekly_digest' => array(
                'name' => 'Weekly Digest',
                'description' => 'Contains 5 random letters, sent Mondays at 9am to weekly subscribers',
                'order' => 3,
                'when' => 'Mon 9am',
                'audience' => 'Weekly subscribers',
                'variables' => 7,
                'default_subject' => 'Your weekly letters - {current_date}',
                'default_body' => '<h2>This Week\'s Letters</h2>{letters_list}<hr><p style="font-size:12px;color:#666;"><a href="{site_url}">Read more</a> | <a href="{change_frequency_url}">Change frequency</a> | <a href="{unsubscribe_url}">Unsubscribe</a></p>',
                'available_variables' => array('{letters_list}', '{subscriber_email}', '{site_name}', '{site_url}', '{change_frequency_url}', '{unsubscribe_url}', '{current_date}')
            ),
            'monthly_digest' => array(
                'name' => 'Monthly Digest',
                'description' => 'Contains 5 curated letters, sent 1st of month at 9am to monthly subscribers',
                'order' => 3,
                'when' => '1st 9am',
                'audience' => 'Monthly subscribers',
                'variables' => 7,
                'default_subject' => 'Your monthly letters - {current_date}',
                'default_body' => '<h2>This Month\'s Letters</h2>{letters_list}<hr><p style="font-size:12px;color:#666;"><a href="{site_url}">Read more</a> | <a href="{change_frequency_url}">Change frequency</a> | <a href="{unsubscribe_url}">Unsubscribe</a></p>',
                'available_variables' => array('{letters_list}', '{subscriber_email}', '{site_name}', '{site_url}', '{change_frequency_url}', '{unsubscribe_url}', '{current_date}')
            ),
            'frequency_changed' => array(
                'name' => 'Frequency Changed',
                'description' => 'Confirmation sent when subscriber changes their email frequency',
                'order' => 2,
                'when' => 'On change',
                'audience' => 'Active subscribers',
                'variables' => 6,
                'default_subject' => 'Email frequency updated - Reasons to Stay',
                'default_body' => '<h2>Frequency Updated</h2><p>Your email frequency has been changed to <strong>{frequency}</strong>. You\'ll now receive letters {frequency}.</p><p>You can change this anytime by clicking the link in any email.</p><p style="font-size:11px;color:#666;"><a href="{unsubscribe_url}">Unsubscribe</a></p>',
                'available_variables' => array('{subscriber_email}', '{frequency}', '{site_name}', '{site_url}', '{change_frequency_url}', '{unsubscribe_url}')
            ),
            'unsubscribe' => array(
                'name' => 'Unsubscribe Confirmation',
                'description' => 'Sent when subscriber unsubscribes (goodbye message)',
                'order' => 3,
                'when' => 'On unsub',
                'audience' => 'Unsubscribed users',
                'variables' => 4,
                'default_subject' => 'You\'ve been unsubscribed',
                'default_body' => '<h2>Goodbye for now</h2><p>You\'ve been successfully unsubscribed from Reasons to Stay emails.</p><p>If you change your mind, you can always re-subscribe at <a href="{site_url}">{site_name}</a>.</p><p>Take care of yourself.</p>',
                'available_variables' => array('{subscriber_email}', '{site_name}', '{site_url}', '{current_date}')
            ),
        );
    }
    
    /**
     * Handle template save
     */
    public function handle_save_template() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('rts_save_template');
        
        $template_key = sanitize_text_field($_POST['template_key']);
        $subject = sanitize_text_field($_POST['template_subject']);
        $body = wp_kses_post($_POST['template_body']);
        
        update_option('rts_email_template_' . $template_key . '_subject', $subject);
        update_option('rts_email_template_' . $template_key . '_body', $body);
        
        wp_redirect(add_query_arg(array(
            'page' => 'rts-email-templates',
            'saved' => '1'
        ), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Handle test email
     */
    public function handle_test_template() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('rts_test_template');
        
        $template_key = sanitize_text_field($_POST['template_key']);
        $to = get_option('admin_email');
        
        // Get current template
        $subject = get_option('rts_email_template_' . $template_key . '_subject', 'Test Email');
        $body = get_option('rts_email_template_' . $template_key . '_body', '<p>Test</p>');
        
        // Replace variables with test data
        $body = str_replace('{subscriber_email}', $to, $body);
        $body = str_replace('{site_name}', get_bloginfo('name'), $body);
        $body = str_replace('{site_url}', home_url(), $body);
        $body = str_replace('{verify_url}', home_url('?test=verify'), $body);
        $body = str_replace('{unsubscribe_url}', home_url('?test=unsubscribe'), $body);
        
        wp_mail($to, '[TEST] ' . $subject, $body, array('Content-Type: text/html'));
        
        wp_redirect(add_query_arg(array(
            'page' => 'rts-email-templates',
            'edit' => $template_key,
            'test_sent' => '1'
        ), admin_url('admin.php')));
        exit;
    }
    
    /**
     * Render Analytics Page with Modern Cards
     */
    public function render_analytics() {
        $analytics = new RTS_Analytics();
        $stats = $analytics->get_subscriber_stats();
        ?>
        <div class="wrap rts-analytics-page">
            <div class="rts-page-header">
                <h1><span class="dashicons dashicons-chart-area"></span>Subscriber Analytics</h1>
                <p class="rts-page-description">Overview of your subscriber base and email performance</p>
            </div>
            
            <div class="rts-metrics-grid">
                <div class="rts-metric-card">
                    <span class="rts-metric-label">Total Subscribers</span>
                    <span class="rts-metric-value"><?php echo number_format($stats['total']); ?></span>
                    <span class="rts-metric-subtitle">All time</span>
                </div>
                
                <div class="rts-metric-card">
                    <span class="rts-metric-label">Active Subscribers</span>
                    <span class="rts-metric-value"><?php echo number_format($stats['active']); ?></span>
                    <span class="rts-metric-subtitle">Currently receiving emails</span>
                </div>
                
                <div class="rts-metric-card">
                    <span class="rts-metric-label">Engagement Rate</span>
                    <span class="rts-metric-value"><?php 
                        echo $stats['total'] > 0 ? number_format(($stats['active'] / $stats['total']) * 100, 1) : '0'; 
                    ?>%</span>
                    <span class="rts-metric-subtitle">Active / Total</span>
                </div>
            </div>
            
            <div class="rts-card">
                <h3><span class="dashicons dashicons-admin-tools"></span> Quick Actions</h3>
                <div class="rts-button-group">
                    <a href="<?php echo admin_url('edit.php?post_type=rts_subscriber'); ?>" class="rts-button">
                        <span class="dashicons dashicons-admin-users"></span> View All Subscribers
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=rts-import'); ?>" class="rts-button secondary">
                        <span class="dashicons dashicons-upload"></span> Import More
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=rts-email-templates'); ?>" class="rts-button secondary">
                        <span class="dashicons dashicons-email-alt"></span> Edit Templates
                    </a>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * Render Import Page with Modern Cards
     */
    public function render_import() {
        ?>
        <div class="wrap rts-import-page">
            <div class="rts-page-header">
                <h1><span class="dashicons dashicons-upload"></span>Import Subscribers</h1>
                <p class="rts-page-description">Upload a CSV file to import subscribers in bulk</p>
            </div>
            
            <?php
            $rts_session_id = isset($_GET['session']) ? sanitize_text_field($_GET['session']) : '';
            if ($rts_session_id) :
                $rts_nonce = wp_create_nonce('rts_import_progress');
            ?>
                <div class="rts-card" id="rts-import-progress" style="margin-bottom:20px;">
                    <h3><span class="dashicons dashicons-update"></span> Import Progress</h3>
                    <p id="rts-import-status" style="margin:8px 0 0 0;color:#cbd5e1;">Loading progress...</p>
                    <div style="margin-top:15px;">
                        <div style="height:10px;background:#0f172a;border:1px solid #334155;border-radius:999px;overflow:hidden;">
                            <div id="rts-import-bar" style="height:10px;width:0%;background:#22c55e;"></div>
                        </div>
                        <p id="rts-import-counts" style="margin:10px 0 0 0;color:#cbd5e1;"></p>
                    </div>
                </div>
                <script>
                (function(){
                    var sessionId = <?php echo json_encode($rts_session_id); ?>;
                    var nonce = <?php echo json_encode($rts_nonce); ?>;
                    var bar = document.getElementById('rts-import-bar');
                    var statusEl = document.getElementById('rts-import-status');
                    var countsEl = document.getElementById('rts-import-counts');

                    function pct(part, total){
                        if(!total || total <= 0) return 0;
                        return Math.min(100, Math.max(0, Math.round((part/total)*100)));
                    }

                    function tick(){
                        var data = new FormData();
                        data.append('action','rts_import_progress');
                        data.append('nonce', nonce);
                        data.append('session_id', sessionId);

                        fetch(ajaxurl, {method:'POST', body:data, credentials:'same-origin'})
                          .then(function(r){ return r.json(); })
                          .then(function(resp){
                              if(!resp || !resp.success){
                                  statusEl.textContent = 'Progress unavailable.';
                                  return;
                              }
                              var s = resp.data;
                              var total = parseInt(s.total_rows || 0, 10);
                              var processed = parseInt(s.processed || 0, 10);
                              var imported = parseInt(s.imported || 0, 10);
                              var dup = parseInt(s.skipped_duplicate || 0, 10);
                              var inv = parseInt(s.skipped_invalid || 0, 10);
                              var oth = parseInt(s.skipped_other || 0, 10);
                              var st = (s.status || 'processing');

                              var p = pct(processed, total);
                              bar.style.width = p + '%';

                              statusEl.textContent = (st === 'complete')
                                  ? ('Complete. Imported ' + imported + ' subscribers.')
                                  : ('Processing... ' + p + '%');

                              countsEl.textContent =
                                  'Processed: ' + processed + ' / ' + total +
                                  ' | Imported: ' + imported +
                                  ' | Duplicates: ' + dup +
                                  ' | Invalid: ' + inv +
                                  ' | Other skipped: ' + oth;

                              if(st !== 'complete' && st !== 'error'){
                                  setTimeout(tick, 2000);
                              }
                          })
                          .catch(function(){
                              statusEl.textContent = 'Progress check failed. Refresh to retry.';
                          });
                    }
                    tick();
                })();
                </script>
            <?php endif; ?>

<?php if (isset($_GET['imported'])): ?>
                <div class="rts-info-box success">
                    <h4><span class="dashicons dashicons-yes"></span> Import Successful</h4>
                    <p>Successfully imported <?php echo intval($_GET['imported']); ?> subscribers!</p>
                </div>
            <?php endif; ?>
            
            <div class="rts-card">
                <h3><span class="dashicons dashicons-media-spreadsheet"></span> CSV Format Requirements</h3>
                <p>Your CSV file must have at minimum an <code>email</code> column. Optional columns:</p>
                <ul style="margin:10px 0 0 20px;color:#cbd5e1;">
                    <li><code>email</code> - Required, subscriber email address</li>
                    <li><code>frequency</code> - Optional: daily, weekly, or monthly (default: weekly)</li>
                    <li><code>source</code> - Optional: where subscriber came from (default: import)</li>
                </ul>
            </div>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field('rts_import_csv'); ?>
                <input type="hidden" name="action" value="rts_import_csv">
                
                <div class="rts-form-section">
                    <h3><span class="dashicons dashicons-upload"></span> Upload CSV File</h3>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">Select CSV File</label>
                        <input type="file" name="csv_file" accept=".csv" required 
                               style="display:block;padding:10px;background:#0f172a;border:2px solid #334155;border-radius:10px;color:#f1f5f9;">
                        <span class="rts-form-description">Maximum file size: 5MB</span>
                    </div>
                    
                    <div class="rts-form-row">
                        <label class="rts-form-label">Default Frequency (if not in CSV)</label>
                        <select name="default_frequency" class="rts-form-select">
                            <option value="weekly">Weekly (recommended)</option>
                            <option value="daily">Daily</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                </div>
                
                <div class="rts-button-group">
                    <button type="submit" class="rts-button success">
                        <span class="dashicons dashicons-upload"></span> Import Subscribers
                    </button>
                </div>
            </form>
            
            <div class="rts-info-box">
                <h4><span class="dashicons dashicons-info"></span> Import Notes</h4>
                <ul style="margin:10px 0 0 20px;">
                    <li>Duplicate emails will be skipped automatically</li>
                    <li>Invalid emails will be logged and skipped</li>
                    <li>Import runs in batches of 1000 for performance</li>
                    <li>All imported subscribers will be marked as "verified" by default</li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Redirect the default post editor for rts_subscriber to our custom form.
     */
    public function redirect_subscriber_post_new() {
        if (!is_admin()) {
            return;
        }
        global $pagenow;
        if ($pagenow === 'post-new.php' && isset($_GET['post_type']) && $_GET['post_type'] === 'rts_subscriber') {
            wp_safe_redirect(admin_url('edit.php?post_type=rts_subscriber'));
            exit;
        }
    }

    public function render_add_subscriber() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }

        $notice = '';
        if (isset($_GET['rts_added']) && $_GET['rts_added'] === '1') {
            $notice = '<div class="notice notice-success is-dismissible"><p>Subscriber added.</p></div>';
        }

        ?>
        <div class="wrap rts-admin-wrap">
            <h1 style="margin-bottom:16px;">Add Subscriber</h1>
            <?php echo $notice; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="rts-form">
                <input type="hidden" name="action" value="rts_add_subscriber" />
                <?php wp_nonce_field('rts_add_subscriber', '_wpnonce'); ?>

                <div class="rts-form-section">
                    <h3><span class="dashicons dashicons-email"></span> Email Address</h3>
                    <div class="rts-form-row">
                        <label class="rts-form-label" for="rts_email">Email</label>
                        <input id="rts_email" type="email" name="email" required placeholder="name@example.com"
                               style="display:block;width:100%;max-width:520px;padding:12px 14px;background:#0f172a;border:2px solid #334155;border-radius:14px;color:#f1f5f9;" />
                        <span class="rts-form-description">This creates (or updates) a subscriber record.</span>
                    </div>
                </div>

                <div class="rts-form-section">
                    <h3><span class="dashicons dashicons-list-view"></span> Subscriptions</h3>
                    <div class="rts-form-row">
                        <label style="display:block;margin-bottom:10px;">
                            <input type="checkbox" name="pref_letters" value="1" /> Receive letter emails
                        </label>
                        <label style="display:block;margin-bottom:10px;">
                            <input type="checkbox" name="pref_newsletters" value="1" /> Receive newsletters
                        </label>
                        <div style="opacity:0.9; font-size:13px;">If <strong>Re-consent Required</strong> is enabled, subscribers will not receive anything until they confirm in the preference centre.</div>
                    </div>
                </div>

                <div class="rts-form-section">
                    <h3><span class="dashicons dashicons-clock"></span> Frequency</h3>
                    <div class="rts-form-row">
                        <label class="rts-form-label" for="rts_frequency">Digest Frequency</label>
                        <select id="rts_frequency" name="frequency" class="rts-form-select" style="max-width:240px;">
                            <option value="weekly">Weekly</option>
                            <option value="daily">Daily</option>
                            <option value="monthly">Monthly</option>
                        </select>
                    </div>
                </div>

                <div class="rts-button-group">
                    <button type="submit" class="rts-button success">
                        <span class="dashicons dashicons-yes"></span> Save Subscriber
                    </button>
                </div>
            </form>
        </div>
        <?php
    }

    public function handle_add_subscriber() {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permissions.');
        }
        check_admin_referer('rts_add_subscriber', '_wpnonce');

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (!$email || !is_email($email)) {
            wp_safe_redirect(add_query_arg(array('post_type' => 'rts_subscriber', 'rts_added' => '0'), admin_url('edit.php')));
            exit;
        }

        // Find existing subscriber by email (title) first.
        $existing = get_page_by_title($email, OBJECT, 'rts_subscriber');
        $subscriber_id = $existing ? (int) $existing->ID : 0;

        if (!$subscriber_id) {
            $subscriber_id = wp_insert_post(array(
                'post_type' => 'rts_subscriber',
                'post_status' => 'publish',
                'post_title' => $email,
            ));
        }

        if (!is_wp_error($subscriber_id) && $subscriber_id) {
            update_post_meta($subscriber_id, '_rts_subscriber_status', 'active');
            update_post_meta($subscriber_id, '_rts_subscriber_frequency', sanitize_key($_POST['frequency'] ?? 'weekly'));
            update_post_meta($subscriber_id, '_rts_pref_letters', isset($_POST['pref_letters']) ? 1 : 0);
            update_post_meta($subscriber_id, '_rts_pref_newsletters', isset($_POST['pref_newsletters']) ? 1 : 0);
            // Do NOT auto-mark consent confirmed here. Consent should come via the preference centre.
        }

        wp_safe_redirect(admin_url('edit.php?post_type=rts_subscriber&rts_added=1'));
        exit;
    }


    /**
     * Command Center dashboard.
     * Provides status + recent delivery ledger + quick actions.
     */
    public function render_command_center() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to access this page.', 'rts'));
        }

        // Simulate Send preview: output rendered HTML only (no wp-admin chrome).
        if (isset($_GET['rts_simulate']) && $_GET['rts_simulate'] === '1') {
            $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
            if (!$nonce || !wp_verify_nonce($nonce, 'rts_simulate_send')) {
                wp_die(__('Security check failed.', 'rts'));
            }

            $subscriber_id = isset($_GET['subscriber_id']) ? absint($_GET['subscriber_id']) : 0;
            $letter_id     = isset($_GET['letter_id']) ? absint($_GET['letter_id']) : 0;

            $letter = $letter_id ? get_post($letter_id) : null;
            if (!$letter || $letter->post_type !== 'letter' || $letter->post_status !== 'publish') {
                wp_die(__('Invalid letter selected.', 'rts'));
            }

            if (!class_exists('RTS_Email_Renderer')) {
                $maybe = dirname(__FILE__) . '/../includes/class-email-renderer.php';
                if (file_exists($maybe)) {
                    require_once $maybe;
                }
            }

            if (!class_exists('RTS_Email_Renderer')) {
                wp_die(__('Email renderer missing.', 'rts'));
            }

            $renderer = new RTS_Email_Renderer();
            $html = $renderer->render($letter, array(
                'subscriber_id' => $subscriber_id,
            ));

            // Render raw HTML for visual accuracy.
            nocache_headers();
            header('Content-Type: text/html; charset=utf-8');
            echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            exit;
        }

        global $wpdb;
        $logs_table  = $wpdb->prefix . 'rts_email_logs';
        $queue_table = $wpdb->prefix . 'rts_email_queue';

        // SMTP health dot
        $smtp_ok = false;
        $smtp_msg = '';
        if (class_exists('RTS_SMTP_Settings')) {
            $smtp = new RTS_SMTP_Settings();
            $res = $smtp->test_smtp_connection();
            $smtp_ok = !empty($res['ok']);
            $smtp_msg = !empty($res['message']) ? (string) $res['message'] : '';
        }

        // Queue health
        $stuck_count = 0;
        $backlog_count = 0;
        $one_hour_ago = gmdate('Y-m-d H:i:s', time() - 3600);
        $now_gmt = gmdate('Y-m-d H:i:s');

        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $queue_table)) === $queue_table) {
            $stuck_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$queue_table} WHERE status = %s AND scheduled_at < %s",
                    'pending',
                    $one_hour_ago
                )
            );
            $backlog_count = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$queue_table} WHERE status = %s AND scheduled_at <= %s",
                    'pending',
                    $now_gmt
                )
            );
        }

        $next_run_ts = wp_next_scheduled('rts_process_email_queue');
        $next_run = $next_run_ts ? date_i18n('j M Y, H:i', $next_run_ts) : __('Not scheduled', 'rts');

        // Recent logs (latest 20)
        $rows = array();
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $logs_table)) === $logs_table) {
            $rows = $wpdb->get_results("SELECT id, subscriber_id, email, template, letter_id, status, sent_at, created_at FROM {$logs_table} ORDER BY id DESC LIMIT 20", ARRAY_A);
        }

        // Simulate Send dropdown data
        $subscribers = get_posts(array(
            'post_type'      => 'rts_subscriber',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ));

        $letters = get_posts(array(
            'post_type'      => 'letter',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ));

        $pause_all = (int) get_option('rts_pause_all_sending', 0);
        ?>
        <div class="wrap rts-settings-page">
            <div class="rts-page-header">
                <h1><span class="dashicons dashicons-dashboard"></span>Command Center</h1>
                <p class="rts-page-description">A single screen to confirm delivery health and recent sends</p>
            </div>

            <div class="rts-form-section">
                <h3><span class="dashicons dashicons-info"></span>Status Bar</h3>

                <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:center;">
                    <div class="rts-card" style="padding:18px 22px !important;margin:0 !important;min-width:220px;">
                        <div style="font-weight:800;margin-bottom:6px;">SMTP</div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span style="width:12px;height:12px;border-radius:99px;display:inline-block;background:<?php echo $smtp_ok ? '#22c55e' : '#ef4444'; ?>;"></span>
                            <span style="color:#ffffff;"><?php echo $smtp_ok ? 'Healthy' : 'Failing'; ?></span>
                        </div>
                        <?php if ($smtp_msg) : ?>
                            <div style="opacity:.85;font-size:12px;margin-top:6px;color:#ffffff;"><?php echo esc_html($smtp_msg); ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="rts-card" style="padding:18px 22px !important;margin:0 !important;min-width:220px;">
                        <div style="font-weight:800;margin-bottom:6px;">Queue</div>
                        <div style="color:#ffffff;">
                            <?php if ($stuck_count > 0) : ?>
                                <strong style="color:#F4C946;">Backlog</strong> <?php echo esc_html($backlog_count); ?> due, <?php echo esc_html($stuck_count); ?> stuck &gt; 1hr
                            <?php else : ?>
                                <strong style="color:#22c55e;">Healthy</strong> <?php echo esc_html($backlog_count); ?> due
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="rts-card" style="padding:18px 22px !important;margin:0 !important;min-width:220px;">
                        <div style="font-weight:800;margin-bottom:6px;">Cron</div>
                        <div style="color:#ffffff;">Next Run: <strong><?php echo esc_html($next_run); ?></strong></div>
                    </div>
                </div>
            </div>

            <div class="rts-form-section">
                <h3><span class="dashicons dashicons-visibility"></span>Transparency Ledger</h3>

                <div class="rts-card" style="padding:0 !important;overflow:auto;">
                    <table class="widefat fixed striped" style="margin:0;background:transparent;border:none;">
                        <thead>
                            <tr>
                                <th style="color:#ffffff;">Time</th>
                                <th style="color:#ffffff;">Recipient</th>
                                <th style="color:#ffffff;">Type</th>
                                <th style="color:#ffffff;">Letter Sent</th>
                                <th style="color:#ffffff;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($rows)) : ?>
                                <tr><td colspan="5" style="color:#ffffff;">No logs found.</td></tr>
                            <?php else : ?>
                                <?php foreach ($rows as $row) : ?>
                                    <?php
                                        $when = !empty($row['sent_at']) ? $row['sent_at'] : $row['created_at'];
                                        $recipient = !empty($row['email']) ? $this->obfuscate_email($row['email']) : '';
                                        $type = !empty($row['template']) ? $row['template'] : '';
                                        $status = !empty($row['status']) ? $row['status'] : '';
                                        $letter_link = '';
                                        if (!empty($row['letter_id'])) {
                                            $letter_link = get_edit_post_link((int) $row['letter_id']);
                                        }
                                    ?>
                                    <tr>
                                        <td style="color:#ffffff;"><?php echo esc_html($when); ?></td>
                                        <td style="color:#ffffff;"><?php echo esc_html($recipient); ?></td>
                                        <td style="color:#ffffff;"><?php echo esc_html($type); ?></td>
                                        <td style="color:#ffffff;">
                                            <?php if ($letter_link) : ?>
                                                <a href="<?php echo esc_url($letter_link); ?>" style="color:#DF157C;font-weight:800;">#<?php echo (int) $row['letter_id']; ?></a>
                                            <?php else : ?>
                                                <span style="opacity:.8;">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="color:#ffffff;"><?php echo esc_html($status); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="rts-form-section">
                <h3><span class="dashicons dashicons-hammer"></span>Quick Actions</h3>

                <div style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-start;">
                    <div class="rts-card" style="min-width:320px;">
                        <div style="font-weight:900;margin-bottom:10px;color:#ffffff;">Pause All Sending</div>
                        <p style="margin-top:0;color:#ffffff;opacity:.9;">Stops the sending engine from processing the queue. Useful for maintenance windows.</p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="rts_toggle_pause_sending">
                            <?php wp_nonce_field('rts_toggle_pause_sending'); ?>
                            <button type="submit" class="rts-button <?php echo $pause_all ? 'warning' : 'success'; ?>">
                                <span class="dashicons dashicons-controls-pause"></span>
                                <?php echo $pause_all ? 'Resume Sending' : 'Pause Sending'; ?>
                            </button>
                        </form>
                    </div>

                    <div class="rts-card" style="min-width:420px;">
                        <div style="font-weight:900;margin-bottom:10px;color:#ffffff;">Simulate Send</div>
                        <p style="margin-top:0;color:#ffffff;opacity:.9;">Preview the exact HTML output before it leaves the server.</p>

                        <form method="get" action="<?php echo esc_url(admin_url('edit.php')); ?>" target="_blank">
                            <input type="hidden" name="post_type" value="rts_subscriber">
                            <input type="hidden" name="page" value="rts-command-center">
                            <input type="hidden" name="rts_simulate" value="1">
                            <?php wp_nonce_field('rts_simulate_send'); ?>

                            <div style="display:grid;gap:10px;">
                                <label style="color:#ffffff;font-weight:800;">Subscriber</label>
                                <select name="subscriber_id" class="rts-form-input">
                                    <option value="0">Select subscriber</option>
                                    <?php foreach ($subscribers as $sid) : ?>
                                        <?php $email = get_the_title($sid); ?>
                                        <option value="<?php echo (int) $sid; ?>"><?php echo esc_html($this->obfuscate_email($email)); ?> (ID <?php echo (int) $sid; ?>)</option>
                                    <?php endforeach; ?>
                                </select>

                                <label style="color:#ffffff;font-weight:800;">Letter</label>
                                <select name="letter_id" class="rts-form-input">
                                    <option value="0">Select letter</option>
                                    <?php foreach ($letters as $lid) : ?>
                                        <option value="<?php echo (int) $lid; ?>">#<?php echo (int) $lid; ?> - <?php echo esc_html(wp_trim_words(get_the_title($lid), 10)); ?></option>
                                    <?php endforeach; ?>
                                </select>

                                <button type="submit" class="rts-button primary">
                                    <span class="dashicons dashicons-visibility"></span> Preview Rendered HTML
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Obfuscate an email for UI display (never show raw email in logs/dashboard).
     * Example: j***@gmail.com
     */
    private function obfuscate_email($email) {
        $email = (string) $email;
        if (!$email || strpos($email, '@') === false) {
            return '';
        }

        list($local, $domain) = explode('@', $email, 2);
        $local = trim($local);
        $domain = trim($domain);

        if ($local === '') {
            return '***@' . $domain;
        }

        $first = substr($local, 0, 1);
        return $first . '***@' . $domain;
    }
}
