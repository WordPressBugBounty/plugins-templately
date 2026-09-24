<?php
/**
 * `templately/import-template-as-page` — import a single template as a new
 * WordPress page (FR-004).
 *
 * Wraps `Templately\API\Import::import_as_page()` via in-process REST
 * dispatch (research.md §2), gated by rate limiting, the dependency gate,
 * and the Pro-entitlement gate (in that order — FR-015, FR-003 edge case,
 * FR-010).
 *
 * `platform` is a REQUIRED input (Auth Tools addendum): `import_as_page()`
 * silently defaults to `'elementor'` when the param is omitted, so any
 * Gutenberg template imported without explicitly stating its platform would
 * be routed through the Elementor importer and mishandled. Requiring the
 * caller to state it removes the silent-default failure mode entirely
 * rather than just picking a "safer" default.
 *
 * Page template (MCP-only — the normal single-import flow is untouched):
 * after a successful import an Elementor page is forced to "Elementor Full
 * Width" (`elementor_header_footer`) when the cloud item left the template
 * empty/`default`, while a Gutenberg page is left on the theme's default
 * (empty) template. See {@see self::enforce_page_template()}.
 *
 * @package Templately\Modules\McpAbilities\Abilities
 */

namespace Templately\Modules\McpAbilities\Abilities;

use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\McpCore\Support\DependencyGateResolver;
use Templately\Modules\McpCore\Support\ImportRateLimiter;
use Templately\Modules\McpCore\Support\Permissions;
use Templately\Modules\McpCore\Support\ProGateResolver;
use Templately\Modules\McpCore\Support\RestDispatcher;
use WP_Error;

class ImportTemplatePageAbility {

	const ID = 'templately/import-template-as-page';

	public static function descriptor(): array {
		return [
			'id'                  => self::ID,
			'label'               => __( 'Import Templately Template as New Page', 'templately' ),
			'description'         => __( 'Import a single Templately template as a brand-new WordPress page.', 'templately' ),
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'       => [ 'type' => 'integer' ],
					'title'    => [ 'type' => 'string' ],
					'platform' => [ 'type' => 'string', 'enum' => [ 'elementor', 'gutenberg' ] ],
				],
				'required'   => [ 'id', 'platform' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'target'       => [ 'type' => 'string' ],
					'status'       => [ 'type' => 'string', 'enum' => [ 'success', 'partial' ] ],
					'post_id'      => [ 'type' => 'integer' ],
					'failed_parts' => [ 'type' => 'array' ],
				],
			],
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => [ Permissions::class, 'can_use_abilities' ],
			'access_level'        => ToolDescriptor::ACCESS_FULL,
			'annotations'         => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
		];
	}

	/**
	 * @param array $input
	 * @return array|WP_Error
	 */
	public static function execute( array $input ) {
		$id       = (int) ( $input['id'] ?? 0 );
		$platform = (string) ( $input['platform'] ?? '' );

		if ( empty( $id ) ) {
			return new WP_Error( 'not_found', __( 'A template id is required.', 'templately' ) );
		}

		if ( ! in_array( $platform, [ 'elementor', 'gutenberg' ], true ) ) {
			return new WP_Error( 'invalid_platform', __( 'platform must be "elementor" or "gutenberg".', 'templately' ) );
		}

		$rate_limit_error = ImportRateLimiter::check();
		if ( $rate_limit_error ) {
			return $rate_limit_error;
		}

		$dependency_check = DependencyGateResolver::check( $id );
		if ( is_wp_error( $dependency_check ) ) {
			return $dependency_check;
		}

		$params = [ 'id' => $id, 'platform' => $platform ];
		if ( ! empty( $input['title'] ) ) {
			$params['title'] = sanitize_text_field( $input['title'] );
		}

		$response = RestDispatcher::dispatch( 'POST', '/templately/v1/import/page', $params );

		if ( is_wp_error( $response ) ) {
			return ProGateResolver::to_purchase_error( $response, $id );
		}

		$data = $response->get_data();

		if ( empty( $data['post_id'] ) ) {
			return new WP_Error( 'import_failed', __( 'Import failed for an unknown reason.', 'templately' ) );
		}

		$post_id = (int) $data['post_id'];

		self::enforce_page_template( $post_id, $platform );

		return [
			'target'  => 'page',
			'status'  => 'success',
			'post_id' => $post_id,
		];
	}

	/**
	 * Set the page template on the freshly-imported page (MCP-only behavior — the
	 * normal single-import flow is deliberately untouched).
	 *
	 * - Elementor: force "Elementor Full Width" (`elementor_header_footer`) when the
	 *   cloud item left the template empty or `default`. A real, non-default template
	 *   coming from the cloud item is respected. Persists it the same way FSI does
	 *   (see `modules/full-site-import/Utils/Utils.php`): the Elementor page setting
	 *   (`_elementor_page_settings['template']`) *and* the WP `_wp_page_template` meta.
	 * - Gutenberg: explicitly pin the WP default (empty) template (`_wp_page_template`
	 *   = `default`), rather than relying on the plain insert's implicit theme default,
	 *   so the outcome is deterministic across themes.
	 *
	 * @param int    $post_id
	 * @param string $platform  'elementor' | 'gutenberg'
	 */
	private static function enforce_page_template( int $post_id, string $platform ): void {
		if ( 'gutenberg' === $platform ) {
			update_post_meta( $post_id, '_wp_page_template', 'default' );
			return;
		}

		if ( 'elementor' !== $platform || ! class_exists( '\Elementor\Plugin' ) ) {
			return;
		}

		$page_settings = get_post_meta( $post_id, '_elementor_page_settings', true );
		if ( ! is_array( $page_settings ) ) {
			$page_settings = [];
		}

		$current = $page_settings['template'] ?? '';
		if ( '' !== $current && 'default' !== $current ) {
			return; // Respect a real template the cloud item already carries.
		}

		$page_settings['template'] = 'elementor_header_footer';
		update_post_meta( $post_id, '_elementor_page_settings', $page_settings );
		update_post_meta( $post_id, '_wp_page_template', 'elementor_header_footer' );
	}
}
