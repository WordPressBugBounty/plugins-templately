<?php
/**
 * JSON-RPC method dispatch for the built-in MCP server (spec 044, FR-002–FR-006).
 *
 * @package Templately\Modules\McpServer\Server
 */

namespace Templately\Modules\McpServer\Server;

use Templately\Modules\McpCore\Registry\ToolRegistry;
use WP_Error;

class McpServer {

	/**
	 * Protocol revisions this server speaks, NEWEST FIRST.
	 *
	 * Negotiated rather than asserted (FR-003): if the client names one of these
	 * it is echoed back, otherwise the newest is offered and the client decides.
	 * The reference implementation returns a hardcoded revision regardless of
	 * what the client asked for, silently misrepresenting itself.
	 *
	 * @var string[]
	 */
	const SUPPORTED_PROTOCOLS = [
		'2025-06-18',
		'2025-03-26',
		'2024-11-05',
	];

	/**
	 * Dispatch one JSON-RPC message.
	 *
	 * @param array  $message
	 * @param string $access_level
	 * @param string|null $credential_id
	 * @return array|null Null for notifications (no reply is sent).
	 */
	public static function dispatch( array $message, string $access_level, ?string $credential_id = null ): ?array {
		$is_notification = JsonRpc::is_notification( $message );
		$id              = $message['id'] ?? null;
		$method          = isset( $message['method'] ) && is_string( $message['method'] ) ? $message['method'] : '';
		$params          = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : [];

		switch ( $method ) {
			case 'initialize':
				$response = self::initialize( $id, $params );
				break;

			case 'ping':
				$response = JsonRpc::result( $id, (object) [] );
				break;

			case 'tools/list':
				$response = JsonRpc::result( $id, [ 'tools' => self::tool_listing() ] );
				break;

			case 'tools/call':
				$response = self::call_tool( $id, $params, $access_level, $credential_id );
				break;

			default:
				// Anything under notifications/* gets no reply, ever.
				if ( 0 === strpos( $method, 'notifications/' ) ) {
					return null;
				}

				$response = JsonRpc::error(
					$id,
					JsonRpc::METHOD_NOT_FOUND,
					sprintf(
						/* translators: %s: JSON-RPC method name. */
						__( 'Method not found: %s', 'templately' ),
						$method
					)
				);
		}

		// A message with no `id` is a NOTIFICATION: it is still processed, but no
		// reply is ever sent (FR-002, JSON-RPC 2.0 §4.1). This was previously
		// checked only in the default branch, so a notification naming a method
		// this server implements — `ping`, `initialize`, `tools/list`,
		// `tools/call` — was answered anyway, with `id: null`, which the spec
		// reserves for replies to requests that could not be parsed at all.
		return $is_notification ? null : $response;
	}

	/**
	 * @param mixed $id
	 * @param array $params
	 * @return array
	 */
	private static function initialize( $id, array $params ): array {
		$requested = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] )
			? $params['protocolVersion']
			: '';

		$negotiated = in_array( $requested, self::SUPPORTED_PROTOCOLS, true )
			? $requested
			: self::SUPPORTED_PROTOCOLS[0];

		return JsonRpc::result(
			$id,
			[
				'protocolVersion' => $negotiated,
				// Only what is actually served is announced (FR-006) — no
				// resources or prompts, so a client never probes for them.
				'capabilities'    => [
					'tools' => [ 'listChanged' => false ],
				],
				'serverInfo'      => [
					'name'    => 'templately',
					'version' => defined( 'TEMPLATELY_VERSION' ) ? TEMPLATELY_VERSION : '1.0.0',
				],
			]
		);
	}

	/**
	 * Agent-facing projection. Never leaks callbacks or the access level.
	 *
	 * @return array
	 */
	private static function tool_listing(): array {
		$tools = [];

		foreach ( ToolRegistry::get_instance()->all() as $descriptor ) {
			$tools[] = $descriptor->to_tool_listing();
		}

		return $tools;
	}

	/**
	 * @param mixed       $id
	 * @param array       $params
	 * @param string      $access_level
	 * @param string|null $credential_id
	 * @return array
	 */
	private static function call_tool( $id, array $params, string $access_level, ?string $credential_id ): array {
		$name = isset( $params['name'] ) && is_string( $params['name'] ) ? $params['name'] : '';

		if ( '' === $name ) {
			return JsonRpc::error( $id, JsonRpc::INVALID_PARAMS, __( 'Missing tool name.', 'templately' ) );
		}

		$registry = ToolRegistry::get_instance();

		if ( null === $registry->get( $name ) ) {
			return JsonRpc::error(
				$id,
				JsonRpc::METHOD_NOT_FOUND,
				sprintf(
					/* translators: %s: capability name. */
					__( 'Unknown tool: %s', 'templately' ),
					$name
				)
			);
		}

		$arguments = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : [];
		$result    = $registry->execute( $name, $arguments, $access_level, $credential_id );

		if ( $result instanceof WP_Error ) {
			$status = (int) ( $result->get_error_data()['status'] ?? 0 );

			// A refusal to run at all is a protocol-level error; a failure while
			// running is a completed exchange carrying isError (FR-005).
			if ( in_array( $status, [ 400, 403, 404 ], true ) ) {
				$code = ( 403 === $status ) ? JsonRpc::UNAUTHORIZED
					: ( ( 404 === $status ) ? JsonRpc::METHOD_NOT_FOUND : JsonRpc::INVALID_PARAMS );

				return JsonRpc::error( $id, $code, $result->get_error_message() );
			}

			return JsonRpc::tool_error( $id, $result->get_error_message() );
		}

		return JsonRpc::tool_result( $id, $result );
	}
}
