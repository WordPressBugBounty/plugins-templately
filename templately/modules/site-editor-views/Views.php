<?php
/**
 * The two saved views added to the Pages screen (spec 054 FR-007).
 *
 * Only these two. The views originally asked for beyond them — outdated,
 * synchronized, client-approved — each need a per-item comparison against cloud
 * state that no capability provides today. They are v2, and are named in the
 * spec's Out of Scope so their absence reads as a decision.
 *
 * PHP 7.2 SYNTAX ONLY.
 *
 * @package Templately
 */

namespace Templately\Modules\SiteEditorViews;

defined( 'ABSPATH' ) || exit;

class Views {

	const SLUG_IMPORTED = 'templately-imported';
	const SLUG_RECENT   = 'templately-recent';

	/** What "recently" means. A display convention, not retention (spec Assumptions). */
	const RECENT_DAYS = 30;

	/**
	 * The views to contribute, or an empty list.
	 *
	 * Empty when the site has no Templately-imported content: a site with none
	 * must look exactly like a site without the plugin, and two views listing
	 * nothing would advertise a feature that has nothing to show (FR-011).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function definitions(): array {
		if ( ! $this->site_has_imported_content() ) {
			return [];
		}

		// NOTE THE NESTING. A view_list entry is `{ title, slug, view }`, and the
		// filters belong INSIDE `view` — that is the host's own schema (see
		// class-wp-rest-view-config-controller.php, get_view_base_schema). Put
		// `filters` at the top level and the schema simply drops them: no error,
		// no warning, no filter parameter on the query, and a view that lists
		// everything. This shipped that way once, and the symptom was
		// indistinguishable from an unsupported operator.
		return [
			[
				'title' => __( 'Templately imported', 'templately' ),
				'slug'  => self::SLUG_IMPORTED,
				'view'  => [
					'filters' => [
						[
							// `contains`, not `isNotEmpty`. The host's operator
							// vocabulary is fixed and `isNotEmpty` is not in it,
							// and naming an operator it lacks fails the same
							// silent way. Every provenance-carrying item's source
							// field starts with "Templately" and nothing else's
							// does, so `contains` selects exactly the right rows.
							'field'    => Fields::FIELD_SOURCE,
							'operator' => 'contains',
							'value'    => 'Templately',
						],
					],
				],
			],
			[
				'title' => __( 'Recently imported', 'templately' ),
				'slug'  => self::SLUG_RECENT,
				'view'  => [
					'filters' => [
						[
							'field'    => Fields::FIELD_IMPORTED,
							'operator' => 'after',
							'value'    => gmdate( 'c', time() - ( self::RECENT_DAYS * DAY_IN_SECONDS ) ),
						],
					],
				],
			],
		];
	}

	/**
	 * Whether anything on this site carries provenance.
	 *
	 * This runs on a screen load, so it must cost at most one cached lookup and
	 * must never issue a per-row query — an unbounded scan here is precisely the
	 * performance defect SC-005 forbids. The answer is cached until an import
	 * or a revert changes it.
	 *
	 * @return bool
	 */
	protected function site_has_imported_content(): bool {
		$cached = get_transient( 'templately_has_imported_content' );

		if ( false !== $cached ) {
			return '1' === $cached;
		}

		global $wpdb;

		// LIMIT 1 — the question is "any?", not "how many?".
		//
		// KEY_IMPORTED_AT, never KEY_SESSION: the pipeline has written a session
		// id since long before this feature existed, so asking about it would
		// answer "yes" on any site that ever imported, and offer views listing
		// content with nothing to show (see Provenance::has()).
		$found = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
				Provenance::KEY_IMPORTED_AT
			)
		);

		$answer = null !== $found;

		set_transient( 'templately_has_imported_content', $answer ? '1' : '0', DAY_IN_SECONDS );

		return $answer;
	}

	/**
	 * Forget the cached answer.
	 *
	 * Called after an import or a revert, both of which can change it.
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_transient( 'templately_has_imported_content' );
	}
}
