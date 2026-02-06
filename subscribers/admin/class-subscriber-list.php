<?php
/**
 * Subscriber List Table
 *
 * NOTE: This file was previously a placeholder. The Subscribers system uses the
 * native CPT list screen for most workflows, but we keep a proper WP_List_Table
 * implementation here so the admin pages can switch to a custom list view
 * without needing to re-architect later.
 *
 * This class is intentionally conservative: no destructive actions, no bulk
 * sending, no side effects. It only renders a list.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class RTS_Subscriber_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct(array(
            'singular' => 'rts_subscriber',
            'plural'   => 'rts_subscribers',
            'ajax'     => false,
        ));
    }

    public function get_columns() {
        return array(
            'email'        => __('Email', 'rts-subscriber-system'),
            'status'       => __('Status', 'rts-subscriber-system'),
            'frequency'    => __('Frequency', 'rts-subscriber-system'),
            'subscriptions'=> __('Subscriptions', 'rts-subscriber-system'),
            'last_sent'    => __('Last Sent', 'rts-subscriber-system'),
            'total_sent'   => __('Total Sent', 'rts-subscriber-system'),
            'date'         => __('Subscribed', 'rts-subscriber-system'),
        );
    }

    protected function column_default($item, $column_name) {
        return isset($item[$column_name]) ? $item[$column_name] : '';
    }

    protected function column_email($item) {
        $email = sanitize_email($item['email'] ?? '');
        if (!$email) return '';

        $post_id = intval($item['post_id'] ?? 0);
        $edit = $post_id ? get_edit_post_link($post_id, '') : '';
        if ($edit) {
            return '<a href="' . esc_url($edit) . '">' . esc_html($email) . '</a>';
        }
        return esc_html($email);
    }

    public function prepare_items() {
        $per_page = 20;
        $paged = max(1, intval($_GET['paged'] ?? 1));

        $args = array(
            'post_type'      => 'rts_subscriber',
            'post_status'    => array('publish', 'draft'),
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        );

        $q = new WP_Query($args);
        $items = array();

        foreach ($q->posts as $post_id) {
            $email = get_post_meta($post_id, '_rts_email', true);
            $status = get_post_meta($post_id, '_rts_subscriber_status', true);
            $frequency = get_post_meta($post_id, '_rts_subscriber_frequency', true);
            $subs = get_post_meta($post_id, '_rts_subscriptions', true);
            if (is_array($subs)) {
                $subs = implode(', ', array_map('sanitize_text_field', $subs));
            }

            $items[] = array(
                'post_id'      => $post_id,
                'email'        => $email,
                'status'       => $status ?: 'active',
                'frequency'    => $frequency ?: 'weekly',
                'subscriptions'=> $subs ?: 'letters, newsletters',
                'last_sent'    => get_post_meta($post_id, '_rts_last_sent', true) ?: '—',
                'total_sent'   => intval(get_post_meta($post_id, '_rts_total_sent', true) ?: 0),
                'date'         => get_the_date('Y-m-d', $post_id),
            );
        }

        $this->items = $items;

        $this->set_pagination_args(array(
            'total_items' => intval($q->found_posts),
            'per_page'    => $per_page,
            'total_pages' => max(1, intval($q->max_num_pages)),
        ));

        $this->_column_headers = array($this->get_columns(), array(), array());
    }
}
