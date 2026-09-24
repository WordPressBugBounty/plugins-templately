<?php
/**
 * utilities module — the plugin's one cleanup service, and the generic
 * "Utilities" settings tab that surfaces it.
 *
 * ## Two halves, one wall
 *
 * `Cleanup/` is the engine: a contribution registry, the single cleanup
 * schedule, a guarded remover, the retention policy, and a run journal. It has
 * ZERO knowledge of the tab — no UI imports, no asset code — and works headless
 * if the JS entry is removed. `REST/` is the only bridge to `assets/js/`.
 *
 * ## Why the engine names no contributor
 *
 *   full-site-import ─┐
 *   block-patterns ───┼─register_classes()─► utilities (TaskRegistry)
 *   mcp-server ───────┘                          │
 *                                                └─► one daily schedule
 *
 * A direct call list would invert that arrow: this module would depend on every
 * feature that produces disposable data, could not boot where one of them is
 * disabled, and would need editing for every future feature. Same reasoning,
 * and the same shape, as `modules/mcp-core/`. This module therefore declares NO
 * dependencies; contributors declare `'utilities'`.
 *
 * ## Nothing is deleted until someone says so
 *
 * Cleanup runs in dry-run until an administrator confirms activation once per
 * site. The first run on an existing site would otherwise reclaim a backlog of
 * abandoned imports — often several gigabytes — with no warning.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities;

use Templately\Core\Module_Base;
use Templately\Modules\Utilities\Cleanup\Scheduler;
use Templately\Modules\Utilities\Notice\ActivationNotice;
use Templately\Modules\Utilities\REST\UtilitiesController;
use Templately\Modules\Utilities\Cleanup\TaskRegistry;
use Templately\Modules\Utilities\Cleanup\Tasks\TransientsTask;

class Module extends Module_Base {

	public function get_name(): string {
		return 'utilities';
	}

	/**
	 * Deliberately empty. Everything points AT this module; it points at
	 * nothing. See the file header.
	 */
	public function get_dependencies(): array {
		return [];
	}

	protected function init_hooks(): void {
		// The one cleanup schedule, plus retirement of the schedules this
		// module supersedes. Registers hooks only — the registry stays lazy, so
		// a request that never runs cleanup constructs no tasks.
		Scheduler::boot();

		$this->inject_settings();

		// The gate is only useful if it can be found. This prompt lives outside
		// the settings page precisely because the sites that leak hardest are
		// the ones whose administrator never opens it.
		ActivationNotice::boot();

		// The engine's own built-in task. Registered through the same public seam
		// every other contributor uses — the registry has no privileged list.
		add_action(
			TaskRegistry::COLLECT_ACTION,
			static function ( TaskRegistry $registry ) {
				$registry->register_classes( [ TransientsTask::class ] );
			}
		);
	}

	/**
	 * Tell the SPA this module booted.
	 *
	 * A field on the EXISTING localized settings object, not a new window
	 * carrier — constitution XIII permits exactly two of those, and this is
	 * injected data rather than a first-party runtime singleton. The JS entry
	 * self-gates on it, so the tab is inert wherever the module did not boot.
	 */
	private function inject_settings(): void {
		add_filter(
			'templately_admin_localized_data',
			static function ( $data ) {
				if ( ! is_array( $data ) ) {
					return $data;
				}

				$data['utilities'] = [
					'canManage' => current_user_can( 'manage_options' ),
				];

				return $data;
			}
		);
	}

	/**
	 * Routes are registered from here — `rest_api_init` — never the constructor.
	 * `register_rest_route()` silently drops a route registered on
	 * `plugins_loaded` with a `_doing_it_wrong` notice.
	 */
	public function register_rest_routes(): void {
		UtilitiesController::get_instance()->register_routes();
	}
}
