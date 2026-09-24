<?php
/**
 * Theme builder module (spec 035-theme-builder).
 *
 * Relocates the entire theme-builder engine from includes/Builder/ (48
 * files) + includes/API/ThemeBuilderApi.php (1 file) into
 * modules/theme-builder/ — the orchestrator (ThemeBuilder.php), the
 * condition-consuming template-type registry (TemplateManager), frontend
 * resolution (LocationManager), page-template registration (PageTemplates),
 * header/footer replacement (TemplateLoader), the platform-aware factory
 * (TemplateFactory), the 19-file template-type hierarchy (Types/), the
 * render-integration strategies (Render/Strategies/), the four Elementor
 * widgets (Widgets/), the Elementor Documents adapter
 * (Platforms/Elementor/Documents/, 12 files) and the Gutenberg editor-canvas
 * context + admin-bar adapters (Platforms/Gutenberg/{EditorContext,
 * AdminBar}.php), and the REST controller for template creation
 * (REST/ThemeBuilderApi.php). Namespace: Templately\Builder\* ->
 * Templately\Modules\ThemeBuilder\*; Templately\API\ThemeBuilderApi ->
 * Templately\Modules\ThemeBuilder\REST\ThemeBuilderApi.
 *
 * SCOPE NOTE — Platforms/Elementor/Documents/* and Platforms/Gutenberg/
 * {EditorContext,AdminBar}.php: these are platform-specific ADAPTERS, not
 * the platform drivers themselves (those already live in
 * modules/elementor-integration/ and modules/gutenberg-integration/, specs
 * 032/033). Builder/CLAUDE.md's own authoritative framing (written during
 * the 2026-07-02 spec-code-reconcile) explicitly calls these "the
 * Builder-specific parts each driver delegates to" — i.e. theme-builder's
 * own per-platform organization, not code owned by the platform-integration
 * modules. Spec 035's own text has one arguably-conflicting sentence
 * ("[Gutenberg editor-canvas rendering] is BLOCKED — owned by spec 033...
 * not this spec") — read in context this is about NOT implementing new
 * editor-canvas FRs as part of 035, not about where the existing
 * EditorContext.php file's namespace should live. Since spec 032
 * (elementor-integration) already explicitly deferred the whole
 * includes/Builder/ tree "to a later spec" when it ran, and that later
 * spec is this one, both platform-adapter subdirectories are extracted
 * here. modules/elementor-integration/CLAUDE.md and
 * modules/gutenberg-integration/CLAUDE.md are updated to reflect this.
 *
 * CRITICAL ORDERING FIX — ThemeBuilder::get_instance() was previously
 * instantiated in Plugin::__construct() itself (NOT even plugins_loaded() —
 * the constructor runs when the main plugin file is included, which is
 * BEFORE the plugins_loaded ACTION even fires). Once ThemeBuilder became a
 * module-namespaced class, that eager call would fatal on class-not-found,
 * since Modules_Manager::boot() (which registers this module's autoloader)
 * doesn't run until INSIDE Plugin::plugins_loaded(), by definition after
 * the constructor has already returned. Fixed by moving the instantiation
 * into this module's own init_hooks() (which runs during
 * Modules_Manager::boot(), after the autoloader is live), assigning the
 * result to templately()->theme_builder (a public property on Plugin —
 * settable from outside the class). Verified via grep that nothing
 * synchronously reads templately()->theme_builder between the old
 * construction point and the new one: Admin's and Enqueue's constructors
 * (built in the same Plugin::__construct(), just before/after
 * ThemeBuilder) never read it; the one place Admin.php DOES read it
 * (admin_enqueue_scripts) fires long after plugins_loaded. ThemeBuilderApi
 * (the REST controller) had the same eager-instantiation problem in
 * Plugin::apis() — fixed via the standard lazy register_rest_routes()
 * pattern every module-owned REST class uses (013-034); no ordering risk
 * there since it was only ever needed at rest_api_init.
 *
 * @package Templately
 */

namespace Templately\Modules\ThemeBuilder;

use Templately\Core\Module_Base;
use Templately\Modules\ThemeBuilder\REST\ThemeBuilderApi;

class Module extends Module_Base {

	public function get_name(): string {
		return 'theme-builder';
	}

	// No get_dependencies() (defaults to []): the ConditionManager is no longer
	// instantiated here — it is supplied by the display-conditions module via the
	// `templately_condition_manager` filter (registered at boot; applied on `init`
	// in ThemeBuilder::source_register()). The dependency direction is therefore
	// INVERTED — display-conditions declares theme-builder (it was extracted FROM
	// theme-builder in spec 034 and consumes its ThemeBuilder/Source/ThemeTemplate
	// types), and theme-builder no longer imports the display-conditions namespace.

	protected function init_hooks(): void {
		templately()->theme_builder = ThemeBuilder::get_instance();
	}

	public function register_rest_routes(): void {
		ThemeBuilderApi::get_instance()->register_routes();
	}
}
