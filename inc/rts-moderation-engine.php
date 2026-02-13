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

		public static function process_letter(int $post_id, bool $force = false): void {
			$post_id = absint($post_id);
			if (!$post_id) return;

			// Never process trashed letters (admin intentionally deleted them).
			if (get_post_status($post_id) === 'trash') return;

			$post = get_post($post_id);
			if (!$post || $post->post_type !== 'letter') return;

			// =========================================================
			// STAGE GUARDS (Authoritative)
			// =========================================================
			if (class_exists('RTS_Workflow')) {
				$stage = RTS_Workflow::get_stage($post_id);

				// Hard rule: engine may ONLY select and process unprocessed letters.
				if ($stage !== RTS_Workflow::STAGE_UNPROCESSED) {
					// Only manual rescans may move quarantined back into processing.
					if (!$force) {
						RTS_Scan_Diagnostics::log('process_skipped_stage_guard', [
							'post_id' => $post_id,
							'stage'   => $stage,
						]);
						return;
					}
					// Force mode is allowed ONLY for quarantined (manual recheck) or stale processing resets.
					if (!in_array($stage, [RTS_Workflow::STAGE_QUARANTINED, RTS_Workflow::STAGE_PROCESSING], true)) {
						RTS_Scan_Diagnostics::log('process_force_denied', [
							'post_id' => $post_id,
							'stage'   => $stage,
						]);
						return;
					}
				}
			} else {
				// Fail-closed if workflow subsystem is missing.
				return;
			}

			// =========================================================
			// Locking
			// =========================================================
			$lock_key = self::LOCK_PREFIX . $post_id;
			if (get_transient($lock_key)) return;
			set_transient($lock_key, time(), self::LOCK_TTL);

			$start_time = microtime(true);

			try {
				// If this is a forced rescan of a stale processing letter, reset first.
				if (class_exists('RTS_Workflow')) {
					$stage_now = RTS_Workflow::get_stage($post_id);
					if ($stage_now === RTS_Workflow::STAGE_PROCESSING && RTS_Workflow::is_processing_stale($post_id)) {
						RTS_Workflow::reset_stuck_to_unprocessed($post_id, 'Forced rescan: reset stale processing state');
					}
				}

				// HARD GUARD: once processing begins, claim the letter immediately.
				RTS_Workflow::set_stage($post_id, RTS_Workflow::STAGE_PROCESSING, 'Processing started');
				RTS_Workflow::mark_processing_started($post_id);

				// Clear stale queue timestamps before fresh scan
				delete_post_meta($post_id, 'rts_scan_queued_ts');
				delete_post_meta($post_id, 'rts_scan_queued_gmt');

				$results = [
					'safety'  => ['pass' => false, 'flags' => []],
					'ip'      => ['pass' => false, 'reason' => ''],
					'quality' => ['pass' => false, 'score' => 0, 'notes' => []],
					'tags'    => ['applied' => []],
				];

				$results['safety']  = self::safety_scan($post_id);
				$results['ip']      = self::ip_history_check($post_id);
				$results['quality'] = self::quality_scoring($post_id);
				$results['tags']    = self::auto_tagging($post_id);

				update_post_meta($post_id, 'quality_score', (int) ($results['quality']['score'] ?? 0));
				update_post_meta($post_id, 'rts_safety_pass', !empty($results['safety']['pass']) ? '1' : '0');
				update_post_meta($post_id, 'rts_ip_pass', !empty($results['ip']['pass']) ? '1' : '0');

				// Save detailed flag reasons
				if (!empty($results['safety']['details'])) {
					update_post_meta($post_id, 'rts_safety_details', $results['safety']['details']);
				}
				update_post_meta($post_id, 'rts_flagged_keywords', wp_json_encode((array) ($results['safety']['flags'] ?? [])));
				update_post_meta($post_id, 'rts_processing_last', gmdate('c'));
				delete_post_meta($post_id, 'rts_system_error');

				$admin_override = (get_post_meta($post_id, 'rts_admin_override', true) === '1');

				// Severity-based moderation gate:
				$safety_hard_block = !empty($results['safety']['hard_block']);
				$safety_soft_flag  = !empty($results['safety']['soft_flag']);
				$safety_pass_for_stage = (!empty($results['safety']['pass']) && $results['safety']['pass'] === true) || !$safety_hard_block;

				$trusted_import_mode = !empty($results['safety']['trusted_import_mode']);
				$quality_blocks_stage = (!empty($results['quality']['pass']) && $results['quality']['pass'] !== true) && !$trusted_import_mode;

				$all_pass = ((($safety_pass_for_stage === true) || $admin_override) && !$quality_blocks_stage);

				if ($all_pass) {
					$prepared = self::prepare_safe_letter_content($post_id);

					if (!empty($prepared['content']) && !empty($prepared['changed'])) {
						update_post_meta($post_id, '_rts_internal_moderation_update', '1');
						wp_update_post([
							'ID' => $post_id,
							'post_content' => (string) $prepared['content'],
						]);
					}

					update_post_meta($post_id, 'rts_safety_tier', $safety_hard_block ? 'hard_block' : ($safety_soft_flag ? 'soft_flag' : 'clear'));
					if ($safety_soft_flag && !empty($results['safety']['flags'])) {
						update_post_meta($post_id, 'rts_soft_flag_reasons', wp_json_encode(array_values((array) $results['safety']['flags'])));
					} else {
						delete_post_meta($post_id, 'rts_soft_flag_reasons');
					}

					delete_post_meta($post_id, 'needs_review');
					delete_post_meta($post_id, 'rts_flag_reasons');
					delete_post_meta($post_id, 'rts_moderation_reasons');

					if ($admin_override) {
						update_post_meta($post_id, 'rts_admin_override', '0');
					}

					update_post_meta($post_id, 'rts_moderation_status', 'pending_review');
					update_post_meta($post_id, 'rts_analysis_status', 'processed');

					if (!empty($prepared['opener_added'])) {
						update_post_meta($post_id, 'rts_letter_opening_added', '1');
					}
					update_post_meta($post_id, 'rts_accessible_markup', '1');
					update_post_meta($post_id, 'rts_accessible_markup_standard', 'WCAG-2.2-AA');

					// FINAL: stage transition only (no auto publish)
					RTS_Workflow::set_stage($post_id, RTS_Workflow::STAGE_PENDING_REVIEW, 'Processing complete: clean, awaiting review');
					RTS_Workflow::clear_processing_lock($post_id);
				} else {
					// Fail-closed: quarantine
					update_post_meta($post_id, '_rts_internal_moderation_update', '1');

					update_post_meta($post_id, 'needs_review', '1');
					update_post_meta($post_id, 'rts_moderation_status', 'quarantined');
					$quarantine_tier = $safety_hard_block ? 'hard_block' : ((!empty($results['quality']['pass']) && $results['quality']['pass'] === true) ? 'soft_flag' : 'quality_block');
					update_post_meta($post_id, 'rts_safety_tier', $quarantine_tier);
					delete_post_meta($post_id, 'rts_soft_flag_reasons');

					$reasons = [];
					if (empty($results['safety']['pass'])) {
						$flags = is_array($results['safety']['flags']) ? $results['safety']['flags'] : [];
						foreach ($flags as $f) {
							$reasons[] = 'safety:' . sanitize_key((string) $f);
						}
						if (!$flags) $reasons[] = 'safety:flagged';
					}
					if (empty($results['quality']['pass'])) {
						$score = isset($results['quality']['score']) ? (int) $results['quality']['score'] : 0;
						$notes = isset($results['quality']['notes']) && is_array($results['quality']['notes']) ? $results['quality']['notes'] : [];
						$reasons[] = 'quality:score_' . $score;
						foreach ($notes as $n) $reasons[] = 'quality:' . sanitize_key((string) $n);
					}

					$reasons = array_values(array_unique(array_filter($reasons)));
					update_post_meta($post_id, 'rts_flag_reasons', wp_json_encode($reasons));
					update_post_meta($post_id, 'rts_moderation_reasons', implode(',', $reasons));
					update_post_meta($post_id, 'rts_analysis_status', 'processed');

					RTS_Scan_Diagnostics::log('letter_quarantined', [
						'post_id' => $post_id,
						'reasons' => $reasons,
					]);

					RTS_Workflow::set_stage($post_id, RTS_Workflow::STAGE_QUARANTINED, 'Processing complete: unsafe, quarantined');
					RTS_Workflow::clear_processing_lock($post_id);
				}

			} catch (\Throwable $e) {
				update_post_meta($post_id, 'rts_system_error', self::safe_error_string($e));
				update_post_meta($post_id, 'rts_analysis_status', 'failed');
				update_post_meta($post_id, 'needs_review', '1');
				update_post_meta($post_id, 'rts_moderation_status', 'system_error');

				// Fail-closed: quarantine on exceptions.
				if (class_exists('RTS_Workflow')) {
					RTS_Workflow::set_stage($post_id, RTS_Workflow::STAGE_QUARANTINED, 'Processing exception: quarantined fail-closed');
					RTS_Workflow::clear_processing_lock($post_id);
				}
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

            $start_date = sanitize_text_field(wp_strip_all_tags($start_date));
            $end_date   = sanitize_text_field(wp_strip_all_tags($end_date));

			// Validate expected date formats (YYYY-MM-DD or YYYY-MM-DD HH:MM:SS).
			$re = '/^\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2}:\d{2})?$/';
			if (!preg_match($re, $start_date) || !preg_match($re, $end_date)) {
				return [];
			}
			if (strlen($start_date) === 10) $start_date .= ' 00:00:00';
			if (strlen($end_date) === 10) $end_date .= ' 23:59:59';

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
         * Adaptive learned weight for a moderation flag with conservative bounds.
         */
        private static function get_adaptive_flag_weight(string $flag, int $fallback): int {
            $fallback = max(1, (int) $fallback);

            if (!class_exists('RTS_Moderation_Learning') || !method_exists('RTS_Moderation_Learning', 'get_flag_weight')) {
                return $fallback;
            }

            $learned = RTS_Moderation_Learning::get_flag_weight($flag);
            $candidate = isset($learned['weight']) ? (float) $learned['weight'] : (float) $fallback;

            // Keep adaptive weights bounded so one noisy streak cannot overfit moderation.
            $min = max(1, (int) floor($fallback * 0.35));
            $max = (int) ceil($fallback * 2.5);

            return (int) round(max($min, min($max, $candidate)));
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
                    $flag_score += self::get_adaptive_flag_weight((string) $flag_name, 10);
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
                    $flag_score += self::get_adaptive_flag_weight((string) $flag_name, 2);
                }
            }
            
            // ENCOURAGEMENT OF HARM
            $encouragement_patterns = [
                '/\b(you should|just do it|go ahead|nobody will miss)\b.*\b(kill|die|end it)\b/i' => 'encouragement',
                '/\b(you(?:\'re| are|\'d| would)?|they(?:\'re| are|\'d| would)?|he(?:\'s| is|\'d| would)?|she(?:\'s| is|\'d| would)?|someone|people)\b.{0,60}\b(better off dead|better without (?:you|them|him|her)|world.{0,30}better without (?:you|them|him|her))\b/i' => 'harmful_encouragement',
            ];
            
            foreach ($encouragement_patterns as $pattern => $flag_name) {
                $ok = @preg_match($pattern, $content_lc);
                if ($ok === 1) {
                    $flags[] = $flag_name;
                    $flag_score += self::get_adaptive_flag_weight((string) $flag_name, 10);
                }
            }

            // Reflective first-person disclosures are common in recovery letters.
            // They should not be treated as encouraging harm.
            $reflective_disclosure_patterns = [
                '/\b(i\s+(felt|feel|thought|worry|worried)\s+like\s+.*world.{0,30}better without me)\b/i',
                '/\b(world.{0,30}better without me)\b/i',
                '/\b(better off dead)\b.{0,40}\b(i|me|myself)\b/i',
            ];
            $reflective_disclosure = false;
            foreach ($reflective_disclosure_patterns as $pattern) {
                $ok = @preg_match($pattern, $content_lc);
                if ($ok === 1) {
                    $reflective_disclosure = true;
                    $context_hits[] = 'reflective_disclosure';
                    $flag_score -= 4;
                    break;
                }
            }

            if ($reflective_disclosure && in_array('harmful_encouragement', $flags, true)) {
                $flags = array_values(array_filter($flags, static function ($flag) {
                    return $flag !== 'harmful_encouragement';
                }));
                $flag_score = max(0, $flag_score - 10);
                $context_hits[] = 'downgraded_harmful_encouragement';
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
            
            $trusted_import_mode = ((int) get_option('rts_trusted_import_mode', 0) === 1)
                && ((string) get_post_meta($post_id, 'rts_import_content_hash', true) !== '');
            $review_threshold = $trusted_import_mode ? 12 : 8;

            $hard_block_flags = ['malicious_code', 'encouragement', 'harmful_encouragement', 'abusive_language', 'suspicious_links'];
            $hard_block = false;
            foreach ((array) $flags as $flag) {
                if (in_array((string) $flag, $hard_block_flags, true)) {
                    $hard_block = true;
                    break;
                }
            }

            $soft_flag = !$hard_block && ($flag_score >= $review_threshold);
            $needs_review = $hard_block || $soft_flag;
            
            return [
                'pass' => !$needs_review,
                'flags' => $flags,
                'score' => $flag_score,
                'context' => $context_hits,
                'hard_block' => $hard_block,
                'soft_flag' => $soft_flag,
                'review_threshold' => $review_threshold,
                'trusted_import_mode' => $trusted_import_mode,
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
            $trusted_import_mode = ((int) get_option('rts_trusted_import_mode', 0) === 1)
                && ((string) get_post_meta($post_id, 'rts_import_content_hash', true) !== '');
            if ($trusted_import_mode) {
                $threshold_opt = max(15, $threshold_opt - 10);
            }
            $threshold = (int) apply_filters('rts_quality_threshold', $threshold_opt, $post_id, $trusted_import_mode);

            $pass = ($score >= $threshold) && ($len >= 10);

            $notes = [];
            if ($len < 80) $notes[] = 'short';
            if ($len < 40) $notes[] = 'very_short';
            if ($word_count < 10) $notes[] = 'low_words';

            return ['pass' => $pass, 'score' => $score, 'notes' => $notes];
        }

        /**
         * In production we keep safe letters in pending for manual editorial review.
         */
        private static function should_require_manual_review(): bool {
            return (int) get_option('rts_letters_require_manual_review', 1) === 1;
        }

        /**
         * Prepare safe letters with human-readable opener and semantic, accessible HTML markup.
         *
         * @return array{content:string,changed:bool,opener_added:bool}
         */
        private static function prepare_safe_letter_content(int $post_id): array {
            $original = (string) get_post_field('post_content', $post_id);

            $has_article = (bool) preg_match('/<article[^>]*data-rts-letter="1"/i', $original);
            if ($has_article) {
                $needs_refresh = (bool) preg_match(
                    "/(?:[A-Za-z](?:&#0?39;|[\x27’])\\s+[A-Za-z])|(?:^|[\\s(\\[{])[\"“]\\s+[A-Za-z]|\\bDear\\s+strange\\b|wp-block-group|is-layout-constrained|<!--\\s*wp:/iu",
                    $original
                );
                if (!$needs_refresh) {
                    return ['content' => $original, 'changed' => false, 'opener_added' => false];
                }
            }

            $working = str_replace(["\r\n", "\r"], "\n", $original);
            $working = preg_replace('/<!--\s*\/?wp:[^>]*-->/u', '', (string) $working);
            $working = preg_replace('/<\/p>/iu', "</p>\n\n", (string) $working);
            $working = preg_replace('/<br\s*\/?>/iu', "\n", (string) $working);

            $plain = trim((string) wp_strip_all_tags((string) $working));
            $plain = html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($plain === '') {
                return ['content' => $original, 'changed' => false, 'opener_added' => false];
            }

            $title = trim((string) get_the_title($post_id));
            if ($title !== '') {
                $plain = str_replace($title, '', $plain);
            }

            $normalized = (string) $plain;
            $normalized = str_replace(["’", "‘"], "'", (string) $normalized);
            $normalized = preg_replace('/[ \t]+/u', ' ', (string) $normalized);
            $normalized = preg_replace('/\h+\n/u', "\n", (string) $normalized);
            $normalized = preg_replace('/\n\h+/u', "\n", (string) $normalized);
            $normalized = preg_replace('/\s+([,.;:!?])/u', '$1', (string) $normalized);
            $normalized = preg_replace('/([,.;:!?])([^\s\)\]\}\.,;:!?])/u', '$1 $2', (string) $normalized);
            $normalized = preg_replace('/([.!?]){2,}/u', '$1', (string) $normalized);
            $normalized = preg_replace('/\n{3,}/u', "\n\n", (string) $normalized);

            $normalized = preg_replace('/(["“])\s+([^\s])/u', '$1$2', (string) $normalized);
            $normalized = preg_replace('/([^\s])\s+(["”])/u', '$1$2', (string) $normalized);
            $normalized = preg_replace_callback(
                '/\b([A-Za-z]+)\s*[\x27\’]\s*(m|ve|ll|d|re|s|t)\b/u',
                static function (array $m): string {
                    return $m[1] . "'" . $m[2];
                },
                (string) $normalized
            );

            $normalized = preg_replace('/\bI\s*\x27\s*m\b/u', "I'm", (string) $normalized);
            $normalized = preg_replace('/\bI\s*\x27\s*ve\b/u', "I've", (string) $normalized);
            $normalized = preg_replace('/\bI\s*\x27\s*ll\b/u', "I'll", (string) $normalized);
            $normalized = preg_replace('/\bI\s*\x27\s*d\b/u', "I'd", (string) $normalized);

            $spelling_patterns = [
                '/\bwat\b/iu' => 'want',
                '/\bbecuase\b/iu' => 'because',
                '/\blatter\b/iu' => 'letter',
                '/\bteh\b/iu' => 'the',
                '/\bim\b/iu' => "I'm",
                '/\bive\b/iu' => "I've",
                '/\bdont\b/iu' => "don't",
                '/\bcant\b/iu' => "can't",
                '/\bwont\b/iu' => "won't",
            ];
            foreach ($spelling_patterns as $pattern => $replacement) {
                $normalized = preg_replace($pattern, $replacement, (string) $normalized);
            }

            $normalized = preg_replace('/\bDear\s+strange\b/iu', 'Dear stranger', (string) $normalized);
            $normalized = preg_replace('/(?:\bDear\s+strang(?:e|er),?\s*){2,}/iu', 'Dear stranger, ', (string) $normalized);

            $opener_added = false;
            $intro_check = mb_strtolower(trim((string) mb_substr((string) $normalized, 0, 120)));
            if (!preg_match('/^(dear|dearest|hello|hi|hey|greetings|salutations|to)\b/u', $intro_check)) {
                $normalized = "Dear stranger,\n\n" . ltrim((string) $normalized);
                $opener_added = true;
            } else {
                $normalized = preg_replace('/^dear\s+strange(?:r)?\b[,]?/iu', 'Dear stranger,', ltrim((string) $normalized));
            }

            $paragraphs = preg_split("/\n{2,}/u", (string) $normalized, -1, PREG_SPLIT_NO_EMPTY);
            if (!is_array($paragraphs) || empty($paragraphs)) {
                $paragraphs = [(string) $normalized];
            }

            $body_html = '';
            foreach ($paragraphs as $paragraph) {
                $p = trim((string) $paragraph);
                if ($p === '') {
                    continue;
                }
                $p = preg_replace('/\s+/u', ' ', (string) $p);
                $escaped = esc_html((string) $p);
                $body_html .= "<p>{$escaped}</p>\n";
            }

            if ($body_html === '') {
                return ['content' => $original, 'changed' => false, 'opener_added' => $opener_added];
            }

            $html = '<article role="article" aria-label="Reasons to Stay letter" data-rts-letter="1" class="rts-letter-body">' . "\n";
            $html .= $body_html;
            $html .= '</article>';

            return [
                'content' => $html,
                'changed' => ($html !== $original),
                'opener_added' => $opener_added,
            ];
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
				$top_feelings = array_slice(array_keys($feeling_scores), 0, 3);
				$feeling_term_ids = self::get_or_create_term_ids('letter_feeling', $top_feelings);
				if (!empty($feeling_term_ids)) {
					wp_set_post_terms($post_id, $feeling_term_ids, 'letter_feeling', false);
					$applied['letter_feeling'] = $feeling_term_ids;
				}
			}
			
			if (!empty($tone_scores)) {
				arsort($tone_scores);
				$top_tones = array_slice(array_keys($tone_scores), 0, 2);
				$tone_term_ids = self::get_or_create_term_ids('letter_tone', $top_tones);
				if (!empty($tone_term_ids)) {
					wp_set_post_terms($post_id, $tone_term_ids, 'letter_tone', false);
					$applied['letter_tone'] = $tone_term_ids;
				}
			}
			
			return ['applied' => $applied];
		}

		private static function get_or_create_term_ids(string $taxonomy, array $slugs_or_names): array {
			if (!taxonomy_exists($taxonomy)) return [];
			$found = [];
			foreach ($slugs_or_names as $val) {
				$val = (string) $val;
				if ($val === '') continue;
				$term = get_term_by('slug', sanitize_title($val), $taxonomy);
				if (!$term) $term = get_term_by('name', $val, $taxonomy);
                if (!$term || is_wp_error($term)) {
                    $created = wp_insert_term($val, $taxonomy, array('slug' => sanitize_title($val)));
                    if (!is_wp_error($created) && !empty($created['term_id'])) {
                        $term = get_term((int) $created['term_id'], $taxonomy);
                    }
                }
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
			// Pre-calculate total rows/items for progress UI.
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
					$title = !empty($item['title']) ? sanitize_text_field($item['title']) : ('Letter ' . current_time('Y-m-d') . ' #0');
                    $post_id = wp_insert_post(['post_type' => 'letter', 'post_title' => $title, 'post_content' => (string) $item['content'], 'post_status' => 'pending'], true);
                    if (is_wp_error($post_id) || !$post_id) { $errors++; continue; }
                    $post_id = (int) $post_id;
                    $date_seed = current_time('Y-m-d');
                    $seed_post_date = (string) get_post_field('post_date', $post_id);
                    if ($seed_post_date !== '' && $seed_post_date !== '0000-00-00 00:00:00') {
                        $seed_ts = strtotime($seed_post_date);
                        if ($seed_ts !== false) {
                            $date_seed = wp_date('Y-m-d', $seed_ts);
                        }
                    }
                    $canonical_title = sprintf('Letter %s #%d', $date_seed, $post_id);
                    update_post_meta($post_id, '_rts_internal_moderation_update', '1');
                    wp_update_post(['ID' => $post_id, 'post_title' => $canonical_title]);
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
				$count = 0;
				foreach ($f as $line) {
					if (trim((string) $line) !== '') $count++;
				}
				return max(0, $count);
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
			try {
				$f = new SplFileObject($file_path, 'r');
				$f->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
				$header_seen = false;
				$count = 0;
				foreach ($f as $row) {
					if (!is_array($row) || count($row) === 0) continue;
					$has_value = false;
					foreach ($row as $cell) {
						if (trim((string) $cell) !== '') { $has_value = true; break; }
					}
					if (!$has_value) continue;
					if (!$header_seen) { $header_seen = true; continue; } // skip header row
					$count++;
				}
				return max(0, $count);
			} catch (\Throwable $e) { return 0; }
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

			private static function valid_nonce(array $payload = []): bool {
				$nonce = '';
				if (function_exists('rts_request_nonce_from_array')) {
					$nonce = rts_request_nonce_from_array($_POST, ['nonce', '_wpnonce']);
				}

				if ($nonce === '' && isset($payload['nonce']) && is_string($payload['nonce'])) {
					$nonce = sanitize_text_field($payload['nonce']);
				}

				if ($nonce === '') return false;

				if (function_exists('rts_verify_nonce_actions')) {
					return rts_verify_nonce_actions($nonce, ['wp_rest', 'rts_public_nonce']);
				}

				return (bool) (wp_verify_nonce($nonce, 'wp_rest') || wp_verify_nonce($nonce, 'rts_public_nonce'));
			}

	        
public static function handle_ajax() {
	            // Payload is posted as a JSON string in `payload` by rts-system.js
	            $payload = [];
	            if (isset($_POST['payload']) && is_string($_POST['payload']) && $_POST['payload'] !== '') {
	                $decoded = json_decode(wp_unslash($_POST['payload']), true);
	                if (is_array($decoded)) $payload = $decoded;
	            }
				if (!self::valid_nonce($payload)) {
					wp_send_json_error(['message' => 'Invalid nonce'], 403);
				}

            $letter_id = isset($payload['letter_id']) ? absint($payload['letter_id']) : 0;
            $platform  = isset($payload['platform']) ? sanitize_key($payload['platform']) : 'unknown';

            if (!$letter_id || get_post_type($letter_id) !== 'letter') {
                wp_send_json_error(['message' => 'Invalid letter']);
            }

            // Basic throttle: 1 share per IP per *platform* per letter per 30s.
            // This prevents accidental rapid-fire spam while still allowing
            // legitimate users to share via multiple platforms during a session.
            $ip = '';
            if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $ip = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
            elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) $ip = (string) explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
            elseif (!empty($_SERVER['REMOTE_ADDR'])) $ip = (string) $_SERVER['REMOTE_ADDR'];
            $ip = trim($ip);
            $ip_hash = $ip ? substr(sha1($ip), 0, 16) : 'noip';
            $plat_norm = preg_replace('/[^a-z0-9_\-]/i', '', strtolower((string) $platform));
            if ($plat_norm === '') $plat_norm = 'unknown';
            $tkey = 'rts_track_share_' . $ip_hash . '_' . $letter_id . '_' . $plat_norm;
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
		private const DASHBOARD_CACHE_GROUP = 'rts_dashboard_metrics';
		private const DASHBOARD_CACHE_TTL   = 120;

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

        /**
         * Register RTS REST routes used by the admin dashboard polling.
         *
         * This MUST exist because init() attaches it to rest_api_init.
         */
        public static function register_rest(): void {
            register_rest_route('rts/v1', '/processing-status', [
                'methods'  => 'GET',
                'callback' => [__CLASS__, 'rest_processing_status'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ]);

            // Frontend engagement tracking (used by rts-system.js). These routes are public
            // and intentionally minimal: they only increment counters with throttling.
            register_rest_route('rts/v1', '/track/view', [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'rest_track_view'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('rts/v1', '/track/helpful', [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'rest_track_helpful'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('rts/v1', '/track/rate', [
                'methods'             => 'POST',
                'callback'            => [__CLASS__, 'rest_track_rate'],
                'permission_callback' => '__return_true',
            ]);
        }


				private static function dashboard_cache_key(string $suffix): string {
			return 'rts_dash_' . get_current_blog_id() . '_' . md5($suffix);
		}

		private static function dashboard_cached(string $suffix, callable $resolver, int $ttl = self::DASHBOARD_CACHE_TTL) {
			$key = self::dashboard_cache_key($suffix);

			$cached = wp_cache_get($key, self::DASHBOARD_CACHE_GROUP);
			if ($cached !== false) {
				return $cached;
			}

			$cached = get_transient($key);
			if ($cached !== false) {
				wp_cache_set($key, $cached, self::DASHBOARD_CACHE_GROUP, $ttl);
				return $cached;
			}

			$value = $resolver();
			wp_cache_set($key, $value, self::DASHBOARD_CACHE_GROUP, $ttl);
			set_transient($key, $value, $ttl);

			return $value;
		}

		private static function table_exists(string $table_name): bool {
			static $cache = [];
			if (isset($cache[$table_name])) {
				return $cache[$table_name];
			}

			global $wpdb;
			$found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name));
			$exists = is_string($found) && $found === $table_name;
			$cache[$table_name] = $exists;

			return $exists;
		}

        public static function enqueue_assets($hook): void {
            // Load CSS and JS on all RTS admin pages
            if (isset($_GET['page']) && strpos($_GET['page'], 'rts-') !== false) {
                // Main Admin Styles (includes dashboard styles) - cache-bust via filemtime
                $css_path = get_stylesheet_directory() . '/assets/css/rts-admin-complete.css';
                $css_ver  = file_exists($css_path) ? (string) filemtime($css_path) : null;
                wp_enqueue_style('rts-admin-css', get_stylesheet_directory_uri() . '/assets/css/rts-admin-complete.css', [], $css_ver);

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
				'Letters Dashboard',
				'Letters Dashboard',
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
            register_setting('rts_engine_settings', 'rts_onboarder_enabled', ['sanitize_callback' => 'absint', 'default' => 1]);
            
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
			return (int) self::dashboard_cached('needs_review_count', function() {
				global $wpdb;
				// Count quarantined letters (draft status + needs_review flag).
				return (int) $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(DISTINCT p.ID)
					 FROM {$wpdb->postmeta} pm
					 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					 WHERE pm.meta_key = %s
					   AND pm.meta_value = %s
					   AND p.post_type = %s
					   AND p.post_status = %s",
					'needs_review', '1', 'letter', 'draft'
				));
			}, 90);
		}

private static function count_posts_by_status_live(string $post_type): array {
global $wpdb;

$rows = $wpdb->get_results(
$wpdb->prepare(
"SELECT post_status, COUNT(1) AS cnt FROM {$wpdb->posts} WHERE post_type=%s GROUP BY post_status",
$post_type
),
ARRAY_A
);

$counts = [
'publish' => 0,
'pending' => 0,
'draft'   => 0,
'future'  => 0,
'private' => 0,
];

if (is_array($rows)) {
foreach ($rows as $r) {
$st = isset($r['post_status']) ? (string) $r['post_status'] : '';
if ($st === '') continue;
$counts[$st] = (int) ($r['cnt'] ?? 0);
}
}

return $counts;
}

private static function get_basic_stats(): array {
// Use live counts for the headline cards (avoid wp_count_posts() cache drift after bulk SQL updates).
$letter_counts = self::count_posts_by_status_live('letter');
$off_letters   = (int) get_option(self::OPTION_OFFSET_LETTERS, 0);

$fb_counts      = self::count_posts_by_status_live('rts_feedback');
$feedback_total = (int) (($fb_counts['publish'] ?? 0) + ($fb_counts['pending'] ?? 0) + ($fb_counts['draft'] ?? 0) + ($fb_counts['private'] ?? 0) + ($fb_counts['future'] ?? 0));

$letters_published = (int) ($letter_counts['publish'] ?? 0);
$letters_pending   = (int) ($letter_counts['pending'] ?? 0);
$letters_draft     = (int) ($letter_counts['draft'] ?? 0);
$letters_future    = (int) ($letter_counts['future'] ?? 0);
$letters_private   = (int) ($letter_counts['private'] ?? 0);


// Workflow-aware counts (meta driven) for clarity.
$pending_review = class_exists('RTS_Workflow') ? RTS_Workflow::count_by_stage('pending_review') : 0;
$quarantined  = class_exists('RTS_Workflow') ? RTS_Workflow::count_by_stage('quarantined') : 0;
$unprocessed       = class_exists('RTS_Workflow') ? RTS_Workflow::count_by_stage('unprocessed') : 0;
$skipped_pub    = class_exists('RTS_Workflow') ? RTS_Workflow::count_by_stage('published') : 0;
$approved_pub   = class_exists('RTS_Workflow') ? RTS_Workflow::count_by_stage('published') : 0;

// Missing stage (used to prompt backfill). Avoid heavy meta queries.
$missing_stage = 0;
if (class_exists('RTS_Workflow')) {
$missing_stage = (int) self::dashboard_cached('workflow_missing_stage', function() {
global $wpdb;
return (int) $wpdb->get_var(
$wpdb->prepare(
"SELECT COUNT(p.ID) FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON (pm.post_id = p.ID AND pm.meta_key = %s) WHERE p.post_type = %s AND pm.post_id IS NULL",
RTS_Workflow::META_STAGE,
'letter'
)
);
}, 120);
}

return [
'total'          => (int) ($letters_published + $letters_pending + $letters_draft + $letters_future + $letters_private),
'published'      => (int) max(0, $letters_published + $off_letters),
'pending'        => (int) $letters_pending,
'needs_review'   => self::count_needs_review(),
'feedback_total' => $feedback_total,
// Workflow metrics
'pending_review' => (int) $pending_review,
'quarantined'  => (int) $quarantined,
'unprocessed'       => (int) $unprocessed,
'skipped_pub'    => (int) $skipped_pub,
'approved_pub'   => (int) $approved_pub,
'missing_stage'  => (int) $missing_stage,
];
}

		public static function render_page(): void {
			if (!current_user_can('manage_options')) return;

			$tab = self::get_tab();
			$stats = self::get_basic_stats();
			$import = get_option('rts_import_job_status', []);
				// Manual import pump: if Action Scheduler runner is blocked on this host, keep imports moving while dashboard is open.
				if (is_array($import) && (($import['status'] ?? '') === 'running') && class_exists('RTS_Import_Hotfix_V2')) {
					try { RTS_Import_Hotfix_V2::manual_tick(); } catch (\Throwable $e) { /* silent */ }
					$import = get_option('rts_import_job_status', []);
				}

			$agg    = get_option('rts_aggregated_stats', []);
			$as_ok  = rts_as_available();
			$message = isset($_GET['rts_msg']) ? sanitize_key((string) $_GET['rts_msg']) : '';
				$dark = (int) get_option(self::OPTION_DARK_MODE, 0) === 1;
			$workflow_attention = (int) ($stats['pending_review'] ?? 0) + (int) ($stats['unprocessed'] ?? 0) + (int) ($stats['needs_review'] ?? 0);
			$workflow_state_label = $workflow_attention > 0 ? 'Workflow Attention Needed' : 'Workflow Healthy';
			$workflow_state_class = $workflow_attention > 0 ? 'status-warning' : 'status-good';

            // Layout uses rts-admin.css
			?>
			<div class="wrap rts-dashboard <?php echo $dark ? "rts-darkmode" : ""; ?>">
                <header class="rts-dashboard-header">
                    <div>
                        <h1 class="rts-title">Letters Dashboard</h1>
                        <p class="rts-subtitle">Review submissions, handle flags, and publish safely.</p>
                    </div>
                    
                    <div class="rts-system-status">
                        <div class="rts-status-indicator <?php echo $as_ok ? 'status-online' : 'status-offline'; ?>">
                            <span class="rts-status-dot"></span>
                            <span class="rts-status-text"><?php echo $as_ok ? 'System Online' : 'System Offline'; ?></span>
                        </div>
                        <div class="rts-status-indicator <?php echo esc_attr($workflow_state_class); ?>">
                            <span class="rts-status-dot"></span>
                            <span class="rts-status-text"><?php echo esc_html($workflow_state_label); ?></span>
                        </div>
	                        <button type="button" class="rts-btn rts-btn-ghost rts-help-inline" id="rts-open-help" aria-label="Open help" aria-expanded="false" aria-controls="rts-dashboard-help-drawer">
	                            Help
	                        </button>
                    </div>
                </header>

	                <div id="rts-dashboard-help-backdrop" class="rts-help-backdrop" hidden></div>
	                <aside id="rts-dashboard-help-drawer" class="rts-help-drawer" aria-hidden="true" role="dialog" aria-labelledby="rts-help-title">
	                    <div class="rts-help-drawer__header">
	                        <h2 id="rts-help-title">Letters Dashboard Help</h2>
	                        <button type="button" class="button button-small" id="rts-close-help">Close</button>
	                    </div>
	                    <div class="rts-help-drawer__content">
	                        <p>Use this page to triage new submissions, review safety flags, and monitor the processing pipeline.</p>
	                        <div class="rts-help-drawer__grid">
	                            <div class="rts-help-drawer__card">
	                                <h3>Queue Terms</h3>
	                                <ul>
	                                    <li><strong>Unprocessed:</strong> Letters that have arrived but haven't been processed yet.</li>
	                                    <li><strong>Processing:</strong> The system is scanning and tagging these letters.</li>
	                                    <li><strong>Pending Review:</strong> Ready for a human check. These will never be auto-processed again.</li>
	                                    <li><strong>Quarantined:</strong> Held back for safety or quality checks. Review the reason, then recheck manually.</li>
	                                </ul>
	                            </div>
	                            <div class="rts-help-drawer__card">
	                                <h3>Fast Actions</h3>
	                                <ul>
	                                    <li><a href="<?php echo esc_url(admin_url('edit.php?post_type=letter&rts_stage=pending_review')); ?>">Open Pending Review</a></li>
	                                    <li><a href="<?php echo esc_url(admin_url('edit.php?post_type=letter&rts_stage=quarantined')); ?>">View Quarantined</a></li>
	                                    <li><a href="<?php echo esc_url(admin_url('edit.php?post_type=letter&page=rts-workflow-tools')); ?>">Open Operations Hub</a></li>
	                                    <li><a href="<?php echo esc_url(self::url_for_tab('system')); ?>">Open System Tab</a></li>
	                                </ul>
	                            </div>
	                        </div>
	                    </div>
	                </aside>

	                <script>
	                (function(){
	                    var btn = document.getElementById('rts-open-help');
	                    var drawer = document.getElementById('rts-dashboard-help-drawer');
	                    var backdrop = document.getElementById('rts-dashboard-help-backdrop');
	                    var closeBtn = document.getElementById('rts-close-help');
	                    if (!btn || !drawer) return;

	                    function setOpen(open) {
	                        drawer.classList.toggle('is-open', open);
	                        drawer.setAttribute('aria-hidden', open ? 'false' : 'true');
	                        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
	                        if (backdrop) backdrop.hidden = !open;
	                    }

	                    btn.addEventListener('click', function(e){
	                        e.preventDefault();
	                        var isOpen = drawer.classList.contains('is-open');
	                        setOpen(!isOpen);
	                    });

	                    if (closeBtn) {
	                        closeBtn.addEventListener('click', function(e){
	                            e.preventDefault();
	                            setOpen(false);
	                        });
	                    }

	                    if (backdrop) {
	                        backdrop.addEventListener('click', function(){
	                            setOpen(false);
	                        });
	                    }

	                    document.addEventListener('keydown', function(e){
	                        if (e.key === 'Escape' && drawer.classList.contains('is-open')) {
	                            setOpen(false);
	                        }
	                    });
	                })();
	                </script>

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
						Each letter is being quality-checked, safety-scanned, and tagged. Letters that pass all checks will be moved into <strong>Pending Review</strong> for a human to publish. 
							<a href="<?php echo esc_url(self::url_for_tab('system')); ?>">View progress →</a>
						</p>
					</div>
					<style>
						@keyframes rotation {
							from { transform: rotate(0deg); }
							to { transform: rotate(359deg); }
						}
					</style>
					<?php endif; ?>

	                <?php
	                if (class_exists('RTS_Workflow_Admin') && method_exists('RTS_Workflow_Admin', 'render_letter_command_center')) {
	                    RTS_Workflow_Admin::render_letter_command_center(['move_under_title' => false]);
	                }
	                ?>

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

		private static function stat_card(string $label, string $value, string $type, string $sublabel, string $status_label = '', string $status_tone = 'neutral'): void {
            $icons = [
                'published' => 'dashicons-visibility',
                'pending_review' => 'dashicons-yes-alt',
                'inbox' => 'dashicons-email-alt',
                'quarantined' => 'dashicons-shield',
                'total' => 'dashicons-portfolio',
                'feedback' => 'dashicons-testimonial',
                'system' => 'dashicons-admin-generic',
            ];

	            $links = [
	                'published'   => admin_url('edit.php?post_type=letter&post_status=publish'),
	                'pending_review' => admin_url('edit.php?post_type=letter&rts_stage=pending_review'),
	                'inbox'       => admin_url('edit.php?post_type=letter&rts_stage=unprocessed'),
	                'quarantined' => admin_url('edit.php?post_type=letter&rts_stage=quarantined'),
	                'total'       => admin_url('edit.php?post_type=letter&all_posts=1'),
	                'feedback'    => admin_url('edit.php?post_type=letter&page=rts_feedback'),
	                'system'      => self::url_for_tab('system'),
	            ];

            $url = $links[$type] ?? '';
			$tones = ['good', 'warning', 'danger', 'info', 'neutral'];
			$status_tone = in_array($status_tone, $tones, true) ? $status_tone : 'neutral';
			?>
			<?php if (!empty($url)) : ?>
			<a class="rts-stat-card rts-stat-<?php echo esc_attr($type); ?>" href="<?php echo esc_url($url); ?>">
			<?php else : ?>
			<div class="rts-stat-card rts-stat-<?php echo esc_attr($type); ?>">
			<?php endif; ?>
				<div class="rts-stat-topline">
	                <div class="rts-stat-icon">
	                    <span class="dashicons <?php echo esc_attr($icons[$type] ?? 'dashicons-chart-area'); ?>"></span>
	                </div>
	                <?php if ($status_label !== ''): ?>
	                    <span class="rts-stat-status rts-stat-status-<?php echo esc_attr($status_tone); ?>"><?php echo esc_html($status_label); ?></span>
	                <?php endif; ?>
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
                                <span class="dashicons dashicons-plus-alt" style="color: #1976d2;"></span>
                            </div>
                            <h4>Unprocessed</h4>
                            <p>Letters that have arrived but haven't been processed yet.</p>
                        </div>
                        <div class="rts-info-card">
                            <div class="rts-info-icon" style="background: #ffebee;">
                                <span class="dashicons dashicons-shield" style="color: #d32f2f;"></span>
                            </div>
                            <h4>Quarantined</h4>
                            <p>Held back for safety or quality checks. Review the reason, then recheck manually.</p>
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
	                            'post_status' => ['publish', 'pending'],
	                            'no_found_rows' => true,
	                            'ignore_sticky_posts' => true,
	                            'update_post_meta_cache' => true,
	                            'update_post_term_cache' => false,
	                        ]);
                        
                        if ($recent->have_posts()): 
                            while ($recent->have_posts()): $recent->the_post();
                                $status = get_post_status();
                                $status_class = $status === 'publish' ? 'status-published' : 'status-pending';
                                $status_text = $status === 'publish' ? 'Published' : 'Pending Review';
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
                                                <span class="rts-activity-flag">⚠️ Quarantined</span>
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
            $import = get_option('rts_import_job_status', []);
				// Manual import pump: if Action Scheduler runner is blocked on this host, keep imports moving while dashboard is open.
				if (is_array($import) && (($import['status'] ?? '') === 'running') && class_exists('RTS_Import_Hotfix_V2')) {
					try { RTS_Import_Hotfix_V2::manual_tick(); } catch (\Throwable $e) { /* silent */ }
					$import = get_option('rts_import_job_status', []);
				}

			?>
            <div class="rts-tab-grid rts-tab-grid--letters">
                <div class="rts-card rts-grid-card">
                    <h3 class="rts-section-title">Pending Review</h3>
                    <?php self::render_letters_table('pending', 'Pending Letters', 15); ?>
                </div>
                
                <div class="rts-card rts-grid-card">
                    <h3 class="rts-section-title" style="color:#d63638;">⚠️ Quarantined</h3>
                    <?php self::render_letters_table('needs_review', 'Quarantined Letters', 15); ?>
                </div>

                <?php self::render_import_export_card($import); ?>
            </div>
			<?php
		}

        /**
         * Shared import/export tools used in Letter Management.
         *
         * @param mixed $import
         */
        private static function render_import_export_card($import): void {
            $import_status = is_array($import) ? ($import['status'] ?? 'idle') : 'idle';
            $import_total = is_array($import) ? (int) ($import['total'] ?? 0) : 0;
            $import_processed = is_array($import) ? (int) ($import['processed'] ?? 0) : 0;
            $import_errors = is_array($import) ? (int) ($import['errors'] ?? 0) : 0;
            ?>
            <div class="rts-card rts-grid-span-2 rts-import-export-card">
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
                    <span style="color:#cbd5e1;">Large files are processed in the background in batches.</span>
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

                <p style="margin: 12px 0 0; color:#cbd5e1;">
                    CSV headers supported: <code>title</code>, <code>content</code> (or <code>letter</code>/<code>message</code>/<code>body</code>), optional <code>submission_ip</code>.
                    NDJSON/JSON object keys supported: the same.
                </p>
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
                                        <span class="rts-safety-flag">⚠️ Quarantined</span>
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
                            View All <?php echo $mode === 'pending' ? 'Pending Review' : 'Quarantined'; ?> Letters
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
		$letter_counts = self::count_posts_by_status_live('letter');
		$published_count = (int) ($letter_counts['publish'] ?? 0);
		$pending_count = (int) ($letter_counts['pending'] ?? 0);
		
		// Calculate acceptance rate
		$total_submissions = $published_count + $pending_count;
		$acceptance_rate = $total_submissions > 0 ? round(($published_count / $total_submissions) * 100, 1) : 0;

		// Get average quality score
		$avg_quality = self::dashboard_cached('avg_quality_score', function() use ($wpdb) {
			return $wpdb->get_var("
				SELECT AVG(CAST(meta_value AS DECIMAL(5,2))) 
				FROM {$wpdb->postmeta} 
				WHERE meta_key = 'quality_score' 
				AND meta_value != ''
			");
		}, 120);
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
					<div class="rts-analytics-label">Quarantined</div>
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
					max-width: none;
					width: 100%;
				}

			.rts-share-total-stat {
				text-align: center;
				padding: 20px;
			}

				.rts-share-total-stat .rts-stat-label {
					font-size: 1.1rem;
					color: #cbd5e1;
					margin-bottom: 10px;
				}

			.rts-share-total-stat .rts-stat-value {
				font-size: 3rem;
				font-weight: 700;
				margin-bottom: 8px;
			}

				.rts-share-total-stat .rts-stat-sub {
					font-size: 0.9rem;
					color: rgba(203, 213, 225, 0.84);
				}

			.rts-share-platform-grid {
				display: grid;
				grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
				gap: 20px;
				margin-bottom: 30px;
			}

				.rts-share-platform-card {
					background: linear-gradient(160deg, rgba(11, 18, 36, 0.74), rgba(24, 34, 56, 0.64));
					border: 1px solid rgba(148, 163, 184, 0.34);
					border-radius: 16px;
					padding: 24px;
					transition: all 0.3s ease;
					color: #e8eef8;
				}

				.rts-share-platform-card:hover {
					border-color: rgba(125, 211, 252, 0.7);
					transform: translateY(-4px);
					box-shadow: 0 8px 16px rgba(56, 189, 248, 0.2);
				}

				.rts-platform-header {
					display: flex;
					align-items: center;
					gap: 12px;
					margin-bottom: 16px;
					padding-bottom: 12px;
					border-bottom: 1px solid rgba(125, 211, 252, 0.28);
				}

				.rts-platform-name {
					margin: 0;
					font-size: 1.1rem;
					font-weight: 600;
					color: #e2e8f0;
				}

			.rts-platform-stats {
				text-align: center;
			}

				.rts-platform-count {
					font-size: 2.4rem;
					font-weight: 700;
					color: #22d3ee;
					margin-bottom: 6px;
				}

				.rts-platform-percentage {
					font-size: 0.95rem;
					color: rgba(203, 213, 225, 0.9);
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

	$feedback_stats = self::dashboard_cached('feedback_tab_stats', function() use ($wpdb) {
		$mood_stats = $wpdb->get_results("
			SELECT meta_value as mood_change, COUNT(*) as count
			FROM {$wpdb->postmeta}
			WHERE meta_key = 'mood_change'
			AND meta_value != ''
			GROUP BY meta_value
			ORDER BY count DESC
		", ARRAY_A);

		$triggered_count = (int) $wpdb->get_var("
			SELECT COUNT(*)
			FROM {$wpdb->postmeta}
			WHERE meta_key = 'triggered'
			AND meta_value = '1'
		");

		$positive_count = (int) $wpdb->get_var("
			SELECT COUNT(*)
			FROM {$wpdb->postmeta}
			WHERE meta_key = 'rating'
			AND meta_value IN ('up', 'neutral')
		");

		$much_better = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'mood_change' AND meta_value = 'much_better'");
		$little_better = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'mood_change' AND meta_value = 'little_better'");
		$mood_total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'mood_change' AND meta_value != ''");

		return [
			'mood_stats' => is_array($mood_stats) ? $mood_stats : [],
			'triggered_count' => $triggered_count,
			'positive_count' => $positive_count,
			'much_better' => $much_better,
			'little_better' => $little_better,
			'mood_total' => $mood_total,
		];
	}, 120);

	$mood_stats = is_array($feedback_stats['mood_stats'] ?? null) ? $feedback_stats['mood_stats'] : [];
	$triggered_count = (int) ($feedback_stats['triggered_count'] ?? 0);
	$positive_count = (int) ($feedback_stats['positive_count'] ?? 0);

	$items = get_posts([
		'post_type'      => 'rts_feedback',
		'post_status'    => 'publish',
		'posts_per_page' => 25,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	]);

	// Calculate mood improvement rate
	$much_better = (int) ($feedback_stats['much_better'] ?? 0);
	$little_better = (int) ($feedback_stats['little_better'] ?? 0);
	$mood_total = (int) ($feedback_stats['mood_total'] ?? 0);
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
            $auto_interval = (int) get_option(self::OPTION_AUTO_INTERVAL, 300);
            $turbo_enabled = get_option(self::OPTION_TURBO_ENABLED, '1') === '1';
            $turbo_threshold = (int) get_option(self::OPTION_TURBO_THRESHOLD, 100);
            $turbo_interval = (int) get_option(self::OPTION_TURBO_INTERVAL, 20);
            $turbo_scope = (string) get_option(self::OPTION_TURBO_SCOPE, 'both');
            $dark_mode = get_option(self::OPTION_DARK_MODE, '0') === '1';

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
            <div class="rts-tab-grid rts-tab-grid--settings">
            <div class="rts-card rts-grid-card rts-settings-map-card">
                <h3 class="rts-section-title">Settings Map</h3>
                <p class="rts-settings-map-intro">Settings are split by ownership so teams can find controls faster.</p>
                <ul class="rts-settings-map-list">
                    <li><strong>Letter Management:</strong> inbox review, quarantine tools, bulk import/export.</li>
                    <li><strong>Settings (this tab):</strong> moderation engine, notifications, frontend experience, public stat overrides.</li>
                    <li><strong>System:</strong> runtime health, scheduler status, and logs.</li>
                    <li><strong>Audience &amp; Mail:</strong> subscriber analytics, newsletter queue, audience operations.</li>
                    <li><strong>Email Settings:</strong> SMTP, sender identity, delivery pacing, branding.</li>
                </ul>
                <div class="rts-settings-map-actions">
                    <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=rts_subscriber&page=rts-subscribers-dashboard')); ?>">Open Audience &amp; Mail</a>
                    <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=rts_subscriber&page=rts-email-settings')); ?>">Open Email Settings</a>
                </div>
            </div>

			<div class="rts-card rts-grid-card rts-settings-engine-card">
				<h3 class="rts-section-title">Moderation Engine</h3>
				<form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
					<?php settings_fields('rts_engine_settings'); ?>
					<table class="form-table" role="presentation">
                        <tr><td colspan="2"><hr><strong>Processing Baseline</strong></td></tr>
						<tr>
							<th scope="row">Auto-processing</th>
							<td>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_AUTO_ENABLED); ?>" value="0">
                                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_AUTO_ENABLED); ?>" value="1" <?php checked($auto_enabled); ?>> Enable background engine</label>
                            </td>
						</tr>
						<tr>
							<th scope="row">Processing interval</th>
							<td>
								<input type="number" min="20" step="10" name="<?php echo esc_attr(self::OPTION_AUTO_INTERVAL); ?>" value="<?php echo esc_attr((string) $auto_interval); ?>" class="small-text"> seconds
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
                        <tr>
                            <th scope="row">Min quality score</th>
                            <td>
                                <input type="number" min="1" max="100" name="<?php echo esc_attr(self::OPTION_MIN_QUALITY); ?>" value="<?php echo esc_attr((string) $min_quality); ?>" class="small-text">
                                <p class="description">Letters below this score are quarantined.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">IP rate-limit threshold</th>
                            <td>
                                <input type="number" min="1" max="1000" name="<?php echo esc_attr(self::OPTION_IP_THRESHOLD); ?>" value="<?php echo esc_attr((string) $ip_thresh); ?>" class="small-text"> submissions/day
                                <p class="description">Daily submission threshold per IP before automatic risk escalation.</p>
                            </td>
                        </tr>

						<tr><td colspan="2"><hr><strong>Turbo Recovery (Advanced)</strong></td></tr>
                        <tr>
                            <th scope="row">Turbo settings</th>
                            <td>
                                <details>
                                    <summary>Show turbo options</summary>
                                    <div class="rts-details-body">
                                        <p>
                                            <input type="hidden" name="<?php echo esc_attr(self::OPTION_TURBO_ENABLED); ?>" value="0">
                                            <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_TURBO_ENABLED); ?>" value="1" <?php checked($turbo_enabled); ?>> Enable Turbo</label>
                                        </p>
                                        <p>
                                            <label>Turbo threshold:
                                                <input type="number" min="10" step="10" name="<?php echo esc_attr(self::OPTION_TURBO_THRESHOLD); ?>" value="<?php echo esc_attr((string) $turbo_threshold); ?>" class="small-text"> letters
                                            </label><br>
                                            <span class="description">If unprocessed letters exceed this, turbo mode starts.</span>
                                        </p>
                                        <p>
                                            <label>Turbo scope:
                                                <select name="<?php echo esc_attr(self::OPTION_TURBO_SCOPE); ?>">
                                                    <option value="inbox" <?php selected($turbo_scope, 'inbox'); ?>>Unprocessed only</option>
                                                    <option value="both" <?php selected($turbo_scope, 'both'); ?>>Unprocessed + Quarantined</option>
                                                </select>
                                            </label><br>
                                            <span class="description">Recommended: Unprocessed + Quarantined.</span>
                                        </p>
                                        <p>
                                            <label>Turbo interval:
                                                <input type="number" min="5" step="5" name="<?php echo esc_attr(self::OPTION_TURBO_INTERVAL); ?>" value="<?php echo esc_attr((string) $turbo_interval); ?>" class="small-text"> seconds
                                            </label><br>
                                            <span class="description">Delay between turbo loops.</span>
                                        </p>
                                    </div>
                                </details>
                            </td>
                        </tr>

						<tr><td colspan="2"><hr><strong>Notification Routing</strong></td></tr>
                        <tr>
                            <th scope="row">Enable notifications</th>
                            <td>
                                <input type="hidden" name="rts_email_notifications_enabled" value="0">
                                <label><input type="checkbox" name="rts_email_notifications_enabled" value="1" <?php checked((string) get_option('rts_email_notifications_enabled', '1') === '1'); ?>> Send moderation alerts by email</label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Primary notification email</th>
                            <td>
                                <input type="email" name="rts_admin_notification_email" value="<?php echo esc_attr(get_option('rts_admin_notification_email', get_option('admin_email'))); ?>" class="regular-text">
                                <p class="description">Defaults to WordPress admin email.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">CC notification email</th>
                            <td>
                                <input type="email" name="rts_cc_notification_email" value="<?php echo esc_attr(get_option('rts_cc_notification_email', '')); ?>" class="regular-text">
                                <p class="description">Optional additional recipient.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Notification triggers</th>
                            <td>
                                <input type="hidden" name="rts_notify_on_feedback" value="0">
                                <label class="rts-stacked-check">
                                    <input type="checkbox" name="rts_notify_on_feedback" value="1" <?php checked((string) get_option('rts_notify_on_feedback', '1') === '1'); ?>>
                                    All feedback submissions
                                </label>
                                <input type="hidden" name="rts_notify_on_triggered" value="0">
                                <label class="rts-stacked-check">
                                    <input type="checkbox" name="rts_notify_on_triggered" value="1" <?php checked((string) get_option('rts_notify_on_triggered', '1') === '1'); ?>>
                                    Triggered report events (urgent)
                                </label>
                                <input type="hidden" name="rts_notify_on_negative" value="0">
                                <label class="rts-stacked-check">
                                    <input type="checkbox" name="rts_notify_on_negative" value="1" <?php checked((string) get_option('rts_notify_on_negative', '0') === '1'); ?>>
                                    Negative feedback only
                                </label>
                            </td>
                        </tr>

						<tr><td colspan="2"><hr><strong>Dashboard Display</strong></td></tr>
						<tr>
							<th scope="row">Dark mode</th>
							<td>
                                <input type="hidden" name="<?php echo esc_attr(self::OPTION_DARK_MODE); ?>" value="0">
                                <label><input type="checkbox" name="<?php echo esc_attr(self::OPTION_DARK_MODE); ?>" value="1" <?php checked($dark_mode); ?>> Use dark UI for RTS dashboard pages</label>
                            </td>
						</tr>

                        <tr><td colspan="2"><hr><strong>Frontend Experience</strong></td></tr>
                        <tr>
                            <th scope="row">Onboarder</th>
                            <td>
                                <input type="hidden" name="rts_onboarder_enabled" value="0">
                                <label><input type="checkbox" name="rts_onboarder_enabled" value="1" <?php checked((int) get_option('rts_onboarder_enabled', 1), 1); ?>> Enable onboarding letter browser flow</label>
                                <p class="description">When disabled, visitors bypass onboarding and load letters directly.</p>
                            </td>
                        </tr>

                        <tr><td colspan="2"><hr><strong>Public Stats Overrides (Advanced)</strong></td></tr>
                        <tr>
                            <th scope="row">Overrides</th>
                            <td>
                                <details>
                                    <summary>Show public stat override options</summary>
                                    <div class="rts-details-body">
                                        <p>
                                            <input type="hidden" name="rts_stats_override[enabled]" value="0">
                                            <label><input type="checkbox" name="rts_stats_override[enabled]" value="1" <?php checked(!empty($frontend_overrides['enabled'])); ?>> Enable migration offsets for frontend stats</label>
                                        </p>
                                        <p>
                                            <label>Letters Delivered Offset<br>
                                                <input type="number" name="rts_stats_override[letters_delivered]" value="<?php echo esc_attr((string) intval($frontend_overrides['letters_delivered'])); ?>" class="regular-text">
                                            </label>
                                        </p>
                                        <p>
                                            <label>Letters Submitted Offset<br>
                                                <input type="number" name="rts_stats_override[letters_submitted]" value="<?php echo esc_attr((string) intval($frontend_overrides['letters_submitted'])); ?>" class="regular-text">
                                            </label>
                                        </p>
                                        <p>
                                            <label>Helped Count Offset<br>
                                                <input type="number" name="rts_stats_override[helps]" value="<?php echo esc_attr((string) intval($frontend_overrides['helps'])); ?>" class="regular-text">
                                            </label>
                                        </p>
                                        <p>
                                            <label>Override Feel Better %<br>
                                                <input type="number" name="rts_stats_override[feel_better_percent]" value="<?php echo esc_attr($frontend_overrides['feel_better_percent']); ?>" class="small-text" min="0" max="100" step="1"> %
                                            </label><br>
                                            <span class="description">Leave blank to use calculated value.</span>
                                        </p>
                                    </div>
                                </details>
                            </td>
                        </tr>
					</table>
					<?php submit_button('Save Moderation Settings'); ?>
				</form>
			</div>
            
            <!-- Recheck Quarantined Letters -->
            <div class="rts-card rts-grid-card rts-settings-reprocess-card">
                <h3 class="rts-section-title">Recheck Quarantined</h3>
                <p class="rts-ops-hint">Recheck all quarantined letters with current moderation rules. This clears stuck timestamps and queues all quarantined letters for rechecking.</p>

                <div class="rts-inline-actions">
                    <button type="button"
                            id="rts-force-reprocess-quarantine"
                            class="button button-primary">
                        Recheck All Quarantined
                    </button>
                    <span id="rts-force-reprocess-status"></span>
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
                        
                        $button.prop('disabled', true).text('Processing...');
                        $status.removeClass('is-success is-error').addClass('is-working').text('Clearing timestamps and queuing letters...');
                        
                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'rts_force_reprocess_quarantine',
                                nonce: '<?php echo wp_create_nonce('rts_dashboard_nonce'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $status.removeClass('is-working is-error').addClass('is-success').text('Success: ' + response.data.message);
                                    $button.prop('disabled', false).text('Recheck All Quarantined');
                                    
                                    // Optionally reload the page after 3 seconds
                                    setTimeout(function() {
                                        location.reload();
                                    }, 3000);
                                } else {
                                    $status.removeClass('is-working is-success').addClass('is-error').text('Error: ' + (response.data.message || 'Unknown error'));
                                    $button.prop('disabled', false).text('Recheck All Quarantined');
                                }
                            },
                            error: function(xhr, status, error) {
                                $status.removeClass('is-working is-success').addClass('is-error').text('Ajax error: ' + error);
                                $button.prop('disabled', false).text('Recheck All Quarantined');
                            }
                        });
                    });
                });
                </script>
            </div>
            <?php self::render_learning_insights(); ?>
            </div>
            
			<?php
		}
        
        /**
         * Render moderation learning insights section
         */
        private static function render_learning_insights(): void {
            if (!class_exists('RTS_Moderation_Learning')) {
                ?>
                <div class="rts-card rts-grid-card rts-settings-learning-card">
                    <h3 class="rts-section-title">Moderation Learning Insights</h3>
                    <div class="rts-notice rts-notice-muted rts-notice-center">
                        <p>Learning module is not active. Enable `RTS_Moderation_Learning` to view override analytics and confidence trends.</p>
                    </div>
                </div>
                <?php
                return;
            }
            
            $stats = RTS_Moderation_Learning::get_stats();
            $weights = get_option('rts_pattern_weights', []);
            ?>
            
            <div class="rts-card rts-grid-card rts-settings-learning-card">
                <h3 class="rts-section-title">Moderation Learning Insights</h3>
                
                <div class="rts-insight-grid">
                    <div class="rts-insight-card">
                        <div class="rts-insight-label">Total Decisions Tracked</div>
                        <div class="rts-insight-value rts-insight-value-info"><?php echo number_format_i18n($stats['total_decisions']); ?></div>
                    </div>
                    
                    <div class="rts-insight-card">
                        <div class="rts-insight-label">Admin Override Rate</div>
                        <div class="rts-insight-value <?php echo $stats['override_rate'] > 30 ? 'rts-insight-value-danger' : 'rts-insight-value-good'; ?>"><?php echo esc_html($stats['override_rate']); ?>%</div>
                    </div>
                    
                    <div class="rts-insight-card">
                        <div class="rts-insight-label">System Accuracy</div>
                        <div class="rts-insight-value <?php echo $stats['accuracy_trend']['rate'] >= 70 ? 'rts-insight-value-good' : 'rts-insight-value-warn'; ?>"><?php echo esc_html($stats['accuracy_trend']['rate']); ?>%</div>
                        <div class="rts-insight-meta"><?php echo $stats['accuracy_trend']['correct']; ?> / <?php echo $stats['accuracy_trend']['total']; ?> correct</div>
                    </div>
                </div>
                
                <?php if (!empty($stats['common_overrides'])): ?>
                <h4 class="rts-learning-subtitle">Most Frequently Overridden Flags</h4>
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
			$import_status = is_array($import) ? (string) ($import['status'] ?? 'idle') : 'idle';
			$import_total = is_array($import) ? (int) ($import['total'] ?? 0) : 0;
			$import_processed = is_array($import) ? (int) ($import['processed'] ?? 0) : 0;
			$import_errors = is_array($import) ? (int) ($import['errors'] ?? 0) : 0;
            $queued_jobs = (int) self::count_scheduled('rts_process_letter');
            $scan = get_option('rts_active_scan', []);
            $active_scan = 'Idle';
            if (is_array($scan) && ($scan['status'] ?? '') === 'running') {
                $scan_type = sanitize_key((string) ($scan['type'] ?? 'inbox'));
                $active_scan = ($scan_type === 'quarantine') ? 'Running (Quarantined)' : 'Running (Unprocessed)';
            }
			?>
            <div class="rts-tab-grid rts-tab-grid--system">
                <div class="rts-card rts-grid-card">
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

                <div class="rts-card rts-grid-card">
                    <h3 class="rts-section-title">Runtime Activity</h3>
                    <table class="widefat striped">
                        <tbody>
                            <tr><td><strong>Active Scan</strong></td><td><?php echo esc_html($active_scan); ?></td></tr>
                            <tr><td><strong>Queued Scan Jobs</strong></td><td><?php echo esc_html(number_format_i18n($queued_jobs)); ?></td></tr>
                            <tr><td><strong>Import Status</strong></td><td><?php echo esc_html($import_status); ?></td></tr>
                            <tr><td><strong>Import Progress</strong></td><td><?php echo esc_html($import_total > 0 ? ($import_processed . ' / ' . $import_total) : 'No import in progress'); ?></td></tr>
                            <tr><td><strong>Import Errors</strong></td><td><?php echo esc_html(number_format_i18n($import_errors)); ?></td></tr>
                        </tbody>
                    </table>
                </div>

                <?php if (class_exists('RTS_Logger')): 
                    $logs = RTS_Logger::get_recent(50);
                    $stats = RTS_Logger::get_instance()->get_stats();
                ?>
                <div class="rts-card rts-grid-span-2">
                    <div class="rts-log-header">
                        <h3 class="rts-section-title" style="margin:0;">System Logs</h3>
                        <div class="rts-log-meta">
                            Log size: <?php echo esc_html($stats['size_formatted']); ?> | 
                            Entries: <?php echo intval($stats['lines']); ?>
                        </div>
                    </div>
                    
                    <div class="rts-log-viewer rts-log-viewer--system">
                        <table class="widefat striped rts-system-log-table">
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
                                    <tr><td colspan="4" class="rts-log-empty">No logs recorded yet.</td></tr>
                                <?php else: foreach ($logs as $log): ?>
                                    <tr>
                                        <td class="rts-log-time"><?php echo esc_html((string) ($log['time'] ?? '')); ?></td>
                                        <td>
                                            <span class="rts-log-level rts-level-<?php echo esc_attr(strtolower((string) ($log['level'] ?? 'info'))); ?>">
                                                <?php echo esc_html(strtoupper((string) ($log['level'] ?? 'info'))); ?>
                                            </span>
                                        </td>
                                        <td class="rts-log-message">
                                            <?php echo esc_html((string) ($log['message'] ?? '')); ?>
                                            <?php if (!empty($log['context'])): ?>
                                                <details class="rts-log-context">
                                                    <summary>View Context</summary>
                                                    <pre><?php echo esc_html(json_encode($log['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                                                </details>
                                            <?php endif; ?>
                                        </td>
                                        <td class="rts-log-source"><?php echo esc_html((string) ($log['source'] ?? '')); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="rts-log-actions">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('Are you sure you want to clear all logs?');">
                            <input type="hidden" name="action" value="rts_dashboard_action">
                            <input type="hidden" name="command" value="clear_logs">
                            <?php wp_nonce_field('rts_dashboard_action'); ?>
                            <button type="submit" class="button">Clear Logs</button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
            </div>
			<?php
		}


		public static function handle_import_upload(): void {
			if (!current_user_can('manage_options')) { wp_die('Access denied'); }
			check_admin_referer('rts_import_letters');
            $redirect_tab = self::url_for_tab('letters');

			if (empty($_FILES['rts_import_file']) || !is_array($_FILES['rts_import_file'])) {
				wp_safe_redirect(add_query_arg('rts_msg', 'import_missing', $redirect_tab)); exit;
			}

			$file = $_FILES['rts_import_file'];
			if (!empty($file['error'])) {
				wp_safe_redirect(add_query_arg('rts_msg', 'import_upload_error', $redirect_tab)); exit;
			}

			$original_name = isset($file['name']) ? (string) $file['name'] : 'import';
			$ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
			if (!in_array($ext, ['csv','json','ndjson'], true)) {
				wp_safe_redirect(add_query_arg('rts_msg', 'import_bad_type', $redirect_tab)); exit;
			}

			$uploads = wp_upload_dir();
			$dir = trailingslashit($uploads['basedir']) . 'rts-imports';
			if (!wp_mkdir_p($dir)) {
				wp_safe_redirect(add_query_arg('rts_msg', 'import_mkdir_failed', $redirect_tab)); exit;
			}

			$filename = 'letters_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false) . '.' . $ext;
			$dest = trailingslashit($dir) . $filename;

			if (!@move_uploaded_file((string) $file['tmp_name'], $dest)) {
				wp_safe_redirect(add_query_arg('rts_msg', 'import_move_failed', $redirect_tab)); exit;
			}

			// Use streaming importer for large files (>10MB)
			$filesize = (int) @filesize($dest);
			if ($filesize > 10 * 1024 * 1024 && class_exists('RTS_Streaming_Importer')) {
				$res = RTS_Streaming_Importer::start_import($dest);
			} else {
				$res = RTS_Import_Orchestrator::start_import($dest);
			}


			if (empty($res['ok'])) {
				wp_safe_redirect(add_query_arg('rts_msg', 'import_failed', $redirect_tab)); exit;
			}

			wp_safe_redirect(add_query_arg('rts_msg', 'import_started', $redirect_tab)); exit;
		}

	public static function handle_export_download(): void {
		if (!current_user_can('manage_options')) { wp_die('Access denied'); }
		check_admin_referer('rts_export_letters');

		// For large exports: stream CSV, never load all records into memory.
		@set_time_limit(0);
		@ini_set('memory_limit', '512M');

		while (ob_get_level()) { ob_end_clean(); }

		$stage = isset($_POST['stage']) ? sanitize_key((string) $_POST['stage']) : 'any';
		$allowed_stages = class_exists('RTS_Workflow') ? array_merge(['any'], RTS_Workflow::valid_stages()) : ['any'];
		if (!in_array($stage, $allowed_stages, true)) $stage = 'any';

		$filename = 'rts_letters_' . $stage . '_' . gmdate('Ymd_His') . '.csv';

		nocache_headers();
		header('Content-Type: text/csv; charset=' . get_option('blog_charset'));
		header('Content-Disposition: attachment; filename=' . $filename);
		header('X-Accel-Buffering: no');

		// Disable output buffering at PHP/proxy level as much as possible.
		if (function_exists('apache_setenv')) @apache_setenv('no-gzip', '1');
		@ini_set('zlib.output_compression', 'Off');

		$fh = fopen('php://output', 'w');
		if (!$fh) { wp_die('Unable to open output stream'); }

		fputcsv($fh, [
			'id',
			'title',
			'content',
			'date_gmt',
			'rts_workflow_stage',
			'rts_safety_pass',
			'quality_score',
			'submission_ip',
		]);

		$paged = 1;
		$per_page = 500;

		do {
			$args = [
				'post_type'      => 'letter',
				'post_status'    => 'any',
				'posts_per_page' => $per_page,
				'paged'          => $paged,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			];

			if ($stage !== 'any' && class_exists('RTS_Workflow')) {
				$args['meta_query'] = [
					[ 'key' => RTS_Workflow::META_STAGE, 'value' => $stage ],
				];
			}

			$q = new \WP_Query($args);
			$ids = array_map('intval', (array) $q->posts);

			foreach ($ids as $id) {
				fputcsv($fh, [
					$id,
					get_the_title($id),
					(string) get_post_field('post_content', $id),
					(string) get_post_field('post_date_gmt', $id),
					class_exists('RTS_Workflow') ? RTS_Workflow::get_stage($id) : '',
					(string) get_post_meta($id, 'rts_safety_pass', true),
					(string) get_post_meta($id, 'quality_score', true),
					(string) get_post_meta($id, 'rts_submission_ip', true),
				]);
			}

			fflush($fh);
			flush();

			$paged++;
		} while (!empty($ids));

		fclose($fh);
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

        // AJAX Handler: Start Scan
public static function ajax_start_scan(): void {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission denied'], 403);
    }
    check_ajax_referer('rts_dashboard_nonce', 'nonce');

    $scan_type = isset($_POST['scan_type']) ? sanitize_text_field($_POST['scan_type']) : 'inbox';
    if (!in_array($scan_type, ['inbox', 'quarantine'], true)) {
        $scan_type = 'inbox';
    }

    // Mark scan state for the dashboard.
    update_option('rts_active_scan', [
        'type'       => $scan_type,
        'started_at' => time(),
        'status'     => 'running',
    ], false);

    // Clear diagnostic log for a fresh run.
    delete_option('rts_diag_log');

    // Reset throttling so the pump starts immediately.
    update_option('rts_scan_queued_ts', time(), false);

    $queued = 0;
    $batch  = (int) get_option(self::OPTION_AUTO_BATCH, 100);
    if ($batch < 1) $batch = 50;
    if ($batch > 500) $batch = 500;

    if ($scan_type === 'inbox') {
        // HARD RULE: Only queue UNPROCESSED letters.
        $q = new \WP_Query([
            'post_type'      => 'letter',
            'post_status'    => 'any', // workflow decisions are stage-based; do not use post_status for selection
            'posts_per_page' => $batch,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_query'     => [
                [ 'key' => RTS_Workflow::META_STAGE, 'value' => RTS_Workflow::STAGE_UNPROCESSED ],
            ],
        ]);

        foreach ((array) $q->posts as $pid) {
            if (self::queue_letter_scan((int) $pid, false)) $queued++;
        }
        wp_reset_postdata();
    } else {
        // Manual recheck: quarantine only (never auto).
        $q = new \WP_Query([
            'post_type'      => 'letter',
            'post_status'    => 'any', // workflow decisions are stage-based; do not use post_status for selection
            'posts_per_page' => $batch,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_query'     => [
                [ 'key' => RTS_Workflow::META_STAGE, 'value' => RTS_Workflow::STAGE_QUARANTINED ],
            ],
        ]);

        foreach ((array) $q->posts as $pid) {
            if (self::queue_letter_scan((int) $pid, true)) $queued++;
        }
        wp_reset_postdata();
    }

    // Kick the pump (Action Scheduler).
    self::schedule_scan_pump(true);

    wp_send_json_success([
        'message' => ($scan_type === 'quarantine')
            ? 'Recheck of quarantined letters queued.'
            : 'Scan of unprocessed letters queued.',
        'queued'  => $queued,
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
            $nonce = '';
            if (isset($_POST['nonce']) && is_string($_POST['nonce'])) {
                $nonce = sanitize_text_field(wp_unslash($_POST['nonce']));
            } elseif (isset($_POST['_wpnonce']) && is_string($_POST['_wpnonce'])) {
                $nonce = sanitize_text_field(wp_unslash($_POST['_wpnonce']));
            }

            $nonce_ok = ($nonce !== '') && (
                wp_verify_nonce($nonce, 'rts_dashboard_nonce')
                || wp_verify_nonce($nonce, 'rts_dashboard_action')
                || wp_verify_nonce($nonce, 'wp_rest')
            );

            if (!$nonce_ok) {
                wp_send_json_error('Invalid security token. Refresh this page and try again.', 403);
            }
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
	            $claims_table  = $wpdb->prefix . 'actionscheduler_claims';
	            $actions_table = $wpdb->prefix . 'actionscheduler_actions';

	            if (self::table_exists($claims_table) && self::table_exists($actions_table)) {
	            	$wpdb->query("DELETE FROM {$claims_table} WHERE action_id IN (SELECT action_id FROM {$actions_table} WHERE hook LIKE 'rts_%' AND status IN ('in-progress', 'pending'))");
	            	$wpdb->query("UPDATE {$actions_table} SET status = 'failed' WHERE hook LIKE 'rts_%' AND status = 'in-progress'");
	            } else {
	            	RTS_Scan_Diagnostics::log('import_cancel_schema_guard_skip', [
	            		'claims_table' => $claims_table,
	            		'actions_table' => $actions_table,
	            	]);
	            }

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

	            if (!function_exists('as_schedule_single_action')) {
	                wp_send_json_error(['message' => 'Action Scheduler not available'], 500);
	            }

	            // Get quarantined letters via authoritative stage meta.
	            $q = new \WP_Query([
	                'post_type'      => 'letter',
	                'post_status'    => 'any',
	                'posts_per_page' => 500,
	                'fields'         => 'ids',
	                'orderby'        => 'ID',
	                'order'          => 'ASC',
	                'no_found_rows'  => true,
	                'meta_query'     => [
	                    [ 'key' => RTS_Workflow::META_STAGE, 'value' => RTS_Workflow::STAGE_QUARANTINED ],
	                ],
	            ]);

	            $ids = array_map('intval', (array) $q->posts);
	            $scheduled = 0;

	            foreach ($ids as $id) {
	                // Manual recheck flag (consumed inside handle_process_letter).
	                update_post_meta($id, 'rts_force_recheck', '1');
	                as_schedule_single_action(time() + 5, 'rts_process_letter', [$id], 'rts');
	                $scheduled++;
	            }

	            RTS_Scan_Diagnostics::log('manual_recheck_quarantine_scheduled', [
	                'scheduled' => $scheduled,
	            ]);

	            wp_send_json_success(['scheduled' => $scheduled]);
	        }

		public static function rest_public_permission(\WP_REST_Request $req) {
			$nonce = '';
			if (function_exists('rts_rest_request_nonce')) {
				$nonce = rts_rest_request_nonce($req, ['nonce', '_wpnonce', 'rts_token']);
			}

			if (!self::valid_public_analytics_nonce($nonce)) {
				return new \WP_Error('rts_invalid_nonce', 'Invalid security token.', ['status' => 403]);
			}

			return true;
		}

		public static function rest_processing_status(\WP_REST_Request $req): \WP_REST_Response {
			try {
				$import = get_option('rts_import_job_status', []);
				// Manual import pump: if Action Scheduler runner is blocked on this host, keep imports moving while dashboard is open.
				if (is_array($import) && (($import['status'] ?? '') === 'running') && class_exists('RTS_Import_Hotfix_V2')) {
					try { RTS_Import_Hotfix_V2::manual_tick(); } catch (\Throwable $e) { /* silent */ }
					$import = get_option('rts_import_job_status', []);
				}

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
    $ok = update_post_meta($post_id, $meta_key, $current + $by);
    if ($ok === false && class_exists('RTS_Scan_Diagnostics')) {
        RTS_Scan_Diagnostics::log('counter_update_failed', [
            'post_id' => $post_id,
            'meta_key' => $meta_key,
            'increment' => $by,
        ]);
    }
}

/**
 * Clear all cached variants of frontend site stats.
 */
private static function clear_site_stats_cache(): void {
    delete_transient('rts_site_stats_v1');

    global $wpdb;
    if (!isset($wpdb) || !isset($wpdb->options)) {
        return;
    }

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE %s
            OR option_name LIKE %s",
        '_transient_rts_site_stats%',
        '_transient_timeout_rts_site_stats%'
    ));
}

private static function valid_public_analytics_nonce(string $nonce): bool {
	$nonce = trim($nonce);
	if ($nonce === '') return false;

	if (function_exists('rts_verify_nonce_actions')) {
		return rts_verify_nonce_actions($nonce, ['wp_rest', 'rts_public_nonce']);
	}

	return (bool) (wp_verify_nonce($nonce, 'wp_rest') || wp_verify_nonce($nonce, 'rts_public_nonce'));
}

private static function ajax_analytics_nonce_ok(array $payload = []): bool {
	$nonce = '';

	if (function_exists('rts_request_nonce_from_array')) {
		$nonce = rts_request_nonce_from_array($_POST, ['nonce', '_wpnonce']);
	}

	if ($nonce === '' && isset($payload['nonce']) && is_string($payload['nonce'])) {
		$nonce = sanitize_text_field($payload['nonce']);
	}
	return self::valid_public_analytics_nonce($nonce);
}

private static function rest_analytics_nonce_ok(\WP_REST_Request $req): bool {
	$nonce = '';
	if (function_exists('rts_rest_request_nonce')) {
		$nonce = rts_rest_request_nonce($req, ['nonce', '_wpnonce', 'rts_token']);
	}
	return self::valid_public_analytics_nonce($nonce);
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

	    
    // If a per-delivery nonce is present, it already de-dupes safely.
    // Keep IP throttle as a fallback only for legacy/no-nonce calls.
    if ($view_nonce === '' && !self::analytics_throttle('view', $letter_id)) {
        return new \WP_REST_Response(['ok' => true, 'throttled' => true], 200);
    }
    // Canonical metric (used by [rts_site_stats_row]).
    self::bump_counter($letter_id, 'rts_views', 1);

    // Back-compat: older builds summed 'view_count'. Keep it in sync.
    self::bump_counter($letter_id, 'view_count', 1);

    // Make the stats row feel "live".
    self::clear_site_stats_cache();
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
    // Throttle per platform so legitimate users can share via multiple channels.
    $plat_throttle = $platform ? preg_replace('/[^a-z0-9_\-]/i', '', strtolower($platform)) : 'unknown';
    if ($plat_throttle === '') $plat_throttle = 'unknown';
    if (!self::analytics_throttle('share_' . $plat_throttle, $letter_id)) {
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
    $view_nonce = isset($payload['view_nonce']) ? sanitize_text_field((string) $payload['view_nonce']) : '';
    if (!$letter_id || get_post_type($letter_id) !== 'letter') {
        wp_send_json_success(['ok' => true]);
    }

    if ($view_nonce !== '') {
        $nonce_key = 'rts_view_nonce_' . md5($view_nonce . '|' . $letter_id);
        if (get_transient($nonce_key)) {
            wp_send_json_success(['ok' => true]);
        }
        set_transient($nonce_key, 1, DAY_IN_SECONDS);
    }

    if ($view_nonce === '' && !self::analytics_throttle('view', $letter_id)) {
        wp_send_json_success(['ok' => true, 'throttled' => true]);
    }
    self::bump_counter($letter_id, 'rts_views', 1);
    self::bump_counter($letter_id, 'view_count', 1);
    self::clear_site_stats_cache();
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
								['key' => 'needs_review', 'value' => 1, 'compare' => '!=', 'type' => 'NUMERIC'],
							],
							[
								'relation' => 'OR',
								['key' => 'rts_spam', 'compare' => 'NOT EXISTS'],
								['key' => 'rts_spam', 'value' => 1, 'compare' => '!=', 'type' => 'NUMERIC'],
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
                        'title'   => '',
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

/**
 * Queue a single letter for scanning/processing.
 *
 * Hard rules:
 * - Automatic processing may only target STAGE_UNPROCESSED.
 * - Manual recheck may target STAGE_QUARANTINED (force=true only).
 */
public static function queue_letter_scan(int $post_id, bool $force = false): bool {
    if ($post_id <= 0) return false;
    if (!function_exists('as_schedule_single_action') || !function_exists('rts_as_available') || !rts_as_available()) return false;

    $stage = (string) get_post_meta($post_id, RTS_Workflow::META_STAGE, true);

    // Stage guards (non-negotiable)
    if ($stage === RTS_Workflow::STAGE_UNPROCESSED) {
        // ok
    } elseif ($force && $stage === RTS_Workflow::STAGE_QUARANTINED) {
        update_post_meta($post_id, 'rts_force_recheck', '1');
    } else {
        return false;
    }

    // Avoid duplicate scheduling for the same letter.
    if (function_exists('as_next_scheduled_action')) {
        $next = as_next_scheduled_action('rts_process_letter', [$post_id], self::GROUP);
        if (!empty($next)) return true;
    }

    as_schedule_single_action(time() + 1, 'rts_process_letter', [$post_id], self::GROUP);
    return true;
}

/**
 * Scan pump called by Action Scheduler/WP-Cron to keep processing moving.
 * Enqueues a batch of UNPROCESSED letters only.
 */
public static function handle_scan_pump(): void {
    if (!function_exists('as_schedule_single_action') || !function_exists('rts_as_available') || !rts_as_available()) return;

    $batch = (int) get_option(self::OPTION_AUTO_BATCH, 100);
    if ($batch < 1) $batch = 50;
    if ($batch > 500) $batch = 500;

    $q = new \WP_Query([
        'post_type'      => 'letter',
        'post_status'    => 'any', // workflow decisions are stage-based; do not use post_status for selection // workflow decisions are stage-based; posts remain draft until published manually
        'posts_per_page' => $batch,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'meta_query'     => [
            [ 'key' => RTS_Workflow::META_STAGE, 'value' => RTS_Workflow::STAGE_UNPROCESSED ],
        ],
    ]);

    if (!empty($q->posts)) {
        foreach ((array) $q->posts as $pid) {
            self::queue_letter_scan((int) $pid, false);
        }
    }
    wp_reset_postdata();
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
			// IMPORTANT:
			// We only auto-process letters that are genuinely *unprocessed*.
			// Once a letter reaches Pending Review, it must not be re-queued unless a human triggers it.
			$q = new \WP_Query([
				'post_type'      => 'letter',
				'post_status'    => ['pending', 'draft'],
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'     => 'rts_analysis_status',
						'compare' => 'NOT EXISTS',
					],
					[
						'relation' => 'OR',
						// Back-compat: older imports might not have a workflow stage yet.
						[
							'key'     => 'rts_workflow_stage',
							'compare' => 'NOT EXISTS',
						],
						// Only unprocessed items are eligible for auto-processing.
						[
							'key'     => 'rts_workflow_stage',
							'value'   => ['unprocessed', 'unprocessed'],
							'compare' => 'IN',
						],
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
					// Keep scheduling while there's work, but back off if progress stalls.
					$guard = get_option('rts_turbo_guard', []);
					if (!is_array($guard)) $guard = [];
					$prev_remaining = (int) ($guard['last_remaining'] ?? -1);
					$stagnant_runs = (int) ($guard['stagnant_runs'] ?? 0);
					if ($prev_remaining >= 0 && $remaining >= $prev_remaining) {
						$stagnant_runs++;
					} else {
						$stagnant_runs = 0;
					}
					update_option('rts_turbo_guard', [
						'last_remaining' => $remaining,
						'stagnant_runs' => $stagnant_runs,
						'updated_gmt' => gmdate('c'),
					], false);

					$delay = self::turbo_interval();
					$stagnant_limit = (int) apply_filters('rts_turbo_stagnant_limit', 30);
					if ($stagnant_runs >= $stagnant_limit) {
						$delay = max(300, $delay * 6);
						RTS_Scan_Diagnostics::log('turbo_backoff', [
							'remaining' => $remaining,
							'stagnant_runs' => $stagnant_runs,
							'delay' => $delay,
						]);
					}
					if (!as_next_scheduled_action(self::ACTION_TURBO_TICK, [], self::GROUP)) {
						as_schedule_single_action(time() + $delay, self::ACTION_TURBO_TICK, [], self::GROUP);
					}
				} else {
					delete_option('rts_turbo_guard');
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


		public static function handle_process_letter($post_id): void {
            $post_id = (int) $post_id;
            $force = (get_post_meta($post_id, 'rts_force_recheck', true) === '1');
            if ($force) {
                delete_post_meta($post_id, 'rts_force_recheck');
            }
            RTS_Moderation_Engine::process_letter($post_id, $force);
        }
		public static function handle_import_batch($job_id, $batch): void { RTS_Import_Orchestrator::process_import_batch((string) $job_id, (array) $batch); }
		public static function handle_aggregate_analytics(): void { RTS_Analytics_Aggregator::aggregate(); }

		public static function on_save_post_letter(int $post_id, \WP_Post $post, bool $update): void {
			// Always keep site stats near real-time when letters are created/imported/updated.
			if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || $post->post_type !== 'letter') return;
			// Skip save events triggered by our own moderation status/content update.
			if (get_post_meta($post_id, '_rts_internal_moderation_update', true) === '1') {
				delete_post_meta($post_id, '_rts_internal_moderation_update');
				return;
			}

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
            // Force fresh analysis for edited content.
            delete_post_meta($post_id, 'rts_analysis_status');
			if (!as_next_scheduled_action('rts_process_letter', [$post_id], self::GROUP)) as_schedule_single_action(time() + 5, 'rts_process_letter', [$post_id], self::GROUP);
		}

		public static function on_save_post_feedback(int $post_id, \WP_Post $post, bool $update): void {
			if ($post->post_type !== 'rts_feedback') return;
            // Support both legacy and canonical feedback meta keys.
			$letter_id = (int) get_post_meta($post_id, '_rts_feedback_letter_id', true);
            if (!$letter_id) {
                $letter_id = (int) get_post_meta($post_id, 'letter_id', true);
            }
			if (!$letter_id || get_post_type($letter_id) !== 'letter') return;

            $triggered = (string) get_post_meta($post_id, 'triggered', true);
            $legacy_trigger = (string) get_post_meta($post_id, 'is_triggering', true);
            $rating = (string) get_post_meta($post_id, 'rating', true);
            $mood_change = (string) get_post_meta($post_id, 'mood_change', true);

            $is_triggering = in_array($triggered, array('1', 'true', 'yes'), true)
                || in_array($legacy_trigger, array('1', 'true', 'yes'), true);
            $is_negative = ($rating === 'down') || in_array($mood_change, array('little_worse', 'much_worse'), true);

            if ($is_triggering || $is_negative) {
                update_post_meta($letter_id, 'needs_review', '1');
                update_post_meta($letter_id, 'rts_feedback_flag', $is_triggering ? 'user_report' : 'negative_feedback');
                wp_update_post(array('ID' => $letter_id, 'post_status' => 'draft'));
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
         * Kick the scan pump immediately in a safe, fail-closed way.
         *
         * - Prefer Action Scheduler async action if available.
         * - Fallback to WP-Cron single event nudge.
         * - Never throws (to avoid admin-ajax 500).
         */
        public static function schedule_scan_pump(bool $immediate = false): void {
            try {
                // Mark the pump as recently queued.
                update_option('rts_scan_queued_ts', time(), false);

                // Prefer Action Scheduler if present and healthy.
                if (function_exists('as_enqueue_async_action') && function_exists('as_next_scheduled_action') && function_exists('as_schedule_single_action') && function_exists('rts_as_available') && rts_as_available()) {
                    // Avoid spamming the runner: only enqueue if nothing already queued very soon.
                    $next = as_next_scheduled_action('rts_scan_pump', [], self::GROUP);
                    if (!$next) {
                        if ($immediate && function_exists('as_enqueue_async_action')) {
                            as_enqueue_async_action('rts_scan_pump', [], self::GROUP);
                        } else {
                            as_schedule_single_action(time() + 5, 'rts_scan_pump', [], self::GROUP);
                        }
                    }
                    return;
                }

                // Fallback: nudge the WP-Cron pump.
                if (!wp_next_scheduled('rts_pump_queue')) {
                    wp_schedule_single_event(time() + 5, 'rts_pump_queue');
                }
            } catch (\Throwable $e) {
                // Never fatal: log and move on.
                if (function_exists('error_log')) {
                    error_log('[RTS] schedule_scan_pump failed: ' . $e->getMessage());
                }
            }
        }

        /**
         * Safety net: if Action Scheduler runner stalls (or isn't installed),
         * keep nudging the queue forward in small batches.
         */
        public static function pump_queue(): void {
            $batch = (int) get_option(RTS_Engine_Dashboard::OPTION_AUTO_BATCH, 50);
            $batch = max(5, min(100, $batch));

            // Queue UNPROCESSED letters only (stage-based)
            $pending = new \WP_Query([
                'post_type'      => 'letter',
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => $batch,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => true,
                'meta_query'     => [
                    [ 'key' => RTS_Workflow::META_STAGE, 'value' => RTS_Workflow::STAGE_UNPROCESSED ],
                ],
            ]);
            foreach ((array) $pending->posts as $id) {
                RTS_Engine_Dashboard::queue_letter_scan((int) $id, false);
            }
            wp_reset_postdata();
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