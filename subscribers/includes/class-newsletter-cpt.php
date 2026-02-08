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
        add_action('init', array($this, 'register_post_type'));
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post_' . self::POST_TYPE, array($this, 'save_meta'), 10, 2);
        add_action('wp_ajax_rts_newsletter_test_send', array($this, 'ajax_test_send'));
        add_action('wp_ajax_rts_newsletter_send_all', array($this, 'ajax_send_all'));
        add_action('wp_ajax_rts_newsletter_progress', array($this, 'ajax_get_progress'));
        add_action('wp_ajax_rts_insert_random_letter', array($this, 'ajax_insert_random_letter'));
        add_action('rts_queue_newsletter_batch', array($this, 'process_queued_batch'));
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
            'capability_type'    => 'post',
            'capabilities'       => array(
                'edit_post'          => 'manage_options',
                'read_post'          => 'manage_options',
                'delete_post'        => 'manage_options',
                'edit_posts'         => 'manage_options',
                'edit_others_posts'  => 'manage_options',
                'publish_posts'      => 'manage_options',
                'read_private_posts' => 'manage_options',
            ),
            'supports'           => array('title', 'editor'),
            'menu_icon'          => 'dashicons-megaphone',
            'has_archive'        => false,
            'publicly_queryable' => false,
            'rewrite'            => false,
        ));
    }

    /**
     * Enqueue CSS/JS on newsletter screens.
     */
    public function enqueue_admin_assets($hook) {
        global $post_type;
        if ($post_type !== self::POST_TYPE) {
            return;
        }

        wp_enqueue_style(
            'rts-subscriber-admin',
            get_stylesheet_directory_uri() . '/subscribers/assets/css/admin.css',
            array(),
            filemtime(get_stylesheet_directory() . '/subscribers/assets/css/admin.css')
        );

        wp_register_script('rts-newsletter-admin', false, array('jquery'), null, true);
        wp_enqueue_script('rts-newsletter-admin');

        wp_localize_script('rts-newsletter-admin', 'rtsNL', array(
            'ajax_url'    => admin_url('admin-ajax.php'),
            'nonce'       => wp_create_nonce('rts_newsletter_action'),
            'admin_email' => get_option('admin_email'),
        ));

        wp_add_inline_script('rts-newsletter-admin', $this->get_inline_js());
    }

    /**
     * Inline JS for sidebar buttons.
     */
    private function get_inline_js() {
        return "
        jQuery(function($) {
            // Insert random letter
            $(document).on('click', '#rts-insert-letter', function(e) {
                e.preventDefault();
                var btn = $(this);
                btn.prop('disabled', true).text('Fetching...');
                $.post(rtsNL.ajax_url, {
                    action: 'rts_insert_random_letter',
                    nonce: rtsNL.nonce
                }, function(resp) {
                    btn.prop('disabled', false).text('Insert Random Letter');
                    if(resp.success && resp.data.content) {
                        if(typeof tinymce !== 'undefined' && tinymce.get('content')) {
                            tinymce.get('content').execCommand('mceInsertContent', false, resp.data.content);
                        } else {
                            var ta = document.getElementById('content');
                            if(ta) ta.value += resp.data.content;
                        }
                    } else {
                        alert(resp.data && resp.data.message ? resp.data.message : 'No letters available.');
                    }
                });
            });

            // Insert social icons
            $(document).on('click', '#rts-insert-socials', function(e) {
                e.preventDefault();
                var html = '<p style=\"text-align:center;\">' +
                    '<a href=\"https://www.facebook.com/ben.west.56884\">Facebook</a> | ' +
                    '<a href=\"https://www.instagram.com/iambenwest/\">Instagram</a> | ' +
                    '<a href=\"https://www.linkedin.com/in/benwest2/\">LinkedIn</a> | ' +
                    '<a href=\"https://linktr.ee/iambenwest\">Linktree</a>' +
                    '</p>';
                if(typeof tinymce !== 'undefined' && tinymce.get('content')) {
                    tinymce.get('content').execCommand('mceInsertContent', false, html);
                } else {
                    var ta = document.getElementById('content');
                    if(ta) ta.value += html;
                }
            });

            // Test send
            $(document).on('click', '#rts-test-send-btn', function(e) {
                e.preventDefault();
                var email = $('#rts-test-email').val() || rtsNL.admin_email;
                var postId = $('#post_ID').val();
                var btn = $(this);
                var msg = $('#rts-test-result');

                btn.prop('disabled', true).text('Sending...');
                msg.html('');

                $.post(rtsNL.ajax_url, {
                    action: 'rts_newsletter_test_send',
                    nonce: rtsNL.nonce,
                    post_id: postId,
                    email: email
                }, function(resp) {
                    btn.prop('disabled', false).text('Send Test');
                    if(resp.success) {
                        msg.html('<div class=\"rts-nl-note rts-nl-note--success\">Test sent to ' + email + '</div>');
                    } else {
                        msg.html('<div class=\"rts-nl-note rts-nl-note--danger\">' + (resp.data.message || 'Send failed.') + '</div>');
                    }
                });
            });

            // Send to all
            $(document).on('click', '#rts-send-all-btn', function(e) {
                e.preventDefault();
                if(!confirm('Send this newsletter to ALL active subscribers?')) return;
                var postId = $('#post_ID').val();
                var btn = $(this);
                var prog = $('#rts-send-progress');

                btn.prop('disabled', true);
                prog.html('<div class=\"rts-nl-note rts-nl-note--warn\">Queuing emails...</div>');

                $.post(rtsNL.ajax_url, {
                    action: 'rts_newsletter_send_all',
                    nonce: rtsNL.nonce,
                    post_id: postId
                }, function(resp) {
                    if(resp.success) {
                        prog.html('<div class=\"rts-nl-note rts-nl-note--success\">Queued ' + resp.data.total + ' emails for delivery.</div>');
                        rtsNL_pollProgress(postId);
                    } else {
                        btn.prop('disabled', false);
                        prog.html('<div class=\"rts-nl-note rts-nl-note--danger\">' + (resp.data.message || 'Queue failed.') + '</div>');
                    }
                });
            });

            function rtsNL_pollProgress(postId) {
                $.post(rtsNL.ajax_url, {
                    action: 'rts_newsletter_progress',
                    nonce: rtsNL.nonce,
                    post_id: postId
                }, function(resp) {
                    if(!resp.success) return;
                    var d = resp.data;
                    var pct = d.percent || 0;
                    var html = '<div style=\"margin-bottom:10px;\">' +
                        '<div style=\"height:8px;background:#020617;border:1px solid #334155;border-radius:999px;overflow:hidden;\">' +
                        '<div style=\"height:100%;width:' + pct + '%;background:#FCA311;transition:width 0.3s;\"></div></div></div>' +
                        '<span style=\"font-size:12px;color:#94a3b8;\">' + pct + '% complete (' + d.queued + '/' + d.total + ')</span>';
                    $('#rts-send-progress').html(html);
                    if(pct < 100) setTimeout(function(){ rtsNL_pollProgress(postId); }, 3000);
                    else {
                        $('#rts-send-all-btn').prop('disabled', false);
                        $('#rts-send-progress').html('<div class=\"rts-nl-note rts-nl-note--success\">All emails delivered.</div>');
                    }
                });
            }
        });
        ";
    }

    /**
     * Add metaboxes: Schedule, Helpers, Test, Send.
     */
    public function add_meta_boxes() {
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
    }

    /**
     * Render the Schedule metabox.
     */
    public function render_schedule_metabox($post) {
        wp_nonce_field('rts_newsletter_meta', '_rts_newsletter_nonce');
        $date = get_post_meta($post->ID, '_rts_newsletter_send_date', true);
        $time = get_post_meta($post->ID, '_rts_newsletter_send_time', true);
        ?>
        <div class="rts-nl-card">
            <span class="rts-nl-card__title">Send Date</span>
            <div class="rts-nl-field">
                <label class="rts-nl-label">Date</label>
                <input type="date" name="_rts_newsletter_send_date" value="<?php echo esc_attr($date); ?>" class="rts-nl-input">
            </div>
            <div class="rts-nl-field">
                <label class="rts-nl-label">Time (24h)</label>
                <input type="time" name="_rts_newsletter_send_time" value="<?php echo esc_attr($time ?: '09:00'); ?>" class="rts-nl-input">
            </div>
            <p class="rts-nl-help">Leave blank to send immediately when published.</p>
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
        if (!isset($_POST['_rts_newsletter_nonce'])) return;
        if (!wp_verify_nonce($_POST['_rts_newsletter_nonce'], 'rts_newsletter_meta')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('manage_options')) return;

        if (isset($_POST['_rts_newsletter_send_date'])) {
            update_post_meta($post_id, '_rts_newsletter_send_date', sanitize_text_field($_POST['_rts_newsletter_send_date']));
        }
        if (isset($_POST['_rts_newsletter_send_time'])) {
            update_post_meta($post_id, '_rts_newsletter_send_time', sanitize_text_field($_POST['_rts_newsletter_send_time']));
        }
    }

    /**
     * AJAX: Send test email.
     */
    public function ajax_test_send() {
        check_ajax_referer('rts_newsletter_action', 'nonce');
        if (!current_user_can('manage_options')) {
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
        $body    = wpautop($post->post_content);

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
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $post_id = absint($_POST['post_id'] ?? 0);
        if (!$post_id) {
            wp_send_json_error(array('message' => 'Missing post ID'));
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

        wp_send_json_success(array('total' => $total, 'message' => "Queued for $total subscribers"));
    }

    /**
     * AJAX: Get send progress.
     */
    public function ajax_get_progress() {
        check_ajax_referer('rts_newsletter_action', 'nonce');

        $post_id = absint($_POST['post_id'] ?? 0);
        $progress = $this->get_send_progress($post_id);
        wp_send_json_success($progress);
    }

    /**
     * AJAX: Insert random letter content.
     */
    public function ajax_insert_random_letter() {
        check_ajax_referer('rts_newsletter_action', 'nonce');
        if (!current_user_can('manage_options')) {
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
                    'value'   => 'true',
                    'compare' => '=',
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

        $offset = (int) get_post_meta($post_id, '_rts_newsletter_queued_count', true);

        $subscribers = get_posts(array(
            'post_type'      => 'rts_subscriber',
            'post_status'    => 'publish',
            'posts_per_page' => self::BATCH_SIZE,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'fields'         => 'ids',
            'meta_query'     => array(
                'relation' => 'AND',
                array('key' => '_rts_subscriber_status', 'value' => 'active', 'compare' => '='),
                array('key' => '_rts_pref_newsletters', 'value' => '1', 'compare' => '='),
                array('key' => '_rts_subscriber_verified', 'value' => '1', 'compare' => '='),
            ),
        ));

        if (empty($subscribers)) {
            update_post_meta($post_id, '_rts_newsletter_send_status', 'sent');
            update_post_meta($post_id, '_rts_newsletter_sent_at', current_time('mysql'));
            return;
        }

        global $wpdb;
        $queue_table = $wpdb->prefix . 'rts_email_queue';
        $now_gmt     = gmdate('Y-m-d H:i:s');

        foreach ($subscribers as $subscriber_id) {
            $email = get_post_meta($subscriber_id, '_rts_email', true);
            if (!$email || !is_email($email)) {
                $email = get_the_title($subscriber_id);
            }
            if (!$email || !is_email($email)) {
                continue;
            }

            $wpdb->insert($queue_table, array(
                'subscriber_id' => $subscriber_id,
                'email'         => $email,
                'template'      => 'newsletter',
                'newsletter_id' => $post_id,
                'subject'       => $post->post_title,
                'body'          => wpautop($post->post_content),
                'status'        => 'pending',
                'scheduled_at'  => $now_gmt,
                'created_at'    => $now_gmt,
            ));
        }

        $new_offset = $offset + count($subscribers);
        update_post_meta($post_id, '_rts_newsletter_queued_count', $new_offset);

        if (count($subscribers) >= self::BATCH_SIZE) {
            wp_schedule_single_event(time() + 5, 'rts_queue_newsletter_batch', array($post_id));
        } else {
            update_post_meta($post_id, '_rts_newsletter_send_status', 'sent');
            update_post_meta($post_id, '_rts_newsletter_sent_at', current_time('mysql'));
        }
    }

    /**
     * Get send progress for a newsletter.
     */
    private function get_send_progress($post_id) {
        $total  = (int) get_post_meta($post_id, '_rts_newsletter_total_recipients', true);
        $queued = (int) get_post_meta($post_id, '_rts_newsletter_queued_count', true);

        $remaining_batches = $total > 0 ? max(0, ceil(($total - $queued) / self::BATCH_SIZE)) : 0;

        return array(
            'percent' => $total > 0 ? round(($queued / $total) * 100) : 0,
            'queued' => $queued,
            'total' => $total,
            'remaining_batches' => $remaining_batches,
            'estimated_time' => $remaining_batches * 5
        );
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
}
