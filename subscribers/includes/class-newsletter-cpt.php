<?php
/**
 * RTS Newsletter CPT Management
 *
 * Handles newsletter post type, queueing, batch sending, and test previews.
 *
 * @package    RTS_Subscriber_System
 * @subpackage Newsletter
 * @version    1.0.5
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_Newsletter_CPT {

    /**
     * @var RTS_Email_Queue|null
     */
    private $email_queue;

    /**
     * @var RTS_Email_Templates|null
     */
    private $email_templates;

    private $default_batch_size = 200;

    public function __construct() {
        add_action('init', array($this, 'register_cpt'));
        add_action('add_meta_boxes', array($this, 'add_metaboxes'));
        add_action('save_post_rts_newsletter', array($this, 'save_newsletter_meta'));
        
        // Admin actions
        add_action('admin_post_rts_queue_newsletter_send', array($this, 'handle_queue_send'));
        add_action('admin_post_rts_test_newsletter_send', array($this, 'handle_test_send'));
        add_action('admin_post_rts_stop_newsletter_send', array($this, 'handle_stop_send'));
        
        // Background processing hook (WP-Cron)
        // Args: newsletter_id, offset, process_token
        add_action('rts_queue_newsletter_batch', array($this, 'process_queue_batch'), 10, 3);
    }

    /**
     * Load required classes if not already available.
     * @return bool True if dependencies loaded successfully.
     */
    private function load_dependencies() {
        if ($this->email_queue && $this->email_templates) {
            return true;
        }

        // Attempt to load files if classes don't exist (assuming standard path structure)
        if (!class_exists('RTS_Email_Queue')) {
            if (file_exists(plugin_dir_path(__FILE__) . 'class-email-queue.php')) {
                require_once plugin_dir_path(__FILE__) . 'class-email-queue.php';
            }
        }

        if (!class_exists('RTS_Email_Templates')) {
            if (file_exists(plugin_dir_path(__FILE__) . 'class-email-templates.php')) {
                require_once plugin_dir_path(__FILE__) . 'class-email-templates.php';
            }
        }

        if (class_exists('RTS_Email_Queue') && class_exists('RTS_Email_Templates')) {
            $this->email_queue = new RTS_Email_Queue();
            $this->email_templates = new RTS_Email_Templates();
            return true;
        }

        return false;
    }

    public function register_cpt() {
        $labels = array(
            'name'               => 'Newsletters',
            'singular_name'      => 'Newsletter',
            'add_new'            => 'Add Newsletter',
            'add_new_item'       => 'Add New Newsletter',
            'edit_item'          => 'Edit Newsletter',
            'new_item'           => 'New Newsletter',
            'view_item'          => 'View Newsletter',
            'search_items'       => 'Search Newsletters',
            'not_found'          => 'No newsletters found',
            'not_found_in_trash' => 'No newsletters found in Trash',
            'menu_name'          => 'Newsletters',
        );

        register_post_type('rts_newsletter', array(
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => 'edit.php?post_type=rts_subscriber', // Nested under Subscribers
            'capability_type'    => 'post',
            'capabilities'       => array(
                'edit_post'          => 'manage_options',
                'read_post'          => 'manage_options',
                'delete_post'        => 'manage_options',
                'edit_posts'         => 'manage_options',
                'edit_others_posts'  => 'manage_options',
                'publish_posts'      => 'manage_options',
                'read_private_posts' => 'manage_options',
                'delete_posts'       => 'manage_options',
            ),
            'supports'           => array('title', 'editor', 'revisions', 'author'),
            'menu_position'      => 26,
        ));
    }

    public function add_metaboxes() {
        add_meta_box(
            'rts_newsletter_send_box',
            'Newsletter Actions',
            array($this, 'render_send_metabox'),
            'rts_newsletter',
            'side',
            'high'
        );

        add_meta_box(
            'rts_newsletter_help_box',
            'Writing tips',
            array($this, 'render_help_metabox'),
            'rts_newsletter',
            'side',
            'default'
        );

        add_meta_box(
            'rts_newsletter_variants',
            'Content Variants (A/B Testing)',
            array($this, 'render_variants_metabox'),
            'rts_newsletter',
            'normal',
            'high'
        );
    }

    /**
     * Help + starter guidance for writing newsletters.
     * Kept short, scannable, and safe for non-technical admins.
     */
    public function render_help_metabox($post) {
        echo '<div class="rts-nl-help">';
        echo '<p><strong>Subject</strong> comes from the newsletter title.</p>';
        echo '<p><strong>Body</strong> is whatever you write in the main editor. If you switch to Text/HTML mode, you can paste HTML too.</p>';
        echo '<p><strong>Links</strong> are fine. Buttons work best as a normal link styled in your template.</p>';
        echo '<hr class="rts-nl-sep">';
        echo '<p class="rts-nl-section-title"><strong>Handy tokens</strong></p>';
        echo '<p class="rts-nl-help">These are added automatically in the footer:</p>';
        echo '<code style="display:block;white-space:pre-wrap;word-break:break-word;">{manage_url}\n{unsubscribe_url}</code>';
        echo '<hr class="rts-nl-sep">';
        echo '<p class="rts-nl-section-title"><strong>Starter HTML</strong></p>';
        echo '<p class="rts-nl-help">Paste into Text/HTML mode if you want a quick structure:</p>';
        echo '<code style="display:block;white-space:pre-wrap;word-break:break-word;">&lt;h2&gt;Hello&lt;/h2&gt;\n&lt;p&gt;A short intro goes here.&lt;/p&gt;\n&lt;p&gt;&lt;a href=\"https://example.com\"&gt;Read more&lt;/a&gt;&lt;/p&gt;</code>';
        echo '</div>';
    }

    public function render_variants_metabox($post) {
        $variant_b = get_post_meta($post->ID, '_rts_variant_b', true);
        wp_nonce_field('rts_save_newsletter_meta', 'rts_newsletter_meta_nonce');
        ?>
        <p class="description">Define alternate content for A/B testing. The system will randomly split recipients between the main content (Variant A) and this content (Variant B).</p>
        <label for="rts_variant_b"><strong>Variant B Content:</strong></label>
        <?php
        wp_editor($variant_b, 'rts_variant_b', array(
            'textarea_name' => 'rts_variant_b',
            'media_buttons' => true,
            'textarea_rows' => 10,
            'teeny'         => true,
        ));
    }

    public function save_newsletter_meta($post_id) {
        if (!isset($_POST['rts_newsletter_meta_nonce']) || !wp_verify_nonce($_POST['rts_newsletter_meta_nonce'], 'rts_save_newsletter_meta')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        if (isset($_POST['rts_variant_b'])) {
            update_post_meta($post_id, '_rts_variant_b', wp_kses_post($_POST['rts_variant_b']));
        }
    }

    public function render_send_metabox($post) {
        if (!current_user_can('manage_options')) {
            echo '<p>You do not have permission.</p>';
            return;
        }

        $status       = get_post_meta($post->ID, '_rts_newsletter_status', true);
        $queued_at    = get_post_meta($post->ID, '_rts_newsletter_queued_at', true);
        $sent_at      = get_post_meta($post->ID, '_rts_newsletter_sent_at', true);
        $queued_count = get_post_meta($post->ID, '_rts_newsletter_queued_count', true);
        $current_user = wp_get_current_user();

        // --- Status Display ---
        if ($status === 'sending') {
            echo '<div class="rts-nl-note rts-nl-note--warn">';
            echo '<strong><span class="dashicons dashicons-hourglass" aria-hidden="true"></span> Sending in progress...</strong><br>';
            echo '<span class="rts-nl-note__sub">Queued so far: <strong>' . intval($queued_count) . '</strong></span>';
            
            // Show estimates if we can calculate them
            $progress = $this->get_send_progress($post->ID);
            if ($progress['total'] > 0) {
                echo '<div class="rts-nl-note__meta">';
                echo 'Progress: ' . esc_html($progress['percent']) . '%<br>';
                echo 'Est. Time: ' . human_time_diff(0, $progress['estimated_time']);
                echo '</div>';
            }
            
            echo '</div>';
            
            // Stop Button
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            echo '<input type="hidden" name="action" value="rts_stop_newsletter_send">';
            echo '<input type="hidden" name="newsletter_id" value="' . intval($post->ID) . '">';
            wp_nonce_field('rts_stop_newsletter_send_' . $post->ID);
            submit_button('Stop Sending', 'secondary', 'submit', false, array('onclick' => "return confirm('Are you sure? This will stop adding new emails to the queue.');"));
            echo '</form>';
            return; // Don't show other controls while sending
        } 
        
        if ($status === 'sent') {
            echo '<div class="rts-nl-note rts-nl-note--success">';
            echo '<strong><span class="dashicons dashicons-yes" aria-hidden="true"></span> Sent!</strong><br>';
            echo 'Completed: ' . esc_html($sent_at) . '<br>';
            echo 'Total Queued: ' . intval($queued_count);
            echo '</div>';
            
            $stats = $this->get_newsletter_stats($post->ID);
            if ($stats['sent'] > 0) {
                echo '<div class="rts-nl-note rts-nl-note--neutral">';
                echo '<strong>Quick stats</strong><br>';
                echo 'Sent: ' . intval($stats['sent']) . '<br>';
                echo 'Unique opens: ' . intval($stats['opens']);
                echo '</div>';
            }
        } elseif ($status === 'scheduled') {
             echo '<div class="rts-nl-note rts-nl-note--info">';
             echo '<strong><span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span> Scheduled</strong><br>';
             echo 'Will send at: ' . esc_html(get_post_meta($post->ID, '_rts_scheduled_time', true));
             echo '</div>';
             
             // Allow stopping a scheduled send
             echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
             echo '<input type="hidden" name="action" value="rts_stop_newsletter_send">';
             echo '<input type="hidden" name="newsletter_id" value="' . intval($post->ID) . '">';
             wp_nonce_field('rts_stop_newsletter_send_' . $post->ID);
             submit_button('Cancel Schedule', 'secondary', 'submit', false);
             echo '</form>';
             return;
        }

        // --- Test Send Section ---
        echo '<div class="rts-nl-card">';
        echo '<strong class="rts-nl-card__title">Send a test</strong>';
        echo '<p class="rts-nl-help">This sends a preview to your email address only. It won\'t send to subscribers.</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="rts_test_newsletter_send">';
        echo '<input type="hidden" name="newsletter_id" value="' . intval($post->ID) . '">';
        wp_nonce_field('rts_test_newsletter_send_' . $post->ID);
        
        echo '<label for="test_email" class="screen-reader-text">Test email</label>';
        echo '<input type="email" id="test_email" name="test_email" value="' . esc_attr($current_user->user_email) . '" class="rts-nl-input" placeholder="name@example.com" required>';
        submit_button('Send test', 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';

        // --- Bulk Send Section ---
        echo '<hr class="rts-nl-sep">';
        echo '<p class="rts-nl-section-title"><strong>Queue or schedule</strong></p>';
        echo '<p class="rts-nl-help">Queues email for all active, verified subscribers who opted into project updates.</p>';

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="rts_queue_newsletter_send">';
        echo '<input type="hidden" name="newsletter_id" value="' . intval($post->ID) . '">';
        wp_nonce_field('rts_queue_newsletter_send_' . $post->ID);
        
        if (get_option('rts_email_demo_mode')) {
            echo '<div class="rts-nl-note rts-nl-note--danger"><strong>Demo mode enabled</strong><br>Emails will be queued but marked as “Cancelled” instead of sending.</div>';
        }

        echo '<div class="rts-nl-field">';
        echo '<label for="rts_schedule_time" class="rts-nl-label">Schedule for later (optional)</label>';
        echo '<input type="datetime-local" id="rts_schedule_time" name="rts_schedule_time" class="rts-nl-input">';
        echo '<p class="rts-nl-help">Leave blank to queue immediately.</p>';
        echo '</div>';

        $btn_attrs = array('onclick' => "return confirm('Ready to send to ALL subscribers?');");
        if (!$this->can_send_newsletter($post->ID)) {
            $btn_attrs['disabled'] = 'disabled';
        }

        submit_button('Queue / schedule send', 'primary', 'submit', false, $btn_attrs);
        echo '</form>';

        // --- Preview ---
        $preview = $this->render_admin_preview($post->ID);
        if ($preview) {
            echo '<hr class="rts-nl-sep">';
            echo '<p class="rts-nl-section-title"><strong>Preview</strong></p>';
            echo '<p class="rts-nl-help">This is a safe preview of how the email will render using your current template settings.</p>';
            echo '<div class="rts-nl-preview" aria-label="Newsletter preview">';
            echo '<iframe class="rts-nl-preview__frame" title="Newsletter preview" sandbox="allow-same-origin" srcdoc="' . esc_attr($preview) . '"></iframe>';
            echo '</div>';
        }
    }

    /**
     * Render a safe admin preview of the newsletter using the email templates wrapper.
     *
     * @param int $newsletter_id
     * @return string
     */
    private function render_admin_preview($newsletter_id) {
        if (!$this->load_dependencies() || !class_exists('RTS_Email_Templates')) {
            return '';
        }

        $sample_subscriber = get_posts(array(
            'post_type'      => 'rts_subscriber',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ));
        $subscriber_id = !empty($sample_subscriber) ? (int) $sample_subscriber[0] : 0;
        if (!$subscriber_id) {
            return '';
        }

        $subject     = get_the_title($newsletter_id);
        $raw_content = $this->get_variant_content($newsletter_id, 'a');
        $body_html   = wp_kses_post(apply_filters('the_content', $raw_content));

        $templates = new RTS_Email_Templates();
        $rendered  = $templates->render('newsletter_custom', $subscriber_id, array(), array(
            'newsletter_subject' => $subject,
            'newsletter_body'    => $body_html,
        ));

        return is_array($rendered) && !empty($rendered['body']) ? (string) $rendered['body'] : '';
    }

    /**
     * Handle sending a test email (immediate, no queue).
     */
    public function handle_test_send() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $newsletter_id = isset($_POST['newsletter_id']) ? intval($_POST['newsletter_id']) : 0;
        check_admin_referer('rts_test_newsletter_send_' . $newsletter_id);

        if (!$newsletter_id || get_post_type($newsletter_id) !== 'rts_newsletter') {
            wp_die('Invalid newsletter.');
        }

        $test_email = isset($_POST['test_email']) ? sanitize_email($_POST['test_email']) : '';
        if (!is_email($test_email)) {
            wp_die('Invalid email address.');
        }

        if (!$this->load_dependencies()) {
            wp_die('Could not load email dependencies.');
        }

        // Get a sample subscriber ID to generate valid context (tokens, manage links)
        $sample_subscriber = get_posts(array(
            'post_type'      => 'rts_subscriber',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids',
        ));
        $subscriber_id = !empty($sample_subscriber) ? $sample_subscriber[0] : 0;

        // Prepare content (test Variant A by default, could toggle)
        $subject = get_the_title($newsletter_id);
        $raw_content = $this->get_variant_content($newsletter_id, 'a');
        $body_content = apply_filters('the_content', $raw_content);
        $body_content = wp_kses_post($body_content);

        // Render template using sample subscriber context
        $rendered = $this->email_templates->render(
            'newsletter_custom', 
            $subscriber_id, 
            array(), 
            array(
                'newsletter_subject' => '[TEST] ' . $subject,
                'newsletter_body'    => $body_content,
            )
        );

        // If no subscriber was found, append a warning note
        if (!$subscriber_id) {
            $rendered['body'] .= '<div style="background:#ffd; padding:10px; border:1px solid #ee5; margin-top:20px; font-size:12px; color:#333;"><strong>Note:</strong> No active subscribers found. Placeholder links (Manage/Unsubscribe) may not function in this test.</div>';
        }

        // Send immediately via WP Mail
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $sent = wp_mail($test_email, $rendered['subject'], $rendered['body'], $headers);

        $redirect_args = array('message' => $sent ? 'test_sent' : 'test_failed');
        wp_safe_redirect(add_query_arg($redirect_args, get_edit_post_link($newsletter_id, 'raw')));
        exit;
    }

    /**
     * Handle stopping a newsletter send in progress.
     */
    public function handle_stop_send() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $newsletter_id = isset($_POST['newsletter_id']) ? intval($_POST['newsletter_id']) : 0;
        check_admin_referer('rts_stop_newsletter_send_' . $newsletter_id);

        $process_token = get_post_meta($newsletter_id, '_rts_newsletter_process_token', true);
        $next_offset   = get_post_meta($newsletter_id, '_rts_newsletter_next_offset', true);

        // Kill the process token - this causes the background batch to fail validation if it runs.
        delete_post_meta($newsletter_id, '_rts_newsletter_process_token');
        delete_post_meta($newsletter_id, '_rts_newsletter_next_offset');
        delete_post_meta($newsletter_id, '_rts_scheduled_time');
        update_post_meta($newsletter_id, '_rts_newsletter_status', 'stopped');

        // Cleanup scheduled event if we know the args
        if ($process_token !== '' && $next_offset !== '') {
            wp_clear_scheduled_hook('rts_queue_newsletter_batch', array($newsletter_id, intval($next_offset), $process_token));
        }
        
        // Also clean up potential future schedule hooks which use 0 offset
        wp_clear_scheduled_hook('rts_queue_newsletter_batch', array($newsletter_id, 0, $process_token));

        wp_safe_redirect(add_query_arg('message', 'stopped', get_edit_post_link($newsletter_id, 'raw')));
        exit;
    }

    /**
     * Handle the admin action to queue a newsletter.
     */
    public function handle_queue_send() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $newsletter_id = isset($_POST['newsletter_id']) ? intval($_POST['newsletter_id']) : 0;
        
        check_admin_referer('rts_queue_newsletter_send_' . $newsletter_id);

        if (!$newsletter_id || get_post_type($newsletter_id) !== 'rts_newsletter') {
            wp_die('Invalid newsletter.');
        }

        if (!$this->can_send_newsletter($newsletter_id)) {
            wp_die('This newsletter is already sending or sent.');
        }

        // Check for schedule
        if (!empty($_POST['rts_schedule_time'])) {
            $schedule_ts = strtotime($_POST['rts_schedule_time']);
            if ($schedule_ts && $schedule_ts > time()) {
                $this->schedule_send($newsletter_id, $schedule_ts);
                $formatted = date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $schedule_ts);
                update_post_meta($newsletter_id, '_rts_scheduled_time', $formatted);
                wp_safe_redirect(add_query_arg('message', 'scheduled', get_edit_post_link($newsletter_id, 'raw')));
                exit;
            }
        }

        // Generate a secure token for this batch process
        $process_token = wp_generate_password(32, false);

        // Pre-calculate total for progress bar accuracy
        $total_recipients = $this->count_total_subscribers($newsletter_id);
        update_post_meta($newsletter_id, '_rts_newsletter_total_recipients', $total_recipients);

        update_post_meta($newsletter_id, '_rts_newsletter_status', 'sending');
        update_post_meta($newsletter_id, '_rts_newsletter_queued_at', current_time('mysql'));
        update_post_meta($newsletter_id, '_rts_newsletter_queued_count', 0);
        update_post_meta($newsletter_id, '_rts_newsletter_process_token', $process_token);
        
        // Track offset for potential cancellation
        update_post_meta($newsletter_id, '_rts_newsletter_next_offset', 0);

        // Schedule first batch
        wp_schedule_single_event(time() + 2, 'rts_queue_newsletter_batch', array($newsletter_id, 0, $process_token));

        wp_safe_redirect(add_query_arg('message', 'queued', get_edit_post_link($newsletter_id, 'raw')));
        exit;
    }

    /**
     * Schedule a newsletter send for the future.
     *
     * @param int $newsletter_id
     * @param int $timestamp Unix timestamp for send.
     */
    public function schedule_send($newsletter_id, $timestamp) {
        $newsletter_id = intval($newsletter_id);
        if (!$newsletter_id || get_post_type($newsletter_id) !== 'rts_newsletter') {
            return;
        }

        $token = wp_generate_password(32, false);
        
        // Pre-calc not needed yet, will be done at send time or we accept estimate
        update_post_meta($newsletter_id, '_rts_newsletter_status', 'scheduled');
        update_post_meta($newsletter_id, '_rts_newsletter_process_token', $token);
        update_post_meta($newsletter_id, '_rts_newsletter_next_offset', 0);

        wp_schedule_single_event($timestamp, 'rts_queue_newsletter_batch', array($newsletter_id, 0, $token));
    }

    /**
     * Process a batch of subscribers for the newsletter.
     *
     * @param int $newsletter_id
     * @param int $offset
     * @param string $token Security token to verify legitimate cron execution
     */
    public function process_queue_batch($newsletter_id, $offset = 0, $token = '') {
        $newsletter_id = intval($newsletter_id);
        $offset = intval($offset);

        // 1. Validation & Security
        if (!$newsletter_id || get_post_type($newsletter_id) !== 'rts_newsletter') {
            return;
        }

        // Verify the process token matches what we stored at start
        $stored_token = get_post_meta($newsletter_id, '_rts_newsletter_process_token', true);
        if (!$stored_token || !hash_equals($stored_token, $token)) {
            // Token mismatch or missing (likely stopped by admin)
            return;
        }

        // Check if we just started (offset 0), if so, mark as sending if it was scheduled
        if ($offset === 0) {
            update_post_meta($newsletter_id, '_rts_newsletter_status', 'sending');
            // Calc total if not already done (e.g. scheduled send)
            if (!get_post_meta($newsletter_id, '_rts_newsletter_total_recipients', true)) {
                $total = $this->count_total_subscribers($newsletter_id);
                update_post_meta($newsletter_id, '_rts_newsletter_total_recipients', $total);
            }
        }

        // Load Dependencies
        if (!$this->load_dependencies()) {
            error_log('RTS Newsletter: Dependencies failed to load. Retrying in 60s.');
            wp_schedule_single_event(time() + 60, 'rts_queue_newsletter_batch', array($newsletter_id, $offset, $token));
            return;
        }

        // 2. Fetch Content (Render Once)
        $subject = get_the_title($newsletter_id);
        
        // Prepare contents for variants
        $content_a = apply_filters('the_content', $this->get_variant_content($newsletter_id, 'a'));
        $content_b = apply_filters('the_content', $this->get_variant_content($newsletter_id, 'b'));
        $has_variant = !empty(get_post_meta($newsletter_id, '_rts_variant_b', true));

        // 3. Fetch Subscribers Batch
        /**
         * Filter: rts_newsletter_batch_size
         * Adjust batch size for processing.
         * @param int $batch_size Default 200.
         */
        $batch_size = apply_filters('rts_newsletter_batch_size', $this->default_batch_size);
        $subscribers = $this->get_subscribers_batch($newsletter_id, $offset, $batch_size);

        // 4. Check for Completion
        if (empty($subscribers)) {
            update_post_meta($newsletter_id, '_rts_newsletter_status', 'sent');
            update_post_meta($newsletter_id, '_rts_newsletter_sent_at', current_time('mysql'));
            delete_post_meta($newsletter_id, '_rts_newsletter_process_token');
            delete_post_meta($newsletter_id, '_rts_newsletter_next_offset');
            return;
        }

        // 5. Process Batch
        $queued_in_batch = 0;

        foreach ($subscribers as $subscriber_id) {
            // A/B Testing Logic: Split 50/50 based on ID
            $use_variant_b = $has_variant && ($subscriber_id % 2 !== 0);
            $body_content = $use_variant_b ? $content_b : $content_a;
            $body_content = wp_kses_post($body_content);

            // Render the email wrapper
            $rendered = $this->email_templates->render(
                'newsletter_custom', 
                $subscriber_id, 
                array(), 
                array(
                    'newsletter_subject' => $subject,
                    'newsletter_body'    => $body_content,
                )
            );

            if (empty($rendered) || !isset($rendered['subject'])) {
                continue;
            }

            // Enqueue passing the newsletter_id as the letter_id (7th param)
            $result = $this->email_queue->enqueue_email(
                $subscriber_id, 
                'newsletter_custom', 
                $rendered['subject'], 
                $rendered['body'],
                current_time('mysql', true),
                5, // Default priority
                $newsletter_id // Pass ID for exact tracking
            );

            if (!is_wp_error($result)) {
                $queued_in_batch++;
            }
        }

        // 6. Update Stats
        $total_queued = intval(get_post_meta($newsletter_id, '_rts_newsletter_queued_count', true));
        update_post_meta($newsletter_id, '_rts_newsletter_queued_count', $total_queued + $queued_in_batch);

        // 7. Schedule Next Batch
        $next_offset = $offset + $batch_size;
        
        // Update offset meta for clean cancellation
        update_post_meta($newsletter_id, '_rts_newsletter_next_offset', $next_offset);
        
        wp_schedule_single_event(time() + 5, 'rts_queue_newsletter_batch', array($newsletter_id, $next_offset, $token));
    }

    /**
     * Efficiently fetch a batch of valid subscribers.
     *
     * @param int $newsletter_id Context for filtering.
     * @param int $offset
     * @param int $limit
     * @return array Array of Subscriber IDs
     */
    private function get_subscribers_batch($newsletter_id, $offset, $limit) {
        $args = array(
            'post_type'              => 'rts_subscriber',
            'post_status'            => 'publish', // Security: Only published/active records
            'posts_per_page'         => $limit,
            'offset'                 => $offset,
            'fields'                 => 'ids', // Performance: Return IDs only
            'no_found_rows'          => true,  // Performance: Don't calculate total
            'update_post_meta_cache' => false, // Performance: Don't load meta cache
            'update_post_term_cache' => false,
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'meta_query'             => array(
                'relation' => 'AND',
                array(
                    'key'     => '_rts_subscriber_status',
                    'value'   => 'active',
                    'compare' => '='
                ),
                array(
                    'key'     => '_rts_pref_newsletters', // User must have opted-in
                    'value'   => '1',
                    'compare' => '='
                ),
                array(
                    'key'     => '_rts_subscriber_verified',
                    'value'   => '1', // Must be verified
                    'compare' => '='
                )
            ),
        );

        // Optional Re-consent Check
        if (get_option('rts_email_reconsent_required')) {
            $args['meta_query'][] = array(
                'key'     => '_rts_subscriber_consent_confirmed',
                'compare' => 'EXISTS'
            );
        }

        /**
         * Filter: rts_newsletter_subscriber_query
         * Modify query args for segmentation.
         *
         * @param array $args          WP_Query args.
         * @param int   $newsletter_id The ID of the newsletter being sent.
         */
        $args = apply_filters('rts_newsletter_subscriber_query', $args, $newsletter_id);

        return get_posts($args);
    }

    /**
     * Retrieve content variant for A/B testing.
     * Defaults to standard post content if no variant requested/found.
     *
     * @param int $newsletter_id
     * @param string $variant 'a' or 'b'
     * @return string
     */
    private function get_variant_content($newsletter_id, $variant = 'a') {
        if ($variant === 'b') {
            $variant_content = get_post_meta($newsletter_id, '_rts_variant_b', true);
            if ($variant_content) {
                return $variant_content;
            }
        }
        // Default to main content (Variant A)
        return get_post_field('post_content', $newsletter_id);
    }

    /**
     * Get basic stats for a newsletter.
     *
     * @param int $newsletter_id
     * @return array
     */
    public function get_newsletter_stats($newsletter_id) {
        global $wpdb;
        $queue_table = $wpdb->prefix . 'rts_email_queue';
        $tracking_table = $wpdb->prefix . 'rts_email_tracking';
        
        $newsletter_id = intval($newsletter_id);

        // Use letter_id link for accuracy
        return array(
            'sent' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$queue_table} 
                 WHERE letter_id = %d 
                 AND status = 'sent'",
                $newsletter_id
            )),
            'opens' => $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT t.subscriber_id) 
                 FROM {$tracking_table} t
                 INNER JOIN {$queue_table} q ON t.queue_id = q.id
                 WHERE q.letter_id = %d
                 AND t.type = 'open'",
                $newsletter_id
            ))
        );
    }

    /**
     * Check if a newsletter can be sent.
     * Prevents duplicates.
     *
     * @param int $newsletter_id
     * @return bool
     */
    private function can_send_newsletter($newsletter_id) {
        $status = get_post_meta($newsletter_id, '_rts_newsletter_status', true);
        return !in_array($status, array('sending', 'sent'));
    }

    /**
     * Render a preview for admin use.
     *
     * @param int $newsletter_id
     * @return array
     */
    public function render_preview($newsletter_id) {
        if (!$this->load_dependencies()) return array();

        $content = $this->get_variant_content($newsletter_id);
        $content = apply_filters('the_content', $content);
        
        return $this->email_templates->render('newsletter_custom', 0, array(), array(
            'newsletter_subject' => get_the_title($newsletter_id),
            'newsletter_body' => $content
        ));
    }

    /**
     * Calculate progress metrics.
     *
     * @param int $newsletter_id
     * @return array
     */
    private function get_send_progress($newsletter_id) {
        // Use cached total if available
        $total = intval(get_post_meta($newsletter_id, '_rts_newsletter_total_recipients', true));
        if (!$total) {
            $total = $this->count_total_subscribers($newsletter_id);
        }

        $queued = intval(get_post_meta($newsletter_id, '_rts_newsletter_queued_count', true));
        
        $remaining = max(0, $total - $queued);
        $batch_size = apply_filters('rts_newsletter_batch_size', $this->default_batch_size);
        $remaining_batches = ceil($remaining / $batch_size);
        
        return array(
            'percent' => $total > 0 ? round(($queued / $total) * 100) : 0,
            'queued' => $queued,
            'total' => $total,
            'remaining_batches' => $remaining_batches,
            'estimated_time' => $remaining_batches * 5 // Approx 5 seconds per batch via cron
        );
    }

    /**
     * Count total subscribers matching criteria for a specific newsletter.
     * Used for progress bars.
     *
     * @param int $newsletter_id
     * @return int
     */
    private function count_total_subscribers($newsletter_id) {
        $args = array(
            'post_type'              => 'rts_subscriber',
            'post_status'            => 'publish',
            'fields'                 => 'ids',
            'no_found_rows'          => false, // Need count
            'posts_per_page'         => 1, // Don't fetch data
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