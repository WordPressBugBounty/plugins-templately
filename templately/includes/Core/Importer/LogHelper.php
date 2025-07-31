<?php

namespace Templately\Core\Importer;

use Templately\Core\Importer\Utils\LogHandler;
use Templately\Core\Importer\Utils\Utils;
use Templately\Utils\Helper;

trait LogHelper {
	private static $ai_last_progress = 0;
	private $log_types = [
		''
	];

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
        $request_params = Utils::get_session_data_by_id();

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

			if ( is_array( $log ) || is_object( $log ) ) {
				error_log( print_r( $log, true ) );
			} else if($log) {
				error_log( $log );
			}

		}
	}
}