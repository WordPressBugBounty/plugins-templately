<?php
/**
 * Publishes the registry's capabilities through the WordPress Abilities API
 * (spec 044, FR-010/FR-012).
 *
 * This is an ADAPTER, not a source of truth. Before 044 the abilities were
 * declared here (well, in MCP.php) and everything else read from them; now the
 * registry holds the declarations and this bridge projects them outward. That
 * inversion is what removes the Abilities API from the critical path — the
 * native server works identically whether or not this bridge ever attaches.
 *
 * Attached ONLY when `wp_register_ability()` exists. Its absence is not an
 * error and must produce no admin notice (FR-012): the overwhelming majority of
 * sites running this plugin are below the WordPress version that ships the
 * Abilities API, and the whole point of 044 is that they are fully supported.
 *
 * @package Templately\Modules\WpAbilitiesApi\Adapters
 */

namespace Templately\Modules\WpAbilitiesApi\Adapters;

use Templately\Modules\McpCore\Registry\ToolRegistry;

class AbilitiesBridge {

	const CATEGORY = 'templately';

	/**
	 * Whether WordPress core's Abilities API is present (core 6.9+).
	 *
	 * Split from the old all-or-nothing `MCP::is_available()`: the Abilities API
	 * and mcp-adapter are INDEPENDENT optional consumers now, and neither gates
	 * the built-in server. Templately supports WordPress 5.0+, so on most sites
	 * this returns false and MCP still works fully.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'wp_register_ability' );
	}

	public static function attach(): void {
		add_action( 'wp_abilities_api_categories_init', [ self::class, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ self::class, 'register_abilities' ] );
	}

	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Templately', 'templately' ),
				'description' => __( 'Search, preview, and import Templately designs on this WordPress site.', 'templately' ),
			]
		);
	}

	/**
	 * One ability per registry descriptor — no hand-maintained list (FR-011).
	 * test-RegistryAbilitiesParity.php asserts this set equals the registry's.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		foreach ( ToolRegistry::get_instance()->all() as $descriptor ) {
			wp_register_ability( $descriptor->id, $descriptor->to_ability_args() );
		}
	}
}
