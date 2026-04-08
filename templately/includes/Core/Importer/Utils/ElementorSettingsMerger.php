<?php

namespace Templately\Core\Importer\Utils;

/**
 * Merges Elementor global kit settings (colors & typography)
 * into template element data, resolving __globals__ references.
 *
 * Ported from templately-exporter ElementorExporter::merge_global_settings()
 * and ElementorExporter::replace_global_settings().
 *
 * @since 3.x
 */
class ElementorSettingsMerger {

	/**
	 * @var array
	 */
	private $colors = [];

	/**
	 * @var array
	 */
	private $fonts = [];

	/**
	 * Merge global kit settings into a template's elements data.
	 *
	 * @param array $elements_data The template content (array of elements).
	 * @param array $settings      The kit settings array (system_colors, custom_colors, system_typography, custom_typography).
	 *
	 * @return array Modified elements data with globals resolved.
	 */
	public static function merge( array $elements_data, array $settings ): array {
		$instance = new self();
		$instance->collect_globals( $settings );
		$instance->replace_global_settings( $elements_data );

		return $elements_data;
	}

	/**
	 * Collect colors and fonts from kit settings.
	 */
	private function collect_globals( array $settings ): void {
		$keys = [ 'system_colors', 'custom_colors', 'system_typography', 'custom_typography' ];

		foreach ( $keys as $key ) {
			if ( empty( $settings[ $key ] ) ) {
				continue;
			}

			if ( str_contains( $key, 'colors' ) ) {
				$this->colors = array_merge( $this->colors, $settings[ $key ] );
			} else {
				$this->fonts = array_merge( $this->fonts, $settings[ $key ] );
			}
		}
	}

	/**
	 * Walk elements tree and replace __globals__ references with actual values.
	 */
	private function replace_global_settings( array &$elements_data ): void {
		foreach ( $elements_data as &$element ) {
			if ( ! empty( $element['settings'] ) && ! empty( $element['settings']['__globals__'] ) ) {
				foreach ( $element['settings']['__globals__'] as $key => $setting ) {
					if ( $this->is_color_setting( $setting ) ) {
						$this->replace_color( $element['settings'], $key );
					}
					if ( $this->is_typography_setting( $setting ) ) {
						$this->replace_fonts( $element['settings'], $key );
					}
				}
			}

			if ( ! empty( $element['elements'] ) ) {
				$this->replace_global_settings( $element['elements'] );
			}
		}
	}

	private function replace_color( array &$settings, string $key ): void {
		$color = $this->get_color(
			str_replace( 'globals/colors?id=', '', trim( $settings['__globals__'][ $key ] ) )
		);

		if ( ! empty( $color ) ) {
			$settings[ $key ] = $color;
			unset( $settings['__globals__'][ $key ] );
		}
	}

	private function replace_fonts( array &$settings, string $key ): void {
		$font = $this->get_font(
			str_replace( 'globals/typography?id=', '', trim( $settings['__globals__'][ $key ] ) )
		);

		if ( ! empty( $font ) ) {
			unset( $font['_id'], $font['title'] );
			unset( $settings['__globals__'][ $key ] );
			$settings = array_merge( $settings, $font );
		}
	}

	private function get_color( string $id ): ?string {
		foreach ( $this->colors as $color ) {
			if ( isset( $color['_id'] ) && $color['_id'] === $id ) {
				return $color['color'] ?? null;
			}
		}

		return null;
	}

	private function get_font( string $id ): ?array {
		foreach ( $this->fonts as $font ) {
			if ( isset( $font['_id'] ) && $font['_id'] === $id ) {
				return $font;
			}
		}

		return null;
	}

	private function is_color_setting( string $setting ): bool {
		return str_contains( $setting, 'globals/colors' );
	}

	private function is_typography_setting( string $setting ): bool {
		return str_contains( $setting, 'globals/typography' );
	}
}
