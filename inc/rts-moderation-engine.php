<?php
declare(strict_types=1);
/**
 * RTS Moderation Engine (Preservation-First, Fail-Closed, Action Scheduler Only)
 *
 * Version: 3.5.8 - LOGGER INTEGRATION
 * Date: 2026-02-03
 *
 * CHANGES IN v3.5.8:
 * - INTEGRATION: Wired RTS_Scan_Diagnostics to push events to RTS_Logger if available
 * - NEW FEATURE: Added "System Logs" viewer to the System tab in dashboard
 * - NEW ACTION: Added 'clear_logs' command to dashboard actions
 * * CHANGES IN v3.5.7:
 * - CRITICAL FIX: Automated pump now includes 'draft' status (quarantined letters)
 * - CRITICAL FIX: IP rate limit no longer blocks safe letters from publishing
 * - CRITICAL FIX: IP rate limit no longer sets needs_review flag on letters
 * - NEW FEATURE: Added clear_quarantine_timestamps() method
 * - NEW FEATURE: Added ajax_force_reprocess_quarantine() AJAX handler
 */

if (!defined('ABSPATH')) { exit; }

/* =========================================================
   Utilities: Action Scheduler presence check (Fail-Closed)
   ========================================================= */
if (!function_exists('rts_as_available')) {
	function rts_as_available(): bool {
		return function_exists('as_schedule_single_action')
			&& function_exists('as_next_scheduled_action');
	}
}

/* =========================================================
   RTS_Scan_Diagnostics: lightweight ring-buffer logger + state
   ========================================================= */
if (!class_exists('RTS_Scan_Diagnostics')) {
	class RTS_Scan_Diagnostics {
		const LOG_OPTION   = 'rts_scan_diag_log';
		const STATE_OPTION = 'rts_scan_diag_state';
		const MAX_LOG      = 250;

		public static function log(string $event, array $data = []): void {
			$entry = [
				't'     => gmdate('c'),
				'event' => $event,
				'data'  => $data,
			];
			$log = get_option(self::LOG_OPTION, []);
			if (!is_array($log)) $log = [];
			$log[] = $entry;

			if (count($log) > self::MAX_LOG) {
				$log = array_slice($log, -self::MAX_LOG);
			}
			update_option(self::LOG_OPTION, $log, false);

            // BRIDGE: Push to persistent RTS_Logger if available
            if (class_exists('RTS_Logger')) {
                // Determine level based on event content
                $level = 'info';
                if (strpos($event, 'error') !== false || strpos($event, 'fail') !== false || strpos($event, 'exception') !== false) {
                    $level = 'error';
                } elseif (strpos($event, 'warn') !== false || strpos($event, 'flagged') !== false || strpos($event, 'blocked') !== false) {
                    $level = 'warning';
                }
                
                // RTS_Logger handles sanitation and persistence
                RTS_Logger::get_instance()->log($level, $event, $data);
            }
		}

		public static function set_state(array $patch): void {
			$state = get_option(self::STATE_OPTION, []);
			if (!is_array($state)) $state = [];
			$state = array_merge($state, $patch, ['updated_gmt' => gmdate('c')]);
			update_option(self::STATE_OPTION, $state, false);
		}

		public static function get_state(): array {
			$state = get_option(self::STATE_OPTION, []);
			return is_array($state) ? $state : [];
		}

		public static function get_log(int $limit = 60): array {
			$log = get_option(self::LOG_OPTION, []);
			if (!is_array($log)) return [];
			return array_slice($log, -max(1, $limit));
		}

		public static function reset(): void {
			delete_option(self::LOG_OPTION);
			delete_option(self::STATE_OPTION);
		}
	}
}

/* =========================================================
   RTS_IP_Utils: Spoof-resistant IP retrieval
   ========================================================= */
if (!class_exists('RTS_IP_Utils')) {
	class RTS_IP_Utils {

		public static function get_client_ip(): string {
			$candidates = [];
			if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
				$candidates[] = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
			}
			if (!empty($_SERVER['REMOTE_ADDR'])) {
				$candidates[] = trim((string) $_SERVER['REMOTE_ADDR']);
			}
			foreach ($candidates as $ip) {
				if (self::is_valid_ip($ip)) {
					return $ip;
				}
			}
			return '';
		}

		public static function is_valid_ip(string $ip): bool {
			return (bool) filter_var($ip, FILTER_VALIDATE_IP);
		}

		public static function hash_ip(string $ip): string {
			$ip = trim($ip);
			if ($ip === '') return '';
            $salt = wp_salt('auth');
            if (empty($salt)) $salt = 'rts_fallback_salt_' . date('Ym');
			return hash_hmac('sha256', $ip, $salt);
		}
	}
}

/* =========================================================
   AJAX Handler: Load settings tab (defined early)
   ========================================================= */
if (!function_exists('rts_ajax_load_settings_tab')) {
    /**
     * AJAX: Load settings tab content without full page refresh.
     * This keeps the core tab rendering methods as the single source of truth.
     */
    function rts_ajax_load_settings_tab(): void {
        check_ajax_referer('rts_dashboard_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Forbidden'], 403);
        }

        $tab = isset($_POST['tab']) ? sanitize_key((string) $_POST['tab']) : '';
        $allowed = ['overview', 'letters', 'processing', 'feedback', 'settings'];
        if (!in_array($tab, $allowed, true)) {
            wp_send_json_error(['message' => 'Invalid tab'], 400);
        }

        ob_start();
        switch ($tab) {
            case 'overview':
                if (class_exists('RTS_Engine_Dashboard')) {
                    RTS_Engine_Dashboard::render_tab_overview(get_option('rts_import_job_status', []), get_option('rts_aggregated_stats', []));
                }
                break;
            case 'letters':
                if (class_exists('RTS_Engine_Dashboard')) {
                    RTS_Engine_Dashboard::render_tab_letters();
                }
                break;
            case 'feedback':
                if (class_exists('RTS_Engine_Dashboard')) {
                    RTS_Engine_Dashboard::render_tab_feedback();
                }
                break;
            case 'settings':
            default:
                if (class_exists('RTS_Engine_Dashboard')) {
                    RTS_Engine_Dashboard::render_tab_settings();
                }
                break;
        }
        $html = ob_get_clean();

        wp_send_json_success(['html' => $html]);
    }
}

/* =========================================================
   RTS_Moderation_Engine: Core pipeline (Fail-Closed)
   ========================================================= */
if (!class_exists('RTS_Moderation_Engine')) {
	class RTS_Moderation_Engine {

		const LOCK_TTL = 300; // 5 minutes
		const LOCK_PREFIX = 'rts_lock_';
		const GROUP = 'rts';

		public static function process_letter(int $post_id): void {
			$post_id = absint($post_id);
			if (!$post_id) return;

			// Never process trashed letters (admin intentionally deleted them).
			if (get_post_status($post_id) === 'trash') return;

			$post = get_post($post_id);
			if (!$post || $post->post_type !== 'letter') return;

			$lock_key = self::LOCK_PREFIX . $post_id;
			if (get_transient($lock_key)) {
				return;
			}
			set_transient($lock_key, time(), self::LOCK_TTL);

			$start_time = microtime(true);

			try {
                // CRITICAL FIX #1: Clear stale queue timestamps before fresh scan
                delete_post_meta($post_id, 'rts_scan_queued_ts');
                delete_post_meta($post_id, 'rts_scan_queued_gmt');

				$results = [
					'safety'  => ['pass' => false, 'flags' => []],
					'ip'      => ['pass' => false, 'reason' => ''],
					'quality' => ['pass' => false, 'score' => 0, 'notes' => []],
					'tags'    => ['applied' => []],
				];

                $results['safety'] = self::safety_scan($post_id);

				$results['ip'] = self::ip_history_check($post_id);
				$results['quality'] = self::quality_scoring($post_id);
				$results['tags'] = self::auto_tagging($post_id);

				update_post_meta($post_id, 'quality_score', (int) $results['quality']['score']);
				update_post_meta($post_id, 'rts_safety_pass', $results['safety']['pass'] ? '1' : '0');
				update_post_meta($post_id, 'rts_ip_pass', $results['ip']['pass'] ? '1' : '0');
                
                // Save detailed flag reasons
                if (!empty($results['safety']['details'])) {
                    update_post_meta($post_id, 'rts_safety_details', $results['safety']['details']);
                }
				update_post_meta($post_id, 'rts_flagged_keywords', wp_json_encode($results['safety']['flags']));
				update_post_meta($post_id, 'rts_processing_last', gmdate('c'));
				delete_post_meta($post_id, 'rts_system_error');

                $admin_override = (get_post_meta($post_id, 'rts_admin_override', true) === '1');

                // CRITICAL FIX: IP rate limit should NOT block safe letters
                // IP check is logged for analytics but doesn't affect publish decision
                $all_pass = (
                    (($results['safety']['pass'] === true) || $admin_override)
                    && $results['quality']['pass'] === true
                );
                // Note: $results['ip']['pass'] is still logged in meta but doesn't block publishing

                if ($all_pass) {
                    wp_update_post([
                        'ID'          => $post_id,
                        'post_status' => 'publish',
                    ]);

                    delete_post_meta($post_id, 'needs_review');
                    delete_post_meta($post_id, 'rts_flag_reasons');
                    delete_post_meta($post_id, 'rts_moderation_reasons');
                    delete_post_meta($post_id, 'rts_flagged_keywords');

                    // Prevent admin edit screens repeatedly requesting blocked RTS OG images.
                    foreach (['rank_math_facebook_image','rank_math_twitter_image'] as $k) {
                        $val = (string) get_post_meta($post_id, $k, true);
                        if ($val && strpos($val, '/rts-og-images/') !== false) {
                            delete_post_meta($post_id, $k);
                        }
                    }
                    foreach (['rank_math_facebook_image_id','rank_math_twitter_image_id'] as $k) {
                        $val = (string) get_post_meta($post_id, $k, true);
                        if ($val) { delete_post_meta($post_id, $k); }
                    }

                    if ($admin_override) {
                        update_post_meta($post_id, 'rts_admin_override', '0');
                    }

                    update_post_meta($post_id, 'rts_moderation_status', 'published');
                } else {
                    // Fail-closed: quarantine
                    wp_update_post([
                        'ID'          => $post_id,
                        'post_status' => 'draft',
                    ]);

                    update_post_meta($post_id, 'needs_review', '1');
                    update_post_meta($post_id, 'rts_moderation_status', 'pending_review');

                    $reasons = [];

                    if (!$results['safety']['pass']) {
                        $flags = is_array($results['safety']['flags']) ? $results['safety']['flags'] : [];
                        foreach ($flags as $f) {
                            $reasons[] = 'safety:' . sanitize_key((string) $f);
                        }
                        if (!$flags) {
                            $reasons[] = 'safety:flagged';
                        }
                    }

                    if (!$results['ip']['pass']) {
                        $reason = isset($results['ip']['reason']) ? (string) $results['ip']['reason'] : 'blocked';
                        $reasons[] = 'ip:' . sanitize_key($reason);
                    }

                    if (!$results['quality']['pass']) {
                        $score = isset($results['quality']['score']) ? (int) $results['quality']['score'] : 0;
                        $notes = isset($results['quality']['notes']) && is_array($results['quality']['notes']) ? $results['quality']['notes'] : [];
                        $reasons[] = 'quality:score_' . $score;
                        foreach ($notes as $n) {
                            $reasons[] = 'quality:' . sanitize_key((string) $n);
                        }
                    }

                    $reasons = array_values(array_unique(array_filter($reasons)));
                    update_post_meta($post_id, 'rts_flag_reasons', wp_json_encode($reasons));
                    update_post_meta($post_id, 'rts_moderation_reasons', implode(',', $reasons));

                    RTS_Scan_Diagnostics::log('letter_flagged', [
                        'post_id'  => $post_id,
                        'status'   => $post->post_status,
                        'reasons'  => $reasons,
                    ]);
                }

			} catch (\Throwable $e) {
				update_post_meta($post_id, 'needs_review', '1');
				update_post_meta($post_id, 'rts_moderation_status', 'system_error');
				update_post_meta($post_id, 'rts_system_error', self::safe_error_string($e));
			} finally {
				$processing_time = microtime(true) - $start_time;
				update_post_meta($post_id, 'rts_processing_time_ms', round($processing_time * 1000, 2));
				if ($processing_time > 2.0) {
					RTS_Scan_Diagnostics::log('slow_processing', [
						'post_id' => $post_id,
						'time_seconds' => round($processing_time, 2),
					]);
				}
				delete_transient($lock_key);
				self::purge_counts_cache();
			}
		}

		

        /**
         * Soft delete a letter (GDPR-friendly workflow).
         * - Moves to Trash
         * - Stores deletion timestamp + reason
         * - Marks as hidden to exclude from front-end queries
         */
        public static function soft_delete_letter(int $post_id, string $reason = 'user_request'): bool {
            $post_id = absint($post_id);
            if (!$post_id) return false;

            $post = get_post($post_id);
            if (!$post || $post->post_type !== 'letter') return false;

            $ok = wp_update_post([
                'ID'          => $post_id,
                'post_status' => 'trash',
            ], true);

            if (is_wp_error($ok)) return false;

            update_post_meta($post_id, 'rts_deleted_at', gmdate('c'));
            update_post_meta($post_id, 'rts_deleted_reason', sanitize_text_field($reason));
            update_post_meta($post_id, 'rts_hidden', '1');

            self::purge_counts_cache();
            return true;
        }

		/**
		 * Restore a previously soft-deleted (hidden) letter.
		 * - Moves status back to draft
		 * - Clears hidden/deletion audit meta
		 * - Queues a rescan so it can re-enter the normal pipeline
		 */
		public static function restore_letter(int $post_id): bool {
			$post_id = absint($post_id);
			if (!$post_id) return false;

			$post = get_post($post_id);
			if (!$post || $post->post_type !== 'letter') return false;

			$ok = wp_update_post([
				'ID'          => $post_id,
				'post_status' => 'draft',
			], true);
			if (is_wp_error($ok)) return false;

			delete_post_meta($post_id, 'rts_hidden');
			delete_post_meta($post_id, 'rts_deleted_at');
			delete_post_meta($post_id, 'rts_deleted_reason');
			self::purge_counts_cache();

			// Re-run moderation pipeline.
			if (class_exists('RTS_Engine_Dashboard') && method_exists('RTS_Engine_Dashboard', 'queue_letter_scan')) {
				RTS_Engine_Dashboard::queue_letter_scan($post_id);
			}

			return true;
		}

        /**
         * Export moderation decisions for auditing/transparency.
         * Dates should be in 'Y-m-d H:i:s' (UTC) or any MySQL compatible datetime.
         *
         * @return array<int, array<string, mixed>>
         */
        public static function export_moderation_log(string $start_date, string $end_date): array {
            global $wpdb;

            $start_date = sanitize_text_field($start_date);
            $end_date   = sanitize_text_field($end_date);

			// Validate expected date formats (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS).
			$re = '/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/';
			if (!preg_match($re, $start_date) || !preg_match($re, $end_date)) {
				return [];
			}

            $sql = "
                SELECT p.ID,
                       p.post_title,
                       p.post_status,
                       p.post_modified_gmt AS decision_time_gmt,
                       pm1.meta_value AS safety_result,
                       pm2.meta_value AS quality_score,
                       pm3.meta_value AS flag_reasons
                FROM {$wpdb->posts} p
                LEFT JOIN {$wpdb->postmeta} pm1 ON pm1.post_id = p.ID AND pm1.meta_key = 'rts_safety_pass'
                LEFT JOIN {$wpdb->postmeta} pm2 ON pm2.post_id = p.ID AND pm2.meta_key = 'quality_score'
                LEFT JOIN {$wpdb->postmeta} pm3 ON pm3.post_id = p.ID AND pm3.meta_key = 'rts_flag_reasons'
                WHERE p.post_type = 'letter'
                  AND p.post_modified_gmt BETWEEN %s AND %s
                ORDER BY p.post_modified_gmt DESC
            ";

            $rows = $wpdb->get_results($wpdb->prepare($sql, $start_date, $end_date), ARRAY_A);
            return is_array($rows) ? $rows : [];
        }


		private static function purge_counts_cache(): void {
			global $wpdb;
			try {
				$like1 = $wpdb->esc_like('_transient_rts_count_') . '%';
				$like2 = $wpdb->esc_like('_transient_timeout_rts_count_') . '%';
				$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $like1, $like2));
			} catch (\Throwable $e) {
				// Silent fail
			}
		}

        private static function safe_error_string(\Throwable $e): string {
			$msg = $e->getMessage();
			$msg = is_string($msg) ? $msg : 'Unknown error';
			$msg = wp_strip_all_tags($msg);
			return mb_substr($msg, 0, 300);
		}

        /**
         * Safety scan a letter.
         *
         * @return array{pass:bool, flags:string[], score:int, context:array<string,mixed>}
         */
        private static function safety_scan(int $post_id): array {
            $content = (string) get_post_field('post_content', $post_id);
            $content_lc = mb_strtolower($content);
            
            $flags = [];
            $flag_score = 0;
            $context_hits = [];
            $context_reduction = 0;
            
            // SPAM/ABUSE PATTERNS
            $spam_patterns = [
                '/\b(viagra|cialis|casino|poker|lottery|bitcoin|crypto)\b/i' => 'spam_keywords',
                '/https?:\/\/[^\s]+\.(ru|cn|tk|ml|ga)\b/i' => 'suspicious_links',
                '/<script|javascript:|onclick=/i' => 'malicious_code',
                '/\b(fuck you|kill yourself|you should die|worthless piece)\b/i' => 'abusive_language',
            ];
            
            foreach ($spam_patterns as $pattern => $flag_name) {
                $ok = @preg_match($pattern, $content_lc);
                if ($ok === 1) {
                    $flags[] = $flag_name;
                    $flag_score += 10;
                }
            }
            
            // SPECIFIC METHOD PATTERNS
            $method_patterns = [
                '/\b([5-9]|[1-9]\d+)\s*(pills?|tablets?|capsules?)\b/i' => 'specific_dosage',
                '/\b(rope|noose|hanging|bridge|jump|pills? and alcohol)\b/i' => 'method_mention',
                '/\b(going to do it)\b/i' => 'imminent_timing',
            ];
            
            foreach ($method_patterns as $pattern => $flag_name) {
                $ok = @preg_match($pattern, $content_lc);
                if ($ok === 1) {
                    $flags[] = $flag_name;
                    $flag_score += 2;
                }
            }
            
            // ENCOURAGEMENT OF HARM
            $encouragement_patterns = [
                '/\b(you should|just do it|go ahead|nobody will miss)\b.*\b(kill|die|end it)\b/i' => 'encouragement',
                '/\b(better off dead|world.*better without)\b/i' => 'harmful_encouragement',
            ];
            
            foreach ($encouragement_patterns as $pattern => $flag_name) {
                $ok = @preg_match($pattern, $content_lc);
                if ($ok === 1) {
                    $flags[] = $flag_name;
                    $flag_score += 10;
                }
            }
            
            // SUPPORTIVE CONTEXT
            $supportive_patterns = [
                '/\b(you are not alone|here for you|it gets better|please stay|reach out)\b/i',
                '/\b(helpline|crisis|support|therapy|counseling|help is available)\b/i',
                '/\b(i understand|me too|i\'ve been there|you matter|you\'re important)\b/i',
                '/\b(keep going|hang in there|tomorrow|hope|future|better days)\b/i',
            ];
            
            foreach ($supportive_patterns as $pattern) {
                $ok = @preg_match($pattern, $content_lc);
                if ($ok === 1) {
                    $flag_score -= 2;
                }
            }
            
            // PAST TENSE
            if (preg_match('/\b(used to|in the past|when i was|back then|years ago|months ago)\b/i', $content_lc)) {
                $flag_score -= 1;
            }
            
            $needs_review = ($flag_score >= 8);
            
            return [
                'pass' => !$needs_review,
                'flags' => $flags,
                'score' => $flag_score,
                'context' => $context_hits,
            ];
        }

		private static function ip_history_check(int $post_id): array {
			global $wpdb;
			$ip = (string) get_post_meta($post_id, 'rts_submission_ip', true);
			$ip = trim($ip);
			if ($ip === '') {
				$ip = RTS_IP_Utils::get_client_ip();
				if ($ip !== '') update_post_meta($post_id, 'rts_submission_ip', $ip);
			}
			if ($ip === '' || !RTS_IP_Utils::is_valid_ip($ip)) return ['pass' => false, 'reason' => 'missing_or_invalid_ip'];

			$ip_hash = RTS_IP_Utils::hash_ip($ip);
			if ($ip_hash === '') return ['pass' => false, 'reason' => 'ip_hash_error'];

			$ip_lock_key = 'rts_ip_lock_' . $ip_hash;
			if (get_transient($ip_lock_key)) {
				// CRITICAL FIX: Log IP rate limit but DON'T set needs_review
				// IP issues alone shouldn't quarantine safe letters
				update_post_meta($post_id, 'rts_ip_lock_hit', '1');
				return ['pass' => false, 'reason' => 'ip_locked'];
			}

			$since_gmt = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
			$sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = %s AND p.post_date_gmt >= %s AND pm.meta_key = %s AND pm.meta_value = %s";
			$count = (int) $wpdb->get_var($wpdb->prepare($sql, 'letter', $since_gmt, 'rts_submission_ip', $ip));

			$threshold = (int) apply_filters('rts_ip_daily_threshold', (int) get_option(RTS_Engine_Settings::OPTION_IP_DAILY_THRESHOLD, 20));
			if ($count > $threshold) {
				set_transient($ip_lock_key, 1, 300);
				return ['pass' => false, 'reason' => 'rate_limit_exceeded'];
			}
			return ['pass' => true, 'reason' => 'ok'];
		}

		
        private static function quality_scoring(int $post_id): array {
            $content = trim((string) get_post_field('post_content', $post_id));
            if ($content === '') {
                return ['pass' => false, 'score' => 0, 'notes' => ['empty']];
            }

            $len = (int) mb_strlen($content);
            $words = preg_split('/\s+/u', wp_strip_all_tags($content), -1, PREG_SPLIT_NO_EMPTY);
            $word_count = is_array($words) ? count($words) : 0;

            $score = 0;
            if ($word_count > 0) {
                $score = (int) min(100, (int) round(($word_count / 60) * 100));
            }

            if ($len >= 120) $score = max($score, 40);
            if ($len >= 200) $score = max($score, 55);
            if ($len >= 320) $score = max($score, 70);

            $threshold_opt = (int) get_option(RTS_Engine_Settings::OPTION_MIN_QUALITY_SCORE, 25);
            $threshold = (int) apply_filters('rts_quality_threshold', $threshold_opt);

            $pass = ($score >= $threshold) && ($len >= 10);

            $notes = [];
            if ($len < 80) $notes[] = 'short';
            if ($len < 40) $notes[] = 'very_short';
            if ($word_count < 10) $notes[] = 'low_words';

            return ['pass' => $pass, 'score' => $score, 'notes' => $notes];
        }

		private static function auto_tagging(int $post_id): array {
			$content = mb_strtolower((string) get_post_field('post_content', $post_id));
			$applied = [];
			if ($content === '') return ['applied' => $applied];
			
			// FEELINGS
			$feeling_patterns = [
				'hopeless' => [
					'keywords' => ['hopeless', 'no hope', 'pointless', 'give up', 'no point', 'nothing matters', 'no future', 'can\'t see', 'no way out', 'trapped', 'stuck forever'],
					'weight' => 3
				],
				'alone' => [
					'keywords' => ['alone', 'lonely', 'nobody', 'no one', 'isolated', 'by myself', 'no friends', 'abandoned', 'left behind', 'on my own', 'all alone'],
					'weight' => 2
				],
				'anxious' => [
					'keywords' => ['anxious', 'anxiety', 'panic', 'worried', 'scared', 'afraid', 'terrified', 'nervous', 'can\'t breathe', 'heart racing', 'overwhelming'],
					'weight' => 2
				],
				'grieving' => [
					'keywords' => ['grief', 'grieving', 'loss', 'lost', 'died', 'death', 'miss', 'gone', 'passed away', 'mourning', 'bereaved'],
					'weight' => 3
				],
				'tired' => [
					'keywords' => ['tired', 'exhausted', 'drained', 'worn out', 'can\'t anymore', 'too much', 'burnt out', 'weary', 'fatigued', 'no energy'],
					'weight' => 2
				],
				'struggling' => [
					'keywords' => ['struggling', 'hard', 'difficult', 'can\'t cope', 'too hard', 'battle', 'fighting', 'barely', 'hanging on', 'drowning'],
					'weight' => 2
				],
				'hopeful' => [
					'keywords' => ['hope', 'hopeful', 'better', 'tomorrow', 'keep going', 'you can', 'it gets easier', 'you are not alone', 'hang in there', 'brighter', 'will pass'],
					'weight' => 2
				]
			];
			
			// TONE
			$tone_patterns = [
				'gentle' => [
					'keywords' => ['softly', 'gently', 'quietly', 'whisper', 'tender', 'kind', 'warm', 'safe', 'comfort', 'hug', 'hold'],
					'weight' => 2
				],
				'real' => [
					'keywords' => ['honestly', 'truth', 'real', 'raw', 'actually', 'really', 'genuinely', 'authentic', 'no sugar', 'straight up'],
					'weight' => 2
				],
				'hopeful' => [
					'keywords' => ['hope', 'believe', 'possible', 'can', 'will', 'better', 'brighter', 'light', 'tomorrow', 'future'],
					'weight' => 2
				]
			];
			
			$feeling_scores = [];
			foreach ($feeling_patterns as $feeling => $pattern) {
				$score = 0;
				foreach ($pattern['keywords'] as $keyword) {
					if (mb_strpos($content, $keyword) !== false) {
						$score += $pattern['weight'];
					}
				}
				if ($score > 0) {
					$feeling_scores[$feeling] = $score;
				}
			}
			
			$tone_scores = [];
			foreach ($tone_patterns as $tone => $pattern) {
				$score = 0;
				foreach ($pattern['keywords'] as $keyword) {
					if (mb_strpos($content, $keyword) !== false) {
						$score += $pattern['weight'];
					}
				}
				if ($score > 0) {
					$tone_scores[$tone] = $score;
				}
			}
			
			if (!empty($feeling_scores)) {
				arsort($feeling_scores);
				$top_feelings = array_slice(array_keys($feeling_scores), 0, 2);
				$feeling_term_ids = self::get_existing_term_ids('letter_feeling', $top_feelings);
				if (!empty($feeling_term_ids)) {
					wp_set_post_terms($post_id, $feeling_term_ids, 'letter_feeling', false);
					$applied['letter_feeling'] = $feeling_term_ids;
				}
			}
			
			if (!empty($tone_scores)) {
				arsort($tone_scores);
				$top_tone = array_key_first($tone_scores);
				$tone_term_ids = self::get_existing_term_ids('letter_tone', [$top_tone]);
				if (!empty($tone_term_ids)) {
					wp_set_post_terms($post_id, $tone_term_ids, 'letter_tone', false);
					$applied['letter_tone'] = $tone_term_ids;
				}
			}
			
			return ['applied' => $applied];
		}

		private static function get_existing_term_ids(string $taxonomy, array $slugs_or_names): array {
			if (!taxonomy_exists($taxonomy)) return [];
			$found = [];
			foreach ($slugs_or_names as $val) {
				$val = (string) $val;
				if ($val === '') continue;
				$term = get_term_by('slug', sanitize_title($val), $taxonomy);
				if (!$term) $term = get_term_by('name', $val, $taxonomy);
				if ($term && !is_wp_error($term)) $found[] = (int) $term->term_id;
			}
			return array_values(array_unique(array_filter($found)));
		}
	}
}

/* =========================================================
   RTS_Import_Orchestrator: Batch import (50) + scheduling
   ========================================================= */
if (!class_exists('RTS_Import_Orchestrator')) {
	class RTS_Import_Orchestrator {
		const BATCH_SIZE = 50;
		const GROUP = 'rts';

		public static function start_import(string $file_path): array {
			if (!rts_as_available()) return ['ok' => false, 'error' => 'action_scheduler_missing'];
			$file_path = wp_normalize_path($file_path);
			if ($file_path === '' || !file_exists($file_path) || !is_readable($file_path)) return ['ok' => false, 'error' => 'file_not_readable'];

			$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
			if (!in_array($ext, ['csv','json','ndjson'], true)) return ['ok' => false, 'error' => 'unsupported_format'];

			$job_id = 'job_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false);

			$total = 0;
            // FIX: Removed duplicated logic block here
			if ($ext === 'csv') {
				$total = self::count_csv_rows($file_path);
			} elseif ($ext === 'ndjson') {
				$total = self::count_lines($file_path);
			} else {
				$size = (int) @filesize($file_path);
				if ($size > 0 && $size <= 8 * 1024 * 1024) {
					$data = json_decode((string) file_get_contents($file_path), true);
					if (is_array($data)) $total = count($data);
				}
			}

			update_option('rts_import_job_status', [
				'job_id' => $job_id,
				'total' => $total,
				'processed' => 0,
				'errors' => 0,
				'status' => 'running',
				'format' => $ext,
				'started_gmt' => gmdate('c'),
				'file' => basename($file_path),
			], false);

			$scheduled = 0;

			if ($ext === 'csv') {
				$fh = new SplFileObject($file_path, 'r');
				$fh->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
				$header = null;
				$batch = [];
				foreach ($fh as $row) {
					if (!is_array($row) || count($row) === 0) continue;
					if ($header === null) { $header = self::normalize_header($row); continue; }
					$item = self::map_row($header, $row);
					if (empty($item['content'])) { self::bump_status('errors', 1); continue; }
					$batch[] = $item;
					if (count($batch) >= self::BATCH_SIZE) {
						as_schedule_single_action(time() + 1, 'rts_process_import_batch', [$job_id, $batch], self::GROUP);
						$scheduled++;
						$batch = [];
					}
				}
				if (!empty($batch)) {
					as_schedule_single_action(time() + 1, 'rts_process_import_batch', [$job_id, $batch], self::GROUP);
					$scheduled++;
				}
			} elseif ($ext === 'ndjson') {
				$fh = new SplFileObject($file_path, 'r');
				$batch = [];
				foreach ($fh as $line) {
					$line = trim((string) $line);
					if ($line === '') continue;
					$obj = json_decode($line, true);
					if (!is_array($obj)) { self::bump_status('errors', 1); continue; }
					$item = self::map_obj($obj);
					if (empty($item['content'])) { self::bump_status('errors', 1); continue; }
					$batch[] = $item;
					if (count($batch) >= self::BATCH_SIZE) {
						as_schedule_single_action(time() + 1, 'rts_process_import_batch', [$job_id, $batch], self::GROUP);
						$scheduled++;
						$batch = [];
					}
				}
				if (!empty($batch)) {
					as_schedule_single_action(time() + 1, 'rts_process_import_batch', [$job_id, $batch], self::GROUP);
					$scheduled++;
				}
			} else {
				$data = json_decode((string) file_get_contents($file_path), true);
				if (!is_array($data)) return ['ok' => false, 'error' => 'json_decode_failed'];
				$batch = [];
				foreach ($data as $obj) {
					if (!is_array($obj)) { self::bump_status('errors', 1); continue; }
					$item = self::map_obj($obj);
					if (empty($item['content'])) { self::bump_status('errors', 1); continue; }
					$batch[] = $item;
					if (count($batch) >= self::BATCH_SIZE) {
						as_schedule_single_action(time() + 1, 'rts_process_import_batch', [$job_id, $batch], self::GROUP);
						$scheduled++;
						$batch = [];
					}
				}
				if (!empty($batch)) {
					as_schedule_single_action(time() + 1, 'rts_process_import_batch', [$job_id, $batch], self::GROUP);
					$scheduled++;
				}
			}

			return ['ok' => true, 'job_id' => $job_id, 'scheduled_batches' => $scheduled, 'total' => $total];
		}

		public static function process_import_batch(string $job_id, array $batch): void {
			if (!is_string($job_id) || $job_id === '') return;
			if (!is_array($batch) || empty($batch)) return;
			$status = get_option('rts_import_job_status', []);
			if (!is_array($status) || ($status['job_id'] ?? '') !== $job_id) return;

			$processed = 0; $errors = 0;
			foreach ($batch as $item) {
				try {
					$title = !empty($item['title']) ? sanitize_text_field($item['title']) : 'Letter ' . wp_generate_password(8, false, false);
					$post_id = wp_insert_post(['post_type' => 'letter', 'post_title' => $title, 'post_content' => (string) $item['content'], 'post_status' => 'pending'], true);
					if (is_wp_error($post_id) || !$post_id) { $errors++; continue; }
					$post_id = (int) $post_id;
					$ip = isset($item['submission_ip']) ? trim((string) $item['submission_ip']) : '';
					if ($ip !== '' && RTS_IP_Utils::is_valid_ip($ip)) update_post_meta($post_id, 'rts_submission_ip', $ip);
					as_schedule_single_action(time() + 1, 'rts_process_letter', [$post_id], self::GROUP);
					$processed++;
				} catch (\Throwable $e) { $errors++; }
			}
			self::bump_status('processed', $processed);
			self::bump_status('errors', $errors);
			$status = get_option('rts_import_job_status', []);
			if ((int) ($status['total'] ?? 0) > 0 && (int) ($status['processed'] ?? 0) >= (int) ($status['total'] ?? 0)) {
				$status['status'] = 'complete'; $status['finished_gmt'] = gmdate('c');
				update_option('rts_import_job_status', $status, false);
			}
		}

		private static function bump_status(string $key, int $by): void {
			if ($by <= 0) return;
			$status = get_option('rts_import_job_status', []);
			if (!is_array($status)) $status = [];
			$status[$key] = (int) ($status[$key] ?? 0) + $by;
			update_option('rts_import_job_status', $status, false);
		}

		
		private static function count_lines(string $file_path): int {
			try {
				$f = new SplFileObject($file_path, 'r');
				$f->seek(PHP_INT_MAX);
				return max(0, (int) $f->key());
			} catch (\Throwable $e) { return 0; }
		}

		private static function map_obj(array $obj): array {
			$content = '';
			foreach (['content', 'letter', 'message', 'body'] as $k) { if (!empty($obj[$k])) { $content = (string) $obj[$k]; break; } }
			$title = '';
			foreach (['title', 'subject', 'name'] as $k) { if (!empty($obj[$k])) { $title = (string) $obj[$k]; break; } }
			$submission_ip = '';
			foreach (['submission_ip', 'ip', 'rts_submission_ip'] as $k) { if (!empty($obj[$k])) { $submission_ip = (string) $obj[$k]; break; } }
			return ['content' => trim($content), 'title' => trim($title), 'submission_ip' => trim($submission_ip)];
		}

private static function count_csv_rows(string $file_path): int {
			try { $f = new SplFileObject($file_path, 'r'); $f->seek(PHP_INT_MAX); return max(0, (int) $f->key()); } catch (\Throwable $e) { return 0; }
		}

		private static function normalize_header(array $row): array {
			$out = []; foreach ($row as $cell) { $out[] = sanitize_key(is_string($cell) ? trim($cell) : ''); } return $out;
		}

		private static function map_row(array $header, array $row): array {
			$data = []; foreach ($header as $i => $key) { if ($key !== '') $data[$key] = isset($row[$i]) ? (string) $row[$i] : ''; }
			$content = ''; foreach (['content', 'letter', 'message', 'body'] as $k) { if (!empty($data[$k])) { $content = $data[$k]; break; } }
			$title = ''; foreach (['title', 'subject', 'name'] as $k) { if (!empty($data[$k])) { $title = $data[$k]; break; } }
			$submission_ip = ''; foreach (['submission_ip', 'ip', 'rts_submission_ip'] as $k) { if (!empty($data[$k])) { $submission_ip = $data[$k]; break; } }
			return ['content' => trim($content), 'title' => trim($title), 'submission_ip' => trim($submission_ip)];
		}
	}
}

/* =========================================================
   RTS_Analytics_Aggregator: Daily cached stats
   ========================================================= */
if (!class_exists('RTS_Analytics_Aggregator')) {
	class RTS_Analytics_Aggregator {
		const GROUP = 'rts';

		public static function aggregate(): void {
			global $wpdb;
			$letters_total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type=%s", 'letter'));
			$letters_published = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s", 'letter', 'publish'));
			$letters_pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s", 'letter', 'pending'));
			
			// Needs Review Count (Quarantined): Draft status + needs_review flag.
			// CRITICAL FIX: Quarantined letters use 'draft' status, not 'pending'.
			$needs_review = self::cached_int('needs_review_draft', 300, function () use ($wpdb) {
				return $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID)
					 FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
					 WHERE p.post_type = %s
					   AND p.post_status = %s
					   AND pm.meta_key = %s
					   AND pm.meta_value = %s",
					'letter',
					'draft',
					'needs_review',
					'1'
				));
			});

			$feedback_total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type=%s", 'rts_feedback'));

            // New: Velocity Stats (Last 24h, 7d, 30d)
            $now = current_time('mysql', 1);
            $date_24h = gmdate('Y-m-d H:i:s', strtotime('-24 hours'));
            $date_7d  = gmdate('Y-m-d H:i:s', strtotime('-7 days'));
            $date_30d = gmdate('Y-m-d H:i:s', strtotime('-30 days'));

            $count_24h = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type='letter' AND post_date_gmt >= %s", $date_24h));
            $count_7d  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type='letter' AND post_date_gmt >= %s", $date_7d));
            $count_30d = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type='letter' AND post_date_gmt >= %s", $date_30d));

			// Store both the newer "letters_*" keys and the legacy keys used elsewhere
			// in the theme, so stats remain consistent even if parts of the UI expect
			// different naming.
			$stats = [
				'generated_gmt'        => gmdate('c'),
				// Canonical keys
				'letters_total'        => $letters_total,
				'letters_published'    => $letters_published,
				'letters_pending'      => $letters_pending,
				'letters_needs_review' => $needs_review,
				// Legacy aliases
				'total'                => $letters_total,
				'published'            => $letters_published,
				'pending'              => $letters_pending,
				'needs_review'         => $needs_review,
				'feedback_total'       => $feedback_total,
                'velocity_24h'         => $count_24h,
                'velocity_7d'          => $count_7d,
                'velocity_30d'         => $count_30d,
				'taxonomy_breakdown'   => [
					'letter_feeling' => self::taxonomy_breakdown('letter_feeling', 'letter', 'publish'),
					'letter_tone'    => self::taxonomy_breakdown('letter_tone', 'letter', 'publish'),
				],
			];
			update_option('rts_aggregated_stats', $stats, false);
		}


        private static function cached_int(string $key, int $ttl, callable $fn): int {
            $tkey = 'rts_count_' . $key;
            $val = get_transient($tkey);
            if ($val !== false) return (int) $val;

            $val = (int) $fn();
            set_transient($tkey, $val, max(10, $ttl));
            return $val;
        }


		private static function taxonomy_breakdown(string $taxonomy, string $post_type, string $status): array {
			global $wpdb;
			if (!taxonomy_exists($taxonomy)) return [];
			$sql = "SELECT t.slug as term, COUNT(1) as count FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tt.taxonomy = %s AND p.post_type = %s AND p.post_status = %s GROUP BY t.slug ORDER BY count DESC LIMIT 50";
			$rows = $wpdb->get_results($wpdb->prepare($sql, $taxonomy, $post_type, $status), ARRAY_A);
			$out = [];
			if (is_array($rows)) { foreach ($rows as $r) { $out[(string) $r['term']] = (int) $r['count']; } }
			return $out;
		}
	}
}

/* =========================================================
   RTS_Share_Tracker: AJAX Handler for Share Counting
   ========================================================= */
if (!class_exists('RTS_Share_Tracker')) {
    class RTS_Share_Tracker {
        public static function init() {
            add_action('wp_ajax_rts_track_share', [__CLASS__, 'handle_ajax']);
            add_action('wp_ajax_nopriv_rts_track_share', [__CLASS__, 'handle_ajax']);
        }

        
public static function handle_ajax() {
            // Payload is posted as a JSON string in `payload` by rts-system.js
            $payload = [];
            if (isset($_POST['payload']) && is_string($_POST['payload']) && $_POST['payload'] !== '') {
                $decoded = json_decode(wp_unslash($_POST['payload']), true);
                if (is_array($decoded)) $payload = $decoded;
            }

            $letter_id = isset($payload['letter_id']) ? absint($payload['letter_id']) : 0;
            $platform  = isset($payload['platform']) ? sanitize_key($payload['platform']) : 'unknown';

            if (!$letter_id || get_post_type($letter_id) !== 'letter') {
                wp_send_json_error(['message' => 'Invalid letter']);
            }

            // Basic throttle: 1 share per IP per letter per 30s
            $ip = '';
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $ip = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
            elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) $ip = (string) explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
            elseif (!empty($_SERVER['REMOTE_ADDR'])) $ip = (string) $_SERVER['REMOTE_ADDR'];
            $ip = trim($ip);
            $ip_hash = $ip ? substr(sha1($ip), 0, 16) : 'noip';
            $tkey = 'rts_track_share_' . $ip_hash . '_' . $letter_id;
            if (get_transient($tkey)) {
                wp_send_json_success(['ok' => true, 'throttled' => true]);
            }
            set_transient($tkey, 1, 30);

            // Increment totals (aligned with REST counters)
            $current = (int) get_post_meta($letter_id, 'rts_shares', true);
            update_post_meta($letter_id, 'rts_shares', $current + 1);

            $plat_key = 'rts_share_' . preg_replace('/[^a-z0-9_\-]/i', '', strtolower($platform));
            $plat_current = (int) get_post_meta($letter_id, $plat_key, true);
            update_post_meta($letter_id, $plat_key, $plat_current + 1);

            wp_send_json_success(['ok' => true, 'new_count' => $current + 1]);
        }

        private static function update_daily_stats($platform) {
            $today = gmdate('Y-m-d');
            $stats = get_option('rts_daily_stats', []);
            if (!is_array($stats)) $stats = [];

            if (!isset($stats[$today])) {
                $stats[$today] = ['shares_total' => 0, 'shares_platform' => []];
            }

            $stats[$today]['shares_total']++;
            if (!isset($stats[$today]['shares_platform'][$platform])) {
                $stats[$today]['shares_platform'][$platform] = 0;
            }
            $stats[$today]['shares_platform'][$platform]++;

            // Cleanup old stats (keep last 60 days)
            if (count($stats) > 60) {
                $stats = array_slice($stats, -60, 60, true);
            }

            update_option('rts_daily_stats', $stats, false);
        }
    }
}

/* =========================================================
   RTS_Engine_Dashboard: Client-Friendly Interface
   ========================================================= */
if (!class_exists('RTS_Engine_Dashboard')) {
	class RTS_Engine_Dashboard {

		public const OPTION_AUTO_ENABLED = 'rts_auto_processing_enabled';

		/**
		 * Treat missing/blank option as enabled. Disabled only when explicitly set to '0'.
		 */
		private static function is_auto_enabled(): bool {
			$v = (string) get_option(self::OPTION_AUTO_ENABLED, '1');
			return ($v !== '0');
		}
		public const OPTION_AUTO_BATCH   = 'rts_auto_processing_batch_size';
		public const OPTION_MIN_QUALITY  = 'rts_min_quality_score';
		public const OPTION_IP_THRESHOLD = 'rts_ip_daily_threshold';

		public const OPTION_AUTO_INTERVAL = 'rts_auto_processing_interval';
		public const OPTION_TURBO_ENABLED  = 'rts_turbo_enabled';
		public const OPTION_TURBO_THRESHOLD= 'rts_turbo_threshold';
		public const OPTION_TURBO_INTERVAL = 'rts_turbo_interval';
		public const OPTION_TURBO_SCOPE    = 'rts_turbo_scope';
		public const OPTION_DARK_MODE      = 'rts_engine_dark_mode';
        // New Offset Options
        public const OPTION_OFFSET_LETTERS = 'rts_stat_offset_letters';
        public const OPTION_OFFSET_SHARES  = 'rts_stat_offset_shares';

		public static function init(): void {
			// REST API must be registered globally (not just in admin)
			add_action('rest_api_init', [__CLASS__, 'register_rest']);

			// Frontend letter delivery endpoints (admin-ajax fallback for when REST is blocked)
			add_action('wp_ajax_nopriv_rts_get_next_letter', [__CLASS__, 'ajax_get_next_letter']);
			add_action('wp_ajax_rts_get_next_letter', [__CLASS__, 'ajax_get_next_letter']);

			// Frontend analytics fallbacks (admin-ajax) when REST is blocked
			add_action('wp_ajax_nopriv_rts_track_view', [__CLASS__, 'ajax_track_view']);
			add_action('wp_ajax_rts_track_view', [__CLASS__, 'ajax_track_view']);
			add_action('wp_ajax_nopriv_rts_track_helpful', [__CLASS__, 'ajax_track_helpful']);
			add_action('wp_ajax_rts_track_helpful', [__CLASS__, 'ajax_track_helpful']);
			add_action('wp_ajax_nopriv_rts_track_rate', [__CLASS__, 'ajax_track_rate']);
			add_action('wp_ajax_rts_track_rate', [__CLASS__, 'ajax_track_rate']);





			
			// Admin-only hooks
			if (!is_admin()) return;
			add_action('admin_menu', [__CLASS__, 'register_menu'], 80);
			add_action('admin_init', [__CLASS__, 'register_settings'], 5);
			add_action('admin_post_rts_dashboard_action', [__CLASS__, 'handle_post_action']);
			add_action('admin_post_rts_import_letters', [__CLASS__, 'handle_import_upload']);
			add_action('admin_post_rts_export_letters', [__CLASS__, 'handle_export_download']);
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
            
            // Add AJAX handlers for modern dashboard
            add_action('wp_ajax_rts_start_scan', [__CLASS__, 'ajax_start_scan']);
            add_action('wp_ajax_rts_process_single', [__CLASS__, 'ajax_process_single']);
	            add_action('wp_ajax_rts_approve_letter', [__CLASS__, 'ajax_approve_letter']);
		            // Diagnostics used by the dashboard UI.
		            add_action('wp_ajax_rts_diag_log_tail', [__CLASS__, 'ajax_diag_log_tail']);
		            add_action('wp_ajax_rts_diag_reset', [__CLASS__, 'ajax_diag_reset']);
	            add_action('wp_ajax_rts_bulk_approve', [__CLASS__, 'ajax_bulk_approve']);
	            add_action('wp_ajax_rts_bulk_soft_delete', [__CLASS__, 'ajax_bulk_soft_delete']);
	            add_action('wp_ajax_rts_restore_letter', [__CLASS__, 'ajax_restore_letter']);
            add_action('wp_ajax_rts_cancel_import', [__CLASS__, 'ajax_cancel_import']);
            add_action('wp_ajax_rts_force_reprocess_quarantine', [__CLASS__, 'ajax_force_reprocess_quarantine']);
            add_action('wp_ajax_rts_load_settings_tab', 'rts_ajax_load_settings_tab');
            add_action('wp_ajax_rts_ajax_load_settings_tab', 'rts_ajax_load_settings_tab');
		}

        public static function enqueue_assets($hook): void {
            // Load CSS and JS on all RTS admin pages
            if (isset($_GET['page']) && strpos($_GET['page'], 'rts-') !== false) {
                // Main Admin Styles (includes dashboard styles) - cache-bust via filemtime
                $css_path = get_stylesheet_directory() . '/assets/css/rts-admin.css';
                $css_ver  = file_exists($css_path) ? (string) filemtime($css_path) : null;
                wp_enqueue_style('rts-admin-css', get_stylesheet_directory_uri() . '/assets/css/rts-admin.css', [], $css_ver);

                // Dashboard Logic Script - cache-bust via filemtime
                $js_path = get_stylesheet_directory() . '/assets/js/rts-dashboard.js';
                $js_ver  = file_exists($js_path) ? (string) filemtime($js_path) : null;
                wp_enqueue_script('rts-dashboard-js', get_stylesheet_directory_uri() . '/assets/js/rts-dashboard.js', ['jquery'], $js_ver, true);
                
                // Localize variables for JS
                wp_localize_script('rts-dashboard-js', 'rtsDashboard', [
                    'ajaxurl' => admin_url('admin-ajax.php'),
                    'resturl' => rest_url('rts/v1/'),
                    'nonce'   => wp_create_nonce('wp_rest'), // Using standard WP REST nonce for REST calls
                    'dashboard_nonce' => wp_create_nonce('rts_dashboard_nonce') // Specific nonce for custom AJAX actions if needed
                ]);
            }
        }

		public static function register_menu(): void {
			add_submenu_page(
				'edit.php?post_type=letter',
				'Dashboard',
				'Dashboard',
				'manage_options',
				'rts-dashboard',
				[__CLASS__, 'render_page'],
				0
			);
		}

		public static function register_settings(): void {
            // General Settings
			register_setting('rts_engine_settings', self::OPTION_AUTO_ENABLED, ['sanitize_callback' => 'sanitize_text_field', 'default' => '1']);
			register_setting('rts_engine_settings', self::OPTION_AUTO_BATCH, ['sanitize_callback' => 'absint', 'default' => 50]);
			register_setting('rts_engine_settings', self::OPTION_MIN_QUALITY, ['sanitize_callback' => 'absint', 'default' => 70]);
			register_setting('rts_engine_settings', self::OPTION_IP_THRESHOLD, ['sanitize_callback' => 'absint', 'default' => 20]);
			register_setting('rts_engine_settings', self::OPTION_AUTO_INTERVAL, ['sanitize_callback' => 'absint', 'default' => 300]);
			register_setting('rts_engine_settings', self::OPTION_TURBO_ENABLED, ['sanitize_callback' => 'sanitize_text_field', 'default' => '1']);
			register_setting('rts_engine_settings', self::OPTION_TURBO_THRESHOLD, ['sanitize_callback' => 'absint', 'default' => 100]);
			register_setting('rts_engine_settings', self::OPTION_TURBO_INTERVAL, ['sanitize_callback' => 'absint', 'default' => 20]);
			register_setting('rts_engine_settings', self::OPTION_TURBO_SCOPE, ['sanitize_callback' => 'sanitize_text_field', 'default' => 'both']);
			register_setting('rts_engine_settings', self::OPTION_DARK_MODE, ['sanitize_callback' => 'sanitize_text_field', 'default' => '0']);
            
            // Stat Offsets (admin only - legacy)
            register_setting('rts_engine_settings', self::OPTION_OFFSET_LETTERS, ['sanitize_callback' => 'absint', 'default' => 0]);
            register_setting('rts_engine_settings', self::OPTION_OFFSET_SHARES, ['sanitize_callback' => 'absint', 'default' => 0]);

            // Frontend stats overrides (used by [rts_site_stats_row])
            register_setting('rts_engine_settings', 'rts_stats_override', [
                'sanitize_callback' => function($value) {
                    if (!is_array($value)) {
                        return [];
                    }
                    return [
                        'enabled' => !empty($value['enabled']) ? 1 : 0,
                        'letters_delivered' => absint($value['letters_delivered'] ?? 0),
                        'letters_submitted' => absint($value['letters_submitted'] ?? 0),
                        'helps' => absint($value['helps'] ?? 0),
                        'feel_better_percent' => isset($value['feel_better_percent']) && $value['feel_better_percent'] !== '' ? max(0, min(100, intval($value['feel_better_percent']))) : '',
                    ];
                },
                'default' => []
            ]);

            // Email Notification Settings
            register_setting('rts_engine_settings', 'rts_email_notifications_enabled', ['sanitize_callback' => 'sanitize_text_field', 'default' => '1']);
            register_setting('rts_engine_settings', 'rts_admin_notification_email', ['sanitize_callback' => 'sanitize_email', 'default' => get_option('admin_email')]);
            register_setting('rts_engine_settings', 'rts_cc_notification_email', ['sanitize_callback' => 'sanitize_email', 'default' => '']);
            register_setting('rts_engine_settings', 'rts_notify_on_feedback', ['sanitize_callback' => 'sanitize_text_field', 'default' => '1']);
            register_setting('rts_engine_settings', 'rts_notify_on_triggered', ['sanitize_callback' => 'sanitize_text_field', 'default' => '1']);
            register_setting('rts_engine_settings', 'rts_notify_on_negative', ['sanitize_callback' => 'sanitize_text_field', 'default' => '0']);
		}

		private static function get_tab(): string {
			$default = 'overview';
			$allowed = ['overview','letters','analytics','shares','feedback','settings','system'];

			if (!isset($_GET['tab'])) {
				return $default;
			}

			$tab = sanitize_key(wp_unslash($_GET['tab']));

			return in_array($tab, $allowed, true) ? $tab : $default;
		}

		private static function url_for_tab(string $tab): string {
			return admin_url('edit.php?post_type=letter&page=rts-dashboard&tab=' . rawurlencode($tab));
		}

		private static function count_needs_review(): int {
			global $wpdb;
			// Count quarantined letters (draft status + needs_review flag).
			$needs = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID)
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE pm.meta_key = %s
				   AND pm.meta_value = %s
				   AND p.post_type = %s
				   AND p.post_status = %s",
				'needs_review', '1', 'letter', 'draft'
			));
			return (int) $needs;
		}

		private static function get_basic_stats(): array {
			// Use live counts for the headline cards (so the UI updates immediately
			// after scans publish/unflag letters). Keep the cached aggregator for
			// heavier analytics elsewhere.
			$letters = wp_count_posts('letter');
			$off_letters = (int) get_option(self::OPTION_OFFSET_LETTERS, 0);
			$feedback_obj = wp_count_posts('rts_feedback');
			$feedback_total = $feedback_obj ? (int) ($feedback_obj->publish + $feedback_obj->pending + $feedback_obj->draft + $feedback_obj->private + $feedback_obj->future) : 0;

			return [
				'total'         => (int) ($letters->publish + $letters->pending + $letters->draft + $letters->future + $letters->private),
				'published'     => (int) max(0, ((int) $letters->publish) + $off_letters),
				'pending'       => (int) $letters->pending,
				'needs_review'  => self::count_needs_review(),
				'feedback_total'=> $feedback_total,
			];
		}

		public static function render_page(): void {
			if (!current_user_can('manage_options')) return;

			$tab = self::get_tab();
			$stats = self::get_basic_stats();
			$import = get_option('rts_import_job_status', []);
			$agg    = get_option('rts_aggregated_stats', []);
			$as_ok  = rts_as_available();
			$message = isset($_GET['rts_msg']) ? sanitize_key((string) $_GET['rts_msg']) : '';
				$dark = (int) get_option(self::OPTION_DARK_MODE, 0) === 1;

            // Layout uses rts-admin.css
			?>
			<div class="wrap rts-dashboard <?php echo $dark ? "rts-darkmode" : ""; ?>">
                <header class="rts-dashboard-header">
                    <div>
                        <h1 class="rts-title">Moderation Control Room</h1>
                        <p class="rts-subtitle">Manage your community's letters</p>
                    </div>
                    
                    <div class="rts-system-status">
                        <div class="rts-status-indicator <?php echo $as_ok ? 'status-online' : 'status-offline'; ?>">
                            <span class="rts-status-dot"></span>
                            <span class="rts-status-text"><?php echo $as_ok ? 'System Online' : 'System Offline'; ?></span>
                        </div>
                    </div>
                </header>

				<?php if ($message): ?>
					<div class="notice notice-success is-dismissible rts-notice">
						<p>
						<?php
						switch ($message) {
							case 'analytics': echo '📊 Stats refresh started in background.'; break;
							case 'rescanned': echo '🔍 Safety scan started. Check back in 1 minute.'; break;
							case 'updated': echo '✅ Status updated.'; break;
                            case 'logs_cleared': echo '🧹 Logs cleared successfully.'; break;
							default: echo '✅ Done.'; break;
						}
						?>
						</p>
					</div>
					<?php endif; ?>
					
					<!-- Calculate true inbox (pending minus needs_review) -->
					<?php $true_inbox = max(0, (int) $stats['pending'] - (int) $stats['needs_review']); ?>
					
					<!-- Auto-Processing Active Banner -->
					<?php if (self::is_auto_enabled() && $true_inbox > 0): ?>
					<div class="notice notice-info" style="margin: 20px 0; padding: 15px; border-left: 4px solid #2271b1; background: #f0f6fc;">
						<p style="margin: 0; font-size: 14px; line-height: 1.6;">
							<span class="dashicons dashicons-update" style="color: #2271b1; animation: rotation 2s infinite linear;"></span>
								<strong>Auto-Processing Active:</strong> The system is automatically working through <strong><?php echo number_format_i18n($true_inbox); ?> letter(s)</strong> in the inbox. 
							Each letter is being quality-checked, safety-scanned, and tagged. Letters that pass all checks will be automatically published. 
							<a href="<?php echo esc_url(self::url_for_tab('system') . '#rts-live-processing-status'); ?>">View progress →</a>
						</p>
					</div>
						<?php if ($stats['needs_review'] > 0): ?>
							<p style="margin: 8px 0 0; font-size: 13px; color: #1d2327;">
								<strong><?php echo number_format_i18n($stats['needs_review']); ?></strong> letter(s) are currently quarantined for safety review.
							</p>
						<?php endif; ?>
					<style>
						@keyframes rotation {
							from { transform: rotate(0deg); }
							to { transform: rotate(359deg); }
						}
					</style>
					<?php endif; ?>

	                <!-- Primary Stats Grid -->
				<div class="rts-stats-grid">
	                    <?php self::stat_card('Live on Site', number_format_i18n($stats['published']), 'published', 'Letters live on the website'); ?>
	                    <?php self::stat_card('Inbox', number_format_i18n($true_inbox), 'inbox', 'Awaiting auto-processing'); ?>
	                    <?php self::stat_card('Quarantined', number_format_i18n($stats['needs_review']), 'quarantined', 'Flagged for safety review'); ?>
	                    <?php self::stat_card('Feedback', number_format_i18n($stats['feedback_total'] ?? 0), 'feedback', 'Reader feedback received'); ?>
	                    <?php self::stat_card('Total Letters', number_format_i18n($stats['total']), 'total', 'All-time submissions'); ?>
				</div>

                <!-- Live Status Panel -->
                <div class="rts-live-status" id="rts-live-processing-status">
                    <div class="rts-live-status-header">
                        <h3><span class="dashicons dashicons-update"></span> Live Processing Status</h3>
                        <div class="rts-live-status-badge" id="rts-scan-status">Idle</div>
                    </div>
                    
                    <div class="rts-live-status-content">
                        <div class="rts-status-item">
                            <span class="rts-status-label">Background Processing:</span>
                            <span class="rts-status-value"><?php echo $as_ok ? '<span class="status-good">Enabled</span>' : '<span class="status-bad">Disabled</span>'; ?></span>
                        </div>
                        
                        <div class="rts-status-item">
                            <span class="rts-status-label">Auto-scan Schedule:</span>
							<span class="rts-status-value"><?php echo self::is_auto_enabled() ? '<span class="status-good">Every 5 min</span>' : '<span class="status-warning">Manual only</span>'; ?></span>
                        </div>
                        
                        <div class="rts-status-item">
                            <span class="rts-status-label">Active Scan:</span>
                            <span class="rts-status-value" id="rts-active-scan">No active scan</span>
                        </div>
                        
                        <div class="rts-status-item">
                            <span class="rts-status-label">Queued Letters:</span>
                            <span class="rts-status-value" id="rts-queued-count">Checking...</span>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="rts-quick-actions">
                    <h3><span class="dashicons dashicons-admin-tools"></span> Quick Actions</h3>
                    <div class="rts-action-buttons">
                        <a class="button button-primary rts-action-btn" href="<?php echo esc_url(admin_url('edit.php?post_type=letter&post_status=pending')); ?>">
                            <span class="dashicons dashicons-list-view"></span> Review Inbox
                        </a>
                        
                        <a class="button rts-action-btn" href="<?php echo esc_url(admin_url('edit.php?post_type=letter&meta_key=needs_review&meta_value=1')); ?>">
                            <span class="dashicons dashicons-shield"></span> View Quarantine
                        </a>
                        
                        <button type="button" class="button rts-action-btn rts-scan-btn" id="rts-scan-inbox-btn">
                            <span class="dashicons dashicons-search"></span> 
                            <span class="btn-text">Scan Inbox Now</span>
                            <span class="spinner" style="float: none; margin: 0 0 0 5px; display: none;"></span>
                        </button>
                        
                        <button type="button" class="button rts-action-btn rts-scan-btn" id="rts-rescan-quarantine-btn">
                            <span class="dashicons dashicons-update"></span> 
                            <span class="btn-text">Rescan Quarantine</span>
                            <span class="spinner" style="float: none; margin: 0 0 0 5px; display: none;"></span>
                        </button>
                        
                        <button type="button" class="button rts-action-btn" id="rts-refresh-status-btn">
                            <span class="dashicons dashicons-update"></span> Refresh Status
                        </button>
                    </div>
                </div>

                <!-- Progress Section -->
                <div class="rts-progress-section">
                    <div class="rts-progress-card">
                        <div class="rts-progress-header">
                            <h4><span class="dashicons dashicons-upload"></span> Import Progress</h4>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <span class="rts-progress-status"><?php echo esc_html($import['status'] ?? 'idle'); ?></span>
                                <?php if (($import['status'] ?? 'idle') !== 'idle'): ?>
                                    <button type="button" class="button button-small" id="rts-cancel-import-btn" title="Cancel import">
                                        <span class="dashicons dashicons-no" style="font-size: 14px; width: 14px; height: 14px;"></span>
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="rts-progress-bar-container">
                            <div class="rts-progress-bar rts-progress-bar-green" id="rts-import-progress-bar">
                                <div class="rts-progress-fill" style="width: 0%"></div>
                            </div>
                            <div class="rts-progress-text" id="rts-import-progress-text">
                                No active import
                            </div>
                        </div>
                    </div>
                    
                    <div class="rts-progress-card">
                        <div class="rts-progress-header">
                            <h4><span class="dashicons dashicons-search"></span> Scan Progress</h4>
                            <span class="rts-progress-status" id="rts-scan-progress-status">Idle</span>
                        </div>
                        <div class="rts-progress-bar-container">
                            <div class="rts-progress-bar rts-progress-bar-green" id="rts-scan-progress-bar">
                                <div class="rts-progress-fill" style="width: 0%"></div>
                            </div>
                            <div class="rts-progress-text" id="rts-scan-progress-text">
                                No active scan
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Navigation Tabs -->
                <nav class="nav-tab-wrapper rts-nav-tabs">
                    <?php self::tab_link('overview','<span class="dashicons dashicons-dashboard"></span> Overview',$tab); ?>
                    <?php self::tab_link('letters','<span class="dashicons dashicons-edit"></span> Letter Management',$tab); ?>
                    <?php self::tab_link('analytics','<span class="dashicons dashicons-chart-bar"></span> Analytics',$tab); ?>
                    <?php self::tab_link('shares','<span class="dashicons dashicons-share"></span> Shares',$tab); ?>
                    <?php self::tab_link('feedback','<span class="dashicons dashicons-testimonial"></span> Feedback',$tab); ?>
                    <?php self::tab_link('settings','<span class="dashicons dashicons-admin-settings"></span> Settings',$tab); ?>
                    <?php self::tab_link('system','<span class="dashicons dashicons-admin-tools"></span> System',$tab); ?>
                </nav>

                <!-- Tab Content -->
                <div class="rts-tab-content">
				<?php
				switch ($tab) {
					case 'letters':  self::render_tab_letters(); break;
					case 'analytics':self::render_tab_analytics($agg); break;
					case 'shares':   self::render_tab_shares(); break;
					case 'feedback': self::render_tab_feedback(); break;
					case 'settings': self::render_tab_settings(); break;
					case 'system':   self::render_tab_system($import, $agg); break;
					case 'overview':
					default:         self::render_tab_overview($import, $agg); break;
				}
				?>
                </div>
                
                <!-- Toast Notification Container -->
                <div id="rts-toast-container"></div>
			</div>
			<?php
		}

		private static function stat_card(string $label, string $value, string $type, string $sublabel): void {
            $icons = [
                'published' => 'dashicons-visibility',
                'inbox' => 'dashicons-email-alt',
                'quarantined' => 'dashicons-shield',
                'total' => 'dashicons-portfolio',
                'feedback' => 'dashicons-testimonial'
            ];

            $links = [
                'published'   => admin_url('edit.php?post_type=letter&post_status=publish'),
                'inbox'       => admin_url('edit.php?post_type=letter&rts_inbox=1'),
                'quarantined' => admin_url('edit.php?post_type=letter&post_status=rts-quarantine'),
                'total'       => admin_url('edit.php?post_type=letter&all_posts=1'),
                'feedback'    => admin_url('edit.php?post_type=letter&page=rts_feedback'),
            ];

            $url = $links[$type] ?? '';
			?>
			<?php if (!empty($url)) : ?>
			<a class="rts-stat-card rts-stat-<?php echo esc_attr($type); ?>" href="<?php echo esc_url($url); ?>">
			<?php else : ?>
			<div class="rts-stat-card rts-stat-<?php echo esc_attr($type); ?>">
			<?php endif; ?>
                <div class="rts-stat-icon">
                    <span class="dashicons <?php echo esc_attr($icons[$type] ?? 'dashicons-chart-area'); ?>"></span>
                </div>
                <div class="rts-stat-content">
                    <div class="rts-stat-label"><?php echo esc_html($label); ?></div>
                    <div class="rts-stat-value"><?php echo esc_html($value); ?></div>
                    <div class="rts-stat-sublabel"><?php echo esc_html($sublabel); ?></div>
                </div>
			<?php if (!empty($url)) : ?>
			</a>
			<?php else : ?>
			</div>
			<?php endif; ?>
			<?php
		}

		private static function tab_link(string $tab, string $label, string $current): void {
			$active = ($tab === $current) ? 'nav-tab-active' : '';
			printf('<a class="nav-tab %s rts-nav-tab" href="%s">%s</a>', esc_attr($active), esc_url(self::url_for_tab($tab)), $label); // Allowed HTML in label
		}

		private static function render_tab_overview($import, $agg): void {
			?>
            <div class="rts-tab-pane">
                <div class="rts-tab-section">
                    <h3><span class="dashicons dashicons-info"></span> How It Works</h3>
                    <div class="rts-info-grid">
                        <div class="rts-info-card">
                            <div class="rts-info-icon" style="background: #e3f2fd;">
                                <span class="dashicons dashicons-email-alt" style="color: #1976d2;"></span>
                            </div>
                            <h4>Inbox</h4>
                            <p>New letters arrive here. They need your review before publishing.</p>
                        </div>
                        <div class="rts-info-card">
                            <div class="rts-info-icon" style="background: #ffebee;">
                                <span class="dashicons dashicons-shield" style="color: #d32f2f;"></span>
                            </div>
                            <h4>Quarantine</h4>
                            <p>Letters flagged by safety checks. Review these carefully before any action.</p>
                        </div>
                        <div class="rts-info-card">
                            <div class="rts-info-icon" style="background: #f3e5f5;">
                                <span class="dashicons dashicons-update" style="color: #7b1fa2;"></span>
                            </div>
                            <h4>Auto-processing</h4>
                            <p>Runs every 5 minutes. Checks safety, quality, and IP limits automatically.</p>
                        </div>
                        <div class="rts-info-card">
                            <div class="rts-info-icon" style="background: #e8f5e9;">
                                <span class="dashicons dashicons-yes" style="color: #388e3c;"></span>
                            </div>
                            <h4>Live Letters</h4>
                            <p>Published letters that passed all checks. Visible to your community.</p>
                        </div>
                    </div>
                </div>
                
                <div class="rts-tab-section">
                    <h3><span class="dashicons dashicons-chart-area"></span> Recent Activity</h3>
                    <div class="rts-activity-feed">
                        <?php 
                        // Get recent letters
                        $recent = new \WP_Query([
                            'post_type' => 'letter',
                            'posts_per_page' => 5,
                            'orderby' => 'date',
                            'order' => 'DESC',
                            'post_status' => ['publish', 'pending']
                        ]);
                        
                        if ($recent->have_posts()): 
                            while ($recent->have_posts()): $recent->the_post();
                                $status = get_post_status();
                                $status_class = $status === 'publish' ? 'status-published' : 'status-pending';
                                $status_text = $status === 'publish' ? 'Published' : 'In Inbox';
                                ?>
                                <div class="rts-activity-item">
                                    <div class="rts-activity-icon <?php echo $status_class; ?>">
                                        <span class="dashicons dashicons-<?php echo $status === 'publish' ? 'visibility' : 'email-alt'; ?>"></span>
                                    </div>
                                    <div class="rts-activity-content">
                                        <div class="rts-activity-title">
                                            <a href="<?php echo get_edit_post_link(); ?>"><?php the_title(); ?></a>
                                            <span class="rts-activity-status <?php echo $status_class; ?>"><?php echo $status_text; ?></span>
                                        </div>
                                        <div class="rts-activity-meta">
                                            <span class="rts-activity-time"><?php echo human_time_diff(get_the_time('U'), current_time('timestamp')) . ' ago'; ?></span>
                                            <?php 
                                            $needs_review = get_post_meta(get_the_ID(), 'needs_review', true);
                                            if ($needs_review === '1'): ?>
                                                <span class="rts-activity-flag">⚠️ Safety Flagged</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endwhile; 
                            wp_reset_postdata();
                        else: ?>
                            <div class="rts-activity-empty">
                                <p>No recent activity</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="rts-tab-section">
                    <h3><span class="dashicons dashicons-clock"></span> Last 24 Hours</h3>
                    <div class="rts-stats-grid" style="grid-template-columns: repeat(3, 1fr);">
                        <?php
                        $today = date('Y-m-d');
                        $yesterday = date('Y-m-d', strtotime('-1 day'));
                        
                        global $wpdb;
                        $today_count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'letter' AND post_date LIKE %s",
                            $today . '%'
                        ));
                        
                        $today_count = (int) $today_count;

                        $yesterday_count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'letter' AND post_date LIKE %s",
                            $yesterday . '%'
                        ));
                        
                        $yesterday_count = (int) $yesterday_count;
?>
                        <div class="rts-stat-card rts-stat-total">
                            <div class="rts-stat-content">
                                <div class="rts-stat-value"><?php echo number_format_i18n($today_count); ?></div>
                                <div class="rts-stat-label">Today</div>
                            </div>
                        </div>
                        <div class="rts-stat-card rts-stat-total">
                            <div class="rts-stat-content">
                                <div class="rts-stat-value"><?php echo number_format_i18n($yesterday_count); ?></div>
                                <div class="rts-stat-label">Yesterday</div>
                            </div>
                        </div>
                        <div class="rts-stat-card rts-stat-total">
                            <div class="rts-stat-content">
                                <div class="rts-stat-value">
                                    <?php 
                                    $change = $yesterday_count > 0
                                        ? (int) round(((($today_count - $yesterday_count) / (float) $yesterday_count) * 100))
                                        : ($today_count > 0 ? 100 : 0);
                                    echo ($change > 0 ? '+' : '') . $change . '%';
                                    ?>
                                </div>
                                <div class="rts-stat-label">Change</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
			<?php
		}

		private static function render_tab_letters(): void {
			?>
			<div class="rts-card" style="margin-bottom: 25px;">
                <h3 class="rts-section-title">Inbox (Unpublished Letters)</h3>
				<?php self::render_letters_table('pending', 'Pending Letters', 15); ?>
            </div>
            
            <div class="rts-card">
                <h3 class="rts-section-title" style="color:#d63638;">⚠️ Quarantine (Safety Risks)</h3>
				<?php self::render_letters_table('needs_review', 'Quarantined Letters', 15); ?>
            </div>
			<?php
		}

		/**
		 * Convert technical flag reasons to human-readable descriptions
		 */
		private static function translate_flag_reason(string $reason): string {
			// Map of technical reasons to human-readable descriptions
			$translations = [
				// IP reasons
				'ip:ip_locked' => 'IP rate limit exceeded',
				'ip:blocked' => 'IP address blocked',
				'ip:spam_pattern' => 'Spam pattern detected',
				
				// Safety reasons
				'safety:flagged' => 'Content safety concern',
				'safety:self_harm' => 'Self-harm content detected',
				'safety:violence' => 'Violent content detected',
				'safety:hate_speech' => 'Hate speech detected',
				'safety:sexual_content' => 'Sexual content detected',
				'safety:spam' => 'Spam detected',
				
				// Quality reasons
				'quality:score_low' => 'Quality score below threshold',
				'quality:too_short' => 'Letter too short',
				'quality:no_substance' => 'Lacks meaningful content',
				'quality:test_content' => 'Test or placeholder content',
				'quality:repetitive' => 'Repetitive content',
				
				// Other
				'quarantine_flag' => 'Manually quarantined',
			];
			
			// Check exact match first
			if (isset($translations[$reason])) {
				return $translations[$reason];
			}
			
			// Handle pattern matches
			if (strpos($reason, 'quality:score_') === 0) {
				$score = (int) str_replace('quality:score_', '', $reason);
				return "Quality score: {$score}/100";
			}
			
			// Fallback: make it more readable
			$reason = str_replace(['_', ':'], ' ', $reason);
			return ucwords($reason);
		}

		private static function render_letters_table(string $mode, string $title, int $limit): void {
			$args = [
				'post_type'      => 'letter',
				'posts_per_page' => $limit,
				'post_status'    => ($mode === 'pending') ? 'pending' : (($mode === 'needs_review') ? 'draft' : ['publish','pending','draft']),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			];
			if ($mode === 'needs_review') {
				$args['meta_query'] = [['key' => 'needs_review', 'value' => '1']];
			}
			$q = new \WP_Query($args);
			
            ?>
            <div class="rts-letters-table-container">
                <?php if (!$q->have_posts()): ?>
                    <div class="rts-empty-state">
                        <span class="dashicons dashicons-<?php echo $mode === 'pending' ? 'email-alt' : 'shield'; ?>"></span>
                        <p>No letters found</p>
                    </div>
                <?php else: ?>
	                <?php if ($mode === 'needs_review'): ?>
	                    <div class="rts-quarantine-toolbar" style="display:flex;gap:10px;align-items:center;margin:10px 0;flex-wrap:wrap;">
	                        <button type="button" class="button rts-bulk-approve-btn">Approve selected</button>
	                        <span style="font-size:12px;opacity:.85;">Approving removes the quarantine flag and re-runs processing.</span>
	                    </div>
	                <?php endif; ?>
                    <table class="wp-list-table widefat fixed striped rts-letters-table">
                        <thead>
                            <tr>
	                            <?php if ($mode === 'needs_review'): ?>
	                                <th style="width:32px;"><input type="checkbox" class="rts-select-all" aria-label="Select all" /></th>
	                            <?php endif; ?>
                                <th>ID</th>
                                <th>Letter</th>
                                <th>Status</th>
                                <th>Quality</th>
                                <th>Safety</th>
	                            <?php if ($mode === 'needs_review'): ?>
	                                <th>Why flagged</th>
	                            <?php endif; ?>
                                <th>Submitted</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($q->have_posts()): $q->the_post(); 
                                $id = get_the_ID();
                                $score = get_post_meta($id, 'quality_score', true);
                                $needs = get_post_meta($id, 'needs_review', true) === '1';
                                $status = get_post_status($id);
                                $edit = get_edit_post_link($id, '');
                                $view = get_permalink($id);
                                $excerpt = wp_trim_words(get_the_content(), 10);
                            ?>
                            <tr>
	                            <?php if ($mode === 'needs_review'): ?>
	                                <td><input type="checkbox" class="rts-row-check" value="<?php echo esc_attr($id); ?>" aria-label="Select letter <?php echo esc_attr($id); ?>" /></td>
	                            <?php endif; ?>
                                <td><?php echo esc_html((string) $id); ?></td>
                                <td>
                                    <a href="<?php echo esc_url($edit); ?>" class="rts-letter-title">
                                        <strong><?php echo esc_html(get_the_title()); ?></strong>
                                    </a>
                                    <div class="rts-letter-excerpt"><?php echo esc_html($excerpt); ?></div>
                                </td>
				                <td>
				                    <span class="rts-status-badge rts-status-<?php echo esc_attr($status); ?>">
				                        <?php echo esc_html($status); ?>
				                    </span>
				                    <?php 
				                    $processing_last = get_post_meta($id, 'rts_processing_last', true);
				                    if ($status === 'pending' && empty($processing_last)): 
				                    ?>
				                    <div style="font-size: 11px; color: #2271b1; margin-top: 3px;">
				                        <span class="dashicons dashicons-clock" style="font-size: 11px; width: 11px; height: 11px;"></span> Queued for processing
				                    </div>
				                    <?php elseif ($processing_last): ?>
				                    <div style="font-size: 11px; color: #50575e; margin-top: 3px;">
				                        ✓ Processed
				                    </div>
				                    <?php endif; ?>
				                </td>
                                <td>
                                    <div class="rts-quality-score">
                                        <?php if ($score !== ''): ?>
                                            <div class="rts-score-bar">
                                                <div class="rts-score-fill" style="width: <?php echo esc_attr($score); ?>%"></div>
                                            </div>
                                            <span class="rts-score-value"><?php echo esc_html($score); ?></span>
                                        <?php else: ?>
                                            <span class="rts-score-na">N/A</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($needs): ?>
                                        <span class="rts-safety-flag">⚠️ Flagged</span>
                                    <?php else: ?>
                                        <span class="rts-safety-ok">✓ Clear</span>
                                    <?php endif; ?>
                                </td>
	                            <?php if ($mode === 'needs_review'): ?>
	                                <td>
	                                    <?php
	                                    $flags_json = (string) get_post_meta($id, 'rts_flagged_keywords', true);
	                                    $flags = [];
	                                    if (!empty($flags_json)) {
	                                        $decoded = json_decode($flags_json, true);
	                                        if (is_array($decoded)) {
	                                            $flags = array_values(array_filter(array_map('strval', $decoded)));
	                                        }
	                                    }
	                                    $reasons_json = (string) get_post_meta($id, 'rts_flag_reasons', true);
	                                    $reasons = [];
	                                    if (!empty($reasons_json)) {
	                                        $decoded2 = json_decode($reasons_json, true);
	                                        if (is_array($decoded2)) {
	                                            $reasons = array_values(array_filter(array_map('strval', $decoded2)));
	                                        }
	                                    }
	                                    $why = [];
	                                    foreach ($reasons as $r) {
	                                        $why[] = self::translate_flag_reason($r);
	                                    }
	                                    if (!empty($flags)) {
	                                        $why[] = 'Matched: ' . implode(', ', array_slice($flags, 0, 5));
	                                    }
	                                    if (empty($why)) {
	                                        echo '<span style="opacity:.75;">Not recorded</span>';
	                                    } else {
	                                        echo esc_html(implode(' | ', $why));
	                                    }
	                                    ?>
	                                </td>
	                            <?php endif; ?>
                                <td>
                                    <?php echo esc_html(get_the_date('M j, Y g:ia')); ?>
                                </td>
                                <td>
                                    <div class="rts-action-buttons-small">
                                        <a class="button button-small" href="<?php echo esc_url($edit); ?>">Edit</a>
                                        <a class="button button-small" href="<?php echo esc_url($view); ?>" target="_blank">View</a>
	                                    <?php if ($mode === 'needs_review'): ?>
	                                        <button class="button button-small rts-approve-btn" data-post-id="<?php echo esc_attr($id); ?>">Approve</button>
	                                    <?php endif; ?>
                                        <button class="button button-small rts-manual-process-btn" data-post-id="<?php echo esc_attr($id); ?>">
                                            Process
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; wp_reset_postdata(); ?>
                        </tbody>
                    </table>
                    <div class="rts-table-footer" style="padding: 10px; background: #f8f9fa; border-top: 1px solid #dcdcde;">
                        <a href="<?php echo esc_url(admin_url('edit.php?post_type=letter&post_status=' . ($mode === 'pending' ? 'pending' : 'all'))); ?>" class="button">
                            View All <?php echo $mode === 'pending' ? 'Inbox' : 'Quarantined'; ?> Letters
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            <?php
		}

		private static function action_button(string $cmd, string $label, int $post_id, bool $disabled): void {
			$style = $disabled ? 'opacity:.55;pointer-events:none;' : '';
			?>
			<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
				<input type="hidden" name="action" value="rts_dashboard_action">
				<input type="hidden" name="command" value="<?php echo esc_attr($cmd); ?>">
				<input type="hidden" name="post_id" value="<?php echo esc_attr((string) $post_id); ?>">
				<?php wp_nonce_field('rts_dashboard_action'); ?>
				<button type="submit" class="button button-small" style="<?php echo esc_attr($style); ?>"><?php echo esc_html($label); ?></button>
			</form>
			<?php
		}

	private static function render_tab_analytics($agg): void {
		global $wpdb;

		// Get additional stats for enhanced dashboard
		$letters = wp_count_posts('letter');
		$published_count = (int) $letters->publish;
		$pending_count = (int) $letters->pending;
		
		// Calculate acceptance rate
		$total_submissions = $published_count + $pending_count;
		$acceptance_rate = $total_submissions > 0 ? round(($published_count / $total_submissions) * 100, 1) : 0;

		// Get average quality score
		$avg_quality = $wpdb->get_var("
			SELECT AVG(CAST(meta_value AS DECIMAL(5,2))) 
			FROM {$wpdb->postmeta} 
			WHERE meta_key = 'quality_score' 
			AND meta_value != ''
		");
		// strict_types=1 + WPDB returns strings, so cast before round().
		$avg_quality = (is_numeric($avg_quality)) ? round((float) $avg_quality, 1) : 0;
		?>
		

		<!-- Growth Velocity Cards -->
		<div class="rts-analytics-grid-3">
			<div class="rts-analytics-card">
				<div class="rts-analytics-icon" style="background: rgba(33, 113, 177, 0.1);">
					<span class="dashicons dashicons-clock" style="color: #2271b1;"></span>
				</div>
				<div class="rts-analytics-content">
					<div class="rts-analytics-label">Last 24 Hours</div>
					<div class="rts-analytics-value"><?php echo esc_html(number_format_i18n((int)($agg['velocity_24h']??0))); ?></div>
					<div class="rts-analytics-sub">New Letters Submitted</div>
				</div>
			</div>

			<div class="rts-analytics-card">
				<div class="rts-analytics-icon" style="background: rgba(45, 127, 249, 0.1);">
					<span class="dashicons dashicons-chart-line" style="color: #2d7ff9;"></span>
				</div>
				<div class="rts-analytics-content">
					<div class="rts-analytics-label">Last 7 Days</div>
					<div class="rts-analytics-value"><?php echo esc_html(number_format_i18n((int)($agg['velocity_7d']??0))); ?></div>
					<div class="rts-analytics-sub">New Letters Submitted</div>
				</div>
			</div>

			<div class="rts-analytics-card">
				<div class="rts-analytics-icon" style="background: rgba(124, 77, 255, 0.1);">
					<span class="dashicons dashicons-calendar-alt" style="color: #7c4dff;"></span>
				</div>
				<div class="rts-analytics-content">
					<div class="rts-analytics-label">Last 30 Days</div>
					<div class="rts-analytics-value"><?php echo esc_html(number_format_i18n((int)($agg['velocity_30d']??0))); ?></div>
					<div class="rts-analytics-sub">New Letters Submitted</div>
				</div>
			</div>
		</div>

		<!-- Quality Metrics -->
		<h3 class="rts-section-title" style="margin-top: 30px; margin-bottom: 20px;">
			<span class="dashicons dashicons-awards"></span> Quality Metrics
		</h3>

		<div class="rts-analytics-grid-3">
			<div class="rts-analytics-card">
				<div class="rts-analytics-icon" style="background: rgba(72, 187, 120, 0.1);">
					<span class="dashicons dashicons-yes-alt" style="color: #48bb78;"></span>
				</div>
				<div class="rts-analytics-content">
					<div class="rts-analytics-label">Acceptance Rate</div>
					<div class="rts-analytics-value"><?php echo esc_html($acceptance_rate); ?>%</div>
					<div class="rts-analytics-sub"><?php echo esc_html(number_format_i18n($published_count)); ?> of <?php echo esc_html(number_format_i18n($total_submissions)); ?> published</div>
				</div>
			</div>

			<div class="rts-analytics-card">
				<div class="rts-analytics-icon" style="background: rgba(252, 163, 17, 0.1);">
					<span class="dashicons dashicons-star-filled" style="color: #FCA311;"></span>
				</div>
				<div class="rts-analytics-content">
					<div class="rts-analytics-label">Average Quality Score</div>
					<div class="rts-analytics-value"><?php echo esc_html($avg_quality); ?></div>
					<div class="rts-analytics-sub">Out of 100</div>
				</div>
			</div>

			<div class="rts-analytics-card">
				<div class="rts-analytics-icon" style="background: rgba(214, 54, 56, 0.1);">
					<span class="dashicons dashicons-shield" style="color: #d63638;"></span>
				</div>
				<div class="rts-analytics-content">
					<div class="rts-analytics-label">In Quarantine</div>
					<div class="rts-analytics-value"><?php echo esc_html(number_format_i18n($pending_count)); ?></div>
					<div class="rts-analytics-sub">Pending Review</div>
				</div>
			</div>
		</div>

		<!-- Taxonomy Breakdown -->
		<h3 class="rts-section-title" style="margin-top: 30px; margin-bottom: 20px;">
			<span class="dashicons dashicons-category"></span> Content Analysis
		</h3>

		<div class="rts-analytics-grid-2">
			<?php self::render_analytics_box('Top Feelings', $agg['taxonomy_breakdown']['letter_feeling'] ?? [], 'dashicons-heart', '#e91e63'); ?>
			<?php self::render_analytics_box('Top Tones', $agg['taxonomy_breakdown']['letter_tone'] ?? [], 'dashicons-admin-appearance', '#9c27b0'); ?>
		</div>
		<?php
	}

	private static function render_analytics_box(string $title, $data, string $icon, string $color): void {
		?>
		<div class="rts-analytics-box-card">
			<div class="rts-analytics-box-header">
				<span class="dashicons <?php echo esc_attr($icon); ?>" style="color: <?php echo esc_attr($color); ?>;"></span>
				<h4><?php echo esc_html($title); ?></h4>
			</div>
			<div class="rts-analytics-box-content">
				<?php if (empty($data) || !is_array($data)): ?>
					<div class="rts-empty-state-small">
						<span class="dashicons dashicons-chart-bar"></span>
						<p>No data yet</p>
					</div>
				<?php else: ?>
					<div class="rts-analytics-list">
						<?php foreach (array_slice($data, 0, 8) as $k => $v): ?>
							<div class="rts-analytics-list-item">
								<span class="rts-analytics-list-label"><?php echo esc_html((string) $k); ?></span>
								<span class="rts-analytics-list-value"><?php echo esc_html(number_format_i18n((int) $v)); ?></span>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private static function render_kv_box(string $title, $data): void {
		// Legacy function - keeping for backward compatibility
		self::render_analytics_box($title, $data, 'dashicons-chart-bar', '#2271b1');
	}

	private static function render_tab_shares(): void {
		global $wpdb;

		// Get platform-specific share counts
		$platforms = ['facebook', 'x', 'threads', 'whatsapp', 'reddit', 'copy', 'email'];
		$platform_counts = [];
		$total_shares = 0;

		foreach ($platforms as $platform) {
			$meta_key = 'rts_share_' . $platform;
			$count = $wpdb->get_var($wpdb->prepare(
				"SELECT SUM(CAST(meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} WHERE meta_key = %s",
				$meta_key
			));
			$platform_counts[$platform] = intval($count);
			$total_shares += intval($count);
		}

		$offset_shares = (int) get_option(self::OPTION_OFFSET_SHARES, 0);
		$display_total = $total_shares + $offset_shares;

		// Platform display config
		$platform_config = [
			'facebook' => ['label' => 'Facebook', 'icon' => 'dashicons-facebook', 'color' => '#1877F2'],
			'x' => ['label' => 'X (Twitter)', 'icon' => 'dashicons-twitter', 'color' => '#000000'],
			'threads' => ['label' => 'Threads', 'icon' => 'dashicons-groups', 'color' => '#000000'],
			'whatsapp' => ['label' => 'WhatsApp', 'icon' => 'dashicons-whatsapp', 'color' => '#25D366'],
			'reddit' => ['label' => 'Reddit', 'icon' => 'dashicons-reddit', 'color' => '#FF4500'],
			'copy' => ['label' => 'Copy Link', 'icon' => 'dashicons-admin-links', 'color' => '#6C757D'],
			'email' => ['label' => 'Email', 'icon' => 'dashicons-email', 'color' => '#EA4335'],
		];
		?>
		<div class="rts-share-engagement-wrapper">
			<!-- Total Summary Card -->
			<div class="rts-card">
				<h3 class="rts-section-title">Share Engagement Overview</h3>
				<div class="rts-share-total-stat">
					<div class="rts-stat-label">Total Shares (All Platforms)</div>
					<div class="rts-stat-value color-info"><?php echo esc_html(number_format_i18n($display_total)); ?></div>
					<?php if ($offset_shares > 0): ?>
					<div class="rts-stat-sub">
						Live: <?php echo number_format_i18n($total_shares); ?> + Offset: <?php echo number_format_i18n($offset_shares); ?>
					</div>
					<?php endif; ?>
				</div>
			</div>

			<!-- Platform Breakdown Cards -->
			<h3 class="rts-section-title" style="margin-top: 30px; margin-bottom: 20px;">Platform Breakdown</h3>
			<div class="rts-share-platform-grid">
				<?php foreach ($platforms as $platform):
					$config = $platform_config[$platform];
					$count = $platform_counts[$platform];
					$percentage = $total_shares > 0 ? round(($count / $total_shares) * 100, 1) : 0;
				?>
				<div class="rts-share-platform-card" data-platform="<?php echo esc_attr($platform); ?>">
					<div class="rts-platform-header">
						<span class="dashicons <?php echo esc_attr($config['icon']); ?>" style="color: <?php echo esc_attr($config['color']); ?>; font-size: 24px;"></span>
						<h4 class="rts-platform-name"><?php echo esc_html($config['label']); ?></h4>
					</div>
					<div class="rts-platform-stats">
						<div class="rts-platform-count"><?php echo number_format_i18n($count); ?></div>
						<div class="rts-platform-percentage"><?php echo esc_html($percentage); ?>% of total</div>
					</div>
				</div>
				<?php endforeach; ?>
			</div>

			<style>
			.rts-share-engagement-wrapper {
				max-width: 1200px;
			}

			.rts-share-total-stat {
				text-align: center;
				padding: 20px;
			}

			.rts-share-total-stat .rts-stat-label {
				font-size: 1.1rem;
				color: #646970;
				margin-bottom: 10px;
			}

			.rts-share-total-stat .rts-stat-value {
				font-size: 3rem;
				font-weight: 700;
				margin-bottom: 8px;
			}

			.rts-share-total-stat .rts-stat-sub {
				font-size: 0.9rem;
				color: #787C82;
			}

			.rts-share-platform-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
				gap: 20px;
				margin-bottom: 30px;
			}

			.rts-share-platform-card {
				background: #182437;
				border: 2px solid #2A3B52;
				border-radius: 12px;
				padding: 24px;
				transition: all 0.3s ease;
				color: #F1E3D3;
			}

			.rts-share-platform-card:hover {
				border-color: #FCA311;
				transform: translateY(-4px);
				box-shadow: 0 8px 16px rgba(252, 163, 17, 0.2);
			}

			.rts-platform-header {
				display: flex;
				align-items: center;
				gap: 12px;
				margin-bottom: 16px;
				padding-bottom: 12px;
				border-bottom: 2px solid rgba(252, 163, 17, 0.2);
			}

			.rts-platform-name {
				margin: 0;
				font-size: 1.1rem;
				font-weight: 600;
				color: #FCA311;
			}

			.rts-platform-stats {
				text-align: center;
			}

			.rts-platform-count {
				font-size: 2.4rem;
				font-weight: 700;
				color: #F1E3D3;
				margin-bottom: 6px;
			}

			.rts-platform-percentage {
				font-size: 0.95rem;
				color: #A0A5AA;
			}

			/* Light mode adjustments */
			body:not(.rts-dark-mode) .rts-share-platform-card {
				background: #F9F9F9;
				border-color: #DEDEDE;
				color: #2A2A2A;
			}

			body:not(.rts-dark-mode) .rts-share-platform-card:hover {
				border-color: #FCA311;
				background: #FFFFFF;
			}

			body:not(.rts-dark-mode) .rts-platform-name {
				color: #2A2A2A;
			}

			body:not(.rts-dark-mode) .rts-platform-count {
				color: #2A2A2A;
			}

			body:not(.rts-dark-mode) .rts-platform-percentage {
				color: #646970;
			}

			body:not(.rts-dark-mode) .rts-platform-header {
				border-bottom-color: rgba(0, 0, 0, 0.1);
			}

			@media (max-width: 768px) {
				.rts-share-platform-grid {
					grid-template-columns: 1fr;
				}

				.rts-platform-count {
					font-size: 2rem;
				}
			}
			</style>
		</div>
		<?php
	}


private static function render_tab_feedback(): void {
	global $wpdb;
	
	$count = wp_count_posts('rts_feedback');
	$total = (int) ($count->publish ?? 0);

	// Get feedback stats
	$mood_stats = $wpdb->get_results("
		SELECT meta_value as mood_change, COUNT(*) as count
		FROM {$wpdb->postmeta}
		WHERE meta_key = 'mood_change'
		AND meta_value != ''
		GROUP BY meta_value
		ORDER BY count DESC
	", ARRAY_A);

	$triggered_count = $wpdb->get_var("
		SELECT COUNT(*)
		FROM {$wpdb->postmeta}
		WHERE meta_key = 'triggered'
		AND meta_value = '1'
	");

	$positive_count = $wpdb->get_var("
		SELECT COUNT(*)
		FROM {$wpdb->postmeta}
		WHERE meta_key = 'rating'
		AND meta_value IN ('up', 'neutral')
	");

	$items = get_posts([
		'post_type'      => 'rts_feedback',
		'post_status'    => 'publish',
		'posts_per_page' => 25,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	]);

	// Calculate mood improvement rate
	$much_better = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'mood_change' AND meta_value = 'much_better'");
	$little_better = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'mood_change' AND meta_value = 'little_better'");
	$mood_total = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'mood_change' AND meta_value != ''");
	$improvement_rate = $mood_total > 0 ? round((($much_better + $little_better) / $mood_total) * 100, 1) : 0;
	?>
	
	<!-- Feedback Overview Cards -->
	<div class="rts-analytics-grid-4">
		<div class="rts-analytics-card">
			<div class="rts-analytics-icon" style="background: rgba(45, 127, 249, 0.1);">
				<span class="dashicons dashicons-testimonial" style="color: #2d7ff9;"></span>
			</div>
			<div class="rts-analytics-content">
				<div class="rts-analytics-label">Total Feedback</div>
				<div class="rts-analytics-value"><?php echo esc_html(number_format_i18n($total)); ?></div>
				<div class="rts-analytics-sub">All time responses</div>
			</div>
		</div>

		<div class="rts-analytics-card">
			<div class="rts-analytics-icon" style="background: rgba(72, 187, 120, 0.1);">
				<span class="dashicons dashicons-smiley" style="color: #48bb78;"></span>
			</div>
			<div class="rts-analytics-content">
				<div class="rts-analytics-label">Mood Improvement</div>
				<div class="rts-analytics-value"><?php echo esc_html($improvement_rate); ?>%</div>
				<div class="rts-analytics-sub">Feel better after reading</div>
			</div>
		</div>

		<div class="rts-analytics-card">
			<div class="rts-analytics-icon" style="background: rgba(252, 163, 17, 0.1);">
				<span class="dashicons dashicons-thumbs-up" style="color: #FCA311;"></span>
			</div>
			<div class="rts-analytics-content">
				<div class="rts-analytics-label">Positive Ratings</div>
				<div class="rts-analytics-value"><?php echo esc_html(number_format_i18n((int)$positive_count)); ?></div>
				<div class="rts-analytics-sub">Helpful responses</div>
			</div>
		</div>

		<div class="rts-analytics-card">
			<div class="rts-analytics-icon" style="background: rgba(214, 54, 56, 0.1);">
				<span class="dashicons dashicons-warning" style="color: #d63638;"></span>
			</div>
			<div class="rts-analytics-content">
				<div class="rts-analytics-label">Triggered Reports</div>
				<div class="rts-analytics-value"><?php echo esc_html(number_format_i18n((int)$triggered_count)); ?></div>
				<div class="rts-analytics-sub">Letters flagged unsafe</div>
			</div>
		</div>
	</div>

	<!-- Mood Change Breakdown -->
	<?php if (!empty($mood_stats)): ?>
	<h3 class="rts-section-title" style="margin-top: 30px; margin-bottom: 20px;">
		<span class="dashicons dashicons-heart"></span> Mood Change Distribution
	</h3>
	
	<div class="rts-analytics-box-card" style="max-width: 600px;">
		<div class="rts-analytics-box-content">
			<div class="rts-mood-chart">
				<?php 
				$mood_labels = [
					'much_better' => ['label' => '😊 Much Better', 'color' => '#48bb78'],
					'little_better' => ['label' => '🙂 A Little Better', 'color' => '#90cdf4'],
					'no_change' => ['label' => '😐 No Change', 'color' => '#a0aec0'],
					'little_worse' => ['label' => '😟 A Little Worse', 'color' => '#fc8181'],
					'much_worse' => ['label' => '😢 Much Worse', 'color' => '#f56565'],
				];
				
				foreach ($mood_stats as $stat): 
					$mood = $stat['mood_change'];
					$count = (int) $stat['count'];
					$percentage = $mood_total > 0 ? round(($count / $mood_total) * 100, 1) : 0;
					$config = $mood_labels[$mood] ?? ['label' => ucfirst($mood), 'color' => '#64748b'];
				?>
					<div class="rts-mood-bar-item">
						<div class="rts-mood-bar-label">
							<span><?php echo esc_html($config['label']); ?></span>
							<span class="rts-mood-bar-count"><?php echo esc_html(number_format_i18n($count)); ?> (<?php echo esc_html($percentage); ?>%)</span>
						</div>
						<div class="rts-mood-bar-track">
							<div class="rts-mood-bar-fill" style="width: <?php echo esc_attr($percentage); ?>%; background: <?php echo esc_attr($config['color']); ?>;"></div>
						</div>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php endif; ?>

	<!-- Latest Feedback Table -->
	<h3 class="rts-section-title" style="margin-top: 30px; margin-bottom: 20px;">
		<span class="dashicons dashicons-list-view"></span> Latest Feedback (25)
	</h3>

	<div class="rts-feedback-table-card">
		<table class="rts-feedback-table">
			<thead>
				<tr>
					<th>Date</th>
					<th>Letter</th>
					<th>Rating</th>
					<th>Mood Change</th>
					<th>Triggered</th>
					<th>Comment</th>
				</tr>
			</thead>
			<tbody>
				<?php if (!$items): ?>
					<tr>
						<td colspan="6">
							<div class="rts-empty-state-small">
								<span class="dashicons dashicons-testimonial"></span>
								<p>No feedback yet</p>
							</div>
						</td>
					</tr>
				<?php else: foreach ($items as $p):
					$letter_id    = (int) get_post_meta($p->ID, 'letter_id', true);
					$rating       = (string) get_post_meta($p->ID, 'rating', true);
					$mood_change  = (string) get_post_meta($p->ID, 'mood_change', true);
					$triggered    = (string) get_post_meta($p->ID, 'triggered', true);
					$comment      = (string) get_post_meta($p->ID, 'comment', true);
					$letter_link  = $letter_id ? admin_url('post.php?post=' . $letter_id . '&action=edit') : '';
					
					$rating_icon = [
						'up' => '👍',
						'down' => '👎',
						'neutral' => '🤷'
					];
					
					$mood_emoji = [
						'much_better' => '😊',
						'little_better' => '🙂',
						'no_change' => '😐',
						'little_worse' => '😟',
						'much_worse' => '😢'
					];
					?>
					<tr>
						<td><span class="rts-date-badge"><?php echo esc_html(get_date_from_gmt($p->post_date_gmt, 'M j, Y')); ?></span></td>
						<td>
							<?php if ($letter_id && $letter_link): ?>
								<a href="<?php echo esc_url($letter_link); ?>" class="rts-letter-link">#<?php echo esc_html((string) $letter_id); ?></a>
							<?php else: ?>
								<span style="color: #a0aec0;">—</span>
							<?php endif; ?>
						</td>
						<td>
							<span class="rts-rating-badge rts-rating-<?php echo esc_attr($rating); ?>">
								<?php echo $rating_icon[$rating] ?? '—'; ?> <?php echo esc_html(ucfirst($rating)); ?>
							</span>
						</td>
						<td>
							<span class="rts-mood-badge">
								<?php echo $mood_emoji[$mood_change] ?? ''; ?> <?php echo esc_html(str_replace('_', ' ', ucfirst($mood_change))); ?>
							</span>
						</td>
						<td>
							<?php if ($triggered === '1'): ?>
								<span class="rts-triggered-badge">⚠️ Yes</span>
							<?php else: ?>
								<span style="color: #a0aec0;">No</span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ($comment): ?>
								<div class="rts-comment-preview" title="<?php echo esc_attr($comment); ?>">
									<?php echo esc_html(wp_trim_words($comment, 8)); ?>
								</div>
							<?php else: ?>
								<span style="color: #a0aec0;">—</span>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; endif; ?>
			</tbody>
		</table>
	</div>

	<div style="margin-top: 20px;">
		<a class="button button-primary" href="<?php echo esc_url(admin_url('edit.php?post_type=rts_feedback')); ?>">
			<span class="dashicons dashicons-external" style="margin-top: 3px;"></span> View All Feedback
		</a>
	</div>
	<?php
}

private static function render_tab_settings(): void {

			$auto_enabled = self::is_auto_enabled();
			$batch        = (int) get_option(self::OPTION_AUTO_BATCH, 50);
			$min_quality  = (int) get_option(self::OPTION_MIN_QUALITY, 40);
			$ip_thresh    = (int) get_option(self::OPTION_IP_THRESHOLD, 20);
            
            $off_letters  = (int) get_option(self::OPTION_OFFSET_LETTERS, 0);
            $off_shares   = (int) get_option(self::OPTION_OFFSET_SHARES, 0);

            // Frontend stats overrides (used by [rts_site_stats_row])
            $frontend_overrides = get_option('rts_stats_override', []);
            if (!is_array($frontend_overrides)) {
                $frontend_overrides = [];
            }
            $frontend_overrides = wp_parse_args($frontend_overrides, [
                'enabled' => 0,
                'letters_delivered' => 0,
                'letters_submitted' => 0,
                'helps' => 0,
                'feel_better_percent' => '',
            ]);
			?>
			<div class="rts-card" style="max-width:800px;">
				<h3 class="rts-section-title">Engine Configuration</h3>
				<form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
					<?php settings_fields('rts_engine_settings'); ?>
					<table class="form-table" role="presentation">
                        <tr><td colspan="2"><hr><strong>Automated Moderation</strong></td></tr>
						<tr>
							<th scope="row">Auto-processing</th>
							<td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_AUTO_ENABLED); ?>" value="1" <?php checked($auto_enabled); ?>> Enable background engine</label></td>
						</tr>
						<tr>
							<th scope="row">Auto interval</th>
							<td>
								<input type="number" min="20" step="10" name="<?php echo esc_attr(self::OPTION_AUTO_INTERVAL); ?>" value="<?php echo esc_attr((string) (int) get_option(self::OPTION_AUTO_INTERVAL, 300)); ?>" class="small-text"> seconds
								<p class="description">Base background tick. Turbo can override this when backlog spikes.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Batch size</th>
							<td>
								<input type="number" min="10" max="250" step="10" name="<?php echo esc_attr(self::OPTION_AUTO_BATCH); ?>" value="<?php echo esc_attr((string) $batch); ?>" class="small-text"> letters per tick
								<p class="description">Max 250. Used by both auto tick and turbo tick.</p>
							</td>
						</tr>
						<tr><td colspan="2"><hr><strong>Turbo Pump</strong></td></tr>
						<tr>
							<th scope="row">Turbo mode</th>
							<td>
								<label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_TURBO_ENABLED); ?>" value="1" <?php checked(get_option(self::OPTION_TURBO_ENABLED, '1') === '1'); ?>> Enable Turbo</label>
								<p class="description">When backlog passes the threshold, the engine starts immediate loops and keeps going until the queue hits 0.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Turbo threshold</th>
							<td>
								<input type="number" min="10" step="10" name="<?php echo esc_attr(self::OPTION_TURBO_THRESHOLD); ?>" value="<?php echo esc_attr((string) (int) get_option(self::OPTION_TURBO_THRESHOLD, 100)); ?>" class="small-text"> letters
								<p class="description">If unprocessed letters (and optionally quarantined) exceed this, Turbo starts.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Turbo scope</th>
							<td>
								<select name="<?php echo esc_attr(self::OPTION_TURBO_SCOPE); ?>">
									<?php $scope = (string) get_option(self::OPTION_TURBO_SCOPE, 'both'); ?>
									<option value="inbox" <?php selected($scope, 'inbox'); ?>>Inbox only</option>
									<option value="both" <?php selected($scope, 'both'); ?>>Inbox + Quarantine</option>
								</select>
								<p class="description">Recommended: <strong>Inbox + Quarantine</strong> so quarantined letters don't pile up silently.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Turbo interval</th>
							<td>
								<input type="number" min="5" step="5" name="<?php echo esc_attr(self::OPTION_TURBO_INTERVAL); ?>" value="<?php echo esc_attr((string) (int) get_option(self::OPTION_TURBO_INTERVAL, 20)); ?>" class="small-text"> seconds
								<p class="description">Delay between Turbo loops. Lower is faster, but can increase server load.</p>
							</td>
						</tr>
						<tr><td colspan="2"><hr><strong>Display</strong></td></tr>
						<tr>
							<th scope="row">Dark mode</th>
							<td><label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_DARK_MODE); ?>" value="1" <?php checked(get_option(self::OPTION_DARK_MODE, '0') === '1'); ?>> Use a dark UI for RTS dashboard pages</label></td>
						</tr>

						<tr>
							<th scope="row">Min Quality Score</th>
							<td><input type="number" name="<?php echo esc_attr(self::OPTION_MIN_QUALITY); ?>" value="<?php echo esc_attr((string) $min_quality); ?>" class="small-text"> <p class="description">Letters below this score are quarantined.</p></td>
						</tr>
                        
                        <tr><td colspan="2"><hr><strong>Frontend Stats Overrides (for [rts_site_stats_row])</strong></td></tr>
                        <tr>
                            <th scope="row">Enable Offsets</th>
                            <td>
                                <label><input type="checkbox" name="rts_stats_override[enabled]" value="1" <?php checked(!empty($frontend_overrides['enabled'])); ?>> Enable migration offsets for frontend stats</label>
                                <p class="description">When enabled, the offsets below are added to live stats shown to site visitors.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Letters Delivered Offset</th>
                            <td>
                                <input type="number" name="rts_stats_override[letters_delivered]" value="<?php echo esc_attr((string) intval($frontend_overrides['letters_delivered'])); ?>" class="regular-text">
                                <p class="description">Added to total letters delivered count (view_count sum).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Letters Submitted Offset</th>
                            <td>
                                <input type="number" name="rts_stats_override[letters_submitted]" value="<?php echo esc_attr((string) intval($frontend_overrides['letters_submitted'])); ?>" class="regular-text">
                                <p class="description">Added to total submitted letters count (published + pending).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">"Helped" Count Offset</th>
                            <td>
                                <input type="number" name="rts_stats_override[helps]" value="<?php echo esc_attr((string) intval($frontend_overrides['helps'])); ?>" class="regular-text">
                                <p class="description">Added to total "helpful" responses (affects feel better percentage).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Override Feel Better %</th>
                            <td>
                                <input type="number" name="rts_stats_override[feel_better_percent]" value="<?php echo esc_attr($frontend_overrides['feel_better_percent']); ?>" class="small-text" min="0" max="100" step="1"> %
                                <p class="description">If set (0-100), this overrides the calculated percentage. Leave blank to use calculated value.</p>
                            </td>
                        </tr>

                        <tr><td colspan="2"><hr><strong>Email Notifications</strong></td></tr>
                        <tr>
                            <th scope="row">Enable Notifications</th>
                            <td>
                                <label><input type="checkbox" name="rts_email_notifications_enabled" value="1" <?php checked(get_option('rts_email_notifications_enabled', '1')); ?>> Send email alerts for feedback and reports</label>
                                <p class="description">When enabled, admins receive emails when users submit feedback or report triggering letters.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Admin Email</th>
                            <td>
                                <input type="email" name="rts_admin_notification_email" value="<?php echo esc_attr(get_option('rts_admin_notification_email', get_option('admin_email'))); ?>" class="regular-text">
                                <p class="description">Primary email address for moderation notifications. Defaults to WordPress admin email.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">CC Email (Optional)</th>
                            <td>
                                <input type="email" name="rts_cc_notification_email" value="<?php echo esc_attr(get_option('rts_cc_notification_email', '')); ?>" class="regular-text">
                                <p class="description">Additional email address to CC on notifications. Leave blank if not needed.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Notification Types</th>
                            <td>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rts_notify_on_feedback" value="1" <?php checked(get_option('rts_notify_on_feedback', '1')); ?>>
                                    Notify on all feedback submissions
                                </label>
                                <label style="display: block; margin-bottom: 8px;">
                                    <input type="checkbox" name="rts_notify_on_triggered" value="1" <?php checked(get_option('rts_notify_on_triggered', '1')); ?>>
                                    Notify when letter is reported as triggering (urgent)
                                </label>
                                <label style="display: block;">
                                    <input type="checkbox" name="rts_notify_on_negative" value="1" <?php checked(get_option('rts_notify_on_negative', '0')); ?>>
                                    Notify on negative feedback (👎 "Didn't help")
                                </label>
                            </td>
                        </tr>
                        </tr>
					</table>
					<?php submit_button('Save Settings'); ?>
				</form>
			</div>
            
            <!-- Force Reprocess Quarantine Button -->
            <div class="rts-card" style="margin-top: 30px; max-width:800px;">
                <h3 class="rts-section-title">🔄 Force Reprocess Quarantine</h3>
                <p>Force all quarantined letters to be reprocessed with current moderation rules. This clears stuck timestamps and queues all quarantined letters for processing.</p>
                
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <button type="button" 
                            id="rts-force-reprocess-quarantine" 
                            class="button button-primary">
                        🔄 Force Reprocess All Quarantine
                    </button>
                    <span id="rts-force-reprocess-status" style="font-weight: 500;"></span>
                </div>
                
                <div class="rts-notice rts-notice-warning">
                    <strong>⚠️ When to use this:</strong>
                    <ul class="rts-notice-list">
                        <li>After updating moderation rules or trigger words</li>
                        <li>When quarantined letters appear stuck</li>
                        <li>After fixing IP rate limiting settings</li>
                        <li>When you want to give safe letters another chance</li>
                    </ul>
                </div>
                
                <script>
                jQuery(document).ready(function($) {
                    $('#rts-force-reprocess-quarantine').on('click', function(e) {
                        e.preventDefault();
                        
                        var $button = $(this);
                        var $status = $('#rts-force-reprocess-status');
                        
                        if (!confirm('This will reprocess ALL quarantined letters with current moderation rules. This may take several minutes. Continue?')) {
                            return;
                        }
                        
                        $button.prop('disabled', true).text('⏳ Processing...');
                        $status.html('<span style="color: #666;">Clearing timestamps and queuing letters...</span>');
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'rts_force_reprocess_quarantine',
                                nonce: '<?php echo wp_create_nonce('rts_dashboard_nonce'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $status.html('<span style="color: #46b450;">✅ ' + response.data.message + '</span>');
                                    $button.prop('disabled', false).text('🔄 Force Reprocess All Quarantine');
                                    
                                    // Optionally reload the page after 3 seconds
                                    setTimeout(function() {
                                        location.reload();
                                    }, 3000);
                                } else {
                                    $status.html('<span style="color: #dc3232;">❌ Error: ' + (response.data.message || 'Unknown error') + '</span>');
                                    $button.prop('disabled', false).text('🔄 Force Reprocess All Quarantine');
                                }
                            },
                            error: function(xhr, status, error) {
                                $status.html('<span style="color: #dc3232;">❌ Ajax error: ' + error + '</span>');
                                $button.prop('disabled', false).text('🔄 Force Reprocess All Quarantine');
                            }
                        });
                    });
                });
                </script>
            </div>
            
            <?php self::render_learning_insights(); ?>
            
			<?php
		}
        
        /**
         * Render moderation learning insights section
         */
        private static function render_learning_insights(): void {
            if (!class_exists('RTS_Moderation_Learning')) {
                return; // Silently skip if learning system not available
            }
            
            $stats = RTS_Moderation_Learning::get_stats();
            $weights = get_option('rts_pattern_weights', []);
            ?>
            
            <div class="rts-card" style="margin-top: 30px; max-width:800px;">
                <h3 class="rts-section-title">📚 Moderation Learning Insights</h3>
                
                <div class="rts-insight-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                    <div class="rts-insight-card" style="padding: 15px; background: #f8f9fa; border-radius: 4px;">
                        <div class="rts-insight-label" style="font-size: 12px; color: #666; margin-bottom: 5px;">Total Decisions Tracked</div>
                        <div class="rts-insight-value" style="font-size: 24px; font-weight: bold; color: #2271b1;"><?php echo number_format_i18n($stats['total_decisions']); ?></div>
                    </div>
                    
                    <div class="rts-insight-card" style="padding: 15px; background: #f8f9fa; border-radius: 4px;">
                        <div class="rts-insight-label" style="font-size: 12px; color: #666; margin-bottom: 5px;">Admin Override Rate</div>
                        <div class="rts-insight-value" style="font-size: 24px; font-weight: bold; color: <?php echo $stats['override_rate'] > 30 ? '#dc3232' : '#46b450'; ?>;"><?php echo esc_html($stats['override_rate']); ?>%</div>
                    </div>
                    
                    <div class="rts-insight-card" style="padding: 15px; background: #f8f9fa; border-radius: 4px;">
                        <div class="rts-insight-label" style="font-size: 12px; color: #666; margin-bottom: 5px;">System Accuracy</div>
                        <div class="rts-insight-value" style="font-size: 24px; font-weight: bold; color: <?php echo $stats['accuracy_trend']['rate'] >= 70 ? '#46b450' : '#f0b849'; ?>;"><?php echo esc_html($stats['accuracy_trend']['rate']); ?>%</div>
                        <div style="font-size: 11px; color: #666;"><?php echo $stats['accuracy_trend']['correct']; ?> / <?php echo $stats['accuracy_trend']['total']; ?> correct</div>
                    </div>
                </div>
                
                <?php if (!empty($stats['common_overrides'])): ?>
                <h4 style="margin: 20px 0 10px 0;">Most Frequently Overridden Flags</h4>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th>Flag</th>
                            <th>Override Rate</th>
                            <th>Confidence</th>
                            <th>Current Weight</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats['common_overrides'] as $flag => $rate): 
                            $confidence = $stats['confidence_levels'][$flag]['confidence'] ?? 0;
                            $data_points = $stats['confidence_levels'][$flag]['data_points'] ?? 0;
                            $weight = $weights[$flag]['weight'] ?? 'N/A';
                        ?>
                        <tr>
                            <td><code><?php echo esc_html($flag); ?></code></td>
                            <td>
                                <span style="color: <?php echo $rate > 50 ? '#dc3232' : ($rate > 30 ? '#f0b849' : '#46b450'); ?>; font-weight: 500;">
                                    <?php echo esc_html($rate); ?>%
                                </span>
                            </td>
                            <td>
                                <?php if ($confidence >= 50): ?>
                                    <span style="color: #46b450;">✓ High (<?php echo $data_points; ?> samples)</span>
                                <?php elseif ($confidence >= 20): ?>
                                    <span style="color: #f0b849;">~ Medium (<?php echo $data_points; ?> samples)</span>
                                <?php else: ?>
                                    <span style="color: #999;">⚠ Low (<?php echo $data_points; ?> samples)</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo is_numeric($weight) ? number_format($weight, 1) : $weight; ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <div class="rts-notice rts-notice-muted rts-notice-center">
                    <p>No learning data yet. System will learn from your moderation decisions.</p>
                </div>
                <?php endif; ?>
                
                <div class="rts-notice rts-notice-info">
                    <p class="rts-notice-title"><strong>How Learning Works:</strong></p>
                    <ul class="rts-notice-list">
                        <li>System tracks every manual override (when you publish a quarantined letter)</li>
                        <li>Flags with high override rates have their weights automatically reduced</li>
                        <li>After 20+ decisions, system may adjust the threshold if accuracy is low</li>
                        <li>Higher confidence = more data points = more reliable adjustments</li>
                    </ul>
                    <p class="rts-notice-footer">
                        <strong>Current threshold adjustment:</strong> 
                        <?php 
                            $accuracy = get_option('rts_severity_accuracy', []);
                            $adjustment = $accuracy['threshold_adjustment'] ?? 0;
                            if ($adjustment > 0) {
                                echo '<span style="color: #f0b849; font-weight: 500;">-' . $adjustment . ' points (being more lenient)</span>';
                            } elseif ($adjustment < 0) {
                                echo '<span style="color: #dc3232; font-weight: 500;">+' . abs($adjustment) . ' points (being stricter)</span>';
                            } else {
                                echo '<span style="color: #46b450; font-weight: 500;">No adjustment (good accuracy)</span>';
                            }
                        ?>
                    </p>
                </div>
            </div>
            
            <?php
        }

				private static function render_tab_system($import, $agg): void {
			$as_ok = rts_as_available();
			$upload_max = size_format(wp_max_upload_size());
			$import_status = is_array($import) ? ($import['status'] ?? 'idle') : 'idle';
			$import_total = is_array($import) ? (int) ($import['total'] ?? 0) : 0;
			$import_processed = is_array($import) ? (int) ($import['processed'] ?? 0) : 0;
			$import_errors = is_array($import) ? (int) ($import['errors'] ?? 0) : 0;
			?>
			<div class="rts-card">
				<h3 class="rts-section-title">System Health</h3>
				<table class="widefat striped">
					<tbody>
						<tr><td><strong>Action Scheduler</strong></td><td><?php echo $as_ok ? '<span class="color-success">Active</span>' : '<span class="color-danger">Missing</span>'; ?></td></tr>
						<tr><td><strong>Server Time (GMT)</strong></td><td><?php echo esc_html(gmdate('c')); ?></td></tr>
						<tr><td><strong>PHP Version</strong></td><td><?php echo esc_html(phpversion()); ?></td></tr>
						<tr><td><strong>Max Upload Size</strong></td><td><?php echo esc_html($upload_max); ?></td></tr>
					</tbody>
				</table>
			</div>

			<div class="rts-card" style="margin-top:18px;">
				<h3 class="rts-section-title">Bulk Import and Export</h3>

				<p style="margin: 0 0 10px;">
					Import supports <strong>CSV</strong>, <strong>NDJSON</strong> (one JSON object per line), or a <strong>JSON array</strong>.
					For Excel files (XLSX), export as CSV first.
				</p>

				<div class="notice notice-info" style="margin: 10px 0 14px;">
					<p style="margin:0;">
						<strong>Current import:</strong>
						<?php echo esc_html($import_status); ?>
						<?php if ($import_total > 0): ?>
							| <?php echo esc_html($import_processed); ?> / <?php echo esc_html($import_total); ?>
						<?php endif; ?>
						<?php if ($import_errors > 0): ?>
							| <?php echo esc_html($import_errors); ?> errors
						<?php endif; ?>
					</p>
				</div>

				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
					<?php wp_nonce_field('rts_import_letters'); ?>
					<input type="hidden" name="action" value="rts_import_letters" />
					<input type="file" name="rts_import_file" accept=".csv,.json,.ndjson" required />
					<button type="submit" class="button button-primary">Start Import</button>
					<span style="color:#646970;">Large files are processed in the background in batches.</span>
				</form>

				<hr style="margin:16px 0;" />

				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
					<?php wp_nonce_field('rts_export_letters'); ?>
					<input type="hidden" name="action" value="rts_export_letters" />
					<select name="status" aria-label="Export status">
						<option value="publish">Published only</option>
						<option value="pending">Pending only</option>
						<option value="any">All statuses</option>
					</select>
					<button type="submit" class="button">Download Export (JSON)</button>
				</form>

				<p style="margin: 12px 0 0; color:#646970;">
					CSV headers supported: <code>title</code>, <code>content</code> (or <code>letter</code>/<code>message</code>/<code>body</code>), optional <code>submission_ip</code>.
					NDJSON/JSON object keys supported: the same.
				</p>
			</div>

            <?php if (class_exists('RTS_Logger')): 
                $logs = RTS_Logger::get_recent(50);
                $stats = RTS_Logger::get_instance()->get_stats();
            ?>
            <div class="rts-card" style="margin-top:20px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h3 class="rts-section-title" style="margin:0;">System Logs</h3>
                    <div style="font-size:12px; color:#666;">
                        Log size: <?php echo esc_html($stats['size_formatted']); ?> | 
                        Entries: <?php echo intval($stats['lines']); ?>
                    </div>
                </div>
                
                <div class="rts-log-viewer" style="max-height:400px; overflow-y:auto; border:1px solid #ddd; background:#fff;">
                    <table class="widefat striped" style="border:none;">
                        <thead>
                            <tr>
                                <th style="width:140px;">Time</th>
                                <th style="width:80px;">Level</th>
                                <th>Message</th>
                                <th>Source</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($logs)): ?>
                                <tr><td colspan="4" style="padding:20px; text-align:center; color:#999;">No logs recorded yet.</td></tr>
                            <?php else: foreach ($logs as $log): ?>
                                <tr>
                                    <td style="color:#666; font-size:11px;"><?php echo esc_html($log['time']); ?></td>
                                    <td>
                                        <span class="rts-log-level rts-level-<?php echo esc_attr(strtolower($log['level'])); ?>">
                                            <?php echo esc_html(strtoupper($log['level'])); ?>
                                        </span>
                                    </td>
                                    <td style="font-family:monospace; font-size:12px;">
                                        <?php echo esc_html($log['message']); ?>
                                        <?php if (!empty($log['context'])): ?>
                                            <details style="margin-top:4px; cursor:pointer;">
                                                <summary style="font-size:10px; color:#2271b1;">View Context</summary>
                                                <pre style="background:#f0f0f1; padding:5px; margin:5px 0 0; font-size:10px; overflow-x:auto;"><?php echo esc_html(json_encode($log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                    <td style="font-size:11px; color:#666;"><?php echo esc_html($log['source']); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="margin-top:10px; text-align:right;">
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Are you sure you want to clear all logs?');">
                        <input type="hidden" name="action" value="rts_dashboard_action">
                        <input type="hidden" name="command" value="clear_logs">
                        <?php wp_nonce_field('rts_dashboard_action'); ?>
                        <button type="submit" class="button button-link-delete">Clear Logs</button>
                    </form>
                </div>
            </div>
            <style>
                .rts-log-level { display:inline-block; padding:2px 6px; border-radius:3px; font-size:10px; font-weight:600; text-transform:uppercase; }
                .rts-level-info { background:#e5f5fa; color:#0085ba; }
                .rts-level-warning { background:#fbf5e0; color:#d98500; }
                .rts-level-error { background:#fbeaea; color:#dc3232; }
                .rts-level-critical { background:#dc3232; color:#fff; }
            </style>
            <?php endif; ?>
			<?php
		}


		public static function handle_import_upload(): void {
			if (!current_user_can('manage_options')) { wp_die('Access denied'); }
			check_admin_referer('rts_import_letters');

			if (empty($_FILES['rts_import_file']) || !is_array($_FILES['rts_import_file'])) {
				wp_safe_redirect(add_query_arg('rts_msg', 'import_missing', self::url_for_tab('system'))); exit;
			}

			$file = $_FILES['rts_import_file'];
			if (!empty($file['error'])) {
				wp_safe_redirect(add_query_arg('rts_msg', 'import_upload_error', self::url_for_tab('system'))); exit;
			}

			$original_name = isset($file['name']) ? (string) $file['name'] : 'import';
			$ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
			if (!in_array($ext, ['csv','json','ndjson'], true)) {
				wp_safe_redirect(add_query_arg('rts_msg', 'import_bad_type', self::url_for_tab('system'))); exit;
			}

			$uploads = wp_upload_dir();
			$dir = trailingslashit($uploads['basedir']) . 'rts-imports';
			if (!wp_mkdir_p($dir)) {
				wp_safe_redirect(add_query_arg('rts_msg', 'import_mkdir_failed', self::url_for_tab('system'))); exit;
			}

			$filename = 'letters_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false) . '.' . $ext;
			$dest = trailingslashit($dir) . $filename;

			if (!@move_uploaded_file((string) $file['tmp_name'], $dest)) {
				wp_safe_redirect(add_query_arg('rts_msg', 'import_move_failed', self::url_for_tab('system'))); exit;
			}

			// Use streaming importer for large files (>10MB)
			$filesize = (int) @filesize($dest);
			if ($filesize > 10 * 1024 * 1024 && class_exists('RTS_Streaming_Importer')) {
				$res = RTS_Streaming_Importer::start_import($dest);
			} else {
				$res = RTS_Import_Orchestrator::start_import($dest);
			}


			if (empty($res['ok'])) {
				wp_safe_redirect(add_query_arg('rts_msg', 'import_failed', self::url_for_tab('system'))); exit;
			}

			wp_safe_redirect(add_query_arg('rts_msg', 'import_started', self::url_for_tab('system'))); exit;
		}

	public static function handle_export_download(): void {
		if (!current_user_can('manage_options')) { wp_die('Access denied'); }
		check_admin_referer('rts_export_letters');

		// Increase limits for large exports
		@ini_set('memory_limit', '512M');
		@set_time_limit(300); // 5 minutes

		// Clean all output buffers to prevent stuck exports
		while (ob_get_level()) {
			ob_end_clean();
		}

		$status = isset($_POST['status']) ? sanitize_key((string) $_POST['status']) : 'publish';
		$allowed = ['publish','pending','any'];
		if (!in_array($status, $allowed, true)) $status = 'publish';

		$args = [
			'post_type' => 'letter',
			'post_status' => ($status === 'any') ? ['publish','pending','draft','private','future'] : [$status],
			'posts_per_page' => -1,
			'orderby' => 'date',
			'order' => 'ASC',
			'fields' => 'ids',
			'no_found_rows' => true,
		];

		$ids = get_posts($args);
		$filename = 'rts_letters_' . $status . '_' . gmdate('Ymd_His') . '.json';

		// Send headers early
		nocache_headers();
		header('Content-Type: application/json; charset=' . get_option('blog_charset'));
		header('Content-Disposition: attachment; filename=' . $filename);
		header('X-Accel-Buffering: no'); // Disable proxy buffering

		// For large exports, stream the JSON instead of building in memory
		$total = count($ids);
		if ($total > 1000) {
			// Stream large exports
			echo "[\n";
			$count = 0;
			foreach ($ids as $id) {
				$id = (int) $id;
				$item = [
					'id' => $id,
					'title' => get_the_title($id),
					'content' => (string) get_post_field('post_content', $id),
					'status' => get_post_status($id),
					'date_gmt' => get_post_field('post_date_gmt', $id),
					'submission_ip' => (string) get_post_meta($id, 'rts_submission_ip', true),
				];

				if ($count > 0) echo ",\n";
				echo wp_json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
				$count++;

				// Flush every 100 items to prevent buffer overflow
				if ($count % 100 === 0) {
					flush();
					if (function_exists('wp_ob_end_flush_all')) {
						wp_ob_end_flush_all();
					}
				}
			}
			echo "\n]";
		} else {
			// Small exports - build array and encode normally
			$out = [];
			foreach ($ids as $id) {
				$id = (int) $id;
				$out[] = [
					'id' => $id,
					'title' => get_the_title($id),
					'content' => (string) get_post_field('post_content', $id),
					'status' => get_post_status($id),
					'date_gmt' => get_post_field('post_date_gmt', $id),
					'submission_ip' => (string) get_post_meta($id, 'rts_submission_ip', true),
				];
			}
			echo wp_json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		}

		flush();
		exit;
	}
		public static function handle_post_action(): void {
			if (!current_user_can('manage_options')) wp_die('Access denied');
			check_admin_referer('rts_dashboard_action');

			$cmd = isset($_POST['command']) ? sanitize_key((string) $_POST['command']) : '';
			$post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
			$redirect = self::url_for_tab('overview');
			if (!empty($_SERVER['HTTP_REFERER'])) $redirect = esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']));

			switch ($cmd) {
				case 'run_analytics':
					if (rts_as_available()) as_schedule_single_action(time() + 5, 'rts_aggregate_analytics', [], 'rts');
					$redirect = add_query_arg('rts_msg', 'analytics', self::url_for_tab('analytics'));
					break;
				case 'rescan_pending_review':
					self::queue_rescan_pending_and_review();
					$redirect = add_query_arg('rts_msg', 'rescanned', self::url_for_tab('letters'));
					break;
				case 'rescan_letter':
					if ($post_id) self::queue_letter_scan($post_id);
					$redirect = add_query_arg('rts_msg', 'rescanned', self::url_for_tab('letters'));
					break;
				case 'publish_letter':
					if ($post_id) { wp_update_post(['ID' => $post_id, 'post_status' => 'publish']); delete_post_meta($post_id, 'needs_review'); }
					$redirect = add_query_arg('rts_msg', 'updated', self::url_for_tab('letters'));
					break;
				case 'mark_reviewed':
					if ($post_id) delete_post_meta($post_id, 'needs_review');
					$redirect = add_query_arg('rts_msg', 'updated', self::url_for_tab('letters'));
					break;
                case 'clear_logs':
                    if (class_exists('RTS_Logger')) {
                        RTS_Logger::get_instance()->clear_logs();
                    }
                    $redirect = add_query_arg('rts_msg', 'logs_cleared', self::url_for_tab('system'));
                    break;
			}
			wp_safe_redirect($redirect); exit;
		}
        
        // AJAX Handler: Start Scan

        public static function ajax_start_scan() {
            // Security checks
            if (!current_user_can('manage_options')) {
                wp_send_json_error(['message' => 'Permission denied']);
            }
		// NOTE: Keep this nonce action aligned with the dashboard JS payload.
		// Other dashboard actions already use 'rts_dashboard_nonce'.
		check_ajax_referer('rts_dashboard_nonce', 'nonce');

            $scan_type = isset($_POST['scan_type']) ? sanitize_text_field($_POST['scan_type']) : 'inbox';
            if (!in_array($scan_type, ['inbox', 'quarantine'], true)) {
                $scan_type = 'inbox';
            }

            // Mark scan state
            update_option('rts_active_scan', [
                'type'       => $scan_type,
                'started_at' => time(),
                'status'     => 'running'
            ]);

            // Clear diagnostic log for a fresh run
            delete_option('rts_diag_log');

            // Always reset throttling so the pump starts immediately
            update_option('rts_scan_queued_ts', time());

            $queued = 0;

            // Queue items based on requested scan type.
            if ($scan_type === 'inbox') {
                // Pending + needs_review (inbox + quarantine)
                $queued = (int) self::queue_rescan_pending_and_review();
            } else {
                // Quarantine-only rescan: rts-quarantine status + needs_review = 1
                $batch = (int) get_option(self::OPTION_AUTO_BATCH, 100);

                $q = new \WP_Query([
                    'post_type'      => 'letter',
                    'post_status'    => 'draft', // FIXED: Only query rts-quarantine, not pending
                    'posts_per_page' => $batch,
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'orderby'        => 'date',
                    'order'          => 'ASC',
                    'meta_query'     => [
                        [
                            'key'     => 'needs_review',
                            'value'   => '1',
                            'compare' => '='
                        ]
                    ],
                ]);

                if (!empty($q->posts)) {
                    foreach ($q->posts as $pid) {
                        self::queue_letter_scan((int) $pid);
                        $queued++;
                    }
                }
                wp_reset_postdata();
            }

            // Kick the pump (Action Scheduler)
            self::schedule_scan_pump(true);

            wp_send_json_success([
                'message'   => ($scan_type === 'quarantine')
                    ? 'Quarantine rescan queued and started.'
                    : 'Inbox scan queued and started.',
                'scan_type' => $scan_type,
                'queued'    => $queued
            ]);
        }

        // AJAX Handler: Process Single Letter
        public static function ajax_process_single(): void {
            check_ajax_referer('rts_dashboard_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_die('Unauthorized', 403);
            }
            
            $post_id = absint($_POST['post_id'] ?? 0);
            
            if (!$post_id || get_post_type($post_id) !== 'letter') {
                wp_send_json_error('Invalid letter ID');
            }
            
            // Queue the letter for processing
            self::queue_letter_scan($post_id);
            
            wp_send_json_success('Letter queued for processing');
        }

	        /**
	         * Admin override: clear quarantine flag and re-process.
	         * Used when a quarantined letter is genuinely fine.
	         */
	        public static function ajax_approve_letter(): void {
	            check_ajax_referer('rts_dashboard_nonce', 'nonce');
	            if (!current_user_can('manage_options')) {
	                wp_die('Unauthorized', 403);
	            }

	            $post_id = absint($_POST['post_id'] ?? 0);
	            if (!$post_id || get_post_type($post_id) !== 'letter') {
	                wp_send_json_error('Invalid letter ID');
	            }

	            // Mark as admin-approved, clear quarantine flag, and queue for processing
	            update_post_meta($post_id, 'rts_admin_override', '1');
	            delete_post_meta($post_id, 'needs_review');
	            // Keep a breadcrumb for auditing
	            update_post_meta($post_id, 'rts_admin_override_gmt', gmdate('c'));
	            RTS_Scan_Diagnostics::log('admin_override', ['post_id' => $post_id]);

	            self::queue_letter_scan($post_id);
	            wp_send_json_success('Approved and queued for processing');
	        }

	        /**
	         * Bulk override: approve multiple letters at once.
	         */
	        public static function ajax_bulk_approve(): void {
	            check_ajax_referer('rts_dashboard_nonce', 'nonce');
	            if (!current_user_can('manage_options')) {
	                wp_die('Unauthorized', 403);
	            }

	            $ids = $_POST['post_ids'] ?? [];
	            if (!is_array($ids) || empty($ids)) {
	                wp_send_json_error('No letters selected');
	            }

	            $approved = 0;
	            foreach ($ids as $raw_id) {
	                $post_id = absint($raw_id);
	                if (!$post_id || get_post_type($post_id) !== 'letter') {
	                    continue;
	                }
	                update_post_meta($post_id, 'rts_admin_override', '1');
	                delete_post_meta($post_id, 'needs_review');
	                update_post_meta($post_id, 'rts_admin_override_gmt', gmdate('c'));
	                self::queue_letter_scan($post_id);
	                $approved++;
	            }

	            RTS_Scan_Diagnostics::log('admin_override_bulk', ['count' => $approved]);
	            wp_send_json_success(['approved' => $approved]);
	        }

			/**
			 * Bulk soft-delete letters (trash + hide + audit meta).
			 */
			public static function ajax_bulk_soft_delete(): void {
				check_ajax_referer('rts_dashboard_nonce', 'nonce');
				if (!current_user_can('manage_options')) {
					wp_die('Unauthorized', 403);
				}

				$ids = $_POST['post_ids'] ?? [];
				$reason = sanitize_text_field($_POST['reason'] ?? 'bulk_action');
				if (!is_array($ids) || empty($ids)) {
					wp_send_json_error('No letters selected');
				}

				$deleted = 0;
				foreach ($ids as $raw_id) {
					$post_id = absint($raw_id);
					if (!$post_id || get_post_type($post_id) !== 'letter') {
						continue;
					}
					if (RTS_Moderation_Engine::soft_delete_letter($post_id, $reason)) {
						$deleted++;
					}
				}

				RTS_Scan_Diagnostics::log('soft_delete_bulk', ['count' => $deleted, 'reason' => $reason]);
				wp_send_json_success(['deleted' => $deleted]);
			}

			/**
			 * Restore a single soft-deleted letter.
			 */
			public static function ajax_restore_letter(): void {
				check_ajax_referer('rts_dashboard_nonce', 'nonce');
				if (!current_user_can('manage_options')) {
					wp_die('Unauthorized', 403);
				}
				$post_id = absint($_POST['post_id'] ?? 0);
				if (!$post_id || get_post_type($post_id) !== 'letter') {
					wp_send_json_error('Invalid letter');
				}
				$ok = RTS_Moderation_Engine::restore_letter($post_id);
				if (!$ok) {
					wp_send_json_error('Restore failed');
				}
				RTS_Scan_Diagnostics::log('soft_delete_restore', ['post_id' => $post_id]);
				wp_send_json_success(['restored' => 1]);
			}

	        /**
	         * Cancel/clear stuck import
	         */
	        public static function ajax_cancel_import(): void {
	            check_ajax_referer('rts_dashboard_nonce', 'nonce');
	            if (!current_user_can('manage_options')) {
	                wp_die('Unauthorized', 403);
	            }

	            // Clear import job status
	            delete_option('rts_import_job_status');

	            // Cancel all pending import batch jobs (try multiple hook names)
	            if (function_exists('as_unschedule_all_actions')) {
	                as_unschedule_all_actions('rts_import_batch', [], 'rts');
	                as_unschedule_all_actions('rts_batch_import', [], 'rts');
	                as_unschedule_all_actions('rts_process_letter_batch', [], 'rts');
	            }

	            // Clear transients
	            delete_transient('rts_import_in_progress');
	            delete_transient('rts_import_offset');
	            delete_transient('rts_import_total');

	            // Clear uploaded file path if exists
	            delete_option('rts_import_file_path');

	            // Clear any stuck Action Scheduler claims and reset stuck actions
	            global $wpdb;
	            $wpdb->query("DELETE FROM {$wpdb->prefix}actionscheduler_claims WHERE action_id IN (SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE 'rts_%' AND status IN ('in-progress', 'pending'))");
	            $wpdb->query("UPDATE {$wpdb->prefix}actionscheduler_actions SET status = 'failed' WHERE hook LIKE 'rts_%' AND status = 'in-progress'");

	            RTS_Scan_Diagnostics::log('import_canceled', ['user' => wp_get_current_user()->user_login]);
	            wp_send_json_success('Import canceled and queue cleared. You can now start a fresh import.');
	        }

	        /**
	         * Clear queue timestamps for all quarantined letters.
	         * This allows them to be picked up by the automated pump.
	         * * @return int Number of quarantined letters with timestamps cleared
	         */
	        public static function clear_quarantine_timestamps(): int {
	            global $wpdb;
	            
	            // Find all quarantined letters (draft status with needs_review flag)
	            $quarantined_ids = $wpdb->get_col($wpdb->prepare(
	                "SELECT DISTINCT p.ID 
	                 FROM {$wpdb->posts} p
	                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
	                 WHERE p.post_type = %s
	                   AND p.post_status = %s
	                   AND pm.meta_key = %s
	                   AND pm.meta_value = %s",
	                'letter',
	                'draft',
	                'needs_review',
	                '1'
	            ));
	            
	            // Clear their queue timestamps so they can be reprocessed
	            foreach ($quarantined_ids as $id) {
	                delete_post_meta($id, 'rts_scan_queued_ts');
	                delete_post_meta($id, 'rts_scan_queued_gmt');
	            }
	            
	            return count($quarantined_ids);
	        }

	        /**
	         * AJAX handler to force reprocess all quarantined letters.
	         * Clears timestamps and queues them for processing.
	         */
	        public static function ajax_force_reprocess_quarantine(): void {
	            check_ajax_referer('rts_dashboard_nonce', 'nonce');
	            if (!current_user_can('manage_options')) {
	                wp_die('Unauthorized', 403);
	            }
	            
	            // Clear timestamps
	            $count = self::clear_quarantine_timestamps();
	            
	            // Get all quarantined letters
	            global $wpdb;
	            $quarantined_ids = $wpdb->get_col($wpdb->prepare(
	                "SELECT DISTINCT p.ID 
	                 FROM {$wpdb->posts} p
	                 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
	                 WHERE p.post_type = %s
	                   AND p.post_status = %s
	                   AND pm.meta_key = %s
	                   AND pm.meta_value = %s",
	                'letter',
	                'draft',
	                'needs_review',
	                '1'
	            ));
	            
	            // Queue them all for processing
	            foreach ($quarantined_ids as $id) {
	                self::queue_letter_scan((int)$id);
	            }
	            
	            RTS_Scan_Diagnostics::log('force_reprocess_quarantine', ['count' => $count]);
	            
	            wp_send_json_success([
	                'message' => "Force-reprocessed {$count} quarantined letters with updated moderation rules",
	                'count' => $count
	            ]);
	        }

		        /**
		         * Return the diagnostics log tail for the admin dashboard.
		         * JS polls this endpoint; keep the response JSON and stable.
		         */
		        public static function ajax_diag_log_tail(): void {
		            check_ajax_referer('rts_dashboard_nonce', 'nonce');
		            if (!current_user_can('manage_options')) {
		                wp_die('Unauthorized', 403);
		            }

		            $tail = RTS_Scan_Diagnostics::get_log();
		            if (!is_array($tail)) {
		                $tail = [];
		            }

		            wp_send_json_success(['tail' => array_values($tail)]);
		        }

		        /**
		         * Clear the diagnostics log.
		         */
		        public static function ajax_diag_reset(): void {
		            check_ajax_referer('rts_dashboard_nonce', 'nonce');
		            if (!current_user_can('manage_options')) {
		                wp_die('Unauthorized', 403);
		            }
		
		            RTS_Scan_Diagnostics::reset();
		            wp_send_json_success(['reset' => true]);
		        }

		
        public static function queue_letter_scan(int $post_id): void {
            if (get_post_type($post_id) !== 'letter') return;
            // Mark this letter as recently queued so the pump doesn't re-enqueue it immediately
            update_post_meta($post_id, 'rts_scan_queued_ts', time());
                // CRITICAL FIX #2: Removed duplicate queue check to prevent zombie queue
                // Old code prevented re-queueing letters that were already scheduled
                // but stuck/failed, causing the "zombie queue" where letters showed
                // as "processing" but never actually processed. Now we allow re-queueing.
                // DISABLED: if (function_exists('as_has_scheduled_action') && as_has_scheduled_action('rts_process_letter', [$post_id], 'rts')) {
                //      RTS_Scan_Diagnostics::log('enqueue_skip_already_scheduled', ['post_id' => $post_id]);
                //      return;
                // }
            if (rts_as_available()) {
                as_schedule_single_action(time() + 2, 'rts_process_letter', [$post_id], 'rts');
                RTS_Scan_Diagnostics::log('enqueue_letter', ['post_id' => $post_id, 'via' => 'action_scheduler']);
                return;
            }

            if (!wp_next_scheduled('rts_wpcron_process_letter', [$post_id])) {
                wp_schedule_single_event(time() + 10, 'rts_wpcron_process_letter', [$post_id]);
                RTS_Scan_Diagnostics::log('enqueue_letter', ['post_id' => $post_id, 'via' => 'wp_cron']);
            }
        }

		private static function queue_rescan_pending_and_review(): int {
            $batch = (int) get_option(self::OPTION_AUTO_BATCH, 100);

            $q_args = [
                'post_type'      => 'letter',
                'post_status' => ['pending','draft'],
                'posts_per_page' => $batch,
                'fields'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'ASC',
            ];

            $pending = new \WP_Query($q_args);
            $ids = array_map('intval', (array) $pending->posts);

            $needs = new \WP_Query(array_merge($q_args, [
                'meta_query'  => [[ 'key' => 'needs_review', 'value' => '1' ]],
            ]));
            $ids = array_values(array_unique(array_merge($ids, array_map('intval', (array) $needs->posts))));

            RTS_Scan_Diagnostics::log('scan_enqueue_batch', [
                'batch'             => $batch,
                'pending_found'      => (int) $pending->found_posts,
                'needs_review_found' => (int) $needs->found_posts,
                'enqueued'           => count($ids),
            ]);

            foreach ($ids as $id) self::queue_letter_scan((int) $id);

            self::schedule_scan_pump();
        }
        
        private static function schedule_scan_pump(bool $force = false): void {
            if (!rts_as_available()) return;
            if (function_exists('as_has_scheduled_action') && as_has_scheduled_action('rts_scan_pump', [], 'rts') && !$force) return;

            as_schedule_single_action(time() + 15, 'rts_scan_pump', [], 'rts');
            RTS_Scan_Diagnostics::log('pump_scheduled', ['run_in_sec' => 15]);
        }
        
        public static function handle_scan_pump(): void {
            $batch = (int) get_option(self::OPTION_AUTO_BATCH, 100);
            $threshold = time() - 120;

            $q_args = [
                'post_type'      => 'letter',
                'post_status'    => ['pending', 'draft'],  // CRITICAL FIX: Include quarantined letters (draft status)
                'posts_per_page' => $batch,
                'fields'         => 'ids',
                'orderby'        => 'date',
                'order'          => 'ASC',
                'meta_query'     => [
                    'relation' => 'OR',
                    [ 'key' => 'rts_scan_queued_ts', 'compare' => 'NOT EXISTS' ],
                    [ 'key' => 'rts_scan_queued_ts', 'value' => $threshold, 'compare' => '<', 'type' => 'NUMERIC' ],
                ],
            ];

            $pending = new \WP_Query($q_args);
            $ids = array_map('intval', (array) $pending->posts);

            $needs = new \WP_Query(array_merge($q_args, [
                'meta_query' => [
                    'relation' => 'AND',
                    [
                        'relation' => 'OR',
                        [ 'key' => 'rts_scan_queued_ts', 'compare' => 'NOT EXISTS' ],
                        [ 'key' => 'rts_scan_queued_ts', 'value' => $threshold, 'compare' => '<', 'type' => 'NUMERIC' ],
                    ],
                    [ 'key' => 'needs_review', 'value' => '1' ],
                ],
            ]));
            $ids = array_values(array_unique(array_merge($ids, array_map('intval', (array) $needs->posts))));

            RTS_Scan_Diagnostics::set_state([
                'pump_last_run_gmt'   => gmdate('c'),
                'pump_batch'          => $batch,
                'pump_candidates'     => count($ids),
                'pending_found'       => (int) $pending->found_posts,
                'needs_review_found'  => (int) $needs->found_posts,
            ]);

            if (empty($ids)) {
                RTS_Scan_Diagnostics::log('pump_idle', ['threshold_ts' => $threshold]);
                return;
            }

            RTS_Scan_Diagnostics::log('pump_enqueue', ['count' => count($ids)]);
            foreach ($ids as $id) self::queue_letter_scan((int) $id);

            self::schedule_scan_pump();
        }

		public static function register_rest(): void {
			register_rest_route('rts/v1', '/processing-status', [
				'methods' => 'GET', 'permission_callback' => function() { return current_user_can('manage_options'); },
				'callback' => [__CLASS__, 'rest_processing_status'],
			]);

				// Public: fetch a random published letter (used by the frontend "Show me another" button)
				// The frontend may POST JSON (body) or GET with query args depending on host/WAF.
				register_rest_route('rts/v1', '/letter/next', [
					'methods' => [ 'GET', 'POST' ],
					'permission_callback' => '__return_true',
					'callback' => [__CLASS__, 'rest_get_next_letter'],
					'args' => [
						'exclude' => [
							'description' => 'Array of letter IDs to avoid repeating',
							'required' => false,
							'sanitize_callback' => function($value){
								if (is_string($value)) {
									// support comma separated
									$value = array_filter(array_map('trim', explode(',', $value)));
								}
								return array_values(array_filter(array_map('intval', (array) $value)));
							},
						],
					],
				]);

// Public analytics: lightweight counters for views/ratings/shares.
// These endpoints are intentionally privacy-minimal: they store only aggregated counts on the letter postmeta.
register_rest_route('rts/v1', '/track/view', [
    'methods' => [ 'POST' ],
    'permission_callback' => '__return_true',
    'callback' => [__CLASS__, 'rest_track_view'],
]);

register_rest_route('rts/v1', '/track/helpful', [
    'methods' => [ 'POST' ],
    'permission_callback' => '__return_true',
    'callback' => [__CLASS__, 'rest_track_helpful'],
]);

register_rest_route('rts/v1', '/track/rate', [
    'methods' => [ 'POST' ],
    'permission_callback' => '__return_true',
    'callback' => [__CLASS__, 'rest_track_rate'],
]);

register_rest_route('rts/v1', '/track/share', [
    'methods' => [ 'POST' ],
    'permission_callback' => '__return_true',
    'callback' => [__CLASS__, 'rest_track_share'],
]);
		}

		public static function rest_processing_status(\WP_REST_Request $req): \WP_REST_Response {
			try {
				$import = get_option('rts_import_job_status', []);
				return new \WP_REST_Response([
					'ok'    => true,
					'ts'    => current_time('mysql'),
					'state' => RTS_Scan_Diagnostics::get_state(),
					'queue' => [
						'pending_letter_jobs'    => self::count_scheduled('rts_process_letter'),
						'pending_import_batches' => self::count_scheduled('rts_process_import_batch'),
						'pending_analytics'      => self::count_scheduled('rts_aggregate_analytics'),
					],
					'import' => $import,
					'diag' => [
						'active_scan' => RTS_Scan_Diagnostics::get_state(),
						'log' => RTS_Scan_Diagnostics::get_log(),
					],
				], 200);
			} catch (\Throwable $e) {
				RTS_Scan_Diagnostics::log('rest_processing_status_error', ['msg' => $e->getMessage()]);
				return new \WP_REST_Response([
					'ok' => false,
					'error' => 'processing-status failed',
					'ts_gmt' => gmdate('c'),
				], 200);
			}
		}

			/**
			 * Public endpoint used by the frontend letter reader.
			 * Returns a single random published letter (HTML content) and basic meta.
			 */
			public static function rest_get_next_letter(\WP_REST_Request $req): \WP_REST_Response {
				try {
					// Support both legacy `exclude` and current `viewed` arrays.
					$exclude = $req->get_param('exclude');
					if ($exclude === null) {
						$exclude = $req->get_param('viewed');
					}
					if (!is_array($exclude)) {
						// accept comma-separated string
						if (is_string($exclude) && strlen($exclude) > 0) {
							$exclude = array_filter(array_map('intval', explode(',', $exclude)));
						} else {
							$exclude = [];
						}
					}

					$data = self::get_next_letter_payload($exclude);
					if (!$data) {
						return new \WP_REST_Response([
							'ok' => true,
							'letter' => null,
						], 200);
					}

					return new \WP_REST_Response([
						'ok' => true,
						'letter' => $data,
					], 200);
				} catch (\Throwable $e) {
					RTS_Scan_Diagnostics::log('rest_get_next_letter_error', ['msg' => $e->getMessage()]);
					return new \WP_REST_Response([
						'ok' => false,
						'letter' => null,
					], 200);
				}
			}

			/**
			 * admin-ajax fallback for the frontend.
			 */
			/**
 * Increment a numeric meta counter safely.
 */
private static function bump_counter(int $post_id, string $meta_key, int $by = 1): void {
    if ($post_id <= 0 || $by <= 0) return;
    $current = (int) get_post_meta($post_id, $meta_key, true);
    $current = max(0, $current);
    update_post_meta($post_id, $meta_key, $current + $by);
}

/**
 * Minimal abuse guard: throttle by IP hash + action.
 */
private static function analytics_throttle(string $action, int $post_id): bool {
    $ip = '';
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $ip = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
    elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) $ip = (string) explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
    elseif (!empty($_SERVER['REMOTE_ADDR'])) $ip = (string) $_SERVER['REMOTE_ADDR'];
    $ip = trim($ip);
    if ($ip === '') return true;

    $ip_hash = substr(sha1($ip), 0, 16);
    $key = 'rts_track_' . $action . '_' . $ip_hash . '_' . (int)$post_id;
    if (get_transient($key)) return false;
    // set_transient can fail (db/object-cache hiccups); fail-open for analytics.
    set_transient($key, 1, 30); // 30s per IP per letter per action
    return true;
}

public static function rest_track_view(\WP_REST_Request $req): \WP_REST_Response {
    $letter_id = (int) ($req->get_param('letter_id') ?? 0);
    $view_nonce = trim((string) ($req->get_param('view_nonce') ?? ''));
    if ($letter_id <= 0 || get_post_type($letter_id) !== 'letter') {
        return new \WP_REST_Response(['ok' => false], 200);
    }

	// Strict de-duplication: if the frontend provides a per-page-load nonce,
	// only count that nonce once for this letter. This prevents accidental
	// double-counting due to duplicate script execution / double event binding.
	if ($view_nonce !== '') {
		$nonce_key = 'rts_view_nonce_' . md5($view_nonce . '|' . $letter_id);
		if (get_transient($nonce_key)) {
			return new \WP_REST_Response(['ok' => true], 200);
		}
		set_transient($nonce_key, 1, DAY_IN_SECONDS);
	}

	    
    if (!self::analytics_throttle('view', $letter_id)) {
        return new \WP_REST_Response(['ok' => true, 'throttled' => true], 200);
    }
    // Canonical metric (used by [rts_site_stats_row]).
    self::bump_counter($letter_id, 'rts_views', 1);

    // Back-compat: older builds summed 'view_count'. Keep it in sync.
    self::bump_counter($letter_id, 'view_count', 1);

    // Make the stats row feel "live".
    delete_transient('rts_site_stats_v1');
    return new \WP_REST_Response(['ok' => true], 200);
}

public static function rest_track_helpful(\WP_REST_Request $req): \WP_REST_Response {
    $letter_id = (int) ($req->get_param('letter_id') ?? 0);
    if ($letter_id <= 0 || get_post_type($letter_id) !== 'letter') {
        return new \WP_REST_Response(['ok' => false], 200);
    }
    if (!self::analytics_throttle('helpful', $letter_id)) {
        return new \WP_REST_Response(['ok' => true, 'throttled' => true], 200);
    }
    self::bump_counter($letter_id, 'rts_helpful', 1);
    return new \WP_REST_Response(['ok' => true], 200);
}

public static function rest_track_rate(\WP_REST_Request $req): \WP_REST_Response {
    $letter_id = (int) ($req->get_param('letter_id') ?? 0);
    $value = (string) ($req->get_param('value') ?? '');
    if ($letter_id <= 0 || get_post_type($letter_id) !== 'letter') {
        return new \WP_REST_Response(['ok' => false], 200);
    }
    if (!self::analytics_throttle('rate', $letter_id)) {
        return new \WP_REST_Response(['ok' => true, 'throttled' => true], 200);
    }
    if ($value === 'down' || $value === '-1' || $value === 'unhelpful') {
        self::bump_counter($letter_id, 'rts_unhelpful', 1);
    } else {
        self::bump_counter($letter_id, 'rts_helpful', 1);
    }
    return new \WP_REST_Response(['ok' => true], 200);
}

public static function rest_track_share(\WP_REST_Request $req): \WP_REST_Response {
    $letter_id = (int) ($req->get_param('letter_id') ?? 0);
    $platform = sanitize_text_field((string) ($req->get_param('platform') ?? ''));
    if ($letter_id <= 0 || get_post_type($letter_id) !== 'letter') {
        return new \WP_REST_Response(['ok' => false], 200);
    }
    if (!self::analytics_throttle('share', $letter_id)) {
        return new \WP_REST_Response(['ok' => true, 'throttled' => true], 200);
    }
    self::bump_counter($letter_id, 'rts_shares', 1);
    if ($platform) {
        $plat = preg_replace('/[^a-z0-9_\-]/i', '', strtolower($platform));
        self::bump_counter($letter_id, 'rts_share_' . $plat, 1);
    }
    return new \WP_REST_Response(['ok' => true], 200);
}

public static function ajax_get_next_letter(): void {
				// Nonce is optional here because some caches / aggressive security setups strip headers.
				// IMPORTANT: The frontend posts a JSON string in `payload`, so we must decode it.
				$payload = [];
				if (isset($_POST['payload']) && is_string($_POST['payload']) && $_POST['payload'] !== '') {
					$decoded = json_decode(wp_unslash($_POST['payload']), true);
					if (is_array($decoded)) $payload = $decoded;
				}

				$exclude = $payload['exclude'] ?? ($payload['viewed'] ?? ($_POST['exclude'] ?? ($_POST['viewed'] ?? [])));
				if (!is_array($exclude)) {
					$exclude = is_string($exclude) ? array_filter(array_map('intval', explode(',', (string) $exclude))) : [];
				}

				try {
					$data = self::get_next_letter_payload($exclude);
					// Frontend expects a flat shape: { success: true, letter: {...} }
					wp_send_json([
						'success' => true,
						'letter'   => $data,
					], 200);
				} catch (\Throwable $e) {
					RTS_Scan_Diagnostics::log('ajax_get_next_letter_error', ['msg' => $e->getMessage()]);
					wp_send_json([
						'success' => false,
						'letter'   => null,
						'message'  => 'No letter available right now. Please refresh the page in a moment.',
					], 200);
				}
			}

			/**
			 * Efficient random selection of a published letter with optional exclusions.
			 */
				public static function ajax_track_view(): void {
    $payload = [];
    if (isset($_POST['payload']) && is_string($_POST['payload']) && $_POST['payload'] !== '') {
        $decoded = json_decode(wp_unslash($_POST['payload']), true);
        if (is_array($decoded)) $payload = $decoded;
    }
    $letter_id = isset($payload['letter_id']) ? absint($payload['letter_id']) : 0;
    if (!$letter_id || get_post_type($letter_id) !== 'letter') {
        wp_send_json_success(['ok' => true]);
    }
    if (!self::analytics_throttle('view', $letter_id)) {
        wp_send_json_success(['ok' => true, 'throttled' => true]);
    }
    self::bump_counter($letter_id, 'rts_views', 1);
    wp_send_json_success(['ok' => true]);
}

public static function ajax_track_helpful(): void {
    $payload = [];
    if (isset($_POST['payload']) && is_string($_POST['payload']) && $_POST['payload'] !== '') {
        $decoded = json_decode(wp_unslash($_POST['payload']), true);
        if (is_array($decoded)) $payload = $decoded;
    }
    $letter_id = isset($payload['letter_id']) ? absint($payload['letter_id']) : 0;
    if (!$letter_id || get_post_type($letter_id) !== 'letter') {
        wp_send_json_success(['ok' => true]);
    }
    if (!self::analytics_throttle('helpful', $letter_id)) {
        wp_send_json_success(['ok' => true, 'throttled' => true]);
    }
    self::bump_counter($letter_id, 'rts_helpful', 1);
    wp_send_json_success(['ok' => true]);
}

public static function ajax_track_rate(): void {
    $payload = [];
    if (isset($_POST['payload']) && is_string($_POST['payload']) && $_POST['payload'] !== '') {
        $decoded = json_decode(wp_unslash($_POST['payload']), true);
        if (is_array($decoded)) $payload = $decoded;
    }
    $letter_id = isset($payload['letter_id']) ? absint($payload['letter_id']) : 0;
    $value = isset($payload['value']) ? (string)$payload['value'] : '';
    if (!$letter_id || get_post_type($letter_id) !== 'letter') {
        wp_send_json_success(['ok' => true]);
    }
    if (!self::analytics_throttle('rate', $letter_id)) {
        wp_send_json_success(['ok' => true, 'throttled' => true]);
    }
    if ($value === 'down' || $value === '-1' || $value === 'unhelpful') {
        self::bump_counter($letter_id, 'rts_unhelpful', 1);
    } else {
        self::bump_counter($letter_id, 'rts_helpful', 1);
    }
    wp_send_json_success(['ok' => true]);
}

private static function get_next_letter_payload(array $exclude_ids = []): ?array {
					// Use WP_Query so this stays compatible with WP's table prefixing, caching,
					// and any future meta/status rules we add.
					$exclude_ids = array_values(array_unique(array_filter(array_map('absint', $exclude_ids))));

					// Exclude anything still in quarantine and anything explicitly marked spam.
					$meta_query = [
						'relation' => 'AND',
						[
							'relation' => 'OR',
							['key' => 'needs_review', 'compare' => 'NOT EXISTS'],
							['key' => 'needs_review', 'value' => '1', 'compare' => '!='],
						],
						[
							'relation' => 'OR',
							['key' => 'rts_spam', 'compare' => 'NOT EXISTS'],
							['key' => 'rts_spam', 'value' => '1', 'compare' => '!='],
						],
					];

					$q = new \WP_Query([
						'post_type'              => 'letter',
						'post_status'            => 'publish',
						'posts_per_page'         => 1,
						'orderby'                => 'rand',
						'post__not_in'           => $exclude_ids,
						'no_found_rows'          => true,
						'ignore_sticky_posts'    => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
						'fields'                 => 'ids',
						'meta_query'             => $meta_query,
					]);

					$post_id = (!empty($q->posts) && is_array($q->posts)) ? (int) $q->posts[0] : 0;
					if ($post_id <= 0) return null;

					$post = get_post($post_id);
					if (!$post || $post->post_status !== 'publish') return null;

					return [
						'id'      => (int) $post->ID,
						'title'   => html_entity_decode(get_the_title($post), ENT_QUOTES, 'UTF-8'),
						'content' => apply_filters('the_content', $post->post_content),
					];
				}
        
		private static function count_scheduled(string $hook): int {
			if (!function_exists('rts_as_available') || !rts_as_available()) return 0;
			if (!function_exists('as_get_scheduled_actions')) return 0;
			if (!class_exists('ActionScheduler_Store')) return 0;
			try {
				$ids = as_get_scheduled_actions([
					'hook' => $hook,
					'status' => ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1000,
					'offset' => 0,
				], 'ids');
				if (function_exists('is_wp_error') && is_wp_error($ids)) return 0;
				if (is_array($ids)) return count($ids);
				return (int) $ids;
			} catch (\Throwable $e) {
				RTS_Scan_Diagnostics::log('count_scheduled_error', ['hook' => $hook, 'msg' => $e->getMessage()]);
				return 0;
			}
		}


	}
}

/* =========================================================
   RTS_Cron_Processing (Compatibility Shim)
   ========================================================= */
if (!class_exists('RTS_Cron_Processing')) {
	class RTS_Cron_Processing {
		const GROUP = 'rts';
		public static function process_letters_batch(string $mode = 'unrated', int $limit = 50, string $source = 'manual'): array {
			if (!rts_as_available()) return ['ok' => false, 'error' => 'action_scheduler_missing'];
            // Logic handled by engine queueing, this is a shim
			return ['ok' => true, 'mode' => $mode, 'found' => 0, 'scheduled' => 0];
		}
	}
}

/* =========================================================
   RTS_Engine_Settings (Helper Class)
   ========================================================= */
if (!class_exists('RTS_Engine_Settings')) {
    class RTS_Engine_Settings {
        public const OPTION_ENABLE_AUTO_PROCESSING     = RTS_Engine_Dashboard::OPTION_AUTO_ENABLED;
        public const OPTION_AUTO_PROCESSING_BATCH_SIZE = RTS_Engine_Dashboard::OPTION_AUTO_BATCH;
        public const OPTION_MIN_QUALITY_SCORE          = RTS_Engine_Dashboard::OPTION_MIN_QUALITY;
        public const OPTION_IP_DAILY_THRESHOLD         = RTS_Engine_Dashboard::OPTION_IP_THRESHOLD;
        public const OPTION_OFFSET_LETTERS             = RTS_Engine_Dashboard::OPTION_OFFSET_LETTERS;
        public const OPTION_OFFSET_SHARES              = RTS_Engine_Dashboard::OPTION_OFFSET_SHARES;

        public const OPTION_AUTO_ENABLED = self::OPTION_ENABLE_AUTO_PROCESSING;
        public const OPTION_AUTO_BATCH   = self::OPTION_AUTO_PROCESSING_BATCH_SIZE;
        public const OPTION_MIN_QUALITY  = self::OPTION_MIN_QUALITY_SCORE;
        public const OPTION_IP_THRESHOLD = self::OPTION_IP_DAILY_THRESHOLD;

        public static function get(string $option, $default = null) { return get_option($option, $default); }
        public static function get_bool(string $option, bool $default = false): bool { return (bool) get_option($option, $default ? '1' : '0'); }
        public static function get_int(string $option, int $default = 0): int { return (int) get_option($option, $default); }
    }
}


/* =========================================================
   RTS_Auto_Processor: Schedules / pumps letter processing
   - Dynamic interval (option)
   - Batch up to 250
   - Turbo mode: when backlog passes threshold, keep processing until 0
   ========================================================= */
if (!class_exists('RTS_Auto_Processor')) {
	class RTS_Auto_Processor {
		public const GROUP = 'rts';
		public const ACTION_TICK = 'rts_auto_process_tick';
		public const ACTION_TURBO_TICK = 'rts_turbo_tick';

		public static function init(): void {
			add_action(self::ACTION_TURBO_TICK, [__CLASS__, 'turbo_tick']);
			self::ensure_scheduled();
		}

		private static function get_int_option(string $key, int $default, int $min, int $max): int {
			$val = (int) get_option($key, $default);
			if ($val < $min) return $min;
			if ($val > $max) return $max;
			return $val;
		}

		private static function get_str_option(string $key, string $default, array $allowed): string {
			$val = (string) get_option($key, $default);
			return in_array($val, $allowed, true) ? $val : $default;
		}

		private static function auto_enabled(): bool {
			$v = (string) get_option(RTS_Engine_Dashboard::OPTION_AUTO_ENABLED, '1');
			return ($v !== '0');
		}

		private static function turbo_enabled(): bool {
			return (int) get_option(RTS_Engine_Dashboard::OPTION_TURBO_ENABLED, 1) === 1;
		}

		private static function batch_size(): int {
			// User requested max 250.
			return self::get_int_option(RTS_Engine_Dashboard::OPTION_AUTO_BATCH, 250, 1, 250);
		}

		private static function auto_interval(): int {
			// Default 300s (5 min), but editable.
			return self::get_int_option(RTS_Engine_Dashboard::OPTION_AUTO_INTERVAL, 300, 15, 3600);
		}

		private static function turbo_threshold(): int {
			return self::get_int_option(RTS_Engine_Dashboard::OPTION_TURBO_THRESHOLD, 100, 1, 5000);
		}

		private static function turbo_interval(): int {
			// Short, but not abusive.
			return self::get_int_option(RTS_Engine_Dashboard::OPTION_TURBO_INTERVAL, 20, 5, 600);
		}

		private static function turbo_scope(): string {
			return self::get_str_option(RTS_Engine_Dashboard::OPTION_TURBO_SCOPE, 'both', ['inbox', 'both']);
		}

		private static function pending_backlog(): int {
            global $wpdb;
            $pending = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s", 'letter', 'pending'));
			
            if (self::turbo_scope() === 'both') {
				// Include quarantined letters (draft status with needs_review flag)
                $pending += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = %s AND p.post_status = %s", 'needs_review', '1', 'letter', 'draft'));
			}
			return $pending;
		}

		public static function ensure_scheduled(): void {
			if (!rts_as_available()) return;
			// We use a self-rescheduling single action so interval changes apply immediately.
			if (!as_next_scheduled_action(self::ACTION_TICK, [], self::GROUP)) {
				as_schedule_single_action(time() + 10, self::ACTION_TICK, [], self::GROUP);
			}
		}

		public static function tick(): void {
			if (!rts_as_available()) return;

			// Always re-schedule next tick first, fail-open for continuity.
			if (self::auto_enabled()) {
				$delay = self::auto_interval();
				if (!as_next_scheduled_action(self::ACTION_TICK, [], self::GROUP)) {
					as_schedule_single_action(time() + $delay, self::ACTION_TICK, [], self::GROUP);
				}
			}

			if (!self::auto_enabled()) return;

			// Normal batch scheduling
			self::enqueue_letter_processing(self::batch_size());

			// Turbo trigger: if backlog is large, start turbo immediately.
			if (self::turbo_enabled() && self::pending_backlog() >= self::turbo_threshold()) {
				self::schedule_turbo_now();
			}
		}

		private static function enqueue_letter_processing(int $limit): void {
			// Only schedule actions; Action Scheduler runner will execute.
			$q = new \WP_Query([
				'post_type'      => 'letter',
				'post_status'    => ['pending', 'draft'],
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_query'     => [
					[
						'key'     => 'rts_analysis_status',
						'compare' => 'NOT EXISTS',
					],
				],
			]);

			if (!empty($q->posts)) {
				foreach ($q->posts as $id) {
					$id = (int) $id;
					if (!as_next_scheduled_action('rts_process_letter', [$id], self::GROUP)) {
						as_schedule_single_action(time() + 2, 'rts_process_letter', [$id], self::GROUP);
					}
				}
			}
            
            // Note: Auto processor uses older logic, but Dashboard uses new pump logic. 
            // This is kept for compatibility with the auto-tick setting.
		}

		private static function schedule_turbo_now(): void {
			if (!rts_as_available()) return;
			if (as_next_scheduled_action(self::ACTION_TURBO_TICK, [], self::GROUP)) return;
			as_schedule_single_action(time() + 5, self::ACTION_TURBO_TICK, [], self::GROUP);
		}

		public static function turbo_tick(): void {
			if (!rts_as_available()) return;
			if (!self::auto_enabled() || !self::turbo_enabled()) return;

			// Run a full batch now.
			self::enqueue_letter_processing(self::batch_size());

			$remaining = self::pending_backlog();
			if ($remaining > 0) {
				// Keep scheduling until we hit zero.
				$delay = self::turbo_interval();
				if (!as_next_scheduled_action(self::ACTION_TURBO_TICK, [], self::GROUP)) {
					as_schedule_single_action(time() + $delay, self::ACTION_TURBO_TICK, [], self::GROUP);
				}
			}
		}
	}
}

if (!class_exists('RTS_Moderation_Bootstrap')) {
	class RTS_Moderation_Bootstrap {
		const GROUP = 'rts';
		public static function init(): void {
			add_action('rts_process_letter', [__CLASS__, 'handle_process_letter'], 10, 1);
			add_action('rts_process_import_batch', [__CLASS__, 'handle_import_batch'], 10, 2);
			add_action('rts_aggregate_analytics', [__CLASS__, 'handle_aggregate_analytics'], 10, 0);
			// WP-Cron fallbacks and queue pump (keeps processing moving even if Action Scheduler runner is stalled)
			add_filter('cron_schedules', [__CLASS__, 'cron_schedules']);
			add_action('rts_pump_queue', [__CLASS__, 'pump_queue']);
			add_action('rts_wpcron_process_letter', [__CLASS__, 'handle_process_letter'], 10, 1);
            
            // New pump handler hook (bound to Dashboard class)
            add_action('rts_scan_pump', ['RTS_Engine_Dashboard', 'handle_scan_pump']);
            
			self::ensure_pump_scheduled();
            add_action('rts_auto_process_tick', ['RTS_Auto_Processor', 'tick']);
			add_action('save_post_letter', [__CLASS__, 'on_save_post_letter'], 20, 3);
			add_action('save_post_rts_feedback', [__CLASS__, 'on_save_post_feedback'], 20, 3);
			add_action('pre_get_posts', [__CLASS__, 'exclude_hidden_letters'], 9);
            
            // Share Tracking
            RTS_Share_Tracker::init();

			RTS_Engine_Dashboard::init();
			RTS_Auto_Processor::init();
			add_action('init', [__CLASS__, 'schedule_daily_analytics'], 20);
			add_action('init', [__CLASS__, 'migrate_quarantine_to_draft_status'], 5);
		}



		public static function exclude_hidden_letters(\WP_Query $q): void {
			if (is_admin() || !$q->is_main_query()) return;

			$post_type = $q->get('post_type');

			// Only target the Letters CPT.
			if ($post_type === 'letter' || (is_array($post_type) && in_array('letter', $post_type, true))) {
				$mq = $q->get('meta_query');
				if (!is_array($mq)) $mq = [];
				$mq[] = [
					'key'     => 'rts_hidden',
					'compare' => 'NOT EXISTS',
				];
				$q->set('meta_query', $mq);
			}
		}


		public static function handle_process_letter($post_id): void { RTS_Moderation_Engine::process_letter((int) $post_id); }
		public static function handle_import_batch($job_id, $batch): void { RTS_Import_Orchestrator::process_import_batch((string) $job_id, (array) $batch); }
		public static function handle_aggregate_analytics(): void { RTS_Analytics_Aggregator::aggregate(); }

		public static function on_save_post_letter(int $post_id, \WP_Post $post, bool $update): void {
			// Always keep site stats near real-time when letters are created/imported/updated.
			if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || $post->post_type !== 'letter') return;
			delete_transient('rts_site_stats_v1');

			// Only queue moderation for non-published letters (published letters are handled elsewhere).
			if (!rts_as_available() || $post->post_status === 'publish') return;

			// Skip empty posts (auto-drafts converted to drafts, etc.)
			if (empty(trim($post->post_content ?? ''))) return;

			// If the frontend submission handler already queued a moderation job, don't double-queue.
			if (defined('RTS_FRONTEND_SUBMISSION_IN_PROGRESS') && RTS_FRONTEND_SUBMISSION_IN_PROGRESS) return;
			if (get_post_meta($post_id, '_rts_moderation_job_scheduled', true) === '1') return;

			$existing_ip = (string) get_post_meta($post_id, 'rts_submission_ip', true);
			if (trim($existing_ip) === '') {
				$ip = RTS_IP_Utils::get_client_ip();
				if ($ip !== '') update_post_meta($post_id, 'rts_submission_ip', $ip);
			}
			if (!as_next_scheduled_action('rts_process_letter', [$post_id], self::GROUP)) as_schedule_single_action(time() + 5, 'rts_process_letter', [$post_id], self::GROUP);
		}

		public static function on_save_post_feedback(int $post_id, \WP_Post $post, bool $update): void {
			if ($post->post_type !== 'rts_feedback') return;
            // Simplified kill switch logic
			$letter_id = (int) get_post_meta($post_id, '_rts_feedback_letter_id', true);
			if (!$letter_id) return;
            
            $is_triggering = get_post_meta($post_id, 'is_triggering', true);
            if ($is_triggering === '1' || $is_triggering === 'yes') {
				update_post_meta($letter_id, 'needs_review', '1');
				update_post_meta($letter_id, 'rts_feedback_flag', 'user_report');
                // Set to draft status for quarantine
                wp_update_post(['ID' => $letter_id, 'post_status' => 'draft']);
            }
		}

		public static function schedule_daily_analytics(): void {
			if (!rts_as_available()) return;
			if (as_next_scheduled_action('rts_aggregate_analytics', [], self::GROUP)) return;
			if (function_exists('as_schedule_recurring_action')) as_schedule_recurring_action(strtotime('tomorrow 02:00'), DAY_IN_SECONDS, 'rts_aggregate_analytics', [], self::GROUP);
            else as_schedule_single_action(strtotime('tomorrow 02:00'), 'rts_aggregate_analytics', [], self::GROUP);
		}

		/**
		 * One-time migration: Convert quarantined letters to 'draft' status.
		 * Migrates from 'pending', 'rts-quarantine', or old 'draft' with needs_review=1
		 * This fixes the overlap between inbox/quarantine and standardizes on 'draft'.
		 * Runs once and sets a flag to never run again.
		 */
		public static function migrate_quarantine_to_draft_status(): void {
			// Check if migration already ran
			if (get_option('rts_quarantine_migration_v3_4_0_done')) {
				return;
			}

			global $wpdb;
			
			// Find ALL quarantined letters regardless of current status
			// This includes: pending+needs_review, rts-quarantine status, or draft+needs_review
			$quarantined_ids = $wpdb->get_col($wpdb->prepare(
				"SELECT DISTINCT p.ID 
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
				 WHERE p.post_type = %s
				   AND p.post_status IN ('pending', 'draft', 'rts-quarantine')
				   AND pm.meta_key = %s
				   AND pm.meta_value = %s",
				'letter',
				'needs_review',
				'1'
			));

			// Update them ALL to draft status
			$updated = 0;
			foreach ($quarantined_ids as $letter_id) {
				$result = wp_update_post([
					'ID' => (int) $letter_id,
					'post_status' => 'draft'
				], true);
				
				if (!is_wp_error($result)) {
					$updated++;
				}
			}

			// Set flag so we never run this again
			update_option('rts_quarantine_migration_v3_4_0_done', true, false);

			// Log the migration for debugging
			if ($updated > 0) {
				error_log(sprintf(
					'RTS v3.4.0: Migrated %d quarantined letters to draft status',
					$updated
				));
			}
		}

        public static function cron_schedules(array $schedules): array {
            if (!isset($schedules['rts_minute'])) {
                $schedules['rts_minute'] = [
                    'interval' => 60,
                    'display'  => 'RTS Every Minute',
                ];
            }
            return $schedules;
        }

        private static function ensure_pump_scheduled(): void {
            if (!wp_next_scheduled('rts_pump_queue')) {
                // Runs on traffic (WP-Cron). If server cron is configured, this becomes very reliable.
                wp_schedule_event(time() + 60, 'rts_minute', 'rts_pump_queue');
            }
        }

        /**
         * Safety net: if Action Scheduler runner stalls (or isn't installed),
         * keep nudging the queue forward in small batches.
         */
        public static function pump_queue(): void {
            $batch = (int) get_option(RTS_Engine_Dashboard::OPTION_AUTO_BATCH, 50);
            $batch = max(5, min(100, $batch));

            // Queue oldest pending letters via Dashboard utility
            $pending = new \WP_Query([
                'post_type'      => 'letter',
                'post_status'    => 'pending',
                'fields'         => 'ids',
                'posts_per_page' => $batch,
                'orderby'        => 'date',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ]);
            foreach ($pending->posts as $id) {
                RTS_Engine_Dashboard::queue_letter_scan((int) $id);
            }
        }
	}
}

/* =========================================================
   Boot
   ========================================================= */
if (!function_exists('rts_moderation_engine_boot')) {
	function rts_moderation_engine_boot(): void {
		static $booted = false;
		if ($booted) return;
		$booted = true;
		if (class_exists('RTS_Moderation_Bootstrap')) RTS_Moderation_Bootstrap::init();
	}
}
add_action('init', 'rts_moderation_engine_boot', 1);
if (did_action('init')) rts_moderation_engine_boot();