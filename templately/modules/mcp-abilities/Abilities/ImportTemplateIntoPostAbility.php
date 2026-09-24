<?php
/**
 * `templately/import-template-into-post` — insert a single template's
 * content into an existing post, headlessly (FR-006, FR-008, FR-009, FR-014).
 *
 * The new capability this feature introduces: no server-side equivalent
 * existed before (research.md §3). Fetches the template's processed content
 * via the existing `/insert` route (the same one the live editor
 * would otherwise merge client-side) and delegates the actual merge to
 * `Editor\{ElementorHeadlessInsert,GutenbergHeadlessInsert}`, which own the
 * conflict (FR-014) and platform-compatibility (FR-009) checks.
 *
 * @package Templately\Modules\McpAbilities\Abilities
 */

namespace Templately\Modules\McpAbilities\Abilities;

use Templately\Modules\McpAbilities\Editor\ElementorHeadlessInsert;
use Templately\Modules\McpAbilities\Editor\GutenbergHeadlessInsert;
use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\McpCore\Support\DependencyGateResolver;
use Templately\Modules\McpCore\Support\ImportRateLimiter;
use Templately\Modules\McpCore\Support\Permissions;
use Templately\Modules\McpCore\Support\ProGateResolver;
use Templately\Modules\McpCore\Support\RestDispatcher;
use WP_Error;

class ImportTemplateIntoPostAbility {

	const ID = 'templately/import-template-into-post';

	public static function descriptor(): array {
		return [
			'id'                  => self::ID,
			'label'               => __( 'Insert Templately Template into Existing Post', 'templately' ),
			'description'         => __( 'Merge a single Templately template\'s content into an existing post, without needing a live editor session.', 'templately' ),
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'        => [ 'type' => 'integer', 'description' => __( 'Template ID to insert.', 'templately' ) ],
					'post_id'   => [ 'type' => 'integer', 'description' => __( 'Existing post to modify.', 'templately' ) ],
					'base_hash' => [ 'type' => 'string', 'description' => __( 'md5 of the post\'s last-read relevant content (see templately/get-template-detail\'s sibling read for the target post).', 'templately' ) ],
					'platform'  => [ 'type' => 'string', 'enum' => [ 'elementor', 'gutenberg' ], 'default' => 'elementor' ],
				],
				'required'   => [ 'id', 'post_id', 'base_hash' ],
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
			'permission_callback' => [ self::class, 'check_permission' ],
			'access_level'        => ToolDescriptor::ACCESS_FULL,
			'annotations'         => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
		];
	}

	/**
	 * FR-007 (manage_options) + FR-008 (target post must exist and be editable).
	 *
	 * @param array $input
	 * @return bool
	 */
	public static function check_permission( array $input ): bool {
		if ( ! Permissions::can_use_abilities() ) {
			return false;
		}

		$post_id = (int) ( $input['post_id'] ?? 0 );

		return $post_id > 0 && Permissions::can_edit_post( $post_id );
	}

	/**
	 * @param array $input
	 * @return array|WP_Error
	 */
	public static function execute( array $input ) {
		$id        = (int) ( $input['id'] ?? 0 );
		$post_id   = (int) ( $input['post_id'] ?? 0 );
		$base_hash = (string) ( $input['base_hash'] ?? '' );
		$platform  = $input['platform'] ?? 'elementor';

		if ( empty( $id ) || empty( $post_id ) || $base_hash === '' ) {
			return new WP_Error( 'not_found', __( 'A template id, target post id, and base_hash are all required.', 'templately' ) );
		}

		$rate_limit_error = ImportRateLimiter::check();
		if ( $rate_limit_error ) {
			return $rate_limit_error;
		}

		$dependency_check = DependencyGateResolver::check( $id, $platform );
		if ( is_wp_error( $dependency_check ) ) {
			return $dependency_check;
		}

		$content_response = RestDispatcher::dispatch( 'POST', '/templately/v1/insert', [
			'platform' => $platform,
			'origin'   => 'remote',
			'id'       => $id,
			'postId'   => $post_id,
		] );

		if ( is_wp_error( $content_response ) ) {
			return ProGateResolver::to_purchase_error( $content_response, $id );
		}

		$processed = $content_response->get_data();

		if ( empty( $processed['content'] ) ) {
			return new WP_Error( 'import_failed', __( 'Template content could not be prepared for insertion.', 'templately' ) );
		}

		if ( $platform === 'gutenberg' ) {
			return GutenbergHeadlessInsert::insert( $post_id, (string) $processed['content'], $base_hash );
		}

		return ElementorHeadlessInsert::insert( $post_id, (array) $processed['content'], $base_hash );
	}
}
