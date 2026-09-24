<?php
/**
 * Headless (browser-free) Gutenberg content insertion into an EXISTING post
 * (FR-006, FR-009, FR-014) — the new capability this feature introduces.
 * Modeled on this environment's own proven sandbox-abilities technique
 * (research.md §3), reimplemented fresh here.
 *
 * @package Templately\Modules\McpAbilities\Editor
 */

namespace Templately\Modules\McpAbilities\Editor;

use WP_Error;

class GutenbergHeadlessInsert {

	/**
	 * @param int    $post_id
	 * @param string $block_markup Processed block markup to append (from the
	 *                             `/import/insert` route's response `content`, research.md §3).
	 * @param string $base_hash    md5 of `post_content` as last read by the caller (FR-014).
	 * @return array|WP_Error
	 */
	public static function insert( int $post_id, string $block_markup, string $base_hash ) {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'not_found', __( 'Target post not found.', 'templately' ) );
		}

		// A post already carrying Elementor data is not a Gutenberg document.
		if ( ! empty( get_post_meta( $post_id, '_elementor_edit_mode', true ) ) ) {
			return new WP_Error( 'platform_mismatch', __( 'The target post is an Elementor document, not Gutenberg.', 'templately' ) );
		}

		$current_hash = self::hash( $post_id );

		if ( $current_hash !== $base_hash ) {
			return new WP_Error(
				'conflict',
				__( 'The post changed since it was last read. Re-read it and retry.', 'templately' ),
				[ 'current_state_hash' => $current_hash ]
			);
		}

		$blocks     = parse_blocks( $post->post_content );
		$new_blocks = parse_blocks( $block_markup );
		$content    = serialize_blocks( array_merge( $blocks, $new_blocks ) );

		$updated = wp_update_post( [ 'ID' => $post_id, 'post_content' => $content ], true );

		if ( is_wp_error( $updated ) ) {
			return $updated;
		}

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
		$post = get_post( $post_id );

		return md5( $post ? (string) $post->post_content : '' );
	}
}
