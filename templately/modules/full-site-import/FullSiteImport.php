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


namespace Templately\Modules\FullSiteImport;

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
use Templately\Utils\Base;
use Templately\Utils\Response\AjaxResponder;
use Templately\Utils\Helper;
use Templately\Utils\Installer;
use Templately\Utils\Options;

class FullSiteImport extends Base {
	use Concerns\LogHelper;
	use Concerns\ThrowsErrors;
	use Concerns\HandlesSession;
	use Concerns\DownloadsPack;
	use Concerns\RunsImport;
	use Concerns\ReportsStatus;
	use Concerns\NormalizesResult;
	use Concerns\CleansUp;
	use Concerns\TracksRevert;
	use Concerns\HandlesAiEndpoints;

	const SESSION_OPTION_KEY = 'templately_import_session';

	/**
	 * Extensions a pack carries that WordPress does not accept as an upload.
	 *
	 * Everything else comes from get_allowed_mime_types(), so this list only has
	 * to name what the pack format adds on top of it:
	 *
	 * - `json` — every pack is built from JSON (manifest, templates, content),
	 *   and WordPress has never allowed .json uploads.
	 * - `xml`  — the WXR files under wp-content/. Also not an allowed upload:
	 *   core hands .xml to the importer plugin rather than the media library.
	 * - `ai`   — the marker segment in `{id}.ai.json`, an AI-generated page. Not
	 *   the Illustrator format.
	 * - `svg`  — packs ship SVG artwork. This plugin already allows SVG uploads
	 *   for the duration of an import (see allow_svg_upload()), but that filter is
	 *   only added in start_content_import(), which runs AFTER extraction — so at
	 *   sweep time get_allowed_mime_types() does not have it and the pack's SVGs
	 *   would be deleted. It has to be named here.
	 * - fonts  — a pack may ship webfonts, and none of these are upload types.
	 *   Passive assets; nothing here is executable by any server.
	 *
	 * Adding to this list is how a new pack file type gets through. It fails
	 * closed and logs, so a missing entry shows up as a dropped file in the
	 * import log — not as a silent hole.
	 *
	 * Lives on the class rather than the DownloadsPack trait that reads it:
	 * trait constants are PHP 8.2+, and the advertised floor here is 7.2.
	 */
	const PACK_EXTENSIONS = [
		'json', 'xml', 'ai',
		'svg', 'svgz',
		'woff', 'woff2', 'ttf', 'otf', 'eot',
	];
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

		// Independent endpoint handlers (registered through the same nonce/cap wrapper).
		// `import_revert` moved to modules/import-revert (2026-07-23) — it arrives
		// through the templately_fsi_ajax_handlers seam below, like the feedback shims.
		$asset     = new Ajax\AssetController();
		$pack_info = new Ajax\PackInfoController();

		$this->add_ajax_action('import_settings', $this);
		$this->add_ajax_action('create_session_and_download', $this);
		$this->add_ajax_action('import_status', $this);
		$this->add_ajax_action('import', $this);
		$this->add_ajax_action('import_info', $pack_info);
		$this->add_ajax_action('import_global_settings', $pack_info);
		$this->add_ajax_action('google_font', $asset);
		$this->add_ajax_action('ai_get_json', $this);
		$this->add_ajax_action('ai_poll_template', $this);
		$this->add_ajax_action('merge_settings_with_template', $asset);

		// Cross-module AJAX handlers arrive through this seam instead of direct class
		// imports (e.g. post-import-feedback supplies its two deprecated shims — see
		// that module.php). Applied on 'init' rather than here because FSI boots before
		// the supplying modules (alphabetical discovery order); admin-ajax.php fires
		// wp_ajax_* well after 'init', so registration is never late.
		add_action('init', function () {
			$handlers = apply_filters('templately_fsi_ajax_handlers', []);
			foreach ($handlers as $action => $handler) {
				$this->add_ajax_action($action, $handler);
			}
		});

		add_action('admin_init', [$this, 'admin_init']);

		// Cleanup scheduling moved to `modules/utilities/` (spec 052). This module
		// contributes tasks to the one shared sweep instead of running its own
		// daily event, which pruned options only and never touched the disk.

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
			// The nonce + capability gate lives in AjaxResponder::guard() so that it
			// answers with the same envelope (and real machine codes) as everything
			// else. It sends and dies on failure. Spec 043 / PRD PHP-5.
			if (!AjaxResponder::guard('templately_nonce', ['install_plugins', 'install_themes'])) {
				return;
			}

			// Every AI/FSI step arrives through here, so this is the one place that can say
			// WHICH step a run reached and how the request ended. Without it a run that
			// stopped between two steps was indistinguishable from one that never started
			// the next.
			//
			// The end is reported from a shutdown handler and NOT gated on "did the handler
			// return", because these handlers answer through AjaxResponder, which `die()`s —
			// so the normal, successful path never comes back here. A flag set after
			// `call_user_func()` would therefore mark every success as a failure. The
			// shutdown handler states the facts instead and lets the reader judge:
			// `connection_aborted=1` means the browser hung up (ignore_user_abort is 0, so
			// PHP stops at the next write, silently), and a non-null `last_error` means PHP
			// died.
			$started = microtime( true );
			Helper::log( sprintf( 'ajax: templately_pack_%s — start', $action ), 'fsi_ajax', 'info' );

			register_shutdown_function( function () use ( $action, $started ) {
				Helper::log(
					sprintf(
						'ajax: templately_pack_%s — ended after %.1fs (connection_aborted=%d, http_code=%s, last_error=%s)',
						$action,
						microtime( true ) - $started,
						connection_aborted(),
						function_exists( 'http_response_code' ) ? (string) http_response_code() : '?',
						wp_json_encode( error_get_last() )
					),
					'fsi_ajax',
					connection_aborted() || null !== error_get_last() ? 'warning' : 'info'
				);
			} );

			// Call the actual handler method on the registered object (defaults to $this).
			call_user_func([$object, $action]);
		});
	}























	private function skipped_plugin(): bool {
		return empty($this->request_params['plugins']) || !is_array($this->request_params['plugins']);
	}








	public function get_request_params() {
		return $this->request_params;
	}

	/**
	 * Kept on FullSiteImport for backward compatibility (called as FullSiteImport::has_revert()).
	 * The revert surface moved to modules/import-revert (2026-07-23), which answers
	 * the `templately_has_revert` filter — no class reference in this direction.
	 */
	public static function has_revert() {
		return (bool) apply_filters( 'templately_has_revert', false );
	}



	public function allow_svg_upload($mimes) {
		// Allow SVG
		$mimes['svg'] = 'image/svg+xml';
		return $mimes;
	}



















}
