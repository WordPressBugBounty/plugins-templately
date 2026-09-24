<?php
/**
 * block-recovery module — repairs Gutenberg blocks that a finished import left invalid.
 *
 * Gutenberg validates a static block by re-running its JS `save(attributes)` and comparing the
 * output to the markup stored in the post. Two things make imported content fail that check:
 *
 *  1. The pack's markup was produced by a DIFFERENT version of the block plugin than the one
 *     installed here. When the site is BEHIND on a block whose `save()` changed, the stored
 *     markup is simply the old shape and can be regenerated losslessly.
 *  2. A merge wrote new copy into a block's attributes or its markup but not both. `WS1`
 *     closed the known cases at the source; this pass is the net for the rest.
 *
 * `save()` exists only in JS, and third-party blocks only register inside a block-editor
 * context, so no amount of PHP can regenerate this markup. The repair therefore runs in the
 * BROWSER (see `assets/js/`), and this module's PHP is only the queue + the report sink: the
 * Finalizer leaves a list of imported post ids, the browser walks it, and what it could not
 * repair comes back here to be surfaced.
 *
 * THE GATE IS THE POINT. `parse` silently drops any attribute the installed block type does not
 * declare, so regenerating a block from its parsed attributes DELETES whatever the newer plugin
 * version wrote. Measured on the SaaStark pack against Essential Blocks 6.4.0: a rebuild turned
 * 13 invalid blocks into 0 and stripped `data-gsap`/`ebGsap` from all 13 in the process —
 * "repairing" them into permanent data loss, when leaving them alone lets them heal by
 * themselves the moment the user updates the plugin. So a block is rebuilt only when nothing
 * would be dropped; everything else is reported, never touched.
 *
 * @package Templately
 */

namespace Templately\Modules\BlockRecovery;

use Templately\Core\Module_Base;

class Module extends Module_Base {

	/** Off switch for the whole pass. Detection still runs; only the repair stops. */
	const ENABLED_OPTION = 'templately_block_rebuild_enabled';

	public function get_name(): string {
		return 'block-recovery';
	}

	/**
	 * Declared: the queue is filled from full-site-import's `templately_fsi_import_complete`
	 * hook and reads its session data for the merge-time parity warnings.
	 */
	public function get_dependencies(): array {
		return [ 'full-site-import' ];
	}

	protected function init_hooks(): void {
		Queue::get_instance();
		// The on-open trigger (spec 060): enqueues the editor-side repair for eligible posts.
		Editor::get_instance();

		add_action( 'rest_api_init', function () {
			( new REST\Rebuild() )->register_routes();
		} );
	}

	/**
	 * Whether the browser-side repair may run.
	 *
	 * The queue is written either way, so the pass can be switched back on later for imports
	 * that already happened instead of losing them.
	 */
	public static function is_enabled(): bool {
		$enabled = (bool) get_option( self::ENABLED_OPTION, true );

		/**
		 * Filter whether the post-import block rebuild runs.
		 *
		 * @param bool $enabled
		 */
		return (bool) apply_filters( 'templately_block_rebuild_enabled', $enabled );
	}
}
