<?php
/**
 * wp-abilities-api module — publishes the capability registry through the two
 * OPTIONAL WordPress-side consumers (spec 046, FR-010/FR-012).
 *
 *   mcp-core (ToolRegistry) ──► AbilitiesBridge   → wp_register_ability()
 *                          └──► McpAdapterBridge  → the mcp-adapter plugin's server
 *
 * Both are adapters, never a source of truth. Each attaches only if its
 * dependency exists, and the absence of either is entirely normal and must
 * surface NO admin notice (FR-012): WordPress core's Abilities API is 6.9+ and
 * mcp-adapter is a separate plugin, while Templately supports WordPress 5.0+.
 * The whole point of splitting the built-in server out into `modules/mcp-server/`
 * is that those sites are fully supported without any of this.
 *
 * The module still boots when neither dependency is present — `Connection` backs
 * the Settings → MCP tab, which must be able to OFFER to install the adapter, so
 * it cannot be gated on the adapter already being there. (That is also why
 * there is no `get_requirements()` entry for the adapter class here.)
 *
 * @package Templately
 */

namespace Templately\Modules\WpAbilitiesApi;

use Templately\Core\Module_Base;
use Templately\Modules\WpAbilitiesApi\Adapters\AbilitiesBridge;
use Templately\Modules\WpAbilitiesApi\Adapters\McpAdapterBridge;
use Templately\Modules\WpAbilitiesApi\REST\Connection;

class Module extends Module_Base {

	public function get_name(): string {
		return 'wp-abilities-api';
	}

	/**
	 * Held back from the 3.8.0 release (2026-09-23) — see Module_Base::deferred_module_enabled().
	 * Delete this override to release it.
	 */
	public function is_active(): bool {
		return Module_Base::deferred_module_enabled( $this->get_name() );
	}

	/**
	 * Declared (not incidental): both bridges project
	 * `McpCore\Registry\ToolRegistry`'s descriptors outward, and `Connection`
	 * uses `McpCore\Support\Permissions` for its permission callbacks.
	 */
	public function get_dependencies(): array {
		return [ 'mcp-core' ];
	}

	/**
	 * The former inline symbol probes, expressed as sub-feature gates
	 * (spec 053). Deliberately NOT `get_requirements()` entries: this module
	 * must boot when both capabilities are absent — the Settings tab has to be
	 * able to OFFER the adapter install (see the class doc block).
	 */
	public function get_capability_gates(): array {
		return [
			'abilities-bridge' => 'abilities-api',
			'adapter-bridge'   => 'mcp-adapter-plugin',
		];
	}

	protected function init_hooks(): void {
		if ( $this->has_gate( 'abilities-bridge' ) ) {
			AbilitiesBridge::attach();
		}

		if ( $this->has_gate( 'adapter-bridge' ) ) {
			McpAdapterBridge::attach();
		}

		// Registered UNCONDITIONALLY, unlike the bridges above, so the tab can
		// offer to install the adapter when it is missing.
		Connection::get_instance();
	}
}
