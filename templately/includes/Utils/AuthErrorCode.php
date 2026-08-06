<?php

namespace Templately\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * The codes the Google sign-in callback may hand back through the redirect URL.
 *
 * Only a code travels. The callback used to put the failure PROSE in an
 * `error_message` query parameter, which the sign-in screen then rendered as
 * markup — a reflected XSS anyone could fire by getting an administrator to
 * open a crafted admin URL. A code cannot carry a payload: the React side
 * matches it against this same list and discards anything it does not
 * recognise, and the copy for each one lives in the plugin.
 *
 * Values mirror `includes/Utils/Response/ErrorCode.php` on the `staging` branch
 * so the two converge when that work lands on the release line.
 */
class AuthErrorCode {

	/**
	 * The state token was missing, expired, or minted for another user.
	 */
	const AUTH_STATE_INVALID = 'templately_auth_state_invalid';

	/**
	 * The callback arrived without an API key to connect with.
	 */
	const AUTH_MISSING_API_KEY = 'templately_auth_missing_api_key';

	/**
	 * Google itself refused — it returns its reason in `?error=`, which we
	 * deliberately do not forward.
	 */
	const AUTH_PROVIDER_FAILED = 'templately_auth_provider_failed';

	/**
	 * The key was rejected when we tried to connect with it. Also the catch-all:
	 * anything without a code of its own collapses here, so no upstream-authored
	 * string ever needs to reach the URL.
	 */
	const INVALID_API_KEY = 'templately_invalid_api_key';

	/**
	 * Every code this class defines.
	 *
	 * @return string[]
	 */
	public static function all() {
		return [
			self::AUTH_STATE_INVALID,
			self::AUTH_MISSING_API_KEY,
			self::AUTH_PROVIDER_FAILED,
			self::INVALID_API_KEY,
		];
	}

	/**
	 * Whether a value is one of ours.
	 *
	 * @param mixed $code Candidate code, typically straight off the query string.
	 *
	 * @return bool
	 */
	public static function exists( $code ) {
		return is_string( $code ) && in_array( $code, self::all(), true );
	}
}
