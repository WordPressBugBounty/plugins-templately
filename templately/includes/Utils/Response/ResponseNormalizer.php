<?php

namespace Templately\Utils\Response;

use Templately\Utils\Helper;
use WP_Error;

/**
 * The single mapper from "whatever the cloud sent" to one typed result (spec 043 / FR-003).
 *
 * The BRANCH ORDER below is itself the contract — see
 * `specs/043-core-api-response-contract/contracts/normalizer-resolution.md`.
 * The TypeScript half (`react-src/utils/errors/normalizeError.ts`) implements the
 * same order and MUST produce the same code for the same input.
 *
 * Invariants enforced here:
 *  - INV-1 the version-gated dual contract (`statusText` vs legacy `extensions`)
 *          resolves to the SAME code for the same logical failure.
 *  - INV-2 an error is never represented as an empty array.
 *  - INV-3 `file` / `line` / `trace` never reach the outward result.
 *  - INV-4 message text is never read to choose a branch (the one exception is the
 *          literal GraphQL sentinel `"validation"`, which is a token, not prose).
 *  - INV-5 `Unauthorised` and `Unauthorized` reach the same code.
 *  - INV-6 an aborted/expected cancellation is `info` and never a network error.
 */
class ResponseNormalizer {

	/**
	 * Upstream `statusText` vocabulary → registry code (FR-004 / FR-006).
	 *
	 * ONLY values observed live are listed. An unmapped value degrades to a
	 * generic code with the raw text preserved in `context.status_text` — we
	 * never guess a meaning we have not seen.
	 */
	private static $status_text_map = [
		'Unauthorised'       => ErrorCode::AUTH_EXPIRED,
		'Unauthorized'       => ErrorCode::AUTH_EXPIRED,
		'AgentKeyNotAllowed' => ErrorCode::AGENT_KEY_NOT_ALLOWED,
		'Unverified'         => ErrorCode::NOT_VERIFIED,
		'Disabled'           => ErrorCode::ACCOUNT_DISABLED,
		'SiteNotConnected'   => ErrorCode::SITE_DISCONNECTED,
		'SiteUrlRequired'    => ErrorCode::SITE_URL_REQUIRED,
		'LimitReached'       => ErrorCode::LIMIT_REACHED,
		'SiteLimitExceeded'  => ErrorCode::SITE_LIMIT_REACHED,
	];

	/**
	 * GraphQL field name → the code its nested `status:'error'` means.
	 *
	 * Keying off the FIELD (not the message) keeps INV-4 intact for the nested
	 * body-error shape, which carries no `statusText` of its own.
	 */
	private static $endpoint_error_map = [
		'connectWithApiKey' => ErrorCode::INVALID_API_KEY,
	];

	/**
	 * Structured body FLAGS that identify an outcome on their own.
	 *
	 * These exist because some endpoints signal a specific condition with a
	 * boolean rather than a `statusText` — `v2/feedback/store` answers a repeat
	 * submission with `{hasFeedback:true, status:'error'}` at HTTP 400. Reading
	 * the flag keeps INV-4 intact (we never parse the prose "Feedback already
	 * submitted"), and it is the difference between telling the user their
	 * feedback failed and telling them it was already recorded.
	 */
	private static $body_flag_map = [
		'hasFeedback' => ErrorCode::ALREADY_SUBMITTED,
	];

	/**
	 * Content types that are passed through untouched (FR-012 / branch 3).
	 */
	private static $raw_content_types = [
		'application/zip',
		'application/octet-stream',
		'application/x-zip-compressed',
		'application/xml',
		'text/xml',
		'text/plain',
		'text/csv',
		'image/',
		'video/',
		'audio/',
	];

	/**
	 * Normalize one `wp_remote_*` result.
	 *
	 * @param array|WP_Error $response Raw WP HTTP response, or a transport WP_Error.
	 * @param array          $options  {
	 *     @type bool   $raw          Treat the body as opaque — never JSON-parse it.
	 *     @type string $endpoint     GraphQL field name, used to unwrap `data.<field>`.
	 *     @type bool   $side_effects Apply the verification/disconnection side-effects (default true).
	 *     @type bool   $cancelled    The caller aborted this request on purpose (branch 0).
	 *     @type bool   $unwrap       Extract the payload from its envelope (default true).
	 *                                Pass false when the caller needs the WHOLE decoded
	 *                                body — e.g. it reads sibling fields like `status`
	 *                                or `credit_cost` that sit next to `data`.
	 * }
	 * @return RemoteResponse
	 */
	public static function normalize( $response, $options = [] ) {
		$options = array_merge( [
			'raw'          => false,
			'endpoint'     => '',
			'side_effects' => true,
			'cancelled'    => false,
			'unwrap'       => true,
		], (array) $options );

		// ── Branch 0 — expected cancellation. MUST precede the transport branch
		// so a deliberate abort is never miscoded as a network failure (INV-6).
		if ( ! empty( $options['cancelled'] ) ) {
			return RemoteResponse::failure(
				new TemplatelyError( ErrorCode::CANCELLED, __( 'Request cancelled.', 'templately' ) )
			);
		}

		// ── Branch 1 — transport failure.
		if ( is_wp_error( $response ) ) {
			return RemoteResponse::failure( self::from_transport_error( $response ) );
		}

		$status       = (int) wp_remote_retrieve_response_code( $response );
		$body         = (string) wp_remote_retrieve_body( $response );
		$content_type = (string) wp_remote_retrieve_header( $response, 'content-type' );

		// ── Branch 2 — side-effects. Applied to EVERY response (FR-011) and
		// never terminating: a verified-header or disconnection signal can ride
		// on a perfectly successful response.
		if ( ! empty( $options['side_effects'] ) ) {
			self::apply_side_effects( $response, $body, $options );
		}

		// ── Branch 3 — raw / binary passthrough. No JSON parsing (FR-012).
		if ( ! empty( $options['raw'] ) || self::is_raw_content_type( $content_type ) ) {
			if ( $status >= 400 ) {
				return RemoteResponse::failure( self::from_http_status( $status, '' ), $status );
			}
			return RemoteResponse::success( $body, $status );
		}

		// ── Branch 4 — empty body where JSON was expected.
		if ( '' === trim( $body ) ) {
			return RemoteResponse::failure(
				new TemplatelyError(
					ErrorCode::EMPTY_RESPONSE,
					__( 'The server returned an empty response.', 'templately' ),
					[ 'status' => $status ?: 502 ]
				),
				$status
			);
		}

		// ── Branch 5 — HTML where JSON was expected (proxy / error page).
		if ( '<' === substr( ltrim( $body ), 0, 1 ) ) {
			return RemoteResponse::failure(
				new TemplatelyError(
					ErrorCode::SERVER_HTML_RESPONSE,
					__( 'The server returned an unexpected page instead of data.', 'templately' ),
					[ 'status' => $status ?: 502 ]
				),
				$status
			);
		}

		// ── Branch 6 — undecodable body. NEVER an empty array (INV-2).
		$decoded = json_decode( $body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return RemoteResponse::failure(
				new TemplatelyError(
					ErrorCode::MALFORMED_JSON,
					__( 'The server response could not be read.', 'templately' ),
					[ 'status' => $status ?: 502 ]
				),
				$status
			);
		}

		if ( ! is_array( $decoded ) ) {
			// A scalar JSON body is a valid payload; there is nothing to dispatch on.
			return $status >= 400
				? RemoteResponse::failure( self::from_http_status( $status, '' ), $status )
				: RemoteResponse::success( $decoded, $status );
		}

		// ── Branch 7 — framework debug-500. Stripped of file/line/trace (INV-3).
		if ( self::is_debug_exception( $decoded ) ) {
			return RemoteResponse::failure( self::from_debug_exception( $decoded, $status ), $status );
		}

		// ── Branch 8 — shape dispatch.
		$error = self::dispatch_body_error( $decoded, $status, $options );
		if ( $error instanceof TemplatelyError ) {
			return RemoteResponse::failure( self::with_retry_after( $error, $response ), $status );
		}

		// ── Branch 9 — a success-shaped body riding a 4xx/5xx status.
		if ( $status >= 400 ) {
			$message = isset( $decoded['message'] ) && is_string( $decoded['message'] ) ? $decoded['message'] : '';
			return RemoteResponse::failure(
				self::with_retry_after( self::from_http_status( $status, $message ), $response ),
				$status
			);
		}

		$payload = empty( $options['unwrap'] )
			? $decoded
			: self::extract_payload( $decoded, $options['endpoint'] );

		return RemoteResponse::success( $payload, $status );
	}

	/**
	 * Carry `Retry-After` into the context when the server sent one.
	 *
	 * Without it a rate-limited client can only guess, and guessing wrong is how
	 * a 429 turns into a retry storm. The value is structured data in `context`,
	 * never text baked into the message.
	 *
	 * @param TemplatelyError $error
	 * @param array           $response
	 * @return TemplatelyError
	 */
	private static function with_retry_after( TemplatelyError $error, $response ) {
		if ( ErrorCode::RATE_LIMITED !== $error->code() ) {
			return $error;
		}

		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
		if ( '' === (string) $retry_after || ! is_numeric( $retry_after ) ) {
			return $error;
		}

		$data                       = $error->data();
		$data['context']            = isset( $data['context'] ) && is_array( $data['context'] ) ? $data['context'] : [];
		$data['context']['retry_after'] = (int) $retry_after;

		return new TemplatelyError( $error->code(), $error->message(), $data );
	}

	/**
	 * Branch 1 — classify a WP transport error.
	 *
	 * @param WP_Error $error
	 * @return TemplatelyError
	 */
	private static function from_transport_error( WP_Error $error ) {
		if ( $error instanceof TemplatelyError ) {
			return $error;
		}

		$message = (string) $error->get_error_message();
		$code    = ErrorCode::NETWORK_ERROR;

		// `http_request_failed` is WP's single bucket for every cURL failure, so
		// the timeout has to be recognised from cURL's own wording. This is the
		// one place a message is inspected, and only to REFINE a code that is
		// already correct — never to pick a branch (INV-4).
		if ( false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'timeout' ) ) {
			$code = ErrorCode::TIMEOUT;
		}

		return new TemplatelyError( $code, self::transport_message( $code ), [
			'context' => [ 'legacy_code' => $error->get_error_code() ],
		] );
	}

	/**
	 * @param string $code
	 * @return string
	 */
	private static function transport_message( $code ) {
		if ( ErrorCode::TIMEOUT === $code ) {
			return __( 'The request timed out. Please try again.', 'templately' );
		}

		return __( 'Could not reach the Templately server. Please check your connection and try again.', 'templately' );
	}

	/**
	 * Branch 2 — verification + disconnection, on every response (FR-011).
	 *
	 * Tolerant per D6: the header may be `true`, `1`, `yes`, `on`; empty or
	 * absent means "no change" (NOT "unverified"). On the connect mutation the
	 * flag arrives in the BODY instead of a header, so it is read there too.
	 *
	 * @param array  $response
	 * @param string $body
	 * @param array  $options
	 * @return void
	 */
	private static function apply_side_effects( $response, $body, $options ) {
		Helper::check_verification_header( $response );

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return;
		}

		Helper::check_site_disconnection( $decoded );

		// GraphQL carries the disconnection signal inside `errors[].extensions`
		// rather than as a top-level `statusText`.
		if ( self::graphql_signals_disconnection( $decoded ) ) {
			Helper::check_site_disconnection( [
				'status'     => 'error',
				'statusText' => 'SiteNotConnected',
			] );
		}

		// Connect mutation: `data.<field>.user.is_verified`.
		$endpoint = isset( $options['endpoint'] ) ? $options['endpoint'] : '';
		if ( $endpoint && ! empty( $decoded['data'][ $endpoint ]['user']['is_verified'] ) ) {
			Helper::mark_user_verified();
		}
	}

	/**
	 * @param array $decoded
	 * @return bool
	 */
	private static function graphql_signals_disconnection( $decoded ) {
		if ( empty( $decoded['errors'] ) || ! is_array( $decoded['errors'] ) ) {
			return false;
		}

		foreach ( $decoded['errors'] as $error ) {
			if ( ! is_array( $error ) ) {
				continue;
			}
			$status_text = isset( $error['extensions']['statusText'] ) ? $error['extensions']['statusText'] : '';
			if ( 'SiteNotConnected' === $status_text ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Branch 3 — is this body opaque to us?
	 *
	 * @param string $content_type
	 * @return bool
	 */
	private static function is_raw_content_type( $content_type ) {
		if ( '' === $content_type ) {
			return false;
		}

		$content_type = strtolower( $content_type );
		foreach ( self::$raw_content_types as $raw ) {
			if ( false !== strpos( $content_type, $raw ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Branch 7 — the Laravel/webonyx debug-500 shape.
	 *
	 * `{ message, exception, file, line, trace }` with NO `status` key. The
	 * `status` exclusion is what separates it from a legitimate error body that
	 * happens to carry a message.
	 *
	 * @param array $decoded
	 * @return bool
	 */
	private static function is_debug_exception( $decoded ) {
		return isset( $decoded['message'], $decoded['exception'] )
			&& ! isset( $decoded['status'] )
			&& ( isset( $decoded['file'] ) || isset( $decoded['line'] ) || isset( $decoded['trace'] ) );
	}

	/**
	 * Branch 7 — build the outward error, discarding every internal detail (INV-3 / FR-008).
	 *
	 * The upstream exception message, file, line and trace are logged
	 * server-side and NEVER travel to the client.
	 *
	 * @param array $decoded
	 * @param int   $status
	 * @return TemplatelyError
	 */
	private static function from_debug_exception( $decoded, $status ) {
		Helper::log(
			'Upstream returned a debug exception: ' . ( isset( $decoded['exception'] ) ? $decoded['exception'] : 'unknown' ),
			'ResponseNormalizer',
			'error'
		);

		return new TemplatelyError(
			ErrorCode::SERVER_ERROR,
			__( 'Something went wrong on the Templately server. Please try again in a moment.', 'templately' ),
			[ 'status' => $status ?: 500 ]
		);
	}

	/**
	 * Branch 8 — dispatch on the decoded body's shape.
	 *
	 * @param array $decoded
	 * @param int   $status
	 * @param array $options
	 * @return TemplatelyError|null null when the body carries no error signal.
	 */
	private static function dispatch_body_error( $decoded, $status, $options ) {
		// 8a — the modern `statusText` contract.
		if ( ! empty( $decoded['statusText'] ) && is_string( $decoded['statusText'] ) ) {
			return self::from_status_text(
				$decoded['statusText'],
				isset( $decoded['message'] ) ? $decoded['message'] : '',
				$status
			);
		}

		// 8b — nested `data.<field>.status === 'error'` (GraphQL body-level error).
		$nested = self::find_nested_body_error( $decoded );
		if ( null !== $nested ) {
			list( $field, $node ) = $nested;

			if ( ! empty( $node['statusText'] ) && is_string( $node['statusText'] ) ) {
				return self::from_status_text( $node['statusText'], $node['message'] ?? '', $status );
			}

			$code = isset( self::$endpoint_error_map[ $field ] )
				? self::$endpoint_error_map[ $field ]
				: ErrorCode::INVALID_REQUEST;

			return new TemplatelyError( $code, isset( $node['message'] ) ? $node['message'] : '', [
				'context' => [ 'field' => $field ],
			] );
		}

		// 8b-bis — a structured flag that names the outcome by itself.
		foreach ( self::$body_flag_map as $flag => $flag_code ) {
			if ( ! empty( $decoded[ $flag ] ) ) {
				return new TemplatelyError(
					$flag_code,
					isset( $decoded['message'] ) ? $decoded['message'] : '',
					[ 'context' => [ 'flag' => $flag ] ]
				);
			}
		}

		if ( isset( $decoded['errors'] ) && is_array( $decoded['errors'] ) && ! empty( $decoded['errors'] ) ) {
			// 8g — REST validation: `errors` is a FIELD MAP, not a GraphQL list.
			if ( ! self::is_list( $decoded['errors'] ) ) {
				return new TemplatelyError(
					ErrorCode::VALIDATION_FAILED,
					isset( $decoded['message'] ) ? $decoded['message'] : __( 'Validation failed.', 'templately' ),
					[
						'status' => $status ?: 422,
						'fields' => self::normalize_fields( $decoded['errors'] ),
					]
				);
			}

			// 8d/8e/8f — GraphQL `errors[]`.
			return self::from_graphql_errors( $decoded['errors'], $status );
		}

		// 8c — top-level `status:'error'` with no statusText.
		if ( isset( $decoded['status'] ) && 'error' === $decoded['status'] ) {
			$message = isset( $decoded['message'] ) ? $decoded['message'] : '';
			return self::from_http_status( $status ?: 400, $message );
		}

		return null;
	}

	/**
	 * 8a — map upstream vocabulary onto the registry (FR-004 / FR-005 / FR-006).
	 *
	 * @param string $status_text
	 * @param string $message
	 * @param int    $status
	 * @return TemplatelyError
	 */
	/**
	 * The registry code an upstream `statusText` means, or `null` when the word
	 * is not one we have observed.
	 *
	 * Exposed because a handler occasionally has to classify a `statusText` that
	 * did NOT arrive as an error envelope — `Login::login()` gets HTTP 200 with a
	 * `user` node that simply carries no `api_key`, so `normalize()` never sees a
	 * failure at all. Reading the map here keeps the vocabulary in ONE place
	 * rather than letting each such handler grow its own copy.
	 *
	 * @param string $status_text
	 * @return string|null
	 */
	public static function code_for_status_text( $status_text ) {
		if ( ! is_string( $status_text ) || '' === $status_text ) {
			return null;
		}

		return isset( self::$status_text_map[ $status_text ] ) ? self::$status_text_map[ $status_text ] : null;
	}

	private static function from_status_text( $status_text, $message, $status ) {
		$context = [ 'status_text' => $status_text ];

		if ( ! isset( self::$status_text_map[ $status_text ] ) ) {
			// Degrade, don't misclassify — the raw value is preserved so a new
			// upstream vocabulary word is diagnosable without a plugin release.
			return new TemplatelyError( self::code_for_http_status( $status ?: 400 ), $message, [
				'status'  => $status ?: 400,
				'context' => $context,
			] );
		}

		$code = self::$status_text_map[ $status_text ];

		if ( ErrorCode::AUTH_EXPIRED === $code || ErrorCode::INVALID_API_KEY === $code ) {
			$context['redirect'] = 'sign-in';
		}

		return new TemplatelyError( $code, $message, [ 'context' => $context ] );
	}

	/**
	 * 8b — locate a `data.<field>` node whose own `status` is `'error'`.
	 *
	 * @param array $decoded
	 * @return array|null [ field, node ]
	 */
	private static function find_nested_body_error( $decoded ) {
		if ( empty( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			return null;
		}

		foreach ( $decoded['data'] as $field => $node ) {
			if ( is_array( $node ) && isset( $node['status'] ) && 'error' === $node['status'] ) {
				return [ $field, $node ];
			}
		}

		return null;
	}

	/**
	 * 8d/8e/8f — the GraphQL `errors[]` list.
	 *
	 * @param array $errors
	 * @param int   $status
	 * @return TemplatelyError
	 */
	private static function from_graphql_errors( $errors, $status ) {
		$first = null;

		foreach ( $errors as $error ) {
			if ( ! is_array( $error ) ) {
				continue;
			}
			if ( null === $first ) {
				$first = $error;
			}

			// 8d — the `validation` sentinel carries per-field messages.
			if ( isset( $error['message'] ) && 'validation' === $error['message'] && ! empty( $error['extensions']['validation'] ) ) {
				return new TemplatelyError(
					ErrorCode::VALIDATION_FAILED,
					__( 'Validation failed.', 'templately' ),
					[
						'status' => $status ?: 422,
						'fields' => self::normalize_fields( $error['extensions']['validation'] ),
					]
				);
			}

			// 8f — the legacy auth contract. Same code as the modern 8a path (INV-1).
			if ( ! empty( $error['extensions']['statusText'] ) ) {
				return self::from_status_text(
					$error['extensions']['statusText'],
					isset( $error['message'] ) ? $error['message'] : '',
					$status
				);
			}
		}

		if ( null === $first ) {
			return new TemplatelyError( ErrorCode::SERVER_ERROR, '', [ 'status' => $status ?: 500 ] );
		}

		// 8e — webonyx leaks its own `file`/`line` in `extensions` when the
		// PLUGIN sent an invalid query. That is our bug, not the user's, so it
		// is fatal — and the internal path is dropped (INV-3).
		if ( isset( $first['extensions']['file'] ) || isset( $first['extensions']['line'] ) ) {
			Helper::log( 'Invalid GraphQL query sent by the plugin: ' . ( $first['message'] ?? '' ), 'ResponseNormalizer', 'error' );

			return new TemplatelyError(
				ErrorCode::MALFORMED_QUERY,
				__( 'Templately could not complete this request due to an internal error. Please update the plugin or contact support.', 'templately' ),
				[ 'status' => $status ?: 500 ]
			);
		}

		// 8f — the legacy `extensions.code` + `extensions.status` shape. The
		// message is NOT read (INV-4), so every authorization-category failure
		// resolves to AUTH_EXPIRED — the same code the modern `statusText`
		// contract produces for the identical failure (INV-1).
		if ( isset( $first['extensions']['code'] ) ) {
			$code = self::code_for_http_status( (int) $first['extensions']['code'] );

			return new TemplatelyError( $code, isset( $first['message'] ) ? $first['message'] : '', [
				'context' => [
					'legacy_code' => (int) $first['extensions']['code'],
					'category'    => isset( $first['extensions']['category'] ) ? $first['extensions']['category'] : '',
				],
			] );
		}

		return new TemplatelyError(
			self::code_for_http_status( $status ?: 500 ),
			isset( $first['message'] ) ? $first['message'] : '',
			[ 'status' => $status ?: 500 ]
		);
	}

	/**
	 * Branch 9 / 8c — derive a code from the HTTP status alone.
	 *
	 * @param int    $status
	 * @param string $message
	 * @return TemplatelyError
	 */
	private static function from_http_status( $status, $message = '' ) {
		$code = self::code_for_http_status( $status );

		if ( '' === trim( (string) $message ) ) {
			$message = self::default_message_for( $code );
		}

		$data = [ 'status' => $status ];

		if ( ErrorCode::AUTH_EXPIRED === $code ) {
			$data['context'] = [ 'redirect' => 'sign-in' ];
		}

		return new TemplatelyError( $code, $message, $data );
	}

	/**
	 * The status → code table. Message-independent by construction (INV-4).
	 *
	 * @param int $status
	 * @return string
	 */
	private static function code_for_http_status( $status ) {
		$status = (int) $status;

		switch ( true ) {
			case 401 === $status:
			case 403 === $status:
				return ErrorCode::AUTH_EXPIRED;
			case 404 === $status:
				return ErrorCode::NOT_FOUND;
			case 409 === $status:
				return ErrorCode::LIMIT_REACHED;
			case 422 === $status:
				return ErrorCode::VALIDATION_FAILED;
			case 426 === $status:
				return ErrorCode::UPDATE_REQUIRED;
			case 429 === $status:
				return ErrorCode::RATE_LIMITED;
			case $status >= 500:
				return ErrorCode::SERVER_ERROR;
			case $status >= 400:
			default:
				// Below 400 the HTTP layer said "fine" while the body said
				// "error" — that is an application-level rejection, not a server
				// fault, so it must not be reported as one.
				return ErrorCode::INVALID_REQUEST;
		}
	}

	/**
	 * @param string $code
	 * @return string
	 */
	private static function default_message_for( $code ) {
		// One source of wording — see ErrorCode::default_message().
		return ErrorCode::default_message( $code );
	}

	/**
	 * Coerce any field-error shape into `{ field: [ string, … ] }` (FR-007).
	 *
	 * @param array $fields
	 * @return array
	 */
	private static function normalize_fields( $fields ) {
		if ( ! is_array( $fields ) ) {
			return [];
		}

		$normalized = [];
		foreach ( $fields as $field => $messages ) {
			$messages = is_array( $messages ) ? $messages : [ $messages ];
			$clean    = [];
			foreach ( $messages as $message ) {
				if ( is_scalar( $message ) ) {
					$clean[] = TemplatelyError::plain_text( (string) $message );
				}
			}
			$normalized[ (string) $field ] = $clean;
		}

		return $normalized;
	}

	/**
	 * Unwrap the successful payload.
	 *
	 * GraphQL nests it under `data.<field>`; the REST API under `data`.
	 *
	 * @param array  $decoded
	 * @param string $endpoint
	 * @return mixed
	 */
	private static function extract_payload( $decoded, $endpoint ) {
		if ( $endpoint ) {
			// GraphQL semantics: the payload is `data.<field>` and NOTHING else.
			//
			// When the queried field is absent, this returns `[]` — never the
			// sibling `data` object. Spec 006 settled that on 2026-07-02 and named
			// the alternative a defect: handing back `data` wholesale leaks the
			// other queried fields into a code path whose caller expects an empty
			// result, and the caller has no way to tell the two apart.
			if ( isset( $decoded['data'] ) && is_array( $decoded['data'] ) && array_key_exists( $endpoint, $decoded['data'] ) ) {
				return $decoded['data'][ $endpoint ];
			}

			return [];
		}

		if ( isset( $decoded['status'] ) && 'success' === $decoded['status'] && array_key_exists( 'data', $decoded ) ) {
			return $decoded['data'];
		}

		return $decoded;
	}

	/**
	 * PHP 7.4-safe `array_is_list()`.
	 *
	 * @param array $array
	 * @return bool
	 */
	private static function is_list( $array ) {
		if ( ! is_array( $array ) ) {
			return false;
		}

		return array_keys( $array ) === range( 0, count( $array ) - 1 );
	}
}
