<?php

namespace Templately\Modules\ElementorKitSettings\Utils;

use Elementor\Core\Files\Fonts\Google_Font;
use Elementor\Plugin;

/**
 * Writes customizer-output color/font/logo assignments into the active
 * Elementor kit document, matching entries by `_id`. Updates BOTH the
 * `system_*` and `custom_*` buckets (`system_colors`/`custom_colors`,
 * `system_typography`/`custom_typography`) — Elementor's own default
 * swatches live in the `system_*` buckets, so a matching `_id` there is
 * updated too.
 *
 * Extracted from modules/settings/REST/Settings.php::update_elementor_settings()
 * — behavior byte-identical.
 */
class KitApplier {

	public static function apply( $_settings ) {
		$colors     = $_settings['colors'] ?? [];
		$typography = $_settings['typography'] ?? [];

		$kit = Plugin::$instance->kits_manager->get_active_kit();
		// `get_active_kit()` never returns falsy: with no valid kit it hands back an
		// EMPTY Kit instance (post id 0), and calling update_settings() on that throws
		// `Exception( 'Invalid post.' )` from Elementor's page settings manager —
		// validating post ID 0. `get_id()` is the check that means "a real kit".
		if ( $kit && $kit->get_id() ) {
			$settings               = $kit->get_settings();
			$settings['site_logo']  = $_settings['logoImage'];

			if ( ! empty( $colors ) ) {
				if ( ! empty( $settings['system_colors'] ) ) {
					foreach ( $settings['system_colors'] as $key => $color ) {
						$settings['system_colors'][ $key ]['color'] = $colors[ $color['_id'] ] ?? $color['color'];
					}
				}
				if ( ! empty( $settings['custom_colors'] ) ) {
					foreach ( $settings['custom_colors'] as $key => $color ) {
						$settings['custom_colors'][ $key ]['color'] = $colors[ $color['_id'] ] ?? $color['color'];
					}
				}
			}



			if ( ! empty( $typography ) ) {
				if ( ! empty( $settings['system_typography'] ) ) {
					foreach ( $settings['system_typography'] as $key => $typo ) {
						if ( ! empty( $typography[ $typo['_id'] ] ) ) {
							$settings['system_typography'][ $key ] = array_merge( $typo, $typography[ $typo['_id'] ] );
							if ( class_exists( 'Elementor\Core\Files\Fonts\Google_Font' ) && ! empty( $typo['typography_font_family'] ) ) {
								Google_Font::enqueue( $typo['typography_font_family'] );
							}
						}
					}
				}
				if ( ! empty( $settings['custom_typography'] ) ) {
					foreach ( $settings['custom_typography'] as $key => $typo ) {
						if ( ! empty( $typography[ $typo['_id'] ] ) ) {
							$settings['custom_typography'][ $key ] = array_merge( $typo, $typography[ $typo['_id'] ] );
							if ( class_exists( 'Elementor\Core\Files\Fonts\Google_Font' ) && ! empty( $typo['typography_font_family'] ) ) {
								Google_Font::enqueue( $typo['typography_font_family'] );
							}
						}
					}
				}
			}

			$kit->update_settings( $settings );
			$settings = $kit->get_settings();

			Plugin::$instance->files_manager->clear_cache();
			return $settings;
		}
	}
}
