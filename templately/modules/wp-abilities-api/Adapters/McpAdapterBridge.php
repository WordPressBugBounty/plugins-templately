<?php
/**
 * Publishes the registry's capabilities through the `wordpress/mcp-adapter`
 * plugin, when that plugin is installed (spec 044, FR-010/FR-012).
 *
 * Relocated from `MCP::register_server()`. This is now the ONLY file in the
 * module that references the adapter's classes — the top-level imports used to
 * sit in MCP.php, which forced the whole module to care about a soft dependency
 * it mostly does not use.
 *
 * The adapter's route (`/wp-json/templately/mcp`) and the native server's
 * (`/wp-json/templately/v1/mcp`) are distinct paths and both may serve
 * concurrently — neither registration displaces the other (FR-007).
 *
 * @package Templately\Modules\WpAbilitiesApi\Adapters
 */

namespace Templately\Modules\WpAbilitiesApi\Adapters;

use Templately\Modules\McpCore\Registry\ToolRegistry;
use WP\MCP\Core\McpAdapter;
use WP\MCP\Transport\HttpTransport;

class McpAdapterBridge {

	const SERVER_ID = 'templately';
	const NAMESPACE = 'templately';
	const ROUTE     = 'mcp';

	/**
	 * Whether the adapter path can serve at all: WordPress core's Abilities API
	 * (core 6.9+) AND the separately-installed mcp-adapter plugin. Both are soft
	 * dependencies, never bundled via Composer.
	 *
	 * This is ONLY a statement about the adapter transport. It used to be
	 * `MCP::is_available()` and gated the whole feature, which meant every
	 * capability was unreachable on the large majority of sites running this
	 * plugin — Templately supports WordPress 5.0+. Since 046 the built-in server
	 * serves the same capabilities regardless of what this returns; the
	 * Settings → MCP tab reads it purely to decide whether to offer the
	 * "Install & Activate" CTA.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return AbilitiesBridge::is_available() && self::has_adapter();
	}

	/**
	 * Whether the separately-installed mcp-adapter plugin is present, and nothing
	 * else. A soft dependency, never bundled via Composer.
	 *
	 * Deliberately separate from {@see self::is_available()}: the adapter path
	 * needs the Abilities API too (its server is registered by ability id), but
	 * "is the plugin installed" and "can the adapter path serve" are different
	 * questions, and collapsing them is what produced the single all-or-nothing
	 * gate 046 removed.
	 *
	 * @return bool
	 */
	public static function has_adapter(): bool {
		return class_exists( McpAdapter::class );
	}

	public static function attach(): void {
		if ( ! class_exists( McpAdapter::class ) ) {
			return;
		}

		McpAdapter::instance();
		add_action( 'mcp_adapter_init', [ self::class, 'register_server' ] );
	}

	/**
	 * @param McpAdapter $adapter
	 */
	public static function register_server( $adapter ): void {
		if ( ! is_object( $adapter ) || ! method_exists( $adapter, 'create_server' ) ) {
			return;
		}

		$adapter->create_server(
			self::SERVER_ID,
			self::NAMESPACE,
			self::ROUTE,
			__( 'Templately', 'templately' ),
			__( 'Search, preview, and import Templately designs on this WordPress site.', 'templately' ),
			defined( 'TEMPLATELY_VERSION' ) ? TEMPLATELY_VERSION : '1.0.0',
			[ HttpTransport::class ],
			null,
			null,
			// Derived from the registry, never a constant (FR-010). Listing the
			// abilities explicitly surfaces them directly in the adapter's tool
			// listing rather than behind its default discover/execute indirection.
			ToolRegistry::get_instance()->ids()
		);
	}
}
