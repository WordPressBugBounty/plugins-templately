<?php

namespace Templately\Modules\Settings\REST;

use Templately\Admin\API\BaseAPI;
use Templately\Modules\Settings\Preferences as PreferencesStore;
use WP_REST_Request;

/**
 * Per-user preference endpoint — separate route from settings/update on purpose:
 * that handler unconditionally writes site options (an empty `siteTitle` blanks
 * `blogname`), so piggybacking a personal pref on it would force callers to
 * resend the whole site-settings payload. It also requires `manage_options`,
 * while a personal UI pref belongs to anyone who can use the editor.
 */
class Preferences extends BaseAPI {
	private $endpoint = 'settings/preferences';

	public function permission_check( WP_REST_Request $request ) {
		$this->request = $request;

		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	public function register_routes() {
		$this->post( $this->endpoint, [ $this, 'update_preferences' ] );
	}

	public function update_preferences() {
		$preferences = $this->request->get_param( 'preferences' );

		if ( ! is_array( $preferences ) ) {
			return $this->error(
				'invalid_preferences',
				__( 'No preferences provided.', 'templately' ),
				'settings/preferences',
				400
			);
		}

		return $this->success( [
			'applied' => PreferencesStore::update( $preferences ),
		] );
	}
}
