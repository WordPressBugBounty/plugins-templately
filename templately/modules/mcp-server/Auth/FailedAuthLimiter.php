<?php
/**
 * Per-source lockout after repeated failed authentication (spec 044, FR-040).
 *
 * Checked BEFORE any secret comparison, so a locked source never reaches the
 * comparison at all — it cannot be used as a timing or existence oracle.
 *
 * ## The forwarded-header decision
 *
 * The client address is taken from the connection only. `X-Forwarded-For` and
 * friends are attacker-controlled: trusting them would let an attacker both
 * evade their own limit (rotate the header) and lock out a victim (spoof theirs).
 *
 * ## Known limitation, accepted
 *
 * Transient-backed, so on a site with no persistent object cache this is
 * best-effort across PHP workers. A single address bucket is also evadable from
 * many addresses and over-broad behind an unconfigured CDN where many visitors
 * share one address. This is a throttle against opportunistic abuse; the
 * credential's own 256 bits of entropy is the actual control.
 *
 * @package Templately\Modules\McpServer\Auth
 */

namespace Templately\Modules\McpServer\Auth;

class FailedAuthLimiter {

	const PREFIX      = 'templately_mcp_rl_';
	const MAX_FAILS   = 10;
	const LOCKOUT     = 900;

	/** Credential presentation on the MCP endpoint. */
	const BUCKET_AUTH = 'auth';

	/** The public OAuth registration/token endpoints. */
	const BUCKET_OAUTH = 'oauth';

	/**
	 * Successful client registrations — a QUOTA, not a strike count.
	 *
	 * Separate from BUCKET_OAUTH and much larger, because the two measure
	 * different things. A failure is evidence of abuse; a successful
	 * registration is ordinary behaviour that merely needs a ceiling, and
	 * clients like Codex register afresh on every login attempt. Charging both
	 * to the same 10-strike bucket meant a handful of legitimate reconnections
	 * locked the user out of their own OAuth endpoints for 15 minutes.
	 */
	const BUCKET_REGISTER = 'register';

	/** Per-bucket ceilings; anything unlisted uses MAX_FAILS. */
	const BUCKET_MAX = [
		self::BUCKET_REGISTER => 60,
	];

	/**
	 * @param string $bucket
	 * @return bool
	 */
	public static function is_locked( string $bucket = self::BUCKET_AUTH ): bool {
		return self::count( $bucket ) >= self::max_fails( $bucket );
	}

	/**
	 * Seconds until the lockout lapses — emitted as `Retry-After` (FR-040).
	 * The reference implementation computes this and never sends it.
	 *
	 * @return int
	 */
	public static function retry_after( string $bucket = self::BUCKET_AUTH ): int {
		$remaining = self::state( $bucket )['until'] - time();

		return $remaining > 0 ? $remaining : self::lockout_seconds();
	}

	/**
	 * Record a failure WITHOUT extending an existing window.
	 *
	 * `set_transient` resets the TTL on every write, so re-arming it per failure
	 * let one caller hold a lockout open indefinitely at roughly a request a
	 * minute. The window runs from the FIRST failure and lapses on schedule.
	 *
	 * @param string $bucket
	 * @return void
	 */
	public static function record_failure( string $bucket = self::BUCKET_AUTH ): void {
		$state = self::state( $bucket );
		$now   = time();

		// Keep the window opened by the first failure; only start a new one when
		// the previous has lapsed.
		$until = $state['until'] > $now ? $state['until'] : $now + self::lockout_seconds();

		set_transient(
			self::key( $bucket ),
			[
				'count' => $state['count'] + 1,
				'until' => $until,
			],
			max( 1, $until - $now )
		);
	}

	public static function clear( string $bucket = self::BUCKET_AUTH ): void {
		delete_transient( self::key( $bucket ) );
	}

	/**
	 * @param string $bucket
	 * @return int
	 */
	public static function count( string $bucket = self::BUCKET_AUTH ): int {
		return self::state( $bucket )['count'];
	}

	/**
	 * The bucket's `{count, until}`, with the window expiry carried INSIDE the
	 * transient value rather than read back off WordPress's timeout row.
	 *
	 * `_transient_timeout_<key>` is an implementation detail of the DATABASE
	 * backend only: with a persistent object cache installed, `set_transient()`
	 * stores value + TTL in the cache and writes no option rows at all. Reading
	 * the timeout row there always missed, which silently undid both behaviours
	 * that depend on knowing when the window started — `retry_after()` always
	 * reported the full window instead of the remaining one, and
	 * `record_failure()` treated every failure as the first, re-arming a fresh
	 * 15 minutes each time. That is the exact indefinite-lockout bug the
	 * "without extending an existing window" note above says was fixed; it was
	 * fixed only on sites with no object cache.
	 *
	 * A bare integer is still accepted so a bucket written by the previous
	 * format keeps counting instead of resetting to zero mid-window.
	 *
	 * @param string $bucket
	 * @return array{count:int,until:int}
	 */
	private static function state( string $bucket ): array {
		$raw = get_transient( self::key( $bucket ) );

		if ( is_array( $raw ) ) {
			return [
				'count' => (int) ( $raw['count'] ?? 0 ),
				'until' => (int) ( $raw['until'] ?? 0 ),
			];
		}

		return [
			'count' => (int) $raw,
			'until' => 0,
		];
	}

	/**
	 * Buckets are SEPARATE per endpoint class on purpose. With one shared
	 * bucket, ten malformed registrations — unauthenticated, free to send —
	 * locked out bearer authentication for every legitimate agent as well.
	 * Abuse of the public OAuth endpoints must not deny the credentialed one.
	 *
	 * @param string $bucket
	 * @return string
	 */
	private static function key( string $bucket = self::BUCKET_AUTH ): string {
		return self::PREFIX . $bucket . '_' . self::key_suffix();
	}

	private static function key_suffix(): string {
		return md5( self::client_address() );
	}

	/**
	 * Connection-level address by default — never a forwarded header, because a
	 * forwarded header is attacker-controlled and trusting it blindly lets an
	 * attacker both evade their own limit and lock out a victim.
	 *
	 * BUT behind a reverse proxy or CDN that does not rewrite REMOTE_ADDR, every
	 * visitor shares one bucket, which turns the limiter into a self-inflicted
	 * denial of service. Such a site can opt in — deliberately, with its own
	 * knowledge of which hop is trustworthy — via this filter.
	 *
	 * @return string
	 */
	private static function client_address(): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: 'unknown';

		/**
		 * Resolve the real client address behind a trusted proxy.
		 *
		 * Return the connection address unchanged to keep the safe default. A
		 * site returning a forwarded header here is asserting that it strips and
		 * re-adds that header at a trusted edge.
		 *
		 * @param string $address Connection-level address.
		 */
		$filtered = apply_filters( 'templately_mcp_client_address', $address );

		return is_string( $filtered ) && '' !== $filtered ? $filtered : $address;
	}

	/**
	 * @return int
	 */
	private static function max_fails( string $bucket = self::BUCKET_AUTH ): int {
		$default = self::BUCKET_MAX[ $bucket ] ?? self::MAX_FAILS;

		$max = ( self::BUCKET_AUTH === $bucket && defined( 'TEMPLATELY_MCP_MAX_AUTH_FAILS' ) )
			? (int) TEMPLATELY_MCP_MAX_AUTH_FAILS
			: $default;

		return (int) apply_filters( 'templately_mcp_max_auth_fails', max( 1, $max ), $bucket );
	}

	/**
	 * @return int
	 */
	private static function lockout_seconds(): int {
		$seconds = defined( 'TEMPLATELY_MCP_LOCKOUT_SECONDS' ) ? (int) TEMPLATELY_MCP_LOCKOUT_SECONDS : self::LOCKOUT;

		return (int) apply_filters( 'templately_mcp_lockout_seconds', max( 1, $seconds ) );
	}
}
