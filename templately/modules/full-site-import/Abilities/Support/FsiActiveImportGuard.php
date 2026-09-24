<?php
/**
 * One-active-import-per-user guard (spec 042 FR-015, research.md §5).
 *
 * FSI is inherently single-session (`SessionData::get_session_id()` tracks one
 * import); the browser enforces one-at-a-time via its UI. Headless MCP callers
 * have no such UI, so this small registry makes the invariant hold: a second
 * `start` while one import is in flight returns the existing handle instead of
 * minting a parallel session.
 *
 * Stored in a NON-autoloaded per-user option so it never bloats the autoload
 * cache (matches the 026/034 storage convention). Scoped by user id the same
 * way `Templately\Utils\Options` scopes its rows.
 *
 * @package Templately\Modules\FullSiteImport\Abilities\Support
 */

namespace Templately\Modules\FullSiteImport\Abilities\Support;

class FsiActiveImportGuard {

	const OPTION_PREFIX = 'templately_mcp_fsi_active_';

	/**
	 * The active handle for a user, or null when there is none.
	 *
	 * @param int $user_id
	 * @return array|null { session_id, started_at } or null.
	 */
	public static function get_active( int $user_id ): ?array {
		$row = get_option( self::key( $user_id ) );

		if ( empty( $row ) || ! is_array( $row ) || empty( $row['session_id'] ) ) {
			return null;
		}

		return $row;
	}

	/**
	 * Record a user's active import handle.
	 *
	 * @param int    $user_id
	 * @param string $session_id
	 * @return void
	 */
	public static function set_active( int $user_id, string $session_id ): void {
		// add_option with autoload 'no' on first write; update_option keeps the
		// existing autoload flag on subsequent writes.
		$key = self::key( $user_id );
		$row = [ 'session_id' => $session_id, 'started_at' => time() ];

		if ( false === get_option( $key ) ) {
			add_option( $key, $row, '', 'no' );
		} else {
			update_option( $key, $row );
		}
	}

	/**
	 * Clear a user's active import handle (on a terminal complete/failed/revert).
	 *
	 * @param int $user_id
	 * @return void
	 */
	public static function clear( int $user_id ): void {
		delete_option( self::key( $user_id ) );
	}

	/**
	 * @param int $user_id
	 * @return string
	 */
	private static function key( int $user_id ): string {
		return self::OPTION_PREFIX . $user_id;
	}
}
