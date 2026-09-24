<?php
/**
 * `templately/inspect-full-site-pack` — pre-import summary for a full-site pack
 * (spec 042 US3, FR-009/008). Pure read: no mutation, no loopback.
 *
 * Calls the same pack-info API the browser uses
 * (`Helper::make_api_get_request("v2/import/info/pack/{id}")`, as in
 * `Ajax\PackInfoController::import_info`) directly in-process, then computes
 * per-plugin installed/active readiness against the site.
 *
 * @package Templately\Modules\FullSiteImport\Abilities
 */

namespace Templately\Modules\FullSiteImport\Abilities;

use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\McpCore\Support\Permissions;
use Templately\Utils\Helper;
use WP_Error;

class InspectFullSitePackAbility {

	const ID = 'templately/inspect-full-site-pack';

	public static function descriptor(): array {
		return [
			'id'                  => self::ID,
			'label'               => __( 'Inspect Templately Full Site Pack', 'templately' ),
			'description'         => __( 'Preview a full-site pack before importing: its required plugins with installed/active status and a summary of the content it will import. Performs no changes.', 'templately' ),
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'       => [ 'type' => 'integer', 'description' => __( 'Full-site pack id.', 'templately' ) ],
					'platform' => [ 'type' => 'string', 'enum' => [ 'elementor', 'gutenberg' ], 'default' => 'elementor' ],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'id'                 => [ 'type' => 'integer' ],
					'name'               => [ 'type' => 'string' ],
					'platform'           => [ 'type' => 'string' ],
					'required_plugins'   => [ 'type' => 'array' ],
					'unmet_dependencies' => [ 'type' => 'array' ],
					'content_summary'    => [ 'type' => 'object' ],
				],
			],
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => [ Permissions::class, 'can_use_abilities' ],
			'access_level'        => ToolDescriptor::ACCESS_READ,
			'annotations'         => [ 'readonly' => true, 'destructive' => false, 'idempotent' => true ],
		];
	}

	/**
	 * @param array $input
	 * @return array|WP_Error
	 */
	public static function execute( array $input ) {
		$id = (int) ( $input['id'] ?? 0 );
		if ( empty( $id ) ) {
			return new WP_Error( 'pack_not_found', __( 'A full-site pack id is required.', 'templately' ) );
		}

		$response = Helper::make_api_get_request( "v2/import/info/pack/{$id}", [], [], 30 );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'not_connected', $response->get_error_message() );
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'pack_not_found', __( 'The pack could not be found or is not accessible with this account.', 'templately' ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || isset( $data['error'] ) ) {
			$message = is_array( $data ) && isset( $data['error'] ) ? $data['error'] : __( 'The pack info could not be read.', 'templately' );
			return new WP_Error( 'pack_not_found', $message );
		}

		$pack     = $data['data'] ?? [];
		$manifest = self::decode( $pack['manifest'] ?? null );

		$required_plugins = self::resolve_plugin_readiness( $manifest );
		$unmet            = array_values( array_filter( $required_plugins, static function ( $p ) {
			return empty( $p['installed'] ) || empty( $p['active'] );
		} ) );

		return [
			'id'                 => $id,
			'name'               => (string) ( $pack['name'] ?? '' ),
			'platform'           => (string) ( $manifest['platform'] ?? ( $input['platform'] ?? 'elementor' ) ),
			'required_plugins'   => $required_plugins,
			'unmet_dependencies' => $unmet,
			'content_summary'    => self::content_summary( $manifest ),
		];
	}

	/**
	 * Normalize the manifest's required-plugin list.
	 *
	 * **The manifest key is `dependencies`, NOT `plugins`.** Reading `plugins`
	 * made this return an empty list for EVERY pack, so `unmet_dependencies` was
	 * always `[]` and the pre-import readiness report was silently useless — it
	 * reported a pack as ready even with its builder deactivated. Verified live
	 * against pack 481, whose manifest carries:
	 *
	 *   "dependencies": ["elementor","essential-addons-for-elementor-lite",
	 *                    "essential-addons-elementor","fluent-forms"]
	 *
	 * i.e. a flat array of SLUGS, not objects. `plugins` is still accepted first
	 * because packs that do ship a richer object list (name/plugin_file/is_pro)
	 * carry more detail than a bare slug.
	 *
	 * @param array $manifest
	 * @return array List of plugin descriptors (associative arrays).
	 */
	private static function manifest_plugins( array $manifest ): array {
		$plugins = $manifest['plugins'] ?? [];

		if ( is_array( $plugins ) && ! empty( $plugins ) ) {
			return $plugins;
		}

		$dependencies = $manifest['dependencies'] ?? [];

		if ( ! is_array( $dependencies ) || empty( $dependencies ) ) {
			return [];
		}

		$normalized = [];

		foreach ( $dependencies as $dependency ) {
			// Already an object/array form — pass through.
			if ( is_array( $dependency ) || is_object( $dependency ) ) {
				$normalized[] = (array) $dependency;
				continue;
			}

			$slug = (string) $dependency;

			if ( '' === $slug ) {
				continue;
			}

			$normalized[] = [
				'name'                 => $slug,
				'slug'                 => $slug,
				'plugin_original_slug' => $slug,
			];
		}

		return $normalized;
	}

	/**
	 * Build the required-plugin list with installed/active flags for this site.
	 *
	 * @param array $manifest
	 * @return array
	 */
	private static function resolve_plugin_readiness( array $manifest ): array {
		$plugins = self::manifest_plugins( $manifest );
		if ( empty( $plugins ) ) {
			return [];
		}

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$installed = array_keys( get_plugins() );

		$result = [];
		foreach ( $plugins as $plugin ) {
			$plugin      = (array) $plugin;
			$plugin_file = (string) ( $plugin['plugin_file'] ?? $plugin['plugin_original_slug'] ?? $plugin['slug'] ?? '' );

			$is_installed = false;
			$is_active    = false;
			if ( '' !== $plugin_file ) {
				// The plugin DIRECTORY is the reliable identity. A bare slug
				// cannot be turned into a file name by guessing "{slug}/{slug}.php":
				// essential-addons-for-elementor-lite's real entry file is
				// essential_adons_elementor.php, so that guess misses it and the
				// plugin is reported not-installed while sitting right there.
				$wanted_dir = ( false !== strpos( $plugin_file, '/' ) )
					? dirname( $plugin_file )
					: $plugin_file;

				foreach ( $installed as $file ) {
					if ( $file === $plugin_file || dirname( $file ) === $wanted_dir ) {
						$is_installed = true;
						$is_active    = is_plugin_active( $file );
						break;
					}
				}
			}

			$result[] = [
				'name'        => (string) ( $plugin['name'] ?? $plugin_file ),
				'slug'        => (string) ( $plugin['slug'] ?? $plugin['plugin_original_slug'] ?? '' ),
				'plugin_file' => $plugin_file,
				'is_pro'      => ! empty( $plugin['is_pro'] ),
				'installed'   => $is_installed,
				'active'      => $is_active,
			];
		}

		return $result;
	}

	/**
	 * Coarse content-scope summary from the manifest (counts of array buckets).
	 *
	 * @param array $manifest
	 * @return array
	 */
	private static function content_summary( array $manifest ): array {
		$summary = [];
		foreach ( [ 'plugins', 'templates', 'content', 'pages', 'posts', 'attachments' ] as $bucket ) {
			if ( isset( $manifest[ $bucket ] ) && is_array( $manifest[ $bucket ] ) ) {
				$summary[ $bucket ] = count( $manifest[ $bucket ] );
			}
		}
		if ( isset( $manifest['name'] ) ) {
			$summary['pack_name'] = (string) $manifest['name'];
		}
		return $summary;
	}

	/**
	 * @param mixed $value JSON string or already-decoded array.
	 * @return array
	 */
	private static function decode( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded ) ? $decoded : [];
		}
		return [];
	}
}
