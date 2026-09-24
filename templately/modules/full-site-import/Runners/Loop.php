<?php

namespace Templately\Modules\FullSiteImport\Runners;

use Exception;
use Templately\Modules\FullSiteImport\Exception\FatalErrorException;
use Templately\Modules\FullSiteImport\Exception\SkippableErrorException;
use Templately\Modules\FullSiteImport\FullSiteImport;
use Templately\Modules\FullSiteImport\Concerns\LogHelper;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Utils\Helper;

/**
 * @method string get_name()
 * @method void sse_message(array $data)
 */
trait Loop {

	public static $max_error_attempts = 2;
	public static $max_consecutive_skips = 5;

	/**
	 * Undocumented function
	 *
	 * @param [type] $items
	 * @param [type] $callback($key, $item, $results)
	 * @param [type] $unique_id
	 * @param boolean $split_to_chunks
	 * @return array
	 */
	public function loop($items, $callback, $unique_id = null, $split_to_chunks = false) {
		// throw error if the callback is not callable
		if (!is_callable($callback)) {
			throw new \Exception('The callback is not callable');
		}
		if (!is_array($items)) {
			throw new \Exception('The items should be an array');
		}

		// 037 US2 (FR-004): derive the resume identity ONCE here, at a FIXED offset
		// from loop() (its direct caller), then thread it to the Loop helpers —
		// instead of each helper re-deriving it via a backtrace level that depends
		// on the helper-call-chain depth (the removed "adjust level if call depth
		// changes" foot-gun). The captured values are byte-identical to the prior
		// scheme: $ctx = the `Class::method` progress/result/skip key (was the
		// helpers' level-3 derivation); $backup_ctx = the class-only attribute
		// backup/restore key (was level-4); $sse_ctx = the continue-message key.
		// Keys are unchanged, so an in-flight import started under the old code
		// resumes normally — no restart needed (FR-005's safe-restart is moot).
		$ctx        = SessionData::get_calling_identifier($unique_id, true, false, 2);
		$sse_ctx    = SessionData::get_calling_identifier($unique_id, true, false, 3);
		$backup_ctx = !empty($this->backup_attributes)
			? SessionData::get_calling_identifier($unique_id, false, false, 3)
			: null;

		$results = $this->_get_loop_result([], $ctx);

		if(!empty($this->backup_attributes)){
			$this->_retrieve_attributes($this->backup_attributes, $backup_ctx);
		}

		$processed_since_backup = 0;

		foreach ($items as $key => $item) {
			// If the template has been processed, skip it
			if ($this->_is_key_processed($key, $ctx)) {
				continue;
			}

			// Skip-on-error: Check if feature is enabled and item should be skipped
			if ($this->_is_skip_feature_enabled()) {
				$error_attempts = SessionData::get_error_attempts($this->session_id, $ctx, $key);

				// Skip if error attempts >= MAX_ERROR_ATTEMPTS
				if ($error_attempts >= self::$max_error_attempts) {
					$this->_mark_key_skipped($key, $ctx, 'Max error attempts reached');
					$this->_increment_consecutive_skips();

					// Abort the import if too many items are skipped in a row (034 FR-004).
					// The counter is reset on any successful item (see below).
					if ($this->_get_consecutive_skips() >= self::$max_consecutive_skips) {
						throw new FatalErrorException(
							sprintf(
								/* translators: %d: number of consecutive skipped items */
								__('Import stopped: %d items were skipped in a row.', 'templately'),
								self::$max_consecutive_skips
							)
						);
					}

					continue;
				}
			}

			// Wrap callback in try-catch for skip-on-error handling
			try {
				$result  = $callback($key, $item, $results);
				if($result === 'continue'){
					// If the callback returns 'continue', skip to the next iteration
					continue;
				}
				$results = $result;

				// Success - reset consecutive skip counter
				if ($this->_is_skip_feature_enabled()) {
					$this->_reset_consecutive_skips();
				}
			} catch (SkippableErrorException $e) {
				// Catchable skip error - increment attempts and mark as skipped
				if ($this->_is_skip_feature_enabled()) {
					$this->_increment_error_attempts($key, $ctx);
					$this->_mark_key_skipped($key, $ctx, $e->getMessage());
					continue;
				} else {
					// Re-throw the ORIGINAL, don't re-wrap it in a bare \Exception.
					//
					// Wrapping erased the type: a SkippableErrorException became a plain
					// Exception, so every catch downstream that branches on the type — and
					// the SSE layer that derives `retry` from it — lost the classification
					// this class exists to carry. The message survived; the meaning did not.
					throw $e;
				}
			} catch (Exception $e) {
				// Other exceptions - increment error attempts then re-throw
				if ($this->_is_skip_feature_enabled()) {
					$this->_increment_error_attempts($key, $ctx);
				}
				throw $e;
			}

			// Mark as processed and save result
			$this->_mark_key_processed($key, $ctx);
			$this->_set_loop_result($results, $ctx);

			// Persist the runner's in-memory mapping state (backup_attributes —
			// processed_posts, url_remap, …) every few items, not ONLY at the
			// graceful chunk-exit: after a hard mid-file death, resume skips the
			// already-marked items but has NO maps for them, silently mis-wiring
			// parents/menus/featured images — a corruption class worse than
			// duplicates.
			if (!empty($this->backup_attributes) && ++$processed_since_backup >= 5) {
				$this->_backup_attributes($this->backup_attributes, $backup_ctx);
				$processed_since_backup = 0;
			}

			// If it's not the last item, send the SSE message and exit

			$is_last_runner = key( array_slice( $items, -1, 1, true ) ) === $key;

			if( (Helper::fsi_should_exit() || $split_to_chunks) && !$is_last_runner && method_exists($this, 'sse_message') ) {
				if(!empty($this->backup_attributes)){
					$this->_backup_attributes($this->backup_attributes, $backup_ctx);
				}
				$this->sse_message( [
					'type'    => 'continue',
					'action'  => 'continue',
					'name'    => method_exists($this, 'get_name') ? $this->get_name() : '',
					'index'   => $key,
					'results' => $sse_ctx,
				] );
				exit;
			}
		}
		return $results;
	}

	/**
	 * Check if a key has been processed (internal).
	 *
	 * 037 US2: takes the pre-derived progress context (the `Class::method` key
	 * captured once by loop() at a fixed offset) — no backtrace here.
	 *
	 * @param mixed  $key     The item key
	 * @param string $context The loop progress identity
	 * @return bool True if processed
	 */
	private function _is_key_processed($key, $context): bool {
		return SessionData::is_key_processed($this->session_id, $context, $key);
	}

	/**
	 * Check if a key has been processed (public wrapper). Derives the context at a
	 * FIXED offset from its own direct caller (037 US2 — byte-identical to the old
	 * level-3 derivation reached via this wrapper).
	 */
	public function is_key_processed($key, $unique_id = null): bool {
		$context = SessionData::get_calling_identifier($unique_id, true, false, 2);
		return $this->_is_key_processed($key, $context);
	}

	/**
	 * Mark a key as processed (internal).
	 *
	 * @param mixed  $key     The item key
	 * @param string $context The loop progress identity
	 * @return bool Success status
	 */
	private function _mark_key_processed($key, $context): bool {
		return SessionData::mark_key_processed($this->session_id, $context, $key);
	}

	/**
	 * Mark a key as processed (public wrapper). Context from the direct caller.
	 */
	public function mark_key_processed($key, $unique_id = null): bool {
		$context = SessionData::get_calling_identifier($unique_id, true, false, 2);
		return $this->_mark_key_processed($key, $context);
	}

	/**
	 * Set loop result (internal).
	 *
	 * @param array  $result  The result data
	 * @param string $context The loop result identity
	 * @return bool Success status
	 */
	private function _set_loop_result($result, $context): bool {
		return SessionData::set_loop_result($this->session_id, $context, $result);
	}

	/**
	 * Set loop result (public wrapper). Context from the direct caller.
	 */
	public function set_loop_result($result, $unique_id = null): bool {
		$context = SessionData::get_calling_identifier($unique_id, true, false, 2);
		return $this->_set_loop_result($result, $context);
	}

	private function _get_loop_result($defaults, $context) {
		return SessionData::get_loop_result($this->session_id, $context, $defaults);
	}

	/**
	 * Get loop result (public wrapper). Context from the direct caller, honoring
	 * the $function flag (several call sites read a class-only key with false).
	 */
	public function get_loop_result($defaults = [], $unique_id = null, $function = true) {
		$context = SessionData::get_calling_identifier($unique_id, $function, false, 2);
		return $this->_get_loop_result($defaults, $context);
	}

	// Modified get_session_data to use SessionData
	protected function get_session_data(): array {
		return SessionData::get_data($this->session_id);
	}

	// Modified update_session_data to use SessionData (deprecated - use specific methods)
	protected function update_session_data($data): bool {
		$existing = SessionData::get_data($this->session_id);
		return SessionData::save($this->session_id, array_merge($existing, $data));
	}

	// 037 US2: $backup_context is the class-only key captured once by loop() at a
	// fixed offset (was each helper's level-4 backtrace) — byte-identical.
	private function _retrieve_attributes($attributes, $backup_context){
		$attr_values = SessionData::get($this->session_id, "loop.backup_attributes.{$backup_context}", []);

		foreach ($attributes as $attribute) {
			if(isset($attr_values[$attribute])){
				$this->$attribute = $attr_values[$attribute];
			}
		}
	}

	private function _backup_attributes($attributes, $backup_context){
		$attr_values = [];

		foreach ($attributes as $attribute) {
			if(isset($this->$attribute)){
				$attr_values[$attribute] = $this->$attribute;
			}
		}

		return SessionData::set($this->session_id, "loop.backup_attributes.{$backup_context}", $attr_values);
	}

	// ============================================================================
	// Skip-on-Error Helper Methods
	// ============================================================================

	/**
	 * Check if skip-on-error feature is enabled
	 *
	 * @return bool True if enabled
	 */
	protected function _is_skip_feature_enabled(): bool {
		return (bool) get_option('templately_enable_fsi_skip_on_error', false);
	}

	/**
	 * Increment error attempts for a loop item
	 *
	 * @param mixed $key The item key
	 * @param string|null $unique_id Optional unique identifier
	 * @return int New error attempt count
	 */
	// 037 US2: all three take the pre-derived progress context from loop().
	private function _increment_error_attempts($key, $context): int {
		return SessionData::increment_error_attempts($this->session_id, $context, $key);
	}

	/**
	 * Get error attempts for a loop item
	 *
	 * @param mixed  $key     The item key
	 * @param string $context The loop progress identity
	 * @return int Error attempt count
	 */
	private function _get_error_attempts($key, $context): int {
		return SessionData::get_error_attempts($this->session_id, $context, $key);
	}

	/**
	 * Mark a loop item as skipped
	 *
	 * @param mixed  $key     The item key
	 * @param string $context The loop progress identity
	 * @param string $reason  The reason for skipping
	 * @return bool Success status
	 */
	private function _mark_key_skipped($key, $context, $reason = ''): bool {
		return SessionData::mark_key_skipped($this->session_id, $context, $key, $reason);
	}

	/**
	 * Increment consecutive skip counter
	 *
	 * @return int New consecutive skip count
	 */
	private function _increment_consecutive_skips(): int {
		return SessionData::increment_consecutive_skips($this->session_id);
	}

	/**
	 * Reset consecutive skip counter
	 *
	 * @return bool Success status
	 */
	private function _reset_consecutive_skips(): bool {
		return SessionData::reset_consecutive_skips($this->session_id);
	}

	/**
	 * Get consecutive skip count
	 *
	 * @return int Consecutive skip count
	 */
	private function _get_consecutive_skips(): int {
		return SessionData::get_consecutive_skips($this->session_id);
	}

}