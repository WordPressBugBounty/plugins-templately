<?php
/**
 * `templately/discover-templates` — unified template search/browse ability (FR-001).
 *
 * Wraps the existing `Templately\API\Items::get_items()` REST logic via an
 * in-process REST dispatch (research.md §2) — no logic duplicated.
 *
 * @package Templately\Modules\McpAbilities\Abilities
 */

namespace Templately\Modules\McpAbilities\Abilities;

use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\McpCore\Support\Permissions;
use Templately\Modules\McpCore\Support\RestDispatcher;

class DiscoverTemplatesAbility {

	const ID = 'templately/discover-templates';

	/** Maps this ability's `type` enum to the underlying Items REST `type` param. */
	const TYPE_MAP = [
		'pack'  => 'packs',
		'page'  => 'pages',
		'block' => 'blocks',
	];

	public static function descriptor(): array {
		return [
			'id'                  => self::ID,
			'label'               => __( 'Discover designs from Templately', 'templately' ),
			'description'         => __( 'Search or browse the Templately design template library, covering both full page designs and individual section/block designs (e.g. headers, heroes, footers, pricing tables). Use this whenever the user wants to find, browse, or get ideas for a design, layout, template, or section — by keyword, type (page or section), platform (Elementor or Gutenberg), category, or sort order.', 'templately' ),
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'keyword'  => [ 'type' => 'string', 'description' => __( 'Optional search keyword.', 'templately' ) ],
					'type'     => [
						'type'        => 'string',
						'enum'        => [ 'pack', 'page', 'block' ],
						'description' => __( 'page = a full-page design; block = an individual section/block design (header, hero, footer, pricing table, …); pack = a full multi-page website kit. Defaults to block; packs are only returned when explicitly requested.', 'templately' ),
					],
					'platform' => [ 'type' => 'string', 'enum' => [ 'elementor', 'gutenberg' ], 'default' => 'elementor' ],
					'category' => [ 'type' => 'integer', 'description' => __( 'Category ID to filter by.', 'templately' ) ],
					'sort_by'  => [ 'type' => 'string', 'enum' => [ 'latest', 'rating', 'download' ], 'default' => 'latest' ],
					'page'     => [ 'type' => 'integer', 'default' => 1 ],
					'per_page' => [ 'type' => 'integer', 'default' => 12, 'maximum' => 50 ],
				],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'items'       => [
						'type'  => 'array',
						'items' => [
							'type'       => 'object',
							'properties' => [
								'id'        => [ 'type' => 'integer' ],
								'name'      => [ 'type' => 'string' ],
								'slug'      => [ 'type' => 'string' ],
								'type'      => [ 'type' => 'string' ],
								'platform'  => [ 'type' => 'string' ],
								'category'  => [ 'type' => 'string' ],
								'thumbnail' => [ 'type' => 'string' ],
								'demo_url'  => [ 'type' => 'string', 'description' => __( 'Live preview/demo URL — open to view the design before importing.', 'templately' ) ],
								'price'     => [ 'type' => 'number' ],
								'is_pro'    => [ 'type' => 'boolean' ],
							],
						],
					],
					'total'       => [ 'type' => 'integer' ],
					'page'        => [ 'type' => 'integer' ],
					'per_page'    => [ 'type' => 'integer' ],
					'total_pages' => [ 'type' => 'integer' ],
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
	 * @return array|\WP_Error
	 */
	public static function execute( array $input ) {
		$params = [
			'type'     => self::TYPE_MAP[ $input['type'] ?? 'block' ] ?? 'blocks',
			'platform' => $input['platform'] ?? 'elementor',
			'sort_by'  => $input['sort_by'] ?? 'latest',
			'page'     => max( 1, (int) ( $input['page'] ?? 1 ) ),
			'per_page' => min( 50, max( 1, (int) ( $input['per_page'] ?? 12 ) ) ),
		];

		if ( ! empty( $input['keyword'] ) ) {
			$params['search'] = sanitize_text_field( $input['keyword'] );
		}

		if ( ! empty( $input['category'] ) ) {
			$params['categories'] = wp_json_encode( [ (int) $input['category'] ] );
		}

		$response = RestDispatcher::dispatch( 'GET', '/templately/v1/items', $params );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$data      = $response->get_data();
		$raw_items = $data['data'] ?? [];

		// Project each result to a concise, agent-friendly shape. `demo_url` (the item's
		// live preview) is surfaced explicitly so the user can view the demo BEFORE
		// choosing/importing — mirrors the fields the dev-tools search tool exposes.
		$items = [];
		foreach ( is_array( $raw_items ) ? $raw_items : [] as $item ) {
			$price   = $item['price'] ?? 0;
			$items[] = [
				'id'        => $item['id'] ?? 0,
				'name'      => $item['name'] ?? '',
				'slug'      => $item['slug'] ?? '',
				'type'      => $item['type'] ?? '',
				'platform'  => $item['platform'] ?? '',
				'category'  => $item['category']['name'] ?? '',
				'thumbnail' => $item['thumbnail'] ?? '',
				'demo_url'  => $item['live_url'] ?? ( $item['preview_url'] ?? '' ),
				'price'     => $price,
				'is_pro'    => (float) $price > 0,
			];
		}

		return [
			'items'       => $items,
			'total'       => isset( $data['total'] ) ? (int) $data['total'] : count( $items ),
			'page'        => (int) ( $data['current_page'] ?? $params['page'] ),
			'per_page'    => $params['per_page'],
			'total_pages' => (int) ( $data['total_page'] ?? 1 ),
		];
	}
}
