<?php
/**
 * The Pages screen contribution (spec 054, contracts/view-config.md).
 *
 * Three rules govern everything here, and each exists because breaking it fails
 * quietly rather than loudly.
 *
 * **1. `merge()`, never `set()`.** The host offers four write modes. `set()`
 * swaps a whole top-level key wholesale — documented for a callback that "owns
 * a key outright" — so using it on `default_view` would discard the site
 * owner's sort order, layout and column selection. That is FR-008's exact
 * prohibition, and because the host also offers `replace()` and `remove()`, the
 * way to violate it is a named sibling method rather than a hypothetical. This
 * class calls `merge()` and nothing else.
 *
 * **2. Declare the schema version.** The second argument is a SCHEMA VERSION,
 * not the priority the pre-release note described. A patch declaring a version
 * the host does not support is rejected IN FULL with `_doing_it_wrong` — so the
 * failure mode is a screen that silently lacks our fields, with nothing a user
 * or a production log would show. When core's `LATEST_VERSION` moves past ours,
 * this constant does not follow automatically: a patch authored against schema 1
 * keeps working only while core still accepts schema 1.
 *
 * **3. Touch only documented keys.** Anything written outside
 * `default_view`/`default_layouts`/`view_list`/`form` is discarded by the host
 * before the config is returned. We touch two: `default_view.fields` to make our
 * fields available, and `view_list` to add our views. We contribute no layout
 * and nothing to Quick Edit.
 *
 * PHP 7.2 SYNTAX ONLY.
 *
 * @package Templately
 */

namespace Templately\Modules\SiteEditorViews;

defined( 'ABSPATH' ) || exit;

class ViewConfig {

	/**
	 * The schema version our patch is authored against.
	 *
	 * `WP_View_Config_Data::LATEST_VERSION` was 1 when this was written and
	 * verified. See rule 2 above before changing it.
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * The one screen we contribute to.
	 *
	 * Pages, and nothing else (FR-009). Templates and Parts list the host's own
	 * template entities — our theme-builder templates are a different post type,
	 * so fields contributed there could never render — and catalog patterns are
	 * registered on demand rather than stored as pattern posts.
	 */
	const FILTER = 'get_entity_view_config_postType_page';

	/**
	 * @var Fields
	 */
	protected $fields;

	/**
	 * @var Views
	 */
	protected $views;

	public function __construct( Provenance $provenance ) {
		$this->fields = new Fields( $provenance );
		$this->views  = new Views();

		add_filter( self::FILTER, [ $this, 'contribute' ], 10, 2 );
	}

	/**
	 * Add our fields and views to the Pages screen configuration.
	 *
	 * @param \WP_View_Config_Data $data   The configuration container.
	 * @param array                $entity The entity being configured.
	 * @return \WP_View_Config_Data
	 */
	public function contribute( $data, $entity = [] ) {
		if ( ! is_object( $data ) || ! method_exists( $data, 'merge' ) ) {
			return $data;
		}

		$patch = $this->patch();

		if ( empty( $patch ) ) {
			return $data;
		}

		$data->merge( $patch, self::SCHEMA_VERSION );

		return $data;
	}

	/**
	 * The patch itself, built separately so it can be asserted without a host.
	 *
	 * Note what is NOT here: no `sort`, no `layout`, no wholesale `fields`
	 * replacement, and no removal or reordering of the host's own views. Those
	 * absences are the requirement (FR-008), which is why this method is the
	 * unit under test rather than the rendered screen.
	 *
	 * @return array<string, mixed> Empty when there is nothing to contribute.
	 */
	public function patch(): array {
		$views = $this->views->definitions();

		if ( empty( $views ) ) {
			// No Templately content on this site: contribute nothing at all, so
			// the screen is indistinguishable from a site without the plugin
			// (FR-011, SC-004).
			return [];
		}

		return [
			'default_view' => [
				'fields' => $this->fields->ids(),
			],
			'view_list'    => $views,
		];
	}
}
