<?php

namespace Templately\Modules\FullSiteImport\Concerns;

use Elementor\Plugin;
use Error;
use Exception;
use Templately\Modules\FullSiteImport\Exception\FatalErrorException;
use Templately\Modules\FullSiteImport\Exception\RetryableErrorException;
use Templately\Modules\FullSiteImport\Exception\UnknownErrorException;
use Templately\Modules\FullSiteImport\Runners\Finalizer;
use Templately\Modules\FullSiteImport\Utils\LogHandler;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Modules\FullSiteImport\Utils\AIUtils;
use Templately\Utils\Helper;
use Templately\Utils\Options;
use Templately\Utils\Response\AjaxResponder;
use Templately\Utils\Response\ErrorCode;

/**
 * HandlesSession — extracted verbatim from FullSiteImport (behavior-preserving).
 * Composed back into FullSiteImport via `use`; $this and method resolution unchanged.
 */
trait HandlesSession {
	/**
	 * Shared session-creation core (037 US3 / FR-006).
	 *
	 * The dir-path setup (`root_dir`/`prv_dir`/`dir_path`/`zip_path`), the
	 * per-field JSON-decode pass, and `SessionData::save()` are identical between
	 * the plain-FSI (`import_settings`) and AI-FSI (`import_ai_settings`) entry
	 * points — they were hand-synced duplicates. This helper owns that common
	 * core; each caller keeps only its genuinely path-specific steps (plain:
	 * session-id resolution + revert/log clear + `wp_send_json`; AI: `isLocalSite`
	 * coercion + download front-load). Behavior is byte-identical to the inline
	 * versions (FR-007): the dir-path keys are independent of the AI-only
	 * `isLocalSite` key, so the order relative to that coercion is immaterial.
	 *
	 * @param string $session_id The (already-resolved, sanitized) session id.
	 * @param array  $data       The request/session data to persist.
	 * @return array The prepared data (dir paths set, string fields JSON-decoded).
	 */
	private function prepare_session($session_id, $data) {

		$tmp_dir = Helper::upload_dir('tmp');
		$prv_dir = Helper::upload_dir('preview');

		$this->session_id = $session_id;

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

		SessionData::save($session_id, $data);

		return $data;
	}

	public function import_settings() {
		$user = Options::get_instance()->get('user');
		if (!empty($user['is_disconnected'])) {
			AjaxResponder::error(ErrorCode::SITE_DISCONNECTED, __('Your site connection is disconnected. Please migrate your connection first.', 'templately'));
			return;
			return;
		}

		$data = wp_unslash($_POST);

		$is_resume = false;

		if(!empty($data['session_id'])){
			$session_id = $data['session_id'];
			// Security: Sanitize session_id from user input
			$session_id = AIUtils::sanitize_path_component($data['session_id'], 'session_id');
			if (is_wp_error($session_id)) {
				AjaxResponder::error(ErrorCode::INVALID_REQUEST, $session_id->get_error_message());
				return;
				return;
			}
			if (SessionData::is_completed($session_id)) {
				// Replay guard (spec 042 FR-032): never resume a session whose
				// import already succeeded — its progress markers would make every
				// runner skip and instantly replay the OLD results. Mint a fresh
				// session; the client's own id was stale.
				Helper::log("Refused resume of completed session {$session_id}; minting a fresh one", 'fsi-session', 'warning');
				$session_id = wp_generate_uuid4();
			} else {
				$session_data = SessionData::get_data($session_id);
				// Re-attaching to an in-flight session (retry/resume) — its
				// half-import's revert backups must survive.
				$is_resume = !empty($session_data);
				$data = array_merge($session_data, $data);
			}
		}
		else {
			// Not uniqid(): that is a formatted microtime, so the path it keys in
			// web-served wp-uploads is enumerable by anyone who knows roughly when
			// an import ran. The chatbot flow already sends a client-side uuid4
			// here, so this only brings the fallback in line with it.
			$session_id  = wp_generate_uuid4();
		}

		$data['session_id'] = $session_id;

		// 037 US3: dir-path setup + JSON-decode + save via the shared helper.
		$data = $this->prepare_session($session_id, $data);


		// Clear the previous import's revert backup — ONLY when launching a
		// fresh import. Clearing on a resume/retry wiped the very backups the
		// in-flight import had just written, corrupting "Revert to old website"
		// for exactly the runs most likely to need it.
		if (!$is_resume) {
			$options = Utils::get_backup_options();
			foreach ($options as $key => $value) {
				delete_option("__templately_$key");
			}
			delete_option('templately_fsi_imported_list');
			delete_option('templately_fsi_log');
		}

		AjaxResponder::success([
			'is_lightspeed' => !Helper::should_flush(),
			'session_id'    => $session_id,
		]);
	}

	public function import_ai_settings() {
		$data = wp_unslash($_POST);

		// Security: Sanitize session_id from user input
		$session_id = AIUtils::sanitize_path_component($data['session_id'], 'session_id');
		if (is_wp_error($session_id)) {
			AjaxResponder::error(ErrorCode::INVALID_REQUEST, $session_id->get_error_message());
				return;
			return;
		}

		// Path-specific: coerce the AI-only isLocalSite flag BEFORE the shared
		// JSON-decode pass (the dir-path keys the helper sets are independent of
		// this key, so ordering is preserved).
		if (isset($data['isLocalSite'])) {
			$data['isLocalSite'] = filter_var($data['isLocalSite'], FILTER_VALIDATE_BOOLEAN);
		}

		// 037 US3: dir-path setup + JSON-decode + save via the shared helper.
		$data = $this->prepare_session($session_id, $data);

		return $data;
	}

	public function create_session_and_download() {
		if ( ! $this->dev_mode && ! wp_doing_ajax() ) {
			exit;
		}

		Utils::add_gd_editor_filter();

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

				SessionData::mark_step_complete($this->session_id, 'create_log_dir');
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

				SessionData::mark_step_complete($this->session_id, 'download_zip');
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
			AjaxResponder::success([
				'session_id' => $this->session_id,
				'pack_downloaded' => true,
				'platform' => $platform,
				'message' => __('Session created and pack downloaded successfully', 'templately')
			]);

		} catch ( Exception $e ) {
			// `retryable` is a first-class field of the envelope now, so the client
			// reads it from the same place for every failure instead of from this
			// endpoint's bespoke `should_retry` key.
			$code = $e instanceof RetryableErrorException ? ErrorCode::TIMEOUT : ErrorCode::SERVER_ERROR;

			AjaxResponder::error($code, $e->getMessage());
		}
	}

    // Modified get_session_data to use SessionData
    public function get_session_data() {
        if ($session_id = SessionData::get_session_id()) {
            return SessionData::get_data($session_id);
        }
        return [];
    }

	// === DEPRECATED / UNUSED (kept intentionally) ===
    // Modified update_session_data to use SessionData
    public function update_session_data($data) {
        if ($session_id = SessionData::get_session_id()) {
            return SessionData::save($session_id, array_merge($this->get_session_data(), $data));
        }
        return false;
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

	// === DEPRECATED / UNUSED (kept intentionally) ===
	public function clear_session_data(): bool {
		return delete_site_option(self::SESSION_OPTION_KEY);
	}

}
