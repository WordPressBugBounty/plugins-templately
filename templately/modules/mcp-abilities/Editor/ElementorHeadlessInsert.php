<?php
/**
 * Headless (browser-free) Elementor content insertion into an EXISTING post
 * (FR-006, FR-009, FR-014) — the new capability this feature introduces.
 * Modeled on this environment's own proven sandbox-abilities technique
 * (research.md §3), reimplemented fresh here.
 *
 * @package Templately\Modules\McpAbilities\Editor
 */

namespace Templately\Modules\McpAbilities\Editor;

use Elementor\Core\Files\CSS\Post as ElementorPostCSS;
use Elementor\Plugin as ElementorPlugin;
use WP_Error;

class ElementorHeadlessInsert {

	/**
	 * @param int    $post_id
	 * @param array  $elements  Processed Elementor element nodes to append (from the
	 *                          `/import/insert` route's response `content`, research.md §3).
	 * @param string $base_hash md5 of `_elementor_data` as last read by the caller (FR-014).
	 * @return array|WP_Error
	 */
	public static function insert( int $post_id, array $elements, string $base_hash ) {
		if ( ! class_exists( ElementorPlugin::class ) ) {
			return new WP_Error( 'platform_mismatch', __( 'Elementor is not active on this site.', 'templately' ) );
		}

		// Conflict-checking only reads post meta, so it is verified before the
		// (more expensive) document-validity check — a caller's stale read is
		// reported as a conflict regardless of the post's editor type.
		$current_hash = self::hash( $post_id );

		if ( $current_hash !== $base_hash ) {
			return new WP_Error(
				'conflict',
				__( 'The post changed since it was last read. Re-read it and retry.', 'templately' ),
				[ 'current_state_hash' => $current_hash ]
			);
		}

		$document = ElementorPlugin::$instance->documents->get( $post_id );

		if ( ! $document ) {
			return new WP_Error( 'platform_mismatch', __( 'The target post is not an Elementor document.', 'templately' ) );
		}

		$tree = array_merge( $document->get_elements_data(), $elements );
		$saved = $document->save( [ 'elements' => $tree ] );

		if ( ! $saved ) {
			return new WP_Error( 'import_failed', __( 'Failed to save Elementor content into the target post.', 'templately' ) );
		}

		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		self::regenerate_css( $post_id );

		return [
			'target'  => 'post',
			'status'  => 'success',
			'post_id' => $post_id,
		];
	}

	/**
	 * @param int $post_id
	 * @return string
	 */
	public static function hash( int $post_id ): string {
		return md5( (string) get_post_meta( $post_id, '_elementor_data', true ) );
	}

	private static function regenerate_css( int $post_id ): void {
		delete_post_meta( $post_id, '_elementor_css' );

		if ( class_exists( ElementorPostCSS::class ) ) {
			ElementorPostCSS::create( $post_id )->update();
		}
	}
}
