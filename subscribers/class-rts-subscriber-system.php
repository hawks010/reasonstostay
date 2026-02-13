<?php
/**
 * Plugin Name: RTS Subscriber System
 * Description: A complete subscriber management, newsletter, and analytics system.
 * Version: 2.3.0
 * Author: RTS
 * Text Domain: rts-subscriber-system
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define Constants
// This system is bundled inside a THEME. Using plugin_dir_url()/plugin_dir_path()
// can generate broken asset URLs (e.g. /wp-content/plugins/.../themes/...) which
// then return HTML 404 pages and trigger strict MIME errors in the browser.
if (!defined('RTS_PLUGIN_DIR')) {
    define('RTS_PLUGIN_DIR', trailingslashit(get_stylesheet_directory()) . 'subscribers/');
}
if (!defined('RTS_PLUGIN_URL')) {
    define('RTS_PLUGIN_URL', trailingslashit(get_stylesheet_directory_uri()) . 'subscribers/');
}
if (!defined('RTS_VERSION')) {
    define('RTS_VERSION', '2.3.0');
}

class RTS_Subscriber_System {
    
    private static $instance = null;
    
    // Versioning
    const VERSION = '2.3.0';
    const DB_VERSION = '2.3.0';
    
    private $plugin_path;
    private $plugin_url;
    
    // Components
    public $subscriber_cpt;
    public $subscription_form;
    public $email_engine;
    public $email_queue;
    public $email_templates;
    public $smtp_settings;
    public $unsubscribe;
    public $analytics;
    public $csv_importer;
    public $admin_menu;
    public $newsletter_cpt;
    public $newsletter_api;
    public $audit_logger;
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->plugin_path = RTS_PLUGIN_DIR;
        $this->plugin_url = RTS_PLUGIN_URL;
        
        // Hooks
        add_action('after_switch_theme', array($this, 'activate'));
        add_action('init', array($this, 'check_database_version'));
        // One-time legacy meta migration
        add_action('admin_init', array($this, 'migrate_legacy_subscriber_meta'));
        
        // Health & Cron
        // NOTE: This class is loaded from a theme (not a plugin), so `plugins_loaded`
        // fires before the theme loads and the callback is never reached. Use `init` instead.
        add_action('init', array($this, 'register_health_checks'));
        add_action('rts_cron_health_check', array($this, 'cron_health_check'));
        
        // Reconsent Handlers
        add_action('admin_post_rts_send_reconsent', array($this, 'handle_send_reconsent'));
        add_action('rts_send_reconsent_batch', array($this, 'process_reconsent_batch'), 10, 1);

        // AJAX handler for new subscription form
        add_action('wp_ajax_rts_handle_subscription', array($this, 'handle_subscription'));
        add_action('wp_ajax_nopriv_rts_handle_subscription', array($this, 'handle_subscription'));

        // Automated drip email processing (hourly cron)
        add_action('rts_automated_drip', array($this, 'process_automated_emails'));

        // Sync subscriber table when post meta changes
        add_action('updated_post_meta', array($this, 'sync_subscriber_meta_change'), 10, 4);
        add_action('added_post_meta', array($this, 'sync_subscriber_meta_change'), 10, 4);

        // Boot
        $this->load_dependencies();
        $this->init_components();
        $this->setup_hooks();
    }
    
    /**
     * Load all required class files.
     */
    private function load_dependencies() {
        // 1. Root Utilities
        if (file_exists($this->plugin_path . 'class-database-installer.php')) {
            require_once $this->plugin_path . 'class-database-installer.php';
        }

        // 2. Core Classes (Looking in includes/)
        $includes_classes = array(
            'class-subscriber-cpt.php',
            'class-subscription-form.php',
            'class-email-engine.php',
            'class-newsletter-cpt.php',
            'class-newsletter-api.php',
            'class-audit-logger.php',
            'class-email-queue.php',
            'class-email-templates.php',
            'class-smtp-settings.php',
            'class-unsubscribe.php',
            'class-analytics.php',
            'class-csv-importer.php',
        );

        foreach ($includes_classes as $file) {
            if (file_exists($this->plugin_path . 'includes/' . $file)) {
                require_once $this->plugin_path . 'includes/' . $file;
            }
        }
        
        // 3. Admin Classes (Looking in admin/)
        if (is_admin()) {
            if (file_exists($this->plugin_path . 'admin/class-admin-menu.php')) {
                require_once $this->plugin_path . 'admin/class-admin-menu.php';
            }
            if (file_exists($this->plugin_path . 'admin/class-subscriber-list.php')) {
                require_once $this->plugin_path . 'admin/class-subscriber-list.php';
            }
        }
    }
    
    private function init_components() {
        // Map of property => class name for all required components.
        // Each entry is initialised if its class exists; otherwise a diagnostic message is logged.
        $components = array(
            'subscriber_cpt'    => 'RTS_Subscriber_CPT',
            'newsletter_cpt'    => 'RTS_Newsletter_CPT',
            'newsletter_api'    => 'RTS_Newsletter_API',
            'audit_logger'      => 'RTS_Audit_Logger',
            'subscription_form' => 'RTS_Subscription_Form',
            'email_engine'      => 'RTS_Email_Engine',
            'email_queue'       => 'RTS_Email_Queue',
            'email_templates'   => 'RTS_Email_Templates',
            'smtp_settings'     => 'RTS_SMTP_Settings',
            'unsubscribe'       => 'RTS_Unsubscribe',
            'csv_importer'      => 'RTS_CSV_Importer',
        );

        foreach ($components as $prop => $class) {
            if (class_exists($class)) {
                $this->$prop = new $class();
            } else {
                error_log('[RTS Subscriber] Missing component class: ' . $class);
            }
        }

        // Analytics uses singleton pattern.
        if (class_exists('RTS_Analytics')) {
            $this->analytics = RTS_Analytics::get_instance();
            if (method_exists($this->analytics, 'init_hooks')) {
                $this->analytics->init_hooks();
            }
        } else {
            error_log('[RTS Subscriber] Missing component class: RTS_Analytics');
        }

        // Admin menu is admin-only.
        if (is_admin()) {
            if (class_exists('RTS_Admin_Menu')) {
                $this->admin_menu = new RTS_Admin_Menu();
                if (method_exists($this->admin_menu, 'init_hooks')) {
                    $this->admin_menu->init_hooks();
                }
            } else {
                error_log('[RTS Subscriber] Missing component class: RTS_Admin_Menu');
            }
        }
    }
    
    private function setup_hooks() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_action('publish_letter', array($this, 'on_letter_published'), 10, 2);

        // Backward-compat shortcode: old form used [rts_subscribe], new uses [rts_subscribe_form]
        add_shortcode('rts_subscribe', array($this, 'render_subscribe_form_compat'));
        // CPT registration is handled by the dedicated CPT classes:
        // - RTS_Subscriber_CPT
        // - RTS_Newsletter_CPT
        // (Avoid duplicate registrations / conflicting show_in_menu behavior.)
        // NOTE: letter CPT is owned by the main theme, not the subscriber system.
        // add_action('init', array($this, 'register_letter_cpt'));
    }

    
    /**
     * Register Subscriber CPT (admin-only)
     * NOTE: This CPT is intentionally non-public and appears under Letters in the admin menu.
     */
    public function register_subscriber_cpt() {
        if ( post_type_exists('rts_subscriber') ) {
            return;
        }

        $labels = array(
            'name'               => 'Subscribers',
            'singular_name'      => 'Subscriber',
            'menu_name'          => 'Subscribers',
            'add_new'            => 'Add Subscriber',
            'add_new_item'       => 'Add New Subscriber',
            'edit_item'          => 'Edit Subscriber',
            'new_item'           => 'New Subscriber',
            'view_item'          => 'View Subscriber',
            'search_items'       => 'Search Subscribers',
            'not_found'          => 'No subscribers found',
            'not_found_in_trash' => 'No subscribers found in Trash',
        );

        register_post_type('rts_subscriber', array(
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => 'edit.php?post_type=letter',
            'show_in_admin_bar'  => false,
            'show_in_nav_menus'  => false,
            'exclude_from_search'=> true,
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
            'hierarchical'       => false,
            'supports'           => array('title'),
            'menu_position'      => null,
            'menu_icon'          => 'dashicons-email',
        ));
    }

    /**
     * Register Newsletter CPT (admin-only)
     * Appears under Letters in the admin menu.
     */
    public function register_newsletter_cpt() {
        if ( post_type_exists('rts_newsletter') ) {
            return;
        }

        $labels = array(
            'name'               => 'Newsletters',
            'singular_name'      => 'Newsletter',
            'menu_name'          => 'Newsletters',
            'add_new'            => 'Add Newsletter',
            'add_new_item'       => 'Add New Newsletter',
            'edit_item'          => 'Edit Newsletter',
            'new_item'           => 'New Newsletter',
            'view_item'          => 'View Newsletter',
            'search_items'       => 'Search Newsletters',
            'not_found'          => 'No newsletters found',
            'not_found_in_trash' => 'No newsletters found in Trash',
        );

        register_post_type('rts_newsletter', array(
            'labels'             => $labels,
            'public'             => false,
            'show_ui'            => true,
            'show_in_menu'       => 'edit.php?post_type=letter',
            'show_in_admin_bar'  => false,
            'show_in_nav_menus'  => false,
            'exclude_from_search'=> true,
            'capability_type'    => 'post',
            'map_meta_cap'       => true,
            'hierarchical'       => false,
            'supports'           => array('title','editor'),
            'menu_position'      => null,
            'menu_icon'          => 'dashicons-email-alt',
        ));
    }

public function register_letter_cpt() {
        if (!post_type_exists('letter')) {
            register_post_type('letter', array(
                'labels' => array(
                    'name'              => 'Letters',
                    'singular_name' => 'Letter',
                    'add_new'       => 'Add New Letter',
                    'edit_item'     => 'Edit Letter',
                ),
                'public'       => true,
                'show_in_rest' => true,
                'supports'     => array('title', 'editor', 'author', 'thumbnail', 'excerpt'),
                'menu_icon'    => 'dashicons-email-alt',
                'has_archive'  => true,
                'rewrite'      => array('slug' => 'letters'),
            ));
        }
    }
    
    public function activate() {
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            wp_die('RTS Subscriber System requires PHP 7.4+. Current: ' . PHP_VERSION);
        }
        
        $this->create_database_tables();
        
        // Ensure migration logic runs on activation
        if (class_exists('RTS_Database_Installer')) {
            RTS_Database_Installer::migrate_from_individual();
        }

        $this->schedule_cron_jobs();
        $this->set_default_options();
        update_option('rts_subscriber_db_version', self::DB_VERSION);
        flush_rewrite_rules();
    }
    
    public function check_database_version() {
        $current = get_option('rts_subscriber_db_version', '0');
        if (version_compare($current, self::DB_VERSION, '<')) {
            $this->create_database_tables();
            update_option('rts_subscriber_db_version', self::DB_VERSION);
        }
    }
    
    private function create_database_tables() {
        if (class_exists('RTS_Database_Installer')) {
            RTS_Database_Installer::install();
        }
    }

    private function schedule_cron_jobs() {
        if (!wp_next_scheduled('rts_daily_digest')) wp_schedule_event(strtotime('tomorrow 09:00:00'), 'daily', 'rts_daily_digest');
        if (!wp_next_scheduled('rts_weekly_digest')) wp_schedule_event(strtotime('next Monday 09:00:00'), 'weekly', 'rts_weekly_digest');
        if (!wp_next_scheduled('rts_monthly_digest')) wp_schedule_event(strtotime('first day of next month 09:00:00'), 'monthly', 'rts_monthly_digest');
        if (!wp_next_scheduled('rts_queue_cleanup')) wp_schedule_event(time(), 'daily', 'rts_queue_cleanup');
        if (!wp_next_scheduled('rts_cron_health_check')) wp_schedule_event(time(), 'hourly', 'rts_cron_health_check');
        // Queue runner: process queued transactional + digest emails frequently.
        // This was previously only scheduled when the health check detected a stale queue,
        // which meant fresh installs could enqueue verification emails but never send them.
        if (!wp_next_scheduled('rts_process_email_queue')) {
            wp_schedule_event(time() + 60, 'rts_5min', 'rts_process_email_queue');
        }
        // Automated drip: process personalized letter delivery hourly
        if (!wp_next_scheduled('rts_automated_drip')) wp_schedule_event(time(), 'hourly', 'rts_automated_drip');
    }
    
    private function set_default_options() {
        $defaults = array(
            'rts_smtp_from_email' => get_option('admin_email'),
            'rts_smtp_from_name' => get_bloginfo('name'),
            'rts_smtp_reply_to' => get_option('admin_email'),
            'rts_email_batch_size' => 100,
            'rts_email_retry_attempts' => 3,
            'rts_email_sending_enabled' => true,
            'rts_email_demo_mode' => false,
            'rts_require_email_verification' => true,
            'rts_capture_signups_while_offline' => true,
            'rts_newsletter_batch_delay' => 5,
            'rts_letters_require_manual_review' => true,
            'rts_queue_retention_sent_days' => 90,
            'rts_queue_retention_cancelled_days' => 30,
            'rts_queue_stuck_timeout_minutes' => 60,
            'rts_retention_email_logs_days' => 90,
            'rts_retention_tracking_days' => 90,
            'rts_retention_bounce_days' => 180,
            'rts_webhook_enabled' => false,
            'rts_webhook_url' => '',
            'rts_webhook_secret' => '',
            'rts_letter_submissions_enabled' => false,
            'rts_newsletter_signups_enabled' => false,
            'rts_frontend_pause_logo_url' => get_stylesheet_directory_uri() . '/assets/img/rts-pause-logo.png',
        );
        foreach ($defaults as $key => $value) {
            if (false === get_option($key)) add_option($key, $value);
        }
    }

    public function add_cron_schedules($schedules) {
        $schedules['weekly'] = array('interval' => 604800, 'display' => 'Weekly');
        $schedules['monthly'] = array('interval' => 2635200, 'display' => 'Monthly');
        $schedules['rts_5min'] = array('interval' => 300, 'display' => 'Every 5 minutes');
        return $schedules;
    }
    
    public function enqueue_frontend_assets() {
        // Register frontend assets (enqueued on demand by form shortcode render method).
        $css_ver = @filemtime($this->plugin_path . 'assets/css/frontend.css') ?: self::VERSION;
        $js_ver  = @filemtime($this->plugin_path . 'assets/js/subscription-form.js') ?: self::VERSION;

        wp_register_style(
            'rts-frontend-css',
            $this->plugin_url . 'assets/css/frontend.css',
            array(),
            $css_ver
        );

        wp_register_script(
            'rts-subscription-js',
            $this->plugin_url . 'assets/js/subscription-form.js',
            array(),
            $js_ver,
            true
        );

        wp_localize_script('rts-subscription-js', 'rtsSubscribe', array(
            'ajax_url' => admin_url('admin-ajax.php'),
        ));
    }
    
    public function enqueue_admin_assets($hook) {
        // Only load subscriber admin assets on subscriber/newsletter screens
        $post_type = isset($_GET['post_type']) ? (string) $_GET['post_type'] : '';
        $page      = isset($_GET['page']) ? (string) $_GET['page'] : '';

        // Detect post type from current screen
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$post_type && $screen && !empty($screen->post_type)) {
            $post_type = $screen->post_type;
        }

        $is_subscriber_context = (
            strpos($post_type, 'rts_') === 0
            || strpos($page, 'rts-subscriber') === 0
            || in_array($page, array('rts-subscribers-dashboard', 'rts-email-templates', 'rts-email-settings'), true)
        );

        if (!$is_subscriber_context) {
            return;
        }

        // Enqueue master admin CSS (consolidated Inkfire Glass design)
        $admin_css_path = get_stylesheet_directory() . '/assets/css/rts-admin-complete.css';
        if (file_exists($admin_css_path)) {
            wp_enqueue_style('rts-admin-master', get_stylesheet_directory_uri() . '/assets/css/rts-admin-complete.css', array(), self::VERSION);
        }

        // Enqueue admin JavaScript
        if (file_exists($this->plugin_path . 'assets/js/admin.js')) {
            wp_enqueue_script('rts-subscriber-admin', $this->plugin_url . 'assets/js/admin.js', array('jquery'), self::VERSION, true);
            wp_localize_script('rts-subscriber-admin', 'rtsAdmin', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('rts_admin_nonce'),
            ));
        }
    }

    public function on_letter_published($post_id, $post) {
        if ($post->post_type !== 'letter') return;
        $quality = get_post_meta($post_id, '_rts_quality_score', true);
        if ($quality >= 70) {
            update_post_meta($post_id, '_rts_email_ready', true);
            update_post_meta($post_id, '_rts_email_added_date', current_time('mysql'));
        }
    }
    
    public function handle_send_reconsent() {
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        if (!wp_next_scheduled('rts_send_reconsent_batch')) {
            wp_schedule_single_event(time(), 'rts_send_reconsent_batch', array(1));
        }
        $redirect = add_query_arg('message', 'reconsent_scheduled', wp_get_referer());
        wp_safe_redirect($redirect);
        exit;
    }
    
    public function process_reconsent_batch($paged = 1) {
        $batch_size = 50;
        $args = array(
            'post_type' => 'rts_subscriber',
            'post_status' => 'publish',
            'posts_per_page' => $batch_size,
            'paged' => $paged,
            'fields' => 'ids',
            'meta_query' => array(
                'relation' => 'AND',
                array('key' => '_rts_subscriber_status', 'value' => 'active'),
                array('key' => '_rts_subscriber_consent_confirmed', 'compare' => 'NOT EXISTS')
            )
        );
        $query = new WP_Query($args);
        if ($query->have_posts()) {
            foreach ($query->posts as $subscriber_id) {
                $subject = "Please confirm your subscription";
                $body = "Please confirm your subscription to continue receiving emails.";
                if ($this->email_templates) {
                    $tpl = $this->email_templates->render('reconsent', $subscriber_id);
                    if (!empty($tpl['body'])) {
                        $subject = $tpl['subject'];
                        $body = $tpl['body'];
                    }
                }
                if ($this->email_queue) {
                    $this->email_queue->enqueue_email($subscriber_id, 'reconsent', $subject, $body, null, 10);
                }
            }
            if ($query->max_num_pages > $paged) {
                wp_schedule_single_event(time() + 60, 'rts_send_reconsent_batch', array($paged + 1));
            }
        }
        wp_reset_postdata();
    }

    public function cron_health_check() {
        update_option('rts_last_cron_health_check', current_time('mysql'));
        // Always ensure the queue runner exists.
        // On new installs rts_last_queue_run can be empty, which previously prevented scheduling.
        if (!wp_next_scheduled('rts_process_email_queue')) {
            wp_schedule_event(time() + 60, 'rts_5min', 'rts_process_email_queue');
        }
    }

    public function validate_configuration() {
        $errors = array();
        if (!get_option('rts_smtp_from_email')) $errors[] = 'From email not configured';
        if (!wp_next_scheduled('rts_daily_digest')) $errors[] = 'Daily digest cron not scheduled';
        return $errors;
    }

    public function register_health_checks() {
        if (class_exists('WP_Site_Health')) {
            add_filter('site_status_tests', function($tests) {
                $tests['direct']['rts_subscriber_tables'] = array(
                    'label' => 'RTS Subscriber Tables',
                    'test'  => array($this, 'health_check_tables'),
                );
                return $tests;
            });
        }
    }

    public function health_check_tables() {
        global $wpdb;
        $missing = array();
        
        // Use installer to check first if available
        if (class_exists('RTS_Database_Installer') && RTS_Database_Installer::tables_exist()) {
            return array('status' => 'good', 'label' => 'Tables OK', 'description' => 'All RTS tables are present.');
        }

        $required = array(
            $wpdb->prefix . 'rts_email_queue',
            $wpdb->prefix . 'rts_email_logs',
            $wpdb->prefix . 'rts_email_tracking',
            $wpdb->prefix . 'rts_email_bounces',
            $wpdb->prefix . 'rts_dead_letter_queue',
            $wpdb->prefix . 'rts_rate_limits',
            $wpdb->prefix . 'rts_engagement',
            $wpdb->prefix . 'rts_newsletter_versions',
            $wpdb->prefix . 'rts_newsletter_analytics',
            $wpdb->prefix . 'rts_newsletter_templates',
            $wpdb->prefix . 'rts_newsletter_audit',
            $wpdb->prefix . 'rts_system_audit',
        );

        foreach ($required as $table) {
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
                $missing[] = $table;
            }
        }

        if (!empty($missing)) {
            return array('status' => 'critical', 'label' => 'Missing Tables', 'description' => 'Missing: ' . implode(', ', $missing));
        }
        return array('status' => 'good', 'label' => 'Tables OK', 'description' => 'All tables present.');
    }

    /**
     * One-time migration (v1.2):
     * Legacy meta keys: _rts_status, _rts_frequency
     * New canonical keys: _rts_subscriber_status, _rts_subscriber_frequency
     *
     * Copies values forward without deleting legacy keys.
     */
    public function migrate_legacy_subscriber_meta() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $flag = get_option('rts_subscriber_meta_migrated_v1_2');
        if ($flag) {
            return;
        }

        global $wpdb;
        $pm = $wpdb->postmeta;

        // Copy _rts_status -> _rts_subscriber_status where new is missing
        $wpdb->query("
            INSERT INTO {$pm} (post_id, meta_key, meta_value)
            SELECT old.post_id, '_rts_subscriber_status', old.meta_value
            FROM {$pm} old
            LEFT JOIN {$pm} new
              ON new.post_id = old.post_id AND new.meta_key = '_rts_subscriber_status'
            WHERE old.meta_key = '_rts_status'
              AND new.meta_id IS NULL
        ");

        // Copy _rts_frequency -> _rts_subscriber_frequency where new is missing
        $wpdb->query("
            INSERT INTO {$pm} (post_id, meta_key, meta_value)
            SELECT old.post_id, '_rts_subscriber_frequency', old.meta_value
            FROM {$pm} old
            LEFT JOIN {$pm} new
              ON new.post_id = old.post_id AND new.meta_key = '_rts_subscriber_frequency'
            WHERE old.meta_key = '_rts_frequency'
              AND new.meta_id IS NULL
        ");

        update_option('rts_subscriber_meta_migrated_v1_2', 1, false);
    }

    // -----------------------------------------------------------------------
    // Backward-compat shortcode: [rts_subscribe] → delegates to new form class
    // -----------------------------------------------------------------------

    /**
     * Render the subscription form via the old [rts_subscribe] shortcode.
     */
    public function render_subscribe_form_compat($atts) {
        if ($this->subscription_form && method_exists($this->subscription_form, 'render')) {
            return $this->subscription_form->render($atts);
        }
        return '';
    }

    // -----------------------------------------------------------------------
    // AJAX Form Handler: rts_handle_subscription
    // -----------------------------------------------------------------------

    /**
     * Handle subscription form submissions via AJAX.
     *
     * Validates input, creates the subscriber via CPT, stores scheduling data
     * in the rts_subscribers table, and triggers welcome/verification email.
     */
    public function handle_subscription() {
        // Verify nonce
        if (!check_ajax_referer('rts_subscribe_nonce', 'security', false)) {
            wp_send_json_error(array('message' => 'Security check failed. Please refresh the page and try again.'));
            return;
        }

        if (!(bool) get_option('rts_newsletter_signups_enabled', false)) {
            wp_send_json_error(array('message' => 'Newsletter signups are temporarily paused. Please check back soon.'), 503);
            return;
        }

        // Honeypot check
        if (!empty($_POST['rts_website'])) {
            wp_send_json_error(array('message' => 'Invalid submission.'));
            return;
        }

        // Email validation
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        if (!is_email($email)) {
            wp_send_json_error(array('message' => 'Please enter a valid email address.'));
            return;
        }

        // Frequency validation (allow only known values)
        $frequency = isset($_POST['frequency']) ? sanitize_text_field(wp_unslash($_POST['frequency'])) : 'weekly';
        if (!in_array($frequency, array('daily', 'weekly', 'monthly'), true)) {
            $frequency = 'weekly';
        }

        // Preferences validation
        $prefs = array();
        if (!empty($_POST['prefs']) && is_array($_POST['prefs'])) {
            $allowed_prefs = array('letters', 'newsletters');
            foreach ($_POST['prefs'] as $pref) {
                $pref = sanitize_text_field(wp_unslash($pref));
                if (in_array($pref, $allowed_prefs, true)) {
                    $prefs[] = $pref;
                }
            }
        }
        // GDPR-safe: require an explicit preference selection.
        if (empty($prefs)) {
            wp_send_json_error(array('message' => 'Please choose what you want to receive (Letters and/or Newsletters).'));
            return;
        }

        // Privacy consent required
        if (empty($_POST['privacy_consent'])) {
            wp_send_json_error(array('message' => 'You must agree to the privacy policy to subscribe.'));
            return;
        }

        // Rate limiting: 5 subscriptions per IP per hour
        $ip = $this->get_client_ip();
        $rate_key = 'rts_sub_rate_' . md5($ip);
        $rate_count = (int) get_transient($rate_key);
        if ($rate_count >= 5) {
            wp_send_json_error(array('message' => 'Too many subscription attempts. Please try again later.'));
            return;
        }
        set_transient($rate_key, $rate_count + 1, HOUR_IN_SECONDS);

        // Ensure CPT class is available
        if (!$this->subscriber_cpt) {
            $this->load_dependencies();
            $this->init_components();
        }

        if (!$this->subscriber_cpt || !method_exists($this->subscriber_cpt, 'get_subscriber_by_email')) {
            wp_send_json_error(array('message' => 'System error. Please try again later.'));
            return;
        }

        // Check for duplicate email
        $existing = $this->subscriber_cpt->get_subscriber_by_email($email);
        if ($existing) {
            wp_send_json_error(array('message' => 'This email address is already subscribed.'));
            return;
        }

        // Create subscriber via CPT
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
        $subscriber_id = $this->subscriber_cpt->create_subscriber(
            $email,
            $frequency,
            'website',
            array(
                'ip_address'       => $ip,
                'user_agent'       => $user_agent,
                'pref_letters'     => in_array('letters', $prefs, true) ? 1 : 0,
                'pref_newsletters' => in_array('newsletters', $prefs, true) ? 1 : 0,
            )
        );

        if (is_wp_error($subscriber_id)) {
            wp_send_json_error(array('message' => $subscriber_id->get_error_message()));
            return;
        }

        // Store preferences explicitly (create_subscriber may use defaults)
        update_post_meta($subscriber_id, '_rts_pref_letters', in_array('letters', $prefs, true) ? 1 : 0);
        update_post_meta($subscriber_id, '_rts_pref_newsletters', in_array('newsletters', $prefs, true) ? 1 : 0);

        // Sync to rts_subscribers table for drip scheduling
        $this->sync_subscriber_to_table($subscriber_id, $email, $frequency, $prefs);

        // Send verification or welcome email
        // Mail offline capture: store the subscriber, but do NOT queue verification/welcome.
        if ((bool) get_option('rts_mail_system_offline', false)) {
            $capture = (bool) get_option('rts_capture_signups_while_offline', true);
            if (!$capture) {
                wp_send_json_error(array('message' => 'Mail system is offline. Signups are temporarily paused. Please try again later.'), 503);
                return;
            }

            update_post_meta($subscriber_id, '_rts_subscriber_status', 'captured_offline');
            // Ensure scheduling table reflects non-active status.
            $this->sync_subscriber_to_table($subscriber_id, $email, $frequency, $prefs);

            wp_send_json_success(array(
                'message' => 'Thanks! You are on the list. We will email you to verify once mail is back online.'
            ));
            return;
        }


        $require_verification = (bool) get_option('rts_require_email_verification', true);

        if ($require_verification && $this->email_engine && method_exists($this->email_engine, 'send_verification_email')) {
            $result = $this->email_engine->send_verification_email($subscriber_id);
            if (is_wp_error($result)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('RTS subscription verification send failed: ' . $result->get_error_message());
                }
                $message = 'Subscription created, but verification email could not be queued. Please contact support.';
            } else {
                $message = 'Please check your email to verify your subscription.';
            }
        } else {
            if ($this->email_engine && method_exists($this->email_engine, 'send_welcome_email')) {
                $this->email_engine->send_welcome_email($subscriber_id);
            }
            $message = 'Thank you for subscribing! Check your inbox for a welcome message.';
        }

        wp_send_json_success(array('message' => $message));
    }

    // -----------------------------------------------------------------------
    // Automated Drip Logic
    // -----------------------------------------------------------------------

    /**
     * Process automated drip emails.
     *
     * Hooked to rts_automated_drip (hourly cron). Queries the rts_subscribers
     * table for active subscribers whose next_send_date has passed, selects a
     * random unsent letter, enqueues it, and calculates the next send date.
     */
    
/**
 * Cache-friendly pool of email-ready published letters.
 *
 * Avoids ORDER BY RAND() and repeated queries inside cron loops.
 *
 * @return int[]
 */
private function get_cached_email_ready_letter_ids() {
    $key = 'rts_email_ready_letter_ids_v1';
    $ids = get_transient($key);
    if (is_array($ids) && !empty($ids)) {
        return array_values(array_unique(array_map('absint', $ids)));
    }

    $q_args = [
        'post_type'      => 'letter',
        'post_status'    => 'publish',
        'posts_per_page' => 2000,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => [
            [
                'key'     => '_rts_email_ready',
                'value'   => ['1', 'true'],
                'compare' => 'IN',
            ],
        ],
    ];

    // Primary workflow decision: stage=published when available.
    if (class_exists('RTS_Workflow')) {
        $q_args['meta_query'][] = [
            'key'   => RTS_Workflow::META_STAGE,
            'value' => RTS_Workflow::STAGE_PUBLISHED,
        ];
    }

    $ids = [];
    $paged = 1;
    do {
        $q_args['paged'] = $paged;
        $q = new WP_Query($q_args);
        if (!empty($q->posts)) {
            $ids = array_merge($ids, array_map('absint', $q->posts));
        }
        $paged++;
    } while (!empty($q->posts) && $paged <= 200);

    $ids = array_values(array_unique(array_filter($ids)));
    set_transient($key, $ids, 15 * MINUTE_IN_SECONDS);

    return $ids;
}

public function process_automated_emails() {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_subscribers';

        // Ensure table exists
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return;
        }

        $now = current_time('mysql', true);

        // Get subscribers due for an email (batch of 50)
        $subscribers = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE status = 'active' AND next_send_date IS NOT NULL AND next_send_date <= %s LIMIT 50",
            $now
        ));

        if (empty($subscribers)) {
            return;
        }

        // Load dependencies
        $includes_path = $this->plugin_path . 'includes/';
        if (!class_exists('RTS_Email_Templates') && file_exists($includes_path . 'class-email-templates.php')) {
            require_once $includes_path . 'class-email-templates.php';
        }
        if (!class_exists('RTS_Email_Queue') && file_exists($includes_path . 'class-email-queue.php')) {
            require_once $includes_path . 'class-email-queue.php';
        }

        if (!class_exists('RTS_Email_Templates') || !class_exists('RTS_Email_Queue')) {
            return;
        }

        $templates = new RTS_Email_Templates();
        $queue = new RTS_Email_Queue();
        $logs_table = $wpdb->prefix . 'rts_email_logs';

        $eligible_pool = $this->get_cached_email_ready_letter_ids();
        if (!empty($eligible_pool)) {
            shuffle($eligible_pool);
        }

        foreach ($subscribers as $sub) {
            $subscriber_id = intval($sub->post_id);

            // Validate subscriber is still active and verified via CPT (source of truth)
            $status   = get_post_meta($subscriber_id, '_rts_subscriber_status', true);
            $verified = (bool) get_post_meta($subscriber_id, '_rts_subscriber_verified', true);

            if ($status !== 'active' || !$verified) {
                // Update table to match CPT status
                $wpdb->update($table, array('status' => $status ?: 'inactive'), array('id' => $sub->id), array('%s'), array('%d'));
                continue;
            }

            // Check preferences: only send letters if subscriber wants them
            $pref_letters = (bool) get_post_meta($subscriber_id, '_rts_pref_letters', true);
            if (!$pref_letters) {
                // Still advance the next_send_date so we don't re-check every hour
                $next = $this->calculate_next_send_date($sub->frequency);
                $wpdb->update($table, array('next_send_date' => $next), array('id' => $sub->id), array('%s'), array('%d'));
                continue;
            }

            // If re-consent is enabled, only send after explicit consent confirmation.
            if (get_option('rts_email_reconsent_required')) {
                $has_consent = (bool) get_post_meta($subscriber_id, '_rts_subscriber_consent_confirmed', true);
                if (!$has_consent) {
                    $next = $this->calculate_next_send_date($sub->frequency);
                    $wpdb->update($table, array('next_send_date' => $next), array('id' => $sub->id), array('%s'), array('%d'));
                    continue;
                }
            }

            // Get IDs of letters already sent to this subscriber
            $sent_ids = array();
            if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $logs_table)) === $logs_table) {
                $sent_ids = $wpdb->get_col($wpdb->prepare(
                    "SELECT DISTINCT letter_id FROM {$logs_table} WHERE subscriber_id = %d AND status = 'sent' AND letter_id IS NOT NULL",
                    $subscriber_id
                ));
                $sent_ids = array_values(array_unique(array_filter(array_map('intval', (array) $sent_ids))));
            }
            // Select a letter they haven't received using cached pool (no ORDER BY RAND()).
            $candidate_pool = $eligible_pool;
            if (!empty($sent_ids) && !empty($candidate_pool)) {
                $candidate_pool = array_values(array_diff($candidate_pool, $sent_ids));
            }
            $letter_id = null;
            if (!empty($candidate_pool)) {
                $letter_id = (int) $candidate_pool[array_rand($candidate_pool)];
            }

            $letter_post = null;
            if ($letter_id) {
                $letter_post = get_post($letter_id);
            }
            $letters = $letter_post ? [ $letter_post ] : [];

            if (empty($letters)) {
                // All caught up: send the all_caught_up template
                $tpl = $templates->render('all_caught_up', $subscriber_id);
                $queue->enqueue_email($subscriber_id, 'all_caught_up', $tpl['subject'], $tpl['body'], null, 5);

                // Advance next_send_date
                $next = $this->calculate_next_send_date($sub->frequency);
                $wpdb->update($table, array('next_send_date' => $next), array('id' => $sub->id), array('%s'), array('%d'));
                continue;
            }

            $letter = $letters[0];

            $template_slug = 'automated_letter';
            $subject_opt = get_option('rts_email_subject_automated_letter', false);
            $body_opt    = get_option('rts_email_body_automated_letter', false);
            $has_custom_template = ($subject_opt !== false || $body_opt !== false);

            if ($has_custom_template) {
                // Use editable automated-letter templates when configured.
                $tpl = $templates->render($template_slug, $subscriber_id, array($letter));
                $subject = (string) ($tpl['subject'] ?? '');
                $body = (string) ($tpl['body'] ?? '');
            } else {
                // Default to branded single-letter renderer so it mirrors the viewer style.
                $token = get_post_meta($subscriber_id, '_rts_subscriber_token', true);
                $unsubscribe_url = $this->generate_unsubscribe_url($token);

                if (!class_exists('RTS_Email_Renderer') && file_exists($includes_path . 'class-email-renderer.php')) {
                    require_once $includes_path . 'class-email-renderer.php';
                }

                $subject = get_bloginfo('name') . ' - ' . get_the_title($letter);
                $letter_content = apply_filters('the_content', $letter->post_content);

                if (class_exists('RTS_Email_Renderer')) {
                    $renderer = new RTS_Email_Renderer();
                    $body = $renderer->render('letter', array(
                        'letter_title'    => esc_html(get_the_title($letter)),
                        'letter_content'  => wp_kses_post($letter_content),
                        'unsubscribe_url' => $unsubscribe_url,
                        'site_name'       => esc_html(get_bloginfo('name')),
                        'letter_url'      => esc_url(get_permalink($letter)),
                    ));
                } else {
                    $tpl = $templates->render($template_slug, $subscriber_id, array($letter));
                    $body = (string) ($tpl['body'] ?? '');
                    $subject = (string) ($tpl['subject'] ?? $subject);
                }
            }

            // Add letter ID marker for granular logging
            $body .= '<!--RTS_LETTER_IDS:' . intval($letter->ID) . '-->';

            // Enqueue the email
            $queue->enqueue_email($subscriber_id, $template_slug, $subject, $body, null, 5, intval($letter->ID));

            // Calculate and store new next_send_date
            $next = $this->calculate_next_send_date($sub->frequency);
            $wpdb->update($table, array('next_send_date' => $next), array('id' => $sub->id), array('%s'), array('%d'));
        }

        // Ensure queue processing kicks in
        if (!wp_next_scheduled('rts_process_email_queue')) {
            wp_schedule_single_event(time() + 30, 'rts_process_email_queue');
        }
    }

    // -----------------------------------------------------------------------
    // Subscriber Table Sync Helpers
    // -----------------------------------------------------------------------

    /**
     * Sync a subscriber to the rts_subscribers scheduling table.
     *
     * @param int    $subscriber_id CPT post ID.
     * @param string $email         Subscriber email.
     * @param string $frequency     daily|weekly|monthly.
     * @param array  $prefs         Array of preference strings.
     */
    public function sync_subscriber_to_table($subscriber_id, $email, $frequency, $prefs) {
        global $wpdb;
        $table = $wpdb->prefix . 'rts_subscribers';

        // Ensure table exists
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return;
        }

        $next_send = $this->calculate_next_send_date($frequency);
        $status = (string) get_post_meta($subscriber_id, '_rts_subscriber_status', true);
        if ($status === '') {
            $status = 'active';
        }

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE post_id = %d", intval($subscriber_id)));

        if ($exists) {
            $wpdb->update(
                $table,
                array(
                    'email'          => sanitize_email($email),
                    'status'         => $status,
                    'frequency'      => $frequency,
                    'preferences'    => wp_json_encode($prefs),
                    'next_send_date' => $next_send,
                ),
                array('post_id' => intval($subscriber_id)),
                array('%s', '%s', '%s', '%s', '%s'),
                array('%d')
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'post_id'        => intval($subscriber_id),
                    'email'          => sanitize_email($email),
                    'status'         => $status,
                    'frequency'      => $frequency,
                    'preferences'    => wp_json_encode($prefs),
                    'next_send_date' => $next_send,
                    'created_at'     => current_time('mysql', true),
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        }
    }

    /**
     * Keep rts_subscribers table in sync when subscriber post meta changes.
     *
     * Listens on updated_post_meta / added_post_meta for status, frequency,
     * and preference meta keys. Debounced per request to avoid multiple writes.
     */
    public function sync_subscriber_meta_change($meta_id, $post_id, $meta_key, $meta_value) {
        static $synced = array();

        $tracked_keys = array(
            '_rts_subscriber_status',
            '_rts_subscriber_frequency',
            '_rts_pref_letters',
            '_rts_pref_newsletters',
        );

        if (!in_array($meta_key, $tracked_keys, true)) {
            return;
        }
        if (get_post_type($post_id) !== 'rts_subscriber') {
            return;
        }
        // Debounce: only sync once per request per post
        if (isset($synced[$post_id])) {
            return;
        }
        $synced[$post_id] = true;

        // Lightweight lock to reduce race conditions during rapid/bulk updates.
        $lock_key = 'rts_sync_subscriber_lock_' . (int) $post_id;
        if (get_transient($lock_key)) {
            return;
        }
        set_transient($lock_key, 1, 5);

        global $wpdb;
        $table = $wpdb->prefix . 'rts_subscribers';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return;
        }

        $email = get_post_meta($post_id, '_rts_subscriber_email', true);
        if (!$email) {
            $email = get_the_title($post_id);
        }

        $frequency = get_post_meta($post_id, '_rts_subscriber_frequency', true) ?: 'weekly';
        $status    = get_post_meta($post_id, '_rts_subscriber_status', true) ?: 'active';

        $prefs = array();
        if (get_post_meta($post_id, '_rts_pref_letters', true)) {
            $prefs[] = 'letters';
        }
        if (get_post_meta($post_id, '_rts_pref_newsletters', true)) {
            $prefs[] = 'newsletters';
        }

        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE post_id = %d", intval($post_id)));

        if ($exists) {
            $wpdb->update(
                $table,
                array(
                    'email'       => sanitize_email($email),
                    'status'      => $status,
                    'frequency'   => $frequency,
                    'preferences' => wp_json_encode($prefs),
                ),
                array('post_id' => intval($post_id)),
                array('%s', '%s', '%s', '%s'),
                array('%d')
            );
        } else {
            $wpdb->insert(
                $table,
                array(
                    'post_id'        => intval($post_id),
                    'email'          => sanitize_email($email),
                    'status'         => $status,
                    'frequency'      => $frequency,
                    'preferences'    => wp_json_encode($prefs),
                    'next_send_date' => $this->calculate_next_send_date($frequency),
                    'created_at'     => current_time('mysql', true),
                ),
                array('%d', '%s', '%s', '%s', '%s', '%s', '%s')
            );
        }
    }

    // -----------------------------------------------------------------------
    // Utility Methods
    // -----------------------------------------------------------------------

    /**
     * Calculate the next send date based on frequency.
     *
     * @param string $frequency daily|weekly|monthly.
     * @return string MySQL datetime (UTC).
     */
    private function calculate_next_send_date($frequency) {
        switch ($frequency) {
            case 'daily':
                return gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
            case 'monthly':
                return gmdate('Y-m-d H:i:s', time() + (30 * DAY_IN_SECONDS));
            case 'weekly':
            default:
                return gmdate('Y-m-d H:i:s', time() + WEEK_IN_SECONDS);
        }
    }

    /**
     * Generate a signed unsubscribe URL for a subscriber token.
     *
     * @param string $token Subscriber token.
     * @return string Full unsubscribe URL.
     */
    private function generate_unsubscribe_url($token) {
        if (!$token) {
            return home_url('/');
        }
        $sig = hash_hmac('sha256', $token . '|unsubscribe|' . gmdate('Y-m-d'), wp_salt('auth'));
        return add_query_arg(array('rts_unsubscribe' => $token, 'sig' => $sig), home_url('/'));
    }

    /**
     * Get client IP with proxy/Cloudflare support.
     *
     * @return string Sanitized IP address.
     */
    private function get_client_ip() {
        $keys = array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR');
        foreach ($keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ips = explode(',', sanitize_text_field(wp_unslash($_SERVER[$key])));
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

}


// Global accessor
function RTS_Subscriber_System() {
    return RTS_Subscriber_System::get_instance();
}

// Bootstrap
RTS_Subscriber_System();
