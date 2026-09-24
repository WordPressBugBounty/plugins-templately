<?php
/**
 * import-revert module — the "Revert to old website" surface.
 *
 * Carved out of modules/full-site-import (2026-07-23, follow-up of the
 * split-modules analysis): revert consumes what a FINISHED import left behind
 * (`__templately_*` backup options, `templately_fsi_imported_list`) and shares
 * no live pipeline state with the runners, so it is a real module boundary.
 *
 * Wiring stays inverted (spec 004 US6): FSI never imports this module —
 * this module contributes its handler through FSI's
 * `templately_fsi_ajax_handlers` filter seam (same pattern as
 * post-import-feedback), and answers the `templately_has_revert` filter that
 * `Core/Admin.php` / `FullSiteImport::has_revert()` read. The
 * `wp_ajax_templately_pack_import_revert` action name is a frozen contract.
 *
 * The JS bundle (assets/js/index.tsx) registers the `import-revert/revert-wrapper`
 * slot fill (App.tsx renders it via <Slot> when props.revert) and mounts the
 * revert admin-page chrome — auto-enqueued by Admin.php's module-asset glob.
 *
 * NOTE: this module's own "Revert Now" UI is NOT the same surface as
 * modules/settings' Miscellaneous tab button — both are real, neither is dead.
 *
 * @package Templately
 */

namespace Templately\Modules\ImportRevert;

use Templately\Core\Module_Base;

class Module extends Module_Base {

	public function get_name(): string {
		return 'import-revert';
	}

	/**
	 * Declared (not incidental): RevertController reads the backup-option map
	 * through full-site-import's `Utils::get_backup_options()`, and the handler
	 * itself is registered through FSI's ajax seam.
	 */
	public function get_dependencies(): array {
		return [ 'full-site-import' ];
	}

	protected function init_hooks(): void {
		// Supply the revert handler through FSI's seam (FSI applies this filter on
		// 'init' and runs each handler through its shared nonce/capability wrapper).
		add_filter( 'templately_fsi_ajax_handlers', [ $this, 'provide_ajax_handlers' ] );

		// The "is there anything to revert?" probe — read by Core/Admin.php's
		// localized config (and FullSiteImport::has_revert(), kept as a shim).
		add_filter( 'templately_has_revert', [ $this, 'filter_has_revert' ] );
	}

	/**
	 * Contribute the revert ajax handler (action name => handler object exposing
	 * a method of the same name).
	 */
	public function provide_ajax_handlers( array $handlers ): array {
		$handlers['import_revert'] = new RevertController();

		return $handlers;
	}

	/**
	 * @param bool $has_revert Incoming filter value.
	 * @return bool
	 */
	public function filter_has_revert( $has_revert ): bool {
		return $has_revert || RevertController::has_revert();
	}
}
