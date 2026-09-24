<?php

namespace Templately\Utils\Log;

use Templately\Utils\Helper;
use Throwable;

/**
 * The one place server-side detail is recorded (spec 043 / FR-008).
 *
 * The contract's hard rule is that a stack trace, a server file path or a raw
 * exception message NEVER travels to the client. This facade is the other half
 * of that rule: the detail is not discarded, it is logged here — so the
 * normalizer and the envelope can stay ruthless about what they emit.
 *
 * Thin on purpose: it delegates to `Helper::log()` (which is `WP_DEBUG_LOG`
 * gated) and exists so call sites express intent — `Logger::exception()` — and
 * so the "never echo this" boundary has a name.
 */
class Logger {

	/**
	 * @param string $message
	 * @param string $context
	 * @return void
	 */
	public static function info( $message, $context = '' ) {
		Helper::log( $message, $context, 'info' );
	}

	/**
	 * @param string $message
	 * @param string $context
	 * @return void
	 */
	public static function warning( $message, $context = '' ) {
		Helper::log( $message, $context, 'warning' );
	}

	/**
	 * @param string $message
	 * @param string $context
	 * @return void
	 */
	public static function error( $message, $context = '' ) {
		Helper::log( $message, $context, 'error' );
	}

	/**
	 * Record a throwable in full — the ONLY place its trace is allowed to exist.
	 *
	 * @param Throwable $throwable
	 * @param string    $context
	 * @return void
	 */
	public static function exception( $throwable, $context = '' ) {
		if ( ! $throwable instanceof Throwable ) {
			return;
		}

		Helper::log(
			sprintf(
				'%s: %s in %s:%d',
				get_class( $throwable ),
				$throwable->getMessage(),
				$throwable->getFile(),
				$throwable->getLine()
			),
			$context,
			'error'
		);

		Helper::log( $throwable->getTraceAsString(), $context, 'error' );
	}

	/**
	 * Log an upstream body that we refused to pass through, with the
	 * client-visible detail already removed.
	 *
	 * @param array  $body
	 * @param string $context
	 * @return void
	 */
	public static function upstream( $body, $context = '' ) {
		if ( ! is_array( $body ) ) {
			Helper::log( (string) $body, $context, 'error' );
			return;
		}

		Helper::log( wp_json_encode( $body ), $context, 'error' );
	}
}
