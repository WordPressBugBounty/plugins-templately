<?php

namespace Templately\Modules\BlockRecovery;

use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Utils\Base;

/**
 * The list of imported posts worth re-opening in a block editor after an import finishes.
 *
 * Written by PHP, consumed by the browser: `save()` is JS-only, so the repair itself cannot
 * happen here (see module.php). Persisted rather than passed through the SSE result because the
 * pass runs after the import request is gone, may be interrupted by the user closing the tab,
 * and must survive that — the queue is the resume point.
 */
class Queue extends Base {

	/** Where the pending queue lives. Autoload off — it is read on demand, by one screen. */
	const OPTION = 'templately_block_rebuild_queue';

	/** Post types that never carry block markup a rebuild could repair. */
	const SKIPPED_POST_TYPES = [ 'attachment', 'revision', 'nav_menu_item', 'customize_changeset' ];

	/**
	 * Queue entries older than this are dropped. Matches SessionData's own retention: a queue
	 * pointing at a week-old import is not worth acting on, and stale entries would otherwise
	 * accumulate on a site that imports repeatedly.
	 */
	const MAX_AGE = 7 * DAY_IN_SECONDS;

	public function __construct() {
		add_action( 'templately_fsi_import_complete', [ $this, 'capture' ] );

		// The generic producer seam. A single-template import writes a post to disk exactly the
		// way a full-site import does, so it needs the same follow-up — but hooking it onto
		// `templately_fsi_import_complete` would be a lie about where the content came from, and
		// FSI's listener reads FSI session state a single import does not have.
		add_action( 'templately_blocks_imported', [ $this, 'capture_posts' ], 10, 2 );
	}

	/**
	 * Queue specific posts, appending to whatever is already pending.
	 *
	 * APPEND, not replace: single imports arrive one at a time and each one is its own event —
	 * replacing would silently drop the previous page the moment the user imported a second.
	 * (A full site import DOES replace, via `capture()`: a new site supersedes the old queue.)
	 *
	 * @param int[]  $post_ids Posts just written.
	 * @param string $context  Where they came from, e.g. 'single' — recorded for reporting only.
	 */
	public function capture_posts( $post_ids, $context = 'single' ) {
		$items = self::eligible_items( is_array( $post_ids ) ? $post_ids : [ $post_ids ] );

		if ( empty( $items ) ) {
			return;
		}

		$queue = self::get();

		if ( empty( $queue ) ) {
			$queue = [
				'session_id'      => null,
				'created_at'      => time(),
				'items'           => [],
				'parity_warnings' => [],
				'done'            => [],
			];
		}

		$known = array_column( $queue['items'], 'id' );
		foreach ( $items as $item ) {
			if ( ! in_array( $item['id'], $known, true ) ) {
				$item['context']   = (string) $context;
				$queue['items'][]  = $item;
			}
		}

		// A re-queued post must be visited again even if an earlier pass marked it done — its
		// content just changed.
		$queue['done'] = array_values( array_diff(
			array_map( 'intval', $queue['done'] ?? [] ),
			array_column( $items, 'id' )
		) );

		self::put( $queue );
	}

	/**
	 * Filter post ids down to the ones a block editor could actually validate.
	 *
	 * @param array $post_ids
	 * @return array<int,array{id:int,type:string}>
	 */
	private static function eligible_items( array $post_ids ): array {
		$items = [];

		foreach ( array_unique( array_map( 'intval', $post_ids ) ) as $post_id ) {
			$post = get_post( $post_id );

			if ( ! $post || in_array( $post->post_type, self::SKIPPED_POST_TYPES, true ) ) {
				continue;
			}

			// No block delimiters means nothing for a block editor to validate.
			if ( false === strpos( (string) $post->post_content, '<!-- wp:' ) ) {
				continue;
			}

			$items[] = [
				'id'   => $post_id,
				'type' => $post->post_type,
			];
		}

		return $items;
	}

	/**
	 * Record what this import created, so the browser pass knows where to look.
	 *
	 * Deliberately records EVERY imported post rather than only the ones we suspect: the
	 * version-drift failure (cause A) happens in content this plugin never touched, so a
	 * suspicion list built from our own writes would miss exactly the case that motivated the
	 * pass. The merge-time parity warnings ride along as priority information, not as a filter.
	 */
	public function capture() {
		// Elementor content is JSON in post meta and has no `save()` validation, so there is
		// nothing for a block editor to repair. An Elementor pack still ships a few posts with
		// core-block markup (blog posts, WooCommerce pages), and queueing those cost an editor
		// boot per post for a pass that could never find anything — the whole "Optimising
		// imported blocks… (1/4)" wait on an Elementor import came from exactly that.
		if ( self::imported_platform() === 'elementor' ) {
			return;
		}

		$session_id = SessionData::get_session_id();

		$imported = get_option( 'templately_fsi_imported_list', [] );
		$post_ids = is_array( $imported ) && ! empty( $imported['posts'] ) ? $imported['posts'] : [];

		$items = self::eligible_items( $post_ids );

		if ( empty( $items ) ) {
			return;
		}

		$warnings = $session_id ? SessionData::get( $session_id, 'parity_warnings' ) : [];

		self::put(
			[
				'session_id'      => $session_id,
				'created_at'      => time(),
				'items'           => $items,
				'parity_warnings' => is_array( $warnings ) ? $warnings : [],
				'done'            => [],
			]
		);
	}

	/**
	 * Which editor the import that just finished was built for.
	 *
	 * Read from the option the import writes as it starts (`RunsImport`), because the
	 * `templately_fsi_import_complete` payload does not carry the platform.
	 */
	private static function imported_platform(): string {
		return (string) get_option( 'templately_import_platform', '' );
	}

	/**
	 * The current queue, or an empty array when there is nothing pending or it has expired.
	 *
	 * @return array
	 */
	public static function get(): array {
		$queue = get_option( self::OPTION, [] );

		if ( empty( $queue['items'] ) || ! is_array( $queue['items'] ) ) {
			return [];
		}

		if ( empty( $queue['created_at'] ) || ( time() - (int) $queue['created_at'] ) > self::MAX_AGE ) {
			self::clear();

			return [];
		}

		return $queue;
	}

	/**
	 * Items not yet reported as handled — what a (re-)run should actually visit.
	 *
	 * @return array<int,array{id:int,type:string}>
	 */
	public static function pending(): array {
		$queue = self::get();

		if ( empty( $queue ) ) {
			return [];
		}

		$done = ! empty( $queue['done'] ) && is_array( $queue['done'] ) ? array_map( 'intval', $queue['done'] ) : [];

		return array_values( array_filter( $queue['items'], function ( $item ) use ( $done ) {
			return ! in_array( (int) $item['id'], $done, true );
		} ) );
	}

	/**
	 * Is this one post still waiting for a visit?
	 *
	 * The on-open trigger's half of eligibility (060 FR-002): a post with no provenance yet —
	 * imported before single imports recorded it — is still repairable while its queue entry
	 * lives.
	 */
	public static function is_pending( int $post_id ): bool {
		foreach ( self::pending() as $item ) {
			if ( (int) $item['id'] === $post_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Mark one post as handled, whatever the outcome.
	 *
	 * A post that failed is still marked done: re-opening an editor that already refused to boot
	 * on the next run would just spend the same time again for the same answer, and the failure
	 * is reported separately.
	 */
	public static function mark_done( int $post_id ) {
		$queue = self::get();

		if ( empty( $queue ) ) {
			return;
		}

		$done = ! empty( $queue['done'] ) && is_array( $queue['done'] ) ? $queue['done'] : [];

		if ( ! in_array( $post_id, array_map( 'intval', $done ), true ) ) {
			$done[] = $post_id;
		}

		$queue['done'] = $done;
		self::put( $queue );
	}

	public static function put( array $queue ) {
		update_option( self::OPTION, $queue, false );
	}

	public static function clear() {
		delete_option( self::OPTION );
	}
}
