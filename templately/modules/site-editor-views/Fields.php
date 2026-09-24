<?php
/**
 * The three provenance fields offered to the Pages screen (spec 054 FR-006).
 *
 * Field ids are namespaced `templately_*` deliberately. The host applies our
 * patch by MERGING it key by key, so an id that collided with a core field id
 * would silently redefine someone else's column rather than adding ours.
 *
 * PHP 7.2 SYNTAX ONLY.
 *
 * @package Templately
 */

namespace Templately\Modules\SiteEditorViews;

defined( 'ABSPATH' ) || exit;

class Fields {

	const FIELD_SOURCE   = 'templately_source';
	const FIELD_IMPORTED = 'templately_imported';
	const FIELD_ORIGIN   = 'templately_origin';

	/**
	 * @var Provenance
	 */
	protected $provenance;

	public function __construct( Provenance $provenance ) {
		$this->provenance = $provenance;
	}

	/**
	 * The field ids, in the order they should appear.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return [ self::FIELD_SOURCE, self::FIELD_IMPORTED, self::FIELD_ORIGIN ];
	}

	/**
	 * What to show for one item.
	 *
	 * Every value derives from the provenance record the screen has already
	 * fetched — no lookup per row, which is FR-013 and the reason SC-005 holds.
	 *
	 * @param int    $id
	 * @param string $entity
	 * @return array<string, string> Field id => display value.
	 */
	public function values_for( $id, $entity = 'post' ): array {
		if ( ! $this->provenance->has( $id, $entity ) ) {
			// Not ours. Empty, not "unknown" — a page someone wrote themselves
			// should look untouched, not mislabelled (FR-011, US1 scenario 2).
			return [
				self::FIELD_SOURCE   => '',
				self::FIELD_IMPORTED => '',
				self::FIELD_ORIGIN   => '',
			];
		}

		return [
			self::FIELD_SOURCE   => $this->source_label( $this->provenance->read( $id, Provenance::KEY_PACK, $entity ) ),
			self::FIELD_IMPORTED => $this->provenance->read( $id, Provenance::KEY_IMPORTED_AT, $entity ),
			self::FIELD_ORIGIN   => $this->origin_label( $this->provenance->read( $id, Provenance::KEY_SOURCE, $entity ) ),
		];
	}

	/**
	 * "Templately", plus the pack when we can name it.
	 *
	 * The pack name can be unresolvable — an offline site, a pack since
	 * withdrawn. Then the field still says Templately rather than showing a bare
	 * identifier or an error, because "this came from Templately" is the useful
	 * part and the id means nothing to the reader (FR-012).
	 *
	 * @param string $pack_id
	 * @return string
	 */
	public function source_label( $pack_id ): string {
		$name = '';

		if ( '' !== (string) $pack_id ) {
			/**
			 * Filters the human-readable name for a pack id.
			 *
			 * @since 3.8.0
			 *
			 * @param string $name
			 * @param string $pack_id
			 */
			$name = (string) apply_filters( 'templately_provenance_pack_name', '', $pack_id );
		}

		if ( '' === $name ) {
			return __( 'Templately', 'templately' );
		}

		/* translators: %s: the name of the Templately pack the content came from. */
		return sprintf( __( 'Templately — %s', 'templately' ), $name );
	}

	/**
	 * Generated, or curated.
	 *
	 * `cloud` and `curated` are both curated as far as a reader is concerned;
	 * only `ai` is generated.
	 *
	 * @param string $source
	 * @return string
	 */
	public function origin_label( $source ): string {
		if ( '' === (string) $source ) {
			return '';
		}

		return Provenance::SOURCE_AI === $source
			? __( 'AI-generated', 'templately' )
			: __( 'Curated', 'templately' );
	}
}
