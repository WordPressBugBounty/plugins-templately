<?php
/**
 * `templately/auth-status` — report whether this site has a connected
 * Templately account for the acting user (spec 041-mcp-abilities, Auth Tools
 * addendum).
 *
 * Reads `Templately\Utils\Options` user meta directly for the explicitly
 * resolved acting user, rather than proxying `Templately\API\Login::
 * is_signed()`. That route's own `permission_check()` requires an
 * *already-valid* api_key resolved through `Options`' internal singleton
 * user-id cache — which is captured once, early in plugin bootstrap, before
 * an MCP/REST request's Application-Password auth has resolved (see
 * MCP/CLAUDE.md's Options-singleton gotcha). That makes `is-signed` unusable
 * as a status check in this transport: the very thing being checked (a
 * valid key) is also what the route requires to answer at all.
 *
 * @package Templately\Modules\McpAbilities\Abilities
 */

namespace Templately\Modules\McpAbilities\Abilities;

use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\McpCore\Support\Permissions;
use Templately\Utils\Options;

class AuthStatusAbility {

	const ID = 'templately/auth-status';

	public static function descriptor(): array {
		return [
			'id'                  => self::ID,
			'label'               => __( 'Check Templately Auth Status', 'templately' ),
			'description'         => __( 'Report whether this site has a connected Templately account for the acting user.', 'templately' ),
			'input_schema'        => [
				'type'                 => 'object',
				'properties'           => (object) [],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type' => 'object',
			],
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => [ Permissions::class, 'can_use_abilities' ],
			'access_level'        => ToolDescriptor::ACCESS_READ,
			'annotations'         => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
		];
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public static function execute( array $input ): array {
		$user_id = get_current_user_id();
		$options = Options::get_instance();

		$api_key = $options->get( 'api_key', false, $user_id );
		$user    = $options->get( 'user', null, $user_id );

		if ( empty( $api_key ) || empty( $user ) ) {
			return [ 'connected' => false, 'user' => null ];
		}

		return [
			'connected' => true,
			'user'      => [
				'id'              => $user['id'] ?? null,
				'name'            => $user['name'] ?? null,
				'email'           => $user['email'] ?? null,
				'plan'            => $user['plan'] ?? null,
				'is_verified'     => $user['is_verified'] ?? false,
				'is_company_user' => $user['is_company_user'] ?? false,
			],
		];
	}
}
