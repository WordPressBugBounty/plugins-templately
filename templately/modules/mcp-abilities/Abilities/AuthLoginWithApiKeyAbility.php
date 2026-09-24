<?php
/**
 * `templately/auth-login-with-api-key` — connect this site to a Templately
 * account using an account API key (spec 041-mcp-abilities, Auth Tools
 * addendum).
 *
 * Deliberately does NOT proxy `Templately\API\Login::login()` via
 * RestDispatcher: that method persists via `Options->set(..., $user_id =
 * null)`, which internally resolves the target user through the same stale
 * singleton cache described in MCP/CLAUDE.md — the call would report
 * success but silently write the connection under the wrong (unauthenticated,
 * id 0) user. This ability calls the same `connectWithApiKey` GraphQL
 * mutation directly and persists with the acting user's id resolved fresh
 * at execute time, explicitly threaded through every `Options` call.
 *
 * Scope: single-account connect only — the `global_signin` (shared/linked
 * account) flow `Login::login()` also supports is intentionally not exposed
 * here to keep this ability's behavior simple and predictable for an agent.
 *
 * @package Templately\Modules\McpAbilities\Abilities
 */

namespace Templately\Modules\McpAbilities\Abilities;

use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\McpCore\Support\Permissions;
use Templately\Utils\Helper;
use Templately\Utils\Response\ErrorCode;
use Templately\Utils\Http;
use Templately\Utils\Options;
use WP_Error;

class AuthLoginWithApiKeyAbility {

	const ID = 'templately/auth-login-with-api-key';

	public static function descriptor(): array {
		return [
			'id'                  => self::ID,
			'label'               => __( 'Connect Templately with an API Key', 'templately' ),
			'description'         => __( 'Connect this site to a Templately account using an account API key.', 'templately' ),
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => [
					'api_key' => [ 'type' => 'string', 'description' => __( 'Templately account API key.', 'templately' ) ],
				],
				'required'             => [ 'api_key' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type' => 'object',
			],
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => [ Permissions::class, 'can_use_abilities' ],
			'access_level'        => ToolDescriptor::ACCESS_FULL,
			'annotations'         => [ 'readonly' => false, 'destructive' => false, 'idempotent' => true ],
		];
	}

	/**
	 * @param array $input
	 * @return array|WP_Error
	 */
	public static function execute( array $input ) {
		$api_key = trim( (string) ( $input['api_key'] ?? '' ) );

		if ( empty( $api_key ) ) {
			return new WP_Error( ErrorCode::INVALID_API_KEY, __( 'An API key is required.', 'templately' ) );
		}

		$user_id = get_current_user_id();

		$query = 'status, message, user{ id, name, first_name, last_name, display_name, email, profile_photo, joined, is_verified, is_company_user, api_key, plan, plan_expire_at }';

		$response = Http::get_instance()->mutation(
			'connectWithApiKey',
			$query,
			[
				'ip'       => Helper::get_ip(),
				'site_url' => home_url( '/' ),
				'api_key'  => $api_key,
			]
		)->post();

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['user']['api_key'] ) ) {
			return new WP_Error( ErrorCode::INVALID_API_KEY, $response['message'] ?? __( 'Invalid API key.', 'templately' ) );
		}

		$options = Options::get_instance();
		$options->set( 'api_key', $response['user']['api_key'], $user_id );
		unset( $response['user']['api_key'] );

		$response['user']['ip']       = Helper::get_ip();
		$response['user']['site_url'] = base64_encode( home_url( '/' ) );

		$options->set( 'user', $response['user'], $user_id );

		return [
			'status' => 'success',
			'user'   => [
				'id'    => $response['user']['id'] ?? null,
				'name'  => $response['user']['name'] ?? null,
				'email' => $response['user']['email'] ?? null,
				'plan'  => $response['user']['plan'] ?? null,
			],
		];
	}
}
