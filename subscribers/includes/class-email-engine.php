<?php
/**
 * RTS Email Engine
 *
 * Builds emails, queues them, sends queued items, logs, tracking, and bounces.
 *
 * @package    RTS_Subscriber_System
 * @subpackage Engine
 * @version    1.0.5
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_Email_Engine {

    /**
     * Cache for subscriber validation results to reduce DB hits in loops.
     * @var array
     */
    private $subscriber_status_cache = array();

    public function __construct() {
        // Queue processing hooks
        add_action('rts_send_queued_email', array($this, 'send_queued_email'), 10, 1);
        
        // Error handling
        add_action('wp_mail_failed', array($this, 'handle_wp_mail_failed'), 10, 1);
        
        // Digest scheduling
        add_action('rts_daily_digest', array($this, 'run_daily_digest'));
        add_action('rts_weekly_digest', array($this, 'run_weekly_digest'));
        add_action('rts_monthly_digest', array($this, 'run_monthly_digest'));

        // Table initialization (runs once per version update)
        // Table creation is centralized in RTS_Database_Installer.
// Tracking Handler (Listens for pixel/link clicks)
        add_action('init', array($this, 'handle_tracking_request'));
    }

    /**
     * Handle incoming tracking requests (Opens and Clicks).
     * This was the missing method causing the fatal error.
     */
    public function handle_tracking_request() {
        if (!isset($_GET['rts_track']) || !isset($_GET['id'])) {
            return;
        }

        $type = sanitize_key($_GET['rts_track']); // 'open' or 'click'
        $id   = sanitize_text_field($_GET['id']);

        global $wpdb;
        $table = $wpdb->prefix . 'rts_email_tracking';

        // 1. Verify existence in DB (The ID is an HMAC, so existence implies validity)
        // Using prepare to prevent SQL injection
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE track_id = %s LIMIT 1", 
            $id
        ));

        if (!$row) {
            // Invalid tracking ID - fail silently or redirect home
            if ($type === 'click') {
                wp_redirect(home_url());
                exit;
            }
            return;
        }

        // 2. Handle 'Open' -> Serve 1x1 Pixel
        if ($type === 'open') {
            // Record open if not already recorded (or unique opens logic)
            // Ideally we update the 'opened' column here if using the new schema
            if (isset($row->opened) && $row->opened == 0) {
                $wpdb->update(
                    $table,
                    array('opened' => 1, 'opened_at' => current_time('mysql')),
                    array('id' => $row->id),
                    array('%d', '%s'),
                    array('%d')
                );
            }

            // Send headers to prevent caching
            if (!headers_sent()) {
                header('Content-Type: image/gif');
                header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
                header('Pragma: no-cache');
            }
            
            // Output 1x1 transparent GIF
            echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
            exit;
        }

        // 3. Handle 'Click' -> Redirect
        if ($type === 'click') {
            // Record click
            if (isset($row->clicked) && $row->clicked == 0) {
                $wpdb->update(
                    $table,
                    array('clicked' => 1, 'clicked_at' => current_time('mysql')),
                    array('id' => $row->id),
                    array('%d', '%s'),
                    array('%d')
                );
            }

            // Prefer URL from DB (Security: Prevents open redirects)
            $url = !empty($row->url) ? $row->url : '';

            // Fallback to GET param if DB is empty (legacy support), but sanitizing heavily
            if (empty($url) && isset($_GET['url'])) {
                $url = esc_url_raw(urldecode($_GET['url']));
            }

            // Validation: Ensure valid URL structure
            if ($url && wp_http_validate_url($url)) {
                wp_redirect($url);
                exit;
            } else {
                wp_redirect(home_url());
                exit;
            }
        }
    }

    /**
     * Ensure required database tables exist.
     */
    public function maybe_create_tables() {
        // Deprecated: table creation is centralized in RTS_Database_Installer.
        return;

        // Skip if centralized installer is handling tables
        if (get_option('rts_centralized_tables') || class_exists('RTS_Database_Installer')) {
            return;
        }

        $db_version = get_option('rts_email_engine_db_version');
        $current_version = '1.0.3'; 

        if ($db_version !== $current_version) {
            $this->create_tables();
            update_option('rts_email_engine_db_version', $current_version);
        }
    }

    // Fallback table creation (only runs if centralized installer is missing)
    private function create_tables() {
        // Deprecated: table creation is centralized in RTS_Database_Installer.
        return;

        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

        // Tracking Table (Simplified fallback)
        $sql_tracking = "CREATE TABLE {$wpdb->prefix}rts_email_tracking (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            queue_id bigint(20) unsigned NOT NULL,
            subscriber_id bigint(20) unsigned NOT NULL,
            template varchar(50) NOT NULL,
            track_id varchar(64) NOT NULL,
            type varchar(20) NOT NULL,
            url text,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY track_id (track_id),
            KEY queue_id (queue_id),
            KEY subscriber_id (subscriber_id)
        ) $charset_collate;";

        dbDelta($sql_tracking);
    }

    /**
     * Send queued email item.
     *
     * @param object $queue_item Database row object from queue table.
     * @return bool|void
     */
    public function send_queued_email($queue_item) {
        if (empty($queue_item) || empty($queue_item->id) || empty($queue_item->subscriber_id)) {
            return;
        }

        if (!$this->load_dependencies()) return;

        $queue = new RTS_Email_Queue();

        // Global kill switch
        if (!get_option('rts_email_sending_enabled')) {
            $queue->mark_cancelled(intval($queue_item->id), 'Email sending disabled via settings');
            return false;
        }

        // Pause switch (Command Center)
        if (get_option('rts_pause_all_sending')) {
            return false;
        }


        $subscriber_id = intval($queue_item->subscriber_id);

        // Validation: Require active. For most templates require verified as well.
        // Verification emails must be allowed for unverified subscribers.
        $template_key = sanitize_key($queue_item->template);

        if (!isset($this->subscriber_status_cache[$subscriber_id])) {
            $verified = (bool) get_post_meta($subscriber_id, '_rts_subscriber_verified', true);
            $status   = (string) get_post_meta($subscriber_id, '_rts_subscriber_status', true);

            // Cache as an array so different templates can make safe decisions.
            $this->subscriber_status_cache[$subscriber_id] = array(
                'verified' => $verified,
                'status'   => $status,
                'active'   => ($status === 'active'),
            );
        }

        $state = $this->subscriber_status_cache[$subscriber_id];

        // Status gate: verification emails are allowed for pending_verification.
        if ($template_key === 'verification') {
            if (empty($state['status']) || !in_array($state['status'], array('active', 'pending_verification'), true)) {
                $queue->mark_failed(intval($queue_item->id), 'Subscriber not eligible for verification email');
                return;
            }
        } else {
            if (empty($state['active'])) {
                $queue->mark_failed(intval($queue_item->id), 'Subscriber not active');
                return;
            }
        }

        if ($template_key !== 'verification' && empty($state['verified'])) {
            $queue->mark_failed(intval($queue_item->id), 'Subscriber not verified');
            return;
        }

        // Get email safely
        $email_meta = get_post_meta($subscriber_id, '_rts_subscriber_email', true);
        if ($email_meta && is_email($email_meta)) {
            $to = $email_meta;
        } else {
            $title = get_the_title($subscriber_id);
            $to = is_email($title) ? $title : '';
        }

        if (!$to) {
            $queue->mark_failed(intval($queue_item->id), 'Invalid or missing subscriber email address');
            return;
        }

        $subject = $queue_item->subject;
        $body    = $queue_item->body;

        // Apply Tracking
        $body = $this->apply_tracking($body, $queue_item);

        $headers = array('Content-Type: text/html; charset=UTF-8');

        // Demo mode
        if (get_option('rts_email_demo_mode')) {
            $queue->mark_cancelled(intval($queue_item->id), 'Demo mode enabled - email not sent');
            $this->log_email($subscriber_id, $to, $queue_item->template, $subject, 'cancelled', 'Demo mode enabled', array('queue_id' => intval($queue_item->id)), intval($queue_item->letter_id));
            return true;
        }

        // Send
        $sent = wp_mail($to, $subject, $body, $headers);

        if ($sent) {
            $queue->mark_sent(intval($queue_item->id));
            $this->log_email_granular($subscriber_id, $to, $queue_item->template, $subject, 'sent', null, array('queue_id' => intval($queue_item->id)), $body, intval($queue_item->letter_id));
            do_action('rts_email_sent', intval($queue_item->id), 'sent');
            return true;
        } else {
            $queue->mark_failed(intval($queue_item->id), 'wp_mail failed');
            $this->log_email($subscriber_id, $to, $queue_item->template, $subject, 'failed', 'wp_mail returned false', array('queue_id' => intval($queue_item->id)));
            do_action('rts_email_sent', intval($queue_item->id), 'failed');
            return false;
        }
    }

    /**
     * Send a verification email for a newly created subscriber.
     *
     * This is used by the frontend subscription form when email verification is enabled.
     * It enqueues an email into the RTS queue and schedules near-term processing.
     *
     * @param int $subscriber_id
     * @return int|WP_Error Queue ID or error
     */
    public function send_verification_email($subscriber_id) {
        $subscriber_id = intval($subscriber_id);
        if ($subscriber_id <= 0) {
            return new WP_Error('invalid_subscriber', 'Invalid subscriber id');
        }

        if (!$this->load_dependencies()) {
            return new WP_Error('missing_dependencies', 'Email engine dependencies missing');
        }

        $templates = new RTS_Email_Templates();
        $rendered  = $templates->render('verification', $subscriber_id);

        $queue = new RTS_Email_Queue();
        $queued_id = $queue->enqueue_email($subscriber_id, 'verification', $rendered['subject'], $rendered['body'], null, 10);

        // Ensure queue runner kicks in quickly (without requiring a full cron wait).
        if (!wp_next_scheduled('rts_process_email_queue')) {
            wp_schedule_single_event(time() + 30, 'rts_process_email_queue');
        }

        // On some hosts WP-Cron is slow to spawn (or disabled). For transactional
        // emails like verification/welcome we want best-effort immediate delivery.
        // Process a small batch right now when we're not already inside cron.
        if (!defined('DOING_CRON') || !DOING_CRON) {
            /**
             * Process queue immediately to avoid "stuck pending verification".
             * Safe because it only sends pending items and has its own time guard.
             */
            do_action('rts_process_email_queue');

            // Nudge WP-Cron as well for any follow-up items.
            if (function_exists('spawn_cron')) {
                @spawn_cron(time());
            }
        }

        return $queued_id;
    }

    /**
     * Send a welcome email for a subscriber.
     *
     * @param int $subscriber_id
     * @return int|WP_Error Queue ID or error
     */
    public function send_welcome_email($subscriber_id) {
        $subscriber_id = intval($subscriber_id);
        if ($subscriber_id <= 0) {
            return new WP_Error('invalid_subscriber', 'Invalid subscriber id');
        }

        if (!$this->load_dependencies()) {
            return new WP_Error('missing_dependencies', 'Email engine dependencies missing');
        }

        $templates = new RTS_Email_Templates();
        $rendered  = $templates->render('welcome', $subscriber_id);

        $queue = new RTS_Email_Queue();
        $queued_id = $queue->enqueue_email($subscriber_id, 'welcome', $rendered['subject'], $rendered['body'], null, 9);

        if (!wp_next_scheduled('rts_process_email_queue')) {
            wp_schedule_single_event(time() + 30, 'rts_process_email_queue');
        }

        // Same best-effort immediate delivery approach as verification.
        if (!defined('DOING_CRON') || !DOING_CRON) {
            do_action('rts_process_email_queue');
            if (function_exists('spawn_cron')) {
                @spawn_cron(time());
            }
        }

        return $queued_id;
    }

    /**
     * Inject open pixel and wrap links for click tracking.
     */
    private function apply_tracking($body, $queue_item) {
        if (!get_option('rts_email_tracking_enabled', true)) {
            return $body;
        }

        $queue_id      = intval($queue_item->id);
        $subscriber_id = intval($queue_item->subscriber_id);
        $template      = sanitize_key($queue_item->template);

        // Open Tracking
        $open_id = hash_hmac('sha256', 'open|' . $queue_id . '|' . $subscriber_id, wp_salt('auth'));
        
        $track_url = add_query_arg(array(
            'rts_track' => 'open',
            'id'        => $open_id
        ), home_url('/'));

        $pixel = '<img src="' . esc_url($track_url) . '" width="1" height="1" style="display:none;" alt="" />';

        if (stripos($body, '</body>') !== false) {
            $body = str_ireplace('</body>', $pixel . '</body>', $body);
        } else {
            $body .= $pixel;
        }

        // Store Open Row
        $this->store_tracking_row($queue_id, $subscriber_id, $template, $open_id, 'open', null);

        // Click Tracking
        if (get_option('rts_click_tracking_enabled', true)) {
            $body = preg_replace_callback(
                '/href=("|\')(.*?)\1/i',
                function($matches) use ($queue_id, $subscriber_id, $template) {
                    $quote = $matches[1];
                    $url   = $matches[2];

                    if (preg_match('/^(mailto:|#|tel:|sms:)/i', $url)) return $matches[0];
                    if (stripos($url, 'unsubscribe') !== false || stripos($url, 'manage') !== false) return $matches[0];

                    $click_id = hash_hmac('sha256', 'click|' . $queue_id . '|' . $subscriber_id . '|' . $url, wp_salt('auth'));
                    
                    $track_url = add_query_arg(array(
                        'rts_track' => 'click',
                        'id'        => $click_id
                    ), home_url('/'));

                    $this->store_tracking_row($queue_id, $subscriber_id, $template, $click_id, 'click', $url);

                    return 'href=' . $quote . esc_url($track_url) . $quote;
                },
                $body
            );
        }

        return $body;
    }

    /**
     * Store tracking row.
     */
    private function store_tracking_row($queue_id, $subscriber_id, $template, $track_id, $type, $url = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_email_tracking';

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE track_id = %s", $track_id));
        if ($exists) {
            return;
        }

        if ($url && strlen($url) > 2000) {
            $url = substr($url, 0, 2000);
        }

        $data = array(
            'queue_id'      => intval($queue_id),
            'subscriber_id' => intval($subscriber_id),
            'template'      => sanitize_key($template),
            'track_id'      => sanitize_text_field($track_id),
            'type'          => $type === 'click' ? 'click' : 'open',
            'url'           => $url ? esc_url_raw($url) : null,
            'created_at'    => current_time('mysql'),
        );

        // Add extra columns if centralized installer tables are present
        if (get_option('rts_centralized_tables')) {
            $data['opened'] = 0;
            $data['clicked'] = 0;
        }

        $wpdb->insert($table, $data);
    }

    /**
     * Granular log helper.
     *
     * Digests may contain multiple letters. To support "no duplicates" we log each
     * letter ID that was actually included in the email.
     *
     * The body may include a marker like: <!--RTS_LETTER_IDS:123,456-->
     */
    private function log_email_granular($subscriber_id, $email, $template, $subject, $status = 'sent', $error = null, $metadata = array(), $body = '', $fallback_letter_id = null) {
        $ids = array();

        if ($fallback_letter_id) {
            $ids[] = intval($fallback_letter_id);
        }

        if (is_string($body) && $body) {
            if (preg_match('/RTS_LETTER_IDS:([0-9,]+)/', $body, $m)) {
                $parts = array_filter(array_map('trim', explode(',', $m[1])));
                foreach ($parts as $p) {
                    $p = intval($p);
                    if ($p > 0) {
                        $ids[] = $p;
                    }
                }
            }
        }

        $ids = array_values(array_unique(array_filter($ids)));

        if (empty($ids)) {
            $this->log_email($subscriber_id, $email, $template, $subject, $status, $error, $metadata, null);
            return;
        }

        foreach ($ids as $letter_id) {
            $this->log_email($subscriber_id, $email, $template, $subject, $status, $error, $metadata, $letter_id);
        }
    }

    private function log_email($subscriber_id, $email, $template, $subject, $status = 'sent', $error = null, $metadata = array(), $letter_id = null) {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_email_logs';

        $wpdb->insert(
            $table,
            array(
                'subscriber_id' => intval($subscriber_id),
                'email'         => sanitize_email($email),
                'template'      => sanitize_key($template),
                'letter_id'     => $letter_id ? intval($letter_id) : null,
                'subject'       => sanitize_text_field($subject),
                'status'        => $status,
                'sent_at'       => current_time('mysql'),
                'error'         => $error ? sanitize_textarea_field($error) : null,
                'metadata'      => !empty($metadata) ? wp_json_encode($metadata) : null,
            ),
            array('%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
        );

        if ($status === 'sent') {
            $total_sent = intval(get_post_meta($subscriber_id, '_rts_subscriber_total_sent', true));
            update_post_meta($subscriber_id, '_rts_subscriber_total_sent', $total_sent + 1);
            update_post_meta($subscriber_id, '_rts_subscriber_last_sent', current_time('mysql'));
        }
    }

    public function handle_wp_mail_failed($wp_error) {
        if (!($wp_error instanceof WP_Error)) return;

        $data = $wp_error->get_error_data();
        if (empty($data) || (empty($data['to']) && empty($data['headers']))) return;

        $recipients = array();
        if (!empty($data['to'])) {
            $recipients = is_array($data['to']) ? $data['to'] : array($data['to']);
        }

        $error_msg = $wp_error->get_error_message();

        foreach ($recipients as $email) {
            $email = sanitize_email($email);
            if ($email) {
                $this->handle_bounce($email, $error_msg);
            }
        }
    }

    public function handle_bounce($email, $error) {
        if (!$this->load_dependencies()) return false;

        $subscriber_cpt = new RTS_Subscriber_CPT();
        $subscriber_id = $subscriber_cpt->get_subscriber_by_email($email);

        if (!$subscriber_id) return false;

        $bounce_count = intval(get_post_meta($subscriber_id, '_rts_subscriber_bounce_count', true));
        $bounce_count++;
        update_post_meta($subscriber_id, '_rts_subscriber_bounce_count', $bounce_count);

        global $wpdb;
        $table = $wpdb->prefix . 'rts_email_bounces';

        $wpdb->insert(
            $table,
            array(
                'subscriber_id' => intval($subscriber_id),
                'email'         => sanitize_email($email),
                'error'         => wp_strip_all_tags($error),
                'bounced_at'    => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%s')
        );

        $max_bounces = intval(get_option('rts_max_bounce_count', 3));
        if ($bounce_count >= $max_bounces) {
            update_post_meta($subscriber_id, '_rts_subscriber_status', 'bounced');
            update_post_meta($subscriber_id, '_rts_subscriber_bounced_at', current_time('mysql'));
            return true;
        }

        return false;
    }

    public function run_daily_digest() {
        $this->queue_digest('daily');
    }

    public function run_weekly_digest() {
        $this->queue_digest('weekly');
    }

    public function run_monthly_digest() {
        $this->queue_digest('monthly');
    }

    /**
     * Queue digest emails efficiently.
     */
    private function queue_digest($frequency) {
        if (!$this->load_dependencies()) return;

        $frequency = sanitize_key($frequency);
        $template_slug = $frequency . '_digest';

        $require_consent = (bool) get_option('rts_email_reconsent_required', true);

        $meta_query = array(
            array('key' => '_rts_pref_letters', 'value' => '1'),
            array('key' => '_rts_subscriber_status', 'value' => 'active'),
            array('key' => '_rts_subscriber_verified', 'value' => 1),
            array('key' => '_rts_subscriber_frequency', 'value' => $frequency),
        );
        if ($require_consent) {
            $meta_query[] = array(
                'key'     => '_rts_subscriber_consent_confirmed',
                'compare' => 'EXISTS',
            );
        }

        $templates = new RTS_Email_Templates();
        $queue     = new RTS_Email_Queue();

        $paged = 1;
        do {
            $subscriber_query = new WP_Query(array(
                'post_type'      => 'rts_subscriber',
                'posts_per_page' => 500,
                'paged'          => $paged,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'update_post_meta_cache' => false,
                'update_post_term_cache' => false,
                'meta_query'     => $meta_query,
            ));

            if (!empty($subscriber_query->posts)) {
                foreach ($subscriber_query->posts as $subscriber_id) {
                    $subscriber_id = intval($subscriber_id);

                    $letters = $this->get_letters_for_subscriber($subscriber_id, $frequency);

                    // If subscriber has seen everything, send the "all caught up" template.
                    if (empty($letters)) {
                        $tpl = $templates->render('all_caught_up', $subscriber_id);
                        $queue->enqueue_email($subscriber_id, 'all_caught_up', $tpl['subject'], $tpl['body'], current_time('mysql'), 5, null);
                        continue;
                    }

                    $tpl = $templates->render($template_slug, $subscriber_id, $letters);

                    // Embed letter ids for granular logging after send.
                    $ids = array();
                    foreach ($letters as $p) {
                        if ($p instanceof WP_Post) {
                            $ids[] = intval($p->ID);
                        }
                    }
                    $ids = array_values(array_unique(array_filter($ids)));
                    $marker = !empty($ids) ? '<!--RTS_LETTER_IDS:' . implode(',', $ids) . '-->' : '';
                    $body_with_marker = $tpl['body'] . $marker;

                    // Store first id in queue. Full list remains in marker for logs.
                    $queue_letter_id = !empty($ids) ? intval($ids[0]) : null;

                    $queue->enqueue_email($subscriber_id, $template_slug, $tpl['subject'], $body_with_marker, current_time('mysql'), 5, $queue_letter_id);
                }
            }

            $paged++;
        } while (!empty($subscriber_query->posts));
        
        wp_reset_postdata();
    }

    private function get_letters_for_digest($frequency) {
        $limit = $frequency === 'daily' ? 1 : ($frequency === 'weekly' ? 5 : 10);

        $args = array(
            'post_type'      => 'letter',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'meta_query'     => array(
                array(
                    'key'   => '_rts_email_ready',
                    'value' => 1
                )
            )
        );

        $posts = get_posts($args);
        return $posts ?: array();
    }

    /**
     * Per-subscriber letter selection with "no duplicates" logic.
     *
     * Pulls already-sent letter IDs from rts_email_logs and excludes them.
     * Returns an array of WP_Post objects.
     */
    private function get_letters_for_subscriber($subscriber_id, $frequency) {
        $subscriber_id = intval($subscriber_id);
        $limit = ($frequency === 'daily') ? 1 : (($frequency === 'weekly') ? 5 : 10);

        global $wpdb;
        $log_table = $wpdb->prefix . 'rts_email_logs';

        $sent_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT letter_id FROM {$log_table} WHERE subscriber_id = %d AND status = 'sent' AND letter_id IS NOT NULL",
            $subscriber_id
        ));

        $sent_ids = array_values(array_unique(array_filter(array_map('intval', (array) $sent_ids))));

        $args = array(
            'post_type'      => 'letter',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
            'post__not_in'   => $sent_ids,
            'meta_query'     => array(
                array(
                    'key'   => '_rts_email_ready',
                    'value' => 1,
                ),
            ),
        );

        $posts = get_posts($args);
        if (!empty($posts)) {
            return $posts;
        }

        // Fallback: subscriber has read everything "email ready".
        // Return empty to allow caller to use the all_caught_up template.
        return array();
    }

    /**
     * Dependency Check Helper
     */
    private function load_dependencies() {
        // Assume includes path is standard
        $includes_path = RTS_PLUGIN_DIR . 'includes/';
        
        if (!class_exists('RTS_Email_Templates') && file_exists($includes_path . 'class-email-templates.php')) {
            require_once $includes_path . 'class-email-templates.php';
        }
        
        if (!class_exists('RTS_Email_Queue') && file_exists($includes_path . 'class-email-queue.php')) {
            require_once $includes_path . 'class-email-queue.php';
        }

        if (!class_exists('RTS_Subscriber_CPT') && file_exists($includes_path . 'class-subscriber-cpt.php')) {
            require_once $includes_path . 'class-subscriber-cpt.php';
        }

        return class_exists('RTS_Email_Templates') && class_exists('RTS_Email_Queue') && class_exists('RTS_Subscriber_CPT');
    }
}