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
			Helper::log( [
				'headers' => $request->get_headers(),
				'body'    => $request->get_params(),
			], 'ai_update_request' );

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
		$this->post($this->endpoint . '/generate-logo', [$this, 'generate_logo']);
		$this->post($this->endpoint . '/generate-meta', [$this, 'generate_meta']);
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
		$language            = $this->get_param('language', 'English');

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
			return $this->error('request_failed', __('Request failed.', 'templately'), 'modify_content', 500, $response->get_error_data());
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
				'user_id'         => isset($user['id']) ? $user['id'] : null,
				'session_id'      => $session_id,        // Store session_id for coordination
				'imageReplace'    => $image_replace,    // Store session_id for coordination
				'language'        => $language,
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
	 * @return array
	 */
	public function get_attachments() {
		// Get parameters from request
		$type = $this->get_param('type', 'pack');
		$id = $this->get_param('pack_id');

		// Require ID parameter - return error if not provided
		if (empty($id)) {
			return $this->error('missing_id', __('Pack ID or ID parameter is required.', 'templately'), 'get_attachments', 400);
		}

		try {
			// Construct API endpoint URL
			$api_endpoint = "get-xml-attachment/{$type}/{$id}";

			// Make API call
			$extra_headers = [
				'Accept' => 'application/xml, text/xml',
			];
			$response = Helper::make_api_get_request("v2/$api_endpoint", [], $extra_headers, 30);

			// Check for HTTP errors
			if (is_wp_error($response)) {
				return $this->error('api_request_failed', __('Failed to fetch attachments from API.', 'templately'), 'get_attachments', 500, $response->get_error_message());
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
				return $this->error('xml_parse_error', __('Failed to parse XML content.', 'templately'), 'get_attachments', 500, $parsed_data->get_error_message());
			}

			// Extract attachments from parsed data
			$attachments = $this->extract_attachments_from_parsed_data($parsed_data);

			return [
				'status' => 'success',
				'data' => $attachments,
				'message' => sprintf(__('Found %d attachments.', 'templately'), count($attachments)),
			];

		} catch (Exception $e) {
			return $this->error('exception', __('An unexpected error occurred while fetching attachments.', 'templately'), 'get_attachments', 500, $e->getMessage());
		}
	}



	/**
	 * Parse XML content string using WXR Parser
	 *
	 * @param string $xml_content XML content string
	 * @return array|WP_Error Parsed data or error
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
	 * Generate logo using AI
	 *
	 * @return array|WP_Error
	 */
	public function generate_logo() {
		// Get parameters
		$business_name = $this->get_param('business_name', '');
		$description = $this->get_param('description');
		$logo_type = $this->get_param('logo_type');
		$requested_platform = $this->get_param('requested_platform', 'templately');

		// Validate required parameters
		if (empty($description)) {
			return $this->error(
				'missing_description',
				__('Description is required for logo generation.', 'templately'),
				'generate_logo',
				400
			);
		}

		if (empty($logo_type)) {
			return $this->error(
				'missing_logo_type',
				__('Logo type is required.', 'templately'),
				'generate_logo',
				400
			);
		}

		// Prepare request body
		$body_data = [
			'business_name' => $business_name,
			'description' => $description,
			'logo_type' => $logo_type,
		];

		// Make API request
		$extra_headers = [
			'Content-Type' => 'application/json',
			'x-templately-requested-platform' => $requested_platform,
		];

		$response = Helper::make_api_post_request('v2/generate-logo', $body_data, $extra_headers, 60);

		// Handle API response errors
		if (is_wp_error($response)) {
			return $this->error(
				'api_request_failed',
				__('Something went wrong. Please try again or contact support.', 'templately'),
				'generate_logo',
				500,
				$response->get_error_message()
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
					'generate_logo',
					$response_code
				);
			}

			// Otherwise, return generic error
			return $this->error(
				'api_response_error',
				__('Something went wrong. Please try again or contact support.', 'templately'),
				'generate_logo',
				$response_code
			);
		}

		// Parse and validate response
		$data = json_decode($response_body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return $this->error(
				'invalid_response',
				__('Invalid response from API.', 'templately'),
				'generate_logo',
				500
			);
		}

		// Check if the response has the expected structure
		if (!isset($data['status'])) {
			return $this->error(
				'api_response_error',
				__('API returned an unexpected response.', 'templately'),
				'generate_logo',
				500
			);
		}

		// Extract image_base64 from nested data field if present
		$image_base64 = null;
		if (isset($data['data']['image_base64'])) {
			$image_base64 = $data['data']['image_base64'];
		} elseif (isset($data['image_base64'])) {
			// Fallback for legacy response format
			$image_base64 = $data['image_base64'];
		}

		// Upload base64 image to media library if provided
		if (!empty($image_base64)) {
			$upload_result = Utils::upload_logo_base64($image_base64);

			if (is_array($upload_result) && isset($upload_result['error'])) {
				// Log error but don't fail the entire response
				error_log('Logo upload error: ' . $upload_result['error']);
			} else if (is_array($upload_result) && isset($upload_result['id'])) {
				// Return clean response with only essential uploaded logo information
				// Overwrite the data field to remove base64 image data and return only ID and URL
				return [
					'status' => 'success',
					'data' => [
						'id' => $upload_result['id'],
						'url' => $upload_result['url'],
					],
				];
			}
		}

		// Return the original response if no logo was uploaded
		return $data;
	}

	/**
	 * Generate meta (title or tagline) using AI
	 *
	 * @return array|WP_Error
	 */
	public function generate_meta() {
		// Get parameters
		$field = $this->get_param('field');
		$text = $this->get_param('text');
		$requested_platform = $this->get_param('requested_platform', 'templately');

		// Validate required parameters
		if (empty($field)) {
			return $this->error(
				'missing_field',
				__('Field is required.', 'templately'),
				'generate_meta',
				400
			);
		}

		if (empty($text)) {
			return $this->error(
				'missing_text',
				__('Text is required for meta generation.', 'templately'),
				'generate_meta',
				400
			);
		}

		// Prepare request body
		$body_data = [
			'field' => $field,
			'text' => $text,
		];

		// Make API request
		$extra_headers = [
			'Content-Type' => 'application/json',
			'x-templately-requested-platform' => $requested_platform,
		];

		$response = Helper::make_api_post_request('v2/generate-meta', $body_data, $extra_headers, 30);

		// Handle API response errors
		if (is_wp_error($response)) {
			return $this->error(
				'api_request_failed',
				__('Failed to generate meta.', 'templately'),
				'generate_meta',
				500,
				$response->get_error_message()
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
					'generate_meta',
					$response_code
				);
			}

			// Otherwise, return generic error
			return $this->error(
				'api_response_error',
				__('Something went wrong. Please try again or contact support.', 'templately'),
				'generate_meta',
				$response_code
			);
		}

		// Parse and validate response
		$data = json_decode($response_body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return $this->error(
				'invalid_response',
				__('Invalid response from API.', 'templately'),
				'generate_meta',
				500
			);
		}

		// Check if the response has the expected structure
		if (!isset($data['status'])) {
			return $this->error(
				'api_response_error',
				__('API returned an unexpected response.', 'templately'),
				'generate_meta',
				500
			);
		}

		// Return the response as-is
		return $data;
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
