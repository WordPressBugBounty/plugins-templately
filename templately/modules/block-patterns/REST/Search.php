<?php

namespace Templately\Modules\BlockPatterns\REST;

use Templately\API\API;
use Templately\Modules\BlockPatterns\PatternRegistrar;
use Templately\Modules\BlockPatterns\PatternSync;
use WP_REST_Request;

/**
 * Live catalog search for the block inserter (R&D).
 *
 * The registered catalog is capped at ~100 items — the ceiling exists because
 * WordPress resolves every registered pattern when it builds the editor payload
 * — so inserter search only ever sees the most-downloaded slice of a catalog in
 * the thousands. This endpoint answers what the user actually typed, and the
 * client injects the results into the editor's pattern settings for the life of
 * the query.
 *
 * The results are NOT registered and NOT cached into the plan-keyed list. They
 * are a transient answer, and the only lasting effect is the per-user allow-list
 * `PatternSync::search()` writes so the ids it returned can be fetched by
 * `block-patterns/content`.
 *
 * Returns patterns in the editor's own shape via `PatternRegistrar::describe_item()`,
 * the same code that builds a registered pattern — so an injected result cannot
 * drift from a registered one.
 */
class Search extends API {
	private $endpoint = 'block-patterns/search';

	/** Bounds one response; the inserter shows a handful at a time regardless. */
	const MAX_LIMIT = 40;

	public function permission_check( WP_REST_Request $request ) {
		$this->request = $request;

		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	public function register_routes() {
		$this->get( $this->endpoint, [ $this, 'search' ] );
	}

	public function search() {
		$term  = trim( (string) $this->get_param( 'q', '' ) );
		$limit = min( self::MAX_LIMIT, max( 1, (int) $this->get_param( 'limit', 24, 'absint' ) ) );

		// Below two characters every query matches half the catalog, which costs a
		// cloud round trip to tell the user nothing.
		if ( mb_strlen( $term ) < 2 ) {
			return $this->success( [ 'patterns' => [], 'term' => $term ] );
		}

		$sync = PatternSync::get_instance();
		if ( null === $sync->plan_key() ) {
			return $this->success( [ 'patterns' => [], 'term' => $term ] );
		}

		$registrar = PatternRegistrar::get_instance();
		$patterns  = [];

		foreach ( $sync->search( $term, $limit ) as $item ) {
			$patterns[] = $registrar->describe_item( $item );
		}

		return $this->success( [ 'patterns' => $patterns, 'term' => $term ] );
	}
}
