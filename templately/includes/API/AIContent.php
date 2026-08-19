<?php

/**
 * Templately AI Content Importer
 *
 * @package Templately
 * @since 1.0.0
 */

namespace Templately\API;

use Error;
use Exception;
use Templately\Utils\Helper;
use Templately\Utils\Database;
use WP_REST_Request;
use WP_Error;
use Templately\Core\Importer\Utils\Utils;
use Templately\Core\Importer\Utils\AIUtils;
use Templately\Core\Importer\Utils\SignatureVerifier;
use Templately\Core\Importer\Parsers\WXR_Parser;

class AIContent extends API {
	private $endpoint  = 'ai-content';
	private $dev_mode  = false;

	/**
	 * Short-lived cache of the `v2/chatbot/generated/{chat}` bundle.
	 *
	 * That payload is large (every generated page's block JSON, served off GCP)
	 * and the direct-import handoff pulls it twice within seconds — once to read
	 * the customization, once to write the pages. Only a COMPLETE bundle is ever
	 * reused (an incomplete one has to be re-pulled to pick up new pages), and
	 * the TTL is deliberately short so the `can_import` / already-imported gate
	 * cannot go meaningfully stale.
	 */
	const GENERATED_CACHE_KEY = 'chatbot_generated_';
	const GENERATED_CACHE_TTL = 60;


	/**
	 * AIContent constructor.
	 *
	 * @param string $file    File path.
	 * @param array  $settings Settings.
	 */
	public function __construct() {

		parent::__construct();

	}

	public function _permission_check(WP_REST_Request $request) {
		$this->request = $request;
		$this->api_key = $this->utils('options')->get( 'api_key' );
		$process_id    = $this->get_param('process_id');

		$_route = $request->get_route();
		if ('/templately/v1/ai-content/ai-update' === $_route || '/templately/v1/ai-content/ai-update-preview' === $_route) {
			// Disabled: the headers carry X-Templately-Apikey, and Helper::log()
			// writes to debug.log, which is web-readable on plenty of hosts. That
			// key authorizes this very route.
			// Helper::log( [
			// 	'headers' => $request->get_headers(),
			// 	'body'    => $request->get_params(),
			// ], 'ai_update_request' );

			if (empty($process_id)) {
				return $this->error('invalid_id', __('Invalid ID.', 'templately'), 'calculate_credit', 400);
			}

			$header_api_key = sanitize_text_field($request->get_header('x_templately_apikey'));
			if (empty($header_api_key)) {
				$header_api_key = sanitize_text_field($request->get_header('X-Templately-Apikey'));
			}

			// Validate API key from header against database
			if (empty($header_api_key)) {
				return $this->error('missing_api_key', __('Missing API key in header.', 'templately'), 'ai-content/permission', 403);
			}

			$is_valid_key = $this->validate_api_key_in_db($header_api_key);
			if (!$is_valid_key) {
				return $this->error('invalid_api_key', __('Invalid API key provided in header.', 'templately'), 'ai-content/permission', 403);
			}

			// Check AI process data using API key-based storage
			$ai_process_data = AIUtils::get_ai_process_data();
			if (is_array($ai_process_data) && !empty($ai_process_data[$process_id])) {
				return true;
			}

			return (bool) AIUtils::get_matched_session_data($process_id);
		}

		// // Allow access to attachments endpoint
		// if ('/templately/v1/ai-content/attachments' === $_route) {
		// 	return true;
		// }
		return parent::_permission_check($request);
	}


	public function register_routes() {
		// $this->get( $this->endpoint . '/calculate-credit', [ $this, 'calculate_credit' ] );
		$this->post($this->endpoint . '/modify-content', [$this, 'modify_content']);
		$this->post($this->endpoint . '/ai-update', [$this, 'ai_update']);
		$this->post($this->endpoint . '/ai-update-preview', [$this, 'ai_update_preview']);
		$this->post($this->endpoint . '/generate-tagline', [$this, 'generate_tagline']);
		$this->get($this->endpoint . '/chatbot-conversation', [$this, 'get_chatbot_conversation'], [
			'chat' => [
				'required' => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function($param, $request, $key) {
					return is_string($param) && strlen($param) > 0 && strlen($param) <= 128 && preg_match('/^[A-Za-z0-9\-_]+$/', $param);
				},
			],
		]);
		$this->get($this->endpoint . '/chatbot-conversations', [$this, 'get_chatbot_conversations'], [
			'page' => [
				'required' => false,
				'default'  => 1,
				// absint, not the sanitize_text_field default — that would turn the
				// int into a string and corrupt anything non-string.
				'sanitize_callback' => 'absint',
			],
			'per_page' => [
				'required' => false,
				'default'  => 10,
				'sanitize_callback' => 'absint',
			],
		]);
		$this->get($this->endpoint . '/chatbot-generated', [$this, 'get_chatbot_generated'], [
			'chat' => [
				'required' => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function($param, $request, $key) {
					return is_string($param) && strlen($param) > 0 && strlen($param) <= 128 && preg_match('/^[A-Za-z0-9\-_]+$/', $param);
				},
			],
		]);
		$this->post($this->endpoint . '/chatbot-detected-info', [$this, 'update_chatbot_detected_info'], [
			'chat' => [
				'required' => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function($param, $request, $key) {
					return is_string($param) && strlen($param) > 0 && strlen($param) <= 128 && preg_match('/^[A-Za-z0-9\-_]+$/', $param);
				},
			],
		]);
		$this->post($this->endpoint . '/chatbot-import-prepare', [$this, 'chatbot_import_prepare']);
		$this->post($this->endpoint . '/chatbot-mark-imported', [$this, 'mark_chatbot_imported'], [
			'chat' => [
				'required' => true,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function($param, $request, $key) {
					return is_string($param) && strlen($param) > 0 && strlen($param) <= 128 && preg_match('/^[A-Za-z0-9\-_]+$/', $param);
				},
			],
		]);
		$this->get($this->endpoint . '/attachments', [$this, 'get_attachments'], [
			'type' => [
				'default' => 'pack',
				'required' => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'id' => [
				'required' => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'pack_id' => [
				'required' => false,
				'sanitize_callback' => 'sanitize_text_field',
			],
		]);
		$this->get($this->endpoint . '/images', [$this, 'search_images'], [
			'query' => [
				'required' => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function($param, $request, $key) {
					return is_string($param) && strlen($param) <= 255;
				},
			],
			'orientation' => [
				'required' => false,
				'default' => 'all',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function($param, $request, $key) {
					$allowed_orientations = ['all', 'landscape', 'portrait', 'square'];
					return in_array($param, $allowed_orientations, true);
				},
			],
			'size' => [
				'required' => false,
				'default' => 'medium',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function($param, $request, $key) {
					$allowed_sizes = ['small', 'medium', 'large'];
					return in_array($param, $allowed_sizes, true);
				},
			],
			'color' => [
				'required' => false,
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => function($param, $request, $key) {
					return is_string($param) && strlen($param) <= 50;
				},
			],
			'page' => [
				'required' => false,
				'default' => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => function($param, $request, $key) {
					return is_numeric($param) && $param > 0 && $param <= 1000;
				},
			],
			'per_page' => [
				'required' => false,
				'default' => 20,
				'sanitize_callback' => 'absint',
				'validate_callback' => function($param, $request, $key) {
					return is_numeric($param) && $param > 0 && $param <= 100;
				},
			],
		]);
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

		$extra_headers = [
			'Accept' => 'application/json',
		];
		$response = Helper::make_api_get_request("v2/ai/calculate-credit/pack/$pack_id", [], $extra_headers, 30);


		// return $response;
		if (is_wp_error($response)) {
			return $this->error('request_failed', __('Request failed.', 'templately'), 'calculate_credit', 500, ['error_detail' => $response->get_error_message()]);
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

		// Security: Sanitize session_id if provided
		if (!empty($session_id)) {
			$session_id = AIUtils::sanitize_path_component($session_id, 'session_id');
			if (is_wp_error($session_id)) {
				return $session_id;
			}
		}

		$preview_pages       = $this->get_param('preview_pages', [], null);
		$image_replace       = $this->get_param('imageReplace', [], null);
		$platform            = $this->get_param('platform');
		$language            = $this->get_param('language', null);

		// ai content fields
		$name               = $this->get_param('name');
		$category           = $this->get_param('category');
		$description        = $this->get_param('description');
		$email              = $this->get_param('email');
		$contactNumber      = $this->get_param('contactNumber');
		$businessAddress    = $this->get_param('businessAddress');
		$openingHour        = $this->get_param('openingHour');
		$requested_platform = $this->get_param('requested_platform', 'templately');

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
		$extra_headers = [
			'Accept'                          => 'application/json',
			'x-templately-session-id'         => $session_id,
			'x-templately-requested-platform' => $requested_platform,
		];
		$body_data = [
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
			'language'        => $language,
			'callback'        => defined('TEMPLATELY_CALLBACK') ? TEMPLATELY_CALLBACK . '/wp-json/templately/v1/ai-content/ai-update' : rest_url('templately/v1/ai-content/ai-update'),
		];
		/**
		 * Filter body data before making API request to modify-content endpoint
		 *
		 * @since 3.5.0
		 * @param array $body_data The request body data
		 * @param WP_REST_Request $request The REST request object
		 */
		$body_data = apply_filters( 'templately_ai_modify_content_body_data', $body_data, $this->request );

		$response = Helper::make_api_post_request('v2/ai/modify-content/pack', $body_data, $extra_headers, 15 * MINUTE_IN_SECONDS);

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
			return $this->error('request_failed', __('Request failed.', 'templately'), 'modify_content', 500, ['error_data' => $response->get_error_data()]);
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);
		// error status is ok, if status is error then return as is
		if (! is_array($data) || ! isset($data['status'])) {
			return $this->error('invalid_response', __('Invalid response.', 'templately'), 'modify_content', 500, ['data' => $data]);
		}

		// "{"status":"success","message":"The content is being generated in the queue","process_id":"01JRQQD39GNWTNF18EWF8YH0BG-271838-pack-408"}"
		if (isset($data['status']) && $data['status'] === 'success' && isset($data['process_id'])) {
			$process_id = $data['process_id'];

			// // Save templates to files if available using the common function
			// if (!empty($data['templates']) && is_array($data['templates'])) {
			// 	foreach ($data['templates'] as $content_id => $template_data) {
			// 		// Decode template if it's base64 encoded
			// 		if (! empty($template_data) && base64_decode($template_data, true) !== false) {
			// 			$data['templates'][$content_id] = base64_decode($template_data);
			// 		}

			// 		if (!empty($template_data)) {
			// 			AIUtils::save_template_to_file(
			// 				$process_id,
			// 				$content_id,
			// 				$template_data,
			// 				$ai_page_ids,
			// 				true, // Always use preview mode for AI content workflow
			// 				isset($template_data['isSkipped']) ? $template_data['isSkipped'] : false
			// 			);
			// 		}
			// 	}
			// }

			$user = $this->utils('options')->get('user');

			$ai_process_data[$process_id] = [
				'name'               => $name,
				'category'           => $category,
				'description'        => $description,
				'email'              => $email,
				'contactNumber'      => $contactNumber,
				'businessAddress'    => $businessAddress,
				'openingHour'        => $openingHour,
				'process_id'         => $process_id,
				'pack_id'            => $pack_id,
				'ai_page_ids'        => $ai_page_ids,
				'ai_preview_ids'     => $preview_pages,
				'content_ids'        => $content_ids,
				'platform'           => $platform,
				'api_key'            => $this->api_key,
				'user_id'            => isset($user['id']) ? $user['id'] : null,
				'session_id'         => $session_id,
				'imageReplace'       => $image_replace,
				'language'           => $language,
				'requested_platform' => $requested_platform,
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
		// Validate and get process data using centralized method
		$process_data = AIUtils::validate_and_get_process_data($process_id);
		if (is_wp_error($process_data)) {
			return $process_data;
		}

		$session_id = $process_data['session_id'];
		$ai_page_ids = $process_data['ai_page_ids'];

		// Use the common helper function to save the template
		$result = AIUtils::save_template_to_file(
			$process_id,
			$session_id,
			$content_id,
			$template,
			$ai_page_ids,
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
		// Validate and get process data using centralized method
		$process_data = AIUtils::validate_and_get_process_data($process_id);
		if (is_wp_error($process_data)) {
			return $process_data;
		}

		$session_id = $process_data['session_id'];
		$ai_page_ids = $process_data['ai_page_ids'];
		$results = [];
		$success_count = 0;
		$error_count = 0;

		// Process each content_id/template pair
		foreach ($template as $content_id => $template_data) {
			// Use the common helper function to save the template (always preview mode)
			$result = AIUtils::save_template_to_file(
				$process_id,
				$session_id,
				$content_id,
				$template_data,
				$ai_page_ids,
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



	/**
	 * Get attachments from API endpoint
	 *
	 * @return array|\WP_Error
	 */
	public function get_attachments() {
		// Get parameters from request
		$type               = $this->get_param('type', 'pack');
		$id                 = $this->get_param('pack_id');
		$requested_platform = $this->get_param('requested_platform', 'templately');

		// Require ID parameter - return error if not provided
		if (empty($id)) {
			return $this->error('missing_id', __('Pack ID or ID parameter is required.', 'templately'), 'get_attachments', 400);
		}

		try {
			// Construct API endpoint URL
			$api_endpoint = "get-xml-attachment/{$type}/{$id}";

			// Make API call
			$extra_headers = [
				'Accept'                          => 'application/xml, text/xml',
				'x-templately-requested-platform' => $requested_platform,
			];
			$response = Helper::make_api_get_request("v2/$api_endpoint", [], $extra_headers, 30);

			// Check for HTTP errors
			if (is_wp_error($response)) {
				return $this->error('api_request_failed', __('Failed to fetch attachments from API.', 'templately'), 'get_attachments', 500, ['error_detail' => $response->get_error_message()]);
			}

			$response_code = wp_remote_retrieve_response_code($response);
			$xml_content = wp_remote_retrieve_body($response);

			if ($response_code !== 200) {
				// check if $xml_content contains valid json
				// ex. '{"status":"error","message":"Attachment XML file not found in pack archive."}'
				$error_data = @json_decode($xml_content, true);
				if(is_array($error_data) && isset($error_data['status']) && $error_data['status'] === 'error' && !empty($error_data['message'])){
					return $this->error('api_http_error', $error_data['message'], 'get_attachments', $response_code);
				}
				return $this->error('api_http_error', sprintf(__('API returned HTTP %d error.', 'templately'), $response_code), 'get_attachments', $response_code);
			}

			// Validate we have XML content
			if (empty($xml_content)) {
				return $this->error('no_xml_content', __('No XML content found in API response.', 'templately'), 'get_attachments', 404);
			}

			// Parse the XML content from API response
			$parsed_data = $this->parse_xml_content($xml_content);

			if (is_wp_error($parsed_data)) {
				return $this->error('xml_parse_error', __('Failed to parse XML content.', 'templately'), 'get_attachments', 500, ['error_detail' => $parsed_data->get_error_message()]);
			}

			// Extract attachments from parsed data
			$attachments = $this->extract_attachments_from_parsed_data($parsed_data);

			return [
				'status' => 'success',
				'data' => $attachments,
				'message' => sprintf(__('Found %d attachments.', 'templately'), count($attachments)),
			];

		} catch (Exception $e) {
			return $this->error('exception', __('An unexpected error occurred while fetching attachments.', 'templately'), 'get_attachments', 500, ['error_detail' => $e->getMessage()]);
		}
	}



	/**
	 * Parse XML content string using WXR Parser
	 *
	 * @param string $xml_content XML content string
	 * @return array|\WP_Error Parsed data or error
	 */
	private function parse_xml_content($xml_content) {
		// Ensure WordPress filesystem functions are available
		if (!function_exists('wp_tempnam')) {
			require_once(ABSPATH . 'wp-admin/includes/file.php');
		}

		// Create a temporary file to store XML content
		$temp_file = wp_tempnam('templately_attachments');
		if (!$temp_file) {
			return new WP_Error('temp_file_failed', __('Failed to create temporary file.', 'templately'));
		}

		// Write XML content to temporary file
		$bytes_written = file_put_contents($temp_file, $xml_content);
		if ($bytes_written === false) {
			unlink($temp_file);
			return new WP_Error('write_failed', __('Failed to write XML content to temporary file.', 'templately'));
		}

		try {
			// Initialize WXR Parser
			$parser = new WXR_Parser();

			// Parse the temporary XML file
			$parsed_data = $parser->parse($temp_file);

			// Clean up temporary file
			unlink($temp_file);

			return $parsed_data;

		} catch (Exception $e) {
			// Clean up temporary file on exception
			if (file_exists($temp_file)) {
				unlink($temp_file);
			}
			return new WP_Error('parse_exception', $e->getMessage());
		}
	}

	/**
	 * Extract attachments from parsed WXR data
	 *
	 * @param array $parsed_data Parsed WXR data
	 * @return array Array of attachment data
	 */
	private function extract_attachments_from_parsed_data($parsed_data) {
		$attachments = [];

		if (isset($parsed_data['posts']) && is_array($parsed_data['posts'])) {
			foreach ($parsed_data['posts'] as $post) {
				// Check if this is an attachment
				if (isset($post['post_type']) && $post['post_type'] === 'attachment') {
					$attachment = [
						'id' => isset($post['post_id']) ? (int) $post['post_id'] : 0,
						'url' => isset($post['attachment_url']) ? (string) $post['attachment_url'] : '',
						'title' => isset($post['post_title']) ? (string) $post['post_title'] : '',
						'type' => isset($post['attachment_type']) ? (string) $post['attachment_type'] : '',
					];

					// Extract metadata including dimensions and medium URL
					$metadata = $this->extract_medium_size_url($post, $attachment['url']);

					// Filter out small images (width or height <= 150px) to ignore small icons
					if ($metadata && isset($metadata['width']) && isset($metadata['height'])) {
						if ($metadata['width'] < 150 || $metadata['height'] < 150) {
							continue; // Skip small images/icons
						}

						// Add dimensions to attachment data
						$attachment['width'] = $metadata['width'];
						$attachment['height'] = $metadata['height'];

						// Add medium URL if available
						if (isset($metadata['medium_url'])) {
							$attachment['medium_url'] = $metadata['medium_url'];
						}
					} else {
						// Skip attachments without metadata or dimensions
						continue;
					}

					// Only add if we have the required data
					if ($attachment['id'] && $attachment['url'] && $attachment['title']) {
						$attachments[] = $attachment;
					}
				}
			}
		}

		return $attachments;
	}

	/**
	 * Extract medium size URL from attachment metadata and get image dimensions
	 *
	 * @param array $post Post data from WXR parser
	 * @param string $original_url Original attachment URL
	 * @return array|null Array with medium_url and dimensions if found, null otherwise
	 */
	private function extract_medium_size_url($post, $original_url) {
		if (!isset($post['postmeta']) || !is_array($post['postmeta'])) {
			return null;
		}

		foreach ($post['postmeta'] as $meta) {
			if (!isset($meta['key']) || !isset($meta['value'])) {
				continue;
			}

			// Only check _wp_attachment_metadata
			if ($meta['key'] === '_wp_attachment_metadata') {
				$attachment_metadata = @unserialize($meta['value']);
				if (is_array($attachment_metadata)) {
					$result = [];

					// Get original image dimensions
					$width = isset($attachment_metadata['width']) ? (int) $attachment_metadata['width'] : 0;
					$height = isset($attachment_metadata['height']) ? (int) $attachment_metadata['height'] : 0;

					$result['width'] = $width;
					$result['height'] = $height;

					// Check if medium size exists
					if (isset($attachment_metadata['sizes']['medium']['file'])) {
						// Construct medium URL from original URL and medium filename
						$medium_filename = $attachment_metadata['sizes']['medium']['file'];
						$original_path = dirname(parse_url($original_url, PHP_URL_PATH));
						$base_url = str_replace(parse_url($original_url, PHP_URL_PATH), '', $original_url);
						$result['medium_url'] = $base_url . $original_path . '/' . $medium_filename;
					}

					return $result;
				}
			}
		}

		return null;
	}



	/**
	 * Search images endpoint
	 *
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|WP_Error
	 */
	public function search_images(WP_REST_Request $request) {
		// Get and sanitize parameters
		$query = $this->get_param('query', '');
		$orientation = $this->get_param('orientation', 'all');
		$size = $this->get_param('size', 'medium');
		$color = $this->get_param('color', '');
		$page = $this->get_param('page', 1, 'absint');
		$per_page = $this->get_param('per_page', 20, 'absint');

		// Validate required query parameter
		if (empty($query)) {
			return $this->error(
				'missing_query',
				__('Search query is required.', 'templately'),
				'search_images',
				400
			);
		}

		// Prepare API request parameters
		$api_params = [
			'query' => urlencode($query),
			'page' => $page,
			'per_page' => $per_page,
		];

		// Add optional parameters if provided
		if ($orientation !== 'all') {
			$api_params['orientation'] = $orientation;
		}

		if (!empty($size)) {
			$api_params['size'] = $size;
		}

		if (!empty($color)) {
			$api_params['color'] = $color;
		}

		// Make API request to external image service
		$extra_headers = [
			'Content-Type' => 'application/json',
		];

		$response = Helper::make_api_get_request('v2/images', $api_params, $extra_headers, 30);

		// Handle API response errors
		if (is_wp_error($response)) {
			return $this->error(
				'api_request_failed',
				__('Failed to fetch images from external service.', 'templately'),
				'search_images',
				500
			);
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);

		if ($response_code !== 200) {
			return $this->error(
				'api_response_error',
				sprintf(__('External API returned error code: %d', 'templately'), $response_code),
				'search_images',
				$response_code
			);
		}

		// Parse and validate response
		$data = json_decode($response_body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return $this->error(
				'invalid_response',
				__('Invalid response from external service.', 'templately'),
				'search_images',
				500
			);
		}

		// Check if the response has the expected structure and success status
		if (!isset($data['status']) || $data['status'] !== 'success') {
			return $this->error(
				'api_response_error',
				__('External API returned an error status.', 'templately'),
				'search_images',
				500
			);
		}

		// Extract nested data from the response
		$response_data = $data['data'] ?? [];
		$images = $response_data['images'] ?? [];
		$total_results = $response_data['total_results'] ?? 0;
		$current_page = $response_data['page'] ?? $page;
		$per_page_count = $response_data['per_page'] ?? $per_page;

		// Return successful response with properly mapped data
		return $this->success([
			'images' => $images,
			'total' => $total_results,
			'page' => $current_page,
			'per_page' => $per_page_count,
			'total_pages' => $total_results > 0 ? ceil($total_results / $per_page_count) : 0,
		]);
	}



	/**
	 * Generate tagline using AI
	 *
	 * @return array|WP_Error
	 */
	public function generate_tagline() {
		// Get parameters
		$prompt = $this->get_param('prompt');
		$requested_platform = $this->get_param('requested_platform', 'templately');

		// Validate required parameters
		if (empty($prompt)) {
			return $this->error(
				'missing_prompt',
				__('Prompt is required for tagline generation.', 'templately'),
				'generate_tagline',
				400
			);
		}

		// Prepare request body
		$body_data = [
			'prompt' => $prompt,
		];

		// Make API request
		$extra_headers = [
			'Content-Type' => 'application/json',
			'x-templately-requested-platform' => $requested_platform,
		];

		$response = Helper::make_api_post_request('v2/generate-tagline', $body_data, $extra_headers, 30);

		// Handle API response errors
		if (is_wp_error($response)) {
			return $this->error(
				'api_request_failed',
				__('Failed to generate tagline.', 'templately'),
				'generate_tagline',
				500,
				['error_detail' => $response->get_error_message()]
			);
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$response_body = wp_remote_retrieve_body($response);

		if ($response_code !== 200) {
			// Try to parse the response body as JSON to get specific error details
			$data = json_decode($response_body, true);

			// If valid JSON, extract error message and return with proper status code
			if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
				$error_message = isset($data['message']) ? $data['message'] : __('Something went wrong. Please try again or contact support.', 'templately');
				return $this->error(
					'api_response_error',
					$error_message,
					'generate_tagline',
					$response_code
				);
			}

			// Otherwise, return generic error
			return $this->error(
				'api_response_error',
				__('Something went wrong. Please try again or contact support.', 'templately'),
				'generate_tagline',
				$response_code
			);
		}

		// Parse and validate response
		$data = json_decode($response_body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return $this->error(
				'invalid_response',
				__('Invalid response from API.', 'templately'),
				'generate_tagline',
				500
			);
		}

		// Check if the response has the expected structure
		if (!isset($data['status'])) {
			return $this->error(
				'api_response_error',
				__('API returned an unexpected response.', 'templately'),
				'generate_tagline',
				500
			);
		}

		// Return the response as-is
		return $data;
	}

	/**
	 * Fetch a chatbot conversation by ID from the external Templately chatbot API.
	 *
	 * Used to resume a conversation that began on templately.dev when the user
	 * is redirected into the plugin with ?process=ai&chat={uuid}.
	 *
	 * @return array|\WP_Error Pass-through of the external response { status, data } or WP_Error.
	 */
	public function get_chatbot_conversation() {
		$chat = $this->get_param('chat');

		if (empty($chat)) {
			return $this->error('invalid_chat_id', __('Invalid conversation ID.', 'templately'), 'ai-content/chatbot-conversation', 400);
		}

		$extra_headers = [
			'Accept' => 'application/json',
		];
		$response = Helper::make_api_get_request("v2/chatbot/conversation/{$chat}", [], $extra_headers, 30);

		if (is_wp_error($response)) {
			return $this->error('request_failed', __('Failed to fetch conversation.', 'templately'), 'ai-content/chatbot-conversation', 500, ['error_detail' => $response->get_error_message()]);
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$body          = wp_remote_retrieve_body($response);
		$data          = json_decode($body, true);

		if ($response_code !== 200) {
			$message = (is_array($data) && !empty($data['message'])) ? $data['message'] : sprintf(__('API returned HTTP %d error.', 'templately'), $response_code);
			return $this->error('api_http_error', $message, 'ai-content/chatbot-conversation', $response_code);
		}

		if (!is_array($data) || !isset($data['status'])) {
			return $this->error('invalid_response', __('Invalid response.', 'templately'), 'ai-content/chatbot-conversation', 500);
		}

		return $data;
	}

	/**
	 * List the connected account's generated AI sites, newest first.
	 *
	 * Backs the "My AI Sites" screen, which exists so a user can re-import a
	 * generation at any time instead of having to keep its templately.com link.
	 *
	 * Each row carries `can_import` / `is_expired` / `is_pro` / `expires_at`,
	 * computed server-side (free accounts get 7 days from generation, entitled
	 * accounts import forever). Render those as given — recomputing the window
	 * here would silently drift from the backend the moment the rule changes.
	 *
	 * @return array|\WP_Error Pass-through of { status, current_page, total_page, total, data } or WP_Error.
	 */
	public function get_chatbot_conversations() {
		$page     = max(1, (int) $this->get_param('page', 1, 'absint'));
		$per_page = min(100, max(1, (int) $this->get_param('per_page', 10, 'absint')));

		$extra_headers = [
			'Accept' => 'application/json',
		];
		$response = Helper::make_api_get_request('v2/chatbot/conversations', [
			'page'     => $page,
			'per_page' => $per_page,
		], $extra_headers, 30);

		if (is_wp_error($response)) {
			return $this->error('request_failed', __('Failed to fetch your AI sites.', 'templately'), 'ai-content/chatbot-conversations', 500, ['error_detail' => $response->get_error_message()]);
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$body          = wp_remote_retrieve_body($response);
		$data          = json_decode($body, true);

		if ($response_code !== 200) {
			$message = (is_array($data) && !empty($data['message'])) ? $data['message'] : sprintf(__('API returned HTTP %d error.', 'templately'), $response_code);
			return $this->error('api_http_error', $message, 'ai-content/chatbot-conversations', $response_code);
		}

		if (!is_array($data) || !isset($data['status'])) {
			return $this->error('invalid_response', __('Invalid response.', 'templately'), 'ai-content/chatbot-conversations', 500);
		}

		return $data;
	}

	/**
	 * Fetch the server-side generated content for a chatbot conversation (Phase 2).
	 *
	 * In Phase 2 the AI content is generated on the backend. This proxy mirrors
	 * {@see get_chatbot_conversation()} and returns the already-generated page
	 * content, customization data and signed logo URL so the plugin can run a
	 * thin import without triggering generation or the customizer locally.
	 *
	 * @return array|\WP_Error Pass-through of the external response { status, data } or WP_Error.
	 */
	public function get_chatbot_generated() {
		$chat = $this->get_param('chat');

		if (empty($chat)) {
			return $this->error('invalid_chat_id', __('Invalid conversation ID.', 'templately'), 'ai-content/chatbot-generated', 400);
		}

		$extra_headers = [
			'Accept' => 'application/json',
		];
		$response = Helper::make_api_get_request("v2/chatbot/generated/{$chat}", [], $extra_headers, 30);

		if (is_wp_error($response)) {
			return $this->error('request_failed', __('Failed to fetch generated content.', 'templately'), 'ai-content/chatbot-generated', 500, ['error_detail' => $response->get_error_message()]);
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$body          = wp_remote_retrieve_body($response);
		$data          = json_decode($body, true);

		if ($response_code !== 200) {
			$message = (is_array($data) && !empty($data['message'])) ? $data['message'] : sprintf(__('API returned HTTP %d error.', 'templately'), $response_code);
			return $this->error('api_http_error', $message, 'ai-content/chatbot-generated', $response_code);
		}

		if (!is_array($data) || !isset($data['status'])) {
			return $this->error('invalid_response', __('Invalid response.', 'templately'), 'ai-content/chatbot-generated', 500);
		}

		// The direct-import handoff reads this endpoint and then immediately calls
		// chatbot-import-prepare, which pulls the very same (large) bundle off GCP
		// seconds later. Park it so prepare can reuse it instead of paying for a
		// second identical transfer.
		Database::set_transient(self::GENERATED_CACHE_KEY . $chat, $data, self::GENERATED_CACHE_TTL);

		return $data;
	}

	/**
	 * Persist edited detected-info back to the chatbot conversation (Phase 2).
	 *
	 * Mirrors {@see get_chatbot_conversation()} / {@see get_chatbot_generated()}
	 * but forwards a POST. When the user edits the detected-info card in the
	 * sidebar, the plugin proxies the corrected values to the backend so a later
	 * replay reflects them. The backend route is gated by the X-Templately-Apikey
	 * header, so it is supplied explicitly here.
	 *
	 * Expected JSON body: { chat, detected_info: { ...fields } }
	 *
	 * @return array|\WP_Error Pass-through of the external response { status, data } or WP_Error.
	 */
	public function update_chatbot_detected_info() {
		$chat          = $this->get_param('chat');
		$detected_info = $this->get_param('detected_info', [], null);

		if (empty($chat)) {
			return $this->error('invalid_chat_id', __('Invalid conversation ID.', 'templately'), 'ai-content/chatbot-detected-info', 400);
		}

		// detected_info may arrive as a JSON string when sent via FormData.
		if (is_string($detected_info)) {
			$decoded       = json_decode($detected_info, true);
			$detected_info = is_array($decoded) ? $decoded : [];
		}
		if (!is_array($detected_info)) {
			$detected_info = [];
		}

		$extra_headers = [
			'Accept'              => 'application/json',
			'X-Templately-Apikey' => $this->api_key,
		];
		$response = Helper::make_api_post_request("v2/chatbot/conversation/{$chat}/detected-info", ['detected_info' => $detected_info], $extra_headers, 30);

		if (is_wp_error($response)) {
			return $this->error('request_failed', __('Failed to update detected info.', 'templately'), 'ai-content/chatbot-detected-info', 500, ['error_detail' => $response->get_error_message()]);
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$body          = wp_remote_retrieve_body($response);
		$data          = json_decode($body, true);

		if ($response_code !== 200) {
			$message = (is_array($data) && !empty($data['message'])) ? $data['message'] : sprintf(__('API returned HTTP %d error.', 'templately'), $response_code);
			return $this->error('api_http_error', $message, 'ai-content/chatbot-detected-info', $response_code);
		}

		if (!is_array($data) || !isset($data['status'])) {
			return $this->error('invalid_response', __('Invalid response.', 'templately'), 'ai-content/chatbot-detected-info', 500);
		}

		return $data;
	}

	/**
	 * Report a completed import back to templately.dev (Phase 2 import-once gate).
	 *
	 * Once the plugin finishes importing the generated content for a conversation,
	 * it calls this so the backend flips the conversation status to `imported`.
	 * Subsequent pulls then return `already_imported = true`, and the web/plugin
	 * UIs refuse a second import.
	 *
	 * @return array|\WP_Error
	 */
	public function mark_chatbot_imported() {
		$chat = $this->get_param('chat');

		if (empty($chat)) {
			return $this->error('invalid_chat_id', __('Invalid conversation ID.', 'templately'), 'ai-content/chatbot-mark-imported', 400);
		}

		$extra_headers = [
			'Accept'              => 'application/json',
			'X-Templately-Apikey' => $this->api_key,
		];
		$response = Helper::make_api_post_request("v2/chatbot/conversation/{$chat}/imported", [], $extra_headers, 30);

		if (is_wp_error($response)) {
			return $this->error('request_failed', __('Failed to record import.', 'templately'), 'ai-content/chatbot-mark-imported', 500, ['error_detail' => $response->get_error_message()]);
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$body          = wp_remote_retrieve_body($response);
		$data          = json_decode($body, true);

		if ($response_code !== 200) {
			$message = (is_array($data) && !empty($data['message'])) ? $data['message'] : sprintf(__('API returned HTTP %d error.', 'templately'), $response_code);
			return $this->error('api_http_error', $message, 'ai-content/chatbot-mark-imported', $response_code);
		}

		if (!is_array($data) || !isset($data['status'])) {
			return $this->error('invalid_response', __('Invalid response.', 'templately'), 'ai-content/chatbot-mark-imported', 500);
		}

		return $data;
	}

	/**
	 * Thin importer prepare step (Phase 2).
	 *
	 * Given a chat uuid and a session that has already been created and had its
	 * pack downloaded (via the existing templately_pack_create_session_and_download
	 * AJAX flow), this:
	 *   1. Registers AI process data (including `chat_id`) so ai_get_json()/
	 *      validation keep working AND so the Finalizer's ChatAIContentProvider
	 *      can claim this process.
	 *   2. Fetches the backend-generated page content for the conversation.
	 *   3. Writes whatever pages are ALREADY generated to the same .ai.json
	 *      location the legacy flow uses (via AIUtils::save_template_to_file).
	 *   4. Downloads the signed logo URL into the WP media library (Utils::upload_logo).
	 *
	 * This endpoint NEVER waits for generation to complete. Pages still being
	 * generated are reported in `missing` and are pulled on demand — and waited
	 * for — by the Finalizer via AIContentResolver. Previously this held the
	 * client in a 3s poll loop until every page was ready, which delayed the
	 * start of the import by minutes for no benefit.
	 *
	 * It returns the resolved customization data, logo attachment and the
	 * process_id so the React app can build the settings FormData and run the
	 * existing import. No generation or local customizer is involved.
	 *
	 * Expected JSON body: { chat, session_id, ai_page_ids: { 'content/page': [...], templates: [...] } }
	 *
	 * @return array|\WP_Error
	 */
	/**
	 * Does a `v2/chatbot/generated` bundle already carry every expected page?
	 *
	 * A page counts as present when it is in `templates` (string or int key, the
	 * upstream is inconsistent) or listed in `skipped_pages` — a skipped page is
	 * never coming, so waiting on it would hang the poll until it timed out.
	 *
	 * @param array $data        Decoded `{ status, data }` bundle.
	 * @param array $expected_ids Flattened page ids, as strings.
	 * @return bool
	 */
	private function is_generated_bundle_complete($data, $expected_ids) {
		$generated = isset($data['data']) && is_array($data['data']) ? $data['data'] : [];

		// Never reuse a bundle the user is no longer allowed to import.
		if (isset($generated['can_import']) && ! $generated['can_import']) {
			return false;
		}

		$templates = isset($generated['templates']) && is_array($generated['templates']) ? $generated['templates'] : [];
		$skipped   = isset($generated['skipped_pages']) && is_array($generated['skipped_pages']) ? array_map('strval', $generated['skipped_pages']) : [];

		if (empty($templates) && empty($skipped)) {
			return false;
		}

		foreach ($expected_ids as $id) {
			if (array_key_exists($id, $templates) || array_key_exists((int) $id, $templates) || in_array($id, $skipped, true)) {
				continue;
			}
			return false;
		}

		return true;
	}

	/**
	 * Resolve the conversation answers to store on a chat-driven process.
	 *
	 * Prefers what the client sent (the sidebar holds the detected info the user
	 * may have just edited), then what was already stored for this process (so a
	 * repeat call costs nothing), and only then pulls the conversation from the
	 * cloud — which is the path the `?process=import` landing takes, since it
	 * never opens the sidebar and so has no answers to send.
	 *
	 * A failure here must never break the import: it returns an empty array and
	 * the process is stored exactly as before.
	 *
	 * @param string $chat            Conversation uuid.
	 * @param mixed  $detected_info   Raw `detected_info` sent by the client.
	 * @param array  $ai_process_data All stored process data.
	 * @param string $process_id      This process id.
	 * @return array<string,string> Map of conversation step key => value.
	 */
	private function resolve_chat_conversation_fields($chat, $detected_info, $ai_process_data, $process_id) {
		$mapped = AIUtils::map_chat_detected_info($detected_info);
		if (!empty($mapped)) {
			return $mapped;
		}

		// Already resolved on an earlier call for this process — reuse it.
		if (isset($ai_process_data[$process_id]) && AIUtils::has_conversation_data($ai_process_data[$process_id])) {
			return AIUtils::map_chat_detected_info(
				array_intersect_key($ai_process_data[$process_id], array_flip(AIUtils::CONVERSATION_FIELDS))
			);
		}

		$response = Helper::make_api_get_request("v2/chatbot/conversation/{$chat}", [], ['Accept' => 'application/json'], 30);
		if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
			Helper::log(sprintf('chatbot_import_prepare[%s] conversation fetch failed — process stored without answers', $chat), 'ai-import', 'warning');
			return [];
		}

		$data = json_decode(wp_remote_retrieve_body($response), true);
		if (!is_array($data) || empty($data['data']['detected_info'])) {
			return [];
		}

		return AIUtils::map_chat_detected_info($data['data']['detected_info']);
	}

	public function chatbot_import_prepare() {
		add_filter('wp_redirect', '__return_false', 999);
		set_time_limit(3 * MINUTE_IN_SECONDS);

		$handler_started = microtime(true);

		$chat        = $this->get_param('chat');
		$session_id  = $this->get_param('session_id');
		$ai_page_ids = $this->get_param('ai_page_ids', [], null);
		// Conversation context, so reopening "Build with AI" later can resume this
		// app-end session instead of starting from scratch. `detected_info` is
		// sanitized field-by-field in AIUtils::map_chat_detected_info().
		$pack_id       = $this->get_param('pack_id', 0, 'absint');
		$platform      = $this->get_param('platform');
		$detected_info = $this->get_param('detected_info', [], null);

		if (empty($chat)) {
			return $this->error('invalid_chat_id', __('Invalid conversation ID.', 'templately'), 'ai-content/chatbot-import-prepare', 400);
		}

		if (empty($session_id)) {
			return $this->error('invalid_session_id', __('Invalid session ID.', 'templately'), 'ai-content/chatbot-import-prepare', 400);
		}

		// Security: sanitize the session id before it is used to build file paths.
		$session_id = AIUtils::sanitize_path_component($session_id, 'session_id');
		if (is_wp_error($session_id)) {
			return $this->error('invalid_session_id', $session_id->get_error_message(), 'ai-content/chatbot-import-prepare', 400);
		}

		// ai_page_ids may arrive as a JSON string when sent via FormData, and with
		// scalar / comma-separated group values — normalize to the canonical
		// `type/sub_type => ['id',...]` shape before anything indexes into it.
		$ai_page_ids = AIUtils::normalize_ai_page_ids($ai_page_ids);
		if (empty($ai_page_ids)) {
			return $this->error('invalid_ai_page_ids', __('Invalid AI page IDs.', 'templately'), 'ai-content/chatbot-import-prepare', 400);
		}

		// Expected page ids (flattened) — the client may redirect to customization
		// as soon as the home/header/footer are ready, so by import time some pages
		// can still be generating. We re-pull until every expected page is present
		// (bounded wait), then proceed; anything still missing falls back to the
		// pack's default content.
		$expected_ids = AIUtils::flatten_ai_page_ids($ai_page_ids);

		$extra_headers = ['Accept' => 'application/json'];
		$cache_key     = self::GENERATED_CACHE_KEY . $chat;

		// This endpoint NEVER waits for completeness. The import starts as soon as
		// the session exists; whatever pages are already generated are written
		// here as a warm start, and any page still generating is pulled on demand
		// by the Finalizer (Core/Importer/Utils/AIContentResolver +
		// Providers/ChatAIContentProvider).
		//
		// Reuse the bundle chatbot-generated just parked, but ONLY when it already
		// holds every expected page — a complete bundle cannot become less
		// complete, whereas an incomplete one has to be re-pulled to pick up the
		// pages that have since finished. On the common handoff (generation
		// finished long before the user landed here) this removes an entire
		// duplicate transfer of every page's block JSON.
		$pull_started  = microtime(true);
		$pull_duration = 0;
		$data          = Database::get_transient($cache_key);
		$from_cache    = is_array($data) && $this->is_generated_bundle_complete($data, $expected_ids);

		if (! $from_cache) {
			// Single pull — no server-side sleep/retry. The user can reach import as
			// soon as the key pages (home/header/footer) are ready while the rest are
			// still generating; rather than hold the request open until everything is
			// done (which tripped a gateway 504), we return a non-fatal `pending`
			// status and let the client poll (JS-side pull).
			$response      = Helper::make_api_get_request("v2/chatbot/generated/{$chat}", [], $extra_headers, 2 * MINUTE_IN_SECONDS);
			$pull_duration = microtime(true) - $pull_started;

			if (is_wp_error($response)) {
				Helper::log(sprintf('chatbot_import_prepare[%s] pull failed after %.2fs: %s', $chat, $pull_duration, $response->get_error_message()), 'ai-import', 'error');
				return $this->error('request_failed', __('Failed to fetch generated content.', 'templately'), 'ai-content/chatbot-import-prepare', 500, ['error_detail' => $response->get_error_message()]);
			}

			$response_code = wp_remote_retrieve_response_code($response);
			$data          = json_decode(wp_remote_retrieve_body($response), true);

			if ($response_code !== 200 || !is_array($data) || !isset($data['status'])) {
				$message = (is_array($data) && !empty($data['message'])) ? $data['message'] : sprintf(__('API returned HTTP %d error.', 'templately'), $response_code);
				Helper::log(sprintf('chatbot_import_prepare[%s] pull HTTP %d after %.2fs', $chat, $response_code, $pull_duration), 'ai-import', 'error');
				return $this->error('api_http_error', $message, 'ai-content/chatbot-import-prepare', $response_code ?: 500);
			}

			// Park a complete bundle for the credits re-read on the success screen.
			if ($this->is_generated_bundle_complete($data, $expected_ids)) {
				Database::set_transient($cache_key, $data, self::GENERATED_CACHE_TTL);
			}
		} else {
			Helper::log(sprintf('chatbot_import_prepare[%s] reused cached bundle (no upstream pull)', $chat), 'ai-import', 'info');
		}

		$generated = isset($data['data']) && is_array($data['data']) ? $data['data'] : [];

		// Access gate: the backend blocks a free user past their 7-day window.
		if (isset($generated['can_import']) && ! $generated['can_import']) {
			return $this->error('access_expired', __('Your free access to this generated site has ended. Upgrade your plan or purchase this template to import it.', 'templately'), 'ai-content/chatbot-import-prepare', 403);
		}

		$templates = isset($generated['templates']) && is_array($generated['templates']) ? $generated['templates'] : [];

		// Pages the backend skipped (empty source JSON) or failed to generate.
		// These will never appear in `templates`, so they must not be treated
		// as "still generating" — without this, one skipped page keeps the poll
		// pending until it times out.
		$skipped_pages    = isset($generated['skipped_pages']) && is_array($generated['skipped_pages']) ? array_map('strval', $generated['skipped_pages']) : [];
		$skipped_expected = array_values(array_intersect($expected_ids, $skipped_pages));

		// Which expected pages are still missing from the bundle?
		$missing = array_values(array_filter($expected_ids, function ($id) use ($templates, $skipped_pages) {
			return !array_key_exists($id, $templates) && !array_key_exists((int) $id, $templates) && !in_array($id, $skipped_pages, true);
		}));

		$ready = array_values(array_diff($expected_ids, $missing));
		Helper::log(sprintf('chatbot_import_prepare[%s] warm start: ready=%d/%d missing=%d skipped=%d pull=%.2fs', $chat, count($ready), count($expected_ids), count($missing), count($skipped_expected), $pull_duration), 'ai-import', 'info');


		// Derive a process_id for this chat-driven import and register process data
		// so the existing validation/ai_get_json paths keep functioning.
		$process_id = 'chat-' . $session_id;
		$user       = $this->utils('options')->get('user');

		$ai_process_data = AIUtils::get_ai_process_data();
		$record = [
			'process_id'  => $process_id,
			'session_id'  => $session_id,
			'ai_page_ids' => $ai_page_ids,
			'api_key'     => $this->api_key,
			'user_id'     => isset($user['id']) ? $user['id'] : null,
			'chat_id'     => $chat,
		];

		if (!empty($pack_id)) {
			$record['pack_id'] = $pack_id;
		}
		if (!empty($platform)) {
			$record['platform'] = $platform;
		}

		// Persist the app-end answers exactly as an in-plugin conversation stores
		// them, so reopening "Build with AI" resumes this session instead of
		// starting over. Without this the record holds no answers at all and the
		// sidebar has nothing to restore.
		$conversation = AIUtils::build_conversation_fields(
			$this->resolve_chat_conversation_fields($chat, $detected_info, $ai_process_data, $process_id)
		);
		if (!empty($conversation)) {
			$record = array_merge($record, $conversation);
		}

		$ai_process_data[$process_id] = $record;
		AIUtils::update_ai_process_data($ai_process_data);

		// Persist each generated page to its .ai.json location for the import runners.
		//
		// Every page present in THIS pull is written immediately, even when others
		// are still generating. Holding the writes back until the whole set was
		// ready meant a single slow page threw away the full bundle on every poll
		// — dozens of multi-hundred-KB pulls (all of `templates`, straight off GCP)
		// discarded to save nothing. Writing as we go also lets the Finalizer
		// runner finalize the pages that ARE ready instead of blocking on all of
		// them. Already-written pages are skipped, so a re-poll is cheap.
		$save_started    = microtime(true);
		$saved_count     = 0;
		$errors          = [];
		$processed_pages = get_option('templately_ai_processed_pages', []);
		$already_saved   = isset($processed_pages[$process_id]['pages']) ? $processed_pages[$process_id]['pages'] : [];
		foreach ($templates as $content_id => $template) {
			if (empty($template)) {
				continue;
			}

			if (array_key_exists((string) $content_id, $already_saved)) {
				$saved_count++;
				continue;
			}

			// The runners read JSON strings; normalize arrays/objects to a string.
			$template_payload = is_string($template) ? $template : wp_json_encode($template);

			$result = AIUtils::save_template_to_file(
				$process_id,
				$session_id,
				$content_id,
				$template_payload,
				$ai_page_ids,
				false
			);

			if (is_wp_error($result)) {
				$errors[$content_id] = $result->get_error_message();
				continue;
			}
			if (isset($result['status']) && $result['status'] === 'success') {
				$saved_count++;
			} else {
				$errors[$content_id] = isset($result['message']) ? $result['message'] : 'unknown';
			}
		}

		// Write each backend-skipped page as an explicit `{"isSkipped": true}`
		// marker (same shape the legacy per-page callback wrote) so the import
		// runners fall back to the pack's default content instead of treating
		// the page as missing.
		$skipped_saved = [];
		foreach ($skipped_expected as $skipped_id) {
			if (array_key_exists($skipped_id, $templates) || array_key_exists((int) $skipped_id, $templates)) {
				continue;
			}

			if (array_key_exists((string) $skipped_id, $already_saved)) {
				$skipped_saved[] = $skipped_id;
				continue;
			}

			$result = AIUtils::save_template_to_file(
				$process_id,
				$session_id,
				$skipped_id,
				'',
				$ai_page_ids,
				true
			);

			if (is_wp_error($result)) {
				$errors[$skipped_id] = $result->get_error_message();
				continue;
			}
			if (isset($result['status']) && $result['status'] === 'success') {
				$skipped_saved[] = $skipped_id;
			} else {
				$errors[$skipped_id] = isset($result['message']) ? $result['message'] : 'unknown';
			}
		}

		$save_duration = microtime(true) - $save_started;
		Helper::log(sprintf('chatbot_import_prepare[%s] saved %d/%d pages in %.2fs (skipped=%d, errors=%d)', $chat, $saved_count, count($templates), $save_duration, count($skipped_saved), count($errors)), 'ai-import', 'info');

		// NOTE: there is deliberately NO `pending` return here any more. Pages that
		// are still generating come back in `missing` and are pulled on demand —
		// and waited for — by the Finalizer. Returning `pending` made the client
		// poll for minutes before the import could even start.
		//
		// NOT an error when nothing was saved: with the wait deferred to the
		// Finalizer it is legitimate for zero pages to be ready at import start.
		// Only a genuine write failure (something was ready but every save
		// errored) is fatal.
		if ($saved_count === 0 && empty($skipped_saved) && !empty($errors)) {
			return $this->error('save_failed', __('Failed to save generated content.', 'templately'), 'ai-content/chatbot-import-prepare', 500, ['errors' => $errors]);
		}

		Helper::log(sprintf('chatbot_import_prepare[%s] returning: pages=%d missing=%d pull=%.2fs', $chat, count($templates), count($missing), $pull_duration), 'ai-import', 'info');

		// Import the logo into the media library and map it into the customization.
		$customization = isset($generated['customization_data']) && is_array($generated['customization_data']) ? $generated['customization_data'] : [];
		$logo          = null;
		$logo_url      = !empty($generated['logo_url']) ? esc_url_raw($generated['logo_url']) : '';

		if (!empty($logo_url)) {
			$logo_started = microtime(true);
			$uploaded = Utils::upload_logo($logo_url, $session_id);
			Helper::log(sprintf('chatbot_import_prepare[%s] logo upload in %.2fs', $chat, microtime(true) - $logo_started), 'ai-import', 'info');
			if (!empty($uploaded['id'])) {
				$logo = [
					'id'  => (int) $uploaded['id'],
					'url' => $uploaded['url'],
				];
			} elseif (!empty($uploaded['error'])) {
				// Logo is non-fatal: log and continue without it.
				Helper::log('chatbot_import_prepare logo upload failed: ' . $uploaded['error']);
			}
		}

		// Reflect the imported logo back into the customization payload so React
		// can build the settings FormData from a single source.
		if (!empty($logo)) {
			$customization['logo'] = $logo;
		}

		Helper::log(sprintf('chatbot_import_prepare[%s] done in %.2fs total', $chat, microtime(true) - $handler_started), 'ai-import', 'info');

		return [
			'status' => 'success',
			'data'   => [
				'session_id'         => $session_id,
				'process_id'         => $process_id,
				'ai_page_ids'        => $ai_page_ids,
				'saved'              => $saved_count,
				// Pages the backend explicitly skipped — imported with the
				// pack's default content instead.
				'skipped'            => $skipped_expected,
				// Readiness snapshot at import start. `missing` pages are NOT a
				// failure: the Finalizer pulls each on demand and waits for it.
				'expected'           => $expected_ids,
				'ready'              => $ready,
				'missing'            => $missing,
				'platform'           => isset($customization['platform']) ? $customization['platform'] : null,
				'customization_data' => $customization,
				'logo'               => $logo,
				'errors'             => $errors,
			],
		];
	}

	/**
	 * Validate API key against database
	 * Checks if the provided API key exists for any user on the current site
	 * Handles both single-site and multisite WordPress installations
	 *
	 * @param string $api_key The API key to validate
	 * @return bool True if valid, false otherwise
	 */
	private function validate_api_key_in_db($api_key) {
		global $wpdb;

		$api_key = sanitize_text_field($api_key);

		if (empty($api_key)) {
			return false;
		}

		$meta_key = '_templately_api_key';

		// Handle multisite: key will have site prefix in multisite
		if (is_multisite()) {
			// get_user_option() uses the format: {$wpdb->base_prefix}{$blog_id}_{$meta_key}
			// For current blog, we need to check with the current blog prefix
			$blog_id = get_current_blog_id();
			$meta_key = $wpdb->get_blog_prefix($blog_id) . $meta_key;
		}

		// Query to check if this API key exists for any user
		$query = $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s AND meta_value = %s LIMIT 1",
			$meta_key,
			$api_key
		);

		$user_id = $wpdb->get_var($query);

		return !empty($user_id);
	}
}
