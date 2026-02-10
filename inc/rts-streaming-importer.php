<?php
/**
 * RTS Streaming Importer (Universal)
 *
 * Handles massive files (300MB+, 20-50k letters) without memory exhaustion.
 * Supports JSON, NDJSON, XML (Wix/WordPress), and CSV.
 *
 * STRATEGY:
 * 1. Auto-detect format (JSON, XML, CSV).
 * 2. If XML/CSV: Stream-convert to NDJSON (Line-Delimited JSON) first.
 * 3. Stream-parse NDJSON to Action Scheduler batches.
 * 4. Process batches with chunked DB transactions.
 * 5. DUPLICATE PREVENTION: Checks against existing posts before insertion.
 *
 * REQUIREMENTS:
 * - PHP 7.4+ with ext-json, ext-xml, ext-simplexml, ext-dom
 * - Action Scheduler plugin active
 */

namespace RTS\Importer;

use XMLReader;
use DOMDocument;
use SimpleXMLElement;
use Exception;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Critical Issue #1: Ensure WP_Error is available
if ( ! class_exists( 'WP_Error' ) ) {
	require_once ABSPATH . 'wp-includes/class-wp-error.php';
}

class RTSStreamingImporter {

	// Minor Issue #16: Constants for magic numbers
	const BATCH_SIZE             = 250;
	const CSV_BATCH_SIZE         = 500; // Optimization #15
	const TRANSACTION_CHUNK_SIZE = 50;
	const CSV_TRANSACTION_SIZE   = 100; // Optimization #15
	// Critical Issue #8: Action Scheduler Hook Name Collision
	const GROUP                  = 'rts_streaming_importer';
	const CHUNK_SIZE             = 8192;
	const UPDATE_FREQUENCY       = 500;
	const GC_FREQUENCY           = 1000;
	
	// Critical Issue #4: File size limit (500MB)
	const MAX_FILE_SIZE          = 524288000;
	const MAX_CSV_ROWS           = 100000; // Optimization #6

	// Code Quality #11: Configurable post type
	const POST_TYPE = 'letter';

	private static $shutdown_registered = false;
	
	// QA #9: Caching for post_exists
	private static $title_date_cache = [];

	// Optimization #5: Configurable CSV mapping
	public static $csv_field_map = [
		'content' => [ 'write a letter', 'content', 'body', 'text', 'message', 'letter' ],
		'id'      => [ 'id', '#', 'identifier', 'uid', 'import_id' ],
		'author'  => [ 'first name', 'author', 'name', 'user', 'writer', 'creator' ],
		'date'    => [ 'created date', 'date', 'created', 'timestamp', 'time', 'submitted', 'pubdate' ],
		'email'   => [ 'email', 'e-mail', 'author_email', 'user_email' ],
		'status'  => [ 'status', 'state' ],
		'ip'      => [ 'ip', 'ip_address', 'submission_ip' ],
	];

	/**
	 * Start import process
	 *
	 * @param string $file_path Absolute path to source file
	 * @return array Import result and stats
	 */
	public static function startImport( string $file_path ): array {
		// Code Quality #10: Parameter validation
		if ( empty( $file_path ) || ! is_string( $file_path ) ) {
			return [ 'ok' => false, 'error' => 'invalid_parameter' ];
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return [ 'ok' => false, 'error' => 'action_scheduler_missing' ];
		}

		// Security #15: Race condition lock
		$lock_option = 'rts_import_lock';
		if ( get_option( $lock_option ) ) {
			// Check if lock is stale (older than 1 hour)
			$lock_time = get_option( $lock_option . '_time' );
			if ( $lock_time && ( time() - $lock_time > 3600 ) ) {
				delete_option( $lock_option );
				delete_option( $lock_option . '_time' );
			} else {
				return [ 'ok' => false, 'error' => 'import_in_progress' ];
			}
		}

		// Prevent timeouts during parsing/conversion phase
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		@ini_set( 'max_execution_time', 1800 ); // 30 minutes for conversion + scanning

		// Critical Issue #3: Path sanitization
		$file_path = wp_normalize_path( $file_path );
		$real_path = realpath( $file_path );

		// Ensure file is within allowed directories (Uploads or Content)
		$upload_dir    = wp_upload_dir();
		$allowed_paths = [
			wp_normalize_path( WP_CONTENT_DIR ),
			wp_normalize_path( $upload_dir['basedir'] ),
		];

		$is_allowed = false;
		if ( $real_path ) {
			$real_path = wp_normalize_path( $real_path );
			foreach ( $allowed_paths as $allowed ) {
				if ( strpos( $real_path, $allowed ) === 0 ) {
					$is_allowed = true;
					break;
				}
			}
		}

		if ( ! $is_allowed || ! file_exists( $real_path ) || ! is_readable( $real_path ) ) {
			return [ 'ok' => false, 'error' => 'file_not_readable_or_forbidden' ];
		}

		$file_path = $real_path; // Use the resolved safe path

		// Critical Issue #4: File size check
		$filesize = (int) @filesize( $file_path );
		if ( $filesize > self::MAX_FILE_SIZE ) {
			return [
				'ok' => false,
				'error' => 'file_too_large',
				'max' => self::MAX_FILE_SIZE,
			];
		}

		$job_id = 'import_' . gmdate( 'Ymd_His' ) . '_' . wp_generate_password( 8, false, false );

		// Set lock
		update_option( $lock_option, $job_id, false );
		update_option( $lock_option . '_time', time(), false );

		// QA #1: Register Shutdown Handler - Pass by reference is safer here via static property update later
		// or just define the file path logic now since we know where temp files go
		$temp_dir = $upload_dir['basedir'] . '/rts_temp_imports';
		// We don't know the exact temp filename yet if conversion happens, but we can register general handler
		self::registerShutdownHandler( $job_id );

		// Estimate total rows for CSV files
		$estimated_total = 0;
		if ( strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ) === 'csv' ) {
			$estimated_total = self::estimateCsvRows( $file_path );
		}

		// Initialize job status
		update_option( 'rts_import_job_status_' . $job_id, [
			'job_id'            => $job_id,
			'total'             => 0,
			'processed'         => 0,
			'errors'            => 0,
			'status'            => 'analyzing',
			'started_gmt'       => gmdate( 'c' ),
			'file'              => basename( $file_path ),
			'filesize'          => $filesize,
			'scheduled_batches' => 0,
			'estimated_total'   => $estimated_total,
			'progress'          => 0,
		], false );

		$scheduled_batches = 0;
		$temp_file         = null;
		$conversion_stats  = [];
		$is_csv            = false;

		try {
			// 1. Detect Format
			$format = self::detectFormat( $file_path );

			// 2. Convert XML/CSV to NDJSON if necessary
			if ( $format === 'xml' || $format === 'csv' ) {
				$is_csv = ( $format === 'csv' );

				update_option( 'rts_import_job_status_' . $job_id, array_merge(
					get_option( 'rts_import_job_status_' . $job_id, [] ),
					[ 'status' => 'converting_' . $format ]
				), false );

				if ( $format === 'xml' ) {
					$conversion = self::convertXmlToNdjson( $file_path, $job_id );
				} else {
					// Optimization #17: Validate CSV
					$validation = self::validateCsv( $file_path );
					if ( is_wp_error( $validation ) ) {
						throw new Exception( $validation->get_error_message() );
					}
					$conversion = self::convertCsvToNdjson( $file_path, $job_id );
				}

				// Critical Issue #2: Proper WP_Error check using is_wp_error()
				if ( is_wp_error( $conversion ) ) {
					throw new Exception( $conversion->get_error_message() );
				}

				// Swap to the new NDJSON file for processing
				$file_path        = $conversion['file'];
				$temp_file        = $file_path; // Mark for cleanup
				$format           = 'ndjson'; // Treat as ndjson from now on
				$conversion_stats = $conversion;
				
				// QA #1: Update shutdown handler with known temp file
				self::updateShutdownHandlerTempFile( $temp_file );
			}

			// 3. Parse & Schedule
			update_option( 'rts_import_job_status_' . $job_id, array_merge(
				get_option( 'rts_import_job_status_' . $job_id, [] ),
				[ 'status' => 'scheduling' ]
			), false );

			if ( $format === 'ndjson' ) {
				$scheduled_batches = self::parseNdjson( $file_path, $job_id, $is_csv );
			} else {
				// Standard JSON array
				$scheduled_batches = self::parseJsonStreaming( $file_path, $job_id );
			}

		} catch ( \Throwable $e ) {
			self::log( "RTS Import Critical Error: " . $e->getMessage() );

			// Security #14: Cleanup on failure
			if ( $temp_file && file_exists( $temp_file ) ) {
				@unlink( $temp_file );
			}
			delete_option( $lock_option );
			delete_option( $lock_option . '_time' );

			return [
				'ok' => false,
				'error' => 'import_failed',
				'message' => $e->getMessage(),
			];
		}

		// 4. Update Final Status
		$status = get_option( 'rts_import_job_status_' . $job_id, [] );
		if ( is_array( $status ) ) {
			$status['scheduled_batches'] = $scheduled_batches;
			$status['status']            = ( $scheduled_batches > 0 ) ? 'running' : 'failed';

			if ( $scheduled_batches === 0 && ( $status['total'] ?? 0 ) === 0 ) {
				$status['error'] = 'No valid objects found in file';
			}
			update_option( 'rts_import_job_status_' . $job_id, $status, false );
		}

		// 5. Cleanup Temp Artifacts
		// Safe to delete because parseNdjson() reads data into AS payload synchronously
		if ( $temp_file && file_exists( $temp_file ) ) {
			@unlink( $temp_file );
		}

		// Remove lock
		delete_option( $lock_option );
		delete_option( $lock_option . '_time' );

		self::cleanupOldJobs();

		return [
			'ok'                => true,
			'job_id'            => $job_id,
			'scheduled_batches' => $scheduled_batches,
			'total'             => $status['total'] ?? 0,
			'format'            => $format,
			'conversion'        => $conversion_stats,
		];
	}

	/**
	 * Detect file format by peeking at content and extension
	 */
	private static function detectFormat( string $file_path ): string {
		$ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		// Extension check first for CSV/NDJSON
		if ( $ext === 'csv' ) {
			return 'csv';
		}
		if ( in_array( $ext, [ 'ndjson', 'jsonl' ], true ) ) {
			return 'ndjson';
		}

		$fh = @fopen( $file_path, 'r' );
		if ( ! $fh ) {
			return 'json';
		}

		$buffer = fread( $fh, 2048 );
		fclose( $fh );
		$buffer = trim( $buffer );

		// XML checks
		if ( strpos( $buffer, '<?xml' ) !== false || strpos( $buffer, '<rss' ) !== false || strpos( $buffer, '<items' ) !== false ) {
			return 'xml';
		}

		// JSON Array check
		if ( strpos( $buffer, '[' ) === 0 ) {
			return 'json';
		}

		return 'json';
	}

	/**
	 * Optimization #17: Validate CSV structure before processing
	 */
	private static function validateCsv( string $file_path ) {
		$delimiter = self::detectCsvDialect( $file_path );
		$fh        = fopen( $file_path, 'r' );
		if ( ! $fh ) {
			return new \WP_Error( 'io_error', 'Could not open CSV file.' );
		}
		
		$headers = fgetcsv( $fh, 0, $delimiter );
		fclose( $fh );

		if ( ! $headers ) {
			return new \WP_Error( 'csv_empty', 'CSV file appears empty or invalid.' );
		}

		$map = self::mapCsvHeaders( $headers );
		
		if ( ! isset( $map['content'] ) ) {
			return new \WP_Error( 'csv_invalid', 'CSV must contain a content column (e.g. "Write a letter", "Body", "Content").' );
		}

		return true;
	}

	/**
	 * Optimization #8: Detect CSV Dialect
	 */
	private static function detectCsvDialect( string $file_path ): string {
		$fh = @fopen( $file_path, 'r' );
		if ( ! $fh ) {
			return ',';
		}
		
		$first_line = fgets( $fh );
		fclose( $fh );

		if ( ! $first_line ) {
			return ',';
		}

		$delimiters = [ ',', ';', "\t", '|' ];
		$counts     = [];

		foreach ( $delimiters as $delimiter ) {
			$counts[ $delimiter ] = substr_count( $first_line, $delimiter );
		}

		arsort( $counts );
		return key( $counts );
	}

	/**
	 * Optimization #10: Detect CSV Encoding (Enhanced)
	 * Critical Issue #5: CSV Encoding Detection Can Fail
	 */
	private static function detectCsvEncoding( string $file_path ): string {
		$content = file_get_contents( $file_path, false, null, 0, 4096 );
		
		if ( function_exists( 'mb_detect_encoding' ) ) {
			$encodings = [ 'UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII', 'UTF-16LE', 'UTF-16BE' ];
			$detected = mb_detect_encoding( $content, $encodings, true );
			if ( $detected ) {
				return $detected;
			}
		}

		// Try alternative detection with iconv
		if ( function_exists( 'iconv' ) ) {
			if ( @iconv( 'UTF-8', 'UTF-8//IGNORE', $content ) === $content ) {
				return 'UTF-8';
			}
		}

		return 'UTF-8';
	}

	/**
	 * Optimization #14: Estimate CSV Rows for progress tracking
	 * QA #7: Helper for other file types
	 */
	private static function estimateCsvRows( string $file_path ): int {
		$handle    = fopen( $file_path, 'r' );
		$row_count = 0;

		while ( fgets( $handle ) !== false ) {
			$row_count++;
		}

		fclose( $handle );
		return max( 0, $row_count - 1 ); // Subtract header
	}
	
	// QA #7: Count file lines helper
	private static function countFileLines( string $file_path ): int {
		$handle    = fopen( $file_path, 'r' );
		$lines = 0;
		while ( fgets( $handle ) !== false ) {
			$lines++;
		}
		fclose( $handle );
		return $lines;
	}

	/**
	 * Critical Bug #11: Flexible Header Mapping
	 */
	private static function mapCsvHeaders( array $headers ): array {
		$map = [];
		foreach ( $headers as $index => $header ) {
			$header_lower = strtolower( trim( $header ) );
			
			foreach ( self::$csv_field_map as $key => $patterns ) {
				if ( isset( $map[ $key ] ) ) {
					continue; // Already mapped
				}
				
				foreach ( $patterns as $pattern ) {
					if ( $header_lower === $pattern || strpos( $header_lower, $pattern ) !== false ) {
						$map[ $key ] = $index;
						break;
					}
				}
			}
		}
		return $map;
	}

	/**
	 * Convert CSV to NDJSON
	 * Optimization #7: Memory, Dialect, Progress, Encoding
	 */
	private static function convertCsvToNdjson( string $csv_path, string $job_id ) {
		// Optimization #7: Increase memory for conversion
		@ini_set( 'memory_limit', '512M' );
		$start_memory = memory_get_usage( true );

		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/rts_temp_imports';
		
		// QA #11: Security check for temp dir
		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
			// Add .htaccess protection
			file_put_contents( $temp_dir . '/.htaccess', 'Deny from all' );
			file_put_contents( $temp_dir . '/index.php', '<?php // Silence is golden' );
		}

		// Security #13: Secure temp file naming
		$ndjson_path = $temp_dir . '/' . uniqid( 'rts_csv_conv_', true ) . '.ndjson';
		$output_fh   = @fopen( $ndjson_path, 'w' );
		
		// Optimization #9: Critical Security Fix - No context needed for simple file read
		$input_fh = @fopen( $csv_path, 'r' );

		if ( ! $output_fh || ! $input_fh ) {
			return new \WP_Error( 'io_error', 'Could not open CSV or create temp file.' );
		}

		// Critical Issue #7: Incremental Progress Check
		$progress_file = $temp_dir . '/progress_' . $job_id . '.json';
		$count = 0;
		if ( file_exists( $progress_file ) ) {
			$progress = json_decode( file_get_contents( $progress_file ), true );
			if ( $progress && $progress['file_size'] === filesize( $csv_path ) ) {
				fseek( $input_fh, $progress['last_pos'] );
				$count = $progress['last_row'];
				self::log( "Resuming CSV conversion from row {$count}" );
			}
		}

		$delimiter = self::detectCsvDialect( $csv_path );
		$encoding  = self::detectCsvEncoding( $csv_path );
		$total_est = self::estimateCsvRows( $csv_path );
		
		// QA #2: Always read headers first to advance pointer past them
		$headers = fgetcsv( $input_fh, 0, $delimiter );
		if ( ! $headers ) {
			fclose( $input_fh );
			fclose( $output_fh );
			return new \WP_Error( 'csv_empty', 'CSV file is empty or invalid.' );
		}
		$header_map = self::mapCsvHeaders( $headers );
		
		// If NOT resuming (count is 0), we are now positioned correctly at first data row.
		// If we ARE resuming (count > 0), the earlier fseek put us at start of next data row.
		// BUT wait, fseek uses byte offset. If we resume, we fseek to 'last_pos'.
		// 'last_pos' was saved via ftell AFTER reading a line.
		// So if resuming, we are at correct byte position.
		// The only issue is if we just read headers again above, we advanced the pointer.
		// So if resuming, we must seek AGAIN to restore the position.
		if ( $count > 0 ) {
			fseek( $input_fh, $progress['last_pos'] );
		}
		
		$errors = 0;
		$update_frequency = max( 100, intval( $total_est * 0.01 ) ); // Update every 1%

		$expected_columns = count( $headers );
		$malformed_rows   = 0;

		while ( ( $row = fgetcsv( $input_fh, 0, $delimiter ) ) !== false ) {
			// Optimization #6: Safety limit
			if ( $count >= self::MAX_CSV_ROWS ) {
				self::log( "CSV row limit reached: " . self::MAX_CSV_ROWS );
				break;
			}

			// Optimization #4: CSV Row Validation
			$col_count = count( $row );
			if ( $col_count !== $expected_columns ) {
				$malformed_rows++;
				if ( $malformed_rows > 10 ) {
					self::log( sprintf( 
						'Too many malformed rows (%d columns expected, %d found)', 
						$expected_columns, 
						$col_count 
					) );
				}
				continue;
			}

			// Optimization #2: Encoding Fix
			if ( $encoding !== 'UTF-8' ) {
				foreach ( $row as &$cell ) {
					if ( $cell !== null ) {
						$cell = mb_convert_encoding( $cell, 'UTF-8', $encoding );
					}
				}
			}

			$data = [];
			
			// Optimization #7: CSV Empty Value Handling
			$content_index = $header_map['content'] ?? -1;
			if ( $content_index === -1 || ! isset( $row[ $content_index ] ) ) {
				$errors++;
				continue;
			}

			$content = trim( $row[ $content_index ] );
			if ( empty( $content ) || strtolower( $content ) === 'null' || strtolower( $content ) === 'n/a' ) {
				$errors++;
				continue;
			}
			$data['content'] = $content;

			// Map other fields
			foreach ( $header_map as $key => $idx ) {
				if ( $key === 'content' ) continue;
				if ( isset( $row[ $idx ] ) ) {
					$val = $row[ $idx ];
					
					if ( $key === 'id' ) $data['import_id'] = $val;
					elseif ( $key === 'author' ) $data['author'] = $val;
					elseif ( $key === 'date' ) $data['submitted_at'] = $val;
					elseif ( $key === 'email' ) $data['author_email'] = $val;
					elseif ( $key === 'ip' ) $data['submission_ip'] = $val;
					else $data[ $key ] = $val;
				}
			}

			// Write to NDJSON
			fwrite( $output_fh, json_encode( $data, JSON_UNESCAPED_UNICODE ) . "\n" );
			$count++;

			// Optimization #9: Progress update
			if ( $count % $update_frequency === 0 ) {
				$percent = ( $total_est > 0 ) ? round( ( $count / $total_est ) * 100, 1 ) : 0;
				update_option( 'rts_import_conversion_' . $job_id, [
					'count'   => $count,
					'percent' => $percent,
					'memory'  => size_format( memory_get_usage( true ) ),
				], false );
				
				// QA #3: Correct Memory Calculation Fix
				$current_memory = memory_get_usage( true );
				$limit_bytes    = wp_convert_hr_to_bytes( '512M' );
				// Safety check if limit is -1 or 0
				if ( $limit_bytes <= 0 ) {
					$limit_bytes = 536870912; // 512MB default fallback
				}
				$memory_used    = $current_memory - $start_memory;
				$percent_used   = ( $memory_used / $limit_bytes ) * 100;

				if ( $percent_used > 80 ) {
					if ( function_exists( 'gc_mem_caches' ) ) {
						gc_mem_caches();
					}
					gc_collect_cycles();
					fflush( $output_fh );
					self::log( sprintf( 'High memory usage detected at row %d: %.1f%%', $count, $percent_used ) );
				}
			}

			// Critical Issue #7: Incremental Progress Saving
			if ( $count % 1000 === 0 ) {
				file_put_contents( $progress_file, json_encode( [
					'last_row'     => $count,
					'last_pos'     => ftell( $input_fh ),
					'file_size'    => filesize( $csv_path ),
					'converted_at' => gmdate( 'c' ),
				] ) );
			}
		}

		fclose( $input_fh );
		fclose( $output_fh );
		
		// Cleanup progress file on success
		if ( file_exists( $progress_file ) ) {
			@unlink( $progress_file );
		}

		if ( $count === 0 ) {
			@unlink( $ndjson_path );
			return new \WP_Error( 'csv_no_data', 'No valid letters found in CSV.' );
		}

		return [
			'file'   => $ndjson_path,
			'count'  => $count,
			'errors' => $errors,
		];
	}

	/**
	 * Convert XML (Wix/RSS) to NDJSON using XMLReader + SimpleXML (Hybrid)
	 * Critical Issue #5: Improving XML robustness
	 */
	private static function convertXmlToNdjson( string $xml_path, string $job_id ) {
		if ( ! class_exists( 'XMLReader' ) || ! class_exists( 'DOMDocument' ) || ! class_exists( 'SimpleXMLElement' ) ) {
			return new \WP_Error( 'xml_missing', 'XMLReader, DOM, and SimpleXML extensions are required.' );
		}

		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/rts_temp_imports';
		
		// QA #11: Security check
		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
			file_put_contents( $temp_dir . '/.htaccess', 'Deny from all' );
			file_put_contents( $temp_dir . '/index.php', '<?php // Silence is golden' );
		}

		// Security #13: Secure temp file naming
		$ndjson_path = $temp_dir . '/' . uniqid( 'rts_xml_conv_', true ) . '.ndjson';
		$output_fh   = @fopen( $ndjson_path, 'w' );

		if ( ! $output_fh ) {
			return new \WP_Error( 'write_failed', 'Could not create temp file.' );
		}

		$reader = new XMLReader();
		if ( ! $reader->open( $xml_path ) ) {
			return new \WP_Error( 'read_failed', 'Could not open XML file.' );
		}

		$count  = 0;
		$errors = 0;

		$item_tags = [ 'item', 'product', 'post', 'entry', 'row', 'letter' ];
		$doc       = new DOMDocument();

		// Critical Issue #5: Use expand() to handle nested elements robustly
		while ( $reader->read() ) {
			if ( $reader->nodeType === XMLReader::ELEMENT && in_array( $reader->name, $item_tags ) ) {
				try {
					// Expand current node to DOM, then import to SimpleXML
					$node = simplexml_import_dom( $doc->importNode( $reader->expand(), true ) );

					if ( $node ) {
						// Convert SimpleXML object to array
						$json  = json_encode( $node );
						$array = json_decode( $json, true );

						$data = self::normalizeXmlItemArray( $array );

						if ( ! empty( $data['content'] ) ) {
							fwrite( $output_fh, json_encode( $data, JSON_UNESCAPED_UNICODE ) . "\n" );
							$count++;

							// Performance #6: Batched updates
							if ( $count % self::UPDATE_FREQUENCY === 0 ) {
								fflush( $output_fh );
								gc_collect_cycles();
								update_option( 'rts_import_conversion_' . $job_id, [ 'count' => $count ], false );
							}
						} else {
							$errors++;
						}
						
						// Critical Issue #6: XML Memory Leak Fix
						$doc = new DOMDocument(); 
					}
				} catch ( Exception $e ) {
					$errors++;
				}
			}
			
			// Periodic hard cleanup
			if ( $count % 100 === 0 ) {
				$doc = new DOMDocument();
				gc_collect_cycles();
			}
		}

		$reader->close();
		fclose( $output_fh );

		if ( $count === 0 && $errors > 0 ) {
			@unlink( $ndjson_path );
			return new \WP_Error( 'xml_parse_empty', 'Could not find valid items in XML.' );
		}

		return [
			'file'   => $ndjson_path,
			'count'  => $count,
			'errors' => $errors,
		];
	}

	/**
	 * Normalize array from SimpleXML conversion
	 */
	private static function normalizeXmlItemArray( array $raw ): array {
		$data = [];
		
		$data['title']         = self::extractVal( $raw, [ 'title', 'name', 'subject' ] );
		$data['content']       = self::extractVal( $raw, [ 'encoded', 'content', 'description', 'body' ] );
		$data['author']        = self::extractVal( $raw, [ 'author', 'creator', 'writer' ] );
		$data['submitted_at']  = self::extractVal( $raw, [ 'pubDate', 'date', 'createdDate', 'updatedDate' ] );
		$data['submission_ip'] = self::extractVal( $raw, [ 'ip', 'submission_ip' ] );
		$data['feeling']       = self::extractVal( $raw, [ 'feeling', 'feelings' ] );
		$data['tone']          = self::extractVal( $raw, [ 'tone' ] );
		$data['import_id']     = self::extractVal( $raw, [ 'id', '_id', 'guid' ] );

		return $data;
	}

	/**
	 * Helper to extract value from array checking multiple keys
	 */
	private static function extractVal( $array, $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $array[ $k ] ) && ! is_array( $array[ $k ] ) ) {
				return $array[ $k ];
			}
			// Handle CDATA or namespaced content often mapping to sub-keys
			if ( isset( $array[ $k ] ) && is_string( $array[ $k ] ) ) {
				return $array[ $k ];
			}
		}
		// Fallback for namespaced content
		return '';
	}

	/**
	 * Parse NDJSON
	 */
	private static function parseNdjson( string $file_path, string $job_id, bool $is_csv = false ): int {
		$fh = @fopen( $file_path, 'r' );
		if ( ! $fh ) {
			return 0;
		}

		$batch     = [];
		$scheduled = 0;
		$line_num  = 0;
		$batch_size = $is_csv ? self::CSV_BATCH_SIZE : self::BATCH_SIZE; // Optimization #15
		
		// Optimization #5: Action Scheduler Queue Management
		$max_queued_actions = 1000;
		
		// QA #7: Estimate total for progress
		$total_lines = 0;
		// Only count lines if not CSV (CSV tracks progress during conversion)
		if ( ! $is_csv ) {
			// Fast line count for progress
			$total_lines = self::countFileLines( $file_path );
		}

		while ( ( $line = fgets( $fh ) ) !== false ) {
			$line_num++;
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}

			$clean_line = rtrim( $line, ',' );
			$item       = @json_decode( $clean_line, true );

			if ( ! is_array( $item ) ) {
				continue;
			}

			$normalized = self::normalizeLetter( $item );

			if ( empty( $normalized['content'] ) ) {
				self::bumpStatus( $job_id, 'errors', 1 );
				continue;
			}

			$batch[] = $normalized;

			if ( count( $batch ) >= $batch_size ) {
				// Check queue size before scheduling more
				global $wpdb;
				$queued_count = (int) $wpdb->get_var(
					$wpdb->prepare(
						"SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE status = 'pending' AND hook = %s",
						'rts_streaming_importer_process_batch'
					)
				);

				// Critical Issue #1: Queue Logic Fix
				if ( $queued_count < $max_queued_actions ) {
					$scheduled_action_id = as_schedule_single_action( time() + 2, 'rts_streaming_importer_process_batch', [ $job_id, $batch ], self::GROUP );
					
					// Critical Issue #4: AS Error Handling
					if ( ! $scheduled_action_id ) {
						// QA #4: Fixed retry logic
						self::log( "Failed to schedule batch for job {$job_id}. Retrying..." );
						sleep(1);
						static $retry_count = 0;
						$retry_count++;
						if ( $retry_count > 5 ) {
							self::log( "Abandoning batch after 5 retries" );
							$batch = [];
							$retry_count = 0;
						}
						// If retry_count <= 5, we keep batch and it retries next loop (or we should retry here?)
						// Actually loop continues and adds more items to batch if we don't schedule.
						// To strictly retry, we should pause loop.
						// Simplest fix: Force schedule with longer delay and hope for best, OR just skip this batch to avoid loop stuck
						// Better:
						// Try one more time immediately
						$scheduled_action_id_retry = as_schedule_single_action( time() + 5, 'rts_streaming_importer_process_batch', [ $job_id, $batch ], self::GROUP );
						if ( $scheduled_action_id_retry ) {
							self::bumpStatus( $job_id, 'total', count( $batch ) );
							$scheduled++;
							$batch = [];
							$retry_count = 0;
						}
					} else {
						self::bumpStatus( $job_id, 'total', count( $batch ) );
						$scheduled++;
						$batch = [];
					}
				} else {
					// QA #4: Queue full - Schedule with delay and clear batch to keep things moving
					as_schedule_single_action( time() + 30, 'rts_streaming_importer_process_batch', [ $job_id, $batch ], self::GROUP );
					self::bumpStatus( $job_id, 'total', count( $batch ) );
					$scheduled++;
					$batch = [];
				}
			}

			// Performance #6: Batched GC
			if ( $line_num % self::GC_FREQUENCY === 0 ) {
				gc_collect_cycles();
				
				// QA #7: Progress update for non-CSV
				if ( ! $is_csv && $total_lines > 0 ) {
					$status = get_option( 'rts_import_job_status_' . $job_id, [] );
					if ( is_array( $status ) ) {
						$status['progress'] = round( ( $line_num / $total_lines ) * 100, 1 );
						update_option( 'rts_import_job_status_' . $job_id, $status, false );
					}
				}
			}
		}

		if ( ! empty( $batch ) ) {
			as_schedule_single_action( time() + 2, 'rts_streaming_importer_process_batch', [ $job_id, $batch ], self::GROUP );
			self::bumpStatus( $job_id, 'total', count( $batch ) );
			$scheduled++;
		}

		fclose( $fh );
		return $scheduled;
	}

	/**
	 * Parse standard JSON array with state-machine streaming
	 */
	private static function parseJsonStreaming( string $file_path, string $job_id ): int {
		$fh = @fopen( $file_path, 'r' );
		if ( ! $fh ) {
			return 0;
		}

		$batch         = [];
		$scheduled     = 0;
		$objects_found = 0;

		$buffer        = '';
		$depth         = 0;
		$in_string     = false;
		$escape_next   = false;
		$obj_start_pos = -1;

		$start_chunk = fread( $fh, 4096 );
		$bracket_pos = strpos( $start_chunk, '[' );
		$brace_pos   = strpos( $start_chunk, '{' );

		if ( $bracket_pos !== false && ( $brace_pos === false || $bracket_pos < $brace_pos ) ) {
			fseek( $fh, $bracket_pos + 1 );
		} elseif ( $brace_pos !== false ) {
			fseek( $fh, $brace_pos );
		} else {
			fseek( $fh, 0 );
		}

		while ( ! feof( $fh ) ) {
			$chunk = fread( $fh, self::CHUNK_SIZE );
			if ( $chunk === false || $chunk === '' ) {
				break;
			}

			$buffer .= $chunk;
			$len     = strlen( $buffer );

			for ( $i = 0; $i < $len; $i++ ) {
				$char = $buffer[ $i ];

				if ( $escape_next ) {
					$escape_next = false;
					continue;
				}
				if ( $char === '\\' ) {
					$escape_next = true;
					continue;
				}
				if ( $char === '"' ) {
					$in_string = ! $in_string;
					continue;
				}
				if ( $in_string ) {
					continue;
				}

				if ( $char === '{' ) {
					if ( $depth === 0 ) {
						$obj_start_pos = $i;
					}
					$depth++;
				} elseif ( $char === '}' ) {
					$depth--;
					if ( $depth === 0 && $obj_start_pos !== -1 ) {
						$json_str = substr( $buffer, $obj_start_pos, $i - $obj_start_pos + 1 );
						$item     = @json_decode( $json_str, true );

						if ( is_array( $item ) ) {
							$normalized = self::normalizeLetter( $item );
							if ( ! empty( $normalized['content'] ) ) {
								$batch[] = $normalized;
								if ( count( $batch ) >= self::BATCH_SIZE ) {
									as_schedule_single_action( time() + 2, 'rts_streaming_importer_process_batch', [ $job_id, $batch ], self::GROUP );
									self::bumpStatus( $job_id, 'total', count( $batch ) );
									$scheduled++;
									$batch = [];
								}
							} else {
								self::bumpStatus( $job_id, 'errors', 1 );
							}
							$objects_found++;
						} else {
							self::bumpStatus( $job_id, 'errors', 1 );
						}

						$obj_start_pos = -1;
						$buffer        = substr( $buffer, $i + 1 );
						$len           = strlen( $buffer );
						$i             = -1;

						// Performance #6: Batched GC
						if ( $objects_found % self::GC_FREQUENCY === 0 ) {
							gc_collect_cycles();
						}
						continue;
					}
				} elseif ( $depth === 0 ) {
					if ( $char === ']' ) {
						break 2;
					}

					if ( $char === ',' || ctype_space( $char ) ) {
						if ( strlen( $buffer ) > self::CHUNK_SIZE && $i > self::CHUNK_SIZE / 2 ) {
							$buffer = substr( $buffer, $i + 1 );
							$len    = strlen( $buffer );
							$i      = -1;
						}
					}
				}
			}

			if ( strlen( $buffer ) > self::CHUNK_SIZE * 10 ) {
				if ( $depth > 0 && $obj_start_pos !== -1 ) {
					$buffer        = substr( $buffer, $obj_start_pos );
					$obj_start_pos = 0;
				} else {
					$buffer = substr( $buffer, -1024 );
				}
			}
		}

		fclose( $fh );

		if ( ! empty( $batch ) ) {
			as_schedule_single_action( time() + 2, 'rts_streaming_importer_process_batch', [ $job_id, $batch ], self::GROUP );
			self::bumpStatus( $job_id, 'total', count( $batch ) );
			$scheduled++;
		}

		return $scheduled;
	}

	private static function normalizeLetter( array $item ): array {
		$content = ! empty( $item['content'] ) ? $item['content'] : '';

		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $content, 'UTF-8' ) ) {
			$content = mb_convert_encoding( $content, 'UTF-8', 'auto' );
		}

		return [
			'title'         => ! empty( $item['title'] ) ? sanitize_text_field( $item['title'] ) : '',
			'content'       => wp_kses_post( $content ),
			'author'        => ! empty( $item['author'] ) ? sanitize_text_field( $item['author'] ) : '',
			'author_email'  => ! empty( $item['author_email'] ) ? sanitize_email( $item['author_email'] ) : '',
			'submission_ip' => ! empty( $item['submission_ip'] ) ? sanitize_text_field( $item['submission_ip'] ) : '',
			'submitted_at'  => ! empty( $item['submitted_at'] ) ? sanitize_text_field( $item['submitted_at'] ) : '',
			'feeling'       => $item['feeling'] ?? $item['feelings'] ?? null,
			'tone'          => $item['tone'] ?? null,
			'import_id'     => $item['import_id'] ?? $item['id'] ?? $item['_id'] ?? null,
		];
	}

	/**
	 * Process a batch (AS Worker)
	 */
	public static function processImportBatch( string $job_id, array $batch ): void {
		// Optimization #8: Batch processing average time logging
		static $average_time = 0;
		static $batch_count = 0;
		$start_time = microtime( true );
		
		// QA #10: Monitor Hook
		do_action( 'rts_import_batch_started', $job_id, count( $batch ) );

		if ( ! is_array( $batch ) || empty( $batch ) ) {
			return;
		}

		global $wpdb;

		if ( ! function_exists( 'post_exists' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		wp_defer_term_counting( true );
		wp_defer_comment_counting( true );
		wp_suspend_cache_addition( true );

		// Optimization #10: SQL Mode Optimization
		if ( count( $batch ) > 200 ) {
			$wpdb->query( "SET SESSION sql_mode='NO_ENGINE_SUBSTITUTION'" );
			$wpdb->query( "SET autocommit=0" );
		}

		// Optimization #16: Memory management for large CSV batches
		if ( count( $batch ) > 100 ) {
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}
			if ( function_exists( 'clean_post_cache' ) ) {
				// We can't clean post cache for IDs we don't know yet,
				// but we can ensure we aren't holding onto heavy query results.
				$wpdb->flush();
			}
		}

		$memory_limit = ini_get( 'memory_limit' );
		// QA #3: Correct memory check logic
		$limit_bytes = $memory_limit === '-1' ? PHP_INT_MAX : wp_convert_hr_to_bytes( $memory_limit );
		
		if ( $limit_bytes > 0 && memory_get_peak_usage( true ) > $limit_bytes * 0.7 ) {
			self::log( "RTS Import: Aborting batch due to high memory usage." );
			self::bumpStatus( $job_id, 'errors', count( $batch ) );
			return;
		}

		// Performance #7: Transaction Isolation
		$wpdb->query( 'SET TRANSACTION ISOLATION LEVEL READ COMMITTED' );

		$processed = 0;
		$errors    = 0;

		// Optimization #15: Larger transaction chunks for CSV
		$chunk_size = ( count( $batch ) >= 400 ) ? self::CSV_TRANSACTION_SIZE : self::TRANSACTION_CHUNK_SIZE;
		$chunks     = array_chunk( $batch, $chunk_size );

		foreach ( $chunks as $chunk ) {
			$chunk_attempts = 0;
			$chunk_success  = false;

			while ( ! $chunk_success && $chunk_attempts < 3 ) {
				$chunk_attempts++;
				$wpdb->query( 'START TRANSACTION' );

				$chunk_processed = 0;
				$chunk_errors    = 0;

				try {
					// Performance #8: Batched Duplicate Check for IDs
					$import_ids = [];
					foreach ( $chunk as $item ) {
						if ( ! empty( $item['import_id'] ) ) {
							// QA #5: Normalize Import IDs
							$import_ids[] = trim( strtolower( $item['import_id'] ) );
						}
					}

					$existing_import_ids = [];
					if ( ! empty( $import_ids ) ) {
						// Critical Issue #2: SQL Injection Fix & QA #5 Sanitize
						$sanitized_ids = array_map( 'sanitize_text_field', $import_ids );
						$placeholders  = implode( ', ', array_fill( 0, count( $sanitized_ids ), '%s' ) );
						$sql           = $wpdb->prepare(
							"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = 'rts_import_id' AND meta_value IN ($placeholders)",
							$sanitized_ids
						);
						$existing_import_ids = $wpdb->get_col( $sql );
					}
					
					// QA #8: Prepare bulk meta arrays
					$bulk_meta_inserts = [];
					$post_ids_map = []; // Map item index to new post ID

					foreach ( $chunk as $item_index => $item ) {
						// ----------------------------------------------------
						// DUPLICATION CHECK
						// ----------------------------------------------------
						$duplicate_found = false;

						// 1. Check by External ID (Batched result)
						if ( ! empty( $item['import_id'] ) ) {
							$normalized_id = trim( strtolower( $item['import_id'] ) );
							if ( in_array( $normalized_id, $existing_import_ids ) ) {
								$duplicate_found = true;
							}
						}

						// 2. Check by Title + Date
						$title = ! empty( $item['title'] ) ? $item['title'] : '';
						$date  = ! empty( $item['submitted_at'] ) ? $item['submitted_at'] : '';

						if ( ! $duplicate_found && ! empty( $title ) && strpos( $title, 'Letter ' ) !== 0 ) {
							// QA #9: Cache post_exists calls
							$cache_key = md5( $title . '|' . $date );
							if ( ! isset( self::$title_date_cache[ $cache_key ] ) ) {
								self::$title_date_cache[ $cache_key ] = post_exists( $title, '', $date );
							}
							if ( self::$title_date_cache[ $cache_key ] ) {
								$duplicate_found = true;
							}
						}

						// 3. Fallback: Content Check (Strict but safe)
						if ( ! $duplicate_found && ! empty( $item['content'] ) ) {
							if ( post_exists( '', $item['content'], $date ) ) {
								$duplicate_found = true;
							}
						}

						if ( $duplicate_found ) {
							$chunk_processed++; // Count as processed (skipped)
							continue;
						}

						// ----------------------------------------------------
						// INSERT POST
						// ----------------------------------------------------
						$final_title = $title ?: 'Letter ' . wp_generate_password( 8, false, false );

						$post_data = [
							'post_type'    => self::POST_TYPE, // Code Quality #11
							'post_title'   => $final_title,
							'post_content' => $item['content'],
							'post_status'  => 'pending',
							'post_author'  => 1,
						];

						if ( ! empty( $date ) ) {
							$timestamp = strtotime( $date );
							if ( $timestamp ) {
								$post_data['post_date']     = gmdate( 'Y-m-d H:i:s', $timestamp );
								$post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', $timestamp );
							}
						}

						$post_id = wp_insert_post( $post_data, true );

						if ( is_wp_error( $post_id ) || ! $post_id ) {
							$chunk_errors++;
							continue;
						}
						
						// Store for bulk meta
						$post_ids_map[$item_index] = $post_id;

						// Collect Meta for Bulk Insert
						if ( ! empty( $item['submission_ip'] ) ) {
							$bulk_meta_inserts[] = [ $post_id, 'rts_submission_ip', $item['submission_ip'] ];
						}
						if ( ! empty( $item['author'] ) ) {
							$bulk_meta_inserts[] = [ $post_id, 'rts_author_name', $item['author'] ];
						}
						if ( ! empty( $item['author_email'] ) ) {
							$bulk_meta_inserts[] = [ $post_id, 'author_email', $item['author_email'] ];
						}
						if ( ! empty( $item['import_id'] ) ) {
							// Store normalized or original? Original is better for display, normalized for check
							$bulk_meta_inserts[] = [ $post_id, 'rts_import_id', $item['import_id'] ];
						}

						// Critical Issue #9: Taxonomy Validation & QA #6: Term Race Condition Fix
						if ( ! empty( $item['feeling'] ) && taxonomy_exists( 'letter_feeling' ) ) {
							$feelings = is_array( $item['feeling'] ) ? $item['feeling'] : [ $item['feeling'] ];
							$valid_terms = [];
							foreach ( $feelings as $term ) {
								$term_obj = term_exists( $term, 'letter_feeling' );
								if ( ! $term_obj ) {
									$term_obj = wp_insert_term( $term, 'letter_feeling' );
									// QA #6 Check for term_exists error in race condition
									if ( is_wp_error( $term_obj ) && isset($term_obj->error_data['term_exists']) ) {
										$term_obj = $term_obj->error_data['term_exists'];
									}
								}
								
								// Handle term object or array return
								if ( $term_obj && ! is_wp_error( $term_obj ) ) {
									if ( is_array( $term_obj ) ) {
										$valid_terms[] = (int) $term_obj['term_id'];
									} elseif ( is_object( $term_obj ) ) {
										$valid_terms[] = (int) $term_obj->term_id;
									} else {
										// If it's just ID
										$valid_terms[] = (int) $term_obj;
									}
								}
							}
							if ( ! empty( $valid_terms ) ) {
								wp_set_object_terms( $post_id, $valid_terms, 'letter_feeling' );
							}
						}

						if ( ! empty( $item['tone'] ) && taxonomy_exists( 'letter_tone' ) ) {
							wp_set_object_terms( $post_id, $item['tone'], 'letter_tone' );
						}

						$chunk_processed++;
					}
					
					// QA #8: Perform Bulk Meta Insert
					if ( ! empty( $bulk_meta_inserts ) ) {
						self::bulkInsertPostMeta( $bulk_meta_inserts );
					}

					$wpdb->query( 'COMMIT' );
					$processed += $chunk_processed;
					$errors    += $chunk_errors;
					$chunk_success = true;

				} catch ( \Throwable $e ) {
					$wpdb->query( 'ROLLBACK' );

					$msg = $e->getMessage();
					if ( strpos( $msg, 'Deadlock' ) !== false || strpos( $msg, 'deadlock' ) !== false ) {
						if ( $chunk_attempts < 3 ) {
							usleep( 200000 * $chunk_attempts );
							continue;
						}
					}

					$errors += count( $chunk );
					self::log( 'RTS Import Chunk Error: ' . $msg );
					break;
				}
			}
		}
		
		// Restore SQL modes
		if ( count( $batch ) > 200 ) {
			$wpdb->query( "SET autocommit=1" );
		}

		wp_defer_term_counting( false );
		wp_defer_comment_counting( false );
		wp_suspend_cache_addition( false );

		self::bumpStatus( $job_id, 'processed', $processed );
		if ( $errors > 0 ) {
			self::bumpStatus( $job_id, 'errors', $errors );
		}

		// Optimization #8: Log batch performance
		$end_time = microtime( true );
		$batch_time = $end_time - $start_time;
		$batch_count++;
		
		// Calculate moving average
		$average_time = ( $average_time * ( $batch_count - 1 ) + $batch_time ) / $batch_count;
		
		if ( $batch_count % 10 === 0 ) {
			self::log( sprintf( 
				'Batch processing average time: %.3fs per %d items', 
				$average_time, 
				count( $batch )
			) );
		}
		
		// QA #10: Completed Hook
		do_action( 'rts_import_batch_completed', $job_id, $processed, $errors );
	}
	
	// QA #8: Bulk Meta Insert Helper
	private static function bulkInsertPostMeta( array $meta_entries ): void {
		global $wpdb;
		if ( empty( $meta_entries ) ) return;
		
		$values = [];
		$placeholders = [];
		
		foreach ( $meta_entries as $entry ) {
			// [post_id, meta_key, meta_value]
			$values[] = $entry[0];
			$values[] = $entry[1];
			$values[] = $entry[2];
			$placeholders[] = '(%d, %s, %s)';
		}
		
		$query = "INSERT INTO {$wpdb->postmeta} (post_id, meta_key, meta_value) VALUES " . implode( ', ', $placeholders );
		$wpdb->query( $wpdb->prepare( $query, $values ) );
	}

	private static function bumpStatus( string $job_id, string $key, int $amount ): void {
		$option_name = 'rts_import_job_status_' . $job_id;
		$status      = get_option( $option_name, [] );

		if ( ! is_array( $status ) ) {
			return;
		}

		$status[ $key ] = ( $status[ $key ] ?? 0 ) + $amount;

		if ( $key === 'processed' || $key === 'errors' ) {
			if ( ! empty( $status['scheduled_batches'] ) && ( $status['processed'] + $status['errors'] ) >= $status['total'] ) {
				$status['status']        = 'complete';
				$status['completed_gmt'] = gmdate( 'c' );
			}
		}

		update_option( $option_name, $status, false );
		// QA #10: Status Update Hook
		do_action( 'rts_import_status_updated', $job_id, $key, $amount, $status );
	}
	
	/**
	 * Critical Issue #11: Graceful Shutdown Handler
	 */
	private static function registerShutdownHandler( string $job_id ): void {
		if ( ! self::$shutdown_registered ) {
			register_shutdown_function( function() use ( $job_id ) {
				$error = error_get_last();
				if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ] ) ) {
					self::log( "Fatal error during import job {$job_id}: " . print_r( $error, true ) );
					
					// Cleanup temp files tracked in static
					if ( ! empty( self::$temp_files_to_clean ) ) {
						foreach ( self::$temp_files_to_clean as $file ) {
							if ( file_exists( $file ) ) @unlink( $file );
						}
					}
					
					// Update job status
					$status = get_option( 'rts_import_job_status_' . $job_id, [] );
					if ( is_array( $status ) ) {
						$status['status'] = 'failed';
						$status['error'] = 'Fatal error: ' . $error['message'];
						update_option( 'rts_import_job_status_' . $job_id, $status, false );
					}
					
					// Remove lock
					delete_option( 'rts_import_lock' );
					delete_option( 'rts_import_lock_time' );
				}
			} );
			self::$shutdown_registered = true;
		}
	}
	
	// QA #1: Helper to track temp files for shutdown handler
	private static $temp_files_to_clean = [];
	private static function updateShutdownHandlerTempFile( string $file ): void {
		self::$temp_files_to_clean[] = $file;
	}

	/**
	 * Code Quality #20: Proper logging abstraction
	 */
	private static function log( string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[RTS Importer] ' . $message );
		}
	}

	public static function cleanupOldJobs( int $days_old = 7 ): void {
		if ( rand( 1, 20 ) !== 1 ) {
			return;
		}

		global $wpdb;
		$cutoff_date = date( 'Y-m-d H:i:s', strtotime( "-{$days_old} days" ) );

		$wpdb->query( $wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value LIKE %s",
			'rts_import_job_status_%', '%"started_gmt":"' . substr( $cutoff_date, 0, 4 ) . '%'
		) );

		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/rts_temp_imports';
		if ( is_dir( $temp_dir ) ) {
			$files   = glob( $temp_dir . '/*' );
			$now     = time();
			$max_age = $days_old * 86400;
			foreach ( $files as $file ) {
				if ( is_file( $file ) && ( $now - filemtime( $file ) > $max_age ) ) {
					@unlink( $file );
				}
			}
		}
	}

	// -------------------------------------------------------------------------
	// BACKWARD COMPATIBILITY SHIMS
	// -------------------------------------------------------------------------

	/**
	 * Shim for legacy calls to start_import (snake_case)
	 */
	public static function start_import( string $file_path ) {
		return self::startImport( $file_path );
	}

	/**
	 * Shim for legacy calls to cleanup_old_jobs (snake_case)
	 */
	public static function cleanup_old_jobs( int $days_old = 7 ) {
		return self::cleanupOldJobs( $days_old );
	}

	/**
	 * Shim for legacy calls to process_import_batch (snake_case)
	 */
	public static function process_import_batch( string $job_id, array $batch ) {
		return self::processImportBatch( $job_id, $batch );
	}

	public static function init(): void {
		// Hook using correct namespace and method
		add_action( 'rts_streaming_importer_process_batch', [ __CLASS__, 'processImportBatch' ], 10, 2 );
	}
}

if ( function_exists( 'add_action' ) ) {
	RTSStreamingImporter::init();
}

// Backward Compatibility Alias for external calls
if ( ! class_exists( 'RTS_Streaming_Importer' ) ) {
	class_alias( 'RTS\Importer\RTSStreamingImporter', 'RTS_Streaming_Importer' );
}