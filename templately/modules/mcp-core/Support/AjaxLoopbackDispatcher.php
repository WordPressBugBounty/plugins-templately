<?php
/**
 * Authenticated in-process HTTP loopback to `admin-ajax.php` for the FSI
 * `templately_pack_*` actions (spec 045, research.md §1–§3).
 *
 * Lives beside `RestDispatcher` — the two are siblings, one per calling
 * convention: in-process `rest_do_request()` for REST routes, an authenticated
 * loopback for transport-coupled ajax handlers. Neither knows anything about a
 * particular capability, which is why both sit in mcp-core rather than in a
 * feature module.
 *
 * WHY a loopback instead of 041's in-process `RestDispatcher`: the FSI
 * handlers are `wp_ajax_*` (not REST) and are transport-coupled —
 * `Concerns\RunsImport::finishRequestHeaders()` sends SSE headers + calls
 * `fastcgi_finish_request()`, and each handler `exit`s at a time-slice
 * boundary. Calling them in-process would emit SSE into / prematurely end the
 * MCP request. A loopback replays the exact browser request, one SSE chunk at
 * a time, with zero importer changes (FR-012). See plan.md Complexity Tracking.
 *
 * AUTH: the MCP request is authenticated by the mcp-adapter transport
 * (Application Password / STDIO), so `get_current_user_id()` is reliable, but
 * that identity does not carry into a fresh loopback HTTP request. We mint a
 * short-lived real session for the resolved user and attach its auth cookies,
 * plus a freshly-generated `templately_nonce` — the underlying handler's own
 * nonce + capability checks then pass exactly as for a browser request. No
 * secret is stored; the session token is created per call and expires in 5m.
 *
 * @package Templately\Modules\McpCore\Support
 */

namespace Templately\Modules\McpCore\Support;

use WP_Error;
use WP_Http_Cookie;
use WP_Session_Tokens;

class AjaxLoopbackDispatcher {

	/** Session/cookie lifetime for a single loopback (seconds). */
	const AUTH_TTL = 300;

	/**
	 * Loopback a JSON action (handlers that end in `wp_send_json*`), e.g.
	 * `import_settings`, `import_status`, `import_revert`.
	 *
	 * @param string $action Bare action name (without the `templately_pack_` prefix).
	 * @param array  $args   Request params (nonce is added automatically).
	 * @param string $method GET|POST.
	 * @param int    $timeout
	 * @return array|WP_Error Decoded JSON body, or a `loopback_failed` WP_Error.
	 */
	public static function dispatch_json( string $action, array $args = [], string $method = 'POST', int $timeout = 60 ) {
		$response = self::request( $action, $args, $method, $timeout );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body    = wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		// Robustness: admin-ajax can prepend output before wp_send_json's payload
		// (PHP notices/deprecations when WP_DEBUG_DISPLAY is on, or stray plugin
		// output), which json_decode() then rejects. wp_send_json emits the JSON as
		// the final output, so retry from each '{' until one parses through to the end.
		if ( ! is_array( $decoded ) ) {
			for ( $pos = strpos( $body, '{' ); false !== $pos; $pos = strpos( $body, '{', $pos + 1 ) ) {
				$candidate = json_decode( substr( $body, $pos ), true );
				if ( is_array( $candidate ) ) {
					$decoded = $candidate;
					break;
				}
			}
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'loopback_failed',
				__( 'The import backend returned an unreadable response.', 'templately' )
			);
		}

		return $decoded;
	}

	/**
	 * Read a failure out of a decoded ajax body, or null when it succeeded.
	 *
	 * Exists because the envelope moved. Spec 043 made every admin-ajax response
	 * `{success, code, message, data:{status, severity, retryable, fields,
	 * context}}` at HTTP 200 — the human message is now at the TOP level and
	 * retryability is the first-class `data.retryable`, replacing the
	 * `create_session_and_download` handler's old bespoke `data.should_retry`
	 * key (see Concerns\HandlesSession). Callers written against the old shape
	 * read `data.message`, which is now always absent: every failure would
	 * silently collapse to a generic fallback string and every retryable one
	 * would look permanent. Reading the envelope in ONE place is what keeps that
	 * from having to be remembered at each call site.
	 *
	 * @param array|mixed $decoded Decoded body from {@see self::dispatch_json()}.
	 * @param string      $fallback Message to use when the envelope carries none.
	 * @return WP_Error|null
	 */
	public static function envelope_error( $decoded, string $fallback ): ?WP_Error {
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'loopback_failed', $fallback );
		}

		if ( ! empty( $decoded['success'] ) ) {
			return null;
		}

		$data = is_array( $decoded['data'] ?? null ) ? $decoded['data'] : [];

		$message = '';
		foreach ( [ $decoded['message'] ?? '', $data['message'] ?? '' ] as $candidate ) {
			if ( is_string( $candidate ) && '' !== $candidate ) {
				$message = $candidate;
				break;
			}
		}

		return new WP_Error(
			is_string( $decoded['code'] ?? null ) && '' !== $decoded['code'] ? $decoded['code'] : 'loopback_failed',
			'' !== $message ? $message : $fallback,
			[ 'retryable' => ! empty( $data['retryable'] ) ]
		);
	}

	/**
	 * Loopback the SSE `import` / `create_session_and_download` actions and
	 * return the parsed events from the single chunk the handler streams
	 * before it `exit`s (one time-slice — poll-driven advance, FR-005).
	 *
	 * @param string $action Bare action name.
	 * @param array  $args   Request params.
	 * @param int    $timeout Seconds to allow one slice.
	 * @return array|WP_Error List of parsed event arrays, or `loopback_failed`.
	 */
	public static function dispatch_sse( string $action, array $args = [], int $timeout = 120 ) {
		// FSI reads the session id from $_GET and the nonce from $_GET/$_POST;
		// send as a GET query so `import` resolves it the way the browser does.
		$response = self::request( $action, $args, 'GET', $timeout );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return self::parse_sse( wp_remote_retrieve_body( $response ) );
	}

	/**
	 * Perform the authenticated loopback request.
	 *
	 * @param string $action
	 * @param array  $args
	 * @param string $method
	 * @param int    $timeout
	 * @return array|WP_Error The raw wp_remote_* response array, or WP_Error.
	 */
	private static function request( string $action, array $args, string $method, int $timeout ) {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return new WP_Error( 'loopback_failed', __( 'No authenticated user for the import loopback.', 'templately' ) );
		}

		$auth = self::mint_auth( $user_id );
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}
		$cookies = $auth['cookies'];

		$params = array_merge( $args, [
			'action' => 'templately_pack_' . $action,
			// Marks this as an MCP-loopback-driven request so MCP can cap the FSI
			// slice duration under the gateway timeout (see MCP::mcp_fsi_slice_cap).
			'templately_mcp_driven' => 1,
			// The nonce MUST be built against the same session token the loopback
			// request will present (the minted cookie's token). wp_create_nonce()
			// here would use THIS (MCP) request's session token — which is empty,
			// because the MCP request authenticates via Application Password with no
			// logged-in cookie — and would then fail wp_verify_nonce() in the
			// loopback, where get_session_token() returns the minted token. So
			// compute the nonce explicitly for that token.
			'nonce'  => self::nonce_for_token( 'templately_nonce', $user_id, $auth['token'] ),
		] );

		// Loopback base URL. Defaults to the site's own admin-ajax. On hosts that
		// cannot reach their own public URL (reverse proxies, split-horizon DNS,
		// containerized setups — the same class of environment where WP-Cron's
		// loopback fails), override via the TEMPLATELY_MCP_FSI_LOOPBACK_URL
		// constant or the `templately_mcp_fsi_loopback_url` filter to point at an
		// internally-reachable address; the correct vhost is preserved by the Host
		// header below so cookies/home_url resolve as normal.
		$default_url = defined( 'TEMPLATELY_MCP_FSI_LOOPBACK_URL' ) && TEMPLATELY_MCP_FSI_LOOPBACK_URL
			? TEMPLATELY_MCP_FSI_LOOPBACK_URL
			: admin_url( 'admin-ajax.php' );
		$url  = apply_filters( 'templately_mcp_fsi_loopback_url', $default_url );
		$http_args = [
			'timeout'   => $timeout,
			'blocking'  => true,
			'cookies'   => $cookies,
			'sslverify' => false, // self-loopback; the dev filter already relaxes this too.
			'headers'   => [
				'Accept' => 'text/event-stream, application/json',
				'Host'   => wp_parse_url( home_url(), PHP_URL_HOST ),
			],
		];

		if ( 'GET' === strtoupper( $method ) ) {
			// Encoded RECURSIVELY. add_query_arg() serialises through build_query()
			// with url-encoding disabled, so the values have to arrive encoded — but
			// a flat `array_map( 'rawurlencode', … )` raises a TypeError the moment
			// any capability passes an array-valued argument, which would take the
			// whole import down with a PHP error rather than a reportable failure.
			$response = wp_remote_get( add_query_arg( self::encode_query( $params ), $url ), $http_args );
		} else {
			$http_args['body'] = $params;
			$response          = wp_remote_post( $url, $http_args );
		}

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'loopback_failed',
				sprintf(
					/* translators: %s: underlying HTTP error. */
					__( 'The site could not reach itself to run the import (loopback blocked): %s', 'templately' ),
					$response->get_error_message()
				)
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code && $code >= 400 ) {
			return new WP_Error(
				'loopback_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'The import backend returned HTTP %d.', 'templately' ),
					$code
				)
			);
		}

		return $response;
	}

	/**
	 * rawurlencode every scalar in a (possibly nested) query array.
	 *
	 * @param array $params
	 * @return array
	 */
	private static function encode_query( array $params ): array {
		return array_map(
			static function ( $value ) {
				return is_array( $value )
					? self::encode_query( $value )
					: rawurlencode( (string) $value );
			},
			$params
		);
	}

	/**
	 * Mint a short-lived real session for the user, returning the session token
	 * plus the auth cookies wp validates on the loopback. A genuine session
	 * token is required — a cookie without one fails `WP_Session_Tokens::verify()`
	 * — and the caller also needs the token to build a matching nonce.
	 *
	 * @param int $user_id
	 * @return array{token:string,cookies:WP_Http_Cookie[]}|WP_Error
	 */
	private static function mint_auth( int $user_id ) {
		if ( ! function_exists( 'wp_generate_auth_cookie' ) ) {
			require_once ABSPATH . WPINC . '/pluggable.php';
		}

		$expiration = time() + self::AUTH_TTL;
		$manager    = WP_Session_Tokens::get_instance( $user_id );
		$token      = $manager->create( $expiration );

		$auth_scheme      = is_ssl() ? 'secure_auth' : 'auth';
		$auth_cookie_name = is_ssl() ? SECURE_AUTH_COOKIE : AUTH_COOKIE;

		return [
			'token'   => $token,
			'cookies' => [
				new WP_Http_Cookie( [
					'name'  => LOGGED_IN_COOKIE,
					'value' => wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ),
				] ),
				new WP_Http_Cookie( [
					'name'  => $auth_cookie_name,
					'value' => wp_generate_auth_cookie( $user_id, $expiration, $auth_scheme, $token ),
				] ),
			],
		];
	}

	/**
	 * Build a `templately_nonce` bound to a specific user + session token,
	 * replicating wp_create_nonce()'s formula so the value verifies in the
	 * loopback request (where get_session_token() returns $token). We cannot use
	 * wp_create_nonce() directly because it reads THIS request's session token,
	 * which is empty under Application-Password auth. Formula stable since WP 3.x.
	 *
	 * @param string $action
	 * @param int    $uid
	 * @param string $token
	 * @return string
	 */
	private static function nonce_for_token( string $action, int $uid, string $token ): string {
		$tick = wp_nonce_tick( $action );
		return substr( wp_hash( $tick . '|' . $action . '|' . $uid . '|' . $token, 'nonce' ), -12, 10 );
	}

	/**
	 * Parse an SSE body (`event: …\n data: {json}\n\n`) into a list of decoded
	 * `data:` payloads. Non-JSON data lines are skipped.
	 *
	 * @param string $body
	 * @return array
	 */
	private static function parse_sse( string $body ): array {
		$events = [];
		foreach ( preg_split( '/\r\n|\r|\n/', $body ) as $line ) {
			if ( 0 !== strpos( $line, 'data:' ) ) {
				continue;
			}
			$json    = trim( substr( $line, strlen( 'data:' ) ) );
			$decoded = json_decode( $json, true );
			if ( is_array( $decoded ) ) {
				$events[] = $decoded;
			}
		}
		return $events;
	}
}
