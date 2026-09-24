<?php

namespace Templately\Modules\LibraryTour\REST;

use Templately\API\API;

class Tour extends API {

	private static $tour_meta_key = 'tour_status';

	/**
	 * Server-side allowlist of legal tour keys (spec 036 FR-017).
	 *
	 * `mark_complete()` and `reset()` reject any `tour_key` that is not present
	 * here with an HTTP 400 `invalid_tour_key` error, so arbitrary strings can
	 * never be written into (or targeted inside) the per-user `tour_status`
	 * option. This is the AUTHORITATIVE, server-side source of truth for which
	 * tours exist.
	 *
	 * KEEP IN SYNC with the frontend key constants in
	 * `modules/library-tour/assets/js/tourSteps.js`
	 * (`LIBRARY_TOUR_KEY` / `DETAIL_TOUR_KEY`) — the two runtimes cannot share a
	 * literal, so when a tour key is added, renamed, or its `_vN` suffix bumped,
	 * both lists must be updated together. Tests assert the exact membership of
	 * this list.
	 *
	 * @var string[]
	 */
	const ALLOWED_TOUR_KEYS = [
		'templately_library_tour_v1',
		'templately_detail_tour_v1',
	];

	public function register_routes() {
		$this->get( 'tour/status', [ $this, 'get_status' ] );
		$this->post( 'tour/complete', [ $this, 'mark_complete' ] );
		$this->post( 'tour/reset', [ $this, 'reset' ] );
	}

	/**
	 * Whether a tour key is present in the server-side allowlist.
	 *
	 * @param mixed $tour_key
	 * @return bool
	 */
	private function is_allowed_tour_key( $tour_key ): bool {
		return in_array( $tour_key, self::ALLOWED_TOUR_KEYS, true );
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

		if ( ! $this->is_allowed_tour_key( $tour_key ) ) {
			return $this->error( 'invalid_tour_key', __( 'Unrecognised tour_key.', 'templately' ), 'tour/complete', 400 );
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
	 * Optional `tour_key` parameter:
	 *   - Present: must be an allowlisted key (HTTP 400 `invalid_tour_key`
	 *     otherwise); deletes that entry (idempotent — a no-op success if the
	 *     key is valid but absent; other keys untouched).
	 *   - Omitted: resets ALL tours by emptying the status map. The Developer
	 *     Tools "reset tour" action depends on this reset-all form.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reset() {
		$tour_key = $this->get_param( 'tour_key', '' );

		if ( ! empty( $tour_key ) ) {
			if ( ! $this->is_allowed_tour_key( $tour_key ) ) {
				return $this->error( 'invalid_tour_key', __( 'Unrecognised tour_key.', 'templately' ), 'tour/reset', 400 );
			}

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
