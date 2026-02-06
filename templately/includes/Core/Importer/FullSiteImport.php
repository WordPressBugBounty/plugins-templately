<?php

/**
 * remove 'action'  => 'continue',
 * way to retry
 * way to skip if failed multiple times
 *
 *	@todo: on runner check for timeout and retry
 *	@todo: use ErrorException on runner to skip item when error occurs: not useful
 *
 */


namespace Templately\Core\Importer;

use Elementor\Plugin;
use Error;
use Exception;
use Templately\Core\Importer\Exception\NonRetirableErrorException;
use Templately\Core\Importer\Exception\RetryableErrorException;
use Templately\Core\Importer\Exception\UnknownErrorException;
use Templately\Core\Importer\Runners\Finalizer;
use Templately\Core\Importer\Utils\LogHandler;
use Templately\Core\Importer\Utils\Utils;
use Templately\Core\Importer\Utils\AIUtils;
use Templately\Utils\Base;
use Templately\Utils\Helper;
use Templately\Utils\Installer;
use Templately\Utils\Options;

class FullSiteImport extends Base {
	use LogHelper;

	const SESSION_OPTION_KEY = 'templately_import_session';
	public    $manifest;
	protected $export;

	private $version = '1.0.0';

	public    $download_key;
	protected $dev_mode       = false;
	protected $api_key        = '';
	protected $session_id        = '';
	protected $documents_data = [];
	private   $is_import_status_handled = false;

	public    $dir_path;
	protected $filePath;
	protected $tmp_dir        = null;
	public    $request_params = [];

	// Polling-specific property for ai_poll_template()
	private   $polling_is_last_part = null;

	public function __construct() {
		$this->dev_mode = defined('TEMPLATELY_DEV') && TEMPLATELY_DEV;
		$this->api_key  = Options::get_instance()->get('api_key');

		$this->add_ajax_action('import_settings', $this);
		$this->add_ajax_action('create_session_and_download', $this);
		$this->add_ajax_action('import_status', $this);
		$this->add_ajax_action('import', $this);
		$this->add_ajax_action('import_revert', $this);
		$this->add_ajax_action('import_info', $this);
		$this->add_ajax_action('import_close_feedback_modal', $this);
		$this->add_ajax_action('feedback_form', $this);
		$this->add_ajax_action('google_font', $this);
		$this->add_ajax_action('ai_get_json', $this);
		$this->add_ajax_action('ai_poll_template', $this);

		add_action('admin_init', [$this, 'admin_init']);
		// add_action('admin_notices', [$this, 'add_revert_button']);

		if(isset($_GET['action']) && ($_GET['action'] == 'templately_pack_import' || $_GET['action'] == 'templately_pack_import_status')) {
			add_filter('wp_redirect', '__return_false', 999);
		}

		if ($this->dev_mode) {
			add_filter('http_request_host_is_external', '__return_true');
			add_filter('http_request_args', function ($args) {
				$args['sslverify'] = false;

				return $args;
			});
		}
	}

	public function add_ajax_action($action, $object) {
		add_action("wp_ajax_templately_pack_$action", function() use ($action, $object) {
			// Check nonce
			$nonce = null;
			if(isset($_POST['nonce'])){
				$nonce = $_POST['nonce'];
			}
			if(isset($_GET['nonce'])){
				$nonce = $_GET['nonce'];
			}
			if (!$nonce || !wp_verify_nonce($nonce, 'templately_nonce')) {
				wp_send_json_error(['message' => __('Invalid nonce', 'templately')]);
				wp_die();
			}

			// Check user capability
			if (!current_user_can('install_plugins') || !current_user_can('install_themes')) {
				wp_send_json_error(['message' => __('Insufficient permissions', 'templately')]);
				wp_die();
			}

			// Call the actual handler method
			call_user_func([$this, $action]);
		});
	}

	public function admin_init() {
		if (get_option('templately_flush_rewrite_rules', false)) {
			flush_rewrite_rules();
			delete_option('templately_flush_rewrite_rules');
		}
	}

	public function import_settings() {
		$data = wp_unslash($_POST);

		$upload_dir  = wp_upload_dir();

		if(!empty($data['session_id'])){
			$session_id = $data['session_id'];
			// Security: Sanitize session_id from user input
			$session_id = AIUtils::sanitize_path_component($data['session_id'], 'session_id');
			if (is_wp_error($session_id)) {
				wp_send_json_error(['message' => $session_id->get_error_message()]);
				return;
			}
			$session_data = Utils::get_session_data($session_id);
			$data = array_merge($session_data, $data);
		}
		else {
			$session_id  = uniqid();
		}

		$tmp_dir = trailingslashit($upload_dir['basedir']) . 'templately' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR;
		$prv_dir = trailingslashit($upload_dir['basedir']) . 'templately' . DIRECTORY_SEPARATOR . 'preview' . DIRECTORY_SEPARATOR;

		$this->session_id = $session_id;
		$data['session_id'] = $session_id;

		$data['root_dir'] = $tmp_dir;
		$data['prv_dir']  = $prv_dir;
		$data['dir_path'] = $tmp_dir . $session_id . DIRECTORY_SEPARATOR;
		$data['zip_path'] = $tmp_dir . "{$session_id}.zip";


		if ( is_array( $data ) && ! empty( $data ) ) {
			foreach ( $data as $key => $value ) {
				$json         = is_string($value) ? json_decode( $value, true ) : null;
				$data[ $key ] = $json !== null ? $json : $value;
			}
		}

		Utils::update_session_data($session_id, $data);


		//clear previous revert backup
		$options = Utils::get_backup_options();
		foreach ($options as $key => $value) {
			delete_option("__templately_$key");
		}
		delete_option('templately_fsi_imported_list');
		delete_option('templately_fsi_log');

		wp_send_json_success([
			'is_lightspeed' => !Helper::should_flush(),
			'session_id'    => $session_id,
		]);
	}

	public function import_ai_settings() {
		$data = wp_unslash($_POST);

		$upload_dir  = wp_upload_dir();

		// Security: Sanitize session_id from user input
		$session_id = AIUtils::sanitize_path_component($data['session_id'], 'session_id');
		if (is_wp_error($session_id)) {
			wp_send_json_error(['message' => $session_id->get_error_message()]);
			return;
		}

		$tmp_dir = trailingslashit($upload_dir['basedir']) . 'templately' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR;
		$prv_dir = trailingslashit($upload_dir['basedir']) . 'templately' . DIRECTORY_SEPARATOR . 'preview' . DIRECTORY_SEPARATOR;

		$this->session_id = $session_id;
		$data['root_dir'] = $tmp_dir;
		$data['prv_dir']  = $prv_dir;
		$data['dir_path'] = $tmp_dir . $session_id . DIRECTORY_SEPARATOR;
		$data['zip_path'] = $tmp_dir . "{$session_id}.zip";

		// Handle isLocalSite flag conversion
		if (isset($data['isLocalSite'])) {
			$data['isLocalSite'] = filter_var($data['isLocalSite'], FILTER_VALIDATE_BOOLEAN);
		}

		if ( is_array( $data ) && ! empty( $data ) ) {
			foreach ( $data as $key => $value ) {
				$json         = is_string($value) ? json_decode( $value, true ) : null;
				$data[ $key ] = $json !== null ? $json : $value;
			}
		}

		Utils::update_session_data($session_id, $data);


		return $data;
	}

	public function create_session_and_download() {
		if ( ! $this->dev_mode && ! wp_doing_ajax() ) {
			exit;
		}

		add_filter( 'wp_image_editors', [ $this, 'wp_image_editors' ], 10, 1 );

		define('TEMPLATELY_START_TIME', microtime(true));

		register_shutdown_function( [ $this, 'register_shutdown' ] );

		// $this->finishRequestHeaders();

		try {
			// Get session data from AJAX request
			$session_data = $this->import_ai_settings();

			$this->request_params = $session_data;
			$this->initialize_props();
			$this->add_revert_hooks();
			$progress = $this->request_params['progress'] ?? [];

			if(empty($progress['create_log_dir'])){
				// Create Log Directory and if fail then chose option method
				LogHandler::create_log_dir();

				$progress['create_log_dir'] = true;
				$this->update_session_data( [
					'progress' => $progress,
				] );
			}

			$_id = isset($this->request_params['id']) ? (int) $this->request_params['id'] : null;

			if ($_id === null) {
				$this->throw(__('Invalid Pack ID.', 'templately'));
			}

			$this->check_writing_permission();


			if(empty($progress['download_zip'])){

				/**
				 * Download the zip
				 */
				$this->download_zip( $_id, true );

				$progress['download_zip'] = true;
				$this->update_session_data( [
					'progress' => $progress,
				] );
			}

			/**
			 * Reading Manifest File
			 */
			$this->manifest = $this->read_manifest($this->request_params['dir_path']);

			/**
			 * Version Check
			 */
			if ( ! empty( $this->manifest['version'] ) && version_compare( $this->manifest['version'], $this->version, '>' ) ) {
				$this->throw( __( 'Please update the templately plugin.', 'templately' ) );
			}

			$platform = $this->manifest['platform'] ?? '';
			if($platform === 'elementor') {
				Helper::enable_elementor_container();
			}

			update_option('templately_import_platform', $platform);

			// Return success response for AJAX
			wp_send_json_success([
				'session_id' => $this->session_id,
				'pack_downloaded' => true,
				'platform' => $platform,
				'message' => __('Session created and pack downloaded successfully', 'templately')
			]);

		} catch ( Exception $e ) {
			$should_retry = $e instanceof RetryableErrorException;

			wp_send_json_error([
				'message' => $e->getMessage(),
				'should_retry' => $should_retry
			]);
		}
	}

	public function import_close_feedback_modal() {
		$return = null;
		if(isset($_GET['closeAction']) && $_GET['closeAction']){
			$review_email = isset($_POST['review-email']) ? sanitize_email($_POST['review-email']) : '';
			$pack_id      = get_user_meta(get_current_user_id(), 'templately_fsi_pack_id', true);

			// Prepare the body of the request
			$body = json_encode([
				'action'      => $_GET['closeAction'],
				'email'       => $review_email,
				'pack_id'     => (int) $pack_id,
			]);

			// Send the request to the API
			$response = Helper::make_api_post_request('v2/feedback/close', json_decode($body, true), [], 30);
			$body = wp_remote_retrieve_body($response);
			$return = json_decode($body, true);
		}
		update_user_meta(get_current_user_id(), 'templately_fsi_complete', 'done');
		wp_send_json_success($return);
	}
	public function feedback_form() {
		// Get data from $_POST
		$review_description = isset($_POST['review-description']) ? sanitize_textarea_field($_POST['review-description']) : '';
		$review_email       = isset($_POST['review-email']) ? sanitize_email($_POST['review-email']) : '';
		$rating             = isset($_POST['rating']) ? sanitize_text_field($_POST['rating']) : '';
		$pack_id            = get_user_meta(get_current_user_id(), 'templately_fsi_pack_id', true);

		// Prepare the body of the request
		$body = json_encode([
			'description' => $review_description,
			'email'       => $review_email,
			'rating'      => (int) $rating,
			'pack_id'     => (int) $pack_id,
		]);

		// Send the request to the API
		$response = Helper::make_api_post_request('v2/feedback/store', json_decode($body, true), [], 30);

		if (is_wp_error($response)) {
			wp_send_json_error($response->get_error_message());
		}

		if (wp_remote_retrieve_response_code($response) != 200) {
			$error_message = $this->extract_error_from_response($response);
			wp_send_json_error($error_message, wp_remote_retrieve_response_code($response));
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (!isset($data['status']) || $data['status'] !== 'success') {
			wp_send_json_error('API response indicates failure.');
		}

		if (!isset($data['message'])) {
			wp_send_json_error('API response missing data.');
		}

		$result = $data['message'];

		wp_send_json_success($result);
	}

    // Modified get_session_data to use the static version
    public function get_session_data() {
        return Utils::get_session_data_by_id();
    }

    // Modified update_session_data to use the static version
    public function update_session_data($data) {
        return Utils::update_session_data_by_id($data);
    }

	public function initialize_props() {
		$data = $this->get_session_data();
		if (isset($data['session_id'])) {
			$this->session_id = $data['session_id'];
		}
		if (isset($data['dir_path'])) {
			$this->dir_path = $data['dir_path'];
		}
		if (isset($data['zip_path'])) {
			$this->filePath = $data['zip_path'];
		}
		if (isset($data['download_key'])) {
			$this->download_key = $data['download_key'];
		}
		if (isset($data['is_import_status_handled'])) {
			$this->is_import_status_handled = $data['is_import_status_handled'];
		}
	}

	public function clear_session_data(): bool {
		return delete_site_option(self::SESSION_OPTION_KEY);
	}

	private function finishRequestHeaders() {
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

		add_filter( 'wp_image_editors', [ $this, 'wp_image_editors' ], 10, 1 );


		define('TEMPLATELY_START_TIME', microtime(true));

		// delete_option( 'templately_fsi_log' );

		register_shutdown_function( [ $this, 'register_shutdown' ] );

		$this->finishRequestHeaders();

		try {
			// TODO: Need to check if user is connected or not
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


			$this->request_params = $this->get_session_data();
 			$this->initialize_props();
			$this->add_revert_hooks();
			$progress = $this->request_params['progress'] ?? [];

			// Trigger action hook for network admin multisite handling
			do_action( 'templately_fsi_before_import', $this, $this->request_params );

			// Refresh progress after potential multisite creation
			$progress = $this->request_params['progress'] ?? [];

			if(empty($progress['create_log_dir'])){
				// Create Log Directory and if fail then chose option method
				LogHandler::create_log_dir();

				$progress['create_log_dir'] = true;
				$this->update_session_data( [
					'progress' => $progress,
				] );
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

				$progress['check_writing_permission'] = true;
				$this->update_session_data( [
					'progress' => $progress,
				] );
			}

			if(empty($progress['download_zip'])){

				/**
				 * Download the zip
				 */
				$this->download_zip( $_id );

				$progress['download_zip'] = true;
				$this->update_session_data( [
					'progress' => $progress,
				] );
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
			$should_retry = $e instanceof RetryableErrorException;
			$this->handle_import_status('failed', $e->getMessage());

			$this->sse_message([
				'action'  => 'error',
				'status'  => 'error',
				'type'    => "error",
				'retry'   => $should_retry,
				'title'   => __("Oops!", "templately"),
				'message' => $e->getMessage(),
				'trace'   => $e->getTraceAsString(),
			]);
		}

		// if($_GET['part'] === 'import'){
			// TODO: cleanup
			// $this->clear_session_data();
		// }
	}


	public function wp_image_editors( $editors ) {
		// If GD is available, use only GD. Otherwise, fallback to all available editors.
		if ( is_callable( [ 'WP_Image_Editor_GD', 'test' ] ) && call_user_func( [ 'WP_Image_Editor_GD', 'test' ] ) ) {
			return [ 'WP_Image_Editor_GD' ];
		}
		return $editors;
	}

	// Updated import_status method
	public function import_status() {
		$request_params = $this->get_session_data();

		if (isset($request_params['log_type']) && $request_params['log_type'] == 'file') {
			$log_index  = isset($_GET['lastLogIndex']) ? (int) $_GET['lastLogIndex'] : 0;
			$log = LogHandler::read_log_file($log_index);

			wp_send_json(['count' => count($log), 'log' => $log]);
		} else {
			$log = get_option('templately_fsi_log');

			if (!empty($log) && is_array($log) && isset($_GET['lastLogIndex'])) {
				$lastLogIndex = (int) $_GET['lastLogIndex'];
				$log = array_slice($log, $lastLogIndex);
			}
			wp_send_json(['count' => $log ? count($log) : 0, 'log' => $log]);
		}
	}

	/**
	 * @throws Exception
	 */
	private function throw($message, $code = 0) {
		if ($this->dev_mode) {
			error_log(print_r($message, 1));
		}
		throw new Exception($message);
	}
	/**
	 * @throws Exception
	 */
	private function throw_non_retryable($message, $code = 0) {
		if ($this->dev_mode) {
			error_log(print_r($message, 1));
		}
		throw new NonRetirableErrorException($message);
	}
	/**
	 * @throws Exception
	 */
	private function throw_retryable($message, $code = 0) {
		if ($this->dev_mode) {
			error_log(print_r($message, 1));
		}
		throw new RetryableErrorException($message);
	}
	/**
	 * @throws Exception
	 */
	private function throw_unknown($message, $code = 0) {
		if ($this->dev_mode) {
			error_log(print_r($message, 1));
		}
		throw new UnknownErrorException($message);
	}

	/**
	 * @throws Exception
	 */
	private function check_writing_permission() {
		$upload_dir = wp_upload_dir();

		if (!is_writable($upload_dir['basedir'])) {
			$this->throw(__('Upload directory is not writable.', 'templately'));
		}

		$this->tmp_dir = trailingslashit($upload_dir['basedir']) . 'templately' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR;

		if (!is_dir($this->tmp_dir)) {
			wp_mkdir_p($this->tmp_dir);
		}

		$this->sse_log('writing_permission_check', __('Permission Passed', 'templately'), 100);
	}

	/**
	 * @throws Exception
	 */
	private function download_zip( $id, $is_ai = false ) {
		$this->sse_log( 'download', __( 'Downloading Template Pack', 'templately' ), 1 );
		$extra_headers = [
			'x-templately-is-ai'              => $is_ai,
			'x-templately-session-id'         => $this->session_id,
			'x-templately-requested-platform' => $this->request_params["requested_platform"] ?? 'templately',
		];
		$response = Helper::make_api_get_request("v2/import/pack/$id", [], $extra_headers, 90);

		$response_code = wp_remote_retrieve_response_code($response);
		$content_type  = wp_remote_retrieve_header($response, 'content-type');
		$this->download_key  = wp_remote_retrieve_header($response, 'download-key');

		if (is_wp_error($response)) {
			$this->throw_retryable(__('Template pack download failed', 'templately') . $response->get_error_message());
		} else if ($response_code != 200) {
			if (strpos($content_type, 'application/json') !== false) {
				// Retrieve Data from Response Body.
				$response_body = json_decode(wp_remote_retrieve_body($response), true);

				// If the response body is JSON and it contains an error, throw an exception with the error message
				if (isset($response_body['status']) && $response_body['status'] === 'error') {
					$support_message = '';
					if(strpos($response_body['message'], 'https://wpdeveloper.com/support') === false){
						$support_message = sprintf(__(" Please try again or contact <a href='%s' target='_blank'>support</a>.", "templately"), 'https://wpdeveloper.com/support');
					}
					$this->throw_non_retryable($response_body['message'] . $support_message);
				}
			}
			$this->throw_unknown(__('Template pack download failed with response code: ', 'templately') . $response_code);
		}

		$this->sse_log('download', __('Downloading Template Pack', 'templately'), 57);

		$this->update_session_data([
			'download_key' => $this->download_key,
		]);

		// Security: Validate file path is within WordPress upload directory before writing
		$validation = AIUtils::validate_file_path($this->filePath);
		if (is_wp_error($validation)) {
			$this->throw($validation->get_error_message());
		}

		wp_mkdir_p(dirname($this->filePath));

		if (file_put_contents($this->filePath, $response['body'])) { // phpcs:ignore
			$this->sse_log('download', __('Downloading Template Pack', 'templately'), 100);

			$this->unzip();
		} else {
			$this->throw_retryable(__('Downloading Failed. Please try again', 'templately'));
		}
	}

	/**
	 * @throws Exception
	 */
	protected function unzip() {
		if (!WP_Filesystem()) {
			$this->throw(__('WP_Filesystem cannot be initialized', 'templately'));
		}
		$unzip = unzip_file($this->filePath, $this->dir_path);
		if (is_wp_error($unzip)) {
			$unzip = $this->unzip_file($this->filePath, $this->dir_path);
		}

		$manifest_file = $this->dir_path . 'manifest.json';

		// If manifest.json is missing, but any subdirectory contains manifest.json, move all its contents up and remove the subdirectory.
		if ( ! file_exists( $manifest_file ) ) {
			$entries = array_diff( scandir( $this->dir_path ), [ '.', '..' ] );
			$dirs = array_filter( $entries, fn($e) => is_dir( $this->dir_path . $e ) );
			$files = array_filter( $entries, fn($e) => is_file( $this->dir_path . $e ) );
			foreach ($dirs as $subdir) {
				$subdir_path = $this->dir_path . $subdir . DIRECTORY_SEPARATOR;
				if ( file_exists( $subdir_path . 'manifest.json' ) ) {
					copy($subdir_path . 'manifest.json', $manifest_file);

					foreach ( array_diff( scandir( $subdir_path ), [ '.', '..' ] ) as $item ) {
						$src = $subdir_path . $item;
						$dst = $this->dir_path . $item;
						if (is_dir($src)) {
							if (!file_exists($dst)) {
								wp_mkdir_p($dst);
							}
							// Recursively copy directory
							$this->copyDirectory($src, $dst);
						} else {
							copy($src, $dst);
						}
					}
					// Remove the subdirectory and its contents
					$this->removeDirectory($subdir_path);
					break; // Only process the first subdir with manifest.json
				}
			}
		}

		if (is_wp_error($unzip)) {
			$error = $unzip->get_error_message();
			if (empty($error)) {
				// Generic error message
				Helper::log($unzip);
				$error_message = sprintf(__("It seems we're experiencing technical difficulties. Please try again or contact <a href='%s' target='_blank'>support</a>.", "templately"), 'https://wpdeveloper.com/support');
				$this->throw($error_message);
			} else {
				$this->throw($unzip->get_error_message());
			}
		}

		if ($unzip) {
			unlink($this->filePath);
		}
	}

	/**
	 * Recursively copy a directory
	 */
	private function copyDirectory($src, $dst) {
		$dir = opendir($src);
		wp_mkdir_p($dst);
		while(false !== ($file = readdir($dir))) {
			if (($file != '.') && ($file != '..')) {
				if (is_dir($src . DIRECTORY_SEPARATOR . $file)) {
					$this->copyDirectory($src . DIRECTORY_SEPARATOR . $file, $dst . DIRECTORY_SEPARATOR . $file);
				} else {
					copy($src . DIRECTORY_SEPARATOR . $file, $dst . DIRECTORY_SEPARATOR . $file);
				}
			}
		}
		closedir($dir);
	}

	/**
	 * Recursively remove a directory
	 */
	private function removeDirectory($dir) {
		if (!file_exists($dir)) return;
		$items = array_diff(scandir($dir), ['.', '..']);
		foreach ($items as $item) {
			$path = $dir . DIRECTORY_SEPARATOR . $item;
			if (is_dir($path)) {
				$this->removeDirectory($path);
			} else {
				unlink($path);
			}
		}
		rmdir($dir);
	}



	/**
	 * Unzip a specified ZIP file to a location on the Filesystem.
	 *
	 * @param string $file Full path and filename of ZIP archive.
	 * @param string $to Full path on the filesystem to extract archive to.
	 * @return true|WP_Error True on success, WP_Error on failure.
	 */
	function unzip_file($file, $to) {
		try {
			$zip = new \ZipArchive;

			$res = $zip->open($file);
			if ($res === TRUE) {
				$zip->extractTo($to);
				$zip->close();

				return true;
			}
		} catch (\Throwable $th) {
			return new \WP_Error('exception_caught', $th->getMessage());
		}

		if (isset($zip)) {
			return new \WP_Error('zip_error_' . $zip->status, $zip->getStatusString());
		} else {
			return new \WP_Error('unknown_error', '');
		}
	}

	/**
	 * @throws Exception
	 */
	private function read_manifest($dir_path) {
		$manifest_content = file_get_contents($dir_path . 'manifest.json');
		if (empty($manifest_content)) {
			$this->throw(__('Cannot be imported, as the manifest file is corrupted', 'templately'));
		}

		$manifest_content = json_decode($manifest_content, true);
		$this->removeLog('temp');

		return $manifest_content;
		// TODO: Read & Broadcast the LOG for waiting list
		// $this->sse_log( 'plugin', 'Installing required plugins', '--', 'updateLog', 'processing' );
		// // $this->sse_log( 'extra-content', 'Import Extra Contents (i.e: Forms)', '--', 'updateLog', 'processing' );
		// $this->sse_log( 'templates', 'Import Templates (i.e: Header, Footer etc)', '--', 'updateLog', 'processing' );
		// // $this->sse_log( 'content', 'Import Pages, Posts etc', '--', 'updateLog', 'processing' );
		// $this->sse_log( 'wp-content', 'Importing Pages, Posts, Navigation, etc', '--', 'updateLog', 'processing' );
		// $this->sse_log( 'finalize', 'Finalizing Your Imports', '--', 'updateLog', 'processing' );
	}

	private function skipped_plugin(): bool {
		return empty($this->request_params['plugins']) || !is_array($this->request_params['plugins']);
	}


	private function before_install_hook() {
		// remove_all_actions( 'wp_loaded' );
		// remove_all_actions( 'after_setup_theme' );
		// remove_all_actions( 'plugins_loaded' );
		// remove_all_actions( 'init' );

		// making sure so that no redirection happens during plugin installation and hooks triggered bellow.
		add_filter('wp_redirect', '__return_false', 999);
	}

	private function after_install_hook() {
		// do_action( 'wp_loaded' );
		// do_action( 'after_setup_theme' );
		// do_action( 'plugins_loaded' );
		// do_action( 'init' );
	}

	/**
	 * @throws Exception
	 */
	private function start_content_import() {
		add_filter('upload_mimes', array($this, 'allow_svg_upload'));
		add_filter('elementor/files/allow_unfiltered_upload', '__return_true');

		$request_params = $this->get_session_data();

		$import        = new Import(array_merge($request_params, [
			'origin'   => $this,
			'manifest' => $this->manifest,
		]));
		$imported_data = $import->run();

		$import_status = $this->handle_import_status('success');

		update_option('templately_flush_rewrite_rules', true, false);

		$normalized_data = $this->normalize_imported_data($imported_data);
		// Use timeout-aware wait handler for AI content processing
		if(!empty($request_params['ai_page_ids']) && empty($normalized_data['ai_content']['processed']['credit_cost'])){
			$processed_pages = get_option("templately_ai_processed_pages", []);
			$updated_ids = $processed_pages[$request_params['process_id']] ?? [];

			// Use the static timeout-aware wait handler from AIUtils
			AIUtils::handle_sse_wait_with_timeout(
				$this->session_id,
				'ai_content_import_time',
				$updated_ids,
				$request_params['ai_page_ids'],
				[$this, 'sse_message'],
				[
					'name' => 'ai-content',
					'message' => __('Missing Credit Cost', 'templately'),
				],
				null, // No specific template ID for this context
				30
			);
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
		// $this->clear_data_file($request_params);
	}

	private function clear_data_file($request_params){
		if(defined('TEMPLATELY_DEV') && TEMPLATELY_DEV){
			return;
		}

		// Handle directory cleanup
		Utils::cleanup_directory($this->dir_path);
		$upload_dir = wp_upload_dir();

		// Always save to preview directory for AI content workflow
		$session_id = $request_params['session_id'] ?? '';
		$pack_id = $request_params['id'] ?? '';

		// Set up directory paths for cleanup
		$root_dir = $request_params['root_dir'] ?? trailingslashit($upload_dir['basedir']) . 'templately' . DIRECTORY_SEPARATOR . 'tmp';
		$prv_dir = $request_params['prv_dir'] ?? trailingslashit($upload_dir['basedir']) . 'templately' . DIRECTORY_SEPARATOR . 'preview';

		$processed_data = AIUtils::get_ai_process_data_by_session_id($session_id);

		// Clean up WordPress options data and corresponding directories
		if (!empty($pack_id) && !empty($session_id)) {
			// Clean session data - keep only current session, remove others with same pack_id
			$removed_session_ids = Utils::clean_session_data_by_pack_id($pack_id, $session_id);

			// Clean AI process data - keep only current process, remove others with same pack_id
			$current_process_id = !empty($processed_data['process_id']) ? $processed_data['process_id'] : null;
			$removed_process_ids = AIUtils::clean_ai_process_data_by_pack_id($pack_id, $current_process_id);

			// Directory-based cleanup for session data directories
			$this->cleanup_session_directories($root_dir, $pack_id, $session_id);

			// Directory-based cleanup for AI process data directories
			$this->cleanup_ai_process_directories($prv_dir, $pack_id, $current_process_id);

			// Log cleanup results if in dev mode
			if (defined('TEMPLATELY_DEV') && TEMPLATELY_DEV) {
				if (!empty($removed_session_ids)) {
					error_log('Templately: Cleaned up session IDs: ' . implode(', ', $removed_session_ids));
				}
				if (!empty($removed_process_ids)) {
					error_log('Templately: Cleaned up process IDs: ' . implode(', ', $removed_process_ids));
				}
			}
		}
	}

	/**
	 * Directory-based cleanup for session data directories
	 * Scans the actual filesystem directories and removes directories that match cleanup criteria
	 *
	 * @param string $root_dir The root directory containing session directories
	 * @param string $pack_id The pack ID to match for cleanup
	 * @param string $current_session_id The current session ID to preserve
	 */
	private function cleanup_session_directories($root_dir, $pack_id, $current_session_id) {
		if (empty($root_dir) || !is_dir($root_dir) || empty($pack_id) || empty($current_session_id)) {
			return;
		}

		try {
			// Get all session data to check pack_id associations
			$all_session_data = Utils::get_all_session_data();

			// Scan the actual directories in the filesystem
			$directories = scandir($root_dir);
			if ($directories === false) {
				return;
			}

			foreach ($directories as $dir_name) {
				// Skip current directory, parent directory, and current session
				if ($dir_name === '.' || $dir_name === '..' || $dir_name === $current_session_id) {
					continue;
				}

				$dir_path = trailingslashit($root_dir) . $dir_name;

				// Only process actual directories
				if (!is_dir($dir_path)) {
					continue;
				}

				// Check if this directory should be cleaned up
				$should_cleanup = false;

				// If we have session data for this directory, check if it matches the pack_id
				if (isset($all_session_data[$dir_name]) &&
					isset($all_session_data[$dir_name]['id']) &&
					$all_session_data[$dir_name]['id'] === $pack_id) {
					$should_cleanup = true;
				} else if (!isset($all_session_data[$dir_name])) {
					// This is an orphaned directory with no corresponding session data
					$should_cleanup = true;
				}

				if ($should_cleanup) {
					Utils::cleanup_directory($dir_path);

					if (defined('TEMPLATELY_DEV') && TEMPLATELY_DEV) {
						error_log('Templately: Cleaned up session directory: ' . $dir_name);
					}
				}
			}
		} catch (Exception $e) {
			if (defined('TEMPLATELY_DEV') && TEMPLATELY_DEV) {
				error_log('Templately: Error during session directory cleanup: ' . $e->getMessage());
			}
		}
	}

	/**
	 * Directory-based cleanup for AI process data directories
	 * Scans the actual filesystem directories and removes directories that match cleanup criteria
	 *
	 * @param string $prv_dir The preview directory containing process directories
	 * @param string $pack_id The pack ID to match for cleanup
	 * @param string $current_process_id The current process ID to preserve (optional)
	 */
	private function cleanup_ai_process_directories($prv_dir, $pack_id, $current_process_id = null) {
		if (empty($prv_dir) || !is_dir($prv_dir) || empty($pack_id)) {
			return;
		}

		try {
			// Get all AI process data to check pack_id associations
			$ai_process_data = AIUtils::get_ai_process_data();

			// Scan the actual directories in the filesystem
			$directories = scandir($prv_dir);
			if ($directories === false) {
				return;
			}

			foreach ($directories as $dir_name) {
				// Skip current directory, parent directory, and current process
				if ($dir_name === '.' || $dir_name === '..' ||
					(!empty($current_process_id) && $dir_name === $current_process_id)) {
					continue;
				}

				$dir_path = trailingslashit($prv_dir) . $dir_name;

				// Only process actual directories
				if (!is_dir($dir_path)) {
					continue;
				}

				// Check if this directory should be cleaned up
				$should_cleanup = false;

				// If we have process data for this directory, check if it matches the pack_id
				if (isset($ai_process_data[$dir_name]) &&
					is_array($ai_process_data[$dir_name]) &&
					isset($ai_process_data[$dir_name]['pack_id']) &&
					$ai_process_data[$dir_name]['pack_id'] === $pack_id) {
					$should_cleanup = true;
				} else if (!isset($ai_process_data[$dir_name])) {
					// This is an orphaned directory with no corresponding process data
					$should_cleanup = true;
				}

				if ($should_cleanup) {
					Utils::cleanup_directory($dir_path);

					if (defined('TEMPLATELY_DEV') && TEMPLATELY_DEV) {
						error_log('Templately: Cleaned up AI process directory: ' . $dir_name);
					}
				}
			}
		} catch (Exception $e) {
			if (defined('TEMPLATELY_DEV') && TEMPLATELY_DEV) {
				error_log('Templately: Error during AI process directory cleanup: ' . $e->getMessage());
			}
		}
	}

	private function normalize_imported_data($data) {
		$request_params     = $this->get_session_data();
		$attachments        = !empty($data['attachments']['succeed']) ? count($data['attachments']['succeed']) : 0;
		$attachments_fail   = !empty($data['attachments']['failed']) ? count($data['attachments']['failed']) : 0;
		$attachments_errors = !empty($data['attachments_errors']) ? $data['attachments_errors'] : [];
		$templates          = !empty($data['templates']['succeed']) ? count($data['templates']['succeed']) : 0;
		$template_types     = !empty($data['templates']['template_types']) ? $data['templates']['template_types'] : [];
		$dependency_data    = !empty($data['dependency_data']) ? $data['dependency_data'] : [];

		$post_types = [];
		$content_templates = [];
		if (!empty($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as $type => $type_data) {
				$content_templates[$type] = !empty($type_data['succeed']) ? count($type_data['succeed']) : 0;
				$post_types[] = $this->get_post_type_label_by_slug($type);
			}
		}

		$contents = [];
		if (!empty($data['wp-content']) && is_array($data['wp-content'])) {
			foreach ($data['wp-content'] as $type => $type_data) {
				$contents[$type] = !empty($type_data['succeed']) ? count($type_data['succeed']) : 0;
				if (!in_array($type, ['wp_navigation', 'nav_menu_item'])) {
					$post_types[] = $this->get_post_type_label_by_slug($type);
				}
			}
		}

		$_processed_pages = AIUtils::get_processed_pages_data($request_params['process_id']);
		$ai_content = [
			'requested' => $request_params['ai_page_ids'] ?? [],
			'processed' => $_processed_pages,
		];


		$result = [
			'attachments'        => $attachments,
			'attachments_fail'   => $attachments_fail,
			'attachments_errors' => $attachments_errors,
			'templates'          => $templates,
			'contents'           => $content_templates,
			'wp-content'         => $contents,
			'post_types'         => $post_types,
			'template_types'     => $template_types,
			'ai_content'         => $ai_content,
			'dependency_data'    => $dependency_data,
			'home_url'           => home_url('/'),
		];

		Helper::log($data);
		Helper::log($result);

		return $result;
	}

	public function get_request_params() {
		return $this->request_params;
	}

	private function revert() {
		// $request = $this->get_request_params();
		// if ( isset( $request['revert'] ) && $request['revert'] ) {
		// 	// TODO: Implement the Revert Process.
		// }
	}

	public function redirect_for_archives($link, $post_id) {
		$archive_settings = get_option('templately_post_archive');
		if (!empty($archive_settings) && intval($archive_settings['post_id']) === intval($post_id)) {
			$link = str_replace($post_id, $archive_settings['archive_id'], $link);
		}

		return $link;
	}

	public function allow_svg_upload($mimes) {
		// Allow SVG
		$mimes['svg'] = 'image/svg+xml';
		return $mimes;
	}

	public function register_shutdown() {
		$status     = connection_status();
		$last_error = error_get_last();
		if ($last_error && ($last_error['type'] === E_ERROR || $last_error['type'] === E_CORE_ERROR || $last_error['type'] === E_COMPILE_ERROR || $last_error['type'] === E_USER_ERROR)) {
			if (!empty($last_error['message'])) {
				$full_message = $last_error['message'];
				$lines = explode("\n", $full_message);

				// For import status: first 5 lines
				$import_status_message = implode("\n", array_slice($lines, 0, 5));
				$import_status_message = str_replace(ABSPATH, 'ABSPATH/', $import_status_message);

				// For SSE: first line only
				$sse_message = $lines[0];
				$sse_message = str_replace(ABSPATH, 'ABSPATH/', $sse_message);
			} else {
				// Generic error message
				$import_status_message = sprintf(__("It seems we're experiencing technical difficulties. Please try again or contact <a href='%s' target='_blank'>support</a>.", "templately"), 'https://wpdeveloper.com/support');
				$sse_message = $import_status_message;
			}

			$this->handle_import_status('failed', $import_status_message);
			$this->sse_message([
				'action'   => 'error',
				'status'   => 'error',
				'type'     => "error",
				'retry'    => true,
				'title'    => __("Oops!", "templately"),
				'message'  => $sse_message,
				'error'    => $last_error,
				// 'position' => 'plugin',
				// 'progress' => '--',
			]);
		}

		$this->debug_log("Shutdown:.....");
		$this->debug_log("connection_status: " . $this->getConnectionStatusText());
		$this->debug_log($last_error);
	}

	public function handle_import_status($status, $description = '') {
		if ($this->is_import_status_handled === $status) {
			Helper::log("Import status already handled: $status");
			return null;
		}
		$this->is_import_status_handled = $status;

		$download_key = $this->download_key;

		$headers = [
			'Content-Type'     => 'application/json',
			'Authorization'    => 'Bearer ' . $this->api_key,
			'download_key'     => $download_key,
			'download-key'     => $download_key,
			'x-templately-ip'  => Helper::get_ip(),
			'x-templately-url' => home_url('/'),
		];


		$request_params = $this->get_session_data();
		if(isset($request_params['process_id']) && !empty($request_params['ai_page_ids'])){
			$updated_ids     = AIUtils::get_processed_pages_data($request_params['process_id']);
			$updated_pages   = $updated_ids['pages'] ?? [];
			$ai_page_ids     = array_reduce($request_params['ai_page_ids'], 'array_merge', array());

			$headers['x-templately-ai-process-id']      = $request_params['process_id'];
			$headers['x-templately-ai-requested-pages'] = implode(',', $ai_page_ids);
			$headers['x-templately-ai-updated-pages']   = implode(',', array_keys($updated_pages));
			$headers['x-templately-ai-missing-pages']   = implode(',', array_diff($ai_page_ids, array_keys($updated_pages)));
			$headers['x-templately-ai-credit-cost']     = $updated_ids['credit_cost'] ?? null;
		}


		$extra_headers = $headers;

		if ($status === 'success') {
			$body = ['type' => 'pack'];
			$response = Helper::make_api_post_request('v1/import/success', $body, $extra_headers);
		} elseif ($status === 'failed') {
			$body = ['type' => 'pack', 'description' => $description ?: "Something Went wrong....."];
			$response = Helper::make_api_post_request('v1/import/failed', $body, $extra_headers);
		}

		Helper::log($response);

		if (is_wp_error($response)) {
			// Handle error
			Helper::log($response->get_error_message());
		} else {

			$this->update_session_data([
				'is_import_status_handled' => $this->is_import_status_handled,
			]);
			// Handle success
			$body = wp_remote_retrieve_body($response);
			$data = json_decode($body, true);
			// Do something with $body
			return $data;
		}

		return null;
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

	protected function get_post_type_label_by_slug($slug) {
		$post_type_obj = get_post_type_object($slug);
		if ($post_type_obj) {
			return $post_type_obj->label;
		}
		return null;
	}

	public function import_info() {

		$platform = isset($_GET['platform']) ? $_GET['platform'] : 'elementor';
		$id       = isset($_GET['id']) ? intval($_GET['id']) : 0;
		$isAi     = isset($_GET['isAi']) ? $_GET['isAi'] : false;

		$extra_headers = [
			'x-templately-is-ai' => $isAi,
		];
		$response = Helper::make_api_get_request("v2/import/info/pack/$id", [], $extra_headers, 30);

		if (is_wp_error($response)) {
			wp_send_json_error($response->get_error_message());
			return;
		}
		// If the response code is not 200, return the error message
		if (wp_remote_retrieve_response_code($response) != 200) {
			$error_message = $this->extract_error_from_response($response);
			wp_send_json_error($error_message, wp_remote_retrieve_response_code($response));
			return;
		}
		// If the response body is JSON and it contains an error, return the error message
		// Retrieve Data from Response Body.
		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		if (isset($data['error'])) {
			wp_send_json_error($data['error']);
			return;
		}

		$business_niches = get_option('templately_ai_business_niches', []);
		$data['data']['business_niches'] = $business_niches;

		if (isset($data['data']['manifest'])) {
			$data['data']['manifest'] = json_decode($data['data']['manifest'], true);
		}
		if (isset($data['data']['settings'])) {
			$data['data']['settings'] = json_decode($data['data']['settings'], true);
		}

		if ($isAi) {
			// Get the latest AI process for the current API key
			$last_ai_process = AIUtils::get_latest_ai_process_by_api_key($id);
			if ($last_ai_process) {
				$data['data']['ai_process'] = $last_ai_process;
			}

			if($last_ai_process && $id == $last_ai_process['pack_id']){
				// Read AI preview content directly from files using the common function
				$session_id = $last_ai_process['session_id'] ?? null;
				$ai_page_ids = $last_ai_process['ai_page_ids'] ?? [];
				$dir_path = null;

				// Get session data to retrieve dir_path
				if ($session_id) {
					$session_data = Utils::get_session_data($session_id);
					$dir_path = $session_data['dir_path'] ?? null;
				}

				// Use the common function to read AI template data if we have the required data
				if ($session_id && $ai_page_ids && $dir_path) {
					$data['data']['ai_preview_content'] = AIUtils::read_ai_template_data($session_id, $ai_page_ids, $dir_path);
				} else {
					$data['data']['ai_preview_content'] = [];
				}
			}
		}

		// Return the response body
		wp_send_json($data);
	}

	public function update_imported_list($type, $id) {
		$imported_list = get_option('templately_fsi_imported_list', []);
		if(!in_array($id, $imported_list[$type] ?? [])){
			$imported_list[$type][] = $id;
			update_option('templately_fsi_imported_list', $imported_list, false);
		}
	}

	/**
	 *
	 *
	 * @return void
	 */
	protected function add_revert_hooks() {
		add_action('wp_insert_post', function ($post_id) {
			$this->update_imported_list('posts', $post_id);
		});
		add_action('add_attachment', function ($post_id) {
			$this->update_imported_list('attachment', $post_id);
		});
		add_action('created_term', function ($term_id, $tt_id, $taxonomy, $args) {
			$this->update_imported_list('term', [$term_id, $taxonomy]);
		}, 10, 4);
		add_action('registered_taxonomy', function ($taxonomy, $object_type, $taxonomy_object) {
			$this->update_imported_list('taxonomy', $taxonomy);
		}, 10, 3);
		add_action('fluentform/form_imported', function ($formId){
			$this->update_imported_list('fluentform', $formId);
		}, 10, 1);
	}

	public static function has_revert(){
		$options = Utils::get_backup_options();
		$imported_list = get_option('templately_fsi_imported_list', []);
		if(!empty($options) || !empty($imported_list)){
			return true;
		}
		return false;
	}

	public function import_revert() {

		// // Get the nonce value from the request (usually from $_POST or $_GET)
		// $received_nonce = isset($_REQUEST['_wpnonce']) ? $_REQUEST['_wpnonce'] : '';

		// // Verify the nonce using wp_verify_nonce()
		// $verified = wp_verify_nonce($received_nonce, 'templately_pack_import_revert_nonce');

		// if (!$verified) {
		// 	wp_send_json_error("Nonce not verified.");
		// }

		delete_option('templately_import_platform');

		$option_active         = null;
		$options_deleted       = false;
		$imported_list_deleted = false;
		$options               = Utils::get_backup_options();
		$status_args           = [ 'post_type' => 'templately_library' ];
		$all_post_url          = add_query_arg( [
			"page" => "templately_settings",
			"path" => "settings/elementor/miscellaneous",
		], admin_url('admin.php' ));
		// wp_send_json_success([$options]);

		if(class_exists('Elementor\Plugin')){
			$kits_manager  = Plugin::$instance->kits_manager;
			$option_active = $kits_manager::OPTION_ACTIVE;
			$kit           = $kits_manager->get_active_kit();

			if ( ! $kit->get_id() ) {
				$kit = $kits_manager->create_default();
				update_option( $kits_manager::OPTION_ACTIVE, $kit );
			}
		}


		if (!empty($options) && is_array($options)) {
			foreach ($options as $key => $value) {
				if ('stylesheet' === $key) {
					if (get_option('stylesheet') !== $value) {
						switch_theme($value);
					}
				} else if($option_active === $key && class_exists('Elementor\Plugin')) {
					$kits_manager->revert( (int) $kits_manager->get_active_id(), (int) $value, 0 );
					$kit      = $kits_manager->get_active_kit();
					$settings = $kit->get_data('settings');
					if ( isset( $settings['site_logo'] ) ) {
						set_theme_mod( 'custom_logo', $settings['site_logo']['id'] );
					}
				} else {
					update_option($key, $value);
				}
				delete_option("__templately_$key");
				$options_deleted = true;
			}
		}

		$imported_list = get_option('templately_fsi_imported_list', []);
		if (!empty($imported_list) && is_array($imported_list)) {
			$_GET['force_delete_kit'] = 1; // Fallback GET Ready!
			foreach ($imported_list as $type => $list) {
				if (empty($list) || !is_array($list)) {
					continue;
				}
				// Loop through each item ID and delete it
				foreach ($list as $key => $item_id) {
					switch ($type) {
						case 'posts':
							// making sure default kit don't get deleted.
							if($option_active && isset($options[$option_active]) && $options[$option_active] == $item_id){
								break;
							}
							wp_delete_post($item_id, true); // Set true for permanent deletion
							break;
						case 'attachment':
							wp_delete_attachment($item_id, true); // Set true for permanent deletion
							break;
						case 'term':
							list($term_id, $taxonomy) = $item_id;
							wp_delete_term($term_id, $taxonomy); // Use corresponding taxonomy
							break;
						case 'taxonomy':
							// Taxonomies cannot be directly deleted. Consider de-registering it.
							break;
						case 'fluentform':
							if(class_exists('\FluentForm\App\Models\Form')){
								\FluentForm\App\Models\Form::remove($item_id);
							}
							break;
					}
				}
			}

			$imported_list_deleted = true;
			delete_option('templately_fsi_imported_list');
		}


		if($options_deleted || $imported_list_deleted){
			sleep(5);
			wp_send_json_success([ 'options' => $options_deleted, 'imported_list' => $imported_list_deleted, 'site_url' => home_url(), 'redirect' => $all_post_url ]);
		}

		wp_send_json_error([ 'options' => $options_deleted, 'imported_list' => $imported_list_deleted, 'site_url' => home_url() ]);
	}

	/**
	 * Extract error response from JSON response body
	 * Returns the entire decoded JSON response if Content-Type is JSON,
	 * otherwise returns a generic HTTP error message
	 *
	 * @param array $response The response from wp_remote_get or similar
	 * @return mixed Decoded JSON response or generic HTTP error string
	 */
	private function extract_error_from_response($response) {
		try {
			$content_type = wp_remote_retrieve_header($response, 'content-type');
			if (!empty($content_type) && strpos($content_type, 'application/json') !== false) {
				$body = wp_remote_retrieve_body($response);
				$error_data = json_decode($body, true);
				if ($error_data !== null) {
					return $error_data;
				}
			}
		} catch (Exception $e) {
			// If JSON parsing fails, fall through to generic error
		}

		// Fallback to generic HTTP error message
		return __('API request failed with response code ', 'templately') . wp_remote_retrieve_response_code($response);
	}

	public function google_font() {
		$result = get_transient('templately-google-fonts');

		if (false == $result) {
			$response = Helper::make_api_get_request('v2/google-font', [], [], 30);

			if (is_wp_error($response)) {
				wp_send_json_error($response->get_error_message());
			}

			if (wp_remote_retrieve_response_code($response) != 200) {
				$error_message = $this->extract_error_from_response($response);
				wp_send_json_error($error_message, wp_remote_retrieve_response_code($response));
			}

			$body = wp_remote_retrieve_body($response);
			$data = json_decode($body, true);

			if (!isset($data['status']) || $data['status'] !== 'success') {
				wp_send_json_error('API response indicates failure.');
			}

			if (!isset($data['data'])) {
				wp_send_json_error('API response missing data.');
			}

			$result = $data['data'];
			set_transient('templately-google-fonts', $result, DAY_IN_SECONDS);
		}

		wp_send_json_success($result);
	}

	public function ai_get_json() {
		// read json data from post body
		$body = file_get_contents('php://input');
		$data = json_decode($body, true);

		if(empty($data['ai_page_ids'])){
			wp_send_json_error('Invalid ai_page_ids');
			return;
		}

		if(!isset($_GET['session_id'])){
			wp_send_json_error('Invalid session_id');
			return;
		}

		$session_id  = isset($_GET['session_id']) ? sanitize_text_field($_GET['session_id']) : null;
		$process_id  = $data['process_id'] ?? null;
		$ai_page_ids = $data['ai_page_ids'] ?? null;

		$this->request_params = $this->get_session_data();
		try {
			$this->manifest = $this->read_manifest($this->request_params['dir_path']);
		} catch (\Exception $th) {
			wp_send_json_error($th->getMessage());
		}

		if(!empty($session_id) && empty($process_id)){
			if ( !empty($this->request_params['process_id']) ){
				$process_id = $this->request_params['process_id'] ?? null;
			} else {
				$process_id = AIUtils::get_ai_process_id_by_session_id($session_id);
			}
		}

		if(empty($process_id)){
			wp_send_json_error('Invalid process_id');
			return;
		}

		$process_data = AIUtils::get_ai_process_data_by_process_id($process_id);
		if (!empty($process_data['preview_error'])) {
			wp_send_json_error($process_data['preview_error']);
		}

		// Use the new common function to read AI template data directly
		$result = AIUtils::read_ai_template_data($session_id, $ai_page_ids, $this->request_params['dir_path']);

		// Check if this is called from polling endpoint and include additional data
		$response_data = ['process_id' => $process_id, 'templates' => $result];

		if (isset($this->polling_is_last_part)) {
			$response_data['is_last_part'] = $this->polling_is_last_part;

			// Clean up the polling property
			unset($this->polling_is_last_part);
		}

		wp_send_json_success($response_data);
	}

	/**
	 * AJAX handler for polling AI template generation status on local sites
	 * Makes GET request to API endpoint and returns data in same format as ai_get_json()
	 */
	public function ai_poll_template() {
		// Read JSON data from post body
		$body = file_get_contents('php://input');
		$data = json_decode($body, true);

		$process_id = $data['process_id'] ?? null;
		$ai_page_ids = $data['ai_page_ids'] ?? null;

		if(empty($process_id)){
			wp_send_json_error('Invalid process_id');
			return;
		}

		// Validate and get AI process data using centralized method
		$process_data = AIUtils::validate_and_get_process_data($process_id);
		if (is_wp_error($process_data)) {
			$this->ai_get_json();
			return;
		}

		$session_id = $process_data['session_id'];
		$ai_page_ids = $process_data['ai_page_ids'];

		// Use the common polling function to handle all template processing
		$polling_result = AIUtils::poll_for_template($process_id, $session_id, $ai_page_ids);

		if (!$polling_result) {
			// Polling failed, fallback to ai_get_json
			$this->ai_get_json();
			return;
		}

		// After polling and processing templates, call ai_get_json() to return the data
		// This reuses all the existing logic without duplication
		$this->ai_get_json();
	}

	/**
	 * Process AI preview content following the ai_get_json() pattern
	 *
	 * @param string $process_id The AI process ID
	 * @param array $ai_page_ids The AI page IDs data structure
	 * @param array $ai_preview_ids The AI preview IDs to process
	 * @return array Processed AI content data
	 */
	private function process_ai_preview_content($process_id, $ai_page_ids, $ai_preview_ids) {
		if (empty($process_id) || empty($ai_page_ids) || empty($ai_preview_ids)) {
			return [];
		}

		$all_ai_process_data = AIUtils::get_ai_process_data();
		if (empty($all_ai_process_data[$process_id])) {
			return [];
		}
		$ai_process_data = $all_ai_process_data[$process_id];
		$_REQUEST['is_lightspeed'] = 'true';
		$_REQUEST['session_id'] = $ai_process_data['session_id'] ?? null;
		// Initialize session data and manifest following ai_get_json() pattern
		$this->request_params = $this->get_session_data();
		$this->manifest = $this->read_manifest($this->request_params['dir_path']);

		// Create Finalizer instance with the same configuration as ai_get_json()
		$finalizer = new Finalizer(array_merge($this->request_params, [
			'origin'   => $this,
			'manifest' => $this->manifest,
		]));
		$finalizer->process_id = $process_id;
		$finalizer->ai_page_ids = $ai_page_ids;

		$result = [];

		// Process each AI preview ID
		foreach ($ai_preview_ids as $preview_id) {
			// Extract type and sub_type metadata from ai_page_ids structure
			$type_info = $this->extract_content_metadata($preview_id, $ai_page_ids);

			if ($type_info) {
				$finalizer->type = $type_info['type'];
				$finalizer->sub_type = $type_info['sub_type'];

				// Check if this is AI content before processing
				if ($finalizer->isAiContent($preview_id)) {
					// Process AI content using AIContentHelper trait
					$ai_result = $finalizer->processAiContent($preview_id);
					if ($ai_result['is_ai'] && !empty($ai_result['template_json'])) {
						$template_json = $ai_result['template_json'];
						$result[$preview_id] = $template_json;
					} else if ($finalizer->isAiFileSkipped($preview_id)) {
						// Handle skipped AI files
						$result[$preview_id] = [];
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Extract content metadata (type and sub_type) from ai_page_ids structure
	 *
	 * @param string $preview_id The preview ID to find
	 * @param array $ai_page_ids The AI page IDs data structure
	 * @return array|null Array with 'type' and 'sub_type' keys, or null if not found
	 */
	private function extract_content_metadata($preview_id, $ai_page_ids) {
		if (empty($ai_page_ids) || !is_array($ai_page_ids)) {
			return null;
		}

		// Search through the ai_page_ids structure to find the preview_id
		foreach ($ai_page_ids as $key => $ids) {
			if (is_array($ids) && in_array($preview_id, $ids)) {
				// Extract type and sub_type from the key (e.g., 'content/page' or 'templates')
				$type_arr = explode('/', $key);
				return [
					'type' => $type_arr[0],
					'sub_type' => isset($type_arr[1]) ? $type_arr[1] : ''
				];
			}
		}

		return null;
	}

}
