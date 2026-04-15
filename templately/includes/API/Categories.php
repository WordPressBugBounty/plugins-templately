<?php
namespace Templately\API;

use Templately\Utils\Database;
use WP_REST_Request;

class Categories extends API {
    private $endpoint = 'categories';

	public function permission_check( WP_REST_Request $request ) {
		$this->request = $request;
		return true;
	}

	public function register_routes() {
		$this->get( $this->endpoint, [ $this, 'get_categories' ] );
	}

	public function get_categories() {
		/** @noinspection SpellCheckingInspection */
        $id       = $this->get_param( 'id', 0, 'intval' );
        $funcArgs = [];

		if ( $id > 0 ) {
			$funcArgs['id'] = $id;
		}

		$fields           = 'id, name, slug, status, packs_count';
		$transient_key    = $this->endpoint . '_' . md5( $fields . json_encode( $funcArgs ) );

        /**
         * Return if there is any cache data.
         */
        $types = Database::get_transient( $transient_key );
		if( ! empty( $types ) ){
			return $this->success( $types );
		}

		$response = $this->http()->query( $this->endpoint, $fields, $funcArgs )->post();
        /**
         * Caching the response in transient.
         */
		if( ! is_wp_error( $response ) ) {
			Database::set_transient( $transient_key, $response );
		}

        return $response;
	}
}