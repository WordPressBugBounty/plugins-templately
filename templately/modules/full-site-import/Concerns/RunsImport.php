<?php

namespace Templately\Modules\FullSiteImport\Concerns;

use Elementor\Plugin;
use Error;
use Exception;
use Templately\Modules\FullSiteImport\Import;
use Templately\Modules\FullSiteImport\Exception\FatalErrorException;
use Templately\Modules\FullSiteImport\Exception\RetryableErrorException;
use Templately\Modules\FullSiteImport\Runners\Finalizer;
use Templately\Modules\FullSiteImport\Utils\LogHandler;
use Templately\Utils\Response\AjaxResponder;
use Templately\Utils\Response\ErrorCode;
use Templately\Utils\Response\FatalGuard;
use Templately\Modules\FullSiteImport\Utils\ImportHelper;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Modules\FullSiteImport\Utils\AIUtils;
use Templately\Utils\Helper;
use Templately\Utils\Options;
use Templately\Modules\FullSiteImport\Utils\AttachmentPrefetcher;


/**
 * RunsImport — extracted verbatim from FullSiteImport (behavior-preserving).
 * Composed back into FullSiteImport via `use`; $this and method resolution unchanged.
 */
trait RunsImport {
	/**
	 * Owner token of this request's per-session run lock (null = not held).
	 * @var string|null
	 */
	protected $run_lock_token = null;

	public function admin_init() {
		if (get_option('templately_flush_rewrite_rules', false)) {
			flush_rewrite_rules();
			delete_option('templately_flush_rewrite_rules');
		}
	}

	private function finishRequestHeaders() {
		// Survive the client going away — on EVERY server, not just LiteSpeed.
		//
		// The browser closes its stream the moment it receives `continue`, while
		// this request is still shutting down. Anything echoed after that point
		// (core spawns wp-cron on `shutdown`, and the Attachments runner's
		// pre_http_request hook logs that loopback over SSE) is a write to a
		// closed connection. Without this, PHP aborts on that write and DROPS the
		// remaining shutdown functions — including register_shutdown(), which
		// releases the run lock. The orphaned lock then turns every reconnect
		// into `wait` until the 180s stale takeover: a three-minute freeze per
		// affected chunk. The work stays bounded either way — runners chunk-exit
		// on the request time budget.
		ignore_user_abort(true);

		if(Helper::should_flush()) {
			// Disable output buffering and compression
			@ini_set('output_buffering', 'Off');
			@ini_set('zlib.output_compression', 'Off');
			@ini_set('implicit_flush', 1);

			// Time to run the import!  Set no limit
			set_time_limit(0);


			// Set headers to prevent caching and buffering
			header('Content-Type: text/event-stream, charset=UTF-8');
			header('Cache-Control: no-cache, must-revalidate');
			header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
			header('Connection: Keep-Alive');
			header('Pragma: no-cache');

			if (!empty($GLOBALS['is_nginx'])) {
				header('X-Accel-Buffering: no');
				header('Content-Encoding: none');
			}

			flush();
			ob_flush();
			wp_ob_end_flush_all();
		} else {
			header("Cache-Control: no-store, no-cache");
			// header( 'Content-Type: text/event-stream, charset=UTF-8' );
			// header( "Connection: Keep-Alive" );

			// Ignore user aborts and allow the script to run forever
			// (Use with caution, consider progress updates or timeouts)
			ignore_user_abort(true);

			// Time to run the import!  Set no limit
			set_time_limit(0);


			if (!empty($GLOBALS['is_nginx'])) {
				header('X-Accel-Buffering: no');
				header('Content-Encoding: none');
			}

			// Send output as soon as possible during long-running process
			if (function_exists('fastcgi_finish_request')) {
				fastcgi_finish_request();
			} elseif (function_exists('litespeed_finish_request')) {
				litespeed_finish_request();
			} else {
				wp_ob_end_flush_all();
			}
		}
	}

	public function import() {
		if ( ! $this->dev_mode && ! wp_doing_ajax() ) {
			exit;
		}

		Utils::add_gd_editor_filter();


		define('TEMPLATELY_START_TIME', microtime(true));

		// delete_option( 'templately_fsi_log' );

		register_shutdown_function( [ $this, 'register_shutdown' ] );

		$this->finishRequestHeaders();

		try {
			$user = Options::get_instance()->get('user');
			if (!empty($user['is_disconnected'])) {
				$this->throw(__('Your site connection is disconnected. Please migrate your connection first.', 'templately'));
			}

			if(!empty($_GET['session_id'])){
				// Security: Sanitize session_id from user input
				$session_id = AIUtils::sanitize_path_component(sanitize_text_field($_GET['session_id']), 'session_id');
				if (is_wp_error($session_id)) {
					$this->throw($session_id->get_error_message());
				}
				$this->session_id = $session_id;
			}
			else {
				$this->throw(__('Invalid Session ID.', 'templately'));
			}


			// Replay guard (spec 042 FR-032): a successfully-completed session's
			// progress markers make every runner skip — "resuming" it would stream
			// the OLD import's results as a fresh 'complete'. Refuse here directly
			// (NOT via throw: the catch below would handle_import_status('failed'),
			// overwriting the stored 'success' marker and filing a bogus failure
			// report upstream).
			if (SessionData::is_completed($this->session_id)) {
				$stale_message = __('This import has already been completed. Please start a new import.', 'templately');
				$this->sse_error(new FatalErrorException($stale_message), $stale_message);
				return;
			}

			// Run lock: ONE import() execution per session. The watchdog's
			// reconnect races the request it abandoned (which may still be
			// executing — detached outright on the LiteSpeed branch); a live
			// lock means "come back shortly", not an error: `wait` makes the
			// client close and reconnect in 5s, by which time the holder has
			// chunk-exited (and its shutdown released the lock) or gone stale.
			$this->run_lock_token = SessionData::acquire_run_lock($this->session_id);
			if (null === $this->run_lock_token) {
				$this->sse_message([
					'type'   => 'wait',
					'action' => 'wait',
				]);
				exit;
			}

			$this->request_params = $this->get_session_data();
 			$this->initialize_props();
			$this->add_revert_hooks();
			$progress = $this->request_params['progress'] ?? [];

			// TEST-ONLY, strictly gated: force ONE recoverable (retryable) step error to
			// exercise the retry affordance for the 023 T071 forced-retry E2E. Fires ONLY
			// when the test has set the `_templately_test_force_step_error` option, and
			// self-clears immediately so the subsequent retry proceeds normally. The option
			// is never set in production — this is a single dormant get_option() there.
			if ( get_option( '_templately_test_force_step_error' ) ) {
				delete_option( '_templately_test_force_step_error' );
				throw new RetryableErrorException( __( 'Forced test error (retryable) — exercising the FSI retry affordance.', 'templately' ) );
			}

			// Trigger action hook for network admin multisite handling. Also the
			// inversion seam for per-import platform wiring: elementor-integration
			// hooks this to register its Pro-promotion child-type filter, so FSI
			// no longer reaches into a platform module (mirroring the
			// templately_fsi_* filter seams the gutenberg side already uses).
			do_action( 'templately_fsi_before_import', $this, $this->request_params );

			// Refresh progress after potential multisite creation
			$progress = $this->request_params['progress'] ?? [];

			if(empty($progress['create_log_dir'])){
				// Create Log Directory and if fail then chose option method
				LogHandler::create_log_dir();

				SessionData::mark_step_complete($this->session_id, 'create_log_dir');
				$this->sse_message( [
					'type'    => 'eventLog',
					'action'  => 'eventLog',
					'info'    => 'create_log_dir',
					'results' => __METHOD__ . '::' . __LINE__,
				] );
			}

			$_id = isset($this->request_params['id']) ? (int) $this->request_params['id'] : null;

			if ($_id === null) {
				$this->throw(__('Invalid Pack ID.', 'templately'));
			}

			$this->sse_message( [
				'type'    => 'start',
				'action'  => 'eventLog',
				'results' => __METHOD__ . '::' . __LINE__,
			] );

			if(empty($progress['check_writing_permission'])){
				/**
				 * Check Writing Permission
				 */
				$this->check_writing_permission();

				SessionData::mark_step_complete($this->session_id, 'check_writing_permission');
			}

			if(empty($progress['download_zip'])){

				/**
				 * Download the zip
				 */
				$this->download_zip( $_id );

				SessionData::mark_step_complete($this->session_id, 'download_zip');
				$this->sse_message( [
					'type'    => 'continue',
					'action'  => 'continue',
					'info'    => 'download_zip',
					'results' => __METHOD__ . '::' . __LINE__,
				] );
				exit;
			}




			/**
			 * Reading Manifest File
			 */
			$this->manifest = $this->read_manifest($this->request_params['dir_path']);

			/**
			 * Version Check
			 */
			if ( ! empty( $this->manifest['version'] ) && version_compare( $this->manifest['version'], $this->version, '>' ) ) {
				/**
				 * FIXME: The message should be re-written (by content/support team).
				 */
				$this->throw( __( 'Please update the templately plugin.', 'templately' ) );
			}

			$platform = $this->manifest['platform'] ?? '';
			if($platform === 'elementor') {
				Helper::enable_elementor_container();
			}



			update_option('templately_import_platform', $platform);


			/**
			 * Should Revert Old Data
			 */
			// $this->revert();

			/**
			 * Platform Based Templates Import
			 */
			$this->start_content_import();

		} catch ( Exception $e ) {
			$this->handle_import_status('failed', $e->getMessage());

			// Log the full trace server-side; never leak it to the browser (034 FR-003).
			Helper::log( $e->getTraceAsString(), 'import_error_trace' );

			// One producer (PRD PHP-4): derives `retry` from the exception type and
			// persists the frame so LiteSpeed clients, which never receive the SSE
			// stream, can still learn the import failed via import_status().
			$this->sse_error( $e );
		}

		// if($_GET['part'] === 'import'){
			// TODO: cleanup
			// $this->clear_session_data();
		// }
	}

	// Updated import_status method
	public function import_status() {
		$request_params = $this->get_session_data();

		if (isset($request_params['log_type']) && $request_params['log_type'] == 'file') {
			$log_index  = isset($_GET['lastLogIndex']) ? (int) $_GET['lastLogIndex'] : 0;
			$log = LogHandler::read_log_file($log_index);

			// Enveloped like every other response (PRD PHP-5). The poller reads
			// `body.data`; see eventSourcePolyfill.fetchProgress().
			// ImportHelper, not LogHelper: the latter is a TRAIT, and PHP 8.1
			// deprecates calling a static trait method on the trait itself. The
			// notice would land mid-response on a polled status endpoint.
			AjaxResponder::success(array_filter([
				'count' => count($log),
				'log'   => $log,
				'error' => ImportHelper::take_last_error($this->session_id ?? ''),
			], function ($value) { return null !== $value; }));
		} else {
			$log = get_option('templately_fsi_log');

			if (!empty($log) && is_array($log) && isset($_GET['lastLogIndex'])) {
				$lastLogIndex = (int) $_GET['lastLogIndex'];
				$log = array_slice($log, $lastLogIndex);
			}
			// LiteSpeed buffers SSE, so these clients never see the stream and poll
			// here instead. Without the error they polled a silent endpoint forever.
			AjaxResponder::success(array_filter([
				'count' => $log ? count($log) : 0,
				'log'   => $log,
				'error' => ImportHelper::take_last_error($this->session_id ?? ''),
			], function ($value) { return null !== $value; }));
		}
	}

	/**
	 * @throws Exception
	 */
	private function start_content_import() {
		add_filter('upload_mimes', array($this, 'allow_svg_upload'));
		add_filter('elementor/files/allow_unfiltered_upload', '__return_true');

		$request_params = $this->get_session_data();

		// Remote images are fetched a few at a time, in parallel, into this session's
		// working directory — see AttachmentPrefetcher. Booted per request; the files
		// themselves survive a chunk boundary.
		AttachmentPrefetcher::boot( $this->dir_path, function ( $message ) {
			$this->sse_log( 'prefetch', $message, 1, 'eventLog' );
		} );

		$import        = new Import(array_merge($request_params, [
			'origin'   => $this,
			'manifest' => $this->manifest,
		]));
		$imported_data = $import->run();

		AttachmentPrefetcher::purge();

		$import_status = $this->handle_import_status('success');

		update_option('templately_flush_rewrite_rules', true, false);

		$normalized_data = $this->normalize_imported_data($imported_data);
		// Use timeout-aware wait handler for AI content processing
		if(!empty($request_params['ai_page_ids']) && empty($normalized_data['ai_content']['processed']['credit_cost'])){
			$updated_ids = AIUtils::get_processed_pages_data($request_params['process_id']);

			// Use the static timeout-aware wait handler from AIUtils.
			// ai_page_ids must be FLATTENED — the handler counts it against the
			// number of processed pages (see AIUtils::flatten_ai_page_ids).
			AIUtils::handle_sse_wait_with_timeout(
				$this->session_id,
				'ai_content_import_time',
				$updated_ids,
				AIUtils::flatten_ai_page_ids($request_params['ai_page_ids']),
				[$this, 'sse_message'],
				[
					// Diagnostic only — it rides the `wait` payload, which the client uses
					// solely to reconnect. `message` used to be here too and, being merged
					// LAST, it replaced the wait row's label with the words "Missing Credit
					// Cost": an internal condition name shown to the user as a progress step.
					// The row is gone now, and so is the label that only existed to name it.
					'name' => 'ai-content',
				],
				null, // No specific template ID for this context
				30
			);
		}

		// Get skipped items if skip feature was enabled
		$skipped_items = SessionData::get_skipped_items($this->session_id);
		if (!empty($skipped_items)) {
			$normalized_data['skipped_items'] = $skipped_items;
		}

		$this->sse_message([
			'type'    => 'complete',
			'action'  => 'complete',
			'results' => $normalized_data,
		]);

		update_user_meta(get_current_user_id(), 'templately_fsi_pack_id', $request_params["id"]);
		if(!empty($import_status['hasFeedback'])){
			update_user_meta(get_current_user_id(), 'templately_fsi_complete', 'done');
		}
		else{
			update_user_meta(get_current_user_id(), 'templately_fsi_complete', true);
		}

		do_action('templately_fsi_import_complete', $normalized_data);
		$this->clear_data_file($request_params);
	}

	public function register_shutdown() {
		// Release the run lock FIRST — every exit path (chunk-exit, completion,
		// fatal) funnels through shutdown, and the owner-token check means a
		// superseded request can never delete a newer execution's lock.
		if ( ! empty( $this->run_lock_token ) && ! empty( $this->session_id ) ) {
			SessionData::release_run_lock( $this->session_id, $this->run_lock_token );
			$this->run_lock_token = null;
		}

		// Shared fatal detection + full-detail logging (spec 043 FR-008: the raw
		// PHP message/file/line goes to the Templately log, NEVER to the client —
		// the old handler streamed the first lines of the fatal over SSE with only
		// ABSPATH masked, leaking plugin-relative server paths).
		$last_error = FatalGuard::detect_and_log( 'full-site-import' );
		if ( null !== $last_error ) {
			$safe_message = ErrorCode::default_message( ErrorCode::FATAL_ERROR );

			$this->handle_import_status( 'failed', $safe_message );

			// A PHP fatal is not retryable — the same request will die the same
			// way. FatalErrorException keeps the SSE frame's `retry` false.
			$this->sse_error( new FatalErrorException( $safe_message ), $safe_message );
		}

		$this->debug_log("Shutdown:.....");
		$this->debug_log("connection_status: " . $this->getConnectionStatusText());
		$this->debug_log($last_error);
	}

	protected function getConnectionStatusText() {
		$status = connection_status();
		switch ($status) {
			case CONNECTION_NORMAL:
				return "Normal";
			case CONNECTION_ABORTED:
				return "Aborted";
			case CONNECTION_TIMEOUT:
				return "Timeout";
			default:
				return "Unknown";
		}
	}

}
