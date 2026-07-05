<?php

namespace Templately\Core\Importer\Utils;

use Templately\Builder\Types\BaseTemplate;
use Templately\Utils\Helper;

class ElementorHelper extends ImportHelper {
	protected $content;

	protected $post_id;

	private $nav_menus = [];

	private $ea_post_widgets = [
		"eael-post-grid",
		"eael-post-list",
		"eael-post-timeline",
		"eael-content-timeline",
		"eael-dynamic-filterable-gallery",
		"eael-post-carousel",
		"eael-post-block",
		"eael-woo-product-carousel",
		"eael-woo-product-slider"
	];

	private $el_post_widgets = [
		'posts',
		'portfolio',
		'archive-posts',
		'woocommerce-products'
	];

	/**
	 * Source site URL of the pack being imported (from manifest `site`). Absolute
	 * links in Elementor data that point here are rewritten to this site on import.
	 *
	 * @var string
	 */
	private $source_url = '';

	/**
	 * Path component of the source site URL, un-trailing-slashed — i.e. the
	 * subdirectory/subsite prefix of a multisite demo (e.g. `/readspire`). Empty
	 * when the source site lives at the domain root. Used to strip the demo
	 * subdirectory off relative links before resolving their slug.
	 *
	 * @var string
	 */
	private $source_path = '';

	/**
	 * Map of the pack's ORIGINAL page slug => the imported page's permalink on THIS
	 * site, e.g. `[ 'about-us' => 'https://mysite.test/about-us-2/' ]`. Built by the
	 * Finalizer (where map_post_ids + permalinks are final) and pushed in via
	 * set_source_pages(). Keyed by the demo slug because that is what stored links
	 * still carry; the value reflects the imported slug even when WordPress changed
	 * it on conflict — which is the whole point of resolving by slug.
	 *
	 * @var array
	 */
	private $source_pages = [];

	/**
	 * Number of link URLs rewritten by the most recent replace_source_urls() call.
	 *
	 * @var int
	 */
	private $url_rewrite_count = 0;

	/**
	 * @param $template_json
	 * @param $template_settings
	 * @param $extra_content
	 *
	 * @return ElementorHelper
	 */
	public function prepare( $template_json, $template_settings, $extra_content = [], $request_params = []) {

		$extraContent = $extra_content;
		$_data        = [];
		$template_settings['data'] = $template_settings['data'] ?? [];
		foreach ( $template_settings['data'] as $type => $type_data ) {
			if ( empty( $type_data ) ) {
				continue;
			}

			if ( $type == 'nav_menus' ) {
				$this->nav_menus = $type_data;
			}

			if ( $type == 'form' || $type == 'post_type' ) {
				foreach ( $type_data as $plugin => $plugin_data ) {
					if ( $type == 'post_type' ) {
						$_data[ $plugin_data['id'] ] = [
							'type'  => $type,
							'query' => $plugin_data['query']
						];
					} else {
						foreach ( $plugin_data as $value ) {
							if ( empty( $value['settings'] ) ) {
								continue;
							}

							foreach ( $value['settings'] as $key => $v ) {
								if ( ! isset( $extraContent[ $plugin ] ) ) {
									continue;
								}
								$_data[ $value['id'] ][ $key ] = $extraContent[ $plugin ][ $value['id'] ];
							}
						}
					}
					// $this->sse_log('prepare', 'Preparing output for finalize, just a moment...' . $plugin, 1, 'eventLog');
				}
			}
			// $this->sse_log('prepare', 'Preparing output for finalize, just a moment...' . $type, 1, 'eventLog');
		}

		// $content = $template_json;
		$this->json_prepare( $template_json['content'], $_data, $request_params );
		$this->replace_source_urls( $template_json['content'] );
		$this->post_id                    = $this->map_post_ids[ $template_settings['post_id'] ];
		$this->content                    = $template_json;
		unset($template_settings['conditions']);
		$this->content['import_settings'] = $template_settings;

		return $this;
	}

	public function update() {
		/**
		 * @var BaseTemplate $template
		 */
		if(!empty($this->post_id)){
			$template = templately()->theme_builder::$templates_manager->get( $this->post_id );
			// $this->sse_log('update', 'Updating prepared data, just a moment...', 1, 'eventLog');
			$template->import( $this->content );
		}
		else{
			Helper::log(__("Templately Error code: T001", "templately"));
		}

		$this->nav_menus = [];
		$this->content   = [];
	}

	private function json_prepare( &$elements, $data, $request_params = []) {
		foreach ( $elements as &$element ) {
			if ( ! empty( $data ) ) {
				foreach ( $data as $id => $settings ) {
					if ( $element['id'] == $id ) {
						if ( isset( $settings['type'] ) && $settings['type'] == 'post_type' ) {
							$this->replace_query_data( $element, $settings['query'] );
						} else {
							foreach ( $settings as $key => $value ) {
								$element['settings'][ $key ] = $value;
							}
						}
						unset( $data[ $id ] );
					}
				}
			}

			/**
			 * Menu Update if needed.
			 */
			if ( ! empty( $this->nav_menus ) ) {
				$this->nav_menu_update( $element );
			}

			if ( ! empty( $element['elements'] ) ) {
				$this->json_prepare( $element['elements'], $data, $request_params );
			}
			else if(!empty($request_params['logo']) && isset($request_params['logo_size']) && $request_params['logo_size'] && $element['elType'] === "widget" && $element['widgetType'] === "tl-site-logo"){
				$element['settings']['width'] = [
					'size' => $request_params['logo_size'],
					'unit' => 'px'
				];
				$element['settings']['max-width'] = [
					'size' => '100',
					'unit' => '%'
				];
				$this->sse_log('prepare', 'Site Logo', 1, 'eventLog');
			}

			// $this->sse_log('prepare', 'Preparing output for finalize, just a moment..Preparing output for finalize.', 1, 'eventLog');
		}
	}

	private function nav_menu_update( &$element ) {
		// $this->sse_log('nav-menu', 'Updating nav menus, just a moment...', 1, 'eventLog');
		if ( ! isset( $element['widgetType'] ) ) {
			return;
		}

		switch ( $element['widgetType'] ) {
			case 'eael-simple-menu':
				if(!empty($element['settings']['eael_simple_menu_menu']) && isset($this->map_term_ids[ $element['settings']['eael_simple_menu_menu'] ])){
					$element['settings']['eael_simple_menu_menu'] = $this->map_term_ids[ $element['settings']['eael_simple_menu_menu'] ];
				}
				break;
			case 'eael-advanced-menu':
				if(!empty($element['settings']['eael_advanced_menu_menu']) && isset($this->map_term_ids[ $element['settings']['eael_advanced_menu_menu'] ])){
					$element['settings']['eael_advanced_menu_menu'] = $this->map_term_ids[ $element['settings']['eael_advanced_menu_menu'] ];
				}
				break;
		}
	}

	/**
	 * Set the source site URL (pack origin) used by replace_source_urls().
	 *
	 * @param string $url Usually the manifest `site` value.
	 *
	 * @return self
	 */
	public function set_source_url( $url ) {
		$this->source_url = is_string( $url ) ? $url : '';

		// Demo subdirectory/subsite prefix (e.g. "/readspire" for a subdirectory
		// multisite demo). Stripped off relative links before slug resolution.
		$path              = '' !== $this->source_url ? (string) wp_parse_url( $this->source_url, PHP_URL_PATH ) : '';
		$this->source_path = untrailingslashit( $path );

		return $this;
	}

	/**
	 * Provide the ORIGINAL-slug => imported-permalink map used to resolve links to
	 * the actual imported page (see $source_pages). Without it, replace_source_urls()
	 * still works — it just falls back to the plain home_url() domain swap.
	 *
	 * @param array $map original page slug (lowercase) => permalink on this site.
	 *
	 * @return self
	 */
	public function set_source_pages( $map ) {
		$this->source_pages = is_array( $map ) ? $map : [];

		return $this;
	}

	/**
	 * Number of link URLs the last replace_source_urls() call rewrote.
	 *
	 * @return int
	 */
	public function get_url_rewrite_count() {
		return $this->url_rewrite_count;
	}

	/**
	 * Rewrite links that point at the imported pack's source site so they point at
	 * the matching imported page on THIS site instead.
	 *
	 * Walks the Elementor element tree — child `elements` AND each element's
	 * `settings` recursively, so repeater rows like a Mega Menu's `menu_items[*]`
	 * (and EA Info Box / Button link controls) are all covered — and rewrites every
	 * link-control `url` that originates from the source site.
	 *
	 * For each such link the URL is first reduced to a path relative to the source
	 * site, covering all three forms a demo produces:
	 *  - absolute / protocol-relative pointing at the source host
	 *    (`https://demo.site/readspire/about-us`);
	 *  - root-relative carrying the demo's subdirectory/subsite prefix
	 *    (`/readspire/about-us` — common on subdirectory-multisite demos);
	 *  - already-local (a prior pass turned it into `home_url()/about-us`).
	 * Its last path segment is taken as the page slug, then:
	 *  1. if that slug is in the source_pages map → the link becomes the imported
	 *     page's real permalink (slug-change-proof: get_permalink() reflects the
	 *     post_name WordPress actually assigned, even after a conflict rename);
	 *  2. otherwise it falls back to the plain home_url() domain swap.
	 *
	 * Matching is:
	 *  - scheme-insensitive — http/https (or protocol-relative) drift still matches;
	 *  - boundary-safe — the source host/subdirectory must end at a `/`, `?`, `#` or
	 *    end-of-string, so `example.com` never matches `example.com.evil.com` and the
	 *    `/readspire` prefix never eats `/readspire-blog`;
	 *  - foreign-link-safe — absolute URLs on other domains are left alone, even if
	 *    their last segment happens to collide with an imported slug;
	 *  - idempotent — a link already resolved to a local permalink is left untouched
	 *    on a second pass.
	 *
	 * @param array       $elements   Elementor `content` array (by reference, mutated in place).
	 * @param string|null $source_url Override source URL; defaults to set_source_url().
	 *
	 * @return int Number of URLs rewritten.
	 */
	public function replace_source_urls( &$elements, $source_url = null ) {
		$this->url_rewrite_count = 0;

		$source_url = null !== $source_url ? $source_url : $this->source_url;

		if ( empty( $source_url ) || ! is_array( $elements ) ) {
			return 0;
		}

		// Scheme-relative forms ("//host/path") so http/https drift still matches.
		$source_sr   = $this->scheme_relative( $source_url );
		$target_base = untrailingslashit( home_url() );

		if ( '' === $source_sr || '//' === $source_sr ) {
			return 0;
		}

		// Demo subdirectory prefix (e.g. "/readspire"); honoured even when the source
		// URL is passed in here rather than via set_source_url().
		$source_path = null !== $source_url
			? untrailingslashit( (string) wp_parse_url( $source_url, PHP_URL_PATH ) )
			: $this->source_path;

		$this->rewrite_elements_urls( $elements, $source_sr, $target_base, $source_path );

		return $this->url_rewrite_count;
	}

	/**
	 * Strip the http(s) scheme from a URL, returning its scheme-relative form
	 * ("//host/path") with any trailing slash removed.
	 *
	 * @param string $url
	 *
	 * @return string
	 */
	private function scheme_relative( $url ) {
		$url = untrailingslashit( (string) $url );
		$url = preg_replace( '#^https?:#i', '', $url );

		if ( '' === $url ) {
			return '';
		}

		// Normalise a bare "host/path" (no scheme at all) to "//host/path".
		return strpos( $url, '//' ) === 0 ? $url : '//' . ltrim( $url, '/' );
	}

	private function rewrite_elements_urls( &$elements, $source_sr, $target_base, $source_path ) {
		foreach ( $elements as &$element ) {
			if ( ! is_array( $element ) ) {
				continue;
			}

			if ( ! empty( $element['settings'] ) && is_array( $element['settings'] ) ) {
				$this->rewrite_settings_urls( $element['settings'], $source_sr, $target_base, $source_path );
			}

			if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
				$this->rewrite_elements_urls( $element['elements'], $source_sr, $target_base, $source_path );
			}
		}
		unset( $element );
	}

	private function rewrite_settings_urls( &$node, $source_sr, $target_base, $source_path ) {
		// Elementor link controls carry a `url` string ({ url, is_external, nofollow,
		// custom_attributes } — or, for some controls, just { url }). Media/attachment
		// controls also carry a `url`, but always alongside an `id`/`source`; excluding
		// those leaves images untouched while still covering link controls that omit
		// `is_external`.
		if ( isset( $node['url'] ) && is_string( $node['url'] )
			&& ! isset( $node['id'] ) && ! isset( $node['source'] ) ) {
			$node['url'] = $this->swap_source_url( $node['url'], $source_sr, $target_base, $source_path );
		}

		foreach ( $node as &$value ) {
			if ( is_array( $value ) ) {
				$this->rewrite_settings_urls( $value, $source_sr, $target_base, $source_path );
			}
		}
		unset( $value );
	}

	/**
	 * Rewrite a single link URL from the source site to the matching imported page
	 * on this site. Returns the URL unchanged when it does not originate from the
	 * source site, so foreign links, mailto:/tel:/#fragment links and already-local
	 * links are safe.
	 *
	 * @param string $url         The control URL to (maybe) rewrite.
	 * @param string $source_sr   Scheme-relative source base ("//host/subdir").
	 * @param string $target_base This site's base (home_url(), with scheme, no trailing slash).
	 * @param string $source_path Demo subdirectory prefix ("/readspire") or '' at root.
	 *
	 * @return string
	 */
	private function swap_source_url( $url, $source_sr, $target_base, $source_path ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		$stripped  = preg_replace( '#^https?:#i', '', $url );
		$target_sr = $this->scheme_relative( $target_base );

		// (1) Absolute / protocol-relative URL pointing at the SOURCE host (+subdir).
		if ( strpos( $stripped, $source_sr ) === 0 ) {
			$rest = substr( $stripped, strlen( $source_sr ) );

			// Boundary so "//example.com" never matches "//example.com.evil.com".
			if ( ! $this->is_url_boundary( $rest ) ) {
				return $url;
			}

			return $this->resolve_rest( $rest, $target_base, true, $url );
		}

		// (2) Absolute URL already on THIS site (e.g. a prior pass rewrote it) —
		// only slug-resolve (to fix a changed slug); never blind-swap it again.
		if ( strpos( $stripped, $target_sr ) === 0 ) {
			$rest = substr( $stripped, strlen( $target_sr ) );

			if ( ! $this->is_url_boundary( $rest ) ) {
				return $url;
			}

			return $this->resolve_rest( $rest, $target_base, false, $url );
		}

		// (3) Root-relative path ("/...", not protocol-relative "//...").
		if ( '/' === $url[0] && ( ! isset( $url[1] ) || '/' !== $url[1] ) ) {
			// Carries the demo subdirectory prefix → strip it (boundary-safe) and
			// treat as a source link so an unmatched slug still loses the prefix.
			if ( '' !== $source_path && strpos( $url, $source_path ) === 0
				&& $this->is_url_boundary( substr( $url, strlen( $source_path ) ) ) ) {
				return $this->resolve_rest( substr( $url, strlen( $source_path ) ), $target_base, true, $url );
			}

			// Plain site-relative path (no demo prefix) — already resolves to this
			// host, so only slug-resolve; leave it untouched if there is no match.
			return $this->resolve_rest( $url, $target_base, false, $url );
		}

		// Foreign absolute URL, mailto:/tel:/#fragment, etc. — leave untouched.
		return $url;
	}

	/**
	 * Resolve a source-relative path ("/about-us", "/about-us?x=1#y") to a final URL.
	 * Looks up the path's last segment in the source_pages slug map first; on a miss
	 * either domain-swaps to home_url() ($blind_fallback) or returns the URL as-is.
	 *
	 * @param string $rest           Path relative to the source/target origin (leading "/").
	 * @param string $target_base     This site's base (home_url(), no trailing slash).
	 * @param bool   $blind_fallback  When no slug matches, swap to $target_base . $rest.
	 * @param string $original_url    The URL to return when nothing applies.
	 *
	 * @return string
	 */
	private function resolve_rest( $rest, $target_base, $blind_fallback, $original_url ) {
		// Split off ?query / #fragment so they can be carried onto the permalink.
		$cut    = strcspn( $rest, '?#' );
		$path   = substr( $rest, 0, $cut );
		$suffix = substr( $rest, $cut );

		// Last non-empty path segment = the page slug (post_name).
		$segments = array_filter( explode( '/', $path ), 'strlen' );
		$slug     = empty( $segments ) ? '' : strtolower( (string) end( $segments ) );

		if ( '' !== $slug && isset( $this->source_pages[ $slug ] ) ) {
			$this->url_rewrite_count++;

			// Permalink keeps its own trailing slash; ?query/#fragment (if any) ride along.
			return '' === $suffix
				? $this->source_pages[ $slug ]
				: untrailingslashit( $this->source_pages[ $slug ] ) . $suffix;
		}

		if ( $blind_fallback ) {
			$this->url_rewrite_count++;

			return $target_base . $rest;
		}

		return $original_url;
	}

	/**
	 * True when $rest is empty or starts at a URL path/query/fragment boundary.
	 *
	 * @param string $rest
	 *
	 * @return bool
	 */
	private function is_url_boundary( $rest ) {
		return '' === $rest || strpos( '/?#', $rest[0] ) !== false;
	}

	private function replace_query_data( &$element, $data ) {
		// $this->sse_log('query', 'Finalizing query data, just a moment...', 1, 'eventLog');
		if ( ! empty( $element['widgetType'] ) ) {
			if ( in_array( $element['widgetType'], $this->ea_post_widgets ) ) {
				if ( ! empty( $data['tax_query'] ) ) {
					foreach ( $data['tax_query'] as $key => $tax_query ) {
						if ( $key === 'relation' ) {
							continue;
						}
						if ( isset( $element['settings'][ $tax_query['taxonomy'] . '_ids' ] ) ) {
							$new_ids = [];
							foreach ( $element['settings'][ $tax_query['taxonomy'] . '_ids' ] as $id ) {
								if ( isset( $this->map_term_ids[ $id ] ) ) {
									$new_ids[] = $this->map_term_ids[ $id ];
								}
							}
							$element['settings'][ $tax_query['taxonomy'] . '_ids' ] = $new_ids;
						}
					}
				}
				if ( ! empty( $element['settings']['authors'] ) ) {
					$element['settings']['authors'] = [ get_current_user_id() ];
				}
			}
			if ( in_array( $element['widgetType'], $this->el_post_widgets ) ) {
				if ( ! empty( $data['tax_query'] ) ) {
					$this->replace_term_ids( $element, [
						'posts_include_term_ids',
						'posts_exclude_term_ids',
						'query_include_term_ids',
						'query_exclude_term_ids',
					] );

				}
				if ( ! empty( $data['author__in'] ) ) {
					$keys = [ 'posts_include_authors', 'query_include_authors' ];
					foreach ( $keys as $key ) {
						if ( isset( $element['settings'][ $key ] ) ) {
							$element['settings'][ $key ] = [ get_current_user_id() ];
						}
					}
				}
			}
			if ( $element['widgetType'] == 'eael-woo-product-gallery' ) {
				$this->replace_term_ids( $element, [ 'eael_product_gallery_categories', 'eael_product_gallery_tags' ] );

			}
			if ( $element['widgetType'] == 'eicon-woocommerce' ) {
				$this->replace_term_ids( $element, [ 'eael_product_grid_categories' ] );
			}
		}
	}

	private function replace_term_ids( &$element, $keys ) {
		foreach ( $keys as $key ) {
			// $this->sse_log('terms', 'Finalizing term ids, just a moment...', 1, 'eventLog');
			if ( ! empty( $element['settings'][ $key ] ) ) {
				$new_ids = [];
				foreach ( $element['settings'][ $key ] as $id ) {
					if ( isset( $this->map_term_ids[ $id ] ) ) {
						$new_ids[] = $this->map_term_ids[ $id ];
					}
				}
				if(!empty($new_ids)){
					$element['settings'][ $key ] = $new_ids;
				}
			}

		}
	}

	public function parse_images($post_content) {
		$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
		error_log('parse_images is getting called from ElementorHelper for unknown reasons' . print_r($backtrace, true));

		return [];
	}
}