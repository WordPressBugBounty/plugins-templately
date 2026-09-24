<?php

namespace Templately\Utils\Exception;

use Templately\Utils\Response\ErrorCode;
use Templately\Utils\Response\TemplatelyError;
use Throwable;

/**
 * The exception base that carries the response contract (spec 043 / PRD PHP-3).
 *
 * A plain `\Exception` carries a message and nothing else, so every catch site had
 * to re-derive the two things that actually matter — is this worth retrying, and
 * how bad is it — usually by matching on the exception's CLASS, and sometimes on
 * its message text. That is why the same failure could be retryable in one runner
 * and terminal in another.
 *
 * This carries the registry code instead, and `severity`/`retryable` follow from
 * it, so a thrown error and a returned `TemplatelyError` describe a failure the
 * same way.
 *
 * The user-facing message is deliberately separate from the internal one. The
 * internal text goes to the log; only `get_user_message()` is safe to show, which
 * is what keeps a framework message or a file path from reaching the browser
 * (FR-008).
 */
class TemplatelyException extends \Exception {

	/**
	 * @var string An `ErrorCode` registry value.
	 */
	protected $error_code;

	/**
	 * @var string|null Shown to the user; falls back to the registry's wording.
	 */
	protected $user_message;

	/**
	 * @var array Structured params (retry_after, upgrade_url, …).
	 */
	protected $context;

	/**
	 * @param string         $error_code   An `ErrorCode` value.
	 * @param string         $message      INTERNAL message — logged, never shown.
	 * @param string|null    $user_message Safe, user-facing text.
	 * @param array          $context      Structured params.
	 * @param Throwable|null $previous
	 */
	public function __construct(
		$error_code = ErrorCode::SERVER_ERROR,
		$message = '',
		$user_message = null,
		$context = [],
		$previous = null
	) {
		$this->error_code   = ErrorCode::exists( $error_code ) ? $error_code : ErrorCode::SERVER_ERROR;
		$this->user_message = $user_message;
		$this->context      = is_array( $context ) ? $context : [];

		parent::__construct( $message, 0, $previous );
	}

	/**
	 * @return string
	 */
	public function get_error_code() {
		return $this->error_code;
	}

	/**
	 * @return bool
	 */
	public function is_retryable() {
		return ErrorCode::retryable( $this->error_code );
	}

	/**
	 * @return string fatal|error|warning|info
	 */
	public function get_severity() {
		return ErrorCode::severity( $this->error_code );
	}

	/**
	 * @return array
	 */
	public function get_context() {
		return $this->context;
	}

	/**
	 * The text that may be shown. Never the internal message.
	 *
	 * @return string
	 */
	public function get_user_message() {
		if ( ! empty( $this->user_message ) ) {
			return $this->user_message;
		}

		return ErrorCode::default_message( $this->error_code );
	}

	/**
	 * Convert to the same value object the normalizer produces, so a thrown error
	 * and a returned one are indistinguishable downstream.
	 *
	 * @return TemplatelyError
	 */
	public function to_error() {
		return new TemplatelyError( $this->error_code, $this->get_user_message(), [
			'context' => $this->context,
		] );
	}

	/**
	 * Give any throwable a registry code.
	 *
	 * The bare `\Exception` throws scattered through the import pipeline carry no
	 * classification at all, so this is what lets a catch site ask "retryable?"
	 * without caring whether the thing it caught was typed.
	 *
	 * The original message becomes the INTERNAL one; the user gets the registry's
	 * wording, because an arbitrary throwable's message may contain a file path,
	 * a SQL fragment, or a framework internal.
	 *
	 * @param Throwable   $throwable
	 * @param string|null $fallback_code Used when the type suggests nothing better.
	 * @return TemplatelyException
	 */
	public static function classify( $throwable, $fallback_code = null ) {
		if ( $throwable instanceof self ) {
			return $throwable;
		}

		$code = $fallback_code && ErrorCode::exists( $fallback_code )
			? $fallback_code
			: self::code_for_throwable( $throwable );

		return new self(
			$code,
			$throwable instanceof Throwable ? $throwable->getMessage() : (string) $throwable,
			null,
			[],
			$throwable instanceof Throwable ? $throwable : null
		);
	}

	/**
	 * Map a throwable's TYPE to a registry code.
	 *
	 * Type only — never the message. Message text is prose: it is translated, it
	 * changes, and branching on it is the habit this contract exists to end (INV-4).
	 *
	 * @param Throwable $throwable
	 * @return string
	 */
	private static function code_for_throwable( $throwable ) {
		// A PHP Error (TypeError, ValueError, a call to an undefined method) is a
		// bug in this plugin, not a condition the user can resolve or retry.
		if ( $throwable instanceof \Error ) {
			return ErrorCode::SERVER_ERROR;
		}

		if ( $throwable instanceof \JsonException ) {
			return ErrorCode::MALFORMED_JSON;
		}

		return ErrorCode::SERVER_ERROR;
	}
}
