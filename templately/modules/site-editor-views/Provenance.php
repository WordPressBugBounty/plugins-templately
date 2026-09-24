<?php
/**
 * The provenance record for imported content (spec 054).
 *
 * Records where an item came from, when, and how its content came to be. It is
 * a record of ORIGIN, not of state: later edits never update it, because the
 * question it answers is "where did this page come from?" and the answer does
 * not change when someone rewrites a heading.
 *
 * Two properties are worth stating before the code:
 *
 * **It is written for everything the import creates, not only what a screen
 * shows.** The Pages screen is the only consumer today. Recording media, custom
 * types and terms as well costs nothing — the pipeline already stamps every one
 * of them through a single meta bag — and it is what lets a later surface be
 * added without the backfill spec 054 rules out (FR-001a, FR-005).
 *
 * **The generated-vs-curated answer is per RUN, not per item** (FR-001b). No
 * per-item marker exists anywhere in the import pipeline, so a per-item claim
 * would be a precision we cannot substantiate. A run where only some items were
 * generated reports at run granularity, and says so.
 *
 * PHP 7.2 SYNTAX ONLY (Constitution XV).
 *
 * @package Templately
 */

namespace Templately\Modules\SiteEditorViews;

defined( 'ABSPATH' ) || exit;

class Provenance {

	/** The pack an item came from. Opaque string — never treated as a number. */
	const KEY_PACK = '_templately_pack_id';

	/** When it arrived. UTC, ISO 8601, stored as a string so the zone is unambiguous. */
	const KEY_IMPORTED_AT = '_templately_imported_at';

	/** How the content came to be: cloud | ai | curated. */
	const KEY_SOURCE = '_templately_source';

	/**
	 * The import run responsible.
	 *
	 * Already written by the pipeline as `BaseRunner::META_SESSION_KEY`; this
	 * module adopts that key rather than storing the same fact twice.
	 */
	const KEY_SESSION = '_templately_import_session_id';

	/** Content assembled from a pack as authored. */
	const SOURCE_CURATED = 'curated';

	/** Content generated for this site. */
	const SOURCE_AI = 'ai';

	/** Content that came from the cloud without generation. Displays as curated. */
	const SOURCE_CLOUD = 'cloud';

	/**
	 * Register the meta so it is readable wherever the item is.
	 *
	 * Registered for BOTH posts and terms, because the import creates both.
	 *
	 * @return void
	 */
	public function register(): void {
		foreach ( $this->definitions() as $key => $definition ) {
			register_post_meta( '', $key, $definition );
			register_term_meta( '', $key, $definition );
		}

		add_filter( 'rest_request_before_callbacks', [ $this, 'drop_write_attempts' ], 10, 3 );
	}

	/**
	 * Remove our keys from any incoming REST `meta` bag, before core reads it.
	 *
	 * WITHOUT THIS, `auth_callback => false` MAKES EVERY UNTAGGED POST UNSAVABLE
	 * IN THE BLOCK EDITOR. The chain, all of it core's:
	 *
	 * 1. `show_in_rest` puts all four keys in the GET response for EVERY post,
	 *    as `''` when no row exists — FR-004/FR-013 require that exposure.
	 * 2. The block editor holds `meta` as one entity property. Dirty ANY meta
	 *    (`footnotes` alone is enough) and it PUTs the whole bag back, ours
	 *    included, at the empty values it was handed.
	 * 3. `WP_REST_Meta_Fields::update_meta_value()` skips the cap check only when
	 *    `1 === count( get_metadata_raw( ... ) )` and the value is unchanged. A
	 *    post with NO row has no old value, so the short-circuit does not apply,
	 *    `current_user_can( 'edit_post_meta' )` runs, our auth callback refuses,
	 *    and the save 403s with "Sorry, you are not allowed to edit the
	 *    _templately_pack_id custom field."
	 *
	 * So the failure lands on content we never touched — an IMPORTED page has a
	 * stored value, matches, and short-circuits; every hand-authored page does
	 * not. Reproduced on WP 7.1-beta4: 129 of 129 posts and pages unsavable,
	 * which also makes the editor's own "Attempt recovery" a dead end, since the
	 * recovered block can never be saved.
	 *
	 * `readonly` in a meta schema would be the obvious fix and does not exist —
	 * `class-wp-rest-meta-fields.php` never reads it. Dropping the keys is the
	 * available one, and it is a STRICTER answer than the 403 it replaces: a
	 * genuine forgery attempt is discarded rather than argued with, and a
	 * round-trip is a no-op. `auth_callback` stays false as the backstop for any
	 * path that does not come through here.
	 *
	 * @param WP_REST_Response|WP_Error|null $response Unmodified, always.
	 * @param array                          $handler  Route handler.
	 * @param WP_REST_Request                $request  Mutated in place.
	 * @return WP_REST_Response|WP_Error|null
	 */
	public function drop_write_attempts( $response, $handler, $request ) {
		if ( ! $request instanceof \WP_REST_Request ) {
			return $response;
		}

		$meta = $request['meta'];

		if ( ! is_array( $meta ) ) {
			return $response;
		}

		$stripped = array_diff_key( $meta, $this->definitions() );

		if ( count( $stripped ) !== count( $meta ) ) {
			$request['meta'] = $stripped;
		}

		return $response;
	}

	/**
	 * The four keys and how each is registered.
	 *
	 * `show_in_rest` is required, not incidental: the screen renders these from
	 * the record it already fetched, and without REST exposure it would need a
	 * request per row — the per-item work FR-013 forbids.
	 *
	 * `auth_callback` refuses WRITES outright. The pipeline writes these
	 * directly during import; nothing should be able to set them over REST, and
	 * a provenance record a user can forge is not a record. Reads are governed
	 * by the item's own permissions, which is what "readable by anyone already
	 * permitted to read the item" means in practice (FR-004).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function definitions(): array {
		$refuse_writes = function () {
			return false;
		};

		return [
			self::KEY_PACK        => [
				'single'            => true,
				'type'              => 'string',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $refuse_writes,
				'description'       => __( 'The Templately pack this content was imported from.', 'templately' ),
			],
			self::KEY_IMPORTED_AT => [
				'single'            => true,
				'type'              => 'string',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $refuse_writes,
				'description'       => __( 'When this content was imported, in UTC.', 'templately' ),
			],
			self::KEY_SOURCE      => [
				'single'            => true,
				'type'              => 'string',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => $refuse_writes,
				'description'       => __( 'Whether this content was generated or curated.', 'templately' ),
			],
			self::KEY_SESSION     => [
				'single'            => true,
				'type'              => 'string',
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
				'auth_callback'     => $refuse_writes,
				'description'       => __( 'The import run that created this content.', 'templately' ),
			],
		];
	}

	/**
	 * Contribute provenance to every item an import creates.
	 *
	 * Attached to the pipeline's per-item meta seam, so this module never names
	 * a runner and no runner names this module.
	 *
	 * The session key is deliberately absent from what we add: the pipeline owns
	 * it and re-applies it after this filter, and writing it here as well would
	 * mean two places could disagree about the same fact.
	 *
	 * @param array  $meta       Meta the pipeline will apply to each created item.
	 * @param string $entity     'post' or 'term'.
	 * @param string $session_id The import run.
	 * @return array
	 */
	public function contribute( $meta, $entity = 'post', $session_id = '' ) {
		if ( ! is_array( $meta ) ) {
			return $meta;
		}

		$meta[ self::KEY_PACK ]        = $this->pack_for_run( $session_id );
		$meta[ self::KEY_IMPORTED_AT ] = gmdate( 'c' );
		$meta[ self::KEY_SOURCE ]      = $this->source_for_run( $session_id );

		return $meta;
	}

	/**
	 * The pack this run is importing.
	 *
	 * @param string $session_id
	 * @return string Empty when unknown — an empty field is honest, a guess is not.
	 */
	protected function pack_for_run( $session_id ) {
		/**
		 * Filters the pack id recorded against content this run creates.
		 *
		 * @since 3.8.0
		 *
		 * @param string $pack_id
		 * @param string $session_id
		 */
		return (string) apply_filters( 'templately_provenance_pack_id', '', $session_id );
	}

	/**
	 * How this run's content came to be — decided ONCE per run (FR-001b).
	 *
	 * @param string $session_id
	 * @return string One of the SOURCE_* constants.
	 */
	protected function source_for_run( $session_id ) {
		/**
		 * Filters whether this run's content is generated or curated.
		 *
		 * Answered by the AI import flow when it is the one running; the default
		 * is the honest answer for every other path.
		 *
		 * @since 3.8.0
		 *
		 * @param string $source
		 * @param string $session_id
		 */
		$source = apply_filters( 'templately_provenance_source', self::SOURCE_CLOUD, $session_id );

		$allowed = [ self::SOURCE_CLOUD, self::SOURCE_AI, self::SOURCE_CURATED ];

		return in_array( $source, $allowed, true ) ? $source : self::SOURCE_CLOUD;
	}

	/**
	 * Stamp posts a SINGLE-template import just wrote (spec 060 FR-002a).
	 *
	 * A full-site import stamps through the pipeline's item-meta bag, and the session key is
	 * the pipeline's to write there. A single import has no pipeline and no session, so this
	 * is the one place that key is written outside it — with a `single-` prefix so a reader can
	 * tell the two apart. The pack is recorded EMPTY: a single template is not a pack, and an
	 * empty field is honest where a template id would be a lie.
	 *
	 * Idempotent, and never overwrites: a post that already carries a session id (an FSI post
	 * re-written by a later single insert, or a second stamp) keeps what it has.
	 *
	 * @param int[]|int $post_ids Posts that now hold imported block markup.
	 * @param string    $context  Which import produced them — recorded nowhere, kept for the hook signature.
	 * @return void
	 */
	public function stamp_single( $post_ids, $context = 'single' ) {
		foreach ( (array) $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			if ( $post_id <= 0 || ! get_post( $post_id ) ) {
				continue;
			}

			if ( '' !== $this->read( $post_id, self::KEY_SESSION ) ) {
				continue;
			}

			update_post_meta( $post_id, self::KEY_SESSION, 'single-' . uniqid( '', true ) );
			update_post_meta( $post_id, self::KEY_PACK, '' );
			update_post_meta( $post_id, self::KEY_IMPORTED_AT, gmdate( 'c' ) );
			update_post_meta( $post_id, self::KEY_SOURCE, self::SOURCE_CLOUD );
		}
	}

	/**
	 * Whether an item carries provenance.
	 *
	 * Keyed on the import TIMESTAMP, not the session id, and the difference is
	 * load-bearing. The session id is written by the import pipeline itself and
	 * has been for far longer than this feature has existed, so every item from
	 * every past import already carries one. Keying on it would mark content
	 * imported before this feature as tagged — content that has no pack, no
	 * time and no source to show — and those items would then appear in the
	 * saved views with three empty fields. That is the opposite of FR-005, and
	 * it is a defect an integration test caught after the unit tests missed it:
	 * the unit fixture had no session id at all, so the two predicates looked
	 * identical there.
	 *
	 * The timestamp is written only by this module, so it means exactly
	 * "provenance was recorded", which is the question being asked.
	 *
	 * @param int    $id
	 * @param string $entity 'post' or 'term'.
	 * @return bool
	 */
	public function has( $id, $entity = 'post' ) {
		return '' !== (string) $this->read( $id, self::KEY_IMPORTED_AT, $entity );
	}

	/**
	 * Read one provenance value.
	 *
	 * @param int    $id
	 * @param string $key    One of the KEY_* constants.
	 * @param string $entity 'post' or 'term'.
	 * @return string Empty string when absent — never a zero, never a 1970 date.
	 */
	public function read( $id, $key, $entity = 'post' ) {
		$value = 'term' === $entity
			? get_term_meta( (int) $id, $key, true )
			: get_post_meta( (int) $id, $key, true );

		return is_scalar( $value ) ? (string) $value : '';
	}
}
