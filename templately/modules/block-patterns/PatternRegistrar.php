<?php

namespace Templately\Modules\BlockPatterns;

use Templately\Utils\Base;
use Templately\Utils\Helper;

/**
 * Registers cached, plan-permitted catalog items as native block patterns.
 * Metadata-only from the local cache; content resolves lazily through the
 * registry's filePath support (WP 6.5+ — the module's is_active() floor).
 * Zero HTTP on this path, ever (spec 051 FR-002/SC-003; research D1).
 */
class PatternRegistrar extends Base {

	/** The one flat category every Templately pattern also belongs to. */
	const BRAND_CATEGORY = 'templately';

	/**
	 * Cumulative pattern-content budget, in bytes.
	 *
	 * Registered patterns are NOT free at page load: WordPress preloads the
	 * patterns REST response into the editor, and building it resolves every
	 * registered pattern's content — `filePath` defers the read, it does not
	 * avoid it. Templately designs are large (a real catalog averages ~50KB per
	 * pattern, full-page templates far more), so an unbounded catalog turned
	 * post-new.php into a 13MB, 6-second page. Registration stops once this much
	 * content has been committed, newest-first.
	 */
	const CONTENT_BUDGET_BYTES = 1500000;

	/**
	 * Register every registrable cached item + its category. Safe to call
	 * repeatedly (idempotent) and safe against corrupt cache entries — one bad
	 * item is skipped and logged, never fatal (FR-011).
	 */
	public function register_all(): void {
		$sync = PatternSync::get_instance();

		if ( null === $sync->plan_key() ) {
			return; // v1: connected sites only.
		}

		$list = $sync->get_list();
		if ( null === $list || empty( $list['items'] ) ) {
			return;
		}

		// Lazy patterns carry a few hundred bytes each, so the budget that exists
		// to keep the editor page small has nothing to protect against — the whole
		// catalog can register.
		$budget = self::lazy_mode()
			? PHP_INT_MAX
			: (int) apply_filters( 'templately_block_patterns_content_budget', self::CONTENT_BUDGET_BYTES );
		$spent  = 0;
		$capped = 0;

		foreach ( $list['items'] as $item ) {
			try {
				$cost = $this->content_size( $item, $sync );

				// Always allow the first pattern through, so a single oversized
				// design cannot leave the library looking empty.
				if ( $spent > 0 && ( $spent + $cost ) > $budget ) {
					$capped++;
					continue;
				}

				if ( $this->register_item( $item, $sync ) ) {
					$spent += $cost;
				}
			} catch ( \Throwable $e ) {
				Helper::log( 'block-patterns: skipped corrupt cache entry — ' . $e->getMessage() );
			}
		}

		if ( $capped > 0 ) {
			// Never silently truncate — say what was dropped and why.
			Helper::log( sprintf(
				'block-patterns: registered %s bytes of pattern content; %d pattern(s) held back by the %s-byte budget.',
				number_format_i18n( $spent ),
				$capped,
				number_format_i18n( $budget )
			) );
		}
	}

	/**
	 * Whether patterns register as lazy placeholders (thumbnail preview, content
	 * fetched on insert) rather than full markup.
	 *
	 * The eager path is kept intact behind this switch while the lazy one is
	 * being proven — flip to false to get the original behaviour back.
	 */
	public static function lazy_mode(): bool {
		return (bool) apply_filters( 'templately_block_patterns_lazy', true );
	}

	/**
	 * The placeholder markup a lazy pattern is made of: one templately/lazy-pattern
	 * block carrying the id and thumbnail. It never persists — on the canvas the
	 * block replaces itself with the real pattern.
	 */
	private function lazy_content( array $item ): string {
		$attrs = [
			'patternId'  => absint( $item['id'] ),
			'previewUrl' => esc_url_raw( $item['preview_url'] ?? '' ),
			'title'      => sanitize_text_field( $item['title'] ),
			// Drives the preview crop, and only that. Cloud thumbnails are a fixed
			// 900x1030 branded card: a page screenshot fills it, but a SECTION is a
			// centred strip on a solid background — a header occupies ~7% of the
			// height, so an uncropped tile is mostly padding.
			'kind'       => sanitize_key( $item['kind'] ?? '' ),
		];

		return '<!-- wp:templately/lazy-pattern ' . wp_json_encode( $attrs ) . ' /-->';
	}

	/**
	 * Search keywords. `keywords` is a real scoring field in the inserter's
	 * ranking (alongside name/title/description/category), so the catalog's own
	 * tags belong here — without them a search for "restaurant" cannot match a
	 * pattern tagged exactly that.
	 *
	 * @return string[]
	 */
	private function keywords_for( array $item ): array {
		$keywords = [ 'templately' ];

		foreach ( (array) ( $item['tags'] ?? [] ) as $tag ) {
			$tag = sanitize_text_field( (string) $tag );
			if ( '' !== $tag ) {
				$keywords[] = $tag;
			}
		}

		if ( ! empty( $item['category'] ) ) {
			$keywords[] = str_replace( '-', ' ', sanitize_key( $item['category'] ) );
		}

		return array_values( array_unique( $keywords ) );
	}

	private function content_size( $item, PatternSync $sync ): int {
		if ( ! is_array( $item ) || empty( $item['id'] ) ) {
			return 0;
		}

		$path = $sync->content_path( absint( $item['id'] ) );

		return file_exists( $path ) ? (int) filesize( $path ) : 0;
	}

	/**
	 * The pattern definition for one catalog item, in the shape the editor holds.
	 *
	 * Extracted from register_item() so a LIVE-SEARCHED item — which is never
	 * registered server-side, only injected into the editor's settings — is
	 * described by exactly the same code. Two copies of this would drift, and the
	 * drift would show up as search results that look or behave subtly unlike the
	 * registered catalog.
	 *
	 * @param array $item Catalog Item per data-model.md.
	 * @return array{name:string, title:string, categories:string[], keywords:string[], content:string, viewportWidth:int, description:string}
	 */
	public function describe_item( array $item ): array {
		$id       = absint( $item['id'] );
		$category = ! empty( $item['category'] ) ? sanitize_key( $item['category'] ) : 'general';

		$pattern = [
			'name'          => "templately/{$id}",
			'title'         => sanitize_text_field( $item['title'] ),
			'categories'    => $this->categories_for( $category ),
			'keywords'      => $this->keywords_for( $item ),
			'viewportWidth' => 1200,
			'description'   => sprintf(
				/* translators: %s: pattern title */
				__( '%s — from the Templately library', 'templately' ),
				sanitize_text_field( $item['title'] )
			),
			'content'       => $this->lazy_content( $item ),
		];

		$kind = $item['kind'] ?? 'section';
		if ( 'page' === $kind || 'post' === $kind ) {
			$pattern['blockTypes'] = [ 'core/post-content' ];
			$pattern['postTypes']  = [ $kind ];
		}

		return $pattern;
	}

	/**
	 * @param array $item Catalog Item per data-model.md.
	 * @return bool Whether a new pattern was registered by this call.
	 */
	private function register_item( $item, PatternSync $sync ): bool {
		if ( ! is_array( $item ) || empty( $item['id'] ) || empty( $item['title'] ) ) {
			return false;
		}

		$id   = absint( $item['id'] );
		$lazy = self::lazy_mode();

		// Eager mode needs the markup on disk; lazy mode needs nothing but a
		// thumbnail, so the whole catalog is registrable.
		$file_path = $sync->content_path( $id );
		if ( ! $lazy && ! file_exists( $file_path ) ) {
			return false; // content not synced yet — appears once the top-up runner lands it
		}

		$name = "templately/{$id}";
		if ( \WP_Block_Patterns_Registry::get_instance()->is_registered( $name ) ) {
			return false;
		}

		$category = ! empty( $item['category'] ) ? sanitize_key( $item['category'] ) : 'general';

		$pattern = [
			'title'         => sanitize_text_field( $item['title'] ),
			'categories'    => $this->categories_for( $category ),
			'keywords'      => $this->keywords_for( $item ),
			'viewportWidth' => 1200,
			'description'   => sprintf(
				/* translators: %s: pattern title */
				__( '%s — from the Templately library', 'templately' ),
				sanitize_text_field( $item['title'] )
			),
		];

		if ( $lazy ) {
			// A few hundred bytes instead of ~67KB: the inserter renders the
			// thumbnail, and the real markup is fetched only when inserted.
			$pattern['content'] = $this->lazy_content( $item );
		} else {
			$pattern['filePath'] = $file_path;
		}

		// Start-page/post chooser keys (spec 051 FR-005; research D7): page and
		// post template items surface in WordPress's native "choose a pattern"
		// modal for exactly their post type; sections carry neither key.
		$kind = $item['kind'] ?? 'section';
		if ( 'page' === $kind || 'post' === $kind ) {
			$pattern['blockTypes'] = [ 'core/post-content' ];
			$pattern['postTypes']  = [ $kind ];
		}

		return register_block_pattern( $name, $pattern );
	}

	/**
	 * Categories a pattern is filed under.
	 *
	 * TWO of them, deliberately (UI review 2026-07-28):
	 *
	 * 1. The matching CORE category when one exists — core already ships
	 *    `call-to-action`, `banner`, `header`, `testimonials` and friends. Filing
	 *    only into a parallel `templately-*` bucket meant a user browsing core's
	 *    "Call to action" saw none of ours, which defeats the point of being in
	 *    the native inserter at all.
	 * 2. One flat `templately` category, so the library is still browsable as a
	 *    set and the brand is visible in the sidebar.
	 *
	 * A catalog slug with no core equivalent falls back to its own
	 * `templately-{slug}` category so nothing is silently swallowed.
	 *
	 * @return string[]
	 */
	private function categories_for( string $category ): array {
		$this->ensure_brand_category();

		$categories = [ self::BRAND_CATEGORY ];

		$core = $this->core_category_for( $category );
		if ( null !== $core ) {
			$categories[] = $core;

			return $categories;
		}

		$own = "templately-{$category}";
		$this->ensure_own_category( $own, $category );
		$categories[] = $own;

		return $categories;
	}

	/**
	 * Map a catalog category slug onto a core pattern category, when core has an
	 * equivalent. Checked against the live registry rather than a hardcoded list
	 * so a theme-registered category counts too.
	 */
	private function core_category_for( string $category ): ?string {
		$registry = \WP_Block_Pattern_Categories_Registry::get_instance();

		// Keys are the slugs the LIVE catalog actually returns (verified against a
		// real sync, 2026-07-28): features-services, herobanner, info, blog,
		// header, contact, footer, product, archive, testimonials, cta,
		// team-member, gallery, faq, videos, subscription, pricing.
		// `null` = deliberately no core equivalent; keep our own bucket.
		// Slugs that already equal a core slug (header, footer, contact, gallery,
		// testimonials, videos) need no entry — they match directly.
		$aliases = apply_filters( 'templately_block_patterns_category_aliases', [
			'features-services' => 'services',
			'herobanner'        => 'banner',
			'hero'              => 'banner',
			'banners'           => 'banner',
			'blog'              => 'posts',
			'archive'           => 'query',
			'team-member'       => 'team',
			'cta'               => 'call-to-action',
			'headers'           => 'header',
			'footers'           => 'footer',
			'testimonial'       => 'testimonials',
			'service'           => 'services',
			'galleries'         => 'gallery',
			// No core equivalent — these keep a Templately-owned category.
			'info'              => null,
			'product'           => null,
			'faq'               => null,
			'subscription'      => null,
			'pricing'           => null,
			'landing'           => null,
		] );

		$slug = array_key_exists( $category, $aliases ) ? $aliases[ $category ] : $category;

		if ( null === $slug ) {
			return null;
		}

		return $registry->is_registered( $slug ) ? $slug : null;
	}

	private function ensure_brand_category(): void {
		if ( \WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( self::BRAND_CATEGORY ) ) {
			return;
		}

		register_block_pattern_category( self::BRAND_CATEGORY, [
			'label'       => __( 'Templately', 'templately' ),
			'description' => __( 'Ready-made sections and page templates from your Templately library.', 'templately' ),
		] );
	}

	/**
	 * Words `ucfirst()` gets wrong because they are acronyms, not words.
	 *
	 * Deliberately tiny and about ENGLISH, not about plugins — this is the same
	 * class of fix as sentence-casing, and unlike a namespace-to-plugin table it
	 * cannot go stale in a way that breaks behaviour. A catalog slug that is not
	 * listed here simply reads as it did before.
	 */
	const ACRONYMS = [ 'faq', 'cta', 'seo', 'ai' ];

	/**
	 * A catalog slug as a human label: `single-post` -> `Single post`,
	 * `faq` -> `FAQ`.
	 *
	 * Sentence case matches core's own labels ("Call to action", not "Call To
	 * Action") — `ucwords()` made ours read as foreign in the list.
	 */
	private function humanize( string $category ): string {
		$words = explode( '-', $category );

		foreach ( $words as $i => $word ) {
			$words[ $i ] = in_array( $word, self::ACRONYMS, true )
				? strtoupper( $word )
				: ( 0 === $i ? ucfirst( $word ) : $word );
		}

		return implode( ' ', $words );
	}

	/**
	 * Register the fallback category for a catalog slug core has no equivalent for.
	 *
	 * THE LABEL CARRIES THE BRAND, and that is not decoration. In the inserter
	 * these sit inside one long alphabetical list where an unattributed "Pricing"
	 * is merely vague. From WordPress 7.1 the Site Editor's Patterns screen builds
	 * its sidebar views from this same registry, so each one becomes a TOP-LEVEL
	 * entry sitting beside core's — and a dozen generic names appearing in a
	 * user's sidebar after a WordPress upgrade, with nothing saying where they
	 * came from or how to remove them, is a support problem we would be handing
	 * ourselves. Verified against the live catalog: twelve of these register
	 * (Slider, Info, Countdown, Pricing, Subscription, FAQ, Product, Single page,
	 * Single product, Landing page, Single post, Product archive).
	 *
	 * The brand category itself stays unprefixed — "Templately" needs no prefix.
	 */
	private function ensure_own_category( string $slug, string $category ): void {
		if ( \WP_Block_Pattern_Categories_Registry::get_instance()->is_registered( $slug ) ) {
			return;
		}

		register_block_pattern_category( $slug, [
			'label' => sprintf(
				/* translators: %s: pattern category name, e.g. "Pricing". */
				__( 'Templately: %s', 'templately' ),
				$this->humanize( $category )
			),
		] );
	}
}
