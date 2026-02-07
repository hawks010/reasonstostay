<?php
/**
 * RTS Analytics
 *
 * Tracks and reports subscriber statistics and analytics.
 *
 * @package    RTS_Subscriber_System
 * @subpackage Analytics
 * @version    1.0.6
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class RTS_Analytics {

    /**
     * Cache key for subscriber stats
     */
    const STATS_CACHE_KEY = 'rts_subscriber_stats';

    /**
     * Cache expiration in seconds.
     *
     * Hotfix: Reduced from 1 hour to 5 minutes so stats stay fresh
     * without hammering the database.
     */
    const CACHE_EXPIRATION = 300; // 5 minutes

    /**
     * Singleton instance
     *
     * @var RTS_Analytics|null
     */
    private static $instance = null;

    /**
     * Get singleton instance
     *
     * @return RTS_Analytics
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserialization
     *
     * @throws Exception If unserialization is attempted.
     */
    public function __wakeup() {
        throw new Exception('Cannot unserialize singleton');
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->setup_hooks();
    }

    /**
     * Setup hooks.
     */
    private function setup_hooks() {
        // Clear cache when subscriber posts are modified
        add_action('save_post_rts_subscriber', [$this, 'clear_all_caches']);
        add_action('delete_post', [$this, 'maybe_clear_caches']);
    }

    /**
     * Maybe clear caches on post delete.
     *
     * @param int $post_id Post ID.
     */
    public function maybe_clear_caches($post_id) {
        if (get_post_type($post_id) === 'rts_subscriber') {
            $this->clear_all_caches();
        }
    }

    /**
     * Get comprehensive subscriber statistics
     *
     * @return array Array of subscriber statistics
     */
    public function get_subscriber_stats($force = false): array {
        // Back-compat: allow array args e.g. ['force' => true]
        if (is_array($force)) {
            $force = !empty($force['force']);
        }

        // Admins (or explicit force) bypass cache.
        $is_admin_user = is_user_logged_in() && current_user_can('manage_options');
        $bypass_cache  = ($force === true) || $is_admin_user;

        // Check cache first unless bypassing
        if (!$bypass_cache) {
            $cached_stats = get_transient(self::STATS_CACHE_KEY);
            if (false !== $cached_stats) {
                return $cached_stats;
            }
        }

        global $wpdb;

        $stats = array(
            'total'      => 0,
            'active'     => 0,
            'verified'   => 0,
            'unverified' => 0,
            'digest_frequency' => array(
                'daily'   => 0,
                'weekly'  => 0,
                'monthly' => 0,
            ),
            'by_status' => array(),
        );

        try {
            // Total subscribers (published posts only)
            $total = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->posts} 
                WHERE post_type = %s 
                AND post_status = %s",
                'rts_subscriber',
                'publish'
            ));
            
            $stats['total'] = absint($total);

            // Active subscribers
            $active = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID) 
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                WHERE p.post_type = %s 
                AND p.post_status = %s 
                AND pm.meta_key = '_rts_subscriber_status' 
                AND pm.meta_value = %s",
                'rts_subscriber',
                'publish',
                'active'
            ));
            
            $stats['active'] = absint($active);

            // Verified vs Unverified
            $verified = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID) 
                FROM {$wpdb->posts} p 
                INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                WHERE p.post_type = %s 
                AND p.post_status = %s 
                AND pm.meta_key = '_rts_subscriber_verified' 
                AND pm.meta_value = %s",
                'rts_subscriber',
                'publish',
                '1'
            ));
            
            $stats['verified'] = absint($verified);
            $stats['unverified'] = $stats['total'] - $stats['verified'];

            // Digest frequency breakdown
            $frequencies = array('daily', 'weekly', 'monthly');
            foreach ($frequencies as $freq) {
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(DISTINCT p.ID) 
                    FROM {$wpdb->posts} p 
                    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                    WHERE p.post_type = %s 
                    AND p.post_status = %s 
                    AND pm.meta_key = '_rts_digest_frequency' 
                    AND pm.meta_value = %s",
                    'rts_subscriber',
                    'publish',
                    $freq
                ));
                
                $stats['digest_frequency'][$freq] = absint($count);
            }

            // Status Breakdown
            $stats['by_status'] = $this->get_status_breakdown();

            // Validate structure and fill missing keys if needed
            if (!$this->validate_stats($stats)) {
                 if (!isset($stats['digest_frequency'])) {
                     $stats['digest_frequency'] = array('daily' => 0, 'weekly' => 0, 'monthly' => 0);
                 }
                 $required = ['total', 'active', 'verified', 'unverified', 'digest_frequency', 'by_status'];
                 foreach ($required as $key) {
                    if (!isset($stats[$key])) {
                        $stats[$key] = ($key === 'by_status' || $key === 'digest_frequency') ? [] : 0;
                    }
                 }
            }

            // Cache the results
            set_transient(self::STATS_CACHE_KEY, $stats, self::CACHE_EXPIRATION);

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('RTS Analytics Error: ' . $e->getMessage());
            }
            
            // Return at least the basic structure with error flag
            $stats['error'] = true;
            // Ensure text domain is loaded or use generic message if unsure
            $stats['error_message'] = __('Unable to retrieve statistics', 'rts');
        }

        return $stats;
    }

    /**
     * Get high-level summary metrics
     *
     * @return array Calculated rates and top metrics
     */
    public function get_summary(): array {
        $stats = $this->get_subscriber_stats();

        // If error or total is null (should be 0 if valid but empty db), return defaults.
        // We allow 0 total subscribers as valid state to return 0 rates.
        if (isset($stats['error']) || !isset($stats['total'])) {
            return array(
                'total_subscribers'      => 0,
                'active_rate'            => 0,
                'verification_rate'      => 0,
                'most_popular_frequency' => 'weekly', // Default
            );
        }

        $total = $stats['total'];
        
        if ($total === 0) {
             return array(
                'total_subscribers'      => 0,
                'active_rate'            => 0,
                'verification_rate'      => 0,
                'most_popular_frequency' => 'weekly',
            );
        }

        // Calculate most popular frequency
        $freqs = $stats['digest_frequency'];
        if (array_sum($freqs) === 0) {
            $popular_freq = 'weekly';
        } else {
            $popular_freq = array_search(max($freqs), $freqs);
            if ($popular_freq === false) {
                $popular_freq = 'weekly';
            }
        }

        return array(
            'total_subscribers'      => $stats['total'],
            'active_rate'            => round(($stats['active'] / $total) * 100, 1),
            'verification_rate'      => round(($stats['verified'] / $total) * 100, 1),
            'most_popular_frequency' => $popular_freq,
        );
    }

    /**
     * Get breakdown of subscribers by status
     *
     * @return array Status counts
     */
    private function get_status_breakdown(): array {
        global $wpdb;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT pm.meta_value as status, COUNT(DISTINCT p.ID) as count 
            FROM {$wpdb->posts} p 
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
            WHERE p.post_type = %s 
            AND p.post_status = %s 
            AND pm.meta_key = '_rts_subscriber_status'
            GROUP BY pm.meta_value",
            'rts_subscriber',
            'publish'
        ), ARRAY_A);
        
        $breakdown = array();
        if (!empty($results)) {
            foreach ($results as $row) {
                $breakdown[$row['status']] = absint($row['count']);
            }
        }
        
        return $breakdown;
    }

    /**
     * Clear analytics cache (Main stats only)
     *
     * @return bool True if cache was cleared
     */
    public function clear_cache() {
        return delete_transient(self::STATS_CACHE_KEY);
    }

    /**
     * Clear ALL analytics caches including growth stats
     * Uses a wildcard deletion to remove main stats and all dynamic growth keys.
     *
     * @return bool True on success
     */
    public function clear_all_caches() {
        global $wpdb;

        $this->clear_cache(); // Clear main key

        // Get all transient names efficiently
        // We trim the '_transient_' prefix to get the actual transient name for delete_transient()
        $keys = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT 
                CASE 
                    WHEN option_name LIKE '_transient_timeout_%%' 
                    THEN SUBSTRING(option_name, 20) 
                    ELSE SUBSTRING(option_name, 12) 
                END as transient_name
            FROM {$wpdb->options} 
            WHERE option_name LIKE %s 
            OR option_name LIKE %s",
            '_transient_' . self::STATS_CACHE_KEY . '%',
            '_transient_timeout_' . self::STATS_CACHE_KEY . '%'
        ));
        
        if (!empty($keys)) {
            foreach ($keys as $transient_name) {
                delete_transient($transient_name);
            }
        }

        return true;
    }

    /**
     * Flush all analytics data and cache.
     * Use with caution - for debugging/reset purposes.
     */
    public function flush() {
        $this->clear_all_caches();
        // Hook for other components to clear their stats if needed
        do_action('rts_analytics_flushed');
    }

    /**
     * Reset all stats (for testing purposes only)
     * * @internal
     */
    public function reset_stats() {
        $this->flush();
        // Could also reset any cumulative counters if you have them
        update_option('rts_analytics_last_reset', time());
    }

    /**
     * Check if analytics system is working.
     *
     * @return array Health status.
     */
    public function health_check() {
        $stats = $this->get_subscriber_stats();
        $growth = $this->get_growth_stats(7); // Last 7 days
        
        return [
            'stats_available'       => !isset($stats['error']),
            'growth_data_available' => !empty($growth),
            'cache_fresh'           => $this->are_stats_fresh(),
            'last_updated'          => $this->get_stats_last_updated(),
            'total_subscribers'     => $stats['total'] ?? 0,
        ];
    }

    /**
     * Check if stats are fresh enough
     *
     * @return bool True if stats are fresh (less than 5 minutes old)
     */
    public function are_stats_fresh() {
        $cache_time = get_option('_transient_timeout_' . self::STATS_CACHE_KEY);
        
        if (!$cache_time) {
            return false;
        }
        
        // Fresh if the transient timeout is still in the future.
        return ($cache_time - time()) > 0;
    }

    /**
     * Get when stats were last updated
     *
     * @return string|false Formatted date or false if not cached
     */
    public function get_stats_last_updated() {
        $timestamp = get_option('_transient_timeout_' . self::STATS_CACHE_KEY);
        
        if (!$timestamp) {
            return false;
        }
        
        // Return human-readable time
        return human_time_diff($timestamp - self::CACHE_EXPIRATION);
    }

    /**
     * Get subscriber growth over time (last 30 days)
     *
     * @param int $days Number of days to look back
     * @return array Daily subscriber growth data (Date => Count)
     */
    public function get_growth_stats(int $days = 30): array {
        $days = absint($days);
        
        // Limit to reasonable range to prevent memory issues
        if ($days < 1) {
            $days = 1;
        }
        if ($days > 365) {
            $days = 365;
        }

        $cache_key = self::STATS_CACHE_KEY . '_growth_' . $days;
        
        // Check cache first
        $cached_stats = get_transient($cache_key);
        if (false !== $cached_stats) {
            return $cached_stats;
        }

        global $wpdb;
        $growth = array();
        
        try {
            // Using simpler query without CONVERT_TZ dependency if possible, 
            // relying on PHP date functions for range generation.
            // Using WordPress offset for day boundary calculation is safer.
            
            $end_date_str = date_i18n('Y-m-d 23:59:59');
            // If days is small, ensure we catch any from 'today' properly if logic is last X days
            $start_date_str = date_i18n('Y-m-d 00:00:00', strtotime("-$days days", current_time('timestamp')));

            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE(post_date) as signup_date, COUNT(*) as count 
                FROM {$wpdb->posts} 
                WHERE post_type = %s 
                AND post_status = %s 
                AND post_date >= %s 
                AND post_date <= %s
                GROUP BY DATE(post_date) 
                ORDER BY signup_date ASC",
                'rts_subscriber',
                'publish',
                $start_date_str,
                $end_date_str
            ), ARRAY_A);
            
            // 1. Populate raw data
            $raw_data = array();
            if (!empty($results)) {
                foreach ($results as $row) {
                    $raw_data[$row['signup_date']] = absint($row['count']);
                }
            }

            // 2. Fill in missing dates with 0 (essential for charting)
            // Use current_time for end date to match WP settings
            $end_date = current_time('Y-m-d');
            $start_date = date('Y-m-d', strtotime("-$days days", current_time('timestamp')));
            
            $date_range = $this->generate_date_range($start_date, $end_date);
            $growth = array_fill_keys($date_range, 0);

            // Then merge with actual data
            foreach ($raw_data as $date => $count) {
                if (isset($growth[$date])) {
                    $growth[$date] = $count;
                }
            }

            // Cache the results
            set_transient($cache_key, $growth, self::CACHE_EXPIRATION);

        } catch (Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('RTS Analytics Growth Error: ' . $e->getMessage());
            }
        }
        
        return $growth;
    }

    /**
     * Helper to generate date range
     *
     * @param string $start Start date (Y-m-d)
     * @param string $end End date (Y-m-d)
     * @return array Array of dates
     */
    private function generate_date_range(string $start, string $end): array {
        $dates = [];
        try {
            $start_dt = new DateTime($start);
            $end_dt = new DateTime($end);
            $end_dt->modify('+1 day'); // Include end date
            
            $interval = new DateInterval('P1D');
            $date_range = new DatePeriod($start_dt, $interval, $end_dt);
            
            foreach ($date_range as $date) {
                $dates[] = $date->format('Y-m-d');
            }
        } catch (Exception $e) {
            // Fallback if DateTime fails
            $current = strtotime($start);
            $end_ts = strtotime($end);
            while ($current <= $end_ts) {
                $dates[] = date('Y-m-d', $current);
                $current = strtotime('+1 day', $current);
            }
        }
        
        return $dates;
    }

    /**
     * Validate stats structure
     *
     * @param array $stats Stats array to validate.
     * @return bool True if valid.
     */
    private function validate_stats(array $stats): bool {
        $required_keys = ['total', 'active', 'verified', 'unverified', 'digest_frequency', 'by_status'];
        
        foreach ($required_keys as $key) {
            if (!array_key_exists($key, $stats)) {
                return false;
            }
        }
        
        // Check frequencies
        if (isset($stats['digest_frequency'])) {
            foreach (['daily', 'weekly', 'monthly'] as $freq) {
                if (!isset($stats['digest_frequency'][$freq])) {
                    return false;
                }
            }
        }
        
        return true;
    }

    /**
     * Debug method to see cache status
     */
    public function debug_cache_status() {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        $stats = $this->get_subscriber_stats();
        $fresh = $this->are_stats_fresh();
        $updated = $this->get_stats_last_updated();
        
        error_log('RTS Analytics Debug:');
        error_log('- Total subscribers: ' . (isset($stats['total']) ? $stats['total'] : 'N/A'));
        error_log('- Cache fresh: ' . ($fresh ? 'Yes' : 'No'));
        error_log('- Last updated: ' . ($updated ? $updated . ' ago' : 'Never'));
        error_log('- Has error: ' . (isset($stats['error']) ? 'Yes' : 'No'));
    }
}