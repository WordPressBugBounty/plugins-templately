<?php

namespace Templately\Core\Importer\Runners;

use Templately\Builder\PageTemplates;
use Templately\Core\Importer\Utils\Utils;
use Templately\Utils\Helper;
use WP_Error;

/**
 * @property GutenbergHelper $json
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
			if(!empty($data['logo']['id'])){
				$site_logo_id = $data['logo']['id'];
				$settings['site_logo'] = $site_logo_id;
				Utils::update_option( 'site_logo', $site_logo_id );
				$this->origin->update_imported_list('attachment', $data['logo']['id']);
			}
			else if (!empty($data['logo']['url']) && strpos($data['logo']['url'], 'data:image/') === 0) {
				// Upload base64 data URL
				$site_logo = Utils::upload_logo_base64($data['logo']['url'], $this->session_id);

				if(!empty($site_logo['id'])){
					$settings['site_logo'] = $site_logo['id'];
					Utils::update_option( 'site_logo', $site_logo['id'] );
					$this->origin->update_imported_list('attachment', $site_logo['id']);
				}
			}
			else if(!empty($data['logo']) && empty(get_option('site_logo'))){
				// demo logo
				$site_logo = Utils::upload_logo($data['logo'], $this->session_id);
				if(!empty($site_logo['id'])){
					$settings['site_logo'] = $site_logo['id'];
					Utils::update_option( 'site_logo', $site_logo['id'] );
					$this->origin->update_imported_list('attachment', $site_logo['id']);
				}
			}


			if(!empty($data['typography'])){
				$settings["global_typography"] = $data['typography'];
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