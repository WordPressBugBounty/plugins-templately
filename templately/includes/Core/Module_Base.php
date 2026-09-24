<?php
/**
 * Module_Base — the contract every feature module extends.
 *
 * @package Templately
 */

namespace Templately\Core;

/**
 * Abstract base class for every auto-discovered feature module.
 *
 * A module lives in `modules/{kebab-name}/module.php` and declares a single class
 * extending this base. The {@see Modules_Manager} discovers, orders, and instantiates
 * these classes. The constructor is `final` so the boot lifecycle cannot be bypassed —
 * override the lifecycle methods (`init_hooks()`, `register_rest_routes()`,
 * `enqueue_assets()`) instead of the constructor.
 *
 * Two entry points with strictly separated responsibilities:
 *  - PHP (`module.php`, this class): WordPress hooks, REST endpoint registration, PHP
 *    asset enqueuing, and sub-component registration.
 *  - JS (`assets/js/index.ts`): SPA route registration via `addFilter('templately.routes')`
 *    and Redux reducer attachment. PHP `add_filter('templately.routes')` never reaches it —
 *    the two hook systems are independent.
 *
 * @see specs/004-core-module-infrastructure
 */
abstract class Module_Base {
	/**
	 * Named internal sub-components, keyed by id.
	 *
	 * @var array<string, mixed>
	 */
	private $components = [];

	/**
	 * Boot the module.
	 *
	 * Declared `final`: runs `init_hooks()` during construction and defers the REST /
	 * asset lifecycle to their proper hooks. `register_rest_routes()` is intentionally
	 * NOT called here — `register_rest_route()` only works on `rest_api_init`; calling it
	 * on `plugins_loaded` silently drops the route with a `_doing_it_wrong` notice
	 * (constitution §VIII). The manager wraps this constructor in a try/catch, so an
	 * exception thrown from `init_hooks()` removes the module from the active registry
	 * without aborting the boot of other modules (FR-010).
	 */
	final public function __construct() {
		$this->init_hooks();

		add_action( 'rest_api_init', [ $this, 'boot_rest_routes' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
	}

	/**
	 * Unique kebab-case module name. Uniqueness is FR-003 (a duplicate is skipped);
	 * equality with the module's DIRECTORY name is the convention everything outside
	 * the manager keys on (JS asset discovery, the dependency gates, the autoloader
	 * fallback) — the manager boots a mismatched module but emits a notice.
	 *
	 * @return string
	 */
	abstract public function get_name(): string;

	/**
	 * Whether the module should boot.
	 *
	 * Evaluated by the manager BEFORE the real instantiation. Returning `false` skips the
	 * module entirely — no hooks, no REST endpoints, no assets (FR-002). MUST be safe to
	 * call on a not-yet-constructed instance (no dependence on constructor state).
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return true;
	}

	/**
	 * Whether a module HELD BACK from the current release may boot.
	 *
	 * Some modules are complete and tested but not part of the release being cut —
	 * they ship in the zip, dormant. Each of them answers `is_active()` with this, so
	 * the manager skips it entirely: no hooks, no REST routes, no assets, and the SPA
	 * pieces that key off what the module localizes never appear either.
	 *
	 * Off by default, everywhere. Turn them back on for development or QA with
	 * `define( 'TEMPLATELY_ENABLE_DEFERRED_MODULES', true );` in wp-config.php, or per
	 * module through the filter. The test bootstrap opts in so the dormant code keeps
	 * its coverage. Releasing a module means deleting its `is_active()` override, not
	 * flipping the default here.
	 *
	 * @param string $name The module's own name, for the filter.
	 * @return bool
	 */
	public static function deferred_module_enabled( string $name ): bool {
		$enabled = defined( 'TEMPLATELY_ENABLE_DEFERRED_MODULES' ) && TEMPLATELY_ENABLE_DEFERRED_MODULES;

		/**
		 * Filters whether a module held back from the release may boot.
		 *
		 * @param bool   $enabled The constant's answer (false when it is not defined).
		 * @param string $name    The module name, e.g. 'mcp-server'.
		 */
		return (bool) apply_filters( 'templately_deferred_module_enabled', $enabled, $name );
	}

	/**
	 * Module names this module depends on; drives topological boot ordering (FR-004..006).
	 *
	 * MUST be safe to call on a not-yet-constructed instance.
	 *
	 * @return string[]
	 */
	public function get_dependencies(): array {
		return [];
	}

	/**
	 * Declarative environment requirements the manager verifies BEFORE instantiation.
	 *
	 * Evaluated by the manager during discovery, AFTER {@see is_active()} passes and after
	 * the `templately_module_requirements_{name}` filter runs. A single unmet requirement
	 * drops the module entirely — no hooks, no REST endpoints, no assets — and records a
	 * human-readable reason in {@see Modules_Manager::get_unavailable_modules()}. Like
	 * `is_active()` and `get_dependencies()`, this MUST be safe to call on a
	 * not-yet-constructed instance (no dependence on constructor state).
	 *
	 * Every key is optional; an empty array (the default) imposes no requirement:
	 *  - `php`       (string)   Minimum PHP version, compared with `version_compare( PHP_VERSION, … )`.
	 *  - `wp`        (string)   Minimum WordPress version, compared against `get_bloginfo( 'version' )`.
	 *  - `classes`   (string[]) Every entry must satisfy `class_exists()` or `interface_exists()`.
	 *  - `functions` (string[]) Every entry must satisfy `function_exists()`.
	 *  - `plugins`   (string[]) Active-plugin basenames (`'dir/file.php'`), checked against the
	 *                           active-plugins option (plus network-active plugins on multisite).
	 *  - `multisite` (bool)     Must equal `is_multisite()`.
	 *
	 * @return array
	 */
	public function get_requirements(): array {
		return [];
	}

	/**
	 * Experimental-flag metadata (FR-009). Metadata only — does not alter boot behaviour.
	 *
	 * @return array|false
	 */
	public function get_experimental_data() {
		return false;
	}

	/**
	 * Named sub-feature gates, each bound to a host capability key (spec 053).
	 *
	 * `[ 'gate-name' => 'capability-key' ]`. A gate NEVER drops the module —
	 * that is `get_requirements()`/`is_active()`'s job. A module with unmet
	 * gates boots normally; only the code paths it guards with
	 * {@see has_gate()} stay inactive. Unmet gates are surfaced per module in
	 * {@see Modules_Manager::get_unmet_gates()} for the developer console.
	 *
	 * @return array<string, string>
	 */
	public function get_capability_gates(): array {
		return [];
	}

	/**
	 * Whether one of this module's own declared gates is met (spec 053).
	 *
	 * Resolution is lazy — nothing is probed until asked — so this is safe at
	 * any point during or after `init_hooks()`. A gate name the module never
	 * declared answers false with a development-mode notice, never a fatal.
	 *
	 * @param string $gate Gate name declared in {@see get_capability_gates()}.
	 * @return bool
	 */
	protected function has_gate( $gate ): bool {
		$gates = $this->get_capability_gates();

		if ( ! isset( $gates[ $gate ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html( sprintf( 'Templately module "%s" queried undeclared gate "%s"; answering unmet.', $this->get_name(), $gate ) ),
				'3.8.0'
			);
			return false;
		}

		return Capabilities::get_instance()->has( $gates[ $gate ] );
	}

	/**
	 * Register WordPress hooks. The only lifecycle method that runs during construction.
	 *
	 * @return void
	 */
	protected function init_hooks(): void {}

	/**
	 * Register the module's REST endpoints.
	 *
	 * MUST call `->register_routes()` explicitly on every endpoint (never rely on a
	 * registry drain). Runs on `rest_api_init` via {@see boot_rest_routes()} — NEVER from
	 * the constructor (constitution §VIII).
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {}

	/**
	 * The public `rest_api_init` callback the base constructor hooks. Kept separate from
	 * `register_rest_routes()` so the hook callback is stable and the intent is explicit.
	 *
	 * @return void
	 */
	public function boot_rest_routes(): void {
		$this->register_rest_routes();
	}

	/**
	 * Enqueue the module's PHP-side assets. Runs on `admin_enqueue_scripts`, not the
	 * constructor.
	 *
	 * @return void
	 */
	public function enqueue_assets(): void {}

	/**
	 * Register a named internal sub-component (FR-007).
	 *
	 * `@internal` — access is convention-bounded, not enforced by PHP visibility. A
	 * name collision preserves the first registrant and emits a diagnostic notice; it
	 * never overwrites (edge case: sub-component name collision).
	 *
	 * @param string $id       Unique sub-component id within this module.
	 * @param mixed  $instance The sub-component instance.
	 * @return void
	 */
	public function add_component( string $id, $instance ): void {
		if ( isset( $this->components[ $id ] ) ) {
			$this->diagnostic_notice(
				sprintf(
					'Templately module "%s": sub-component "%s" is already registered; keeping the first instance.',
					$this->get_name(),
					$id
				)
			);
			return;
		}

		$this->components[ $id ] = $instance;
	}

	/**
	 * Retrieve a named sub-component (FR-007).
	 *
	 * `@internal` — intended for use only from within the owning module. Returns `null`
	 * for an unregistered id — never a wrong instance.
	 *
	 * @param string $id Sub-component id.
	 * @return mixed|null
	 */
	public function get_component( string $id ) {
		return $this->components[ $id ] ?? null;
	}

	/**
	 * Emit a non-fatal diagnostic notice (FR-011).
	 *
	 * @param string $message Human-readable diagnostic.
	 * @return void
	 */
	private function diagnostic_notice( string $message ): void {
		trigger_error( esc_html( $message ), E_USER_NOTICE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error

		// Templately's dedicated log file, not debug.log (WP_DEBUG_LOG-gated inside).
		\Templately\Utils\Helper::log( $message, 'modules', 'warning' );
	}
}
