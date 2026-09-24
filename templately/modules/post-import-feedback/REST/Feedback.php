<?php

namespace Templately\Modules\PostImportFeedback\REST;

use Templately\API\API;
use Templately\Modules\PostImportFeedback\Widget;
use Templately\Utils\Helper;
use Templately\Utils\Response\ErrorCode;
use Templately\Utils\Response\ResponseNormalizer;
use WP_Error;
use WP_REST_Request;

/**
 * REST replacement for the two post-import-feedback admin-ajax actions
 * (constitution III REST migration; PRD §9 ajax→REST backlog):
 *
 *   - `wp_ajax_templately_pack_feedback_form`               → POST /templately/v1/feedback
 *   - `wp_ajax_templately_pack_import_close_feedback_modal` → POST /templately/v1/feedback/skip
 *
 * Behavior is preserved byte-for-byte from `Ajax\FeedbackController` — same cloud
 * forwarding (`v2/feedback/store` / `v2/feedback/close` via the shared `Helper` API
 * client, never raw wp_remote_*), same `Widget::LAST_SHOWN_META` cooldown writes, same
 * `templately_fsi_complete = 'done'` write on skip/close. Only the transport moved.
 *
 * The old ajax handlers (`Ajax\FeedbackController::feedback_form()` /
 * `::import_close_feedback_modal()`, still wired through
 * `modules/full-site-import`'s shared nonce/capability AJAX registrar) are kept
 * running as deprecated shims: a browser tab holding an already-cached feedback JS
 * bundle would still POST/GET the old action names, and dropping the handlers would
 * 400 those requests. Same reasoning documented for the B8 GlobalSettings migration.
 */
class Feedback extends API {

	/**
	 * Auth comparison — REST is a strict SUPERSET of the ajax path, never weaker:
	 *
	 *   ajax path (FullSiteImport::add_ajax_action wrapper): a valid `templately_nonce`
	 *   nonce + `install_plugins` AND `install_themes`.
	 *
	 *   REST path: WP core verifies the `X-WP-Nonce` (wp_rest) BEFORE permission_callback
	 *   runs, then `_permission_check()` (base API) requires `delete_posts`, this override
	 *   requires the same `install_plugins` + `install_themes` pair, and finally
	 *   `parent::permission_check()` requires a connected/verified account (`api_key`).
	 *
	 * The api_key requirement is the one addition over ajax — always satisfied here since
	 * feedback only fires after an FSI import, which itself requires a connected account.
	 */
	public function permission_check( WP_REST_Request $request ) {
		$this->request = $request;

		if ( ! current_user_can( 'install_plugins' ) || ! current_user_can( 'install_themes' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to submit feedback.', 'templately' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return parent::permission_check( $request );
	}

	public function register_routes() {
		$this->post( 'feedback', [ $this, 'submit_feedback' ], [
			'rating' => [
				'required' => true,
			],
		] );

		$this->post( 'feedback/skip', [ $this, 'skip_feedback' ] );
	}

	/**
	 * POST /templately/v1/feedback — submit the star rating + optional review.
	 *
	 * Mirrors `Ajax\FeedbackController::feedback_form()`: forwards
	 * `{description, email, rating, pack_id}` to the cloud at `v2/feedback/store`,
	 * and on success writes `Widget::LAST_SHOWN_META` to start the FR-005 30-day
	 * cooldown (FR-013). Failure paths do NOT write the cooldown — same as the ajax
	 * handler — because the widget's subsequent close call (skip_feedback) records it.
	 */
	public function submit_feedback() {
		$review_description = $this->get_param( 'review-description', '', 'sanitize_textarea_field' );
		$review_email       = $this->get_param( 'review-email', '', 'sanitize_email' );
		$rating             = $this->get_param( 'rating', 0, 'absint' );
		$pack_id            = get_user_meta( get_current_user_id(), 'templately_fsi_pack_id', true );

		$response = Helper::make_api_post_request( 'v2/feedback/store', [
			'description' => $review_description,
			'email'       => $review_email,
			'rating'      => (int) $rating,
			'pack_id'     => (int) $pack_id,
		], [], 30 );

		// 043 FR-003 — the central normalizer classifies transport failures and
		// error bodies alike, and it is what guarantees no upstream stack trace
		// reaches the client (the old `extract_error_from_response()` could return
		// a whole decoded debug-500 body as the "message").
		$normalized = ResponseNormalizer::normalize( $response );

		if ( $normalized->is_error() ) {
			$error = $normalized->error();

			// A repeat submission is NOT a failure. The cloud answers it with
			// HTTP 400 `{hasFeedback:true}` — captured live as
			// `fixtures/rest-feedback-already-submitted.json`, and the gate is per
			// USER, not per pack. Treating it as an error meant the cooldown was
			// never written, so the widget came back and asked again for feedback
			// the user had already given. Their feedback IS recorded; say so.
			if ( ErrorCode::ALREADY_SUBMITTED === $error->code() ) {
				update_user_meta( get_current_user_id(), Widget::LAST_SHOWN_META, time() );

				return $this->success( __( 'Your feedback has already been submitted. Thank you!', 'templately' ) );
			}

			return $this->error( $error->code(), $error->message(), 'feedback', $error->status() );
		}

		$payload = $normalized->payload();
		$message = is_array( $payload ) && isset( $payload['message'] ) ? $payload['message'] : '';

		if ( '' === $message ) {
			// The cloud answers a successful store with a message; its absence
			// means we did not get the response we think we did.
			return $this->error(
				ErrorCode::SERVER_ERROR,
				__( 'The feedback service returned an unexpected response.', 'templately' ),
				'feedback',
				500
			);
		}

		// FR-005 / FR-013: a submission starts the 30-day cooldown, same as a skip/close.
		update_user_meta( get_current_user_id(), Widget::LAST_SHOWN_META, time() );

		return $this->success( $message );
	}

	/**
	 * POST /templately/v1/feedback/skip — record a skip/dismiss and start the cooldown.
	 *
	 * Mirrors `Ajax\FeedbackController::import_close_feedback_modal()`: when a
	 * `closeAction` dismiss reason is present, forwards `{action, email, pack_id}` to
	 * the cloud at `v2/feedback/close`; in ALL cases writes
	 * `templately_fsi_complete = 'done'` and `Widget::LAST_SHOWN_META` (FR-014).
	 */
	public function skip_feedback() {
		$return = null;

		$close_action = $this->get_param( 'closeAction', '', 'sanitize_text_field' );
		if ( ! empty( $close_action ) ) {
			$review_email = $this->get_param( 'review-email', '', 'sanitize_email' );
			$pack_id      = get_user_meta( get_current_user_id(), 'templately_fsi_pack_id', true );

			$response = Helper::make_api_post_request( 'v2/feedback/close', [
				'action'  => $close_action,
				'email'   => $review_email,
				'pack_id' => (int) $pack_id,
			], [], 30 );

			$return = json_decode( wp_remote_retrieve_body( $response ), true );
		}

		update_user_meta( get_current_user_id(), 'templately_fsi_complete', 'done' );
		// FR-005 / FR-014: record when the widget was dismissed so the 30-day cooldown
		// (evaluated by Widget::is_eligible()) can expire on a rolling basis instead of
		// suppressing forever.
		update_user_meta( get_current_user_id(), Widget::LAST_SHOWN_META, time() );

		return $this->success( $return );
	}
}
