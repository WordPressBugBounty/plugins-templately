<?php

namespace Templately\Core\Importer\Utils;

use Templately\Utils\Options;

/**
 * AI-related utility functions for Templately
 * Handles AI process data management using API key-based storage with count-based cleanup
 */
class AIUtils {

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
	 * @return bool True if should continue processing, false if should exit
	 */
	public static function handle_sse_wait_with_timeout($session_id, $progress_id, $updated_ids, $ai_page_ids, $sse_message_callback, $additional_sse_data = []) {
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

		// Skip timeout check if credit cost is available and time difference is > 10 seconds
		if (isset($updated_ids['credit_cost']) && !empty($last_time) && ($current_time - $last_time) > 10) {
			return true;
		}

		// Check if time difference is less than 5 minutes (timeout condition)
		if (empty($last_time) || ($current_time - $last_time) < 5 * MINUTE_IN_SECONDS) {
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
	 * Get the latest AI process data for the current API key
	 * Used in import_info() to return the most recent AI process
	 *
	 * @return array|null The latest AI process data or null if not found
	 */
	public static function get_latest_ai_process_by_api_key() {
		$api_key = Options::get_instance()->get('api_key');
		if (empty($api_key)) {
			return null;
		}

		$all_ai_process_data = get_option('templately_ai_process_data', []);
		if (!is_array($all_ai_process_data)) {
			return null;
		}

		$api_key_processes = [];

		// Collect processes for current API key
		foreach ($all_ai_process_data as $process_id => $process_data) {
			if (is_array($process_data) && isset($process_data['api_key']) && $process_data['api_key'] === $api_key) {
				$api_key_processes[$process_id] = $process_data;
			}
		}

		if (empty($api_key_processes)) {
			return null;
		}

		// Return the last (most recent) process
		return end($api_key_processes);
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
}
