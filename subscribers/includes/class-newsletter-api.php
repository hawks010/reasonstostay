<?php
/**
 * RTS Newsletter REST API
 *
 * Provides save/preview/analytics/template/workflow endpoints for the visual builder.
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_Newsletter_API {

    const REST_NAMESPACE = 'rts-newsletter/v1';

    public function __construct() {
        add_action('rest_api_init', array($this, 'register_routes'));
        add_action('admin_init', array($this, 'maybe_seed_default_templates'), 30);
    }

    public function register_routes() {
        register_rest_route(self::REST_NAMESPACE, '/save', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'save_newsletter'),
            'permission_callback' => array($this, 'can_manage_newsletters'),
        ));

        register_rest_route(self::REST_NAMESPACE, '/preview', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'preview_newsletter'),
            'permission_callback' => array($this, 'can_manage_newsletters'),
        ));

        register_rest_route(self::REST_NAMESPACE, '/analytics/(?P<id>\\d+)', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'get_newsletter_analytics'),
            'permission_callback' => array($this, 'can_manage_newsletters'),
        ));

        register_rest_route(self::REST_NAMESPACE, '/templates', array(
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array($this, 'get_templates'),
                'permission_callback' => array($this, 'can_manage_newsletters'),
            ),
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array($this, 'save_template'),
                'permission_callback' => array($this, 'can_manage_newsletters'),
            ),
        ));

        register_rest_route(self::REST_NAMESPACE, '/workflow', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'update_workflow'),
            'permission_callback' => array($this, 'can_manage_newsletters'),
        ));

        register_rest_route(self::REST_NAMESPACE, '/health', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'health_status'),
            'permission_callback' => array($this, 'can_read_health_status'),
        ));

        register_rest_route(self::REST_NAMESPACE, '/status', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array($this, 'health_status'),
            'permission_callback' => array($this, 'can_read_health_status'),
        ));
    }

    public function can_manage_newsletters() {
        return current_user_can('manage_options') || current_user_can('edit_rts_newsletters');
    }

    /**
     * Capability gate for health endpoints.
     *
     * Allowing public access can be re-enabled via:
     * add_filter('rts_newsletter_api_public_health', '__return_true');
     */
    public function can_read_health_status() {
        if ((bool) apply_filters('rts_newsletter_api_public_health', false)) {
            return true;
        }
        return $this->can_manage_newsletters();
    }

    private function can_edit_newsletter($post_id) {
        return current_user_can('manage_options') || current_user_can('edit_post', (int) $post_id);
    }

    public function save_newsletter(WP_REST_Request $request) {
        $rate_error = $this->check_rate_limit('save_newsletter', 120, 60);
        if (is_wp_error($rate_error)) {
            return $rate_error;
        }

        $post_id = absint($request->get_param('post_id'));
        if (!$post_id) {
            return new WP_Error('missing_post_id', 'Missing newsletter ID.', array('status' => 400));
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'rts_newsletter') {
            return new WP_Error('invalid_newsletter', 'Invalid newsletter.', array('status' => 404));
        }

        if (!$this->can_edit_newsletter($post_id)) {
            return new WP_Error('forbidden', 'Not allowed to edit this newsletter.', array('status' => 403));
        }

        $update_fields = array('ID' => $post_id);
        $dirty         = false;

        $title = $request->get_param('title');
        if (is_string($title)) {
            $update_fields['post_title'] = sanitize_text_field($title);
            $dirty = true;
        }

        $content = $request->get_param('content');
        if (is_string($content)) {
            $update_fields['post_content'] = wp_kses_post($content);
            $dirty = true;
        }

        if ($dirty) {
            wp_update_post($update_fields);
        }

        $blocks = $request->get_param('blocks');
        if (!$this->is_valid_blocks_payload($blocks)) {
            return new WP_Error('invalid_blocks_payload', 'Blocks payload is invalid or too large.', array('status' => 400));
        }
        $clean_blocks = array();
        if (is_array($blocks)) {
            $clean_blocks = $this->sanitize_builder_blocks($blocks);
            update_post_meta($post_id, '_rts_nl_builder_blocks', wp_json_encode($clean_blocks));
            update_post_meta($post_id, '_rts_nl_builder_updated_at', current_time('mysql', true));
            update_post_meta($post_id, '_rts_nl_builder_updated_by', get_current_user_id());
        }

        $subject_variants = $this->sanitize_subject_variants($request->get_param('subject_variants'));
        if (!empty($subject_variants)) {
            update_post_meta($post_id, '_rts_newsletter_subject_variants', wp_json_encode($subject_variants));
        } elseif ($request->has_param('subject_variants')) {
            delete_post_meta($post_id, '_rts_newsletter_subject_variants');
        }

        $this->store_version($post_id, 'api_save');
        $this->insert_audit($post_id, 'api_save', 'Newsletter saved via API.', array(
            'has_content' => $dirty,
            'has_blocks'  => !empty($clean_blocks),
            'ab_variants' => count($subject_variants),
        ));

        return rest_ensure_response(array(
            'success'    => true,
            'post_id'    => $post_id,
            'saved_at'   => current_time('mysql', true),
            'blocks'     => $clean_blocks,
            'subject_variants' => $subject_variants,
        ));
    }

    public function preview_newsletter(WP_REST_Request $request) {
        $rate_error = $this->check_rate_limit('preview_newsletter', 180, 60);
        if (is_wp_error($rate_error)) {
            return $rate_error;
        }

        $blocks = $request->get_param('blocks');
        if (!is_array($blocks)) {
            $post_id = absint($request->get_param('post_id'));
            if ($post_id > 0) {
                $raw = get_post_meta($post_id, '_rts_nl_builder_blocks', true);
                $blocks = $this->decode_json_array($raw, 'builder_blocks_meta', $post_id);
            }
        }

        $clean_blocks = $this->sanitize_builder_blocks(is_array($blocks) ? $blocks : array());
        $html         = $this->render_blocks_to_html($clean_blocks);

        return rest_ensure_response(array(
            'success' => true,
            'html'    => $html,
            'text'    => wp_strip_all_tags(str_replace('<br>', "\n", (string) $html)),
        ));
    }

    public function get_newsletter_analytics(WP_REST_Request $request) {
        $rate_error = $this->check_rate_limit('analytics_newsletter', 120, 60);
        if (is_wp_error($rate_error)) {
            return $rate_error;
        }

        global $wpdb;

        $newsletter_id = absint($request['id']);
        if ($newsletter_id <= 0 || get_post_type($newsletter_id) !== 'rts_newsletter') {
            return new WP_Error('invalid_newsletter', 'Invalid newsletter ID.', array('status' => 404));
        }

        $table = $wpdb->prefix . 'rts_newsletter_analytics';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return rest_ensure_response(array(
                'newsletter_id' => $newsletter_id,
                'totals'        => array('sent' => 0, 'open' => 0, 'click' => 0, 'bounce' => 0, 'unsubscribe' => 0),
                'rates'         => array('open_rate' => 0, 'click_rate' => 0),
                'timeline'      => array(),
                'top_links'     => array(),
            ));
        }

        $totals = array(
            'sent'        => 0,
            'open'        => 0,
            'click'       => 0,
            'bounce'      => 0,
            'unsubscribe' => 0,
        );

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, COUNT(*) AS qty FROM {$table} WHERE newsletter_id = %d GROUP BY event_type",
            $newsletter_id
        ), ARRAY_A);

        foreach ($rows as $row) {
            $event = sanitize_key((string) ($row['event_type'] ?? ''));
            $qty   = (int) ($row['qty'] ?? 0);
            if (array_key_exists($event, $totals)) {
                $totals[$event] = $qty;
            }
        }

        $sent = max(1, (int) $totals['sent']);
        $rates = array(
            'open_rate'  => round(((int) $totals['open'] / $sent) * 100, 2),
            'click_rate' => round(((int) $totals['click'] / $sent) * 100, 2),
        );

        $timeline = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT DATE(occurred_at) AS day,
                    SUM(CASE WHEN event_type='sent' THEN 1 ELSE 0 END) AS sent,
                    SUM(CASE WHEN event_type='open' THEN 1 ELSE 0 END) AS opened,
                    SUM(CASE WHEN event_type='click' THEN 1 ELSE 0 END) AS clicked
             FROM {$table}
             WHERE newsletter_id = %d
               AND occurred_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)
             GROUP BY DATE(occurred_at)
             ORDER BY day ASC",
            $newsletter_id
        ), ARRAY_A);

        $top_links = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT target_url, COUNT(*) AS clicks
             FROM {$table}
             WHERE newsletter_id = %d
               AND event_type = 'click'
               AND target_url IS NOT NULL
               AND target_url <> ''
             GROUP BY target_url
             ORDER BY clicks DESC
             LIMIT 10",
            $newsletter_id
        ), ARRAY_A);

        return rest_ensure_response(array(
            'newsletter_id' => $newsletter_id,
            'totals'        => $totals,
            'rates'         => $rates,
            'timeline'      => $timeline,
            'top_links'     => $top_links,
        ));
    }

    public function get_templates() {
        $rate_error = $this->check_rate_limit('get_templates', 120, 60);
        if (is_wp_error($rate_error)) {
            return $rate_error;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'rts_newsletter_templates';
        $templates = array();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) {
            $rows = (array) $wpdb->get_results("SELECT id, slug, name, thumbnail_url, structure, is_system FROM {$table} ORDER BY is_system DESC, name ASC", ARRAY_A);
            foreach ($rows as $row) {
                $structure = $this->decode_json_array((string) ($row['structure'] ?? ''), 'template_structure', (int) ($row['id'] ?? 0));
                $templates[] = array(
                    'id'        => (int) ($row['id'] ?? 0),
                    'slug'      => sanitize_key((string) ($row['slug'] ?? '')),
                    'name'      => sanitize_text_field((string) ($row['name'] ?? '')),
                    'thumbnail' => esc_url_raw((string) ($row['thumbnail_url'] ?? '')),
                    'is_system' => !empty($row['is_system']),
                    'structure' => is_array($structure) ? $structure : array(),
                );
            }
        }

        return rest_ensure_response(array(
            'templates' => $templates,
        ));
    }

    public function save_template(WP_REST_Request $request) {
        $rate_error = $this->check_rate_limit('save_template', 60, 60);
        if (is_wp_error($rate_error)) {
            return $rate_error;
        }

        global $wpdb;

        $slug = sanitize_key((string) $request->get_param('slug'));
        $name = sanitize_text_field((string) $request->get_param('name'));
        $structure = $request->get_param('structure');

        if ($slug === '' || $name === '' || !is_array($structure)) {
            return new WP_Error('invalid_template', 'Template requires slug, name, and structure.', array('status' => 400));
        }

        $clean_structure = $this->sanitize_builder_blocks($structure);
        if (empty($clean_structure)) {
            return new WP_Error('invalid_structure', 'Template structure is empty or invalid.', array('status' => 400));
        }

        $table = $wpdb->prefix . 'rts_newsletter_templates';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return new WP_Error('missing_table', 'Template library table is missing.', array('status' => 500));
        }

        $existing_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE slug = %s", $slug));

        $data = array(
            'slug'      => $slug,
            'name'      => $name,
            'structure' => wp_json_encode($clean_structure),
            'created_by'=> get_current_user_id(),
        );

        if ($existing_id > 0) {
            $wpdb->update($table, $data, array('id' => $existing_id), array('%s', '%s', '%s', '%d'), array('%d'));
            $template_id = $existing_id;
        } else {
            $data['is_system']  = 0;
            $data['created_at'] = current_time('mysql', true);
            $wpdb->insert($table, $data, array('%s', '%s', '%s', '%d', '%d', '%s'));
            $template_id = (int) $wpdb->insert_id;
        }

        return rest_ensure_response(array(
            'success'     => true,
            'template_id' => $template_id,
            'slug'        => $slug,
        ));
    }

    public function update_workflow(WP_REST_Request $request) {
        $rate_error = $this->check_rate_limit('update_workflow', 120, 60);
        if (is_wp_error($rate_error)) {
            return $rate_error;
        }

        $post_id = absint($request->get_param('post_id'));
        $status  = sanitize_key((string) $request->get_param('status'));
        $comment = sanitize_text_field((string) $request->get_param('comment'));
        $assignees = $request->get_param('assignees');

        if ($post_id <= 0 || get_post_type($post_id) !== 'rts_newsletter') {
            return new WP_Error('invalid_newsletter', 'Invalid newsletter.', array('status' => 404));
        }
        if (!$this->can_edit_newsletter($post_id)) {
            return new WP_Error('forbidden', 'Not allowed to update this newsletter.', array('status' => 403));
        }

        $allowed_statuses = array('draft', 'review', 'approved', 'scheduled', 'sent');
        if (!in_array($status, $allowed_statuses, true)) {
            return new WP_Error('invalid_status', 'Invalid workflow status.', array('status' => 400));
        }

        update_post_meta($post_id, '_rts_newsletter_workflow_status', $status);

        $clean_assignees = array();
        if (is_array($assignees)) {
            foreach ($assignees as $id) {
                $id = absint($id);
                if ($id > 0) {
                    $clean_assignees[] = $id;
                }
            }
            $clean_assignees = array_values(array_unique($clean_assignees));
            update_post_meta($post_id, '_rts_newsletter_workflow_assignees', $clean_assignees);
        }

        $this->insert_audit($post_id, 'workflow_api', 'Workflow updated via API.', array(
            'status'    => $status,
            'assignees' => $clean_assignees,
            'comment'   => $comment,
        ));

        return rest_ensure_response(array(
            'success'   => true,
            'post_id'   => $post_id,
            'status'    => $status,
            'assignees' => $clean_assignees,
        ));
    }

    private function sanitize_builder_blocks(array $blocks) {
        $allowed_types = array('header', 'text', 'button', 'divider', 'social', 'footer');
        $out = array();

        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }

            $type = sanitize_key($block['type'] ?? '');
            if (!in_array($type, $allowed_types, true)) {
                continue;
            }

            $id = isset($block['id']) ? (string) $block['id'] : wp_generate_uuid4();
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
            if ($id === '') {
                $id = wp_generate_uuid4();
            }

            $data = isset($block['data']) && is_array($block['data']) ? $block['data'] : array();
            if ($type === 'header') {
                $clean_data = array(
                    'title'      => sanitize_text_field($data['title'] ?? 'Newsletter Update'),
                    'subtitle'   => sanitize_text_field($data['subtitle'] ?? ''),
                    'background' => $this->sanitize_color($data['background'] ?? '#1e293b', '#1e293b'),
                );
            } elseif ($type === 'text') {
                $clean_data = array(
                    'heading' => sanitize_text_field($data['heading'] ?? ''),
                    'body'    => sanitize_textarea_field($data['body'] ?? ''),
                );
            } elseif ($type === 'button') {
                $clean_data = array(
                    'label'      => sanitize_text_field($data['label'] ?? 'Read More'),
                    'url'        => esc_url_raw($data['url'] ?? home_url('/letters/')),
                    'background' => $this->sanitize_color($data['background'] ?? '#1d4ed8', '#1d4ed8'),
                );
            } elseif ($type === 'divider') {
                $clean_data = array(
                    'spacing' => max(8, min(48, (int) ($data['spacing'] ?? 18))),
                );
            } elseif ($type === 'social') {
                $clean_data = array(
                    'intro' => sanitize_text_field($data['intro'] ?? 'Share and stay connected'),
                );
            } else {
                $clean_data = array(
                    'text' => sanitize_textarea_field($data['text'] ?? ''),
                );
            }

            $out[] = array(
                'id'   => $id,
                'type' => $type,
                'data' => $clean_data,
            );

            if (count($out) >= 80) {
                break;
            }
        }

        return $out;
    }

    /**
     * Sanitize optional A/B subject variants.
     *
     * @param mixed $variants
     * @return array<int, string>
     */
    private function sanitize_subject_variants($variants) {
        if (!is_array($variants)) {
            return array();
        }
        $out = array();
        foreach ($variants as $variant) {
            if (!is_string($variant)) {
                continue;
            }
            $value = sanitize_text_field($variant);
            if ($value !== '') {
                $out[] = $value;
            }
            if (count($out) >= 3) {
                break;
            }
        }
        return array_values(array_unique($out));
    }

    private function render_blocks_to_html(array $blocks) {
        $html = '';
        foreach ($blocks as $block) {
            $type = sanitize_key($block['type'] ?? '');
            $data = isset($block['data']) && is_array($block['data']) ? $block['data'] : array();

            if ($type === 'header') {
                $html .= '<section style="padding:24px 18px;background:' . esc_attr($this->sanitize_color($data['background'] ?? '#1e293b', '#1e293b')) . ';color:#fff;text-align:center;">';
                $html .= '<h2 style="margin:0 0 8px;">' . esc_html($data['title'] ?? 'Newsletter Update') . '</h2>';
                if (!empty($data['subtitle'])) {
                    $html .= '<p style="margin:0;">' . esc_html($data['subtitle']) . '</p>';
                }
                $html .= '</section>';
            } elseif ($type === 'text') {
                $html .= '<section style="padding:18px 0;">';
                if (!empty($data['heading'])) {
                    $html .= '<h3 style="margin:0 0 10px;">' . esc_html($data['heading']) . '</h3>';
                }
                $html .= '<p style="margin:0;">' . nl2br(esc_html($data['body'] ?? '')) . '</p>';
                $html .= '</section>';
            } elseif ($type === 'button') {
                $html .= '<p style="margin:18px 0;text-align:center;">';
                $html .= '<a href="' . esc_url($data['url'] ?? home_url('/letters/')) . '" style="display:inline-block;padding:10px 18px;border-radius:8px;background:' . esc_attr($this->sanitize_color($data['background'] ?? '#1d4ed8', '#1d4ed8')) . ';color:#fff;text-decoration:none;">' . esc_html($data['label'] ?? 'Read More') . '</a>';
                $html .= '</p>';
            } elseif ($type === 'divider') {
                $spacing = max(8, min(48, (int) ($data['spacing'] ?? 18)));
                $html .= '<hr style="border:none;border-top:1px solid #d1d5db;margin:' . $spacing . 'px 0;">';
            } elseif ($type === 'social') {
                $social_links = $this->get_social_links();
                $parts = array();
                if (!empty($social_links['facebook'])) {
                    $parts[] = '<a href="' . esc_url($social_links['facebook']) . '">Facebook</a>';
                }
                if (!empty($social_links['instagram'])) {
                    $parts[] = '<a href="' . esc_url($social_links['instagram']) . '">Instagram</a>';
                }
                if (!empty($social_links['linkedin'])) {
                    $parts[] = '<a href="' . esc_url($social_links['linkedin']) . '">LinkedIn</a>';
                }
                if (!empty($social_links['linktree'])) {
                    $parts[] = '<a href="' . esc_url($social_links['linktree']) . '">Linktree</a>';
                }
                $html .= '<p style="margin:0 0 8px;text-align:center;font-size:13px;color:#475569;">' . esc_html($data['intro'] ?? 'Share and stay connected') . '</p>';
                $html .= '<p style="margin:0;text-align:center;">';
                $html .= !empty($parts) ? implode(' | ', $parts) : esc_html__('Social links available soon.', 'rts-subscriber-system');
                $html .= '</p>';
            } elseif ($type === 'footer') {
                $html .= '<section style="padding:18px 0 4px;font-size:12px;color:#64748b;">' . nl2br(esc_html($data['text'] ?? '')) . '</section>';
            }
        }
        return $html;
    }

    private function sanitize_color($value, $fallback) {
        $value = trim((string) $value);
        return preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $value) ? strtolower($value) : $fallback;
    }

    private function store_version($post_id, $reason) {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_newsletter_versions';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            update_option('rts_newsletter_templates_seeded_v1', 1, false);
        return;
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== 'rts_newsletter') {
            return;
        }

        $next_version = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(version_no), 0) + 1 FROM {$table} WHERE newsletter_id = %d",
            $post_id
        ));

        $wpdb->insert($table, array(
            'newsletter_id' => $post_id,
            'version_no'    => max(1, $next_version),
            'title'         => (string) $post->post_title,
            'content'       => (string) $post->post_content,
            'builder_json'  => (string) get_post_meta($post_id, '_rts_nl_builder_blocks', true),
            'reason'        => sanitize_text_field((string) $reason),
            'created_by'    => get_current_user_id(),
            'created_at'    => current_time('mysql', true),
        ), array('%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s'));

        // Keep only the latest 40 snapshots per newsletter.
        $old_ids = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE newsletter_id = %d ORDER BY version_no DESC LIMIT 999 OFFSET 40",
            $post_id
        ));
        if (!empty($old_ids)) {
            $old_ids = array_map('intval', $old_ids);
            $wpdb->query("DELETE FROM {$table} WHERE id IN (" . implode(',', $old_ids) . ")");
        }
    }

    private function insert_audit($post_id, $event_type, $message, array $context = array()) {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_newsletter_audit';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        $wpdb->insert($table, array(
            'newsletter_id' => (int) $post_id,
            'actor_id'      => (int) get_current_user_id(),
            'event_type'    => sanitize_key((string) $event_type),
            'message'       => sanitize_text_field((string) $message),
            'context'       => !empty($context) ? wp_json_encode($context) : null,
            'created_at'    => current_time('mysql', true),
        ), array('%d', '%d', '%s', '%s', '%s', '%s'));
    }

    public function maybe_seed_default_templates() {
        // Run once, and only in wp-admin when visiting RTS Newsletter screens.
        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }
        if (get_option('rts_newsletter_templates_seeded_v1')) {
            return;
        }
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page && strpos($page, 'rts-newsletter') === false && strpos($page, 'rts_newsletter') === false) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'rts_newsletter_templates';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return;
        }

        $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        if ($count > 0) {
            return;
        }

        $defaults = array(
            array(
                'slug'      => 'welcome-update',
                'name'      => 'Welcome Update',
                'structure' => array(
                    array('id' => 'h1', 'type' => 'header', 'data' => array('title' => 'Dear strange,', 'subtitle' => 'A gentle update from Reasons to Stay.', 'background' => '#1e293b')),
                    array('id' => 't1', 'type' => 'text', 'data' => array('heading' => 'What is new', 'body' => 'Share one clear update and one concrete takeaway.')),
                    array('id' => 'b1', 'type' => 'button', 'data' => array('label' => 'Read More Letters', 'url' => home_url('/letters/'), 'background' => '#1d4ed8')),
                    array('id' => 'f1', 'type' => 'footer', 'data' => array('text' => 'Thank you for being here.')),
                ),
            ),
            array(
                'slug'      => 'feature-and-share',
                'name'      => 'Feature + Share',
                'structure' => array(
                    array('id' => 'h2', 'type' => 'header', 'data' => array('title' => 'Featured Letter', 'subtitle' => 'Hand-picked from the letter pool.', 'background' => '#0f766e')),
                    array('id' => 't2', 'type' => 'text', 'data' => array('heading' => 'Why this letter', 'body' => 'Introduce the letter and why you selected it this week.')),
                    array('id' => 's2', 'type' => 'social', 'data' => array('intro' => 'Share with someone who needs this today')),
                    array('id' => 'f2', 'type' => 'footer', 'data' => array('text' => 'You can manage frequency and subscriptions at any time.')),
                ),
            ),
        );

        foreach ($defaults as $tpl) {
            $wpdb->insert($table, array(
                'slug'       => sanitize_key($tpl['slug']),
                'name'       => sanitize_text_field($tpl['name']),
                'structure'  => wp_json_encode($tpl['structure']),
                'is_system'  => 1,
                'created_by' => 0,
                'created_at' => current_time('mysql', true),
            ), array('%s', '%s', '%s', '%d', '%d', '%s'));
        }

        update_option('rts_newsletter_templates_seeded_v1', 1, false);
    }

    /**
     * REST health/status payload for external monitoring integrations.
     *
     * @return WP_REST_Response
     */
    public function health_status() {
        global $wpdb;

        $queue_table = $wpdb->prefix . 'rts_email_queue';
        $queue_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $queue_table)) === $queue_table;
        $pending = 0;
        if ($queue_exists) {
            $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table} WHERE status = 'pending'");
        }

        return rest_ensure_response(array(
            'status'        => $queue_exists ? 'ok' : 'degraded',
            'timestamp_utc' => gmdate('c'),
            'tables'        => array(
                'email_queue' => $queue_exists,
            ),
            'queue'         => array(
                'pending' => $pending,
            ),
        ));
    }

    /**
     * Basic endpoint rate limiter (per user or IP) using transients.
     *
     * @param string $action
     * @param int    $limit
     * @param int    $window_seconds
     * @return true|WP_Error
     */
    private function check_rate_limit($action, $limit, $window_seconds) {
        $identity = get_current_user_id();
        if ($identity <= 0) {
            $identity = sanitize_text_field((string) ($_SERVER['REMOTE_ADDR'] ?? 'anon'));
        }
        $key = 'rts_nl_rate_' . md5($action . '|' . $identity);
        $count = (int) get_transient($key);
        if ($count >= (int) $limit) {
            return new WP_Error('rate_limited', 'Rate limit exceeded. Please retry shortly.', array('status' => 429));
        }
        set_transient($key, $count + 1, max(5, (int) $window_seconds));
        return true;
    }

    /**
     * Validate blocks payload shape/size before sanitization.
     *
     * @param mixed $blocks
     * @return bool
     */
    private function is_valid_blocks_payload($blocks) {
        if ($blocks === null) {
            return true;
        }
        if (!is_array($blocks)) {
            return false;
        }
        if (count($blocks) > 80) {
            return false;
        }
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                return false;
            }
            if (empty($block['type']) || !is_string($block['type'])) {
                return false;
            }
            if (isset($block['data']) && !is_array($block['data'])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Decode JSON payload into an array with observability hooks on malformed content.
     *
     * @param mixed  $raw
     * @param string $context
     * @param int    $entity_id
     * @return array<int|string, mixed>
     */
    private function decode_json_array($raw, $context, $entity_id = 0) {
        $raw = (string) $raw;
        if (trim($raw) === '') {
            return array();
        }

        $decoded = json_decode($raw, true);
        $json_error = json_last_error();
        if (is_array($decoded)) {
            return $decoded;
        }

        if ($json_error !== JSON_ERROR_NONE) {
            do_action(
                'rts_newsletter_api_invalid_json',
                sanitize_key((string) $context),
                (int) $entity_id,
                $raw,
                $json_error,
                json_last_error_msg()
            );
        }

        return array();
    }

    /**
     * Resolve social links from settings with safe defaults.
     *
     * @return array<string, string>
     */
    private function get_social_links() {
        return array(
            'facebook'  => esc_url_raw((string) get_option('rts_social_facebook', '')),
            'instagram' => esc_url_raw((string) get_option('rts_social_instagram', '')),
            'linkedin'  => esc_url_raw((string) get_option('rts_social_linkedin', '')),
            'linktree'  => esc_url_raw((string) get_option('rts_social_linktree', '')),
        );
    }
}
