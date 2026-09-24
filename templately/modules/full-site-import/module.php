<?php
/**
 * Full-site-import module (spec 025-full-site-import).
 *
 * The FSI engine — a 10-runner sequential pipeline, SSE-streamed progress, session
 * management via WordPress options, an exception hierarchy for retry/skip/fatal error
 * handling, and 11 admin-ajax actions registered as `wp_ajax_templately_pack_*`.
 * Relocated (git mv) from includes/Core/Importer/ — behavior byte-identical (same
 * ajax actions, params, option keys, runner order).
 *
 * Activation mechanism (distinct from the REST-route pattern most modules use): this
 * module has almost NO `templately/v1` REST routes of its own — with TWO exceptions
 * (FR-018), both replacing a `wp_ajax_templately_pack_*` action as the primary transport
 * while the old ajax action stays registered as a deprecated shim (browsers with an
 * already-cached JS bundle referencing the old action name would otherwise break),
 * registered exactly as before via `add_ajax_action()`:
 *   - `POST /templately/v1/import/global-settings` (`REST/GlobalSettings.php`, backlog B8,
 *     2026-07-16) — the settings-only import mode.
 *   - `GET  /templately/v1/import/info` (`REST/PackInfo.php`, 2026-07-16) — the pack
 *     import-info fetch. GET because the handler is a pure read (no session/option writes);
 *     it matches the spec's ajax→REST mapping table.
 * Both routes share the upstream `v2/import/info/pack/{id}` fetch via
 * `Utils\PackInfoFetcher` (de-duplicated from the two former private copies). The FSI
 * module used to have
 * an FSI-adjacent stub REST route (`GET /templately/v1/site-import/{id}` via a legacy
 * `includes/API\FullSiteImport` class) — deleted during Phase 3 consolidation
 * (2026-07-05): the class was never actually instantiated anywhere (confirmed via a
 * full-repo grep), so the route was never even registered, and its callback method
 * didn't exist either (would have fataled had it somehow been reachable). Pure dead
 * code with zero consumers (no frontend caller referenced it) — removed rather than
 * migrated. The FSI PHP activates purely by CONSTRUCTING `FullSiteImport` — its
 * constructor wires up all the `wp_ajax_templately_pack_*` action hooks. So the
 * module's job is simply to call `FullSiteImport::get_instance()` (plus, now, register
 * the one REST route from `register_rest_routes()`, the standard lazy `rest_api_init`
 * pattern every module-owned REST class uses).
 *
 * We do that from `init_hooks()` — the one lifecycle method that runs during module
 * construction (at `plugins_loaded`, via Modules_Manager::boot()). This is the exact
 * moment the old `Plugin::plugins_loaded()` used to call `FullSiteImport::get_instance()`,
 * and it is the semantically correct "the module is now active, wire up its runtime
 * behavior" point (constructor-time side effects — NOT REST registration, which must
 * wait for `rest_api_init`). This mirrors the developer-tools module, which likewise
 * boots its `Developer::get_instance()` singleton from `init_hooks()`.
 *
 * `get_dependencies()` was intentionally EMPTY until 2026-07-26: the FSI constructor
 * instantiates only this module's own `Ajax\*` controllers and registers ajax hooks —
 * it needs no other module booted first, and cross-module class references (e.g. the AI
 * merger) autoload lazily at request time. The original monolith had no
 * module-dependency gating; adding one risks the module being skipped on a dep-name
 * mismatch. It now declares exactly one dependency, `mcp-core`, for the agent
 * capabilities in `Abilities/` — see the note on the method itself for why that one is
 * safe to name and why the rest stay undeclared.
 *
 * @package Templately
 */

namespace Templately\Modules\FullSiteImport;

use Templately\Core\Module_Base;
use Templately\Modules\FullSiteImport\Abilities\AgentCapabilities;
use Templately\Modules\FullSiteImport\Cleanup\CleanupTasks;
use Templately\Modules\FullSiteImport\REST\GlobalSettings;
use Templately\Modules\FullSiteImport\REST\PackInfo;

class Module extends Module_Base {

	public function get_name(): string {
		return 'full-site-import';
	}

	/**
	 * The ONE declared dependency, and a narrow one: `Abilities/` contributes this
	 * module's five agent capabilities to `McpCore\Registry\ToolRegistry` and reads
	 * access levels off `McpCore\Registry\ToolDescriptor`.
	 *
	 * This does not reintroduce the boot-ordering risk the docblock above warns
	 * about. `mcp-core` registers no hooks, declares no requirements and overrides
	 * no `is_active()` — it cannot fail to boot, so naming it cannot cause this
	 * module to be skipped. Every OTHER cross-module reference here is still
	 * lazy-autoloaded at request time and still deliberately undeclared.
	 */
	public function get_dependencies(): array {
		// `utilities` owns the cleanup engine this module contributes tasks to,
		// and the guarded remover those tasks delete through (spec 052). The
		// arrow points one way: utilities never names this module.
		//
		// `pro-plugin-provisioning` (spec 059) is deliberately NOT listed, though the
		// runner reads its catalog. Declaring it makes Modules_Manager SKIP this whole
		// module whenever that one is inactive — the exact opposite of "behaves as
		// before when disabled". The reads are soft (`class_exists` + `is_live()`), so
		// it is baselined as an intentional reach-in instead.
		return [ 'mcp-core', 'utilities' ];
	}

	protected function init_hooks(): void {
		// Constructing the singleton wires the wp_ajax_templately_pack_* action hooks
		// (and the independent Ajax\* controllers) — the FSI engine's activation point.
		FullSiteImport::get_instance();

		// The agent-facing surface over that engine (spec 045). Registration only —
		// nothing here runs unless a transport actually invokes a capability.
		AgentCapabilities::register();

		// This module's contribution to the shared cleanup service (spec 052):
		// expired session/AI records, abandoned working and preview directories,
		// and old import logs. Hands over class names only — nothing is
		// constructed unless a sweep actually runs.
		CleanupTasks::register();
	}

	public function register_rest_routes(): void {
		// FR-018 / backlog B8: the settings-only import mode's REST transport.
		GlobalSettings::get_instance()->register_routes();
		// FR-018 (§9 ajax→REST): pack import-info fetch (GET /import/info). Both routes
		// share the upstream fetch via Utils\PackInfoFetcher; the matching ajax actions
		// (import_info / import_global_settings) stay registered as deprecated shims.
		PackInfo::get_instance()->register_routes();
	}
}
