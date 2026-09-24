<?php
/**
 * Gutenberg editor integration module (spec 033-gutenberg-integration).
 *
 * PHP relocated: includes/Core/Platform/Gutenberg.php (the platform driver —
 * create_page/insert/import_in_library/print_admin_js_template/
 * update_gutenberg_hide_buttons/ui_theme) +
 * modules/full-site-import/Utils/GutenbergHelper.php (FSI block-tree
 * processing — parse_images/process_images/replace/replace_archive_id) +
 * modules/full-site-import/Utils/GutenbergSettingsMerger.php (Essential
 * Blocks global colors/typography resolution). Namespace:
 * Templately\Core\Platform\Gutenberg -> this module's Platform\Gutenberg;
 * Templately\Modules\FullSiteImport\Utils\{GutenbergHelper,
 * GutenbergSettingsMerger} -> this module's own Utils\*.
 *
 * NOT relocated: modules/full-site-import/Runners/GutenbergContent.php (the
 * FSI runner, get_name()==='content'). Confirmed via grep it calls
 * $this->loop() directly (twice, for the outer post-type loop and the inner
 * per-post loop) — per the resume-key-identity gotcha documented in
 * modules/full-site-import/Runners/CLAUDE.md ("Resume key") and established
 * in spec 031 (ElementorContent stayed for the same reason), relocating a
 * runner class that calls loop() directly would change its Class::method
 * resume-key identity and strand in-flight imports. GutenbergHelper, by
 * contrast, does NOT call $this->loop() itself (confirmed via grep) — the
 * Loop trait's resume context is derived ONCE by the runner's own loop()
 * call and threaded into the helper as a parameter, so GutenbergHelper is
 * safe to relocate despite extending ImportHelper (which `use`s the Loop
 * trait). This mirrors spec 032, which moved ElementorSettingsMerger (031)
 * but left ElementorHelper.php in place — here the equivalent split moves
 * BOTH Gutenberg utility classes because this spec's own FR-006..FR-009/
 * FR-015/FR-016 explicitly own GutenbergHelper (unlike 032, whose spec.md
 * never claimed ElementorHelper).
 *
 * CRITICAL ORDERING FIX applied during the PHP move (identical to spec 032's
 * Elementor fix): Plugin::platforms() used to eagerly call
 * Gutenberg::get_instance() during plugins_loaded — but that runs BEFORE
 * Modules_Manager::boot() registers this module's autoloader (plugins_loaded
 * calls platforms() then apis() then Modules_Manager::boot(), in that fixed
 * order), so leaving the eager call in Plugin.php would fatal on
 * class-not-found. Gutenberg's self-registration (which internally calls
 * Templately\Core\Platform_Registry::get_instance()->add() — the platform-adapter
 * registry, unrelated to Module_Base/Modules_Manager) now happens from this
 * module's own init_hooks() instead, which runs during Modules_Manager::boot()
 * — after the autoloader for this module's namespace is registered.
 * Plugin::platforms() is now an empty no-op (both Elementor and Gutenberg
 * self-register from their own modules). Confirmed via grep that nothing
 * between platforms()/apis() and Modules_Manager::boot() eagerly resolves
 * Platform_Registry::get_instance()->active('gutenberg') (all such calls happen later,
 * during actual request handling — REST routes, template rendering — never
 * during the plugins_loaded bootstrap chain), so this timing shift is safe.
 *
 * @package Templately
 */

namespace Templately\Modules\GutenbergIntegration;

use Templately\Core\Module_Base;
use Templately\Modules\GutenbergIntegration\Platform\Gutenberg;
use Templately\Modules\GutenbergIntegration\REST\Visibility;

class Module extends Module_Base {

	public function get_name(): string {
		return 'gutenberg-integration';
	}

	public function get_dependencies(): array {
		// GutenbergHelper extends full-site-import's ImportHelper and the driver
		// calls FSI Utils (get_json_helper / import_and_replace_attachments /
		// read_json_file / import_page_settings). The driver also uses theme-builder
		// (PageTemplates / TemplateFactory). Both are real,
		// one-directional structural edges — FSI and theme-builder declare nothing back
		// (FSI resolves this module lazily via the templately_fsi_* filter seams).
		return [ 'full-site-import', 'theme-builder' ];
	}

	protected function init_hooks(): void {
		Gutenberg::get_instance();
	}

	public function register_rest_routes(): void {
		// FR-003 / FR-020: the toolbar-visibility toggle's REST transport
		// (PUT/POST /templately/v1/gutenberg/visibility) — replaces the primary use of the
		// wp_ajax_update_gutenberg_hide_buttons action, which stays as a deprecated shim
		// (see Platform\Gutenberg::hooks()). Standard lazy rest_api_init registration.
		Visibility::get_instance()->register_routes();
	}
}
