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
            'permission_callback' => '__return_true',
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
        update_post_meta($feedback_id, 'user_agent', substr((string) $_SERVER['HTTP_USER_AGENT'] ?? '', 0, 250));

        // If rating is up/down, also increment per-letter thumbs to keep systems aligned
        if (class_exists('RTS_Letter_System')) {
            $ls = RTS_Letter_System::get_instance();
            if ($rating === 'up') {
                // reuse existing endpoint logic by directly updating meta
                $ups = (int) get_post_meta($letter_id, 'rts_thumbs_up', true);
                update_post_meta($letter_id, 'rts_thumbs_up', $ups + 1);
            }
            if ($rating === 'down') {
                $downs = (int) get_post_meta($letter_id, 'rts_thumbs_down', true);
                update_post_meta($letter_id, 'rts_thumbs_down', $downs + 1);
            }
            // Recompute helpful %
            $this->recalc_helpful_pct($letter_id);
        }

        return new WP_REST_Response(['success' => true, 'feedback_id' => (int) $feedback_id]);
    }

    /**
     * AJAX fallback: Submit feedback (public)
     *
     * Expects POST fields:
     * - payload: JSON string
     */
    public function ajax_submit_feedback() {
        $raw = isset($_POST['payload']) ? wp_unslash($_POST['payload']) : '';
        $payload = json_decode((string) $raw, true);
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

    private function get_client_ip() {
        $ip = '';
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) $ip = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
        elseif (!empty($_SERVER['REMOTE_ADDR'])) $ip = $_SERVER['REMOTE_ADDR'];
        return trim($ip);
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
            if ($lid) {
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
}

RTS_Feedback_System::get_instance();
