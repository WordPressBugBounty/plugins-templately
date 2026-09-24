<?php
/**
 * Templately's template-library agent capabilities (specs 041, 046).
 *
 * This class does two things and nothing else:
 *
 *  1. Hands this module's ability classes to the shared registry in mcp-core.
 *  2. Owns the Google-OAuth api-key handoff page, which is a concern of
 *     {@see Abilities\AuthLoginWithGoogleAbility} specifically.
 *
 * It used to also decide whether MCP was available at all, register the
 * WordPress Abilities API category/abilities, and create the mcp-adapter server.
 * Since 046 the registry is the source of truth and those consumers are optional
 * bridges living in `modules/wp-abilities-api/`, so none of that belongs here —
 * and critically, none of it gates anything: the native server in
 * `modules/mcp-server/` serves these capabilities on any supported WordPress,
 * with or without the Abilities API (core 6.9+) or the mcp-adapter plugin.
 * Templately supports WordPress 5.0+, so before 046 every capability here was
 * unreachable on the large majority of sites running this plugin.
 *
 * @package Templately\Modules\McpAbilities
 */

namespace Templately\Modules\McpAbilities;

use Templately\Modules\McpAbilities\Abilities\AuthLoginWithApiKeyAbility;
use Templately\Modules\McpAbilities\Abilities\AuthLoginWithGoogleAbility;
use Templately\Modules\McpAbilities\Abilities\AuthLogoutAbility;
use Templately\Modules\McpAbilities\Abilities\AuthStatusAbility;
use Templately\Modules\McpAbilities\Abilities\DiscoverTemplatesAbility;
use Templately\Modules\McpAbilities\Abilities\GetTemplateDetailAbility;
use Templately\Modules\McpAbilities\Abilities\ImportTemplateIntoPostAbility;
use Templately\Modules\McpAbilities\Abilities\ImportTemplateLibraryAbility;
use Templately\Modules\McpAbilities\Abilities\ImportTemplatePageAbility;
use Templately\Modules\McpCore\Registry\ToolRegistry;
use Templately\Utils\Base;

class MCP extends Base {

	/**
	 * The ONE list. Adding a capability to this module means adding its class
	 * here and nothing else (FR-011) — this replaced the two hand-maintained
	 * lists (`ABILITY_IDS` and `register_abilities()`) that previously had to be
	 * edited in step, and which a third and fourth consumer would have doubled
	 * again.
	 *
	 * @var string[]
	 */
	const ABILITY_CLASSES = [
		DiscoverTemplatesAbility::class,
		GetTemplateDetailAbility::class,
		ImportTemplatePageAbility::class,
		ImportTemplateLibraryAbility::class,
		ImportTemplateIntoPostAbility::class,
		AuthStatusAbility::class,
		AuthLoginWithApiKeyAbility::class,
		AuthLoginWithGoogleAbility::class,
		AuthLogoutAbility::class,
	];

	/**
	 * Transient key prefix marking an MCP-initiated Google OAuth login as
	 * in-flight (see intercept_mcp_oauth_callback()). Keyed by the token
	 * AuthLoginWithGoogleAbility::execute() generates and passes as
	 * Http::google_auth_url()'s `$redirect_to` param — NOT the URL's own
	 * `state` param, which templately-backend's own docs confirm is cached
	 * server-side for the Google round-trip only and never echoed back to
	 * the site (see this module's CLAUDE.md).
	 */
	const OAUTH_TOKEN_TRANSIENT_PREFIX = 'templately_mcp_oauth_';

	public function __construct() {
		add_action( ToolRegistry::COLLECT_ACTION, [ $this, 'register_capabilities' ] );

		// Priority 5 — must run BEFORE Plugin::google_login_handler() (priority
		// 10, registered in Plugin.php's constructor) so an MCP-initiated
		// login's api_key is intercepted and shown to the user instead of
		// being auto-consumed by that handler.
		add_action( 'init', [ $this, 'intercept_mcp_oauth_callback' ], 5 );
	}

	/**
	 * Contribute this module's capabilities to the shared registry.
	 *
	 * Hooked rather than called directly so the registry can rebuild itself from
	 * every contributor at any time (see ToolRegistry::COLLECT_ACTION). Hands over
	 * CLASS NAMES only — descriptors resolve lazily on first use. Resolving them
	 * eagerly would call each descriptor's `__()` during `plugins_loaded`, i.e.
	 * before `init` loads the textdomain, which makes WP 6.7+ emit a "textdomain
	 * triggered too early" notice. Under WP_DEBUG_DISPLAY that notice is echoed
	 * during bootstrap and every later REST error status collapses to 200,
	 * because headers are already sent. See ToolRegistry::$pending_classes.
	 *
	 * @param ToolRegistry $registry
	 * @return void
	 */
	public function register_capabilities( $registry ): void {
		$registry->register_classes( self::ABILITY_CLASSES );
	}

	/**
	 * Ability IDs this module contributes. Derived from the descriptors so it
	 * can never drift from ABILITY_CLASSES.
	 *
	 * @return string[]
	 */
	public static function ability_ids(): array {
		$registry = ToolRegistry::get_instance();
		$ids      = [];

		foreach ( self::ABILITY_CLASSES as $class ) {
			$descriptor = $registry->get( $class::ID );

			if ( null !== $descriptor ) {
				$ids[] = $descriptor->id;
			}
		}

		return $ids;
	}

	/**
	 * Validate an MCP OAuth token's format before it is ever used to build
	 * a transient key or read back from `$_GET['redirect-to']`. Matches
	 * wp_generate_password(32, false)'s charset (alphanumeric only) — the
	 * exact generator AuthLoginWithGoogleAbility::execute() uses. This also
	 * happens to be what distinguishes our token from a genuine
	 * `redirect-to` value (always path/URL-shaped, never a bare 32-char
	 * alnum string) — see intercept_mcp_oauth_callback(). Raw, unvalidated
	 * request data must never be interpolated into an option/transient key.
	 *
	 * @param mixed $token
	 * @return bool
	 */
	public static function is_valid_oauth_state( $token ): bool {
		return is_string( $token ) && 1 === preg_match( '/^[A-Za-z0-9]{32}$/', $token );
	}

	/**
	 * Intercept an MCP-initiated Google OAuth callback and show the raw
	 * `api_key` directly to the user instead of letting
	 * Plugin::google_login_handler() (priority 10, same `init` hook)
	 * auto-consume it via Login::login().
	 *
	 * `templately/auth-login-with-google` (AuthLoginWithGoogleAbility) runs
	 * headlessly — there is no browser cookie session at URL-generation
	 * time to attribute a connection to, and the browser completing the
	 * OAuth redirect may not be logged into wp-admin either. Rather than
	 * trying to bridge attribution into that ambient session (the previous
	 * approach here — see git history / this module's CLAUDE.md), this instead
	 * skips Plugin::google_login_handler() entirely for MCP-initiated logins and
	 * shows the api_key on-screen for the user to copy back to the agent,
	 * which then calls the ALREADY-EXISTING `templately/auth-login-with-api-key`
	 * ability in its own genuinely-authenticated MCP session — where
	 * get_current_user_id() resolves correctly with no bridging needed at
	 * all. templately-backend's own docs confirm the api_key returned here
	 * is the same permanent, reusable API key `connectWithApiKey` expects
	 * (not a one-time exchange token) — see this module's CLAUDE.md.
	 *
	 * AuthLoginWithGoogleAbility::execute() marks a login as MCP-initiated
	 * via a one-time, 5-minute transient keyed by a token it generates
	 * itself and passes as `google_auth_url()`'s `$redirect_to` param,
	 * which gets folded into the `site_url` sent to app.templately.com.
	 * Live testing (and templately-backend's own docs) confirm `site_url`'s
	 * contents come back intact on redirect, unlike the URL's own separate
	 * `state` param (cached server-side for the Google round-trip only,
	 * never echoed back to the site — do not key off that).
	 *
	 * When the browser lands back on this site with `templately_google_login`
	 * + `redirect-to` matching our token format AND a live transient, this
	 * callback (priority 5, before Plugin's priority 10) renders the
	 * api_key/error page and exits — Plugin::google_login_handler() never
	 * runs at all for this request. No-ops silently (leaving `$_GET`
	 * untouched, letting Plugin's handler run as normal) when `redirect-to`
	 * isn't present, doesn't match the token format, or has no matching
	 * transient — the normal case for a human clicking "Connect" from an
	 * already-authenticated wp-admin session, or a genuine editor-path
	 * redirect. This must never change behavior for those existing flows.
	 *
	 * @return void
	 */
	public function intercept_mcp_oauth_callback(): void {
		$token = self::find_mcp_oauth_token();

		if ( null === $token ) {
			return;
		}

		// One-time use — replay protection.
		delete_transient( self::OAUTH_TOKEN_TRANSIENT_PREFIX . $token );

		$api_key = ! empty( $_GET['api_key'] ) ? sanitize_text_field( wp_unslash( $_GET['api_key'] ) ) : '';
		$error   = ! empty( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		$this->render_mcp_oauth_key_page( $api_key, $error );
		exit;
	}

	/**
	 * Whether the current request is an MCP-initiated OAuth callback with a
	 * still-live transient, and if so, the token itself. Split out from
	 * intercept_mcp_oauth_callback() — a pure read-only check (no
	 * transient deletion, no rendering, no exit) so it unit-tests cleanly.
	 * Returns null for: a non-OAuth-callback request, a missing/malformed
	 * `redirect-to` (including a genuine path-shaped one — never matches
	 * the 32-char alnum format), or an expired/already-consumed transient.
	 *
	 * @return string|null
	 */
	public static function find_mcp_oauth_token(): ?string {
		if ( empty( $_GET['templately_google_login'] ) || empty( $_GET['redirect-to'] ) ) {
			return null;
		}

		$token = sanitize_text_field( wp_unslash( $_GET['redirect-to'] ) );

		if ( ! self::is_valid_oauth_state( $token ) ) {
			return null;
		}

		if ( empty( get_transient( self::OAUTH_TOKEN_TRANSIENT_PREFIX . $token ) ) ) {
			return null;
		}

		return $token;
	}

	/**
	 * Render a minimal, standalone HTML page (no wp-admin chrome needed —
	 * this never depends on any wp-admin session) showing the api_key for
	 * the user to copy back to the agent, or the error if Google/Templately
	 * sign-in failed. Not unit-tested (headers + exit side effects); the
	 * decision to call it lives in intercept_mcp_oauth_callback(), which is.
	 *
	 * @param string $api_key
	 * @param string $error
	 * @return void
	 */
	private function render_mcp_oauth_key_page( string $api_key, string $error ): void {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		if ( '' !== $api_key ) {
			$title = __( 'Templately Sign-In Complete', 'templately' );
			$body  = sprintf(
				'<p>%1$s</p><p style="font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:1.1em;background:#f0f0f1;padding:12px 16px;border-radius:6px;display:inline-block;word-break:break-all;">%2$s</p><p>%3$s</p>',
				esc_html__( 'Copy this key and give it to the agent to finish connecting:', 'templately' ),
				esc_html( $api_key ),
				esc_html__( 'You can close this tab afterward.', 'templately' )
			);
		} else {
			$title   = __( 'Templately Sign-In Failed', 'templately' );
			$message = '' !== $error
				? sprintf(
					/* translators: %s: error returned by Google/Templately */
					__( 'Sign-in failed: %s', 'templately' ),
					$error
				)
				: __( 'Sign-in failed. Please try again.', 'templately' );
			$body    = sprintf( '<p>%s</p>', esc_html( $message ) );
		}

		printf(
			'<!DOCTYPE html><html><head><meta charset="utf-8"><title>%1$s</title></head><body style="font-family:-apple-system,BlinkMacSystemFont,sans-serif;text-align:center;padding:80px 20px;"><h1>%1$s</h1>%2$s</body></html>',
			esc_html( $title ),
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $body is built above as sprintf('<p>%s</p>', esc_html($message)).
			$body
		);
	}
}
