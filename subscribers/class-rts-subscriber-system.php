<?php
/**
 * Plugin Name: RTS Subscriber System
 * Description: A complete subscriber management, newsletter, and analytics system.
 * Version: 2.0.39.12
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
    define('RTS_VERSION', '2.0.39.12');
}

class RTS_Subscriber_System {
    
    private static $instance = null;
    
    // Versioning
    const VERSION = '2.0.39.12';
    const DB_VERSION = '2.0.0'; 
    
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
        add_action('plugins_loaded', array($this, 'register_health_checks'));
        add_action('rts_cron_health_check', array($this, 'cron_health_check'));
        
        // Reconsent Handlers
        add_action('admin_post_rts_send_reconsent', array($this, 'handle_send_reconsent'));
        add_action('rts_send_reconsent_batch', array($this, 'process_reconsent_batch'), 10, 1);
        
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
        if (class_exists('RTS_Subscriber_CPT')) {
            $this->subscriber_cpt = new RTS_Subscriber_CPT();
            if (method_exists($this->subscriber_cpt, 'init_hooks')) { $this->subscriber_cpt->init_hooks(); }
        }
        if (class_exists('RTS_Newsletter_CPT')) {
            $this->newsletter_cpt = new RTS_Newsletter_CPT();
            if (method_exists($this->newsletter_cpt, 'init_hooks')) { $this->newsletter_cpt->init_hooks(); }
        }
        if (class_exists('RTS_Subscription_Form')) {
            $this->subscription_form = new RTS_Subscription_Form();
            if (method_exists($this->subscription_form, 'init_hooks')) { $this->subscription_form->init_hooks(); }
        }
        if (class_exists('RTS_Email_Engine')) {
            $this->email_engine = new RTS_Email_Engine();
            if (method_exists($this->email_engine, 'init_hooks')) { $this->email_engine->init_hooks(); }
        }
        if (class_exists('RTS_Email_Queue')) {
            $this->email_queue = new RTS_Email_Queue();
            if (method_exists($this->email_queue, 'init_hooks')) { $this->email_queue->init_hooks(); }
        }
        if (class_exists('RTS_Email_Templates')) {
            $this->email_templates = new RTS_Email_Templates();
            if (method_exists($this->email_templates, 'init_hooks')) { $this->email_templates->init_hooks(); }
        }
        if (class_exists('RTS_SMTP_Settings')) {
            $this->smtp_settings = new RTS_SMTP_Settings();
            if (method_exists($this->smtp_settings, 'init_hooks')) { $this->smtp_settings->init_hooks(); }
        }
        if (class_exists('RTS_Unsubscribe')) {
            $this->unsubscribe = new RTS_Unsubscribe();
            if (method_exists($this->unsubscribe, 'init_hooks')) { $this->unsubscribe->init_hooks(); }
        }
        
        // FIX: Use singleton accessor for Analytics
        if (class_exists('RTS_Analytics')) {
            $this->analytics = RTS_Analytics::get_instance();
            // Note: The Singleton constructor already calls setup_hooks(), so explicit init_hooks() call isn't needed here for Analytics if it follows the new pattern.
            // But checking for method existence is safe.
            if (method_exists($this->analytics, 'init_hooks')) { $this->analytics->init_hooks(); }
        }
        
        if (class_exists('RTS_CSV_Importer')) {
            $this->csv_importer = new RTS_CSV_Importer();
            if (method_exists($this->csv_importer, 'init_hooks')) { $this->csv_importer->init_hooks(); }
        }
        if (is_admin() && class_exists('RTS_Admin_Menu')) {
            $this->admin_menu = new RTS_Admin_Menu();
            if (method_exists($this->admin_menu, 'init_hooks')) { $this->admin_menu->init_hooks(); }
        }
    }
    
    private function setup_hooks() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_action('publish_letter', array($this, 'on_letter_published'), 10, 2);
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
    }
    
    private function set_default_options() {
        $defaults = array(
            'rts_smtp_from_email' => get_option('admin_email'),
            'rts_smtp_from_name' => get_bloginfo('name'),
            'rts_smtp_reply_to' => get_option('admin_email'),
            'rts_email_batch_size' => 100,
            'rts_email_retry_attempts' => 3,
            'rts_require_email_verification' => true,
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
        global $post;
    }
    
    public function enqueue_admin_assets($hook) {
        // Only load subscriber admin assets on subscriber/newsletter screens.
        // Avoid loading on Letters dashboard pages (they also use `rts-*` page slugs).
        $post_type = isset($_GET['post_type']) ? (string) $_GET['post_type'] : '';
        $page      = isset($_GET['page']) ? (string) $_GET['page'] : '';

        $is_subscriber_context = (
            strpos($post_type, 'rts_') === 0
            || strpos($page, 'rts-subscriber') === 0
            || in_array($page, array('rts-subscribers-dashboard', 'rts-email-templates', 'rts-email-settings'), true)
        );

        if (!$is_subscriber_context) {
            return;
        }

        // Shared admin styling (Letters dashboard look) + subscriber-specific styling.
        $shared_css_path = get_stylesheet_directory() . '/assets/css/rts-admin.css';
        if (file_exists($shared_css_path)) {
            wp_enqueue_style('rts-admin-shared', get_stylesheet_directory_uri() . '/assets/css/rts-admin.css', array(), self::VERSION);
        }

        if (file_exists($this->plugin_path . 'assets/css/admin.css')) {
            $deps = wp_style_is('rts-admin-shared', 'enqueued') ? array('rts-admin-shared') : array();
            wp_enqueue_style('rts-subscriber-admin', $this->plugin_url . 'assets/css/admin.css', $deps, self::VERSION);
        }

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
        $last_run = get_option('rts_last_queue_run', '');
        if ($last_run && (time() - strtotime($last_run) > 1800) && !wp_next_scheduled('rts_process_email_queue')) {
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
            $wpdb->prefix . 'rts_engagement'
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

}


// Global accessor
function RTS_Subscriber_System() {
    return RTS_Subscriber_System::get_instance();
}

// Bootstrap
RTS_Subscriber_System();