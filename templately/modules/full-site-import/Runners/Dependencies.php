<?php

namespace Templately\Modules\FullSiteImport\Runners;

use Exception;
use Templately\Modules\FullSiteImport\Exception\FatalErrorException;
use Templately\Modules\FullSiteImport\Utils\Form;
use Templately\Modules\FullSiteImport\Runners\BaseRunner;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Utils\Helper;
use Templately\Utils\Installer;

class Dependencies extends BaseRunner {

	public function get_name(): string {
		return 'dependencies';
	}

	public function get_label(): string {
		return __( 'Download Dependencies', 'templately' );
	}

	public function should_log(): bool {
		return true;
	}

	public function get_action(): string {
		return 'eventLog';
	}

	public function log_message(): string {
		return __( 'Downloading Dependencies', 'templately' );
	}

	public function should_run( $data, $imported_data = [] ): bool {
		return self::has_dependencies_to_install( (array) $data );
	}

	/**
	 * Whether this import carries anything for this runner to install.
	 *
	 * Static twin of {@see self::should_run()} so callers outside the runner loop
	 * can ask the same question without instantiating a runner. The two must stay
	 * in agreement, which is why `should_run()` delegates here rather than
	 * repeating the condition.
	 *
	 * @param array $data Request params.
	 * @return bool
	 */
	public static function has_dependencies_to_install( array $data ): bool {
		return ! empty( $data['theme'] ) || ! empty( $data['plugins'] );
	}

	/**
	 * Whether this runner still has plugins to install and activate.
	 *
	 * True only on the slice BEFORE installation happens. `import()` installs, marks
	 * `plugins_installed`, and then deliberately `exit`s with a `continue` event so
	 * the next AJAX call starts a fresh PHP request in which WordPress has actually
	 * LOADED the newly activated plugins — they are not loadable in the request that
	 * activated them.
	 *
	 * That two-phase shape is why `Import::run()`'s builder preflight must consult
	 * this before refusing. The preflight asks "is the pack's builder loaded?", and
	 * on the first slice of a fresh site the honest answer is "not yet, and this
	 * runner is about to fix that". Running it unconditionally turned every import
	 * that needed its builder INSTALLED into a hard, non-retryable stop — the
	 * dependency step could never run, so the plugin it would have installed was
	 * exactly the plugin the user was told to go and install by hand.
	 *
	 * @param array $data Request params (carrying `progress` from the session).
	 * @return bool
	 */
	public static function installation_pending( array $data ): bool {
		$progress = isset( $data['progress'] ) && is_array( $data['progress'] ) ? $data['progress'] : [];

		return self::has_dependencies_to_install( $data ) && empty( $progress['plugins_installed'] );
	}

	public function import( $data, $imported_data ): array {
		$dependency_data = [];
		$progress = $data['progress'] ?? [];

  		/**
		 * Checking & Installing Plugin Dependencies
		 */
		$dependency_data['theme']   = $this->install_themes($data);
		$dependency_data['plugins'] = $this->install_plugins($data);

		/**
		 * Exit current request to allow WordPress to load newly activated plugins
		 * On the next AJAX call, WordPress will have loaded all active plugins
		 */
		if ( empty( $progress['plugins_installed'] ) ) {
			// Mark plugins as installed
			SessionData::mark_step_complete($this->session_id, 'plugins_installed');

			// Exit current request and continue in new AJAX call where WordPress has loaded the plugins
			$this->sse_message( [
				'type'    => 'continue',
				'action'  => 'continue',
				'info'    => 'plugins_installed',
				'results' => __METHOD__ . '::' . __LINE__,
			] );
			exit;
		}

		/**
		 * Verify that required plugins are active and their classes are loaded.
		 * Runs in the new AJAX call, after WordPress has loaded the plugins.
		 *
		 * RE-ENABLED. This was commented out in f6697b73a (2025-12-08) as a
		 * temporary bypass and never restored. With it disabled, an import whose
		 * builder plugin is missing or failed to activate ran on to the Templates
		 * runner and died with a raw PHP fatal:
		 *
		 *   Uncaught Error: Class "Elementor\Core\Base\Document" not found
		 *
		 * That fatal reached real users via post-import feedback reports. This
		 * check turns it into an actionable FatalErrorException instead.
		 */
		$this->verify_required_plugins_active( $data );

		return  ['dependency_data' => $dependency_data];
	}

	private function install_plugins($request_params) {
		$progress = $request_params['progress'] ?? [];
		$results = [];

		if ( ! empty( $request_params['plugins'] ) && is_array( $request_params['plugins'] ) ) {
			// $this->sse_log( 'plugin', 'Installing Plugins', 1 );
			$total_plugin = count($request_params['plugins']);

			// $total_plugin_installed = $total_plugin;
			// $_installed_plugins     = $request_params['dependency_data']['plugins']['_installed_plugins'] ?? 0;
			$progress['plugin_dependency'] = $progress['plugin_dependency'] ?? [];

			// $this->before_install_hook();

			// Sort plugins to ensure pro plugins are activated after free versions.
			$plugin_order = [
				'elementor/elementor.php',
				'elementor-pro/elementor-pro.php',
				'essential-addons-for-elementor-lite/essential_adons_elementor.php',
				'essential-addons-elementor/essential_adons_elementor.php',
				'essential-blocks/essential-blocks.php',
				'essential-blocks-pro/essential-blocks-pro.php',
			];

			usort($request_params['plugins'], function($a, $b) use ($plugin_order) {
				$pos_a = array_search($a['plugin_file'], $plugin_order);
				$pos_b = array_search($b['plugin_file'], $plugin_order);

				// If both plugins are in the order array, sort by their position
				if ($pos_a !== false && $pos_b !== false) {
					return $pos_a - $pos_b;
				}

				// If only one plugin is in the order array, prioritize it
				if ($pos_a !== false) {
					return -1;
				}
				if ($pos_b !== false) {
					return 1;
				}

				// If neither plugin is in the order array, maintain original order
				return 0;
			});

			$results = $this->loop( $request_params['plugins'], function( $key,  $dependency, $results ) use(&$_installed_plugins, $total_plugin) {
				$_installed_plugins = $results['_installed_plugins'] ?? 0;

				// $result = [];
				$this->sse_log( 'plugin', 'Installing Required Plugins: ' . $dependency['name'], floor( ( 100 * $_installed_plugins / $total_plugin ) ) );

				$dependency['slug'] = $dependency['plugin_original_slug'];
				$plugin_status      = Installer::get_instance()->install($dependency);

				if (!$plugin_status['success']) {
					$error_message = 'Installation Failed: ' . $dependency['name'];
					if (!empty($plugin_status['message'])) {
						$error_message .= ' (' . $plugin_status['message'] . ')';
					}

					$this->sse_message([
						'position' => 'plugin',
						'action'   => 'updateLog',
						'status'   => 'error',
						'message'  => $error_message,
						'type'     => "plugin_{$dependency['plugin_original_slug']}",
						'progress' => 0
					]);

					if (isset($dependency['mustHave']) && $dependency['mustHave']) {
						$this->removeLog('plugin');
						throw new FatalErrorException($error_message);
					}

					$results['failed'][] = [
						'name'    => $dependency['name'],
						'slug'    => $dependency['slug'],
						'link'    => $dependency['link'] ?? '',
						'message' => $plugin_status['message'] ?? '',
					];
				} else {
					$_installed_plugins++;
					// $total_plugin_installed--;
				}

				$results['_installed_plugins'] = $_installed_plugins;

				// Say what happened. This line used to run unconditionally, so the ONLY live
				// log a non-entitled user saw for a refused pro plugin said it was installed.
				if ( ! empty( $plugin_status['success'] ) ) {
					$this->sse_log( 'plugin', 'Installed Required Plugins: ' . $dependency['name'], floor( ( 100 * $_installed_plugins / $total_plugin ) ) );
				} else {
					$this->sse_log( 'plugin', 'Skipped: ' . $dependency['name'], floor( ( 100 * $_installed_plugins / $total_plugin ) ) );
				}

				return $results;
			}, null, true);

			// $this->after_install_hook();

			$this->sse_message([
				'action'   => 'updateLog',
				'status'   => 'complete',
				'message'  => "Installed Required Plugins ({$results['_installed_plugins']}/$total_plugin)",
				'type'     => "plugin",
				'progress' => 100
			]);
			$results['total'] = $total_plugin;
			$results['succeed'] = $results['_installed_plugins'];
		} else {
			$this->removeLog('plugin');
		}



		return $results;
	}

	/**
	 * Assert that the builder the pack targets is actually LOADED in this request.
	 *
	 * Static and public because this must ALSO run when this runner is skipped.
	 * `should_run()` only returns true when the request carries `theme`/`plugins`,
	 * so an import that supplies no plugin list — one started headlessly through
	 * an agent capability, or a pack whose plugin list did not reach the request —
	 * bypasses this runner entirely and lands in the Templates runner with no
	 * builder loaded. `Import::run()` therefore calls this directly, before any
	 * runner executes.
	 *
	 * Checking `class_exists()` rather than `is_plugin_active()` is deliberate: a
	 * plugin can be flagged active in options yet not be loaded in THIS request
	 * (WordPress only reads the active-plugin list at bootstrap, so a plugin
	 * activated mid-request is unavailable until the next one). It is the class,
	 * not the option, that `Factory\TemplateFactory` needs.
	 *
	 * @param string $platform Platform slug from the manifest.
	 * @throws FatalErrorException If the builder's classes are not loaded.
	 */
	public static function verify_platform_available( string $platform ): void {
		if ( 'elementor' === $platform ) {
			if ( ! class_exists( 'Elementor\Plugin' ) && ! class_exists( 'Elementor\Core\Base\Document' ) ) {
				throw new FatalErrorException(
					__( 'Elementor is required by this template but is not active on your site. Please install and activate Elementor, then try again.', 'templately' )
				);
			}
		}

		if ( 'gutenberg' === $platform ) {
			if ( ! defined( 'ESSENTIAL_BLOCKS_FILE' ) ) {
				throw new FatalErrorException(
					__( 'Essential Blocks is required by this template but is not active on your site. Please install and activate Essential Blocks, then try again.', 'templately' )
				);
			}
		}
	}

	/**
	 * Verify that required plugins are active and their classes are loaded
	 *
	 * This method checks if the required plugins (Elementor or Gutenberg) are properly
	 * activated and their classes are available. This is crucial because WordPress doesn't
	 * automatically load newly installed plugins until the next request.
	 *
	 * @param array $data Request parameters containing plugin information
	 * @throws FatalErrorException If required plugin classes are not loaded
	 */
	private function verify_required_plugins_active( $data ) {
		self::verify_platform_available( $data['manifest']['platform'] ?? '' );

		// Additional verification: Check if installed plugins are actually active
		if ( ! empty( $data['plugins'] ) && is_array( $data['plugins'] ) ) {
			foreach ( $data['plugins'] as $plugin ) {
				// Only check mustHave plugins
				if ( isset( $plugin['mustHave'] ) && $plugin['mustHave'] ) {
					if ( ! is_plugin_active( $plugin['plugin_file'] ) ) {
						$error_message = sprintf(
							__( 'Required plugin "%s" is not active. Please refresh the page and try again.', 'templately' ),
							$plugin['name']
						);
						throw new FatalErrorException( $error_message );
					}
				}
			}
		}
	}

	private function install_themes($request_params) {
		$themes = [];


		if ( ! empty( $request_params['theme'] ) && is_array( $request_params['theme'] ) ) {
			$themes = [$request_params['theme']];
		}
		else {
			$this->removeLog( 'theme' );
		}
			// $this->before_install_hook();

		$results = $this->loop($themes, function($key, $theme, $results) {
			if (isset($theme['stylesheet'])) {
				$stylesheet = get_option('stylesheet');

				// do_action('before_theme_activation', $theme); // Trigger action before theme activation
				$this->sse_log('theme', 'Installing and Activating Theme: ' . $theme['name'], 0);
				if (!get_option("__templately_stylesheet")) {
					add_option("__templately_stylesheet", $stylesheet, '', 'no');
				}

				$plugin_status      = Installer::get_instance()->install_and_activate_theme($theme['stylesheet']);

				if (!$plugin_status['success']) {
					$this->sse_message([
						'action'   => 'updateLog',
						'status'   => 'error',
						'message'  => "Failed to activate theme: " . $theme['name'],
						'type'     => "theme",
						'progress' => 0
					]);
					$results = [
						'success' => false,
						'name'    => $theme['name'],
						'slug'    => $theme['stylesheet'],
						'message' => $plugin_status['message'] ?? ''
					];
				} else {
					// do_action('after_theme_activation', $theme); // Trigger action after theme activation

					$this->sse_message([
						'action'   => 'updateLog',
						'status'   => 'complete',
						'message'  => "Activated theme: " . $theme['name'],
						'type'     => "theme",
						'progress' => 100
					]);
					$results = [
						'success' => true,
						'name'    => $theme['name'],
						'slug'    => $theme['stylesheet'],
						'message' => $plugin_status['message'] ?? ''
					];
					$progress['theme_dependency'] = true;
				}

			}
			return $results;
		});


		return $results;
	}
}
