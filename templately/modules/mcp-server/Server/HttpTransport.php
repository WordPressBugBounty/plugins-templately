<?php
/**
 * The built-in MCP endpoint (spec 044, FR-001, FR-007, FR-008, FR-041).
 *
 * Routes:
 *   POST /wp-json/templately/v1/mcp            JSON-RPC
 *   POST /templately/mcp                       pretty alias (rewrite)
 *   GET  /.well-known/oauth-protected-resource discovery
 *   GET  /.well-known/oauth-authorization-server discovery
 *   GET|POST /templately/authorize             approval screen (front-end page)
 *
 * No collision with the mcp-adapter's `/wp-json/templately/mcp` (namespace
 * `templately`, route `mcp`): this lives under `templately/v1`. Both may serve
 * the same capability set concurrently (FR-007).
 *
 * Registered with register_rest_route() DIRECTLY rather than through
 * API::register_endpoint(), which force-injects its own `_permission_check`
 * and would fight AuthManager.
 *
 * @package Templately\Modules\McpServer\Server
 */

namespace Templately\Modules\McpServer\Server;

use Templately\Modules\McpServer\Auth\AuthManager;
use Templately\Modules\McpServer\Auth\Credentials;
use Templately\Modules\McpServer\Auth\FailedAuthLimiter;
use Templately\Modules\McpServer\Auth\OAuth\ConsentScreen;
use Templately\Modules\McpServer\Auth\OAuth\OAuthServer;
use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Utils\Base;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class HttpTransport extends Base {

	const NAMESPACE = 'templately/v1';
	const ROUTE     = '/mcp';

	/** Rewrite rules this feature owns. Used by the self-healing flush check. */
	const REWRITES = [
		'^templately/mcp/?$'       => 'index.php?templately_mcp=1',
		'^templately/authorize/?$' => 'index.php?templately_mcp_authorize=1',

		// NOTE: the BARE `/.well-known/oauth-protected-resource` and
		// `/.well-known/oauth-authorization-server` are deliberately NOT claimed.
		//
		// Those are one global slot per origin, and the documents we would serve
		// there are not even valid at that address: RFC 8414 §3.3 requires the
		// returned `issuer` to be identical to the issuer whose metadata was
		// retrieved, and RFC 9728 the same for `resource`. Ours are
		// `<origin>/templately` and `<origin>/wp-json/templately/v1/mcp`, so a
		// strict client fetching the bare path would rightly reject the answer.
		//
		// Claiming them anyway is what let two MCP plugins fight over discovery.
		// Nothing needs them: the 401 challenge names the resource-specific
		// metadata URL directly (AuthManager::challenge_header), that document
		// names the scoped issuer, and the issuer resolves to its own slot below.

		// RFC 9728 §3.1: a client appends the RESOURCE PATH to the well-known
		// prefix, so an MCP client configured with
		// `…/wp-json/templately/v1/mcp` actually requests
		// `/.well-known/oauth-protected-resource/wp-json/templately/v1/mcp`.
		// Serving only the bare path above meant that request fell through to
		// whatever else on the site claimed `.well-known` — on a site that also
		// runs another MCP plugin, that plugin answered FOR THIS RESOURCE and
		// handed the client its own authorization endpoint, so approval happened
		// somewhere else entirely. Observed live with ChatGPT.
		//
		// Deliberately matched to THIS plugin's own resource paths rather than a
		// wildcard: a broad rule would hijack the neighbour's discovery exactly
		// as ours was hijacked.
		'^\.well-known/oauth-(protected-resource|authorization-server)/.*templately/(?:v1/)?mcp/?$'
			=> 'index.php?templately_mcp_wellknown=$matches[1]',

		// The PATH-SCOPED issuer's own metadata slot. RFC 8414 §3.1 builds the
		// URL for an issuer with a path by inserting the well-known segment
		// between host and path, so issuer `https://site/templately` publishes at
		// `/.well-known/oauth-authorization-server/templately`.
		//
		// This is the slot that makes coexistence possible: the bare
		// `/.well-known/oauth-authorization-server` is a SINGLE global slot per
		// origin, which two MCP plugins cannot both own — we were winning it by
		// rewrite-registration order, not by right.
		'^\.well-known/oauth-authorization-server/templately/?$'
			=> 'index.php?templately_mcp_wellknown=authorization-server',
	];

	/**
	 * Routes that speak a FOREIGN protocol and must never be enveloped.
	 *
	 * Matched exactly, not by prefix: `/mcp/connection*` shares the `/mcp` prefix
	 * but is an ordinary Templately REST route consumed by our own settings SPA,
	 * so it MUST keep the envelope.
	 */
	const PROTOCOL_ROUTES = [
		'/' . self::NAMESPACE . '/mcp',                 // JSON-RPC 2.0
		'/' . self::NAMESPACE . '/mcp/oauth/register',  // RFC 7591
		'/' . self::NAMESPACE . '/mcp/oauth/token',     // RFC 6749 §5
	];

	/** @var array|null Auth context resolved in the permission callback. */
	private $context = null;

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		// Keep the spec-043 envelope off this module's protocol routes — see
		// exempt_protocol_routes(). Registered in the constructor (plugins_loaded)
		// so it is in place before any request is dispatched.
		add_filter( 'templately_rest_envelope_owns_route', [ $this, 'exempt_protocol_routes' ], 10, 2 );
		// WP_Error data does not become response headers on its own, and the
		// WWW-Authenticate pointer is what lets a URL-only client bootstrap
		// (FR-029) — without it a 401 is a dead end.
		// Priority 20, NOT 10: core's own `rest_send_allow_header` is also on
		// `rest_post_dispatch` at 10, and this class is constructed on
		// `plugins_loaded` — i.e. registered first — so at equal priority ours
		// would run before core's and be overwritten by it.
		add_filter( 'rest_post_dispatch', [ $this, 'attach_auth_headers' ], 20, 3 );
		add_action( 'init', [ $this, 'add_rewrites' ] );
		add_filter( 'query_vars', [ $this, 'add_query_vars' ] );
		add_action( 'parse_request', [ $this, 'maybe_handle_pretty_request' ] );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'handle' ],
					'permission_callback' => [ $this, 'authorize' ],
				],
				[
					// GET/DELETE are the streamable-HTTP session verbs: GET opens an
					// SSE stream, DELETE terminates a session. This server is
					// stateless and serves no SSE (spec 044, Out of Scope), and the
					// protocol says a server that does not offer a stream at this
					// endpoint MUST answer 405 — not 404.
					//
					// Without this, WordPress answers an unmatched method with a
					// generic `rest_no_route` 404, which reads as "this feature is
					// not installed" to anyone who pastes the endpoint into a
					// browser. 405 says "right address, wrong verb".
					'methods'             => 'GET, DELETE',
					'callback'            => [ $this, 'method_not_allowed' ],
					'permission_callback' => '__return_true',
				],
			]
		);

		OAuthServer::register_routes();
	}

	/**
	 * @return WP_REST_Response
	 */
	public function method_not_allowed(): WP_REST_Response {
		$response = new WP_REST_Response(
			JsonRpc::error(
				null,
				JsonRpc::INVALID_REQUEST,
				__( 'This MCP endpoint accepts POST only. Configure it in an MCP client rather than opening it in a browser.', 'templately' )
			),
			405
		);

		// NOTE: `Allow` is (re)set in attach_auth_headers(), not here — the REST
		// server overwrites it after dispatch with the matched route's own
		// methods, which would advertise "GET, DELETE": precisely the verbs that
		// are NOT allowed.
		//
		// Still advertise where to authenticate, so a client probing with GET can
		// discover the flow instead of dead-ending.
		$response->header( 'WWW-Authenticate', AuthManager::challenge_header() );

		return $response;
	}

	/**
	 * Preconditions + identity, in a load-bearing order (contracts/mcp-endpoint.md):
	 *
	 *   1. lockout       — BEFORE any secret comparison, so a locked source
	 *                      cannot use the endpoint as a guessing oracle
	 *   2. content type  — reject non-agent submissions
	 *   3. origin        — reject cross-site browser submissions
	 *   4. inert check   — refuse while the site holds no credential
	 *   5. credential    — resolve and establish the acting user
	 *
	 * @param WP_REST_Request $request
	 * @return true|WP_Error
	 */
	public function authorize( WP_REST_Request $request ) {
		if ( FailedAuthLimiter::is_locked() ) {
			return new WP_Error(
				'too_many_requests',
				__( 'Too many failed authentication attempts. Try again later.', 'templately' ),
				[
					'status'      => 429,
					'retry_after' => FailedAuthLimiter::retry_after(),
				]
			);
		}

		$content_type = (string) $request->get_header( 'content-type' );

		if ( '' !== $content_type && false === stripos( $content_type, 'application/json' ) ) {
			return new WP_Error(
				'unsupported_media_type',
				__( 'This endpoint accepts application/json only.', 'templately' ),
				[ 'status' => 415 ]
			);
		}

		$origin = (string) $request->get_header( 'origin' );

		if ( '' !== $origin && ! $this->origin_is_same_site( $origin ) ) {
			return new WP_Error(
				'forbidden_origin',
				__( 'Cross-origin requests are not accepted by this endpoint.', 'templately' ),
				[ 'status' => 403 ]
			);
		}

		// Inert until an administrator explicitly connects (FR-023).
		if ( ! Credentials::site_has_any() ) {
			return $this->unauthorized( __( 'This site has no agent connection configured.', 'templately' ) );
		}

		$context = AuthManager::resolve();

		if ( null === $context ) {
			return $this->unauthorized( __( 'Invalid or missing connection credential.', 'templately' ) );
		}

		$this->context = $context;

		return true;
	}

	/**
	 * @param string $message
	 * @return WP_Error
	 */
	private function unauthorized( string $message ): WP_Error {
		return new WP_Error(
			'unauthorized',
			$message,
			[
				'status'           => 401,
				'www_authenticate' => AuthManager::challenge_header(),
			]
		);
	}

	/**
	 * @param string $origin
	 * @return bool
	 */
	private function origin_is_same_site( string $origin ): bool {
		$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );

		return is_string( $origin_host ) && is_string( $site_host )
			&& strtolower( $origin_host ) === strtolower( $site_host );
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function handle( WP_REST_Request $request ) {
		$body    = $request->get_body();
		$decoded = json_decode( $body, true );

		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_REST_Response(
				JsonRpc::error( null, JsonRpc::PARSE_ERROR, __( 'Could not parse request body as JSON.', 'templately' ) ),
				200
			);
		}

		// Batching was removed in the supported protocol revision (FR-004). The
		// reference implementation still accepts it.
		if ( ! JsonRpc::is_valid_request( $decoded ) ) {
			return new WP_REST_Response(
				JsonRpc::error(
					null,
					JsonRpc::INVALID_REQUEST,
					is_array( $decoded ) && isset( $decoded[0] )
						? __( 'JSON-RPC batching is not supported.', 'templately' )
						: __( 'Not a valid JSON-RPC request.', 'templately' )
				),
				200
			);
		}

		$access_level  = (string) ( $this->context['access_level'] ?? ToolDescriptor::ACCESS_FULL );
		$credential_id = $this->context['credential_id'] ?? null;

		$response = McpServer::dispatch( $decoded, $access_level, $credential_id );

		// Notification — accepted, no body (FR-002).
		if ( null === $response ) {
			return new WP_REST_Response( null, 202 );
		}

		return new WP_REST_Response( $response, 200 );
	}

	/**
	 * Refuse the spec-043 envelope on the three routes that answer to a protocol
	 * this plugin does not own.
	 *
	 * `RestEnvelope` claims the whole `templately/v1` namespace, and these routes
	 * live in it — so without this every JSON-RPC reply ships as
	 * `{"success":true,"data":{"jsonrpc":"2.0",…}}` and every token response as
	 * `{"success":true,"data":{"access_token":…}}`. Neither is parseable by the
	 * clients they are for, and an OAuth error body loses the RFC-required `error`
	 * field to this plugin's own error vocabulary.
	 *
	 * The damage is not only to the body. The envelope rebuilds a WP_Error's data
	 * bag from a fixed key set, dropping `www_authenticate` — which
	 * `attach_auth_headers()` reads back to emit the `WWW-Authenticate` challenge.
	 * That challenge is how a URL-only client discovers where to authenticate
	 * (FR-029), so losing it dead-ends the whole delegated-approval flow.
	 *
	 * None of this was visible in the test suite: every MCP test drives the
	 * endpoint with `rest_do_request()`, which does not run `rest_post_dispatch`,
	 * so the envelope never applied there. It applies over real HTTP, on exactly
	 * the endpoint the settings screen and the docs tell users to configure.
	 *
	 * @param bool   $owned
	 * @param string $route
	 * @return bool
	 */
	public function exempt_protocol_routes( $owned, $route ) {
		return $owned && ! in_array( untrailingslashit( (string) $route ), self::PROTOCOL_ROUTES, true );
	}

	/**
	 * Promote auth metadata from the WP_Error payload into real headers.
	 *
	 * @param WP_REST_Response $response
	 * @param mixed            $server
	 * @param WP_REST_Request  $request
	 * @return WP_REST_Response
	 */
	public function attach_auth_headers( $response, $server, $request ) {
		if ( ! $response instanceof WP_REST_Response || ! $request instanceof WP_REST_Request ) {
			return $response;
		}

		if ( 0 !== strpos( (string) $request->get_route(), '/' . self::NAMESPACE . '/mcp' ) ) {
			return $response;
		}

		// Correct the Allow header the REST server writes after dispatch: it lists
		// the matched handler's methods (GET, DELETE) when those are exactly the
		// ones being refused. POST is what a caller should use.
		if ( 405 === $response->get_status() ) {
			$response->header( 'Allow', 'POST', true );
		}

		$data = $response->get_data();

		if ( ! is_array( $data ) || empty( $data['data'] ) || ! is_array( $data['data'] ) ) {
			return $response;
		}

		if ( ! empty( $data['data']['www_authenticate'] ) ) {
			$response->header( 'WWW-Authenticate', (string) $data['data']['www_authenticate'] );
		}

		if ( ! empty( $data['data']['retry_after'] ) ) {
			$response->header( 'Retry-After', (string) (int) $data['data']['retry_after'] );
		}

		return $response;
	}

	// ---------------------------------------------------------------- rewrites

	public function add_rewrites(): void {
		foreach ( self::REWRITES as $pattern => $target ) {
			add_rewrite_rule( $pattern, $target, 'top' );
		}

		$this->maybe_flush_rewrites();
	}

	/**
	 * Self-healing flush (FR-008). Compares EVERY rule this feature needs, not
	 * just one — a single-rule check is exactly what let the reference
	 * implementation ship a state where discovery 404'd permanently because an
	 * earlier partial flush had left one rule present and the rest missing.
	 *
	 * @return void
	 */
	private function maybe_flush_rewrites(): void {
		$rules = get_option( 'rewrite_rules' );

		if ( ! is_array( $rules ) ) {
			return;
		}

		foreach ( array_keys( self::REWRITES ) as $pattern ) {
			if ( ! isset( $rules[ $pattern ] ) ) {
				flush_rewrite_rules( false );

				return;
			}
		}
	}

	/**
	 * @param array $vars
	 * @return array
	 */
	public function add_query_vars( array $vars ): array {
		$vars[] = 'templately_mcp';
		$vars[] = 'templately_mcp_authorize';
		$vars[] = 'templately_mcp_wellknown';

		return $vars;
	}

	/**
	 * Serve the pretty paths. The JSON-RPC alias synthesizes a WP_REST_Request
	 * and reuses the same handler rather than duplicating dispatch.
	 *
	 * @param \WP $wp
	 * @return void
	 */
	public function maybe_handle_pretty_request( $wp ): void {
		$vars = isset( $wp->query_vars ) && is_array( $wp->query_vars ) ? $wp->query_vars : [];

		if ( ! empty( $vars['templately_mcp_wellknown'] ) ) {
			OAuthServer::serve_discovery( (string) $vars['templately_mcp_wellknown'] );

			return;
		}

		// The approval screen must be a normal front-end page, NOT a REST route:
		// a browser returning from wp-login carries a cookie but no REST nonce,
		// so inside REST it does not read as logged in and the login redirect
		// loops forever (FR-032).
		if ( ! empty( $vars['templately_mcp_authorize'] ) ) {
			ConsentScreen::render();

			return;
		}

		if ( empty( $vars['templately_mcp'] ) ) {
			return;
		}

		// POST only. This path bypasses the REST server's own method routing, so
		// without an explicit check a GET navigation reached the handler.
		if ( 'POST' !== strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) ) {
			$this->emit_error(
				new WP_Error(
					'method_not_allowed',
					__( 'This MCP endpoint accepts POST only.', 'templately' ),
					[ 'status' => 405 ]
				)
			);
		}

		$request = new WP_REST_Request( 'POST', '/' . self::NAMESPACE . self::ROUTE );

		// The REAL content type, not a hardcoded one. Hardcoding it made
		// authorize()'s content-type gate — documented above as step 2 of a
		// load-bearing order — a no-op on this route: it could never observe
		// anything but application/json, so the whole check was dead code here
		// and cross-origin protection rested on the Origin check alone.
		$request->set_header(
			'content-type',
			isset( $_SERVER['CONTENT_TYPE'] )
				? sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) )
				: ''
		);

		foreach ( [ 'authorization', 'origin' ] as $header ) {
			$key = 'HTTP_' . strtoupper( str_replace( '-', '_', $header ) );

			if ( ! empty( $_SERVER[ $key ] ) ) {
				$request->set_header( $header, sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) );
			}
		}

		$request->set_body( file_get_contents( 'php://input' ) );

		$permission = $this->authorize( $request );

		if ( is_wp_error( $permission ) ) {
			$this->emit_error( $permission );
		}

		$response = $this->handle( $request );

		status_header( $response->get_status() );
		header( 'Content-Type: application/json; charset=utf-8' );
		nocache_headers();

		$data = $response->get_data();

		if ( null !== $data ) {
			echo wp_json_encode( $data );
		}

		exit;
	}

	/**
	 * @param WP_Error $error
	 * @return void
	 */
	private function emit_error( WP_Error $error ): void {
		$data   = $error->get_error_data();
		$status = (int) ( $data['status'] ?? 401 );

		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );

		if ( ! empty( $data['www_authenticate'] ) ) {
			header( 'WWW-Authenticate: ' . $data['www_authenticate'] );
		}

		if ( ! empty( $data['retry_after'] ) ) {
			header( 'Retry-After: ' . (int) $data['retry_after'] );
		}

		echo wp_json_encode(
			JsonRpc::error( null, JsonRpc::UNAUTHORIZED, $error->get_error_message() )
		);

		exit;
	}
}
