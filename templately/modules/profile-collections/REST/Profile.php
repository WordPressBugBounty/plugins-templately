<?php

namespace Templately\Modules\ProfileCollections\REST;

use Templately\API\API;

class Profile extends API {

	public function register_routes() {
		$this->get( 'profile/download-history', [ $this, 'get_download_history' ] );
		$this->get( 'profile/my-favourites', [ $this, 'get_my_favourites' ] );
		$this->get( 'profile/purchased-items', [ $this, 'get_my_purchased_items' ] );
	}

	public function get_download_history() {
		$page     = $this->get_param( 'page', 1, 'intval' );
		$per_page = $this->get_param( 'per_page', 10, 'intval' );

		$response = $this->http()->mutation( 'myDownloadHistory', 'data{ name, thumbnail, downloaded_at, slug, type }, current_page, total_page, total', [
				'api_key'  => $this->api_key,
				'per_page' => $per_page,
				"page"     => $page
			] )->post();

		if ( is_wp_error( $response ) ) {
			return $this->error( 'invalid_download_history_response', __( $response->get_error_message(), 'templately' ), 'profile/download-history' );
		}

		if ( isset( $response['total'] ) ) {
			$this->utils( 'options' )->set( 'total_download_counts', $response['total'] );
		}

		return $response;
	}

	public function get_my_favourites() {
		$type      = $this->get_param( 'itemType', 'all' );
		$plan      = $this->get_param( 'plan', 'all' );
		$plan_type = $this->get_plan( $plan );
		$page      = $this->get_param( 'page', 1, 'intval' );
		$per_page  = $this->get_param( 'per_page', 20, 'intval' );

		$funcArgs = [
			'api_key'   => $this->api_key,
			"page"      => $page,
			"per_page"  => $per_page,
			"plan_type" => $plan_type
		];

		if ( $type != 'all' ) {
			$funcArgs['type'] = $type;
		}

		$response = $this->http()->mutation( 'myFavouriteItem', 'total_page, current_page, data { id, name, rating, type, slug, favourite_count, thumbnail, thumbnail2, thumbnail3, price, author{ display_name, name, joined }, dependencies{ id, name, icon, plugin_file, plugin_original_slug, is_pro, link } }', $funcArgs )->post();

		if ( is_wp_error( $response ) ) {
			return $this->error( 'invalid_my_favourites_response', __( $response->get_error_message(), 'templately' ), 'profile/my-favourites' );
		}

		return $response;
	}

	/**
	 * The buyer's purchased items.
	 *
	 * `platform` is OMITTED unless a caller explicitly asks for one. The cloud's
	 * `myItems` resolver applies the platform filter only `when(! empty($args['platform']))`,
	 * so sending nothing means "every platform" — which is what both callers want and
	 * neither ever asked for: the paged My Items table sends no platform, and the
	 * owned-item INDEX (`all=true`, the thing that decides whether a pack shows
	 * "Purchase This Pack" or "Build Full Website") sends none either.
	 *
	 * This defaulted to `'elementor'`, so the index only ever contained Elementor
	 * purchases. A Gutenberg pack the user had just bought stayed unowned forever — the
	 * detail page kept offering to sell it back to them, through reloads and re-syncs,
	 * because the answer it cached had the purchase filtered out server-side.
	 */
	public function get_my_purchased_items() {
		$page     = $this->get_param( 'page', 1, 'intval' );
		$per_page = $this->get_param( 'per_page', 10, 'intval' );
		$_all     = $this->get_param( 'all', false );
		$platform = $this->get_param( 'platform', '' );

		$funcArgs = [
			'api_key'  => $this->api_key,
			'page'     => $page,
			'per_page' => $per_page
		];

		if ( ! empty( $platform ) ) {
			$funcArgs['platform'] = $platform;
		}

		$query = 'total_page, current_page, data { id, item_id, name, slug, type, platform, thumbnail, purchased_at }';

		if ( $_all ) {
			$query                = 'total_page, current_page, data { id, item_id, type, platform }';
			$funcArgs['per_page'] = -1;
		}

		$response = $this->http()->query( 'myItems', $query, $funcArgs )->post();

		if ( $response ) {
			// $user = $this->utils('options')->get('user');

			// $user['is_verified'] = true;
			// $this->utils('options')->set('user', $user);
		}

		return $response;
	}
}