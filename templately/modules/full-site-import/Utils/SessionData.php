<?php

namespace Templately\Modules\FullSiteImport\Utils;

use Templately\Modules\FullSiteImport\FullSiteImport;

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
	 * Whether this stored session's import already finished SUCCESSFULLY.
	 *
	 * The resume-replay guard (spec 042 FR-032 defense-in-depth behind the
	 * client-side fix): a finished session's `progress.*` markers make every
	 * runner skip, so "resuming" it instantly streams the OLD import's results
	 * as a fresh 'complete'. A 'failed' status is NOT completed — retry
	 * legitimately re-attaches to a failed session to skip finished steps, and
	 * an in-progress session (no status yet) is the LiteSpeed-resume case.
	 *
	 * @param string $session_id The session ID
	 * @return bool
	 */
	public static function is_completed($session_id): bool {
		$data = self::get_data($session_id);

		return isset($data['is_import_status_handled']) && 'success' === $data['is_import_status_handled'];
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
	/**
	 * Run-lock option key — one import() execution per session at a time.
	 */
	private static function get_run_lock_key($session_id) {
		return 'templately_session_lock_' . $session_id;
	}

	/**
	 * Acquire the per-session run lock.
	 *
	 * The client watchdog reconnects while the abandoned server request is
	 * STILL executing (an import request survives its client going away and
	 * runs to the end of its chunk, on every server) — without a lock, two
	 * import() executions interleave this class's non-atomic read-modify-write
	 * and duplicate inserts.
	 *
	 * @param string $session_id
	 * @param int    $stale_after Seconds after which a lock whose holder died
	 *                            without its shutdown release is taken over.
	 * @return string|null Owner token, or null when a LIVE execution holds it.
	 */
	public static function acquire_run_lock($session_id, $stale_after = 180) {
		global $wpdb;

		$key   = self::get_run_lock_key($session_id);
		$token = uniqid('', true);
		$raw   = maybe_serialize(['owner' => $token, 'heartbeat' => time()]);

		// A plain INSERT, straight at the table — NOT add_option(). This used to be
		// add_option(), described here as "the atomic primitive: it INSERTs and fails
		// on a duplicate". It does not. Core runs
		// `INSERT … ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)`, so a
		// second caller arriving between the first caller's existence check and its
		// write OVERWRITES the first caller's token and is told it succeeded, because a
		// changed row reports affected-rows 2. Both then run import() on one session.
		// Observed 2026-09-18: the content step re-ran on top of the finalizer, imported
		// the navigation posts twice, and the two executions' interleaved session
		// writes lost the old→new id map — the header and footer kept the demo site's
		// navigation ids and the imported site had no menus.
		//
		// INSERT IGNORE relies on the unique index on option_name: exactly one caller
		// gets affected-rows 1, whatever the timing.
		$acquired = $wpdb->query( $wpdb->prepare(
			"INSERT IGNORE INTO `{$wpdb->options}` (`option_name`, `option_value`, `autoload`) VALUES (%s, %s, 'no')",
			$key,
			$raw
		) );
		self::forget_cached_lock($key);

		if (1 === (int) $acquired) {
			return $token;
		}

		$existing_raw = $wpdb->get_var( $wpdb->prepare(
			"SELECT `option_value` FROM `{$wpdb->options}` WHERE `option_name` = %s LIMIT 1",
			$key
		) );

		if (null === $existing_raw) {
			// Released between our INSERT and this read. The next reconnect takes it.
			return null;
		}

		$existing  = maybe_unserialize($existing_raw);
		$heartbeat = is_array($existing) ? (int) ($existing['heartbeat'] ?? 0) : 0;

		if ($heartbeat >= time() - $stale_after) {
			return null;
		}

		// Stale takeover — the holder hard-died (its shutdown never ran). Compare-and-
		// swap on the exact value we judged stale, so two takeovers racing here resolve
		// to ONE winner instead of both proceeding (which the add_option() version
		// accepted as "the pre-lock status quo, not worse").
		$taken = $wpdb->query( $wpdb->prepare(
			"UPDATE `{$wpdb->options}` SET `option_value` = %s WHERE `option_name` = %s AND `option_value` = %s",
			$raw,
			$key,
			$existing_raw
		) );
		self::forget_cached_lock($key);

		return 1 === (int) $taken ? $token : null;
	}

	/**
	 * Release the run lock — only by its owner, so a superseded request's
	 * shutdown can never delete the lock a newer execution holds.
	 *
	 * The ownership test and the delete are ONE statement: a read followed by a
	 * delete would let a stale takeover land in between and have its fresh lock
	 * deleted by the request it had just superseded.
	 */
	public static function release_run_lock($session_id, $token): void {
		global $wpdb;

		if (empty($session_id) || empty($token)) {
			return;
		}

		$key = self::get_run_lock_key($session_id);

		// The token sits inside a serialized array, and uniqid('', true) is
		// [0-9a-f.] only, so it is safe inside LIKE once esc_like()d and cannot
		// match another token as a substring (they are all the same length).
		$wpdb->query( $wpdb->prepare(
			"DELETE FROM `{$wpdb->options}` WHERE `option_name` = %s AND `option_value` LIKE %s",
			$key,
			'%' . $wpdb->esc_like('"' . $token . '"') . '%'
		) );
		self::forget_cached_lock($key);
	}

	/**
	 * Drop what WordPress has cached about the lock row.
	 *
	 * The lock is written with direct queries, so the options cache never hears about
	 * it. Left alone, a request that read the key BEFORE it existed keeps it in the
	 * `notoptions` list and every later get_option()/delete_option() in that request
	 * answers "no such option" about a row that is in the table.
	 */
	private static function forget_cached_lock($key): void {
		wp_cache_delete($key, 'options');

		$notoptions = wp_cache_get('notoptions', 'options');
		if (is_array($notoptions) && isset($notoptions[$key])) {
			unset($notoptions[$key]);
			wp_cache_set('notoptions', $notoptions, 'options');
		}
	}

	public static function delete($session_id): bool {
		if (empty($session_id)) {
			return false;
		}

		$deleted = false;

		// Delete individual option (+ any leftover run lock)
		delete_option(self::get_run_lock_key($session_id));
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
	 * Build a scoped-storage identifier (`Class::method[::unique_id]`) for a frame
	 * in the current call stack. Migrated from Loop::CallingFunctionName().
	 *
	 * 037 US2 (FR-004): the `Loop` trait now calls this ONCE per loop()/wrapper
	 * entry with a FIXED, local `$level` measured from a DIRECT call here (e.g.
	 * `$level = 2` resolves the direct caller of the function that called this),
	 * then threads the result down. `$level` is therefore a stable, local offset —
	 * it no longer tracks how deep the import pipeline or the trait's private
	 * helper chain happens to be, so it never needs "adjusting for call depth".
	 *
	 * @param string|null $unique_id Optional unique identifier to append
	 * @param bool $function Include the ::method segment (false = class only)
	 * @param bool $line Include line number
	 * @param int $level Backtrace frame to read, counted from this method
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
	 * Clean up expired sessions based on time threshold
	 * Removes sessions older than the specified number of days
	 *
	 * @param int $max_age_days Maximum age in days (default 7)
	 * @return array Array with 'removed_count' and 'removed_ids'
	 */
	public static function cleanup_expired($max_age_days = 7): array {
		$all_session_data    = self::get_all_data();
		$removed_session_ids = [];
		$threshold_time      = time() - ($max_age_days * DAY_IN_SECONDS);

		foreach ($all_session_data as $session_id => $session_data) {
			// Active-session exemption (FR-006): never clean a session with a live
			// import (recently touched or an in-progress, not-yet-complete step).
			if (self::is_session_active($session_data, $threshold_time)) {
				continue;
			}
			self::delete($session_id);
			$removed_session_ids[] = $session_id;
		}

		// Sweep orphaned AI process rows (+ their page sub-keys) past retention,
		// exempting any still linked to an active session (FR-006).
		$removed_process_ids = AIUtils::cleanup_expired_processes($threshold_time);

		return [
			'removed_count'       => count($removed_session_ids),
			'removed_ids'         => $removed_session_ids,
			'removed_process_ids' => $removed_process_ids,
		];
	}

	/**
	 * Whether a session is still in use, by id.
	 *
	 * The public form of the activity predicate. The shared cleanup service
	 * (spec 052) consumes this rather than reimplementing it: only this module
	 * knows that a session carrying in-progress markers is still running, and
	 * duplicating that judgement in the engine is how the two would drift.
	 *
	 * Callers MUST re-check immediately before removing anything — an import
	 * that starts while a sweep is walking the directory has to be exempt from
	 * that moment on, not from when the candidate list was built.
	 *
	 * @param string   $session_id
	 * @param int|null $threshold_time Unix time; older than this is expired.
	 *                                 Null uses the 7-day default.
	 * @return bool
	 */
	public static function is_active( $session_id, $threshold_time = null ): bool {
		if ( empty( $session_id ) ) {
			return false;
		}

		if ( null === $threshold_time ) {
			$threshold_time = time() - ( 7 * DAY_IN_SECONDS );
		}

		$session_data = self::get_data( $session_id );
		if ( ! is_array( $session_data ) || empty( $session_data ) ) {
			return false;
		}

		return self::is_session_active( $session_data, (int) $threshold_time );
	}

	/**
	 * A session is "active" (cleanup-exempt) when it was touched within the
	 * retention window, or when it carries an in-progress step that has not yet
	 * completed. Any live import bumps `_updated_at` on every write, so recency
	 * covers the common case; the progress check is a defensive belt.
	 *
	 * @param array $session_data
	 * @param int   $threshold_time
	 * @return bool
	 */
	private static function is_session_active($session_data, $threshold_time): bool {
		$updated_at = isset($session_data['_updated_at']) ? (int) $session_data['_updated_at'] : 0;
		if ($updated_at >= $threshold_time && $updated_at > 0) {
			return true;
		}

		if (!empty($session_data['progress']) && is_array($session_data['progress'])) {
			$progress     = $session_data['progress'];
			$has_complete = !empty($progress['import_complete']) || !empty($progress['completed']);
			// In-progress markers present but no completion → keep (don't kill a
			// running import), bounded by a 2× grace so genuinely abandoned rows
			// still age out.
			if (!$has_complete && $updated_at >= ($threshold_time - ($threshold_time > 0 ? DAY_IN_SECONDS : 0))) {
				return true;
			}
		}

		return false;
	}
}
