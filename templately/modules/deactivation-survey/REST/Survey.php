<?php

namespace Templately\Modules\DeactivationSurvey\REST;

use Templately\API\API;
use Templately\Utils\Helper;
use WP_REST_Request;

/**
 * POST /templately/v1/deactivation-feedback — the optional "why are you leaving?"
 * submission from the plugins screen.
 *
 * REST rather than the `wp_ajax_templately_deactivation_feedback` action the
 * original carried on `latest`. New endpoints on this branch are REST by
 * convention (Constitution III / the PRD §9 migration), and unlike the
 * post-import-feedback migration there is nothing to keep a shim for: this
 * feature has never shipped from this branch, so no cached bundle anywhere is
 * calling the old action name.
 *
 * The request is fire-and-forget from the client — it is sent with `keepalive`
 * and never awaited, because it must not delay or block the deactivation the
 * user actually asked for. That shapes the contract here: this endpoint has no
 * meaningful failure the UI will ever read.
 */
class Survey extends API {

	private $endpoint = 'deactivation-feedback';

	/**
	 * Reason codes offered in the survey dropdown.
	 *
	 * Keep in sync with CommonConstant::DEACTIVATION_REASONS on the backend — the
	 * cloud rejects a code it does not know, and the whole submission is discarded
	 * rather than partially stored.
	 *
	 * @var string[]
	 */
	const REASONS = [
		'temporary'         => 'It\'s a temporary deactivation',
		'no_longer_need'    => 'I no longer need the plugin',
		'found_better'      => 'I found a better plugin',
		'not_working'       => 'It\'s not working / broke my site',
		'couldnt_configure' => 'I couldn\'t get the plugin to work',
		'missing_feature'   => 'Missing a feature I need',
		'other'             => 'Other',
	];

	/**
	 * Deactivating is the act being surveyed, so it is also the capability.
	 *
	 * Deliberately NOT the base class's `delete_posts`: an author who can delete a
	 * post has no business filing a deactivation reason, and deliberately not a
	 * connected-account check either — someone deactivating over a broken or
	 * expired connection is exactly the feedback worth having.
	 *
	 * @param WP_REST_Request $request
	 * @return bool
	 */
	public function permission_check( WP_REST_Request $request ) {
		$this->request = $request;

		return current_user_can( 'deactivate_plugins' );
	}

	public function register_routes() {
		$this->post( $this->endpoint, [ $this, 'submit' ] );
	}

	/**
	 * The reason list, shaped for the JS dropdown.
	 *
	 * @return array<int, array{value:string,label:string}>
	 */
	public static function get_reasons_for_js() {
		$reasons = [];

		foreach ( self::REASONS as $value => $label ) {
			$reasons[] = [
				'value' => $value,
				// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteral -- constant-fed, one entry per REASONS key.
				'label' => __( $label, 'templately' ),
			];
		}

		return $reasons;
	}

	/**
	 * Forward the reason to the cloud.
	 */
	public function submit() {
		$reason = $this->get_param( 'reason', '', 'sanitize_key' );

		if ( ! array_key_exists( $reason, self::REASONS ) ) {
			return $this->error(
				'invalid_reason',
				__( 'Unknown deactivation reason.', 'templately' ),
				$this->endpoint,
				400
			);
		}

		// sanitize_textarea_field, not the base sanitize_text_field: this is the one
		// free-text field in the payload and newlines are the whole point of it.
		$message = $this->get_param( 'message', '', 'sanitize_textarea_field' );

		$response = Helper::make_api_post_request( 'v2/deactivation-feedback', [
			'reason'  => $reason,
			'message' => $message,
		] );

		if ( is_wp_error( $response ) ) {
			return $this->error(
				'api_error',
				$response->get_error_message(),
				$this->endpoint,
				500
			);
		}

		return $this->success( [] );
	}
}
