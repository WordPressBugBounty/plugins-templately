<?php

namespace Templately\Modules\BlockRecovery;

use Templately\Modules\FullSiteImport\Runners\BaseRunner;
use Templately\Utils\Base;

/**
 * The on-open trigger's server half (spec 060 US1).
 *
 * When a user opens a post in the block editor, decide — here, at enqueue time — whether the
 * repair should run in that editor session, and if so put the editor-side bundle on the page
 * with its config. Deciding in PHP means a clean open costs no request: everything the decision
 * needs (post id, capability, the kill switch, provenance, the queue) is already in hand.
 *
 * Eligible = the current user can edit the post AND the pass is enabled AND the post was
 * imported by Templately. "Imported" is answered by provenance meta (durable — a page opened a
 * year later still qualifies) OR by a pending queue entry (the bridge for content imported
 * before single imports recorded provenance; see 060 FR-002).
 *
 * The bundle is a screen-bound entry (constitution XIII): its only host is the block editor,
 * so it is enqueued by path, only here, and never joins the SPA graph.
 */
class Editor extends Base {

	const HANDLE = 'templately-block-recovery-editor';

	/** How long the editor bundle waits for the editor to parse blocks before giving up. */
	const READY_TIMEOUT_MS = 15000;

	public function __construct() {
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue' ] );
	}

	/**
	 * @return void
	 */
	public function enqueue() {
		$post_id = (int) get_the_ID();

		// `post-new.php` has no post yet — and nothing imported is ever "new".
		if ( $post_id <= 0 || ! current_user_can( 'edit_post', $post_id ) || ! Module::is_enabled() ) {
			return;
		}

		$reason = self::eligibility( $post_id );

		if ( '' === $reason ) {
			return;
		}

		templately()->assets->enqueue(
			self::HANDLE,
			'js/block-recovery-editor.js',
			[ 'wp-data', 'wp-blocks', 'wp-editor', 'wp-api-fetch' ]
		);

		templately()->assets->localize(
			self::HANDLE,
			'templatelyBlockRecoveryEditor',
			[
				'postId'         => $post_id,
				'trigger'        => Report::TRIGGER_ON_OPEN,
				'reason'         => $reason,
				'readyTimeoutMs' => self::READY_TIMEOUT_MS,
			]
		);
	}

	/**
	 * Why a post qualifies for on-open repair, or '' when it does not.
	 *
	 * @param int $post_id
	 * @return string 'provenance' | 'queued' | 'both' | ''
	 */
	public static function eligibility( int $post_id ): string {
		$provenance = metadata_exists( 'post', $post_id, BaseRunner::META_SESSION_KEY );
		$queued     = Queue::is_pending( $post_id );

		if ( $provenance && $queued ) {
			return 'both';
		}
		if ( $provenance ) {
			return 'provenance';
		}
		if ( $queued ) {
			return 'queued';
		}

		return '';
	}
}
