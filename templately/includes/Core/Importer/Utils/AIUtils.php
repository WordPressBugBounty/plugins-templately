<?php

namespace Templately\Core\Importer\Utils;

use Templately\Utils\Options;
use Templately\Utils\Helper;

/**
 * AI-related utility functions for Templately
 * Handles AI process data management using API key-based storage with count-based cleanup
 */
class AIUtils {

	/**
	 * Sanitize path component for security - prevents path traversal attacks
	 *
	 * @param mixed $value The value to sanitize (string or numeric)
	 * @param string $type Type for error message ('session_id', 'content_id', 'path_key')
	 * @return string|WP_Error Sanitized value or error if invalid
	 */
	public static function sanitize_path_component($value, $type = 'path_component') {
		// Convert numeric values to string (content IDs like 136 are valid)
		if (is_numeric($value)) {
			$value = (string) $value;
		}

		// Check for empty or non-string values
		if (empty($value) || !is_string($value)) {
			return Helper::error(
				'invalid_' . $type,
				sprintf(__('Invalid %s: empty or not a valid value.', 'templately'), $type),
				'sanitize_path_component',
				400
			);
		}

		// Check for path traversal attempts BEFORE sanitizing
		if (strpos($value, '..') !== false ||
			strpos($value, '/') !== false ||
			strpos($value, '\\') !== false) {
			return Helper::error(
				'invalid_' . $type,
				sprintf(__('Invalid %s: contains path separators.', 'templately'), $type),
				'sanitize_path_component',
				400
			);
		}

		// Apply WordPress sanitize_file_name for additional safety
		return sanitize_file_name($value);
	}

	/**
	 * Validate that a file path is within WordPress upload directory
	 * Prevents path traversal attacks
	 *
	 * @param string $file_path The file path to validate
	 * @return bool|WP_Error True if valid, WP_Error otherwise
	 */
	public static function validate_file_path($file_path) {
		// Get WordPress upload directory
		$upload_dir = wp_upload_dir();
		$real_upload_base = realpath($upload_dir['basedir']);

		if ($real_upload_base === false) {
			return Helper::error(
				'invalid_upload_dir',
				__('WordPress upload directory is not accessible.', 'templately'),
				'validate_file_path',
				500
			);
		}

		// For file path, check the directory if file doesn't exist yet
		$dir_to_check = dirname($file_path);

		// Create directory if it doesn't exist (for pre-write validation)
		if (!file_exists($dir_to_check)) {
			wp_mkdir_p($dir_to_check);
		}

		$real_path = realpath($dir_to_check);
		if ($real_path === false) {
			return Helper::error(
				'path_not_exists',
				__('File path does not exist or is not accessible.', 'templately'),
				'validate_file_path',
				400
			);
		}

		// Normalize paths with trailing directory separator
		$normalized_upload_base = rtrim($real_upload_base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		$normalized_path = rtrim($real_path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

		// Check if path starts with upload directory (PHP 8+ style with polyfill)
		// This prevents partial matches like /var/www/uploads-backup matching /var/www/uploads
		$is_subdirectory = false;
		if (function_exists('str_starts_with')) {
			// PHP 8+
			$is_subdirectory = str_starts_with($normalized_path, $normalized_upload_base);
		} else {
			// PHP 7.4 polyfill
			$is_subdirectory = substr($normalized_path, 0, strlen($normalized_upload_base)) === $normalized_upload_base;
		}

		if (!$is_subdirectory) {
			return Helper::error(
				'path_outside_uploads',
				__('Invalid file path: path is outside WordPress upload directory.', 'templately'),
				'validate_file_path',
				400
			);
		}

		return true;
	}

	/**
	 * Handle SSE message wait with timeout exit condition
	 * Static utility function for reusable timeout handling across different import contexts
	 *
	 * @param string $session_id Session ID for progress tracking
	 * @param string $progress_id Progress tracking identifier (e.g., 'ai_content_time', 'finalize_time')
	 * @param array $updated_ids Currently updated/processed pages
	 * @param array $ai_page_ids All AI pages that need processing
	 * @param callable $sse_message_callback Callback function for sending SSE messages
	 * @param array $additional_sse_data Additional data to include in SSE message
	 * @param string|null $old_template_id Optional template ID for local site polling
	 * @return bool True if should continue processing, false if should exit
	 */
	public static function handle_sse_wait_with_timeout($session_id, $progress_id, $updated_ids, $ai_page_ids, $sse_message_callback, $additional_sse_data = [], $old_template_id = null, $timeout_seconds = 420) {
		$total_pages = count($ai_page_ids);
		$updated_pages = count($updated_ids['pages'] ?? []);

		// If all pages are processed or credit cost is available, continue processing
		if ($total_pages <= $updated_pages || isset($updated_ids['credit_cost'])) {
			return true;
		}

		// Get timeout tracking data from session
		$session_data = Utils::get_session_data($session_id);
		$progress_data = $session_data['progress'][$progress_id] ?? [];
		$last_progress = $progress_data['last_progress'] ?? 0;
		$last_time = $progress_data['last_time'] ?? 0;
		$current_time = time();
		$progress_percentage = $total_pages > 0 ? round(($updated_pages / $total_pages) * 100) : 0;
		$is_local_site = $session_data['isLocalSite'] ?? false;

		// Skip timeout check if credit cost is available and time difference is > 10 seconds
		if (isset($updated_ids['credit_cost']) && !empty($last_time) && ($current_time - $last_time) > 10) {
			return true;
		}

		// Check if time difference is less than timeout(default 7 minutes) (timeout condition)
		if (empty($last_time) || ($current_time - $last_time) < $timeout_seconds) {
			// For local sites with template ID, attempt polling before waiting
			if ($is_local_site && !empty($old_template_id)) {
				$process_id = $session_data['process_id'] ?? null;
				if (!empty($process_id)) {
					// Call generic polling function to process all available templates
					$polling_result = self::poll_for_template($process_id, $session_id, $ai_page_ids);
					if ($polling_result === true) {
						// Check if the specific template file now exists after polling
						$upload_dir = wp_upload_dir();
						$tmp_dir = trailingslashit($upload_dir['basedir']) . 'templately' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $session_id . DIRECTORY_SEPARATOR;

						// Find the correct directory for the template ID
						$template_file_found = false;
						foreach ($ai_page_ids as $key => $ids) {
							if (in_array($old_template_id, $ids)) {
								$page_dir = $tmp_dir . $key . DIRECTORY_SEPARATOR;
								$file_path = $page_dir . $old_template_id . '.ai.json';
								if (file_exists($file_path)) {
									$template_file_found = true;
									break;
								}
							}
						}

						if ($template_file_found) {
							// Specific template found and downloaded, continue processing
							return true;
						}
					}
				}
			}

			// Only update time if progress has changed
			if ($progress_percentage !== $last_progress) {
				$updated_progress = $session_data['progress'] ?? [];
				$updated_progress[$progress_id] = [
					'last_progress' => $progress_percentage,
					'last_time' => $current_time,
				];
				Utils::update_session_data($session_id, ['progress' => $updated_progress]);
			}

			// Prepare SSE message data
			$sse_data = array_merge([
				'type'            => 'wait',
				'action'          => 'wait',
				'generated_pages' => $updated_ids,
				'all_pages'       => $ai_page_ids,
			], $additional_sse_data);

			// Send wait message and exit
			call_user_func($sse_message_callback, $sse_data);
			exit;
		}

		// Timeout exceeded - could send error message here if needed
		// Currently commented out in original AIContent.php
		/*
		call_user_func($sse_message_callback, [
			'action'   => 'error',
			'status'   => 'error',
			'type'     => "error",
			'title'    => __("Oops!", "templately"),
			'message'  => __("Taking too long....", "templately"),
		]);
		exit;
		*/

		// Continue processing if timeout exceeded (current behavior)
		return true;
	}

	/**
	 * Poll for AI template generation status on local sites
	 * Generic polling utility that processes all available templates
	 *
	 * @param string $process_id The AI process ID
	 * @param string $session_id The session ID
	 * @param array $ai_page_ids The AI page IDs structure
	 * @return bool True if polling was successful, false otherwise
	 */
	public static function poll_for_template($process_id, $session_id, $ai_page_ids) {
		// First check if polling is already complete
		$processed_pages = get_option("templately_ai_processed_pages", []);
		if (isset($processed_pages[$process_id]['is_last_part']) && $processed_pages[$process_id]['is_last_part']) {
			return true;
		}

		// Make GET request to API endpoint
		$response = Helper::make_api_get_request("v2/ai/{$process_id}/template", [], [], 30);

		if (is_wp_error($response)) {
			return false;
		}

		$response_code = wp_remote_retrieve_response_code($response);
		if ($response_code !== 200) {
			return false;
		}

		$body = wp_remote_retrieve_body($response);
		$api_data = json_decode($body, true);

		if (!isset($api_data['status']) || $api_data['status'] !== 'success') {
			return false;
		}

		$response_data = $api_data['data'] ?? [];

		// Extract required fields from API response
		$is_last_part = $response_data['is_last_part'] ?? false;
		$credit_cost = $response_data['credit_cost'] ?? 0;
		$templates = $response_data['templates'] ?? [];

		// Save credit_cost if available
		if ($credit_cost > 0) {
			$processed_pages = get_option("templately_ai_processed_pages", []);
			$processed_pages[$process_id] = $processed_pages[$process_id] ?? [];
			$processed_pages[$process_id]['credit_cost'] = $credit_cost;
			update_option("templately_ai_processed_pages", $processed_pages, false);
		}

		// Save is_last_part if true
		if ($is_last_part) {
			$processed_pages = get_option("templately_ai_processed_pages", []);
			$processed_pages[$process_id] = $processed_pages[$process_id] ?? [];
			$processed_pages[$process_id]['is_last_part'] = $is_last_part;
			update_option("templately_ai_processed_pages", $processed_pages, false);
		}

		// Process ALL templates if available (extracted from FullSiteImport::ai_poll_template)
		if (!empty($templates) && is_array($templates)) {
			foreach ($templates as $content_id => $template_data) {
				// Save template to file using the common helper function
				$result = self::save_template_to_file(
					$process_id,
					$session_id,
					$content_id,
					$template_data,
					$ai_page_ids,
					false // Not skipped
				);
				// Continue processing other templates even if one fails
			}
		}

		return true; // Return true if polling was successful, regardless of specific templates
	}

	/**
	 * Get AI process data from WordPress options
	 *
	 * @return array The AI process data array
	 */
	public static function get_ai_process_data() {
		$all_ai_process_data = get_option('templately_ai_process_data', []);
		return is_array($all_ai_process_data) ? $all_ai_process_data : [];
	}

	/**
	 * Update AI process data in WordPress options with process_id as key
	 * Appends to existing data
	 *
	 * @param array $data The AI process data to save (process_id => process_data)
	 * @return bool True on success, false on failure
	 */
	public static function update_ai_process_data($data) {
		if (!is_array($data)) {
			return false;
		}

		// Get existing data
		$all_ai_process_data = get_option('templately_ai_process_data', []);
		if (!is_array($all_ai_process_data)) {
			$all_ai_process_data = [];
		}

		// Append new data to existing data
		foreach ($data as $process_id => $process_data) {
			if (is_array($process_data)) {
				$all_ai_process_data[$process_id] = $process_data;
			}
		}

		return update_option('templately_ai_process_data', $all_ai_process_data);
	}

	/**
	 * Get the latest AI process data for the current API key or user ID
	 * Used in import_info() to return the most recent AI process
	 * Priority: api_key first, then user_id as fallback
	 *
	 * @return array|null The latest AI process data or null if not found
	 */
	public static function get_latest_ai_process_by_api_key($id) {
		$api_key = Options::get_instance()->get('api_key');
		$user = Options::get_instance()->get('user');
		$user_id = isset($user['id']) ? $user['id'] : null;

		$all_ai_process_data = get_option('templately_ai_process_data', []);
		if (!is_array($all_ai_process_data)) {
			return null;
		}

		$matching_processes = [];

		// Priority 1: Try to find processes by API key if available
		if (!empty($api_key)) {
			foreach ($all_ai_process_data as $process_id => $process_data) {
				if (is_array($process_data) && isset($process_data['api_key']) && $process_data['api_key'] === $api_key) {
					$matching_processes[$process_id] = $process_data;
				}
			}
		}

		// Priority 2: If no API key matches found or API key is empty, fallback to user_id
		if (empty($matching_processes) && !empty($user_id)) {
			foreach ($all_ai_process_data as $process_id => $process_data) {
				if (is_array($process_data) && isset($process_data['user_id']) && $process_data['user_id'] === $user_id) {
					$matching_processes[$process_id] = $process_data;
				}
			}
		}

		if (empty($matching_processes)) {
			return null;
		}

		$latest_data = end($matching_processes);

		if($id != $latest_data['pack_id'] && isset($latest_data['imageReplace'])){
			unset($latest_data['imageReplace']);
		}

		// Return the last (most recent) process
		return $latest_data;
	}



	/**
	 * Get AI process data by session ID
	 *
	 * @param string $session_id The session ID to search for
	 * @return array|null The AI process data array or null if not found
	 */
	public static function get_ai_process_data_by_session_id($session_id) {
		if (empty($session_id)) {
			return null;
		}

		$ai_process_data = self::get_ai_process_data();

		foreach ($ai_process_data as $process) {
			if (is_array($process) && isset($process['session_id']) && $process['session_id'] === $session_id) {
				return $process;
			}
		}

		return null;
	}

	/**
	 * Get AI process ID by session ID
	 *
	 * @param string $session_id The session ID to search for
	 * @return string|null The process ID (array key) or null if not found
	 */
	public static function get_ai_process_id_by_session_id($session_id) {
		if (empty($session_id)) {
			return null;
		}

		$ai_process_data = self::get_ai_process_data();

		foreach ($ai_process_data as $process_id => $process) {
			if (is_array($process) && isset($process['session_id']) && $process['session_id'] === $session_id) {
				return $process_id;
			}
		}

		return null;
	}

	/**
	 * Get AI process data by process ID
	 *
	 * @param string $process_id The process ID to search for
	 * @return array|null The AI process data array or null if not found
	 */
	public static function get_ai_process_data_by_process_id($process_id) {
		if (empty($process_id)) {
			return null;
		}

		$ai_process_data = self::get_ai_process_data();

		if (isset($ai_process_data[$process_id]) && is_array($ai_process_data[$process_id])) {
			return $ai_process_data[$process_id];
		}

		return null;
	}

	/**
	 * Clean AI process data by pack ID, keeping only the current process
	 * Removes all AI process entries with the same pack_id except the current process
	 *
	 * @param string $pack_id The pack ID to match for cleanup
	 * @param string $current_process_id The current process ID to preserve (optional)
	 * @return array Array of removed process IDs
	 */
	public static function clean_ai_process_data_by_pack_id($pack_id, $current_process_id = null) {
		if (empty($pack_id)) {
			return [];
		}

		$all_ai_process_data = get_option('templately_ai_process_data', []);
		if (!is_array($all_ai_process_data)) {
			return [];
		}

		$removed_process_ids = [];

		foreach ($all_ai_process_data as $process_id => $process_data) {
			if (!is_array($process_data)) {
				continue;
			}

			// Skip the current process by process_id if provided
			if (!empty($current_process_id) && $process_id === $current_process_id) {
				continue;
			}

			// Remove processes that have the same pack_id
			if (isset($process_data['pack_id']) && $process_data['pack_id'] === $pack_id) {
				unset($all_ai_process_data[$process_id]);
				$removed_process_ids[] = $process_id;
			}
		}

		// Update the options with cleaned data if any processes were removed
		if (!empty($removed_process_ids)) {
			update_option('templately_ai_process_data', $all_ai_process_data);
		}

		return $removed_process_ids;
	}

	/**
	 * Save template data to file
	 *
	 * Common function for saving templates to files, used by AI content operations
	 *
	 * @param string $process_id The process ID
	 * @param string $session_id The session ID
	 * @param string $content_id The content ID
	 * @param string $template The template data (base64 encoded or raw)
	 * @param array $ai_page_ids Array of AI page IDs
	 * @param bool $is_skipped Whether the template was skipped
	 * @return array|WP_Error Result array with status and data
	 */
	public static function save_template_to_file($process_id, $session_id, $content_id, $template, $ai_page_ids, $is_skipped = false) {
		// Security: Sanitize session_id
		$session_id = self::sanitize_path_component($session_id, 'session_id');
		if (is_wp_error($session_id)) {
			return $session_id;
		}

		// Security: Sanitize content_id
		$content_id = self::sanitize_path_component($content_id, 'content_id');
		if (is_wp_error($content_id)) {
			return $content_id;
		}

		$upload_dir = wp_upload_dir();

		// Always save to tmp directory for AI content workflow
		$tmp_dir = trailingslashit($upload_dir['basedir']) . 'templately' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $session_id . DIRECTORY_SEPARATOR;

		// Decode template if it's base64 encoded
		if (! empty($template) && base64_decode($template, true) !== false) {
			$template = base64_decode($template);
		}

		// Handle empty template (skipped)
		if (empty($template)) {
			$template = json_encode([
				"isSkipped" => true,
			]);
		}

		// Find the correct directory for the content ID
		$found_key = null;
		foreach ($ai_page_ids as $key => $ids) {
			if (in_array($content_id, $ids)) {
				$found_key = $key;
				break;
			}
		}

		if ($found_key === null) {
			return Helper::error('invalid_content_id', __('Content ID not found in AI page IDs.', 'templately'), 'save_template_to_file', 400);
		}

		// Security: Sanitize found_key parts (e.g., "templates/page" -> sanitize each part)
		$key_parts = explode('/', $found_key);
		$sanitized_parts = [];
		foreach ($key_parts as $part) {
			$sanitized = self::sanitize_path_component($part, 'path_key');
			if (is_wp_error($sanitized)) {
				return $sanitized;
			}
			$sanitized_parts[] = $sanitized;
		}
		$found_key = implode(DIRECTORY_SEPARATOR, $sanitized_parts);

		// Create directory and file path
		$page_dir = $tmp_dir . $found_key . DIRECTORY_SEPARATOR;
		$file_path = $page_dir . $content_id . '.ai.json';

		// Security: Validate path is within expected directory before writing
		$validation = self::validate_file_path($file_path);
		if (is_wp_error($validation)) {
			return $validation;
		}

		wp_mkdir_p($page_dir);

		// Save the file
		$is_success = file_put_contents($file_path, $template);

		if ($is_success) {
			// Update processed pages option
			$processed_pages = get_option("templately_ai_processed_pages", []);
			$processed_pages[$process_id] = $processed_pages[$process_id] ?? [];
			$processed_pages[$process_id]['pages'][$content_id] = $is_skipped;
			update_option("templately_ai_processed_pages", $processed_pages, false);

			return [
				'status' => 'success',
				'data'   => [
					'process_id'      => $process_id,
					// 'file_path'       => $file_path,
					'content_id'      => $content_id,
				],
			];
		}

		return Helper::error('file_save_failed', __('Failed to save template file.', 'templately'), 'save_template_to_file', 500);
	}

	/**
	 * Get matched session data by process ID
	 *
	 * Helper function to retrieve session data for a given process ID
	 *
	 * @param string $process_id The process ID to match
	 * @return array|false Returns matched data array or false if not found
	 */
	public static function get_matched_session_data($process_id) {
		if (empty($process_id)) {
			return false;
		}

		$all_data = Utils::get_all_session_data();

		if (empty($all_data) || ! is_array($all_data)) {
			return false;
		}

		// INSERT_YOUR_CODE
		if (is_array($all_data)) {
			$all_data = array_reverse($all_data);
		}
		foreach ($all_data as $data) {
			if (isset($data['process_id']) && ($data['process_id'] === $process_id)) {
				return $data;
			}
		}

		return false;
	}

	/**
	 * Generate AI file paths for content processing
	 *
	 * @param string $session_id The session ID
	 * @param string $type The content type (templates, content, etc.)
	 * @param string $sub_type The content sub-type (page, post, etc.)
	 * @param string $template_id The template ID
	 * @param string $dir_path The base directory path for original files
	 * @return array Array containing paths for original and AI files
	 */
	public static function generate_ai_file_paths($session_id, $type, $sub_type, $template_id, $dir_path) {
		// Original file path
		$original_path = $dir_path . $type . DIRECTORY_SEPARATOR;
		if (!empty($sub_type)) {
			$original_path .= $sub_type . DIRECTORY_SEPARATOR;
		}
		$original_file = $original_path . "{$template_id}.json";

		// AI file path in tmp directory
		$upload_dir = wp_upload_dir();
		$tmp_dir = trailingslashit($upload_dir['basedir']) . 'templately' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $session_id . DIRECTORY_SEPARATOR;
		$ai_path = $tmp_dir . $type . DIRECTORY_SEPARATOR;
		if (!empty($sub_type)) {
			$ai_path .= $sub_type . DIRECTORY_SEPARATOR;
		}
		$ai_file = $ai_path . "{$template_id}.ai.json";

		return [
			'original_file' => $original_file,
			'ai_file_path'  => $ai_file,
			'ai_directory'  => $ai_path,
			'tmp_directory' => $tmp_dir,
		];
	}

	/**
	 * Get AI tmp directory path for a session
	 *
	 * @param string $session_id The session ID
	 * @return string The tmp directory path
	 */
	public static function get_ai_tmp_directory($session_id) {
		$upload_dir = wp_upload_dir();
		return trailingslashit($upload_dir['basedir']) . 'templately' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . $session_id . DIRECTORY_SEPARATOR;
	}

	/**
	 * Get AI file path for specific content
	 *
	 * @param string $session_id The session ID
	 * @param string $type The content type
	 * @param string $sub_type The content sub-type (optional)
	 * @param string $content_id The content ID
	 * @return string The AI file path
	 */
	public static function get_ai_file_path($session_id, $type, $sub_type, $content_id) {
		$tmp_dir = self::get_ai_tmp_directory($session_id);
		$file_path = $tmp_dir . $type . DIRECTORY_SEPARATOR;
		if (!empty($sub_type)) {
			$file_path .= $sub_type . DIRECTORY_SEPARATOR;
		}
		return $file_path . "{$content_id}.ai.json";
	}

	/**
	 * Check if AI file exists and is valid
	 *
	 * @param string $ai_file_path The AI file path
	 * @return bool True if AI file exists and is valid
	 */
	public static function has_ai_file($ai_file_path) {
		if (!file_exists($ai_file_path)) {
			return false;
		}

		$file_content = file_get_contents($ai_file_path);
		if (empty($file_content)) {
			return false;
		}

		$decoded = json_decode($file_content, true);
		return json_last_error() === JSON_ERROR_NONE && !empty($decoded);
	}

	/**
	 * Check if AI file is marked as skipped
	 *
	 * @param string $ai_file_path The AI file path
	 * @return bool True if AI file exists and is marked as skipped
	 */
	public static function is_ai_file_skipped($ai_file_path) {
		if (!file_exists($ai_file_path)) {
			return false;
		}

		$ai_content = Utils::read_json_file($ai_file_path);
		return isset($ai_content['isSkipped']) && $ai_content['isSkipped'];
	}

	/**
	 * Check if content ID is in AI page IDs
	 *
	 * @param string $content_id The content ID to check
	 * @param array $ai_page_ids The AI page IDs structure
	 * @return bool True if content ID is found in AI page IDs
	 */
	public static function is_ai_content($content_id, $ai_page_ids) {
		if (empty($ai_page_ids) || !is_array($ai_page_ids)) {
			return false;
		}

		foreach ($ai_page_ids as $ids) {
			if (is_array($ids) && in_array($content_id, $ids)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if content should be processed as AI content
	 *
	 * @param string $session_id The session ID
	 * @param string $type The content type
	 * @param string $sub_type The content sub-type
	 * @param string $content_id The content ID
	 * @param array $ai_page_ids The AI page IDs structure
	 * @param string $dir_path The base directory path
	 * @return bool True if this is AI content
	 */
	public static function should_process_as_ai_content($session_id, $type, $sub_type, $content_id, $ai_page_ids, $dir_path) {
		// Check if content ID is in AI page IDs list
		$is_ai_template = self::is_ai_content($content_id, $ai_page_ids);

		// Check if AI file exists
		$paths = self::generate_ai_file_paths($session_id, $type, $sub_type, $content_id, $dir_path);
		$has_ai_file = self::has_ai_file($paths['ai_file_path']);

		return $is_ai_template || $has_ai_file;
	}

	/**
	 * Validate and extract AI process data for a given process ID
	 *
	 * @param string $process_id The process ID
	 * @return array|WP_Error Returns process data array or WP_Error on failure
	 */
	public static function validate_and_get_process_data($process_id) {
		if (empty($process_id)) {
			return Helper::error('invalid_process_id', __('Process ID is required.', 'templately'), 'validate_process_data', 400);
		}

		$ai_process_data = self::get_ai_process_data();
		if (empty($ai_process_data[$process_id])) {
			return Helper::error('process_not_found', __('Process ID not found.', 'templately'), 'validate_process_data', 404);
		}

		$process_data = $ai_process_data[$process_id];

		// Validate required fields
		if (empty($process_data['session_id'])) {
			return Helper::error('missing_session_id', __('Session ID missing from process data.', 'templately'), 'validate_process_data', 400);
		}

		if (empty($process_data['ai_page_ids'])) {
			return Helper::error('missing_ai_page_ids', __('AI page IDs missing from process data.', 'templately'), 'validate_process_data', 400);
		}

		return $process_data;
	}

	/**
	 * Get processed pages data for a process ID
	 *
	 * @param string $process_id The process ID
	 * @return array The processed pages data
	 */
	public static function get_processed_pages_data($process_id) {
		$processed_pages = get_option("templately_ai_processed_pages", []);
		return $processed_pages[$process_id] ?? [];
	}

	/**
	 * Update processed pages data for a process ID
	 *
	 * @param string $process_id The process ID
	 * @param array $data The data to update
	 * @return bool True on success, false on failure
	 */
	public static function update_processed_pages_data($process_id, $data) {
		$processed_pages = get_option("templately_ai_processed_pages", []);
		$processed_pages[$process_id] = array_merge($processed_pages[$process_id] ?? [], $data);
		return update_option("templately_ai_processed_pages", $processed_pages, false);
	}

	/**
	 * Find content type and sub-type for a given content ID in AI page IDs
	 *
	 * @param string $content_id The content ID to find
	 * @param array $ai_page_ids The AI page IDs structure
	 * @return array|null Array with 'type' and 'sub_type' keys, or null if not found
	 */
	public static function find_content_type_info($content_id, $ai_page_ids) {
		if (empty($ai_page_ids) || !is_array($ai_page_ids)) {
			return null;
		}

		foreach ($ai_page_ids as $key => $ids) {
			if (is_array($ids) && in_array($content_id, $ids)) {
				$type_parts = explode('/', $key);
				return [
					'type' => $type_parts[0],
					'sub_type' => isset($type_parts[1]) ? $type_parts[1] : '',
					'key' => $key
				];
			}
		}

		return null;
	}

	/**
	 * Read AI template data directly from files
	 * Common function for reading template data, used by AI content operations
	 *
	 * @param string $session_id The session ID
	 * @param array $ai_page_ids The AI page IDs structure
	 * @param string $dir_path The base directory path
	 * @return array Array of template data indexed by content ID
	 */
	public static function read_ai_template_data($session_id, $ai_page_ids, $dir_path) {
		$result = [];

		if (empty($ai_page_ids) || !is_array($ai_page_ids)) {
			return $result;
		}

		foreach ($ai_page_ids as $key => $ids) {
			if (!is_array($ids)) {
				continue;
			}

			foreach ($ids as $id) {
				$type_arr = explode('/', $key);
				$type = $type_arr[0];
				$sub_type = isset($type_arr[1]) ? $type_arr[1] : '';

				// Check if this is AI content before processing
				if (self::should_process_as_ai_content($session_id, $type, $sub_type, $id, $ai_page_ids, $dir_path)) {
					// Generate AI file paths
					$ai_paths = self::generate_ai_file_paths($session_id, $type, $sub_type, $id, $dir_path);
					$ai_file_path = $ai_paths['ai_file_path'];

					if (file_exists($ai_file_path)) {
						$ai_content = Utils::read_json_file($ai_file_path);
						if (isset($ai_content['isSkipped']) && $ai_content['isSkipped']) {
							// Return empty array for skipped content (for JS compatibility)
							$result[$id] = [];
						} else {
							$result[$id] = $ai_content; // Raw AI content
						}
					}
				}
			}
		}

		return $result;
	}

}
