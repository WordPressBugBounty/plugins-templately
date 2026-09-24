<?php
/**
 * Workspaces module (spec 030-workspaces).
 *
 * Collaborative workspaces: CRUD, sharing/membership, browsing, the workspace
 * switcher, and workspace-scoped file operations (add/delete). Relocated
 * verbatim from includes/API/WorkSpaces.php — behavior byte-identical (same
 * routes, params, permission logic).
 *
 * SCOPE NOTE: copy/move of a personal-cloud file into a workspace, and
 * delete/download of a personal-cloud file, are served by modules/my-cloud's
 * (spec 028) endpoints — this module does not duplicate that surface (see
 * spec.md's Ownership & Artifacts section). The genuinely-shared UI consumed
 * by both this module and modules/my-cloud (CloudsLayout, Actions,
 * ViewButtons, ContextMenu, CloudFileName, CloudSidebar, the multi-source
 * Add-File/CopyMove/DeleteFile modals, and the mixed clouds+workspace redux
 * actions/saga files) intentionally stays in react-src/ rather than being
 * force-owned by either module. See this module's assets/js/CLAUDE.md and
 * data-model.md's "Module Home" section for the full boundary reasoning.
 *
 * @package Templately
 */

namespace Templately\Modules\Workspaces;

use Templately\Core\Module_Base;
use Templately\Modules\Workspaces\REST\WorkSpaces;

class Module extends Module_Base {

	public function get_name(): string {
		return 'workspaces';
	}

	public function register_rest_routes(): void {
		WorkSpaces::get_instance()->register_routes();
	}
}
