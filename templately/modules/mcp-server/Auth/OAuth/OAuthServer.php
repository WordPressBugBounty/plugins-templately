<?php
/**
 * Delegated approval for clients that hold only a site address (spec 044,
 * FR-028–FR-039).
 *
 * Some hosted agent clients accept a URL and nothing else — no field to paste a
 * credential into. This lets them obtain one without the administrator ever
 * handling a secret.
 *
 * @package Templately\Modules\McpServer\Auth\OAuth
 */

namespace Templately\Modules\McpServer\Auth\OAuth;

use Templately\Modules\McpServer\Auth\Credentials;
use Templately\Modules\McpServer\Auth\FailedAuthLimiter;
use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\McpServer\Server\HttpTransport;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class OAuthServer {

	/**
	 * Registration input bounds. The endpoint is unauthenticated by design
	 * (RFC 7591 §5 accepts that and asks the server to "take appropriate steps
	 * to mitigate"), so every field it persists has to be bounded.
	 */
	const MAX_REDIRECT_URIS       = 5;
	const MAX_REDIRECT_URI_LENGTH = 512;
	const MAX_CLIENT_NAME_LENGTH  = 128;

	public static function register_routes(): void {
		register_rest_route(
			HttpTransport::NAMESPACE,
			'/mcp/oauth/register',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle_register' ],
				'permission_callback' => '__return_true',
			]
		);

		register_rest_route(
			HttpTransport::NAMESPACE,
			'/mcp/oauth/token',
			[
				'methods'             => 'POST',
				'callback'            => [ self::class, 'handle_token' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	// ----------------------------------------------------------- discovery

	/**
	 * Serve a discovery document.
	 *
	 * Only served when the site actually holds connection state, and passed
	 * through a filter so another plugin or site owner can decline (FR-043). An
	 * unconfigured site stays transparent to other OAuth-serving plugins — the
	 * reference implementation serves unconditionally and wins by rewrite
	 * priority regardless of which plugin the request was for.
	 *
	 * @param string $which
	 * @return void
	 */
	public static function serve_discovery( string $which ): void {
		if ( ! Credentials::site_has_any() ) {
			return;
		}

		if ( ! apply_filters( 'templately_mcp_serve_wellknown', true, $which ) ) {
			return;
		}

		$document = ( 'authorization-server' === $which )
			? self::authorization_server_metadata()
			: self::protected_resource_metadata();

		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600' );

		echo wp_json_encode( $document );

		exit;
	}

	/**
	 * @return array
	 */
	public static function protected_resource_metadata(): array {
		return [
			'resource'                 => rest_url( HttpTransport::NAMESPACE . '/mcp' ),
			'authorization_servers'    => [ RecordStore::issuer() ],
			// Advertised here too, not only on the authorization server: it is
			// what tells a client the levels it may ask for. Without it a client
			// guesses — and a guess that does not include `full` silently gets a
			// read-only connection.
			'scopes_supported'         => [ ToolDescriptor::ACCESS_READ, ToolDescriptor::ACCESS_FULL ],
			'bearer_methods_supported' => [ 'header' ],
		];
	}

	/**
	 * @return array
	 */
	public static function authorization_server_metadata(): array {
		return [
			'issuer'                                => RecordStore::issuer(),
			'authorization_endpoint'                => home_url( '/templately/authorize' ),
			'token_endpoint'                        => rest_url( HttpTransport::NAMESPACE . '/mcp/oauth/token' ),
			'registration_endpoint'                 => rest_url( HttpTransport::NAMESPACE . '/mcp/oauth/register' ),
			'response_types_supported'              => [ 'code' ],
			'grant_types_supported'                 => [ 'authorization_code', 'refresh_token' ],
			'code_challenge_methods_supported'      => [ 'S256' ],
			'token_endpoint_auth_methods_supported' => [ 'none' ],
			'scopes_supported'                      => [ ToolDescriptor::ACCESS_READ, ToolDescriptor::ACCESS_FULL ],
		];
	}

	// -------------------------------------------------------- registration

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_register( WP_REST_Request $request ) {
		if ( FailedAuthLimiter::is_locked( FailedAuthLimiter::BUCKET_OAUTH ) ) {
			return self::error_response( 'too_many_requests', __( 'Too many requests.', 'templately' ), 429 );
		}

		$body = json_decode( $request->get_body(), true );
		$body = is_array( $body ) ? $body : [];

		$uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? $body['redirect_uris'] : [];

		// Bounded BEFORE validation. This endpoint is unauthenticated by design,
		// and nothing previously capped the number of URIs, their length, or the
		// client name — so a single request could persist a row the size of
		// post_max_size, and nothing stopped an attacker repeating it. See
		// create_client() for the matching expiry that lets the sweep reclaim it.
		$uris = array_slice( array_map( 'strval', $uris ), 0, self::MAX_REDIRECT_URIS );
		$uris = array_values(
			array_filter(
				$uris,
				static function ( $uri ) {
					return strlen( $uri ) <= self::MAX_REDIRECT_URI_LENGTH
						&& self::is_allowed_redirect_uri( $uri );
				}
			)
		);

		if ( empty( $uris ) ) {
			// Counts against the limiter so registration cannot be used to
			// enumerate or to fill storage (FR-030).
			FailedAuthLimiter::record_failure( FailedAuthLimiter::BUCKET_OAUTH );

			return self::error_response(
				'invalid_redirect_uri',
				__( 'At least one https (or loopback http) redirect_uri is required.', 'templately' ),
				400
			);
		}

		// A SUCCESSFUL registration also counts — against its own generous QUOTA,
		// not the strike bucket. Charging only failures left the well-formed
		// case completely unthrottled (the case an attacker filling the database
		// would use); charging it to the strike bucket locked legitimate users
		// out after a handful of ordinary reconnections.
		if ( FailedAuthLimiter::is_locked( FailedAuthLimiter::BUCKET_REGISTER ) ) {
			return self::error_response( 'too_many_requests', __( 'Too many client registrations.', 'templately' ), 429 );
		}

		FailedAuthLimiter::record_failure( FailedAuthLimiter::BUCKET_REGISTER );

		$name   = isset( $body['client_name'] ) ? sanitize_text_field( (string) $body['client_name'] ) : '';
		$name   = function_exists( 'mb_substr' )
			? mb_substr( $name, 0, self::MAX_CLIENT_NAME_LENGTH )
			: substr( $name, 0, self::MAX_CLIENT_NAME_LENGTH );
		$client = RecordStore::create_client( $name, $uris );

		$response = new WP_REST_Response(
			[
				'client_id'                => $client['client_id'],
				'client_name'              => $client['client_name'],
				'redirect_uris'            => $client['redirect_uris'],
				'token_endpoint_auth_method' => 'none',
			],
			201
		);

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * https, or http ONLY for loopback (FR-035). The reference implementation
	 * accepts any scheme matching a generic pattern, including `javascript:`.
	 *
	 * @param string $uri
	 * @return bool
	 */
	public static function is_allowed_redirect_uri( string $uri ): bool {
		$parts = wp_parse_url( $uri );

		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );

		if ( 'https' === $scheme ) {
			return true;
		}

		// wp_parse_url() returns an IPv6 host WITH its brackets ("[::1]"), and
		// brackets are the only syntactically valid way to write an IPv6 URI —
		// so matching the bare "::1" alone could never succeed. Strip them.
		$host = trim( $host, '[]' );

		return 'http' === $scheme && in_array( $host, [ '127.0.0.1', '::1', 'localhost' ], true );
	}

	// -------------------------------------------------------------- token

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function handle_token( WP_REST_Request $request ) {
		if ( FailedAuthLimiter::is_locked( FailedAuthLimiter::BUCKET_OAUTH ) ) {
			return self::error_response( 'too_many_requests', __( 'Too many requests.', 'templately' ), 429 );
		}

		$params     = $request->get_params();
		$grant_type = isset( $params['grant_type'] ) ? (string) $params['grant_type'] : '';

		if ( 'authorization_code' === $grant_type ) {
			return self::grant_authorization_code( $params );
		}

		if ( 'refresh_token' === $grant_type ) {
			return self::grant_refresh_token( $params );
		}

		return self::error_response( 'unsupported_grant_type', __( 'Unsupported grant type.', 'templately' ), 400 );
	}

	/**
	 * @param array $params
	 * @return WP_REST_Response
	 */
	private static function grant_authorization_code( array $params ): WP_REST_Response {
		$code = isset( $params['code'] ) ? (string) $params['code'] : '';

		// Consumed (and deleted) BEFORE verification — see RecordStore::consume_grant().
		$grant = RecordStore::consume_grant( $code );

		if ( null === $grant ) {
			// A code that was already spent: deny (MUST) and revoke everything
			// issued from it (RFC 6749 §4.1.2 SHOULD), since a replay means the
			// code reached someone it should not have.
			$chain = RecordStore::spent_chain( $code );

			if ( '' !== $chain ) {
				RecordStore::revoke_chain( $chain );
			}

			FailedAuthLimiter::record_failure( FailedAuthLimiter::BUCKET_OAUTH );

			return self::error_response( 'invalid_grant', __( 'Invalid or expired authorization code.', 'templately' ), 400 );
		}

		$client_id    = isset( $params['client_id'] ) ? (string) $params['client_id'] : '';
		$redirect_uri = isset( $params['redirect_uri'] ) ? (string) $params['redirect_uri'] : '';
		$verifier     = isset( $params['code_verifier'] ) ? (string) $params['code_verifier'] : '';

		if ( ! hash_equals( (string) $grant['client_id'], $client_id )
			|| ! hash_equals( (string) $grant['redirect_uri'], $redirect_uri ) ) {
			FailedAuthLimiter::record_failure( FailedAuthLimiter::BUCKET_OAUTH );

			return self::error_response( 'invalid_grant', __( 'Grant does not match this client.', 'templately' ), 400 );
		}

		// Proof that the client completing the exchange is the one that began it.
		if ( '' === $verifier || ! hash_equals( (string) $grant['challenge'], self::s256( $verifier ) ) ) {
			FailedAuthLimiter::record_failure( FailedAuthLimiter::BUCKET_OAUTH );

			return self::error_response( 'invalid_grant', __( 'Proof of origination failed.', 'templately' ), 400 );
		}

		// RFC 8707 §2: refuse to mint a token for a resource that is not ours,
		// rather than issuing one the client will believe is scoped elsewhere.
		$requested_resource = isset( $params['resource'] ) ? (string) $params['resource'] : '';

		if ( ! RecordStore::resource_matches( $requested_resource ) ) {
			return self::error_response(
				'invalid_target',
				__( 'This server does not issue tokens for that resource.', 'templately' ),
				400
			);
		}

		$chain = RecordStore::new_chain();

		// Tombstone the code under the SAME chain as the tokens it produces, so
		// a later replay of this code can revoke them.
		RecordStore::tombstone_refresh( hash( 'sha256', $code ), $chain, RecordStore::REFRESH_TTL );

		return self::issue_tokens(
			(string) $grant['client_id'],
			(int) $grant['user_id'],
			(string) $grant['access_level'],
			$chain
		);
	}

	/**
	 * Rotation: the prior refresh token AND its paired access token are
	 * invalidated before new ones are issued (FR-036).
	 *
	 * @param array $params
	 * @return WP_REST_Response
	 */
	private static function grant_refresh_token( array $params ): WP_REST_Response {
		$secret  = isset( $params['refresh_token'] ) ? (string) $params['refresh_token'] : '';
		$refresh = RecordStore::find_refresh_token( $secret );

		if ( null === $refresh ) {
			// REPLAY DETECTION. A token we no longer hold but have a tombstone
			// for was already spent — so either it leaked and the thief is using
			// it, or the legitimate client is retrying with a token the thief
			// already burned. The server cannot tell which, and OAuth 2.1 §4.3.1
			// answers that by revoking the whole grant chain: the attacker is
			// stopped, at the cost of making the honest client re-authorize.
			//
			// Without this, rotation was cosmetic — whoever redeemed a stolen
			// token first simply kept the connection, and the real client's
			// failure looked like an ordinary expiry.
			$chain = RecordStore::spent_chain( $secret );

			if ( '' !== $chain ) {
				RecordStore::revoke_chain( $chain );

				FailedAuthLimiter::record_failure( FailedAuthLimiter::BUCKET_OAUTH );

				return self::error_response(
					'invalid_grant',
					__( 'This refresh token has already been used. The connection has been revoked; reconnect to continue.', 'templately' ),
					400
				);
			}

			FailedAuthLimiter::record_failure( FailedAuthLimiter::BUCKET_OAUTH );

			return self::error_response( 'invalid_grant', __( 'Invalid or expired refresh token.', 'templately' ), 400 );
		}

		// RFC 8707 §2.2: on a refresh, the requested resource must stay within
		// what was originally granted.
		$requested_resource = isset( $params['resource'] ) ? (string) $params['resource'] : '';

		if ( ! RecordStore::resource_matches( $requested_resource ) ) {
			return self::error_response(
				'invalid_target',
				__( 'This server does not issue tokens for that resource.', 'templately' ),
				400
			);
		}

		// Spend it. The DELETE inside arbitrates concurrent refreshes: exactly
		// one caller may proceed, where find-then-delete let both mint a pair.
		$refresh = RecordStore::consume_refresh_token( $secret );

		if ( null === $refresh ) {
			return self::error_response( 'invalid_grant', __( 'Invalid or expired refresh token.', 'templately' ), 400 );
		}

		$chain = (string) ( $refresh['chain'] ?? '' );

		if ( ! empty( $refresh['paired_access'] ) ) {
			// delete_option alone left the index pointing at a deleted row on
			// every rotation. Harmless in isolation, but the index is what
			// has_any() reads, so the debris kept the site looking connected.
			RecordStore::delete_token_by_hash( (string) $refresh['paired_access'] );
		}

		// Tombstone for the lifetime the token would have had, so a replay
		// within its original validity window is still recognised as reuse.
		RecordStore::tombstone_refresh( hash( 'sha256', $secret ), $chain, RecordStore::REFRESH_TTL );

		return self::issue_tokens(
			(string) $refresh['client_id'],
			(int) $refresh['user_id'],
			(string) $refresh['access_level'],
			$chain
		);
	}

	/**
	 * @param string $client_id
	 * @param int    $user_id
	 * @param string $access_level
	 * @return WP_REST_Response
	 */
	private static function issue_tokens( string $client_id, int $user_id, string $access_level, string $chain = '' ): WP_REST_Response {
		$level = Credentials::normalize_level( $access_level );

		// One chain id per authorization grant, carried forward across every
		// rotation, so a reuse detected at any point can revoke the lot.
		$chain = '' !== $chain ? $chain : RecordStore::new_chain();

		$access = RecordStore::create_token(
			[
				'type'         => 'access',
				'client_id'    => $client_id,
				'user_id'      => $user_id,
				'access_level' => $level,
				// The MCP endpoint's canonical URI, NOT the issuer — see
				// RecordStore::audience().
				'resource'     => RecordStore::audience(),
				'chain'        => $chain,
			],
			RecordStore::ACCESS_TTL
		);

		$refresh = RecordStore::create_token(
			[
				'type'          => 'refresh',
				'client_id'     => $client_id,
				'user_id'       => $user_id,
				'access_level'  => $level,
				'resource'      => RecordStore::audience(),
				'chain'         => $chain,
				'paired_access' => hash( 'sha256', $access ),
			],
			RecordStore::REFRESH_TTL
		);

		$response = new WP_REST_Response(
			[
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => RecordStore::ACCESS_TTL,
				'refresh_token' => $refresh,
				'scope'         => $level,
			],
			200
		);

		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );

		return $response;
	}

	/**
	 * @param string $verifier
	 * @return string
	 */
	public static function s256( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	/**
	 * @param string $code
	 * @param string $message
	 * @param int    $status
	 * @return WP_REST_Response
	 */
	private static function error_response( string $code, string $message, int $status ): WP_REST_Response {
		$response = new WP_REST_Response(
			[
				'error'             => $code,
				'error_description' => $message,
			],
			$status
		);

		$response->header( 'Cache-Control', 'no-store' );

		if ( 429 === $status ) {
			$response->header( 'Retry-After', (string) FailedAuthLimiter::retry_after( FailedAuthLimiter::BUCKET_OAUTH ) );
		}

		return $response;
	}
}
