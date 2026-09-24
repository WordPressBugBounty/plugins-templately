<?php
/**
 * `templately/auth-logout` — disconnect this site's Templately account for
 * the acting user (spec 041-mcp-abilities, Auth Tools addendum).
 *
 * Deliberately does NOT proxy `Templately\API\Login::logout()` via
 * RestDispatcher: that route's own `permission_check()` requires an
 * already-valid api_key resolved through `Options`' stale singleton user-id
 * cache (see MCP/CLAUDE.md) — sign-out must not depend on the same broken
 * read path it exists to undo, or it fails with "session expired" even when
 * a connection genuinely exists. `Options::remove()` has the same problem
 * one level deeper: it doesn't accept an explicit user id at all, so this
 * ability deletes the `_templately_*` user meta directly (mirroring
 * `Options::delete_user_meta()`'s own multisite/single-site branch) against
 * the acting user id resolved fresh at execute time.
 *
 * @package Templately\Modules\McpAbilities\Abilities
 */

namespace Templately\Modules\McpAbilities\Abilities;

use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\McpCore\Support\Permissions;
use Templately\Utils\Http;
use Templately\Utils\Options;

class AuthLogoutAbility {

	const ID = 'templately/auth-logout';

	/**
	 * Mirrors the meta keys `Templately\API\Login::delete()` clears, prefixed
	 * the same way `Options::set()`/`get()` auto-prefix every key.
	 *
	 * @var string[]
	 */
	const META_KEYS = [
		'_templately_user',
		'_templately_favourites',
		'_templately_reviews',
		'_templately_cloud_activity',
		'_templately_api_key',
	];

	public static function descriptor(): array {
		return [
			'id'                  => self::ID,
			'label'               => __( 'Disconnect Templately Account', 'templately' ),
			'description'         => __( 'Sign this site out of its connected Templately account.', 'templately' ),
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
			'access_level'        => ToolDescriptor::ACCESS_FULL,
			'annotations'         => [ 'readonly' => false, 'destructive' => true, 'idempotent' => true ],
		];
	}

	/**
	 * @param array $input
	 * @return array
	 */
	public static function execute( array $input ): array {
		$user_id = get_current_user_id();
		$api_key = Options::get_instance()->get( 'api_key', false, $user_id );

		$cloud_disconnected = null;

		if ( ! empty( $api_key ) ) {
			$response = Http::get_instance()->mutation(
				'disconnect',
				'status, message',
				[ 'api_key' => $api_key, 'site_url' => home_url( '/' ) ]
			)->post();

			$cloud_disconnected = ! is_wp_error( $response ) && ( $response['status'] ?? '' ) === 'success';
		}

		self::clear_local_meta( $user_id );

		return [
			'status'             => 'success',
			'cloud_disconnected' => $cloud_disconnected,
		];
	}

	/**
	 * @param int $user_id
	 * @return void
	 */
	private static function clear_local_meta( int $user_id ): void {
		$is_global = apply_filters( 'templately_multisite_is_global', false );

		foreach ( self::META_KEYS as $key ) {
			if ( is_multisite() ) {
				delete_user_option( $user_id, $key, $is_global );
			} else {
				delete_user_meta( $user_id, $key );
			}
		}
	}
}
