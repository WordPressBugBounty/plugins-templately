<?php
/**
 * `templately/auth-login-with-google` — get the URL to connect this site to
 * a Templately account via Google sign-in (spec 041-mcp-abilities, Auth
 * Tools addendum).
 *
 * `Http::google_auth_url()` is a pure URL builder with no session/Options
 * dependency, so it is safe to call directly (no singleton-caching gotcha
 * applies here, unlike auth-status/auth-logout). Completing the sign-in
 * itself requires a real browser — an agent cannot finish an OAuth flow
 * headlessly, so this ability only starts it and hands back the URL.
 *
 * Manual key handoff, not auto-attribution: this call happens headlessly
 * (no browser cookie session), and the browser that later completes the
 * Google redirect may not be logged into wp-admin either — so there's no
 * ambient session to attribute a connection to automatically. Rather than
 * bridging attribution into that session (an earlier approach here — see
 * git history / MCP/CLAUDE.md), the flow instead marks this login as
 * MCP-initiated via a one-time, 5-minute transient keyed by a
 * self-generated token, and `MCP::intercept_mcp_oauth_callback()`
 * (registered on `init` at priority 5, in MCP.php) shows the resulting
 * `api_key` directly to the user on callback instead of letting
 * Plugin::google_login_handler() auto-consume it. The user copies that key
 * back to the agent, which then calls the existing
 * `templately/auth-login-with-api-key` ability in its own genuinely
 * authenticated MCP session — no attribution bridging needed there at all.
 *
 * The token travels via `google_auth_url()`'s `$redirect_to` param, NOT the
 * URL's own `state` param: templately-backend's own docs confirm `state` is
 * cached server-side only for the Google round-trip and never echoed back
 * to the site, while `site_url` (which `redirect_to` folds into) comes back
 * intact — confirmed live too. `MCP::intercept_mcp_oauth_callback()` reads
 * the token from `$_GET['redirect-to']` — that same key is read by
 * `Plugin::google_login_handler()` (priority 10) for its own unrelated
 * "reopen this editor path after login" purpose, but for an MCP-initiated
 * login that handler never runs at all (the interceptor exits first).
 *
 * @package Templately\Modules\McpAbilities\Abilities
 */

namespace Templately\Modules\McpAbilities\Abilities;

use Templately\Modules\McpAbilities\MCP;
use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\McpCore\Support\Permissions;
use Templately\Utils\Http;

class AuthLoginWithGoogleAbility {

	const ID = 'templately/auth-login-with-google';

	public static function descriptor(): array {
		return [
			'id'                  => self::ID,
			'label'               => __( 'Start Templately Google Sign-In', 'templately' ),
			'description'         => __( 'Get the URL to connect this site to a Templately account via Google sign-in. Completing sign-in requires opening the URL in a real browser.', 'templately' ),
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => (object) [],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type' => 'object',
			],
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => [ Permissions::class, 'can_use_abilities' ],
			'access_level'        => ToolDescriptor::ACCESS_FULL,
			'annotations'         => [ 'readonly' => true, 'destructive' => false, 'idempotent' => false ],
		];
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public static function execute( array $input ): array {
		// Self-generated — guaranteed to already match MCP::is_valid_oauth_state()'s
		// format, so no extraction/validation round-trip is needed here.
		$token = wp_generate_password( 32, false );

		$url = Http::get_instance()->google_auth_url( $token );

		// Marker only — no user id needed. Attribution happens later, when
		// the user pastes the api_key back for an auth-login-with-api-key
		// call in this agent's own authenticated session.
		set_transient(
			MCP::OAUTH_TOKEN_TRANSIENT_PREFIX . $token,
			true,
			5 * MINUTE_IN_SECONDS
		);

		return [
			'url'          => $url,
			'instructions' => __( 'Open this URL in a browser and complete Google sign-in. Instead of connecting automatically, the page will show you an API key — copy it and give it to the agent, which will call templately-auth-login-with-api-key to finish connecting this site.', 'templately' ),
		];
	}
}
