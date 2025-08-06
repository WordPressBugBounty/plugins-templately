<?php

/**
 * Templately AI Content Importer
 *
 * @package Templately
 * @since 1.0.0
 */

namespace Templately\API;

use Error;
use Templately\Utils\Helper;
use WP_REST_Request;
use Templately\Core\Importer\Utils\Utils;
use Templately\Core\Importer\Utils\AIUtils;

class AIContent extends API {
	private $endpoint  = 'ai-content';
	private $dev_mode  = false;


	/**
	 * AIContent constructor.
	 *
	 * @param string $file    File path.
	 * @param array  $settings Settings.
	 */
	public function __construct() {
	    $this->dev_mode = (defined('TEMPLATELY_DEV') && TEMPLATELY_DEV) || (defined('IMPORT_DEBUG') && IMPORT_DEBUG);

		parent::__construct();

	}

	public function _permission_check(WP_REST_Request $request) {
		$this->request = $request;
		$process_id    = $this->get_param('process_id');

		$_route = $request->get_route();
		if ('/templately/v1/ai-content/ai-update' === $_route || '/templately/v1/ai-content/ai-update-preview' === $_route) {
			if (empty($process_id)) {
				return $this->error('invalid_id', __('Invalid ID.', 'templately'), 'calculate_credit', 400);
			}

			// Check AI process data using API key-based storage
			$ai_process_data = AIUtils::get_ai_process_data();
			if (is_array($ai_process_data) && !empty($ai_process_data[$process_id])) {
				return true;
			}

			return (bool) Helper::get_matched_session_data($process_id);
		}

		return parent::permission_check($request);
	}


	public function register_routes() {
		// $this->get( $this->endpoint . '/calculate-credit', [ $this, 'calculate_credit' ] );
		$this->post($this->endpoint . '/modify-content', [$this, 'modify_content']);
		$this->post($this->endpoint . '/ai-update', [$this, 'ai_update']);
		$this->post($this->endpoint . '/ai-update-preview', [$this, 'ai_update_preview']);
		// die(rest_url( 'templately/v1/ai-content/ai-update' ));
	}

	public function calculate_credit() {
		$pack_id = $this->get_param('pack_id');

		return [
			'status' => 'success',
			'data'   => [
				'availableCredit' => 100,
			],
		];

		if (empty($pack_id)) {
			return $this->error('invalid_id', __('Invalid ID.', 'templately'), 'calculate_credit', 400);
		}

		$response = wp_remote_get($this->get_api_url("ai/calculate-credit/pack/$pack_id"), [
			'timeout' => 30,
			'headers' => [
				'Accept'               => 'application/json',
				'Content-Type'         => 'application/json',
				'Authorization'        => 'Bearer ' . $this->api_key,
				'x-templately-ip'      => Helper::get_ip(),
				'x-templately-url'     => home_url('/'),
				'x-templately-version' => TEMPLATELY_VERSION,
			]
		]);


		// return $response;
		if (is_wp_error($response)) {
			return $this->error('request_failed', __('Request failed.', 'templately'), 'calculate_credit', 500, $response);
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);
		// error status is ok
		if (! is_array($data) || ! isset($data['status'])) {
			return $this->error('invalid_response', __('Invalid response.', 'templately'), 'calculate_credit', 500);
		}


		return $data;
	}

	public function modify_content() {
		add_filter('wp_redirect', '__return_false', 999);
		set_time_limit(3 * MINUTE_IN_SECONDS);
		ini_set('max_execution_time', 3 * MINUTE_IN_SECONDS);

		$pack_id             = $this->get_param('pack_id');
		$isBusinessNichesNew = $this->get_param('isBusinessNichesNew', false);
		$ai_page_ids         = $this->get_param('ai_page_ids', [], null);
		$content_ids         = $this->get_param('content_ids', [], null);
		$session_id          = $this->get_param('session_id');                  // Add session_id parameter
		$preview_pages       = $this->get_param('preview_pages', [], null);
		$platform            = $this->get_param('platform');

		// ai content fields
		$name            = $this->get_param('name');
		$category        = $this->get_param('category');
		$description     = $this->get_param('description');
		$email           = $this->get_param('email');
		$contactNumber   = $this->get_param('contactNumber');
		$businessAddress = $this->get_param('businessAddress');
		$openingHour     = $this->get_param('openingHour');

		if (empty($pack_id)) {
			return $this->error('invalid_id', __('Invalid ID.', 'templately'), 'modify_content', 400);
		}
		if (empty($category)) {
			return $this->error('invalid_prompt', __('Invalid prompt.', 'templately'), 'modify_content', 400);
		}
		if (empty($content_ids) && empty($preview_pages)) {
			return $this->error('invalid_content_ids', __('Invalid content ids.', 'templately'), 'modify_content', 400);
		}
		if (empty($platform)) {
			return $this->error('invalid_platform', __('Invalid platform.', 'templately'), 'modify_content', 400);
		}


		// $response    = get_transient( '__templately_ai_process_id' );

		// if(empty($response)) {
		$response = wp_remote_post($this->get_api_url("ai/modify-content/pack"), [
			'timeout' => 15 * MINUTE_IN_SECONDS,
			'headers' => [
				'Accept'                  => 'application/json',
				'Content-Type'            => 'application/json',
				'Authorization'           => 'Bearer ' . $this->api_key,
				'x-templately-ip'         => Helper::get_ip(),
				'x-templately-url'        => home_url('/'),
				'x-templately-version'    => TEMPLATELY_VERSION,
				'x-templately-session-id' => $session_id,
			],
			'body' => json_encode([
				'business_name'   => $name,
				'business_niches' => $category,
				'prompt'          => $description,
				'email'           => $email,
				'phone'           => $contactNumber,
				'address'         => $businessAddress,
				'openingHour'     => $openingHour,
				'pack_id'         => $pack_id,
				'content_ids'     => $content_ids,
				'platform'        => $platform,
				'preview_pages'   => $preview_pages,
				'callback'        => defined('TEMPLATELY_CALLBACK') ? TEMPLATELY_CALLBACK . '/wp-json/templately/v1/ai-content/ai-update' : rest_url('templately/v1/ai-content/ai-update'),
			]),
		]);

		// 	set_transient( '__templately_ai_process_id', $response, 60 * 60 * 24 * 30 );
		// }

		$bk_ai_business_niches = get_option('templately_ai_business_niches', []);
		if (!empty($business_niches) && $isBusinessNichesNew && ! in_array($business_niches, $bk_ai_business_niches)) {
			$bk_ai_business_niches[] = $business_niches;
			update_option('templately_ai_business_niches', $bk_ai_business_niches, false);
		}

		// return $response;
		if (is_wp_error($response)) {
			error_log(print_r($response, true));
			return $this->error('request_failed', __('Request failed.', 'templately'), 'modify_content', 500, $response->additional_data);
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);
		// error status is ok, if status is error then return as is
		if (! is_array($data) || ! isset($data['status'])) {
			return $this->error('invalid_response', __('Invalid response.', 'templately'), 'modify_content', 500, $data);
		}

		// "{"status":"success","message":"The content is being generated in the queue","process_id":"01JRQQD39GNWTNF18EWF8YH0BG-271838-pack-408"}"
		if (isset($data['status']) && $data['status'] === 'success' && isset($data['process_id'])) {
			$process_id = $data['process_id'];

			// Save templates to files if available using the common function
			if (!empty($data['templates']) && is_array($data['templates'])) {
				foreach ($data['templates'] as $content_id => $template_data) {
					// Decode template if it's base64 encoded
					if (! empty($template_data) && base64_decode($template_data, true) !== false) {
						$data['templates'][$content_id] = base64_decode($template_data);
					}

					if (!empty($template_data)) {
						Helper::save_template_to_file(
							$process_id,
							$content_id,
							$template_data,
							$ai_page_ids,
							true, // Always use preview mode for AI content workflow
							isset($template_data['isSkipped']) ? $template_data['isSkipped'] : false
						);
					}
				}
			}

			$ai_process_data[$process_id] = [
				'name'            => $name,
				'category'        => $category,
				'description'     => $description,
				'email'           => $email,
				'contactNumber'   => $contactNumber,
				'businessAddress' => $businessAddress,
				'openingHour'     => $openingHour,
				'process_id'      => $process_id,
				'pack_id'         => $pack_id,
				'ai_page_ids'     => $ai_page_ids,
				'ai_preview_ids'  => $preview_pages,
				'content_ids'     => $content_ids,
				'platform'        => $platform,
				'api_key'         => $this->api_key,
				'session_id'      => $session_id, // Store session_id for coordination
			];

			// Update using API key-based storage with automatic count-based cleanup
			AIUtils::update_ai_process_data($ai_process_data);

			return [
				'status'     => 'success',
				'message'    => __('The content is being generated in the queue', 'templately'),
				'process_id' => $process_id,
				'templates'  => !empty($data['templates']) ? $data['templates'] : null,
				'is_local_site'  => !empty($data['is_local_site']) ? $data['is_local_site'] : null,
			];
		}

		return $data;
	}

	public function ai_update() {
		add_filter('wp_redirect', '__return_false', 999);

		$template    = $this->get_param('template');
		$process_id  = $this->get_param('process_id');
		$template_id = $this->get_param('template_id');
		$content_id  = $this->get_param('content_id');
		$type        = $this->get_param('type');
		$isSkipped   = $this->get_param('isSkipped', false);
		$credit_cost = $this->request->get_param('credit_cost');

		error_log('process_id: ' . $process_id);

		// Handle credit cost updates separately
		if ($this->request->has_param('credit_cost')) {
			$processed_pages = get_option("templately_ai_processed_pages", []);
			$processed_pages[$process_id] = $processed_pages[$process_id] ?? [];
			$processed_pages[$process_id]['credit_cost'] = $credit_cost;
			update_option("templately_ai_processed_pages", $processed_pages, false);

			return [
				'status' => 'success',
				'data'   => [
					'process_id' => $process_id,
					'credit_cost' => $credit_cost,
				],
			];
		}

		// Always use preview mode for AI content workflow
		// Get AI process data using API key-based storage
		$ai_process_data = AIUtils::get_ai_process_data();

		if (empty($ai_process_data[$process_id]['ai_page_ids'])) {
			return $this->error('invalid_process_id', __('Invalid process id.', 'templately'), 'ai-content/ai-update', 400);
		}

		$ai_page_ids = $ai_process_data[$process_id]['ai_page_ids'];

		// Use the common helper function to save the template (always preview mode)
		$result = Helper::save_template_to_file(
			$process_id,
			$content_id,
			$template,
			$ai_page_ids,
			true, // Always use preview mode
			$isSkipped
		);

		if(is_wp_error($result)){
			return $result;
		}

		// Return the result from the helper function
		if (isset($result['status']) && $result['status'] === 'success') {
			return $result;
		}

		// Return error if the helper function failed
		return $result;
	}

	public function ai_update_preview() {
		add_filter('wp_redirect', '__return_false', 999);

		$template   = $this->get_param('templates');         // Now expects an array with content_id as keys
		$process_id = $this->get_param('process_id');
		$isSkipped  = $this->get_param('isSkipped', false);
		$error      = $this->get_param('error', null);

		error_log('process_id: ' . $process_id);

		if (!empty($isSkipped) || !empty($error)) {
			// Update AI process data with error using API key-based storage
			$ai_process_data = AIUtils::get_ai_process_data();
			if (isset($ai_process_data[$process_id])) {
				$ai_process_data[$process_id]['preview_error'] = $error;
				AIUtils::update_ai_process_data($ai_process_data);
			}
			wp_send_json_error([
				'status' => 'error',
				'message' => $error,
			]);
		}

		// Validate template parameter is an array
		if (!is_array($template) || empty($template)) {
			return $this->error('invalid_template', __('Template must be a non-empty array with content_id as keys.', 'templately'), 'ai-content/ai-update-preview', 400);
		}

		// Always use preview mode for AI content workflow
		// Get AI process data using API key-based storage
		$ai_process_data = AIUtils::get_ai_process_data();

		if (empty($ai_process_data[$process_id]['ai_page_ids'])) {
			return $this->error('invalid_process_id', __('Invalid process id.', 'templately'), 'ai-content/ai-update-preview', 400);
		}

		$ai_page_ids = $ai_process_data[$process_id]['ai_page_ids'];
		$results = [];
		$success_count = 0;
		$error_count = 0;

		// Process each content_id/template pair
		foreach ($template as $content_id => $template_data) {
			// Use the common helper function to save the template (always preview mode)
			$result = Helper::save_template_to_file(
				$process_id,
				$content_id,
				$template_data,
				$ai_page_ids,
				true, // Always use preview mode
				$isSkipped
			);

			$results[$content_id] = $result;

			// Track success/error counts
			if (isset($result['status']) && $result['status'] === 'success') {
				$success_count++;
			} else {
				$error_count++;
			}
		}

		// Return consolidated response
		$overall_status = $error_count === 0 ? 'success' : ($success_count === 0 ? 'error' : 'partial_success');

		// Note: No cleanup needed with API key-based storage and count-based management

		return [
			'status' => $overall_status,
			'message' => sprintf(
				__('Processed %d templates: %d successful, %d failed.', 'templately'),
				count($template),
				$success_count,
				$error_count
			),
		];
	}



	private function get_api_url($end_point): string {
		$dev_mode = defined('TEMPLATELY_DEV') && TEMPLATELY_DEV;
		return $dev_mode ? "https://app.templately.dev/api/v2/" . $end_point : "https://app.templately.com/api/v2/" . $end_point;
	}
}
