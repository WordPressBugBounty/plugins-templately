<?php

namespace Templately\Modules\FullSiteImport\Concerns;

use Elementor\Plugin;
use Error;
use Exception;
use Templately\Modules\FullSiteImport\Exception\FatalErrorException;
use Templately\Modules\FullSiteImport\Exception\RetryableErrorException;
use Templately\Modules\FullSiteImport\Exception\UnknownErrorException;
use Templately\Modules\FullSiteImport\Runners\Finalizer;
use Templately\Modules\FullSiteImport\Utils\LogHandler;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Modules\FullSiteImport\Utils\AIUtils;
use Templately\Utils\Helper;
use Templately\Utils\Options;

/**
 * NormalizesResult — extracted verbatim from FullSiteImport (behavior-preserving).
 * Composed back into FullSiteImport via `use`; $this and method resolution unchanged.
 */
trait NormalizesResult {
	private function normalize_imported_data($data) {
		$request_params     = $this->get_session_data();
		$attachments        = !empty($data['attachments']['succeed']) ? count($data['attachments']['succeed']) : 0;
		$attachments_fail   = !empty($data['attachments']['failed']) ? count($data['attachments']['failed']) : 0;
		$attachments_errors = !empty($data['attachments_errors']) ? $data['attachments_errors'] : [];
		$templates          = !empty($data['templates']['succeed']) ? count($data['templates']['succeed']) : 0;
		$template_types     = !empty($data['templates']['template_types']) ? $data['templates']['template_types'] : [];
		$dependency_data    = !empty($data['dependency_data']) ? $data['dependency_data'] : [];

		$post_types = [];
		$content_templates = [];
		if (!empty($data['content']) && is_array($data['content'])) {
			foreach ($data['content'] as $type => $type_data) {
				$content_templates[$type] = !empty($type_data['succeed']) ? count($type_data['succeed']) : 0;
				$post_types[] = $this->get_post_type_label_by_slug($type);
			}
		}

		$contents = [];
		if (!empty($data['wp-content']) && is_array($data['wp-content'])) {
			foreach ($data['wp-content'] as $type => $type_data) {
				$contents[$type] = !empty($type_data['succeed']) ? count($type_data['succeed']) : 0;
				if (!in_array($type, ['wp_navigation', 'nav_menu_item'])) {
					$post_types[] = $this->get_post_type_label_by_slug($type);
				}
			}
		}

		$_processed_pages = AIUtils::get_processed_pages_data($request_params['process_id']);
		$ai_content = [
			'requested' => $request_params['ai_page_ids'] ?? [],
			'processed' => $_processed_pages,
		];


		$result = [
			'attachments'        => $attachments,
			'attachments_fail'   => $attachments_fail,
			'attachments_errors' => $attachments_errors,
			'templates'          => $templates,
			'contents'           => $content_templates,
			'wp-content'         => $contents,
			'post_types'         => $post_types,
			'template_types'     => $template_types,
			'ai_content'         => $ai_content,
			'dependency_data'    => $dependency_data,
			'home_url'           => home_url('/'),
		];

		Helper::log($data);
		Helper::log($result);

		return $result;
	}

	protected function get_post_type_label_by_slug($slug) {
		$post_type_obj = get_post_type_object($slug);
		if ($post_type_obj) {
			return $post_type_obj->label;
		}
		return null;
	}

}
