<?php

/**
 * Templately Logo Generation API
 *
 * @package Templately
 * @since 3.4.0
 */

namespace Templately\API;

use Templately\Utils\Helper;
use WP_REST_Request;
use Templately\Core\Importer\Utils\Utils;
use Templately\Core\Importer\Utils\AIUtils;

class LogoGeneration extends API {
	private $endpoint = 'logo-generation';

	/**
	 * LogoGeneration constructor.
	 */
	public function __construct() {
		parent::__construct();
	}

	public function _permission_check( WP_REST_Request $request ) {
		$this->request = $request;
		$this->api_key = $this->utils( 'options' )->get( 'api_key' );
		$process_id    = $this->get_param( 'process_id' );

		$_route = $request->get_route();

		// Handle logo generation callback endpoint
		if ( '/templately/v1/logo-generation/callback' === $_route ) {
			Helper::log(
				[
					'headers' => $request->get_headers(),
					'body'    => $request->get_params(),
				],
				'logo_generation_callback_request'
			);

			if ( empty( $process_id ) ) {
				return $this->error( 'invalid_id', __( 'Invalid ID.', 'templately' ), 'logo_generation_callback', 400 );
			}

			$header_api_key = sanitize_text_field( $request->get_header( 'x_templately_apikey' ) );
			if ( empty( $header_api_key ) ) {
				$header_api_key = sanitize_text_field( $request->get_header( 'X-Templately-Apikey' ) );
			}

			$is_valid_key = $this->validate_api_key_in_db( $header_api_key );
			if ( ! $is_valid_key ) {
				return $this->error( 'invalid_api_key', __( 'Invalid API key provided in header.', 'templately' ), 'logo-generation/permission', 403 );
			}

			if(defined('TEMPLATELY_DEV_API') && TEMPLATELY_DEV_API){
				sleep(5);
			}

			// Check if process_id exists in logo generation processes
			$logo_generation_processes = get_option( 'templately_logo_generation_processes', [] );
			if ( isset( $logo_generation_processes[ $process_id ] ) ) {
				return true;
			}

			Helper::log( 'Invalid or expired logo generation process ID.', 'logo_generation_callback' );

			return $this->error( 'invalid_process_id', __( 'Invalid or expired logo generation process ID.', 'templately' ), 'logo_generation_callback', 400 );
		}

		return parent::_permission_check( $request );
	}

	public function register_routes() {
		$this->post( $this->endpoint . '/generate', [ $this, 'generate_logo' ] );
		$this->post( $this->endpoint . '/callback', [ $this, 'ai_update_logo_generation' ] );
		$this->get( $this->endpoint . '/data', [ $this, 'get_logo_generation_data' ] );
		$this->post( $this->endpoint . '/poll', [ $this, 'poll_logo_generation' ] );
		$this->get( $this->endpoint . '/available-credits', [ $this, 'get_available_credits' ] );
	}

	/**
	 * Generate logo using AI - Async callback pattern
	 *
	 * @return array|\WP_Error
	 */
	public function generate_logo() {
		// Get parameters
		$business_name      = $this->get_param( 'business_name', '' );
		$description        = $this->get_param( 'description' );
		$quality            = $this->get_param( 'quality', 'auto' );
		$size               = $this->get_param( 'size', 'auto' );
		$output_format      = $this->get_param( 'output_format', 'png' );
		$category           = $this->get_param( 'category', '' );
		$quantity           = $this->get_param( 'quantity', 1 );
		$requested_platform = $this->get_param( 'requested_platform', 'templately' );

		// Validate required parameters
		if ( empty( $description ) ) {
			return $this->error(
				'missing_description',
				__( 'Description is required for logo generation.', 'templately' ),
				'generate_logo',
				400
			);
		}

		// Validate size parameter
		$allowed_sizes = [ 'auto', '1024x1024', '1536x1024', '1024x1536' ];
		if ( ! empty( $size ) && ! in_array( $size, $allowed_sizes, true ) ) {
			return $this->error(
				'invalid_size',
				__( 'Invalid size parameter. Allowed values: auto, 1024x1024, 1536x1024, 1024x1536', 'templately' ),
				'generate_logo',
				400
			);
		}

		// Validate quality parameter
		$allowed_qualities = [ 'auto', 'high', 'medium', 'low' ];
		if ( ! empty( $quality ) && ! in_array( $quality, $allowed_qualities, true ) ) {
			return $this->error(
				'invalid_quality',
				__( 'Invalid quality parameter. Allowed values: auto, high, medium, low', 'templately' ),
				'generate_logo',
				400
			);
		}

		// Validate output_format parameter
		$allowed_formats = [ 'png', 'jpeg' ];
		if ( ! empty( $output_format ) && ! in_array( $output_format, $allowed_formats, true ) ) {
			return $this->error(
				'invalid_output_format',
				__( 'Invalid output format. Allowed values: png, jpeg', 'templately' ),
				'generate_logo',
				400
			);
		}

		// Validate quantity parameter
		if ( ! empty( $quantity ) && ( ! is_numeric( $quantity ) || $quantity < 1 || $quantity > 10 ) ) {
			return $this->error(
				'invalid_quantity',
				__( 'Invalid quantity. Must be between 1 and 10', 'templately' ),
				'generate_logo',
				400
			);
		}

		// Prepare request body
		$body_data = [
			'business_name'  => $business_name,
			'description'    => $description,
			'quality'        => $quality,
			'size'           => $size,
			'output_format'  => $output_format,
			'category'       => $category,
			'quantity'       => (int) $quantity,
			'call_back_url'  => defined( 'TEMPLATELY_CALLBACK' ) ? TEMPLATELY_CALLBACK . '/wp-json/templately/v1/logo-generation/callback' : rest_url( 'templately/v1/logo-generation/callback' ),
		];

		// Make API request
		$extra_headers = [
			'Content-Type'                    => 'application/json',
			'x-templately-requested-platform' => $requested_platform,
		];

		$response = Helper::make_api_post_request( 'v2/generate-logo', $body_data, $extra_headers, 60 );

		// Handle API response errors
		if ( is_wp_error( $response ) ) {
			return $this->error(
				'api_request_failed',
				__( 'Something went wrong. Please try again or contact support.', 'templately' ),
				'generate_logo',
				500,
				[ 'error_detail' => $response->get_error_message() ]
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( 200 !== $response_code ) {
			// Try to parse the response body as JSON to get specific error details
			$data = json_decode( $response_body, true );

			// If valid JSON, extract error message and return with proper status code
			if ( JSON_ERROR_NONE === json_last_error() && is_array( $data ) ) {
				$error_message = isset( $data['message'] ) ? $data['message'] : __( 'Something went wrong. Please try again or contact support.', 'templately' );
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
				__( 'Something went wrong. Please try again or contact support.', 'templately' ),
				'generate_logo',
				$response_code
			);
		}

		// Parse and validate response
		$data = json_decode( $response_body, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return $this->error(
				'invalid_response',
				__( 'Invalid response from API.', 'templately' ),
				'generate_logo',
				500
			);
		}

		// Check if the response has the expected structure
		if ( ! isset( $data['status'] ) ) {
			return $this->error(
				'api_response_error',
				__( 'API returned an unexpected response.', 'templately' ),
				'generate_logo',
				500
			);
		}

		// Expected response format: { status: 'success', message: '...', statusCode: 200, process_id: '...', is_local_site: true/false }
		if ( 'success' === $data['status'] && isset( $data['process_id'] ) ) {
			$process_id = $data['process_id'];

			// Save process_id in a global option for security validation in callback
			$logo_generation_processes                = get_option( 'templately_logo_generation_processes', [] );
			$logo_generation_processes[ $process_id ] = [
				'created_at' => time(),
				'api_key'    => $this->api_key,
			];
			update_option( 'templately_logo_generation_processes', $logo_generation_processes, false );

			// Return the process_id to frontend for polling, including is_local_site flag
			$response_data = [
				'status'     => 'success',
				'message'    => $data['message'] ?? __( 'Logo generation started', 'templately' ),
				'process_id' => $process_id,
			];

			// Include is_local_site flag from API response if available
			if ( isset( $data['is_local_site'] ) ) {
				$response_data['is_local_site'] = (bool) $data['is_local_site'];
			}

			return $response_data;
		}

		// Return error response from API
		return $data;
	}

	/**
	 * Handle logo generation callback from API
	 *
	 * This endpoint is called by the Templately API when logo generation is complete.
	 * It processes the generated images and stores them for frontend retrieval.
	 *
	 * @return array|\WP_Error
	 */
	public function ai_update_logo_generation() {
		add_filter( 'wp_redirect', '__return_false', 999 );

		$process_id       = $this->get_param( 'process_id' );
		$credit_cost      = $this->request->get_param( 'credit_cost' );
		$remaining_credit = $this->request->get_param( 'remaining_credit' );

		if ( null === $remaining_credit ) {
			$remaining_credit = $this->request->get_param( 'available_credit' );
		}

		error_log( 'Logo generation callback - process_id: ' . $process_id . ' remaining_credit: ' . ( $remaining_credit ?? 'null' ) );
		// NOTE: Pass null as sanitizer to preserve base64-encoded image data in the images array
		// The images array contains b64_json fields with base64-encoded image data
		// Sanitization would corrupt the base64 encoding
		$images      = $this->get_param( 'images', [], null );


		// Validate process_id exists in our saved processes
		// Note: API key validation is already done in _permission_check() before this handler is called
		$logo_generation_processes = get_option( 'templately_logo_generation_processes', [] );
		if ( empty( $logo_generation_processes[ $process_id ] ) ) {
			return $this->error(
				'invalid_process_id',
				__( 'Invalid or expired logo generation process ID.', 'templately' ),
				'ai_update_logo_generation',
				400
			);
		}

		// Process logo images
		$uploaded_images = [];
		$has_errors      = false;

		if ( is_array( $images ) && ! empty( $images ) ) {
			foreach ( $images as $index => $image_data ) {
				if ( isset( $image_data['b64_json'] ) && ! empty( $image_data['b64_json'] ) ) {
					$upload_result = Utils::upload_logo_base64( $image_data['b64_json'] );

					if ( is_array( $upload_result ) && isset( $upload_result['error'] ) ) {
						error_log( 'Logo upload error for image ' . $index . ': ' . $upload_result['error'] );
						$has_errors = true;
					} elseif ( is_array( $upload_result ) && isset( $upload_result['id'] ) ) {
						$uploaded_images[] = [
							'id'  => $upload_result['id'],
							'url' => $upload_result['url'],
						];
					}
				}
			}
		}

		// Store uploaded logo data for frontend retrieval
		$logo_generation_data                = get_option( 'templately_logo_generation_data', [] );
		$logo_generation_data[ $process_id ] = [
			'images'           => $uploaded_images,
			'credit_cost'      => $credit_cost,
			'remaining_credit' => $remaining_credit,
			'completed_at'     => time(),
		];
		update_option( 'templately_logo_generation_data', $logo_generation_data, false );

		// Clean up the process from the processes list
		unset( $logo_generation_processes[ $process_id ] );
		update_option( 'templately_logo_generation_processes', $logo_generation_processes, false );

		// Return success response
		$response_data = [
			'status'  => 'success',
			'message' => __( 'Logo generation completed successfully.', 'templately' ),
			'data'    => [
				'process_id' => $process_id,
				'images'     => $uploaded_images,
			],
		];

		if ( null !== $credit_cost ) {
			$response_data['data']['credit_cost'] = $credit_cost;
		}

		if ( null !== $remaining_credit ) {
			$response_data['data']['remaining_credit'] = $remaining_credit;
		}

		return $response_data;
	}

	/**
	 * Get logo generation data by process_id
	 *
	 * This endpoint is called by the frontend to retrieve completed logo generation data.
	 * For local sites, it will attempt to poll the API if data is not found locally.
	 * For production sites, the data is stored by the ai_update_logo_generation() callback endpoint.
	 *
	 * @return array|\WP_Error
	 */
	public function get_logo_generation_data() {
		$process_id    = $this->get_param( 'process_id' );
		$is_local_site = $this->get_param( 'is_local_site', false );

		// Get logo generation data
		$logo_generation_data = get_option( 'templately_logo_generation_data', [] );

		// If data exists locally, return it
		if ( isset( $logo_generation_data[ $process_id ] ) ) {
			$data = $logo_generation_data[ $process_id ];

			return [
				'status' => 'success',
				'data'   => $data,
			];
		}

		// For local sites, attempt to poll the API if data not found
		// This must be done BEFORE checking if process is active, to ensure we get fresh data
		if ( $is_local_site ) {
			// Use the polling helper function from AIUtils
			$polling_result = AIUtils::poll_for_logo_generation( $process_id );

			if ( ! is_wp_error( $polling_result ) ) {
				// Extract logo data from polling result
				$logo_data = $polling_result['data'] ?? [];

				// Process and upload images if available
				if ( ! empty( $logo_data['images'] ) && is_array( $logo_data['images'] ) ) {
					$uploaded_images = [];

					foreach ( $logo_data['images'] as $image ) {
						// Upload image using the same method as ai_update_logo_generation
						$upload_result = Utils::upload_logo_base64( $image['b64_json'] );

						if ( ! is_wp_error( $upload_result ) ) {
							$uploaded_images[] = $upload_result;
						}
					}

					$logo_data['images'] = $uploaded_images;
				}

				// Store the polled data in the option for future retrieval
				$logo_generation_data[ $process_id ] = $logo_data;
				update_option( 'templately_logo_generation_data', $logo_generation_data, false );

				// Clean up the process from the processes list
				$logo_generation_processes = get_option( 'templately_logo_generation_processes', [] );
				unset( $logo_generation_processes[ $process_id ] );
				update_option( 'templately_logo_generation_processes', $logo_generation_processes, false );

				// Return the polled data
				return [
					'status' => 'success',
					'data'   => $logo_data,
				];
			}
		}

		// Check if process is still active in the processes list
		// For local sites, this is checked AFTER attempting API polling
		// For production sites, this is checked early to avoid unnecessary API calls
		$logo_generation_processes = get_option( 'templately_logo_generation_processes', [] );
		if ( isset( $logo_generation_processes[ $process_id ] ) ) {
			// Process is still running - return pending status to continue polling
			return [
				'status'  => 'pending',
				'message' => __( 'Logo generation in progress', 'templately' ),
			];
		}

		// Data not found, process not active, and polling failed or not a local site
		return $this->error(
			'not_found',
			__( 'Logo generation data not found.', 'templately' ),
			'get_logo_generation_data',
			404
		);
	}

	/**
	 * Poll for logo generation status on local sites
	 *
	 * This endpoint is called by the frontend on local sites to poll for logo generation completion.
	 * It makes a GET request to the API endpoint and processes the results.
	 *
	 * @return array|\WP_Error
	 */
	public function poll_logo_generation() {
		$process_id = $this->get_param( 'process_id' );

		if ( empty( $process_id ) ) {
			return $this->error(
				'missing_process_id',
				__( 'Process ID is required for logo polling.', 'templately' ),
				'poll_logo_generation',
				400
			);
		}

		// Use the polling helper function from AIUtils
		$polling_result = AIUtils::poll_for_logo_generation( $process_id );

		if ( is_wp_error( $polling_result ) ) {
			return $polling_result;
		}

		// Extract logo data from polling result
		$logo_data = $polling_result['data'] ?? [];

		// Process and upload images if available
		if ( ! empty( $logo_data['images'] ) && is_array( $logo_data['images'] ) ) {
			$uploaded_images = [];

			foreach ( $logo_data['images'] as $image ) {
				// Upload image using the same method as ai_update_logo_generation
				$upload_result = Utils::upload_logo_base64( $image );

				if ( ! is_wp_error( $upload_result ) ) {
					$uploaded_images[] = $upload_result;
				}
			}

			$logo_data['images'] = $uploaded_images;
		}

		// Store the polled data in the option for future retrieval
		$logo_generation_data                = get_option( 'templately_logo_generation_data', [] );
		$logo_generation_data[ $process_id ] = $logo_data;
		update_option( 'templately_logo_generation_data', $logo_generation_data, false );

		// Clean up the process from the processes list
		$logo_generation_processes = get_option( 'templately_logo_generation_processes', [] );
		unset( $logo_generation_processes[ $process_id ] );
		update_option( 'templately_logo_generation_processes', $logo_generation_processes, false );

		// Return success response
		$response_data = [
			'status'  => 'success',
			'message' => __( 'Logo generation data retrieved successfully.', 'templately' ),
			'data'    => [
				'process_id' => $process_id,
				'images'     => $logo_data['images'] ?? [],
			],
		];

		if ( isset( $logo_data['credit_cost'] ) ) {
			$response_data['data']['credit_cost'] = $logo_data['credit_cost'];
		}

		if ( isset( $logo_data['remaining_credit'] ) ) {
			$response_data['data']['remaining_credit'] = $logo_data['remaining_credit'];
		} elseif ( isset( $logo_data['available_credit'] ) ) {
			$response_data['data']['remaining_credit'] = $logo_data['available_credit'];
		}

		return $response_data;
	}

	/**
	 * Get available credits for the user
	 *
	 * @return array
	 */
	public function get_available_credits() {
		$response = Helper::make_api_get_request( 'v2/ai/available-credits' );

		if ( is_wp_error( $response ) ) {
			return [
				'status' => 'error',
				'message' => $response->get_error_message(),
				'data' => [
					'available_credit' => 0,
				]
			];
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$credits = isset( $body['data']['available_credit'] ) ? (int) $body['data']['available_credit'] : 0;

		return [
			'status' => 'success',
			'data'   => [
				'available_credit' => $credits,
			],
		];
	}

	/**
	 * Validate API key against database
	 * Checks if the provided API key exists for any user on the current site
	 * Uses Options class for proper multisite handling
	 *
	 * @param string $api_key The API key to validate
	 * @return bool True if valid, false otherwise
	 */
	private function validate_api_key_in_db( $api_key ) {
		$api_key = sanitize_text_field( $api_key );

		if ( empty( $api_key ) ) {
			return false;
		}

		// Get all users and check their API keys
		$users = get_users( [ 'fields' => 'ID' ] );
		$options = $this->utils( 'options' );

		foreach ( $users as $user_id ) {
			$stored_api_key = $options->get_user_meta( $user_id, '_templately_api_key', true );
			if ( $stored_api_key === $api_key ) {
				return true;
			}
		}

		return false;
	}
}

