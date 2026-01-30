<?php
/**
 * Reasons to Stay - Custom Post Type: Letters
 * Registers the letter CPT, taxonomies, and admin performance tooling.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RTS_CPT_Letters')) {
    
class RTS_CPT_Letters {

    public function __construct() {
        add_action('init', [$this, 'register_post_type']);
        add_action('init', [$this, 'register_taxonomies']);

        // Admin columns & list UI are handled in inc/admin-letter-bulk-actions.php
        // to avoid duplicate columns/output.

        // Meta boxes
        add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
        add_action('save_post_letter', [$this, 'save_meta_boxes']);

        // List filter for performance
        add_action('restrict_manage_posts', [$this, 'add_performance_filter']);
        add_action('pre_get_posts', [$this, 'apply_performance_filter']);

        // Underperformers screen is now surfaced under Moderation (consolidated admin UI)
        add_action('admin_post_rts_bulk_draft_letters', [$this, 'handle_bulk_draft_underperformers']);
    }

    public function register_post_type() {
        $labels = [
            'name' => 'Letters',
            'singular_name' => 'Letter',
            'menu_name' => 'Letters',
            'add_new' => 'Add New',
            'add_new_item' => 'Add New Letter',
            'edit_item' => 'Edit Letter',
            'new_item' => 'New Letter',
            'view_item' => 'View Letter',
            'search_items' => 'Search Letters',
        ];

        register_post_type('letter', [
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            // Keep Letters as the top-level menu (client expects this)
            'show_in_menu' => true,
            'menu_position' => 24,
            'menu_icon' => 'dashicons-email-alt2',
            'supports' => ['title', 'editor'],
            'capability_type' => 'post',
            'has_archive' => false,
            'rewrite' => false,
            'show_in_rest' => false,
        ]);
    }

    public function register_taxonomies() {
        register_taxonomy('letter_feeling', 'letter', [
            'label' => 'Feelings',
            'public' => false,
            'show_ui' => true,
            'hierarchical' => true,
            'rewrite' => false,
            'show_in_rest' => false,
        ]);

        register_taxonomy('letter_tone', 'letter', [
            'label' => 'Tone',
            'public' => false,
            'show_ui' => true,
            'hierarchical' => false,
            'rewrite' => false,
            'show_in_rest' => false,
        ]);
    }

    // -----------------------
    // Meta boxes
    // -----------------------
    public function add_meta_boxes() {
        add_meta_box(
            'rts_letter_meta',
            'Letter Details',
            [$this, 'render_meta_box'],
            'letter',
            'side',
            'default'
        );
    }

    public function render_meta_box($post) {
        wp_nonce_field('rts_letter_meta_save', 'rts_letter_meta_nonce');

        $author_name  = get_post_meta($post->ID, 'author_name', true);
        $author_email = get_post_meta($post->ID, 'author_email', true);
        $reading_time = get_post_meta($post->ID, 'reading_time', true);
        $view_count   = get_post_meta($post->ID, 'view_count', true);
        $help_count   = get_post_meta($post->ID, 'help_count', true);

        $reading_opts = [
            '' => 'Auto',
            'short' => 'Short (under 1 min)',
            'medium' => 'Medium (1-3 mins)',
            'long' => 'Long (3+ mins)',
        ];

        echo '<p><label for="rts_author_name"><strong>Author name</strong></label><br>';
        echo '<input type="text" id="rts_author_name" name="rts_author_name" value="' . esc_attr($author_name) . '" style="width:100%;" /></p>';

        echo '<p><label for="rts_author_email"><strong>Author email</strong></label><br>';
        echo '<input type="email" id="rts_author_email" name="rts_author_email" value="' . esc_attr($author_email) . '" style="width:100%;" /></p>';

        echo '<p><label for="rts_reading_time"><strong>Reading time</strong></label><br>';
        echo '<select id="rts_reading_time" name="rts_reading_time" style="width:100%;">';
        foreach ($reading_opts as $val => $label) {
            echo '<option value="' . esc_attr($val) . '"' . selected($reading_time, $val, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select></p>';

        echo '<p style="font-size:12px;color:#666;">If Reading time is set to Auto, the system will estimate it from the letter length.</p>';
        
        echo '<hr style="margin: 15px 0; border: 0; border-top: 1px solid #ddd;">';
        
        echo '<p style="margin-bottom: 10px;"><strong>Manual Stats Override</strong></p>';
        echo '<p style="font-size:12px;color:#666;margin-bottom:10px;">Use these fields when importing letters from Wix to preserve view/help counts. Leave blank for new letters.</p>';
        
        echo '<p><label for="rts_view_count"><strong>View Count</strong></label><br>';
        echo '<input type="number" id="rts_view_count" name="rts_view_count" value="' . esc_attr($view_count) . '" min="0" style="width:100%;" placeholder="0" />';
        echo '<span style="font-size:11px;color:#999;">Number of times this letter was viewed</span></p>';
        
        echo '<p><label for="rts_help_count"><strong>Help Count</strong></label><br>';
        echo '<input type="number" id="rts_help_count" name="rts_help_count" value="' . esc_attr($help_count) . '" min="0" style="width:100%;" placeholder="0" />';
        echo '<span style="font-size:11px;color:#999;">Number of "This helped" clicks</span></p>';
    }

    public function save_meta_boxes($post_id) {
        if (!isset($_POST['rts_letter_meta_nonce']) || !wp_verify_nonce($_POST['rts_letter_meta_nonce'], 'rts_letter_meta_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        $author_name  = isset($_POST['rts_author_name']) ? sanitize_text_field($_POST['rts_author_name']) : '';
        $author_email = isset($_POST['rts_author_email']) ? sanitize_email($_POST['rts_author_email']) : '';
        $reading_time = isset($_POST['rts_reading_time']) ? sanitize_key($_POST['rts_reading_time']) : '';

        if ($author_email && !is_email($author_email)) $author_email = '';

        update_post_meta($post_id, 'author_name', $author_name);
        update_post_meta($post_id, 'author_email', $author_email);

        // If set explicitly, store it. If blank/auto, allow other systems to set it.
        if ($reading_time === '') {
            delete_post_meta($post_id, 'reading_time');
        } else {
            update_post_meta($post_id, 'reading_time', $reading_time);
        }
        
        // Manual stats override (for Wix imports)
        if (isset($_POST['rts_view_count'])) {
            $view_count = intval($_POST['rts_view_count']);
            if ($view_count >= 0) {
                update_post_meta($post_id, 'view_count', $view_count);
            }
        }
        
        if (isset($_POST['rts_help_count'])) {
            $help_count = intval($_POST['rts_help_count']);
            if ($help_count >= 0) {
                update_post_meta($post_id, 'help_count', $help_count);
            }
        }
    }

    // -----------------------
    // Admin columns + badges
    // -----------------------
    public function set_custom_columns($columns) {
        $new = [];
        $new['cb'] = $columns['cb'];
        $new['title'] = $columns['title'];
        $new['author_name'] = 'Author';
        $new['views'] = 'Views';
        $new['helps'] = 'Thumbs Up';
        $new['thumbs_down'] = 'Thumbs Down';
        $new['helpful_pct'] = 'Helpful %';
        $new['date'] = $columns['date'];
        return $new;
    }

    public function sortable_columns($columns) {
        $columns['views'] = 'views';
        $columns['helpful_pct'] = 'helpful_pct';
        return $columns;
    }

    public function custom_column_content($column, $post_id) {
        switch ($column) {
            case 'author_name':
                $name = get_post_meta($post_id, 'author_name', true);
                echo $name ? esc_html($name) : '—';
                break;

            case 'views':
                $views = (int) get_post_meta($post_id, 'view_count', true);
                echo number_format($views);
                break;

            case 'helps':
                $ups = (int) get_post_meta($post_id, 'rts_thumbs_up', true);
                if ($ups <= 0) $ups = (int) get_post_meta($post_id, 'help_count', true); // legacy
                echo number_format($ups);
                break;

            case 'thumbs_down':
                $downs = (int) get_post_meta($post_id, 'rts_thumbs_down', true);
                echo number_format($downs);
                break;

            case 'helpful_pct':
                $pct = get_post_meta($post_id, 'rts_helpful_pct', true);
                $pct = ($pct === '' || $pct === null) ? '' : (float) $pct;

                $ups = (int) get_post_meta($post_id, 'rts_thumbs_up', true);
                $downs = (int) get_post_meta($post_id, 'rts_thumbs_down', true);
                $votes = $ups + $downs;

                if ($pct === '') {
                    echo '<span class="rts-badge rts-badge-muted">Unrated</span>';
                    break;
                }

                $class = 'rts-badge-green';
                if ($pct < 40) $class = 'rts-badge-red';
                elseif ($pct < 60) $class = 'rts-badge-amber';

                $label = esc_html($pct . '%');
                echo '<span class="rts-badge ' . esc_attr($class) . '">' . $label . '</span>';
                echo '<span class="rts-small">(' . number_format($votes) . ')</span>';
                break;
        }
    }

    public function admin_list_styles() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-letter') return;

        echo '<style>
            .rts-badge{display:inline-block;padding:2px 10px;border-radius:999px;font-size:12px;font-weight:700;line-height:18px}
            .rts-badge-red{background:#ffe1e1;color:#8b0000}
            .rts-badge-amber{background:#fff0d6;color:#7a4a00}
            .rts-badge-green{background:#e6ffe9;color:#0b5a18}
            .rts-badge-muted{background:#f1f1f1;color:#444}
            .rts-small{margin-left:6px;color:#666;font-size:12px}
        </style>';
    }

    // -----------------------
    // Admin filter: performance band
    // -----------------------
    public function add_performance_filter() {
        global $typenow;
        if ($typenow !== 'letter') return;

        $current = isset($_GET['rts_perf']) ? sanitize_key((string) $_GET['rts_perf']) : '';
        $options = [
            '' => 'All Performance',
            'low' => 'Low (<40%)',
            'mid' => 'Mid (40-59.9%)',
            'high' => 'High (60%+)',
            'unrated' => 'Unrated',
        ];

        echo '<select name="rts_perf" id="rts_perf">';
        foreach ($options as $val => $label) {
            printf('<option value="%s"%s>%s</option>', esc_attr($val), selected($current, $val, false), esc_html($label));
        }
        echo '</select>';
    }

    public function apply_performance_filter($query) {
        if (!is_admin() || !$query->is_main_query()) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->id !== 'edit-letter') return;

        $perf = isset($_GET['rts_perf']) ? sanitize_key((string) $_GET['rts_perf']) : '';
        if (!$perf) return;

        $meta_query = (array) $query->get('meta_query');

        if ($perf === 'unrated') {
            $meta_query[] = [
                'relation' => 'OR',
                [
                    'key' => 'rts_helpful_pct',
                    'compare' => 'NOT EXISTS',
                ],
                [
                    'key' => 'rts_helpful_pct',
                    'value' => 0,
                    'compare' => '=',
                    'type' => 'NUMERIC',
                ],
            ];
        } else {
            $ranges = [
                'low' => [0.01, 39.9],
                'mid' => [40, 59.9],
                'high' => [60, 100],
            ];
            if (isset($ranges[$perf])) {
                [$min,$max] = $ranges[$perf];
                $meta_query[] = [
                    'key' => 'rts_helpful_pct',
                    'value' => [$min, $max],
                    'compare' => 'BETWEEN',
                    'type' => 'NUMERIC',
                ];
            }
        }

        $query->set('meta_query', $meta_query);
    }

    // -----------------------
    // Underperformers screen
    // -----------------------
    public function register_underperformers_page() {
        add_submenu_page(
            'edit.php?post_type=letter',
            'Underperformers',
            'Underperformers',
            'edit_posts',
            'rts-underperformers',
            [$this, 'render_underperformers_page']
        );
    }

    public function render_underperformers_page() {
        if (!current_user_can('edit_posts')) {
            wp_die('You do not have permission to view this page.');
        }

        $min_views = isset($_GET['min_views']) ? max(0, absint($_GET['min_views'])) : 50;
        $max_pct   = isset($_GET['max_pct']) ? floatval($_GET['max_pct']) : 40.0;
        $min_votes = isset($_GET['min_votes']) ? max(0, absint($_GET['min_votes'])) : 5;

        $meta_query = [
            'relation' => 'AND',
            [
                'key' => 'view_count',
                'value' => $min_views,
                'compare' => '>=',
                'type' => 'NUMERIC',
            ],
            [
                'key' => 'rts_helpful_pct',
                'value' => $max_pct,
                'compare' => '<',
                'type' => 'NUMERIC',
            ],
        ];

        // Require at least X votes (ups+downs). Stored as meta? If not, we compute after query.
        $q = new WP_Query([
            'post_type' => 'letter',
            'post_status' => ['publish'],
            'posts_per_page' => 200,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => $meta_query,
            'no_found_rows' => true,
        ]);

        echo '<div class="wrap"><h1>Underperforming Letters</h1>';
        echo '<p>Use this to quickly spot letters with enough views but low Helpful %.</p>';

        echo '<form method="get" style="margin:14px 0;">';
        echo '<input type="hidden" name="post_type" value="letter">';
        echo '<input type="hidden" name="page" value="rts-underperformers">';
        echo '<label>Min views <input type="number" name="min_views" value="' . esc_attr($min_views) . '" style="width:90px;"></label> ';
        echo '<label>Max Helpful % <input type="number" step="0.1" name="max_pct" value="' . esc_attr($max_pct) . '" style="width:90px;"></label> ';
        echo '<label>Min votes <input type="number" name="min_votes" value="' . esc_attr($min_votes) . '" style="width:90px;"></label> ';
        submit_button('Filter', 'secondary', '', false);
        echo '</form>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('rts_bulk_draft_letters', 'rts_bulk_nonce');
        echo '<input type="hidden" name="action" value="rts_bulk_draft_letters">';

        echo '<table class="widefat striped">';
        echo '<thead><tr>
            <th style="width:28px;"><input type="checkbox" id="rts-select-all"></th>
            <th>Letter</th>
            <th style="width:90px;">Views</th>
            <th style="width:90px;">👍</th>
            <th style="width:90px;">👎</th>
            <th style="width:120px;">Helpful %</th>
            <th style="width:160px;">Last Viewed</th>
        </tr></thead><tbody>';

        $shown = 0;

        if ($q->have_posts()) {
            foreach ($q->posts as $p) {
                $views = (int) get_post_meta($p->ID, 'view_count', true);
                $ups   = (int) get_post_meta($p->ID, 'rts_thumbs_up', true);
                $downs = (int) get_post_meta($p->ID, 'rts_thumbs_down', true);
                $votes = $ups + $downs;
                if ($votes < $min_votes) continue;

                $pct = get_post_meta($p->ID, 'rts_helpful_pct', true);
                $pct = ($pct === '' || $pct === null) ? '' : (float) $pct;

                $class = 'rts-badge-green';
                if ($pct !== '' && $pct < 40) $class = 'rts-badge-red';
                elseif ($pct !== '' && $pct < 60) $class = 'rts-badge-amber';

                $last = get_post_meta($p->ID, 'last_viewed', true);

                echo '<tr>';
                echo '<td><input type="checkbox" name="letter_ids[]" value="' . esc_attr($p->ID) . '"></td>';
                echo '<td><a href="' . esc_url(get_edit_post_link($p->ID)) . '"><strong>' . esc_html(get_the_title($p->ID) ?: ('Letter #' . $p->ID)) . '</strong></a></td>';
                echo '<td>' . number_format($views) . '</td>';
                echo '<td>' . number_format($ups) . '</td>';
                echo '<td>' . number_format($downs) . '</td>';
                echo '<td>' . ($pct === '' ? '<span class="rts-badge rts-badge-muted">Unrated</span>' : '<span class="rts-badge ' . esc_attr($class) . '">' . esc_html($pct . '%') . '</span>') . '</td>';
                echo '<td>' . esc_html($last ?: '—') . '</td>';
                echo '</tr>';

                $shown++;
            }
        }

        if ($shown === 0) {
            echo '<tr><td colspan="7">No letters match these filters.</td></tr>';
        }

        echo '</tbody></table>';

        echo '<p style="margin-top:14px;">';
        submit_button('Move selected to Draft', 'primary', 'submit', false);
        echo '</p>';

        echo '</form>';

        echo '<script>
            (function(){
                const all = document.getElementById("rts-select-all");
                if(!all) return;
                all.addEventListener("change", function(){
                    document.querySelectorAll("input[name=\"letter_ids[]\"]").forEach(cb => cb.checked = all.checked);
                });
            })();
        </script>';

        echo '</div>';
    }

    public function handle_bulk_draft_underperformers() {
        if (!current_user_can('edit_posts')) {
            wp_die('You do not have permission.');
        }
        if (!isset($_POST['rts_bulk_nonce']) || !wp_verify_nonce($_POST['rts_bulk_nonce'], 'rts_bulk_draft_letters')) {
            wp_die('Invalid request.');
        }

        $ids = isset($_POST['letter_ids']) && is_array($_POST['letter_ids']) ? array_map('absint', $_POST['letter_ids']) : [];
        $ids = array_filter($ids);

        $updated = 0;
        foreach ($ids as $id) {
            if (get_post_type($id) !== 'letter') continue;
            wp_update_post([
                'ID' => $id,
                'post_status' => 'draft',
            ]);
            $updated++;
        }

        $redirect = add_query_arg([
            'post_type' => 'letter',
            'page' => 'rts-underperformers',
            'rts_drafted' => $updated,
        ], admin_url('edit.php'));

        wp_safe_redirect($redirect);
        exit;
    }
}

// Initialize
new RTS_CPT_Letters();

} // end class_exists check
