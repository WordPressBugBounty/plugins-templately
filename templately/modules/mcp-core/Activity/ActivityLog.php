<?php
/**
 * Record of what an agent CHANGED on this site (spec 044, FR-039d–h).
 *
 * Answers the question a site owner will ask first: "what did the AI do to my
 * site?" Before 044 that was unanswerable — write access was granted with no
 * record of its use.
 *
 * ## Storage
 *
 * A capped ring buffer in ONE non-autoloaded option, trimmed on append by both
 * entry count and age. Bounded by construction (FR-039e), so it needs no sweep
 * job and cannot grow without limit. Plain get_option/update_option, so on
 * multisite it is per-site automatically (FR-039a) — see Credentials.php for
 * why get_user_option is deliberately avoided.
 *
 * ## Best-effort by design
 *
 * FR-039h: a recording failure MUST NOT fail or roll back the invocation it was
 * describing. Every write here is wrapped and swallowed. This is an accountability
 * aid, not a security control — losing an entry under concurrent append is
 * acceptable and explicitly permitted, which is why no locking is used.
 *
 * @package Templately\Modules\McpCore\Activity
 */

namespace Templately\Modules\McpCore\Activity;

class ActivityLog {

	const OPTION = 'templately_mcp_activity';

	/** Hard cap on retained entries. */
	const MAX_ENTRIES = 200;

	/** Hard cap on retained age. */
	const MAX_AGE = 2592000; // 30 days.

	/**
	 * Append one record. Never throws; never fails the caller (FR-039h).
	 *
	 * @param string|null $credential_id Credential identifier — NEVER the secret or its hash.
	 * @param int         $user_id
	 * @param string      $capability
	 * @param bool        $success
	 * @param string|null $message       Short reason on failure. Never a stack trace or path.
	 */
	public static function record( ?string $credential_id, int $user_id, string $capability, bool $success, ?string $message = null ): void {
		try {
			$entries = self::all();

			$entries[] = [
				'at'            => time(),
				'credential_id' => $credential_id,
				'user_id'       => $user_id,
				'capability'    => $capability,
				'outcome'       => $success ? 'success' : 'failure',
				'message'       => $message ? self::sanitize_message( $message ) : null,
			];

			update_option( self::OPTION, self::trim( $entries ), 'no' );
		} catch ( \Throwable $e ) {
			// Swallowed deliberately — see class docblock (FR-039h).
			return;
		}
	}

	/**
	 * @return array
	 */
	public static function all(): array {
		$entries = get_option( self::OPTION, [] );

		return is_array( $entries ) ? $entries : [];
	}

	/**
	 * Most recent first, for display.
	 *
	 * @param int $limit
	 * @return array
	 */
	public static function recent( int $limit = 50 ): array {
		$entries = self::trim( self::all() );

		return array_slice( array_reverse( $entries ), 0, max( 1, $limit ) );
	}

	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Trim by age first, then by count. Both bounds exist so a busy site does
	 * not retain unboundedly and a quiet site does not retain forever.
	 *
	 * @param array $entries
	 * @return array
	 */
	private static function trim( array $entries ): array {
		$cutoff = time() - self::MAX_AGE;

		$entries = array_values(
			array_filter(
				$entries,
				static function ( $entry ) use ( $cutoff ) {
					return isset( $entry['at'] ) && (int) $entry['at'] >= $cutoff;
				}
			)
		);

		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}

		return $entries;
	}

	/**
	 * Keep messages short and free of internal detail (FR-039f, FR-044).
	 * Anything resembling an absolute path is dropped rather than truncated.
	 *
	 * @param string $message
	 * @return string
	 */
	private static function sanitize_message( string $message ): string {
		$message = (string) preg_replace( '#(/[^\s:]+)+\.php#', '', $message );
		$message = trim( wp_strip_all_tags( $message ) );

		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $message, 0, 200 );
		}

		return substr( $message, 0, 200 );
	}
}
