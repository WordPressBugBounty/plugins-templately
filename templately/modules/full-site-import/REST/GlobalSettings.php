<?php

namespace Templately\Modules\FullSiteImport\REST;

use Templately\API\API;
use Templately\Modules\FullSiteImport\Utils\PackInfoFetcher;
use Templately\Utils\Helper;
use WP_Error;
use WP_REST_Request;

/**
 * REST replacement for the `wp_ajax_templately_pack_import_global_settings` action
 * (FR-018, specs/025-full-site-import/spec.md). Fetches a pack's manifest-declared
 * color/typography/customizer settings WITHOUT importing any posts, attachments,
 * templates, or terms — used by the settings-only import mode and by single-import's
 * "merge pack settings into the inserted template" flow.
 *
 * The old ajax action (`Ajax\PackInfoController::import_global_settings()`) is kept
 * running as a deprecated shim (see module.php) — browsers with an already-cached old
 * JS bundle would otherwise break on the next request after this route ships.
 */
class GlobalSettings extends API {

	/**
	 * The ajax path required `install_plugins` AND `install_themes` (the same
	 * capability pair `FullSiteImport::add_ajax_action()` enforces for every
	 * `wp_ajax_templately_pack_*` action) plus a valid `templately_nonce`. REST
	 * requests carry their own nonce (`X-WP-Nonce`, verified by WP core before
	 * permission_callback even runs), so the equivalent protection here is: the
	 * same capability pair, ON TOP OF the base API class's `permission_check()`
	 * (delete_posts + a connected/verified account `api_key`) — net STRICTER than
	 * the ajax path, never weaker.
	 */
	public function permission_check( WP_REST_Request $request ) {
		$this->request = $request;

		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'install_themes' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to import global settings.', 'templately' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return parent::permission_check( $request );
	}

	public function register_routes() {
		$this->post( 'import/global-settings', [ $this, 'import_global_settings' ], [
			'id'   => [
				'required' => true,
			],
			'isAi' => [
				'required' => false,
			],
		] );
	}

	public function import_global_settings() {
		$id   = $this->get_param( 'id', 0, 'absint' );
		$isAi = Helper::sanitize( $this->get_param( 'isAi', false, null ), 'boolean' );

		if ( empty( $id ) ) {
			return $this->error( 'invalid_id', __( 'A valid pack id is required.', 'templately' ), 'import/global-settings', 400 );
		}

		$data = ( new PackInfoFetcher() )->fetch( $id, $isAi );
		if ( is_wp_error( $data ) ) {
			return $this->error( 'api_error', $data->get_error_message(), 'import/global-settings', 500 );
		}

		$settings = [];
		if ( isset( $data['data']['settings'] ) ) {
			$settings = json_decode( $data['data']['settings'], true );
		}

		return $this->success( [ 'settings' => $settings ] );
	}
}
