<?php
/**
 * Templately plugin.
 *
 * The main plugin handler class is responsible for initializing Templately. The
 * class registers and all the components required to run the plugin.
 *
 * @package Templately
 */

namespace Templately;

use Templately\Utils\Base;
use Templately\Utils\Enqueue;

use Templately\Core\Admin;
use Templately\Core\Platform_Registry;
use Templately\Core\Modules_Manager;
use Templately\Core\Capability_Seed;

use Templately\API\Sites;
use Templately\Core\Maintenance;
use Templately\Core\Migrator;
use Templately\Utils\Response\RestEnvelope;

final class Plugin extends Base {
    public $version = '3.8.1';

	public $admin;
	/**
	 * Enqueue class responsible for assets
	 * @var Enqueue
	 */
	public $assets;

	/**
	 * Set by modules/theme-builder/module.php's init_hooks() (during
	 * Modules_Manager::boot(), inside plugins_loaded()) — NOT here in the
	 * constructor. ThemeBuilder is now a module-namespaced class
	 * (Templately\Modules\ThemeBuilder\ThemeBuilder); eagerly instantiating it
	 * in the constructor (which runs before plugins_loaded even fires) would
	 * fatal on class-not-found, since Modules_Manager hasn't registered that
	 * module's autoloader yet.
	 *
	 * @var \Templately\Modules\ThemeBuilder\ThemeBuilder
	 */
	public $theme_builder;

	/**
	 * Plugin constructor.
	 * Initializing Templately plugin.
	 *
	 * @access private
	 */
	public function __construct() {
		$this->define_constants();
		$this->set_locale();

		Maintenance::init();

		$this->assets        = Enqueue::get_instance( TEMPLATELY_URL, TEMPLATELY_PATH, $this->version );
		$this->admin         = Admin::get_instance();

		add_action( 'plugins_loaded', [ $this, 'plugins_loaded' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );

		// 043 — the single response envelope, applied on rest_post_dispatch so
		// every route in the namespace is covered by construction rather than by
		// each endpoint remembering to opt in.
		RestEnvelope::init();


		/**
		 * Initialize.
		 */
		do_action( 'templately_init' );

	}

	/**
	 * Cloning is forbidden.
	 *
	 * @since 2.0
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning is forbidden.', 'templately' ), '2.0' );
	}

	/**
	 * Un-serializing instances of this class is forbidden.
	 *
	 * @since 2.0
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, esc_html__( 'Un-serializing instances of this class is forbidden.', 'templately' ), '2.0' );
	}

	/**
	 * Initializing Things on Plugins Loaded
	 * @return void
	 */
	public function plugins_loaded() {
		$this->apis(); // APIs LOADED

		/**
		 * Host capability registry (spec 053): the seed set MUST exist before any
		 * module boots so is_active()/sub-feature gates can consult it. Registration
		 * is metadata-only — no probe runs here.
		 */
		Capability_Seed::register_all();

		/**
		 * Feature modules (spec 004): auto-discovered from modules/*, booted after the
		 * legacy API registration so a module may depend on it.
		 */
		Modules_Manager::get_instance()->boot();

		/**
		 * Migrator for Templately
		 */
		Migrator::get_instance();
	}

	/**
	 * All the API instantiated
	 *
	 * Every other legacy API endpoint (ThemeBuilderApi, Tour, Conditions, ...)
	 * migrated to its own module's register_rest_routes(), booted lazily on
	 * rest_api_init (Phase 2 extraction, specs 013-037 — see
	 * docs/modularization-prd.md). Sites is the one REST endpoint with no
	 * owning feature spec (site-connection migration is cross-cutting
	 * infrastructure, not a bounded feature) — it stays here as core.
	 *
	 * Both Elementor and Gutenberg platform drivers are now self-registered by
	 * their own module's init_hooks() (modules/elementor-integration/module.php,
	 * modules/gutenberg-integration/module.php) — the former platforms() method
	 * that used to run before this one is gone; there is nothing left to boot
	 * before Modules_Manager::boot() runs.
	 *
	 * @return void
	 */
	private function apis() {
		Sites::get_instance();

		// Note: DeveloperSettings::get_instance() is called in Developer::init_modules() when developer functionality is available and enabled

		// MCP Abilities (specs/041-mcp-abilities) now boots via modules/mcp-abilities/
		// module.php (Modules_Manager auto-discovery, spec 004) — see Plugin::plugins_loaded().
	}

	/**
	 * Register all REST API endpoints
	 * @return void
	 */
	public function register_routes() {
		if ( ! empty( $modules = Platform_Registry::get_instance()->get( 'API' ) ) ) {
			foreach ( $modules as $module ) {
				// Module-owned endpoints (Templately\Modules\*) register explicitly via
				// Modules_Manager::boot_rest_routes (constitution §VIII). They can still
				// land in this legacy registry through the shared API base constructor if
				// something instantiates them early — skip them so routes register once.
				if ( 0 === strpos( get_class( $module->object ), 'Templately\\Modules\\' ) ) {
					continue;
				}
				$module->object->register_routes();
			}
		}
	}

	/**
	 * Define CONSTANTS
	 *
	 * @return void
	 * @since 2.0.0
	 */
	public function define_constants() {
		$this->define( 'TEMPLATELY_URL', plugin_dir_url( TEMPLATELY_FILE ) );
		$this->define( 'TEMPLATELY_ASSETS', TEMPLATELY_URL . 'assets/' );
		$this->define( 'TEMPLATELY_PLUGIN_BASENAME', plugin_basename( TEMPLATELY_FILE ) );
		$this->define( 'TEMPLATELY_VERSION', $this->version );
		$this->define( 'TEMPLATELY_API_NAMESPACE', 'templately/v1' );
		$this->define( 'TEMPLATELY_VIEWS_ABSPATH', TEMPLATELY_PATH . 'views/' );
	}

	/**
	 * Define constant if not already set.
	 *
	 * @param string $name Constant name.
	 * @param mixed $value Constant value.
	 *
	 * @return void
	 */
	private function define( string $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}

	/**
	 * Setting the locale for translation availability
	 * @return void
	 * @since 1.0.0
	 */
	public function set_locale() {
		add_action( 'init', [ $this, 'load_textdomain' ] );
	}

	/**
	 * Loading Text Domain on init HOOK
	 * @return void
	 * @since 1.0.0
	 *
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'templately', false, dirname( TEMPLATELY_PLUGIN_BASENAME ) . '/languages' );
	}

}
