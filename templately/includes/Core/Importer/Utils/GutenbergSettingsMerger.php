<?php

namespace Templately\Core\Importer\Utils;

/**
 * Merges Essential Blocks global styles (colors & typography)
 * into Gutenberg block content, resolving CSS variable references
 * and global font source references.
 *
 * Ported from templately-exporter GutenbergExporter::merge_settings(),
 * replace_colors(), and replace_fonts().
 *
 * @since 3.x
 */
class GutenbergSettingsMerger {

	/**
	 * Global color entries: each has 'var' and 'color' keys.
	 * @var array
	 */
	private $colors = [];

	/**
	 * Global font entries keyed by font ID / tag name.
	 * @var array
	 */
	private $fonts = [];

	/**
	 * Font attribute names to copy when resolving a global font.
	 * @var string[]
	 */
	private $font_attrs = [
		'fontFamily',
		'fontWeight',
		'textTransform',
		'textDecoration',
		'fontSize',
		'fontSizeUnit',
		'lineHeight',
		'lineHeightUnit',
		'letterSpacing',
		'letterSpacingUnit',
	];

	/**
	 * Responsive font attr names (also need TAB/MOB prefixes).
	 * @var string[]
	 */
	private $responsive_font_attrs = [
		'fontSize',
		'fontSizeUnit',
		'lineHeight',
		'lineHeightUnit',
		'letterSpacing',
		'letterSpacingUnit',
	];

	/**
	 * Merge EB global styles into serialized Gutenberg block content.
	 *
	 * @param string $block_content Serialized block content (post_content format).
	 * @param array  $settings      The EB global styles (keyed by setting type,
	 *                              values are already decoded arrays matching
	 *                              what GutenbergExporter::export() writes).
	 *
	 * @return string Modified block content with globals resolved.
	 */
	public static function merge( string $block_content, array $settings ): string {
		$instance = new self();
		$instance->collect_globals( $settings );

		// 1. Replace CSS color variables in the serialized content
		$block_content = $instance->replace_colors( $block_content );

		// 2. Resolve global font sources in block attrs
		if ( function_exists( 'parse_blocks' ) && function_exists( 'serialize_blocks' ) ) {
			$blocks = parse_blocks( $block_content );
			$instance->replace_fonts( $blocks );
			$block_content = serialize_blocks( $blocks );
		}

		return $block_content;
	}

	/**
	 * Build $colors and $fonts from the settings array.
	 */
	private function collect_globals( array $settings ): void {
		$color_keys = [ 'gradient_colors', 'custom_gradient_colors', 'custom_colors', 'global_colors' ];

		foreach ( $color_keys as $key ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$this->colors = array_merge( $this->colors, $settings[ $key ] );
			}
		}

		if ( ! empty( $settings['global_typography'] ) ) {
			foreach ( $settings['global_typography'] as $k => $v ) {
				if ( $k === 'custom' ) {
					// Custom entries are keyed by their ID
					foreach ( $v as $k2 => $v2 ) {
						$this->fonts[ $k2 ] = $v2;
					}
				} else {
					$this->fonts[ $k ] = $v;
				}
			}
		}
	}

	/**
	 * Replace CSS variable references (e.g. var(--eb-global-...)) with
	 * their actual color values.
	 */
	private function replace_colors( string $content ): string {
		foreach ( $this->colors as $color ) {
			if ( ! empty( $color['var'] ) && ! empty( $color['color'] ) ) {
				$content = str_replace( 'var(' . $color['var'] . ')', $color['color'], $content );
			}
		}

		return $content;
	}

	/**
	 * Walk parsed blocks recursively and resolve global font sources.
	 */
	private function replace_fonts( array &$blocks ): void {
		foreach ( $blocks as &$block ) {
			if ( ! empty( $block['attrs'] ) ) {
				foreach ( $block['attrs'] as $attr => $value ) {
					if ( $this->has_global_fonts( $attr, $value ) ) {
						$font_id = str_replace( 'global:', '', $block['attrs'][ $attr ] );
						$tag_name = $block['attrs']['tagName'] ?? '';
						$block['attrs'] = array_merge(
							$block['attrs'],
							$this->prepare_font_attrs( $font_id, $attr, $tag_name )
						);
						$block['attrs'][ $attr ] = 'custom';
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) ) {
				$this->replace_fonts( $block['innerBlocks'] );
			}
		}
	}

	/**
	 * Determine if an attribute is a global font source reference.
	 */
	private function has_global_fonts( string $attr, $value ): bool {
		return str_contains( $attr, 'FontSource' ) && is_string( $value ) && str_contains( $value, 'global:' );
	}

	/**
	 * Build the concrete font attribute array for a given global font ID.
	 */
	private function prepare_font_attrs( string $font_id, string $attr, string $tag_name ): array {
		$styles = [];
		$prefix = str_replace( 'FontSource', '', $attr );

		if ( $font_id === 'global' ) {
			if ( ! empty( $tag_name ) && isset( $this->fonts[ $tag_name ] ) ) {
				$styles = $this->fonts[ $tag_name ];
				// If the font family is "Default", fall back to allHeadings
				if ( isset( $styles['fontFamily'] ) && trim( strtolower( $styles['fontFamily'] ) ) === 'default' ) {
					$styles['fontFamily'] = $this->fonts['allHeadings']['fontFamily'] ?? $styles['fontFamily'];
				}
			} elseif ( isset( $this->fonts['body'] ) ) {
				$styles = $this->fonts['body'];
			}
		} elseif ( isset( $this->fonts[ $font_id ] ) ) {
			$styles = $this->fonts[ $font_id ];
		}

		if ( empty( $styles ) ) {
			return [];
		}

		$prepared = [];

		foreach ( $this->font_attrs as $font_attr ) {
			if ( ! empty( $styles[ $font_attr ] ) ) {
				$prepared[ $prefix . ucfirst( $font_attr ) ] = $styles[ $font_attr ];
			}
		}

		foreach ( $this->responsive_font_attrs as $font_attr ) {
			if ( ! empty( $styles[ 'TAB' . $font_attr ] ) ) {
				$prepared[ 'TAB' . $prefix . ucfirst( $font_attr ) ] = $styles[ 'TAB' . $font_attr ];
			}
			if ( ! empty( $styles[ 'MOB' . $font_attr ] ) ) {
				$prepared[ 'MOB' . $prefix . ucfirst( $font_attr ) ] = $styles[ 'MOB' . $font_attr ];
			}
		}

		return $prepared;
	}
}
