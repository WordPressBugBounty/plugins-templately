<?php

namespace Templately\Core\Importer\Runners;

use Exception;
use Templately\Core\Importer\Exception\SkippableErrorException;
use Templately\Core\Importer\FullSiteImport;
use Templately\Core\Importer\LogHelper;
use Templately\Core\Importer\Utils\SessionData;
use Templately\Core\Importer\Utils\Utils;
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

		$results = $this->_get_loop_result([], $unique_id);

		if(!empty($this->backup_attributes)){
			$this->_retrieve_attributes($this->backup_attributes, $unique_id);
		}

		foreach ($items as $key => $item) {
			// If the template has been processed, skip it
			if ($this->_is_key_processed($key, $unique_id)) {
				continue;
			}

			// Skip-on-error: Check if feature is enabled and item should be skipped
			if ($this->_is_skip_feature_enabled()) {
				$calling_class = SessionData::get_calling_identifier($unique_id, true, false);
				$error_attempts = SessionData::get_error_attempts($this->session_id, $calling_class, $key);

				// Skip if error attempts >= MAX_ERROR_ATTEMPTS
				if ($error_attempts >= self::$max_error_attempts) {
					$this->_mark_key_skipped($key, $unique_id, 'Max error attempts reached');
					$this->_increment_consecutive_skips();
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
					$this->_increment_error_attempts($key, $unique_id);
					$this->_mark_key_skipped($key, $unique_id, $e->getMessage());
					continue;
				} else {
					// Feature disabled - throw as normal exception
					throw new Exception($e->getMessage(), $e->getCode(), $e);
				}
			} catch (Exception $e) {
				// Other exceptions - increment error attempts then re-throw
				if ($this->_is_skip_feature_enabled()) {
					$this->_increment_error_attempts($key, $unique_id);
				}
				throw $e;
			}

			// Mark as processed and save result
			$this->_mark_key_processed($key, $unique_id);
			$this->_set_loop_result($results, $unique_id);

			// If it's not the last item, send the SSE message and exit

			$is_last_runner = key( array_slice( $items, -1, 1, true ) ) === $key;

			if( (Helper::fsi_should_exit() || $split_to_chunks) && !$is_last_runner && method_exists($this, 'sse_message') ) {
				if(!empty($this->backup_attributes)){
					$this->_backup_attributes($this->backup_attributes, $unique_id);
				}
				$this->sse_message( [
					'type'    => 'continue',
					'action'  => 'continue',
					'name'    => method_exists($this, 'get_name') ? $this->get_name() : '',
					'index'   => $key,
					'results' => SessionData::get_calling_identifier($unique_id, true, false, 3),
				] );
				exit;
			}
		}
		return $results;
	}

	/**
	 * Check if a key has been processed (internal)
	 *
	 * @param mixed $key The item key
	 * @param string|null $unique_id Optional unique identifier
	 * @return bool True if processed
	 */
	private function _is_key_processed($key, $unique_id = null): bool {
		$calling_class = SessionData::get_calling_identifier($unique_id, true, false);
		return SessionData::is_key_processed($this->session_id, $calling_class, $key);
	}

	/**
	 * Check if a key has been processed (public wrapper)
	 */
	public function is_key_processed($key, $unique_id = null): bool {
		return $this->_is_key_processed($key, $unique_id);
	}

	/**
	 * Mark a key as processed (internal)
	 *
	 * @param mixed $key The item key
	 * @param string|null $unique_id Optional unique identifier
	 * @return bool Success status
	 */
	private function _mark_key_processed($key, $unique_id = null): bool {
		$calling_class = SessionData::get_calling_identifier($unique_id, true, false);
		return SessionData::mark_key_processed($this->session_id, $calling_class, $key);
	}

	/**
	 * Mark a key as processed (public wrapper)
	 */
	public function mark_key_processed($key, $unique_id = null): bool {
		return $this->_mark_key_processed($key, $unique_id);
	}

	/**
	 * Set loop result (internal)
	 *
	 * @param array $result The result data
	 * @param string|null $unique_id Optional unique identifier
	 * @return bool Success status
	 */
	private function _set_loop_result($result, $unique_id = null): bool {
		$calling_class = SessionData::get_calling_identifier($unique_id, true, false);
		return SessionData::set_loop_result($this->session_id, $calling_class, $result);
	}

	/**
	 * Set loop result (public wrapper)
	 */
	public function set_loop_result($result, $unique_id = null): bool {
		return $this->_set_loop_result($result, $unique_id);
	}

	private function _get_loop_result($defaults = [], $unique_id = null, $function = true) {
		$calling_class = SessionData::get_calling_identifier($unique_id, $function, false);
		return SessionData::get_loop_result($this->session_id, $calling_class, $defaults);
	}

	public function get_loop_result($defaults = [], $unique_id = null, $function = true) {
		return $this->_get_loop_result($defaults, $unique_id, $function);
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

	private function _retrieve_attributes($attributes, $unique_id){
		$calling_class = SessionData::get_calling_identifier($unique_id, false, false, 4);
		$attr_values = SessionData::get($this->session_id, "loop.backup_attributes.{$calling_class}", []);

		foreach ($attributes as $attribute) {
			if(isset($attr_values[$attribute])){
				$this->$attribute = $attr_values[$attribute];
			}
		}
	}

	private function _backup_attributes($attributes, $unique_id){
		$calling_class = SessionData::get_calling_identifier($unique_id, false, false, 4);
		$attr_values = [];

		foreach ($attributes as $attribute) {
			if(isset($this->$attribute)){
				$attr_values[$attribute] = $this->$attribute;
			}
		}

		return SessionData::set($this->session_id, "loop.backup_attributes.{$calling_class}", $attr_values);
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
	private function _increment_error_attempts($key, $unique_id = null): int {
		$calling_class = SessionData::get_calling_identifier($unique_id, true, false);
		return SessionData::increment_error_attempts($this->session_id, $calling_class, $key);
	}

	/**
	 * Get error attempts for a loop item
	 *
	 * @param mixed $key The item key
	 * @param string|null $unique_id Optional unique identifier
	 * @return int Error attempt count
	 */
	private function _get_error_attempts($key, $unique_id = null): int {
		$calling_class = SessionData::get_calling_identifier($unique_id, true, false);
		return SessionData::get_error_attempts($this->session_id, $calling_class, $key);
	}

	/**
	 * Mark a loop item as skipped
	 *
	 * @param mixed $key The item key
	 * @param string|null $unique_id Optional unique identifier
	 * @param string $reason The reason for skipping
	 * @return bool Success status
	 */
	private function _mark_key_skipped($key, $unique_id = null, $reason = ''): bool {
		$calling_class = SessionData::get_calling_identifier($unique_id, true, false);
		return SessionData::mark_key_skipped($this->session_id, $calling_class, $key, $reason);
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