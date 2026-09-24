<?php

namespace Templately\Utils\Response;

use WP_HTTP_Response;
use WP_REST_Request;

/**
 * Applies the envelope to every Templately REST response (spec 043 / FR-001, FR-008a).
 *
 * It runs on `rest_post_dispatch`, which is the one place EVERY route in the
 * namespace passes through — including the ones that still return a bare array
 * or a legacy `WP_Error`. Doing it here rather than per-endpoint is what makes
 * "one envelope" true by construction instead of by convention.
 *
 * The frontend counterpart is `unwrapEnvelope()` in `react-src/utils/api.ts`:
 * it strips the success wrapper in the single transport every call goes through,
 * so consumers still receive exactly the payload they received before the
 * envelope existed. That pairing is what makes wrapping everything here safe.
 *
 * ## Padding (FR-008a)
 *
 * Some proxies/browsers mishandle very small JSON bodies, which is why the
 * plugin used to staple 512 bytes of spaces into EVERY error's data bag. That
 * padding is now applied here, once, only when the serialized body is under
 * `PADDING_THRESHOLD` — and as a HEADER, so the body stays schema-valid and no
 * `browser_padding` key leaks into the contract.
 */
class RestEnvelope {

	/**
	 * Bodies smaller than this get padded; larger ones never do.
	 */
	const PADDING_THRESHOLD = 1024;

	/**
	 * @return void
	 */
	public static function init() {
		add_filter( 'rest_post_dispatch', [ __CLASS__, 'filter_response' ], 10, 3 );
	}

	/**
	 * @param WP_HTTP_Response $result
	 * @param mixed            $server
	 * @param WP_REST_Request  $request
	 * @return WP_HTTP_Response
	 */
	public static function filter_response( $result, $server, $request ) {
		if ( ! $result instanceof WP_HTTP_Response || ! $request instanceof WP_REST_Request ) {
			return $result;
		}

		if ( ! self::owns_route( $request ) ) {
			return $result;
		}

		return self::apply( $result );
	}

	/**
	 * Wrap one response. Extracted from the filter so tests can exercise it
	 * without standing up a dispatcher.
	 *
	 * @param WP_HTTP_Response $result
	 * @return WP_HTTP_Response
	 */
	public static function apply( WP_HTTP_Response $result ) {
		$data     = $result->get_data();
		$status   = (int) $result->get_status();
		$envelope = self::to_envelope( $data, $status );

		if ( Envelope::is_error( $envelope ) ) {
			// `data.status` is only guaranteed on envelopes this class builds from
			// a WP_Error shape. An error envelope constructed anywhere else — or a
			// foreign `success:false` payload that reached us — has no such key,
			// and reading it blind emitted a pair of PHP warnings on every request
			// that took this path ("Undefined array key \"data\"", then "Trying to
			// access array offset on null"). Fall back to the response's own
			// status rather than assuming a shape we did not build.
			$error_status = isset( $envelope['data']['status'] ) ? (int) $envelope['data']['status'] : $status;
			$result->set_status( $error_status >= 400 ? $error_status : 500 );
		}

		$result->set_data( $envelope );
		self::maybe_pad( $result, $envelope );

		return $result;
	}

	/**
	 * @param mixed $data
	 * @param int   $status
	 * @return array
	 */
	public static function to_envelope( $data, $status ) {
		// Already an envelope (an endpoint built it directly) — leave it alone.
		if ( self::is_envelope( $data ) ) {
			return $data;
		}

		// The REST server has already flattened any WP_Error into
		// `{ code, message, data: { status } }` by the time we see it.
		if ( self::is_wp_error_shape( $data ) ) {
			return Envelope::error( self::error_from_wp_error_shape( $data, $status ) );
		}

		if ( $status >= 400 ) {
			$message = is_array( $data ) && isset( $data['message'] ) && is_string( $data['message'] )
				? $data['message']
				: '';

			return Envelope::error( self::error_for_status( $status, $message ) );
		}

		return Envelope::success( $data );
	}

	/**
	 * @param mixed $data
	 * @return bool
	 */
	public static function is_envelope( $data ) {
		return is_array( $data )
			&& array_key_exists( 'success', $data )
			&& is_bool( $data['success'] )
			&& ( $data['success'] ? array_key_exists( 'data', $data ) : isset( $data['code'], $data['message'] ) );
	}

	/**
	 * @param mixed $data
	 * @return bool
	 */
	private static function is_wp_error_shape( $data ) {
		return is_array( $data )
			&& isset( $data['code'], $data['message'] )
			&& is_string( $data['code'] )
			&& ! array_key_exists( 'success', $data );
	}

	/**
	 * @param array $data
	 * @param int   $status
	 * @return TemplatelyError
	 */
	private static function error_from_wp_error_shape( $data, $status ) {
		$legacy       = $data['code'];
		$error_data   = isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : [];
		$http_status  = isset( $error_data['status'] ) ? (int) $error_data['status'] : $status;
		$code         = ErrorCode::exists( $legacy ) ? $legacy : self::legacy_code( $legacy, $http_status );

		$context = [];
		if ( $code !== $legacy ) {
			$context['legacy_code'] = $legacy;
		}
		if ( ! empty( $error_data['endpoint'] ) ) {
			$context['endpoint'] = $error_data['endpoint'];
		}

		// The historical unconditional padding never belonged in the contract.
		unset( $error_data['browser_padding'], $error_data['status'], $error_data['endpoint'] );

		return new TemplatelyError( $code, $data['message'], [
			'status'  => $http_status ?: 500,
			'fields'  => isset( $error_data['fields'] ) ? $error_data['fields'] : [],
			'context' => array_merge( $context, isset( $error_data['context'] ) && is_array( $error_data['context'] ) ? $error_data['context'] : [] ),
		] );
	}

	/**
	 * Map the pre-043 `WP_Error` vocabulary onto the registry. Anything
	 * unrecognised degrades by HTTP status rather than collapsing to a generic
	 * server error.
	 *
	 * @param string $legacy
	 * @param int    $status
	 * @return string
	 */
	private static function legacy_code( $legacy, $status ) {
		$map = [
			'invalid_api_key'           => ErrorCode::AUTH_EXPIRED,
			'rest_forbidden'            => ErrorCode::AUTH_EXPIRED,
			'rest_cookie_invalid_nonce' => ErrorCode::AUTH_EXPIRED,
			'rest_no_route'             => ErrorCode::NOT_FOUND,
			'templately_graphql_error'  => ErrorCode::SERVER_ERROR,
			'templately_api_error'      => ErrorCode::SERVER_ERROR,
			'templately_http_error'     => ErrorCode::SERVER_ERROR,
		];

		if ( isset( $map[ $legacy ] ) ) {
			return $map[ $legacy ];
		}

		return self::error_for_status( $status ?: 500, '' )->code();
	}

	/**
	 * @param int    $status
	 * @param string $message
	 * @return TemplatelyError
	 */
	private static function error_for_status( $status, $message ) {
		$response = ResponseNormalizer::normalize(
			[
				'response' => [ 'code' => $status, 'message' => '' ],
				'body'     => wp_json_encode( [ 'status' => 'error', 'message' => $message ] ),
				'headers'  => [ 'content-type' => 'application/json' ],
			],
			[ 'side_effects' => false ]
		);

		return $response->error();
	}

	/**
	 * FR-008a — pad only what is small enough to trip the browser bug, and pad
	 * in a header so the body stays exactly the contract shape.
	 *
	 * @param WP_HTTP_Response $result
	 * @param array            $envelope
	 * @return void
	 */
	private static function maybe_pad( WP_HTTP_Response $result, $envelope ) {
		$serialized = wp_json_encode( $envelope );
		$length     = is_string( $serialized ) ? strlen( $serialized ) : self::PADDING_THRESHOLD;

		if ( $length >= self::PADDING_THRESHOLD ) {
			return;
		}

		$result->header( 'X-Templately-Padding', str_repeat( '.', self::PADDING_THRESHOLD - $length ) );
	}

	/**
	 * @param WP_REST_Request $request
	 * @return bool
	 */
	private static function owns_route( WP_REST_Request $request ) {
		if ( ! defined( 'TEMPLATELY_API_NAMESPACE' ) ) {
			return false;
		}

		$route = (string) $request->get_route();
		$owned = 0 === strpos( ltrim( $route, '/' ), TEMPLATELY_API_NAMESPACE );

		/**
		 * Whether the envelope applies to this route.
		 *
		 * The envelope is the wire contract with OUR OWN frontend, which strips it
		 * again in `unwrapEnvelope()`. A route in this namespace that answers to a
		 * FOREIGN protocol — JSON-RPC, OAuth 2.1 — has its own mandated body shape
		 * and must opt out: wrapping it produces `{"success":true,"data":{…}}`,
		 * which no MCP or OAuth client can parse, and replaces RFC-required fields
		 * (`error`, `access_token`) with this vocabulary. It also drops error-data
		 * keys that later filters promote into headers, so `WWW-Authenticate` never
		 * reaches the wire.
		 *
		 * Opting out is the module's own call — core names no module. See
		 * `McpServer\Server\HttpTransport::exempt_protocol_routes()`.
		 *
		 * @param bool            $owned   Whether the envelope applies.
		 * @param string          $route   Route path, e.g. `/templately/v1/mcp`.
		 * @param WP_REST_Request $request The request being answered.
		 */
		return (bool) apply_filters( 'templately_rest_envelope_owns_route', $owned, $route, $request );
	}
}
