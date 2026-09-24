<?php

namespace Templately\Modules\FullSiteImport\Concerns;

use Templately\Modules\FullSiteImport\Utils\LogHandler;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Modules\FullSiteImport\Exception\RetryableErrorException;
use Templately\Utils\Helper;

trait LogHelper {
	private static $ai_last_progress = 0;
	private $log_types = [
		''
	];

	/**
	 * The SINGLE producer of an SSE error frame (spec 043 / PRD PHP-4).
	 *
	 * There were three ad-hoc error frames built inline, which is why they had
	 * drifted apart — one hardcoded `retry => true` regardless of whether the
	 * failure could ever succeed on a second attempt, so the UI offered Retry for
	 * terminal errors and the user pressed it to reach the same wall.
	 * Retryability now comes from the exception type.
	 *
	 * It also PERSISTS the error, which is the LiteSpeed fix. LiteSpeed buffers
	 * SSE, so those clients never receive the stream at all — they fall back to
	 * polling `import_status()`, which returned only logs. An import could fail and
	 * the client would poll a silent endpoint forever with no error and no end.
	 * The frame is stored under `templately_fsi_last_error_{session_id}` and
	 * `import_status()` returns it.
	 *
	 * The message is the exception's user-facing text. The TRACE is never included
	 * — it goes to `Helper::log()` (034 FR-003 / 043 FR-008).
	 *
	 * @param \Throwable  $error
	 * @param string|null $message Overrides the exception message when the caller
	 *                             has friendlier copy.
	 * @return void
	 */
	public function sse_error( $error, $message = null ) {
		$retry = $error instanceof RetryableErrorException;

		$frame = [
			'action'  => 'error',
			'status'  => 'error',
			'type'    => 'error',
			'retry'   => $retry,
			'title'   => __( 'Oops!', 'templately' ),
			'message' => $message !== null ? $message : $error->getMessage(),
		];

		$this->persist_last_error( $frame );

		$this->sse_message( $frame );
	}

	/**
	 * Store the last error so a polling (LiteSpeed) client can still learn the
	 * import failed. Best-effort: a storage failure must not mask the error we are
	 * in the middle of reporting.
	 *
	 * @param array $frame
	 * @return void
	 */
	protected function persist_last_error( array $frame ) {
		$session_id = isset( $this->session_id ) ? $this->session_id : '';

		if ( empty( $session_id ) ) {
			return;
		}

		update_option( 'templately_fsi_last_error_' . $session_id, $frame, false );
	}

	/**
	 * Read (and clear) the persisted error for a session.
	 *
	 * Cleared on read so a later, successful import of the same session cannot
	 * report a stale failure.
	 *
	 * @param string $session_id
	 * @return array|null
	 */
	public static function take_last_error( $session_id ) {
		if ( empty( $session_id ) ) {
			return null;
		}

		$key   = 'templately_fsi_last_error_' . $session_id;
		$error = get_option( $key, null );

		if ( ! empty( $error ) ) {
			delete_option( $key );
			return $error;
		}

		return null;
	}

	public function sse_log( $type, $message, $progress = 1, $action = 'updateLog', $status = null ) {
		$data = [
			'action'   => $action,
			'type'     => $type,
			'progress' => $progress,
			'message'  => $message
		];

		if ( $progress == 100 && $status == null ) {
			$data['status'] = 'complete';
		} elseif ( $status != null ) {
			$data['status'] = $status;
		}

		$this->sse_message( $data );
	}

	public function removeLog( $type ) {
		$this->sse_message( [
			'action'   => 'removeLog',
			'type'     => $type,
			'progress' => 100
		] );
	}

	public function sse_message( $data, $ai_content = true ) {
		// Log the data into debug log file
		$this->sse_log_file( $data );

		if(Helper::should_flush()){
			echo "event: message\n";
			echo 'data: ' . wp_json_encode( $data ) . "\n\n";

			// Extra padding.
			echo esc_html( ':' . str_repeat( ' ', 2048 ) . "\n\n" );

			flush();
			if (ob_get_level() > 0) {
				ob_flush();
			}
		}

        // $data = Utils::get_session_data_by_id();
		// if($ai_content && !empty($data['process_id']) && $data['process_id'] !== 'undefined' && "null" !== $data['process_id']){

		// 	// wp_cache_delete( 'templately_ai_processed_pages', 'options' );
		// 	global $wpdb;
		// 	$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'templately_ai_processed_pages' ) );
		// 	// You might need to manually unserialize the value if it was serialized
		// 	$processed_pages = maybe_unserialize( $value );
        //     // $processed_pages = get_option( "templately_ai_processed_pages", [] );

		// 	$updated_ids = $processed_pages[$data['process_id']]['pages'] ?? [];
		// 	$ai_page_ids = array_reduce($data['ai_page_ids'], 'array_merge', array());

		// 	// $ai_page_ids = array_filter(array_map('intval', explode(',', $data['ai_page_ids'] ?? '')));
		// 	// Calculate progress percentage

		// 	$total_pages         = count($ai_page_ids);
		// 	$updated_pages       = count($updated_ids);
		// 	$progress_percentage = $total_pages > 0 ? round(($updated_pages / $total_pages) * 100) : 0;

		// 	if( $progress_percentage != self::$ai_last_progress ) {
		// 		self::$ai_last_progress = $progress_percentage;
		// 		$this->sse_message( [
		// 			'type'        => 'ai-content',
		// 			'action'      => 'updateLog',
		// 			'progress'    => $progress_percentage,
		// 			// 'name'        => method_exists($runner, 'get_name') ? $runner->get_name() : '',
		// 			'processed_pages' => $processed_pages,
		// 			// 'asdfg' => $updated_ids,
		// 		], false );
		// 	}
		// }
		// else {
		// 	$log = get_option( 'templately_fsi_log' ) ?: [];
		// 	$log[] = $data;
		// 	update_option( 'templately_fsi_log', $log );
		// }
		// else if($data['action'] === 'complete' || $data['action'] === 'downloadComplete' || $data['action'] === 'error'){
		// 	wp_send_json( $data );
		// }
	}

	/**
	 * Printing Error Logs in debug.log file or in option.
	 *
	 * @param mixed $log
	 * @return void
	 */
	public function sse_log_file( $log ){
        $session_id = SessionData::get_session_id();
        $request_params = $session_id ? SessionData::get_data($session_id) : [];

        if (is_array($log)) {
            $log['timestamp'] = date('Y-m-d H:i:s');
        }

        if (isset($request_params['log_type']) && $request_params['log_type'] == 'file') {
			LogHandler::sse_log_file($log);
        } else {
            $_log = get_option('templately_fsi_log') ?: [];
            $_log[] = $log;
            update_option('templately_fsi_log', $_log, false);
        }
	}
	/**
	 * Printing Error Logs in debug.log file.
	 *
	 * @param mixed $log
	 * @return void
	 */
	public function debug_log( $log ){
		if ( defined('TEMPLATELY_EVENT_LOG') && TEMPLATELY_EVENT_LOG === true ) {

			if ( is_array( $log ) || is_object( $log ) || $log ) {
				Helper::log( $log, 'fsi_event' );
			}

		}
	}
}