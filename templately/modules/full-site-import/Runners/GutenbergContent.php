<?php

namespace Templately\Modules\FullSiteImport\Runners;

use Templately\Modules\ThemeBuilder\PageTemplates;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Utils\Helper;
use WP_Error;

/**
 * @property \Templately\Modules\GutenbergIntegration\Utils\GutenbergHelper $json
 */
class GutenbergContent extends BaseRunner {

	public function get_name(): string {
		return 'content';
	}

	public function get_label(): string {
		return __( 'Block Editor Content', 'templately' );
	}

	public function should_run( $data, $imported_data = [] ): bool {
		return $this->platform == 'gutenberg' && ! empty( $this->manifest['content'] );
	}

	public function should_log(): bool {
		return true;
	}

	public function get_action(): string {
		return 'updateLog';
	}

	public function log_message(): string {
		return __( 'Importing Gutenberg Page and Post Templates', 'templately' );
	}

	/**
	 * Lay the customizer's typography OVER the pack's, entry by entry.
	 *
	 * This used to be a wholesale replace guarded by `! empty()`. The wizard sends
	 * `{"custom":{}}` for a Gutenberg import whose typography nobody touched, and an
	 * array holding one empty key is not empty — so the pack's whole set (h1, body,
	 * button and every `ebcustomtypo_*` entry) was replaced by nothing. Essential
	 * Blocks then emitted no `--ebcustomtypo_*` variables, its headings fell back to
	 * the wrapper's `line-height: 0`, and every wrapped heading on the imported site
	 * drew its lines on top of each other.
	 *
	 * So: the pack is the base, and the customizer can only ADD or OVERRIDE. An entry
	 * it does not mention survives; a field it sends as null (the denormalizer emits
	 * null for every property the user left alone) does not erase the pack's value.
	 * The Elementor runner has always merged this way.
	 *
	 * @param array $pack     The pack's `global_typography`.
	 * @param mixed $incoming The customizer's typography, as sent.
	 * @return array
	 */
	public static function merge_typography( array $pack, $incoming ): array {
		if ( ! is_array( $incoming ) ) {
			return $pack;
		}

		$overlay = function ( $base, $entry ) {
			if ( ! is_array( $entry ) ) {
				return $base;
			}
			$entry = array_filter( $entry, function ( $value ) {
				return null !== $value;
			} );
			if ( empty( $entry ) ) {
				return $base;
			}
			return is_array( $base ) ? array_merge( $base, $entry ) : $entry;
		};

		foreach ( $incoming as $key => $entry ) {
			if ( 'custom' === $key ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$custom = isset( $pack['custom'] ) && is_array( $pack['custom'] ) ? $pack['custom'] : [];
				foreach ( $entry as $id => $custom_entry ) {
					$merged = $overlay( $custom[ $id ] ?? null, $custom_entry );
					if ( null !== $merged ) {
						$custom[ $id ] = $merged;
					}
				}
				if ( ! empty( $custom ) ) {
					$pack['custom'] = $custom;
				}
				continue;
			}

			$merged = $overlay( $pack[ $key ] ?? null, $entry );
			if ( null !== $merged ) {
				$pack[ $key ] = $merged;
			}
		}

		return $pack;
	}

	public function import( $data, $imported_data ): array {
		$contents = $this->manifest['content'];

		// Log start of import
		if(!$this->is_key_processed('started', 'import')){
			$this->log( 0 );
			$this->mark_key_processed('started', 'import');
		}

		if(isset($this->manifest['has_settings']) && $this->manifest['has_settings'] && !$this->is_key_processed('global_colors', 'settings')){
			$file     = $this->dir_path . "settings.json";
			$settings = Utils::read_json_file( $file );

			if(!empty($data['color'])){
				if (isset($settings['global_colors'])) {
					foreach ($settings['global_colors'] as $key => $color) {
						$settings['global_colors'][$key]['color'] = $data['color'][$color['var']] ?? $color['color'];
					}
				}

				if (isset($settings['custom_colors'])) {
					foreach ($settings['custom_colors'] as $key => $color) {
						$settings['custom_colors'][$key]['color'] = $data['color'][$color['var']] ?? $color['color'];
					}
				}
			}
			// `logo` arrives either as a bare url string or as an { id, url } array,
			// and upload_logo() takes a URL — handing it the array made
			// wp_http_validate_url() reject it, so a remote logo never imported.
			$logo_url = Utils::get_logo_url($data['logo'] ?? null);
			$logo_id  = is_array($data['logo'] ?? null) ? ($data['logo']['id'] ?? 0) : 0;

			if(!empty($logo_id)){
				$site_logo_id = $logo_id;
				$settings['site_logo'] = $site_logo_id;
				Utils::update_option( 'site_logo', $site_logo_id );
				$this->origin->update_imported_list('attachment', $logo_id);
			}
			else if (!empty($logo_url) && strpos($logo_url, 'data:image/') === 0) {
				// Upload base64 data URL
				$site_logo = Utils::upload_logo_base64($logo_url, $this->session_id);

				if(!empty($site_logo['id'])){
					$settings['site_logo'] = $site_logo['id'];
					Utils::update_option( 'site_logo', $site_logo['id'] );
					$this->origin->update_imported_list('attachment', $site_logo['id']);
				}
			}
			else if(!empty($logo_url)){
				// Upload remote URL
				$site_logo = Utils::upload_logo($logo_url, $this->session_id);
				if(!empty($site_logo['id'])){
					$settings['site_logo'] = $site_logo['id'];
					Utils::update_option( 'site_logo', $site_logo['id'] );
					$this->origin->update_imported_list('attachment', $site_logo['id']);
				}
			}


			$typography = self::merge_typography(
				isset( $settings['global_typography'] ) && is_array( $settings['global_typography'] ) ? $settings['global_typography'] : [],
				$data['typography'] ?? null
			);
			// Never invent the key: a pack with no typography and a customizer that sent
			// none must store what it always stored — nothing.
			if ( ! empty( $typography ) ) {
				$settings['global_typography'] = $typography;
			}

			$settings = array_map('json_encode', $settings);

			// Save the settings to the 'eb_global_styles' option
			Utils::update_option('eb_global_styles', $settings);

			$this->mark_key_processed('global_colors', 'settings');
		}

		$processed = 0;
		$total     = array_reduce($contents, function($carry, $item) {
			return $carry + count($item);
		}, 0);

		$results = $this->loop( $contents, function($type, $posts, $results ) use($total) {
			// Inner loop accumulates results for this type
			$inner_result = $this->loop( $posts, function($id, $settings, $result ) use($type, $results, $total) {
				$path     = $this->dir_path . 'content' . DIRECTORY_SEPARATOR;

				$import = $this->import_page_content( $id, $type, $path, $settings );

				if ( ! $import ) {
					$result[ $type ]['failed'][ $id ] = $import;
				} else {
					Utils::import_page_settings( $import['id'], $settings );
					$result[ $type ]['succeed'][ $id ] = $import['id'];
				}

				// Broadcast Log - merge with outer results for accurate counting
				$merged = array_merge($results, $result);
				$processed = 0;
				foreach ($merged as $t => $data) {
					if (is_array($data) && $t !== '__attachments') {
						$processed += count($data['succeed'] ?? []) + count($data['failed'] ?? []);
					}
				}
				$progress = $total > 0 ? floor( ( 100 * $processed ) / $total ) : 100;
				$this->log( $progress );

				$result['__attachments'][$type][ $id ] = isset($import['__attachments']) ? $import['__attachments'] : [];

				return $result;
			}, $type);

			// Non-recursive merge of inner result with outer results
			$results = array_merge($results, $inner_result);
			return $results;
		});

		return [ 'content' => $results ];
	}

	/**
	 * @param $id
	 * @param $type
	 * @param $path
	 * @param $settings
	 *
	 * @return false|int|void|WP_Error
	 */
	private function import_page_content( $id, $type, $path, $settings ) {
		try {
			$json_content = Utils::read_json_file( $path . '/' . $type . '/' . $id . '.json' );
			if ( ! empty( $json_content ) ) {

				/**
				 * TODO:
				 *
				 * We can check if there is any data for settings.
				 * if yes: ignore content from insert.
				 *
				 * Process the content while finalizing.
				 */

				$post_data = [
					'post_title'    => $json_content['title'] ?? ucfirst( $type ) . ' - (by Templately)',
					'post_status'   => 'publish',
					'post_type'     => $type,
					'post_content'  => wp_slash( $json_content['content'] ),
					'page_template' => PageTemplates::TEMPLATE_HEADER_FOOTER
				];
				$inserted  = wp_insert_post( $post_data );

				if ( is_wp_error( $inserted ) ) {
					return false;
				}

				$attachments = $this->json->parse_images($json_content['content']);

				$result = [];
				if (!empty($attachments)) {
					$result['__attachments'] = $attachments;
				}
				$result['id'] = $inserted;

				return $result;
			}
		} catch ( \Exception $e ) {
			return false;
		}
	}

}