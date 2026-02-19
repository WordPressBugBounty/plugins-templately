<?php

namespace Templately\Core\Importer\Utils;

use Templately\Core\Importer\FullSiteImport;

/**
 * SessionData - Centralized session data management
 *
 * Provides path-based access to session data without recursive merging.
 * Replaces the problematic recursive_wp_parse_args pattern.
 */
class SessionData {

	/**
	 * Get individual session option key
	 *
	 * @param string $session_id The session ID
	 * @return string The option key for this session
	 */
	private static function get_session_option_key($session_id) {
		return 'templately_session_' . $session_id;
	}

	/**
	 * Get all session data for a specific session
	 *
	 * @param string $session_id The session ID
	 * @return array The session data
	 */
	public static function get_data($session_id): array {
		if (empty($session_id)) {
			return [];
		}

		// First try new individual option
		$option_key = self::get_session_option_key($session_id);
		$data = get_option($option_key, null);

		if (is_array($data)) {
			return $data;
		}

		// Fallback to legacy option
		$legacy_data = get_option(FullSiteImport::SESSION_OPTION_KEY, []);
		if (isset($legacy_data[$session_id]) && is_array($legacy_data[$session_id])) {
			return $legacy_data[$session_id];
		}

		return [];
	}

	/**
	 * Save full session data (overwrites existing)
	 * Use this when you need to save the entire session data object
	 *
	 * @param string $session_id The session ID
	 * @param array $data The complete session data
	 * @return bool Success status
	 */
	public static function save($session_id, $data): bool {
		if (empty($session_id) || !is_array($data)) {
			return false;
		}

		$option_key = self::get_session_option_key($session_id);

		// Add timestamp for expiry tracking
		$data['_updated_at'] = time();

		// Write to individual option (autoload = no for memory efficiency)
		return update_option($option_key, $data, false);
	}

	/**
	 * Get a value at a specific path from session data
	 * Uses dot notation for nested access: "loop.progress.ClassName"
	 *
	 * @param string $session_id The session ID
	 * @param string $path Dot-notation path to the value
	 * @param mixed $default Default value if path doesn't exist
	 * @return mixed The value at the path or default
	 */
	public static function get($session_id, $path, $default = null) {
		$data = self::get_data($session_id);
		$keys = explode('.', $path);

		foreach ($keys as $key) {
			if (!is_array($data) || !isset($data[$key])) {
				return $default;
			}
			$data = $data[$key];
		}

		return $data;
	}

	/**
	 * Set a value at a specific path in session data (no merging)
	 * Uses dot notation for nested access: "loop.progress.ClassName"
	 *
	 * @param string $session_id The session ID
	 * @param string $path Dot-notation path to set
	 * @param mixed $value The value to set
	 * @return bool Success status
	 */
	public static function set($session_id, $path, $value): bool {
		if (empty($session_id)) {
			return false;
		}

		$data = self::get_data($session_id);
		$keys = explode('.', $path);
		$current = &$data;

		// Navigate to the target location
		foreach ($keys as $i => $key) {
			if ($i === count($keys) - 1) {
				// Last key - set the value
				$current[$key] = $value;
			} else {
				// Intermediate key - ensure it's an array
				if (!isset($current[$key]) || !is_array($current[$key])) {
					$current[$key] = [];
				}
				$current = &$current[$key];
			}
		}

		// Add timestamp for expiry tracking
		$data['_updated_at'] = time();

		// Write to individual option (autoload = no for memory efficiency)
		$option_key = self::get_session_option_key($session_id);
		return update_option($option_key, $data, false);
	}

	/**
	 * Append a value to an array at a specific path
	 *
	 * @param string $session_id The session ID
	 * @param string $path Dot-notation path to the array
	 * @param mixed $value The value to append
	 * @return bool Success status
	 */
	public static function append($session_id, $path, $value): bool {
		$current = self::get($session_id, $path, []);

		if (!is_array($current)) {
			$current = [];
		}

		$current[] = $value;
		return self::set($session_id, $path, $current);
	}

	/**
	 * Delete session data
	 *
	 * @param string $session_id The session ID
	 * @return bool Success status
	 */
	public static function delete($session_id): bool {
		if (empty($session_id)) {
			return false;
		}

		$deleted = false;

		// Delete individual option
		$option_key = self::get_session_option_key($session_id);
		if (get_option($option_key) !== false) {
			$deleted = delete_option($option_key);
		}

		// Also remove from legacy option if exists
		$legacy_data = get_option(FullSiteImport::SESSION_OPTION_KEY, []);
		if (isset($legacy_data[$session_id])) {
			unset($legacy_data[$session_id]);
			update_option(FullSiteImport::SESSION_OPTION_KEY, $legacy_data);
			$deleted = true;
		}

		return $deleted;
	}

	/**
	 * Get calling function identifier for scoped storage
	 * Migrated from Loop::CallingFunctionName()
	 *
	 * @param string|null $unique_id Optional unique identifier to append
	 * @param bool $function Include function name
	 * @param bool $line Include line number
	 * @param int $level Backtrace level (adjust based on call stack depth)
	 * @return string The calling identifier
	 */
	public static function get_calling_identifier($unique_id = null, $function = true, $line = false, $level = 3): string {
		$return = 'unknown';
		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, ($level + 1));

		// Check if the trace has at least the required elements
		if (isset($trace[$level])) {
			$final_call = $trace[$level];
			$return = '';

			if (isset($final_call['object'])) {
				$return .= get_class($final_call['object']);
			} elseif (isset($final_call['class'])) {
				$return .= $final_call['class'];
			}

			if ($function && isset($final_call['function'])) {
				$return .= ($return ? '::' : '') . $final_call['function'];
			}

			// Line number should be from previous level (where the function was called FROM)
			if ($line && isset($trace[$level - 1]['line'])) {
				$return .= ($return ? '::' : '') . $trace[$level - 1]['line'];
			}

			if (!empty($unique_id)) {
				$return .= ($return ? '::' : '') . $unique_id;
			}

			if (!$return) {
				$return = 'unknown';
			}
		}

		return $return;
	}

	// ============================================================================
	// Loop Helper Functions
	// ============================================================================

	/**
	 * Check if a key has been processed in the loop
	 *
	 * @param string $session_id The session ID
	 * @param string $calling_class The calling class identifier
	 * @param mixed $key The item key to check
	 * @return bool True if processed
	 */
	public static function is_key_processed($session_id, $calling_class, $key) {
		$progress = self::get($session_id, "loop.progress.{$calling_class}", []);
		return in_array("key_{$key}", $progress, true);
	}

	/**
	 * Mark a key as processed in the loop
	 *
	 * @param string $session_id The session ID
	 * @param string $calling_class The calling class identifier
	 * @param mixed $key The item key to mark
	 * @return bool Success status
	 */
	public static function mark_key_processed($session_id, $calling_class, $key) {
		return self::append($session_id, "loop.progress.{$calling_class}", "key_{$key}");
	}

	/**
	 * Set loop result for a calling context
	 *
	 * @param string $session_id The session ID
	 * @param string $calling_class The calling class identifier
	 * @param array $result The result data
	 * @return bool Success status
	 */
	public static function set_loop_result($session_id, $calling_class, $result) {
		return self::set($session_id, "loop.result.{$calling_class}", $result);
	}

	/**
	 * Get loop result for a calling context
	 *
	 * @param string $session_id The session ID
	 * @param string $calling_class The calling class identifier
	 * @param array $default Default value if not found
	 * @return array The result data
	 */
	public static function get_loop_result($session_id, $calling_class, $default = []) {
		return self::get($session_id, "loop.result.{$calling_class}", $default);
	}

	// ============================================================================
	// FullSiteImport Step Helper Functions
	// ============================================================================

	/**
	 * Check if an import step has been completed
	 *
	 * @param string $session_id The session ID
	 * @param string $step_name The step name (e.g., 'download_zip')
	 * @return bool True if completed
	 */
	public static function is_step_complete($session_id, $step_name) {
		return (bool) self::get($session_id, "progress.{$step_name}", false);
	}

	/**
	 * Mark an import step as complete
	 *
	 * @param string $session_id The session ID
	 * @param string $step_name The step name (e.g., 'download_zip')
	 * @return bool Success status
	 */
	public static function mark_step_complete($session_id, $step_name) {
		return self::set($session_id, "progress.{$step_name}", true);
	}

	// ============================================================================
	// Skip-on-Error Tracking Functions
	// ============================================================================

	/**
	 * Increment error attempts for a specific loop item
	 *
	 * @param string $session_id The session ID
	 * @param string $calling_class The calling class identifier
	 * @param mixed $key The item key
	 * @return int The new error attempt count
	 */
	public static function increment_error_attempts($session_id, $calling_class, $key): int {
		$current = self::get($session_id, "loop.error_attempts.{$calling_class}.key_{$key}", 0);
		$new_count = $current + 1;
		self::set($session_id, "loop.error_attempts.{$calling_class}.key_{$key}", $new_count);
		return $new_count;
	}

	/**
	 * Get error attempt count for a specific loop item
	 *
	 * @param string $session_id The session ID
	 * @param string $calling_class The calling class identifier
	 * @param mixed $key The item key
	 * @return int The error attempt count
	 */
	public static function get_error_attempts($session_id, $calling_class, $key): int {
		return (int) self::get($session_id, "loop.error_attempts.{$calling_class}.key_{$key}", 0);
	}

	/**
	 * Reset error attempts for a specific loop item
	 *
	 * @param string $session_id The session ID
	 * @param string $calling_class The calling class identifier
	 * @param mixed $key The item key
	 * @return bool Success status
	 */
	public static function reset_error_attempts($session_id, $calling_class, $key): bool {
		return self::set($session_id, "loop.error_attempts.{$calling_class}.key_{$key}", 0);
	}

	/**
	 * Mark a loop item as skipped
	 *
	 * @param string $session_id The session ID
	 * @param string $calling_class The calling class identifier
	 * @param mixed $key The item key
	 * @param string $reason The reason for skipping
	 * @return bool Success status
	 */
	public static function mark_key_skipped($session_id, $calling_class, $key, $reason = ''): bool {
		$skip_data = [
			'class' => $calling_class,
			'key' => $key,
			'reason' => $reason,
			'timestamp' => time(),
		];
		return self::append($session_id, "loop.skipped_items", $skip_data);
	}

	/**
	 * Check if a loop item has been skipped
	 *
	 * @param string $session_id The session ID
	 * @param string $calling_class The calling class identifier
	 * @param mixed $key The item key
	 * @return bool True if skipped
	 */
	public static function is_key_skipped($session_id, $calling_class, $key): bool {
		$skipped_items = self::get($session_id, "loop.skipped_items", []);
		foreach ($skipped_items as $item) {
			if ($item['class'] === $calling_class && $item['key'] === $key) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Get all skipped items for a session
	 *
	 * @param string $session_id The session ID
	 * @return array Array of skipped items
	 */
	public static function get_skipped_items($session_id): array {
		return self::get($session_id, "loop.skipped_items", []);
	}

	/**
	 * Increment consecutive skip counter
	 *
	 * @param string $session_id The session ID
	 * @return int The new consecutive skip count
	 */
	public static function increment_consecutive_skips($session_id): int {
		$current = self::get($session_id, "loop.consecutive_skips", 0);
		$new_count = $current + 1;
		self::set($session_id, "loop.consecutive_skips", $new_count);
		return $new_count;
	}

	/**
	 * Reset consecutive skip counter
	 *
	 * @param string $session_id The session ID
	 * @return bool Success status
	 */
	public static function reset_consecutive_skips($session_id): bool {
		return self::set($session_id, "loop.consecutive_skips", 0);
	}

	/**
	 * Get consecutive skip count
	 *
	 * @param string $session_id The session ID
	 * @return int The consecutive skip count
	 */
	public static function get_consecutive_skips($session_id): int {
		return (int) self::get($session_id, "loop.consecutive_skips", 0);
	}

	// ============================================================================
	// Utility Functions
	// ============================================================================

	/**
	 * Get session ID from request
	 *
	 * @return string|null The session ID or null
	 */
	public static function get_session_id() {
		$session_id = null;
		if (!empty($_REQUEST['session_id'])) {
			$session_id = sanitize_text_field($_REQUEST['session_id']);
		}
		return $session_id;
	}

	// ============================================================================
	// Cleanup Functions
	// ============================================================================

	/**
	 * Get all session data - ONLY use for cleanup operations
	 * This queries both new individual options and legacy option
	 *
	 * @return array Array of session_id => session_data
	 */
	public static function get_all_data(): array {
		global $wpdb;

		$all_sessions = [];

		// Get sessions from new individual options
		$option_prefix = 'templately_session_';
		$sql = $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like($option_prefix) . '%'
		);
		$results = $wpdb->get_results($sql);

		if ($results) {
			foreach ($results as $row) {
				$session_id = str_replace($option_prefix, '', $row->option_name);
				$session_data = maybe_unserialize($row->option_value);
				if (is_array($session_data)) {
					$all_sessions[$session_id] = $session_data;
				}
			}
		}

		// Also get legacy data from single option for backward compatibility
		$legacy_data = get_option(FullSiteImport::SESSION_OPTION_KEY, []);
		if (is_array($legacy_data) && !empty($legacy_data)) {
			foreach ($legacy_data as $session_id => $session_data) {
				// Don't overwrite if already exists in new format
				if (!isset($all_sessions[$session_id]) && is_array($session_data)) {
					$all_sessions[$session_id] = $session_data;
				}
			}
		}

		return $all_sessions;
	}

	/**
	 * Clean session data by pack ID, keeping only the current session
	 * Removes all session entries with the same pack_id except the current session
	 *
	 * @param string $pack_id The pack ID to match for cleanup
	 * @param string $current_session_id The current session ID to preserve
	 * @return array Array of removed session IDs
	 */
	public static function clean_by_pack_id($pack_id, $current_session_id): array {
		// DISABLED: Cleanup temporarily disabled during migration testing
		return [];

		// Original implementation:
		// if (empty($pack_id) || empty($current_session_id)) {
		// 	return [];
		// }

		// $all_session_data = self::get_all_data();
		// $removed_session_ids = [];

		// foreach ($all_session_data as $session_id => $session_data) {
		// 	if ($session_id === $current_session_id) {
		// 		continue;
		// 	}
		// 	if (isset($session_data['id']) && $session_data['id'] === $pack_id) {
		// 		self::delete($session_id);
		// 		$removed_session_ids[] = $session_id;
		// 	}
		// }

		// return $removed_session_ids;
	}

	/**
	 * Clean up expired sessions based on time threshold
	 * Removes sessions older than the specified number of days
	 *
	 * @param int $max_age_days Maximum age in days (default 7)
	 * @return array Array with 'removed_count' and 'removed_ids'
	 */
	public static function cleanup_expired($max_age_days = 7): array {
		// DISABLED: Cleanup temporarily disabled during migration testing
		return [
			'removed_count' => 0,
			'removed_ids'   => [],
		];

		// Original implementation:
		// $all_session_data = self::get_all_data();
		// $removed_session_ids = [];
		// $threshold_time = time() - ($max_age_days * DAY_IN_SECONDS);

		// foreach ($all_session_data as $session_id => $session_data) {
		// 	$updated_at = isset($session_data['_updated_at']) ? (int) $session_data['_updated_at'] : 0;
		// 	if ($updated_at === 0 || $updated_at < $threshold_time) {
		// 		self::delete($session_id);
		// 		$removed_session_ids[] = $session_id;
		// 	}
		// }

		// return [
		// 	'removed_count' => count($removed_session_ids),
		// 	'removed_ids'   => $removed_session_ids,
		// ];
	}
}
