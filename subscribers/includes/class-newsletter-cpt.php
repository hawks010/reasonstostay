<?php
/**
 * Newsletter Custom Post Type
 *
 * Manages the rts_newsletter CPT, batch sending, progress tracking,
 * and metabox UI with a 2-column layout (Main vs Sidebar).
 *
 * @package    RTS_Subscriber_System
 * @subpackage Newsletter
 * @version    3.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_Newsletter_CPT {

    const POST_TYPE  = 'rts_newsletter';
    const BATCH_SIZE = 200;

    public function __construct() {
        add_action('init', array($this, 'register_role_capabilities'), 20);
        add_action('init', array($this, 'register_post_type'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post_' . self::POST_TYPE, array($this, 'save_meta'), 10, 2);
        add_action('transition_post_status', array($this, 'handle_transition_post_status'), 10, 3);
        add_action('admin_post_rts_newsletter_workflow_action', array($this, 'handle_workflow_action'));
        add_action('admin_post_rts_newsletter_restore_version', array($this, 'handle_restore_version'));
        add_action('wp_ajax_rts_newsletter_test_send', array($this, 'ajax_test_send'));
        add_action('wp_ajax_rts_newsletter_send_all', array($this, 'ajax_send_all'));
        add_action('wp_ajax_rts_newsletter_progress', array($this, 'ajax_get_progress'));
        add_action('wp_ajax_rts_insert_random_letter', array($this, 'ajax_insert_random_letter'));
        add_action('rts_queue_newsletter_batch', array($this, 'process_queued_batch'));
        add_action('rts_newsletter_scheduled_send', array($this, 'run_scheduled_send'), 10, 2);
        add_filter('manage_' . self::POST_TYPE . '_posts_columns', array($this, 'filter_newsletter_columns'));
        add_action('manage_' . self::POST_TYPE . '_posts_custom_column', array($this, 'render_newsletter_column'), 10, 2);
        add_action('restrict_manage_posts', array($this, 'render_admin_filters'));
        add_action('pre_get_posts', array($this, 'apply_admin_filters'));
        add_action('admin_notices', array($this, 'render_admin_notices'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
    }

    /**
     * Register the rts_newsletter custom post type.
     */
    public function register_post_type() {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name'               => 'Newsletters',
                'singular_name'      => 'Newsletter',
                'add_new'            => 'Create Newsletter',
                'add_new_item'       => 'Create New Newsletter',
                'edit_item'          => 'Edit Newsletter',
                'new_item'           => 'New Newsletter',
                'view_item'          => 'View Newsletter',
                'search_items'       => 'Search Newsletters',
                'not_found'          => 'No newsletters found',
                'not_found_in_trash' => 'No newsletters found in trash',
            ),
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => 'edit.php?post_type=rts_subscriber',
            'map_meta_cap'       => true,
            'capability_type'    => array('rts_newsletter', 'rts_newsletters'),
            'capabilities'       => array(
                'edit_post'              => 'edit_rts_newsletter',
                'read_post'              => 'read_rts_newsletter',
                'delete_post'            => 'delete_rts_newsletter',
                'edit_posts'             => 'edit_rts_newsletters',
                'edit_others_posts'      => 'edit_others_rts_newsletters',
                'publish_posts'          => 'publish_rts_newsletters',
                'read_private_posts'     => 'read_private_rts_newsletters',
                'delete_posts'           => 'delete_rts_newsletters',
                'delete_private_posts'   => 'delete_private_rts_newsletters',
                'delete_published_posts' => 'delete_published_rts_newsletters',
                'delete_others_posts'    => 'delete_others_rts_newsletters',
                'edit_private_posts'     => 'edit_private_rts_newsletters',
                'edit_published_posts'   => 'edit_published_rts_newsletters',
                'create_posts'           => 'edit_rts_newsletters',
            ),
            'supports'           => array('title', 'editor'),
            'menu_icon'          => 'dashicons-megaphone',
            'has_archive'        => false,
            'publicly_queryable' => false,
            'rewrite'            => false,
        ));
    }

    /**
     * Grant newsletter capabilities to administrator/editor roles.
     */
    public function register_role_capabilities() {
        $caps = array(
            'edit_rts_newsletter',
            'read_rts_newsletter',
            'delete_rts_newsletter',
            'edit_rts_newsletters',
            'edit_others_rts_newsletters',
            'publish_rts_newsletters',
            'read_private_rts_newsletters',
            'delete_rts_newsletters',
            'delete_private_rts_newsletters',
            'delete_published_rts_newsletters',
            'delete_others_rts_newsletters',
            'edit_private_rts_newsletters',
            'edit_published_rts_newsletters',
        );

        foreach (array('administrator', 'editor') as $role_slug) {
            $role = get_role($role_slug);
            if (!$role) {
                continue;
            }
            foreach ($caps as $cap) {
                if (!$role->has_cap($cap)) {
                    $role->add_cap($cap);
                }
            }
        }
    }

    /**
     * Enqueue CSS/JS on newsletter screens.
     */
    public function enqueue_admin_assets($hook) {
        global $post_type, $post;
        if ($post_type !== self::POST_TYPE) {
            return;
        }

        // Dedicated builder styles for post-new/post edit screens.
        $css_asset = $this->resolve_admin_asset('assets/css/newsletter-admin.css');
        if (!empty($css_asset)) {
            wp_enqueue_style(
                'rts-newsletter-admin',
                $css_asset['url'],
                array(),
                (string) filemtime($css_asset['path'])
            );
        }

        $post_id = 0;
        if ($post instanceof WP_Post && $post->post_type === self::POST_TYPE) {
            $post_id = (int) $post->ID;
        } elseif (isset($_GET['post'])) {
            $post_id = absint($_GET['post']);
        }

        $js_asset = $this->resolve_admin_asset('assets/js/newsletter-admin.js');
        if (!empty($js_asset)) {
            wp_enqueue_script(
                'rts-newsletter-admin',
                $js_asset['url'],
                array('jquery', 'jquery-ui-sortable'),
                (string) filemtime($js_asset['path']),
                true
            );
        } else {
            wp_register_script('rts-newsletter-admin', false, array('jquery'), null, true);
            wp_enqueue_script('rts-newsletter-admin');
        }

        wp_localize_script('rts-newsletter-admin', 'rtsNL', array(
            'ajax_url'       => admin_url('admin-ajax.php'),
            'nonce'          => wp_create_nonce('rts_newsletter_action'),
            'rest_url'       => esc_url_raw(rest_url('rts-newsletter/v1/')),
            'rest_nonce'     => wp_create_nonce('wp_rest'),
            'admin_email'    => get_option('admin_email'),
            'site_url'       => home_url('/'),
            'post_id'        => $post_id,
            'builder_blocks' => $post_id ? $this->get_builder_blocks($post_id) : array(),
            'builder_templates' => $this->get_builder_templates(),
            'social_links'   => $this->get_social_links(),
        ));
    }

    /**
     * Resolve newsletter admin assets with optional theme override.
     *
     * @param string $relative_path Relative path from subscribers root.
     * @return array<string, string>
     */
    private function resolve_admin_asset($relative_path) {
        $relative_path = ltrim((string) $relative_path, '/');
        $theme_path = trailingslashit(get_stylesheet_directory()) . 'subscribers/' . $relative_path;
        if (file_exists($theme_path)) {
            return array(
                'path' => $theme_path,
                'url'  => trailingslashit(get_stylesheet_directory_uri()) . 'subscribers/' . $relative_path,
            );
        }

        $base_dir = defined('RTS_PLUGIN_DIR') ? trailingslashit((string) RTS_PLUGIN_DIR) : trailingslashit(dirname(__DIR__));
        $base_url = defined('RTS_PLUGIN_URL') ? trailingslashit((string) RTS_PLUGIN_URL) : trailingslashit(get_stylesheet_directory_uri()) . 'subscribers/';
        $asset_path = $base_dir . $relative_path;
        if (!file_exists($asset_path)) {
            return array();
        }

        return array(
            'path' => $asset_path,
            'url'  => $base_url . $relative_path,
        );
    }

    /**
     * Fetch template library rows for builder palette.
     *
     * @return array<int, array<string, mixed>>
     */
    private function get_builder_templates() {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_newsletter_templates';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
            return array();
        }

        $rows = (array) $wpdb->get_results("SELECT slug, name, structure FROM {$table} ORDER BY is_system DESC, name ASC LIMIT 30", ARRAY_A);
        $out  = array();
        foreach ($rows as $row) {
            $structure = json_decode((string) ($row['structure'] ?? ''), true);
            $out[] = array(
                'slug'      => sanitize_key((string) ($row['slug'] ?? '')),
                'name'      => sanitize_text_field((string) ($row['name'] ?? '')),
                'structure' => is_array($structure) ? $structure : array(),
            );
        }
        return $out;
    }

    /**
     * Return sanitized newsletter builder block data.
     */
    private function get_builder_blocks($post_id) {
        $raw = (string) get_post_meta($post_id, '_rts_nl_builder_blocks', true);
        return $this->sanitize_builder_blocks($raw);
    }

    /**
     * Sanitize serialized builder block JSON.
     */
    private function sanitize_builder_blocks($raw_json) {
        $decoded = json_decode((string) $raw_json, true);
        if (!is_array($decoded)) {
            return array();
        }

        $allowed_types = array('header', 'text', 'button', 'divider', 'social', 'footer');
        $sanitized     = array();

        foreach ($decoded as $block) {
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
                    'subtitle'   => sanitize_text_field($data['subtitle'] ?? 'Thanks for being here.'),
                    'background' => $this->sanitize_color($data['background'] ?? '#1e293b', '#1e293b'),
                );
            } elseif ($type === 'text') {
                $clean_data = array(
                    'heading' => sanitize_text_field($data['heading'] ?? 'Section Heading'),
                    'body'    => sanitize_textarea_field($data['body'] ?? 'Add your message here.'),
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
                    'text' => sanitize_textarea_field($data['text'] ?? 'You received this email because you subscribed.'),
                );
            }

            $sanitized[] = array(
                'id'   => $id,
                'type' => $type,
                'data' => $clean_data,
            );

            if (count($sanitized) >= 80) {
                break;
            }
        }

        return $sanitized;
    }

    /**
     * Restrict color values to safe hex colors.
     */
    private function sanitize_color($value, $fallback) {
        $value = trim((string) $value);
        return preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $value) ? strtolower($value) : $fallback;
    }

    /**
     * Add metaboxes: Schedule, Helpers, Test, Send.
     */
    public function add_meta_boxes() {
        // Main: Builder guide + quick starts
        add_meta_box(
            'rts_newsletter_builder_guide',
            'Newsletter Builder Guide',
            array($this, 'render_builder_metabox'),
            self::POST_TYPE,
            'normal',
            'high'
        );

        // Sidebar: Workflow and approvals
        add_meta_box(
            'rts_newsletter_workflow',
            'Workflow & Approval',
            array($this, 'render_workflow_metabox'),
            self::POST_TYPE,
            'side',
            'high'
        );

        // Sidebar: Schedule
        add_meta_box(
            'rts_newsletter_schedule',
            'Schedule',
            array($this, 'render_schedule_metabox'),
            self::POST_TYPE,
            'side',
            'high'
        );

        // Sidebar: Helpers
        add_meta_box(
            'rts_newsletter_helpers',
            'Content Helpers',
            array($this, 'render_helpers_metabox'),
            self::POST_TYPE,
            'side',
            'default'
        );

        // Sidebar: Test & Send
        add_meta_box(
            'rts_newsletter_send',
            'Test & Send',
            array($this, 'render_send_metabox'),
            self::POST_TYPE,
            'side',
            'default'
        );

        // Sidebar: Version history
        add_meta_box(
            'rts_newsletter_versions',
            'Version History',
            array($this, 'render_versions_metabox'),
            self::POST_TYPE,
            'side',
            'default'
        );

        // Sidebar: Analytics summary
        add_meta_box(
            'rts_newsletter_analytics',
            'Analytics Summary',
            array($this, 'render_analytics_metabox'),
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    /**
     * Render the Schedule metabox.
     */
    public function render_schedule_metabox($post) {
        wp_nonce_field('rts_newsletter_meta', '_rts_newsletter_nonce');
        $date      = (string) get_post_meta($post->ID, '_rts_newsletter_send_date', true);
        $time      = (string) get_post_meta($post->ID, '_rts_newsletter_send_time', true);
        $mode      = (string) get_post_meta($post->ID, '_rts_newsletter_schedule_mode', true);
        $recurring = (string) get_post_meta($post->ID, '_rts_newsletter_recurrence', true);
        $weekday   = (string) get_post_meta($post->ID, '_rts_newsletter_weekday', true);
        $monthday  = (int) get_post_meta($post->ID, '_rts_newsletter_monthday', true);
        if (!in_array($mode, array('immediate', 'scheduled', 'recurring'), true)) {
            $mode = 'immediate';
        }
        if (!in_array($recurring, array('daily', 'weekly', 'monthly'), true)) {
            $recurring = 'weekly';
        }
        if (!in_array($weekday, array('0', '1', '2', '3', '4', '5', '6'), true)) {
            $weekday = (string) (int) gmdate('w');
        }
        if ($monthday < 1 || $monthday > 28) {
            $monthday = 1;
        }
        ?>
        <div class="rts-nl-card">
            <span class="rts-nl-card__title">Schedule Mode</span>
            <div class="rts-nl-field">
                <label class="rts-nl-label">Type</label>
                <select name="_rts_newsletter_schedule_mode" class="rts-nl-input">
                    <option value="immediate" <?php selected($mode, 'immediate'); ?>>Manual / Immediate</option>
                    <option value="scheduled" <?php selected($mode, 'scheduled'); ?>>One-time Scheduled</option>
                    <option value="recurring" <?php selected($mode, 'recurring'); ?>>Recurring Campaign</option>
                </select>
            </div>
            <div class="rts-nl-field">
                <label class="rts-nl-label">Date</label>
                <input type="date" name="_rts_newsletter_send_date" value="<?php echo esc_attr($date); ?>" class="rts-nl-input">
            </div>
            <div class="rts-nl-field">
                <label class="rts-nl-label">Time (24h)</label>
                <input type="time" name="_rts_newsletter_send_time" value="<?php echo esc_attr($time ?: '09:00'); ?>" class="rts-nl-input">
            </div>
            <div class="rts-nl-field">
                <label class="rts-nl-label">Recurrence</label>
                <select name="_rts_newsletter_recurrence" class="rts-nl-input">
                    <option value="daily" <?php selected($recurring, 'daily'); ?>>Daily</option>
                    <option value="weekly" <?php selected($recurring, 'weekly'); ?>>Weekly</option>
                    <option value="monthly" <?php selected($recurring, 'monthly'); ?>>Monthly</option>
                </select>
            </div>
            <div class="rts-nl-field">
                <label class="rts-nl-label">Weekday (for weekly)</label>
                <select name="_rts_newsletter_weekday" class="rts-nl-input">
                    <option value="1" <?php selected($weekday, '1'); ?>>Monday</option>
                    <option value="2" <?php selected($weekday, '2'); ?>>Tuesday</option>
                    <option value="3" <?php selected($weekday, '3'); ?>>Wednesday</option>
                    <option value="4" <?php selected($weekday, '4'); ?>>Thursday</option>
                    <option value="5" <?php selected($weekday, '5'); ?>>Friday</option>
                    <option value="6" <?php selected($weekday, '6'); ?>>Saturday</option>
                    <option value="0" <?php selected($weekday, '0'); ?>>Sunday</option>
                </select>
            </div>
            <div class="rts-nl-field">
                <label class="rts-nl-label">Month day (1-28)</label>
                <input type="number" min="1" max="28" step="1" name="_rts_newsletter_monthday" value="<?php echo esc_attr((string) $monthday); ?>" class="rts-nl-input">
            </div>
            <p class="rts-nl-help">Recurring mode requeues this newsletter automatically using these rules and your batch pacing settings.</p>
        </div>
        <?php
    }

    /**
     * Render workflow/approval controls.
     */
    public function render_workflow_metabox($post) {
        $status    = $this->get_workflow_status($post->ID);
        $assignees = get_post_meta($post->ID, '_rts_newsletter_workflow_assignees', true);
        if (!is_array($assignees)) {
            $assignees = array();
        }
        $labels = $this->get_workflow_labels();
        $users  = get_users(array('role__in' => array('administrator', 'editor'), 'orderby' => 'display_name', 'order' => 'ASC'));
        ?>
        <div class="rts-nl-card">
            <span class="rts-nl-card__title">Current Stage</span>
            <div class="rts-nl-note rts-nl-note--warn" style="margin:0;"><?php echo esc_html($labels[$status] ?? ucfirst($status)); ?></div>
            <div class="rts-nl-field">
                <label class="rts-nl-label" for="rts_nl_workflow_status">Change Stage</label>
                <select id="rts_nl_workflow_status" name="_rts_newsletter_workflow_status" class="rts-nl-input">
                    <?php foreach ($labels as $key => $label) : ?>
                        <option value="<?php echo esc_attr($key); ?>" <?php selected($status, $key); ?>><?php echo esc_html($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rts-nl-field">
                <label class="rts-nl-label">Assign Reviewers</label>
                <select name="_rts_newsletter_workflow_assignees[]" class="rts-nl-input" multiple size="4">
                    <?php foreach ($users as $user) : ?>
                        <?php $uid = (int) $user->ID; ?>
                        <option value="<?php echo esc_attr((string) $uid); ?>" <?php selected(in_array($uid, array_map('intval', $assignees), true)); ?>>
                            <?php echo esc_html($user->display_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rts-nl-field">
                <span class="rts-nl-label">Quick Actions</span>
                <div class="rts-button-row">
                    <?php
                    $actions = array(
                        'submit_review' => 'Submit Review',
                        'approve'       => 'Approve',
                        'send_back'     => 'Send Back',
                        'mark_sent'     => 'Mark Sent',
                    );
                    foreach ($actions as $action_key => $action_label) :
                        $url = wp_nonce_url(
                            add_query_arg(
                                array(
                                    'action'          => 'rts_newsletter_workflow_action',
                                    'post_id'         => (int) $post->ID,
                                    'workflow_action' => $action_key,
                                ),
                                admin_url('admin-post.php')
                            ),
                            'rts_newsletter_workflow_action_' . (int) $post->ID
                        );
                    ?>
                        <a class="button button-secondary" href="<?php echo esc_url($url); ?>"><?php echo esc_html($action_label); ?></a>
                    <?php endforeach; ?>
                </div>
            </div>
            <p class="rts-nl-help">Use workflow status to separate drafting, review, approval, and scheduled send readiness.</p>
        </div>
        <?php
    }

    /**
     * Render version history with restore links.
     */
    public function render_versions_metabox($post) {
        $versions = $this->get_recent_versions($post->ID, 6);
        ?>
        <div class="rts-nl-card">
            <span class="rts-nl-card__title">Latest Snapshots</span>
            <?php if (empty($versions)) : ?>
                <p class="rts-nl-help">No snapshots yet. Save this newsletter to start version history.</p>
            <?php else : ?>
                <ul style="margin:0;padding-left:18px;">
                    <?php foreach ($versions as $row) : ?>
                        <?php
                        $restore_url = wp_nonce_url(
                            add_query_arg(
                                array(
                                    'action'      => 'rts_newsletter_restore_version',
                                    'post_id'     => (int) $post->ID,
                                    'version_id'  => (int) $row['id'],
                                ),
                                admin_url('admin-post.php')
                            ),
                            'rts_newsletter_restore_version_' . (int) $row['id']
                        );
                        ?>
                        <li style="margin-bottom:8px;">
                            <strong>v<?php echo (int) $row['version_no']; ?></strong>
                            <span class="rts-nl-help"><?php echo esc_html((string) $row['created_at']); ?></span><br>
                            <a href="<?php echo esc_url($restore_url); ?>">Restore this version</a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render per-newsletter analytics summary.
     */
    public function render_analytics_metabox($post) {
        $stats = $this->get_newsletter_analytics_summary($post->ID);
        ?>
        <div class="rts-nl-card">
            <span class="rts-nl-card__title">Performance</span>
            <div class="rts-nl-field"><strong>Sent:</strong> <?php echo number_format_i18n((int) $stats['sent']); ?></div>
            <div class="rts-nl-field"><strong>Opened:</strong> <?php echo number_format_i18n((int) $stats['open']); ?> (<?php echo esc_html(number_format_i18n((float) $stats['open_rate'], 1)); ?>%)</div>
            <div class="rts-nl-field"><strong>Clicked:</strong> <?php echo number_format_i18n((int) $stats['click']); ?> (<?php echo esc_html(number_format_i18n((float) $stats['click_rate'], 1)); ?>%)</div>
            <div class="rts-nl-field"><strong>Unsubscribed:</strong> <?php echo number_format_i18n((int) $stats['unsubscribe']); ?></div>
            <div class="rts-nl-field"><strong>Bounced:</strong> <?php echo number_format_i18n((int) $stats['bounce']); ?></div>
            <p class="rts-nl-help">Detailed timeline and link stats are available via the newsletter REST analytics endpoint.</p>
        </div>
        <?php
    }

    /**
     * Render main builder guidance metabox.
     */
    public function render_builder_metabox($post) {
        $builder_blocks = $this->get_builder_blocks($post->ID);
        $builder_json   = wp_json_encode($builder_blocks);
        if (!is_string($builder_json)) {
            $builder_json = '[]';
        }
        ?>
        <div class="rts-nl-guide">
            <p class="rts-nl-guide-lead">
                Build with reusable blocks first, then push the result into the editor. This keeps newsletters visual, structured, and easier to review.
            </p>

            <div id="rts-nl-builder" class="rts-nl-builder">
                <section class="rts-nl-builder__panel rts-nl-builder__panel--palette">
                    <h3>Components</h3>
                    <div class="rts-nl-components">
                        <button type="button" class="button button-secondary" data-rts-block-type="header">Header</button>
                        <button type="button" class="button button-secondary" data-rts-block-type="text">Text</button>
                        <button type="button" class="button button-secondary" data-rts-block-type="button">Button</button>
                        <button type="button" class="button button-secondary" data-rts-block-type="divider">Divider</button>
                        <button type="button" class="button button-secondary" data-rts-block-type="social">Social</button>
                        <button type="button" class="button button-secondary" data-rts-block-type="footer">Footer</button>
                    </div>
                    <h3 style="margin-top:14px;">Templates</h3>
                    <div id="rts-nl-template-library" class="rts-nl-template-library"></div>
                    <div class="rts-nl-template-actions">
                        <button type="button" class="button button-secondary" id="rts-nl-save-template">Save Current as Template</button>
                    </div>
                    <p class="rts-nl-help">Add blocks, then drag in canvas to reorder.</p>
                </section>

                <section class="rts-nl-builder__panel rts-nl-builder__panel--canvas">
                    <div class="rts-nl-builder__toolbar">
                        <button type="button" class="button button-secondary" id="rts-nl-builder-starter">Starter Layout</button>
                        <button type="button" class="button button-secondary" id="rts-nl-builder-sync-editor">Push to Editor</button>
                        <button type="button" class="button button-secondary" id="rts-nl-builder-preview">Refresh Preview</button>
                        <button type="button" class="button button-secondary" id="rts-nl-builder-save-api">Save Builder Draft</button>
                        <button type="button" class="button button-secondary" id="rts-nl-builder-clear">Clear Canvas</button>
                    </div>
                    <ul id="rts-nl-canvas" class="rts-nl-canvas" aria-label="Newsletter builder canvas"></ul>
                    <p id="rts-nl-builder-status" class="rts-nl-help"></p>
                    <div id="rts-nl-preview" class="rts-nl-preview" aria-live="polite"></div>
                </section>

                <section class="rts-nl-builder__panel rts-nl-builder__panel--settings">
                    <h3>Block Settings</h3>
                    <div id="rts-nl-block-settings" class="rts-nl-settings-empty">
                        Select a block to edit its content and styles.
                    </div>
                </section>
            </div>

            <input type="hidden" id="rts-nl-builder-json" name="_rts_nl_builder_blocks" value="<?php echo esc_attr($builder_json); ?>">

            <div class="rts-nl-guide-actions">
                <button type="button" class="button button-secondary" id="rts-insert-letter-main">Insert Random Letter</button>
                <button type="button" class="button button-secondary" id="rts-insert-socials-main">Insert Social Links</button>
                <button type="button" class="button button-secondary" id="rts-insert-cta-block">Insert CTA Block</button>
                <button type="button" class="button button-secondary" id="rts-insert-starter-layout">Append Starter HTML</button>
            </div>
            <p class="description">Tip: Keep sections short and scannable for mobile inbox users. Use "Push to Editor" before sending.</p>
        </div>
        <?php
    }

    /**
     * Render the Helpers metabox.
     */
    public function render_helpers_metabox($post) {
        ?>
        <div class="rts-nl-card">
            <span class="rts-nl-card__title">Quick Insert</span>
            <button type="button" id="rts-insert-letter" class="rts-button secondary" style="width:100%;margin-bottom:10px;justify-content:center;">
                <span class="dashicons dashicons-welcome-write-blog"></span> Insert Random Letter
            </button>
            <button type="button" id="rts-insert-socials" class="rts-button secondary" style="width:100%;justify-content:center;">
                <span class="dashicons dashicons-share"></span> Insert Socials
            </button>
            <p class="rts-nl-help" style="margin-top:10px;">Inserts content at cursor position in the editor.</p>
        </div>
        <?php
    }

    /**
     * Render the Test & Send metabox.
     */
    public function render_send_metabox($post) {
        $status = get_post_meta($post->ID, '_rts_newsletter_send_status', true);
        ?>
        <div class="rts-nl-card">
            <div style="margin:0 0 10px 0;color:rgba(255,255,255,0.84);line-height:1.5;">
                Recipients are filtered automatically: Active + Verified + Newsletters ticked<?php echo get_option('rts_email_reconsent_required') ? ' + consent confirmed' : ''; ?>.<br>
                The send count you see is the number of eligible subscribers after those rules are applied.
            </div>

            <span class="rts-nl-card__title">Test Email</span>
            <div class="rts-nl-field">
                <input type="email" id="rts-test-email" class="rts-nl-input"
                       placeholder="<?php echo esc_attr(get_option('admin_email')); ?>"
                       value="<?php echo esc_attr(get_option('admin_email')); ?>">
            </div>
            <button type="button" id="rts-test-send-btn" class="rts-button" style="width:100%;justify-content:center;">
                <span class="dashicons dashicons-email"></span> Send Test
            </button>
            <div id="rts-test-result" style="margin-top:10px;"></div>
        </div>

        <hr class="rts-nl-sep">

        <div class="rts-nl-card">
            <span class="rts-nl-card__title">Send to All Subscribers</span>
            <?php if ($status === 'sent') : ?>
                <div class="rts-nl-note rts-nl-note--success">
                    This newsletter has been sent.
                    <div class="rts-nl-note__meta">
                        Sent: <?php echo esc_html(get_post_meta($post->ID, '_rts_newsletter_sent_at', true)); ?>
                    </div>
                </div>
            <?php elseif ($status === 'sending') : ?>
                <div class="rts-nl-note rts-nl-note--warn">
                    Send in progress...
                </div>
                <div id="rts-send-progress"></div>
            <?php else : ?>
                <button type="button" id="rts-send-all-btn" class="rts-button primary" style="width:100%;justify-content:center;">
                    <span class="dashicons dashicons-megaphone"></span> Send Newsletter
                </button>
                <div id="rts-send-progress" style="margin-top:10px;"></div>
                <p class="rts-nl-help" style="margin-top:10px;">Sends to all active, verified subscribers who opted into newsletters.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Save metabox data.
     */
    public function save_meta($post_id, $post) {
        $nonce = isset($_POST['_rts_newsletter_nonce']) ? sanitize_text_field(wp_unslash($_POST['_rts_newsletter_nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'rts_newsletter_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id) && !current_user_can('manage_options')) return;

        $existing_hash = (string) get_post_meta($post_id, '_rts_nl_content_hash', true);

        if (isset($_POST['_rts_newsletter_send_date'])) {
            update_post_meta($post_id, '_rts_newsletter_send_date', sanitize_text_field($_POST['_rts_newsletter_send_date']));
        }
        if (isset($_POST['_rts_newsletter_send_time'])) {
            update_post_meta($post_id, '_rts_newsletter_send_time', sanitize_text_field($_POST['_rts_newsletter_send_time']));
        }
        if (isset($_POST['_rts_newsletter_schedule_mode'])) {
            $mode = sanitize_key((string) $_POST['_rts_newsletter_schedule_mode']);
            if (!in_array($mode, array('immediate', 'scheduled', 'recurring'), true)) {
                $mode = 'immediate';
            }
            update_post_meta($post_id, '_rts_newsletter_schedule_mode', $mode);
        }
        if (isset($_POST['_rts_newsletter_recurrence'])) {
            $recurrence = sanitize_key((string) $_POST['_rts_newsletter_recurrence']);
            if (!in_array($recurrence, array('daily', 'weekly', 'monthly'), true)) {
                $recurrence = 'weekly';
            }
            update_post_meta($post_id, '_rts_newsletter_recurrence', $recurrence);
        }
        if (isset($_POST['_rts_newsletter_weekday'])) {
            $weekday = (string) absint($_POST['_rts_newsletter_weekday']);
            if (!in_array($weekday, array('0', '1', '2', '3', '4', '5', '6'), true)) {
                $weekday = '1';
            }
            update_post_meta($post_id, '_rts_newsletter_weekday', $weekday);
        }
        if (isset($_POST['_rts_newsletter_monthday'])) {
            $monthday = max(1, min(28, (int) $_POST['_rts_newsletter_monthday']));
            update_post_meta($post_id, '_rts_newsletter_monthday', $monthday);
        }
        if (isset($_POST['_rts_newsletter_workflow_status'])) {
            $labels = $this->get_workflow_labels();
            $status = sanitize_key((string) $_POST['_rts_newsletter_workflow_status']);
            if (!isset($labels[$status])) {
                $status = 'draft';
            }
            update_post_meta($post_id, '_rts_newsletter_workflow_status', $status);
            update_post_meta($post_id, '_rts_newsletter_workflow_updated_at', current_time('mysql'));
            update_post_meta($post_id, '_rts_newsletter_workflow_updated_by', get_current_user_id());
            $this->insert_newsletter_audit($post_id, 'workflow_status', 'Workflow status changed on save.', array('status' => $status));
        }
        if (isset($_POST['_rts_newsletter_workflow_assignees']) && is_array($_POST['_rts_newsletter_workflow_assignees'])) {
            $assignees = array();
            foreach ((array) $_POST['_rts_newsletter_workflow_assignees'] as $uid) {
                $uid = absint($uid);
                if ($uid > 0) {
                    $assignees[] = $uid;
                }
            }
            $assignees = array_values(array_unique($assignees));
            update_post_meta($post_id, '_rts_newsletter_workflow_assignees', $assignees);
        }
        if (isset($_POST['_rts_nl_builder_blocks'])) {
            $blocks = $this->sanitize_builder_blocks((string) wp_unslash($_POST['_rts_nl_builder_blocks']));
            if (!empty($blocks)) {
                update_post_meta($post_id, '_rts_nl_builder_blocks', wp_json_encode($blocks));
            } else {
                delete_post_meta($post_id, '_rts_nl_builder_blocks');
            }
            update_post_meta($post_id, '_rts_nl_builder_updated_at', current_time('mysql'));
            update_post_meta($post_id, '_rts_nl_builder_updated_by', get_current_user_id());
        }

        $this->schedule_newsletter_send($post_id);

        $new_hash = md5((string) $post->post_title . '|' . (string) $post->post_content . '|' . (string) get_post_meta($post_id, '_rts_nl_builder_blocks', true));
        if ($existing_hash !== $new_hash) {
            update_post_meta($post_id, '_rts_nl_content_hash', $new_hash);
            $this->insert_version_snapshot($post_id, $post, 'manual_save');
        }
    }

    /**
     * Resolve newsletter body with builder fallback when post content is empty.
     */
    private function get_rendered_newsletter_content(WP_Post $post) {
        $content = (string) $post->post_content;

        if (trim($content) === '') {
            $blocks = $this->get_builder_blocks($post->ID);
            if (!empty($blocks)) {
                $content = $this->render_builder_blocks_to_html($blocks);
            }
        }

        return apply_filters('the_content', $content);
    }

    /**
     * Build email-safe HTML from saved block structures.
     */
    private function render_builder_blocks_to_html(array $blocks) {
        $output = '';
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = sanitize_key($block['type'] ?? '');
            $data = isset($block['data']) && is_array($block['data']) ? $block['data'] : array();

            if ($type === 'header') {
                $title      = esc_html($data['title'] ?? 'Newsletter Update');
                $subtitle   = esc_html($data['subtitle'] ?? '');
                $background = $this->sanitize_color($data['background'] ?? '#1e293b', '#1e293b');
                $output .= '<section style="padding:24px 18px;background:' . esc_attr($background) . ';color:#ffffff;text-align:center;">';
                $output .= '<h2 style="margin:0 0 8px 0;color:#ffffff;">' . $title . '</h2>';
                if ($subtitle !== '') {
                    $output .= '<p style="margin:0;color:rgba(255,255,255,0.92);">' . $subtitle . '</p>';
                }
                $output .= '</section>';
            } elseif ($type === 'text') {
                $heading = esc_html($data['heading'] ?? '');
                $body    = $this->render_builder_text($data['body'] ?? '');
                $output .= '<section style="padding:18px 0;">';
                if ($heading !== '') {
                    $output .= '<h3 style="margin:0 0 10px 0;">' . $heading . '</h3>';
                }
                $output .= '<p style="margin:0;">' . $body . '</p>';
                $output .= '</section>';
            } elseif ($type === 'button') {
                $label      = esc_html($data['label'] ?? 'Read More');
                $url        = esc_url($data['url'] ?? home_url('/letters/'));
                $background = $this->sanitize_color($data['background'] ?? '#1d4ed8', '#1d4ed8');
                $output .= '<p style="margin:18px 0;text-align:center;">';
                $output .= '<a href="' . $url . '" style="display:inline-block;padding:10px 18px;border-radius:8px;background:' . esc_attr($background) . ';color:#ffffff;text-decoration:none;font-weight:600;">' . $label . '</a>';
                $output .= '</p>';
            } elseif ($type === 'divider') {
                $spacing = max(8, min(48, (int) ($data['spacing'] ?? 18)));
                $output .= '<hr style="border:none;border-top:1px solid #d1d5db;margin:' . $spacing . 'px 0;">';
            } elseif ($type === 'social') {
                $intro = esc_html($data['intro'] ?? 'Share and stay connected');
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
                $output .= '<p style="margin:0 0 8px 0;text-align:center;font-size:13px;color:#475569;">' . $intro . '</p>';
                $output .= '<p style="margin:0;text-align:center;">';
                $output .= !empty($parts) ? implode(' | ', $parts) : esc_html__('Social links available soon.', 'rts-subscriber-system');
                $output .= '</p>';
            } elseif ($type === 'footer') {
                $text = $this->render_builder_text($data['text'] ?? 'You received this email because you subscribed.');
                $output .= '<section style="padding:18px 0 4px;font-size:12px;color:#64748b;">' . $text . '</section>';
            }
        }

        return $output;
    }

    /**
     * Convert plain text into safe HTML line breaks.
     */
    private function render_builder_text($text) {
        return nl2br(esc_html((string) $text));
    }

    /**
     * Resolve social links from settings with sane fallbacks.
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

    /**
     * AJAX: Send test email.
     */
    public function ajax_test_send() {
        check_ajax_referer('rts_newsletter_action', 'nonce');
        if (!current_user_can('edit_rts_newsletters') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        $email   = sanitize_email($_POST['email'] ?? '');

        if (!$post_id || !$email) {
            wp_send_json_error(array('message' => 'Missing post ID or email'));
        }

        $post = get_post($post_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            wp_send_json_error(array('message' => 'Invalid newsletter'));
        }

        $subject = '[TEST] ' . $post->post_title;
        $body    = $this->get_rendered_newsletter_content($post);

        $sent = wp_mail($email, $subject, $body, array('Content-Type: text/html; charset=UTF-8'));

        if ($sent) {
            wp_send_json_success(array('message' => 'Sent to ' . $email));
        }
        wp_send_json_error(array('message' => 'Failed to send test email.'));
    }

    /**
     * AJAX: Queue newsletter for all subscribers.
     */
    public function ajax_send_all() {
        check_ajax_referer('rts_newsletter_action', 'nonce');
        if (!current_user_can('edit_rts_newsletters') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        if (!$post_id) {
            wp_send_json_error(array('message' => 'Missing post ID'));
        }

        $workflow = $this->get_workflow_status($post_id);
        if (!in_array($workflow, array('approved', 'scheduled', 'sent'), true)) {
            wp_send_json_error(array('message' => 'Newsletter must be approved before sending.'));
        }

        $total = $this->count_total_subscribers($post_id);
        if ($total === 0) {
            wp_send_json_error(array('message' => 'No eligible subscribers found.'));
        }

        update_post_meta($post_id, '_rts_newsletter_send_status', 'sending');
        update_post_meta($post_id, '_rts_newsletter_total_recipients', $total);
        update_post_meta($post_id, '_rts_newsletter_queued_count', 0);

        if (!wp_next_scheduled('rts_queue_newsletter_batch', array($post_id))) {
            wp_schedule_single_event(time(), 'rts_queue_newsletter_batch', array($post_id));
        }

        $this->insert_newsletter_audit($post_id, 'queue_started', 'Newsletter queue started.', array('total' => $total));

        wp_send_json_success(array('total' => $total, 'message' => "Queued for $total subscribers"));
    }

    /**
     * AJAX: Get send progress.
     */
    public function ajax_get_progress() {
        check_ajax_referer('rts_newsletter_action', 'nonce');
        if (!current_user_can('edit_rts_newsletters') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        $progress = $this->get_send_progress($post_id);
        wp_send_json_success($progress);
    }

    /**
     * AJAX: Insert random letter content.
     */
    public function ajax_insert_random_letter() {
        check_ajax_referer('rts_newsletter_action', 'nonce');
        if (!current_user_can('edit_rts_newsletters') && !current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $letters = get_posts(array(
            'post_type'      => 'letter',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'orderby'        => 'rand',
            'meta_query'     => array(
                array(
                    'key'     => '_rts_email_ready',
                    'value'   => array('1', 'true'),
                    'compare' => 'IN',
                ),
            ),
        ));

        if (empty($letters)) {
            $letters = get_posts(array(
                'post_type'      => 'letter',
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'orderby'        => 'rand',
            ));
        }

        if (empty($letters)) {
            wp_send_json_error(array('message' => 'No published letters found.'));
        }

        $letter = $letters[0];
        $content = '<blockquote style="font-family:\'Special Elite\',\'Courier New\',monospace;line-height:1.6;">';
        $content .= wpautop($letter->post_content);
        $content .= '</blockquote>';

        wp_send_json_success(array('content' => $content, 'title' => $letter->post_title));
    }

    /**
     * WP-Cron: Process a batch of queued newsletter emails.
     */
    public function process_queued_batch($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== self::POST_TYPE) {
            return;
        }

        $status = get_post_meta($post_id, '_rts_newsletter_send_status', true);
        if ($status !== 'sending') {
            return;
        }

        $lock_ttl = max(120, (int) $this->get_batch_delay() * 6);
        $lock_key = $this->acquire_batch_lock($post_id, $lock_ttl);
        if ($lock_key === '') {
            return;
        }

        try {
            $batch_size  = $this->get_batch_size();
            $batch_delay = $this->get_batch_delay();
            $offset = (int) get_post_meta($post_id, '_rts_newsletter_queued_count', true);

            $meta_query = array(
                'relation' => 'AND',
                array('key' => '_rts_subscriber_status', 'value' => 'active', 'compare' => '='),
                array('key' => '_rts_pref_newsletters', 'value' => '1', 'compare' => '='),
                array('key' => '_rts_subscriber_verified', 'value' => '1', 'compare' => '='),
            );
            if (get_option('rts_email_reconsent_required')) {
                $meta_query[] = array('key' => '_rts_subscriber_consent_confirmed', 'compare' => 'EXISTS');
            }

            $subscribers = get_posts(array(
                'post_type'      => 'rts_subscriber',
                'post_status'    => 'publish',
                'posts_per_page' => $batch_size,
                'offset'         => $offset,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'meta_query'     => $meta_query,
            ));

            if (empty($subscribers)) {
                update_post_meta($post_id, '_rts_newsletter_send_status', 'sent');
                update_post_meta($post_id, '_rts_newsletter_sent_at', current_time('mysql', true));
                update_post_meta($post_id, '_rts_newsletter_workflow_status', 'sent');
                $this->insert_newsletter_audit($post_id, 'send_complete', 'Newsletter queue completed.');
                return;
            }

            global $wpdb;
            $queue_table = $wpdb->prefix . 'rts_email_queue';
            $now_gmt     = gmdate('Y-m-d H:i:s');
            $subject_variants = $this->get_subject_variants($post_id, (string) $post->post_title);
            $rendered_newsletter = $this->get_rendered_newsletter_content($post);
            $body_with_marker = (string) $rendered_newsletter . '<!--RTS_NEWSLETTER_ID:' . (int) $post_id . '-->';

            foreach ($subscribers as $subscriber_id) {
                $email = get_post_meta($subscriber_id, '_rts_subscriber_email', true);
                if (!$email || !is_email($email)) {
                    $email = get_the_title($subscriber_id);
                }
                if (!$email || !is_email($email)) {
                    continue;
                }

                $subject_line = $this->choose_subject_variant_for_subscriber((int) $subscriber_id, $subject_variants, (string) $post->post_title);
                $wpdb->insert($queue_table, array(
                    'subscriber_id' => $subscriber_id,
                    'letter_id'     => null,
                    'template'      => 'newsletter_custom',
                    'subject'       => $subject_line,
                    'body'          => $body_with_marker,
                    'status'        => 'pending',
                    'attempts'      => 0,
                    'priority'      => 7,
                    'scheduled_at'  => $now_gmt,
                    'created_at'    => $now_gmt,
                ));
            }

            $new_offset = $offset + count($subscribers);
            update_post_meta($post_id, '_rts_newsletter_queued_count', $new_offset);

            if (count($subscribers) >= $batch_size) {
                if (!wp_next_scheduled('rts_queue_newsletter_batch', array($post_id))) {
                    wp_schedule_single_event(time() + $batch_delay, 'rts_queue_newsletter_batch', array($post_id));
                }
            } else {
                update_post_meta($post_id, '_rts_newsletter_send_status', 'sent');
                update_post_meta($post_id, '_rts_newsletter_sent_at', current_time('mysql', true));
                update_post_meta($post_id, '_rts_newsletter_workflow_status', 'sent');
                $this->insert_newsletter_audit($post_id, 'send_complete', 'Newsletter queue completed.', array('queued' => $new_offset));
            }
        } finally {
            $this->release_batch_lock($lock_key);
        }
    }

    /**
     * Acquire a per-newsletter batch lock.
     *
     * @param int $post_id
     * @param int $ttl
     * @return string Lock key when acquired, empty string otherwise.
     */
    private function acquire_batch_lock($post_id, $ttl = 300) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return '';
        }

        $lock_key = 'rts_nl_batch_lock_' . $post_id;
        $now = time();
        $ttl = max(30, (int) $ttl);

        if (add_option($lock_key, (string) $now, '', 'no')) {
            return $lock_key;
        }

        $locked_at = (int) get_option($lock_key, 0);
        if ($locked_at > 0 && ($now - $locked_at) < $ttl) {
            return '';
        }

        delete_option($lock_key);
        if (add_option($lock_key, (string) $now, '', 'no')) {
            return $lock_key;
        }

        return '';
    }

    /**
     * Release a previously acquired batch lock.
     *
     * @param string $lock_key
     * @return void
     */
    private function release_batch_lock($lock_key) {
        $lock_key = (string) $lock_key;
        if ($lock_key === '') {
            return;
        }
        delete_option($lock_key);
    }

    /**
     * Get send progress for a newsletter.
     */
    private function get_send_progress($post_id) {
        $batch_size  = $this->get_batch_size();
        $batch_delay = $this->get_batch_delay();
        $total  = (int) get_post_meta($post_id, '_rts_newsletter_total_recipients', true);
        $queued = (int) get_post_meta($post_id, '_rts_newsletter_queued_count', true);

        $remaining_batches = $total > 0 ? max(0, ceil(($total - $queued) / $batch_size)) : 0;

        return array(
            'percent' => $total > 0 ? round(($queued / $total) * 100) : 0,
            'queued' => $queued,
            'total' => $total,
            'remaining_batches' => $remaining_batches,
            'estimated_time' => $remaining_batches * $batch_delay
        );
    }

    /**
     * Batch size for newsletter queueing.
     * Uses global email batch size so delivery pressure is consistent.
     */
    private function get_batch_size() {
        $batch = (int) get_option('rts_email_batch_size', 100);
        return max(10, min(500, $batch));
    }

    /**
     * Delay in seconds between newsletter queue chunks.
     */
    private function get_batch_delay() {
        $delay = (int) get_option('rts_newsletter_batch_delay', 5);
        return max(1, min(120, $delay));
    }

    /**
     * Get sanitized subject variants used for lightweight A/B testing.
     *
     * @param int    $post_id
     * @param string $fallback
     * @return array<int, string>
     */
    private function get_subject_variants($post_id, $fallback) {
        $raw = get_post_meta((int) $post_id, '_rts_newsletter_subject_variants', true);
        $raw = (string) $raw;
        $decoded = json_decode($raw, true);
        $json_error = json_last_error();
        if (!is_array($decoded) && trim($raw) !== '' && $json_error !== JSON_ERROR_NONE) {
            do_action(
                'rts_newsletter_subject_variants_invalid_json',
                (int) $post_id,
                $raw,
                $json_error,
                json_last_error_msg()
            );
        }
        $variants = array();
        if (is_array($decoded)) {
            foreach ($decoded as $value) {
                if (!is_string($value)) {
                    continue;
                }
                $clean = sanitize_text_field($value);
                if ($clean !== '') {
                    $variants[] = $clean;
                }
                if (count($variants) >= 3) {
                    break;
                }
            }
        }
        if (empty($variants)) {
            $variants[] = sanitize_text_field((string) $fallback);
        }
        return array_values(array_unique($variants));
    }

    /**
     * Deterministically choose variant per subscriber for consistent analytics.
     *
     * @param int   $subscriber_id
     * @param array $variants
     * @param string $fallback
     * @return string
     */
    private function choose_subject_variant_for_subscriber($subscriber_id, array $variants, $fallback) {
        if (empty($variants)) {
            return sanitize_text_field((string) $fallback);
        }
        $count = count($variants);
        $hash = crc32((string) $subscriber_id);
        $index = abs((int) $hash) % $count;
        return sanitize_text_field((string) ($variants[$index] ?? $fallback));
    }

    /**
     * Count total subscribers matching criteria for a specific newsletter.
     */
    private function count_total_subscribers($newsletter_id) {
        $args = array(
            'post_type'              => 'rts_subscriber',
            'post_status'            => 'publish',
            'fields'                 => 'ids',
            'no_found_rows'          => false,
            'posts_per_page'         => 1,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
            'meta_query'             => array(
                'relation' => 'AND',
                array('key' => '_rts_subscriber_status', 'value' => 'active', 'compare' => '='),
                array('key' => '_rts_pref_newsletters', 'value' => '1', 'compare' => '='),
                array('key' => '_rts_subscriber_verified', 'value' => '1', 'compare' => '=')
            ),
        );

        if (get_option('rts_email_reconsent_required')) {
            $args['meta_query'][] = array('key' => '_rts_subscriber_consent_confirmed', 'compare' => 'EXISTS');
        }

        $args = apply_filters('rts_newsletter_subscriber_query', $args, $newsletter_id);

        $query = new WP_Query($args);
        return $query->found_posts;
    }

    /**
     * Workflow labels map.
     *
     * @return array<string, string>
     */
    private function get_workflow_labels() {
        return array(
            'draft'     => 'Draft',
            'review'    => 'In Review',
            'approved'  => 'Approved',
            'scheduled' => 'Scheduled',
            'sent'      => 'Sent',
        );
    }

    /**
     * Return workflow status with safe default.
     */
    private function get_workflow_status($post_id) {
        $status = (string) get_post_meta($post_id, '_rts_newsletter_workflow_status', true);
        $labels = $this->get_workflow_labels();
        if (!isset($labels[$status])) {
            $status = 'draft';
        }
        return $status;
    }

    /**
     * Insert audit event row.
     */
    private function insert_newsletter_audit($post_id, $event_type, $message, array $context = array()) {
        global $wpdb;
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return;
        }

        $table = $wpdb->prefix . 'rts_newsletter_audit';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return;
        }

        $wpdb->insert($table, array(
            'newsletter_id' => $post_id,
            'actor_id'      => get_current_user_id(),
            'event_type'    => sanitize_key((string) $event_type),
            'message'       => sanitize_text_field((string) $message),
            'context'       => !empty($context) ? wp_json_encode($context) : null,
            'created_at'    => current_time('mysql', true),
        ), array('%d', '%d', '%s', '%s', '%s', '%s'));
    }

    /**
     * Save a version snapshot in version table.
     */
    private function insert_version_snapshot($post_id, WP_Post $post, $reason = 'manual_save') {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_newsletter_versions';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return;
        }

        $next_version = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COALESCE(MAX(version_no), 0) + 1 FROM {$table} WHERE newsletter_id = %d",
            (int) $post_id
        ));

        $wpdb->insert($table, array(
            'newsletter_id' => (int) $post_id,
            'version_no'    => max(1, $next_version),
            'title'         => (string) $post->post_title,
            'content'       => (string) $post->post_content,
            'builder_json'  => (string) get_post_meta($post_id, '_rts_nl_builder_blocks', true),
            'reason'        => sanitize_text_field((string) $reason),
            'created_by'    => get_current_user_id(),
            'created_at'    => current_time('mysql', true),
        ), array('%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s'));

        // Keep latest 40 versions.
        $prune_ids = (array) $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$table} WHERE newsletter_id = %d ORDER BY version_no DESC LIMIT 999 OFFSET 40",
            (int) $post_id
        ));
        if (!empty($prune_ids)) {
            $prune_ids = array_map('intval', $prune_ids);
            $wpdb->query("DELETE FROM {$table} WHERE id IN (" . implode(',', $prune_ids) . ")");
        }
    }

    /**
     * Fetch recent version rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function get_recent_versions($post_id, $limit = 6) {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_newsletter_versions';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return array();
        }

        $limit = max(1, min(20, (int) $limit));
        return (array) $wpdb->get_results($wpdb->prepare(
            "SELECT id, version_no, reason, created_at
             FROM {$table}
             WHERE newsletter_id = %d
             ORDER BY version_no DESC
             LIMIT %d",
            (int) $post_id,
            $limit
        ), ARRAY_A);
    }

    /**
     * Get newsletter analytics totals/rates.
     *
     * @return array<string, float|int>
     */
    private function get_newsletter_analytics_summary($post_id) {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_newsletter_analytics';
        $out = array(
            'sent'        => 0,
            'open'        => 0,
            'click'       => 0,
            'bounce'      => 0,
            'unsubscribe' => 0,
            'open_rate'   => 0.0,
            'click_rate'  => 0.0,
        );
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return $out;
        }

        $rows = (array) $wpdb->get_results($wpdb->prepare(
            "SELECT event_type, COUNT(*) AS qty
             FROM {$table}
             WHERE newsletter_id = %d
             GROUP BY event_type",
            (int) $post_id
        ), ARRAY_A);

        foreach ($rows as $row) {
            $event = sanitize_key((string) ($row['event_type'] ?? ''));
            if (array_key_exists($event, $out)) {
                $out[$event] = (int) ($row['qty'] ?? 0);
            }
        }

        $sent = max(1, (int) $out['sent']);
        $out['open_rate'] = round(((int) $out['open'] / $sent) * 100, 1);
        $out['click_rate'] = round(((int) $out['click'] / $sent) * 100, 1);
        return $out;
    }

    /**
     * Apply schedule metadata as cron events.
     */
    private function schedule_newsletter_send($post_id) {
        $post_id = (int) $post_id;
        $this->clear_scheduled_send($post_id);

        $mode = (string) get_post_meta($post_id, '_rts_newsletter_schedule_mode', true);
        if (!in_array($mode, array('scheduled', 'recurring'), true)) {
            return;
        }

        if ($mode === 'scheduled') {
            $date = (string) get_post_meta($post_id, '_rts_newsletter_send_date', true);
            $time = (string) get_post_meta($post_id, '_rts_newsletter_send_time', true);
            if ($date === '' || $time === '') {
                return;
            }
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
                return;
            }

            $tz = wp_timezone();
            $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i', $date . ' ' . $time, $tz);
            $date_errors = DateTimeImmutable::getLastErrors();
            if (!$dt) {
                return;
            }
            if ($date_errors !== false && (((int) $date_errors['warning_count']) > 0 || ((int) $date_errors['error_count']) > 0)) {
                return;
            }
            $ts = $dt->getTimestamp();
            if ($ts <= time()) {
                $ts = time() + 60;
            }
            wp_schedule_single_event($ts, 'rts_newsletter_scheduled_send', array($post_id, 'scheduled'));
            update_post_meta($post_id, '_rts_newsletter_workflow_status', 'scheduled');
            $this->insert_newsletter_audit($post_id, 'scheduled', 'Newsletter scheduled for one-time send.', array('timestamp' => gmdate('c', $ts)));
            return;
        }

        $next = $this->calculate_next_recurring_timestamp($post_id, time());
        if ($next > 0) {
            wp_schedule_single_event($next, 'rts_newsletter_scheduled_send', array($post_id, 'recurring'));
            update_post_meta($post_id, '_rts_newsletter_workflow_status', 'scheduled');
            update_post_meta($post_id, '_rts_newsletter_next_recurring_at', gmdate('Y-m-d H:i:s', $next));
            $this->insert_newsletter_audit($post_id, 'scheduled_recurring', 'Newsletter recurring schedule updated.', array('timestamp' => gmdate('c', $next)));
        }
    }

    /**
     * Remove pending scheduled newsletter run events.
     */
    private function clear_scheduled_send($post_id) {
        wp_clear_scheduled_hook('rts_newsletter_scheduled_send', array((int) $post_id, 'scheduled'));
        wp_clear_scheduled_hook('rts_newsletter_scheduled_send', array((int) $post_id, 'recurring'));
    }

    /**
     * Compute next recurring send timestamp.
     */
    private function calculate_next_recurring_timestamp($post_id, $from_ts) {
        $recurrence = (string) get_post_meta($post_id, '_rts_newsletter_recurrence', true);
        $time       = (string) get_post_meta($post_id, '_rts_newsletter_send_time', true);
        $weekday    = (int) get_post_meta($post_id, '_rts_newsletter_weekday', true);
        $monthday   = (int) get_post_meta($post_id, '_rts_newsletter_monthday', true);

        if (!in_array($recurrence, array('daily', 'weekly', 'monthly'), true)) {
            $recurrence = 'weekly';
        }
        if (!preg_match('/^\\d{2}:\\d{2}$/', $time)) {
            $time = '09:00';
        }
        if ($weekday < 0 || $weekday > 6) {
            $weekday = 1;
        }
        if ($monthday < 1 || $monthday > 28) {
            $monthday = 1;
        }

        $tz = wp_timezone();
        $base = new DateTime('@' . (int) $from_ts);
        $base->setTimezone($tz);
        list($hh, $mm) = array_map('intval', explode(':', $time));

        if ($recurrence === 'daily') {
            $base->setTime($hh, $mm, 0);
            if ($base->getTimestamp() <= $from_ts) {
                $base->modify('+1 day');
            }
            return $base->getTimestamp();
        }

        if ($recurrence === 'weekly') {
            $candidate = clone $base;
            $candidate->setTime($hh, $mm, 0);
            while ((int) $candidate->format('w') !== $weekday || $candidate->getTimestamp() <= $from_ts) {
                $candidate->modify('+1 day');
            }
            return $candidate->getTimestamp();
        }

        $candidate = clone $base;
        $candidate->setDate((int) $candidate->format('Y'), (int) $candidate->format('m'), $monthday);
        $candidate->setTime($hh, $mm, 0);
        if ($candidate->getTimestamp() <= $from_ts) {
            $candidate->modify('first day of next month');
            $candidate->setDate((int) $candidate->format('Y'), (int) $candidate->format('m'), $monthday);
            $candidate->setTime($hh, $mm, 0);
        }
        return $candidate->getTimestamp();
    }

    /**
     * Trigger queue send for scheduled/recurring jobs.
     */
    public function run_scheduled_send($post_id, $mode = 'scheduled') {
        $post_id = (int) $post_id;
        $post = get_post($post_id);
        if (!$post || $post->post_type !== self::POST_TYPE || $post->post_status !== 'publish') {
            return;
        }

        $workflow = $this->get_workflow_status($post_id);
        if (!in_array($workflow, array('approved', 'scheduled', 'sent'), true)) {
            $this->insert_newsletter_audit($post_id, 'scheduled_skip', 'Scheduled run skipped due to workflow status.', array('workflow' => $workflow));
            return;
        }

        $total = $this->count_total_subscribers($post_id);
        if ($total <= 0) {
            $this->insert_newsletter_audit($post_id, 'scheduled_skip', 'Scheduled run skipped (no eligible subscribers).');
        } else {
            update_post_meta($post_id, '_rts_newsletter_send_status', 'sending');
            update_post_meta($post_id, '_rts_newsletter_total_recipients', $total);
            update_post_meta($post_id, '_rts_newsletter_queued_count', 0);
            update_post_meta($post_id, '_rts_newsletter_workflow_status', 'scheduled');

            if (!wp_next_scheduled('rts_queue_newsletter_batch', array($post_id))) {
                wp_schedule_single_event(time(), 'rts_queue_newsletter_batch', array($post_id));
            }
            $this->insert_newsletter_audit($post_id, 'scheduled_run', 'Scheduled send queued.', array('mode' => sanitize_key((string) $mode), 'total' => $total));
        }

        if ($mode === 'recurring') {
            $next = $this->calculate_next_recurring_timestamp($post_id, time() + 60);
            if ($next > 0) {
                wp_schedule_single_event($next, 'rts_newsletter_scheduled_send', array($post_id, 'recurring'));
                update_post_meta($post_id, '_rts_newsletter_next_recurring_at', gmdate('Y-m-d H:i:s', $next));
            }
        }
    }

    /**
     * Keep scheduled hooks in sync when newsletter publish state changes.
     */
    public function handle_transition_post_status($new_status, $old_status, $post) {
        if (!($post instanceof WP_Post) || $post->post_type !== self::POST_TYPE) {
            return;
        }
        if ($new_status === 'publish') {
            $this->schedule_newsletter_send((int) $post->ID);
            return;
        }
        $this->clear_scheduled_send((int) $post->ID);
    }

    /**
     * Handle workflow action buttons.
     */
    public function handle_workflow_action() {
        $post_id = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        $action  = isset($_GET['workflow_action']) ? sanitize_key((string) $_GET['workflow_action']) : '';
        if ($post_id <= 0 || !current_user_can('edit_post', $post_id)) {
            wp_die('Unauthorized');
        }

        check_admin_referer('rts_newsletter_workflow_action_' . $post_id);

        $map = array(
            'submit_review' => 'review',
            'approve'       => 'approved',
            'send_back'     => 'draft',
            'mark_sent'     => 'sent',
        );
        if (isset($map[$action])) {
            update_post_meta($post_id, '_rts_newsletter_workflow_status', $map[$action]);
            update_post_meta($post_id, '_rts_newsletter_workflow_updated_at', current_time('mysql'));
            update_post_meta($post_id, '_rts_newsletter_workflow_updated_by', get_current_user_id());
            $this->insert_newsletter_audit($post_id, 'workflow_action', 'Workflow action applied.', array('action' => $action, 'status' => $map[$action]));
        }

        $redirect = add_query_arg(array(
            'post'           => $post_id,
            'action'         => 'edit',
            'rts_nl_notice'  => 'workflow_updated',
        ), admin_url('post.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Restore version snapshot into current newsletter draft.
     */
    public function handle_restore_version() {
        global $wpdb;
        $post_id    = isset($_GET['post_id']) ? absint($_GET['post_id']) : 0;
        $version_id = isset($_GET['version_id']) ? absint($_GET['version_id']) : 0;
        if ($post_id <= 0 || $version_id <= 0 || !current_user_can('edit_post', $post_id)) {
            wp_die('Unauthorized');
        }

        check_admin_referer('rts_newsletter_restore_version_' . $version_id);

        $table = $wpdb->prefix . 'rts_newsletter_versions';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            wp_die('Version table not available.');
        }

        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND newsletter_id = %d LIMIT 1",
            $version_id,
            $post_id
        ));
        if (!$row) {
            wp_die('Version not found.');
        }

        wp_update_post(array(
            'ID'           => $post_id,
            'post_title'   => (string) $row->title,
            'post_content' => (string) $row->content,
        ));
        if (!empty($row->builder_json)) {
            update_post_meta($post_id, '_rts_nl_builder_blocks', (string) $row->builder_json);
        }
        update_post_meta($post_id, '_rts_nl_builder_updated_at', current_time('mysql'));
        update_post_meta($post_id, '_rts_nl_builder_updated_by', get_current_user_id());

        $this->insert_newsletter_audit($post_id, 'version_restore', 'Restored newsletter version.', array('version_id' => $version_id));

        $redirect = add_query_arg(array(
            'post'          => $post_id,
            'action'        => 'edit',
            'rts_nl_notice' => 'version_restored',
        ), admin_url('post.php'));
        wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Add custom list table columns.
     */
    public function filter_newsletter_columns($columns) {
        $out = array();
        foreach ($columns as $key => $label) {
            $out[$key] = $label;
            if ($key === 'title') {
                $out['rts_workflow'] = 'Workflow';
                $out['rts_schedule'] = 'Schedule';
                $out['rts_recipients'] = 'Recipients';
                $out['rts_engagement'] = 'Engagement';
            }
        }
        return $out;
    }

    /**
     * Render custom newsletter columns.
     */
    public function render_newsletter_column($column, $post_id) {
        if ($column === 'rts_workflow') {
            $status = $this->get_workflow_status($post_id);
            $labels = $this->get_workflow_labels();
            echo '<span class="rts-badge">' . esc_html($labels[$status] ?? ucfirst($status)) . '</span>';
            return;
        }
        if ($column === 'rts_schedule') {
            $mode = (string) get_post_meta($post_id, '_rts_newsletter_schedule_mode', true);
            if ($mode === 'recurring') {
                $next = (string) get_post_meta($post_id, '_rts_newsletter_next_recurring_at', true);
                echo 'Recurring';
                echo $next ? '<br><span class="description">Next: ' . esc_html($next) . '</span>' : '';
                return;
            }
            $next = wp_next_scheduled('rts_newsletter_scheduled_send', array((int) $post_id, 'scheduled'));
            if ($next) {
                echo 'Scheduled';
                echo '<br><span class="description">' . esc_html(date_i18n('Y-m-d H:i', $next)) . '</span>';
                return;
            }
            echo 'Manual';
            return;
        }
        if ($column === 'rts_recipients') {
            $total = (int) get_post_meta($post_id, '_rts_newsletter_total_recipients', true);
            echo number_format_i18n($total);
            return;
        }
        if ($column === 'rts_engagement') {
            $stats = $this->get_newsletter_analytics_summary($post_id);
            echo 'Open: ' . esc_html(number_format_i18n((float) $stats['open_rate'], 1)) . '%';
            echo '<br>Click: ' . esc_html(number_format_i18n((float) $stats['click_rate'], 1)) . '%';
        }
    }

    /**
     * Render list filters on newsletter list table.
     */
    public function render_admin_filters() {
        global $typenow;
        if ($typenow !== self::POST_TYPE) {
            return;
        }
        $current_stage = isset($_GET['rts_workflow']) ? sanitize_key((string) $_GET['rts_workflow']) : '';
        $current_mode  = isset($_GET['rts_mode']) ? sanitize_key((string) $_GET['rts_mode']) : '';
        ?>
        <select name="rts_workflow">
            <option value="">All workflow stages</option>
            <?php foreach ($this->get_workflow_labels() as $key => $label) : ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected($current_stage, $key); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <select name="rts_mode">
            <option value="">All schedule modes</option>
            <option value="immediate" <?php selected($current_mode, 'immediate'); ?>>Manual</option>
            <option value="scheduled" <?php selected($current_mode, 'scheduled'); ?>>Scheduled</option>
            <option value="recurring" <?php selected($current_mode, 'recurring'); ?>>Recurring</option>
        </select>
        <?php
    }

    /**
     * Apply newsletter list table filters.
     */
    public function apply_admin_filters($query) {
        if (!is_admin() || !$query instanceof WP_Query || !$query->is_main_query()) {
            return;
        }
        global $pagenow;
        if ($pagenow !== 'edit.php' || $query->get('post_type') !== self::POST_TYPE) {
            return;
        }

        $meta_query = (array) $query->get('meta_query');
        $labels     = $this->get_workflow_labels();

        $stage = isset($_GET['rts_workflow']) ? sanitize_key((string) $_GET['rts_workflow']) : '';
        if ($stage !== '' && isset($labels[$stage])) {
            $meta_query[] = array(
                'key'     => '_rts_newsletter_workflow_status',
                'value'   => $stage,
                'compare' => '=',
            );
        }

        $mode = isset($_GET['rts_mode']) ? sanitize_key((string) $_GET['rts_mode']) : '';
        if (in_array($mode, array('immediate', 'scheduled', 'recurring'), true)) {
            $meta_query[] = array(
                'key'     => '_rts_newsletter_schedule_mode',
                'value'   => $mode,
                'compare' => '=',
            );
        }

        if (!empty($meta_query)) {
            $query->set('meta_query', $meta_query);
        }
    }

    /**
     * Admin notices for workflow/version operations.
     */
    public function render_admin_notices() {
        if (!is_admin()) {
            return;
        }
        $notice = isset($_GET['rts_nl_notice']) ? sanitize_key((string) $_GET['rts_nl_notice']) : '';
        if ($notice === '') {
            return;
        }
        $messages = array(
            'workflow_updated' => 'Newsletter workflow updated.',
            'version_restored' => 'Newsletter version restored successfully.',
        );
        if (!isset($messages[$notice])) {
            return;
        }
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($messages[$notice]) . '</p></div>';
    }
}
