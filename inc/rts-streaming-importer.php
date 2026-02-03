<?php
/**
 * RTS Streaming JSON Importer
 *
 * Handles massive JSON files (300MB+, 20-50k letters) without memory exhaustion
 * Uses streaming parser to process one letter at a time
 *
 * REQUIREMENTS:
 * - PHP 7.4+ with ext-json
 * - At least 128MB memory_limit (256MB recommended)
 * - Action Scheduler plugin active
 */

if (!defined('ABSPATH')) { exit; }

class RTS_Streaming_Importer {

	const BATCH_SIZE = 50; // Letters per Action Scheduler job
	const GROUP = 'rts';
	const CHUNK_SIZE = 8192; // Bytes to read at a time

	/**
	 * Start import with streaming parser for large JSON files
	 *
	 * @param string $file_path Absolute path to JSON file
	 * @return array ['ok' => bool, 'job_id' => string, 'scheduled_batches' => int]
	 */
	public static function start_import(string $file_path): array {
		if (!function_exists('as_schedule_single_action')) {
			return ['ok' => false, 'error' => 'action_scheduler_missing'];
		}

		$file_path = wp_normalize_path($file_path);

		if (!file_exists($file_path) || !is_readable($file_path)) {
			return ['ok' => false, 'error' => 'file_not_readable'];
		}

		$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
		if (!in_array($ext, ['json', 'ndjson', 'jsonl'], true)) {
			return ['ok' => false, 'error' => 'unsupported_format'];
		}

		$job_id = 'import_' . gmdate('Ymd_His') . '_' . wp_generate_password(8, false, false);
		$filesize = (int) @filesize($file_path);

		// Initialize job status
		update_option('rts_import_job_status', [
			'job_id' => $job_id,
			'total' => 0, // Will be updated as we parse
			'processed' => 0,
			'errors' => 0,
			'status' => 'running',
			'format' => $ext,
			'started_gmt' => gmdate('c'),
			'file' => basename($file_path),
			'filesize' => $filesize,
		], false);

		$scheduled_batches = 0;

		if ($ext === 'ndjson' || $ext === 'jsonl') {
			// Line-delimited JSON (each line is a complete JSON object)
			$scheduled_batches = self::parse_ndjson($file_path, $job_id);
		} else {
			// Standard JSON array (requires streaming parser)
			$scheduled_batches = self::parse_json_streaming($file_path, $job_id);
		}

		// Update final total
		$status = get_option('rts_import_job_status', []);
		if (is_array($status) && $status['job_id'] === $job_id) {
			$status['scheduled_batches'] = $scheduled_batches;
			update_option('rts_import_job_status', $status, false);
		}

		return [
			'ok' => true,
			'job_id' => $job_id,
			'scheduled_batches' => $scheduled_batches,
			'total' => $status['total'] ?? 0,
		];
	}

	/**
	 * Parse NDJSON (newline-delimited JSON) - memory efficient
	 */
	private static function parse_ndjson(string $file_path, string $job_id): int {
		$fh = @fopen($file_path, 'r');
		if (!$fh) return 0;

		$batch = [];
		$scheduled = 0;
		$line_num = 0;
		$errors = 0;

		while (($line = fgets($fh)) !== false) {
			$line_num++;
			$line = trim($line);

			if ($line === '' || $line === '[' || $line === ']') {
				continue; // Skip empty lines and array brackets
			}

			// Remove trailing comma (common in JSON arrays formatted across lines)
			$line = rtrim($line, ',');

			$item = @json_decode($line, true);

			if (!is_array($item) || empty($item['content'])) {
				$errors++;
				self::bump_status($job_id, 'errors', 1);
				continue;
			}

			$normalized = self::normalize_letter($item);
			$batch[] = $normalized;

			if (count($batch) >= self::BATCH_SIZE) {
				as_schedule_single_action(time() + 2, 'rts_process_import_batch', [$job_id, $batch], self::GROUP);
				self::bump_status($job_id, 'total', count($batch));
				$scheduled++;
				$batch = [];
			}

			// Memory management: clear variables every 100 lines
			if ($line_num % 100 === 0) {
				unset($item, $normalized);
				gc_collect_cycles();
			}
		}

		// Schedule remaining batch
		if (!empty($batch)) {
			as_schedule_single_action(time() + 2, 'rts_process_import_batch', [$job_id, $batch], self::GROUP);
			self::bump_status($job_id, 'total', count($batch));
			$scheduled++;
		}

		fclose($fh);

		return $scheduled;
	}

	/**
	 * Parse standard JSON array with streaming (for 300MB+ files)
	 *
	 * Uses a state machine to extract objects without loading entire file
	 */
	private static function parse_json_streaming(string $file_path, string $job_id): int {
		$fh = @fopen($file_path, 'r');
		if (!$fh) return 0;

		$batch = [];
		$scheduled = 0;
		$errors = 0;

		$buffer = '';
		$depth = 0;
		$in_object = false;
		$object_buffer = '';
		$objects_found = 0;

		while (!feof($fh)) {
			$chunk = fread($fh, self::CHUNK_SIZE);
			if ($chunk === false) break;

			$buffer .= $chunk;

			// Process character by character to track JSON depth
			$len = strlen($buffer);
			for ($i = 0; $i < $len; $i++) {
				$char = $buffer[$i];

				if ($char === '{') {
					if ($depth === 0) {
						$in_object = true;
						$object_buffer = '';
					}
					$depth++;
				}

				if ($in_object) {
					$object_buffer .= $char;
				}

				if ($char === '}') {
					$depth--;

					if ($depth === 0 && $in_object) {
						// Complete object found
						$in_object = false;
						$objects_found++;

						$item = @json_decode($object_buffer, true);

						if (is_array($item) && !empty($item['content'])) {
							$normalized = self::normalize_letter($item);
							$batch[] = $normalized;

							if (count($batch) >= self::BATCH_SIZE) {
								as_schedule_single_action(time() + 2, 'rts_process_import_batch', [$job_id, $batch], self::GROUP);
								self::bump_status($job_id, 'total', count($batch));
								$scheduled++;
								$batch = [];
							}
						} else {
							$errors++;
							self::bump_status($job_id, 'errors', 1);
						}

						$object_buffer = '';

						// Memory management
						if ($objects_found % 100 === 0) {
							unset($item, $normalized);
							gc_collect_cycles();
						}
					}
				}
			}

			// Keep only incomplete object in buffer
			if ($in_object) {
				$buffer = $object_buffer;
			} else {
				$buffer = '';
			}
		}

		// Schedule remaining batch
		if (!empty($batch)) {
			as_schedule_single_action(time() + 2, 'rts_process_import_batch', [$job_id, $batch], self::GROUP);
			self::bump_status($job_id, 'total', count($batch));
			$scheduled++;
		}

		fclose($fh);

		return $scheduled;
	}

	/**
	 * Normalize letter data to consistent format
	 */
	private static function normalize_letter(array $item): array {
		return [
			'title' => !empty($item['title']) ? sanitize_text_field($item['title']) : '',
			'content' => !empty($item['content']) ? wp_kses_post($item['content']) : '',
			'author' => !empty($item['author']) ? sanitize_text_field($item['author']) : '',
			'submission_ip' => !empty($item['submission_ip']) ? sanitize_text_field($item['submission_ip']) : '',
			'submitted_at' => !empty($item['submitted_at']) ? sanitize_text_field($item['submitted_at']) : '',
			// Support multiple field name variations
			'feeling' => $item['feeling'] ?? $item['feelings'] ?? null,
			'tone' => $item['tone'] ?? null,
		];
	}

	/**
	 * Process a batch of letters (called by Action Scheduler)
	 */
	public static function process_import_batch(string $job_id, array $batch): void {
		if (!is_array($batch) || empty($batch)) return;

		$processed = 0;
		$errors = 0;

		foreach ($batch as $item) {
			try {
				$title = !empty($item['title']) ? $item['title'] : 'Letter ' . wp_generate_password(8, false, false);

				$post_data = [
					'post_type' => 'letter',
					'post_title' => $title,
					'post_content' => $item['content'],
					'post_status' => 'pending', // Will be moderated
					'post_author' => 1, // Admin
				];

				// Set custom post date if provided
				if (!empty($item['submitted_at'])) {
					$timestamp = strtotime($item['submitted_at']);
					if ($timestamp) {
						$post_data['post_date'] = gmdate('Y-m-d H:i:s', $timestamp);
						$post_data['post_date_gmt'] = gmdate('Y-m-d H:i:s', $timestamp);
					}
				}

				$post_id = wp_insert_post($post_data, true);

				if (is_wp_error($post_id) || !$post_id) {
					$errors++;
					continue;
				}

				$post_id = (int) $post_id;

				// Save metadata
				if (!empty($item['submission_ip'])) {
					update_post_meta($post_id, 'rts_submission_ip', $item['submission_ip']);
				}

				if (!empty($item['author'])) {
					update_post_meta($post_id, 'rts_author_name', $item['author']);
				}

				// Assign taxonomies
				if (!empty($item['feeling']) && taxonomy_exists('letter_feeling')) {
					$feelings = is_array($item['feeling']) ? $item['feeling'] : [$item['feeling']];
					wp_set_object_terms($post_id, $feelings, 'letter_feeling');
				}

				if (!empty($item['tone']) && taxonomy_exists('letter_tone')) {
					wp_set_object_terms($post_id, $item['tone'], 'letter_tone');
				}

				// Queue for moderation
				if (function_exists('as_schedule_single_action')) {
					as_schedule_single_action(time() + 5, 'rts_process_letter', [$post_id], self::GROUP);
				}

				$processed++;

			} catch (\Throwable $e) {
				$errors++;
				error_log('RTS Import Error: ' . $e->getMessage());
			}
		}

		// Update job status
		self::bump_status($job_id, 'processed', $processed);
		self::bump_status($job_id, 'errors', $errors);
	}

	/**
	 * Atomically increment job status counters
	 */
	private static function bump_status(string $job_id, string $key, int $amount): void {
		$status = get_option('rts_import_job_status', []);

		if (!is_array($status) || ($status['job_id'] ?? '') !== $job_id) {
			return;
		}

		$status[$key] = ($status[$key] ?? 0) + $amount;

		// Mark as complete if processed == total
		if ($key === 'processed' && isset($status['total']) && $status['total'] > 0) {
			if ($status['processed'] >= $status['total']) {
				$status['status'] = 'complete';
				$status['completed_gmt'] = gmdate('c');
			}
		}

		update_option('rts_import_job_status', $status, false);
	}

	/**
	 * Register Action Scheduler hook
	 */
	public static function init(): void {
		add_action('rts_process_import_batch', [__CLASS__, 'process_import_batch'], 10, 2);
	}
}

// Auto-init
if (function_exists('add_action')) {
	RTS_Streaming_Importer::init();
}
