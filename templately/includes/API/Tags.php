<?php

namespace Templately\API;

use Templately\Utils\Database;
use WP_REST_Request;

class Tags extends API {
	private $endpoint = 'tags';

	public function permission_check( WP_REST_Request $request ) {
		$this->request = $request;
		return true;
	}

	public function register_routes() {
		$this->get($this->endpoint, [$this, 'tags']);
		$this->get('popular-tags', [$this, 'popular_tags']);
	}

	public function tags() {
		$tags = Database::get_transient( $this->endpoint );

		if( $tags ){
			return $this->success( $tags );
		}

		$response = $this->http()->query( 'tags', 'id, name' )->post();

		if( ! is_wp_error( $response ) ) {
			$_tags = [];
			if( ! empty( $response ) ) {
				foreach( $response as $tag ) {
					$_tags[] = [
						'label' => $tag['name'],
						'value' => $tag['id'],
					];
				}
			}

			Database::set_transient( $this->endpoint, $_tags );
			return $_tags;
		}

		return $response;
	}

	public function popular_tags() {
		$popular_tags = Database::get_transient( 'popular-tags' );

		if( $popular_tags ){
			return $this->success( $popular_tags );
		}

		$response = $this->http()->query( 'popularTags', 'id, name, slug' )->post();

		if( ! is_wp_error( $response ) ) {
			Database::set_transient( 'popular-tags', $response );
			return $response;
		}

		return $response;
	}
}