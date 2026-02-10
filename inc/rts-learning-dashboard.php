<?php
/**
 * RTS Learning Dashboard Widget
 *
 * Adds a small dashboard widget for admins showing active learning rules.
 */

if (!defined('ABSPATH')) { exit; }

class RTS_Learning_Dashboard {
    public static function init(): void {
        add_action('wp_dashboard_setup', [__CLASS__, 'add_widget']);
    }

    public static function add_widget(): void {
        if (!current_user_can('manage_options')) return;
        wp_add_dashboard_widget('rts_learn', '🧠 RTS Learning', [__CLASS__, 'render']);
    }

    public static function render(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_learned_patterns';

        $active = 0;
        // Fail-soft if table isn't present yet.
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) === $table) {
            $active = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE is_active = 1");
        }

        echo "<div style='text-align:center;padding:10px;'>
                <h3 style='margin:0 0 8px'>Active Rules</h3>
                <p style='font-size:26px;font-weight:700;margin:0;color:#2271b1'>" . esc_html((string) $active) . "</p>
                <p style='margin:8px 0 0;color:#555'>System is learning from your edits.</p>
              </div>";
    }
}

RTS_Learning_Dashboard::init();
