<?php
/**
 * JSON-RPC 2.0 envelopes and error codes (spec 044, FR-004/FR-005).
 *
 * @package Templately\Modules\McpServer\Server
 */

namespace Templately\Modules\McpServer\Server;

class JsonRpc {

	const VERSION = '2.0';

	const PARSE_ERROR      = -32700;
	const INVALID_REQUEST  = -32600;
	const METHOD_NOT_FOUND = -32601;
	const INVALID_PARAMS   = -32602;
	const UNAUTHORIZED     = -32001;

	/**
	 * @param mixed $id
	 * @param mixed $result
	 * @return array
	 */
	public static function result( $id, $result ): array {
		return [
			'jsonrpc' => self::VERSION,
			'id'      => $id,
			'result'  => $result,
		];
	}

	/**
	 * @param mixed  $id
	 * @param int    $code
	 * @param string $message
	 * @param mixed  $data
	 * @return array
	 */
	public static function error( $id, int $code, string $message, $data = null ): array {
		$error = [
			'code'    => $code,
			'message' => $message,
		];

		if ( null !== $data ) {
			$error['data'] = $data;
		}

		return [
			'jsonrpc' => self::VERSION,
			'id'      => $id,
			'error'   => $error,
		];
	}

	/**
	 * A capability that failed INSIDE its own logic is still a successful
	 * exchange — the agent gets a readable message it can act on, rather than a
	 * protocol error implying it called the server wrongly (FR-005).
	 *
	 * @param mixed  $id
	 * @param string $message
	 * @return array
	 */
	public static function tool_error( $id, string $message ): array {
		return self::result(
			$id,
			[
				'content' => [
					[
						'type' => 'text',
						'text' => $message,
					],
				],
				'isError' => true,
			]
		);
	}

	/**
	 * @param mixed $id
	 * @param mixed $payload
	 * @return array
	 */
	public static function tool_result( $id, $payload ): array {
		return self::result(
			$id,
			[
				'content' => [
					[
						'type' => 'text',
						'text' => wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
					],
				],
				'isError' => false,
			]
		);
	}

	/**
	 * A message with no `id` is a notification: no reply is ever sent (FR-002).
	 *
	 * @param array $message
	 * @return bool
	 */
	public static function is_notification( array $message ): bool {
		return ! array_key_exists( 'id', $message );
	}

	/**
	 * A structurally valid single request object.
	 *
	 * @param mixed $message
	 * @return bool
	 */
	public static function is_valid_request( $message ): bool {
		return is_array( $message )
			&& ! empty( $message['method'] )
			&& is_string( $message['method'] );
	}
}
