<?php

namespace Templately\Modules\BlockPatterns\REST;

use Templately\API\API;
use Templately\Modules\BlockPatterns\MediaLocalizer;
use Templately\Modules\BlockPatterns\PatternSync;
use Templately\Modules\FullSiteImport\Utils\PackInfoFetcher;
use WP_REST_Request;

/**
 * On-demand pattern content for the lazy-pattern block.
 *
 * Only reachable for ids that are in the current plan-keyed catalog cache, so
 * this cannot be used to pull arbitrary items: the plan gate that decides what
 * is registered also decides what is fetchable.
 */
class Content extends API {
	private $endpoint = 'block-patterns/content';

	public function permission_check( WP_REST_Request $request ) {
		$this->request = $request;

		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	public function register_routes() {
		$this->get( $this->endpoint, [ $this, 'get_content' ] );
		$this->post( 'block-patterns/localize', [ $this, 'localize' ] );
	}

	/**
	 * Import a pattern's media and return a remote-URL => local-URL map.
	 *
	 * Split from get_content on purpose: the editor shows the design first with
	 * its demo URLs and swaps them once this returns, so insertion never waits
	 * on image downloads.
	 */
	public function localize() {
		$id      = $this->get_param( 'id', 0, 'absint' );
		$post_id = $this->get_param( 'post_id', 0, 'absint' );
		$sync    = PatternSync::get_instance();

		if ( ! $id || null === $sync->plan_key() ) {
			return $this->success( [ 'map' => (object) [] ] );
		}

		$list  = $sync->get_list();
		$known = $list ? in_array( $id, array_map( 'absint', wp_list_pluck( $list['items'], 'id' ) ), true ) : false;
		if ( ! $known && ! $sync->was_searched( $id ) ) {
			return $this->success( [ 'map' => (object) [] ] );
		}

		$content = $sync->get_content( $id );
		if ( ! is_string( $content ) || '' === $content ) {
			return $this->success( [ 'map' => (object) [] ] );
		}

		$map = MediaLocalizer::get_instance()->build_url_map( $content, $post_id );

		return $this->success( [ 'map' => empty( $map ) ? (object) [] : $map ] );
	}

	public function get_content() {
		$id   = $this->get_param( 'id', 0, 'absint' );
		$sync = PatternSync::get_instance();

		if ( ! $id ) {
			return $this->error( 'invalid_id', __( 'A pattern id is required.', 'templately' ), $this->endpoint, 400 );
		}

		if ( null === $sync->plan_key() ) {
			return $this->error(
				'not_connected',
				__( 'Connect your Templately account to use this pattern.', 'templately' ),
				$this->endpoint,
				403
			);
		}

		// The catalog cache is the allow-list: an id the current plan never synced
		// is not fetchable, so plan gating holds on this path too.
		$list  = $sync->get_list();
		$record = $this->find_item( $list, $id );
		$known  = null !== $record;

		// A live-searched id is not in the cached catalog by definition. It is
		// still gated: `PatternSync::search()` applied the same plan filter before
		// recording it against this user, so the allow-list is an extension of the
		// same boundary, not a hole in it.
		if ( ! $known ) {
			$known = $sync->was_searched( $id );
		}

		if ( ! $known ) {
			return $this->error(
				'unknown_pattern',
				__( 'That pattern is not available on your plan.', 'templately' ),
				$this->endpoint,
				404
			);
		}

		$content = $sync->get_content( $id );

		if ( ! is_string( $content ) || '' === $content ) {
			return $this->error(
				'content_unavailable',
				__( 'This pattern could not be loaded. Please try again.', 'templately' ),
				$this->endpoint,
				502
			);
		}

		// Apply the pack's global colours and typography, exactly as single-import
		// does. Without this the inserter produced a visibly different result from
		// importing the SAME design through the library: identical markup, none of
		// the pack's palette. A settings failure is non-fatal there and is non-fatal
		// here — the design is worth more than its palette.
		$settings = $this->pack_settings_for( $record );
		if ( ! empty( $settings ) ) {
			$content = apply_filters( 'templately_fsi_merge_settings', $content, $settings );
		}

		// What the CATALOG says this design needs. Shipped with the markup rather
		// than inferred from it in the browser: a block namespace does not identify
		// a plugin (a free plugin can register its paid tier's block names as upsell
		// stubs, so the registry reports nothing missing), and this is the same
		// field the full-site and single-import dependency steps already trust.
		// Absent for a live-searched id or a cache written before this field existed
		// — the client falls back to scanning the markup in that case.
		return $this->success( [
			'content'  => $content,
			'requires' => is_array( $record['requires'] ?? null ) ? array_values( $record['requires'] ) : null,
		] );
	}

	/**
	 * The owning pack's global settings for a cached catalog record.
	 *
	 * Returns an empty array whenever anything is missing rather than failing the
	 * request: a standalone design has no pack, a live-searched id has no cached
	 * record at all, and a cache written before `pack` was queried carries neither
	 * field. All three are the pre-existing behaviour — unstyled but working.
	 *
	 * `has_settings` is checked before the fetch, not after: it is the catalog's
	 * own answer to "is there anything to merge", so trusting it saves a round trip
	 * to the cloud for every design that has no globals.
	 *
	 * @param array<string,mixed>|null $record Cached catalog record, or null.
	 * @return array<string,mixed>
	 */
	private function pack_settings_for( $record ): array {
		if ( ! is_array( $record ) || empty( $record['has_settings'] ) ) {
			return [];
		}

		$pack_id = absint( $record['pack_id'] ?? 0 );
		if ( ! $pack_id ) {
			return [];
		}

		$data = ( new PackInfoFetcher() )->fetch( $pack_id, false );
		if ( is_wp_error( $data ) || empty( $data['data']['settings'] ) ) {
			return [];
		}

		$settings = json_decode( $data['data']['settings'], true );

		return is_array( $settings ) ? $settings : [];
	}

	/**
	 * The cached catalog record for an id, or null when it is not in this plan's list.
	 *
	 * @param mixed $list Cached list payload.
	 * @return array<string,mixed>|null
	 */
	private function find_item( $list, int $id ) {
		foreach ( (array) ( $list['items'] ?? [] ) as $item ) {
			if ( absint( $item['id'] ?? 0 ) === $id ) {
				return (array) $item;
			}
		}

		return null;
	}
}
