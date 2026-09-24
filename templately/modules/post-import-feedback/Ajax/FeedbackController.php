<?php

namespace Templately\Modules\PostImportFeedback\Ajax;

use Templately\Modules\PostImportFeedback\Widget;
use Templately\Utils\Helper;
use Templately\Utils\Response\AjaxResponder;
use Templately\Utils\Response\ErrorCode;
use Templately\Utils\Response\ResponseNormalizer;

/**
 * FeedbackController — independent AJAX endpoint handler, owned by
 * modules/post-import-feedback and supplied to full-site-import through its
 * `templately_fsi_ajax_handlers` filter seam (module.php::provide_ajax_handlers);
 * FSI executes it through its shared nonce/capability wrapper (action names
 * unchanged — see this spec's own Ownership note). Dropped 6 unused imports
 * (Elementor\Plugin, Exception, and 4 FullSiteImport\Utils\* classes) carried over
 * from the original monolith file, confirmed dead before removing (grep showed zero
 * actual usage in the method bodies below).
 */
class FeedbackController {

	public function feedback_form() {
		// Get data from $_POST
		$review_description = isset($_POST['review-description']) ? sanitize_textarea_field($_POST['review-description']) : '';
		$review_email       = isset($_POST['review-email']) ? sanitize_email($_POST['review-email']) : '';
		$rating             = isset($_POST['rating']) ? sanitize_text_field($_POST['rating']) : '';
		$pack_id            = get_user_meta(get_current_user_id(), 'templately_fsi_pack_id', true);

		// Prepare the body of the request
		$body = json_encode([
			'description' => $review_description,
			'email'       => $review_email,
			'rating'      => (int) $rating,
			'pack_id'     => (int) $pack_id,
		]);

		// Send the request to the API
		$response = Helper::make_api_post_request('v2/feedback/store', json_decode($body, true), [], 30);

		// 043 FR-003 — classification via the central normalizer, replacing
		// `extract_error_from_response()`'s `mixed` return (which could surface a
		// raw upstream body as the user's message). Behaviour is kept identical to
		// the REST twin in REST\Feedback::submit_feedback() — this shim exists only
		// for already-cached bundles, so the two must not drift.
		$normalized = ResponseNormalizer::normalize($response);

		if ($normalized->is_error()) {
			$error = $normalized->error();

			// A repeat submission is not a failure — see the REST twin for the
			// live-captured `{hasFeedback:true}` 400 this handles.
			if (ErrorCode::ALREADY_SUBMITTED === $error->code()) {
				update_user_meta(get_current_user_id(), Widget::LAST_SHOWN_META, time());
				AjaxResponder::success(__('Your feedback has already been submitted. Thank you!', 'templately'));
				return;
			}

			// The whole error, not just its text — the envelope carries the code, so the
			// client can branch on it instead of reading prose.
			AjaxResponder::error($error);
			return;
		}

		$payload = $normalized->payload();
		$result  = is_array($payload) && isset($payload['message']) ? $payload['message'] : '';

		if ('' === $result) {
			AjaxResponder::error(ErrorCode::EMPTY_RESPONSE, __('The feedback service returned an unexpected response.', 'templately'));
			return;
		}

		// FR-005: a submission starts the 30-day cooldown, same as a skip/close.
		update_user_meta(get_current_user_id(), Widget::LAST_SHOWN_META, time());

		AjaxResponder::success($result);
	}
	public function import_close_feedback_modal() {
		$return = null;

		// The dismiss reason is raw user input that gets FORWARDED TO THE CLOUD, so
		// it is sanitized once here rather than read twice unsanitized. The nonce is
		// verified by FullSiteImport's shared ajax wrapper before this handler runs.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$close_action = isset($_GET['closeAction']) ? sanitize_text_field(wp_unslash($_GET['closeAction'])) : '';

		if ($close_action) {
			$review_email = isset($_POST['review-email']) ? sanitize_email(wp_unslash($_POST['review-email'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$pack_id      = get_user_meta(get_current_user_id(), 'templately_fsi_pack_id', true);

			// Prepare the body of the request
			$body = json_encode([
				'action'      => $close_action,
				'email'       => $review_email,
				'pack_id'     => (int) $pack_id,
			]);

			// Send the request to the API
			$response = Helper::make_api_post_request('v2/feedback/close', json_decode($body, true), [], 30);
			$body = wp_remote_retrieve_body($response);
			$return = json_decode($body, true);
		}
		update_user_meta(get_current_user_id(), 'templately_fsi_complete', 'done');
		// FR-005: record when the widget was dismissed so the 30-day cooldown
		// (evaluated by Widget::is_eligible()) can expire on a rolling basis
		// instead of suppressing forever.
		update_user_meta(get_current_user_id(), Widget::LAST_SHOWN_META, time());
		AjaxResponder::success($return);
	}
}
