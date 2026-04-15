<?php

namespace Templately\API;

class Tour extends API {

	private static $tour_meta_key = 'tour_status';

	public function register_routes() {
		$this->get( 'tour/status', [ $this, 'get_status' ] );
		$this->post( 'tour/complete', [ $this, 'mark_complete' ] );
		$this->post( 'tour/reset', [ $this, 'reset' ] );
	}

	/**
	 * Get the current tour completion status.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_status() {
		$status = $this->utils( 'options' )->get( self::$tour_meta_key, [] );

		if ( ! is_array( $status ) ) {
			$status = [];
		}

		return $this->success( $status );
	}

	/**
	 * Mark a tour as complete.
	 *
	 * Expects a `tour_key` parameter (e.g. "templately_library_tour_v1").
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function mark_complete() {
		$tour_key = $this->get_param( 'tour_key', '' );

		if ( empty( $tour_key ) ) {
			return $this->error( 'missing_tour_key', __( 'tour_key is required.', 'templately' ), 'tour/complete', 400 );
		}

		$status = $this->utils( 'options' )->get( self::$tour_meta_key, [] );

		if ( ! is_array( $status ) ) {
			$status = [];
		}

		$status[ $tour_key ] = 'done';

		$this->utils( 'options' )->set( self::$tour_meta_key, $status );

		return $this->success( $status );
	}

	/**
	 * Reset one or all tours.
	 *
	 * Optional `tour_key` parameter — if omitted, resets all tours.
	 *
	 * @return \WP_REST_Response
	 */
	public function reset() {
		$tour_key = $this->get_param( 'tour_key', '' );

		if ( ! empty( $tour_key ) ) {
			$status = $this->utils( 'options' )->get( self::$tour_meta_key, [] );

			if ( ! is_array( $status ) ) {
				$status = [];
			}

			unset( $status[ $tour_key ] );

			$this->utils( 'options' )->set( self::$tour_meta_key, $status );

			return $this->success( $status );
		}

		// Reset all
		$this->utils( 'options' )->set( self::$tour_meta_key, [] );

		return $this->success( [] );
	}
}
