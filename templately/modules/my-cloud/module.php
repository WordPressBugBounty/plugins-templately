<?php
/**
 * My-cloud module (spec 028-my-cloud).
 *
 * Personal cloud file storage: MyClouds.php (list/usage/upload/download/delete/
 * copy-move) + SavedTemplates.php (local Elementor library listing/deletion).
 * Relocated verbatim from includes/API/ — behavior byte-identical (same routes,
 * params, permission logic).
 *
 * SCOPE NOTE: this spec's own Ownership section nests workspace routes
 * (clouds/workspace, clouds/workspace/:slug, clouds/shared-workspace,
 * clouds/shared-workspace/:slug) under the /clouds SPA subtree, but that
 * functionality (WorkSpaces.php REST, the WorkSpace* React components, the
 * workspace/sharedWorkspace/workspaceDetails redux slices) is owned by the
 * separate, not-yet-extracted spec 030-workspaces. Genuinely shared UI
 * (CloudsLayout, Actions, ViewButtons, ContextMenu, CloudFileName, CloudSidebar —
 * all consumed by 3+ specs including 029-profile-collections' favourites/downloads/
 * purchased pages) intentionally stays in react-src/ for now rather than being
 * force-owned by this module; each stays cross-referenced. See this module's
 * assets/js/CLAUDE.md and data-model.md's "Module Home" section for the full
 * boundary reasoning.
 *
 * @package Templately
 */

namespace Templately\Modules\MyCloud;

use Templately\Core\Module_Base;
use Templately\Modules\MyCloud\REST\MyClouds;
use Templately\Modules\MyCloud\REST\SavedTemplates;

class Module extends Module_Base {

	public function get_name(): string {
		return 'my-cloud';
	}

	public function register_rest_routes(): void {
		MyClouds::get_instance()->register_routes();
		SavedTemplates::get_instance()->register_routes();
	}
}
