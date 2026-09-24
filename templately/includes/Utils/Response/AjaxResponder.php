<?php

namespace Templately\Utils\Response;

use WP_Error;

/**
 * Emits the SAME envelope over admin-ajax that REST emits (spec 043 / FR-001).
 *
 * The plugin talks to its frontend over two transports — REST and admin-ajax
 * (the FSI/SSE paths) — and they historically disagreed on the response shape,
 * which is why the client grew two error readers. They now emit a
 * byte-identical body.
 *
 * The one deliberate difference is the HTTP status: admin-ajax always answers
 * **200**, with the real status carried in `data.status`. A non-200 admin-ajax
 * response is swallowed by some hosts' error pages and by the SSE reader, so
 * the status has to travel in the body to survive the trip.
 */
class AjaxResponder {

	/**
	 * Nonce + capability gate for an admin-ajax handler (spec 043 / PRD PHP-5).
	 *
	 * Every `wp_ajax_templately_*` action ran its own inline copy of this check and
	 * answered with `wp_send_json_error( [ 'message' => 'Invalid nonce' ] )` — a
	 * bare string with no machine code, so the client could only tell an expired
	 * nonce from a missing capability by reading English prose. They are now
	 * distinct codes, and `INVALID_NONCE` is marked retryable so the existing
	 * asset-reload-and-retry path can recover from it automatically instead of
	 * showing the user an error for what is really a stale page.
	 *
	 * Sends the envelope and terminates on failure, exactly like the checks it
	 * replaces; returns true when the request may proceed.
	 *
	 * @param string   $nonce_action
	 * @param string[] $capabilities ALL are required.
	 * @return bool
	 */
	public static function guard( $nonce_action = 'templately_nonce', $capabilities = [] ) {
		// This block IS the nonce verification — the value must be read before it
		// can be checked — but it is still sanitized like any other input.
		// phpcs:disable WordPress.Security.NonceVerification -- verifying the nonce is what this does.
		$nonce = null;
		if ( isset( $_POST['nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ) );
		}
		if ( isset( $_GET['nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_GET['nonce'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification

		if ( ! $nonce || ! wp_verify_nonce( $nonce, $nonce_action ) ) {
			self::error( ErrorCode::INVALID_NONCE );
			return false;
		}

		foreach ( $capabilities as $capability ) {
			if ( ! current_user_can( $capability ) ) {
				self::error( ErrorCode::FORBIDDEN );
				return false;
			}
		}

		return true;
	}

	/**
	 * @param mixed $data
	 * @param array $meta
	 * @return void
	 */
	public static function success( $data = null, $meta = [] ) {
		self::send( Envelope::success( $data, $meta ) );
	}

	/**
	 * @param TemplatelyError|WP_Error|string $error
	 * @param string                          $message
	 * @param array                           $data
	 * @return void
	 */
	public static function error( $error, $message = '', $data = [] ) {
		self::send( Envelope::error( $error, $message, $data ) );
	}

	/**
	 * The envelope, at HTTP 200, always.
	 *
	 * @param array $envelope
	 * @return void
	 */
	public static function send( $envelope ) {
		wp_send_json( $envelope, 200 );
	}

	/**
	 * Build the body without sending it — for SSE frames and for tests, which
	 * cannot survive `wp_send_json()`'s `die()`.
	 *
	 * @param mixed $data
	 * @param array $meta
	 * @return array
	 */
	public static function success_body( $data = null, $meta = [] ) {
		return Envelope::success( $data, $meta );
	}

	/**
	 * @param TemplatelyError|WP_Error|string $error
	 * @param string                          $message
	 * @param array                           $data
	 * @return array
	 */
	public static function error_body( $error, $message = '', $data = [] ) {
		return Envelope::error( $error, $message, $data );
	}
}
