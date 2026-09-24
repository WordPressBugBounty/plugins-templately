<?php

namespace Templately\Utils\Response;

use Templately\Utils\Log\Logger;

/**
 * Shutdown-time PHP-fatal capture for import requests (Query-Monitor-style:
 * full detail to the log, a safe envelope to the client).
 *
 * A fatal (OOM, undefined function in a dependency plugin, parse error in a
 * just-activated plugin…) bypasses every try/catch and every REST filter —
 * the client otherwise receives a blank 500 or an HTML error page it cannot
 * parse. The only hook that still runs is a shutdown function, so this guard:
 *
 *  1. detects a fatal via error_get_last(),
 *  2. records the FULL detail (message, file, line) through {@see Logger}
 *     — per spec 043 FR-008 that detail never travels to the client,
 *  3. emits the standard 043 error envelope with {@see ErrorCode::FATAL_ERROR}
 *     (severity fatal, NOT retryable — the same request dies the same way).
 *
 * Two consumers, one detection+logging path:
 *  - single-import REST handlers call {@see arm()} and get the default JSON
 *    envelope emission,
 *  - the FSI SSE stream calls {@see detect_and_log()} from its own shutdown
 *    handler and emits over its own channel (`sse_error` + import status).
 */
class FatalGuard {

	/**
	 * Context string for the current armed request ('' = not armed).
	 *
	 * @var string
	 */
	private static $context = '';

	/**
	 * Whether the shutdown hook has been registered (once per request).
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Whether the envelope has already been written for this request's fatal
	 * (the wp_die hijack and our own shutdown handler must not both print it).
	 *
	 * @var bool
	 */
	private static $emitted = false;

	/**
	 * Arm the guard for the current request: on a fatal, log the detail and
	 * emit the standard JSON error envelope instead of WP core's "critical
	 * error" template.
	 *
	 * Two cooperating mechanisms, because WP core's fatal handler is
	 * registered at bootstrap — BEFORE any plugin code — and prints first:
	 *
	 *  - Core's template goes out through `wp_die()`, which resolves its
	 *    handler through a filter AT CALL TIME. When a fatal is in
	 *    error_get_last(), {@see filter_die_handler()} swaps in
	 *    {@see render_die()}, so core's own shutdown pass prints OUR envelope
	 *    (core calls it with `'exit' => false`, so execution continues).
	 *  - Our own shutdown handler then logs the full detail, and emits the
	 *    envelope itself only when core's handler didn't run at all
	 *    (WP_DISABLE_FATAL_ERROR_HANDLER, custom drop-in that bails, …).
	 *
	 * A pre-armed output buffer CANNOT solve this — PHP's fatal bailout
	 * discards all output buffers before shutdown functions run.
	 *
	 * Idempotent; later calls just update the context label.
	 *
	 * @param string $context Where we are ("single-import/insert" …) — log prefix.
	 * @return void
	 */
	public static function arm( $context ) {
		self::$context = (string) $context;

		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		add_filter( 'wp_die_handler', [ self::class, 'filter_die_handler' ] );
		add_filter( 'wp_die_json_handler', [ self::class, 'filter_die_handler' ] );
		add_filter( 'wp_die_ajax_handler', [ self::class, 'filter_die_handler' ] );

		register_shutdown_function( [ self::class, 'handle_shutdown' ] );
	}

	/**
	 * `wp_die_*_handler` filter: hijack ONLY the shutdown-time wp_die that WP
	 * core's fatal handler issues for the fatal we are armed against. Any
	 * ordinary wp_die during the request keeps its normal handler (a fatal
	 * ends execution, so a fatal in error_get_last() and a legitimate wp_die
	 * cannot coexist).
	 *
	 * @param callable $handler The resolved wp_die handler.
	 * @return callable
	 */
	public static function filter_die_handler( $handler ) {
		if ( '' === self::$context ) {
			return $handler;
		}

		$last_error = error_get_last();
		if ( is_array( $last_error ) && self::is_fatal( $last_error ) ) {
			return [ self::class, 'render_die' ];
		}

		return $handler;
	}

	/**
	 * The hijacked wp_die handler: print the 043 envelope instead of core's
	 * HTML/JSON "critical error" template. Signature per wp_die contract.
	 *
	 * @param string|\WP_Error $message Ignored — core's generic message.
	 * @param string           $title   Ignored.
	 * @param array            $args    Only `exit` is honored (core passes false).
	 * @return void
	 */
	public static function render_die( $message, $title = '', $args = [] ) {
		self::emit_envelope();

		// Core's fatal template passes `'exit' => false`; honor an explicit
		// true from any other caller (wp_die's own default).
		$exit = is_array( $args ) && array_key_exists( 'exit', $args ) ? (bool) $args['exit'] : true;
		if ( $exit ) {
			die();
		}
	}

	/**
	 * Shutdown callback for {@see arm()}. Public only because PHP requires the
	 * callable to be; not part of the API.
	 *
	 * @return void
	 */
	public static function handle_shutdown() {
		if ( '' === self::$context ) {
			return;
		}

		$fatal = self::detect_and_log( self::$context );
		if ( null === $fatal ) {
			return;
		}

		self::emit_envelope();
	}

	/**
	 * The shared half: did this request die of a fatal? If so, record the full
	 * detail in the Templately log and return it; null otherwise.
	 *
	 * @param string $context Log context label.
	 * @return array|null error_get_last() array when a fatal occurred.
	 */
	public static function detect_and_log( $context ) {
		$last_error = error_get_last();

		if ( ! is_array( $last_error ) || ! self::is_fatal( $last_error ) ) {
			return null;
		}

		Logger::error(
			sprintf(
				'PHP fatal (type %d): %s in %s:%d',
				$last_error['type'],
				isset( $last_error['message'] ) ? $last_error['message'] : '(no message)',
				isset( $last_error['file'] ) ? $last_error['file'] : '(unknown file)',
				isset( $last_error['line'] ) ? $last_error['line'] : 0
			),
			$context
		);

		return $last_error;
	}

	/**
	 * Whether an error_get_last() record is a request-killing fatal (as opposed
	 * to a warning/notice/deprecation that happened to be the last error).
	 *
	 * @param array $error error_get_last()-shaped array.
	 * @return bool
	 */
	public static function is_fatal( array $error ) {
		if ( ! isset( $error['type'] ) ) {
			return false;
		}

		$fatal_types = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

		return (bool) ( $error['type'] & $fatal_types );
	}

	/**
	 * The client-safe error for a captured fatal — stable code + generic
	 * message, no server detail (that went to the log).
	 *
	 * @return TemplatelyError
	 */
	public static function safe_error() {
		return new TemplatelyError(
			ErrorCode::FATAL_ERROR,
			ErrorCode::default_message( ErrorCode::FATAL_ERROR )
		);
	}

	/**
	 * Best-effort JSON envelope emission — once per request. Headers are set
	 * only while unsent; once a broken body has started (display_errors
	 * printed the fatal), nothing can unsend it and the client's transport
	 * falls back to its non-JSON classification.
	 *
	 * @return void
	 */
	private static function emit_envelope() {
		if ( self::$emitted ) {
			return;
		}
		self::$emitted = true;

		if ( ! headers_sent() ) {
			// Discard any partial output so the envelope is the whole body.
			while ( ob_get_level() > 0 ) {
				@ob_end_clean();
			}

			status_header( 500 );
			header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		}

		echo wp_json_encode( Envelope::error( self::safe_error() ) );
	}
}
