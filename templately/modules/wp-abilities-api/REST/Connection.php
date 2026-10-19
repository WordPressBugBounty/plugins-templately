<?php
/**
 * Onboarding support for the mcp-adapter transport — REST endpoints + localized
 * data for the Settings → MCP tab.
 *
 * Scoped to the ADAPTER path specifically, which is why it lives beside the
 * bridges rather than beside the capabilities: everything here exists to get a
 * site from "the mcp-adapter plugin is not installed" to "an agent can reach the
 * adapter's server". It is:
 *  - injects `window.templately.mcp` so the React tab knows the adapter's
 *    endpoint URL, WP-CLI paths, and current adapter/app-password availability;
 *  - mints a WordPress Application Password for the current admin (the Basic-Auth
 *    credential the adapter's HTTP transport needs), returned base64-encoded and
 *    shown once;
 *  - installs + activates the separately-distributed "MCP Adapter" plugin,
 *    from WordPress.org ONLY. There is no GitHub fallback (removed 2026-10-15):
 *    WordPress.org plugin guideline 8 forbids a plugin from installing software
 *    from outside the directory, so when wp.org has no listing the endpoint says
 *    so and the user uploads the zip themselves on the Upload Plugin screen.
 *
 * The BUILT-IN server needs none of this — it has no external plugin to install
 * and issues its own credentials. Its connection management is a separate
 * surface in `modules/mcp-server/REST/Connections.php`.
 *
 * @package Templately\Modules\WpAbilitiesApi\REST
 */

namespace Templately\Modules\WpAbilitiesApi\REST;

use Templately\Modules\McpCore\Support\Permissions;
use Templately\Modules\WpAbilitiesApi\Adapters\McpAdapterBridge;
use Templately\Utils\Base;
use WP_Application_Passwords;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class Connection extends Base {

	/** Main-file path of the MCP Adapter plugin (folder/main-file). */
	const ADAPTER_PLUGIN_FILE = 'mcp-adapter/mcp-adapter.php';

	/** wp.org slug, the ONLY source this endpoint installs from (not published there today). */
	const ADAPTER_WPORG_SLUG = 'mcp-adapter';

	/** Where the user gets the zip themselves while the plugin is not on wp.org. Linked, never fetched. */
	const ADAPTER_RELEASES_PAGE = 'https://github.com/WordPress/mcp-adapter/releases/latest';

	public function __construct() {
		add_filter( 'templately_admin_localized_data', [ $this, 'inject_localized_data' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Expose the data the MCP tab needs on `window.templately.mcp`.
	 *
	 * @param array $data
	 * @return array
	 */
	public function inject_localized_data( $data ) {
		$user = wp_get_current_user();

		$data['mcp'] = [
			'adapter_active'          => McpAdapterBridge::is_available(),
			'adapter_installed'       => $this->is_adapter_installed(),
			'app_passwords_available' => $user->exists() && wp_is_application_passwords_available() && wp_is_application_passwords_available_for_user( $user ),
			'can_install'             => $this->can_install_adapter(),
			'endpoint'                => get_rest_url( null, McpAdapterBridge::NAMESPACE . '/' . McpAdapterBridge::ROUTE ),
			'server_id'               => McpAdapterBridge::SERVER_ID,
			'wp_path'                 => untrailingslashit( ABSPATH ),
			'in_container'            => self::is_containerized(),
			'wp_user'                 => $user->user_login,
			'is_ssl'                  => is_ssl(),
			'docs_url'                => 'https://github.com/WordPress/mcp-adapter#readme',
			'releases_url'            => self::ADAPTER_RELEASES_PAGE,
			'upload_url'              => self_admin_url( 'plugin-install.php?tab=upload' ),
		];

		return $data;
	}

	public function register_routes(): void {
		register_rest_route(
			'templately/v1',
			'/mcp/app-password',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'create_app_password' ],
				'permission_callback' => [ Permissions::class, 'can_use_abilities' ],
				'args'                => [
					'name' => [
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		register_rest_route(
			'templately/v1',
			'/mcp/install-adapter',
			[
				'methods'             => 'POST',
				'callback'            => [ $this, 'install_adapter' ],
				'permission_callback' => [ $this, 'can_install_adapter' ],
			]
		);

		register_rest_route(
			'templately/v1',
			'/mcp/status',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => [ Permissions::class, 'can_use_abilities' ],
			]
		);
	}

	/**
	 * Whether the current user may install/activate the MCP Adapter plugin.
	 * DISALLOW_FILE_MODS (set on locked-down/managed sites) hard-blocks it.
	 *
	 * @return bool
	 */
	public function can_install_adapter(): bool {
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return false;
		}
		return current_user_can( 'install_plugins' ) && current_user_can( 'activate_plugins' );
	}

	/**
	 * Fresh availability snapshot — the tab polls this after an install to
	 * flip from the "install" CTA to the "connect" instructions without a reload.
	 */
	public function get_status(): WP_REST_Response {
		$user = wp_get_current_user();

		return new WP_REST_Response(
			[
				'adapter_active'          => McpAdapterBridge::is_available(),
				'adapter_installed'       => $this->is_adapter_installed(),
				'app_passwords_available' => $user->exists() && wp_is_application_passwords_available() && wp_is_application_passwords_available_for_user( $user ),
				'can_install'             => $this->can_install_adapter(),
			]
		);
	}

	/**
	 * Mint a WordPress Application Password for the current admin and return the
	 * base64 Basic-Auth credential the MCP HTTP transport needs. The plaintext
	 * password is returned ONCE (WordPress never stores it in the clear) — the
	 * client must surface it immediately and not persist it server-side.
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_app_password( WP_REST_Request $request ) {
		$user = wp_get_current_user();

		if ( ! $user->exists() ) {
			return new WP_Error( 'templately_mcp_no_user', __( 'No authenticated user.', 'templately' ), [ 'status' => 401 ] );
		}

		if ( ! wp_is_application_passwords_available() || ! wp_is_application_passwords_available_for_user( $user ) ) {
			return new WP_Error(
				'templately_mcp_app_pw_unavailable',
				__( 'Application Passwords are not available for your account. They require an HTTPS connection.', 'templately' ),
				[ 'status' => 400 ]
			);
		}

		$name = $request->get_param( 'name' );
		if ( empty( $name ) ) {
			$name = __( 'Templately MCP (AI agent)', 'templately' );
		}

		// Reuse the single named slot instead of piling up duplicates: WordPress can't
		// return an already-created password's plaintext, so "reuse" here means revoke
		// any prior password WE created under this exact name before minting the fresh
		// one — the site keeps exactly one active Templately-MCP credential, never a
		// growing list from repeated clicks. (The React tab additionally avoids
		// re-minting at all once it already holds a credential this session.)
		$existing = WP_Application_Passwords::get_user_application_passwords( $user->ID );
		foreach ( is_array( $existing ) ? $existing : [] as $item ) {
			if ( isset( $item['name'], $item['uuid'] ) && $item['name'] === $name ) {
				WP_Application_Passwords::delete_application_password( $user->ID, $item['uuid'] );
			}
		}

		$created = WP_Application_Passwords::create_new_application_password( $user->ID, [ 'name' => $name ] );
		if ( is_wp_error( $created ) ) {
			return $created;
		}

		$password = $created[0];          // plaintext — shown once.
		$item     = is_array( $created[1] ?? null ) ? $created[1] : [];

		return new WP_REST_Response(
			[
				'username' => $user->user_login,
				'password' => $password,
				'base64'   => base64_encode( $user->user_login . ':' . $password ),
				'uuid'     => $item['uuid'] ?? '',
				'name'     => $item['name'] ?? $name,
			]
		);
	}

	/**
	 * Install (if needed) and activate the MCP Adapter plugin.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function install_adapter() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Already installed → just activate.
		if ( $this->is_adapter_installed() ) {
			$activated = $this->activate_adapter();
			if ( is_wp_error( $activated ) ) {
				return $activated;
			}
			return new WP_REST_Response( [ 'installed' => true, 'active' => true, 'source' => 'existing' ] );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		if ( ! class_exists( '\WP_Upgrader' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		}
		if ( ! class_exists( '\WP_Ajax_Upgrader_Skin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-ajax-upgrader-skin.php';
		}

		$resolved = $this->resolve_adapter_download_url();
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $resolved['url'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( is_wp_error( $skin->result ) ) {
			return $skin->result;
		}
		if ( $skin->get_errors()->has_errors() ) {
			return $skin->get_errors();
		}
		if ( true !== $result ) {
			return new WP_Error( 'templately_mcp_install_failed', __( 'MCP Adapter installation failed.', 'templately' ), [ 'status' => 500 ] );
		}

		$activated = $this->activate_adapter();
		if ( is_wp_error( $activated ) ) {
			return $activated;
		}

		return new WP_REST_Response( [ 'installed' => true, 'active' => true, 'source' => $resolved['source'] ] );
	}

	/**
	 * Resolve a downloadable MCP Adapter zip from WordPress.org, and nowhere else.
	 *
	 * There used to be a GitHub latest-release fallback here. It was removed on
	 * 2026-10-15: WordPress.org plugin guideline 8 forbids a plugin from installing
	 * software from outside the directory, whatever the host. While the adapter is
	 * not listed on wp.org the endpoint answers `templately_mcp_not_on_wporg` and the
	 * tab tells the user to download the release zip and upload it themselves. Do
	 * not reintroduce a download from GitHub or any other host.
	 *
	 * @return array{url:string,source:string}|WP_Error
	 */
	private function resolve_adapter_download_url() {
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$info = plugins_api(
			'plugin_information',
			[ 'slug' => self::ADAPTER_WPORG_SLUG, 'fields' => [ 'download_link' => true ] ]
		);
		if ( ! is_wp_error( $info ) && ! empty( $info->download_link ) ) {
			return [ 'url' => $info->download_link, 'source' => 'wordpress.org' ];
		}

		return new WP_Error(
			'templately_mcp_not_on_wporg',
			__( 'The MCP Adapter plugin is not available on WordPress.org yet. Download its release zip and upload it under Plugins → Add New → Upload Plugin, then activate it and reload this page.', 'templately' ),
			[
				'status'       => 404,
				'releases_url' => self::ADAPTER_RELEASES_PAGE,
				'upload_url'   => self_admin_url( 'plugin-install.php?tab=upload' ),
			]
		);
	}

	/**
	 * Best-effort detection of a container (Docker/Podman) runtime. When true, the
	 * WP-CLI STDIO command's `--path` (ABSPATH) is the path *inside* the container —
	 * not a path that exists on the agent's host — so the UI warns and steers the
	 * user to the HTTP method (or to run `wp` inside the container).
	 *
	 * @return bool
	 */
	public static function is_containerized(): bool {
		if ( file_exists( '/.dockerenv' ) || file_exists( '/run/.containerenv' ) ) {
			return true;
		}
		// cgroup fingerprint (Linux hosts) — cheap and only read once per page load.
		$cgroup = '/proc/1/cgroup';
		if ( is_readable( $cgroup ) ) {
			$contents = @file_get_contents( $cgroup );
			if ( is_string( $contents ) && preg_match( '/docker|kubepods|containerd|podman/i', $contents ) ) {
				return true;
			}
		}
		return false;
	}

	private function is_adapter_installed(): bool {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		return isset( $plugins[ self::ADAPTER_PLUGIN_FILE ] );
	}

	/**
	 * @return true|WP_Error
	 */
	private function activate_adapter() {
		if ( ! function_exists( 'activate_plugin' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active( self::ADAPTER_PLUGIN_FILE ) ) {
			return true;
		}
		$result = activate_plugin( self::ADAPTER_PLUGIN_FILE );
		return is_wp_error( $result ) ? $result : true;
	}
}
