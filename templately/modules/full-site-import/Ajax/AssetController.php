<?php

namespace Templately\Modules\FullSiteImport\Ajax;

use Elementor\Plugin;
use Exception;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Modules\FullSiteImport\Utils\AIUtils;
use Templately\Modules\ElementorKitSettings\Utils\ElementorSettingsMerger;
use Templately\Utils\Helper;
use Templately\Utils\Response\ResponseNormalizer;
use Templately\Utils\Response\AjaxResponder;
use Templately\Utils\Response\ErrorCode;

/**
 * AssetController — independent AJAX endpoint handler extracted from FullSiteImport.
 * Registered through FullSiteImport's nonce/capability wrapper (action names unchanged).
 */
class AssetController {

	public function google_font() {
		$result = get_transient('templately-google-fonts');

		if (false == $result) {
			$response = Helper::make_api_get_request('v2/google-font', [], [], 30);

			// 043 FR-003 — one normalizer classifies transport failures and error
			// bodies alike, replacing `extract_error_from_response()`. That helper
			// returned `mixed` (a string, or the whole decoded upstream body), so a
			// framework stack trace could end up rendered as the user's error text.
			// The wire shape here is unchanged; only the message source moved.
			if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
				$error = ResponseNormalizer::normalize($response)->error();
				AjaxResponder::error($error);
				return;
			}

			$body = wp_remote_retrieve_body($response);
			$data = json_decode($body, true);

			if (!isset($data['status']) || $data['status'] !== 'success') {
				AjaxResponder::error(ErrorCode::INVALID_REQUEST, 'API response indicates failure.');
				return;
			}

			if (!isset($data['data'])) {
				AjaxResponder::error(ErrorCode::EMPTY_RESPONSE, 'API response missing data.');
				return;
			}

			$result = $data['data'];
			// The Google Fonts catalog is near-static (additive, a handful of new fonts a
			// month), and a stale list is harmless — a newly published font just appears
			// late. A month-long cache spares the upstream round-trip for every settings
			// Typography / FSI customizer / fsi-kit mount that shares this transient.
			set_transient('templately-google-fonts', $result, MONTH_IN_SECONDS);
		}

		AjaxResponder::success($result);
	}
	/**
	 * AJAX handler: merge global kit settings into template content.
	 *
	 * Accepts (via POST JSON body):
	 *   platform  - 'elementor' | 'gutenberg'
	 *   template  - the template data array returned by /v1/insert
	 *   settings  - the kit settings array from import_info
	 *
	 * Returns the template data with globals resolved, or an error.
	 * The JS side should fall back to the unmerged template on any error.
	 */
	public function merge_settings_with_template() {
		$body     = file_get_contents( 'php://input' );
		$data     = json_decode( $body, true );

		$platform = isset( $data['platform'] ) ? sanitize_text_field( $data['platform'] ) : 'elementor';
		$template = $data['template'] ?? null;
		$settings = $data['settings'] ?? null;

		if ( empty( $template ) || ! is_array( $template ) ) {
			AjaxResponder::error( ErrorCode::INVALID_REQUEST, 'Invalid template data.' );
			return;
			return;
		}

		if ( empty( $settings ) || ! is_array( $settings ) ) {
			// No settings to merge – return template unchanged.
			AjaxResponder::success( $template );
			return;
		}

		try {
			if ( $platform === 'elementor' ) {
				if ( ! empty( $template['content'] ) && is_array( $template['content'] ) ) {
					$template['content'] = ElementorSettingsMerger::merge( $template['content'], $settings );
				}
			} elseif ( $platform === 'gutenberg' ) {
				if ( ! empty( $template['content'] ) && is_string( $template['content'] ) ) {
					// Platform filter seam (spec 025/033): gutenberg-integration hooks
					// this to run GutenbergSettingsMerger::merge without FSI importing it.
					$template['content'] = apply_filters( 'templately_fsi_merge_settings', $template['content'], $settings );
				}
			}

			AjaxResponder::success( $template );
		} catch ( \Exception $e ) {
			Helper::log( 'merge_settings_with_template error: ' . $e->getMessage() );
			// Return error so JS can fall back to unmerged template.
			AjaxResponder::error( ErrorCode::SERVER_ERROR, 'Settings merge failed.' );
			return;
		}
	}
}
