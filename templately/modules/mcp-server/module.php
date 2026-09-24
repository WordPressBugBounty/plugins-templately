<?php
/**
 * mcp-server module — Templately's BUILT-IN MCP server (spec 046).
 *
 * A JSON-RPC 2.0 endpoint, its own OAuth 2.1 authorization server, and the
 * credential store behind both. It serves whatever `mcp-core`'s registry holds,
 * so it needs no knowledge of any individual capability and no capability module
 * needs to know it exists.
 *
 * ## Why this is separate from wp-abilities-api
 *
 * This server has NO availability gate. `wp-abilities-api` publishes the same
 * capabilities through WordPress core's Abilities API (6.9+) and the separately
 * installed mcp-adapter plugin — both optional, both frequently absent.
 * Templately supports WordPress 5.0+, so before this module existed every agent
 * capability was unreachable on the large majority of sites running the plugin.
 * Keeping the two in separate modules is what makes that independence
 * structural rather than a convention someone can quietly break: neither module
 * references the other, and both read the registry.
 *
 * Routes (see Server\HttpTransport for the full map):
 *   POST /wp-json/templately/v1/mcp               JSON-RPC
 *   POST /templately/mcp                          pretty alias (rewrite)
 *   GET  /.well-known/oauth-*                     path-scoped discovery
 *   GET|POST /templately/authorize                approval screen
 *
 * The adapter's own route (`/wp-json/templately/mcp`) is a DIFFERENT path and
 * both may serve concurrently (FR-007).
 *
 * @package Templately
 */

namespace Templately\Modules\McpServer;

use Templately\Core\Module_Base;
use Templately\Modules\McpServer\Auth\OAuth\RecordStore;
use Templately\Modules\McpServer\Cleanup\OAuthRecordsTask;
use Templately\Modules\McpServer\REST\Connections;
use Templately\Modules\McpServer\Server\HttpTransport;

class Module extends Module_Base {

	public function get_name(): string {
		return 'mcp-server';
	}

	/**
	 * Held back from the 3.8.0 release (2026-09-23) — see Module_Base::deferred_module_enabled().
	 * Delete this override to release it.
	 */
	public function is_active(): bool {
		return Module_Base::deferred_module_enabled( $this->get_name() );
	}

	/**
	 * Declared (not incidental): the server dispatches through
	 * `McpCore\Registry\ToolRegistry`, reads access levels off
	 * `McpCore\Registry\ToolDescriptor`, and `AuthManager` falls back to
	 * `McpCore\Support\Permissions` for cookie/Application-Password callers.
	 */
	public function get_dependencies(): array {
		return [ 'mcp-core', 'utilities' ];
	}

	protected function init_hooks(): void {
		// The endpoint itself. Registers its REST routes on `rest_api_init` and
		// its pretty-path rewrites on `init` — see HttpTransport's constructor.
		HttpTransport::get_instance();

		// Credential management for the Settings → MCP tab.
		Connections::get_instance();

		// Reclaim expired delegated-approval records (FR-030). The sweep itself
		// stays here; its SCHEDULING moved to the shared cleanup service, which
		// this module contributes a task to (spec 052).
		RecordStore::schedule_sweep();
		OAuthRecordsTask::register();
	}
}
