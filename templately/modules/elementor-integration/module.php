<?php
/**
 * Elementor editor integration module (spec 032-elementor-integration).
 *
 * PARTIAL EXTRACTION (WIP — stopped mid-spec by explicit user instruction,
 * 2026-07-03): the PHP platform driver + importer are relocated here; the JS
 * side (react-src/elementor.js entry + react-src/elementor/*.js component
 * tree, 8 files, zero external imports — confirmed self-contained) and the
 * webpack entry migration are NOT done yet. `assets/js/` is intentionally
 * absent. Continue from here: move react-src/elementor.js ->
 * modules/elementor-integration/assets/js/index.js (or similar) +
 * react-src/elementor/ -> modules/elementor-integration/assets/js/elementor/
 * (git mv preserves the directory name so its 8 files' sibling imports need
 * zero edits — confirmed via grep, none of them import outside their own
 * directory), fix index.js's `./utils/sharedLibsBootstrap` + SCSS import
 * paths, add a webpack.config.partial.js using the ORIGINAL entry key
 * `elementor` (not `modules/elementor-integration` — PRD §1 R2 note: "editor
 * entry moves (032/033) keep their original key" since Platform/Elementor.php
 * hardcodes the enqueue as `js/elementor.js`/`css/elementor.css`, not the
 * modules/*.js auto-discovery glob), remove the old root `elementor:` entry
 * from webpack.config.js, then run the full standing verification (PHPUnit,
 * build, Jest, live sandbox check) before marking the PRD checklist item done.
 *
 * PHP relocated: includes/Core/Platform/Elementor.php (the platform driver —
 * create_page/import_in_library/is_eligible/ui_theme/delete/is_kit/
 * get_saved_templates/register_child_type_filter) + the importer class that
 * was misplaced in modules/full-site-import/ since spec 025 (its sole
 * consumer was always Platform/Elementor.php, confirmed via grep — moving it
 * here alongside its only caller is the correct home, not full-site-import).
 * Namespace: Templately\Core\Platform\Elementor -> this module's
 * Platform\Elementor; Templately\Modules\FullSiteImport\Elementor -> this
 * module's own Elementor (the importer, extends Elementor's Source_Local).
 *
 * CRITICAL ORDERING FIX applied during the PHP move: Plugin::platforms() used
 * to eagerly call Elementor::get_instance() during plugins_loaded — but that
 * runs BEFORE Modules_Manager::boot() registers this module's autoloader
 * (plugins_loaded calls platforms() then apis() then Modules_Manager::boot(),
 * in that fixed order), so leaving the eager call in Plugin.php would fatal
 * on class-not-found. Elementor's self-registration (which internally calls
 * Templately\Core\Platform_Registry::get_instance()->add() — the platform-adapter
 * registry, unrelated to Module_Base/Modules_Manager) now happens from this
 * module's own init_hooks() instead, which runs during Modules_Manager::boot()
 * — after the autoloader for this module's namespace is registered.
 * Confirmed via grep that nothing between platforms()/apis() and
 * Modules_Manager::boot() eagerly resolves Platform_Registry::get_instance()->active('elementor')
 * (all such calls happen later, during actual request handling — REST routes,
 * template rendering — never during the plugins_loaded bootstrap chain), so
 * this timing shift is safe.
 *
 * @package Templately
 */

namespace Templately\Modules\ElementorIntegration;

use Templately\Core\Module_Base;
use Templately\Modules\ElementorIntegration\Platform\Elementor;

class Module extends Module_Base {

	public function get_name(): string {
		return 'elementor-integration';
	}

	public function get_dependencies(): array {
		// create_page()/import_in_library() call ElementorSettingsMerger::merge()
		// from modules/elementor-kit-settings (spec 031). The importer + platform
		// driver also use full-site-import's Utils during import (spec 025). The
		// driver's create_page() constructs theme-builder's TemplateFactory
		// (Elementor.php) — the same one-directional structural edge
		// gutenberg-integration declares for its driver.
		return [ 'elementor-kit-settings', 'full-site-import', 'theme-builder' ];
	}

	protected function init_hooks(): void {
		Elementor::get_instance();

		// Inverted from FSI's RunsImport (which used to call into this module
		// directly — the one remaining FSI→platform-module code reference): every
		// import slice needs the Pro-promotion child-type filter active BEFORE the
		// runners touch Elementor documents, and templately_fsi_before_import fires
		// at exactly the point the inline call used to run. Idempotent (static
		// guard inside), and a no-op while Elementor is not yet installed — the
		// Dependencies runner may install it mid-import, and the next slice's
		// before-import fire picks it up, exactly as the inline check did.
		add_action( 'templately_fsi_before_import', static function () {
			if ( class_exists( '\Elementor\Plugin' ) ) {
				Elementor::register_child_type_filter();
			}
		} );
	}
}
