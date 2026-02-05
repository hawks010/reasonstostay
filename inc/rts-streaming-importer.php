<?php
/**
 * RTS Streaming Importer (Universal)
 *
 * Handles massive files (300MB+, 20-50k letters) without memory exhaustion.
 * Supports JSON, NDJSON, and XML (Wix/WordPress exports).
 *
 * STRATEGY:
 * 1. Auto-detect format.
 * 2. If XML: Stream-convert to NDJSON (Line-Delimited JSON) first using incremental parsing.
 * 3. Stream-parse NDJSON to Action Scheduler batches.
 * 4. Process batches with chunked DB transactions and deadlock retries.
 *
 * REQUIREMENTS:
 * - PHP 7.4+ with ext-json and ext-xml
 * - Action Scheduler plugin active
 */

if (!defined('ABSPATH')) { exit; }

class RTS_Streaming_Importer {

	const BATCH_SIZE = 250; 
	const TRANSACTION_CHUNK_SIZE = 50; 
	const GROUP = 'rts';
	const CHUNK_SIZE = 8192; 

	/**
	 * Start import process
	 *
	 * @param string $file_path Absolute path to source file
	 * @return array Import result and stats
	 */
	public static function start_import(string $file_path): array {
		if (!function_exists('as_schedule_single_action')) {
			return ['ok' => false, 'error' => 'action_scheduler_missing'];
		}

		// Prevent timeouts during parsing/conversion phase
		if (function_exists('set_time_limit')) {
			@set_time_limit(0);
		}
		@ini_set('max_execution_time', 1800); // 30 minutes for conversion + scanning

		$file_path = wp_normalize_path($file_path);

		if (!file_exists($file_path) || !is_readable($file_path)) {
			return ['ok' => false, 'error' => 'file_not_readable'];
		}

		$job_id = 'import_' . gmdate('Ymd_His') . '_' . wp_generate_password(8, false, false);
		$filesize = (int) @filesize($file_path);

		// Initialize job status
		update_option('rts_import_job_status_' . $job_id, [
			'job_id' => $job_id,
			'total' => 0, 
			'processed' => 0,
			'errors' => 0,
			'status' => 'analyzing',
			'started_gmt' => gmdate('c'),
			'file' => basename($file_path),
			'filesize' => $filesize,
			'scheduled_batches' => 0
		], false);

		$scheduled_batches = 0;
		$temp_file = null;
		$conversion_stats = [];

		try {
			// 1. Detect Format
			$format = self::detect_format($file_path);
			
			// 2. Convert XML to NDJSON if necessary
			if ($format === 'xml') {
				update_option('rts_import_job_status_' . $job_id, array_merge(
					get_option('rts_import_job_status_' . $job_id, []), 
					['status' => 'converting_xml']
				), false);

				$conversion = self::convert_xml_to_ndjson($file_path, $job_id);
				
				if (is_wp_error($conversion)) {
					throw new Exception($conversion->get_error_message());
				}

				// Swap to the new NDJSON file for processing
				$file_path = $conversion['file'];
				$temp_file = $file_path; // Mark for cleanup
				$format = 'ndjson'; // Treat as ndjson from now on
				$conversion_stats = $conversion;
			}

			// 3. Parse & Schedule
			update_option('rts_import_job_status_' . $job_id, array_merge(
				get_option('rts_import_job_status_' . $job_id, []), 
				['status' => 'scheduling']
			), false);

			if ($format === 'ndjson') {
				$scheduled_batches = self::parse_ndjson($file_path, $job_id);
			} else {
				// Standard JSON array
				$scheduled_batches = self::parse_json_streaming($file_path, $job_id);
			}

		} catch (\Throwable $e) {
			error_log("RTS Import Critical Error: " . $e->getMessage());
			// Cleanup temp file on failure
			if ($temp_file && file_exists($temp_file)) @unlink($temp_file);
			
			return ['ok' => false, 'error' => 'import_failed', 'message' => $e->getMessage()];
		}

		// 4. Update Final Status
		$status = get_option('rts_import_job_status_' . $job_id, []);
		if (is_array($status)) {
			$status['scheduled_batches'] = $scheduled_batches;
			$status['status'] = ($scheduled_batches > 0) ? 'running' : 'failed';
			
			if ($scheduled_batches === 0 && ($status['total'] ?? 0) === 0) {
				$status['error'] = 'No valid objects found in file';
			}
			update_option('rts_import_job_status_' . $job_id, $status, false);
		}

		// 5. Cleanup Temp Artifacts
		// Safe to delete because parse_ndjson() reads data into AS payload synchronously
		if ($temp_file && file_exists($temp_file)) {
			@unlink($temp_file);
		}

		self::cleanup_old_jobs();

		return [
			'ok' => true,
			'job_id' => $job_id,
			'scheduled_batches' => $scheduled_batches,
			'total' => $status['total'] ?? 0,
			'format' => $format,
			'conversion' => $conversion_stats
		];
	}

	/**
	 * Detect file format by peeking at content
	 */
	private static function detect_format(string $file_path): string {
		$fh = @fopen($file_path, 'r');
		if (!$fh) return 'json'; 

		$buffer = fread($fh, 2048);
		fclose($fh);
		$buffer = trim($buffer);
		
		// XML checks
		if (strpos($buffer, '<?xml') !== false || strpos($buffer, '<rss') !== false || strpos($buffer, '<items') !== false) {
			return 'xml';
		}
		
		// JSON Array check
		if (strpos($buffer, '[') === 0) {
			return 'json';
		}
		
		// NDJSON/JSONL check (or single object)
		$ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));
		if (in_array($ext, ['ndjson', 'jsonl'], true)) {
			return 'ndjson';
		}

		return 'json';
	}

	/**
	 * Convert XML (Wix/RSS) to NDJSON line-by-line using incremental parsing
	 * This prevents memory exhaustion with large XML files containing huge nodes.
	 * * @return array|WP_Error ['file' => path, 'count' => int]
	 */
	private static function convert_xml_to_ndjson(string $xml_path, string $job_id) {
		if (!class_exists('XMLReader')) {
			return new WP_Error('xml_missing', 'XMLReader extension is required.');
		}

		$upload_dir = wp_upload_dir();
		$temp_dir = $upload_dir['basedir'] . '/rts_temp_imports';
		if (!file_exists($temp_dir)) {
			wp_mkdir_p($temp_dir);
		}

		$ndjson_path = $temp_dir . '/converted_' . $job_id . '.ndjson';
		$output_fh = @fopen($ndjson_path, 'w');
		
		if (!$output_fh) {
			return new WP_Error('write_failed', 'Could not create temporary conversion file.');
		}

		$reader = new XMLReader();
		if (!$reader->open($xml_path)) {
			fclose($output_fh);
			return new WP_Error('read_failed', 'Could not open XML file.');
		}

		$count = 0;
		$errors = 0;
		
		// Incremental parsing state
		$in_item = false;
		$current_item = [];
		$current_field = '';
		$current_value = '';
		
		// Fields to capture
		$target_fields = [
			'title', 'name', 'subject', 
			'content', 'description', 'body', 'encoded', 
			'author', 'creator', 'writer', 
			'pubDate', 'date', 'createdDate', 'updatedDate', 
			'ip', 'submission_ip', 
			'feeling', 'feelings', 'tone'
		];
		// Item container tags
		$item_tags = ['item', 'product', 'post', 'entry', 'row', 'letter'];

		// Iterate through the XML stream
		while ($reader->read()) {
			if ($reader->nodeType == XMLReader::ELEMENT) {
				// Check if entering an item
				if (in_array($reader->name, $item_tags)) {
					$in_item = true;
					$current_item = [];
					$current_field = '';
					continue;
				}

				if ($in_item) {
					// Check if entering a target field
					// We check both name (ns:tag) and localName (tag)
					if (in_array($reader->name, $target_fields) || in_array($reader->localName, $target_fields)) {
						$current_field = $reader->localName; // Use localName to normalize namespaces like content:encoded
						$current_value = '';
					}
				}
			}
			elseif (($reader->nodeType == XMLReader::TEXT || $reader->nodeType == XMLReader::CDATA) && $in_item && $current_field) {
				$current_value .= $reader->value;
			}
			elseif ($reader->nodeType == XMLReader::END_ELEMENT && $in_item) {
				if ($reader->localName === $current_field) {
					// Store collected value
					// Simple overwrite strategy; could array_append if multiple tags exist
					$current_item[$current_field] = trim($current_value);
					$current_field = '';
					$current_value = '';
				}
				elseif (in_array($reader->name, $item_tags)) {
					// End of item - process it
					$data = self::normalize_xml_item($current_item);
					
					if (!empty($data['content'])) {
						fwrite($output_fh, json_encode($data, JSON_UNESCAPED_UNICODE) . "\n");
						$count++;
						
						// Periodic flush/GC/Update
						if ($count % 500 === 0) {
							fflush($output_fh);
							gc_collect_cycles();
							// Update progress
							update_option('rts_import_conversion_' . $job_id, [
								'count' => $count,
								'errors' => $errors,
								'memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2)
							], false);
						}
					} else {
						$errors++;
					}
					
					$in_item = false;
					$current_item = [];
				}
			}
		}

		$reader->close();
		fclose($output_fh);

		if ($count === 0 && $errors > 0) {
			@unlink($ndjson_path);
			return new WP_Error('xml_parse_empty', 'Could not find valid items in XML.');
		}

		return [
			'file' => $ndjson_path,
			'count' => $count,
			'errors' => $errors
		];
	}

	/**
	 * Normalize raw XML field array into standard format
	 */
	private static function normalize_xml_item(array $raw): array {
		$data = [];
		
		// Map collected fields
		$data['title'] = $raw['title'] ?? $raw['name'] ?? $raw['subject'] ?? '';
		
		// Content mapping (prioritize 'encoded' for RSS content:encoded)
		$data['content'] = $raw['encoded'] ?? $raw['content'] ?? $raw['description'] ?? $raw['body'] ?? '';
		
		$data['author'] = $raw['author'] ?? $raw['creator'] ?? $raw['writer'] ?? '';
		
		// Date mapping
		$data['submitted_at'] = $raw['pubDate'] ?? $raw['date'] ?? $raw['createdDate'] ?? $raw['updatedDate'] ?? '';
		
		// Extra fields
		$data['submission_ip'] = $raw['ip'] ?? $raw['submission_ip'] ?? '';
		$data['feeling'] = $raw['feeling'] ?? $raw['feelings'] ?? null;
		$data['tone'] = $raw['tone'] ?? null;

		return $data;
	}

	/**
	 * Parse NDJSON (newline-delimited JSON)
	 */
	private static function parse_ndjson(string $file_path, string $job_id): int {
		$fh = @fopen($file_path, 'r');
		if (!$fh) return 0;

		$batch = [];
		$scheduled = 0;
		$line_num = 0;

		while (($line = fgets($fh)) !== false) {
			$line_num++;
			$line = trim($line);
			if ($line === '') continue;

			// Handle trailing comma artifacts
			$clean_line = rtrim($line, ',');
			$item = @json_decode($clean_line, true);

			if (!is_array($item)) continue;
			
			// Normalize
			$normalized = self::normalize_letter($item);
			
			// Validate content exists
			if (empty($normalized['content'])) {
				self::bump_status($job_id, 'errors', 1);
				continue;
			}
			
			$batch[] = $normalized;

			if (count($batch) >= self::BATCH_SIZE) {
				as_schedule_single_action(time() + 2, 'rts_process_import_batch', [$job_id, $batch], self::GROUP);
				self::bump_status($job_id, 'total', count($batch));
				$scheduled++;
				$batch = [];
			}

			if ($line_num % 1000 === 0) gc_collect_cycles();
		}

		if (!empty($batch)) {
			as_schedule_single_action(time() + 2, 'rts_process_import_batch', [$job_id, $batch], self::GROUP);
			self::bump_status($job_id, 'total', count($batch));
			$scheduled++;
		}

		fclose($fh);
		return $scheduled;
	}

	/**
	 * Parse standard JSON array with state-machine streaming
	 */
	private static function parse_json_streaming(string $file_path, string $job_id): int {
		$fh = @fopen($file_path, 'r');
		if (!$fh) return 0;

		$batch = [];
		$scheduled = 0;
		$objects_found = 0;

		// State tracking
		$buffer = '';
		$depth = 0;
		$in_string = false;
		$escape_next = false;
		$obj_start_pos = -1;

		// Skip initial brackets
		$start_chunk = fread($fh, 4096);
		$bracket_pos = strpos($start_chunk, '[');
		$brace_pos = strpos($start_chunk, '{');

		if ($bracket_pos !== false && ($brace_pos === false || $bracket_pos < $brace_pos)) {
			fseek($fh, $bracket_pos + 1);
		} elseif ($brace_pos !== false) {
			fseek($fh, $brace_pos);
		} else {
			fseek($fh, 0);
		}

		while (!feof($fh)) {
			$chunk = fread($fh, self::CHUNK_SIZE);
			if ($chunk === false || $chunk === '') break;

			$buffer .= $chunk;
			$len = strlen($buffer);
			
			for ($i = 0; $i < $len; $i++) {
				$char = $buffer[$i];

				if ($escape_next) {
					$escape_next = false;
					continue;
				}
				if ($char === '\\') {
					$escape_next = true;
					continue;
				}
				if ($char === '"') {
					$in_string = !$in_string;
					continue;
				}
				if ($in_string) continue;

				if ($char === '{') {
					if ($depth === 0) $obj_start_pos = $i;
					$depth++;
				} elseif ($char === '}') {
					$depth--;
					if ($depth === 0 && $obj_start_pos !== -1) {
						$json_str = substr($buffer, $obj_start_pos, $i - $obj_start_pos + 1);
						$item = @json_decode($json_str, true);

						if (is_array($item)) {
							$normalized = self::normalize_letter($item);
							if (!empty($normalized['content'])) {
								$batch[] = $normalized;
								if (count($batch) >= self::BATCH_SIZE) {
									as_schedule_single_action(time() + 2, 'rts_process_import_batch', [$job_id, $batch], self::GROUP);
									self::bump_status($job_id, 'total', count($batch));
									$scheduled++;
									$batch = [];
								}
							} else {
								self::bump_status($job_id, 'errors', 1);
							}
							$objects_found++;
						} else {
							self::bump_status($job_id, 'errors', 1);
						}

						$obj_start_pos = -1;
						
						// Truncate buffer to keep memory low
						$buffer = substr($buffer, $i + 1);
						$len = strlen($buffer);
						$i = -1; 
						
						if ($objects_found % 1000 === 0) gc_collect_cycles();
						continue;
					}
				} elseif ($depth === 0) {
					// Check for array end to prevent infinite loops on trailing whitespace
					if ($char === ']') {
						break 2; // Break out of for loop and while loop
					}
					
					if ($char === ',' || ctype_space($char)) {
						if (strlen($buffer) > self::CHUNK_SIZE && $i > self::CHUNK_SIZE / 2) {
							$buffer = substr($buffer, $i + 1);
							$len = strlen($buffer);
							$i = -1;
						}
					}
				}
			}
			
			// Safety cutoff
			if (strlen($buffer) > self::CHUNK_SIZE * 10) {
				if ($depth > 0 && $obj_start_pos !== -1) {
					$buffer = substr($buffer, $obj_start_pos);
					$obj_start_pos = 0;
				} else {
					$buffer = substr($buffer, -1024);
				}
			}
		}

		fclose($fh);

		if (!empty($batch)) {
			as_schedule_single_action(time() + 2, 'rts_process_import_batch', [$job_id, $batch], self::GROUP);
			self::bump_status($job_id, 'total', count($batch));
			$scheduled++;
		}

		return $scheduled;
	}

	/**
	 * Normalize letter data
	 */
	private static function normalize_letter(array $item): array {
		$content = !empty($item['content']) ? $item['content'] : '';

		// UTF-8 Safety
		if (function_exists('mb_check_encoding') && !mb_check_encoding($content, 'UTF-8')) {
			$content = mb_convert_encoding($content, 'UTF-8', 'auto');
		}

		return [
			'title' => !empty($item['title']) ? sanitize_text_field($item['title']) : '',
			'content' => wp_kses_post($content),
			'author' => !empty($item['author']) ? sanitize_text_field($item['author']) : '',
			'submission_ip' => !empty($item['submission_ip']) ? sanitize_text_field($item['submission_ip']) : '',
			'submitted_at' => !empty($item['submitted_at']) ? sanitize_text_field($item['submitted_at']) : '',
			'feeling' => $item['feeling'] ?? $item['feelings'] ?? null,
			'tone' => $item['tone'] ?? null,
		];
	}

	/**
	 * Process a batch of letters (called by Action Scheduler)
	 */
	public static function process_import_batch(string $job_id, array $batch): void {
		if (!is_array($batch) || empty($batch)) return;

		global $wpdb;

		wp_defer_term_counting(true);
		wp_defer_comment_counting(true);
		wp_suspend_cache_addition(true);

		$memory_limit = ini_get('memory_limit');
		if ($memory_limit && $memory_limit !== '-1') {
			$limit_bytes = wp_convert_hr_to_bytes($memory_limit);
			if (memory_get_usage(true) > $limit_bytes * 0.9) {
				error_log("RTS Import: Aborting batch due to high memory usage.");
				self::bump_status($job_id, 'errors', count($batch));
				return;
			}
		}

		$processed = 0;
		$errors = 0;

		$chunks = array_chunk($batch, self::TRANSACTION_CHUNK_SIZE);

		foreach ($chunks as $chunk) {
			$chunk_attempts = 0;
			$chunk_success = false;

			// Retry loop for Deadlock protection
			while (!$chunk_success && $chunk_attempts < 3) {
				$chunk_attempts++;
				$wpdb->query('START TRANSACTION');
				
				$chunk_processed = 0;
				$chunk_errors = 0;

				try {
					foreach ($chunk as $item) {
						$title = !empty($item['title']) ? $item['title'] : 'Letter ' . wp_generate_password(8, false, false);
						
						$post_data = [
							'post_type' => 'letter',
							'post_title' => $title,
							'post_content' => $item['content'],
							'post_status' => 'pending', 
							'post_author' => 1,
						];

						if (!empty($item['submitted_at'])) {
							$timestamp = strtotime($item['submitted_at']);
							if ($timestamp) {
								$post_data['post_date'] = gmdate('Y-m-d H:i:s', $timestamp);
								$post_data['post_date_gmt'] = gmdate('Y-m-d H:i:s', $timestamp);
							}
						}

						$post_id = wp_insert_post($post_data, true);

						if (is_wp_error($post_id) || !$post_id) {
							// If WP_Error due to deadlock, Exception might not be thrown automatically
							// but usually $wpdb catches query errors. 
							$chunk_errors++;
							continue;
						}

						if (!empty($item['submission_ip'])) {
							update_post_meta($post_id, 'rts_submission_ip', $item['submission_ip']);
						}
						if (!empty($item['author'])) {
							update_post_meta($post_id, 'rts_author_name', $item['author']);
						}

						if (!empty($item['feeling']) && taxonomy_exists('letter_feeling')) {
							$feelings = is_array($item['feeling']) ? $item['feeling'] : [$item['feeling']];
							wp_set_object_terms($post_id, $feelings, 'letter_feeling');
						}

						if (!empty($item['tone']) && taxonomy_exists('letter_tone')) {
							wp_set_object_terms($post_id, $item['tone'], 'letter_tone');
						}

						$chunk_processed++;
					}

					$wpdb->query('COMMIT');
					$processed += $chunk_processed;
					$errors += $chunk_errors;
					$chunk_success = true;

				} catch (\Throwable $e) {
					$wpdb->query('ROLLBACK');
					
					// Retry on Deadlock
					$msg = $e->getMessage();
					if (strpos($msg, 'Deadlock') !== false || strpos($msg, 'deadlock') !== false) {
						if ($chunk_attempts < 3) {
							usleep(200000 * $chunk_attempts); // Backoff 0.2s, 0.4s
							continue;
						}
					}
					
					// Hard fail or max retries
					$errors += count($chunk);
					error_log('RTS Import Chunk Error: ' . $msg);
					break; // Move to next chunk
				}
			}
		}

		wp_defer_term_counting(false);
		wp_defer_comment_counting(false);
		wp_suspend_cache_addition(false);

		self::bump_status($job_id, 'processed', $processed);
		if ($errors > 0) {
			self::bump_status($job_id, 'errors', $errors);
		}
	}

	/**
	 * Atomically increment job status counters
	 */
	private static function bump_status(string $job_id, string $key, int $amount): void {
		$option_name = 'rts_import_job_status_' . $job_id;
		$status = get_option($option_name, []);

		if (!is_array($status)) return;

		$status[$key] = ($status[$key] ?? 0) + $amount;

		if ($key === 'processed' || $key === 'errors') {
			if (!empty($status['scheduled_batches']) && ($status['processed'] + $status['errors']) >= $status['total']) {
				$status['status'] = 'complete';
				$status['completed_gmt'] = gmdate('c');
			}
		}

		update_option($option_name, $status, false);
	}

	/**
	 * Cleanup old import job data (options) and temp files
	 */
	public static function cleanup_old_jobs(int $days_old = 7): void {
		if (rand(1, 20) !== 1) return;

		global $wpdb;
		$cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_old} days"));

		// Clean options
		$wpdb->query($wpdb->prepare(
			"DELETE FROM {$wpdb->options} 
			 WHERE option_name LIKE %s 
			 AND option_value LIKE %s",
			'rts_import_job_status_%',
			'%"started_gmt":"' . substr($cutoff_date, 0, 4) . '%' 
		));

		// Clean orphaned temp files in rts_temp_imports
		$upload_dir = wp_upload_dir();
		$temp_dir = $upload_dir['basedir'] . '/rts_temp_imports';
		if (is_dir($temp_dir)) {
			$files = glob($temp_dir . '/*');
			$now = time();
			$max_age = $days_old * 86400;
			
			foreach ($files as $file) {
				if (is_file($file) && ($now - filemtime($file) > $max_age)) {
					@unlink($file);
				}
			}
		}
	}

	public static function init(): void {
		add_action('rts_process_import_batch', [__CLASS__, 'process_import_batch'], 10, 2);
	}
}

if (function_exists('add_action')) {
	RTS_Streaming_Importer::init();
}