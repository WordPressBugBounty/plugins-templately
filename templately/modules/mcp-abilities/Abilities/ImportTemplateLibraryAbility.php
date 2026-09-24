<?php
/**
 * `templately/import-template-to-library` — save a single template into the
 * site's Templately Library for later reuse (FR-005).
 *
 * Wraps `Templately\API\Import::import_in_library()` via in-process REST
 * dispatch (research.md §2), reusing US2's shared rate-limit, dependency-gate,
 * and Pro-gate infrastructure (tasks.md Notes — built once, not duplicated).
 *
 * `platform` is a REQUIRED input (Auth Tools addendum) — see
 * ImportTemplatePageAbility's docblock for why: `import_in_library()`
 * silently defaults to `'elementor'` when omitted, mishandling any
 * Gutenberg template.
 *
 * @package Templately\Modules\McpAbilities\Abilities
 */

namespace Templately\Modules\McpAbilities\Abilities;

use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\McpCore\Support\DependencyGateResolver;
use Templately\Modules\McpCore\Support\ImportRateLimiter;
use Templately\Modules\McpCore\Support\Permissions;
use Templately\Modules\McpCore\Support\ProGateResolver;
use Templately\Modules\McpCore\Support\RestDispatcher;
use WP_Error;

class ImportTemplateLibraryAbility {

	const ID = 'templately/import-template-to-library';

	public static function descriptor(): array {
		return [
			'id'                  => self::ID,
			'label'               => __( 'Save Templately Template to Library', 'templately' ),
			'description'         => __( 'Save a single Templately template into this site\'s Templately Library for later reuse.', 'templately' ),
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'       => [ 'type' => 'integer' ],
					'platform' => [ 'type' => 'string', 'enum' => [ 'elementor', 'gutenberg' ] ],
				],
				'required'   => [ 'id', 'platform' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'target'       => [ 'type' => 'string' ],
					'status'       => [ 'type' => 'string', 'enum' => [ 'success', 'partial' ] ],
					'post_id'      => [ 'type' => 'integer' ],
					'failed_parts' => [ 'type' => 'array' ],
				],
			],
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => [ Permissions::class, 'can_use_abilities' ],
			'access_level'        => ToolDescriptor::ACCESS_FULL,
			'annotations'         => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
		];
	}

	/**
	 * @param array $input
	 * @return array|WP_Error
	 */
	public static function execute( array $input ) {
		$id       = (int) ( $input['id'] ?? 0 );
		$platform = (string) ( $input['platform'] ?? '' );

		if ( empty( $id ) ) {
			return new WP_Error( 'not_found', __( 'A template id is required.', 'templately' ) );
		}

		if ( ! in_array( $platform, [ 'elementor', 'gutenberg' ], true ) ) {
			return new WP_Error( 'invalid_platform', __( 'platform must be "elementor" or "gutenberg".', 'templately' ) );
		}

		$rate_limit_error = ImportRateLimiter::check();
		if ( $rate_limit_error ) {
			return $rate_limit_error;
		}

		$dependency_check = DependencyGateResolver::check( $id );
		if ( is_wp_error( $dependency_check ) ) {
			return $dependency_check;
		}

		$response = RestDispatcher::dispatch( 'POST', '/templately/v1/import', [ 'id' => $id, 'platform' => $platform ] );

		if ( is_wp_error( $response ) ) {
			return ProGateResolver::to_purchase_error( $response, $id );
		}

		$data = $response->get_data();

		if ( empty( $data['post_id'] ) ) {
			return new WP_Error( 'import_failed', __( 'Import failed for an unknown reason.', 'templately' ) );
		}

		return [
			'target'  => 'library',
			'status'  => 'success',
			'post_id' => (int) $data['post_id'],
		];
	}
}
