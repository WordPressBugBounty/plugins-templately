<?php

namespace Templately\Utils\Response;

/**
 * The typed result of one outbound cloud call (spec 043 / FR-003).
 *
 * `ResponseNormalizer` returns this instead of the historical
 * "array | WP_Error | [] | string" grab-bag. It is intentionally tiny: either it
 * carries a payload, or it carries a `TemplatelyError`. Never both, never
 * neither — that ambiguity (an empty array standing in for an error) is the
 * INV-2 bug this contract removes.
 */
class RemoteResponse {

	/**
	 * @var mixed
	 */
	private $payload;

	/**
	 * @var TemplatelyError|null
	 */
	private $error;

	/**
	 * Raw HTTP status of the upstream response (0 when there was no response).
	 *
	 * @var int
	 */
	private $status;

	/**
	 * @param mixed                $payload
	 * @param TemplatelyError|null $error
	 * @param int                  $status
	 */
	private function __construct( $payload, $error, $status = 0 ) {
		$this->payload = $payload;
		$this->error   = $error;
		$this->status  = (int) $status;
	}

	/**
	 * @param mixed $payload
	 * @param int   $status
	 * @return RemoteResponse
	 */
	public static function success( $payload, $status = 200 ) {
		return new self( $payload, null, $status );
	}

	/**
	 * @param TemplatelyError $error
	 * @param int             $status Upstream HTTP status; defaults to the error's own.
	 * @return RemoteResponse
	 */
	public static function failure( TemplatelyError $error, $status = null ) {
		return new self( null, $error, null === $status ? $error->status() : $status );
	}

	/**
	 * @return bool
	 */
	public function is_error() {
		return $this->error instanceof TemplatelyError;
	}

	/**
	 * The successful payload, or `null` when this is an error.
	 *
	 * @return mixed
	 */
	public function payload() {
		return $this->is_error() ? null : $this->payload;
	}

	/**
	 * @return TemplatelyError|null
	 */
	public function error() {
		return $this->error;
	}

	/**
	 * @return int upstream HTTP status.
	 */
	public function status() {
		return $this->status;
	}

	/**
	 * Back-compat bridge for call sites that still expect
	 * `array|WP_Error` from the old helpers. New code should branch on
	 * `is_error()` instead.
	 *
	 * @return mixed|TemplatelyError
	 */
	public function unwrap() {
		return $this->is_error() ? $this->error : $this->payload;
	}
}
