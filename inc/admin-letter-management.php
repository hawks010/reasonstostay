<?php
/**
 * RTS Letter Management (Client-Friendly Admin)
 * Creates a single, clean "Letter Management" menu and routes all tools beneath it.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RTS_Letter_Management_Admin')) {

class RTS_Letter_Management_Admin {

    const MENU_SLUG = 'rts-letter-management';

    public function __construct() {
        add_action('admin_menu', [$this, 'register_menu'], 5);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_filter('manage_edit-letter_columns', [$this, 'force_letter_columns'], 999);
        add_action('manage_letter_posts_custom_column', [$this, 'render_forced_columns'], 20, 2);
        add_filter('manage_edit-letter_sortable_columns', [$this, 'sortable_columns'], 999);
        add_action('pre_get_posts', [$this, 'sort_query']);
    }

    public function register_menu() {
        static $done = false;
        if ($done) return;
        $done = true;
        // Add a single submenu under the existing Letters CPT.
        // This page provides a client-friendly dashboard with tabs.
        add_submenu_page(
            'edit.php?post_type=letter',
            'Letter Management',
            'Letter Management',
            'edit_posts',
            self::MENU_SLUG,
            [$this, 'render_dashboard']
        );

        // IMPORTANT: Do not hide any submenus. The site owner needs access
        // to Settings, Monitor, Import/Export, and batch tools.
    }

    public function enqueue_admin_assets($hook) {
        // Load only on our pages or the letter list
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_letter = $screen && ($screen->post_type === 'letter' || strpos((string) $screen->id, 'rts') !== false);
        if (!$is_letter) return;

        $css = "
        .rts-lm-wrap{max-width:1100px}
        .rts-lm-tabs{display:flex;gap:10px;flex-wrap:wrap;margin:14px 0 18px}
        .rts-lm-tab{display:inline-flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #d0d7de;border-radius:10px;background:#fff;text-decoration:none;color:#1d2327;font-weight:600}
        .rts-lm-tab:focus{outline:2px solid #2271b1;outline-offset:2px}
        .rts-lm-tab.is-active{border-color:#2271b1;box-shadow:0 0 0 1px #2271b1 inset}
        .rts-lm-grid{display:grid;grid-template-columns:repeat(12,1fr);gap:14px;margin-top:12px}
        .rts-lm-card{grid-column:span 12;background:#fff;border:1px solid #d0d7de;border-radius:14px;padding:14px 16px}
        .rts-lm-card h2{margin:0 0 10px;font-size:14px}
        .rts-lm-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
        .rts-lm-kpi{border:1px solid #eef2f6;border-radius:12px;padding:12px;background:#fbfcfe}
        .rts-lm-kpi strong{display:block;font-size:22px;line-height:1.1}
        .rts-lm-kpi span{color:#50575e}
        @media (max-width: 900px){.rts-lm-kpis{grid-template-columns:repeat(2,1fr)}}
        @media (max-width: 520px){.rts-lm-kpis{grid-template-columns:1fr}}
        ";

        wp_register_style('rts-letter-management-admin', false);
        wp_enqueue_style('rts-letter-management-admin');
        wp_add_inline_style('rts-letter-management-admin', $css);
    }

    private function tabs($active) {
        $base = admin_url('edit.php?post_type=letter&page=' . self::MENU_SLUG);
        $items = [
            'dashboard' => ['Dashboard', $base],
            'letters'   => ['All Letters', admin_url('edit.php?post_type=letter')],
            'queue'     => ['Review Queue', admin_url('edit.php?post_type=letter&page=rts-review-queue')],
            'stats'     => ['Stats Overview', admin_url('edit.php?post_type=letter&page=rts-analytics')],
            'analytics' => ['Analytics', admin_url('edit.php?post_type=letter&page=rts-moderation')],
            'import'    => ['Import/Export', admin_url('edit.php?post_type=letter&page=rts-import-export')],
            'settings'  => ['Settings', admin_url('edit.php?post_type=letter&page=rts-settings')],
        ];

        echo '<nav class="rts-lm-tabs" aria-label="Letter Management">';
        foreach ($items as $key => [$label, $url]) {
            $cls = 'rts-lm-tab' . ($key === $active ? ' is-active' : '');
            echo '<a class="' . esc_attr($cls) . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    public function render_dashboard() {
        if (!current_user_can('edit_posts')) {
            wp_die('You do not have permission to view this page.');
        }

        $counts = $this->get_letter_counts();

        echo '<div class="wrap rts-lm-wrap">';
        echo '<h1>Letter Management</h1>';
        $this->tabs('dashboard');

        echo '<div class="rts-lm-grid">';

        echo '<section class="rts-lm-card" aria-label="Overview">';
        echo '<h2>Overview</h2>';
        echo '<div class="rts-lm-kpis">';
        $this->kpi('Pending review', $counts['pending']);
        $this->kpi('Flagged', $counts['flagged']);
        $this->kpi('Published', $counts['published']);
        $this->kpi('Needs scoring', $counts['unscored']);
        echo '</div>';
        echo '<p style="margin-top:12px">';
        // These pages live under the Letters (CPT) menu, so always link via edit.php?post_type=letter.
        echo '<a class="button button-primary" href="' . esc_url(admin_url('edit.php?post_type=letter&page=rts-review-queue')) . '">Go to Review Queue</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=letter&post_status=pending')) . '">View Pending Letters</a> ';
        echo '<a class="button" href="' . esc_url(admin_url('edit.php?post_type=letter&page=rts-import-export')) . '">Import/Export</a>';
        echo '</p>';
        echo '</section>';

        echo '<section class="rts-lm-card" aria-label="Help">';
        echo '<h2>How to manage letters (simple flow)</h2>';
        echo '<ol style="margin:0 0 0 18px;">';
        echo '<li>Open <strong>Review Queue</strong> to see what needs action.</li>';
        echo '<li>Click <strong>Review</strong> to read a letter. Approve or keep it pending.</li>';
        echo '<li>If a letter is unsafe or unsuitable, flag it for follow-up.</li>';
        echo '<li>Use <strong>Import/Export</strong> if you are adding lots of letters at once.</li>';
        echo '</ol>';
        echo '</section>';

        echo '</div>';
        echo '</div>';
    }

    private function kpi($label, $value) {
        echo '<div class="rts-lm-kpi"><strong>' . esc_html(number_format_i18n((int)$value)) . '</strong><span>' . esc_html($label) . '</span></div>';
    }

    private function get_letter_counts() {
        global $wpdb;
        $pending = (int) $wpdb->get_var("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type='letter' AND post_status='pending'");
        $published = (int) $wpdb->get_var("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type='letter' AND post_status='publish'");
        $flagged = (int) $wpdb->get_var("SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='rts_flagged' AND pm.meta_value='1'
            WHERE p.post_type='letter' AND p.post_status IN ('pending','publish')");
        $unscored = (int) $wpdb->get_var("SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='quality_score'
            WHERE p.post_type='letter' AND p.post_status IN ('pending','publish') AND pm.meta_id IS NULL");
        return [
            'pending' => $pending,
            'published' => $published,
            'flagged' => $flagged,
            'unscored' => $unscored,
        ];
    }

    // --- Letter list columns (force a clean set) ---
    public function force_letter_columns($columns) {
        $clean = [];
        $clean['cb'] = isset($columns['cb']) ? $columns['cb'] : '<input type="checkbox" />';
        $clean['title'] = 'Title';
        // Prefix custom columns so other plugins/theme handlers don't double-render them.
        $clean['rts_status'] = 'Status';
        $clean['rts_score'] = 'Score';
        $clean['rts_safety'] = 'Safety';
        $clean['rts_feeling'] = 'Feeling';
        $clean['rts_tone'] = 'Tone';
        $clean['rts_views'] = 'Views';
        $clean['rts_helpful'] = 'Helpful';
        $clean['rts_feedback'] = 'Feedback';
        $clean['rts_social'] = 'Social';
        $clean['date'] = 'Last Modified';
        return $clean;
    }

    public function render_forced_columns($column, $post_id) {
        switch ($column) {
            case 'rts_status':
                $st = get_post_status($post_id);
                $map = ['publish' => 'Published', 'pending' => 'Pending', 'draft' => 'Draft'];
                echo esc_html($map[$st] ?? ucfirst($st));
                break;
            case 'rts_score':
                $score = get_post_meta($post_id, 'quality_score', true);
                if ($score === '' || $score === null) {
                    echo '<span style="color:#666">Unscored</span>';
                } else {
                    $s = (int) $score;
                    $badge = '#e6ffe9';
                    $color = '#0b5a18';
                    if ($s < 45) { $badge = '#ffe1e1'; $color = '#8b0000'; }
                    elseif ($s < 70) { $badge = '#fff0d6'; $color = '#7a4a00'; }
                    echo '<span style="display:inline-block;padding:2px 10px;border-radius:999px;background:' . esc_attr($badge) . ';color:' . esc_attr($color) . ';font-weight:700;">' . esc_html($s) . '/100</span>';
                }
                break;
            case 'rts_safety':
                $risk = get_post_meta($post_id, 'content_risk_score', true);
                $risk = ($risk === '' || $risk === null) ? '' : (int) $risk;
                if ($risk === '') {
                    echo '<span style="color:#666">Not checked</span>';
                } else {
                    $label = 'Low';
                    $bg = '#e6ffe9';
                    $c = '#0b5a18';
                    if ($risk >= 15) { $label = 'High'; $bg = '#ffe1e1'; $c = '#8b0000'; }
                    elseif ($risk >= 8) { $label = 'Medium'; $bg = '#fff0d6'; $c = '#7a4a00'; }
                    echo '<span style="display:inline-block;padding:2px 10px;border-radius:999px;background:' . esc_attr($bg) . ';color:' . esc_attr($c) . ';font-weight:700;">' . esc_html($label) . '</span>';
                }
                break;
            case 'rts_feeling':
                $terms = wp_get_post_terms($post_id, 'letter_feeling', ['fields' => 'names']);
                echo !empty($terms) ? esc_html($terms[0]) : '—';
                break;
            case 'rts_tone':
                $terms = wp_get_post_terms($post_id, 'letter_tone', ['fields' => 'names']);
                echo !empty($terms) ? esc_html($terms[0]) : '—';
                break;
            case 'rts_views':
                echo number_format_i18n((int) get_post_meta($post_id, 'view_count', true));
                break;
            case 'rts_helpful':
                $pct = get_post_meta($post_id, 'rts_helpful_pct', true);
                if ($pct === '' || $pct === null) {
                    echo '<span style="color:#666">Unrated</span>';
                } else {
                    echo esc_html((float)$pct) . '%';
                }
                break;
            case 'rts_feedback':
                $ups = (int) get_post_meta($post_id, 'rts_thumbs_up', true);
                $downs = (int) get_post_meta($post_id, 'rts_thumbs_down', true);
                echo '👍 ' . number_format_i18n($ups) . ' | 👎 ' . number_format_i18n($downs);
                break;
            case 'rts_social':
                $has = get_post_meta($post_id, 'rts_social_image_id', true);
                echo $has ? '✓' : '—';
                break;
        }
    }

    public function sortable_columns($columns) {
        $columns['rts_score'] = 'quality_score';
        $columns['rts_views'] = 'view_count';
        return $columns;
    }

    public function sort_query($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-letter') return;

        $orderby = $query->get('orderby');
        if ($orderby === 'quality_score') {
            $query->set('meta_key', 'quality_score');
            $query->set('orderby', 'meta_value_num');
        }
        if ($orderby === 'view_count') {
            $query->set('meta_key', 'view_count');
            $query->set('orderby', 'meta_value_num');
        }
    }
}

}
