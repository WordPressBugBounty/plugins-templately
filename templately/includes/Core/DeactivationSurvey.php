<?php

namespace Templately\Core;

use Templately\Utils\Helper;

class DeactivationSurvey {
	/**
	 * Reason codes offered in the deactivation survey dropdown.
	 * Keep in sync with CommonConstant::DEACTIVATION_REASONS on the backend.
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
	 * Init
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_templately_deactivation_feedback', [ __CLASS__, 'handle_feedback' ] );
	}

	/**
	 * Get the reason list formatted for the JS dropdown.
	 *
	 * @return array
	 */
	public static function get_reasons_for_js() {
		$reasons = [];
		foreach ( self::REASONS as $value => $label ) {
			$reasons[] = [
				'value' => $value,
				'label' => __( $label, 'templately' ),
			];
		}

		return $reasons;
	}

	/**
	 * Handle the deactivation feedback AJAX submission.
	 *
	 * @return void
	 */
	public static function handle_feedback() {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'templately_nonce' ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid nonce', 'templately' ) ] );
		}

		if ( ! Helper::current_user_can( 'deactivate_plugins' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions', 'templately' ) ] );
		}

		$reason = isset( $_POST['reason'] ) ? sanitize_key( wp_unslash( $_POST['reason'] ) ) : '';
		if ( ! array_key_exists( $reason, self::REASONS ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid reason', 'templately' ) ] );
		}

		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		$response = Helper::make_api_post_request( 'v2/deactivation-feedback', [
			'reason'  => $reason,
			'message' => $message,
		] );

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [ 'message' => $response->get_error_message() ] );
		}

		wp_send_json_success();
	}
}
