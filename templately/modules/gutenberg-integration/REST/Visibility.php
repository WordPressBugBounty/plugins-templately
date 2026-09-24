<?php

namespace Templately\Modules\GutenbergIntegration\REST;

use Templately\API\API;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/**
 * REST replacement for the `wp_ajax_update_gutenberg_hide_buttons` admin-ajax action
 * (FR-003 / FR-020, specs/033-gutenberg-integration/spec.md). Toggles the visibility of
 * the "Templately" / "Save in Templately" buttons in the Gutenberg editor toolbar and
 * persists the preference to the SITE-WIDE WordPress option
 * `templately-gutenberg-hide-buttons` (via `update_option()`/`get_option()` — NOT
 * per-user meta; the AJAX handler stored it site-wide and this route preserves that).
 *
 * The old ajax action (`Gutenberg::update_gutenberg_hide_buttons()`) is kept running as
 * a deprecated shim (see `Platform/Gutenberg.php::hooks()`) — the caller lives in the
 * browser-cached editor bundle (`assets/js/gutenberg.js`, enqueued on
 * `enqueue_block_editor_assets`); an already-open editor tab, or a soft reload served
 * stale JS from the HTTP cache, would still POST to admin-ajax with the old action name
 * after this route ships, so removing the handler would break the toggle for those tabs.
 *
 * Transport: the route is registered for `EDITABLE` methods (PUT/PATCH/POST) so an
 * external/API consumer can `PUT` per the constitution III target
 * (`PUT /templately/v1/gutenberg/visibility`, body `{ visibility: 'visible' | 'hidden' }`).
 * The plugin's own frontend calls it via POST because the shared `templatelyApi` wrapper
 * only attaches a JSON request body on POST (and query args on GET); EDITABLE accepts
 * both, so a single route serves both callers.
 */
class Visibility extends API {

	/**
	 * The ajax handler's authorization gate was `check_ajax_referer('templately_nonce',
	 * 'nonce')` + `current_user_can('edit_posts')`. The REST equivalent is at least as
	 * strict on every axis, never weaker:
	 *
	 * - Nonce: WP core verifies the `X-WP-Nonce` (wp_rest) header before any
	 *   permission_callback runs — the CSRF protection the ajax path got from
	 *   `templately_nonce`.
	 * - Capability: the base `API::_permission_check()` ALREADY requires
	 *   `current_user_can('delete_posts')` before this method is reached; this override
	 *   adds the ajax handler's own `edit_posts` check on top. Net gate:
	 *   `delete_posts && edit_posts`. For every standard WP role `edit_posts` implies
	 *   `delete_posts`, so the allow-set equals the ajax path's; for an exotic custom role
	 *   with `edit_posts` but not `delete_posts`, REST is STRICTER (denies where ajax
	 *   allowed) — the desired direction.
	 * - Connected account: this override deliberately does NOT call
	 *   `parent::permission_check()` (which would require a non-empty `api_key`). Showing
	 *   or hiding the LOCAL editor toolbar is a per-site editor preference, not a cloud
	 *   operation; the ajax handler never required a connected Templately account, and
	 *   requiring one here would regress the toggle for users editing before they connect.
	 */
	public function permission_check( WP_REST_Request $request ) {
		$this->request = $request;

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to change the Templately toolbar visibility.', 'templately' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	public function register_routes() {
		$this->register_endpoint(
			'gutenberg/visibility',
			[ $this, 'update_visibility' ],
			[
				'visibility' => [
					'required'    => true,
					'type'        => 'string',
					'description' => __( 'Desired visibility of the Templately editor toolbar buttons: "visible" or "hidden".', 'templately' ),
				],
			],
			WP_REST_Server::EDITABLE
		);
	}

	public function update_visibility() {
		$visibility = $this->get_param( 'visibility', '', 'sanitize_key' );

		if ( ! in_array( $visibility, [ 'visible', 'hidden' ], true ) ) {
			return $this->error(
				'invalid_visibility',
				__( 'The visibility value must be either "visible" or "hidden".', 'templately' ),
				'gutenberg/visibility',
				400
			);
		}

		// Same site-wide option key the ajax handler wrote to. 'hidden' => 'yes'.
		$hide_buttons = 'hidden' === $visibility ? 'yes' : 'no';
		update_option( 'templately-gutenberg-hide-buttons', $hide_buttons );

		// Re-read and normalize exactly as the ajax handler did (any non-'yes' => 'no').
		$stored       = get_option( 'templately-gutenberg-hide-buttons', 'no' );
		$hide_buttons = 'yes' === $stored ? 'yes' : 'no';

		return $this->success( [
			'visibility'   => 'yes' === $hide_buttons ? 'hidden' : 'visible',
			'hide_buttons' => $hide_buttons,
		] );
	}
}
