<?php

namespace Templately\Modules\BlockPatterns\REST;

use Templately\API\API;
use Templately\Modules\BlockPatterns\PatternSync;
use WP_REST_Request;

/**
 * The editor's own catalog refresh — so the library does not depend on cron.
 *
 * Cron is the wrong single point of failure for this: WP-Cron only fires when
 * someone visits the site, a stalled loopback disables it entirely, and it runs
 * with no user, which is what made the sync silently no-op on a locally
 * connected site (see PatternSync::OWNER_OPTION). The editor, by contrast, is
 * exactly where an empty library is noticed — and it arrives with a logged-in
 * user whose connection resolves without any borrowing.
 *
 * This is NOT a violation of "no editor-facing request may touch the network"
 * (spec 051 FR-001): that rule governs pattern REGISTRATION, which still reads
 * the local cache only and is what the inserter blocks on. This is a separate,
 * explicit request the editor makes AFTER it has loaded, and its result is
 * applied by invalidating the client's pattern cache — never by holding up a
 * render.
 */
class Sync extends API {
	private $endpoint = 'block-patterns/sync';

	public function permission_check( WP_REST_Request $request ) {
		$this->request = $request;

		return is_user_logged_in() && current_user_can( 'edit_posts' );
	}

	public function register_routes() {
		$this->post( $this->endpoint, [ $this, 'sync' ] );
	}

	/**
	 * A no-op when the cache is fresh, which is the common case — the editor
	 * calls this on every load and must not re-fetch the catalog each time.
	 * Concurrent editors collapse onto one fetch via the sync lock.
	 */
	public function sync() {
		$result = PatternSync::get_instance()->sync_if_stale();

		return $this->success( [
			'status'   => $result['status'],
			'count'    => $result['count'],
			'plan_key' => $result['plan_key'],
			// The client only needs to refresh its pattern store when the set it
			// already loaded is out of date.
			'changed'  => 'synced' === $result['status'],
		] );
	}
}
