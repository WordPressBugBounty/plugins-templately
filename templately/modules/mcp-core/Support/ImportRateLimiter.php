<?php
/**
 * Local, per-authenticated-user import rate limiter (FR-015).
 *
 * A small, self-contained seed — not the "shared infrastructure" the spec's
 * Clarifications describe, because no such shared layer exists anywhere in
 * this codebase yet (research.md §5, spec.md Assumptions). Isolated behind
 * this one class so it can be swapped for real shared infrastructure later
 * without touching any ability.
 *
 * @package Templately\Modules\McpCore\Support
 */

namespace Templately\Modules\McpCore\Support;

use WP_Error;

class ImportRateLimiter {

	const MAX_CALLS      = 10;
	const WINDOW_SECONDS = 60;

	/**
	 * @return WP_Error|null Null if the call may proceed.
	 */
	public static function check(): ?WP_Error {
		$key   = 'templately_mcp_import_rl_' . get_current_user_id();
		$count = (int) get_transient( $key );

		if ( $count >= self::MAX_CALLS ) {
			return new WP_Error(
				'rate_limited',
				__( 'Too many import requests. Please wait a moment and try again.', 'templately' )
			);
		}

		set_transient( $key, $count + 1, self::WINDOW_SECONDS );

		return null;
	}
}
