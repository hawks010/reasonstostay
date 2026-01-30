<?php
/**
 * Reasons to Stay - Auto-Tagging & Content Analysis
 * Automatically tags letters and scans for problematic content.
 * Enhanced with performance optimizations (Unified Regex), scoring, caching, safety alerts,
 * queuing, API support, and privacy controls.
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('RTS_Content_Analyzer')) {
    
class RTS_Content_Analyzer {
    
    private static $instance = null;
    
    // Keyword Definitions
    private $feeling_keywords = [
        'hopeless' => ['hopeless', 'despair', 'give up', 'no hope', 'pointless', 'worthless', 'end it all', 'no point', 'not worth', 'meaningless', 'empty', 'void'],
        'alone' => ['alone', 'lonely', 'isolated', 'nobody', 'no one', 'by myself', 'solitary', 'abandoned', 'forgotten', 'left behind', 'invisible'],
        'anxious' => ['anxious', 'anxiety', 'worried', 'panic', 'scared', 'fear', 'nervous', 'stress', 'terrified', 'frightened', 'on edge', 'restless'],
        'grieving' => ['grief', 'loss', 'died', 'death', 'mourn', 'miss', 'lost', 'passed away', 'gone', 'losing', 'lost someone', 'bereaved'],
        'tired' => ['tired', 'exhausted', 'weary', 'drained', 'burnt out', 'can\'t go on', 'giving up', 'worn out', 'no energy', 'depleted', 'fatigued'],
        'struggling' => ['struggling', 'difficult', 'hard', 'tough', 'challenge', 'overwhelmed', 'can\'t cope', 'too much', 'drowning', 'breaking'],
        'depressed' => ['depressed', 'depression', 'sad', 'down', 'low', 'dark', 'darkness', 'numb', 'nothing', 'flat'],
        'hurt' => ['hurt', 'pain', 'ache', 'broken', 'wounded', 'damaged', 'scarred', 'suffering', 'agony'],
        'angry' => ['angry', 'rage', 'mad', 'furious', 'frustrated', 'irritated', 'annoyed', 'bitter', 'resentful'],
        'confused' => ['confused', 'lost', 'don\'t know', 'uncertain', 'unclear', 'mixed up', 'bewildered', 'puzzled'],
        'ashamed' => ['ashamed', 'shame', 'guilty', 'embarrassed', 'humiliated', 'worthless', 'failure', 'not good enough'],
        'unloved' => ['unloved', 'unwanted', 'rejected', 'not wanted', 'don\'t belong', 'outcast', 'unlovable']
    ];
    
    private $tone_keywords = [
        'gentle' => ['understand', 'here for you', 'take your time', 'be kind', 'gentle', 'soft', 'warm', 'caring', 'tender', 'compassion'],
        'real' => ['honestly', 'real talk', 'straight up', 'truth', 'no bullshit', 'real', 'authentic', 'genuine', 'raw', 'honest'],
        'hopeful' => ['hope', 'better', 'tomorrow', 'future', 'brighter', 'light', 'healing', 'recover', 'will get better', 'possible'],
        'encouraging' => ['you can', 'you will', 'keep going', 'don\'t give up', 'you\'re strong', 'you\'ve got this', 'believe in you'],
        'empathetic' => ['I understand', 'I know', 'I\'ve been there', 'me too', 'same here', 'felt that way', 'not alone'],
        'supportive' => ['I\'m here', 'reach out', 'talk to someone', 'get help', 'here to listen', 'support', 'with you']
    ];
    
    private $red_flags = [
        'methods' => ['how to', 'ways to', 'steps to', 'method', 'plan to'],
        'immediate_danger' => ['tonight', 'right now', 'gonna do it', 'going to do it'],
        'graphic' => ['blood', 'pain', 'hurt', 'weapon'],
        'external_links' => ['http://', 'https://', 'www.', '.com', '.org'],
        'contact_info' => ['@', 'phone', 'call me', 'text me', 'whatsapp', 'telegram']
    ];

    private $emergency_keywords = [
        '911' => 'emergency_services',
        'suicide hotline' => 'crisis_line',
        'kill myself' => 'immediate_risk',
        'end my life' => 'immediate_risk',
        'plan to die' => 'immediate_risk',
        'tonight' => 'time_specific',
        'right now' => 'time_specific'
    ];

    // Compiled Regex Patterns
    private $unified_pattern = '';
    private $flag_patterns = [];
    private $performance_metrics = [
        'total_scans' => 0,
        'avg_scan_time' => 0,
    ];
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->compile_patterns();

        // Hooks
        add_action('transition_post_status', [$this, 'auto_tag_on_publish'], 10, 3);
        add_action('save_post_letter', [$this, 'scan_for_problems'], 20, 2);
        
        // Bulk Actions
        add_filter('bulk_actions-edit-letter', [$this, 'add_bulk_actions']);
        add_filter('handle_bulk_actions-edit-letter', [$this, 'handle_bulk_actions'], 10, 3);

        // REST API
        add_action('rest_api_init', [$this, 'register_rest_endpoints']);

        // Scheduled Tasks
        add_action('rts_process_analysis_queue', [$this, 'process_analysis_queue']);
        add_action('rts_optimize_analysis_tables', [$this, 'optimize_analysis_tables']);
        
        // Privacy & GDPR Hooks
        add_filter('wp_privacy_personal_data_exporters', [$this, 'register_privacy_exporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'register_privacy_eraser']);

        // Emergency Escalation Handler
        add_action('rts_escalate_emergency', function($subject, $message, $email) {
            wp_mail($email, '[ESCALATED] ' . $subject, $message);
        }, 10, 3);

        // Schedule maintenance if needed
        $this->schedule_optimization();
    }

    /**
     * Pre-compile regex patterns for optimized scanning
     */
    private function compile_patterns() {
        // 1. Unified Pattern for Feelings & Tone (One-pass scan)
        $all_keywords = [];
        foreach ($this->feeling_keywords as $words) {
            $all_keywords = array_merge($all_keywords, $words);
        }
        foreach ($this->tone_keywords as $words) {
            $all_keywords = array_merge($all_keywords, $words);
        }
        // FIX: preg_quote needs delimiter parameter to escape forward slashes
        $escaped_keywords = array_map(function($word) { return preg_quote($word, '/'); }, array_unique($all_keywords));
        $this->unified_pattern = '/\b(' . implode('|', $escaped_keywords) . ')\b/i';

        // 2. Flag Patterns
        foreach ($this->red_flags as $key => $words) {
            // FIX: preg_quote needs delimiter parameter to escape forward slashes
            $escaped_words = array_map(function($word) { return preg_quote($word, '/'); }, $words);
            $this->flag_patterns[$key] = '/(' . implode('|', $escaped_words) . ')/i';
        }
    }

    /**
     * Prevent analysis overload during high traffic
     */
    private function should_analyze_now($post_id) {
        // Check rate limits
        $hourly_limit = 1000; // Max analyses per hour
        $hourly_count = get_transient('rts_analysis_hourly_count') ?: 0;
        
        if ($hourly_count >= $hourly_limit) {
            // Queue for later instead of analyzing now
            $this->queue_analysis($post_id);
            return false;
        }
        
        // Increment counter
        set_transient('rts_analysis_hourly_count', $hourly_count + 1, HOUR_IN_SECONDS);
        
        // Check system load (optional)
        if (function_exists('sys_getloadavg')) {
            $load = sys_getloadavg();
            $load1 = isset($load[0]) ? (float) $load[0] : 0.0;
            $max_load = (float) apply_filters('rts_max_load_avg', 0.0);
            if ($max_load > 0 && $load1 > 0 && $load1 >= $max_load) {
                $this->queue_analysis($post_id);
                return false;
            }
        }
        return true;
    }

    /**
     * Queue analysis for background processing
     */
    public function queue_analysis($post_id) {
        $queue = get_option('rts_analysis_queue', []);
        $queue[] = [
            'post_id' => $post_id,
            'queued_at' => time(),
            'priority' => get_post_status($post_id) === 'publish' ? 'high' : 'normal'
        ];
        
        // Keep queue manageable
        if (count($queue) > 1000) {
            $queue = array_slice($queue, -500); // Keep last 500
        }
        
        update_option('rts_analysis_queue', $queue, false);
        
        // Trigger background processing if not already running
        if (!wp_next_scheduled('rts_process_analysis_queue')) {
            wp_schedule_single_event(time() + 60, 'rts_process_analysis_queue');
        }
    }

    /**
     * Process queued analyses in batches
     */
    public function process_analysis_queue() {
        $queue = get_option('rts_analysis_queue', []);
        if (empty($queue)) return;
        
        // Process in batches of 50
        $batch = array_slice($queue, 0, 50);
        $remaining = array_slice($queue, 50);
        
        foreach ($batch as $item) {
            $this->analyze_and_tag($item['post_id'], true); // Force execution
        }
        
        update_option('rts_analysis_queue', $remaining, false);
        
        // Schedule next batch if more remain
        if (!empty($remaining)) {
            wp_schedule_single_event(time() + 30, 'rts_process_analysis_queue');
        }
    }

    /**
     * Automatically tag letter when published
     */
    public function auto_tag_on_publish($new_status, $old_status, $post) {
        if ($post->post_type !== 'letter' || $new_status !== 'publish') return;
        if (get_transient('rts_import_mode') === 'active') return;
        
        $this->analyze_and_tag($post->ID);
    }
    
    /**
     * Core Analysis Function
     */
    public function analyze_and_tag($post_id, $force = false) {
        if (!get_post($post_id) || get_post_type($post_id) !== 'letter') return false;

        // 1. Consent Check (Privacy)
        $post = get_post($post_id);
        if ($post->post_author > 0 && !$this->check_user_consent($post->post_author)) {
            return false; // User opted out of automated analysis
        }

        // 2. Throttling Check
        if (!$force && !$this->should_analyze_now($post_id)) {
            return false;
        }

        // 3. Timeout Protection for Long Content
        $content = strip_tags($post->post_content);
        if (strlen($content) > 10000 && !$force) {
            $this->queue_analysis($post_id); // Offload to background
            return false;
        }

        $start_time = microtime(true);
        if (function_exists('set_time_limit')) @set_time_limit(60);
        
        try {
            $content_hash = md5($content);

            // Check Cache
            $cache_key = 'rts_analysis_' . $post_id . '_' . $content_hash;
            if (false !== ($cached = get_transient($cache_key))) {
                return true; // Already processed
            }

            // 4. Scan Content (Feelings & Tone)
            $analysis = $this->scan_content_once($content);
            $detected_feelings = array_values(array_unique($analysis['feelings']));
            $detected_tones = $this->get_all_detected_tones($analysis['tones']);

            // Always assign something so the owner isn't faced with a wall of blanks.
            // This also helps ensure the "smart filters" have usable data.
            if (empty($detected_feelings)) {
                $detected_feelings = ['General'];
            }
            if (empty($detected_tones)) {
                $detected_tones = ['Supportive'];
            }

            // 5. ML Sentiment Analysis (Future/Placeholder)
            $this->analyze_sentiment($content);

            // 6. Apply Tags - ALL detected feelings AND tones
            // Ensure the taxonomy exists and CREATE TERMS if needed
            if (taxonomy_exists('letter_feeling')) {
                // Make sure ALL feeling terms exist in the taxonomy
                $this->ensure_feeling_terms_exist();
                wp_set_post_terms($post_id, $detected_feelings, 'letter_feeling', false);
            }
            if (taxonomy_exists('letter_tone')) {
                // Make sure tone terms exist
                $this->ensure_tone_terms_exist();
                wp_set_post_terms($post_id, $detected_tones, 'letter_tone', false);
            }

            // Validate analysis
            $this->validate_analysis($post_id, [
                'detected_feelings' => $detected_feelings,
                'detected_tone' => $detected_tone
            ]);

            // 7. Deep Analysis
            $this->check_for_duplicates($post_id, $content);
            $this->analyze_structure($content, $post_id);
            $this->calculate_quality_metrics($content, $post_id);
            $this->categorize_content($content, $post_id);
            $this->learn_patterns_from_content($content, $post_id);

            // 8. Reading Time
            $word_count = str_word_count($content);
            $reading_time = ($word_count < 100) ? 'short' : (($word_count < 200) ? 'medium' : 'long');
            update_post_meta($post_id, 'reading_time', $reading_time);

            // 9. Finalize
            update_post_meta($post_id, 'last_analyzed', current_time('mysql'));
            update_post_meta($post_id, 'auto_tagged', current_time('mysql'));
            
            // Set cache
            set_transient($cache_key, true, HOUR_IN_SECONDS);
            
            // Track Performance & Memory
            $this->track_performance('analyze_tag', $start_time, strlen($content));
            $this->track_memory_usage('analyze_tag');

            return true;

        } catch (Exception $e) {
            error_log("RTS Content Analyzer Error on post {$post_id}: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Optimized Single-Pass Scanner
     */
    private function scan_content_once($content) {
        $matches = [];
        preg_match_all($this->unified_pattern, $content, $matches);
        
        $detected = ['feelings' => [], 'tones' => []];
        
        foreach ($matches[0] as $match) {
            $word = strtolower($match);
            
            // Map match back to feelings
            foreach ($this->feeling_keywords as $feeling => $keywords) {
                if (in_array($word, $keywords)) {
                    $detected['feelings'][] = $feeling;
                }
            }
            // Map match back to tones
            foreach ($this->tone_keywords as $tone => $keywords) {
                if (in_array($word, $keywords)) {
                    $detected['tones'][$tone] = ($detected['tones'][$tone] ?? 0) + 1;
                }
            }
        }
        return $detected;
    }

    /**
     * Placeholder for ML-based sentiment analysis
     */
    private function analyze_sentiment($content) {
        // Integration point for external API or local ML model
        // Example: $sentiment = External_ML_API::analyze($content);
        // update_post_meta($post_id, 'ml_sentiment', $sentiment);
        return null;
    }

    private function determine_dominant_tone($tone_scores) {
        if (empty($tone_scores)) return 'gentle'; // Default
        return array_search(max($tone_scores), $tone_scores);
    }
    
    /**
     * Get ALL detected tones above minimum threshold (not just dominant one)
     * Returns array of all applicable tones
     */
    private function get_all_detected_tones($tone_scores) {
        if (empty($tone_scores)) return [];
        
        // Get tones that appear at least 2 times (threshold)
        $detected_tones = [];
        foreach ($tone_scores as $tone => $count) {
            if ($count >= 2) {
                $detected_tones[] = $tone;
            }
        }
        
        // Sort by count (most common first)
        arsort($tone_scores);
        
        // If no tones met threshold, return top tone
        if (empty($detected_tones) && !empty($tone_scores)) {
            $detected_tones = [array_key_first($tone_scores)];
        }
        
        return $detected_tones;
    }
    
    /**
     * Ensure all feeling terms exist in the taxonomy
     */
    private function ensure_feeling_terms_exist() {
        $feelings = array_keys($this->feeling_keywords);
        $feelings[] = 'General'; // Add default term
        foreach ($feelings as $feeling) {
            if (!term_exists($feeling, 'letter_feeling')) {
                wp_insert_term($feeling, 'letter_feeling');
            }
        }
    }
    
    /**
     * Ensure all tone terms exist in the taxonomy
     */
    private function ensure_tone_terms_exist() {
        $tones = array_keys($this->tone_keywords);
        $tones[] = 'Supportive'; // Add default term
        foreach ($tones as $tone) {
            if (!term_exists($tone, 'letter_tone')) {
                wp_insert_term($tone, 'letter_tone');
            }
        }
    }
    
    /**
     * Scan for problematic content
     */
    public function scan_for_problems($post_id, $post) {
        // Handle object or array input
        $content_raw = is_object($post) ? $post->post_content : (is_array($post) ? $post['post_content'] : '');
        $status = is_object($post) ? $post->post_status : '';

        if ($status === 'auto-draft') return;
        
        $content = strip_tags(strtolower($content_raw));
        $flags = [];
        
        // 1. Emergency Check
        $emergency = $this->check_emergency_keywords($content);
        if ($emergency) {
            $flags[] = 'CRITICAL: Emergency keywords detected';
            $this->trigger_emergency_response($content, $emergency);
        }

        // 2. Red Flags (Regex)
        foreach ($this->flag_patterns as $category => $pattern) {
            if (preg_match($pattern, $content)) {
                $flags[] = "Contains potential issue: " . str_replace('_', ' ', $category);
            }
        }
        
        // 3. Profanity Check
        $profanity_list = ['fuck', 'shit', 'damn', 'ass', 'bitch', 'bastard'];
        $profanity_count = 0;
        foreach ($profanity_list as $word) {
            $profanity_count += substr_count($content, $word);
        }
        if ($profanity_count > 3) {
            $flags[] = 'Contains excessive profanity';
        }
        
        // 4. Scoring
        $risk_score = $this->calculate_content_score($content, $post_id, count($flags));

        // Save flags (only if post_id is valid)
        if ($post_id) {
            if (!empty($flags) || $risk_score > 10) {
                update_post_meta($post_id, 'content_flags', $flags);
                update_post_meta($post_id, 'flagged_at', current_time('mysql'));
                update_post_meta($post_id, 'needs_review', true);
                
                // Alert for non-emergency but high risk
                if (empty($emergency) && $risk_score > 15) {
                    $this->send_critical_content_alert($post_id, $flags);
                }
            } else {
                delete_post_meta($post_id, 'content_flags');
                delete_post_meta($post_id, 'flagged_at');
                delete_post_meta($post_id, 'needs_review');
            }
        }
        
        return $flags;
    }

    private function check_emergency_keywords($content) {
        $found = [];
        foreach ($this->emergency_keywords as $keyword => $category) {
            if (stripos($content, $keyword) !== false) {
                $found[$category][] = $keyword;
            }
        }
        return !empty($found) ? $found : false;
    }

    /**
     * Enhanced emergency response with escalation
     */
    private function trigger_emergency_response($content, $keywords) {
        $admin_email = get_option('admin_email');
        $subject = '[CRITICAL] Emergency Content Detected - Immediate Review Required';
        
        // Sanitize content
        $safe_content = substr(wp_strip_all_tags($content), 0, 500);
        $message = "Emergency Content Alert\n\n";
        $message .= "Keywords found: " . print_r($keywords, true) . "\n\n";
        $message .= "Content excerpt: " . $safe_content . "...\n\n";
        $message .= "URL: " . admin_url('edit.php?post_type=letter&rts_emergency=1') . "\n";
        $message .= "Time: " . current_time('mysql') . "\n";
        
        // Send to primary admin
        wp_mail($admin_email, $subject, $message);
        
        // Escalate to secondary if no response in 15 minutes
        $escalation_email = get_option('rts_emergency_contact', '');
        if ($escalation_email && $escalation_email !== $admin_email) {
            wp_schedule_single_event(time() + 900, 'rts_escalate_emergency', [
                $subject, $message, $escalation_email
            ]);
        }
        
        // Log emergency
        $emergencies = get_option('rts_emergency_log', []);
        $emergencies[] = [
            'time' => time(),
            'keywords' => $keywords,
            'content_preview' => $safe_content
        ];
        
        // Keep last 50 emergencies
        if (count($emergencies) > 50) {
            $emergencies = array_slice($emergencies, -50);
        }
        
        update_option('rts_emergency_log', $emergencies, false);
        
        // Send SMS/webhook if configured
        $this->trigger_emergency_webhook($keywords);
    }

    private function trigger_emergency_webhook($keywords) {
        $webhook_url = get_option('rts_emergency_webhook', '');
        if (!$webhook_url) return;
        
        $payload = [
            'event' => 'content_emergency',
            'time' => time(),
            'keywords' => $keywords,
            'site' => get_bloginfo('name'),
            'url' => admin_url()
        ];
        
        (function_exists("session_status") && session_status() === PHP_SESSION_ACTIVE) ? session_write_close() : null; 
        wp_remote_post($webhook_url, [
            'method' => 'POST',
            'timeout' => 5,
            'redirection' => 5,
            'httpversion' => '1.0',
            'blocking' => false,
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode($payload)
        ]);
    }

    private function calculate_content_score($content, $post_id, $flag_count) {
        $score = [
            'emotional_intensity' => 0,
            'urgency_level' => 0,
            'supportive_elements' => 0,
            'red_flags' => $flag_count
        ];

        $emotional_words = ['crying', 'sobbing', 'broken', 'destroyed', 'suffering'];
        foreach ($emotional_words as $word) {
            $score['emotional_intensity'] += substr_count($content, $word);
        }

        $urgency_phrases = ['need help now', 'urgent', 'emergency', 'please hurry'];
        foreach ($urgency_phrases as $phrase) {
            if (strpos($content, $phrase) !== false) $score['urgency_level']++;
        }

        $supportive_phrases = ['you can get through', 'stay strong', 'reach out', 'help is available'];
        foreach ($supportive_phrases as $phrase) {
            if (strpos($content, $phrase) !== false) $score['supportive_elements']++;
        }

        $risk_score = ($score['emotional_intensity'] * 2) + 
                      ($score['urgency_level'] * 3) + 
                      ($score['red_flags'] * 5) - 
                      ($score['supportive_elements'] * 1);

        if ($post_id) {
            update_post_meta($post_id, 'content_risk_score', $risk_score);
            update_post_meta($post_id, 'content_score_breakdown', $score);
        }
        return $risk_score;
    }

    private function check_for_duplicates($post_id, $content) {
        global $wpdb;
        $fingerprint = md5(preg_replace('/\s+/', '', $content));
        
        // Cache Check
        $cache_key = 'rts_dup_' . $fingerprint;
        if (false !== ($cached_id = get_transient($cache_key))) {
            if ($cached_id != $post_id) {
                update_post_meta($post_id, 'possible_duplicate_of', $cached_id);
            }
            return;
        }

        // Optimized Query
        $similar = $wpdb->get_var($wpdb->prepare("
            SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = 'content_fingerprint' 
            AND meta_value = %s 
            AND post_id != %d
            LIMIT 1
        ", $fingerprint, $post_id));
        
        if ($similar) {
            update_post_meta($post_id, 'possible_duplicate_of', $similar);
            set_transient($cache_key, $similar, 5 * MINUTE_IN_SECONDS);
        }
        
        update_post_meta($post_id, 'content_fingerprint', $fingerprint);
    }

    private function analyze_structure($content, $post_id) {
        $sentences = preg_split('/[.!?]+/', $content);
        $total_words = 0;
        foreach ($sentences as $s) $total_words += str_word_count($s);
        
        $structure = [
            'paragraph_count' => count(explode("\n\n", $content)),
            'avg_sentence_len' => count($sentences) > 0 ? $total_words / count($sentences) : 0,
            'length' => strlen($content)
        ];
        update_post_meta($post_id, 'content_structure', $structure);
        return $structure;
    }

    private function calculate_quality_metrics($content, $post_id) {
        $words = str_word_count($content, 1);
        $unique = count(array_unique($words));
        $total = count($words);
        $ratio = $total > 0 ? $unique / $total : 0;

        // Keep legacy ratio for internal tuning...
        update_post_meta($post_id, 'content_quality_ratio', $ratio);

        // Canonical quality score (0-100) used by admin UI and bulk processing.
        // Ratio naturally falls between ~0.1 and ~1.0; map it to a friendly score.
        // We clamp to avoid weird outliers.
        $score = (int) round(max(0, min(100, $ratio * 100)));
        update_post_meta($post_id, 'quality_score', $score);

        // Simple label for quick filtering in admin.
        $level = 'low';
        if ($score >= 70) $level = 'high';
        elseif ($score >= 45) $level = 'medium';
        update_post_meta($post_id, 'quality_level', $level);

        return $ratio;
    }

    private function categorize_content($content, $post_id) {
        $themes = [
            'relationships' => ['breakup', 'divorce', 'family', 'friend', 'relationship'],
            'work_school' => ['job', 'work', 'school', 'college', 'exam', 'deadline'],
            'health' => ['sick', 'illness', 'pain', 'hospital', 'doctor', 'medication'],
            'identity' => ['lgbtq', 'trans', 'gay', 'lesbian', 'coming out', 'identity']
        ];
        
        $found_themes = [];
        foreach ($themes as $theme => $keywords) {
            foreach ($keywords as $keyword) {
                if (stripos($content, $keyword) !== false) {
                    $found_themes[] = $theme;
                    break; 
                }
            }
        }
        
        if (!empty($found_themes)) {
            wp_set_object_terms($post_id, $found_themes, 'letter_theme', true);
        }
    }

    private function learn_patterns_from_content($content, $post_id) {
        // Add rate limiting - only learn from every 10th post
        static $learn_counter = 0;
        $learn_counter++;
        
        if ($learn_counter % 10 !== 0) {
            return;
        }
        
        $words = str_word_count(strtolower($content), 1);
        $freq = array_count_values($words);
        $common = ['the', 'and', 'to', 'of', 'a', 'in', 'is', 'it', 'you', 'that'];
        $freq = array_diff_key($freq, array_flip($common));

        $feelings = wp_get_post_terms($post_id, 'letter_feeling', ['fields' => 'names']);
        if (!empty($feelings)) {
            $feeling = reset($feelings);
            $learned = get_option('rts_learned_keywords', []);
            if (!isset($learned[$feeling])) $learned[$feeling] = [];

            foreach ($freq as $word => $count) {
                if (strlen($word) > 3 && $count > 1) {
                    $learned[$feeling][$word] = ($learned[$feeling][$word] ?? 0) + 1;
                }
            }
            arsort($learned[$feeling]);
            $learned[$feeling] = array_slice($learned[$feeling], 0, 20);
            
            $old_value = get_option('rts_learned_keywords', []);
            if ($old_value != $learned) {
                update_option('rts_learned_keywords', $learned, false);
            }
        }
    }
    
    private function send_critical_content_alert($post_id, $flags) {
        $post = get_post($post_id);
        $edit_link = admin_url("post.php?post={$post_id}&action=edit");
        
        $to = get_option('admin_email');
        $subject = '[URGENT] Reasons to Stay - Problematic Content Detected';
        
        $message = "<h2 style='color: #f44336;'>⚠️ Critical Content Alert</h2>";
        $message .= "<ul>";
        foreach ($flags as $flag) {
            $message .= "<li>" . esc_html($flag) . "</li>";
        }
        $message .= "</ul>";
        $message .= "<p><strong>Letter ID:</strong> {$post_id}</p>";
        $message .= "<p><a href='{$edit_link}'>Review Letter</a></p>";
        $message .= "<blockquote style='background: #f5f5f5; padding: 15px;'>";
        $message .= wp_trim_words(wp_strip_all_tags($post->post_content), 100);
        $message .= "</blockquote>";
        
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        wp_mail($to, $subject, $message, $headers);
    }
    
    public function add_bulk_actions($actions) {
        $actions['rts_auto_tag'] = 'Auto-Tag Letters';
        $actions['rts_scan_problems'] = 'Scan for Problems';
        return $actions;
    }
    
    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        if ($action === 'rts_auto_tag') {
            $this->analyze_batch($post_ids); 
            $redirect_to = add_query_arg('bulk_tagged', count($post_ids), $redirect_to);
        }
        if ($action === 'rts_scan_problems') {
            foreach ($post_ids as $post_id) {
                $this->scan_for_problems($post_id, get_post($post_id));
            }
            $redirect_to = add_query_arg('bulk_scanned', count($post_ids), $redirect_to);
        }
        return $redirect_to;
    }

    public function analyze_batch($post_ids) {
        $results = ['processed' => 0, 'errors' => 0];
        foreach ($post_ids as $post_id) {
            try {
                if ($this->analyze_and_tag($post_id, true)) { // force analysis in batch
                    $results['processed']++;
                }
                if ($results['processed'] % 50 === 0) {
                    wp_cache_delete($post_id, 'posts');
                    wp_cache_delete($post_id, 'post_meta');
                }
            } catch (Exception $e) {
                $results['errors']++;
            }
        }
        return $results;
    }

    public function find_similar_content($post_id, $threshold = 0.7) {
        $post = get_post($post_id);
        $content = strip_tags($post->post_content);
        $fingerprint = get_post_meta($post_id, 'content_fingerprint', true);
        if (!$fingerprint) $fingerprint = md5(preg_replace('/\s+/', '', $content));

        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("
            SELECT post_id, meta_value as fingerprint,
            (LENGTH(meta_value) - LENGTH(REPLACE(meta_value, LEFT(%s, 8), ''))) / LENGTH(meta_value) as similarity
            FROM {$wpdb->postmeta}
            WHERE meta_key = 'content_fingerprint'
            AND post_id != %d
            HAVING similarity > %f
            ORDER BY similarity DESC
            LIMIT 10
        ", $fingerprint, $post_id, $threshold));
    }

    public function get_health_dashboard() {
        global $wpdb;
        return [
            'flagged_today' => $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = 'flagged_at' AND DATE(meta_value) = CURDATE()"),
            'common_feelings' => $wpdb->get_results("
                SELECT t.name, COUNT(*) as count 
                FROM {$wpdb->term_relationships} tr
                JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
                WHERE tt.taxonomy = 'letter_feeling'
                GROUP BY t.term_id ORDER BY count DESC LIMIT 5
            ")
        ];
    }

    public function export_analysis_data($post_ids = []) {
        $args = [
            'post_type' => 'letter',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'fields' => 'ids'
        ];
        if (!empty($post_ids)) $args['post__in'] = $post_ids;
        
        $ids = get_posts($args);
        $data = [];
        
        foreach ($ids as $pid) {
            $data[] = [
                'ID' => $pid,
                'Risk Score' => get_post_meta($pid, 'content_risk_score', true),
                'Flags' => implode(', ', (array)get_post_meta($pid, 'content_flags', true)),
                'Last Analyzed' => get_post_meta($pid, 'last_analyzed', true)
            ];
        }
        return $data;
    }

    private function track_performance($op, $start, $size) {
        $duration = microtime(true) - $start;
        $this->performance_metrics['total_scans']++;
        $this->performance_metrics['avg_scan_time'] = 
            ($this->performance_metrics['avg_scan_time'] * ($this->performance_metrics['total_scans'] - 1) + $duration) / $this->performance_metrics['total_scans'];
        
        if ($this->performance_metrics['total_scans'] % 100 === 0) {
            update_option('rts_analysis_performance', $this->performance_metrics, false);
        }
    }

    /**
     * Validate analysis results and track accuracy
     */
    private function validate_analysis($post_id, $analysis_results) {
        $manual_tags = [
            'feelings' => wp_get_post_terms($post_id, 'letter_feeling', ['fields' => 'names']),
            'tone' => wp_get_post_terms($post_id, 'letter_tone', ['fields' => 'names'])
        ];
        
        $auto_tags = [
            'feelings' => $analysis_results['detected_feelings'] ?? [],
            'tone' => $analysis_results['detected_tone'] ?? ''
        ];
        
        // Calculate accuracy metrics
        $accuracy = [
            'feelings_match' => count(array_intersect($manual_tags['feelings'], $auto_tags['feelings'])) / 
                            max(1, count($manual_tags['feelings'])),
            'tone_match' => in_array($auto_tags['tone'], $manual_tags['tone']) ? 1 : 0,
            'timestamp' => time()
        ];
        
        // Store for reporting
        $all_accuracy = get_option('rts_analysis_accuracy', []);
        $all_accuracy[] = $accuracy;
        
        // Keep last 100 samples
        if (count($all_accuracy) > 100) {
            $all_accuracy = array_slice($all_accuracy, -100);
        }
        
        update_option('rts_analysis_accuracy', $all_accuracy, false);
    }

    /**
     * Get analysis accuracy report
     */
    public function get_accuracy_report() {
        $samples = get_option('rts_analysis_accuracy', []);
        if (empty($samples)) return null;
        
        $report = [
            'total_samples' => count($samples),
            'avg_feelings_accuracy' => 0,
            'avg_tone_accuracy' => 0,
            'recent_trend' => 'stable'
        ];
        
        foreach ($samples as $sample) {
            $report['avg_feelings_accuracy'] += $sample['feelings_match'];
            $report['avg_tone_accuracy'] += $sample['tone_match'];
        }
        
        $report['avg_feelings_accuracy'] /= count($samples);
        $report['avg_tone_accuracy'] /= count($samples);
        
        return $report;
    }

    /**
     * REST API endpoint for external analysis
     */
    public function register_rest_endpoints() {
        register_rest_route('rts/v1', '/analyze-content', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_api_analysis'],
            'permission_callback' => function() {
                return current_user_can('edit_posts');
            },
            'args' => [
                'content' => [
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'wp_kses_post'
                ],
                'post_id' => [
                    'required' => false,
                    'type' => 'integer'
                ]
            ]
        ]);
    }

    public function handle_api_analysis($request) {
        $content = $request->get_param('content');
        $post_id = $request->get_param('post_id');
        
        $analysis = $this->scan_content_once($content);
        $risk_score = $this->calculate_content_score($content, $post_id ?: 0, 0);
        $flags = $this->scan_for_problems($post_id ?: 0, (object)['post_content' => $content]);
        
        return [
            'feelings' => array_unique($analysis['feelings']),
            'tones' => $analysis['tones'],
            'risk_score' => $risk_score,
            'flags' => $flags,
            'structure' => $this->analyze_structure($content, $post_id ?: 0),
            'quality' => $this->calculate_quality_metrics($content, $post_id ?: 0)
        ];
    }

    /**
     * Optimize analysis database tables
     */
    public function optimize_analysis_tables() {
        global $wpdb;
        
        $tables = ['posts', 'postmeta', 'terms', 'term_relationships'];
        foreach ($tables as $table) {
            $wpdb->query("OPTIMIZE TABLE {$wpdb->$table}");
        }
        
        // Clean old transients
        $wpdb->query("
            DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_rts_analysis_%' 
            AND option_name NOT LIKE '_transient_timeout_rts_analysis_%'
            AND LENGTH(option_name) > 40
        ");
        
        // Clean old cache
        $wpdb->query("
            DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_rts_dup_%'
            AND option_name NOT LIKE '_transient_timeout_rts_dup_%'
        ");
    }

    /**
     * Schedule regular optimization
     */
    public function schedule_optimization() {
        if (!wp_next_scheduled('rts_optimize_analysis_tables')) {
            wp_schedule_event(time(), 'weekly', 'rts_optimize_analysis_tables');
        }
    }

    /**
     * Track memory usage for heavy operations
     */
    private function track_memory_usage($operation) {
        $memory = memory_get_peak_usage(true);
        // Only update if current memory is higher or transient doesn't exist to save DB writes
        $key = 'rts_memory_peak_' . $operation;
        $current_peak = get_transient($key);
        
        if (false === $current_peak || $memory > $current_peak) {
            set_transient($key, $memory, DAY_IN_SECONDS);
        }
    }

    /**
     * Register Privacy Data Exporter (GDPR)
     */
    public function register_privacy_exporter($exporters) {
        $exporters['rts-content-analysis'] = [
            'exporter_friendly_name' => __('Reason to Stay Analysis Data', 'rts'),
            'callback' => [$this, 'privacy_data_export'],
        ];
        return $exporters;
    }

    /**
     * Privacy Data Export Callback
     */
    public function privacy_data_export($email_address, $page = 1) {
        $export_items = [];
        $user = get_user_by('email', $email_address);

        if ($user) {
            $letters = get_posts([
                'author' => $user->ID,
                'post_type' => 'letter',
                'posts_per_page' => -1,
                'fields' => 'ids'
            ]);

            foreach ($letters as $post_id) {
                $risk_score = get_post_meta($post_id, 'content_risk_score', true);
                $flags = get_post_meta($post_id, 'content_flags', true);
                
                if ($risk_score || $flags) {
                    $export_items[] = [
                        'group_id' => 'rts-analysis',
                        'group_label' => __('Content Analysis', 'rts'),
                        'item_id' => "letter-{$post_id}",
                        'data' => [
                            ['name' => __('Letter ID', 'rts'), 'value' => $post_id],
                            ['name' => __('Risk Score', 'rts'), 'value' => $risk_score],
                            ['name' => __('Flags', 'rts'), 'value' => is_array($flags) ? implode(', ', $flags) : $flags],
                        ],
                    ];
                }
            }
        }

        return [
            'data' => $export_items,
            'done' => true,
        ];
    }

    /**
     * Register Privacy Data Eraser (GDPR)
     */
    public function register_privacy_eraser($erasers) {
        $erasers['rts-content-analysis'] = [
            'eraser_friendly_name' => __('Reason to Stay Analysis Data', 'rts'),
            'callback' => [$this, 'privacy_data_erase'],
        ];
        return $erasers;
    }

    /**
     * Privacy Data Eraser Callback
     */
    public function privacy_data_erase($email_address, $page = 1) {
        $items_removed = false;
        $items_retained = false;
        $messages = [];
        $user = get_user_by('email', $email_address);

        if ($user) {
            $letters = get_posts([
                'author' => $user->ID,
                'post_type' => 'letter',
                'posts_per_page' => -1,
                'fields' => 'ids'
            ]);

            foreach ($letters as $post_id) {
                delete_post_meta($post_id, 'content_risk_score');
                delete_post_meta($post_id, 'content_flags');
                delete_post_meta($post_id, 'content_score_breakdown');
                delete_post_meta($post_id, 'content_fingerprint');
                $items_removed = true;
            }
        }

        return [
            'items_removed' => $items_removed,
            'items_retained' => $items_retained,
            'messages' => $messages,
            'done' => true,
        ];
    }

    /**
     * Check if user has consented to analysis
     */
    public function check_user_consent($user_id) {
        // Default to YES if no meta found (implied consent for existing users), 
        // strictly enforce only if 'no' is explicitly set. 
        // Adjust logic based on specific legal requirements.
        $consent = get_user_meta($user_id, 'rts_analysis_consent', true);
        return $consent !== 'no';
    }
}

// Initialize
RTS_Content_Analyzer::get_instance();

} // end class_exists check
