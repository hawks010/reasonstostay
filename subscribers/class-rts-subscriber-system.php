<?php
/**
 * Plugin Name: RTS Subscriber System
 * Description: A complete subscriber management, newsletter, and analytics system.
 * Version: 2.0.38
 * Author: RTS
 * Text Domain: rts-subscriber-system
 */

if (!defined('ABSPATH')) {
    exit;
}

// Define Constants
if (!defined('RTS_PLUGIN_DIR')) {
    define('RTS_PLUGIN_DIR', plugin_dir_path(__FILE__));
}
if (!defined('RTS_PLUGIN_URL')) {
    define('RTS_PLUGIN_URL', plugin_dir_url(__FILE__));
}
if (!defined('RTS_VERSION')) {
    define('RTS_VERSION', '2.0.38');
}

class RTS_Subscriber_System {
    
    private static $instance = null;
    
    // Versioning
    const VERSION = '2.0.38';
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
        register_activation_hook(__FILE__, array($this, 'activate'));
        add_action('after_switch_theme', array($this, 'activate'));
        add_action('init', array($this, 'check_database_version'));
        
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
        }
        if (class_exists('RTS_Newsletter_CPT')) {
            $this->newsletter_cpt = new RTS_Newsletter_CPT();
        }
        if (class_exists('RTS_Subscription_Form')) {
            $this->subscription_form = new RTS_Subscription_Form();
        }
        if (class_exists('RTS_Email_Engine')) {
            $this->email_engine = new RTS_Email_Engine();
        }
        if (class_exists('RTS_Email_Queue')) {
            $this->email_queue = new RTS_Email_Queue();
        }
        if (class_exists('RTS_Email_Templates')) $this->email_templates = new RTS_Email_Templates();
        if (class_exists('RTS_SMTP_Settings')) $this->smtp_settings = new RTS_SMTP_Settings();
        if (class_exists('RTS_Unsubscribe')) $this->unsubscribe = new RTS_Unsubscribe();
        if (class_exists('RTS_Analytics')) $this->analytics = new RTS_Analytics();
        if (class_exists('RTS_CSV_Importer')) $this->csv_importer = new RTS_CSV_Importer();
        if (is_admin() && class_exists('RTS_Admin_Menu')) {
            $this->admin_menu = new RTS_Admin_Menu();
        }
    }
    
    private function setup_hooks() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_frontend_assets'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
        add_action('publish_letter', array($this, 'on_letter_published'), 10, 2);
        add_action('init', array($this, 'register_letter_cpt'));
    }

    public function register_letter_cpt() {
        if (!post_type_exists('letter')) {
            register_post_type('letter', array(
                'labels' => array(
                    'name'          => 'Letters',
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
        if (strpos((string)$hook, 'rts-') !== false || (isset($_GET['post_type']) && strpos($_GET['post_type'], 'rts_') !== false)) {
            if (file_exists($this->plugin_path . 'assets/css/admin.css')) {
                wp_enqueue_style('rts-subscriber-admin', $this->plugin_url . 'assets/css/admin.css', array(), self::VERSION);
            }
            if (file_exists($this->plugin_path . 'assets/js/admin.js')) {
                wp_enqueue_script('rts-subscriber-admin', $this->plugin_url . 'assets/js/admin.js', array('jquery'), self::VERSION, true);
                wp_localize_script('rts-subscriber-admin', 'rtsAdmin', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('rts_admin_nonce'),
                ));
            }
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
}

// Global accessor
function RTS_Subscriber_System() {
    return RTS_Subscriber_System::get_instance();
}

// Bootstrap
RTS_Subscriber_System();