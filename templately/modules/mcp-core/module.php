<?php
/**
 * mcp-core module — the agent-capability registry every MCP surface reads from.
 *
 * This module owns no capabilities and serves no endpoint. It exists so that the
 * things which DO — the capability modules, the WordPress Abilities API bridge,
 * and the native MCP server — can all share one declaration of what exists
 * without depending on each other:
 *
 *   mcp-abilities ─┐
 *   mcp-fsi ───────┼──register_classes()──► mcp-core (ToolRegistry)
 *                  │                            │
 *                  │                            ├──► wp-abilities-api  (iff Abilities API / mcp-adapter)
 *                  │                            └──► mcp-server        (always)
 *
 * Splitting this out is what keeps that graph acyclic. Before the split the
 * registry lived beside the abilities, so the native server had to depend on the
 * ability module and the ability module had to depend on the server's
 * `ToolDescriptor` for its access levels — a cycle the module-dependency gate
 * (spec 004 US6) cannot express and boot ordering cannot satisfy.
 *
 * Nothing is registered here at boot. `ToolRegistry` is a lazy singleton and
 * resolves its descriptors on first use (see ToolRegistry::$pending_classes for
 * why resolution must not happen during `plugins_loaded`), so a module that
 * boots later can still contribute capabilities.
 *
 * @package Templately
 */

namespace Templately\Modules\McpCore;

use Templately\Core\Module_Base;

class Module extends Module_Base {

	public function get_name(): string {
		return 'mcp-core';
	}

	/**
	 * Intentionally empty. Consumers reach the registry through
	 * `Registry\ToolRegistry::get_instance()`; there is no hook to install and
	 * nothing to eagerly construct.
	 */
	protected function init_hooks(): void {}
}
