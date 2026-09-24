<?php

namespace Templately\Modules\FullSiteImport\Runners;

use Exception;
use Templately\Modules\ThemeBuilder\PageTemplates;
use Templately\Modules\ThemeBuilder\Types\BaseTemplate;
use Templately\Modules\FullSiteImport\Exception\FatalErrorException;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Utils\Helper;

class Templates extends BaseRunner {
	protected $imported_types = [];

	public function get_name(): string {
		return 'templates';
	}

	public function get_label(): string {
		return __( 'Templates', 'templately' );
	}

	public function log_message(): string {
		return __( 'Importing Templates (i.e: Header, Footer, etc)', 'templately' );
	}

	public function should_run( $data, $imported_data = [] ): bool {
		return ! empty( $this->manifest['templates'] );
		// return isset( $data['templates'] ) && $data['templates'] && ! empty( $this->manifest['templates'] );
	}

	/**
	 * @throws Exception
	 */
	public function import( $data, $imported_data ): array {
		$results  = $data["imported_data"]["templates"] ?? [];
		$templates = $this->manifest['templates'];
		$path      = $this->dir_path . 'templates' . DIRECTORY_SEPARATOR;

		// Importing Elementor templates requires Elementor to be loaded; without it the
		// template factory and the document import below hit an uncatchable "Class not
		// found" fatal. Fail fast with a message the user can act on instead.
		//
		// `Import::run()` now preflights the same condition for EVERY import via
		// `Dependencies::verify_platform_available()`, so in the normal pipeline this
		// is unreachable. Kept as a local guard because it is two class_exists calls
		// and this runner is the actual site of the fatal — if a future caller ever
		// drives a runner without going through Import::run(), this is what stops it.
		if ( $this->manifest['platform'] === 'elementor' && ! class_exists( '\\Elementor\\Core\\Base\\Document' ) ) {
			throw new FatalErrorException( __( 'Elementor is required to import this template pack, but it is not active. Please install and activate Elementor, then try again.', 'templately' ) );
		}

		if ( $this->manifest['platform'] === 'elementor' ) {
			$this->json->set_source_url( $this->manifest['site'] ?? '' );
		}

		$_extra_pages = [];


		$total     = count( $templates );

		$results = $this->loop( $templates, function($id, $template_settings, $results ) use( $path, $total ) {
			$template_content = Utils::read_json_file( $path . $id . '.json' );

			$import = $this->import_template( $id, $template_settings, $template_content );
			if ( $import ) {
				$results['templates']['succeed'][ $id ]   = $import['id'];
				$results['templates']['template_types'][] = $template_settings['type'];

				if ( ! empty( $import['__link_rewrites'] ) ) {
					$results['templates']['__link_rewrites'][ $id ] = true;
				}

				if ( $template_settings['type'] === 'archive' || $template_settings['type'] === 'product_archive' || $template_settings['type'] === 'course_archive' ) {
					$page_id = Utils::create_archive_page( $template_settings, $this->manifest['platform'], $this->manifest );
					if ( $page_id && isset($template_settings['page_settings']['archive_page_id']) ) {
						$archive_page_id = $template_settings['page_settings']['archive_page_id'];
						$results['archive_settings'][$archive_page_id] = [
							'old_id'     => $id,
							'page_id'    => $page_id,
							'archive_id' => $archive_page_id
						];
					}
				}
				else if($template_settings['type'] === 'fluent_product_single'){
					Utils::create_page_template( $this->manifest['platform'] );
				}

			} else {
				$results['templates']['failed'][ $id ] = $import;
			}

			// Broadcast Log
			$processed = 0;
			$processed_template = array_merge($results['templates']['succeed'] ?? [], $results['templates']['failed'] ?? []);
			array_walk_recursive($processed_template, function($item, $key) use (&$processed) {
				$processed++;
			});
			$progress  = floor( ( 100 * $processed ) / $total );
			$this->log( $progress );

			$results['templates']['__attachments'][ $id ] = isset($import['__attachments']) ? $import['__attachments'] : [];
			// Add the template to the processed templates and update the session data


			return $results;
		}); // , null, true

		return $results;
	}

	private function import_template( $id, $template_settings, $template_content ) {
		$type = $template_settings['type'];

		/**
		 * @var BaseTemplate $template
		 */

		$post_data = [
			'post_title'  => $template_settings['title'] ?? ucfirst( $type ) . ' - (by Templately)',
			'post_status' => 'publish',
			'post_type'   => 'templately_library',
		];

		$meta = [];
		$result = [];

		if ( $this->manifest['platform'] == 'gutenberg' ) {
			$meta['_wp_page_template'] = PageTemplates::TEMPLATE_HEADER_FOOTER;
		}

		$template = $this->factory->create( $type, $post_data, $meta );

		if ( is_wp_error( $template ) ) {
			return false;
		}
		if (!$template->is_elementor_template()) {
			$attachments = $this->json->parse_images($template_content['content']);

			if (!empty($attachments)) {
				$result['__attachments'] = $attachments;
				// $manifest_content = &$this->manifest['templates'][$id];
				// if(!isset($manifest_content['__attachments'])){
				// 	$manifest_content['__attachments'] = [];
				// }
				// $manifest_content['__attachments'] = $attachments;
			}
		}

		$template_content['id']              = $id;
		unset($template_settings['conditions']);
		$template_content['import_settings'] = $template_settings;

		// Detect source-site links (e.g. Mega Menu / EA Info Box / Button URLs). When
		// present, flag the template so the Finalizer picks it up and rewrites them to
		// the real imported permalinks once every page exists (see Finalizer::prepare).
		if ( $this->manifest['platform'] === 'elementor' && ! empty( $template_content['content'] ) ) {
			if ( $this->json->replace_source_urls( $template_content['content'] ) > 0 ) {
				$result['__link_rewrites'] = true;
			}
		}

		if($this->platform === 'elementor' && $this->is_ai_content($id)){
			$template_content['content'] = [];
		}
		else if($this->platform === 'gutenberg' && $this->is_ai_content($id)){
			$template_content['content'] = '';
		}

		// While importing an Elementor document, Elementor builds each widget's control
		// stack and fires its `register_controls` hook. An outdated add-on whose callback
		// references a class/constant removed from newer Elementor (Essential Addons'
		// `Elementor\Core\Schemes\Typography`, a `Controls_Manager::ALERT` constant)
		// throws a PHP \Error there — which `catch ( Exception )` cannot contain, so ONE
		// broken widget aborted the entire Full Site Import.
		//
		// Contained to this template: the rest of the pack still imports. Deliberately NOT
		// a SkippableErrorException — that is only honoured when the opt-in
		// `templately_enable_fsi_skip_on_error` option is set (Loop::_is_skip_feature_enabled,
		// default false), so on a default install throwing it would re-abort the import and
		// leave users worse off than this fix found them. The failure is instead surfaced on
		// the SSE stream so it is visible in the import log rather than silent.
		try {
			$template->import( $template_content );
		} catch ( \Error $e ) {
			$label = $this->manifest['templates'][ $id ]['name'] ?? $id;

			Helper::log(
				sprintf( 'Elementor document import failed for template #%s: %s', $template->get_main_id(), $e->getMessage() ),
				'fsi',
				'error'
			);

			$this->log(
				0,
				sprintf(
					/* translators: %s: template name */
					__( 'Skipped "%s" — a plugin conflict stopped this template from importing. The rest of the pack was imported.', 'templately' ),
					$label
				),
				'eventLog'
			);

			$result['__import_failed'] = true;
		}

		if($template->has_logo($template_content)){
			$this->manifest['templates'][$id]['has_logo'] = true;
		}
		$result['id'] = $template->get_main_id();
		return $result;
	}
}