<?php
/**
 * RTS Subscriber Custom Post Type
 * Handles subscriber data storage with GDPR compliance
 *
 * @package    RTS_Subscriber_System
 * @subpackage Subscriber
 * @version    1.0.6
 */

if (!defined('ABSPATH')) {
    exit;
}

class RTS_Subscriber_CPT {

    /**
     * Subscriber post type slug.
     */
    public const CPT = 'rts_subscriber';

/**
     * Constructor
     *
     * During refactors we separated hook wiring into init_hooks().
     * The subscriber CPT MUST always register its hooks when instantiated,
     * otherwise WordPress will report "Invalid post type" in wp-admin.
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    // Hooks are now separated so this class can be instantiated for utility without duplication
    public function init_hooks() {
        add_action('init', array($this, 'register_post_type'));
        add_action('init', array($this, 'register_meta_fields'));
        
        if (is_admin()) {
            add_filter('manage_rts_subscriber_posts_columns', array($this, 'set_custom_columns'));
            add_action('manage_rts_subscriber_posts_custom_column', array($this, 'custom_column_content'), 10, 2);
            add_filter('manage_edit-rts_subscriber_sortable_columns', array($this, 'sortable_columns'));
            add_action('restrict_manage_posts', array($this, 'add_filters'));
            add_filter('parse_query', array($this, 'filter_query'));
            add_filter('bulk_actions-edit-rts_subscriber', array($this, 'register_bulk_actions'));
            add_filter('handle_bulk_actions-edit-rts_subscriber', array($this, 'handle_bulk_actions'), 10, 3);
            add_action('admin_notices', array($this, 'bulk_action_admin_notices'));

            // Add/Edit Subscriber: email-first meta box flow
            add_filter('enter_title_here', array($this, 'filter_title_placeholder'), 10, 2);
            add_action('add_meta_boxes', array($this, 'register_metaboxes'));
            add_action('save_post_rts_subscriber', array($this, 'save_metaboxes'), 10, 2);
            add_action('admin_enqueue_scripts', array($this, 'enqueue_editor_ui_assets'));
        }
    }

    /**
     * Enqueue admin/editor UI assets for the Subscriber CPT only.
     *
     * A refactor wired this method via admin_enqueue_scripts, but the
     * method was not included in the class, causing a fatal error in
     * wp-admin. Keep this lightweight and scoped.
     */
    public function enqueue_editor_ui_assets($hook_suffix) {
        if (!is_admin()) {
            return;
        }

        // Only load on Subscriber add/edit screens.
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen) {
            return;
        }

        $is_subscriber_editor = (
            $screen->post_type === self::CPT &&
            in_array($screen->base, array('post', 'post-new', 'edit'), true)
        );

        if (!$is_subscriber_editor) {
            return;
        }

        $ver = defined('RTS_THEME_VERSION') ? RTS_THEME_VERSION : (string) time();

        // Admin UI CSS is provided by the theme-level RTS admin skin.
        // (Avoid enqueuing a missing/duplicate CSS file here.)

        // Optional admin JS (safe even if it only contains enhancements).
        wp_enqueue_script(
            'rts-subscriber-admin',
            RTS_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            $ver,
            true
        );

        // Provide basic context for admin.js enhancements.
        wp_localize_script(
            'rts-subscriber-admin',
            'RTSSubscriberAdmin',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('rts_subscriber_admin'),
                'post_type'=> self::CPT,
            )
        );
    }

    /**
     * Check if a custom table exists.
     */
    public function table_exists($table_suffix) {
        global $wpdb;
        $table_name = $wpdb->prefix . $table_suffix;
        return $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s", $table_name
        )) === $table_name;
    }
    
    public function register_post_type() {
        register_post_type('rts_subscriber', array(
            'labels' => array(
                'name'               => 'Subscribers',
                'singular_name'      => 'Subscriber',
                'add_new'            => 'Add Subscriber',
                'add_new_item'       => 'Add New Subscriber',
                'edit_item'          => 'Edit Subscriber',
                'view_item'          => 'View Subscriber',
                'search_items'       => 'Search Subscribers',
                'not_found'          => 'No subscribers found',
            ),
            'public'              => false,
            'show_ui'             => true,
            // Subscribers MUST be a top-level admin menu item.
            // We use the CPT menu as the anchor and attach Settings/Templates/Analytics/Import as submenus.
            'show_in_menu'        => true,
            'show_in_admin_bar'   => false,
            // Data-only CPT: we keep `title` for search/listing, but we hide the title UI
            // and treat it as the subscriber's email (set automatically on save).
            'supports'            => array('title'),
            'capabilities'        => array(
                'edit_post'          => 'manage_options',
                'edit_posts'         => 'manage_options',
                'edit_others_posts'  => 'manage_options',
                'publish_posts'      => 'manage_options',
                'read_private_posts' => 'manage_options',
                'delete_post'        => 'manage_options',
            ),
            'capability_type'     => 'post',
        ));
    }
    
    public function register_meta_fields() {
        // Use custom callback for booleans to avoid dependency on REST API
        $boolean_callback = array($this, 'sanitize_boolean_meta');

        $field_definitions = array(
            '_rts_subscriber_email'             => array('type' => 'string',  'sanitize' => 'sanitize_email'),
            '_rts_subscriber_status'            => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            '_rts_subscriber_frequency'         => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            '_rts_subscriber_token'             => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            '_rts_subscriber_verified'          => array('type' => 'integer', 'sanitize' => $boolean_callback), // Stored as 1/0
            '_rts_subscriber_source'            => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            '_rts_subscriber_subscribed_date'   => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            '_rts_subscriber_last_sent'         => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            '_rts_subscriber_total_sent'        => array('type' => 'integer', 'sanitize' => 'absint'),
            '_rts_subscriber_ip_address'        => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            '_rts_subscriber_user_agent'        => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
            '_rts_subscriber_consent_log'       => array('type' => 'array',   'sanitize' => array($this, 'sanitize_consent_log')),
            '_rts_pref_letters'                 => array('type' => 'integer', 'sanitize' => $boolean_callback),
            '_rts_pref_newsletters'             => array('type' => 'integer', 'sanitize' => $boolean_callback),
            '_rts_subscriber_verification_token' => array('type' => 'string', 'sanitize' => 'sanitize_text_field'),
            '_rts_subscriber_verification_sent' => array('type' => 'string',  'sanitize' => 'sanitize_text_field'),
        );

        foreach ($field_definitions as $key => $def) {
            register_post_meta('rts_subscriber', $key, array(
                'type'              => $def['type'],
                'single'            => true,
                'show_in_rest'      => false,
                'sanitize_callback' => $def['sanitize'],
            ));
        }
    }

    /**
     * Custom boolean sanitizer that returns integer 1 or 0.
     * Prevents fatal errors if rest_sanitize_boolean is unavailable.
     */
    public function sanitize_boolean_meta($value) {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
    }

    /**
     * Sanitize consent log array.
     */
    public function sanitize_consent_log($value) {
        if (!is_array($value)) {
            return array();
        }
        // Basic sanitization of log entries
        return array_map(function($item) {
            return is_array($item) ? array_map('sanitize_text_field', $item) : sanitize_text_field($item);
        }, $value);
    }
    
    public function create_subscriber($email, $frequency = 'weekly', $source = 'website', $args = array()) {
        $email = sanitize_email($email);
        
        if (!is_email($email)) {
            return new WP_Error('invalid_email', 'Invalid email address');
        }

        // DNS Validation (Optional via filter)
        if (apply_filters('rts_validate_email_dns', false)) {
            $domain = substr(strrchr($email, "@"), 1);
            if (!checkdnsrr($domain, 'MX')) {
                return new WP_Error('invalid_domain', 'Email domain does not exist');
            }
        }
        
        // Check for duplicates
        $existing = $this->get_subscriber_by_email($email);
        if ($existing) {
            return new WP_Error('duplicate_email', sprintf(__('Email %s is already subscribed.', 'rts-subscriber-system'), $email));
        }
        
        // Generate unique token
        $token = $this->generate_unique_token();
        
        // Create post
        $post_id = wp_insert_post(array(
            'post_type'   => 'rts_subscriber',
            'post_title'  => $email,
            'post_status' => 'publish',
            'post_name'   => sanitize_title($email),
        ));
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        // Set meta
        update_post_meta($post_id, '_rts_subscriber_email', $email);
        update_post_meta($post_id, '_rts_subscriber_status', 'active');
        update_post_meta($post_id, '_rts_subscriber_frequency', $frequency);
        update_post_meta($post_id, '_rts_subscriber_token', $token);
        update_post_meta($post_id, '_rts_subscriber_source', $source);
        update_post_meta($post_id, '_rts_subscriber_subscribed_date', current_time('mysql'));
        
        // Default preferences
        $default_letters = apply_filters('rts_default_pref_letters', true);
        $default_newsletters = apply_filters('rts_default_pref_newsletters', true);

        if (!isset($args['pref_letters'])) update_post_meta($post_id, '_rts_pref_letters', $default_letters ? 1 : 0);
        if (!isset($args['pref_newsletters'])) update_post_meta($post_id, '_rts_pref_newsletters', $default_newsletters ? 1 : 0);

        $require_verification = (bool) apply_filters('rts_require_email_verification', (bool) get_option('rts_require_email_verification', true));
        update_post_meta($post_id, '_rts_subscriber_verified', !$require_verification ? 1 : 0);
        
        if ($require_verification) {
            // Generate unique verification token
            global $wpdb;
            do {
                $ver_token = bin2hex(random_bytes(16));
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_rts_subscriber_verification_token' AND meta_value = %s",
                    $ver_token
                ));
            } while ($exists);

            update_post_meta($post_id, '_rts_subscriber_verification_token', $ver_token);
            update_post_meta($post_id, '_rts_subscriber_verification_sent', current_time('mysql'));
            update_post_meta($post_id, '_rts_subscriber_status', 'pending_verification');
        }

        if (!empty($args['ip_address'])) {
            update_post_meta($post_id, '_rts_subscriber_ip_address', sanitize_text_field($args['ip_address']));
        }
        
        if (!empty($args['user_agent'])) {
            update_post_meta($post_id, '_rts_subscriber_user_agent', sanitize_text_field($args['user_agent']));
        }
        
        // GDPR consent log
        $consent_log = array(
            'subscribed' => array(
                'timestamp'  => current_time('mysql'),
                'ip'         => $args['ip_address'] ?? '',
                'user_agent' => $args['user_agent'] ?? '',
                'via'        => $source
            )
        );
        update_post_meta($post_id, '_rts_subscriber_consent_log', $consent_log);
        
        return $post_id;
    }

    /**
     * Update consent log for GDPR compliance.
     * @param int    $subscriber_id
     * @param string $action
     * @param array  $data Should include 'ip' and 'user_agent' provided by controller
     */
    public function update_consent_log($subscriber_id, $action, $data = array()) {
        $log = get_post_meta($subscriber_id, '_rts_subscriber_consent_log', true) ?: array();
        
        $entry = array_merge(array(
            'timestamp' => current_time('mysql'),
        ), $data);
        
        $log[$action] = $entry;
        
        update_post_meta($subscriber_id, '_rts_subscriber_consent_log', $log);
    }

    /**
     * Verify a subscriber via token.
     *
     * @param string $token Verification token.
     * @param string $ip Optional IP for logging.
     * @param string $user_agent Optional UA for logging.
     * @return int|false Subscriber ID on success, false on failure.
     */
    public function verify_subscriber($token, $ip = '', $user_agent = '') {
        global $wpdb;
        
        $token = sanitize_text_field($token);
        
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_rts_subscriber_verification_token' 
             AND meta_value = %s 
             LIMIT 1",
            $token
        ));

        if ($post_id) {
            update_post_meta($post_id, '_rts_subscriber_verified', 1);
            update_post_meta($post_id, '_rts_subscriber_status', 'active');
            delete_post_meta($post_id, '_rts_subscriber_verification_token');
            
            // Log verification event
            $this->update_consent_log($post_id, 'verified', array(
                'method'     => 'email_token',
                'ip'         => $ip ?: ($_SERVER['REMOTE_ADDR'] ?? ''),
                'user_agent' => $user_agent ?: ($_SERVER['HTTP_USER_AGENT'] ?? ''),
            ));
            
            return intval($post_id);
        }

        return false;
    }
    
    private function generate_unique_token() {
        global $wpdb;
        
        do {
            $token = bin2hex(random_bytes(16));
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                 WHERE meta_key = '_rts_subscriber_token' AND meta_value = %s",
                $token
            ));
        } while ($exists);
        
        return $token;
    }
    
    public function get_subscriber_by_email($email, $exclude_id = 0) {
        global $wpdb;

        $email = sanitize_email($email);
        if (!is_email($email)) {
            return 0;
        }

        // Direct meta query is faster/cleaner than joining posts table
        // We assume _rts_subscriber_email is the source of truth
        $query = $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
             WHERE meta_key = '_rts_subscriber_email' 
             AND meta_value = %s
             AND post_id != %d
             LIMIT 1",
            $email,
            intval($exclude_id)
        );

        $id = $wpdb->get_var($query);

        // Fallback: Check post title if meta migration isn't complete or consistent
        if (!$id) {
            $query = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} 
                 WHERE post_type = 'rts_subscriber' 
                 AND post_title = %s 
                 AND post_status != 'trash'
                 AND ID != %d
                 LIMIT 1",
                $email,
                intval($exclude_id)
            );
            $id = $wpdb->get_var($query);
        }

        return intval($id);
    }

    public function get_subscriber_by_token($token) {
        global $wpdb;
        
        $post_id = $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_rts_subscriber_token'
             AND meta_value = %s
             LIMIT 1",
            sanitize_text_field($token)
        ));
        
        if ($post_id) {
            return get_post($post_id);
        }
        
        return null;
    }
    
    public function export_subscriber_data($subscriber_id, $format = 'array') {
        $post = get_post($subscriber_id);
        if (!$post || $post->post_type !== 'rts_subscriber') {
            return new WP_Error('invalid_subscriber', 'Invalid subscriber');
        }
        
        global $wpdb;
        $logs_table = $wpdb->prefix . 'rts_email_logs';
        
        $email_history = array();
        // Check if table exists first to avoid errors
        if ($this->table_exists('rts_email_logs')) {
            $email_history = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$logs_table} WHERE subscriber_id = %d ORDER BY sent_at DESC LIMIT 100",
                $subscriber_id
            ));
        }

        $data = array(
            'basic_information' => array(
                'email'           => $post->post_title,
                'status'          => get_post_meta($subscriber_id, '_rts_subscriber_status', true),
                'frequency'       => get_post_meta($subscriber_id, '_rts_subscriber_frequency', true),
                'subscribed_date' => get_post_meta($subscriber_id, '_rts_subscriber_subscribed_date', true),
                'source'          => get_post_meta($subscriber_id, '_rts_subscriber_source', true),
                'ip_address'      => get_post_meta($subscriber_id, '_rts_subscriber_ip_address', true),
            ),
            'consent_history'   => get_post_meta($subscriber_id, '_rts_subscriber_consent_log', true),
            'email_history'     => $email_history,
        );
        
        $data = apply_filters('rts_subscriber_export_data', $data, $subscriber_id);

        if ($format === 'json') {
            return wp_json_encode($data, JSON_PRETTY_PRINT);
        } elseif ($format === 'csv') {
            return $this->flatten_for_csv($data);
        }

        return $data;
    }

    /**
     * Helper to flatten export data for CSV format.
     */
    private function flatten_for_csv($data) {
        // Prepare translatable headers
        $headers = array(
            'email'             => __('Email', 'rts-subscriber-system'),
            'status'            => __('Status', 'rts-subscriber-system'),
            'frequency'         => __('Frequency', 'rts-subscriber-system'),
            'subscribed_date'   => __('Subscribed Date', 'rts-subscriber-system'),
            'source'            => __('Source', 'rts-subscriber-system'),
            'ip_address'        => __('IP Address', 'rts-subscriber-system'),
            'consent_timestamp' => __('Consent Timestamp', 'rts-subscriber-system'),
            'consent_ip'        => __('Consent IP', 'rts-subscriber-system'),
        );

        $flat = $data['basic_information'];
        
        if (!empty($data['consent_history']['subscribed'])) {
            $flat['consent_timestamp'] = $data['consent_history']['subscribed']['timestamp'] ?? '';
            $flat['consent_ip']        = $data['consent_history']['subscribed']['ip'] ?? '';
        }

        // CSV Injection Protection: Escape cells starting with specific characters
        $safe_flat = array_map(function($value) {
            $value = (string)$value;
            // If starts with =, +, -, @
            if (preg_match('/^[=+\-@]/', $value)) {
                $value = "'" . $value;
            }
            // Enclose in quotes if contains comma, newline, or quote
            if (preg_match('/[,"\n\r]/', $value)) {
                $value = '"' . str_replace('"', '""', $value) . '"';
            }
            return $value;
        }, $flat);

        // Map safe values to headers order
        $ordered_values = array();
        foreach (array_keys($headers) as $key) {
            $ordered_values[] = isset($safe_flat[$key]) ? $safe_flat[$key] : '';
        }

        // Headers row
        $csv = implode(',', array_values($headers)) . "\n";
        // Values row
        $csv .= implode(',', $ordered_values);
        
        return $csv;
    }
    
    public function delete_subscriber_data($subscriber_id) {
        global $wpdb;
        
        // Anonymize
        wp_update_post(array(
            'ID'         => $subscriber_id,
            'post_title' => 'deleted-' . $subscriber_id . '@example.com',
            'post_name'  => 'deleted-' . $subscriber_id,
        ));
        
        update_post_meta($subscriber_id, '_rts_subscriber_status', 'deleted');
        delete_post_meta($subscriber_id, '_rts_subscriber_email');
        delete_post_meta($subscriber_id, '_rts_subscriber_token');
        delete_post_meta($subscriber_id, '_rts_subscriber_ip_address');
        delete_post_meta($subscriber_id, '_rts_subscriber_user_agent');
        
        // Delete from queue and logs if tables exist
        $queue_table = $wpdb->prefix . 'rts_email_queue';
        if ($this->table_exists('rts_email_queue')) {
            $wpdb->delete($queue_table, array('subscriber_id' => $subscriber_id));
        }

        $logs_table = $wpdb->prefix . 'rts_email_logs';
        if ($this->table_exists('rts_email_logs')) {
            $wpdb->delete($logs_table, array('subscriber_id' => $subscriber_id));
        }
        
        return true;
    }
    
    public function set_custom_columns($columns) {
        $new_columns = array(
            'cb'         => $columns['cb'],
            'email'      => 'Email',
            'status'     => 'Status',
            'frequency'  => 'Frequency',
            'prefs'      => 'Subscriptions',
            'last_sent'  => 'Last Sent',
            'total_sent' => 'Total Sent',
            'source'     => 'Source',
            'date'       => 'Subscribed',
        );
        return $new_columns;
    }
    
    public function custom_column_content($column, $post_id) {
        global $wp_list_table, $wpdb;

        // Bulk Fetch Optimization
        // If we are in the list table loop, fetch all metadata for current items once
        $cache_key = 'rts_subscriber_meta_bulk';
        $all_meta = wp_cache_get($cache_key);

        if ($all_meta === false) {
            $all_meta = array();
            $post_ids = array();

            // Try to find IDs from the global WP_Query if available
            // This logic detects if we are in the main loop of the admin screen
            global $wp_query;
            if (!empty($wp_query->posts)) {
                foreach ($wp_query->posts as $p) {
                    $post_ids[] = $p->ID;
                }
            }

            if (!empty($post_ids)) {
                // Efficient single query for all meta rows of these posts
                // Use prepare with dynamic IN clause
                $placeholders = implode(',', array_fill(0, count($post_ids), '%d'));
                $query = $wpdb->prepare(
                    "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN ($placeholders)",
                    $post_ids
                );
                $results = $wpdb->get_results($query);
                
                foreach ($results as $row) {
                    $all_meta[$row->post_id][$row->meta_key][] = $row->meta_value;
                }
            }
            // Store for this request (short expiry is fine)
            wp_cache_set($cache_key, $all_meta, '', 300);
        }

        $meta = isset($all_meta[$post_id]) ? $all_meta[$post_id] : array();
        
        // Fallback if cache missed for this specific ID (e.g. single post edits)
        if (empty($meta)) {
            $meta = get_post_meta($post_id);
        }

        switch ($column) {
            case 'email':
                echo esc_html(get_the_title($post_id));
                break;
            case 'status':
                $status = isset($meta['_rts_subscriber_status'][0]) ? $meta['_rts_subscriber_status'][0] : '';
                $colors = array('active' => '#22c55e', 'paused' => '#f59e0b', 'unsubscribed' => '#ef4444', 'bounced' => '#dc2626', 'pending_verification' => '#f59e0b', 'deleted' => '#000000');
                echo '<span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:' . ($colors[$status] ?? '#6b7280') . ';margin-right:8px;"></span>';
                echo esc_html(ucfirst(str_replace('_', ' ', $status)));
                break;
            case 'frequency':
                $freq = isset($meta['_rts_subscriber_frequency'][0]) ? $meta['_rts_subscriber_frequency'][0] : '';
                echo esc_html(ucfirst($freq));
                break;
            case 'prefs':
                $letters = isset($meta['_rts_pref_letters'][0]) ? (int)$meta['_rts_pref_letters'][0] : 0;
                $news = isset($meta['_rts_pref_newsletters'][0]) ? (int)$meta['_rts_pref_newsletters'][0] : 0;
                $badges = array();
                if ($letters) {
                    $badges[] = '<span style="display:inline-block;padding:3px 8px;border-radius:999px;background:rgba(244,201,70,0.18);color:#F4C946;font-weight:700;font-size:12px;">Letters</span>';
                }
                if ($news) {
                    $badges[] = '<span style="display:inline-block;padding:3px 8px;border-radius:999px;background:rgba(223,21,124,0.18);color:#DF157C;font-weight:700;font-size:12px;">Newsletters</span>';
                }
                if (empty($badges)) {
                    echo '<span style="color:#999;">None</span>';
                } else {
                    echo implode(' ', $badges);
                }
                break;
            case 'last_sent':
    $last = '';
    $logs_table = $wpdb->prefix . 'rts_email_logs';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $logs_table)) === $logs_table) {
        $last = $wpdb->get_var($wpdb->prepare(
            "SELECT sent_at FROM {$logs_table} WHERE subscriber_id = %d AND status = 'sent' ORDER BY sent_at DESC LIMIT 1",
            intval($post_id)
        ));
    }
    // Fallback to meta if logs table unavailable
    if (!$last) {
        $last = isset($meta['_rts_subscriber_last_sent'][0]) ? $meta['_rts_subscriber_last_sent'][0] : '';
    }
    echo $last ? esc_html(date('M j, Y', strtotime($last))) : '—';
    break;
            case 'total_sent':
                $total = isset($meta['_rts_subscriber_total_sent'][0]) ? $meta['_rts_subscriber_total_sent'][0] : '0';
                echo esc_html($total);
                break;
            case 'source':
                $src = isset($meta['_rts_subscriber_source'][0]) ? $meta['_rts_subscriber_source'][0] : '';
                echo esc_html($src);
                break;
        }
    }
    
    public function sortable_columns($columns) {
        $columns['status'] = 'status';
        $columns['frequency'] = 'frequency';
        return $columns;
    }
    
    public function add_filters($post_type) {
        if ($post_type !== 'rts_subscriber') return;
        
        // Preserve pagination when filtering
        if (!empty($_GET['paged'])) {
            echo '<input type="hidden" name="paged" value="' . absint($_GET['paged']) . '">';
        }

        // Status filter
        $status = isset($_GET['subscriber_status']) ? sanitize_text_field($_GET['subscriber_status']) : '';
        echo '<select name="subscriber_status">';
        echo '<option value="">All Statuses</option>';
        $statuses = array('active' => 'Active', 'paused' => 'Paused', 'unsubscribed' => 'Unsubscribed', 'bounced' => 'Bounced', 'pending_verification' => 'Pending Verification', 'deleted' => 'Deleted');
        foreach ($statuses as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($status, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        
        

// Smart segment filter
$segment = isset($_GET['subscriber_segment']) ? sanitize_text_field($_GET['subscriber_segment']) : '';
echo '<select name="subscriber_segment">';
echo '<option value="">Smart Segments</option>';
$segments = array(
    'bounced' => 'Bounced',
    'highly_engaged' => 'Highly Engaged (50+)',
    'new' => 'New (last 7 days)',
);
foreach ($segments as $key => $label) {
    echo '<option value="' . esc_attr($key) . '"' . selected($segment, $key, false) . '>' . esc_html($label) . '</option>';
}
echo '</select>';

// Frequency filter
        $freq = isset($_GET['subscriber_frequency']) ? sanitize_text_field($_GET['subscriber_frequency']) : '';
        echo '<select name="subscriber_frequency">';
        echo '<option value="">All Frequencies</option>';
        $frequencies = array('daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly');
        foreach ($frequencies as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($freq, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';

        // Subscription preferences filter
        $prefs = isset($_GET['subscriber_prefs']) ? sanitize_text_field($_GET['subscriber_prefs']) : '';
        echo '<select name="subscriber_prefs">';
        echo '<option value="">All Subscriptions</option>';
        $opts = array(
            'letters' => 'Letters only',
            'newsletters' => 'Newsletters only',
            'both' => 'Letters + Newsletters',
            'none' => 'No subscriptions',
        );
        foreach ($opts as $key => $label) {
            echo '<option value="' . esc_attr($key) . '"' . selected($prefs, $key, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }
    
    public function filter_query($query) {
        global $pagenow, $typenow;
        
        // Securely ensure we are in the admin edit screen for this CPT
        if (is_admin() && $pagenow === 'edit.php' && $typenow === 'rts_subscriber' && $query->is_main_query()) {
            $meta_query = array();
            
            // Check if any filter is active, if so, reset page to 1
            if (!empty($_GET['subscriber_status']) || !empty($_GET['subscriber_frequency']) || !empty($_GET['subscriber_prefs']) || !empty($_GET['subscriber_segment'])) {
                $query->set('paged', 1);
            }

            if (!empty($_GET['subscriber_status'])) {
                $status = sanitize_text_field($_GET['subscriber_status']);
                // Allowlist check for security
                $allowed = array('active', 'paused', 'unsubscribed', 'bounced', 'pending_verification', 'deleted');
                if (in_array($status, $allowed, true)) {
                    $meta_query[] = array(
                        'key'   => '_rts_subscriber_status',
                        'value' => $status,
                    );
                }
            }
            
            if (!empty($_GET['subscriber_frequency'])) {
                $freq = sanitize_text_field($_GET['subscriber_frequency']);
                $allowed = array('daily', 'weekly', 'monthly');
                if (in_array($freq, $allowed, true)) {
                    $meta_query[] = array(
                        'key'   => '_rts_subscriber_frequency',
                        'value' => $freq,
                    );
                }
            }

            if (!empty($_GET['subscriber_prefs'])) {
                $pref = sanitize_text_field($_GET['subscriber_prefs']);
                if ($pref === 'letters') {
                    $meta_query[] = array('key' => '_rts_pref_letters', 'value' => '1');
                } elseif ($pref === 'newsletters') {
                    $meta_query[] = array('key' => '_rts_pref_newsletters', 'value' => '1');
                } elseif ($pref === 'both') {
                    $meta_query[] = array('key' => '_rts_pref_letters', 'value' => '1');
                    $meta_query[] = array('key' => '_rts_pref_newsletters', 'value' => '1');
                } elseif ($pref === 'none') {
                    $meta_query[] = array(
                        'relation' => 'AND',
                        array(
                            'relation' => 'OR',
                            array('key' => '_rts_pref_letters', 'compare' => 'NOT EXISTS'),
                            array('key' => '_rts_pref_letters', 'value' => '1', 'compare' => '!='),
                        ),
                        array(
                            'relation' => 'OR',
                            array('key' => '_rts_pref_newsletters', 'compare' => 'NOT EXISTS'),
                            array('key' => '_rts_pref_newsletters', 'value' => '1', 'compare' => '!='),
                        )
                    );
                }
            }

// Smart segment filters
if (!empty($_GET['subscriber_segment'])) {
    $seg = sanitize_text_field($_GET['subscriber_segment']);
    if ($seg === 'bounced') {
        $meta_query[] = array('key' => '_rts_subscriber_status', 'value' => 'bounced');
    } elseif ($seg === 'highly_engaged') {
        $meta_query[] = array(
            'key'     => '_rts_subscriber_total_sent',
            'value'   => 50,
            'compare' => '>=',
            'type'    => 'NUMERIC',
        );
    } elseif ($seg === 'new') {
        $meta_query[] = array(
            'key'     => '_rts_subscriber_created_at',
            'value'   => date('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS)),
            'compare' => '>=',
            'type'    => 'DATETIME',
        );
    }
}


            
            if (!empty($meta_query)) {
                $query->set('meta_query', $meta_query);
            }
        }
    }
    
    public function register_bulk_actions($actions) {
        $actions['change_to_daily'] = 'Change to Daily';
        $actions['change_to_weekly'] = 'Change to Weekly';
        $actions['change_to_monthly'] = 'Change to Monthly';
        $actions['pause_subscribers'] = 'Pause';
        $actions['activate_subscribers'] = 'Activate';
        $actions['unsubscribe_subscribers'] = 'Unsubscribe';
        return $actions;
    }
    
    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        $count = 0;
        
        if (strpos($action, 'change_to_') === 0) {
            $frequency = str_replace('change_to_', '', $action);
            foreach ($post_ids as $post_id) {
                update_post_meta($post_id, '_rts_subscriber_frequency', $frequency);
                $count++;
            }
            $redirect_to = add_query_arg('bulk_updated', $count, $redirect_to);
        } elseif ($action === 'pause_subscribers') {
            foreach ($post_ids as $post_id) {
                update_post_meta($post_id, '_rts_subscriber_status', 'paused');
                $count++;
            }
            $redirect_to = add_query_arg('bulk_paused', $count, $redirect_to);
        } elseif ($action === 'activate_subscribers') {
            foreach ($post_ids as $post_id) {
                update_post_meta($post_id, '_rts_subscriber_status', 'active');
                $count++;
            }
            $redirect_to = add_query_arg('bulk_activated', $count, $redirect_to);
        } elseif ($action === 'unsubscribe_subscribers') {
            foreach ($post_ids as $post_id) {
                update_post_meta($post_id, '_rts_subscriber_status', 'unsubscribed');
                $count++;
            }
            $redirect_to = add_query_arg('bulk_unsubscribed', $count, $redirect_to);
        }
        
        return $redirect_to;
    }

    public function bulk_action_admin_notices() {
        if (!empty($_GET['bulk_updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(__('%d subscribers updated.', 'rts-subscriber-system'), intval($_GET['bulk_updated'])) . '</p></div>';
        }
        if (!empty($_GET['bulk_paused'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(__('%d subscribers paused.', 'rts-subscriber-system'), intval($_GET['bulk_paused'])) . '</p></div>';
        }
        if (!empty($_GET['bulk_activated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(__('%d subscribers activated.', 'rts-subscriber-system'), intval($_GET['bulk_activated'])) . '</p></div>';
        }
        if (!empty($_GET['bulk_unsubscribed'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(__('%d subscribers unsubscribed.', 'rts-subscriber-system'), intval($_GET['bulk_unsubscribed'])) . '</p></div>';
        }
    }

    /**
     * Get statistics for subscribers.
     * @return array
     */
    public function get_subscriber_stats() {
        global $wpdb;
        
        $stats = array(
            'total'   => wp_count_posts('rts_subscriber')->publish,
            'active'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_rts_subscriber_status' AND meta_value = 'active'"),
            'pending' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_rts_subscriber_status' AND meta_value = 'pending_verification'"),
            'paused'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_rts_subscriber_status' AND meta_value = 'paused'"),
        );

        return apply_filters('rts_subscriber_stats', $stats);
    }

    /**
     * Update subscriber preferences meta.
     * @param int $subscriber_id
     * @param array $prefs Key/value array of preferences.
     */
    public function update_subscriber_prefs($subscriber_id, $prefs) {
        $allowed = array('letters', 'newsletters', 'frequency', 'status');
        foreach ($allowed as $key) {
            if (isset($prefs[$key])) {
                if (in_array($key, array('letters', 'newsletters'))) {
                    // Boolean types stored as 1 or 0
                    update_post_meta($subscriber_id, '_rts_pref_' . $key, $prefs[$key] ? 1 : 0);
                } else {
                    // String types
                    // Effectively _rts_subscriber_status or _rts_subscriber_frequency
                    $meta_key = '_rts_subscriber_' . $key;
                    update_post_meta($subscriber_id, $meta_key, sanitize_text_field($prefs[$key]));
                }
            }
        }
    }

    /**
     * Title placeholder (we treat post title as the subscriber email).
     */
    public function filter_title_placeholder($text, $post) {
        if (!($post instanceof WP_Post)) {
            return $text;
        }
        if ($post->post_type !== 'rts_subscriber') {
            return $text;
        }
        return 'Email address (this will become the subscriber record title)';
    }

    /**
     * Register meta boxes for Add/Edit Subscriber.
     */
    public function register_metaboxes() {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'rts_subscriber') {
            return;
        }

        add_meta_box(
            'rts_subscriber_details',
            'Subscriber Details',
            array($this, 'render_subscriber_details_metabox'),
            'rts_subscriber',
            'normal',
            'high'
        );

        // Keep core submit box (so saving stays native) but make it feel like a form,
        // not a “publish a post” flow.
        add_action('admin_head', array($this, 'admin_ui_css_tweaks'));
    }

    public function render_subscriber_details_metabox($post) {
        wp_nonce_field('rts_subscriber_details_save', 'rts_subscriber_details_nonce');

        $email = get_post_meta($post->ID, '_rts_subscriber_email', true);
        if (!$email) {
            // Back-compat: many imports store email as the title.
            $email = $post->post_title;
        }

        $freq = get_post_meta($post->ID, '_rts_subscriber_frequency', true);
        if (!$freq) {
            $freq = 'weekly';
        }
        $pref_letters = (int) get_post_meta($post->ID, '_rts_pref_letters', true);
        $pref_news    = (int) get_post_meta($post->ID, '_rts_pref_newsletters', true);
        $status = get_post_meta($post->ID, '_rts_subscriber_status', true);
        if (!$status) {
            $status = 'active';
        }

        echo '<div class="rts-subscriber-metabox">';
        echo '<p class="description" style="margin-top:0;">Create a subscriber record. The title is automatically set to the email address.</p>';

        echo '<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:900px;">';

        echo '<div>';
        echo '<label for="rts_subscriber_email" style="display:block;font-weight:600;margin-bottom:6px;">Email address</label>';
        echo '<input type="email" id="rts_subscriber_email" name="rts_subscriber_email" value="' . esc_attr($email) . '" class="regular-text" style="width:100%;max-width:100%;" placeholder="name@example.com" required />';
        echo '</div>';

        echo '<div>';
        echo '<label for="rts_subscriber_frequency" style="display:block;font-weight:600;margin-bottom:6px;">Letter digest frequency</label>';
        echo '<select id="rts_subscriber_frequency" name="rts_subscriber_frequency" style="width:100%;max-width:100%;">';
        foreach (array('daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly') as $k => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($freq, $k, false), esc_html($label));
        }
        echo '</select>';
        echo '</div>';

        echo '<div>';
        echo '<label style="display:block;font-weight:600;margin-bottom:6px;">Subscriptions</label>';
        echo '<label style="display:flex;gap:10px;align-items:center;margin:8px 0;">'
            . '<input type="checkbox" name="rts_pref_letters" value="1" ' . checked(1, $pref_letters, false) . ' />'
            . '<span>Letters digest</span>'
            . '</label>';
        echo '<label style="display:flex;gap:10px;align-items:center;margin:8px 0;">'
            . '<input type="checkbox" name="rts_pref_newsletters" value="1" ' . checked(1, $pref_news, false) . ' />'
            . '<span>Newsletters</span>'
            . '</label>';
        echo '</div>';

        echo '<div>';
        echo '<label for="rts_subscriber_status" style="display:block;font-weight:600;margin-bottom:6px;">Status</label>';
        echo '<select id="rts_subscriber_status" name="rts_subscriber_status" style="width:100%;max-width:100%;">';
        foreach (array('active' => 'Active', 'paused' => 'Paused', 'unsubscribed' => 'Unsubscribed') as $k => $label) {
            printf('<option value="%s" %s>%s</option>', esc_attr($k), selected($status, $k, false), esc_html($label));
        }
        echo '</select>';
        echo '</div>';

        echo '</div>'; // grid
        echo '</div>'; // wrapper
    }

    /**
     * Save meta box values and keep title synced to email.
     */
    public function save_subscriber_details($post_id, $post) {
        if (!isset($_POST['rts_subscriber_details_nonce']) || !wp_verify_nonce($_POST['rts_subscriber_details_nonce'], 'rts_subscriber_details_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $email = isset($_POST['rts_subscriber_email']) ? sanitize_email(wp_unslash($_POST['rts_subscriber_email'])) : '';
        if ($email && is_email($email)) {
            update_post_meta($post_id, '_rts_subscriber_email', $email);

            // Always keep title as email for search + list display.
            if ($post->post_title !== $email) {
                remove_action('save_post_rts_subscriber', array($this, 'save_subscriber_details'), 10);
                wp_update_post(array(
                    'ID'         => $post_id,
                    'post_title' => $email,
                    'post_name'  => sanitize_title($email),
                ));
                add_action('save_post_rts_subscriber', array($this, 'save_subscriber_details'), 10, 2);
            }
        }

        $freq = isset($_POST['rts_subscriber_frequency']) ? sanitize_text_field(wp_unslash($_POST['rts_subscriber_frequency'])) : 'weekly';
        if (!in_array($freq, array('daily','weekly','monthly'), true)) {
            $freq = 'weekly';
        }
        update_post_meta($post_id, '_rts_subscriber_frequency', $freq);

        $status = isset($_POST['rts_subscriber_status']) ? sanitize_text_field(wp_unslash($_POST['rts_subscriber_status'])) : 'active';
        if (!in_array($status, array('active','paused','unsubscribed'), true)) {
            $status = 'active';
        }
        update_post_meta($post_id, '_rts_subscriber_status', $status);

        update_post_meta($post_id, '_rts_pref_letters', isset($_POST['rts_pref_letters']) ? 1 : 0);
        update_post_meta($post_id, '_rts_pref_newsletters', isset($_POST['rts_pref_newsletters']) ? 1 : 0);

        // This is a data record, not editorial content.
        if ($post->post_status !== 'publish') {
            remove_action('save_post_rts_subscriber', array($this, 'save_subscriber_details'), 10);
            wp_update_post(array('ID' => $post_id, 'post_status' => 'publish'));
            add_action('save_post_rts_subscriber', array($this, 'save_subscriber_details'), 10, 2);
        }
    }

    /**
     * Back-compat alias.
     *
     * Some versions of the theme hooked save_post to save_metaboxes().
     * The actual save logic lives in save_subscriber_details().
     *
     * @param int     $post_id
     * @param WP_Post $post
     * @return void
     */
    public function save_metaboxes($post_id, $post) {
        $this->save_subscriber_details($post_id, $post);
    }

    /**
     * Inline admin CSS tweaks to make Add/Edit Subscriber feel like a form.
     */
    public function admin_ui_css_tweaks() {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'rts_subscriber') {
            return;
        }
        echo '<style>
            /* Hide the core title UI (we use our meta box email field instead) */
            #titlediv { display:none !important; }
            /* Make the sidebar look like a form action box */
            #submitdiv .hndle, #submitdiv h2 { font-size:14px; }
            #minor-publishing, #misc-publishing-actions, #minor-publishing-actions { display:none !important; }
            #submitdiv .button.button-primary { width:100%; font-size:14px; padding:10px 14px; }
            #submitdiv .edit-post-status, #submitdiv .edit-visibility, #submitdiv .edit-timestamp { display:none !important; }
        </style>';
    }
}