<?php

namespace Templately\Utils\Response;

use WP_Error;

/**
 * Builds THE downstream response envelope (spec 043 / FR-001).
 *
 * Every REST and AJAX response the plugin sends its own frontend has this
 * shape — one success shape, one error shape, no per-endpoint variations:
 *
 *   success: { success: true, data: <payload>, meta?: {} }
 *   error:   { success: false, code, message, data: { status, severity,
 *              retryable, fields, context, debug? } }
 *
 * `message` is ALWAYS plain text (FR-010) and `debug` exists only when the host
 * is in debug mode (FR-008) — a stack trace never reaches the browser.
 *
 * Validated against `contracts/error-envelope.schema.json`.
 */
class Envelope {

	/**
	 * @param mixed $data payload of any shape.
	 * @param array $meta optional metadata (pagination, timings, …).
	 * @return array
	 */
	public static function success( $data = null, $meta = [] ) {
		$envelope = [
			'success' => true,
			'data'    => $data,
		];

		if ( ! empty( $meta ) && is_array( $meta ) ) {
			$envelope['meta'] = $meta;
		}

		return $envelope;
	}

	/**
	 * @param TemplatelyError|WP_Error|string $error   A normalized error, a legacy WP_Error, or a bare code.
	 * @param string                          $message Used only when $error is a bare code.
	 * @param array                           $data    Used only when $error is a bare code.
	 * @return array
	 */
	public static function error( $error, $message = '', $data = [] ) {
		$error = self::to_templately_error( $error, $message, $data );

		$body = [
			'success' => false,
			'code'    => $error->code(),
			'message' => $error->message(),
			'data'    => [
				'status'    => $error->status(),
				'severity'  => $error->severity(),
				'retryable' => $error->is_retryable(),
				'fields'    => (object) $error->fields(),
				'context'   => (object) $error->context(),
			],
		];

		$error_data = $error->data();
		if ( self::is_debug_mode() && ! empty( $error_data['debug'] ) ) {
			$body['data']['debug'] = (object) self::scrub_debug( $error_data['debug'] );
		}

		return $body;
	}

	/**
	 * @param TemplatelyError|WP_Error|string $error
	 * @param string                          $message
	 * @param array                           $data
	 * @return TemplatelyError
	 */
	private static function to_templately_error( $error, $message, $data ) {
		if ( $error instanceof TemplatelyError ) {
			return $error;
		}

		if ( $error instanceof WP_Error ) {
			return TemplatelyError::from_wp_error( $error );
		}

		return new TemplatelyError( (string) $error, $message, $data );
	}

	/**
	 * Whether an envelope array is the error variant.
	 *
	 * @param mixed $envelope
	 * @return bool
	 */
	public static function is_error( $envelope ) {
		return is_array( $envelope ) && isset( $envelope['success'] ) && false === $envelope['success'];
	}

	/**
	 * @return bool
	 */
	public static function is_debug_mode() {
		return ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'TEMPLATELY_DEBUG_LOG' ) && TEMPLATELY_DEBUG_LOG );
	}

	/**
	 * Even in debug mode the envelope never carries a stack trace or a server
	 * path — those go to the log, not the wire (FR-008 / INV-3).
	 *
	 * @param array $debug
	 * @return array
	 */
	public static function scrub_debug( $debug ) {
		if ( ! is_array( $debug ) ) {
			return [];
		}

		unset( $debug['trace'], $debug['file'], $debug['line'], $debug['exception'] );

		return $debug;
	}
}
