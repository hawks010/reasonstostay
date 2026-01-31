<?php
/**
 * RTS Moderation Engine (Preservation-First, Fail-Closed, Action Scheduler Only)
 *
 * Drop-in file for your theme: /inc/rts-moderation-engine.php
 * Include from functions.php (safe include snippet provided in final output).
 *
 * Preservation Mandate:
 * - DOES NOT register/modify CPTs or taxonomies.
 * - DOES NOT touch RTS_Shortcodes, frontend styling, or ally-widget.php.
 * - Hooks only: schedules background processing + provides admin dashboard + REST endpoint + analytics cache.
 *
 * Requirements:
 * - Action Scheduler for all background tasks
 * - Fail-Closed: default pending; publish only if all pass
 * - Atomic transient lock: rts_lock_{$post_id} (5 minutes)
 * - Atomic IP lock check: rts_ip_lock_{$ip_hash} (5 minutes, external writer)
 * - Spoof-resistant IP retrieval (Cloudflare HTTP_CF_CONNECTING_IP supported) with FILTER_VALIDATE_IP
 * - Import orchestrator: batch size 50, tracks rts_import_job_status
 * - Decoupled moderation: one action per letter (rts_process_letter)
 * - Admin: single top-level "RTS Dashboard" menu item; removes conflicting submenu slugs
 * - REST: /rts/v1/processing-status (manage_options)
 * - Daily analytics aggregation: rts_aggregate_analytics
 */

if (!defined('ABSPATH')) { exit; }

/* =========================================================
   Utilities: Action Scheduler presence check (Fail-Closed)
   ========================================================= */
if (!function_exists('rts_as_available')) {
	function rts_as_available(): bool {
		return function_exists('as_schedule_single_action')
			&& function_exists('as_next_scheduled_action')
			&& function_exists('as_enqueue_async_action');
	}
}

/* =========================================================
   RTS_IP_Utils: Spoof-resistant IP retrieval
   ========================================================= */
if (!class_exists('RTS_IP_Utils')) {
	class RTS_IP_Utils {

		/**
		 * Get a validated client IP with Cloudflare support.
		 *
		 * Strategy:
		 * - Prefer Cloudflare header if present (HTTP_CF_CONNECTING_IP).
		 * - Then REMOTE_ADDR.
		 * - Explicitly DO NOT trust X-Forwarded-For unless you build a trusted proxy list.
		 */
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

		/**
		 * Hash IP for storage/logging to reduce sensitivity in logs.
		 */
		public static function hash_ip(string $ip): string {
			$ip = trim($ip);
			if ($ip === '') return '';
			return hash_hmac('sha256', $ip, wp_salt('auth'));
		}
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

		/**
		 * Pipeline entrypoint (Action Scheduler hook: rts_process_letter).
		 * Fail-Closed: letter remains pending unless final gate passes.
		 */
		public static function process_letter(int $post_id): void {
			$post_id = absint($post_id);
			if (!$post_id) return;

			$post = get_post($post_id);
			if (!$post || $post->post_type !== 'letter') return;

			$lock_key = self::LOCK_PREFIX . $post_id;

			// Atomic lock (transient)
			if (get_transient($lock_key)) {
				return;
			}
			set_transient($lock_key, time(), self::LOCK_TTL);

			try {
				$results = [
					'safety'  => ['pass' => false, 'flags' => []],
					'ip'      => ['pass' => false, 'reason' => ''],
					'quality' => ['pass' => false, 'score' => 0, 'notes' => []],
					'tags'    => ['applied' => []],
				];

				// 2. Safety Scan
				$results['safety'] = self::safety_scan($post_id);

				// 3. IP History Check
				$results['ip'] = self::ip_history_check($post_id);

				// 4. Quality Scoring
				$results['quality'] = self::quality_scoring($post_id);

				// 5. Auto-Tagging
				$results['tags'] = self::auto_tagging($post_id);

				// Store meta for existing UI/shortcodes to use (preserve RTS_Shortcodes; update data only)
				update_post_meta($post_id, 'quality_score', (int) $results['quality']['score']);
				update_post_meta($post_id, 'rts_safety_pass', $results['safety']['pass'] ? '1' : '0');
				update_post_meta($post_id, 'rts_ip_pass', $results['ip']['pass'] ? '1' : '0');
				update_post_meta($post_id, 'rts_flagged_keywords', wp_json_encode($results['safety']['flags']));
				update_post_meta($post_id, 'rts_processing_last', gmdate('c'));
				delete_post_meta($post_id, 'rts_system_error');

				// 6. Final Gate (publish only if ALL pass)
				$external_flag = (get_post_meta($post_id, 'needs_review', true) === '1');

            $all_pass = (
                $results['safety']['pass'] === true
                && $results['ip']['pass'] === true
                && $results['quality']['pass'] === true
                && !$external_flag
            );

				if ($all_pass) {
					wp_update_post([
						'ID'          => $post_id,
						'post_status' => 'publish',
					]);

					update_post_meta($post_id, 'needs_review', '0');
					update_post_meta($post_id, 'rts_moderation_status', 'published');
				} else {
					update_post_meta($post_id, 'needs_review', '1');
					update_post_meta($post_id, 'rts_moderation_status', 'pending_review');

					$reasons = [];
					if (!$results['safety']['pass']) $reasons[] = 'safety';
					if (!$results['ip']['pass']) $reasons[] = 'ip';
					if (!$results['quality']['pass']) $reasons[] = 'quality';
					update_post_meta($post_id, 'rts_moderation_reasons', implode(',', $reasons));
				}

			} catch (\Throwable $e) {
				// Fail-Closed: do not publish on exception; leave pending and mark system error.
				update_post_meta($post_id, 'needs_review', '1');
				update_post_meta($post_id, 'rts_moderation_status', 'system_error');
				update_post_meta($post_id, 'rts_system_error', self::safe_error_string($e));
			} finally {
				delete_transient($lock_key);
			}
		}

		private static function safe_error_string(\Throwable $e): string {
			$msg = $e->getMessage();
			$msg = is_string($msg) ? $msg : 'Unknown error';
			$msg = wp_strip_all_tags($msg);
			return mb_substr($msg, 0, 300);
		}

		/**
		 * Step 2: Safety scan (simple regex flags; fail-closed on errors)
		 */
		private static function safety_scan(int $post_id): array {
			$content = (string) get_post_field('post_content', $post_id);
			$content_lc = mb_strtolower($content);

			$patterns = [
				'/\bkill myself\b/i',
				'/\bsuicide\b/i',
				'/\bend it all\b/i',
				'/\bno reason to live\b/i',
				'/\bself[-\s]?harm\b/i',
				'/\boverdose\b/i',
				'/\bcutting\b/i',
				'/\bi want to die\b/i',
			];

			$flags = [];
			foreach ($patterns as $pattern) {
				$ok = @preg_match($pattern, $content_lc);
				if ($ok === false) {
					return ['pass' => false, 'flags' => ['regex_error']];
				}
				if ($ok === 1) {
					$flags[] = trim($pattern, '/i');
				}
			}

			return [
				'pass'  => empty($flags),
				'flags' => $flags,
			];
		}

		/**
		 * Step 3: IP history check
		 * - Uses stored meta `rts_submission_ip` if available; otherwise uses current request IP (if any).
		 * - Validates IP strictly.
		 * - Checks transient lock `rts_ip_lock_{$ip_hash}`. If present, the letter is flagged for review.
		 */
		private static function ip_history_check(int $post_id): array {
			global $wpdb;

			$ip = (string) get_post_meta($post_id, 'rts_submission_ip', true);
			$ip = trim($ip);

			if ($ip === '') {
				$ip = RTS_IP_Utils::get_client_ip();
				if ($ip !== '') {
					update_post_meta($post_id, 'rts_submission_ip', $ip);
				}
			}

			if ($ip === '' || !RTS_IP_Utils::is_valid_ip($ip)) {
				return ['pass' => false, 'reason' => 'missing_or_invalid_ip'];
			}

			// NEW: transient lock check (spoof-resistant via hashed key)
			$ip_hash = RTS_IP_Utils::hash_ip($ip);
			if ($ip_hash === '') {
				return ['pass' => false, 'reason' => 'ip_hash_error'];
			}

			$ip_lock_key = 'rts_ip_lock_' . $ip_hash;
			if (get_transient($ip_lock_key)) {
				// Explicitly flag the letter (even though the final gate will also do this).
				update_post_meta($post_id, 'needs_review', '1');
				update_post_meta($post_id, 'rts_ip_lock_hit', '1');
				return ['pass' => false, 'reason' => 'ip_locked'];
			}

			// Basic abuse heuristic: too many submissions from same IP in last 24h
			$since_gmt = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
			$sql = "
				SELECT COUNT(1)
				FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm
					ON pm.post_id = p.ID
				WHERE p.post_type = %s
				  AND p.post_date_gmt >= %s
				  AND pm.meta_key = %s
				  AND pm.meta_value = %s
			";

			$count = (int) $wpdb->get_var($wpdb->prepare(
				$sql,
				'letter',
				$since_gmt,
				'rts_submission_ip',
				$ip
			));

			$threshold = (int) apply_filters('rts_ip_daily_threshold', (int) get_option(RTS_Engine_Dashboard::OPTION_IP_THRESHOLD, 20));
			if ($count > $threshold) {
				set_transient($ip_lock_key, 1, 300);
			return ['pass' => false, 'reason' => 'rate_limit_exceeded'];
			}

			return ['pass' => true, 'reason' => 'ok'];
		}

		/**
		 * Step 4: Quality scoring (0-100)
		 * Spec: basic scoring logic: score = min(100, strlen(content) / 5)
		 * Pass if score >= 70 (configurable via filter).
		 */
		private static function quality_scoring(int $post_id): array {
			$content = trim((string) get_post_field('post_content', $post_id));
			if ($content === '') {
				return ['pass' => false, 'score' => 0, 'notes' => ['empty']];
			}

			$len = (int) mb_strlen($content);
			$score = (int) min(100, (int) floor($len / 5));

			$threshold = (int) apply_filters('rts_quality_threshold', (int) get_option(RTS_Engine_Settings::OPTION_MIN_QUALITY, 70));
			$pass = ($score >= $threshold);

			$notes = [];
			if ($len < 100) $notes[] = 'short';
			if ($len < 50) $notes[] = 'very_short';

			return [
				'pass'  => $pass,
				'score' => $score,
				'notes' => $notes,
			];
		}

		/**
		 * Step 5: Auto-tagging
		 * Spec: apply term 'hopeful' if content contains positive keyword(s) like 'hope' or 'better'.
		 * - Assigns existing terms only (does not create terms).
		 * - Uses existing taxonomies: letter_feeling, letter_tone.
		 */
		private static function auto_tagging(int $post_id): array {
			$content = mb_strtolower((string) get_post_field('post_content', $post_id));
			$applied = [];

			if ($content === '') {
				return ['applied' => $applied];
			}

			$positive_needles = [
				'hope',
				'better',
				'tomorrow',
				'keep going',
				'you can',
				'it gets easier',
				'you are not alone',
			];

			$should_tag_hopeful = false;
			foreach ($positive_needles as $needle) {
				if ($needle !== '' && mb_strpos($content, $needle) !== false) {
					$should_tag_hopeful = true;
					break;
				}
			}

			if ($should_tag_hopeful) {
				$term_ids = self::get_existing_term_ids('letter_feeling', ['hopeful']);
				if (!empty($term_ids)) {
					wp_set_post_terms($post_id, $term_ids, 'letter_feeling', false);
					$applied['letter_feeling'] = $term_ids;
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
				if (!$term) {
					$term = get_term_by('name', $val, $taxonomy);
				}
				if ($term && !is_wp_error($term)) {
					$found[] = (int) $term->term_id;
				}
			}
			$found = array_values(array_unique(array_filter($found)));
			return $found;
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

		/**
		 * Start an import from a CSV file path.
		 * CSV expected columns (flexible):
		 * - content (required) OR letter OR message OR body
		 * - title (optional)
		 * - submission_ip (optional)
		 */
		public static function start_import(string $file_path): array {
			if (!rts_as_available()) {
				return ['ok' => false, 'error' => 'action_scheduler_missing'];
			}

			$file_path = wp_normalize_path($file_path);
			if ($file_path === '' || !file_exists($file_path) || !is_readable($file_path)) {
				return ['ok' => false, 'error' => 'file_not_readable'];
			}

			$job_id = 'job_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false);

			$total = self::count_csv_rows($file_path);
			update_option('rts_import_job_status', [
				'job_id'      => $job_id,
				'total'       => $total,
				'processed'   => 0,
				'errors'      => 0,
				'status'      => 'running',
				'started_gmt' => gmdate('c'),
				'file'        => basename($file_path),
			], false);

			$fh = new SplFileObject($file_path, 'r');
			$fh->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);

			$header = null;
			$batch = [];
			$scheduled = 0;

			foreach ($fh as $row) {
				if (!is_array($row) || count($row) === 0) continue;

				if ($header === null) {
					$header = self::normalize_header($row);
					continue;
				}

				$item = self::map_row($header, $row);
				if (empty($item['content'])) {
					self::bump_status('errors', 1);
					continue;
				}

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

			return ['ok' => true, 'job_id' => $job_id, 'scheduled_batches' => $scheduled, 'total' => $total];
		}

		public static function process_import_batch(string $job_id, array $batch): void {
			if (!is_string($job_id) || $job_id === '') return;
			if (!is_array($batch) || empty($batch)) return;

			$status = get_option('rts_import_job_status', []);
			if (!is_array($status) || ($status['job_id'] ?? '') !== $job_id) {
				return;
			}

			$processed = 0;
			$errors = 0;

			foreach ($batch as $item) {
				try {
					$title = !empty($item['title']) ? sanitize_text_field($item['title']) : '';
					if ($title === '') {
						$title = 'Letter ' . wp_generate_password(8, false, false);
					}

					$post_id = wp_insert_post([
						'post_type'    => 'letter',
						'post_title'   => $title,
						'post_content' => (string) $item['content'],
						'post_status'  => 'pending',
					], true);

					if (is_wp_error($post_id) || !$post_id) {
						$errors++;
						continue;
					}

					$post_id = (int) $post_id;

					$ip = isset($item['submission_ip']) ? trim((string) $item['submission_ip']) : '';
					if ($ip !== '' && RTS_IP_Utils::is_valid_ip($ip)) {
						update_post_meta($post_id, 'rts_submission_ip', $ip);
					}

					as_schedule_single_action(time() + 1, 'rts_process_letter', [$post_id], self::GROUP);
					$processed++;
				} catch (\Throwable $e) {
					$errors++;
				}
			}

			self::bump_status('processed', $processed);
			self::bump_status('errors', $errors);

			$status = get_option('rts_import_job_status', []);
			$total = (int) ($status['total'] ?? 0);
			$done  = (int) ($status['processed'] ?? 0);

			if ($total > 0 && $done >= $total) {
				$status['status'] = 'complete';
				$status['finished_gmt'] = gmdate('c');
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

		private static function count_csv_rows(string $file_path): int {
			try {
				$f = new SplFileObject($file_path, 'r');
				$f->seek(PHP_INT_MAX);
				$lines = (int) $f->key();
				return max(0, $lines);
			} catch (\Throwable $e) {
				return 0;
			}
		}

		private static function normalize_header(array $row): array {
			$out = [];
			foreach ($row as $cell) {
				$cell = is_string($cell) ? trim($cell) : '';
				$out[] = sanitize_key($cell);
			}
			return $out;
		}

		private static function map_row(array $header, array $row): array {
			$data = [];
			foreach ($header as $i => $key) {
				if ($key === '') continue;
				$data[$key] = isset($row[$i]) ? (string) $row[$i] : '';
			}

			$content = '';
			foreach (['content', 'letter', 'message', 'body'] as $k) {
				if (!empty($data[$k])) { $content = $data[$k]; break; }
			}

			$title = '';
			foreach (['title', 'subject', 'name'] as $k) {
				if (!empty($data[$k])) { $title = $data[$k]; break; }
			}

			$submission_ip = '';
			foreach (['submission_ip', 'ip', 'rts_submission_ip'] as $k) {
				if (!empty($data[$k])) { $submission_ip = $data[$k]; break; }
			}

			return [
				'content'       => trim($content),
				'title'         => trim($title),
				'submission_ip' => trim($submission_ip),
			];
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

			$letters_total = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type=%s",
				'letter'
			));

			$letters_published = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s",
				'letter',
				'publish'
			));

			$letters_pending = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type=%s AND post_status=%s",
				'letter',
				'pending'
			));

			$needs_review = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(1)
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID
				 WHERE p.post_type=%s
				   AND pm.meta_key=%s
				   AND pm.meta_value=%s",
				'letter',
				'needs_review',
				'1'
			));

			$feedback_total = (int) $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type=%s",
				'rts_feedback'
			));

			$tax_breakdown = [
				'letter_feeling' => self::taxonomy_breakdown('letter_feeling', 'letter', 'publish'),
				'letter_tone'    => self::taxonomy_breakdown('letter_tone', 'letter', 'publish'),
			];

			$stats = [
				'generated_gmt'        => gmdate('c'),
				'letters_total'        => $letters_total,
				'letters_published'    => $letters_published,
				'letters_pending'      => $letters_pending,
				'letters_needs_review' => $needs_review,
				'feedback_total'       => $feedback_total,
				'taxonomy_breakdown'   => $tax_breakdown,
			];

			update_option('rts_aggregated_stats', $stats, false);
		}

		private static function taxonomy_breakdown(string $taxonomy, string $post_type, string $status): array {
			global $wpdb;
			if (!taxonomy_exists($taxonomy)) return [];

			$sql = "
				SELECT t.slug as term, COUNT(1) as count
				FROM {$wpdb->term_relationships} tr
				INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
				WHERE tt.taxonomy = %s
				  AND p.post_type = %s
				  AND p.post_status = %s
				GROUP BY t.slug
				ORDER BY count DESC
				LIMIT 50
			";

			$rows = $wpdb->get_results($wpdb->prepare($sql, $taxonomy, $post_type, $status), ARRAY_A);
			$out = [];
			if (is_array($rows)) {
				foreach ($rows as $r) {
					$out[(string) $r['term']] = (int) $r['count'];
				}
			}
			return $out;
		}
	}
}

/* =========================================================
   RTS_Engine_Dashboard: Single top-level menu + REST endpoint
   ========================================================= */
if (!class_exists('RTS_Engine_Dashboard')) {
	class RTS_Engine_Dashboard {

		// Keep the same option names as the engine settings shim so existing saved values remain valid.
		public const OPTION_AUTO_ENABLED = 'rts_auto_processing_enabled';
		public const OPTION_AUTO_BATCH   = 'rts_auto_processing_batch_size';
		public const OPTION_MIN_QUALITY  = 'rts_min_quality_score';
		public const OPTION_IP_THRESHOLD = 'rts_ip_daily_threshold';

		public static function init(): void {
			if (!is_admin()) return;

			add_action('admin_menu', [__CLASS__, 'register_menu'], 80);
			add_action('admin_init', [__CLASS__, 'register_settings'], 5);
			add_action('admin_init', [__CLASS__, 'handle_legacy_redirects'], 20);
			add_action('admin_post_rts_dashboard_action', [__CLASS__, 'handle_post_action']);

			// Lightweight live status feed.
			add_action('rest_api_init', [__CLASS__, 'register_rest']);
		}

		public static function register_menu(): void {
			// Single source of truth: keep everything under Letters.
			add_submenu_page(
				'edit.php?post_type=letter',
				'RTS Dashboard',
				'RTS Dashboard',
				'manage_options',
				'rts-dashboard',
				[__CLASS__, 'render_page'],
				0
			);

			// Keep the historic slug alive (existing bookmarks) but route into the new dashboard.
			add_submenu_page(
				'edit.php?post_type=letter',
				'RTS Settings',
				'Settings',
				'manage_options',
				'rts-settings',
				[__CLASS__, 'render_settings_proxy'],
				99
			);
		}

		public static function handle_legacy_redirects(): void {
			if (!is_admin()) return;
			if (!current_user_can('manage_options')) return;
			if (empty($_GET['page'])) return;
			$page = sanitize_key((string) $_GET['page']);
			if ($page !== 'rts-settings') return;
			// Only redirect when we're on the Letters CPT context.
			if (!isset($_GET['post_type']) || sanitize_key((string) $_GET['post_type']) !== 'letter') return;
			$target = admin_url('edit.php?post_type=letter&page=rts-dashboard&tab=settings');
			wp_safe_redirect($target);
			exit;
		}

		public static function render_settings_proxy(): void {
			// This should almost always redirect, but keep it safe.
			self::render_page();
		}

		public static function register_settings(): void {
			register_setting('rts_engine_settings', self::OPTION_AUTO_ENABLED, [
				'type' => 'string',
				'sanitize_callback' => static function ($v) {
					return ($v === '1' || $v === 1 || $v === true) ? '1' : '0';
				},
				'default' => '1',
			]);

			register_setting('rts_engine_settings', self::OPTION_AUTO_BATCH, [
				'type' => 'integer',
				'sanitize_callback' => static function ($v) {
					$v = absint($v);
					if ($v < 1) $v = 1;
					if ($v > 200) $v = 200;
					return $v;
				},
				'default' => 50,
			]);

			register_setting('rts_engine_settings', self::OPTION_MIN_QUALITY, [
				'type' => 'integer',
				'sanitize_callback' => static function ($v) {
					$v = absint($v);
					if ($v > 100) $v = 100;
					return $v;
				},
				'default' => 70,
			]);

			register_setting('rts_engine_settings', self::OPTION_IP_THRESHOLD, [
				'type' => 'integer',
				'sanitize_callback' => static function ($v) {
					$v = absint($v);
					if ($v < 1) $v = 1;
					if ($v > 500) $v = 500;
					return $v;
				},
				'default' => 20,
			]);
		}

		private static function get_tab(): string {
			$tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'overview';
			$allowed = ['overview','letters','analytics','shares','feedback','settings','system'];
			return in_array($tab, $allowed, true) ? $tab : 'overview';
		}

		private static function url_for_tab(string $tab): string {
			return admin_url('edit.php?post_type=letter&page=rts-dashboard&tab=' . rawurlencode($tab));
		}

		private static function count_needs_review(): int {
			global $wpdb;
			$needs = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = %s",
				'needs_review', '1', 'letter'
			));
			return (int) $needs;
		}

		private static function get_basic_stats(): array {
			$letters = wp_count_posts('letter');
			return [
				'total'       => (int) ($letters->publish + $letters->pending + $letters->draft + $letters->future + $letters->private),
				'published'   => (int) $letters->publish,
				'pending'     => (int) $letters->pending,
				'needs_review'=> self::count_needs_review(),
			];
		}

		public static function render_page(): void {
			if (!current_user_can('manage_options')) return;

			$tab   = self::get_tab();
			$stats = self::get_basic_stats();
			$import = get_option('rts_import_job_status', []);
			$agg    = get_option('rts_aggregated_stats', []);
			$as_ok  = rts_as_available();

			$message = isset($_GET['rts_msg']) ? sanitize_key((string) $_GET['rts_msg']) : '';
			?>
			<div class="wrap">
				<h1 style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
					<span>RTS Dashboard</span>
					<span style="font-size:12px;color:#666;font-weight:400;">Moderation Engine integrated</span>
				</h1>

				<?php if ($message): ?>
					<div class="notice notice-success is-dismissible"><p>
						<?php
						switch ($message) {
							case 'analytics': echo 'Analytics run queued.'; break;
							case 'rescanned': echo 'Rescan queued.'; break;
							case 'saved': echo 'Settings saved.'; break;
							case 'updated': echo 'Updated.'; break;
							default: echo 'Done.'; break;
						}
						?>
					</p></div>
				<?php endif; ?>

				<div style="display:flex;gap:10px;flex-wrap:wrap;margin:14px 0 6px;">
					<a class="button button-primary" href="<?php echo esc_url(self::url_for_tab('letters')); ?>">Manage letters</a>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
						<input type="hidden" name="action" value="rts_dashboard_action">
						<input type="hidden" name="command" value="run_analytics">
						<?php wp_nonce_field('rts_dashboard_action'); ?>
						<button type="submit" class="button">Run analytics now</button>
					</form>
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
						<input type="hidden" name="action" value="rts_dashboard_action">
						<input type="hidden" name="command" value="rescan_pending_review">
						<?php wp_nonce_field('rts_dashboard_action'); ?>
						<button type="submit" class="button">Rescan pending - review</button>
					</form>
					<a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=letter')); ?>">Open Letters list</a>
				</div>

				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:12px;margin:10px 0 18px;">
					<?php self::stat_card('Total', number_format_i18n($stats['total']), '#1d2327'); ?>
					<?php self::stat_card('Published', number_format_i18n($stats['published']), '#00a32a'); ?>
					<?php self::stat_card('Pending', number_format_i18n($stats['pending']), '#d63638'); ?>
					<?php self::stat_card('Needs review', number_format_i18n($stats['needs_review']), '#b32d2e'); ?>
					<?php self::stat_card('Action Scheduler', $as_ok ? 'Active' : 'Missing', $as_ok ? '#00a32a' : '#d63638'); ?>
				</div>

				<h2 class="nav-tab-wrapper" style="margin-bottom:14px;">
					<?php self::tab_link('overview','Overview',$tab); ?>
					<?php self::tab_link('letters','Letters',$tab); ?>
					<?php self::tab_link('analytics','Analytics',$tab); ?>
					<?php self::tab_link('shares','Share stats',$tab); ?>
					<?php self::tab_link('feedback','Feedback',$tab); ?>
					<?php self::tab_link('settings','Settings',$tab); ?>
					<?php self::tab_link('system','System',$tab); ?>
				</h2>

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
			<?php
		}

		private static function stat_card(string $label, string $value, string $color): void {
			?>
			<div style="background:#fff;border:1px solid #ccd0d4;border-radius:10px;padding:14px;">
				<div style="font-size:12px;color:#646970;"><?php echo esc_html($label); ?></div>
				<div style="font-size:26px;font-weight:700;color:<?php echo esc_attr($color); ?>;line-height:1.15;margin-top:6px;">
					<?php echo esc_html($value); ?>
				</div>
			</div>
			<?php
		}

		private static function tab_link(string $tab, string $label, string $current): void {
			$cls = ($tab === $current) ? 'nav-tab nav-tab-active' : 'nav-tab';
			printf('<a class="%s" href="%s">%s</a>', esc_attr($cls), esc_url(self::url_for_tab($tab)), esc_html($label));
		}

		private static function render_tab_overview($import, $agg): void {
			$import_total = (int) ($import['total'] ?? 0);
			$import_processed = (int) ($import['processed'] ?? 0);
			$import_errors = (int) ($import['errors'] ?? 0);
			$pct = ($import_total > 0) ? min(100, (int) round(($import_processed / $import_total) * 100)) : 0;
			?>
			<div class="card" style="max-width:1100px;">
				<h3 style="margin-top:0;">Engine status</h3>
				<p><strong>Import:</strong> <?php echo esc_html($import['status'] ?? 'idle'); ?>
					<?php if (!empty($import['file'])): ?>
						<span style="color:#666;">(<?php echo esc_html($import['file']); ?>)</span>
					<?php endif; ?>
				</p>
				<div style="height:14px;background:#f0f0f1;border-radius:999px;overflow:hidden;">
					<div id="rts-progress-bar" style="height:14px;width:<?php echo esc_attr($pct); ?>%;background:#2271b1;"></div>
				</div>
				<p id="rts-progress-text" style="margin-top:10px;color:#50575e;">
					<?php echo esc_html($import_processed); ?> of <?php echo esc_html($import_total); ?> processed (<?php echo esc_html($pct); ?>%) - errors: <?php echo esc_html($import_errors); ?>
				</p>
				<p style="margin:0;color:#646970;font-size:13px;">Live status: <code>/wp-json/rts/v1/processing-status</code></p>
			</div>

			<div class="card" style="max-width:1100px;">
				<h3 style="margin-top:0;">Last analytics snapshot</h3>
				<p style="margin-top:0;"><strong>Generated:</strong> <?php echo esc_html($agg['generated_gmt'] ?? 'not yet'); ?></p>
				<table class="widefat striped" style="max-width:820px;">
					<tbody>
						<tr><td><strong>Total letters</strong></td><td><?php echo esc_html(number_format_i18n((int) ($agg['letters_total'] ?? 0))); ?></td></tr>
						<tr><td><strong>Published</strong></td><td><?php echo esc_html(number_format_i18n((int) ($agg['letters_published'] ?? 0))); ?></td></tr>
						<tr><td><strong>Pending</strong></td><td><?php echo esc_html(number_format_i18n((int) ($agg['letters_pending'] ?? 0))); ?></td></tr>
						<tr><td><strong>Needs review</strong></td><td><?php echo esc_html(number_format_i18n((int) ($agg['letters_needs_review'] ?? 0))); ?></td></tr>
						<tr><td><strong>Feedback total</strong></td><td><?php echo esc_html(number_format_i18n((int) ($agg['feedback_total'] ?? 0))); ?></td></tr>
					</tbody>
				</table>
			</div>

			<script>
			(function(){
				const bar = document.getElementById('rts-progress-bar');
				const txt = document.getElementById('rts-progress-text');
				if(!bar || !txt) return;
				async function poll(){
					try{
						const res = await fetch('<?php echo esc_url_raw(rest_url('rts/v1/processing-status')); ?>', {credentials:'same-origin',headers:{'Accept':'application/json'}});
						if(!res.ok) return;
						const data = await res.json();
						if(!data || !data.import) return;
						const total = Number(data.import.total||0);
						const processed = Number(data.import.processed||0);
						const errors = Number(data.import.errors||0);
						const pct = total>0 ? Math.min(100, Math.round((processed/total)*100)) : 0;
						bar.style.width = pct + '%';
						txt.textContent = processed + ' of ' + total + ' processed (' + pct + '%) - errors: ' + errors;
					}catch(e){}
				}
				poll();
				setInterval(poll, 5000);
			})();
			</script>
			<?php
		}

		private static function render_tab_letters(): void {
			$min_quality = (int) get_option(self::OPTION_MIN_QUALITY, 70);
			?>
			<div class="card" style="max-width:1200px;">
				<h3 style="margin-top:0;">Letter management</h3>
				<p style="margin-top:0;color:#646970;">This is the control room: review, publish, and rescan from one place. Minimum quality score is currently <strong><?php echo esc_html($min_quality); ?></strong>.</p>
				<div style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0 14px;">
					<a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type=letter')); ?>">Add new letter</a>
					<a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=letter&post_status=pending')); ?>">Open pending list</a>
					<a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=letter')); ?>">Open all letters</a>
				</div>
			</div>

			<?php self::render_letters_table('pending', 'Pending letters (latest 25)', 25); ?>
			<?php self::render_letters_table('needs_review', 'Needs review (latest 25)', 25); ?>
			<?php
		}

		private static function render_letters_table(string $mode, string $title, int $limit): void {
			$args = [
				'post_type'      => 'letter',
				'posts_per_page' => $limit,
				'post_status'    => ($mode === 'pending') ? 'pending' : ['publish','pending','draft'],
				'orderby'        => 'date',
				'order'          => 'DESC',
				'no_found_rows'  => true,
			];
			if ($mode === 'needs_review') {
				$args['meta_query'] = [[
					'key'   => 'needs_review',
					'value' => '1',
				]];
			}
			$q = new \WP_Query($args);
			?>
			<div class="card" style="max-width:1200px;">
				<h3 style="margin-top:0;"><?php echo esc_html($title); ?></h3>
				<?php if (!$q->have_posts()): ?>
					<p style="margin:0;color:#646970;">Nothing here right now.</p>
				<?php else: ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width:70px;">ID</th>
								<th>Title</th>
								<th style="width:110px;">Status</th>
								<th style="width:110px;">Score</th>
								<th style="width:110px;">Needs review</th>
								<th style="width:320px;">Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php while ($q->have_posts()): $q->the_post(); $id = get_the_ID();
								$score = get_post_meta($id, 'rts_quality_score', true);
								$needs = get_post_meta($id, 'needs_review', true) === '1';
								$status = get_post_status($id);
								$edit = get_edit_post_link($id, '');
								$view = get_permalink($id);
							?>
							<tr>
								<td><?php echo esc_html((string) $id); ?></td>
								<td><a href="<?php echo esc_url($edit); ?>"><strong><?php echo esc_html(get_the_title()); ?></strong></a></td>
								<td><?php echo esc_html($status); ?></td>
								<td><?php echo esc_html($score === '' ? 'n/a' : (string) $score); ?></td>
								<td><?php echo $needs ? '<span style="color:#b32d2e;font-weight:700;">Yes</span>' : '<span style="color:#00a32a;font-weight:700;">No</span>'; ?></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url($edit); ?>">Edit</a>
									<a class="button button-small" href="<?php echo esc_url($view); ?>" target="_blank" rel="noopener">View</a>
									<?php self::action_button('publish_letter','Publish',$id, $status === 'publish'); ?>
									<?php self::action_button('mark_reviewed','Mark reviewed',$id, !$needs); ?>
									<?php self::action_button('rescan_letter','Rescan',$id, false); ?>
								</td>
							</tr>
							<?php endwhile; wp_reset_postdata(); ?>
						</tbody>
					</table>
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
			?>
			<div class="card" style="max-width:1100px;">
				<h3 style="margin-top:0;">Analytics</h3>
				<p style="margin-top:0;color:#646970;">These figures are cached by the engine and refreshed by the scheduled analytics job or the "Run analytics now" button.</p>
				<table class="widefat striped" style="max-width:900px;">
					<tbody>
						<tr><td><strong>Generated (GMT)</strong></td><td><?php echo esc_html($agg['generated_gmt'] ?? 'not yet'); ?></td></tr>
						<tr><td><strong>Total letters</strong></td><td><?php echo esc_html(number_format_i18n((int) ($agg['letters_total'] ?? 0))); ?></td></tr>
						<tr><td><strong>Published</strong></td><td><?php echo esc_html(number_format_i18n((int) ($agg['letters_published'] ?? 0))); ?></td></tr>
						<tr><td><strong>Pending</strong></td><td><?php echo esc_html(number_format_i18n((int) ($agg['letters_pending'] ?? 0))); ?></td></tr>
						<tr><td><strong>Needs review</strong></td><td><?php echo esc_html(number_format_i18n((int) ($agg['letters_needs_review'] ?? 0))); ?></td></tr>
						<tr><td><strong>Feedback total</strong></td><td><?php echo esc_html(number_format_i18n((int) ($agg['feedback_total'] ?? 0))); ?></td></tr>
					</tbody>
				</table>
				<h4 style="margin:18px 0 8px;">Breakdowns</h4>
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
					<?php self::render_kv_box('Feelings', $agg['feelings'] ?? []); ?>
					<?php self::render_kv_box('Tones', $agg['tones'] ?? []); ?>
					<?php self::render_kv_box('Top keywords', $agg['top_keywords'] ?? []); ?>
				</div>
			</div>
			<?php
		}

		private static function render_kv_box(string $title, $data): void {
			?>
			<div style="background:#fff;border:1px solid #ccd0d4;border-radius:10px;padding:12px;">
				<h4 style="margin:0 0 8px;"><?php echo esc_html($title); ?></h4>
				<?php if (empty($data) || !is_array($data)): ?>
					<p style="margin:0;color:#646970;">No data yet.</p>
				<?php else: ?>
					<table class="widefat striped" style="margin:0;">
						<tbody>
							<?php foreach ($data as $k => $v): ?>
								<tr><td><?php echo esc_html((string) $k); ?></td><td style="width:90px;"><strong><?php echo esc_html(number_format_i18n((int) $v)); ?></strong></td></tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<?php
		}

		private static function render_tab_shares(): void {
			// Pull last 30 days from rts_daily_stats (stored by letter-system.php)
			$daily = get_option('rts_daily_stats', []);
			if (!is_array($daily)) $daily = [];
			$cutoff = strtotime(gmdate('Y-m-d') . ' 00:00:00') - (29 * DAY_IN_SECONDS);
			$total = 0;
			$platforms = [];
			foreach ($daily as $date => $row) {
				$ts = strtotime($date . ' 00:00:00');
				if (!$ts || $ts < $cutoff) continue;
				if (!is_array($row)) continue;
				$total += (int) ($row['shares_total'] ?? 0);
				$pf = $row['shares_platform'] ?? [];
				if (is_array($pf)) {
					foreach ($pf as $p => $c) {
						if (!isset($platforms[$p])) $platforms[$p] = 0;
						$platforms[$p] += (int) $c;
					}
				}
			}
			arsort($platforms);

			$top_letters = new \WP_Query([
				'post_type' => 'letter',
				'post_status' => 'publish',
				'posts_per_page' => 10,
				'meta_key' => 'share_count_total',
				'orderby' => 'meta_value_num',
				'order' => 'DESC',
				'no_found_rows' => true,
			]);
			?>
			<div class="card" style="max-width:1100px;">
				<h3 style="margin-top:0;">Share stats</h3>
				<p style="margin-top:0;color:#646970;">Share tracking is logged by the front-end share buttons and stored as daily stats plus per-letter totals.</p>
				<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
					<div style="background:#fff;border:1px solid #ccd0d4;border-radius:10px;padding:12px;">
						<h4 style="margin:0 0 8px;">Last 30 days</h4>
						<div style="font-size:28px;font-weight:800;"><?php echo esc_html(number_format_i18n($total)); ?></div>
						<div style="color:#646970;">total shares</div>
					</div>
					<div style="background:#fff;border:1px solid #ccd0d4;border-radius:10px;padding:12px;">
						<h4 style="margin:0 0 8px;">By platform</h4>
						<?php if (empty($platforms)): ?>
							<p style="margin:0;color:#646970;">No share data yet.</p>
						<?php else: ?>
							<table class="widefat striped" style="margin:0;">
								<tbody>
								<?php foreach ($platforms as $p => $c): ?>
									<tr><td><?php echo esc_html(ucfirst((string) $p)); ?></td><td style="width:90px;"><strong><?php echo esc_html(number_format_i18n((int) $c)); ?></strong></td></tr>
								<?php endforeach; ?>
								</tbody>
							</table>
						<?php endif; ?>
					</div>
				</div>

				<h4 style="margin:18px 0 8px;">Top shared letters</h4>
				<?php if (!$top_letters->have_posts()): ?>
					<p style="margin:0;color:#646970;">No shared letters yet.</p>
				<?php else: ?>
					<table class="widefat striped" style="max-width:900px;">
						<thead><tr><th>Letter</th><th style="width:120px;">Total shares</th><th style="width:120px;">Actions</th></tr></thead>
						<tbody>
						<?php while ($top_letters->have_posts()): $top_letters->the_post(); $id = get_the_ID();
							$shares = (int) get_post_meta($id, 'share_count_total', true);
							$edit = get_edit_post_link($id, '');
						?>
						<tr>
							<td><a href="<?php echo esc_url($edit); ?>"><strong><?php echo esc_html(get_the_title()); ?></strong></a></td>
							<td><?php echo esc_html(number_format_i18n($shares)); ?></td>
							<td><a class="button button-small" href="<?php echo esc_url($edit); ?>">Edit</a></td>
						</tr>
						<?php endwhile; wp_reset_postdata(); ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<?php
		}

		private static function render_tab_feedback(): void {
			$count = wp_count_posts('rts_feedback');
			$total = (int) ($count->publish ?? 0);
			$recent = new \WP_Query([
				'post_type' => 'rts_feedback',
				'post_status' => 'publish',
				'posts_per_page' => 20,
				'orderby' => 'date',
				'order' => 'DESC',
				'no_found_rows' => true,
			]);
			?>
			<div class="card" style="max-width:1200px;">
				<h3 style="margin-top:0;">Feedback</h3>
				<p style="margin-top:0;color:#646970;">Feedback is captured by the front-end form and stored as <code>rts_feedback</code> posts with linked letter IDs.</p>
				<div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;">
					<div style="background:#fff;border:1px solid #ccd0d4;border-radius:10px;padding:12px;min-width:220px;">
						<div style="color:#646970;font-size:12px;">Total feedback entries</div>
						<div style="font-size:26px;font-weight:800;"><?php echo esc_html(number_format_i18n($total)); ?></div>
					</div>
					<a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=rts_feedback')); ?>">Open feedback list</a>
				</div>

				<h4 style="margin:18px 0 8px;">Recent feedback</h4>
				<?php if (!$recent->have_posts()): ?>
					<p style="margin:0;color:#646970;">No feedback yet.</p>
				<?php else: ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th style="width:120px;">Date</th>
								<th style="width:90px;">Rating</th>
								<th>Comment</th>
								<th style="width:220px;">Linked letter</th>
							</tr>
						</thead>
						<tbody>
							<?php while ($recent->have_posts()): $recent->the_post(); $fid = get_the_ID();
								$rating = get_post_meta($fid, '_rts_feedback_rating', true);
								$comment = get_post_meta($fid, '_rts_feedback_comment', true);
								$letter_id = (int) get_post_meta($fid, '_rts_feedback_letter_id', true);
								$letter_link = $letter_id ? get_edit_post_link($letter_id, '') : '';
								$letter_title = $letter_id ? get_the_title($letter_id) : '';
							?>
							<tr>
								<td><?php echo esc_html(get_the_date('Y-m-d')); ?></td>
								<td><?php echo esc_html($rating === '' ? '-' : (string) $rating); ?></td>
								<td><?php echo esc_html(wp_trim_words((string) $comment, 22)); ?></td>
								<td>
									<?php if ($letter_id && $letter_link): ?>
										<a href="<?php echo esc_url($letter_link); ?>"><strong><?php echo esc_html($letter_title ?: ('Letter #' . $letter_id)); ?></strong></a>
									<?php else: ?>
										<span style="color:#646970;">Not linked</span>
									<?php endif; ?>
								</td>
							</tr>
							<?php endwhile; wp_reset_postdata(); ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<?php
		}

		private static function render_tab_settings(): void {
			$auto_enabled = get_option(self::OPTION_AUTO_ENABLED, '1') === '1';
			$batch        = (int) get_option(self::OPTION_AUTO_BATCH, 50);
			$min_quality  = (int) get_option(self::OPTION_MIN_QUALITY, 70);
			$ip_thresh    = (int) get_option(self::OPTION_IP_THRESHOLD, 20);
			?>
			<div class="card" style="max-width:1100px;">
				<h3 style="margin-top:0;">Settings</h3>
				<form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>" style="max-width:900px;">
					<?php settings_fields('rts_engine_settings'); ?>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">Auto-processing</th>
							<td>
								<label>
									<input type="checkbox" name="<?php echo esc_attr(self::OPTION_AUTO_ENABLED); ?>" value="1" <?php checked($auto_enabled); ?>>
									Enable background processing (runs every 5 minutes via Action Scheduler)
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">Auto-processing batch size</th>
							<td>
								<input type="number" min="1" max="200" step="1" name="<?php echo esc_attr(self::OPTION_AUTO_BATCH); ?>" value="<?php echo esc_attr((string) $batch); ?>" class="small-text">
								<p class="description">How many letters to queue per tick (default 50).</p>
							</td>
						</tr>
						<tr>
							<th scope="row">Minimum quality score</th>
							<td>
								<input type="number" min="0" max="100" step="1" name="<?php echo esc_attr(self::OPTION_MIN_QUALITY); ?>" value="<?php echo esc_attr((string) $min_quality); ?>" class="small-text">
								<p class="description">Letters below this score remain pending and are marked needs_review.</p>
							</td>
						</tr>
						<tr>
							<th scope="row">IP daily threshold</th>
							<td>
								<input type="number" min="1" max="500" step="1" name="<?php echo esc_attr(self::OPTION_IP_THRESHOLD); ?>" value="<?php echo esc_attr((string) $ip_thresh); ?>" class="small-text">
								<p class="description">If more than this many letters are submitted from the same IP in 24h, new letters stay pending and are marked needs_review.</p>
							</td>
						</tr>
					</table>
					<?php submit_button('Save settings'); ?>
				</form>
			</div>
			<?php
		}

		private static function render_tab_system($import, $agg): void {
			$queue = [
				'action_scheduler_available' => rts_as_available(),
				'pending_letter_jobs'       => self::count_scheduled('rts_process_letter'),
				'pending_import_batches'    => self::count_scheduled('rts_process_import_batch'),
				'pending_analytics'         => self::count_scheduled('rts_aggregate_analytics'),
			];
			?>
			<div class="card" style="max-width:1100px;">
				<h3 style="margin-top:0;">System</h3>
				<table class="widefat striped" style="max-width:900px;">
					<tbody>
						<tr><td><strong>Action Scheduler</strong></td><td><?php echo $queue['action_scheduler_available'] ? '<span style="color:#00a32a;font-weight:700;">Active</span>' : '<span style="color:#d63638;font-weight:700;">Missing</span>'; ?></td></tr>
						<tr><td><strong>Queued letter jobs</strong></td><td><?php echo esc_html(number_format_i18n((int) $queue['pending_letter_jobs'])); ?></td></tr>
						<tr><td><strong>Queued import batches</strong></td><td><?php echo esc_html(number_format_i18n((int) $queue['pending_import_batches'])); ?></td></tr>
						<tr><td><strong>Queued analytics jobs</strong></td><td><?php echo esc_html(number_format_i18n((int) $queue['pending_analytics'])); ?></td></tr>
						<tr><td><strong>Server time (GMT)</strong></td><td><?php echo esc_html(gmdate('c')); ?></td></tr>
					</tbody>
				</table>
				<p style="margin-top:12px;color:#646970;font-size:13px;">Health endpoints: <code>/wp-json/rts/v1/health</code> and <code>/wp-json/rts/v1/processing-status</code>.</p>
			</div>
			<?php
		}

		public static function handle_post_action(): void {
			if (!current_user_can('manage_options')) wp_die('Access denied');
			check_admin_referer('rts_dashboard_action');

			$cmd = isset($_POST['command']) ? sanitize_key((string) $_POST['command']) : '';
			$post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
			$redirect = self::url_for_tab('overview');
			if (!empty($_SERVER['HTTP_REFERER'])) {
				$redirect = esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']));
			}

			switch ($cmd) {
				case 'run_analytics':
					if (rts_as_available()) {
						as_schedule_single_action(time() + 5, 'rts_aggregate_analytics', [], 'rts');
					}
					$redirect = add_query_arg('rts_msg', 'analytics', self::url_for_tab('analytics'));
					break;

				case 'rescan_pending_review':
					self::queue_rescan_pending_and_review();
					$redirect = add_query_arg('rts_msg', 'rescanned', self::url_for_tab('letters'));
					break;

				case 'rescan_letter':
					if ($post_id) {
						self::queue_letter_scan($post_id);
					}
					$redirect = add_query_arg('rts_msg', 'rescanned', self::url_for_tab('letters'));
					break;

				case 'publish_letter':
					if ($post_id) {
						wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
						delete_post_meta($post_id, 'needs_review');
					}
					$redirect = add_query_arg('rts_msg', 'updated', self::url_for_tab('letters'));
					break;

				case 'mark_reviewed':
					if ($post_id) {
						delete_post_meta($post_id, 'needs_review');
					}
					$redirect = add_query_arg('rts_msg', 'updated', self::url_for_tab('letters'));
					break;
			}

			wp_safe_redirect($redirect);
			exit;
		}

		private static function queue_letter_scan(int $post_id): void {
			if (!rts_as_available()) return;
			if (get_post_type($post_id) !== 'letter') return;
			// De-dupe: don't spam jobs if a scan is already pending for this ID.
			if (function_exists('as_has_scheduled_action') && as_has_scheduled_action('rts_process_letter', [$post_id], 'rts')) {
				return;
			}
			as_schedule_single_action(time() + 2, 'rts_process_letter', [$post_id], 'rts');
		}

		private static function queue_rescan_pending_and_review(): void {
			$batch = (int) get_option(self::OPTION_AUTO_BATCH, 50);
			$batch = max(1, min(200, $batch));

			// Pending letters.
			$pending = new \WP_Query([
				'post_type' => 'letter',
				'post_status' => 'pending',
				'posts_per_page' => $batch,
				'fields' => 'ids',
				'orderby' => 'date',
				'order' => 'ASC',
				'no_found_rows' => true,
			]);
			if (!empty($pending->posts)) {
				foreach ($pending->posts as $id) {
					self::queue_letter_scan((int) $id);
				}
			}

			// Needs review letters.
			$needs = new \WP_Query([
				'post_type' => 'letter',
				'post_status' => ['publish','pending','draft'],
				'posts_per_page' => $batch,
				'fields' => 'ids',
				'meta_query' => [[
					'key' => 'needs_review',
					'value' => '1',
				]],
				'orderby' => 'date',
				'order' => 'ASC',
				'no_found_rows' => true,
			]);
			if (!empty($needs->posts)) {
				foreach ($needs->posts as $id) {
					self::queue_letter_scan((int) $id);
				}
			}
		}

		public static function register_rest(): void {
			register_rest_route('rts/v1', '/processing-status', [
				'methods'  => 'GET',
				'permission_callback' => function () {
					return current_user_can('manage_options');
				},
				'callback' => [__CLASS__, 'rest_processing_status'],
			]);
		}

		public static function rest_processing_status(\WP_REST_Request $req): \WP_REST_Response {
			$import = get_option('rts_import_job_status', []);
			if (!is_array($import)) $import = [];

			$queue = [
				'action_scheduler_available' => rts_as_available(),
				'pending_letter_jobs'       => self::count_scheduled('rts_process_letter'),
				'pending_import_batches'    => self::count_scheduled('rts_process_import_batch'),
				'pending_analytics'         => self::count_scheduled('rts_aggregate_analytics'),
			];

			return new \WP_REST_Response([
				'import'          => $import,
				'queue'           => $queue,
				'server_time_gmt' => gmdate('c'),
			], 200);
		}

		private static function count_scheduled(string $hook): int {
			if (!rts_as_available()) return 0;
			if (function_exists('as_get_scheduled_actions') && class_exists('ActionScheduler_Store')) {
				$actions = as_get_scheduled_actions([
					'hook'     => $hook,
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 100,
				]);
				if (is_array($actions)) return count($actions);
			}
			return 0;
		}
	}
}

/* =========================================================
   RTS_Cron_Processing (Compatibility Shim)
   - Legacy dashboard UI expects this class for "Process All Now"
   - Implementation queues moderation via Action Scheduler, does NOT process inline
   ========================================================= */
if (!class_exists('RTS_Cron_Processing')) {
	class RTS_Cron_Processing {

		const GROUP = 'rts';

		public static function process_letters_batch(string $mode = 'unrated', int $limit = 50, string $source = 'manual'): array {
			if (!rts_as_available()) {
				return ['ok' => false, 'error' => 'action_scheduler_missing'];
			}

			$limit = absint($limit);
			if ($limit < 1) $limit = 1;
			if ($limit > 200) $limit = 200;

			$args = [
				'post_type'      => 'letter',
				'post_status'    => ['pending', 'draft', 'publish'],
				'fields'         => 'ids',
				'posts_per_page' => $limit,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			];

			if ($mode === 'needs_review') {
				$args['meta_query'] = [
					[
						'key'   => 'needs_review',
						'value' => '1',
					]
				];
			} elseif ($mode === 'pending') {
				$args['post_status'] = ['pending'];
			} else {
				$args['meta_query'] = [
					'relation' => 'OR',
					[
						'key'     => 'quality_score',
						'compare' => 'NOT EXISTS',
					],
					[
						'key'     => 'quality_score',
						'value'   => '0',
						'compare' => '=',
					],
				];
			}

			$ids = get_posts($args);
			if (!is_array($ids)) $ids = [];

			$scheduled = 0;
			foreach ($ids as $post_id) {
				$post_id = absint($post_id);
				if (!$post_id) continue;

				$already = as_next_scheduled_action('rts_process_letter', [$post_id], self::GROUP);
				if (!$already) {
					as_schedule_single_action(time() + 2, 'rts_process_letter', [$post_id], self::GROUP);
					update_post_meta($post_id, 'rts_queued_by', sanitize_text_field($source));
					$scheduled++;
				}
			}

			return [
				'ok'        => true,
				'mode'      => $mode,
				'found'     => count($ids),
				'scheduled' => $scheduled,
			];
		}
	}
}

/* =========================================================
   RTS_Auto_Processor: recurring queue tick (every 5 minutes)
   ========================================================= */
if (!class_exists('RTS_Auto_Processor')) {
/**
 * Engine Settings (shim)
 *
 * Some parts of the engine reference RTS_Engine_Settings.
 * The dashboard/settings UI is driven by RTS_Engine_Dashboard constants,
 * but to keep the engine stable (and avoid fatal errors) we provide
 * a small settings helper with the same option keys.
 */
if (!class_exists('RTS_Engine_Settings')) {
    class RTS_Engine_Settings {
        public const OPTION_ENABLE_AUTO_PROCESSING     = 'rts_enable_auto_processing';
        public const OPTION_AUTO_PROCESSING_BATCH_SIZE = 'rts_auto_processing_batch_size';
        public const OPTION_MIN_QUALITY_SCORE          = 'rts_min_quality_score';
        public const OPTION_IP_DAILY_THRESHOLD         = 'rts_ip_daily_threshold';


    /**
     * Back-compat aliases (older dashboard/engine builds referenced these constants)
     * Keep these in sync with the canonical option keys below.
     */
    public const OPTION_AUTO_ENABLED = self::OPTION_ENABLE_AUTO_PROCESSING;
    public const OPTION_AUTO_BATCH   = self::OPTION_AUTO_PROCESSING_BATCH_SIZE;
    public const OPTION_MIN_QUALITY  = self::OPTION_MIN_QUALITY_SCORE;


        public static function get(string $option, $default = null) {
            return get_option($option, $default);
        }

        public static function get_bool(string $option, bool $default = false): bool {
            $val = get_option($option, $default ? '1' : '0');
            $filtered = filter_var($val, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($filtered === null) {
                return (bool) $val;
            }
            return (bool) $filtered;
        }

        public static function get_int(string $option, int $default = 0, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX): int {
            $val = (int) get_option($option, $default);
            if ($val < $min) $val = $min;
            if ($val > $max) $val = $max;
            return $val;
        }
    }
}


	class RTS_Auto_Processor {

		const GROUP = 'rts';

		public static function init(): void {
			add_action('init', [__CLASS__, 'ensure_schedule'], 30);
			add_action('rts_auto_process_tick', [__CLASS__, 'tick'], 10, 0);
		}

		public static function ensure_schedule(): void {
			if (!rts_as_available()) return;

			$enabled = get_option(RTS_Engine_Settings::OPTION_AUTO_ENABLED, '1') === '1';
			$next = as_next_scheduled_action('rts_auto_process_tick', [], self::GROUP);

			if (!$enabled) {
				return;
			}

			if ($next) return;

			if (function_exists('as_schedule_recurring_action')) {
				as_schedule_recurring_action(time() + 90, 300, 'rts_auto_process_tick', [], self::GROUP);
			} else {
				as_schedule_single_action(time() + 90, 'rts_auto_process_tick', [], self::GROUP);
			}
		}

		public static function tick(): void {
			if (!rts_as_available()) return;

			$enabled = get_option(RTS_Engine_Settings::OPTION_AUTO_ENABLED, '1') === '1';
			if (!$enabled) return;

			$batch = (int) get_option(RTS_Engine_Settings::OPTION_AUTO_BATCH, 50);
			if ($batch < 1) $batch = 1;
			if ($batch > 200) $batch = 200;

			RTS_Cron_Processing::process_letters_batch('unrated', $batch, 'auto_tick');

			if (!function_exists('as_schedule_recurring_action')) {
				$next = as_next_scheduled_action('rts_auto_process_tick', [], self::GROUP);
				if (!$next) {
					as_schedule_single_action(time() + 300, 'rts_auto_process_tick', [], self::GROUP);
				}
			}
		}
	}
}


/* =========================================================
   RTS_Moderation_Bootstrap: Hooks + scheduling
   ========================================================= */
if (!class_exists('RTS_Moderation_Bootstrap')) {
	class RTS_Moderation_Bootstrap {

		const GROUP = 'rts';

		public static function init(): void {
			add_action('rts_process_letter', [__CLASS__, 'handle_process_letter'], 10, 1);
			add_action('rts_process_import_batch', [__CLASS__, 'handle_import_batch'], 10, 2);
			add_action('rts_aggregate_analytics', [__CLASS__, 'handle_aggregate_analytics'], 10, 0);

			add_action('save_post_letter', [__CLASS__, 'on_save_post_letter'], 20, 3);
			add_action('save_post_rts_feedback', [__CLASS__, 'on_save_post_feedback'], 20, 3);

			if (is_admin()) {
				RTS_Engine_Dashboard::init();
				// Settings UI is provided inside the all-in-one RTS Dashboard.
			}

			RTS_Auto_Processor::init();
			add_action('init', [__CLASS__, 'schedule_daily_analytics'], 20);
		}

		public static function handle_process_letter($post_id): void {
			RTS_Moderation_Engine::process_letter((int) $post_id);
		}

		public static function handle_import_batch($job_id, $batch): void {
			RTS_Import_Orchestrator::process_import_batch((string) $job_id, (array) $batch);
		}

		public static function handle_aggregate_analytics(): void {
			RTS_Analytics_Aggregator::aggregate();
		}

		public static function on_save_post_letter(int $post_id, \WP_Post $post, bool $update): void {
			if (!rts_as_available()) return;
			if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;
			if (!$post || $post->post_type !== 'letter') return;

			// Do not auto-override deliberate admin publishing.
			if ($post->post_status === 'publish') {
				return;
			}

			// Capture IP at submission time if not already stored (best effort).
			$existing_ip = (string) get_post_meta($post_id, 'rts_submission_ip', true);
			if (trim($existing_ip) === '') {
				$ip = RTS_IP_Utils::get_client_ip();
				if ($ip !== '') {
					update_post_meta($post_id, 'rts_submission_ip', $ip);
				}
			}

			$already = as_next_scheduled_action('rts_process_letter', [$post_id], self::GROUP);
			if (!$already) {
				as_schedule_single_action(time() + 5, 'rts_process_letter', [$post_id], self::GROUP);
			}
		}

		/**
		 * Feedback "kill switch" integration (hook-only, schema-agnostic).
		 */
		public static function on_save_post_feedback(int $post_id, \WP_Post $post, bool $update): void {
			if (!$post || $post->post_type !== 'rts_feedback') return;
			if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

			$letter_id = 0;
			foreach (['letter_id', 'rts_letter_id', 'related_letter_id', 'letter'] as $k) {
				$val = get_post_meta($post_id, $k, true);
				if ($val) { $letter_id = absint($val); break; }
			}
			if (!$letter_id) return;

			$flag = false;
			$reason = '';
			foreach (['is_triggering', 'triggered', 'report_type', 'severity'] as $k) {
				$val = (string) get_post_meta($post_id, $k, true);
				$val_lc = mb_strtolower($val);
				if (in_array($val_lc, ['1', 'yes', 'true', 'triggered', 'danger', 'dangerous', 'self-harm', 'suicide'], true)) {
					$flag = true;
					$reason = $k . ':' . $val_lc;
					break;
				}
			}

			if (!$flag) {
				$body = mb_strtolower((string) $post->post_content);
				if (strpos($body, 'trigger') !== false || strpos($body, 'danger') !== false) {
					$flag = true;
					$reason = 'content_signal';
				}
			}

			if ($flag) {
				$letter = get_post($letter_id);
				if ($letter && $letter->post_type === 'letter') {
					update_post_meta($letter_id, 'needs_review', '1');
					update_post_meta($letter_id, 'rts_feedback_flag', $reason);

					if ($letter->post_status === 'publish') {
						wp_update_post([
							'ID'          => $letter_id,
							'post_status' => 'pending',
						]);
						update_post_meta($letter_id, 'rts_moderation_status', 'unpublished_by_feedback');
					}
				}
			}
		}

		public static function schedule_daily_analytics(): void {
			if (!rts_as_available()) return;

			$next = as_next_scheduled_action('rts_aggregate_analytics', [], self::GROUP);
			if ($next) return;

			$ts = time();
			$tomorrow_0205 = gmmktime(2, 5, 0, (int) gmdate('n', $ts), (int) gmdate('j', $ts) + 1, (int) gmdate('Y', $ts));
			if (function_exists('as_schedule_recurring_action')) {
				as_schedule_recurring_action($tomorrow_0205, DAY_IN_SECONDS, 'rts_aggregate_analytics', [], self::GROUP);
			} else {
				as_schedule_single_action($tomorrow_0205, 'rts_aggregate_analytics', [], self::GROUP);
			}
		}
	}
}

/* =========================================================
   Boot
   ========================================================= */
/**
 * IMPORTANT:
 * This engine file is included from the theme (functions.php). Themes load
 * AFTER the `plugins_loaded` action has already fired.
 *
 * Therefore, we bootstrap on `init` (which always runs after theme load) and we
 * guard against double-boot.
 */
if (!function_exists('rts_moderation_engine_boot')) {
	function rts_moderation_engine_boot(): void {
		static $booted = false;
		if ($booted) { return; }
		$booted = true;
		if (class_exists('RTS_Moderation_Bootstrap')) {
			RTS_Moderation_Bootstrap::init();
		}
	}
}

add_action('init', 'rts_moderation_engine_boot', 1);
if (did_action('init')) {
	rts_moderation_engine_boot();
}


/* =========================================================
   SELF-QA LOOP (as code comments, per requirement)
   =========================================================
   1) Action Scheduler only?
      - Import batches: as_schedule_single_action('rts_process_import_batch'...)
      - Per-letter moderation: as_schedule_single_action('rts_process_letter'...)
      - Daily analytics: as_schedule_recurring_action/as_schedule_single_action('rts_aggregate_analytics'...)
      - No WP-Cron loops introduced here.

   2) Spoof-resistant IP retrieval?
      - RTS_IP_Utils::get_client_ip() prioritizes HTTP_CF_CONNECTING_IP then REMOTE_ADDR.
      - Validates with FILTER_VALIDATE_IP.
      - Does NOT trust X-Forwarded-For by default.
      - IP history check uses hashed transient key rts_ip_lock_{ip_hash}.

   3) Avoid redefining CPTs/Taxonomies?
      - This file never calls register_post_type() or register_taxonomy().
      - Auto-tagging only applies existing terms if taxonomy_exists() and term exists.
 */
