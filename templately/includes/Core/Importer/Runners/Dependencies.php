<?php

namespace Templately\Core\Importer\Runners;

use Exception;
use Templately\Core\Importer\Exception\NonRetirableErrorException;
use Templately\Core\Importer\Form;
use Templately\Core\Importer\Runners\BaseRunner;
use Templately\Core\Importer\Utils\Utils;
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
		return ! empty( $data['theme'] ) || ! empty( $data['plugins'] );
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
			$progress['plugins_installed'] = true;
			$this->update_session_data( [
				'progress' => $progress,
			] );

			// Exit current request and continue in new AJAX call where WordPress has loaded the plugins
			$this->sse_message( [
				'type'    => 'continue',
				'action'  => 'continue',
				'info'    => 'plugins_installed',
				'results' => __METHOD__ . '::' . __LINE__,
			] );
			exit;
		}

		// /**
		//  * Verify that required plugins are active and their classes are loaded
		//  * This runs in the new AJAX call after WordPress has loaded the plugins
		//  */
		// $this->verify_required_plugins_active( $data );

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
			// error_log(print_r($request_params['plugins'], true), 3, ABSPATH . 'wp-content/debug.log');

			// Sort plugins to ensure pro plugins are activated after free versions
			$plugin_order = [
				'elementor/elementor.php',
				'elementor-pro/elementor-pro.php',
				'essential-addons-for-elementor-lite/essential_adons_elementor.php',
				'essential-addons-elementor/essential_adons_elementor.php'
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
				error_log(print_r([$key,  $dependency['name']], true), 3, ABSPATH . 'wp-content/debug.log');

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
						throw new NonRetirableErrorException($error_message);
					}

					$results['failed'][] = [
						'name'    => $dependency['name'],
						'slug'    => $dependency['slug'],
						'link'    => $dependency['link'],
						'message' => $plugin_status['message'] ?? ''
					];
				} else {
					$_installed_plugins++;
					// $total_plugin_installed--;
				}

				$results['_installed_plugins'] = $_installed_plugins;

				$this->sse_log( 'plugin', 'Installed Required Plugins: ' . $dependency['name'], floor( ( 100 * $_installed_plugins / $total_plugin ) ) );

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
	 * Verify that required plugins are active and their classes are loaded
	 *
	 * This method checks if the required plugins (Elementor or Gutenberg) are properly
	 * activated and their classes are available. This is crucial because WordPress doesn't
	 * automatically load newly installed plugins until the next request.
	 *
	 * @param array $data Request parameters containing plugin information
	 * @throws NonRetirableErrorException If required plugin classes are not loaded
	 */
	private function verify_required_plugins_active( $data ) {
		// Get the platform from manifest
		$platform = $data['manifest']['platform'] ?? '';

		// Check for Elementor platform
		if ( $platform === 'elementor' ) {
			// Check if Elementor core classes are loaded
			if ( ! class_exists( 'Elementor\Plugin' ) && ! class_exists( 'Elementor\Core\Base\Document' ) ) {
				$error_message = __( 'Elementor plugin is not properly activated. Please refresh the page and try again.', 'templately' );
				throw new NonRetirableErrorException( $error_message );
			}
		}

		// Check for Gutenberg/Essential Blocks platform
		if ( $platform === 'gutenberg' ) {
			// Check if Essential Blocks plugin is active
			if ( ! defined( 'ESSENTIAL_BLOCKS_FILE' ) ) {
				$error_message = __( 'Essential Blocks plugin is not properly activated. Please refresh the page and try again.', 'templately' );
				throw new NonRetirableErrorException( $error_message );
			}
		}

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
						throw new NonRetirableErrorException( $error_message );
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

		$results = $this->loop($themes, function($key, $theme) {
			$result = [];
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
					$result = [
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
					$result = [
						'success' => true,
						'name'    => $theme['name'],
						'slug'    => $theme['stylesheet'],
						'message' => $plugin_status['message'] ?? ''
					];
					$progress['theme_dependency'] = true;
				}

			}
			return $result;
		});


		return $results;
	}
}
