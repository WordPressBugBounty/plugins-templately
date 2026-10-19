<?php

namespace Templately\Modules\SingleImport\REST;

use stdClass;
use Templately\API\API;
use Templately\Utils\Helper;
use Templately\Utils\Database;
use Templately\Utils\Installer;
use WP_REST_Request;
use Templately\Utils\Caching;

use function current_user_can;


class Dependencies extends API {

	private $endpoint = 'dependencies';

	public $query = 'dependencies{ id, name, icon, plugin_file, plugin_original_slug, is_pro, link }';

	/**
	 * How old WordPress's cached update check may be before this endpoint refreshes it.
	 * Long enough that the dependency step almost always answers from cache.
	 */
	const UPDATE_CHECK_MAX_AGE = 12 * HOUR_IN_SECONDS;

	/** Guards against concurrent wizards each firing their own wordpress.org check. */
	const UPDATE_CHECK_LOCK = 'plugin_update_check_lock';

	/**
	 * Essential Addons Pro and Essential Blocks Pro. When one is on disk but inactive,
	 * the dialog asks the user to activate it themselves instead of activating it,
	 * the same as the full-site wizard. Mirrors `react-src/utils/platformProPlugin.ts`.
	 */
	const PLATFORM_PRO_FILES = [
		'essential-addons-elementor/essential_adons_elementor.php',
		'essential-blocks-pro/essential-blocks-pro.php',
	];

	public function __construct() {
		parent::__construct();

		if(!empty($_GET['disable_redirect'])) {
			add_filter('wp_redirect', '__return_false', 999);
		}
	}

	public function permission_check( WP_REST_Request $request ) {
		$this->request = $request;

		// Installing reaches beyond template content, so it does not ride the
		// `delete_posts` base gate the read routes are happy with. Installer::install()
		// checks `install_plugins` / `activate_plugins` per plugin as well; this is the
		// front gate, so the route cannot be probed at all without the capability.
		if ( $request->get_route() === '/templately/v1/dependencies/install' && ! Helper::current_user_can( 'install_plugins' ) ) {
			return $this->error(
				'invalid_permission',
				__( 'Sorry, you do not have permission to install a plugin.', 'templately' ),
				'dependencies/install',
				rest_authorization_required_code()
			);
		}

		return true;
	}

	public function register_routes() {
		$this->get( $this->endpoint, [ $this, 'get_dependencies' ] );
		$this->post( $this->endpoint  . '/plugins', [ $this, 'get_plugins' ] );
		$this->post( $this->endpoint  . '/themes', [ $this, 'get_themes' ] );
		$this->post( $this->endpoint . '/check', [$this, 'check_dependencies'] );
		$this->post( $this->endpoint . '/install', [$this, 'install_dependencies'] );
		$this->post( $this->endpoint . '/update', [$this, 'update_dependencies'] );
	}

	public function get_dependencies() {
		$dependencies = Database::get_transient( $this->endpoint );

		if( $dependencies ) {
			return $this->success( $dependencies );
		}

		$response = $this->http()->query(
			'dependencies',
			'id, name, icon, is_pro, platforms{ id, name, file_type, icon }'
		)->post();

		if( ! is_wp_error( $response ) ) {
			$_dependencies = [];
			if( ! empty( $response ) ) {
				$_dependencies[ 'unknown' ] = [];
				foreach( $response as $dependency ) {
					if( ! empty( $dependency['platforms'] ) ) {
						foreach( $dependency['platforms'] as $platform ) {
							if( ! isset( $_dependencies[ $platform['name'] ] ) ) {
								$_dependencies[ $platform['name'] ] = [];
							}
							$_dependencies[ $platform['name'] ][] = $dependency;
						}
					} else {
						$_dependencies[ 'unknown' ][] = $dependency;
					}
				}
			}

			Database::set_transient( $this->endpoint, $_dependencies );
			return $_dependencies;
		}

		return $response;
	}

	public function check_dependencies(){
		$dependencies = $this->get_param( 'dependencies', '', '' );
		$platform = $this->get_param( 'platform', 'elementor' );

		$_inactive_plugins = [];
		$_plugins = Helper::get_plugins();

		if( $platform === 'elementor' ) {
			$elementor_plugin              = new stdClass();
			$elementor_plugin->name        = __( 'Elementor', 'templately' );
			$elementor_plugin->plugin_file = 'elementor/elementor.php';
			$elementor_plugin->slug        = 'elementor';
			$elementor_plugin->is_pro      = false;
			$elementor_plugin->is_active   = Helper::is_plugin_active( 'elementor/elementor.php' );
			$elementor_plugin->is_installed = isset( $_plugins['elementor/elementor.php'] );

			$_inactive_plugins[] = $elementor_plugin;
		}

		if ( ! empty( $dependencies ) && is_array( $dependencies ) ) {
			foreach ( $dependencies as $dependency ) {
				if( ! is_array( $dependency ) || ! isset( $dependency['plugin_file'] ) ) {
					continue;
				}

				$dependency  = ( object ) $dependency;
				if ( is_null( $dependency->plugin_file ) ) {
					continue;
				}

				$dependency->is_active = Helper::is_plugin_active( $dependency->plugin_file );
				// On disk at all: the dialog's button says "Proceed" rather than "Install"
				// when every row it will act on only needs activating.
				$dependency->is_installed = isset( $_plugins[ $dependency->plugin_file ] );

				if( isset( $dependency->plugin_original_slug ) ) {
					$dependency->slug = $dependency->plugin_original_slug;
					unset( $dependency->plugin_original_slug );
				}

				if ( $dependency->is_pro ) {
					if ( isset( $_plugins[ $dependency->plugin_file ] ) ) {
						if ( ! $dependency->is_active && in_array( $dependency->plugin_file, self::PLATFORM_PRO_FILES, true ) ) {
							// Keep `is_pro` so the row stays a Pro row (crown, never activated
							// by Templately) and the notice says "installed but not active".
							$dependency->installed_inactive = true;
						} else {
							unset( $dependency->is_pro );
							$dependency->message = __( 'You have the plugin installed.', 'templately' );
						}
					}
				}
				$_inactive_plugins[] = $dependency;
			}
		}

		// Same version-drift reasoning as the full-site path: a template's markup comes from the
		// plugin version on the AUTHORING site, so an out-of-date dependency here produces blocks
		// the editor reports as invalid. This endpoint feeds single-import's dependency UI, which
		// reads `/check` and not `/plugins` — decorating only the latter left this surface blind.
		$pending    = $this->get_pending_plugin_updates();
		$decoratable = array_map( function ( $plugin ) {
			return is_object( $plugin ) ? (array) $plugin : $plugin;
		}, $_inactive_plugins );

		$decorated = $this->decorate_with_update_state( $decoratable, $pending );

		return [
			'dependencies' => array_map( function ( $plugin ) {
				return (object) $plugin;
			}, $decorated ),
			'updates'      => [
				'available'  => array_values( array_filter( $decorated, function ( $plugin ) {
					return ! empty( $plugin['update_available'] );
				} ) ),
				'checked_at' => $this->get_update_check_timestamp(),
			],
		];
	}

	public function install_dependencies(){
		// Installing/activating a dependency runs foreign plugin code in THIS
		// request — the classic fatal source. Capture it instead of replying
		// with an unparseable blank 500.
		\Templately\Utils\Response\FatalGuard::arm( 'single-import/install-dependencies' );

		$requirements = $this->get_param( 'requirement', [], '' );
		if( empty( $requirements ) ) {
			return $this->error(
				'invalid_requirements',
				__('You have supplied an invalid requirements. Please reload the page and try again.'),
				'/install',
				400
			);
		}
		$installed = Installer::get_instance()->install( $requirements );
		if(empty($installed['success'])){
			// `code` and `message` are NOT set on every failing branch of Installer::install()
			// — activation failing without a WP_Error leaves both unset, and reading them
			// raised "Undefined array key" warnings on a path whose whole job is to report a
			// failure cleanly. Defaults, so a failure never becomes a second failure.
			return $this->error(
				! empty( $installed['code'] ) ? $installed['code'] : 'install_failed',
				! empty( $installed['message'] ) ? $installed['message'] : __( 'The plugin could not be installed. Please try again, or install it from the Plugins screen.', 'templately' ),
				'dependencies/install',
				403
			);
		}
		return $installed;
	}

	public function get_plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		// Get parameters from request
		$dependencies = $this->get_param( 'dependencies', [], null );
		$platform = $this->get_param( 'platform', 'elementor' );
		$categories = $this->get_param( 'categories', [], null );

		$pending_updates = $this->get_pending_plugin_updates();

		// Get all plugins for checking status
		$all_plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugin_data = array(
				'plugin'       => substr( $file, 0, - 4 ),
				'plugin_file'  => $file,
				'status'       => $this->get_plugin_status( $file ),
				'name'         => $data['Name'],
				'plugin_uri'   => $data['PluginURI'],
				'author'       => $data['Author'],
				'author_uri'   => $data['AuthorURI'],
				'description'  => array(
					'raw'      => $data['Description'],
					'rendered' => $data['Description'],
				),
				'version'      => $data['Version'],
				'network_only' => $data['Network'],
				'requires_wp'  => $data['RequiresWP'],
				'requires_php' => $data['RequiresPHP'],
				'textdomain'   => $data['TextDomain'],
			);
			$all_plugins[] = $plugin_data;
		}

		// Process dependencies and return only necessary data
		$new_dependency_list = [];
		$new_required_plugins = [];

		// Platform-specific plugins
		if ( $platform === 'elementor' ) {
			$elementor_plugin = $this->find_plugin_by_name( $all_plugins, 'elementor/elementor' );
			$e_plugin = array(
				'plugin_file' => 'elementor/elementor.php',
				'plugin_original_slug' => 'elementor',
				'name' => $elementor_plugin ? $elementor_plugin['name'] : 'Elementor',
				'installed' => $elementor_plugin ? ( $elementor_plugin['status'] === 'active' ) : false,
				'mustHave' => true,
			);
			$new_dependency_list[] = $e_plugin;
			if ( ! $elementor_plugin || $elementor_plugin['status'] !== 'active' ) {
				$new_required_plugins[] = $e_plugin;
			}
		} elseif ( $platform === 'gutenberg' ) {
			// Check if WordPress supports Gutenberg natively or if Gutenberg plugin is needed
			$gutenberg_plugin = $this->find_plugin_by_name( $all_plugins, 'gutenberg/gutenberg' );

			// Note: templately.is_wp_support_gutenberg is a frontend variable,
			// we'll assume Gutenberg plugin is needed if it exists and is inactive
			if ( $gutenberg_plugin && $gutenberg_plugin['status'] === 'inactive' ) {
				$g_plugin = array(
					'plugin_file' => 'gutenberg/gutenberg.php',
					'plugin_original_slug' => 'gutenberg',
					'name' => $gutenberg_plugin['name'],
					'mustHave' => true,
				);
				$new_dependency_list[] = $g_plugin;
				$new_required_plugins[] = $g_plugin;
			}
		}

		// Process template dependencies
		if ( ! empty( $dependencies ) && is_array( $dependencies ) ) {
			foreach ( $dependencies as $plugin_file => $plugin_obj ) {
				if ( ! is_array( $plugin_obj ) ) {
					continue;
				}

				$plugin_name = str_replace( '.php', '', $plugin_file );
				$plugin = $this->find_plugin_by_name( $all_plugins, $plugin_name );

				if ( $plugin && $plugin['status'] === 'active' ) {
					$plugin_obj['installed'] = true;
					$new_dependency_list[] = $plugin_obj;
				} else {
					// On disk but switched off: the wizard asks the user to activate a Pro
					// plugin like this one instead of pointing them at a download.
					if ( $plugin ) {
						$plugin_obj['installed_inactive'] = true;
					}
					$new_dependency_list[] = $plugin_obj;
					$new_required_plugins[] = $plugin_obj;
				}
			}
		}

		// NotificationX used to be INJECTED here for any item in one of 15 categories
		// (essentially the whole catalog, `miscellaneous` and `multipurpose` included), and
		// pushed into the REQUIRED list — so imports installed it whether or not the template
		// contained a single NotificationX element. A dependency is something the template
		// needs to render; this was a recommendation wearing a requirement's clothes.
		// Removed 2026-09-21. Dependencies now come from the pack, which is the only thing
		// that knows what the template actually uses.
		//
		// The caching suggestion (3.7.5, merged from `dev`) arrived wrapped around that
		// injection as an either/or: offer the caching plugin, OR fall through to the category plugins.
		// The either/or is kept for what is left of that branch (EmbedPress); NotificationX
		// stays removed rather than returning through the merge.
		if ( $this->should_offer_caching() ) {
			$caching = Caching::dependency_entry();
			Caching::mark_offered();

			$new_dependency_list[]  = $caching;
			$new_required_plugins[] = $caching;
		} else {
			// EmbedPress for blog-magazine category
			$ep_is_any_category_included = false;
			if ( ! empty( $categories ) && is_array( $categories ) ) {
				foreach ( $categories as $category ) {
					if ( isset( $category['slug'] ) && $category['slug'] === 'blog-magazine' ) {
						$ep_is_any_category_included = true;
						break;
					}
				}
			}

			if ( $ep_is_any_category_included ) {
				$ep_plugin = $this->find_plugin_by_name( $all_plugins, 'embedpress/embedpress' );
				$embed_press = array(
					'name' => 'EmbedPress',
					'icon' => 'https://ps.w.org/embedpress/assets/icon-256x256.gif?rev=2783824',
					'plugin_file' => 'embedpress/embedpress.php',
					'plugin_original_slug' => 'embedpress',
					'is_pro' => false,
					'installed' => $ep_plugin ? ( $ep_plugin['status'] === 'active' ) : false,
					'link' => 'https://wordpress.org/plugins/embedpress/',
				);
				$new_dependency_list[] = $embed_press;
				$new_required_plugins[] = $embed_press;
			}
		}

		$new_dependency_list  = $this->decorate_with_update_state( $new_dependency_list, $pending_updates );
		$new_required_plugins = $this->decorate_with_update_state( $new_required_plugins, $pending_updates );

		// Only dependencies of THIS import are offered for update — the site's other outdated
		// plugins are not this step's business and listing them turns a targeted, explainable
		// recommendation into a nag screen.
		$updatable = array_values( array_filter( $new_dependency_list, function ( $plugin ) {
			return ! empty( $plugin['update_available'] );
		} ) );

		return new \WP_REST_Response( array(
			'dependencyList' => $new_dependency_list,
			'requiredPlugins' => $new_required_plugins,
			'updates' => array(
				'available'  => $updatable,
				'checked_at' => $this->get_update_check_timestamp(),
			),
		) );
	}

	protected function should_offer_caching(): bool {
		return Caching::should_offer();
	}

	/**
	 * Plugin updates WordPress already knows about, keyed by plugin file.
	 *
	 * Reads the `update_plugins` transient rather than forcing a wordpress.org round trip:
	 * this runs inside the import wizard's dependency step, and a cold remote check there is
	 * several seconds of the user staring at a spinner. A refresh is triggered only when the
	 * cached answer is older than {@see self::UPDATE_CHECK_MAX_AGE}, and even then it is
	 * lock-guarded so a re-opened wizard cannot stack requests.
	 *
	 * @return array<string,object> plugin_file => update object (`new_version`, `package`, …)
	 */
	private function get_pending_plugin_updates(): array {
		require_once ABSPATH . 'wp-admin/includes/update.php';

		$transient = get_site_transient( 'update_plugins' );
		$is_stale  = empty( $transient ) || empty( $transient->last_checked )
			|| ( time() - (int) $transient->last_checked ) > self::UPDATE_CHECK_MAX_AGE;

		if ( $is_stale && ! Database::get_transient( self::UPDATE_CHECK_LOCK ) ) {
			// Short lock, not the full max-age: if the refresh fails we want to retry sooner
			// than a whole check window, but never from two wizards at once.
			Database::set_transient( self::UPDATE_CHECK_LOCK, time(), 5 * MINUTE_IN_SECONDS );
			wp_update_plugins();
			$transient = get_site_transient( 'update_plugins' );
		}

		return ( ! empty( $transient->response ) && is_array( $transient->response ) ) ? $transient->response : [];
	}

	/**
	 * When WordPress last checked for plugin updates, so the UI can say how fresh this is.
	 */
	private function get_update_check_timestamp(): int {
		$transient = get_site_transient( 'update_plugins' );

		return ! empty( $transient->last_checked ) ? (int) $transient->last_checked : 0;
	}

	/**
	 * Annotate dependency rows with what is installed and what is available.
	 *
	 * A pack's markup is generated by the block plugin version on the AUTHORING site. When the
	 * importing site is behind, blocks whose `save()` output has changed since — or that use
	 * attributes this version does not declare — fail Gutenberg's validation and open with
	 * "Attempt recovery". Surfacing the gap here is the only point in the flow where it is
	 * still cheap to fix, so the data has to reach the step even when the user declines.
	 *
	 * Pro plugins are marked `auto_updatable: false`: they update through their own licensed
	 * channel, and offering a one-click update that cannot work is worse than a link.
	 *
	 * @param array                 $plugins Dependency rows.
	 * @param array<string,object>  $pending plugin_file => update object.
	 * @return array
	 */
	private function decorate_with_update_state( array $plugins, array $pending ): array {
		$installed = function_exists( 'get_plugins' ) ? get_plugins() : [];

		foreach ( $plugins as $index => $plugin ) {
			$file = $plugin['plugin_file'] ?? null;

			$plugins[ $index ]['version']          = null;
			$plugins[ $index ]['new_version']      = null;
			$plugins[ $index ]['update_available'] = false;
			$plugins[ $index ]['auto_updatable']   = false;

			if ( empty( $file ) || ! isset( $installed[ $file ] ) ) {
				continue;
			}

			$plugins[ $index ]['version'] = $installed[ $file ]['Version'] ?? null;

			if ( empty( $pending[ $file ]->new_version ) ) {
				continue;
			}

			// The transient is a CACHE, and it is not always invalidated when a plugin is
			// updated — an entry can outlive the update it described, naming a version the
			// site already runs. Trusting it blindly is what put already-updated plugins in
			// "Updates Recommended". Only a version we do not yet have is an update.
			$current = $plugins[ $index ]['version'];
			if ( ! empty( $current ) && version_compare( $current, $pending[ $file ]->new_version, '>=' ) ) {
				continue;
			}

			$plugins[ $index ]['new_version']      = $pending[ $file ]->new_version;
			$plugins[ $index ]['update_available'] = true;
			$plugins[ $index ]['auto_updatable']   = empty( $plugin['is_pro'] ) && ! empty( $pending[ $file ]->package );
		}

		return $plugins;
	}

	/**
	 * Update installed dependency plugins.
	 *
	 * Scoped deliberately: only plugins this site actually has an update for may be named, so
	 * the route cannot be used to drive arbitrary upgrades.
	 */
	public function update_dependencies() {
		// Upgrading runs foreign plugin code in THIS request — capture a fatal as an envelope
		// instead of an unparseable blank 500, same as install_dependencies().
		\Templately\Utils\Response\FatalGuard::arm( 'single-import/update-dependencies' );

		$plugin_files = $this->get_param( 'plugin_files', [], 'sanitize_text_field' );

		if ( empty( $plugin_files ) || ! is_array( $plugin_files ) ) {
			return $this->error(
				'invalid_requirements',
				__( 'No plugins were selected for update.', 'templately' ),
				'dependencies/update',
				400
			);
		}

		$pending  = $this->get_pending_plugin_updates();
		$eligible = array_values( array_intersect( $plugin_files, array_keys( $pending ) ) );

		// A selected plugin with no pending update is ALREADY UP TO DATE, which is the
		// outcome this step wants — not a failure. It happens routinely: the user updated
		// from the Plugins screen in another tab, an auto-update landed, or a previous
		// attempt here succeeded and the caller is working from the list it fetched before.
		// Reporting it as an error painted "None of the selected plugins have an update
		// available" under every row of a fully up-to-date site.
		$already = array_values( array_diff( $plugin_files, $eligible ) );
		$settled = array_map( function ( $plugin_file ) {
			return [
				'plugin_file' => $plugin_file,
				'updated'     => true,
				'code'        => 'already_updated',
			];
		}, $already );

		if ( empty( $eligible ) ) {
			return [
				'success' => true,
				'results' => $settled,
			];
		}

		$updated = Installer::get_instance()->update( $eligible );

		if ( empty( $updated['success'] ) && empty( $updated['results'] ) ) {
			return $this->error(
				$updated['code'] ?? 'update_failed',
				$updated['message'] ?? __( 'The plugins could not be updated.', 'templately' ),
				'dependencies/update',
				403
			);
		}

		// `success: false` here means SOME plugin failed; the per-plugin results say which, and
		// the caller decides whether that is worth stopping for. The updated code is not loaded
		// in this request, so callers must re-check dependencies in a fresh one.
		$updated['results'] = array_merge( $updated['results'] ?? [], $settled );

		return $updated;
	}

	/**
	 * Helper method to find a plugin by its name/slug
	 *
	 * @param array $plugins Array of all plugins
	 * @param string $plugin_name Plugin name to search for
	 * @return array|null Plugin data or null if not found
	 */
	private function find_plugin_by_name( $plugins, $plugin_name ) {
		foreach ( $plugins as $plugin ) {
			if ( $plugin['plugin'] === $plugin_name ) {
				return $plugin;
			}
		}
		return null;
	}

	public function get_themes() {
		// Get platform parameter from request
		$platform = $this->get_param( 'platform', 'elementor' );

		$active_themes = wp_get_themes();
		$current_theme = wp_get_theme();

		$recommended_theme = array();

		if ( $platform === 'elementor' ) {
			// Look for Hello Elementor theme
			$target_stylesheet = 'hello-elementor';
			$elementor_theme = null;

			foreach ( $active_themes as $theme ) {
				if ( $theme->get_stylesheet() === $target_stylesheet ) {
					$elementor_theme = $theme;
					break;
				}
			}

			$recommended_theme = array(
				'name' => $elementor_theme ? $elementor_theme->display( 'Name' ) : 'Hello Elementor',
				'status' => $elementor_theme ? ( $elementor_theme->get_stylesheet() === $current_theme->get_stylesheet() ? 'active' : 'inactive' ) : 'inactive',
				'template' => $elementor_theme ? $elementor_theme->get_template() : 'hello-elementor',
				'stylesheet' => $elementor_theme ? $elementor_theme->get_stylesheet() : 'hello-elementor',
			);

		} elseif ( $platform === 'gutenberg' ) {
			// Look for Twenty Twenty-Four theme
			$target_stylesheet = 'twentytwentyfour';
			$gutenberg_theme = null;

			foreach ( $active_themes as $theme ) {
				if ( $theme->get_stylesheet() === $target_stylesheet ) {
					$gutenberg_theme = $theme;
					break;
				}
			}

			$recommended_theme = array(
				'name' => $gutenberg_theme ? $gutenberg_theme->display( 'Name' ) : 'Twenty Twenty-Four',
				'status' => $gutenberg_theme ? ( $gutenberg_theme->get_stylesheet() === $current_theme->get_stylesheet() ? 'active' : 'inactive' ) : 'inactive',
				'template' => $gutenberg_theme ? $gutenberg_theme->get_template() : 'twentytwentyfour',
				'stylesheet' => $gutenberg_theme ? $gutenberg_theme->get_stylesheet() : 'twentytwentyfour',
			);
		}

		return new \WP_REST_Response( $recommended_theme );
	}

	/**
	 * Get's the activation status for a plugin.
	 *
	 * @since 5.5.0
	 *
	 * @param string $plugin The plugin file to check.
	 * @return string Either 'active' or 'inactive'.
	 */
	protected function get_plugin_status( $plugin ) {
		if ( is_plugin_active_for_network( $plugin ) ) {
			return 'active';
		}

		if ( is_plugin_active( $plugin ) ) {
			return 'active';
		}

		return 'inactive';
	}

}
