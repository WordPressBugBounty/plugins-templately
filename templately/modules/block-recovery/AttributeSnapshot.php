<?php

namespace Templately\Modules\BlockRecovery;

/**
 * Keeps the attributes a rebuild would otherwise delete.
 *
 * Regenerating a block does NOT rewrite only its markup — it rewrites the block comment too.
 * `parse` drops every attribute the installed block type does not declare, `createBlock` builds
 * from what survived, and `serialize` writes the comment from that. Measured on the SaaStark
 * pack with Essential Blocks Pro inactive: a rebuild took `data-gsap` 13 -> 0 in the markup AND
 * `"ebGsap"` 13 -> 0 in the comments. The animation config was simply gone, and activating Pro
 * afterwards could not bring it back.
 *
 * So the rebuild happens in two halves:
 *
 *   1. SNAPSHOT (here, before the editor saves) — read the stored comment JSON and remember
 *      every attribute, keyed by `blockId`.
 *   2. RESTORE (here, after the editor saves) — merge back any key the rebuild dropped.
 *
 * The result is exactly "regenerate the rendered HTML, leave the attributes alone": the markup
 * is what THIS site's `save()` produces, so the block validates, while the comment still carries
 * the Pro/newer-plugin configuration for the day that plugin shows up. PHP's `serialize_block()`
 * writes whatever is in `attrs` — unlike the JS serializer, it does not filter against the
 * registered block type — which is why the restore has to happen on this side.
 */
class AttributeSnapshot {

	/** Where a post's pre-rebuild attributes live between the two halves. */
	const META_KEY = '_templately_block_attr_snapshot';

	/**
	 * Record every block's stored attributes, keyed by `blockId`.
	 *
	 * `blockId` rather than document position: it is the only identifier shared between the
	 * stored markup and the editor's parsed tree that survives a deprecation migration, and
	 * every block that can carry an unknown attribute (Essential Blocks and friends) has one.
	 * A block without one is not snapshotted, and the gate keeps refusing to rebuild it.
	 *
	 * @param int $post_id
	 * @return int Number of blocks recorded.
	 */
	public static function capture( int $post_id ): int {
		$post = get_post( $post_id );

		if ( ! $post ) {
			return 0;
		}

		$map = self::attributes_by_block_id( (string) $post->post_content );

		if ( empty( $map ) ) {
			delete_post_meta( $post_id, self::META_KEY );

			return 0;
		}

		// wp_slash: update_post_meta unslashes, and these values are JSON-ish arrays that can
		// legitimately contain backslashes (unicode escapes in EB's generated CSS).
		update_post_meta( $post_id, self::META_KEY, wp_slash( wp_json_encode( $map ) ) );

		return count( $map );
	}

	/**
	 * Merge back whatever the rebuild dropped, then rewrite the post if anything changed.
	 *
	 * Only ADDS keys that are missing. A key the rebuild kept is left exactly as the editor
	 * wrote it — the editor is authoritative for anything this site understands; the snapshot is
	 * authoritative only for what it could not see.
	 *
	 * @param int $post_id
	 * @return array{restored:int,blocks:int} Attributes restored, and how many blocks got them.
	 */
	public static function restore( int $post_id ): array {
		$result = [ 'restored' => 0, 'blocks' => 0 ];

		$post = get_post( $post_id );
		$raw  = get_post_meta( $post_id, self::META_KEY, true );

		if ( ! $post || empty( $raw ) ) {
			return $result;
		}

		$snapshot = json_decode( (string) $raw, true );

		if ( ! is_array( $snapshot ) || empty( $snapshot ) ) {
			delete_post_meta( $post_id, self::META_KEY );

			return $result;
		}

		$blocks  = parse_blocks( (string) $post->post_content );
		$changed = false;

		$walk = function ( array &$list ) use ( &$walk, $snapshot, &$result, &$changed ) {
			foreach ( $list as &$block ) {
				$block_id = $block['attrs']['blockId'] ?? null;

				if ( is_string( $block_id ) && isset( $snapshot[ $block_id ] ) && is_array( $snapshot[ $block_id ] ) ) {
					$missing = array_diff_key( $snapshot[ $block_id ], $block['attrs'] );

					if ( ! empty( $missing ) ) {
						$block['attrs']    = array_merge( $block['attrs'], $missing );
						$result['restored'] += count( $missing );
						$result['blocks']++;
						$changed = true;
					}
				}

				if ( ! empty( $block['innerBlocks'] ) ) {
					$walk( $block['innerBlocks'] );
				}
			}
		};
		$walk( $blocks );

		if ( $changed ) {
			// Bypass kses for this write. On MULTISITE `unfiltered_html` belongs to super admins
			// only, so an ordinary site administrator running the pass would have their own
			// already-stored content re-filtered on the way back in — and kses can only ever
			// remove from it. This write adds nothing new: every byte is either what the editor
			// just saved or what was already in the post when we snapshotted it, so re-sanitising
			// it buys no safety and risks corrupting block markup.
			$kses_was_active = has_filter( 'content_save_pre', 'wp_filter_post_kses' );

			if ( $kses_was_active ) {
				kses_remove_filters();
			}

			wp_update_post(
				[
					'ID'           => $post_id,
					'post_content' => wp_slash( serialize_blocks( $blocks ) ),
				]
			);

			if ( $kses_was_active ) {
				kses_init_filters();
			}
		}

		delete_post_meta( $post_id, self::META_KEY );

		return $result;
	}

	/**
	 * Attributes of every block comment that carries a `blockId`, keyed by it.
	 *
	 * Reads the RAW comments rather than `parse_blocks()` output: `parse_blocks` hands back
	 * whatever the JSON decoded to, which is what we want here — but going through the parser
	 * would tie this to WP's block registry, and the whole point is to capture attributes the
	 * registry knows nothing about.
	 *
	 * @param string $content
	 * @return array<string,array<string,mixed>>
	 */
	public static function attributes_by_block_id( string $content ): array {
		$map = [];

		if ( '' === $content ) {
			return $map;
		}

		// Block comments are single-line and never nest, so a non-greedy match to the closing
		// delimiter cannot swallow the following block.
		if ( ! preg_match_all( '#<!--\s+wp:[a-z0-9-]+/[a-z0-9-]+\s+(\{.*?\})\s*/?-->#s', $content, $matches ) ) {
			return $map;
		}

		$seen = [];

		foreach ( $matches[1] as $json ) {
			$attrs = json_decode( $json, true );

			if ( ! is_array( $attrs ) || ! isset( $attrs['blockId'] ) || ! is_string( $attrs['blockId'] ) ) {
				continue;
			}

			$block_id = $attrs['blockId'];

			// A duplicated blockId (copy/paste in the editor, or a pack that repeats a section)
			// would make the restore apply one block's attributes to a different block. There is
			// no way to tell them apart afterwards, so drop the id entirely rather than guess —
			// those blocks keep the conservative treatment and are left as imported.
			if ( isset( $seen[ $block_id ] ) ) {
				unset( $map[ $block_id ] );
				continue;
			}

			$seen[ $block_id ] = true;
			$map[ $block_id ]  = $attrs;
		}

		return $map;
	}
}
