<?php
/**
 * Connection management for the built-in MCP server (spec 046, FR-020, FR-039c,
 * FR-039g, FR-046).
 *
 * Mints, lists, re-scopes and revokes the credentials an agent presents to the
 * built-in server, and reports the delegated (OAuth) connections alongside them
 * so one screen answers "who can reach this site".
 *
 * Named `Connections` (plural) rather than `Connection`: `wp-abilities-api` owns
 * a `REST/Connection.php` too, and the two are unrelated — that one onboards the
 * separately-installed mcp-adapter plugin (installs it, mints an Application
 * Password); this one manages THIS server's own credential list. Distinct
 * namespaces would keep them from colliding in PHP, but not in a reader's head.
 *
 * No new admin screen is introduced — the tab attaches to the EXISTING settings
 * page through its tab-injection filter.
 *
 * @package Templately\Modules\McpServer\REST
 */

namespace Templately\Modules\McpServer\REST;

use Templately\Modules\McpCore\Activity\ActivityLog;
use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\McpCore\Registry\ToolRegistry;
use Templately\Modules\McpServer\Auth\Credentials;
use Templately\Modules\McpServer\Auth\OAuth\RecordStore;
use Templately\Modules\McpServer\Server\HttpTransport;
use Templately\Utils\Base;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Connections extends Base {

	public function __construct() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_filter( 'templately_admin_localized_data', [ $this, 'inject_localized_data' ] );
	}

	public function register_routes(): void {
		$base = '/mcp/connection';

		register_rest_route(
			HttpTransport::NAMESPACE,
			$base,
			[
				[
					'methods'             => 'GET',
					'callback'            => [ $this, 'get_connection' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'create_credential' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
			]
		);

		register_rest_route(
			HttpTransport::NAMESPACE,
			$base . '/(?P<id>[A-Za-z0-9_]+)',
			[
				[
					'methods'             => 'POST',
					'callback'            => [ $this, 'update_credential' ],
					'permission_callback' => [ $this, 'can_manage' ],
					// REJECT an absent or misspelled level rather than coercing it.
					// `Credentials::normalize_level()` resolves anything that is not
					// exactly "read" to FULL — correct for a token an administrator
					// is deliberately minting, dangerous here, where the same call
					// now also edits a DELEGATED grant. Without this, a retried or
					// malformed POST silently promotes a read-only agent connection
					// to full write access and answers 200 as if that were intended.
					'args'                => [
						'access_level' => [
							'required' => true,
							'type'     => 'string',
							'enum'     => [ ToolDescriptor::ACCESS_READ, ToolDescriptor::ACCESS_FULL ],
						],
					],
				],
				[
					'methods'             => 'DELETE',
					'callback'            => [ $this, 'revoke_credential' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
			]
		);

		register_rest_route(
			HttpTransport::NAMESPACE,
			$base . '/revoke-all',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'revoke_all' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);
	}

	/**
	 * Administrator OF THIS SITE — never network administrator (FR-039c), so a
	 * site owner on multisite can manage their own connection.
	 *
	 * @return bool
	 */
	public function can_manage(): bool {
		return is_user_logged_in() && current_user_can( 'manage_options' );
	}

	/**
	 * @return WP_REST_Response
	 */
	public function get_connection(): WP_REST_Response {
		return new WP_REST_Response(
			[
				'endpoint'          => rest_url( HttpTransport::NAMESPACE . '/mcp' ),
				'pretty_url'        => home_url( '/templately/mcp' ),
				'connected'         => Credentials::site_has_any(),
				'credentials'       => self::all_connections(),
				'activity'          => ActivityLog::recent( 25 ),
				'tools'             => self::tool_summary(),
				// Everything this server serves through a REWRITE — OAuth discovery,
				// the approval screen, the pretty endpoint alias — is unreachable
				// while permalinks are plain, because no rewrite rule runs at all.
				// The bearer-credential flow below is unaffected (the REST route is
				// still reachable as `?rest_route=`), so the tab warns instead of
				// blocking. Without this an administrator on a default-permalink
				// site sees a connect flow that simply never completes, with nothing
				// on screen explaining why.
				'pretty_permalinks' => '' !== (string) get_option( 'permalink_structure' ),
			],
			200
		);
	}

	/**
	 * Every connection this site holds, from BOTH credential systems.
	 *
	 * There are two: pairing tokens an administrator creates here
	 * (`Credentials`), and delegated grants an application obtains through
	 * OAuth (`RecordStore`). The list previously showed only the first, so a
	 * site connected the way ChatGPT and Claude connect — the URL-only flow this
	 * whole OAuth surface exists to serve — reported "No connections yet" while
	 * an agent was actively using it. Nothing was wrong with the connection; it
	 * was simply invisible, and therefore un-revokable from the UI.
	 *
	 * Pairing tokens carry `source: 'token'` and delegated grants
	 * `source: 'oauth'`, which is what the table keys its per-row affordances off.
	 *
	 * @return array
	 */
	private static function all_connections(): array {
		$tokens = array_map(
			static function ( $record ) {
				$record['source'] = 'token';

				return $record;
			},
			Credentials::list_public()
		);

		return array_merge( $tokens, RecordStore::list_connections() );
	}

	/**
	 * The capabilities an agent can call, for the Settings → AI Agents "Tools"
	 * view. Read from the registry so it can never drift from what the server
	 * actually serves (FR-010).
	 *
	 * Exposes `access_level` — the security control — NOT the advisory
	 * `annotations.readonly`, which can legitimately disagree with it (see
	 * ToolDescriptor). Showing the annotation here would tell an administrator
	 * that `auth-login-with-google` is read-only when a read-only credential is
	 * in fact refused it.
	 *
	 * @return array
	 */
	private static function tool_summary(): array {
		$tools = [];

		foreach ( ToolRegistry::get_instance()->all() as $descriptor ) {
			$tools[] = [
				'id'           => $descriptor->id,
				'label'        => $descriptor->label,
				'description'  => $descriptor->description,
				'access_level' => $descriptor->access_level,
			];
		}

		return $tools;
	}

	/**
	 * The ONLY moment the secret exists (FR-021). It is never returned again.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public function create_credential( WP_REST_Request $request ): WP_REST_Response {
		$name  = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$level = Credentials::normalize_level( (string) $request->get_param( 'access_level' ) );

		$created = Credentials::create( $name, get_current_user_id(), $level );

		return new WP_REST_Response(
			[
				'id'          => $created['id'],
				// Shown once, then unrecoverable — only its hash is stored.
				'secret'      => $created['secret'],
				'credentials' => self::all_connections(),
			],
			201
		);
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_credential( WP_REST_Request $request ) {
		$id    = (string) $request->get_param( 'id' );
		$level = Credentials::normalize_level( (string) $request->get_param( 'access_level' ) );

		// A delegated grant is edited in its own store — and can be widened to
		// full access from here WITHOUT the user disconnecting and re-approving,
		// which is otherwise the only remedy when a client asks for read only.
		$client_id = RecordStore::client_id_from_public_id( $id );

		$updated = '' !== $client_id
			? RecordStore::set_client_access_level( $client_id, $level )
			: Credentials::set_access_level( $id, $level );

		if ( ! $updated ) {
			return new WP_Error( 'not_found', __( 'Connection not found.', 'templately' ), [ 'status' => 404 ] );
		}

		return new WP_REST_Response( [ 'credentials' => self::all_connections() ], 200 );
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function revoke_credential( WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'id' );

		$client_id = RecordStore::client_id_from_public_id( $id );

		$revoked = '' !== $client_id
			? RecordStore::revoke_client( $client_id )
			: Credentials::revoke( $id );

		if ( ! $revoked ) {
			return new WP_Error( 'not_found', __( 'Connection not found.', 'templately' ), [ 'status' => 404 ] );
		}

		return new WP_REST_Response( [ 'credentials' => self::all_connections() ], 200 );
	}

	/**
	 * @return WP_REST_Response
	 */
	public function revoke_all(): WP_REST_Response {
		Credentials::revoke_all();

		return new WP_REST_Response(
			[
				'credentials' => [],
				'connected'   => false,
			],
			200
		);
	}

	/**
	 * Tell the settings SPA the tab exists and where the endpoint lives.
	 *
	 * Keyed `mcpServer`, NOT `mcp`: `wp-abilities-api`'s own Connection class
	 * writes `mcp` for the adapter-onboarding tab, and whichever filter ran last
	 * would otherwise silently overwrite the other — leaving one of the two tabs
	 * permanently unregistered, since each bundle self-gates on its own key.
	 *
	 * @param array $data
	 * @return array
	 */
	public function inject_localized_data( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$data['mcpServer'] = [
			'endpoint'      => rest_url( HttpTransport::NAMESPACE . '/mcp' ),
			'connected'     => Credentials::site_has_any(),
			'accessLevels'  => [
				ToolDescriptor::ACCESS_READ => __( 'Read-only', 'templately' ),
				ToolDescriptor::ACCESS_FULL => __( 'Full access', 'templately' ),
			],
		];

		return $data;
	}
}
