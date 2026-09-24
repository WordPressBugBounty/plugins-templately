<?php

namespace Templately\Modules\FullSiteImport\Ajax;

use Elementor\Plugin;
use Exception;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Modules\FullSiteImport\Utils\AIUtils;
use Templately\Modules\FullSiteImport\Utils\PackInfoFetcher;
use Templately\Utils\Helper;
use Templately\Utils\Response\AjaxResponder;

/**
 * PackInfoController — independent AJAX endpoint handler extracted from FullSiteImport.
 * Registered through FullSiteImport's nonce/capability wrapper (action names unchanged).
 *
 * Both handlers here are now DEPRECATED SHIMS: the primary transport moved to REST
 * (`REST\PackInfo` = GET /import/info, FR-018; `REST\GlobalSettings` = POST
 * /import/global-settings, FR-018/B8). These ajax actions are kept only so already-cached
 * old frontend bundles keep working. The upstream fetch is shared via `PackInfoFetcher`.
 */
class PackInfoController {

	public function import_info() {

		$platform = isset($_GET['platform']) ? sanitize_text_field(wp_unslash($_GET['platform'])) : 'elementor';
		$id       = isset($_GET['id']) ? intval($_GET['id']) : 0;
		$isAi     = isset($_GET['isAi']) ? sanitize_text_field(wp_unslash($_GET['isAi'])) : false;

		$data = ( new PackInfoFetcher() )->fetch($id, $isAi);
		if (is_wp_error($data)) {
			AjaxResponder::error($data);
			return;
		}

		$business_niches = get_option('templately_ai_business_niches', []);
		$data['data']['business_niches'] = $business_niches;

		if (isset($data['data']['manifest'])) {
			$data['data']['manifest'] = json_decode($data['data']['manifest'], true);
		}
		if (isset($data['data']['settings'])) {
			$data['data']['settings'] = json_decode($data['data']['settings'], true);
		}

		if ($isAi) {
			// Get the latest AI process for the current API key
			$last_ai_process = AIUtils::get_latest_ai_process_by_api_key($id);
			if ($last_ai_process) {
				// The cloud API key is stored on the process for server-side matching
				// only. It must never reach the browser — under a global login it is
				// the admin's credential, not the requesting user's.
				unset($last_ai_process['api_key']);

				$data['data']['ai_process'] = $last_ai_process;
			}

			if($last_ai_process && $id == ($last_ai_process['pack_id'] ?? null)){
				// Read AI preview content directly from files using the common function
				$session_id = $last_ai_process['session_id'] ?? null;
				$ai_page_ids = $last_ai_process['ai_page_ids'] ?? [];
				$dir_path = null;

				// Get session data to retrieve dir_path
				if ($session_id) {
					$session_data = SessionData::get_data($session_id);
					$dir_path = $session_data['dir_path'] ?? null;
				}

				// Use the common function to read AI template data if we have the required data
				if ($session_id && $ai_page_ids && $dir_path) {
					$data['data']['ai_preview_content'] = AIUtils::read_ai_template_data($session_id, $ai_page_ids, $dir_path);
				} else {
					$data['data']['ai_preview_content'] = [];
				}
			}
		}

		// Return the response body
		AjaxResponder::success($data);
	}
	public function import_global_settings() {
		$id   = isset($_GET['id']) ? intval($_GET['id']) : 0;
		$isAi = isset($_GET['isAi']) ? sanitize_text_field(wp_unslash($_GET['isAi'])) : false;

		$data = ( new PackInfoFetcher() )->fetch($id, $isAi);
		if (is_wp_error($data)) {
			AjaxResponder::error($data);
			return;
		}

		$settings = [];
		if (isset($data['data']['settings'])) {
			$settings = json_decode($data['data']['settings'], true);
		}

		AjaxResponder::success(['settings' => $settings]);
	}
}
