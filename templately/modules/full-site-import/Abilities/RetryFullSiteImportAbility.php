<?php
/**
 * `templately/retry-full-site-import` — resume a recoverable (needs_retry) or
 * stalled (running) import (spec 042 US4, FR-010). Shares the advance path with
 * `FullSiteImportStatusAbility` (task T016) — the pipeline resumes from the
 * saved `progress`, it does not restart.
 *
 * Terminal detection: a `complete`/`failed` slice clears the one-active-import
 * guard, whereas `needs_retry` leaves it set. So if the handle is no longer the
 * user's active handle, the import already finished and there is nothing to
 * resume (`not_retryable`).
 *
 * @package Templately\Modules\FullSiteImport\Abilities
 */

namespace Templately\Modules\FullSiteImport\Abilities;

use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\FullSiteImport\Abilities\Support\FsiActiveImportGuard;
use Templately\Modules\McpCore\Support\Permissions;
use WP_Error;

class RetryFullSiteImportAbility {

	const ID = 'templately/retry-full-site-import';

	public static function descriptor(): array {
		return [
			'id'                  => self::ID,
			'label'               => __( 'Retry Templately Full Site Import', 'templately' ),
			'description'         => __( 'Resume a full-site import that reported a recoverable error, continuing from where it stopped (does not restart). Keep polling full-site-import-status afterward.', 'templately' ),
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'handle' => [ 'type' => 'string' ],
				],
				'required'             => [ 'handle' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'handle'   => [ 'type' => 'string' ],
					'status'   => [ 'type' => 'string' ],
					'progress' => [ 'type' => 'integer' ],
					'phase'    => [ 'type' => 'string' ],
				],
			],
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => [ Permissions::class, 'can_use_abilities' ],
			'access_level'        => ToolDescriptor::ACCESS_FULL,
			'annotations'         => [ 'readonly' => false, 'destructive' => false, 'idempotent' => false ],
		];
	}

	/**
	 * @param array $input
	 * @return array|WP_Error
	 */
	public static function execute( array $input ) {
		$handle = FullSiteImportStatusAbility::sanitize_handle( $input['handle'] ?? '' );
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		$session_data = SessionData::get_data( $handle );
		if ( empty( $session_data ) || ! is_array( $session_data ) ) {
			return new WP_Error( 'expired_handle', __( 'Unknown or expired import handle.', 'templately' ) );
		}

		$active = FsiActiveImportGuard::get_active( get_current_user_id() );
		if ( empty( $active ) || ( $active['session_id'] ?? '' ) !== $handle ) {
			return new WP_Error( 'not_retryable', __( 'This import has already finished and cannot be retried.', 'templately' ) );
		}

		return FullSiteImportStatusAbility::advance( $handle, 0, true );
	}
}
