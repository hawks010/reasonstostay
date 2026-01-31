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
 * - Share Tracking: AJAX endpoint for frontend share buttons.
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

		public static function process_letter(int $post_id): void {
			$post_id = absint($post_id);
			if (!$post_id) return;

			$post = get_post($post_id);
			if (!$post || $post->post_type !== 'letter') return;

			$lock_key = self::LOCK_PREFIX . $post_id;
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

				$results['safety'] = self::safety_scan($post_id);
				$results['ip'] = self::ip_history_check($post_id);
				$results['quality'] = self::quality_scoring($post_id);
				$results['tags'] = self::auto_tagging($post_id);

				update_post_meta($post_id, 'quality_score', (int) $results['quality']['score']);
				update_post_meta($post_id, 'rts_safety_pass', $results['safety']['pass'] ? '1' : '0');
				update_post_meta($post_id, 'rts_ip_pass', $results['ip']['pass'] ? '1' : '0');
				update_post_meta($post_id, 'rts_flagged_keywords', wp_json_encode($results['safety']['flags']));
				update_post_meta($post_id, 'rts_processing_last', gmdate('c'));
				delete_post_meta($post_id, 'rts_system_error');

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

		private static function safety_scan(int $post_id): array {
			$content = (string) get_post_field('post_content', $post_id);
			$content_lc = mb_strtolower($content);
			$patterns = [
				'/\bkill myself\b/i', '/\bsuicide\b/i', '/\bend it all\b/i',
				'/\bno reason to live\b/i', '/\bself[-\s]?harm\b/i',
				'/\boverdose\b/i', '/\bcutting\b/i', '/\bi want to die\b/i',
			];
			$flags = [];
			foreach ($patterns as $pattern) {
				$ok = @preg_match($pattern, $content_lc);
				if ($ok === false) return ['pass' => false, 'flags' => ['regex_error']];
				if ($ok === 1) $flags[] = trim($pattern, '/i');
			}
			return ['pass' => empty($flags), 'flags' => $flags];
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
				update_post_meta($post_id, 'needs_review', '1');
				update_post_meta($post_id, 'rts_ip_lock_hit', '1');
				return ['pass' => false, 'reason' => 'ip_locked'];
			}

			$since_gmt = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
			$sql = "SELECT COUNT(1) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE p.post_type = %s AND p.post_date_gmt >= %s AND pm.meta_key = %s AND pm.meta_value = %s";
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
			if ($content === '') return ['pass' => false, 'score' => 0, 'notes' => ['empty']];
			$len = (int) mb_strlen($content);
			$score = (int) min(100, (int) floor($len / 5));
			$threshold = (int) apply_filters('rts_quality_threshold', (int) get_option(RTS_Engine_Settings::OPTION_MIN_QUALITY_SCORE, 70));
			$pass = ($score >= $threshold);
			$notes = [];
			if ($len < 100) $notes[] = 'short';
			if ($len < 50) $notes[] = 'very_short';
			return ['pass' => $pass, 'score' => $score, 'notes' => $notes];
		}

		private static function auto_tagging(int $post_id): array {
			$content = mb_strtolower((string) get_post_field('post_content', $post_id));
			$applied = [];
			if ($content === '') return ['applied' => $applied];
			$positive_needles = ['hope', 'better', 'tomorrow', 'keep going', 'you can', 'it gets easier', 'you are not alone'];
			$should_tag_hopeful = false;
			foreach ($positive_needles as $needle) {
				if ($needle !== '' && mb_strpos($content, $needle) !== false) {
					$should_tag_hopeful = true; break;
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

			$job_id = 'job_' . gmdate('Ymd_His') . '_' . wp_generate_password(6, false, false);
			$total = self::count_csv_rows($file_path);
			update_option('rts_import_job_status', [
				'job_id' => $job_id, 'total' => $total, 'processed' => 0, 'errors' => 0, 'status' => 'running',
				'started_gmt' => gmdate('c'), 'file' => basename($file_path),
			], false);

			$fh = new SplFileObject($file_path, 'r');
			$fh->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY);
			$header = null;
			$batch = [];
			$scheduled = 0;

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
			$needs_review = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID WHERE p.post_type=%s AND pm.meta_key=%s AND pm.meta_value=%s", 'letter', 'needs_review', '1'));
			$feedback_total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type=%s", 'rts_feedback'));

            // New: Velocity Stats (Last 24h, 7d, 30d)
            $now = current_time('mysql', 1);
            $date_24h = gmdate('Y-m-d H:i:s', strtotime('-24 hours'));
            $date_7d  = gmdate('Y-m-d H:i:s', strtotime('-7 days'));
            $date_30d = gmdate('Y-m-d H:i:s', strtotime('-30 days'));

            $count_24h = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type='letter' AND post_date_gmt >= %s", $date_24h));
            $count_7d  = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type='letter' AND post_date_gmt >= %s", $date_7d));
            $count_30d = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(1) FROM {$wpdb->posts} WHERE post_type='letter' AND post_date_gmt >= %s", $date_30d));

			$stats = [
				'generated_gmt'        => gmdate('c'),
				'letters_total'        => $letters_total,
				'letters_published'    => $letters_published,
				'letters_pending'      => $letters_pending,
				'letters_needs_review' => $needs_review,
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
            // Check nonce (create a nonce in your frontend 'rts_share_nonce')
            // For now, fail-open on nonce if not strictly implemented in JS yet, but better to check.
            if (isset($_REQUEST['nonce']) && !wp_verify_nonce($_REQUEST['nonce'], 'rts_track_share')) {
                wp_send_json_error(['message' => 'Invalid nonce']);
            }

            $post_id  = isset($_REQUEST['post_id']) ? absint($_REQUEST['post_id']) : 0;
            $platform = isset($_REQUEST['platform']) ? sanitize_key($_REQUEST['platform']) : 'unknown';

            if (!$post_id || get_post_type($post_id) !== 'letter') {
                wp_send_json_error(['message' => 'Invalid post']);
            }

            // 1. Increment Total Share Count for the Letter
            $current = (int) get_post_meta($post_id, 'share_count_total', true);
            update_post_meta($post_id, 'share_count_total', $current + 1);

            // 2. Increment Platform Specific Count
            $plat_count = (int) get_post_meta($post_id, 'share_count_' . $platform, true);
            update_post_meta($post_id, 'share_count_' . $platform, $plat_count + 1);

            // 3. Update Daily Aggregate
            self::update_daily_stats($platform);

            wp_send_json_success(['new_count' => $current + 1]);
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
		public const OPTION_AUTO_BATCH   = 'rts_auto_processing_batch_size';
		public const OPTION_MIN_QUALITY  = 'rts_min_quality_score';
		public const OPTION_IP_THRESHOLD = 'rts_ip_daily_threshold';
        // New Offset Options
        public const OPTION_OFFSET_LETTERS = 'rts_stat_offset_letters';
        public const OPTION_OFFSET_SHARES  = 'rts_stat_offset_shares';

		public static function init(): void {
			if (!is_admin()) return;
			add_action('admin_menu', [__CLASS__, 'register_menu'], 80);
			add_action('admin_init', [__CLASS__, 'register_settings'], 5);
			add_action('admin_post_rts_dashboard_action', [__CLASS__, 'handle_post_action']);
			add_action('rest_api_init', [__CLASS__, 'register_rest']);
            add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
            
            // Add AJAX handlers for modern dashboard
            add_action('wp_ajax_rts_start_scan', [__CLASS__, 'ajax_start_scan']);
            add_action('wp_ajax_rts_process_single', [__CLASS__, 'ajax_process_single']);
		}

        public static function enqueue_assets($hook): void {
            // Load CSS and JS on all RTS admin pages
            if (isset($_GET['page']) && strpos($_GET['page'], 'rts-') !== false) {
                // Main Admin Styles (includes dashboard styles)
                wp_enqueue_style('rts-admin-css', get_stylesheet_directory_uri() . '/assets/css/rts-admin.css', [], '2.40');
                
                // Dashboard Logic Script
                wp_enqueue_script('rts-dashboard-js', get_stylesheet_directory_uri() . '/inc/js/rts-dashboard.js', ['jquery'], '2.40', true);
                
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
				'Moderation Dashboard',
				'Moderation Dashboard',
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
            
            // Stat Offsets
            register_setting('rts_engine_settings', self::OPTION_OFFSET_LETTERS, ['sanitize_callback' => 'absint', 'default' => 0]);
            register_setting('rts_engine_settings', self::OPTION_OFFSET_SHARES, ['sanitize_callback' => 'absint', 'default' => 0]);
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
			$needs = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = %s", 'needs_review', '1', 'letter'));
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

			$tab   = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'overview';
			$stats = self::get_basic_stats();
			$import = get_option('rts_import_job_status', []);
			$agg    = get_option('rts_aggregated_stats', []);
			$as_ok  = rts_as_available();
			$message = isset($_GET['rts_msg']) ? sanitize_key((string) $_GET['rts_msg']) : '';

            // Layout uses rts-admin.css
			?>
			<div class="wrap rts-dashboard">
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
							default: echo '✅ Done.'; break;
						}
						?>
						</p>
					</div>
				<?php endif; ?>

                <!-- Primary Stats Grid -->
				<div class="rts-stats-grid">
                    <?php self::stat_card('Live on Site', number_format_i18n($stats['published']), 'published', 'Letters live on the website'); ?>
                    <?php self::stat_card('Inbox', number_format_i18n($stats['pending']), 'inbox', 'Awaiting your decision'); ?>
                    <?php self::stat_card('Quarantined', number_format_i18n($stats['needs_review']), 'quarantined', 'Flagged for safety review'); ?>
                    <?php self::stat_card('Total Letters', number_format_i18n($stats['total']), 'total', 'All-time submissions'); ?>
				</div>

                <!-- Live Status Panel -->
                <div class="rts-live-status">
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
                            <span class="rts-status-value"><?php echo get_option(self::OPTION_AUTO_ENABLED, '1') === '1' ? '<span class="status-good">Every 5 min</span>' : '<span class="status-warning">Manual only</span>'; ?></span>
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
                            <span class="rts-progress-status"><?php echo esc_html($import['status'] ?? 'idle'); ?></span>
                        </div>
                        <div class="rts-progress-bar-container">
                            <div class="rts-progress-bar" id="rts-import-progress-bar">
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
                            <div class="rts-progress-bar" id="rts-scan-progress-bar">
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
                'total' => 'dashicons-portfolio'
            ];
			?>
			<div class="rts-stat-card rts-stat-<?php echo esc_attr($type); ?>">
                <div class="rts-stat-icon">
                    <span class="dashicons <?php echo esc_attr($icons[$type] ?? 'dashicons-chart-area'); ?>"></span>
                </div>
                <div class="rts-stat-content">
                    <div class="rts-stat-label"><?php echo esc_html($label); ?></div>
                    <div class="rts-stat-value"><?php echo esc_html($value); ?></div>
                    <div class="rts-stat-sublabel"><?php echo esc_html($sublabel); ?></div>
                </div>
			</div>
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
                                <span class="dashicons dashicons-automate" style="color: #7b1fa2;"></span>
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
                        
                        $yesterday_count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'letter' AND post_date LIKE %s",
                            $yesterday . '%'
                        ));
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
                                        ? round((($today_count - $yesterday_count) / $yesterday_count) * 100) 
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
                    <table class="wp-list-table widefat fixed striped rts-letters-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Letter</th>
                                <th>Status</th>
                                <th>Quality</th>
                                <th>Safety</th>
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
                                <td>
                                    <?php echo esc_html(get_the_date('M j, Y g:ia')); ?>
                                </td>
                                <td>
                                    <div class="rts-action-buttons-small">
                                        <a class="button button-small" href="<?php echo esc_url($edit); ?>">Edit</a>
                                        <a class="button button-small" href="<?php echo esc_url($view); ?>" target="_blank">View</a>
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
			?>
			<div class="rts-card">
				<h3 class="rts-section-title">Growth & Velocity</h3>
                <div class="rts-grid" style="grid-template-columns: repeat(3, 1fr);">
                    <div>
                        <div class="rts-stat-label">Last 24 Hours</div>
                        <div class="rts-stat-value"><?php echo esc_html(number_format_i18n((int)($agg['velocity_24h']??0))); ?></div>
                        <div class="rts-stat-sub">New Letters</div>
                    </div>
                    <div>
                        <div class="rts-stat-label">Last 7 Days</div>
                        <div class="rts-stat-value"><?php echo esc_html(number_format_i18n((int)($agg['velocity_7d']??0))); ?></div>
                        <div class="rts-stat-sub">New Letters</div>
                    </div>
                    <div>
                        <div class="rts-stat-label">Last 30 Days</div>
                        <div class="rts-stat-value"><?php echo esc_html(number_format_i18n((int)($agg['velocity_30d']??0))); ?></div>
                        <div class="rts-stat-sub">New Letters</div>
                    </div>
                </div>
            </div>

            <div class="rts-grid">
                <?php self::render_kv_box('Top Feelings', $agg['taxonomy_breakdown']['letter_feeling'] ?? []); ?>
                <?php self::render_kv_box('Top Tones', $agg['taxonomy_breakdown']['letter_tone'] ?? []); ?>
            </div>
			<?php
		}

		private static function render_kv_box(string $title, $data): void {
			?>
			<div class="rts-card">
				<h3 class="rts-section-title"><?php echo esc_html($title); ?></h3>
				<?php if (empty($data) || !is_array($data)): ?>
					<p style="color:#646970;">No data yet.</p>
				<?php else: ?>
					<table class="widefat striped" style="margin:0; box-shadow:none; border:none;">
						<tbody>
							<?php foreach (array_slice($data, 0, 8) as $k => $v): ?>
								<tr><td><?php echo esc_html((string) $k); ?></td><td style="width:60px; text-align:right;"><strong><?php echo esc_html(number_format_i18n((int) $v)); ?></strong></td></tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
			<?php
		}

		private static function render_tab_shares(): void {
			$daily = get_option('rts_daily_stats', []);
            $total_shares = 0;
            if (is_array($daily)) {
                foreach($daily as $d) $total_shares += (int)($d['shares_total']??0);
            }
            
            $offset_shares = (int) get_option(self::OPTION_OFFSET_SHARES, 0);
            $display_total = $total_shares + $offset_shares;
			?>
			<div class="rts-card">
				<h3 class="rts-section-title">Share Engagement</h3>
                <div class="rts-grid" style="grid-template-columns: 1fr 1fr;">
                    <div>
                        <div class="rts-stat-label">Total Shares (All Time)</div>
                        <div class="rts-stat-value color-info"><?php echo esc_html(number_format_i18n($display_total)); ?></div>
                        <div class="rts-stat-sub">
                            Live: <?php echo $total_shares; ?> + Offset: <?php echo $offset_shares; ?>
                        </div>
                    </div>
                    <div>
                        <p style="color:#646970;">
                            Shares are tracked when users click share buttons in the <code>[rts_letter_viewer]</code>.
                            Data is aggregated daily.
                        </p>
                    </div>
                </div>
            </div>
			<?php
		}

		private static function render_tab_feedback(): void {
			// Existing feedback rendering logic kept simple
            $count = wp_count_posts('rts_feedback');
			?>
			<div class="rts-card">
				<h3 class="rts-section-title">Feedback Overview</h3>
                <div class="rts-stat-value"><?php echo esc_html(number_format_i18n((int)($count->publish??0))); ?></div>
                <div class="rts-stat-sub">Total Feedback Entries</div>
                <br>
                <a class="button" href="<?php echo esc_url(admin_url('edit.php?post_type=rts_feedback')); ?>">View All Feedback</a>
			</div>
			<?php
		}

		private static function render_tab_settings(): void {
			$auto_enabled = get_option(self::OPTION_AUTO_ENABLED, '1') === '1';
			$batch        = (int) get_option(self::OPTION_AUTO_BATCH, 50);
			$min_quality  = (int) get_option(self::OPTION_MIN_QUALITY, 70);
			$ip_thresh    = (int) get_option(self::OPTION_IP_THRESHOLD, 20);
            
            $off_letters  = (int) get_option(self::OPTION_OFFSET_LETTERS, 0);
            $off_shares   = (int) get_option(self::OPTION_OFFSET_SHARES, 0);
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
							<th scope="row">Min Quality Score</th>
							<td><input type="number" name="<?php echo esc_attr(self::OPTION_MIN_QUALITY); ?>" value="<?php echo esc_attr((string) $min_quality); ?>" class="small-text"> <p class="description">Letters below this score are quarantined.</p></td>
						</tr>
                        
                        <tr><td colspan="2"><hr><strong>Live Stats Overrides (Offsets)</strong></td></tr>
                        <tr>
                            <th scope="row">Letter Count Offset</th>
                            <td>
                                <input type="number" name="<?php echo esc_attr(self::OPTION_OFFSET_LETTERS); ?>" value="<?php echo esc_attr((string) $off_letters); ?>" class="regular-text">
                                <p class="description">Added to the "Live" total. Use this to include legacy counts not in DB.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Share Count Offset</th>
                            <td>
                                <input type="number" name="<?php echo esc_attr(self::OPTION_OFFSET_SHARES); ?>" value="<?php echo esc_attr((string) $off_shares); ?>" class="regular-text">
                                <p class="description">Added to the "Total Shares" count.</p>
                            </td>
                        </tr>
					</table>
					<?php submit_button('Save Settings'); ?>
				</form>
			</div>
			<?php
		}

		private static function render_tab_system($import, $agg): void {
			$as_ok = rts_as_available();
			?>
			<div class="rts-card">
				<h3 class="rts-section-title">System Health</h3>
				<table class="widefat striped">
					<tbody>
						<tr><td><strong>Action Scheduler</strong></td><td><?php echo $as_ok ? '<span class="color-success">Active</span>' : '<span class="color-danger">Missing</span>'; ?></td></tr>
						<tr><td><strong>Server Time (GMT)</strong></td><td><?php echo esc_html(gmdate('c')); ?></td></tr>
                        <tr><td><strong>PHP Version</strong></td><td><?php echo esc_html(phpversion()); ?></td></tr>
					</tbody>
				</table>
			</div>
			<?php
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
			}
			wp_safe_redirect($redirect); exit;
		}
        
        // AJAX Handler: Start Scan
        public static function ajax_start_scan(): void {
            check_ajax_referer('rts_dashboard_nonce', 'nonce');
            
            if (!current_user_can('manage_options')) {
                wp_die('Unauthorized', 403);
            }
            
            $scan_type = sanitize_key($_POST['scan_type'] ?? 'inbox');
            
            // Start the scan
            if ($scan_type === 'inbox') {
                self::queue_rescan_pending_and_review();
                $message = 'Inbox scan started';
            } else {
                // Queue quarantine rescan
                $batch = (int) get_option(self::OPTION_AUTO_BATCH, 50);
                $batch = max(1, min(200, $batch));
                
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
                    $message = 'Quarantine scan started for ' . count($needs->posts) . ' letters';
                } else {
                    $message = 'No quarantined letters to scan';
                }
            }
            
            wp_send_json_success($message);
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

		private static function queue_letter_scan(int $post_id): void {
			if (!rts_as_available() || get_post_type($post_id) !== 'letter') return;
			if (function_exists('as_has_scheduled_action') && as_has_scheduled_action('rts_process_letter', [$post_id], 'rts')) return;
			as_schedule_single_action(time() + 2, 'rts_process_letter', [$post_id], 'rts');
		}

		private static function queue_rescan_pending_and_review(): void {
			$batch = (int) get_option(self::OPTION_AUTO_BATCH, 50);
            $q_args = ['post_type'=>'letter', 'posts_per_page'=>$batch, 'fields'=>'ids', 'orderby'=>'date', 'order'=>'ASC'];
            
			$pending = new \WP_Query(array_merge($q_args, ['post_status'=>'pending']));
			foreach ($pending->posts as $id) self::queue_letter_scan((int) $id);

			$needs = new \WP_Query(array_merge($q_args, ['post_status'=>['publish','pending','draft'], 'meta_query'=>[['key'=>'needs_review','value'=>'1']]]));
			foreach ($needs->posts as $id) self::queue_letter_scan((int) $id);
		}

		public static function register_rest(): void {
			register_rest_route('rts/v1', '/processing-status', [
				'methods' => 'GET', 'permission_callback' => function() { return current_user_can('manage_options'); },
				'callback' => [__CLASS__, 'rest_processing_status'],
			]);
		}

		public static function rest_processing_status(\WP_REST_Request $req): \WP_REST_Response {
			$import = get_option('rts_import_job_status', []);
            $queue = [
                'pending_letter_jobs' => self::count_scheduled('rts_process_letter'),
                'pending_import_batches' => self::count_scheduled('rts_process_import_batch'),
                'pending_analytics' => self::count_scheduled('rts_aggregate_analytics'),
            ];
			return new \WP_REST_Response(['import' => $import, 'queue' => $queue, 'server_time_gmt' => gmdate('c')], 200);
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
   RTS_Auto_Processor: recurring queue tick
   ========================================================= */
if (!class_exists('RTS_Auto_Processor')) {
	class RTS_Auto_Processor {
		const GROUP = 'rts';
		public static function init(): void {
			add_action('init', [__CLASS__, 'ensure_schedule'], 30);
			add_action('rts_auto_process_tick', [__CLASS__, 'tick'], 10, 0);
		}
		public static function ensure_schedule(): void {
			if (!rts_as_available()) return;
			if (get_option(RTS_Engine_Settings::OPTION_AUTO_ENABLED, '1') !== '1') return;
			if (as_next_scheduled_action('rts_auto_process_tick', [], self::GROUP)) return;
			if (function_exists('as_schedule_recurring_action')) as_schedule_recurring_action(time() + 90, 300, 'rts_auto_process_tick', [], self::GROUP);
            else as_schedule_single_action(time() + 90, 'rts_auto_process_tick', [], self::GROUP);
		}
		public static function tick(): void {
			// Trigger processing logic if needed here, mostly handled by single actions now.
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
            add_action('rts_auto_process_tick', ['RTS_Auto_Processor', 'tick']);
			add_action('save_post_letter', [__CLASS__, 'on_save_post_letter'], 20, 3);
			add_action('save_post_rts_feedback', [__CLASS__, 'on_save_post_feedback'], 20, 3);
            
            // Share Tracking
            RTS_Share_Tracker::init();

			if (is_admin()) { RTS_Engine_Dashboard::init(); }
			RTS_Auto_Processor::init();
			add_action('init', [__CLASS__, 'schedule_daily_analytics'], 20);
		}

		public static function handle_process_letter($post_id): void { RTS_Moderation_Engine::process_letter((int) $post_id); }
		public static function handle_import_batch($job_id, $batch): void { RTS_Import_Orchestrator::process_import_batch((string) $job_id, (array) $batch); }
		public static function handle_aggregate_analytics(): void { RTS_Analytics_Aggregator::aggregate(); }

		public static function on_save_post_letter(int $post_id, \WP_Post $post, bool $update): void {
			if (!rts_as_available() || wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || $post->post_type !== 'letter' || $post->post_status === 'publish') return;
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
                wp_update_post(['ID' => $letter_id, 'post_status' => 'pending']);
            }
		}

		public static function schedule_daily_analytics(): void {
			if (!rts_as_available()) return;
			if (as_next_scheduled_action('rts_aggregate_analytics', [], self::GROUP)) return;
			if (function_exists('as_schedule_recurring_action')) as_schedule_recurring_action(strtotime('tomorrow 02:00'), DAY_IN_SECONDS, 'rts_aggregate_analytics', [], self::GROUP);
            else as_schedule_single_action(strtotime('tomorrow 02:00'), 'rts_aggregate_analytics', [], self::GROUP);
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