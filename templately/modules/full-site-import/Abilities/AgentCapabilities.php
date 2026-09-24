<?php
/**
 * Full Site Import's agent-facing capabilities (spec 045).
 *
 * Five capabilities — inspect a pack, start an import, advance/poll it, retry it,
 * revert it — contributed to the shared registry in `modules/mcp-core/`. Whatever
 * transport is serving (the built-in server in `modules/mcp-server/`, the
 * WordPress Abilities API, the mcp-adapter plugin) picks them up from there; none
 * of them is named here and none of them needs to know FSI exists.
 *
 * ## Why these live inside full-site-import and not in an MCP module
 *
 * They are FSI code. They import `Utils\SessionData`, drive the frozen
 * `wp_ajax_templately_pack_*` action contract, parse FSI's own SSE event stream
 * (`Support\FsiStatusNormalizer`), and hook `templately_fsi_before_import`. A
 * change to any of that breaks them, so they belong in the same module and the
 * same review as the thing they wrap.
 *
 * That is the general rule, not an exception carved for FSI: **a feature module
 * owns its agent capabilities whenever it is genuinely coupled to them.**
 * `modules/mcp-abilities/` keeps the nine template-library capabilities precisely
 * because they are NOT coupled — they reach their features by route string
 * through `McpCore\Support\RestDispatcher` and import nothing but
 * `Templately\Utils\*`, so they are a standalone pack with no owning feature to
 * live in.
 *
 * @package Templately\Modules\FullSiteImport\Abilities
 */

namespace Templately\Modules\FullSiteImport\Abilities;

use Templately\Modules\McpCore\Registry\ToolRegistry;

class AgentCapabilities {

	/**
	 * The ONE list. Adding an FSI capability means adding its class here and
	 * nothing else (FR-011).
	 *
	 * @var string[]
	 */
	const ABILITY_CLASSES = [
		InspectFullSitePackAbility::class,
		StartFullSiteImportAbility::class,
		FullSiteImportStatusAbility::class,
		RetryFullSiteImportAbility::class,
		RevertFullSiteImportAbility::class,
	];

	/**
	 * PHP time limit (seconds) imposed on an FSI import slice driven through the
	 * MCP admin-ajax loopback. Chosen so the importer's own
	 * `Helper::fsi_should_exit()` yields the runner slice (at ~max_time minus its
	 * built-in 20% delay, i.e. ~32s here) comfortably under common 60s gateway/
	 * proxy read timeouts, keeping every `full-site-import-status` poll responsive.
	 */
	const LOOPBACK_TIME_LIMIT = 40;

	public static function register(): void {
		// Hooked, not called directly, so the registry can rebuild itself from
		// every contributor at any time — see ToolRegistry::COLLECT_ACTION.
		add_action( ToolRegistry::COLLECT_ACTION, [ self::class, 'declare_capabilities' ] );

		// Bound the slice duration for imports driven through the loopback so each
		// slice returns before an upstream gateway/proxy timeout. Done entirely
		// through the importer's EXISTING `templately_fsi_before_import` action —
		// no runner code is modified. The browser flow carries no marker and is
		// untouched.
		add_action( 'templately_fsi_before_import', [ self::class, 'cap_loopback_slice_time' ] );
	}

	/**
	 * Hands over CLASS NAMES only — descriptors resolve lazily on first use.
	 * Resolving them eagerly would call each descriptor's `__()` during
	 * `plugins_loaded`, i.e. before `init` loads the textdomain, which makes
	 * WP 6.7+ emit a "textdomain triggered too early" notice. Under
	 * WP_DEBUG_DISPLAY that notice is echoed during bootstrap and every later
	 * REST error status collapses to 200, because headers are already sent.
	 * See ToolRegistry::$pending_classes.
	 *
	 * @param ToolRegistry $registry
	 * @return void
	 */
	public static function declare_capabilities( $registry ): void {
		$registry->register_classes( self::ABILITY_CLASSES );
	}

	/**
	 * For an FSI import driven by the loopback (marked by
	 * `McpCore\Support\AjaxLoopbackDispatcher` via `templately_mcp_driven`),
	 * impose a finite PHP time limit.
	 *
	 * FSI runs under `set_time_limit(0)`, which makes `Helper::fsi_should_exit()`'s
	 * max_execution_time check a no-op; restoring a finite limit here re-enables
	 * that existing per-item time-box so a heavy runner splits into short slices.
	 * Hooked after the importer's own `set_time_limit(0)` and before the runner
	 * loop.
	 *
	 * @return void
	 */
	public static function cap_loopback_slice_time(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the underlying ajax handler already verifies the nonce; this only reads a boolean routing flag.
		if ( ! empty( $_REQUEST['templately_mcp_driven'] ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- set_time_limit may be disabled on some hosts; a warning there is harmless and the cap is best-effort.
			@set_time_limit( self::LOOPBACK_TIME_LIMIT );
		}
	}
}
