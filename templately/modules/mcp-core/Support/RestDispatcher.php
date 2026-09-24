<?php
/**
 * Dispatches to Templately's existing internal REST routes in-process
 * (no HTTP wire traffic) so abilities can reuse `Templately\API\*` logic
 * without duplicating it (research.md §2). Fresh implementation — FR-011
 * forbids depending on the existing dev-only modules/developer/mcp-tools/ registry.
 *
 * @package Templately\Modules\McpCore\Support
 */

namespace Templately\Modules\McpCore\Support;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class RestDispatcher {

	/**
	 * @param string $method GET|POST|etc.
	 * @param string $route  Route path, e.g. '/templately/v1/items'.
	 * @param array  $params Request params.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function dispatch( string $method, string $route, array $params = [] ) {
		$normalized_route = strpos( $route, '/' ) === 0 ? $route : '/' . ltrim( $route, '/' );

		$request = new WP_REST_Request( strtoupper( $method ), $normalized_route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		$response = rest_do_request( $request );

		if ( is_wp_error( $response ) ) {
			// Only truly fundamental dispatch failures (e.g. no matching route)
			// come back this way — application-level errors returned by a route
			// callback are converted by WP_REST_Server into an error-shaped
			// WP_REST_Response instead (handled below).
			return $response;
		}

		if ( ! $response instanceof WP_REST_Response ) {
			return new WP_Error( 'invalid_rest_response', __( 'Unexpected internal REST response.', 'templately' ) );
		}

		$data = $response->get_data();

		if ( $response->get_status() >= 400 ) {
			return new WP_Error(
				$data['code'] ?? 'internal_rest_error',
				$data['message'] ?? __( 'An internal REST request failed.', 'templately' ),
				$data['data'] ?? []
			);
		}

		// A failed route can also come back with a status BELOW 400: a
		// transport-level failure (cloud unreachable) carries `data.status = 0`,
		// and WP_REST_Server derives the HTTP status from that field — so the
		// check above never fires and callers would iterate an error body as if
		// it were their payload. Detect the flattened WP_Error shape directly
		// (same signature RestEnvelope::is_wp_error_shape() keys on).
		if (
			is_array( $data )
			&& isset( $data['code'], $data['message'] )
			&& is_string( $data['code'] )
			&& ! array_key_exists( 'success', $data )
			&& isset( $data['data'] ) && is_array( $data['data'] ) && array_key_exists( 'status', $data['data'] )
		) {
			return new WP_Error( $data['code'], $data['message'], $data['data'] );
		}

		return $response;
	}
}
