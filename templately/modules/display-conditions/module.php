<?php
/**
 * Display conditions module (spec 034-display-conditions).
 *
 * Relocated: includes/API/Conditions.php (the REST controller — 4 endpoints:
 * conditions/check-conditions/save-conditions/autocomplete-condition) +
 * includes/Builder/Managers/ConditionManager.php + Cache.php (the condition
 * evaluation engine + its wp_options cache) + includes/Builder/Conditions/
 * (the 14-file condition-type hierarchy: Archive, Condition (base), Error,
 * Front, General, Page, Post, PostByAuthor, PostTypeArchive, ProductSearch,
 * Search, Singular, Taxonomy, WooCommerce). Namespace:
 * Templately\API\Conditions -> this module's REST\Conditions;
 * Templately\Builder\Managers\{ConditionManager,Cache} -> this module's
 * Managers\*; Templately\Builder\Conditions\* -> this module's Conditions\*.
 *
 * ConditionManager + Cache are explicitly attributed to this spec by spec
 * 035-theme-builder's own FR-030 ("the existing condition/location engine
 * (ConditionManager + Cache, FR-009, spec 034) MUST remain the sole source
 * of truth...") and spec 035's Depends On (032, 033, 034) — 035 CONSUMES
 * this module's engine, so it had to be extracted first, even though 035's
 * own Ownership section also lists ConditionManager+Cache among the
 * "Server-Side Logic" it touches (035 is a consumer, not the owner).
 *
 * NOT relocated (at 034 time): ThemeBuilder.php, TemplateManager,
 * LocationManager, PageTemplates, Source, Types/*, Platforms/*, Render/* —
 * all spec 035-theme-builder's territory. ConditionManager keeps a
 * cross-module reference to ThemeBuilder (constructor takes the builder
 * instance) and ThemeTemplate (docblock type).
 *
 * UPDATE (spec 035, 2026-07-05): the whole `includes/Builder/` tree named
 * above has SINCE relocated to `modules/theme-builder/` — this module's own
 * cross-references now import `Templately\Modules\ThemeBuilder\{ThemeBuilder,
 * Types\ThemeTemplate,Source}` accordingly. This docblock's namespace
 * mentions above (`Templately\Builder\...`) describe the state AT THE TIME
 * of the 034 move, not the current tree — see modules/theme-builder/CLAUDE.md
 * for the current home of everything theme-builder owns.
 *
 * No boot-ordering fix needed here (unlike 032/033's platform drivers):
 * Conditions.php was eagerly instantiated in Plugin::apis() (during
 * plugins_loaded) purely to self-register into the platform-adapter `Platform_Registry::add($this,
 * 'API')` registry — a registration that Plugin::register_routes() explicitly
 * SKIPS for any Templately\Modules\* class already (see its own skip-comment).
 * So the eager call was simply removed from Plugin::apis(); this module's
 * register_rest_routes() (below) wires the real route registration on
 * rest_api_init via Module_Base::boot_rest_routes(), the same lazy pattern
 * every other module-owned REST class uses (013-033) — no autoloader-timing
 * hazard exists for a class only ever resolved at rest_api_init.
 *
 * @package Templately
 */

namespace Templately\Modules\DisplayConditions;

use Templately\Core\Module_Base;
use Templately\Modules\DisplayConditions\REST\Conditions;
use Templately\Modules\DisplayConditions\Managers\ConditionManager;

class Module extends Module_Base {

	public function get_name(): string {
		return 'display-conditions';
	}

	public function get_dependencies(): array {
		// theme-builder. ConditionManager was extracted FROM theme-builder in spec 034
		// and consumes its types: ConditionManager/Cache/Condition + the REST controller
		// reference theme-builder's ThemeBuilder / Source / ThemeTemplate. The former
		// reverse edge (theme-builder -> display-conditions) is removed: theme-builder
		// now obtains ConditionManager through the `templately_condition_manager` filter
		// registered below, so the dependency is one-directional (no declared cycle).
		return [ 'theme-builder' ];
	}

	protected function init_hooks(): void {
		// Supply the ConditionManager to theme-builder through a filter seam instead of
		// theme-builder importing this module's namespace directly (cycle inversion).
		// ThemeBuilder::source_register() applies this on `init`; init_hooks() runs at
		// `plugins_loaded` (Modules_Manager::boot()), so the filter is always registered
		// before it is applied.
		add_filter( 'templately_condition_manager', [ $this, 'provide_condition_manager' ], 10, 2 );
	}

	/**
	 * Provide the ConditionManager for the theme-builder module.
	 *
	 * @param ConditionManager|null $manager Existing manager, if any upstream filter already supplied one.
	 * @param mixed                 $builder The ThemeBuilder instance requesting the manager.
	 * @return ConditionManager
	 */
	public function provide_condition_manager( $manager, $builder ) {
		return $manager instanceof ConditionManager ? $manager : new ConditionManager( $builder );
	}

	public function register_rest_routes(): void {
		Conditions::get_instance()->register_routes();
	}
}
