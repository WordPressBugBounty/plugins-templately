<?php

namespace Templately\Modules\ThemeBuilder\Managers;

use Templately\Modules\ThemeBuilder\PageTemplates;
use Templately\Modules\ThemeBuilder\Source;
use Templately\Modules\ThemeBuilder\Types\Archive;
use Templately\Modules\ThemeBuilder\Types\BaseTemplate;
use Templately\Modules\ThemeBuilder\Types\CourseArchive;
use Templately\Modules\ThemeBuilder\Types\CourseSingle;
use Templately\Modules\ThemeBuilder\Types\DocsArchive;
use Templately\Modules\ThemeBuilder\Types\DocsSingle;
use Templately\Modules\ThemeBuilder\Types\Error;
use Templately\Modules\ThemeBuilder\Types\FluentProductSingle;
use Templately\Modules\ThemeBuilder\Types\Footer;
use Templately\Modules\ThemeBuilder\Types\Header;
use Templately\Modules\ThemeBuilder\Types\Page;
use Templately\Modules\ThemeBuilder\Types\PageSingle;
use Templately\Modules\ThemeBuilder\Types\Post;
use Templately\Modules\ThemeBuilder\Types\ProductArchive;
use Templately\Modules\ThemeBuilder\Types\ProductSingle;
use Templately\Modules\ThemeBuilder\Types\Search;
use Templately\Modules\ThemeBuilder\Types\Single;
use WP_Error;

class TemplateManager {
	private $types     = [];
	private $documents = [];

	public function __construct( $builder ) {
		$this->register_types();
	}

	public function register_types() {
		$types = [
			'header'          => Header::class,
			'footer'          => Footer::class,
			'single'          => Single::class,
			'archive'         => Archive::class,
			'post'            => Post::class,
			'page'            => Page::class,
			'error'           => Error::class,

			// WooCommerce Template Types
			'product_single'  => ProductSingle::class,
			'fluent_product_single'  => FluentProductSingle::class,
			'product_archive' => ProductArchive::class,

			'page_single'     => PageSingle::class,

			// LMS (LearnDash)
			'course_archive'  => CourseArchive::class,
			'course_single'   => CourseSingle::class,

			// BetterDocs
			'docs_single'     => DocsSingle::class,
			'docs_archive'    => DocsArchive::class,

			// Search results
			'search'          => Search::class,
		];

		$this->types = $types;
	}

	/**
	 * @param       $type
	 * @param       $post_data
	 * @param array $meta
	 *
	 * @return BaseTemplate|WP_Error
	 */
	public function create( $type, $post_data, array $meta = [] ) {
		/**
		 * @var BaseTemplate $class ;
		 */
		$class = $this->get_template_type( $type );

		$should_update_title = false;

		if ( empty( $post_data['post_title'] ) ) {
			$post_data['post_title'] = ucwords( $type ) . ' Template';

			$should_update_title = true;
		}

		$meta = wp_parse_args( $meta, [
			Source::TYPE_META_KEY     => $type,
			Source::PLATFORM_META_KEY => $meta['platform'] ?? 'gutenberg'
		] );

		if ( isset( $meta['platform'] ) ) {
			unset( $meta['platform'] );
		}

		if ( $meta[ Source::PLATFORM_META_KEY ] == 'elementor' ) {
			$meta['_wp_page_template'] = 'elementor_header_footer';
		} elseif ( $meta[ Source::PLATFORM_META_KEY ] == 'gutenberg' ) {
			$meta['_wp_page_template'] = PageTemplates::TEMPLATE_HEADER_FOOTER;
		}

		$post_data['meta_input'] = $meta;

		$post_type = $class::get_property( 'cpt' );
		if ( ! empty( $post_type ) && empty( $post_data['post_type'] ) ) {
			$post_data['post_type'] = $post_type;
		}

		$post_id = wp_insert_post( $post_data );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		if ( $should_update_title ) {
			$post_data['ID']         = $post_id;
			$post_data['post_title'] .= " #{$post_id} (by Templately)";
			unset( $post_data['meta_input'] );

			wp_update_post( $post_data );
		}

		// A template you just created — by importing a pack, or by hand — is the one you
		// mean to use. Without this the tie-break falls to "lowest post ID", so a second
		// site-wide header of the same type would be shadowed by the first one ever
		// imported and never render.
		$this->set_active( $post_id, $type );

		return new $class( [
			'post_id' => $post_id
		] );
	}

	/**
	 * Make `$post_id` the active template for its type, and the only one.
	 *
	 * Scoped per TYPE, not per location or condition set: "active" answers "which header",
	 * not "which header here" — conditions already answer the second and keep overriding
	 * this. Clearing the flag from a sibling therefore never stops it rendering where its
	 * conditions are more specific; it only gives up the tie.
	 *
	 * @param int    $post_id Template to activate.
	 * @param string $type    Its `_templately_template_type`; read from the post when omitted.
	 * @return void
	 */
	public function set_active( int $post_id, string $type = '' ) {
		if ( ! $post_id ) {
			return;
		}

		if ( '' === $type ) {
			$type = (string) get_post_meta( $post_id, Source::TYPE_META_KEY, true );
		}

		if ( '' === $type ) {
			return;
		}

		$siblings = get_posts( [
			'post_type'        => Source::CPT,
			'post_status'      => 'any',
			'numberposts'      => -1,
			'fields'           => 'ids',
			'suppress_filters' => false,
			'meta_query'       => [
				[ 'key' => Source::TYPE_META_KEY, 'value' => $type ],
				[ 'key' => Source::ACTIVE_META_KEY, 'value' => '1' ],
			],
		] );

		foreach ( $siblings as $sibling_id ) {
			if ( (int) $sibling_id !== $post_id ) {
				delete_post_meta( (int) $sibling_id, Source::ACTIVE_META_KEY );
			}
		}

		update_post_meta( $post_id, Source::ACTIVE_META_KEY, '1' );

		self::$active_id_cache = [];
	}

	/**
	 * `get_active_id()` results, per type. Invalidated by `set_active()` — a stale entry
	 * would badge the previous winner on the very screen the user just changed.
	 *
	 * @var array<string,int>
	 */
	private static $active_id_cache = [];

	/** Whether `$post_id` carries the active flag. */
	public static function is_active( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, Source::ACTIVE_META_KEY, true );
	}

	/**
	 * The template of `$type` that WINS a tie — flagged if one is, else most recently
	 * modified. Mirrors `ConditionManager::sort_by_priority()`'s tie-break, minus the
	 * post-ID step, which only ever separates two templates saved in the same second.
	 *
	 * Reading the flag alone is not enough to tell a user what is happening: no site
	 * predating the flag has one set, so a list keyed on it shows a badge on NOTHING
	 * while two headers sit there and one of them is quietly winning. The badge has to
	 * answer "which of these am I getting", which is this — not "which was flagged".
	 *
	 * Conditions still override per request, so this is the DEFAULT for the type, not a
	 * promise about every URL.
	 *
	 * @param string $type
	 * @return int Template id, or 0 when the type has none.
	 */
	public static function get_active_id( string $type ): int {
		if ( '' === $type ) {
			return 0;
		}

		if ( isset( self::$active_id_cache[ $type ] ) ) {
			return self::$active_id_cache[ $type ];
		}

		$flagged = get_posts( [
			'post_type'        => Source::CPT,
			'post_status'      => 'any',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'suppress_filters' => false,
			'meta_query'       => [
				[ 'key' => Source::TYPE_META_KEY, 'value' => $type ],
				[ 'key' => Source::ACTIVE_META_KEY, 'value' => '1' ],
			],
		] );

		if ( ! empty( $flagged ) ) {
			return self::$active_id_cache[ $type ] = (int) $flagged[0];
		}

		$recent = get_posts( [
			'post_type'        => Source::CPT,
			'post_status'      => 'any',
			'numberposts'      => 1,
			'fields'           => 'ids',
			'orderby'          => [ 'modified' => 'DESC', 'ID' => 'ASC' ],
			'suppress_filters' => false,
			'meta_query'       => [
				[ 'key' => Source::TYPE_META_KEY, 'value' => $type ],
			],
		] );

		return self::$active_id_cache[ $type ] = ! empty( $recent ) ? (int) $recent[0] : 0;
	}

	private function get_template_type( $type, $default = 'post' ): string {
		if ( isset( $this->types[ $type ] ) ) {
			return $this->types[ $type ];
		}

		return $this->types[ $default ];
	}

	/**
	 *
	 * @param int  $post_id
	 * @param bool $from_cache
	 *
	 * @return ThemeTemplate||false
	 */
	public function get( int $post_id, $from_cache = true ) {
		$this->register_types();

		$post_id = absint( $post_id );

		if ( ! $post_id || ! get_post( $post_id ) ) {
			return false;
		}

		if ( ! $from_cache || ! isset( $this->documents[ $post_id ] ) ) {
			$doc_type       = $this->get_template_type_by_id( $post_id );
			$doc_type_class = $this->get_template_type( $doc_type );

			$this->documents[ $post_id ] = new $doc_type_class( [
				'post_id' => $post_id,
			] );
		}

		return $this->documents[ $post_id ];
	}

	private function get_template_type_by_id( int $post_id ) {
		// Auto-save inherits from the original post.
		if ( wp_is_post_autosave( $post_id ) ) {
			$post_id = wp_get_post_parent_id( $post_id );
		}

		$template_type = get_post_meta( $post_id, Source::TYPE_META_KEY, true );

		if ( $template_type && isset( $this->types[ $template_type ] ) ) {
			return $template_type;
		}

		return 'post';
	}

	public function get_template_types(): array {
		$this->register_types();

		return $this->types;
	}
}