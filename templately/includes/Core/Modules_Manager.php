<?php
/**
 * Modules_Manager — discovers, orders, and boots feature modules.
 *
 * @package Templately
 */

namespace Templately\Core;

use ReflectionClass;
use Templately\Utils\Base;
use Throwable;

/**
 * Singleton orchestrator for the module system (spec 004).
 *
 * Scans `modules/{kebab}/module.php` (shipped) AND `modules/developer/{kebab}/module.php`
 * (dev-only, excluded from production zips via `.distignore`) one level deep into a SINGLE
 * discovery pool — no central registration file. Shared name-collision detection and one
 * dependency graph span both roots, so a dev module may depend on a shipped module and on
 * other dev modules. Evaluates each module's `is_active()` then its declarative
 * `get_requirements()`, resolves declared dependencies via topological sort, and
 * instantiates active modules in dependency order. Inactive, unmet-requirement, duplicate,
 * malformed, unresolvable, cyclic, or exception-throwing modules are skipped with a
 * non-fatal diagnostic notice — and, where a name resolves, recorded in
 * {@see get_unavailable_modules()}; the plugin always finishes booting.
 *
 * Also registers an SPL autoloader mapping the per-module namespace
 * `Templately\Modules\{Pascal}\…` to files under `modules/{kebab}/…` (FR-014) — composer
 * PSR-4 covers only `Templately\ → includes/`, so a module's REST/tool sub-classes are
 * loadable ONLY through this map.
 *
 * @see specs/004-core-module-infrastructure
 */
class Modules_Manager extends Base {
	/**
	 * Active module instances, keyed by name.
	 *
	 * @var array<string, Module_Base>
	 */
	private $modules = [];

	/**
	 * Modules that were discovered but did not boot, mapped to a human-readable reason:
	 * an unmet requirement, a missing/skipped dependency, or a dependency cycle. Only
	 * entries whose module name resolved are recorded (a malformed/nameless module has no
	 * key to record under). Exposed via {@see get_unavailable_modules()}.
	 *
	 * @var array<string, string>
	 */
	private $unavailable = [];

	/**
	 * Namespace-prefix → base-directory map for the module autoloader (FR-014).
	 *
	 * @var array<string, string>
	 */
	private $namespace_map = [];

	/**
	 * Whether the SPL autoloader has been registered.
	 *
	 * @var bool
	 */
	private $autoloader_registered = false;

	/**
	 * Whether {@see boot()} has run. Guards against a double boot in one request.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Discover and boot all modules.
	 *
	 * @param string|null $modules_dir Absolute path to the modules root. Defaults to
	 *                                 `TEMPLATELY_PATH . 'modules'`. Overridable for tests.
	 * @return void
	 */
	public function boot( ?string $modules_dir = null ): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		if ( null === $modules_dir ) {
			$modules_dir = ( defined( 'TEMPLATELY_PATH' ) ? TEMPLATELY_PATH : '' ) . 'modules';
		}

		$this->register_autoloader();

		$meta = $this->discover( $modules_dir );

		if ( ! empty( $meta ) ) {
			$order = $this->resolve_order( $meta );

			foreach ( $order as $name ) {
				$this->instantiate( $name, $meta[ $name ] );
			}
		}

		/**
		 * Fires once after the whole module boot loop completes — even when zero modules
		 * were discovered. Consumers can enumerate the active registry / unavailable map.
		 *
		 * @param Modules_Manager $manager The manager instance.
		 */
		do_action( 'templately_modules_booted', $this );
	}

	/**
	 * Scan both module roots (shipped top level + the dev-only `developer/` sub-root) and
	 * collect metadata for every active, uniquely-named, well-formed module whose declared
	 * requirements are met. Inactive and unmet-requirement modules are dropped here (before
	 * any side effect).
	 *
	 * @param string $modules_dir Absolute path to the modules root.
	 * @return array<string, array{class:string, probe:Module_Base, deps:string[], file:string, dir:string}>
	 */
	private function discover( string $modules_dir ): array {
		$root = rtrim( $modules_dir, '/\\' );

		// Two roots into ONE pool: shipped `modules/{kebab}/module.php` plus the dev-only
		// `modules/developer/{kebab}/module.php`. The primary glob naturally skips the
		// `developer/` dir itself (it has no module.php one level down from the root).
		// `?: []`, not `(array)`: glob() returns FALSE on error, and `(array) false`
		// is `[ false ]` — which would flow a boolean into basename()/require_once
		// below instead of yielding the empty scan the error case means.
		$files = array_merge(
			glob( $root . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'module.php' ) ?: [],
			glob( $root . DIRECTORY_SEPARATOR . 'developer' . DIRECTORY_SEPARATOR . '*' . DIRECTORY_SEPARATOR . 'module.php' ) ?: []
		);

		if ( empty( $files ) ) {
			return [];
		}

		$meta = [];

		foreach ( $files as $file ) {
			$dir   = basename( dirname( $file ) );
			$class = $this->load_module_class( $file );

			if ( null === $class ) {
				$this->notice( sprintf( 'Templately module "%s" skipped: no Module_Base subclass found in %s.', $dir, 'module.php' ) );
				continue;
			}

			// Probe metadata without triggering the constructor's side effects.
			try {
				$probe = ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
				$name  = $probe->get_name();
				$deps  = $probe->get_dependencies();
			} catch ( Throwable $e ) {
				$this->notice( sprintf( 'Templately module "%s" skipped: %s.', $dir, $e->getMessage() ) );
				continue;
			}

			if ( ! is_string( $name ) || '' === $name ) {
				$this->notice( sprintf( 'Templately module in "%s" skipped: get_name() must return a non-empty string.', $dir ) );
				continue;
			}

			if ( isset( $meta[ $name ] ) ) {
				$this->notice( sprintf( 'Templately module "%s" skipped: another module already claims this name.', $name ) );
				continue;
			}

			// Convention guard, notice-only: everything OUTSIDE the manager keys on the
			// DIRECTORY name — the JS asset auto-discovery glob, the dependency gate's
			// kebab↔Pascal mapping, and the load_module_class() repeat-include fallback
			// all assume `get_name() === dirname`. The manager itself keys on the name,
			// so a mismatched module still boots — but it half-works in ways none of
			// those consumers can diagnose, which is why the drift is called out here.
			if ( $name !== $dir ) {
				$this->notice( sprintf( 'Templately module "%s": get_name() does not match its directory "%s"; asset discovery, the dependency gates, and the autoloader fallback all key on the directory name.', $name, $dir ) );
			}

			// Register the namespace → directory mapping for ALL discovered modules —
			// including inactive ones. Loading a class produces no side effects (hooks
			// come from the manager-driven boot, which inactive modules never get), and
			// core code holding a static reference to a module class (e.g. Utils\Http →
			// Auth\REST\Login) must not fatal just because the module is inactive.
			$this->register_namespace( $class, dirname( $file ) );

			if ( ! $probe->is_active() ) {
				continue; // Inactive — zero side effects.
			}

			// Requirements gate: evaluated AFTER is_active() passes. The per-module filter
			// runs FIRST, so it can rescue a module (return []) or doom one (add a key) —
			// then a single unmet requirement drops the module and records the reason.
			$requirements = $probe->get_requirements();
			$requirements = is_array( $requirements ) ? $requirements : [];

			/**
			 * Filters a module's declared requirements just before they are checked.
			 *
			 * @param array       $requirements The module's declared requirements.
			 * @param Module_Base $probe        The not-yet-constructed probe instance.
			 */
			$requirements = apply_filters( "templately_module_requirements_{$name}", $requirements, $probe );
			$requirements = is_array( $requirements ) ? $requirements : [];

			$met = $this->check_requirements( $requirements );
			if ( true !== $met ) {
				$this->unavailable[ $name ] = $met;
				// NOT notice(): an unmet requirement is a BY-DESIGN outcome, not an anomaly.
				// FR-011's notice list is naming conflict / malformed entry / missing
				// dependency / circular dependency / runtime exception — every one a
				// developer mistake. A module declaring `['multisite' => true]` and then
				// correctly standing down on a single-site install is the gate working, and
				// it happens on EVERY request for the lifetime of the install. Routing it
				// through trigger_error() would render the notice into the response body
				// whenever display_errors is on (the norm for the developer-mode audience),
				// corrupting every REST payload and sending headers early. The designed
				// surface for this outcome is get_unavailable_modules() (see Module_Base::
				// get_requirements()), which the developer console reads.
				$this->log_unavailable( sprintf( 'Templately module "%s" skipped: %s.', $name, $met ) );
				continue;
			}

			$meta[ $name ] = [
				'class' => $class,
				'probe' => $probe,
				'deps'  => is_array( $deps ) ? $deps : [],
				'file'  => $file,
				'dir'   => $dir,
			];
		}

		return $meta;
	}

	/**
	 * Require a module entry file and return the name of the Module_Base subclass it
	 * declares, or null if the file is malformed.
	 *
	 * Uses a declared-classes diff so the entry class may be named anything (avoiding the
	 * `module.php` vs `Module.php` autoload-filename clash on case-sensitive filesystems).
	 *
	 * @param string $file Absolute path to a module.php.
	 * @return string|null Fully-qualified class name, or null.
	 */
	private function load_module_class( string $file ): ?string {
		$before = get_declared_classes();

		try {
			require_once $file;
		} catch ( Throwable $e ) {
			$this->notice( sprintf( 'Templately module entry %s failed to load: %s.', $file, $e->getMessage() ) );
			return null;
		}

		$new = array_diff( get_declared_classes(), $before );

		foreach ( $new as $class ) {
			if ( is_subclass_of( $class, Module_Base::class ) ) {
				return $class;
			}
		}

		// The file may have been required earlier in the request (declared-classes diff is
		// empty on a repeat include). Fall back to the naming convention.
		$expected = 'Templately\\Modules\\' . $this->pascal_case( basename( dirname( $file ) ) ) . '\\Module';
		if ( class_exists( $expected ) && is_subclass_of( $expected, Module_Base::class ) ) {
			return $expected;
		}

		return null;
	}

	/**
	 * Instantiate a single resolved module inside a try/catch (FR-010). Runs the `final`
	 * constructor on the already-created probe instance, so `is_active()` was evaluated
	 * before any side effect. An uncaught exception removes it from the active registry
	 * and emits a diagnostic; the boot continues.
	 *
	 * @param string $name Module name.
	 * @param array  $info Discovery metadata for the module.
	 * @return void
	 */
	private function instantiate( string $name, array $info ): void {
		try {
			$instance    = $info['probe'];
			$constructor = ( new ReflectionClass( $info['class'] ) )->getConstructor();
			$constructor->invoke( $instance );

			$this->modules[ $name ] = $instance;

			/**
			 * Fires once for each module that successfully boots, in dependency order.
			 *
			 * @param string      $name     The module name.
			 * @param Module_Base $instance The booted module instance.
			 */
			do_action( 'templately_module_booted', $name, $instance );
		} catch ( Throwable $e ) {
			unset( $this->modules[ $name ] );
			$this->notice( sprintf( 'Templately module "%s" removed from the active registry: %s.', $name, $e->getMessage() ) );
		}
	}

	/**
	 * Compute the boot order via topological sort, skipping modules with an inactive /
	 * absent dependency (FR-005) and every module involved in a dependency cycle (FR-006).
	 *
	 * @param array<string, array> $meta Discovery metadata keyed by module name.
	 * @return string[] Module names in dependency order (dependencies first).
	 */
	private function resolve_order( array $meta ): array {
		$skipped = [];

		foreach ( $this->find_cycle_nodes( $meta ) as $name ) {
			$skipped[ $name ] = 'circular dependency';
		}

		// Modules already dropped by the requirements gate. Depending on one of these is a
		// BY-DESIGN outcome (the environment simply lacks the feature), so the skip it
		// cascades is logged rather than raised as an FR-011 anomaly notice — see
		// log_unavailable(). At this point $this->unavailable holds gate entries only.
		$gated = $this->unavailable;

		/** @var array<string,bool> Names whose skip is by design, not a developer mistake. */
		$by_design = [];

		// Propagate skips: a module whose dependency is absent or skipped cannot boot.
		do {
			$changed = false;
			foreach ( $meta as $name => $info ) {
				if ( isset( $skipped[ $name ] ) ) {
					continue;
				}
				foreach ( $info['deps'] as $dep ) {
					if ( isset( $gated[ $dep ] ) ) {
						$skipped[ $name ]   = sprintf( "dependency '%s' is unavailable: %s", $dep, $gated[ $dep ] );
						$by_design[ $name ] = true;
					} elseif ( ! isset( $meta[ $dep ] ) ) {
						$skipped[ $name ] = sprintf( "missing dependency '%s'", $dep );
					} elseif ( isset( $skipped[ $dep ] ) ) {
						$skipped[ $name ]   = sprintf( "dependency '%s' was skipped", $dep );
						$by_design[ $name ] = isset( $by_design[ $dep ] );
					} else {
						continue;
					}
					$changed = true;
					break;
				}
			}
		} while ( $changed );

		foreach ( $skipped as $name => $reason ) {
			$this->unavailable[ $name ] = $reason;
			$message                    = sprintf( 'Templately module "%s" skipped: %s.', $name, $reason );

			if ( isset( $by_design[ $name ] ) && $by_design[ $name ] ) {
				$this->log_unavailable( $message );
			} else {
				$this->notice( $message );
			}
		}

		return $this->topological_order( $meta, $skipped );
	}

	/**
	 * Return the names of all modules involved in any dependency cycle.
	 *
	 * @param array<string, array> $meta Discovery metadata.
	 * @return string[]
	 */
	private function find_cycle_nodes( array $meta ): array {
		$color = []; // 0 = unvisited, 1 = on stack, 2 = done.
		$stack = [];
		$cycle = [];

		$dfs = function ( string $u ) use ( &$dfs, &$meta, &$color, &$stack, &$cycle ): void {
			$color[ $u ] = 1;
			$stack[]     = $u;

			foreach ( $meta[ $u ]['deps'] as $v ) {
				if ( ! isset( $meta[ $v ] ) ) {
					continue; // Absent dep — handled by skip propagation, not a cycle.
				}
				$state = $color[ $v ] ?? 0;
				if ( 1 === $state ) {
					$idx = array_search( $v, $stack, true );
					foreach ( array_slice( $stack, $idx ) as $node ) {
						$cycle[ $node ] = true;
					}
				} elseif ( 0 === $state ) {
					$dfs( $v );
				}
			}

			array_pop( $stack );
			$color[ $u ] = 2;
		};

		foreach ( array_keys( $meta ) as $u ) {
			if ( 0 === ( $color[ $u ] ?? 0 ) ) {
				$dfs( $u );
			}
		}

		return array_keys( $cycle );
	}

	/**
	 * Post-order DFS topological sort over the surviving (non-skipped) modules.
	 *
	 * @param array<string, array>  $meta    Discovery metadata.
	 * @param array<string, string> $skipped Skipped module names → reason.
	 * @return string[] Dependencies precede their dependents.
	 */
	private function topological_order( array $meta, array $skipped ): array {
		$ordered = [];
		$visited = [];

		$visit = function ( string $u ) use ( &$visit, &$meta, &$skipped, &$visited, &$ordered ): void {
			if ( isset( $visited[ $u ] ) || isset( $skipped[ $u ] ) ) {
				return;
			}
			$visited[ $u ] = true;

			foreach ( $meta[ $u ]['deps'] as $v ) {
				if ( isset( $meta[ $v ] ) && ! isset( $skipped[ $v ] ) ) {
					$visit( $v );
				}
			}

			$ordered[] = $u;
		};

		foreach ( array_keys( $meta ) as $u ) {
			$visit( $u );
		}

		return $ordered;
	}

	/**
	 * Retrieve an active module instance by name (FR-013). Returns null when the module is
	 * absent, inactive, or not yet booted — safe for optional/post-boot lookups, but no
	 * substitute for declaring a dependency via `get_dependencies()`.
	 *
	 * @param string $name Module name.
	 * @return Module_Base|null
	 */
	public function get_module( string $name ): ?Module_Base {
		return $this->modules[ $name ] ?? null;
	}

	/**
	 * The names of all currently active modules (FR-008).
	 *
	 * @return string[]
	 */
	public function get_active_modules(): array {
		return array_keys( $this->modules );
	}

	/**
	 * Modules that were discovered but did not boot, mapped to a human-readable reason
	 * (unmet requirement, missing/skipped dependency, or dependency cycle). Complements
	 * {@see get_active_modules()} for diagnostics / a developer surface.
	 *
	 * @return array<string, string> Module name → reason.
	 */
	public function get_unavailable_modules(): array {
		return $this->unavailable;
	}

	/**
	 * Declared-but-unmet sub-feature gates per ACTIVE module (spec 053).
	 *
	 * Complements {@see get_unavailable_modules()}: that inventory lists
	 * modules that did not boot at all, this one lists booted modules whose
	 * gated enhancements are currently off. Resolved lazily on call — never
	 * during discovery — so modules may register their own capability keys in
	 * `init_hooks()` before anything is checked.
	 *
	 * @return array<string, array<int, array{gate:string, capability:string, reason:string}>>
	 */
	public function get_unmet_gates(): array {
		$capabilities = Capabilities::get_instance();
		$unmet        = [];

		foreach ( $this->modules as $name => $module ) {
			foreach ( $module->get_capability_gates() as $gate => $capability ) {
				if ( $capabilities->has( $capability ) ) {
					continue;
				}

				$explanation = $capabilities->explain( $capability );

				// A gate map is `array<string,string>` by contract, but the return
				// type only constrains the container — a module CAN hand back a
				// non-string value. `sprintf( '%s' )` would then raise "Array to
				// string conversion", so the label is built defensively; the
				// resolver has already answered unavailable and warned.
				$label = is_scalar( $capability ) ? (string) $capability : gettype( $capability );

				$unmet[ $name ][] = [
					'gate'       => $gate,
					'capability' => $capability,
					'reason'     => sprintf( 'capability "%s" unavailable (decided by %s)', $label, $explanation['decided_by'] ),
				];
			}
		}

		return $unmet;
	}

	/**
	 * Register the module SPL autoloader once (FR-014).
	 *
	 * @return void
	 */
	private function register_autoloader(): void {
		if ( $this->autoloader_registered ) {
			return;
		}
		$this->autoloader_registered = true;

		spl_autoload_register( [ $this, 'autoload' ] );
	}

	/**
	 * Map a discovered module's namespace prefix to its directory for the autoloader.
	 *
	 * @param string $class Fully-qualified entry class name.
	 * @param string $dir   Absolute module directory (dirname of module.php).
	 * @return void
	 */
	private function register_namespace( string $class, string $dir ): void {
		$namespace = ( new ReflectionClass( $class ) )->getNamespaceName();
		if ( '' === $namespace ) {
			return;
		}

		$prefix = $namespace . '\\';
		$base   = rtrim( $dir, '/\\' ) . DIRECTORY_SEPARATOR;

		// Two modules declaring the SAME namespace (the copy-a-module-and-rename-the-
		// directory mistake) would otherwise silently overwrite this entry, and every
		// sub-class of BOTH modules would autoload from whichever directory registered
		// last — a wrong-file failure with no visible cause. Keep the first mapping and
		// say so, mirroring the name-collision rule above.
		if ( isset( $this->namespace_map[ $prefix ] ) && $this->namespace_map[ $prefix ] !== $base ) {
			$this->notice( sprintf( 'Templately module namespace "%s" is already mapped to %s; ignoring the duplicate declaration in %s.', $namespace, $this->namespace_map[ $prefix ], $base ) );
			return;
		}

		$this->namespace_map[ $prefix ] = $base;
	}

	/**
	 * SPL autoloader for module sub-classes (FR-014). Maps
	 * `Templately\Modules\{Pascal}\Sub\Class` → `modules/{kebab}/Sub/Class.php`.
	 *
	 * @param string $class Fully-qualified class name being loaded.
	 * @return void
	 */
	public function autoload( string $class ): void {
		foreach ( $this->namespace_map as $prefix => $base_dir ) {
			if ( 0 !== strncmp( $class, $prefix, strlen( $prefix ) ) ) {
				continue;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$file     = $base_dir . str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
			return;
		}
	}

	/**
	 * Convert a kebab-case directory name to PascalCase (`template-browsing` → `TemplateBrowsing`).
	 *
	 * @param string $kebab Kebab-case name.
	 * @return string
	 */
	private function pascal_case( string $kebab ): string {
		return str_replace( ' ', '', ucwords( str_replace( [ '-', '_' ], ' ', $kebab ) ) );
	}

	/**
	 * Verify a module's declared requirements against the running environment.
	 *
	 * Returns `true` when every declared requirement is met, or a single human-readable
	 * reason string for the FIRST unmet one (e.g. "requires PHP >= 7.4 (running 7.2.34)").
	 * All keys are optional; see {@see Module_Base::get_requirements()} for the contract.
	 * Kept side-effect-free so it is safe to call during discovery on a probe instance.
	 *
	 * @param array $requirements Declared (and already filtered) requirements.
	 * @return true|string
	 */
	private function check_requirements( array $requirements ) {
		if ( isset( $requirements['php'] ) && version_compare( PHP_VERSION, (string) $requirements['php'], '<' ) ) {
			return sprintf( 'requires PHP >= %s (running %s)', $requirements['php'], PHP_VERSION );
		}

		if ( isset( $requirements['wp'] ) ) {
			$wp_version = get_bloginfo( 'version' );
			if ( '' === $wp_version && isset( $GLOBALS['wp_version'] ) ) {
				$wp_version = $GLOBALS['wp_version'];
			}
			if ( version_compare( (string) $wp_version, (string) $requirements['wp'], '<' ) ) {
				return sprintf( 'requires WordPress >= %s (running %s)', $requirements['wp'], $wp_version );
			}
		}

		if ( ! empty( $requirements['classes'] ) ) {
			foreach ( (array) $requirements['classes'] as $class ) {
				if ( ! class_exists( $class ) && ! interface_exists( $class ) ) {
					return sprintf( 'requires the class %s', $class );
				}
			}
		}

		if ( ! empty( $requirements['functions'] ) ) {
			foreach ( (array) $requirements['functions'] as $function ) {
				if ( ! function_exists( $function ) ) {
					return sprintf( 'requires the function %s()', $function );
				}
			}
		}

		if ( ! empty( $requirements['plugins'] ) ) {
			// is_plugin_active() lives in wp-admin/includes and is NOT loaded on plugins_loaded,
			// so read the option directly (plus network-active plugins on multisite).
			$active = (array) get_option( 'active_plugins', [] );
			if ( is_multisite() ) {
				$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) ) );
			}
			foreach ( (array) $requirements['plugins'] as $plugin ) {
				if ( ! in_array( $plugin, $active, true ) ) {
					return sprintf( 'requires the plugin %s to be active', $plugin );
				}
			}
		}

		if ( isset( $requirements['multisite'] ) ) {
			$needs_multisite = (bool) $requirements['multisite'];
			if ( $needs_multisite !== is_multisite() ) {
				return $needs_multisite ? 'requires multisite' : 'requires a single-site install';
			}
		}

		return true;
	}

	/**
	 * Emit a non-fatal diagnostic notice for a skipped module (FR-011).
	 *
	 * @param string $message Human-readable reason.
	 * @return void
	 */
	private function notice( string $message ): void {
		trigger_error( esc_html( $message ), E_USER_NOTICE ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error

		// Templately's dedicated log file, not debug.log (WP_DEBUG_LOG-gated inside).
		\Templately\Utils\Helper::log( $message, 'modules', 'warning' );
	}

	/**
	 * Record a BY-DESIGN module skip (an unmet declarative requirement).
	 *
	 * Deliberately log-only — the counterpart to {@see notice()} for outcomes that are
	 * expected rather than anomalous. `trigger_error()` writes into the response body when
	 * `display_errors` is on, so using it for a condition that recurs on every request
	 * (e.g. a multisite-only module on a single-site install) would corrupt every REST
	 * payload and send headers before WordPress can. The machine-readable surface for
	 * these skips is {@see get_unavailable_modules()}.
	 *
	 * @param string $message Human-readable reason.
	 * @return void
	 */
	private function log_unavailable( string $message ): void {
		// Templately's dedicated log file, not debug.log (WP_DEBUG_LOG-gated inside).
		\Templately\Utils\Helper::log( $message, 'modules', 'info' );
	}
}
