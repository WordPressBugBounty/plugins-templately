<?php

namespace Templately\Modules\FullSiteImport\REST;

use Templately\API\API;
use Templately\Modules\FullSiteImport\Utils\AIUtils;
use Templately\Modules\FullSiteImport\Utils\PackInfoFetcher;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Utils\Helper;
use WP_Error;
use WP_REST_Request;

/**
 * REST replacement for the `wp_ajax_templately_pack_import_info` action
 * (FR-018, specs/025-full-site-import/spec.md — the spec's ajax→REST map targets
 * `GET /templately/v1/import/info`). Fetches a pack's import metadata (manifest,
 * customizer settings, credit balance, business niches, and — for AI packs — the
 * latest AI process + any already-generated preview content) from the Templately API.
 *
 * VERB — GET (not POST like the sibling `GlobalSettings`): `import_info()` is a pure
 * READ. It performs zero session/option writes — every call it makes is read-only
 * (`get_option('templately_ai_business_niches')`, `AIUtils::get_latest_ai_process_by_api_key()`,
 * `SessionData::get_data()`, `AIUtils::read_ai_template_data()`), so GET is the
 * REST-correct verb, it matches the spec's own mapping table, and it matches every
 * existing frontend caller (all of which already GET). None of the fetched data feeds
 * the later SSE import as state written here — the SSE session is created separately by
 * `import_settings`/`create_session_and_download`, which this route does not touch.
 *
 * The old ajax action (`Ajax\PackInfoController::import_info()`) is kept running as a
 * deprecated shim (see module.php) — browsers with an already-cached old JS bundle would
 * otherwise break. The upstream fetch is shared with `GlobalSettings` + the ajax shims
 * via `Utils\PackInfoFetcher`.
 */
class PackInfo extends API {

	/**
	 * The ajax path required `install_plugins` AND `install_themes` (the capability pair
	 * `FullSiteImport::add_ajax_action()` enforces for every `wp_ajax_templately_pack_*`
	 * action) plus a valid `templately_nonce`. REST requests carry their own nonce
	 * (`X-WP-Nonce`, verified by WP core before permission_callback runs), so the
	 * equivalent protection here is: the same capability pair, ON TOP OF the base API
	 * class's `permission_check()` (delete_posts + a connected/verified `api_key`) — net
	 * STRICTER than the ajax path, never weaker. Identical gate to the sibling
	 * `GlobalSettings`.
	 */
	public function permission_check( WP_REST_Request $request ) {
		$this->request = $request;

		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'install_themes' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to fetch pack import info.', 'templately' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return parent::permission_check( $request );
	}

	public function register_routes() {
		$this->get( 'import/info', [ $this, 'import_info' ], [
			'id'   => [
				'required' => true,
			],
			'isAi' => [
				'required' => false,
			],
		] );
	}

	public function import_info() {
		$id   = $this->get_param( 'id', 0, 'absint' );
		$isAi = Helper::sanitize( $this->get_param( 'isAi', false, null ), 'boolean' );

		if ( empty( $id ) ) {
			return $this->error( 'invalid_id', __( 'A valid pack id is required.', 'templately' ), 'import/info', 400 );
		}

		$data = ( new PackInfoFetcher() )->fetch( $id, $isAi );
		if ( is_wp_error( $data ) ) {
			return $this->error( 'api_error', $data->get_error_message(), 'import/info', 500 );
		}

		// Post-fetch enrichment — identical to Ajax\PackInfoController::import_info().
		$business_niches                 = get_option( 'templately_ai_business_niches', [] );
		$data['data']['business_niches'] = $business_niches;

		if ( isset( $data['data']['manifest'] ) ) {
			$data['data']['manifest'] = json_decode( $data['data']['manifest'], true );
		}
		if ( isset( $data['data']['settings'] ) ) {
			$data['data']['settings'] = json_decode( $data['data']['settings'], true );
		}

		if ( $isAi ) {
			// Get the latest AI process for the current API key.
			$last_ai_process = AIUtils::get_latest_ai_process_by_api_key( $id );
			if ( $last_ai_process ) {
				$data['data']['ai_process'] = $last_ai_process;
			}

			if ( $last_ai_process && $id == ( $last_ai_process['pack_id'] ?? null ) ) {
				// Read AI preview content directly from files using the common function.
				$session_id  = $last_ai_process['session_id'] ?? null;
				$ai_page_ids = $last_ai_process['ai_page_ids'] ?? [];
				$dir_path    = null;

				// Get session data to retrieve dir_path.
				if ( $session_id ) {
					$session_data = SessionData::get_data( $session_id );
					$dir_path     = $session_data['dir_path'] ?? null;
				}

				// Use the common function to read AI template data if we have the required data.
				if ( $session_id && $ai_page_ids && $dir_path ) {
					$data['data']['ai_preview_content'] = AIUtils::read_ai_template_data( $session_id, $ai_page_ids, $dir_path );
				} else {
					$data['data']['ai_preview_content'] = [];
				}
			}
		}

		// The ajax handler returned the payload raw via `wp_send_json($data)` (no
		// success wrapper) — `success($data)` emits the same body verbatim (200 + $data).
		return $this->success( $data );
	}
}
