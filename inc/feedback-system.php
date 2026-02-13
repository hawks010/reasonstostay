<?php
/**
 * Reasons to Stay - Feedback System
 * Internal feedback tied to a specific letter.
 *
 * Stores feedback as a private CPT (rts_feedback) so admins can:
 * - see triggered/negative feedback quickly
 * - filter by letter
 * - audit trends without exposing user info publicly
 */

if (!defined('ABSPATH')) exit;

class RTS_Feedback_System {

    private static $instance = null;

    /**
     * Build a short-lived dedupe key to avoid double inserts if the browser
     * submits twice (double JS init, accidental double-click, etc.).
     */
    private function build_dedupe_key($letter_id, $rating, $mood_change, $triggered, $comment, $ip, $ua) {
        $comment_snip = mb_substr(trim((string) $comment), 0, 200);
        $ip_hash = $ip ? md5((string) $ip) : '';
        $ua_snip = mb_substr((string) $ua, 0, 120);
        return 'rts_fb_' . md5(implode('|', [
            (int) $letter_id,
            (string) $rating,
            (string) $mood_change,
            (string) $triggered,
            $comment_snip,
            $ip_hash,
            $ua_snip,
        ]));
    }

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('rest_api_init', [$this, 'register_endpoints']);

        // AJAX fallback (public) for environments that block /wp-json
        add_action('wp_ajax_nopriv_rts_submit_feedback', [$this, 'ajax_submit_feedback']);
        add_action('wp_ajax_rts_submit_feedback', [$this, 'ajax_submit_feedback']);

        // Admin UI
        add_filter('manage_rts_feedback_posts_columns', [$this, 'admin_columns']);
        add_action('manage_rts_feedback_posts_custom_column', [$this, 'admin_column_content'], 10, 2);
        add_filter('manage_edit-rts_feedback_sortable_columns', [$this, 'admin_sortable_columns']);

        add_action('restrict_manage_posts', [$this, 'admin_filters']);
        add_action('pre_get_posts', [$this, 'admin_filter_query']);
        add_action('admin_notices', [$this, 'render_feedback_endpoint_insights'], 16);

        add_action('add_meta_boxes', [$this, 'add_letter_metabox']);
        add_action('admin_head', [$this, 'admin_styles']);
    }

    public function register_cpt() {
        $labels = [
            'name' => 'Letter Feedback',
            'singular_name' => 'Letter Feedback',
            'menu_name' => 'Feedback',
        ];

        register_post_type('rts_feedback', [
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            // Expose feedback under Letters so admins can manage it alongside letters.
            'show_in_menu' => 'edit.php?post_type=letter',
            'capability_type' => 'post',
            'supports' => ['title'],
            'menu_position' => 25,
        ]);
    }

    public function register_endpoints() {
        // Submit feedback (internal form)
        register_rest_route('rts/v1', '/feedback/submit', [
            'methods' => 'POST',
            'callback' => [$this, 'submit_feedback'],
            'permission_callback' => function(\WP_REST_Request $request) {
                $nonce = '';
                if (function_exists('rts_rest_request_nonce')) {
                    $nonce = rts_rest_request_nonce($request, ['_wpnonce', 'nonce'], ['x_wp_nonce', 'x-wp-nonce']);
                }

                if ($nonce === '') {
                    $nonce = sanitize_text_field((string) $request->get_header('x-wp-nonce'));
                    if ($nonce === '') {
                        $nonce = sanitize_text_field((string) $request->get_param('_wpnonce'));
                    }
                }

                if (function_exists('rts_verify_nonce_actions')) {
                    return rts_verify_nonce_actions($nonce, ['wp_rest']);
                }

                $verified = wp_verify_nonce($nonce, 'wp_rest');
                return ($verified === 1 || $verified === 2);
            },
        ]);
    }

    public function submit_feedback(WP_REST_Request $request) {
        $letter_id = absint($request->get_param('letter_id'));
        if (!$letter_id || get_post_type($letter_id) !== 'letter') {
            return new WP_REST_Response(['success' => false, 'message' => 'Invalid letter.'], 400);
        }

        // Honeypot (if present)
        $hp = (string) $request->get_param('website');
        if ($hp !== '') {
            // Pretend success to bots
            return new WP_REST_Response(['success' => true]);
        }

        $rating = sanitize_text_field((string) $request->get_param('rating')); // up|down|neutral
        $mood_change = sanitize_text_field((string) $request->get_param('mood_change')); // much_better|little_better|no_change|little_worse|much_worse
        if (!in_array($mood_change, ['much_better','little_better','no_change','little_worse','much_worse'], true)) $mood_change = '';
        if (!in_array($rating, ['up','down','neutral'], true)) $rating = 'neutral';

        $triggered = (string) $request->get_param('triggered');
        $triggered = ($triggered === '1' || $triggered === 'true' || $triggered === 'yes') ? '1' : '0';

        // Server-side idempotency: block duplicate inserts for the same payload
        // within a short window. This protects against double-submit bugs.
        $ip = $this->get_client_ip();
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $dedupe_key = $this->build_dedupe_key($letter_id, $rating, $mood_change, $triggered, (string) $request->get_param('comment'), $ip, $ua);
        if (get_transient($dedupe_key)) {
            return new WP_REST_Response([
                'success' => true,
                'duplicate' => true,
            ], 200);
        }
        set_transient($dedupe_key, 1, 120);

        $comment = wp_kses_post((string) $request->get_param('comment'));
        $comment = trim($comment);

        // Optional: allow an email for follow-up (kept private)
        $email = sanitize_email((string) $request->get_param('email'));
        if ($email && !is_email($email)) $email = '';

        $mood = sanitize_text_field((string) $request->get_param('mood')); // optional short descriptor
        $mood = trim($mood);

        // Create feedback post
        $title = 'Feedback for Letter #' . $letter_id . ' - ' . current_time('Y-m-d H:i:s');
        $feedback_id = wp_insert_post([
            'post_type' => 'rts_feedback',
            'post_status' => 'publish',
            'post_title' => $title,
        ], true);

        if (is_wp_error($feedback_id)) {
            return new WP_REST_Response(['success' => false, 'message' => 'Could not save feedback.'], 500);
        }

        update_post_meta($feedback_id, 'letter_id', $letter_id);
        update_post_meta($feedback_id, 'rating', $rating);
        if ($mood_change !== '') {
            update_post_meta($feedback_id, 'mood_change', $mood_change);
        }
        update_post_meta($feedback_id, 'triggered', $triggered);
        update_post_meta($feedback_id, 'comment', $comment);
        update_post_meta($feedback_id, 'email', $email);
        update_post_meta($feedback_id, 'mood', $mood);
        update_post_meta($feedback_id, 'ip_hash', md5($this->get_client_ip())); // privacy-safe-ish
        update_post_meta($feedback_id, 'user_agent', substr($ua, 0, 250));

        // If rating is up/down, also increment per-letter thumbs to keep systems aligned
        if (class_exists('RTS_Letter_System')) {
            $ls = RTS_Letter_System::get_instance();
            if ($rating === 'up') {
                // reuse existing endpoint logic by directly updating meta
                global $wpdb;
            $pm = $wpdb->postmeta;
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$pm} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE post_id = %d AND meta_key = %s",
                $letter_id,
                'rts_thumbs_up'
            ));
            if ($updated === false) {
                $current = (int) get_post_meta($letter_id, 'rts_thumbs_up', true);
                update_post_meta($letter_id, 'rts_thumbs_up', $current + 1);
            } elseif ($updated === 0) {
                add_post_meta($letter_id, 'rts_thumbs_up', 1, true);
            }
            }
            if ($rating === 'down') {
                global $wpdb;
            $pm = $wpdb->postmeta;
            $updated = $wpdb->query($wpdb->prepare(
                "UPDATE {$pm} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE post_id = %d AND meta_key = %s",
                $letter_id,
                'rts_thumbs_down'
            ));
            if ($updated === false) {
                $current = (int) get_post_meta($letter_id, 'rts_thumbs_down', true);
                update_post_meta($letter_id, 'rts_thumbs_down', $current + 1);
            } elseif ($updated === 0) {
                add_post_meta($letter_id, 'rts_thumbs_down', 1, true);
            }
            }
            // Recompute helpful %
            $this->recalc_helpful_pct($letter_id);
        }

        // Send email notification
        $this->send_feedback_notification($feedback_id, $letter_id, $rating, $mood_change, $triggered, $comment);

        return new WP_REST_Response(['success' => true, 'feedback_id' => (int) $feedback_id]);
    }

    /**
     * Send email notification for feedback submission
     */
    private function send_feedback_notification($feedback_id, $letter_id, $rating, $mood_change, $triggered, $comment) {
        // Check if notifications are enabled
        if (get_option('rts_email_notifications_enabled', '1') !== '1') {
            return;
        }

        // Determine if we should send based on notification settings
        $should_send = false;
        $is_urgent = false;

        if ($triggered === '1' && get_option('rts_notify_on_triggered', '1') === '1') {
            $should_send = true;
            $is_urgent = true;
        } elseif ($rating === 'down' && get_option('rts_notify_on_negative', '0') === '1') {
            $should_send = true;
        } elseif (get_option('rts_notify_on_feedback', '1') === '1') {
            $should_send = true;
        }

        if (!$should_send) {
            return;
        }

        // Get email addresses
        $to = get_option('rts_admin_notification_email', get_option('admin_email'));
        if (!is_email($to)) {
            $to = get_option('admin_email');
        }

        $cc = get_option('rts_cc_notification_email', '');
        $headers = ['Content-Type: text/html; charset=UTF-8'];

        if ($cc && is_email($cc)) {
            $headers[] = 'Cc: ' . $cc;
        }

        // Build email subject
        if ($is_urgent) {
            $subject = '🚨 URGENT: Letter Reported as Triggering - Letter #' . $letter_id;
        } elseif ($rating === 'down') {
            $subject = '👎 Negative Feedback Received - Letter #' . $letter_id;
        } else {
            $subject = '💬 New Feedback - Letter #' . $letter_id;
        }

        // Build email body
        $letter_title = get_the_title($letter_id);
        $letter_edit_url = admin_url('post.php?post=' . $letter_id . '&action=edit');
        $feedback_url = admin_url('edit.php?post_type=rts_feedback');
        $dashboard_url = admin_url('edit.php?post_type=letter&page=rts-dashboard&tab=feedback');

        $rating_emoji = [
            'up' => '👍 Helpful',
            'down' => '👎 Didn\'t help',
            'neutral' => '🤷 Neutral'
        ];

        $mood_emoji = [
            'much_better' => '😊 Much better',
            'little_better' => '🙂 A little better',
            'no_change' => '😐 No change',
            'little_worse' => '😟 A little worse',
            'much_worse' => '😢 Much worse'
        ];

        $message = '<html><body style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, \'Helvetica Neue\', Arial, sans-serif; line-height: 1.6; color: #333;">';

        if ($is_urgent) {
            $message .= '<div style="background: #fee; border-left: 4px solid #d63638; padding: 16px; margin-bottom: 20px;">';
            $message .= '<h2 style="margin: 0 0 8px 0; color: #d63638;">⚠️ Urgent: Letter Flagged as Triggering</h2>';
            $message .= '<p style="margin: 0;">A user has reported this letter as triggering or unsafe. Please review immediately.</p>';
            $message .= '</div>';
        }

        $message .= '<h3 style="margin: 20px 0 12px 0;">Feedback Details</h3>';
        $message .= '<table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">';
        $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: 600; width: 150px;">Letter:</td><td style="padding: 8px; border-bottom: 1px solid #ddd;"><a href="' . esc_url($letter_edit_url) . '">Letter #' . $letter_id . ': ' . esc_html($letter_title) . '</a></td></tr>';
        $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: 600;">Rating:</td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . ($rating_emoji[$rating] ?? esc_html($rating)) . '</td></tr>';

        if ($mood_change) {
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: 600;">Mood Change:</td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . ($mood_emoji[$mood_change] ?? esc_html($mood_change)) . '</td></tr>';
        }

        $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: 600;">Triggered:</td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . ($triggered === '1' ? '<strong style="color: #d63638;">⚠️ YES</strong>' : 'No') . '</td></tr>';

        if ($comment) {
            $message .= '<tr><td style="padding: 8px; border-bottom: 1px solid #ddd; font-weight: 600; vertical-align: top;">Comment:</td><td style="padding: 8px; border-bottom: 1px solid #ddd;">' . nl2br(esc_html($comment)) . '</td></tr>';
        }

        $message .= '<tr><td style="padding: 8px; font-weight: 600;">Submitted:</td><td style="padding: 8px;">' . current_time('F j, Y g:i a') . '</td></tr>';
        $message .= '</table>';

        $message .= '<div style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd;">';
        $message .= '<p style="margin: 0 0 12px 0;"><strong>Quick Actions:</strong></p>';
        $message .= '<a href="' . esc_url($letter_edit_url) . '" style="display: inline-block; padding: 10px 20px; background: #2271b1; color: #fff; text-decoration: none; border-radius: 4px; margin-right: 10px;">Edit Letter</a>';
        $message .= '<a href="' . esc_url($dashboard_url) . '" style="display: inline-block; padding: 10px 20px; background: #50575e; color: #fff; text-decoration: none; border-radius: 4px; margin-right: 10px;">View Dashboard</a>';
        $message .= '<a href="' . esc_url($feedback_url) . '" style="display: inline-block; padding: 10px 20px; background: #646970; color: #fff; text-decoration: none; border-radius: 4px;">All Feedback</a>';
        $message .= '</div>';

        $message .= '<div style="margin-top: 30px; padding: 16px; background: #f9f9f9; border-radius: 4px; font-size: 12px; color: #646970;">';
        $message .= '<p style="margin: 0;">This email was sent from the RTS (Reasons to Stay) moderation system.</p>';
        $message .= '<p style="margin: 8px 0 0 0;">To manage notification settings, go to: <a href="' . admin_url('edit.php?post_type=letter&page=rts-dashboard&tab=settings') . '">RTS Settings</a></p>';
        $message .= '</div>';

        $message .= '</body></html>';

        // Send email
        wp_mail($to, $subject, $message, $headers);
    }

    /**
     * AJAX fallback: Submit feedback (public)
     *
     * Expects POST fields:
     * - payload: JSON string
     */
    public function ajax_submit_feedback() {
        // CSRF protection.
        $nonce = isset($_POST['_ajax_nonce']) ? sanitize_text_field(wp_unslash($_POST['_ajax_nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'rts_feedback_submit')) {
            wp_send_json_error(['message' => 'Invalid security token'], 403);
        }

        $raw = isset($_POST['payload']) ? (string) wp_unslash($_POST['payload']) : '';
        $raw = is_string($raw) ? $raw : '';
        // Payload is JSON, so we validate after decoding rather than sanitize as plain text.
        $payload = json_decode((string) $raw, true);

        if (!is_array($payload) || json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => 'Invalid payload format'], 400);
        }
        if (!is_array($payload)) {
            $payload = [];
        }

        $req = new WP_REST_Request('POST');
        foreach ($payload as $k => $v) {
            $req->set_param($k, $v);
        }

        $resp = $this->submit_feedback($req);
        if ($resp instanceof WP_REST_Response) {
            wp_send_json($resp->get_data(), $resp->get_status());
        }

        wp_send_json(['success' => false, 'message' => 'Unexpected response.'], 500);
    }

    private function recalc_helpful_pct($letter_id) {
        $ups = (int) get_post_meta($letter_id, 'rts_thumbs_up', true);
        $downs = (int) get_post_meta($letter_id, 'rts_thumbs_down', true);
        $total = $ups + $downs;
        $pct = $total > 0 ? round(($ups / $total) * 100, 1) : '';
        if ($pct === '') {
            delete_post_meta($letter_id, 'rts_helpful_pct');
        } else {
            update_post_meta($letter_id, 'rts_helpful_pct', $pct);
        }
    }

    private function get_client_ip(): string {
        $cf_ip = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP'])) : '';
        $xff   = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])) : '';
        $xri   = isset($_SERVER['HTTP_X_REAL_IP']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_X_REAL_IP'])) : '';
        $ra    = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';

        $candidates = [];

        if ($cf_ip) { $candidates[] = $cf_ip; }
        if ($xff) {
            foreach (explode(',', $xff) as $piece) {
                $piece = trim($piece);
                if ($piece !== '') { $candidates[] = $piece; }
            }
        }
        if ($xri) { $candidates[] = $xri; }
        if ($ra)  { $candidates[] = $ra; }

        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }

        return '0.0.0.0';
    }

    // -----------------------
    // Admin list table
    // -----------------------
    public function admin_columns($columns) {
        $new = [];
        $new['cb'] = $columns['cb'] ?? '';
        $new['title'] = 'Submitted';
        $new['letter'] = 'Letter';
        $new['rating'] = 'Reaction';
        $new['mood_change'] = 'Mood change';
        $new['triggered'] = 'Triggered?';
        $new['comment'] = 'Comment';
        $new['date'] = $columns['date'] ?? 'Date';
        return $new;
    }

    public function admin_column_content($column, $post_id) {
        if ($column === 'letter') {
            $lid = (int) get_post_meta($post_id, 'letter_id', true);
            if ($lid && get_post_status($lid) !== false) {
                $url = get_edit_post_link($lid);
                echo '<a href="' . esc_url($url) . '">Letter #' . (int) $lid . '</a>';
            } else {
                echo '—';
            }
        }

        if ($column === 'rating') {
            $r = get_post_meta($post_id, 'rating', true);
            $label = ($r === 'up') ? '👍' : (($r === 'down') ? '👎' : '—');
            echo esc_html($label);
        }
        if ($column === 'mood_change') {
            $v = (string) get_post_meta($post_id, 'mood_change', true);
            $map = [
                'much_better'   => 'Much better',
                'little_better' => 'A little better',
                'no_change'     => 'No change',
                'little_worse'  => 'A little worse',
                'much_worse'    => 'Much worse',
            ];
            echo esc_html($map[$v] ?? '');
            return;
        }


        if ($column === 'triggered') {
            $t = get_post_meta($post_id, 'triggered', true);
            echo $t === '1' ? '<span class="rts-badge rts-badge-red">Yes</span>' : 'No';
        }

        if ($column === 'comment') {
            $c = get_post_meta($post_id, 'comment', true);
            $c = trim((string) $c);
            if ($c === '') {
                echo '—';
            } else {
                echo esc_html(mb_strimwidth($c, 0, 120, '…'));
            }
        }
    }

    public function admin_sortable_columns($columns) {
        $columns['triggered'] = 'triggered';
        return $columns;
    }

    public function admin_filters() {
        global $typenow;
        if ($typenow !== 'rts_feedback') return;

        // Triggered filter
        $current = isset($_GET['rts_triggered']) ? sanitize_text_field($_GET['rts_triggered']) : '';
        echo '<select name="rts_triggered" aria-label="Filter by triggered">';
        echo '<option value="">All feedback</option>';
        echo '<option value="1"' . selected($current, '1', false) . '>Triggered only</option>';
        echo '<option value="0"' . selected($current, '0', false) . '>Not triggered</option>';
        echo '</select>';

        // Letter ID filter
        $lid = isset($_GET['rts_letter_id']) ? absint($_GET['rts_letter_id']) : 0;
        echo '<input type="number" name="rts_letter_id" value="' . esc_attr($lid ?: '') . '" placeholder="Letter ID" style="width:120px;" />';
    }

    public function admin_filter_query($query) {
        if (!is_admin() || !$query->is_main_query()) return;

        $post_type = $query->get('post_type');
        if ($post_type !== 'rts_feedback') return;

        $meta_query = [];

        if (isset($_GET['rts_triggered']) && $_GET['rts_triggered'] !== '') {
            $meta_query[] = [
                'key' => 'triggered',
                'value' => sanitize_text_field($_GET['rts_triggered']),
                'compare' => '=',
            ];
        }

        if (!empty($_GET['rts_letter_id'])) {
            $meta_query[] = [
                'key' => 'letter_id',
                'value' => absint($_GET['rts_letter_id']),
                'compare' => '=',
            ];
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }
    }

    // -----------------------
    // Letter edit screen summary
    // -----------------------
    public function add_letter_metabox() {
        add_meta_box(
            'rts_letter_feedback_summary',
            'Letter Feedback',
            [$this, 'render_letter_metabox'],
            'letter',
            'side',
            'default'
        );
    }

    public function render_letter_metabox($post) {
        $lid = $post->ID;
        $ups = (int) get_post_meta($lid, 'rts_thumbs_up', true);
        $downs = (int) get_post_meta($lid, 'rts_thumbs_down', true);
        $pct = get_post_meta($lid, 'rts_helpful_pct', true);
        $pct = ($pct === '' || $pct === null) ? '—' : (float) $pct . '%';

        $feedback_url = admin_url('edit.php?post_type=rts_feedback&rts_letter_id=' . (int) $lid);

        echo '<p><strong>Thumbs up:</strong> ' . number_format($ups) . '<br>';
        echo '<strong>Thumbs down:</strong> ' . number_format($downs) . '<br>';
        echo '<strong>Helpful %:</strong> ' . esc_html($pct) . '</p>';
        echo '<p><a class="button button-small" href="' . esc_url($feedback_url) . '">View feedback</a></p>';
        echo '<p style="font-size:12px;color:#666;">Feedback is private and is not shown publicly.</p>';
    }

    public function admin_styles() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return;
        if (!in_array($screen->post_type, ['rts_feedback', 'letter'], true)) return;

        echo '<style>
            .rts-badge{display:inline-block;padding:2px 8px;border-radius:999px;font-size:12px;font-weight:600;line-height:18px}
            .rts-badge-red{background:#ffe1e1;color:#8b0000}
        </style>';
    }

    public function render_feedback_endpoint_insights() {
        if (!is_admin()) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) return;
        if ($screen->base !== 'edit' || $screen->post_type !== 'rts_feedback') return;

        $stats = $this->get_feedback_endpoint_stats();
        $trends = $this->get_feedback_endpoint_trends(14);

        $mood_svg = $this->build_line_chart_svg(
            $trends['labels'],
            [
                [
                    'label' => 'Better %',
                    'color' => '#48bb78',
                    'values' => $trends['mood_better'],
                ],
                [
                    'label' => 'Worse %',
                    'color' => '#f56565',
                    'values' => $trends['mood_worse'],
                ],
            ]
        );

        $feeling_svg = $this->build_line_chart_svg(
            $trends['labels'],
            [
                [
                    'label' => $trends['feeling_a_label'] . ' %',
                    'color' => '#60a5fa',
                    'values' => $trends['feeling_a'],
                ],
                [
                    'label' => $trends['feeling_b_label'] . ' %',
                    'color' => '#f59e0b',
                    'values' => $trends['feeling_b'],
                ],
            ]
        );
        ?>
        <section id="rts-feedback-endpoint-insights" class="rts-feedback-endpoint-insights rts-card" style="clear:both;">
            <div class="rts-feedback-endpoint-insights__head">
                <h2 class="rts-section-title"><span class="dashicons dashicons-chart-line"></span> Feedback Insights</h2>
                <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=letter&page=rts-dashboard&tab=feedback')); ?>">Open Dashboard Feedback Tab</a>
            </div>

            <div class="rts-analytics-grid-4">
                <div class="rts-analytics-card">
                    <div class="rts-analytics-icon" style="background: rgba(45, 127, 249, 0.1);">
                        <span class="dashicons dashicons-testimonial" style="color: #2d7ff9;"></span>
                    </div>
                    <div class="rts-analytics-content">
                        <div class="rts-analytics-label">Total Feedback</div>
                        <div class="rts-analytics-value"><?php echo esc_html(number_format_i18n((int) $stats['total'])); ?></div>
                        <div class="rts-analytics-sub">All time responses</div>
                    </div>
                </div>

                <div class="rts-analytics-card">
                    <div class="rts-analytics-icon" style="background: rgba(72, 187, 120, 0.1);">
                        <span class="dashicons dashicons-smiley" style="color: #48bb78;"></span>
                    </div>
                    <div class="rts-analytics-content">
                        <div class="rts-analytics-label">Mood Improvement</div>
                        <div class="rts-analytics-value"><?php echo esc_html($stats['improvement_rate']); ?>%</div>
                        <div class="rts-analytics-sub">Feel better after reading</div>
                    </div>
                </div>

                <div class="rts-analytics-card">
                    <div class="rts-analytics-icon" style="background: rgba(252, 163, 17, 0.1);">
                        <span class="dashicons dashicons-thumbs-up" style="color: #FCA311;"></span>
                    </div>
                    <div class="rts-analytics-content">
                        <div class="rts-analytics-label">Positive Ratings</div>
                        <div class="rts-analytics-value"><?php echo esc_html(number_format_i18n((int) $stats['positive'])); ?></div>
                        <div class="rts-analytics-sub">Helpful or neutral responses</div>
                    </div>
                </div>

                <div class="rts-analytics-card">
                    <div class="rts-analytics-icon" style="background: rgba(214, 54, 56, 0.1);">
                        <span class="dashicons dashicons-warning" style="color: #d63638;"></span>
                    </div>
                    <div class="rts-analytics-content">
                        <div class="rts-analytics-label">Triggered Reports</div>
                        <div class="rts-analytics-value"><?php echo esc_html(number_format_i18n((int) $stats['triggered'])); ?></div>
                        <div class="rts-analytics-sub">Letters flagged unsafe</div>
                    </div>
                </div>
            </div>

            <div class="rts-analytics-grid-2 rts-feedback-line-grid">
                <div class="rts-analytics-box-card rts-feedback-line-card">
                    <div class="rts-analytics-box-header">
                        <h4><span class="dashicons dashicons-heart"></span> Mood Trend (14d)</h4>
                    </div>
                    <p class="rts-feedback-line-sub">Daily share of better vs worse mood outcomes.</p>
                    <?php echo $mood_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <div class="rts-feedback-line-legend">
                        <span><i style="--line:#48bb78;"></i> Better %</span>
                        <span><i style="--line:#f56565;"></i> Worse %</span>
                    </div>
                </div>

                <div class="rts-analytics-box-card rts-feedback-line-card">
                    <div class="rts-analytics-box-header">
                        <h4><span class="dashicons dashicons-chart-line"></span> Feeling Trend (14d)</h4>
                    </div>
                    <p class="rts-feedback-line-sub">Daily share by linked letter feeling (top 2).</p>
                    <?php echo $feeling_svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                    <div class="rts-feedback-line-legend">
                        <span><i style="--line:#60a5fa;"></i> <?php echo esc_html($trends['feeling_a_label']); ?> %</span>
                        <span><i style="--line:#f59e0b;"></i> <?php echo esc_html($trends['feeling_b_label']); ?> %</span>
                    </div>
                </div>
            </div>
        </section>
        <script>
        (function(){
            var panel = document.getElementById('rts-feedback-endpoint-insights');
            if (!panel) return;
            function placePanel() {
                var wrap = document.querySelector('#wpbody-content .wrap');
                if (!wrap) return false;
                var commandCenter = wrap.querySelector('#rts-letter-command-center');
                if (commandCenter && commandCenter.parentNode) {
                    commandCenter.insertAdjacentElement('afterend', panel);
                    return true;
                }
                var anchor = wrap.querySelector('.wp-header-end') || wrap.querySelector('h1.wp-heading-inline') || wrap.querySelector('h1');
                if (!anchor || !anchor.parentNode) return false;
                anchor.insertAdjacentElement('afterend', panel);
                return true;
            }
            var attempts = 0;
            if (!placePanel()) {
                var timer = setInterval(function(){
                    attempts++;
                    if (placePanel() || attempts >= 14) clearInterval(timer);
                }, 120);
            }
        })();
        </script>
        <?php
    }

    /**
     * @return array{total:int,triggered:int,positive:int,improvement_rate:float}
     */
    private function get_feedback_endpoint_stats(): array {
        global $wpdb;
        $cache_key = 'rts_feedback_endpoint_stats_v1';
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $total = (int) $wpdb->get_var("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type='rts_feedback' AND post_status='publish'");
        $triggered = (int) $wpdb->get_var(
            "SELECT COUNT(1)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type='rts_feedback'
               AND p.post_status='publish'
               AND pm.meta_key='triggered'
               AND pm.meta_value='1'"
        );
        $positive = (int) $wpdb->get_var(
            "SELECT COUNT(1)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type='rts_feedback'
               AND p.post_status='publish'
               AND pm.meta_key='rating'
               AND pm.meta_value IN ('up','neutral')"
        );
        $much_better = (int) $wpdb->get_var(
            "SELECT COUNT(1)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type='rts_feedback'
               AND p.post_status='publish'
               AND pm.meta_key='mood_change'
               AND pm.meta_value='much_better'"
        );
        $little_better = (int) $wpdb->get_var(
            "SELECT COUNT(1)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type='rts_feedback'
               AND p.post_status='publish'
               AND pm.meta_key='mood_change'
               AND pm.meta_value='little_better'"
        );
        $mood_total = (int) $wpdb->get_var(
            "SELECT COUNT(1)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type='rts_feedback'
               AND p.post_status='publish'
               AND pm.meta_key='mood_change'
               AND pm.meta_value<>''"
        );

        $improvement = $mood_total > 0 ? round((($much_better + $little_better) / $mood_total) * 100, 1) : 0.0;
        $stats = [
            'total' => $total,
            'triggered' => $triggered,
            'positive' => $positive,
            'improvement_rate' => $improvement,
        ];

        set_transient($cache_key, $stats, 120);
        return $stats;
    }

    /**
     * @return array{labels:array<int,string>,mood_better:array<int,float>,mood_worse:array<int,float>,feeling_a:array<int,float>,feeling_b:array<int,float>,feeling_a_label:string,feeling_b_label:string}
     */
    private function get_feedback_endpoint_trends(int $days = 14): array {
        global $wpdb;

        $days = max(7, min(30, $days));
        $cache_key = 'rts_feedback_endpoint_trends_' . $days;
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }

        $tz = wp_timezone();
        $today = new \DateTimeImmutable('now', $tz);
        $date_keys = [];
        $labels = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = $today->sub(new \DateInterval('P' . $i . 'D'));
            $key = $d->format('Y-m-d');
            $date_keys[] = $key;
            $labels[] = $d->format('M j');
        }
        $start_at = $date_keys[0] . ' 00:00:00';

        $mood_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(p.post_date) AS day_key,
                        SUM(CASE WHEN pm.meta_value IN ('much_better','little_better') THEN 1 ELSE 0 END) AS better_count,
                        SUM(CASE WHEN pm.meta_value IN ('little_worse','much_worse') THEN 1 ELSE 0 END) AS worse_count,
                        COUNT(*) AS total_count
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
                 WHERE p.post_type = 'rts_feedback'
                   AND p.post_status = 'publish'
                   AND p.post_date >= %s
                   AND pm.meta_key = 'mood_change'
                   AND pm.meta_value <> ''
                 GROUP BY DATE(p.post_date)",
                $start_at
            ),
            ARRAY_A
        );

        $mood_by_day = [];
        if (is_array($mood_rows)) {
            foreach ($mood_rows as $row) {
                $day = (string) ($row['day_key'] ?? '');
                $total = (int) ($row['total_count'] ?? 0);
                if ($day === '' || $total <= 0) continue;
                $mood_by_day[$day] = [
                    'better' => round((((int) ($row['better_count'] ?? 0)) / $total) * 100, 1),
                    'worse' => round((((int) ($row['worse_count'] ?? 0)) / $total) * 100, 1),
                ];
            }
        }

        $feeling_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(p.post_date) AS day_key,
                        COALESCE(lf.feeling, 'Uncategorized') AS feeling,
                        COUNT(*) AS total_count
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'letter_id'
                 LEFT JOIN (
                    SELECT tr.object_id AS letter_id, MIN(t.name) AS feeling
                    FROM {$wpdb->term_relationships} tr
                    INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                    INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
                    WHERE tt.taxonomy = 'letter_feeling'
                    GROUP BY tr.object_id
                 ) lf ON lf.letter_id = CAST(pm.meta_value AS UNSIGNED)
                 WHERE p.post_type = 'rts_feedback'
                   AND p.post_status = 'publish'
                   AND p.post_date >= %s
                 GROUP BY DATE(p.post_date), COALESCE(lf.feeling, 'Uncategorized')",
                $start_at
            ),
            ARRAY_A
        );

        $feeling_totals = [];
        $feeling_by_day = [];
        $day_totals = [];
        if (is_array($feeling_rows)) {
            foreach ($feeling_rows as $row) {
                $day = (string) ($row['day_key'] ?? '');
                $feeling = sanitize_text_field((string) ($row['feeling'] ?? 'Uncategorized'));
                $count = (int) ($row['total_count'] ?? 0);
                if ($day === '' || $count <= 0) continue;

                if (!isset($feeling_by_day[$day])) $feeling_by_day[$day] = [];
                $feeling_by_day[$day][$feeling] = ($feeling_by_day[$day][$feeling] ?? 0) + $count;
                $day_totals[$day] = ($day_totals[$day] ?? 0) + $count;
                $feeling_totals[$feeling] = ($feeling_totals[$feeling] ?? 0) + $count;
            }
        }

        arsort($feeling_totals);
        $top_feelings = array_slice(array_keys($feeling_totals), 0, 2);
        $feeling_a_label = $top_feelings[0] ?? 'Uncategorized';
        $feeling_b_label = $top_feelings[1] ?? 'Other';

        $mood_better = [];
        $mood_worse = [];
        $feeling_a = [];
        $feeling_b = [];
        foreach ($date_keys as $day_key) {
            $mood_better[] = (float) ($mood_by_day[$day_key]['better'] ?? 0.0);
            $mood_worse[] = (float) ($mood_by_day[$day_key]['worse'] ?? 0.0);

            $day_total = (int) ($day_totals[$day_key] ?? 0);
            if ($day_total > 0) {
                $a_count = (int) ($feeling_by_day[$day_key][$feeling_a_label] ?? 0);
                $b_count = (int) ($feeling_by_day[$day_key][$feeling_b_label] ?? 0);
                $feeling_a[] = round(($a_count / $day_total) * 100, 1);
                $feeling_b[] = round(($b_count / $day_total) * 100, 1);
            } else {
                $feeling_a[] = 0.0;
                $feeling_b[] = 0.0;
            }
        }

        $data = [
            'labels' => $labels,
            'mood_better' => $mood_better,
            'mood_worse' => $mood_worse,
            'feeling_a' => $feeling_a,
            'feeling_b' => $feeling_b,
            'feeling_a_label' => $feeling_a_label,
            'feeling_b_label' => $feeling_b_label,
        ];
        set_transient($cache_key, $data, 120);
        return $data;
    }

    /**
     * @param array<int,string> $labels
     * @param array<int,array{label:string,color:string,values:array<int,float>}> $series
     */
    private function build_line_chart_svg(array $labels, array $series): string {
        $count = count($labels);
        if ($count < 2) {
            return '<div class="rts-empty-state-small"><span class="dashicons dashicons-chart-line"></span><p>Not enough trend data yet</p></div>';
        }

        $w = 760.0;
        $h = 220.0;
        $pad_l = 40.0;
        $pad_r = 12.0;
        $pad_t = 12.0;
        $pad_b = 30.0;
        $plot_w = $w - $pad_l - $pad_r;
        $plot_h = $h - $pad_t - $pad_b;
        $step_x = ($count > 1) ? ($plot_w / ($count - 1)) : 0.0;

        $grid = '';
        foreach ([0, 25, 50, 75, 100] as $tick) {
            $y = $pad_t + (1 - ($tick / 100)) * $plot_h;
            $grid .= '<line x1="' . round($pad_l, 1) . '" y1="' . round($y, 1) . '" x2="' . round($w - $pad_r, 1) . '" y2="' . round($y, 1) . '" class="rts-line-grid"/>';
            $grid .= '<text x="2" y="' . round($y + 4, 1) . '" class="rts-line-axis">' . (int) $tick . '%</text>';
        }

        $x_labels = '';
        $index_marks = array_unique([0, (int) floor(($count - 1) / 2), $count - 1]);
        foreach ($index_marks as $idx) {
            $x = $pad_l + ($idx * $step_x);
            $x_labels .= '<text x="' . round($x, 1) . '" y="' . round($h - 6, 1) . '" text-anchor="middle" class="rts-line-axis">' . esc_html((string) ($labels[$idx] ?? '')) . '</text>';
        }

        $paths = '';
        foreach ($series as $line) {
            $values = is_array($line['values'] ?? null) ? $line['values'] : [];
            if (count($values) !== $count) continue;
            $points = [];
            foreach ($values as $i => $value) {
                $pct = max(0.0, min(100.0, (float) $value));
                $x = $pad_l + ($i * $step_x);
                $y = $pad_t + (1 - ($pct / 100.0)) * $plot_h;
                $points[] = round($x, 1) . ',' . round($y, 1);
            }
            $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($line['color'] ?? '')) ? (string) $line['color'] : '#60a5fa';
            $paths .= '<polyline fill="none" stroke="' . esc_attr($color) . '" stroke-width="3" points="' . esc_attr(implode(' ', $points)) . '"/>';
        }

        return '<svg class="rts-feedback-line-svg" viewBox="0 0 760 220" role="img" aria-label="Feedback trend chart">'
            . '<g>' . $grid . $paths . $x_labels . '</g>'
            . '</svg>';
    }
}

RTS_Feedback_System::get_instance();
