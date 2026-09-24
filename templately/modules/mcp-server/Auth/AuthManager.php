<?php
/**
 * Resolves a presented credential to an acting WordPress user + access level
 * (spec 044, FR-016–FR-019, FR-025).
 *
 * ## Why identity is established here and not in a global filter
 *
 * This runs from the route's `permission_callback`, which fires before the
 * route callback — so `wp_set_current_user()` lands before any capability's own
 * permission rule is evaluated and before any work begins (FR-016). A global
 * `determine_current_user` filter would also work but would run on EVERY request
 * to the site to serve one route.
 *
 * The ordering is load-bearing well beyond the permission checks: 042's
 * Support\AjaxLoopbackDispatcher mints its FSI cookie session from
 * `get_current_user_id()` (AjaxLoopbackDispatcher.php:110). If the acting user
 * were not established by then, full-site import could not start at all — which
 * is why the reference implementation's model (tokens with no user) could not
 * simply be copied.
 *
 * @package Templately\Modules\McpServer\Auth
 */

namespace Templately\Modules\McpServer\Auth;

use Templately\Modules\McpServer\Auth\OAuth\RecordStore;
use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\McpCore\Support\Permissions;

class AuthManager {

	/** @var array|null Resolved context for this request. */
	private static $context = null;

	/**
	 * Resolve the request's credential.
	 *
	 * @return array|null {credential_id, user_id, access_level} or null.
	 */
	public static function resolve(): ?array {
		if ( null !== self::$context ) {
			return self::$context;
		}

		$secret = self::bearer_token();

		if ( '' !== $secret ) {
			$context = self::resolve_secret( $secret );

			if ( null === $context ) {
				FailedAuthLimiter::record_failure();

				return null;
			}

			FailedAuthLimiter::clear();
			wp_set_current_user( $context['user_id'] );
			self::$context = $context;

			return $context;
		}

		// No bearer credential — fall back to whatever core already
		// authenticated (Application Passwords keep working unchanged, FR-025).
		$user_id = get_current_user_id();

		if ( $user_id > 0 && Permissions::can_use_abilities() ) {
			self::$context = [
				'credential_id' => null,
				'user_id'       => $user_id,
				'access_level'  => ToolDescriptor::ACCESS_FULL,
			];

			return self::$context;
		}

		return null;
	}

	/**
	 * Map a secret to a context, checking direct credentials then delegated ones.
	 *
	 * @param string $secret
	 * @return array|null
	 */
	private static function resolve_secret( string $secret ): ?array {
		$record = Credentials::find_by_secret( $secret );

		if ( null !== $record ) {
			if ( ! self::user_still_eligible( (int) $record['user_id'] ) ) {
				return null;
			}

			Credentials::touch( (string) $record['id'] );

			return [
				'credential_id' => (string) $record['id'],
				'user_id'       => (int) $record['user_id'],
				'access_level'  => Credentials::normalize_level( (string) $record['access_level'] ),
			];
		}

		if ( ! class_exists( RecordStore::class ) ) {
			return null;
		}

		$issued = RecordStore::find_access_token( $secret );

		if ( null === $issued || ! self::user_still_eligible( (int) $issued['user_id'] ) ) {
			return null;
		}

		return [
			'credential_id' => (string) ( $issued['client_id'] ?? 'oauth' ),
			'user_id'       => (int) $issued['user_id'],
			'access_level'  => Credentials::normalize_level( (string) $issued['access_level'] ),
		];
	}

	/**
	 * A credential stops granting access the moment its bound account is deleted
	 * or drops below the required capability (FR-018) — the credential itself
	 * need not be revoked for access to end.
	 *
	 * @param int $user_id
	 * @return bool
	 */
	private static function user_still_eligible( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			return false;
		}

		return user_can( $user, 'manage_options' );
	}

	/**
	 * Header only — never a path or query parameter (FR-022). A bearer secret in
	 * a URL lands in access logs, proxy logs, browser history and `Referer`.
	 *
	 * @return string
	 */
	private static function bearer_token(): string {
		$header = '';

		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			// Apache strips Authorization unless explicitly passed through.
			$header = sanitize_text_field( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}

		if ( '' === $header || 0 !== stripos( $header, 'bearer ' ) ) {
			return '';
		}

		return trim( substr( $header, 7 ) );
	}

	/**
	 * The WWW-Authenticate challenge — how a URL-only client discovers where to
	 * authenticate (FR-029).
	 *
	 * @return string
	 */
	public static function challenge_header(): string {
		// The RESOURCE-SPECIFIC metadata URL (RFC 9728 §3.1: the resource's path
		// is appended to the well-known prefix), not the bare one.
		//
		// This header is the authoritative pointer — it is how a client that
		// holds only a site address finds the auth flow, and it is followed in
		// preference to guessing. Aiming it at the bare path aimed clients at a
		// slot shared with every other OAuth-serving plugin on the site, which is
		// how ChatGPT ended up being handed a different plugin's authorization
		// endpoint for Templately's own resource.
		$path = (string) wp_parse_url( RecordStore::audience(), PHP_URL_PATH );

		return sprintf(
			'Bearer resource_metadata="%s"',
			esc_url_raw( home_url( '/.well-known/oauth-protected-resource' . $path ) )
		);
	}

	/**
	 * Test seam.
	 */
	public static function reset(): void {
		self::$context = null;
	}
}
