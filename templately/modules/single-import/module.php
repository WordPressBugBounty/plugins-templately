<?php
/**
 * Single-import module (spec 020-single-template-import).
 *
 * Single-template import across platforms: the in-editor insert endpoint, library
 * import, dashboard-initiated page creation, and the plugin/theme dependency
 * check+install surface. Relocated from includes/API/ — behavior byte-identical
 * (same routes, params). Dependencies.php was assigned here by the modularization
 * PRD's ownership ruling (overriding spec 017's metadata-endpoints listing).
 *
 * @package Templately
 */

namespace Templately\Modules\SingleImport;

use Templately\Core\Module_Base;
use Templately\Modules\SingleImport\REST\Dependencies;
use Templately\Modules\SingleImport\REST\Import;

class Module extends Module_Base {

	public function get_name(): string {
		return 'single-import';
	}

	public function get_dependencies(): array {
		// REST/Import.php calls full-site-import's Utils::add_gd_editor_filter() during
		// import (spec 025). (The elementor-/gutenberg-integration references stay
		// baselined — they are docblock-only @method type hints; the driver is resolved
		// through the Module string-ID locator, not the class reference.)
		// `pro-plugin-provisioning` (spec 059) is deliberately NOT listed: the only
		// reach is a build-time TypeScript import of its catalog twin, and declaring
		// it would make Modules_Manager skip THIS module whenever that one is inactive.
		// Baselined as an intentional reach-in instead.
		return [ 'full-site-import' ];
	}

	public function register_rest_routes(): void {
		Import::get_instance()->register_routes();
		Dependencies::get_instance()->register_routes();
	}
}
