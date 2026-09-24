<?php

namespace Templately\Modules\FullSiteImport\Utils;

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
	 * @return string|\WP_Error Sanitized value or error if invalid
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
	 * @param array $ai_page_ids All AI pages that need processing. MUST be a FLAT
	 *                          list of content ids — this is counted against the
	 *                          number of processed pages. Passing the nested
	 *                          `type/sub_type => [...]` map counts groups, not
	 *                          pages, and the wait silently never engages. Use
	 *                          AIUtils::flatten_ai_page_ids().
	 * @param callable $sse_message_callback Callback function for sending SSE messages
	 * @param array $additional_sse_data Additional data to include in SSE message
	 * @param string|null $old_template_id The AI page id being waited on. Retained for
	 *                                     signature/back-compat; on-demand pulling now
	 *                                     runs in AIContentResolver providers before this.
	 * @return bool True if should continue processing, false if should exit
	 */
	public static function handle_sse_wait_with_timeout($session_id, $progress_id, $updated_ids, $ai_page_ids, $sse_message_callback, $additional_sse_data = [], $old_template_id = null, $timeout_seconds = 420) {
		// Both callers hand this the GROUPED map — ['content/page' => [6, 10, …],
		// 'templates' => [21, …]] — so a raw count() yields the number of groups
		// (2), not of pages (9), and the "everything is done" check below passes
		// the moment 3 pages exist. Flatten first. This stayed invisible while the
		// pages were always written before the import started; it only bites once
		// the writes can land mid-import, where it silently drops the AI content
		// and reports success.
		$ai_page_ids   = self::flatten_ai_page_ids($ai_page_ids);
		$total_pages   = count($ai_page_ids);
		$updated_pages = count($updated_ids['pages'] ?? []);

		// If all pages are processed or credit cost is available, continue processing
		if ($total_pages <= $updated_pages || isset($updated_ids['credit_cost'])) {
			return true;
		}

		// Get timeout tracking data from session
		$session_data = SessionData::get_data($session_id);
		$progress_data = $session_data['progress'][$progress_id] ?? [];
		$last_progress = $progress_data['last_progress'] ?? 0;
		$last_time = $progress_data['last_time'] ?? 0;
		$current_time = time();
		$progress_percentage = $total_pages > 0 ? round(($updated_pages / $total_pages) * 100) : 0;

		// Skip timeout check if credit cost is available and time difference is > 10 seconds
		if (isset($updated_ids['credit_cost']) && !empty($last_time) && ($current_time - $last_time) > 10) {
			return true;
		}

		// Check if time difference is less than timeout(default 7 minutes) (timeout condition)
		if (empty($last_time) || ($current_time - $last_time) < $timeout_seconds) {
			// NOTE: on-demand pulling of the page (classic local-site poll, chat-id
			// pull, etc.) now happens in the source providers dispatched by
			// AIContentResolver *before* this wait handler is reached. Here we only
			// track progress and emit the shared SSE `wait`.

			// Only update time if progress has changed
			if ($progress_percentage !== $last_progress) {
				$updated_progress = $session_data['progress'] ?? [];
				$updated_progress[$progress_id] = [
					'last_progress' => $progress_percentage,
					'last_time' => $current_time,
				];
				SessionData::set($session_id, 'progress', $updated_progress);
			}

			// NO `updateLog` here — the wait must not put a row in the progress list.
			//
			// It used to emit `type: 'ai-content'`, and since the client appends any step
			// type it has not seen (applyStepUpdate in engine/steps.ts) that arrived as a
			// NEW row below "Finalizing Your Imports". This is the only place that type is
			// ever emitted: nothing takes it to 100 and nothing removes it, so the row could
			// only ever be born, and it sat there spinning through the rest of the import
			// and past completion. A checklist whose last line never ticks reads as a stall,
			// which is the opposite of the reassurance it was added for — and it narrated an
			// internal wait the user has no action to take about.
			//
			// The wait itself is unchanged, and so is the liveness it was protecting: the
			// step that is genuinely running ("Finalizing Your Imports") stays on screen
			// spinning, and advances as the pages either arrive or time out into the pack's
			// default content. The progress figure is still recorded in the session above,
			// where the timeout logic reads it.

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

		// Timeout exceeded → proceed. This "timeout → continue" fallthrough is the only
		// terminal outcome of the single timeout regime ($timeout_seconds). 035 D3
		// removed the dead commented-out "Taking too long…" error branch that had
		// implied a second, never-taken regime here.
		//
		// Waiting is the normal path; reaching here means the page never arrived
		// within $timeout_seconds, so the caller will import the pack's DEFAULT
		// content for it. Log it — this used to be entirely silent, which made a
		// defaulted page impossible to diagnose after the fact.
		Helper::log(sprintf(
			'ai_wait[%s/%s] timed out after %ds — page %s falls back to pack default content (%d/%d pages ready)',
			$session_id,
			$progress_id,
			$timeout_seconds,
			$old_template_id !== null ? $old_template_id : 'n/a',
			$updated_pages,
			$total_pages
		), 'ai-import', 'error');
		return true;
	}

	/**
	 * Unified remote pull (035 US1, D2): the SINGLE GET → HTTP-status triage → decode
	 * routine behind both `poll_for_template` and `poll_for_logo_generation`. Each
	 * caller does ONE pull per invocation (the retry loop is external — the client
	 * poller / the SSE-wait re-invocation); this only removes the duplicated
	 * request + triage + decode, never adds a loop.
	 *
	 * Strictness preserves each caller's historical triage exactly:
	 *   - lenient ($spec['strict_non200'] = false, the template poll): a transport
	 *     error / other non-200 / non-success body is TRANSIENT (returns `false` so
	 *     the caller keeps polling); only 401/403/404 are terminal (a `WP_Error`).
	 *   - strict ($spec['strict_non200'] = true, the logo poll): a transport error
	 *     is surfaced and ANY non-200 / non-success body is a terminal `WP_Error`.
	 *
	 * @param string $process_id The AI process ID (for terminal error context).
	 * @param string $session_id The session ID (unused here; kept for caller symmetry).
	 * @param array  $spec        { endpoint:string, strict_non200?:bool, context?:string }
	 * @return array{data:array}|WP_Error|false Decoded `data` on a 200 success
	 *   response; `WP_Error` on a terminal failure; `false` on a transient condition
	 *   (lenient callers only).
	 */
	private static function pull_remote_artifacts($process_id, $session_id, array $spec) {
		$endpoint = $spec['endpoint'];
		$strict   = !empty($spec['strict_non200']);
		$context  = $spec['context'] ?? 'pull_remote_artifacts';

		// `unwrap => false`: this poller reads the WHOLE body — `status`, `templates`,
		// `credit_cost` and friends sit alongside `data`, so unwrapping to `data` would
		// hide the fields the callers below actually branch on.
		$normalized = Helper::api_get($endpoint, [], [], 30, ['unwrap' => false]);

		if ($normalized->is_error()) {
			$error = $normalized->error();

			if ($strict) {
				// Logo poll: any failure is terminal (unchanged behavior).
				return $error;
			}

			// Template poll: keep polling only while the failure is RETRYABLE.
			//
			// This used to be a hardcoded list — 401/403/404 terminal, everything else
			// transient — which is precisely what the registry's `retryable` flag
			// encodes, and it encodes it in one place instead of per call site. An
			// expired session or a missing generation never resolves by waiting; a 5xx
			// or a dropped connection usually does.
			if ($error->is_retryable()) {
				return false;
			}

			return new \WP_Error(
				'ai_poll_terminal_error',
				$error->message() ?: __('AI content generation could not be retrieved.', 'templately'),
				['status' => $error->status(), 'process_id' => $process_id, 'code' => $error->code()]
			);
		}

		$api_data = $normalized->payload();
		if (!isset($api_data['status']) || $api_data['status'] !== 'success') {
			return $strict
				? Helper::error('api_response_error', __('API returned an error response.', 'templately'), $context, 400)
				: false;
		}

		return ['data' => $api_data['data'] ?? []];
	}

	/**
	 * Poll for logo generation status on local sites — a THIN caller of
	 * `pull_remote_artifacts` (035 US1). Makes one GET and returns the logo data.
	 *
	 * @param string $process_id The logo generation process ID
	 * @return array|WP_Error Logo generation data (images, credit_cost) or error
	 */
	public static function poll_for_logo_generation($process_id) {
		if (empty($process_id)) {
			return Helper::error(
				'invalid_process_id',
				__('Process ID is required for logo polling.', 'templately'),
				'poll_for_logo_generation',
				400
			);
		}

		$result = self::pull_remote_artifacts($process_id, '', [
			'endpoint'      => "v2/get-generated-logo/{$process_id}",
			'strict_non200' => true,
			'context'       => 'poll_for_logo_generation',
		]);

		if (is_wp_error($result)) {
			return $result;
		}

		// Return the logo generation data (images array, credit_cost, etc.)
		return [
			'status' => 'success',
			'data'   => $result['data'],
		];
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
		// First check if polling is already complete.
		//
		// NOTE this is a HARD short-circuit: once `is_last_part` is on the row, no further
		// remote call is ever made for this process. If the cloud reported "last part" while
		// pages were still unwritten, the process is stuck in that state permanently — the
		// client then reports "N pages still missing when process marked as complete" on
		// every retry, and no amount of re-polling can recover it. Log the short-circuit so
		// that state is visible rather than inferred from silence.
		$existing_record = self::read_process($process_id);
		if (!empty($existing_record['is_last_part'])) {
			Helper::log(
				sprintf('poll_for_template: %s already marked last-part — no remote call made', $process_id),
				'poll_for_template',
				'debug'
			);
			return true;
		}

		// One GET + status triage + decode via the shared routine (lenient: transient
		// transport/5xx → keep polling; 401/403/404 → terminal WP_Error).
		$result = self::pull_remote_artifacts($process_id, $session_id, [
			'endpoint'      => "v2/ai/{$process_id}/template",
			'strict_non200' => false,
			'context'       => 'poll_for_template',
		]);

		if ($result === false) {
			return false; // transient — caller may keep polling
		}
		if (is_wp_error($result)) {
			return $result; // terminal (401/403/404)
		}

		$response_data = $result['data'];

		// Extract required fields from API response
		$is_last_part = $response_data['is_last_part'] ?? false;
		$credit_cost = $response_data['credit_cost'] ?? 0;
		$templates = $response_data['templates'] ?? [];

		Helper::log(
			sprintf(
				'poll_for_template: %s → %d template(s), is_last_part=%s, credit_cost=%s',
				$process_id,
				is_array($templates) ? count($templates) : 0,
				$is_last_part ? 'yes' : 'no',
				(string) $credit_cost
			),
			'poll_for_template',
			'info'
		);

		// Save credit_cost / is_last_part by folding them into the process row.
		$patch = [];
		if ($credit_cost > 0) {
			$patch['credit_cost'] = $credit_cost;
		}
		if ($is_last_part) {
			$patch['is_last_part'] = $is_last_part;
		}
		if (!empty($patch)) {
			self::merge_process_row($process_id, $patch);
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
		// 026: aggregate the per-process rows.
		return self::get_all_processes();
	}

	/**
	 * Persist AI process records. Each entry is written as its own
	 * non-autoloaded per-process row (026 seam), preserving an existing
	 * session linkage if the incoming record omits it.
	 *
	 * @param array $data The AI process data to save (process_id => process_data)
	 * @return bool True on success, false on failure
	 */
	public static function update_ai_process_data($data) {
		if (!is_array($data)) {
			return false;
		}

		$ok = true;
		foreach ($data as $process_id => $process_data) {
			if (!is_array($process_data)) {
				continue;
			}
			// Don't drop a session linkage already written to the row.
			if (empty($process_data['session_id'])) {
				$existing = get_option(self::process_option_key($process_id), null);
				if (is_array($existing) && !empty($existing['session_id'])) {
					$process_data['session_id'] = $existing['session_id'];
				}
			}
			$ok = self::write_process_row($process_id, $process_data) && $ok;
		}

		return $ok;
	}

	/**
	 * Fields captured by the in-plugin AI conversation (AiContentSidebar).
	 *
	 * @var string[]
	 */
	const CONVERSATION_FIELDS = ['name', 'category', 'description', 'email', 'contactNumber', 'businessAddress', 'openingHour'];

	/**
	 * Whether a stored process holds an in-plugin AI conversation.
	 *
	 * A process registered by the chat handoff (`ai-content/chatbot-import-prepare`)
	 * carries none of these fields — it exists only so the import runners can locate
	 * the pages generated on the app end. Offering it as a "previous conversation"
	 * made the sidebar render every question as "I want to skip the question",
	 * because a *present but empty* field is what marks a genuinely skipped answer.
	 *
	 * @param array $process_data The stored process data.
	 * @return bool
	 */
	public static function has_conversation_data($process_data) {
		if (!is_array($process_data)) {
			return false;
		}

		foreach (self::CONVERSATION_FIELDS as $field) {
			if (array_key_exists($field, $process_data)) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Chatbot `detected_info` keys → conversation step keys.
	 *
	 * Mirrors DETECTED_INFO_KEY_MAP in AiContentSidebar/helper.js, including its
	 * snake_case/alias tolerance and its deliberate omission of `language` (that
	 * step has bespoke selection logic). Step keys map to themselves so a payload
	 * already keyed by step key passes through unchanged.
	 *
	 * @var array<string,string>
	 */
	const DETECTED_INFO_KEY_MAP = [
		'business_name'        => 'name',
		'name'                 => 'name',
		'business_type'        => 'category',
		'business_niche'       => 'category',
		'business_niches'      => 'category',
		'business_industry'    => 'category',
		'category'             => 'category',
		'business_description' => 'description',
		'description'          => 'description',
		'about'                => 'description',
		'prompt'               => 'description',
		'email'                => 'email',
		'business_email'       => 'email',
		'phone'                => 'contactNumber',
		'phone_number'         => 'contactNumber',
		'contact_number'       => 'contactNumber',
		'contactNumber'        => 'contactNumber',
		'business_address'     => 'businessAddress',
		'address'              => 'businessAddress',
		'businessAddress'      => 'businessAddress',
		'opening_hour'         => 'openingHour',
		'opening_hours'        => 'openingHour',
		'openingHour'          => 'openingHour',
	];

	/**
	 * Normalize a chatbot `detected_info` payload into conversation step keys.
	 *
	 * Accepts the plain-object shape (`{ business_name: '…' }`), the older
	 * array-of-`{key,value}` shape, and a payload already keyed by step key.
	 * Blank/non-scalar values and unknown keys are dropped; the first value wins
	 * when two aliases map to the same step.
	 *
	 * @param mixed $detected_info Raw detected info.
	 * @return array<string,string> Map of step key => sanitized value.
	 */
	public static function map_chat_detected_info($detected_info) {
		$mapped = [];

		if (!is_array($detected_info)) {
			return $mapped;
		}

		foreach ($detected_info as $key => $value) {
			// Array-of-{key,value} rows.
			if (is_array($value) && isset($value['key'])) {
				$key   = $value['key'];
				$value = isset($value['value']) ? $value['value'] : '';
			}

			if (!is_string($key) || !isset(self::DETECTED_INFO_KEY_MAP[$key])) {
				continue;
			}

			if (!is_scalar($value)) {
				continue;
			}

			$value = trim(sanitize_textarea_field((string) $value));
			if ($value === '') {
				continue;
			}

			$step_key = self::DETECTED_INFO_KEY_MAP[$key];
			if (isset($mapped[$step_key])) {
				continue;
			}

			$mapped[$step_key] = $value;
		}

		return $mapped;
	}

	/**
	 * Fill in every conversation field so a stored process reads as a complete
	 * conversation: detected answers keep their value, undetected ones stay empty
	 * (which the sidebar renders as "I want to skip the question" — accurate,
	 * since the user never answered them).
	 *
	 * Returns an empty array when nothing at all was detected, so a process is
	 * never stored as a conversation where *every* answer was skipped.
	 *
	 * @param array $mapped Output of {@see map_chat_detected_info()}.
	 * @return array<string,string>
	 */
	public static function build_conversation_fields($mapped) {
		if (empty($mapped) || !is_array($mapped)) {
			return [];
		}

		$fields = [];
		foreach (self::CONVERSATION_FIELDS as $field) {
			$fields[$field] = isset($mapped[$field]) ? $mapped[$field] : '';
		}

		return $fields;
	}

	/**
	 * Get the latest AI process data for the current API key or user ID
	 * Used in import_info() to return the most recent AI process
	 * Priority: api_key first, then user_id as fallback
	 * Only processes carrying an in-plugin conversation are considered.
	 *
	 * @return array|null The latest AI process data or null if not found
	 */
	public static function get_latest_ai_process_by_api_key($id) {
		$api_key = Options::get_instance()->get('api_key');
		$user = Options::get_instance()->get('user');
		$user_id = isset($user['id']) ? $user['id'] : null;

		$all_ai_process_data = self::get_all_processes();
		if (empty($all_ai_process_data)) {
			return null;
		}

		$matching_processes = [];

		// Priority 1: Try to find processes by API key if available
		if (!empty($api_key)) {
			foreach ($all_ai_process_data as $process_id => $process_data) {
				if (is_array($process_data) && isset($process_data['api_key']) && $process_data['api_key'] === $api_key && self::has_conversation_data($process_data)) {
					$matching_processes[$process_id] = $process_data;
				}
			}
		}

		// Priority 2: If no API key matches found or API key is empty, fallback to user_id
		if (empty($matching_processes) && !empty($user_id)) {
			foreach ($all_ai_process_data as $process_id => $process_data) {
				if (is_array($process_data) && isset($process_data['user_id']) && $process_data['user_id'] === $user_id && self::has_conversation_data($process_data)) {
					$matching_processes[$process_id] = $process_data;
				}
			}
		}

		if (empty($matching_processes)) {
			return null;
		}

		// Most recent by write time (per-process rows are no longer insertion-ordered).
		uasort($matching_processes, function ($a, $b) {
			return ($a['_updated_at'] ?? 0) <=> ($b['_updated_at'] ?? 0);
		});
		$latest_data = end($matching_processes);

		if($id != ($latest_data['pack_id'] ?? null) && isset($latest_data['imageReplace'])){
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

		// 026: single-row read (merged) instead of a full scan.
		return self::read_process($process_id);
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

		$all_ai_process_data = self::get_all_processes();
		if (empty($all_ai_process_data)) {
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

			// Remove processes that have the same pack_id (row + page sub-keys).
			if (isset($process_data['pack_id']) && $process_data['pack_id'] === $pack_id) {
				self::delete_process($process_id);
				$removed_process_ids[] = $process_id;
			}
		}

		return $removed_process_ids;
	}

	/* =====================================================================
	 * 026 — Per-process / per-page storage seam (non-autoloaded)
	 *
	 * The single read/write/merge surface for AI generation records. Replaces
	 * the two shared options (`templately_ai_process_data`,
	 * `templately_ai_processed_pages`) — which forced a read-modify-write on
	 * every callback and raced — with one row per process plus one row per
	 * completed page:
	 *
	 *   templately_ai_process_{process_id}            — the process record
	 *   templately_ai_process_{process_id}_page_{pid} — one per page completion
	 *
	 * Every write is NON-AUTOLOADED (D4). `record_page_completion` writes a
	 * DISTINCT key (no shared row, no lock → no lost updates, SC-001).
	 * `read_process` is the ONLY place that merges sub-keys.
	 * ===================================================================== */

	/** Option key for a process record. */
	public static function process_option_key($process_id) {
		return 'templately_ai_process_' . $process_id;
	}

	/** Option key for a single page-completion sub-row of a process. */
	public static function page_option_key($process_id, $page_id) {
		return 'templately_ai_process_' . $process_id . '_page_' . $page_id;
	}

	/**
	 * Write an option as NON-AUTOLOADED. For a new option `update_option`
	 * creates it with autoload='no'; for an existing one it updates the value.
	 */
	private static function write_option_no_autoload($key, $value) {
		return update_option($key, $value, false);
	}

	/**
	 * Write (create or replace) the process record row. $record carries the
	 * server-owned fields — session_id (FR-005), is_local_site (FR-004),
	 * answers, pack_id, ai_page_ids, credit_cost, is_last_part, error, …
	 *
	 * @param string $process_id
	 * @param array  $record
	 * @return bool
	 */
	public static function write_process_row($process_id, array $record) {
		if (empty($process_id)) {
			return false;
		}
		$record['process_id'] = $process_id;
		$record['_updated_at'] = time();
		return self::write_option_no_autoload(self::process_option_key($process_id), $record);
	}

	/**
	 * Record ONE page completion — called by each ai_update callback. Writes a
	 * DISTINCT per-page sub-key row; never reads-then-writes a shared row, so N
	 * concurrent callbacks for distinct pages cannot lose updates (SC-001).
	 *
	 * @param string     $process_id
	 * @param string|int $page_id     the content/page id this completion is for
	 * @param array      $completion  { content_id?, is_skipped?, payload_ref?, … }
	 * @return bool
	 */
	public static function record_page_completion($process_id, $page_id, array $completion) {
		if (empty($process_id) || $page_id === null || $page_id === '') {
			return false;
		}
		if (!isset($completion['content_id'])) {
			$completion['content_id'] = $page_id;
		}
		if (!isset($completion['_completed_at'])) {
			$completion['_completed_at'] = time();
		}
		return self::write_option_no_autoload(self::page_option_key($process_id, $page_id), $completion);
	}

	/**
	 * Collect every page-completion sub-row for a process via a single LIKE
	 * query (the only place that globs the sub-keys).
	 *
	 * @return array { page_id => completion-array }
	 */
	private static function collect_page_completions($process_id) {
		global $wpdb;
		$prefix = self::page_option_key($process_id, ''); // …_page_
		$like   = $wpdb->esc_like($prefix) . '%';
		$rows   = $wpdb->get_results(
			$wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s", $like),
			ARRAY_A
		);

		$completions = [];
		if (is_array($rows)) {
			foreach ($rows as $row) {
				$page_id = substr($row['option_name'], strlen($prefix));
				$value   = maybe_unserialize($row['option_value']);
				$completions[$page_id] = is_array($value) ? $value : [];
			}
		}
		return $completions;
	}

	/**
	 * Rebuild today's `templately_ai_processed_pages[$id]` shape
	 * — { pages: { content_id => is_skipped }, credit_cost?, is_last_part? } —
	 * from the per-page sub-rows + the process record, so existing consumers
	 * (Finalizer, Attachments, timeout handler) read an unchanged structure.
	 */
	private static function build_processed_pages($process_id, $row) {
		$completions = self::collect_page_completions($process_id);

		$pages = [];
		foreach ($completions as $page_id => $completion) {
			$content_id = $completion['content_id'] ?? $page_id;
			$pages[$content_id] = $completion['is_skipped'] ?? false;
		}

		$processed = ['pages' => $pages];
		if (isset($row['credit_cost'])) {
			$processed['credit_cost'] = $row['credit_cost'];
		}
		if (isset($row['is_last_part'])) {
			$processed['is_last_part'] = $row['is_last_part'];
		}
		return $processed;
	}

	/**
	 * Read the process record MERGED with its page sub-keys. Returns null if unknown.
	 *
	 * @return array|null [ ...record, 'processed_pages' => { content_id => is_skipped } ]
	 */
	public static function read_process($process_id) {
		if (empty($process_id)) {
			return null;
		}

		$row = get_option(self::process_option_key($process_id), null);
		if (!is_array($row)) {
			return null;
		}

		$row['processed_pages'] = self::build_processed_pages($process_id, $row);
		return $row;
	}

	/**
	 * Link a process to its import session (idempotent). Writes session_id into
	 * the process row and mirrors process_id into the session, so the client no
	 * longer re-submits identifiers via import_settings (FR-005).
	 *
	 * @return bool
	 */
	public static function link_session($process_id, $session_id) {
		if (empty($process_id) || empty($session_id)) {
			return false;
		}

		$row = get_option(self::process_option_key($process_id), null);
		if (!is_array($row)) {
			$row = [];
		}

		$row['session_id'] = $session_id;
		$written = self::write_process_row($process_id, $row);

		// Mirror the linkage onto the session so consumers that start from a
		// session id can resolve the process without the client round-trip.
		SessionData::set($session_id, 'process_id', $process_id);

		return $written;
	}

	/**
	 * Remove a process row + all its `_page_*` sub-keys. Used by cleanup and on
	 * import success.
	 *
	 * @return bool
	 */
	public static function delete_process($process_id) {
		if (empty($process_id)) {
			return false;
		}

		global $wpdb;
		delete_option(self::process_option_key($process_id));

		$prefix = self::page_option_key($process_id, '');
		$like   = $wpdb->esc_like($prefix) . '%';
		$names  = $wpdb->get_col(
			$wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $like)
		);
		if (is_array($names)) {
			foreach ($names as $name) {
				delete_option($name);
			}
		}

		return true;
	}

	/**
	 * Merge a partial patch into a process record (read raw row → merge → write).
	 * Used to fold late-arriving fields (credit_cost, is_last_part, preview_error)
	 * into the process row without touching the page sub-keys.
	 *
	 * @return bool
	 */
	public static function merge_process_row($process_id, array $patch) {
		if (empty($process_id)) {
			return false;
		}
		$row = get_option(self::process_option_key($process_id), null);
		if (!is_array($row)) {
			$row = [];
		}
		$row = array_merge($row, $patch);
		return self::write_process_row($process_id, $row);
	}

	/**
	 * Every known process id (the per-process rows) — so iteration (resume lookup,
	 * pack-id cleanup) stays complete.
	 *
	 * @return string[]
	 */
	public static function get_all_process_ids() {
		global $wpdb;

		$prefix    = 'templately_ai_process_';
		$like      = $wpdb->esc_like($prefix) . '%';
		$page_like = '%' . $wpdb->esc_like('_page_') . '%';

		$names = $wpdb->get_col($wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options}
			 WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name != %s",
			$like,
			$page_like,
			'templately_ai_process_data'
		));

		$ids = [];
		foreach ((array) $names as $name) {
			$ids[substr($name, strlen($prefix))] = true;
		}

		return array_keys($ids);
	}

	/**
	 * Remove process rows (+ their page sub-keys) whose `_updated_at` is older
	 * than $threshold_time, exempting any still linked to an active session.
	 * Reads rows RAW (no read_process) so a read can't reset the timestamp and
	 * resurrect a genuinely-expired record.
	 *
	 * @param int $threshold_time unix time; rows older than this are candidates
	 * @return string[] removed process ids
	 */
	public static function cleanup_expired_processes($threshold_time) {
		global $wpdb;

		$prefix    = 'templately_ai_process_';
		$like      = $wpdb->esc_like($prefix) . '%';
		$page_like = '%' . $wpdb->esc_like('_page_') . '%';

		$names = $wpdb->get_col($wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options}
			 WHERE option_name LIKE %s AND option_name NOT LIKE %s AND option_name != %s",
			$like,
			$page_like,
			'templately_ai_process_data'
		));

		$removed = [];
		foreach ((array) $names as $name) {
			$row = get_option($name, null);
			if (!is_array($row)) {
				continue;
			}

			$updated_at = isset($row['_updated_at']) ? (int) $row['_updated_at'] : 0;
			if ($updated_at >= $threshold_time && $updated_at > 0) {
				continue; // recently active
			}

			// Exempt if its linked session is still active.
			if (!empty($row['session_id'])) {
				$session = SessionData::get_data($row['session_id']);
				if (is_array($session) && !empty($session)) {
					$s_updated = isset($session['_updated_at']) ? (int) $session['_updated_at'] : 0;
					if ($s_updated >= $threshold_time) {
						continue;
					}
				}
			}

			$process_id = substr($name, strlen($prefix));
			self::delete_process($process_id);
			$removed[] = $process_id;
		}

		return $removed;
	}

	/**
	 * All process records keyed by process id (each merged via read_process).
	 *
	 * @return array { process_id => record }
	 */
	public static function get_all_processes() {
		$result = [];
		foreach (self::get_all_process_ids() as $process_id) {
			$record = self::read_process($process_id);
			if (is_array($record)) {
				$result[$process_id] = $record;
			}
		}
		return $result;
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


		// Always save to tmp directory for AI content workflow
		$tmp_dir = Helper::upload_dir('tmp') . $session_id . DIRECTORY_SEPARATOR;

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

		// Find the correct directory for the content ID. Normalize first: a scalar
		// group value would make in_array() throw a TypeError on PHP 8, and mixed
		// int/string ids make loose comparison unreliable.
		$ai_page_ids = self::normalize_ai_page_ids($ai_page_ids);
		$found_key   = null;
		foreach ($ai_page_ids as $key => $ids) {
			if (in_array((string) $content_id, $ids, true)) {
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
			// 026: record this page's completion as its own distinct sub-key row
			// (no shared read-modify-write → concurrent callbacks can't lose updates).
			self::record_page_completion($process_id, $content_id, [
				'content_id' => $content_id,
				'is_skipped' => $is_skipped,
			]);

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
	 * Optimized to use AI process data which already contains session_id,
	 * avoiding the need to load all session data.
	 *
	 * @param string $process_id The process ID to match
	 * @return array|false Returns matched data array or false if not found
	 */
	public static function get_matched_session_data($process_id) {
		if (empty($process_id)) {
			return false;
		}

		// Get AI process data which contains the session_id
		$process_data = self::get_ai_process_data_by_process_id($process_id);

		if (!empty($process_data['session_id'])) {
		// Direct lookup using session_id - no loading all sessions
			return SessionData::get_data($process_data['session_id']);
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
		$tmp_dir = Helper::upload_dir('tmp') . $session_id . DIRECTORY_SEPARATOR;
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
		return Helper::upload_dir('tmp') . $session_id . DIRECTORY_SEPARATOR;
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
	 * Normalize an `ai_page_ids` payload into the canonical structure:
	 * `[ 'type/sub_type' => [ 'content_id', ... ], ... ]` with ids as strings.
	 *
	 * The value reaches us from several places in several shapes — the JS import
	 * FormData (a JSON string), session data (already decoded), the manifest, and
	 * AI process data — and consumers index into it in incompatible ways. Without
	 * normalization the failure modes are ugly and inconsistent:
	 *   - `save_template_to_file()` runs `in_array($id, $ids)` with no is_array
	 *     guard → TypeError on PHP 8 if a group value is a scalar.
	 *   - `BaseRunner::is_ai_content()` runs `array_merge` over the values → same.
	 *   - `is_ai_content()` / `find_content_type_info()` DO guard with is_array,
	 *     so a scalar group makes the page silently invisible instead — the page
	 *     imports with default content and nothing is logged.
	 *
	 * Accepted inputs:
	 *   - canonical map:      `['content/page' => [1, 2]]`
	 *   - JSON string:        `'{"content/page":[1,2]}'`
	 *   - scalar group value: `['content/page' => 1]`     → `['content/page' => ['1']]`
	 *   - comma-separated:    `['content/page' => '1,2']` → `['content/page' => ['1','2']]`
	 *   - flat list:          `[1, 2]` (see caveat below)
	 *
	 * Ids become strings so membership tests can use strict comparison — PHP's
	 * loose `in_array()` on mixed int/string ids is a known foot-gun.
	 *
	 * CAVEAT: a flat list carries no `type/sub_type` grouping, and grouping cannot
	 * be invented. Such input normalizes to one id per (numeric) key, which is
	 * fine for membership and counting via {@see flatten_ai_page_ids()} but yields
	 * meaningless type info from {@see find_content_type_info()}. Callers that
	 * need the directory (file paths) require a genuinely grouped payload.
	 *
	 * @param mixed $ai_page_ids Raw value in any of the shapes above.
	 * @return array Canonical `type/sub_type => [id,...]` map (empty on junk input).
	 */
	public static function normalize_ai_page_ids($ai_page_ids) {
		// FormData sends this as a JSON string; some paths decode it, some don't.
		if (is_string($ai_page_ids)) {
			$trimmed = trim($ai_page_ids);
			if ($trimmed === '') {
				return [];
			}
			$decoded     = json_decode($trimmed, true);
			$ai_page_ids = is_array($decoded) ? $decoded : explode(',', $trimmed);
		}

		if (!is_array($ai_page_ids) || empty($ai_page_ids)) {
			return [];
		}

		$normalized = [];
		foreach ($ai_page_ids as $key => $ids) {
			// A group may arrive as a scalar, or as a comma-separated string.
			if (is_string($ids) && strpos($ids, ',') !== false) {
				$ids = explode(',', $ids);
			} elseif (!is_array($ids)) {
				$ids = [$ids];
			}

			$clean = [];
			foreach ($ids as $id) {
				// Objects/arrays are not valid ids; drop rather than stringify them.
				if (is_array($id) || is_object($id) || is_bool($id) || $id === null) {
					continue;
				}
				$id = trim((string) $id);
				if ($id === '') {
					continue;
				}
				$clean[] = $id;
			}

			if (!empty($clean)) {
				$normalized[$key] = array_values(array_unique($clean));
			}
		}

		return $normalized;
	}

	/**
	 * Flatten an `ai_page_ids` payload into a single list of content-id strings.
	 *
	 * REQUIRED before handing the value to {@see handle_sse_wait_with_timeout()},
	 * which compares `count($ai_page_ids)` against the number of processed pages.
	 * Passing the nested map counts GROUPS (typically 2-3) rather than PAGES, so
	 * the "everything is done" guard trips as soon as a couple of pages land and
	 * the wait never engages — every still-generating page then silently imports
	 * with pack default content.
	 *
	 * @param mixed $ai_page_ids Raw value in any shape {@see normalize_ai_page_ids()} accepts.
	 * @return array Flat list of unique content-id strings.
	 */
	public static function flatten_ai_page_ids($ai_page_ids) {
		$normalized = self::normalize_ai_page_ids($ai_page_ids);
		if (empty($normalized)) {
			return [];
		}

		return array_values(array_unique(array_merge(...array_values($normalized))));
	}

	/**
	 * Check if content ID is in AI page IDs
	 *
	 * @param string $content_id The content ID to check
	 * @param array $ai_page_ids The AI page IDs structure
	 * @return bool True if content ID is found in AI page IDs
	 */
	public static function is_ai_content($content_id, $ai_page_ids) {
		return in_array((string) $content_id, self::flatten_ai_page_ids($ai_page_ids), true);
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

		$process_data = self::read_process($process_id);
		if (empty($process_data)) {
			return Helper::error('process_not_found', __('Process ID not found.', 'templately'), 'validate_process_data', 404);
		}

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
		$record = self::read_process($process_id);
		return is_array($record) && isset($record['processed_pages']) ? $record['processed_pages'] : [];
	}

	/**
	 * Update processed pages data for a process ID
	 *
	 * @param string $process_id The process ID
	 * @param array $data The data to update
	 * @return bool True on success, false on failure
	 */
	public static function update_processed_pages_data($process_id, $data) {
		// 026: process-level fields (credit_cost / is_last_part) fold into the row;
		// per-page completions go through record_page_completion, not here.
		if (!is_array($data)) {
			return false;
		}
		$patch = $data;
		unset($patch['pages']);
		if (empty($patch)) {
			return true;
		}
		return self::merge_process_row($process_id, $patch);
	}

	/**
	 * Find content type and sub-type for a given content ID in AI page IDs
	 *
	 * @param string $content_id The content ID to find
	 * @param array $ai_page_ids The AI page IDs structure
	 * @return array|null Array with 'type' and 'sub_type' keys, or null if not found
	 */
	public static function find_content_type_info($content_id, $ai_page_ids) {
		$ai_page_ids = self::normalize_ai_page_ids($ai_page_ids);
		if (empty($ai_page_ids)) {
			return null;
		}

		foreach ($ai_page_ids as $key => $ids) {
			if (in_array((string) $content_id, $ids, true)) {
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

		$ai_page_ids = self::normalize_ai_page_ids($ai_page_ids);
		if (empty($ai_page_ids)) {
			return $result;
		}

		foreach ($ai_page_ids as $key => $ids) {
			foreach ($ids as $id) {
				$type_arr = explode('/', $key);
				$type = $type_arr[0];
				$sub_type = isset($type_arr[1]) ? $type_arr[1] : '';

				// 037 US4 / FR-008: every $id here is drawn from $ai_page_ids, so
				// should_process_as_ai_content() (is_ai_content || has_ai_file) was
				// UNCONDITIONALLY true in this loop — its has_ai_file() read+decode
				// (and the duplicate generate_ai_file_paths()) were redundant
				// per-poll filesystem work the completion record already answers.
				// File presence remains the include-gate (byte-identical output),
				// and the file is now decoded exactly once — for the result.
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

		return $result;
	}

}
