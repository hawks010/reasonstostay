<?php
/**
 * RTS Analytics
 *
 * Tracks and reports subscriber statistics and analytics.
 *
 * @package    RTS_Subscriber_System
 * @subpackage Analytics
 * @version    1.0.2
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
     * Cache expiration in seconds (1 hour)
     */
    const CACHE_EXPIRATION = HOUR_IN_SECONDS;

    /**
     * Get comprehensive subscriber statistics
     *
     * @return array Array of subscriber statistics
     */
    public function get_subscriber_stats() {
        // Check cache first
        $cached_stats = get_transient(self::STATS_CACHE_KEY);
        if (false !== $cached_stats) {
            return $cached_stats;
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

            // Cache the results
            set_transient(self::STATS_CACHE_KEY, $stats, self::CACHE_EXPIRATION);

        } catch (Exception $e) {
            // Log error if needed
            error_log('RTS Analytics Error: ' . $e->getMessage());
            
            // Return at least the basic structure
            $stats['error'] = true;
        }

        return $stats;
    }

    /**
     * Get high-level summary metrics
     * * @return array Calculated rates and top metrics
     */
    public function get_summary() {
        $stats = $this->get_subscriber_stats();

        if (isset($stats['error'])) {
            return array();
        }

        // Avoid division by zero
        $total = $stats['total'] > 0 ? $stats['total'] : 1;

        // Calculate most popular frequency
        $freqs = $stats['digest_frequency'];
        $popular_freq = array_search(max($freqs), $freqs);

        return array(
            'total_subscribers'      => $stats['total'],
            'active_rate'            => round(($stats['active'] / $total) * 100, 1),
            'verification_rate'      => round(($stats['verified'] / $total) * 100, 1),
            'most_popular_frequency' => $popular_freq,
        );
    }

    /**
     * Get breakdown of subscribers by status
     * * @return array Status counts
     */
    private function get_status_breakdown() {
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
     * * Uses a wildcard deletion to remove main stats and all dynamic growth keys.
     * * @return bool True on success
     */
    public function clear_all_caches() {
        global $wpdb;

        $this->clear_cache(); // Clear main key

        // Delete all transients matching the prefix (handling growth keys)
        // Note: We delete both the data and the timeout options
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE %s 
            OR option_name LIKE %s",
            '_transient_' . self::STATS_CACHE_KEY . '%',
            '_transient_timeout_' . self::STATS_CACHE_KEY . '%'
        ));

        return true;
    }

    /**
     * Get subscriber growth over time (last 30 days)
     *
     * @param int $days Number of days to look back
     * @return array Daily subscriber growth data (Date => Count)
     */
    public function get_growth_stats($days = 30) {
        $days = absint($days);
        $cache_key = self::STATS_CACHE_KEY . '_growth_' . $days;
        
        // Check cache first
        $cached_stats = get_transient($cache_key);
        if (false !== $cached_stats) {
            return $cached_stats;
        }

        global $wpdb;
        $growth = array();
        
        try {
            // Get daily signups for the last X days
            $results = $wpdb->get_results($wpdb->prepare(
                "SELECT DATE(post_date) as signup_date, COUNT(*) as count 
                FROM {$wpdb->posts} 
                WHERE post_type = %s 
                AND post_status = %s 
                AND post_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY) 
                GROUP BY DATE(post_date) 
                ORDER BY signup_date ASC",
                'rts_subscriber',
                'publish',
                $days
            ), ARRAY_A);
            
            // 1. Populate raw data
            $raw_data = array();
            if (!empty($results)) {
                foreach ($results as $row) {
                    $raw_data[$row['signup_date']] = absint($row['count']);
                }
            }

            // 2. Fill in missing dates with 0 (essential for charting)
            // Loop backwards from today to $days ago
            for ($i = $days; $i >= 0; $i--) {
                $date = date('Y-m-d', strtotime("-$i days"));
                $growth[$date] = isset($raw_data[$date]) ? $raw_data[$date] : 0;
            }

            // Cache the results
            set_transient($cache_key, $growth, self::CACHE_EXPIRATION);

        } catch (Exception $e) {
            error_log('RTS Analytics Growth Error: ' . $e->getMessage());
        }
        
        return $growth;
    }
}