<?php

/**
 * Templately AI Content Importer
 *
 * @package Templately
 * @since 1.0.0
 */

namespace Templately\Modules\AiFsi\REST;

use Error;
use Exception;
use Templately\API\API;
use Templately\Utils\Helper;
use Templately\Utils\Response\ErrorCode;
use WP_REST_Request;
use WP_Error;
use Templately\Modules\FullSiteImport\Utils\Utils;
use Templately\Modules\FullSiteImport\Utils\AIUtils;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Utils\Database;
use Templately\Modules\FullSiteImport\Utils\SignatureVerifier;
use Templately\Modules\FullSiteImport\Parsers\WXR_Parser;

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
			// Redact the API-key header before logging — a credential must NEVER
			// reach the log, even under WP_DEBUG_LOG (Helper::log's guard). The body
			// (AI request params, not a credential) stays inside that dev guard.
			$safe_headers = $request->get_headers();
			foreach ( [ 'x_templately_apikey', 'x-templately-apikey' ] as $redact_key ) {
				if ( isset( $safe_headers[ $redact_key ] ) ) {
					$safe_headers[ $redact_key ] = [ '[redacted]' ];
				}
			}
			Helper::log( [
				'headers' => $safe_headers,
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

			// Verify the callback HMAC signature (034 FR-001) — log-only by default;
			// rejects only in enforce mode, once cloud-signed traffic is confirmed.
			$verified = SignatureVerifier::verify_request($request, $header_api_key, 'ai-content/permission');
			if (is_wp_error($verified)) {
				return $verified;
			}

			// Check the AI process exists (single per-process row read; 026 seam).
			if (AIUtils::read_process($process_id) !== null) {
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
		$this->post($this->endpoint . '/modify-content', [$this, 'modify_content']);
		$this->post($this->endpoint . '/ai-update', [$this, 'ai_update']);
		$this->post($this->endpoint . '/ai-update-preview', [$this, 'ai_update_preview']);
		$this->post($this->endpoint . '/generate-tagline', [$this, 'generate_tagline']);
		$this->post($this->endpoint . '/generate-brand-kit', [$this, 'generate_brand_kit']);
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
				'default'           => 1,
				'required'          => false,
				'sanitize_callback' => 'absint',
			],
			'per_page' => [
				'default'           => 10,
				'required'          => false,
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
		$this->post($this->endpoint . '/customization', [$this, 'save_customization'], [
			'process_id' => [
				'required' => true,
				'sanitize_callback' => 'sanitize_text_field',
			],
			// A free-form design blob (title, tagline, colours, typography, logo +
			// composition). `null` because the default per-element `sanitize_text_field`
			// would flatten the nested structure and corrupt the base64 logo; validated
			// structurally in the handler instead.
			'customization_data' => [
				'required' => true,
				'sanitize_callback' => null,
			],
			// Whether to also create/update the CLOUD conversation. False during the
			// import, true at the finalizer — see the handler.
			'sync_cloud' => [
				'required' => false,
				'default' => false,
				'sanitize_callback' => null,
			],
		]);
		// /images (search_images) moved to modules/image-replace/REST/ImageSearch.php (spec 023).
		// die(rest_url( 'templately/v1/ai-content/ai-update' ));
	}

	/**
	 * Extract + sanitize + validate the modify-content request params (033 US4 / T026).
	 *
	 * The DTO half of modify_content's former 181-line body, pulled out verbatim so the
	 * endpoint reads as parse → call → build-row. Returns the request fields as an assoc
	 * array, or a WP_Error (the validation/sanitize early-returns) the caller propagates.
	 * Behaviour-identical: same params, same sanitize, same validation order.
	 *
	 * @return array|\WP_Error
	 */
	private function parse_modify_content_request() {
		$session_id = $this->get_param('session_id');
		// Security: Sanitize session_id if provided
		if (!empty($session_id)) {
			$session_id = AIUtils::sanitize_path_component($session_id, 'session_id');
			if (is_wp_error($session_id)) {
				return $session_id;
			}
		}

		$req = [
			'pack_id'             => $this->get_param('pack_id'),
			'isBusinessNichesNew' => $this->get_param('isBusinessNichesNew', false),
			'ai_page_ids'         => $this->get_param('ai_page_ids', [], null),
			'content_ids'         => $this->get_param('content_ids', [], null),
			'session_id'          => $session_id,
			'preview_pages'       => $this->get_param('preview_pages', [], null),
			'image_replace'       => $this->get_param('imageReplace', [], null),
			'platform'            => $this->get_param('platform'),
			'language'            => $this->get_param('language', null),
			// ai content fields
			'name'                => $this->get_param('name'),
			'category'            => $this->get_param('category'),
			'description'         => $this->get_param('description'),
			'email'               => $this->get_param('email'),
			'contactNumber'       => $this->get_param('contactNumber'),
			'businessAddress'     => $this->get_param('businessAddress'),
			'openingHour'         => $this->get_param('openingHour'),
			'requested_platform'  => Helper::get_requested_platform(),
		];

		if (empty($req['pack_id'])) {
			return $this->error('invalid_id', __('Invalid ID.', 'templately'), 'modify_content', 400);
		}
		if (empty($req['category'])) {
			return $this->error('invalid_prompt', __('Invalid prompt.', 'templately'), 'modify_content', 400);
		}
		if (empty($req['content_ids']) && empty($req['preview_pages'])) {
			return $this->error('invalid_content_ids', __('Invalid content ids.', 'templately'), 'modify_content', 400);
		}
		if (empty($req['platform'])) {
			return $this->error('invalid_platform', __('Invalid platform.', 'templately'), 'modify_content', 400);
		}

		return $req;
	}

	public function modify_content() {
		add_filter('wp_redirect', '__return_false', 999);
		set_time_limit(3 * MINUTE_IN_SECONDS);
		ini_set('max_execution_time', 3 * MINUTE_IN_SECONDS);

		$req = $this->parse_modify_content_request();
		if (is_wp_error($req)) {
			return $req;
		}
		// Re-establish the locals the rest of this method uses (keyed list destructuring,
		// PHP 7.1+) so the body below stays byte-identical to the pre-T026 implementation.
		[
			'pack_id'             => $pack_id,
			'isBusinessNichesNew' => $isBusinessNichesNew,
			'ai_page_ids'         => $ai_page_ids,
			'content_ids'         => $content_ids,
			'session_id'          => $session_id,
			'preview_pages'       => $preview_pages,
			'image_replace'       => $image_replace,
			'platform'            => $platform,
			'language'            => $language,
			'name'                => $name,
			'category'            => $category,
			'description'         => $description,
			'email'               => $email,
			'contactNumber'       => $contactNumber,
			'businessAddress'     => $businessAddress,
			'openingHour'         => $openingHour,
			'requested_platform'  => $requested_platform,
		] = $req;


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
		// The `templately_ai_modify_content_body_data` filter was removed 2026-07-30 along
		// with the developer AI Settings tab that was its only consumer. It existed to stamp
		// an `ai_model` parameter onto this request; the cloud no longer supports that
		// parameter, so the seam had nothing legitimate left to add and keeping it invited
		// re-introducing a field the API rejects.

		// `unwrap => false`: the handler below reads `status`/`process_id` from the
		// TOP level, and treats a body-level `status:'error'` as a result to pass
		// through rather than a hard failure — so the envelope must stay intact.
		// The REST client logs nothing (unlike `Http`, which logs URL/QUERY/RESPONSE for
		// every GraphQL call), so an AI generation used to leave NO trace at all — a failed
		// run was indistinguishable from one that never started. Mark the attempt with the
		// identifiers needed to correlate it with the session log and the process row. The
		// business copy and the API key are deliberately NOT logged.
		Helper::log(
			sprintf(
				'modify_content → v2/ai/modify-content/pack (pack_id=%s, platform=%s, language=%s, session=%s, content_ids=%d, preview_pages=%d, local=%s)',
				$pack_id,
				$platform,
				'' === $language ? '(default)' : $language,
				$session_id,
				is_array($content_ids) ? count($content_ids) : 0,
				is_array($preview_pages) ? count($preview_pages) : 0,
				Helper::is_local_site() ? 'yes' : 'no'
			),
			'modify_content',
			'info'
		);

		$normalized = Helper::api_post('v2/ai/modify-content/pack', $body_data, $extra_headers, 15 * MINUTE_IN_SECONDS, ['unwrap' => false]);

		// 	set_transient( '__templately_ai_process_id', $response, 60 * 60 * 24 * 30 );
		// }

		// The business-niche value is destructured as $category (sent as
		// body_data['business_niches'] above). The previous $business_niches local was
		// never defined — so this "remember my niche" persistence silently never ran and
		// emitted a PHP 8 undefined-variable warning on every AI generation. Use $category.
		$bk_ai_business_niches = get_option('templately_ai_business_niches', []);
		if (!empty($category) && $isBusinessNichesNew && ! in_array($category, $bk_ai_business_niches)) {
			$bk_ai_business_niches[] = $category;
			update_option('templately_ai_business_niches', $bk_ai_business_niches, false);
		}

		// A transport/protocol failure is terminal. A body-level `status:'error'` is
		// NOT — it is a legitimate outcome this endpoint forwards to the client, so it
		// is deliberately allowed through to the handling below.
		if ($normalized->is_error() && ErrorCode::INVALID_REQUEST !== $normalized->error()->code()) {
			$error = $normalized->error();
			Helper::log("modify_content failed: {$error->code()} — {$error->message()}", 'modify_content_error', 'error');

			return $this->error($error->code(), $error->message(), 'modify_content', $error->status());
		}

		$data = $normalized->is_error()
			? ['status' => 'error', 'message' => $normalized->error()->message()]
			: $normalized->payload();

		if (! is_array($data) || ! isset($data['status'])) {
			return $this->error(ErrorCode::SERVER_ERROR, __('Invalid response.', 'templately'), 'modify_content', 500);
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

			// FR-004: is_local_site is decided by the backend, not supplied by the
			// client or trusted from the remote pass-through.
			$is_local_site = Helper::is_local_site();

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
				'is_local_site'      => $is_local_site,
				'imageReplace'       => $image_replace,
				'language'           => $language,
				'requested_platform' => $requested_platform,
			];

			// Persist the process record (per-process non-autoloaded row, 026 seam).
			AIUtils::update_ai_process_data($ai_process_data);

			// FR-005: link the generation process to its import session server-side
			// so the client never re-submits these identifiers. Mirror is_local_site
			// onto the session for the import pipeline.
			if (!empty($session_id)) {
				AIUtils::link_session($process_id, $session_id);
				SessionData::set($session_id, 'isLocalSite', $is_local_site);
			}

			// FR-003: additive {success,terminal,code,message} envelope; the
			// generation request itself succeeded (the client now polls for content).
			return self::ai_envelope('ok', __('The content is being generated in the queue', 'templately'), [
				'status'     => 'success',
				'process_id' => $process_id,
				'templates'  => !empty($data['templates']) ? $data['templates'] : null,
				// FR-004: server-decided, authoritative.
				'is_local_site'  => $is_local_site,
			]);
		}

		// Everything above returns early on success. Reaching here means the cloud
		// answered with a non-success status, or with success but no `process_id` — and
		// this pass-through used to return it to the client WITHOUT a word in the log,
		// which is why a "failed to generate content" in the UI had nothing behind it to
		// read. It is not an error from this endpoint's point of view (the call itself
		// worked), so it is still passed through; it is now merely audible.
		Helper::log(
			sprintf(
				'modify_content: cloud declined to start generation (pack_id=%s, platform=%s, status=%s, process_id=%s) — %s',
				$pack_id,
				$platform,
				isset($data['status']) ? (string) $data['status'] : '(none)',
				isset($data['process_id']) ? (string) $data['process_id'] : '(none)',
				isset($data['message']) ? (string) $data['message'] : '(no message)'
			),
			'modify_content',
			'error'
		);

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

		Helper::log('process_id: ' . $process_id, 'ai_update', 'debug'); // gated by WP_DEBUG_LOG

		// Handle credit cost updates separately
		if ($this->request->has_param('credit_cost')) {
			AIUtils::merge_process_row($process_id, ['credit_cost' => $credit_cost]);

			return self::ai_envelope('ok', '', [
				'status' => 'success',
				'data'   => [
					'process_id' => $process_id,
					'credit_cost' => $credit_cost,
				],
			]);
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

		// Return the result from the helper function (FR-003 additive envelope).
		if (isset($result['status']) && $result['status'] === 'success') {
			return self::ai_envelope('ok', '', $result);
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

		Helper::log('process_id: ' . $process_id, 'ai_update', 'debug'); // gated by WP_DEBUG_LOG

		if (!empty($isSkipped) || !empty($error)) {
			// The cloud reporting a generation failure is THE answer to "why did this
			// fail", and it was recorded only on the process row — a serialized option
			// nobody reads while debugging. Put it in the log too.
			Helper::log(
				sprintf(
					'ai_update_preview: remote reported failure for %s (isSkipped=%s) — %s',
					$process_id,
					!empty($isSkipped) ? 'yes' : 'no',
					!empty($error) ? (string) $error : '(no message)'
				),
				'ai_update_preview',
				'error'
			);

			// Persist the preview error onto the process row (026 seam).
			if (AIUtils::read_process($process_id) !== null) {
				AIUtils::merge_process_row($process_id, ['preview_error' => $error]);
			}
			// This is a REST route (registered via $this->post()), so it must RETURN.
			// `wp_send_json_error()` die()s mid-request and emits an AJAX-shaped body,
			// bypassing the REST envelope, the HTTP status mapping and every filter
			// after dispatch — the one place in the codebase where a REST handler
			// answered in AJAX. Every other exit in this method already returns.
			return $this->error(
				ErrorCode::AI_REMOTE_FAILED,
				! empty( $error ) ? $error : __( 'AI preview generation did not complete.', 'templately' ),
				'ai-content/ai-update-preview',
				502
			);
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

		// FR-003 additive envelope: a total failure is terminal (remote_failed);
		// full or partial success is 'ok' (the per-page results carry the detail).
		$code = $overall_status === 'error' ? 'remote_failed' : 'ok';

		return self::ai_envelope($code, sprintf(
			__('Processed %d templates: %d successful, %d failed.', 'templately'),
			count($template),
			$success_count,
			$error_count
		), [
			'status' => $overall_status,
		]);
	}



	/**
	 * Get attachments from API endpoint
	 *
	 * @return array|\WP_Error
	 */
	public function get_attachments() {
		// Get parameters from request. The route declares BOTH `id` and `pack_id` and the
		// error copy says "Pack ID or ID" — but only `pack_id` was read, so a caller
		// passing `?id=` (the documented alternative, matching the get-xml-attachment/{type}/{id}
		// URL shape) failed with missing_id. Fall back to `id` when `pack_id` is absent.
		$type               = $this->get_param('type', 'pack');
		$id                 = $this->get_param('pack_id');
		if (empty($id)) {
			$id = $this->get_param('id');
		}
		$requested_platform = Helper::get_requested_platform();

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
			// XML, not JSON — FR-012: the body passes through the normalizer untouched
			// and is only CLASSIFIED. An error body on this endpoint is JSON even
			// though success is XML, so it is re-checked below.
			$normalized = Helper::api_get("v2/$api_endpoint", [], $extra_headers, 30, ['raw' => true]);

			if ($normalized->is_error()) {
				$error = $normalized->error();

				return $this->error($error->code(), $error->message(), 'get_attachments', $error->status());
			}

			$xml_content = $normalized->payload();

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
				// Pack-supplied meta: parse without object hydration, and without @
				// (a malformed value just fails the is_array check below).
				$attachment_metadata = is_serialized($meta['value'])
					? unserialize($meta['value'], ['allowed_classes' => false])
					: null;
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



	// search_images() moved to modules/image-replace/REST/ImageSearch.php (spec 023).



	/**
	 * Generate tagline using AI
	 *
	 * @return array|WP_Error
	 */
	public function generate_tagline() {
		// Get parameters
		$prompt = $this->get_param('prompt');
		$requested_platform = Helper::get_requested_platform();

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

		// Same rule as {@see chatbot_request()}: a body-level `status:'error'` on an
		// HTTP 200 is a failure, not a payload. Inline rather than routed through
		// that helper because this is `v2/generate-tagline`, not a chatbot route —
		// folding it in would widen a chatbot-specific seam to mean "any upstream
		// call", which is how such helpers stop being safe to reason about.
		//
		// Defence in depth here specifically: `useTaglineGeneration` already checks
		// `response.status === 'error'` itself. This is the one of the four sites
		// where the frontend had compensated — but leaving the backend inconsistent
		// is how the next caller inherits the bug.
		if ('error' === $data['status']) {
			$message = !empty($data['message'])
				? $data['message']
				: __('Failed to generate tagline.', 'templately');

			return $this->error('tagline_generation_failed', $message, 'generate_tagline', 400);
		}

		// Return the response as-is
		return $data;
	}

	/**
	 * Ask the cloud for a tagline AND typography-logo parameters in one call.
	 *
	 * A thin pass-through to `v2/generate-brand-kit`, same shape as {@see generate_tagline()}.
	 * The font and icon vocabularies travel in the request because only the CLIENT knows what
	 * its logo editor can render; sending them is what stops the model naming a font we cannot
	 * load or an icon that exists nowhere.
	 *
	 * Nothing is validated against those vocabularies here. That check belongs where the
	 * renderer is — the client re-checks every returned value and falls back to its own
	 * deterministic composition — and duplicating it in PHP would mean this endpoint had to
	 * track the icon registry's contents.
	 *
	 * @return array|\WP_Error Pass-through of the external response { status, data } or WP_Error.
	 */
	/**
	 * Persist the customizer draft for a generation — locally always, in the cloud on request.
	 *
	 * **Three cadences, and this is the slow two.** The moment-to-moment draft is a keystroke
	 * and lives in the browser's localStorage; a database write per keystroke is the wrong
	 * shape at any scale. This runs at two MILESTONES instead: when the import starts, and
	 * when it finishes.
	 *
	 * `sync_cloud` is what separates them. The import-start write is local only — fast, and
	 * an import that never completes should not leave a cloud record claiming a site exists.
	 * The finalizer's write carries `sync_cloud`, and THAT is where a site generated in this
	 * plugin becomes a conversation in "My AI Sites", re-importable anywhere.
	 *
	 * **Create-if-absent lives here, not in the client.** The conversation uuid is stored on
	 * the process row, so this endpoint knows whether one already exists: a web-generated site
	 * has one and gets an update, a plugin-generated site has none and gets a create whose
	 * minted uuid is written back. A client deciding that would have to be trusted with the
	 * uuid, and a second import would mint a duplicate.
	 *
	 * A cloud failure is reported but does NOT fail the call: the local write already
	 * succeeded, and the import it belongs to has finished. Losing the listing entry is worth
	 * far less than surfacing an error on a site that imported correctly.
	 *
	 * @return array|\WP_Error { status, data: { saved, uuid, cloud } } or WP_Error.
	 */
	public function save_customization() {
		$process_id = $this->get_param('process_id');
		$customization = $this->get_param('customization_data', [], null);

		if (empty($process_id)) {
			return $this->error(
				'missing_process_id',
				__('A generation id is required to save customization.', 'templately'),
				'ai-content/customization',
				400
			);
		}

		if (!is_array($customization)) {
			return $this->error(
				'invalid_customization',
				__('Customization data must be an object.', 'templately'),
				'ai-content/customization',
				400
			);
		}

		$row = AIUtils::read_process($process_id);

		if (!is_array($row)) {
			// Nothing to attach it to. Not an error the user can act on — the draft still
			// lives in their browser — but the caller should know the durable write did not
			// happen rather than believing it did.
			return $this->error(
				'unknown_process',
				__('That generation is no longer available on this site.', 'templately'),
				'ai-content/customization',
				404
			);
		}

		// `processed_pages` is a MERGE-ON-READ view assembled from the per-page rows, not a
		// stored field. Writing it back would bake a snapshot into the record and make the
		// live per-page rows unreachable.
		unset($row['processed_pages']);

		$row['customization_data'] = $customization;
		AIUtils::write_process_row($process_id, $row);

		$result = [
			'saved' => true,
			'uuid'  => isset($row['conversation_uuid']) ? $row['conversation_uuid'] : null,
			'cloud' => 'skipped',
		];

		if (!$this->get_param('sync_cloud', false, null)) {
			return ['status' => 'success', 'data' => $result];
		}

		$body = ['customization_data' => $customization];

		if (!empty($row['conversation_uuid'])) {
			$body['uuid'] = $row['conversation_uuid'];
		} else {
			// Only on the CREATE. Without these the row appears in "My AI Sites" with no pack
			// name, no thumbnail and — the one that does damage — a null platform, which is
			// what a later re-import hands back to pick a builder with.
			//
			// The cloud fills name/slug/thumbnail from the live pack; it cannot know the
			// PLATFORM this generation actually ran for, so that travels from here.
			$body['selected_pack_id'] = isset($row['pack_id']) ? (int) $row['pack_id'] : null;
			$body['platform']         = isset($row['platform']) ? $row['platform'] : null;
			$body['detected_info']    = array_filter([
				'business_name'        => isset($row['name']) ? $row['name'] : null,
				'business_type'        => isset($row['category']) ? $row['category'] : null,
				'business_description' => isset($row['description']) ? $row['description'] : null,
			]);
		}

		$response = Helper::make_api_post_request(
			'v2/chatbot/conversation/customization',
			$body,
			['Content-Type' => 'application/json'],
			20
		);

		if (is_wp_error($response)) {
			$result['cloud'] = 'failed';
			return ['status' => 'success', 'data' => $result];
		}

		$data = json_decode(wp_remote_retrieve_body($response), true);
		$uuid = isset($data['data']['uuid']) ? $data['data']['uuid'] : null;

		if ($uuid) {
			$result['uuid']  = $uuid;
			$result['cloud'] = 'synced';

			// Written back so the NEXT save updates that conversation instead of minting a
			// second one. This is what makes the whole thing create-once.
			if (empty($row['conversation_uuid'])) {
				$row['conversation_uuid'] = $uuid;
				AIUtils::write_process_row($process_id, $row);
			}
		} else {
			$result['cloud'] = 'failed';
		}

		return ['status' => 'success', 'data' => $result];
	}

	public function generate_brand_kit() {
		$business_name = $this->get_param('business_name');

		if (empty($business_name)) {
			return $this->error(
				'missing_business_name',
				__('Business name is required for brand kit generation.', 'templately'),
				'generate_brand_kit',
				400
			);
		}

		// Arrays of plain identifiers (font families, icon LIBRARY names, hex colours).
		// `sanitize_text_field` is applied per element by `get_param`, which is right for all
		// three; the cloud caps their length and the client validates their contents.
		$body_data = [
			'business_name'        => $business_name,
			'business_type'        => $this->get_param('business_type', ''),
			'business_description' => $this->get_param('business_description', ''),
			// The rest of what the conversation collected. `language` is the one that was
			// missing and was a live defect: without it the cloud writes the tagline in the
			// language of its own instruction, so a French or Bengali site opened with an
			// English tagline. `sanitize_email` for the address — `sanitize_text_field`
			// would pass a malformed one straight through.
			'email'                => sanitize_email((string) $this->get_param('email', '', null)),
			'contact_number'       => $this->get_param('contact_number', ''),
			'business_address'     => $this->get_param('business_address', ''),
			'opening_hour'         => $this->get_param('opening_hour', ''),
			'language'             => $this->get_param('language', ''),
			'fonts'                => (array) $this->get_param('fonts', []),
			'icon_libraries'       => (array) $this->get_param('icon_libraries', []),
			'colors'               => (array) $this->get_param('colors', []),
		];

		$extra_headers = [
			'x-templately-requested-platform' => Helper::get_requested_platform(),
		];

		// GET, not POST — and not a style choice: `v2/generate-brand-kit` answers
		// `Allow: GET,HEAD`, so a POST is rejected with 405 before the cloud ever reads the
		// body, and every brand kit failed. (Its sibling `v2/generate-tagline` DOES allow
		// POST, which is why the two look inconsistent here; they are, on the server.)
		// The fields travel as query params — `make_api_get_request` encodes them, arrays
		// included — and none of them is a secret: the credential rides the Authorization
		// header, as on every other call.
		$response = Helper::make_api_get_request('v2/generate-brand-kit', $body_data, $extra_headers, 30);

		if (is_wp_error($response)) {
			return $this->error(
				'api_request_failed',
				__('Failed to generate brand kit.', 'templately'),
				'generate_brand_kit',
				500,
				['error_detail' => $response->get_error_message()]
			);
		}

		$response_code = wp_remote_retrieve_response_code($response);
		$data          = json_decode(wp_remote_retrieve_body($response), true);

		if (json_last_error() !== JSON_ERROR_NONE || ! is_array($data)) {
			return $this->error(
				'invalid_response',
				__('Invalid response from API.', 'templately'),
				'generate_brand_kit',
				500
			);
		}

		if ($response_code !== 200) {
			$message = ! empty($data['message'])
				? $data['message']
				: __('Something went wrong. Please try again or contact support.', 'templately');

			return $this->error('api_response_error', $message, 'generate_brand_kit', $response_code);
		}

		// Same rule as {@see generate_tagline()}: a body-level `status:'error'` on an HTTP 200
		// is a failure, not a payload. Insufficient credit arrives this way.
		if (isset($data['status']) && 'error' === $data['status']) {
			$message = ! empty($data['message'])
				? $data['message']
				: __('Failed to generate brand kit.', 'templately');

			return $this->error('brand_kit_generation_failed', $message, 'generate_brand_kit', 400);
		}

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

		return $this->chatbot_request('GET', "v2/chatbot/conversation/{$chat}", 'ai-content/chatbot-conversation', [
			'transport_message' => __('Failed to fetch conversation.', 'templately'),
			'error_code'        => 'conversation_unavailable',
			'error_message'     => __('Could not retrieve the conversation.', 'templately'),
		]);
	}

	/**
	 * The account's AI-generated sites, paginated — the list behind "My AI Sites".
	 *
	 * Plural sibling of {@see get_chatbot_conversation()}: that one fetches a
	 * single conversation by id, this one enumerates them so a user can come back
	 * to a site they generated earlier and import it again.
	 *
	 * The upstream page size is clamped rather than trusted. `per_page` reaches
	 * this from a query string, and an unbounded value turns one list render into
	 * an arbitrarily large upstream fetch — the pagination exists precisely
	 * because an account can accumulate a lot of these.
	 *
	 * @return array|\WP_Error Pass-through of the external response { status, data } or WP_Error.
	 */
	public function get_chatbot_conversations() {
		$page     = max(1, (int) $this->get_param('page', 1, 'absint'));
		$per_page = min(100, max(1, (int) $this->get_param('per_page', 10, 'absint')));

		return $this->chatbot_request('GET', 'v2/chatbot/conversations', 'ai-content/chatbot-conversations', [
			'body'              => [
				'page'     => $page,
				'per_page' => $per_page,
			],
			'transport_message' => __('Failed to fetch your AI sites.', 'templately'),
			'error_code'        => 'conversations_unavailable',
			'error_message'     => __('Could not retrieve your AI sites.', 'templately'),
		]);
	}

	/**
	 * THE single path to the chatbot API. Every call goes through here.
	 *
	 * This exists because the validation could not be left to call sites. Four
	 * handlers each hand-rolled the same twenty lines — transport check, HTTP
	 * status check, decode, envelope check — and every one of them stopped at
	 * "does `status` EXIST", then returned the body. The chatbot API signals
	 * application-level failure INSIDE an HTTP 200 as `{status:'error', message}`,
	 * so all four forwarded failures that the 043 envelope then stamped
	 * `success: true`. Downstream, the caller reads a field the error body does
	 * not carry, gets nothing, and renders an empty state with no error and no
	 * retry — the "an error is never an empty array" failure includes/CLAUDE.md
	 * warns about.
	 *
	 * Guarding each site individually fixed those four and left the NEXT handler
	 * free to inherit the bug by simply not knowing about the guard. Owning the
	 * whole sequence here makes that impossible: there is no way to call the
	 * chatbot API without the check, because there is no other way to call it.
	 *
	 * The disconnected pre-flight lives here for the same reason. Only
	 * {@see get_chatbot_conversation()} carried it inline, so the other four
	 * handlers made the doomed upstream call anyway and answered with a generic
	 * auth error that named no remedy. A disconnected site cannot authenticate
	 * upstream, full stop — so the check belongs to the seam, not to whichever
	 * handler remembered it.
	 *
	 * Inside this seam an error body is ALWAYS a failure — there is no opt-out.
	 * The callers that legitimately render an error body as a partial outcome
	 * ({@see modify_content()} via `Helper::api_post(['unwrap' => false])`,
	 * {@see generate_tagline()} with its inline guard) are different route
	 * families and sit outside this seam by design.
	 *
	 * @param string $method   GET|POST.
	 * @param string $path     Upstream path, e.g. `v2/chatbot/generated/{id}`.
	 * @param string $endpoint Endpoint label carried on error payloads.
	 * @param array  $opts     body, timeout, with_api_key, transport_message,
	 *                         error_code, error_message.
	 * @return array|\WP_Error Decoded body on success; WP_Error on ANY failure.
	 */
	private function chatbot_request(string $method, string $path, string $endpoint, array $opts = []) {
		$opts = wp_parse_args($opts, [
			'body'              => [],
			'timeout'           => 30,
			'with_api_key'      => false,
			'transport_message' => __('The request could not be completed.', 'templately'),
			'error_code'        => 'upstream_error',
			'error_message'     => __('The request could not be completed.', 'templately'),
		]);

		// Pre-flight the connection, mirroring the FSI handlers
		// (Concerns\HandlesSession::import_settings, Concerns\RunsImport).
		$user = $this->options()->get('user');

		if (!empty($user['is_disconnected'])) {
			return $this->error(
				ErrorCode::SITE_DISCONNECTED,
				__('Your site connection is disconnected. Please migrate your connection first.', 'templately'),
				$endpoint,
				403
			);
		}

		$headers = ['Accept' => 'application/json'];

		// Some chatbot routes are gated on the key header rather than the bearer.
		if ($opts['with_api_key']) {
			$headers['X-Templately-Apikey'] = $this->api_key;
		}

		$response = ('POST' === strtoupper($method))
			? Helper::make_api_post_request($path, $opts['body'], $headers, $opts['timeout'])
			: Helper::make_api_get_request($path, $opts['body'], $headers, $opts['timeout']);

		if (is_wp_error($response)) {
			return $this->error(
				'request_failed',
				$opts['transport_message'],
				$endpoint,
				500,
				['error_detail' => $response->get_error_message()]
			);
		}

		$code = wp_remote_retrieve_response_code($response);
		$data = json_decode(wp_remote_retrieve_body($response), true);

		if (200 !== $code) {
			$message = (is_array($data) && !empty($data['message']))
				? $data['message']
				: sprintf(
					/* translators: %d: HTTP status code returned by the API. */
					__('API returned HTTP %d error.', 'templately'),
					$code
				);

			return $this->error('api_http_error', $message, $endpoint, $code ?: 500);
		}

		if (!is_array($data) || !isset($data['status'])) {
			return $this->error('invalid_response', __('Invalid response.', 'templately'), $endpoint, 500);
		}

		if ('error' === $data['status']) {
			$message = !empty($data['message']) ? $data['message'] : $opts['error_message'];

			return $this->error($opts['error_code'], $message, $endpoint, 400);
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

		// Validation happens inside chatbot_request(), i.e. BEFORE the transient
		// write below — an error body must never be cached. Caching one would
		// replay the failure to chatbot-import-prepare for the whole TTL, so a
		// TRANSIENT upstream error would look like a persistent one and the thin
		// import would keep failing after the cause had cleared.
		$data = $this->chatbot_request('GET', "v2/chatbot/generated/{$chat}", 'ai-content/chatbot-generated', [
			'transport_message' => __('Failed to fetch generated content.', 'templately'),
			'error_code'        => 'generated_unavailable',
			'error_message'     => __('Could not retrieve the generated content.', 'templately'),
		]);

		if (is_wp_error($data)) {
			return $data;
		}

		// The direct-import handoff reads this endpoint and then immediately calls
		// chatbot-import-prepare, which pulls the very same (large) bundle off GCP
		// seconds later. Park it so prepare can reuse it instead of paying for a
		// second identical transfer.
		Database::set_transient(self::GENERATED_CACHE_KEY . $chat, $data, self::GENERATED_CACHE_TTL);

		return $data;
	}

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

		// The caller uses templatelyApiOrThrow, which throws on `error` but NOT on a
		// body-level `status:'error'` — so an unguarded failure meant the user's
		// edit silently did not persist and a later replay used the stale values.
		return $this->chatbot_request('POST', "v2/chatbot/conversation/{$chat}/detected-info", 'ai-content/chatbot-detected-info', [
			'body'              => ['detected_info' => $detected_info],
			'with_api_key'      => true,
			'transport_message' => __('Failed to update detected info.', 'templately'),
			'error_code'        => 'detected_info_not_saved',
			'error_message'     => __('Could not save your changes.', 'templately'),
		]);
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

		// A GATE, not a read: this flips the conversation to `imported` so a second
		// import is refused. Forwarding an upstream failure as success made
		// markChatbotImported() return true when nothing had been recorded — the
		// import-once gate failing OPEN, the one direction it must not fail. The
		// caller already branches on `error`, so it now correctly returns false.
		return $this->chatbot_request('POST', "v2/chatbot/conversation/{$chat}/imported", 'ai-content/chatbot-mark-imported', [
			'with_api_key'      => true,
			'transport_message' => __('Failed to record import.', 'templately'),
			'error_code'        => 'mark_imported_failed',
			'error_message'     => __('Could not record the import.', 'templately'),
		]);
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
	 * Expected JSON body: { chat, session_id, ai_page_ids: { 'content/page': [...], templates: [...] },
	 *                       pack_id?, platform?, detected_info? }
	 *
	 * @return array|\WP_Error
	 */
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
		// can still be generating.
		$expected_ids = AIUtils::flatten_ai_page_ids($ai_page_ids);

		$cache_key = self::GENERATED_CACHE_KEY . $chat;

		// This endpoint NEVER waits for completeness. The import starts as soon as
		// the session exists; whatever pages are already generated are written
		// here as a warm start, and any page still generating is pulled on demand
		// by the Finalizer (FullSiteImport\Utils\AIContentResolver +
		// Providers\ChatAIContentProvider).
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
			// Single pull — no server-side sleep/retry. Routed through the shared
			// seam so this endpoint cannot drift from the others on what counts as
			// a failure; it was the last hand-rolled copy of that sequence.
			$data          = $this->chatbot_request('GET', "v2/chatbot/generated/{$chat}", 'ai-content/chatbot-import-prepare', [
				'timeout'           => 2 * MINUTE_IN_SECONDS,
				'transport_message' => __('Failed to fetch generated content.', 'templately'),
				'error_code'        => 'generated_unavailable',
				'error_message'     => __('Could not retrieve the generated content.', 'templately'),
			]);
			$pull_duration = microtime(true) - $pull_started;

			// A FAILED pull is not an empty one. Before this, an upstream
			// `status:'error'` on an HTTP 200 passed the shape check (`status` was
			// present), left `$generated` empty, and fell through the whole handler
			// to return `status:'success'` with saved=0 and every page "missing" —
			// so a hard upstream failure was indistinguishable from the legitimate
			// "nothing generated yet, the Finalizer will pull on demand" case, and
			// the import began against a process with no content behind it.
			if (is_wp_error($data)) {
				Helper::log(
					sprintf('chatbot_import_prepare[%s] pull failed after %.2fs: %s', $chat, $pull_duration, $data->get_error_message()),
					'ai-import',
					'error'
				);

				return $data;
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
