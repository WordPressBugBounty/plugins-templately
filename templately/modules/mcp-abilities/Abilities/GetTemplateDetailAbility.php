<?php
/**
 * `templately/get-template-detail` — full template detail + dependency
 * readiness ability (FR-002, FR-003).
 *
 * Wraps `Templately\API\Items` detail lookup + `Templately\API\Dependencies::
 * check_dependencies()` via in-process REST dispatch (research.md §2),
 * merging the two so an agent gets everything in a single call (SC-002).
 *
 * @package Templately\Modules\McpAbilities\Abilities
 */

namespace Templately\Modules\McpAbilities\Abilities;

use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\McpCore\Support\Permissions;
use Templately\Modules\McpCore\Support\RestDispatcher;
use WP_Error;

class GetTemplateDetailAbility {

	const ID = 'templately/get-template-detail';

	public static function descriptor(): array {
		return [
			'id'                  => self::ID,
			'label'               => __( 'Get Templately Template Detail', 'templately' ),
			'description'         => __( 'Retrieve full details for a single Templately template, including dependency readiness on this site.', 'templately' ),
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id' => [ 'type' => 'integer' ],
				],
				'required'   => [ 'id' ],
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
	 * @return array|WP_Error
	 */
	public static function execute( array $input ) {
		$id = (int) ( $input['id'] ?? 0 );

		if ( empty( $id ) ) {
			return new WP_Error( 'not_found', __( 'A template id is required.', 'templately' ) );
		}

		// NOTE: the route's own registered default for `type` ('block', singular) does not
		// match `get_query_types()`'s special-cased 'blocks'/'sections' (plural), so the
		// route's bare default throws `invalid_type_call` — pass 'items' explicitly (the
		// value 'blocks'/'sections' would themselves resolve to).
		$detail_response = RestDispatcher::dispatch( 'GET', '/templately/v1/items/' . $id, [ 'type' => 'items' ] );

		if ( is_wp_error( $detail_response ) ) {
			return $detail_response;
		}

		$item = $detail_response->get_data();

		if ( empty( $item ) || empty( $item['id'] ) ) {
			return new WP_Error( 'not_found', __( 'Template not found.', 'templately' ) );
		}

		$item['dependencies'] = self::resolve_dependency_status( $item['dependencies'] ?? [], $item['platform'] ?? 'elementor' );

		return $item;
	}

	/**
	 * Merge the template's declared dependencies with this site's install/active
	 * status via the existing Dependencies::check_dependencies() logic (FR-003).
	 *
	 * @param array  $dependencies
	 * @param string $platform
	 * @return array
	 */
	private static function resolve_dependency_status( array $dependencies, string $platform ): array {
		if ( empty( $dependencies ) ) {
			return [];
		}

		$response = RestDispatcher::dispatch( 'POST', '/templately/v1/dependencies/check', [
			'dependencies' => $dependencies,
			'platform'     => $platform,
		] );

		if ( is_wp_error( $response ) ) {
			// Dependency-status resolution failing should not hide the rest of the
			// template detail — surface the raw (unchecked) list instead.
			return $dependencies;
		}

		$data = $response->get_data();

		return $data['dependencies'] ?? $dependencies;
	}
}
