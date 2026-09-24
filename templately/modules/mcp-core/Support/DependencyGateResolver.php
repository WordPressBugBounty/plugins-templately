<?php
/**
 * Import-time dependency gating (FR-003, spec.md Edge Cases, analyze finding
 * G2): blocks an import before any changes are made when a required plugin
 * dependency is not active on this site.
 *
 * @package Templately\Modules\McpCore\Support
 */

namespace Templately\Modules\McpCore\Support;

use WP_Error;

class DependencyGateResolver {

	/**
	 * @param int    $template_id
	 * @param string $platform
	 * @return true|WP_Error True if the import may proceed; a WP_Error
	 *                       ('not_found' or 'dependency_required') otherwise.
	 */
	public static function check( int $template_id, string $platform = 'elementor' ) {
		$item = self::resolve_item( $template_id );

		if ( is_wp_error( $item ) ) {
			return $item;
		}

		if ( empty( $item ) || empty( $item['id'] ) ) {
			return new WP_Error( 'not_found', __( 'Template not found.', 'templately' ) );
		}

		$dependencies = $item['dependencies'] ?? [];

		if ( empty( $dependencies ) ) {
			return true;
		}

		$checked = RestDispatcher::dispatch( 'POST', '/templately/v1/dependencies/check', [
			'dependencies' => $dependencies,
			'platform'     => $platform,
		] );

		if ( is_wp_error( $checked ) ) {
			// Resolver failure should not itself block import — the real
			// import call will still surface any genuine problem.
			return true;
		}

		$data  = $checked->get_data();
		$unmet = array_values( array_filter( $data['dependencies'] ?? [], static function ( $dep ) {
			$is_active = is_object( $dep ) ? ( $dep->is_active ?? null ) : ( $dep['is_active'] ?? null );
			return empty( $is_active );
		} ) );

		if ( ! empty( $unmet ) ) {
			return new WP_Error(
				'dependency_required',
				__( 'This template requires plugins that are not active on this site.', 'templately' ),
				[ 'dependencies' => $unmet ]
			);
		}

		return true;
	}

	/**
	 * Resolve a template's item detail regardless of which backend collection
	 * it lives in.
	 *
	 * `/items/{id}` scopes its GraphQL lookup by the `type` param, and each
	 * template type lives in a different collection: block/section templates in
	 * `items`, page templates in `pages`, packs in `packs`. Hardcoding a single
	 * `type` here silently 404s ("Item data not found") for every other type —
	 * that is why page-type templates failed the dependency gate before the
	 * import could run. Try each collection until one resolves.
	 *
	 * @param int $template_id
	 * @return array|WP_Error The item detail array, or the last WP_Error.
	 */
	private static function resolve_item( int $template_id ) {
		$last = null;

		foreach ( [ 'items', 'pages', 'packs' ] as $type ) {
			$detail = RestDispatcher::dispatch( 'GET', '/templately/v1/items/' . $template_id, [ 'type' => $type ] );

			if ( is_wp_error( $detail ) ) {
				$last = $detail;
				continue;
			}

			$data = $detail->get_data();

			if ( ! empty( $data['id'] ) ) {
				return $data;
			}

			$last = $data;
		}

		if ( is_wp_error( $last ) ) {
			return $last;
		}

		return new WP_Error( 'not_found', __( 'Template not found.', 'templately' ) );
	}
}
