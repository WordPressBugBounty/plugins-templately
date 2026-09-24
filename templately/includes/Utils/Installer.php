<?php

namespace Templately\Utils;

use Automatic_Upgrader_Skin;
use Templately\Modules\ProPluginProvisioning\Archive as ProProvisioningArchive;
use Templately\Modules\ProPluginProvisioning\Catalog as ProProvisioningCatalog;
use Templately\Modules\ProPluginProvisioning\Module as ProProvisioningModule;
use Plugin_Upgrader;
use Theme_Upgrader;
use WP_Ajax_Upgrader_Skin;
use WP_Filesystem_Base;
use function activate_plugin;
use function current_user_can;
use function install_plugin_install_status;
use function is_plugin_inactive;
use function is_wp_error;
use function plugins_api;
use function sanitize_key;
use function wp_unslash;

class Installer extends Base {
	/**
	 * Some process take long time to execute
	 * for that need to raise the limit.
	 */
	public static function raise_limits() {
		wp_raise_memory_limit( 'admin' );
		if ( wp_is_ini_value_changeable( 'max_execution_time' ) ) {
			@ini_set( 'max_execution_time', 0 );
		}
		@ set_time_limit( 0 );
	}

	public function install( $plugin ): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		include_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$response = [ 'success' => false, 'message' => '' ];

		$_plugins     = Helper::get_plugins();
		$is_installed = isset( $_plugins[ $plugin['plugin_file'] ] );

		// Check if the plugin is already active
		if (is_plugin_active($plugin['plugin_file'])) {
			$response['success'] = true;
			$response['slug']    = $plugin['slug'];
			return $response;
		}

		$is_pro = ! empty( $plugin['is_pro'] );

		if ( $is_pro && ! $is_installed ) {
			$response['code']    = 'pro_plugin';
			$response['message'] = 'Pro Plugin';
		}

		if ( ! $is_installed ) {
			if(!Helper::current_user_can( 'install_plugins' )){
				$response['code']    = 'invalid_requirements';
				$response['message'] = __( 'Sorry, you do not have permission to install a plugin.', 'templately' );
				return $response;
			}

			/**
			 * A pro plugin has no wordpress.org record, so `plugins_api()` cannot supply a
			 * download link for one. Before spec 059 that was the end of it: the response
			 * above stood, and the user was told to install the plugin by hand.
			 *
			 * Now we ASK first. If a registered provisioning source can supply an archive
			 * — because the user's subscription entitles them to it — we install from that
			 * path and skip `plugins_api()` entirely. If nothing answers, the `pro_plugin`
			 * response above is returned untouched, which is byte-for-byte the behaviour
			 * every non-entitled user has always had.
			 *
			 * The ask is made only for plugins that are actually obtainable, and the
			 * catalog — never the descriptor's own claim — decides which those are: this
			 * governs what gets downloaded from a third party and executed on the site, so
			 * a spoofed dependency response must not be able to widen it.
			 *
			 * @see modules/pro-plugin-provisioning/
			 * @see specs/059-pro-plugin-provisioning/contracts/provisioning-source.md
			 */
			$provisioned = $is_pro ? $this->provision_pro_archive( $plugin ) : null;

			if ( null === $provisioned && $is_pro ) {
				// Not obtainable. Return the refusal rather than asking wordpress.org for
				// a plugin it has never heard of.
				return $response;
			}

			if ( null === $provisioned ) {
				$plugins_api_args =  [
					'slug'   => sanitize_key( wp_unslash( $plugin['slug'] ) ),
					'fields' => [
						'sections' => false,
					],
				];
				if(isset($plugin['has_license']) && $plugin['has_license']){
					$plugins_api_args['has_license'] = $plugin['has_license'];
				}
				/**
				 * @var array|object $api
				 */
				$api = plugins_api( 'plugin_information', $plugins_api_args);


				if ( is_wp_error( $api ) ) {
					$response['message'] = $api->get_error_message();

					return $response;
				}

				$compatibility = $this->check_compatibility($api);
				if (!$compatibility['success']) {
					return $compatibility;
				}

				$response['name'] = $api->name;
			} else {
				// A provisioned pro plugin: the refusal recorded above no longer applies.
				// UNSET, not '' — an empty-string code survives every `?? 'fallback'` in the
				// callers, and `new WP_Error( '', … )` early-returns with no error at all,
				// which the REST server turns into a 500 with a null body.
				unset( $response['code'], $response['message'] );
				$response['name'] = $plugin['name'] ?? $plugin['slug'];
			}

			$skin     = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->install( null !== $provisioned ? $provisioned : $api->download_link );

			// Nothing licensed survives the request that downloaded it, on either branch.
			ProProvisioningArchive::discard( $provisioned );

			if ( is_wp_error( $result ) ) {
				$response['code']    = $result->get_error_code();
				$response['message'] = $result->get_error_message();

				return $response;
			} elseif ( is_wp_error( $skin->result ) ) {
				$response['code']    = $skin->result->get_error_code();
				$response['message'] = $skin->result->get_error_message();

				return $response;
			} elseif ( $skin->get_errors()->has_errors() ) {
				$response['message'] = $skin->get_error_messages();

				return $response;
			} elseif ( is_null( $result ) ) {
				global $wp_filesystem;
				$response['code']    = 'unable_to_connect_to_filesystem';
				$response['message'] = __( 'Unable to connect to the filesystem. Please confirm your credentials.' );

				if ( $wp_filesystem instanceof WP_Filesystem_Base && is_wp_error( $wp_filesystem->errors ) && $wp_filesystem->errors->has_errors() ) {
					$response['message'] = esc_html( $wp_filesystem->errors->get_error_message() );
				}

				return $response;
			}
			else if($result !== true){
				$response['message'] = __('Failed to install plugin', 'templately');
				return $response;
			}

			if ( null === $provisioned ) {
				$install_status        = install_plugin_install_status( $api );
				$plugin['plugin_file'] = $install_status['file'];
			}
			// A provisioned plugin keeps the plugin_file the dependency descriptor named:
			// `install_plugin_install_status()` resolves it from a wordpress.org API
			// object, and a pro plugin has none.
		}

		if ( !Helper::current_user_can( 'activate_plugins' ) && is_plugin_inactive( $plugin['plugin_file'] ) ) {
			$response['code']    = 'invalid_requirements';
			$response['message'] = __( 'Sorry, you do not have permission to activate a plugin.', 'templately' );
			return $response;
		}

		// Claim the install immediately before activating, so the caching plugin comes
		// up as a host install — every feature off, page caching on only if nothing
		// else owns it, and its own setup wizard skipped. One option write; it does
		// the rest on its own activation, so there is nothing here to finish or undo.
		if ( Caching::PLUGIN_FILE === $plugin['plugin_file'] ) {
			Caching::claim_install();
		}

		$activate_status = $this->activate_plugin( $plugin['plugin_file'] );

		if ( is_wp_error( $activate_status ) ) {
			$response['message'] = $activate_status->get_error_message();
		}

		if ( $activate_status && ! is_wp_error( $activate_status ) ) {
			$response['success'] = true;
		}

		$response['slug'] = $plugin['slug'];

		return $response;
	}

	/**
	 * Update already-installed plugins to their latest available version.
	 *
	 * Exists because a pack's markup is produced by the block plugin version on the AUTHORING
	 * site: when the importing site runs an older one, blocks whose `save()` has since changed
	 * — or whose newer attributes this version does not declare — fail Gutenberg's validation
	 * and open with "Attempt recovery". Updating first is the only fix that keeps the pack's
	 * newer features; regenerating the markup locally would silently drop them.
	 *
	 * Mirrors {@see self::install()}'s result contract so callers can treat the two alike.
	 *
	 * NOTE: the updated code is NOT loaded in the request that updates it. Callers must
	 * re-check state in a FRESH request before acting on it — the same constraint the FSI
	 * dependency runner handles with its `plugins_installed` → `continue` → new-request split.
	 *
	 * @param string[] $plugin_files Plugin basenames, e.g. `essential-blocks/essential-blocks.php`.
	 * @return array{success:bool,results:array<int,array<string,mixed>>}
	 */
	public function update( array $plugin_files ): array {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		$results = [];

		if ( ! Helper::current_user_can( 'update_plugins' ) ) {
			return [
				'success' => false,
				'code'    => 'invalid_permission',
				'message' => __( 'Sorry, you do not have permission to update plugins.', 'templately' ),
				'results' => [],
			];
		}

		self::raise_limits();

		$installed = Helper::get_plugins();

		foreach ( $plugin_files as $plugin_file ) {
			$plugin_file = (string) $plugin_file;
			$from        = $installed[ $plugin_file ]['Version'] ?? null;

			if ( ! isset( $installed[ $plugin_file ] ) ) {
				$results[] = [
					'plugin_file' => $plugin_file,
					'updated'     => false,
					'code'        => 'not_installed',
					'message'     => __( 'This plugin is not installed on the site.', 'templately' ),
				];
				continue;
			}

			$was_active = is_plugin_active( $plugin_file );

			// Core's own precondition, checked BEFORE handing the plugin over:
			// `Plugin_Upgrader::upgrade()` bails with `up_to_date` when the plugin has no
			// entry in the `update_plugins` transient. Asking first lets us report that as
			// settled; letting the upgrader discover it yields an error we cannot identify,
			// because `WP_Ajax_Upgrader_Skin::error()` files a string code under a GENERATED
			// key (`unknown_upgrade_error_N`) — the literal 'up_to_date' never survives, so
			// there is nothing downstream to match on.
			$current = get_site_transient( 'update_plugins' );
			if ( ! isset( $current->response[ $plugin_file ] ) ) {
				$results[] = [
					'plugin_file' => $plugin_file,
					'updated'     => true,
					'code'        => 'already_updated',
					'from'        => $from,
					'to'          => $from,
				];
				continue;
			}

			$skin     = new WP_Ajax_Upgrader_Skin();
			$upgrader = new Plugin_Upgrader( $skin );
			// `clear_update_cache => false` is REQUIRED in a loop, and core's own
			// `bulk_upgrade()` does exactly this. Left at its default of true, `upgrade()`
			// hooks `wp_clean_plugins_cache` onto `upgrader_process_complete`, so the FIRST
			// plugin to upgrade successfully deletes the `update_plugins` transient — and
			// every later plugin in this loop then re-reads that now-empty transient at the
			// top of `upgrade()`, finds no `response[$plugin]`, and fails with `up_to_date`
			// ("The plugin is at the latest version.") without being touched. Updating three
			// plugins updated one and falsely reported the other two as already current.
			// The single `wp_clean_plugins_cache( true )` after the loop does the refresh
			// once, which is the whole point of deferring it.
			$result = $upgrader->upgrade( $plugin_file, [ 'clear_update_cache' => false ] );

			$error = null;
			if ( is_wp_error( $result ) ) {
				$error = $result;
			} elseif ( is_wp_error( $skin->result ) ) {
				$error = $skin->result;
			} elseif ( $skin->get_errors()->has_errors() ) {
				$error = $skin->get_errors();
			}

			if ( null !== $error ) {
				$results[] = [
					'plugin_file' => $plugin_file,
					'updated'     => false,
					'code'        => $error->get_error_code(),
					'message'     => $error->get_error_message(),
					'from'        => $from,
				];
				continue;
			}

			if ( false === $result ) {
				$results[] = [
					'plugin_file' => $plugin_file,
					'updated'     => false,
					'code'        => 'update_failed',
					'message'     => __( 'The update could not be completed. Please update this plugin from the Plugins screen.', 'templately' ),
					'from'        => $from,
				];
				continue;
			}

			// WP deactivates a plugin while upgrading it; put it back the way we found it.
			//
			// SILENTLY, and that is not a detail. `Plugin_Upgrader` deactivates with
			// `deactivate_plugins( $plugin, true )` — hooks suppressed — because the plugin
			// is not being turned off, it is being swapped underneath. Re-activating it
			// loudly runs its ACTIVATION hooks, and those belong to the version now on disk
			// while this request is still running the autoloader it booted with. WooCommerce
			// 11.1.1's `WC_Install::install()` reaches for a class that exists only in its
			// own build and fatals the whole request: every plugin after it in this loop
			// goes un-updated, and the user is told a critical error occurred for all of
			// them — for plugins that in fact updated correctly.
			//
			// A wrapper restoring prior state has no business running first-run setup. The
			// plugin's own upgrade routine runs on the NEXT request, from its own code,
			// which is where it can work.
			if ( $was_active && ! is_plugin_active( $plugin_file ) ) {
				try {
					$this->activate_plugin( $plugin_file, true );
				} catch ( \Throwable $e ) {
					// The UPDATE succeeded; only putting it back failed. Say so, and let the
					// rest of the run continue — aborting here would strand every plugin
					// after this one, which is exactly what the fatal above did.
					Helper::log(
						sprintf( 'update: %s updated but could not be re-activated — %s', $plugin_file, $e->getMessage() ),
						'installer_update',
						'warning'
					);
				}
			}

			// Read the version off disk rather than the stale in-memory copy.
			$to = null;
			if ( function_exists( 'get_plugin_data' ) && file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
				$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $plugin_file, false, false );
				$to   = $data['Version'] ?? null;
			}

			$results[] = [
				'plugin_file' => $plugin_file,
				'updated'     => true,
				'from'        => $from,
				'to'          => $to,
			];
		}

		// A fresh check so the next read of the update transient reflects what we just did.
		// The argument is `$clear_update_cache`, and it MUST be true: `false` deletes only the
		// `plugins` object cache and LEAVES the `update_plugins` transient in place, so every
		// plugin we just updated keeps its stale "update available" entry and comes back as a
		// recommendation on the next dependency check.
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}

		$failed = array_filter( $results, function ( $result ) {
			return empty( $result['updated'] );
		} );

		return [
			'success' => empty( $failed ),
			'results' => $results,
		];
	}

	public function install_and_activate_theme($theme_slug) {
		require_once(ABSPATH . 'wp-admin/includes/class-wp-upgrader.php');
		require_once(ABSPATH . 'wp-admin/includes/theme.php');
		require_once(ABSPATH . 'wp-admin/includes/theme-install.php');

		$response = ['success' => false];

		// Check if the theme is already active
		if ($theme_slug == get_option('stylesheet')) {
			$response['success'] = true;
			$response['message'] = __('Theme is already active', 'templately');
			return $response;
		}

		if (!function_exists('themes_api')) {
			$response['message'] = __('Function themes_api does not exist', 'templately');
			return $response;
		}

		$api = themes_api('theme_information', [
			'slug' => sanitize_key($theme_slug),
			'fields' => [
				'sections' => false,
			],
		]);

		if (is_wp_error($api)) {
			$response['message'] = $api->get_error_message();
			return $response;
		}

		$compatibility = $this->check_compatibility($api);
		if (!$compatibility['success']) {
			return $compatibility;
		}

		if (!wp_get_theme($theme_slug)->exists()) {
			$upgrader = new Theme_Upgrader(new Automatic_Upgrader_Skin());
			$result = $upgrader->install($api->download_link);

			if (is_wp_error($result)) {
				$response['message'] = $result->get_error_message();
				return $response;
			}
			else if($result !== true){
				$response['message'] = __('Failed to install theme', 'templately');
				return $response;
			}

			$response['install'] = 'success';
		}

		$activate_status = $this->activate_theme($theme_slug);

		if ( is_wp_error( $activate_status ) ) {
			$response['message'] = $activate_status->get_error_message();
		}
		else if ($activate_status) {
			$response['success'] = true;
		} else {
			$response['message'] = __('Failed to activate theme', 'templately');
		}

		return $response;
	}

	public function check_compatibility($api) {
		// Check compatibility with current PHP version
		if (version_compare(PHP_VERSION, $api->requires_php, '<')) {
			return [
				'success' => false,
				'message' => sprintf(__('The plugin requires PHP version %s or higher. You are running version %s.', 'templately'), $api->requires_php, PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION)
			];
		}

		// Check compatibility with current WP version
		global $wp_version;
		if (version_compare($wp_version, $api->requires, '<')) {
			return [
				'success' => false,
				'message' => sprintf(__('The plugin requires WordPress version %s or higher. You are running version %s.', 'templately'), $api->requires, $wp_version)
			];
		}

		return ['success' => true];
	}

	/**
	 * @param string $file   Plugin file.
	 * @param bool   $silent Skip the activation hooks. Correct ONLY when the plugin was
	 *                       already set up and is merely being put back the way we found
	 *                       it — see the call in `update()`. A fresh install must run them.
	 */
	private function activate_plugin( $file, $silent = false ) {
		if ( is_plugin_active( $file ) ) {
			return true;
		}

		if ( Helper::current_user_can( 'activate_plugins' ) && is_plugin_inactive( $file ) ) {
			$result = activate_plugin( $file, '', false, $silent );
			if ( is_wp_error( $result ) ) {
				return $result;
			} else {
				$this->clear_known_activation_redirects();

				return true;
			}
		}

		return false;
	}

	/**
	 * Neutralise "getting started" redirect flags that popular dependency plugins
	 * set in their own activation hooks (e.g. Elementor's
	 * `elementor_activation_redirect` transient, Essential Addons' own
	 * `eael_do_activation_redirect` transient + `eael_setup_wizard` option).
	 *
	 * Templately activates these plugins programmatically during FSI / single-
	 * template import. Each plugin's own redirect check is guarded against
	 * `DOING_AJAX`, so it never fires DURING the (AJAX-driven) import request —
	 * but the flag survives past it and hijacks the user's NEXT ordinary
	 * (non-AJAX) admin page load into that plugin's onboarding wizard instead of
	 * wherever they actually navigated. Deleting an already-cleared flag is a
	 * harmless no-op, so this is safe to call unconditionally after every
	 * activation, regardless of which plugin was just activated.
	 */
	private function clear_known_activation_redirects() {
		delete_transient( 'elementor_activation_redirect' ); // Elementor (free)
		delete_transient( 'eael_do_activation_redirect' );   // Essential Addons for Elementor

		if ( 'redirect' === get_option( 'eael_setup_wizard' ) ) {
			delete_option( 'eael_setup_wizard' );
		}
	}

	private function activate_theme($theme_slug) {
		if (get_option('stylesheet') == $theme_slug) {
			// The theme is already active
			return true;
		}

		if (Helper::current_user_can('switch_themes')) {
			// Activate the theme
			switch_theme($theme_slug);

			// Check if the theme was successfully activated
			if (get_option('stylesheet') == $theme_slug) {
				return true;
			} else {
				return new \WP_Error('theme_activation_failed', __('Failed to activate theme', 'templately'));
			}
		}

		return false;
	}


	/**
	 * Ask any registered provisioning source for an installable pro-plugin archive.
	 *
	 * Returns null — meaning "not available", the only failure this feature has — when the
	 * plugin is not one Templately may obtain, when the module supplying the seam is not
	 * loaded, when the site is not connected, when the current user may not activate what
	 * would be installed, or when no source answers.
	 *
	 * The activation capability is checked HERE as well as after installation: downloading
	 * megabytes for a user who could never activate the result is wasted work and leaves a
	 * plugin installed that nobody asked for.
	 *
	 * @param array $plugin Dependency descriptor.
	 * @return string|null Absolute path to an archive, or null.
	 */
	private function provision_pro_archive( array &$plugin ) {
		// `class_exists()` alone is NOT a "module enabled" test: Modules_Manager registers
		// the autoloader namespace for every DISCOVERED module, inactive ones included, so
		// the class loads even when the module never booted. What a disabled module does
		// not do is add its filter listener — and the dev mock depends on it, so it is
		// skipped too. No listener ⇒ nothing can answer ⇒ the pre-059 refusal stands
		// (FR-025), without spending the checks below.
		if ( ! class_exists( ProProvisioningModule::class ) || ! ProProvisioningModule::is_live() ) {
			Helper::log(
				sprintf( 'pro-provisioning: module not live, cannot obtain "%s"', $plugin['slug'] ?? '(no slug)' ),
				'pro_plugin_provisioning',
				'info'
			);

			return null;
		}

		$entry = ProProvisioningCatalog::find( $plugin );

		if ( null === $entry ) {
			// Not one of the plugins this feature can obtain. Ordinary, and the reason the
			// catalog exists — but indistinguishable from a failure without saying so.
			Helper::log(
				sprintf( 'pro-provisioning: "%s" is not in the obtainable catalog', $plugin['slug'] ?? '(no slug)' ),
				'pro_plugin_provisioning',
				'info'
			);

			return null;
		}

		// The catalog matched — possibly by slug alone. Everything after installation
		// (`is_plugin_inactive()`, `activate_plugin()`) keys on the plugin FILE, so it
		// must be the catalog's, not whatever the descriptor did or did not carry.
		$plugin['plugin_file'] = $entry['plugin_file'];

		if ( ! Helper::current_user_can( 'activate_plugins' ) ) {
			Helper::log(
				sprintf( 'pro-provisioning[%s]: user cannot activate_plugins', $entry['slug'] ),
				'pro_plugin_provisioning',
				'info'
			);

			return null;
		}

		$credential = ProProvisioningModule::credential();

		if ( '' === $credential ) {
			// No stored api_key: the site is not connected to Templately, so there is
			// nothing to present as proof of entitlement.
			Helper::log(
				sprintf( 'pro-provisioning[%s]: no stored credential — site not connected', $entry['slug'] ),
				'pro_plugin_provisioning',
				'info'
			);

			return null;
		}

		$archive = apply_filters( ProProvisioningModule::ARCHIVE_FILTER, null, [
			'plugin_file' => $entry['plugin_file'],
			'slug'        => $entry['slug'],
			'platform'    => $entry['platform'],
			'credential'  => $credential,
		] );

		if ( is_string( $archive ) && '' !== $archive && is_readable( $archive ) ) {
			return $archive;
		}

		// The source has already logged WHY it could not supply one; record that the
		// refusal is what the import will act on, so the two halves join up in the log.
		Helper::log(
			sprintf( 'pro-provisioning[%s]: no archive available — falling back to "install it yourself"', $entry['slug'] ),
			'pro_plugin_provisioning',
			'info'
		);

		return null;
	}

}