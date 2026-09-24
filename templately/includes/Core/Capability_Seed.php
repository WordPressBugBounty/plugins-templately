<?php
/**
 * Capability_Seed — registers the core capability set at bootstrap (spec 053).
 *
 * Called from Plugin.php on `plugins_loaded`, immediately before
 * Modules_Manager::boot(), so every module can gate on these keys during its
 * own boot. Registration stores metadata only — no probe runs here (FR-006).
 *
 * The WordPress 7.1 keys are registered VERSION-ONLY on purpose (spec 053
 * Clarifications, 2026-08-02): the code must be testable on a 7.1 release
 * candidate as soon as one exists; each probe is added when the final 7.1
 * documentation confirms the symbol. Every key here has a matching ledger row
 * in .ai/context/wp-feature-watch.md (FR-024) — keep the two in sync.
 *
 * PHP 7.2 SYNTAX ONLY (FR-038).
 *
 * @package Templately
 */

namespace Templately\Core;

class Capability_Seed {
	/**
	 * Register the seed set on the shared registry.
	 *
	 * @return void
	 */
	public static function register_all() {
		$capabilities = Capabilities::get_instance();

		// The block-pattern catalog's host gate. The version INPUT still flows
		// through the legacy `templately_block_patterns_wp_version` filter so
		// third-party/test code holding that seam keeps working (FR-033); the
		// boolean override `templately_capability_pattern-file-path` is the
		// documented path going forward.
		$capabilities->register( 'pattern-file-path', [
			'probe'   => function () {
				global $wp_version;
				$version = apply_filters( 'templately_block_patterns_wp_version', $wp_version );
				return version_compare( (string) $version, '6.5', '>=' );
			},
			'wp'      => '6.5',
			'posture' => 'hard',
			'note'    => 'Lazy pattern content via filePath registration (WP 6.5); the block-patterns module is absent without it.',
		] );

		$capabilities->register( 'http-parallel-requests', [
			'probe'   => function () {
				return class_exists( 'WpOrg\\Requests\\Requests' ) && is_callable( [ 'WpOrg\\Requests\\Requests', 'request_multiple' ] );
			},
			'wp'      => '6.2',
			'posture' => 'degrade',
			'note'    => 'Namespaced Requests library (WP 6.2) with request_multiple(); full-site-import fetches attachments a few at a time with it and falls back to one request per file without it.',
		] );

		$capabilities->register( 'abilities-api', [
			'probe'   => function () {
				return function_exists( 'wp_register_ability' );
			},
			'wp'      => '6.9',
			'posture' => 'degrade',
			'note'    => 'WordPress core Abilities API; the abilities bridge detaches without it (built-in MCP server still serves).',
		] );

		$capabilities->register( 'mcp-adapter-plugin', [
			'probe'   => function () {
				return class_exists( 'WP\\MCP\\Core\\McpAdapter' );
			},
			'posture' => 'degrade',
			'note'    => 'The WordPress/mcp-adapter companion plugin; the adapter bridge detaches without it and Settings offers the install.',
		] );

		// ---- WordPress 7.1 wave: version-only until the 7.1 Field Guide
		// ---- confirms each probe symbol (Clarifications 2026-08-02).

		// Probed, not merely versioned, as of 2026-08-02: the mechanism was read
		// off a real 7.1-beta4 host, so the symbol is known rather than assumed.
		// `WP_View_Config_Data` is what the filter hands every callback, so its
		// presence is the same fact the feature actually depends on.
		$capabilities->register( 'site-editor-view-config', [
			'probe'   => function () {
				return class_exists( 'WP_View_Config_Data' );
			},
			'wp'      => '7.1',
			'posture' => 'degrade',
			'note'    => 'Site Editor screen view-config filters (get_entity_view_config_{kind}_{name}); contribute additively via WP_View_Config_Data::merge( $patch, $version ).',
		] );

		$capabilities->register( 'abilities-selective-fields', [
			'wp'      => '7.1',
			'posture' => 'degrade',
			'note'    => 'Abilities API selective field responses / validation hooks. Probe TBD at 7.1 Field Guide.',
		] );

		$capabilities->register( 'wp-knowledge-cpt', [
			'probe'   => function () {
				return post_type_exists( 'wp_knowledge' );
			},
			'wp'      => '7.1',
			'stage'   => 'init',
			'posture' => 'degrade',
			'note'    => 'wp_knowledge post type (/wp/v2/knowledge). Probe valid only after init registers post types.',
		] );

		$capabilities->register( 'block-bindings-nested-richtext', [
			'wp'      => '7.1',
			'posture' => 'degrade',
			'note'    => 'Bound rich text preserves nested inner blocks (behavioral — likely stays version-only).',
		] );

		$capabilities->register( 'icons-api', [
			'probe'   => function () {
				return function_exists( 'register_icon' );
			},
			'wp'      => '7.1',
			'posture' => 'degrade',
			'note'    => 'Icons API (register_icon(), wp/v2/icon-collections).',
		] );

		// Read on the CLIENT, not here: `@wordpress/components` is host-provided,
		// so the site's WordPress decides whether its form controls still want the
		// `__next40pxDefaultSize` opt-in (below 7.1) or warn about it (7.1+, where
		// 40px is unconditional and the prop is a no-op). There is no server-side
		// symbol to probe — the evidence lives in a JS package — so this key stays
		// version-only by nature, not as a stopgap. See
		// `react-src/utils/componentSizing.ts`, its only consumer.
		$capabilities->register( 'components-40px-default', [
			'wp'      => '7.1',
			'posture' => 'degrade',
			'note'    => '@wordpress/components form controls default to 40px; __next40pxDefaultSize hard-deprecated to a no-op.',
		] );
	}
}
