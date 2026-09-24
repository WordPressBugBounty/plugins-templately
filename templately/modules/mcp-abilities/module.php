<?php
/**
 * MCP Abilities module (specs 041-mcp-abilities, 046-native-mcp-server).
 *
 * Templately's template-library agent capabilities: search, detail, and the three
 * import paths, plus the four account-authentication actions. Nine capabilities
 * in all, contributed to the shared registry in `modules/mcp-core/`.
 *
 * This module owns the capabilities themselves and nothing about how they are
 * served. The transports live elsewhere and read the registry:
 * `modules/mcp-server/` (built in, always) and `modules/wp-abilities-api/`
 * (WordPress Abilities API + the mcp-adapter plugin, both optional). Full-site
 * import capabilities live in their own module, `modules/mcp-fsi/`.
 *
 * Relocated from includes/MCP/ (2026-07-08); narrowed to capabilities-only when
 * the registry, bridges, and native server were split out (2026-07-26).
 * Independent of the dev-only tool registry in modules/developer/mcp-tools/ (FR-011).
 *
 * @package Templately
 */

namespace Templately\Modules\McpAbilities;

use Templately\Core\Module_Base;

class Module extends Module_Base {

	public function get_name(): string {
		return 'mcp-abilities';
	}

	/**
	 * Held back from the 3.8.0 release (2026-09-23) — see Module_Base::deferred_module_enabled().
	 * Delete this override to release it.
	 */
	public function is_active(): bool {
		return Module_Base::deferred_module_enabled( $this->get_name() );
	}

	/**
	 * Declared (not incidental): every ability's descriptor names
	 * `McpCore\Registry\ToolDescriptor` for its access level, and `MCP` hands the
	 * ability classes to `McpCore\Registry\ToolRegistry`.
	 */
	public function get_dependencies(): array {
		return [ 'mcp-core' ];
	}

	protected function init_hooks(): void {
		// Registers this module's capabilities and the Google-OAuth key handoff.
		// There is no availability gate: the native server serves these on any
		// supported WordPress, with or without the Abilities API or mcp-adapter.
		MCP::get_instance();
	}
}
