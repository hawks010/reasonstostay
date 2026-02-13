<?php
/**
 * RTS Home Dashboard Widgets
 *
 * Shows high-value RTS snapshots on wp-admin Dashboard (index.php):
 * - Orange Letter Command Center
 * - Secondary Analytics + Shares snapshot widget
 */

if (!defined('ABSPATH')) { exit; }

final class RTS_Home_Dashboard_Widgets {

    public static function init(): void {
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_notices', [__CLASS__, 'render_home_widgets'], 2);
    }

    public static function enqueue_assets(string $hook): void {
        if ($hook !== 'index.php') return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'dashboard') return;

        $css_rel = '/assets/css/rts-admin-complete.css';
        $css_path = get_stylesheet_directory() . $css_rel;
        if (!file_exists($css_path)) return;

        wp_enqueue_style(
            'rts-admin-complete-home',
            get_stylesheet_directory_uri() . $css_rel,
            [],
            (string) filemtime($css_path)
        );
    }

    public static function render_home_widgets(): void {
        if (!is_admin() || !current_user_can('edit_others_posts')) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'dashboard') return;

        echo '<div class="rts-home-dashboard-widgets">';
        if (class_exists('RTS_Workflow_Admin') && method_exists('RTS_Workflow_Admin', 'render_letter_command_center')) {
            RTS_Workflow_Admin::render_letter_command_center(['move_under_title' => false]);
        }
        self::render_secondary_widget();
        echo '</div>';
    }

    private static function render_secondary_widget(): void {
        $snapshot = self::get_snapshot();
        $analytics_url = admin_url('edit.php?post_type=letter&page=rts-dashboard&tab=analytics');
        $shares_url = admin_url('edit.php?post_type=letter&page=rts-dashboard&tab=shares');
        $letters_url = admin_url('edit.php?post_type=letter&page=rts-dashboard');
        $audience_url = admin_url('edit.php?post_type=rts_subscriber&page=rts-subscribers-dashboard');
        $mailing_url = admin_url('edit.php?post_type=rts_subscriber&page=rts-subscriber-mailing');
        $import_url = admin_url('edit.php?post_type=rts_subscriber&page=rts-subscriber-import-export');
        $templates_url = admin_url('edit.php?post_type=rts_subscriber&page=rts-email-templates');

        ?>
        <section class="rts-home-secondary-widget rts-home-widget-card">
            <div class="rts-home-secondary-head">
                <div>
                    <h2 class="rts-section-title"><span class="dashicons dashicons-chart-area"></span> Client Snapshot</h2>
                    <p>Analytics + share engagement summary from your RTS dashboard tabs.</p>
                </div>
                <div class="rts-home-secondary-actions">
                    <a class="button" href="<?php echo esc_url($analytics_url); ?>">Open Analytics</a>
                    <a class="button" href="<?php echo esc_url($shares_url); ?>">Open Shares</a>
                </div>
            </div>

            <div class="rts-home-secondary-quicklinks">
                <a class="button" href="<?php echo esc_url($letters_url); ?>">Letters Dashboard</a>
                <a class="button" href="<?php echo esc_url($audience_url); ?>">Audience &amp; Email</a>
                <a class="button" href="<?php echo esc_url($mailing_url); ?>">Letter Mailing</a>
                <a class="button" href="<?php echo esc_url($import_url); ?>">Import / Export</a>
                <a class="button" href="<?php echo esc_url($templates_url); ?>">Email Templates</a>
            </div>

            <div class="rts-home-secondary-mail-health">
                <div class="rts-home-secondary-health-item">
                    <span>Mail Mode</span>
                    <strong><?php echo esc_html((string) $snapshot['mail_mode']); ?></strong>
                </div>
                <div class="rts-home-secondary-health-item">
                    <span>Send Engine</span>
                    <strong><?php echo esc_html((string) $snapshot['send_engine']); ?></strong>
                </div>
                <div class="rts-home-secondary-health-item">
                    <span>Queue Due Now</span>
                    <strong><?php echo esc_html(number_format_i18n((int) $snapshot['queue_due_now'])); ?></strong>
                </div>
                <div class="rts-home-secondary-health-item">
                    <span>Dead Letters</span>
                    <strong><?php echo esc_html(number_format_i18n((int) $snapshot['dead_letters'])); ?></strong>
                </div>
            </div>

            <div class="rts-home-secondary-grid">
                <div class="rts-home-col">
                    <h3 class="rts-section-title">Analytics</h3>
                    <div class="rts-analytics-grid-3">
                        <?php self::metric_card('Last 24 Hours', (int) $snapshot['velocity_24h'], 'New letters submitted'); ?>
                        <?php self::metric_card('Last 7 Days', (int) $snapshot['velocity_7d'], 'New letters submitted'); ?>
                        <?php self::metric_card('Last 30 Days', (int) $snapshot['velocity_30d'], 'New letters submitted'); ?>
                        <?php self::metric_card('Acceptance Rate', (float) $snapshot['acceptance_rate'] . '%', number_format_i18n((int) $snapshot['published']) . ' of ' . number_format_i18n((int) $snapshot['submissions']) . ' published'); ?>
                        <?php self::metric_card('Avg Quality Score', (float) $snapshot['avg_quality'], 'Out of 100'); ?>
                        <?php self::metric_card('Quarantined', (int) $snapshot['pending'], 'Held for safety or quality checks'); ?>
                    </div>

                    <div class="rts-analytics-grid-2">
                        <?php self::kv_card('Top Feelings', (array) $snapshot['top_feelings']); ?>
                        <?php self::kv_card('Top Tones', (array) $snapshot['top_tones']); ?>
                    </div>
                </div>

                <div class="rts-home-col">
                    <h3 class="rts-section-title">Shares</h3>
                    <div class="rts-share-total-stat">
                        <div class="rts-stat-label">Total Shares (All Platforms)</div>
                        <div class="rts-stat-value"><?php echo esc_html(number_format_i18n((int) $snapshot['shares_total_display'])); ?></div>
                        <?php if ((int) $snapshot['shares_offset'] > 0): ?>
                            <div class="rts-stat-sub">
                                Live: <?php echo esc_html(number_format_i18n((int) $snapshot['shares_total'])); ?> + Offset: <?php echo esc_html(number_format_i18n((int) $snapshot['shares_offset'])); ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="rts-share-platform-grid">
                        <?php foreach ((array) $snapshot['platform_counts'] as $platform => $count): ?>
                            <?php
                            $cfg = self::platform_config($platform);
                            $count = (int) $count;
                            $pct = ((int) $snapshot['shares_total'] > 0)
                                ? round(($count / (int) $snapshot['shares_total']) * 100, 1)
                                : 0;
                            ?>
                            <div class="rts-share-platform-card" data-platform="<?php echo esc_attr($platform); ?>">
                                <div class="rts-platform-header">
                                    <span class="dashicons <?php echo esc_attr($cfg['icon']); ?>"></span>
                                    <h4 class="rts-platform-name"><?php echo esc_html($cfg['label']); ?></h4>
                                </div>
                                <div class="rts-platform-stats">
                                    <div class="rts-platform-count"><?php echo esc_html(number_format_i18n($count)); ?></div>
                                    <div class="rts-platform-percentage"><?php echo esc_html($pct); ?>% of total</div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </section>
        <?php
    }

    /**
     * @return array<string,mixed>
     */
    private static function get_snapshot(): array {
        global $wpdb;
        $agg = get_option('rts_aggregated_stats', []);
        if (!is_array($agg)) $agg = [];

        $letters = wp_count_posts('letter');
        $published = (int) ($letters->publish ?? 0);
        $pending = (int) ($letters->pending ?? 0);
        $submissions = $published + $pending;
        $acceptance_rate = $submissions > 0 ? round(($published / $submissions) * 100, 1) : 0.0;

        $avg_quality = $wpdb->get_var(
            "SELECT AVG(CAST(pm.meta_value AS DECIMAL(5,2)))
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type = 'letter'
               AND pm.meta_key = 'quality_score'
               AND pm.meta_value <> ''"
        );
        $avg_quality = is_numeric($avg_quality) ? round((float) $avg_quality, 1) : 0.0;

        $platforms = ['facebook', 'x', 'threads', 'whatsapp', 'reddit', 'copy', 'email'];
        $platform_counts = [];
        $shares_total = 0;
        foreach ($platforms as $platform) {
            $meta_key = 'rts_share_' . $platform;
            $count = (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COALESCE(SUM(CAST(meta_value AS UNSIGNED)), 0) FROM {$wpdb->postmeta} WHERE meta_key = %s", $meta_key)
            );
            $platform_counts[$platform] = $count;
            $shares_total += $count;
        }

        $shares_offset = (int) get_option('rts_stat_offset_shares', 0);
        $taxonomy = isset($agg['taxonomy_breakdown']) && is_array($agg['taxonomy_breakdown'])
            ? $agg['taxonomy_breakdown']
            : [];
        $top_feelings = isset($taxonomy['letter_feeling']) && is_array($taxonomy['letter_feeling'])
            ? array_slice($taxonomy['letter_feeling'], 0, 4, true)
            : [];
        $top_tones = isset($taxonomy['letter_tone']) && is_array($taxonomy['letter_tone'])
            ? array_slice($taxonomy['letter_tone'], 0, 4, true)
            : [];

        $queue_due_now = 0;
        $dead_letters = 0;
        $queue_table = $wpdb->prefix . 'rts_email_queue';
        $dead_table = $wpdb->prefix . 'rts_dead_letter_queue';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $queue_table)) === $queue_table) {
            $queue_due_now = (int) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$queue_table} WHERE status = 'pending' AND scheduled_at <= %s",
                    current_time('mysql', true)
                )
            );
        }
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $dead_table)) === $dead_table) {
            $dead_letters = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$dead_table}");
        }

        return [
            'velocity_24h' => (int) ($agg['velocity_24h'] ?? 0),
            'velocity_7d' => (int) ($agg['velocity_7d'] ?? 0),
            'velocity_30d' => (int) ($agg['velocity_30d'] ?? 0),
            'published' => $published,
            'pending' => $pending,
            'submissions' => $submissions,
            'acceptance_rate' => $acceptance_rate,
            'avg_quality' => $avg_quality,
            'shares_total' => $shares_total,
            'shares_offset' => $shares_offset,
            'shares_total_display' => $shares_total + $shares_offset,
            'platform_counts' => $platform_counts,
            'top_feelings' => $top_feelings,
            'top_tones' => $top_tones,
            'mail_mode' => get_option('rts_smtp_enabled', false) ? 'Live SMTP' : 'Testing',
            'send_engine' => get_option('rts_email_sending_enabled', true) ? 'Enabled' : 'Paused',
            'queue_due_now' => $queue_due_now,
            'dead_letters' => $dead_letters,
        ];
    }

    /**
     * @return array{label:string,icon:string}
     */
    private static function platform_config(string $platform): array {
        $map = [
            'facebook' => ['label' => 'Facebook', 'icon' => 'dashicons-facebook-alt'],
            'x' => ['label' => 'X (Twitter)', 'icon' => 'dashicons-twitter'],
            'threads' => ['label' => 'Threads', 'icon' => 'dashicons-groups'],
            'whatsapp' => ['label' => 'WhatsApp', 'icon' => 'dashicons-whatsapp'],
            'reddit' => ['label' => 'Reddit', 'icon' => 'dashicons-reddit'],
            'copy' => ['label' => 'Copy Link', 'icon' => 'dashicons-admin-links'],
            'email' => ['label' => 'Email', 'icon' => 'dashicons-email'],
        ];
        return $map[$platform] ?? ['label' => ucfirst($platform), 'icon' => 'dashicons-share'];
    }

    /**
     * @param string|int|float $value
     */
    private static function metric_card(string $label, $value, string $sub): void {
        ?>
        <div class="rts-analytics-card">
            <div class="rts-analytics-content">
                <div class="rts-analytics-label"><?php echo esc_html($label); ?></div>
                <div class="rts-analytics-value"><?php echo esc_html((string) $value); ?></div>
                <div class="rts-analytics-sub"><?php echo esc_html($sub); ?></div>
            </div>
        </div>
        <?php
    }

    /**
     * @param array<string,int> $data
     */
    private static function kv_card(string $title, array $data): void {
        ?>
        <div class="rts-analytics-box-card">
            <div class="rts-analytics-box-header">
                <h4><?php echo esc_html($title); ?></h4>
            </div>
            <?php if (empty($data)): ?>
                <div class="rts-empty-state-small">
                    <span class="dashicons dashicons-chart-bar"></span>
                    <p>No data yet</p>
                </div>
            <?php else: ?>
                <div class="rts-analytics-list">
                    <?php foreach ($data as $key => $value): ?>
                        <div class="rts-analytics-list-item">
                            <span class="rts-analytics-list-label"><?php echo esc_html((string) $key); ?></span>
                            <span class="rts-analytics-list-value"><?php echo esc_html(number_format_i18n((int) $value)); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

RTS_Home_Dashboard_Widgets::init();
