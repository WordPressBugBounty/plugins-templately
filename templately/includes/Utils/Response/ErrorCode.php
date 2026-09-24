<?php

namespace Templately\Utils\Response;

/**
 * The canonical machine-code registry (spec 043 / FR-002).
 *
 * Every downstream error carries one of these codes. The set here MUST be
 * identical to the keys of `TEMPLATELY_ERROR_CODES` in
 * `react-src/utils/errors/errorCodes.ts` — a cross-language sync test fails on
 * any divergence (spec 043 US4). When you add a code, add it in BOTH files in
 * the same change.
 *
 * Source of truth for the table: `specs/043-core-api-response-contract/contracts/error-codes.md`.
 */
class ErrorCode {
	// Auth / account.
	const AUTH_EXPIRED          = 'templately_auth_expired';
	const NOT_VERIFIED          = 'templately_not_verified';
	const ACCOUNT_DISABLED      = 'templately_account_disabled';
	const SITE_DISCONNECTED     = 'templately_site_disconnected';
	const SITE_URL_REQUIRED     = 'templately_site_url_required';
	const AGENT_KEY_NOT_ALLOWED = 'templately_agent_key_not_allowed';
	const LIMIT_REACHED         = 'templately_limit_reached';
	const INVALID_API_KEY       = 'templately_invalid_api_key';
	const AUTH_STATE_INVALID    = 'templately_auth_state_invalid';
	const AUTH_MISSING_API_KEY  = 'templately_auth_missing_api_key';
	const AUTH_PROVIDER_FAILED  = 'templately_auth_provider_failed';

	// Transport / server.
	const NETWORK_ERROR         = 'templately_network_error';
	const NETWORK_OFFLINE       = 'templately_network_offline';
	const TIMEOUT               = 'templately_timeout';
	const SERVER_ERROR          = 'templately_server_error';
	// A PHP fatal on THIS site (shutdown-captured) — unlike SERVER_ERROR (the
	// Templately cloud), never retryable: the same request dies the same way.
	const FATAL_ERROR           = 'templately_fatal_error';
	const SERVER_HTML_RESPONSE  = 'templately_server_html_response';
	const EMPTY_RESPONSE        = 'templately_empty_response';
	const MALFORMED_JSON        = 'templately_malformed_json';
	const MALFORMED_QUERY       = 'templately_malformed_query';
	const RATE_LIMITED          = 'templately_rate_limited';

	// Request / client.
	const INVALID_NONCE         = 'templately_invalid_nonce';
	const FORBIDDEN             = 'templately_forbidden';
	const INVALID_REQUEST       = 'templately_invalid_request';
	const VALIDATION_FAILED     = 'templately_validation_failed';
	const NOT_FOUND             = 'templately_not_found';

	// Domain.
	const SITE_LIMIT_REACHED    = 'templately_site_limit_reached';
	const NO_CREDITS            = 'templately_no_credits';
	const CLOUD_SPACE_FULL      = 'templately_cloud_space_full';
	const PACK_NOT_FOUND        = 'templately_pack_not_found';
	const UPDATE_REQUIRED       = 'templately_update_required';
	const ALREADY_SUBMITTED     = 'templately_already_submitted';

	// AI generation (spec 026 taxonomy folded in; `terminal === ! retryable`).
	const AI_PENDING            = 'templately_ai_pending';
	const AI_NOT_READY          = 'templately_ai_not_ready';
	const AI_INVALID_PROCESS    = 'templately_ai_invalid_process';
	const AI_UNAUTHORIZED       = 'templately_ai_unauthorized';
	const AI_REMOTE_FAILED      = 'templately_ai_remote_failed';
	const AI_EXPIRED            = 'templately_ai_expired';
	const AI_INTERNAL_ERROR     = 'templately_ai_internal_error';

	// Expected cancellation (FR-009a) — `info`, never surfaced to the user.
	const CANCELLED             = 'templately_cancelled';

	/**
	 * severity + default retryable per code.
	 *
	 * severity: fatal | error | warning | info — drives the surface (modal /
	 * sticky notice / auto-dismiss notice / silent).
	 */
	private static $meta = [
		self::AUTH_EXPIRED          => [ 'severity' => 'error',   'retryable' => false, 'status' => 401 ],
		self::NOT_VERIFIED          => [ 'severity' => 'warning', 'retryable' => false, 'status' => 403 ],
		self::ACCOUNT_DISABLED      => [ 'severity' => 'error',   'retryable' => false, 'status' => 403 ],
		self::SITE_DISCONNECTED     => [ 'severity' => 'error',   'retryable' => false, 'status' => 403 ],
		self::SITE_URL_REQUIRED     => [ 'severity' => 'fatal',   'retryable' => false, 'status' => 404 ],
		self::AGENT_KEY_NOT_ALLOWED => [ 'severity' => 'error',   'retryable' => false, 'status' => 403 ],
		self::LIMIT_REACHED         => [ 'severity' => 'error',   'retryable' => false, 'status' => 409 ],
		self::INVALID_API_KEY       => [ 'severity' => 'error',   'retryable' => false, 'status' => 401 ],
		self::AUTH_STATE_INVALID    => [ 'severity' => 'error',   'retryable' => true,  'status' => 400 ],
		self::AUTH_MISSING_API_KEY  => [ 'severity' => 'error',   'retryable' => true,  'status' => 400 ],
		self::AUTH_PROVIDER_FAILED  => [ 'severity' => 'error',   'retryable' => true,  'status' => 502 ],

		self::NETWORK_ERROR         => [ 'severity' => 'error',   'retryable' => true,  'status' => 0 ],
		self::NETWORK_OFFLINE       => [ 'severity' => 'warning', 'retryable' => true,  'status' => 0 ],
		self::TIMEOUT               => [ 'severity' => 'error',   'retryable' => true,  'status' => 0 ],
		self::SERVER_ERROR          => [ 'severity' => 'error',   'retryable' => true,  'status' => 500 ],
		self::FATAL_ERROR           => [ 'severity' => 'fatal',   'retryable' => false, 'status' => 500 ],
		self::SERVER_HTML_RESPONSE  => [ 'severity' => 'error',   'retryable' => true,  'status' => 502 ],
		self::EMPTY_RESPONSE        => [ 'severity' => 'error',   'retryable' => true,  'status' => 502 ],
		self::MALFORMED_JSON        => [ 'severity' => 'error',   'retryable' => false, 'status' => 502 ],
		self::MALFORMED_QUERY       => [ 'severity' => 'fatal',   'retryable' => false, 'status' => 500 ],
		self::RATE_LIMITED          => [ 'severity' => 'warning', 'retryable' => true,  'status' => 429 ],

		// Retryable: a nonce expires with the page, and the client's existing
		// `retry_again` path reloads assets and repeats the call — which succeeds.
		self::INVALID_NONCE         => [ 'severity' => 'error',   'retryable' => true,  'status' => 403 ],
		self::FORBIDDEN             => [ 'severity' => 'error',   'retryable' => false, 'status' => 403 ],
		self::INVALID_REQUEST       => [ 'severity' => 'error',   'retryable' => false, 'status' => 400 ],
		self::VALIDATION_FAILED     => [ 'severity' => 'error',   'retryable' => false, 'status' => 422 ],
		self::NOT_FOUND             => [ 'severity' => 'error',   'retryable' => false, 'status' => 404 ],

		self::SITE_LIMIT_REACHED    => [ 'severity' => 'error',   'retryable' => false, 'status' => 403 ],
		self::NO_CREDITS            => [ 'severity' => 'warning', 'retryable' => false, 'status' => 402 ],
		self::CLOUD_SPACE_FULL      => [ 'severity' => 'error',   'retryable' => false, 'status' => 507 ],
		self::PACK_NOT_FOUND        => [ 'severity' => 'error',   'retryable' => false, 'status' => 404 ],
		self::UPDATE_REQUIRED       => [ 'severity' => 'error',   'retryable' => false, 'status' => 426 ],
		self::ALREADY_SUBMITTED     => [ 'severity' => 'info',    'retryable' => false, 'status' => 200 ],

		self::AI_PENDING            => [ 'severity' => 'info',    'retryable' => true,  'status' => 202 ],
		self::AI_NOT_READY          => [ 'severity' => 'info',    'retryable' => true,  'status' => 202 ],
		self::AI_INVALID_PROCESS    => [ 'severity' => 'error',   'retryable' => false, 'status' => 400 ],
		self::AI_UNAUTHORIZED       => [ 'severity' => 'error',   'retryable' => false, 'status' => 401 ],
		self::AI_REMOTE_FAILED      => [ 'severity' => 'error',   'retryable' => false, 'status' => 502 ],
		self::AI_EXPIRED            => [ 'severity' => 'error',   'retryable' => false, 'status' => 410 ],
		// Retryable: a transient manifest/session race produced this code and a
		// single occurrence was killing a paid, still-running generation. The
		// client poll is attempt-capped, so retrying a permanent internal error
		// is bounded, while a transient one now recovers.
		self::AI_INTERNAL_ERROR     => [ 'severity' => 'error',   'retryable' => true,  'status' => 500 ],

		self::CANCELLED             => [ 'severity' => 'info',    'retryable' => false, 'status' => 0 ],
	];

	/**
	 * Every registered code, in declaration order.
	 *
	 * @return string[]
	 */
	public static function all() {
		return array_keys( self::$meta );
	}

	/**
	 * Whether $code is a member of the registry.
	 *
	 * @param string $code
	 * @return bool
	 */
	public static function exists( $code ) {
		return is_string( $code ) && isset( self::$meta[ $code ] );
	}

	/**
	 * severity / retryable / status defaults for a code.
	 *
	 * Unknown codes degrade to a generic error rather than throwing — the
	 * normalizer must never fail on an unexpected upstream value (FR-006).
	 *
	 * @param string $code
	 * @return array{severity:string,retryable:bool,status:int}
	 */
	public static function meta( $code ) {
		if ( self::exists( $code ) ) {
			return self::$meta[ $code ];
		}

		return [ 'severity' => 'error', 'retryable' => false, 'status' => 500 ];
	}

	/**
	 * @param string $code
	 * @return string one of fatal|error|warning|info
	 */
	public static function severity( $code ) {
		$meta = self::meta( $code );
		return $meta['severity'];
	}

	/**
	 * @param string $code
	 * @return bool
	 */
	public static function retryable( $code ) {
		$meta = self::meta( $code );
		return $meta['retryable'];
	}

	/**
	 * The default HTTP-equivalent status for a code.
	 *
	 * @param string $code
	 * @return int
	 */
	public static function status( $code ) {
		$meta = self::meta( $code );
		return $meta['status'];
	}

	/**
	 * The default user-facing sentence for a code.
	 *
	 * Lives here, not in the normalizer, because more than one producer needs it:
	 * the normalizer when upstream sent no usable message, and
	 * `TemplatelyException::get_user_message()` when the thrown error carries only
	 * an internal one. Two copies of this wording is two things to keep in step.
	 *
	 * Deliberately generic — a message the user can act on, never a description of
	 * what went wrong internally (FR-008).
	 *
	 * @param string $code
	 * @return string
	 */
	public static function default_message( $code ) {
		switch ( $code ) {
			case self::AUTH_EXPIRED:
			case self::INVALID_API_KEY:
				return __( 'Your session has expired. Please log in again.', 'templately' );
			case self::NOT_VERIFIED:
				return __( 'Please verify your email address to continue.', 'templately' );
			case self::ACCOUNT_DISABLED:
				return __( 'This account is not active. Please contact support.', 'templately' );
			case self::SITE_DISCONNECTED:
				return __( 'This site is not connected to Templately. Please log in again.', 'templately' );
			case self::SITE_URL_REQUIRED:
				return __( 'Templately could not identify this site. Please contact support.', 'templately' );
			case self::LIMIT_REACHED:
				return __( 'You have reached your plan limit.', 'templately' );
			case self::NOT_FOUND:
			case self::PACK_NOT_FOUND:
				return __( 'The requested resource was not found.', 'templately' );
			case self::RATE_LIMITED:
				return __( 'Too many requests. Please wait a moment and try again.', 'templately' );
			case self::VALIDATION_FAILED:
				return __( 'Validation failed.', 'templately' );
			case self::NETWORK_OFFLINE:
				return __( 'You appear to be offline. Check your connection and try again.', 'templately' );
			case self::NETWORK_ERROR:
				return __( 'Could not reach the Templately server. Please check your connection and try again.', 'templately' );
			case self::TIMEOUT:
				return __( 'The request timed out. Please try again.', 'templately' );
			case self::MALFORMED_QUERY:
				return __( 'Templately could not complete this request due to an internal error. Please update the plugin or contact support.', 'templately' );
			case self::ALREADY_SUBMITTED:
				return __( 'This has already been submitted. Thank you!', 'templately' );
			case self::CANCELLED:
				return __( 'Request cancelled.', 'templately' );
			case self::INVALID_NONCE:
				return __( 'This page has expired. Please refresh and try again.', 'templately' );
			case self::FORBIDDEN:
				return __( 'You do not have permission to do this.', 'templately' );
			case self::SERVER_ERROR:
				return __( 'Something went wrong on the Templately server. Please try again in a moment.', 'templately' );
			case self::FATAL_ERROR:
				return __( 'A critical error occurred on your website while processing this request. Details were saved to the Templately log — please contact support if it keeps happening.', 'templately' );
			default:
				return __( 'The request could not be completed.', 'templately' );
		}
	}

	/**
	 * spec 026's AI generation slug → this registry (043 FR-014).
	 *
	 * The AI endpoints shipped their own four-field envelope
	 * (`{success, terminal, code, message}`) before this contract existed, and
	 * the poller keys off `terminal` only. Folding it in ADDITIVELY — rather
	 * than rewriting it — keeps that poller byte-stable while giving the AI
	 * failures the same machine codes as everything else.
	 *
	 * The two taxonomies line up exactly: `terminal === ! retryable`. Only
	 * `pending`/`not_ready` are retryable, and only those two are non-terminal.
	 * `ai_terminal_matches_retryable()` asserts that rather than assuming it.
	 */
	private static $ai_code_map = [
		'pending'         => self::AI_PENDING,
		'not_ready'       => self::AI_NOT_READY,
		'invalid_process' => self::AI_INVALID_PROCESS,
		'unauthorized'    => self::AI_UNAUTHORIZED,
		'remote_failed'   => self::AI_REMOTE_FAILED,
		'expired'         => self::AI_EXPIRED,
		'internal_error'  => self::AI_INTERNAL_ERROR,
	];

	/**
	 * @param string $ai_code One of spec 026's taxonomy slugs.
	 * @return string|null the registry code, or null for `ok` (a success, not an error).
	 */
	public static function from_ai_code( $ai_code ) {
		return isset( self::$ai_code_map[ $ai_code ] ) ? self::$ai_code_map[ $ai_code ] : null;
	}

	/**
	 * Every AI slug this registry knows about.
	 *
	 * @return string[]
	 */
	public static function ai_codes() {
		return array_keys( self::$ai_code_map );
	}
}
