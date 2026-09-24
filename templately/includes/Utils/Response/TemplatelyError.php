<?php

namespace Templately\Utils\Response;

use WP_Error;

/**
 * The normalized error value object (spec 043).
 *
 * It deliberately **extends `WP_Error`** so every untouched `is_wp_error()` /
 * `get_error_message()` call site in the plugin keeps working unchanged while
 * consumers migrate onto the richer contract. The extra contract fields live in
 * the `WP_Error` data bag, which is also exactly what the REST envelope
 * serializes.
 *
 * `message` is ALWAYS plain text — never HTML (FR-010). The constructor strips
 * tags defensively, because upstream messages have historically carried anchor
 * markup and that is the reflected-XSS channel this spec closes.
 */
class TemplatelyError extends WP_Error {

	/**
	 * @param string $code      An `ErrorCode` registry value.
	 * @param string $message   Plain-text, translated, user-safe message.
	 * @param array  $data      Optional overrides: status, severity, retryable, fields, context, debug.
	 */
	public function __construct( $code, $message = '', $data = [] ) {
		$meta = ErrorCode::meta( $code );
		$data = is_array( $data ) ? $data : [];

		$normalized = [
			'status'    => isset( $data['status'] ) ? (int) $data['status'] : (int) $meta['status'],
			'severity'  => isset( $data['severity'] ) ? (string) $data['severity'] : $meta['severity'],
			'retryable' => isset( $data['retryable'] ) ? (bool) $data['retryable'] : (bool) $meta['retryable'],
			'fields'    => isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : [],
			'context'   => isset( $data['context'] ) && is_array( $data['context'] ) ? $data['context'] : [],
		];

		// `debug` is carried only when the host is in debug mode; RestEnvelope
		// strips it otherwise. It must NEVER contain file/line/trace (FR-008).
		if ( isset( $data['debug'] ) && is_array( $data['debug'] ) ) {
			$normalized['debug'] = $data['debug'];
		}

		// An empty message would reach the user as a blank error box. The registry
		// already owns the wording for every code (and the normalizer already uses
		// it on the inbound side), so fall back to it here rather than letting each
		// construction site invent its own — or, as `AjaxResponder::guard()` did,
		// send nothing at all.
		$message = self::plain_text( $message );
		if ( '' === $message ) {
			$message = ErrorCode::default_message( $code );
		}

		parent::__construct( $code, $message, $normalized );
	}

	/**
	 * Reduce any upstream message to inert plain text (FR-010).
	 *
	 * Upstream sends anchor markup in some messages (e.g. `SiteNotConnected`).
	 * The link is re-composed on the client from `code` + `context`, never
	 * parsed back out of the message, so the tags are dropped here.
	 *
	 * @param mixed $message
	 * @return string
	 */
	public static function plain_text( $message ) {
		if ( is_array( $message ) ) {
			$message = reset( $message );
		}

		if ( ! is_string( $message ) ) {
			return '';
		}

		$message = wp_strip_all_tags( $message, true );
		$message = html_entity_decode( $message, ENT_QUOTES, 'UTF-8' );

		return trim( preg_replace( '/\s+/u', ' ', $message ) );
	}

	/**
	 * @return string the ErrorCode registry value.
	 */
	public function code() {
		return $this->get_error_code();
	}

	/**
	 * @return string plain-text message.
	 */
	public function message() {
		return (string) $this->get_error_message();
	}

	/**
	 * @return array the full normalized data bag.
	 */
	public function data() {
		$data = $this->get_error_data();
		return is_array( $data ) ? $data : [];
	}

	/**
	 * @return int HTTP-equivalent status.
	 */
	public function status() {
		$data = $this->data();
		return isset( $data['status'] ) ? (int) $data['status'] : 500;
	}

	/**
	 * @return string fatal|error|warning|info
	 */
	public function severity() {
		$data = $this->data();
		return isset( $data['severity'] ) ? (string) $data['severity'] : 'error';
	}

	/**
	 * @return bool
	 */
	public function is_retryable() {
		$data = $this->data();
		return ! empty( $data['retryable'] );
	}

	/**
	 * @return array { field: [messages] }
	 */
	public function fields() {
		$data = $this->data();
		return isset( $data['fields'] ) && is_array( $data['fields'] ) ? $data['fields'] : [];
	}

	/**
	 * @return array structured params (status_text, retry_after, redirect, …).
	 */
	public function context() {
		$data = $this->data();
		return isset( $data['context'] ) && is_array( $data['context'] ) ? $data['context'] : [];
	}

	/**
	 * Whether this error should never be surfaced to the user (FR-009a).
	 *
	 * @return bool
	 */
	public function is_silent() {
		return 'info' === $this->severity();
	}

	/**
	 * Adopt an arbitrary `WP_Error` into the contract without losing its code.
	 *
	 * Used at the seams where legacy code still hands us a plain `WP_Error`.
	 *
	 * @param WP_Error $error
	 * @param string   $fallback_code an ErrorCode used when the WP_Error's own code is unregistered.
	 * @return TemplatelyError
	 */
	public static function from_wp_error( WP_Error $error, $fallback_code = ErrorCode::SERVER_ERROR ) {
		if ( $error instanceof self ) {
			return $error;
		}

		$code = $error->get_error_code();
		$data = $error->get_error_data();
		$data = is_array( $data ) ? $data : [];

		if ( ! ErrorCode::exists( $code ) ) {
			$data['context'] = isset( $data['context'] ) && is_array( $data['context'] ) ? $data['context'] : [];
			if ( ! empty( $code ) ) {
				$data['context']['legacy_code'] = $code;
			}
			$code = $fallback_code;
		}

		return new self( $code, $error->get_error_message(), $data );
	}
}
