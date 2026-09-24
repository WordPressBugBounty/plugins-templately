<?php

/**
 * Image search endpoint for the image-replace module (spec 023-image-replace-module).
 *
 * Relocated from includes/API/AIContent.php's search_images()/register_routes() —
 * behavior byte-identical, same route (`GET /templately/v1/ai-content/images`, kept
 * literal rather than reconstructed via an `$endpoint` property, since the route path
 * is a frozen frontend contract — react-src's imageApiHelpers.ts hardcodes it), same
 * params/validation/sanitization. No custom permission_check needed: the route fell
 * through to the base API::_permission_check() default in AIContent.php (its custom
 * override only branches on the ai-update/ai-update-preview routes), so omitting an
 * override here reproduces the exact same effective behavior.
 *
 * @package Templately
 */

namespace Templately\Modules\ImageReplace\REST;

use Templately\API\API;
use Templately\Utils\Helper;
use Templately\Utils\Response\ResponseNormalizer;
use WP_REST_Request;

class ImageSearch extends API {

	public function register_routes() {
		$this->get('ai-content/images', [$this, 'search_images'], [
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

		// 043 FR-003/FR-007 — one normalizer covers transport failure, non-200,
		// undecodable body and error status in a single pass.
		//
		// This endpoint used to flatten a 422 to "External API returned error
		// code: 422" and throw away `errors: { query: [ "Search query is
		// required." ] }` — the user saw a number instead of the sentence telling
		// them what to fix. `fields` is now carried through to the client.
		$normalized = ResponseNormalizer::normalize($response, ['side_effects' => false]);

		if ($normalized->is_error()) {
			$error = $normalized->error();

			return Helper::error(
				$error->code(),
				$error->message(),
				'search_images',
				$error->status(),
				['fields' => $error->fields(), 'context' => $error->context()]
			);
		}

		// Extract nested data from the response
		$response_data = $normalized->payload() ?: [];
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
}
