<?php

namespace Templately\Modules\BlockPatterns;

use Templately\Utils\Base;
use Templately\Utils\Database;
use Templately\Utils\Helper;
use Templately\Utils\Http;
use Templately\Utils\Options;

/**
 * Catalog sync: plan-keyed list cache (transient freshness signal + shadow
 * option for stale-while-revalidate) and inert content files for lazy
 * filePath registration. All remote work happens in cron events — an
 * editor-facing read NEVER fetches inline (spec 051 FR-001; research D4/D5).
 */
class PatternSync extends Base {

	const EVENT_SYNC_LIST    = 'templately_block_patterns_sync_list';
	const EVENT_SYNC_CONTENT = 'templately_block_patterns_sync_content';

	const LIST_KEY_PREFIX   = 'block_patterns_';
	const SHADOW_KEY_PREFIX = '_templately_block_patterns_shadow_';

	/**
	 * Which user's connection this site syncs with.
	 *
	 * Needed because `Options` resolves the API key through the CURRENT user
	 * (`Options::user_id()` → `get_current_user_id()` for a non-global login), and
	 * WP-Cron runs with no user at all. So on a locally-connected site the cron
	 * sync read an empty key, `plan_key()` returned null, and `sync_list()`
	 * returned at its first line — silently, no fetch, no log, forever. Observed
	 * live: the event stayed scheduled, fired in 0.04s, and the inserter stayed
	 * empty while an admin page load kept rescheduling it.
	 */
	const OWNER_OPTION = '_templately_block_patterns_owner';

	/** Guards against N editor loads each starting their own catalog fetch. */
	const LOCK_KEY = 'block_patterns_sync_lock';
	const LOCK_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Ids the current user has seen in a LIVE SEARCH, per user.
	 *
	 * `block-patterns/content` uses the cached catalog as its allow-list, which is
	 * exactly where plan gating lives (FR-003) — the native inserter has no
	 * insert-time hook, so an item that is not in the cache must not be fetchable.
	 * A live-searched item is by definition not in that cache, so it needs its own
	 * allow-list: short-lived, per user, and populated only by a search that has
	 * ALREADY applied the same plan filter.
	 */
	const SEARCHED_KEY_PREFIX = 'block_patterns_searched_';
	const SEARCHED_TTL        = 30 * MINUTE_IN_SECONDS;
	const SEARCHED_MAX        = 300;

	/**
	 * Plan cache keys, ordered LOWEST entitlement first. The order is load-bearing:
	 * `get_list()` walks it downwards to borrow a lower tier's cache when the
	 * current tier has never synced (see lower_tier_list()).
	 */
	const PLAN_KEYS          = [ 'free', 'starter', 'pro' ];
	const CONTENT_BATCH_SIZE = 10;
	const RESYNC_INTERVAL    = 12 * HOUR_IN_SECONDS;

	/** Hard stop on pagination, so a bad total_page cannot loop forever. */
	const MAX_PAGES = 10;

	/**
	 * How many sections to carry per template type (`templately_block_patterns_per_type`).
	 *
	 * The catalog is composed BY TYPE rather than as one popularity-ranked run,
	 * because the inserter browses by category: a flat top-N fills whichever types
	 * happen to be popular and leaves the rest of the sidebar empty. Ten per type
	 * gives every category something to show.
	 */
	const DEFAULT_PER_TYPE = 20;

	/**
	 * The two full pages the catalog always carries: newest, and most downloaded.
	 *
	 * Page templates feed the native new-page chooser, which shows EVERY
	 * page-kind pattern it is offered — so this stays deliberately tiny. Two
	 * curated entries is a chooser someone reads; a per-type fan-out is a wall.
	 */
	const FEATURED_PAGE_SORTS = [ 'latest', 'download' ];

	/**
	 * Runaway cap only — no longer the thing that decides catalog size.
	 *
	 * It used to be 100 because every registered pattern was inlined into every
	 * editor page load (~87KB of `__experimentalAdditionalBlockPatterns`). Since
	 * registration moved to `init` nothing inlines them, and the cost is one
	 * cached REST response the editor fetches only when it wants patterns. So the
	 * composition below decides the size and this is just a backstop against a
	 * cloud response nobody expected.
	 */
	const DEFAULT_CEILING = 1000;

	/** Template-type axis cache. Changes rarely; a failed read falls back, never empties. */
	const TYPES_KEY = 'block_patterns_types';
	const TYPES_TTL = 24 * HOUR_IN_SECONDS;

	/**
	 * Seconds one composition run may spend fetching (`templately_block_patterns_sync_budget`).
	 *
	 * Ten, against PHP's default 30s `max_execution_time`, because this can run
	 * inside a request the editor makes and the run still has to publish, write
	 * an option and return afterwards.
	 */
	const SYNC_BUDGET = 10.0;

	/** Where a partly-composed catalog parks between runs. Autoloaded off. */
	const PROGRESS_KEY_PREFIX = '_templately_block_patterns_progress_';

	/**
	 * Plan cache key for the connected account, or null when not connected.
	 *
	 * The cloud returns PRODUCT names, not tiers — a live account reported
	 * `lifetime-five-hundred-site`. Matching against an allow-list of tier names
	 * therefore downgraded every paying customer to the free catalog. Follow the
	 * plugin's own convention instead (see `Admin::is_free_user`): `free` is
	 * free, anything else is paid.
	 */
	public function plan_key(): ?string {
		$api_key = Options::get_instance()->get( 'api_key' );
		if ( empty( $api_key ) ) {
			return null;
		}

		$user = Options::get_instance()->get( 'user', [] );
		$plan = is_array( $user ) && ! empty( $user['plan'] ) ? strtolower( trim( (string) $user['plan'] ) ) : 'free';

		if ( '' === $plan || 'free' === $plan ) {
			return 'free';
		}

		return 'starter' === $plan ? 'starter' : 'pro';
	}

	/**
	 * Record whose connection this site syncs with. Called from contexts that
	 * HAVE a user (admin_init, the connect hook, the editor's sync request), so
	 * the userless cron run has someone to borrow.
	 */
	public function remember_owner(): void {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return;
		}
		if ( empty( Options::get_instance()->get( 'api_key', '', $user_id ) ) ) {
			return;
		}
		if ( (int) get_option( self::OWNER_OPTION ) === $user_id ) {
			return;
		}

		update_option( self::OWNER_OPTION, $user_id, false );
	}

	/**
	 * The user whose connection to sync with, or 0 when there is none.
	 *
	 * Global login first: that option IS the site-wide answer, and it is what
	 * `Options::user_id()` itself falls back to. Then the remembered owner, whose
	 * key is re-checked — a user can disconnect or be deleted without anything
	 * clearing this option.
	 */
	public function owner_id(): int {
		$global = (int) get_option( '_templately_global_login', 0 );
		if ( $global > 0 && ! empty( Options::get_instance()->get( 'api_key', '', $global ) ) ) {
			return $global;
		}

		$owner = (int) get_option( self::OWNER_OPTION, 0 );
		if ( $owner > 0 && ! empty( Options::get_instance()->get( 'api_key', '', $owner ) ) ) {
			return $owner;
		}

		return 0;
	}

	/**
	 * Adopt the owner's identity when the current context has no connection.
	 *
	 * Returns the user id to restore, or null when nothing was changed. ONLY for
	 * the sync entry points: `Http` reads the key through the current user too,
	 * so passing an id down to `plan_key()` alone would fix the gate and still
	 * send an unauthenticated fetch.
	 */
	private function assume_owner(): ?int {
		if ( null !== $this->plan_key() ) {
			return null; // Already running as someone connected.
		}

		$owner = $this->owner_id();
		if ( $owner < 1 ) {
			return null;
		}

		$previous = get_current_user_id();
		wp_set_current_user( $owner );

		return $previous;
	}

	private function restore_user( ?int $previous ): void {
		if ( null !== $previous ) {
			wp_set_current_user( $previous );
		}
	}

	/**
	 * Sync inline when the cache is missing or stale, unless another request is
	 * already doing it. Safe to call from a user-facing request — it is what the
	 * editor calls on load so the library does not depend on cron firing.
	 *
	 * @return array{status:string, count:int, plan_key:?string}
	 */
	public function sync_if_stale(): array {
		$this->remember_owner();

		$previous = $this->assume_owner();

		try {
			$plan_key = $this->plan_key();
			if ( null === $plan_key ) {
				return [ 'status' => 'not_connected', 'count' => 0, 'plan_key' => null ];
			}

			// The transient IS the freshness signal (it expires at RESYNC_INTERVAL);
			// the shadow option outlives it deliberately, so read the transient here
			// rather than get_list(), which would report a stale shadow as a hit.
			$fresh = Database::get_transient( self::LIST_KEY_PREFIX . $plan_key );

			// A PARKED COMPOSITION IS NOT FRESH, however recently it was written.
			// Each batch publishes what it has, so the transient exists and looks
			// current while most of the catalog is still unfetched — reporting that
			// as fresh would strand the remaining types until cron happened to
			// fire, which is the single point of failure this endpoint exists to
			// remove. The lock below still collapses concurrent editors onto one.
			$parked = 0 !== $this->composition_progress( $plan_key )['cursor'];

			if ( ! $parked && is_array( $fresh ) && ! empty( $fresh['items'] ) ) {
				return [ 'status' => 'fresh', 'count' => count( $fresh['items'] ), 'plan_key' => $plan_key ];
			}

			if ( Database::get_transient( self::LOCK_KEY ) ) {
				return [ 'status' => 'syncing', 'count' => 0, 'plan_key' => $plan_key ];
			}

			Database::set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );

			try {
				$this->sync_list();
			} finally {
				Database::delete_transient( self::LOCK_KEY );
			}

			$list = $this->get_list();

			return [
				'status'   => is_array( $list ) && ! empty( $list['items'] ) ? 'synced' : 'failed',
				'count'    => is_array( $list ) ? count( $list['items'] ) : 0,
				'plan_key' => $plan_key,
			];
		} finally {
			$this->restore_user( $previous );
		}
	}

	/**
	 * Fetch + cache the curated list for the current plan.
	 *
	 * Runs in cron AND, via {@see sync_if_stale()}, in the editor's own request.
	 */
	public function sync_list(): void {
		$previous = $this->assume_owner();

		try {
			$this->do_sync_list();
		} finally {
			$this->restore_user( $previous );
		}
	}

	private function do_sync_list(): void {
		$plan_key = $this->plan_key();
		if ( null === $plan_key ) {
			return; // v1: connected sites only (spec 051 Clarifications).
		}

		// Engagement marker: sync traffic must be separable from user imports
		// in the cloud's access logs (spec 051 FR-010 / spec 006 FR-016).
		add_filter( 'templately_request_source', [ $this, 'source_marker' ] );

		// TWO endpoints, and they answer different questions. The blocks endpoint
		// returns only sections, so querying it alone leaves the native new-page
		// chooser with nothing; the pages endpoint feeds only that chooser.
		//
		// The composition is by TEMPLATE TYPE, not one popularity-ranked run. A
		// flat top-N fills whichever types happen to be popular and leaves the
		// rest of the inserter's category sidebar empty, which is the thing that
		// made a 100-item catalog feel small — not the count itself.
		//
		// IT IS ALSO TIME-BOXED AND RESUMABLE, and that is not optional. Composing
		// by type means one cloud request PER TYPE — measured on dev, 22 types plus
		// the two featured pages and the axis query took 49 SECONDS end to end, at
		// roughly 2s a request. `sync_if_stale()` runs this inside a request the
		// editor makes, and PHP's default `max_execution_time` is 30s, so a
		// single-shot composition would be killed halfway on a stock host and
		// leave nothing behind. Each run therefore does as many types as fit in
		// the budget, PUBLISHES what it has, and schedules the rest.
		//
		// Publishing every batch (rather than only the complete set) is what makes
		// a half-finished catalog harmless: the inserter fills in over the next few
		// loads instead of showing nothing until the whole composition lands.
		$progress = $this->composition_progress( $plan_key );

		$items = $progress['items'];

		if ( 0 === $progress['cursor'] ) {
			$items = array_merge( $items, $this->fetch_featured_pages( $plan_key ) );
		}

		$types    = $this->section_template_types();
		$per_type = (int) apply_filters( 'templately_block_patterns_per_type', self::DEFAULT_PER_TYPE );
		$deadline = microtime( true ) + (float) apply_filters( 'templately_block_patterns_sync_budget', self::SYNC_BUDGET );
		$cursor   = $progress['cursor'];

		if ( empty( $types ) ) {
			// A failed axis query must not empty the catalog. Fall back to the flat
			// popularity run this replaced, sized to roughly what the composition
			// would have produced, and treat it as complete.
			Helper::log( 'block-patterns: no template types resolved — falling back to a flat popular fetch.' );

			$items  = array_merge( $items, $this->fetch_type( 'items', 'section', max( 1, $per_type * 10 ), $plan_key ) );
			$cursor = 0;
		} else {
			while ( $cursor < count( $types ) ) {
				$items = array_merge(
					$items,
					$this->fetch_type( 'items', 'section', $per_type, $plan_key, [
						'template_type_id' => (int) $types[ $cursor ]['id'],
					] )
				);

				$cursor++;

				// Checked AFTER a type completes, never mid-type: a partially
				// fetched type would be published as if it were all that exists.
				if ( microtime( true ) >= $deadline ) {
					break;
				}
			}

			$cursor = $cursor >= count( $types ) ? 0 : $cursor;
		}

		remove_filter( 'templately_request_source', [ $this, 'source_marker' ] );

		if ( empty( $items ) ) {
			return; // FR-011: failed sync degrades to the existing cache, never errors.
		}

		// One design can be returned by more than one query — the newest full page
		// may also be the most downloaded one, and a section can carry two types.
		$items = $this->unique_by_id( $items );

		$this->save_composition_progress( $plan_key, $cursor, $items );

		$ceiling = (int) apply_filters( 'templately_block_patterns_ceiling', self::DEFAULT_CEILING );

		if ( $ceiling > 0 && count( $items ) > $ceiling ) {
			// Never truncate silently: a catalog that quietly stops at the cap
			// reads as "this is everything the cloud has" when it is not.
			Helper::log( sprintf(
				'block-patterns: composed %d designs, ceiling %d — %d dropped.',
				count( $items ),
				$ceiling,
				count( $items ) - $ceiling
			) );

			$items = $this->trim_evenly( $items, $ceiling );
		}

		$list = [
			'items'      => $items,
			'fetched_at' => time(),
			'plan_key'   => $plan_key,
		];

		Database::set_transient( self::LIST_KEY_PREFIX . $plan_key, $list, self::RESYNC_INTERVAL );
		update_option( self::SHADOW_KEY_PREFIX . $plan_key, $list, false );

		// Only prefetch markup when patterns register EAGERLY. In lazy mode the
		// content is fetched the moment someone inserts a pattern, so warming all
		// ~100 files would be ~6.7MB of downloads and disk for markup that mostly
		// never gets used.
		if ( ! PatternRegistrar::lazy_mode() && $this->missing_content_ids() ) {
			$this->schedule_once( self::EVENT_SYNC_CONTENT );
		}
	}

	/**
	 * Live catalog search — the one path that is allowed to hit the cloud in
	 * response to something the user typed.
	 *
	 * Deliberately NOT cached in the plan-keyed list: this is a transient answer
	 * to a query, not the curated set the inserter registers. What it does write
	 * is the per-user allow-list, so the ids it returned become fetchable by
	 * `block-patterns/content` — plan gating is applied HERE, at the same
	 * boundary, using the same rule as the sync (a free plan never sees a pro
	 * item).
	 *
	 * @return array<int, array> Catalog Items, already plan-filtered.
	 */
	public function search( string $term, int $limit = 24 ): array {
		$term = trim( $term );
		if ( '' === $term ) {
			return [];
		}

		$previous = $this->assume_owner();

		try {
			$plan_key = $this->plan_key();
			if ( null === $plan_key ) {
				return [];
			}

			add_filter( 'templately_request_source', [ $this, 'source_marker' ] );

			$total = 1;
			$items = array_merge(
				$this->fetch_page( 'items', 'section', $limit, $plan_key, 1, $total, $term ),
				$this->fetch_page( 'pages', 'page', $limit, $plan_key, 1, $total, $term )
			);

			remove_filter( 'templately_request_source', [ $this, 'source_marker' ] );

			$items = array_slice( $items, 0, $limit );

			$this->remember_searched( wp_list_pluck( $items, 'id' ) );

			return $items;
		} finally {
			$this->restore_user( $previous );
		}
	}

	/**
	 * Add ids to the acting user's search allow-list.
	 *
	 * Newest first and capped: the list only has to cover ids the user can still
	 * plausibly click, and an uncapped one would grow with every keystroke's
	 * results until it expired.
	 *
	 * @param int[] $ids
	 */
	private function remember_searched( array $ids ): void {
		$ids = array_values( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		$key      = self::SEARCHED_KEY_PREFIX . get_current_user_id();
		$existing = Database::get_transient( $key );
		$existing = is_array( $existing ) ? array_map( 'absint', $existing ) : [];

		$merged = array_slice( array_values( array_unique( array_merge( $ids, $existing ) ) ), 0, self::SEARCHED_MAX );

		Database::set_transient( $key, $merged, self::SEARCHED_TTL );
	}

	/**
	 * Whether this user may fetch the markup for an id that is not in the cached
	 * catalog — i.e. one their own search returned.
	 */
	public function was_searched( int $id ): bool {
		$stored = Database::get_transient( self::SEARCHED_KEY_PREFIX . get_current_user_id() );

		return is_array( $stored ) && in_array( absint( $id ), array_map( 'absint', $stored ), true );
	}

	public function source_marker(): string {
		return 'pattern-sync';
	}

	/**
	 * Read the cached list: fresh transient first, then the shadow copy, then a
	 * LOWER tier's cache (and schedule an async refresh). Never fetches.
	 * Null = nothing has ever synced for this account at any tier.
	 */
	public function get_list(): ?array {
		$plan_key = $this->plan_key();
		if ( null === $plan_key ) {
			return null;
		}

		$list = Database::get_transient( self::LIST_KEY_PREFIX . $plan_key );
		if ( is_array( $list ) && isset( $list['items'] ) ) {
			return $list;
		}

		$shadow = get_option( self::SHADOW_KEY_PREFIX . $plan_key );
		if ( is_array( $shadow ) && isset( $shadow['items'] ) ) {
			$this->schedule_once( self::EVENT_SYNC_LIST );

			return $shadow;
		}

		return $this->lower_tier_list( $plan_key );
	}

	/**
	 * The best cache from a LOWER plan tier, or null when there is none.
	 *
	 * A plan change switches the cache key to one that has never synced, and the
	 * refresh only ever happens in cron — so between the two the account had NO
	 * cache at its own key and the entire library vanished from the inserter with
	 * no error and no log. That is up to a full resync interval on a healthy site
	 * and unbounded on one where cron does not fire (a stalled loopback request is
	 * enough). Borrowing the tier below closes that window: entitlement grows
	 * monotonically, so anything cached for a lower tier is by definition usable by
	 * a higher one, while a refresh for the real tier is scheduled.
	 *
	 * DOWNWARD ONLY. The reverse would hand a free account the paid catalog and
	 * defeat the plan gate that deliberately lives at this cache boundary (FR-003)
	 * — the native inserter has no insert-time hook to catch it later.
	 *
	 * The borrowed list is capped at the ceiling: it was written under another key
	 * and may predate the current limit (a real site carried a 3430-item free
	 * shadow from an ad-hoc sync), and registering that many patterns is exactly
	 * the editor-payload blowup the ceiling exists to prevent.
	 */
	private function lower_tier_list( string $plan_key ): ?array {
		$rank = array_search( $plan_key, self::PLAN_KEYS, true );
		if ( ! is_int( $rank ) || $rank < 1 ) {
			return null; // unknown key, or already the lowest tier — nothing below.
		}

		// Nearest tier first: closer to the real entitlement than anything under it.
		$lower_keys = array_reverse( array_slice( self::PLAN_KEYS, 0, $rank ) );

		foreach ( $lower_keys as $lower ) {
			$cached = Database::get_transient( self::LIST_KEY_PREFIX . $lower );
			if ( ! is_array( $cached ) || ! isset( $cached['items'] ) ) {
				$cached = get_option( self::SHADOW_KEY_PREFIX . $lower );
			}
			if ( ! is_array( $cached ) || ! isset( $cached['items'] ) ) {
				continue;
			}

			$this->schedule_once( self::EVENT_SYNC_LIST );

			$ceiling = (int) apply_filters( 'templately_block_patterns_ceiling', 100 );

			$cached['items']         = array_slice( (array) $cached['items'], 0, max( 1, $ceiling ) );
			$cached['borrowed_from'] = $lower;

			return $cached;
		}

		return null;
	}

	/**
	 * Fetch content for up to CONTENT_BATCH_SIZE items missing a file, remove
	 * orphaned files, chain another batch if items remain. Cron-context only.
	 */
	public function sync_content_batch(): void {
		$previous = $this->assume_owner();

		try {
			$this->do_sync_content_batch();
		} finally {
			$this->restore_user( $previous );
		}
	}

	private function do_sync_content_batch(): void {
		$list = $this->get_list();
		if ( null === $list ) {
			return;
		}

		// Lazy patterns fetch their own markup on insert; prefetching it here
		// would download the whole catalog to serve the few that get used.
		if ( PatternRegistrar::lazy_mode() ) {
			return;
		}

		$this->remove_orphan_files( wp_list_pluck( $list['items'], 'id' ) );

		add_filter( 'templately_request_source', [ $this, 'source_marker' ] );

		$fetched = 0;
		foreach ( $this->missing_content_ids() as $id ) {
			if ( $fetched >= self::CONTENT_BATCH_SIZE ) {
				$this->schedule_once( self::EVENT_SYNC_CONTENT );
				break;
			}
			$content = $this->fetch_content( $id );
			if ( is_string( $content ) && '' !== $content ) {
				$this->write_content_file( $id, $content );
			}
			$fetched++;
		}

		remove_filter( 'templately_request_source', [ $this, 'source_marker' ] );
	}

	/**
	 * Pattern markup for one item: cached file first, otherwise fetched now and
	 * cached for next time.
	 *
	 * This is the read path for the lazy-pattern block — the only moment a
	 * pattern's real content is needed is when someone actually inserts it, so
	 * a cache miss here is normal rather than exceptional.
	 */
	public function get_content( int $id ): ?string {
		$path = $this->content_path( $id );
		if ( file_exists( $path ) ) {
			$cached = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions

			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		add_filter( 'templately_request_source', [ $this, 'source_marker' ] );
		$content = $this->fetch_content( $id );
		remove_filter( 'templately_request_source', [ $this, 'source_marker' ] );

		if ( ! is_string( $content ) || '' === $content ) {
			return null;
		}

		$this->write_content_file( $id, $content );

		return $content;
	}

	/**
	 * Write one inert content file. Rejects anything containing a PHP open-tag
	 * sequence: the file is later `include`d by WP's pattern registry, so a
	 * `<?` that slipped through would EXECUTE (research D2 — RCE guarantee).
	 */
	public function write_content_file( int $id, string $content ): bool {
		if ( false !== strpos( $content, '<?' ) ) {
			Helper::log( "block-patterns: rejected content for item {$id} (contains PHP open tag)" );

			return false;
		}

		$dir = $this->content_dir();
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$this->harden_dir( $dir );

		$tmp = $dir . "/{$id}.tmp";
		if ( false === file_put_contents( $tmp, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		}

		return rename( $tmp, $this->content_path( $id ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	public function content_path( int $id ): string {
		return $this->content_dir() . "/{$id}.php";
	}

	/**
	 * Where cached pattern content lives.
	 *
	 * Filterable so tests can point it at a scratch directory. They MUST: the
	 * content top-up runner removes files whose id is absent from the current
	 * list, so a test that seeds a small fixture list and runs a batch will
	 * delete every real cached pattern on the site it runs against. That is
	 * exactly how a full test run once emptied a working sandbox.
	 */
	public function content_dir(): string {
		$uploads = wp_upload_dir( null, false );

		return (string) apply_filters(
			'templately_block_patterns_content_dir',
			$uploads['basedir'] . '/templately/patterns'
		);
	}

	/**
	 * Purge cached catalogs on a real DISCONNECT only.
	 *
	 * Deliberately narrow. This used to fire on any write to the stored account
	 * blob (`_templately_user`), but the plugin rewrites that blob on every
	 * routine profile sync — far more often than the plan ever changes — so the
	 * cache was emptied during ordinary admin page loads and patterns vanished
	 * from the inserter until the next cron run.
	 *
	 * Nothing else needs a purge: the cache is keyed BY plan, so a plan change
	 * simply reads a different key (a free account can never be served a pro
	 * catalog), and a disconnected site resolves `plan_key()` to null and serves
	 * nothing at all. Correctness comes from the keying, not from eviction —
	 * this hook only reclaims space once the site is genuinely disconnected.
	 */
	public function maybe_invalidate_for_meta( string $meta_key ): void {
		if ( false === strpos( $meta_key, '_templately_api_key' ) ) {
			return;
		}

		// Only when the key is actually gone — `Options::set()` on connect writes
		// this same meta, and purging there would throw away a catalog we just
		// synced for the very account that is connecting.
		if ( empty( Options::get_instance()->get( 'api_key' ) ) ) {
			$this->invalidate_all();

			return;
		}

		// Just connected — and this hook fires as the connecting user, which is
		// the one context that reliably knows who owns the key.
		$this->remember_owner();

		// Sync NOW rather than waiting for the 12h cron: a fresh connection with
		// an empty library is indistinguishable from a broken feature, and it read
		// exactly that way twice during development.
		if ( null === $this->get_list() ) {
			$this->schedule_once( self::EVENT_SYNC_LIST, 0 );
		}
	}

	public function invalidate_all(): void {
		foreach ( self::PLAN_KEYS as $key ) {
			Database::delete_transient( self::LIST_KEY_PREFIX . $key );
			delete_option( self::SHADOW_KEY_PREFIX . $key );

			// A parked cursor left behind would make the next composition resume
			// into a catalog that no longer exists, producing a list missing every
			// type before the cursor.
			delete_option( self::PROGRESS_KEY_PREFIX . $key );
		}

		// The type axis too — it is what the next sync composes against, so a
		// "clear the pattern library" that left it behind would rebuild the same
		// catalog shape from a cache the user just asked to be rid of.
		Database::delete_transient( self::TYPES_KEY );

		delete_option( self::OWNER_OPTION );
	}

	/**
	 * Schedule the first sync when nothing has ever synced (admin_init).
	 */
	public function ensure_scheduled(): void {
		if ( null === $this->plan_key() ) {
			return;
		}

		// admin_init has a real user, so this is where the cron run gets told
		// whose connection to use.
		$this->remember_owner();

		if ( null === $this->get_list() && ! wp_next_scheduled( self::EVENT_SYNC_LIST ) ) {
			$this->schedule_once( self::EVENT_SYNC_LIST );
		}
	}

	/**
	 * @param int $delay Seconds from now; 0 makes the event due immediately so
	 *                   the very next request runs it.
	 */
	private function schedule_once( string $event, int $delay = MINUTE_IN_SECONDS ): void {
		if ( ! wp_next_scheduled( $event ) ) {
			wp_schedule_single_event( time() + $delay, $event );
		}
	}

	/**
	 * Fetch one catalog endpoint and normalise it into Catalog Items.
	 *
	 * `kind` comes from WHICH endpoint answered, not from a field on the item:
	 * the blocks endpoint returns sections and the pages endpoint returns page
	 * templates, and the payload itself does not distinguish them reliably.
	 *
	 * @param string $endpoint Cloud query name (`items` | `pages`).
	 * @param string $kind     Kind to stamp on everything it returns.
	 * @return array<int, array>
	 */
	/**
	 * The two full pages the chooser gets: newest, and most downloaded.
	 *
	 * @return array<int, array>
	 */
	private function fetch_featured_pages( string $plan_key ): array {
		$pages = [];

		foreach ( self::FEATURED_PAGE_SORTS as $sort ) {
			$total_pages = 1;

			$batch = $this->fetch_page( 'pages', 'page', 1, $plan_key, 1, $total_pages, '', [ 'sort_by' => $sort ] );

			if ( ! empty( $batch[0] ) ) {
				$pages[] = $batch[0];
			}
		}

		return $pages;
	}

	/**
	 * Where the last run stopped, and what it had collected by then.
	 *
	 * A cursor of 0 means "start a fresh composition" — which is also what a
	 * completed run leaves behind, so the next scheduled sync recomposes from
	 * the top rather than resuming a finished one.
	 *
	 * @return array{cursor:int, items:array<int, array>}
	 */
	private function composition_progress( string $plan_key ): array {
		$saved = get_option( self::PROGRESS_KEY_PREFIX . $plan_key );

		if ( ! is_array( $saved ) || empty( $saved['cursor'] ) ) {
			return [ 'cursor' => 0, 'items' => [] ];
		}

		return [
			'cursor' => (int) $saved['cursor'],
			'items'  => isset( $saved['items'] ) && is_array( $saved['items'] ) ? $saved['items'] : [],
		];
	}

	/**
	 * Park the cursor and chain the next batch, or clear it when complete.
	 *
	 * @param int   $cursor Next type index, or 0 when the composition finished.
	 * @param array $items  Everything collected so far.
	 * @return void
	 */
	private function save_composition_progress( string $plan_key, int $cursor, array $items ): void {
		if ( $cursor < 1 ) {
			delete_option( self::PROGRESS_KEY_PREFIX . $plan_key );

			return;
		}

		update_option( self::PROGRESS_KEY_PREFIX . $plan_key, [
			'cursor' => $cursor,
			'items'  => $items,
		], false );

		// Chain immediately rather than waiting for the 12h cycle. If cron never
		// fires — the failure mode this module already plans around — the editor's
		// own sync request picks the composition up instead, because a parked
		// cursor makes the cache "not fresh enough" (see sync_if_stale()).
		$this->schedule_once( self::EVENT_SYNC_LIST, 0 );
	}

	/**
	 * The section template types, cached.
	 *
	 * `groupedCategories` is the cloud's own axis and takes no platform argument,
	 * so it can name a type that has no Gutenberg designs at all. Those cost one
	 * request that returns nothing and are then absent from the catalog, which is
	 * the correct outcome — filtering them out up front would mean trusting
	 * `platforms`, a free-form string, to be parseable.
	 *
	 * @return array<int, array{id:int, slug:string}>
	 */
	private function section_template_types(): array {
		$cached = Database::get_transient( self::TYPES_KEY );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = Http::get_instance()
			->query( 'groupedCategories', 'item_categories { id, name, slug, type }', [] )
			->post();

		if ( is_wp_error( $response ) || empty( $response['item_categories'] ) ) {
			return [];
		}

		$types = [];

		foreach ( (array) $response['item_categories'] as $type ) {
			$id = absint( $type['id'] ?? 0 );

			// `type` is the cloud's page-vs-block marker. Pages are composed from
			// FEATURED_PAGE_SORTS, not fanned out per type, so only blocks here.
			if ( ! $id || ( ! empty( $type['type'] ) && 'block' !== $type['type'] ) ) {
				continue;
			}

			$types[] = [
				'id'   => $id,
				'slug' => sanitize_key( $type['slug'] ?? '' ),
			];
		}

		Database::set_transient( self::TYPES_KEY, $types, self::TYPES_TTL );

		return $types;
	}

	/**
	 * Cut the catalog to the ceiling WITHOUT emptying whole categories.
	 *
	 * The composition appends one category at a time, so a plain
	 * `array_slice( $items, 0, $ceiling )` removes the LAST categories entirely
	 * — reinstating exactly the empty-sidebar problem composing by category
	 * exists to fix, just at the tail instead of the middle. It also drops the
	 * two featured pages last, which is the wrong order: they are 2 rows and the
	 * only thing feeding the new-page chooser.
	 *
	 * So: keep the pages, then take from each category in turn until the budget
	 * is spent. Every category keeps its most-downloaded designs (the cloud's own
	 * order within a category is preserved) and every category keeps something.
	 *
	 * @param array<int, array> $items
	 * @return array<int, array>
	 */
	private function trim_evenly( array $items, int $ceiling ): array {
		$pages    = [];
		$by_group = [];

		foreach ( $items as $item ) {
			if ( isset( $item['kind'] ) && 'page' === $item['kind'] ) {
				$pages[] = $item;
				continue;
			}

			$group                = isset( $item['category'] ) ? (string) $item['category'] : 'general';
			$by_group[ $group ][] = $item;
		}

		$kept = array_slice( $pages, 0, $ceiling );

		// Round-robin, so the cut falls on the deepest categories rather than on
		// whichever ones happen to sort last.
		$round = 0;
		while ( count( $kept ) < $ceiling ) {
			$took = false;

			foreach ( $by_group as $group => $group_items ) {
				if ( ! isset( $group_items[ $round ] ) ) {
					continue;
				}

				$kept[] = $group_items[ $round ];
				$took   = true;

				if ( count( $kept ) >= $ceiling ) {
					break;
				}
			}

			if ( ! $took ) {
				break; // every category exhausted
			}

			$round++;
		}

		return $kept;
	}

	/**
	 * Collapse designs returned by more than one query, keeping the first seen.
	 *
	 * @param array<int, array> $items
	 * @return array<int, array>
	 */
	private function unique_by_id( array $items ): array {
		$seen   = [];
		$unique = [];

		foreach ( $items as $item ) {
			$id = (int) ( $item['id'] ?? 0 );

			if ( ! $id || isset( $seen[ $id ] ) ) {
				continue;
			}

			$seen[ $id ] = true;
			$unique[]    = $item;
		}

		return $unique;
	}

	private function fetch_type( string $endpoint, string $kind, int $limit, string $plan_key, array $extra = [] ): array {
		if ( $limit < 1 ) {
			return [];
		}

		// The cloud caps its own page size, so one request returns fewer rows than
		// `per_page` asks for and reports the real total in `total_page`. Ignoring
		// that capped the catalog at whatever page 1 happened to hold.
		$items       = [];
		$page        = 1;
		$total_pages = 1; // replaced by the first response; must be set before the guard reads it

		do {
			$batch = $this->fetch_page( $endpoint, $kind, $limit, $plan_key, $page, $total_pages, '', $extra );
			$items = array_merge( $items, $batch );
			$page++;
		} while ( count( $items ) < $limit && $page <= (int) $total_pages && $page <= self::MAX_PAGES );

		return array_slice( $items, 0, $limit );
	}

	/**
	 * One page of one endpoint.
	 *
	 * @param int|null $total_pages Set to the endpoint's reported page count.
	 * @return array<int, array>
	 */
	/**
	 * The catalog's dependency rows, sanitized but NOT renamed.
	 *
	 * The field names stay exactly as the cloud sends them — `plugin_file`,
	 * `plugin_original_slug`, `is_pro`, `link` — because these rows are handed
	 * straight to `templately/v1/dependencies/check` and `.../install`, the same
	 * endpoints the full-site and single-import dependency steps post to. Renaming
	 * them into a shape of our own would mean writing a second installer for a
	 * problem that already has one.
	 *
	 * Anything without a `plugin_file` is dropped: `check_dependencies()` skips
	 * such a row anyway, and there would be nothing to install.
	 *
	 * @param mixed $dependencies Raw `dependencies` array from the items query.
	 * @return array<int,array<string,mixed>>
	 */
	private static function normalize_dependencies( $dependencies ): array {
		if ( ! is_array( $dependencies ) ) {
			return [];
		}

		$rows = [];
		foreach ( $dependencies as $dependency ) {
			$dependency = (array) $dependency;
			$file       = isset( $dependency['plugin_file'] ) ? (string) $dependency['plugin_file'] : '';

			// `plugin_file` is a path (`folder/file.php`), so sanitize_text_field is
			// the wrong tool — strip anything that could escape the plugins dir.
			$file = ltrim( str_replace( [ '..', '\\' ], '', $file ), '/' );
			if ( '' === $file || false === strpos( $file, '/' ) ) {
				continue;
			}

			$rows[] = [
				'plugin_file'          => $file,
				'name'                 => sanitize_text_field( (string) ( $dependency['name'] ?? '' ) ),
				'plugin_original_slug' => sanitize_key( (string) ( $dependency['plugin_original_slug'] ?? '' ) ),
				'is_pro'               => ! empty( $dependency['is_pro'] ),
				'link'                 => esc_url_raw( (string) ( $dependency['link'] ?? '' ) ),
			];
		}

		return $rows;
	}

	private function fetch_page( string $endpoint, string $kind, int $limit, string $plan_key, int $page, &$total_pages, string $search = '', array $extra = [] ): array {
		$total_pages = 1;

		$args = [
			'platform' => 'gutenberg',
			'page'     => $page,
			'per_page' => $limit,
			'sort_by'  => 'download',
		];

		// Composition arguments — `template_type_id` for the per-type fan-out, and
		// `sort_by` for the two featured pages. Merged BEFORE the search branch so
		// a live search still wins on ordering, which is its whole point.
		if ( $extra ) {
			$args = array_merge( $args, $extra );
		}

		// The cloud's own relevance ordering, which only applies when searching.
		if ( '' !== $search ) {
			$args['search']  = $search;
			$args['sort_by'] = 'latest';
		}

		// `dependencies` is the CLOUD's own answer to "what does this design need",
		// the same field the full-site and single-import dependency steps read. It
		// carries the plugin file, the wordpress.org slug and the purchase link, so
		// nothing on our side has to infer a plugin from a block namespace — which
		// cannot be done reliably anyway: a free plugin may register its paid tier's
		// block names as upsell stubs, and core's own block-directory search resolves
		// `woocommerce/mini-cart` to an unrelated plugin called OffCanvas.
		//
		// `pack` is what lets the insert apply the pack's global colours and
		// typography, the way single-import already does. Both fields were already
		// available on this query; asking for them costs nothing extra.
		$query    = 'total_page, current_page, data { id, name, price, type, template_type{ slug }, slug, thumbnail, tags{ name }, pack { id, has_settings }, dependencies{ name, plugin_file, plugin_original_slug, is_pro, link } }';
		$response = Http::get_instance()->query( $endpoint, $query, $args )->post();

		if ( is_wp_error( $response ) || empty( $response['data'] ) || ! is_array( $response['data'] ) ) {
			return [];
		}

		$total_pages = isset( $response['total_page'] ) ? (int) $response['total_page'] : 1;

		$items = [];
		foreach ( $response['data'] as $item ) {
			if ( empty( $item['id'] ) ) {
				continue;
			}

			$is_pro = ! empty( $item['price'] ) && (float) $item['price'] > 0;
			// Free accounts must never even cache a pro item under their key —
			// registration-side gating starts at the cache boundary (FR-003).
			if ( 'free' === $plan_key && $is_pro ) {
				continue;
			}

			$items[] = [
				'id'          => absint( $item['id'] ),
				'title'       => sanitize_text_field( $item['name'] ?? '' ),
				'slug'        => sanitize_key( $item['slug'] ?? '' ),
				'kind'        => $kind,
				'plan'        => $is_pro ? 'pro' : 'free',
				'category'    => sanitize_key( $item['template_type']['slug'] ?? 'general' ),
				'preview_url' => esc_url_raw( $item['thumbnail'] ?? '' ),
				// Feeds the inserter's `keywords` scoring field.
				'tags'        => array_values( array_filter( array_map(
					'sanitize_text_field',
					wp_list_pluck( (array) ( $item['tags'] ?? [] ), 'name' )
				) ) ),
				// What this design needs installed, straight from the catalog.
				'requires'    => self::normalize_dependencies( $item['dependencies'] ?? [] ),
				// Pack provenance, read at insert time to merge the pack's global
				// settings into the markup. Both absent for a standalone item, and
				// absent from any cache written before this field existed — the
				// insert then behaves exactly as it did before, unstyled but working,
				// until the next resync fills them in.
				'pack_id'      => absint( $item['pack']['id'] ?? 0 ),
				'has_settings' => ! empty( $item['pack']['has_settings'] ),
			];
		}

		return $items;
	}

	/**
	 * @return int[] Item ids in the current list with no content file yet.
	 */
	private function missing_content_ids(): array {
		$list = $this->get_list();
		if ( null === $list ) {
			return [];
		}

		$missing = [];
		foreach ( $list['items'] as $item ) {
			if ( ! file_exists( $this->content_path( (int) $item['id'] ) ) ) {
				$missing[] = (int) $item['id'];
			}
		}

		return $missing;
	}

	private function remove_orphan_files( array $live_ids ): void {
		$live  = array_map( 'intval', $live_ids );
		$files = glob( $this->content_dir() . '/*.php' );
		foreach ( (array) $files as $file ) {
			$basename = basename( $file, '.php' );
			if ( 'index' === $basename ) {
				continue;
			}
			if ( ! in_array( (int) $basename, $live, true ) ) {
				wp_delete_file( $file );
			}
		}
	}

	private function fetch_content( int $id ): ?string {
		$api_key  = Options::get_instance()->get( 'api_key' );
		$response = Http::get_instance()->query( 'itemContent', 'status, message, data', [
			'api_key' => $api_key,
			'id'      => $id,
		] )->post();

		if ( is_wp_error( $response ) || empty( $response['data'] ) ) {
			return null;
		}

		$data = is_string( $response['data'] ) ? json_decode( $response['data'], true ) : (array) $response['data'];

		return isset( $data['content'] ) && is_string( $data['content'] ) ? $data['content'] : null;
	}

	private function harden_dir( string $dir ): void {
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
}
