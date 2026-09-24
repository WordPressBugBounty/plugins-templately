<?php
/**
 * `templately/full-site-import-status` — advance a Full Site Import by ONE
 * time-slice and report normalized status (spec 042 US2, FR-002/003/004/005).
 *
 * Poll-driven advancement (research.md §1/§3): each call drives the next slice
 * of the resumable pipeline and returns where it now stands. The agent's
 * repeated calls replace the browser's SSE `continue`→next-request loop; the
 * import only progresses while it is polled (if the agent stops, the import
 * pauses — its state is preserved and observable on a later poll).
 *
 * Drive sequence per handle:
 *   1. first call (no `progress.download_zip`): loopback JSON
 *      `create_session_and_download` (download zip + read manifest), then
 *   2. loopback SSE `import` (one slice; the handler `exit`s at the boundary),
 *   3. read the incremental log via JSON `import_status`,
 *   4. normalize via FsiStatusNormalizer.
 *
 * @package Templately\Modules\FullSiteImport\Abilities
 */

namespace Templately\Modules\FullSiteImport\Abilities;

use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\McpCore\Support\AjaxLoopbackDispatcher;
use Templately\Modules\FullSiteImport\Abilities\Support\FsiActiveImportGuard;
use Templately\Modules\FullSiteImport\Abilities\Support\FsiStatusNormalizer;
use Templately\Modules\McpCore\Support\Permissions;
use WP_Error;

class FullSiteImportStatusAbility {

	const ID = 'templately/full-site-import-status';

	public static function descriptor(): array {
		return [
			'id'                  => self::ID,
			'label'               => __( 'Track Templately Full Site Import', 'templately' ),
			'description'         => __( 'Advance a running full-site import by ONE step (advance=true, the default) and report progress + status: running, needs_retry, failed, complete, or expired. Poll-driven — keep calling until status is "complete" or "failed" (the import only moves while polled); on "needs_retry" call templately/retry-full-site-import then continue polling. Pass advance=false to read current progress cheaply WITHOUT running a step — ideal for a monitor/watcher checking whether the import has finished. The whole advance loop can run in a background process that treats a terminal status ("complete"/"failed") as the completion signal.', 'templately' ),
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'handle'         => [ 'type' => 'string', 'description' => __( 'The import handle returned by start-full-site-import.', 'templately' ) ],
					'last_log_index' => [ 'type' => 'integer', 'default' => 0 ],
					'advance'        => [ 'type' => 'boolean', 'default' => true, 'description' => __( 'false = report only, do not run a slice.', 'templately' ) ],
				],
				'required'             => [ 'handle' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'handle'         => [ 'type' => 'string' ],
					'status'         => [ 'type' => 'string', 'enum' => [ 'running', 'needs_retry', 'failed', 'complete', 'expired' ] ],
					'progress'       => [ 'type' => 'integer' ],
					'phase'          => [ 'type' => 'string' ],
					'log'            => [ 'type' => 'array' ],
					'last_log_index' => [ 'type' => 'integer' ],
					'message'        => [ 'type' => 'string' ],
					'summary'        => [ 'type' => 'object' ],
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
		$handle         = self::sanitize_handle( $input['handle'] ?? '' );
		$last_log_index = (int) ( $input['last_log_index'] ?? 0 );
		$advance        = array_key_exists( 'advance', $input ) ? (bool) $input['advance'] : true;

		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		return self::advance( $handle, $last_log_index, $advance );
	}

	/**
	 * Advance one slice (when $advance) and return normalized status. Shared
	 * with RetryFullSiteImportAbility (task T016).
	 *
	 * @param string $handle
	 * @param int    $last_log_index
	 * @param bool   $advance
	 * @return array|WP_Error
	 */
	public static function advance( string $handle, int $last_log_index = 0, bool $advance = true ) {
		$session_data = SessionData::get_data( $handle );

		// Unknown / expired handle (7-day cleanup or never existed).
		if ( empty( $session_data ) || ! is_array( $session_data ) ) {
			return [
				'handle'         => $handle,
				'status'         => FsiStatusNormalizer::STATUS_EXPIRED,
				'progress'       => 0,
				'phase'          => '',
				'log'            => [],
				'last_log_index' => $last_log_index,
			];
		}

		$sse_events = [];

		if ( $advance ) {
			$progress = $session_data['progress'] ?? [];

			// First slice for this handle: download the pack (JSON handler).
			if ( empty( $progress['download_zip'] ) ) {
				$download = AjaxLoopbackDispatcher::dispatch_json( 'create_session_and_download', [
					'session_id' => $handle,
					'id'         => (int) ( $session_data['id'] ?? 0 ),
					'platform'   => (string) ( $session_data['platform'] ?? 'elementor' ),
				] );
				if ( is_wp_error( $download ) ) {
					return $download;
				}
				// A download failure (e.g. an unentitled pack) surfaces here.
				$failure = AjaxLoopbackDispatcher::envelope_error(
					$download,
					__( 'The pack could not be downloaded.', 'templately' )
				);
				if ( null !== $failure ) {
					// `retryable` is the envelope's own field (spec 043); the handler's
					// old bespoke `should_retry` key no longer exists.
					$retry   = ! empty( $failure->get_error_data()['retryable'] );
					$message = $failure->get_error_message();
					if ( ! $retry ) {
						FsiActiveImportGuard::clear( get_current_user_id() );
					}
					return [
						'handle'         => $handle,
						'status'         => $retry ? FsiStatusNormalizer::STATUS_NEEDS_RETRY : FsiStatusNormalizer::STATUS_FAILED,
						'progress'       => 0,
						'phase'          => 'download_zip',
						'log'            => [],
						'last_log_index' => $last_log_index,
						'message'        => $message,
					];
				}
				$session_data = SessionData::get_data( $handle );
			}

			// Run one content-import slice (SSE handler; exits at the boundary).
			$sse_result = AjaxLoopbackDispatcher::dispatch_sse( 'import', [ 'session_id' => $handle ] );
			if ( is_wp_error( $sse_result ) ) {
				return $sse_result;
			}
			$sse_events   = $sse_result;
			$session_data = SessionData::get_data( $handle );
		}

		// Incremental log (best-effort; SSE events already carry status).
		$log     = [];
		$new_idx = $last_log_index;
		$log_res = AjaxLoopbackDispatcher::dispatch_json( 'import_status', [
			'session_id'   => $handle,
			'lastLogIndex' => $last_log_index,
		], 'GET' );
		if ( ! is_wp_error( $log_res ) && ! empty( $log_res['log'] ) && is_array( $log_res['log'] ) ) {
			$log     = $log_res['log'];
			$new_idx = $last_log_index + count( $log );
		}

		$normalized = FsiStatusNormalizer::normalize( $sse_events, $session_data, $log );

		// Clear the one-active-import guard on a terminal outcome.
		if ( in_array( $normalized['status'], [ FsiStatusNormalizer::STATUS_COMPLETE, FsiStatusNormalizer::STATUS_FAILED ], true ) ) {
			FsiActiveImportGuard::clear( get_current_user_id() );
		}

		return array_merge(
			[
				'handle'         => $handle,
				'log'            => $log,
				'last_log_index' => $new_idx,
			],
			$normalized
		);
	}

	/**
	 * FSI session ids are uuid4 (or, for a session minted before that change,
	 * `uniqid()`-shaped); reject anything with path or control characters before
	 * it reaches SessionData / the loopback. The character class already allows
	 * the hyphens a uuid carries, so both shapes pass.
	 *
	 * @param mixed $handle
	 * @return string|WP_Error
	 */
	public static function sanitize_handle( $handle ) {
		$handle = is_string( $handle ) ? trim( $handle ) : '';
		if ( '' === $handle || 1 !== preg_match( '/^[A-Za-z0-9_.-]{6,64}$/', $handle ) ) {
			return new WP_Error( 'expired_handle', __( 'Unknown or invalid import handle.', 'templately' ) );
		}
		return $handle;
	}
}
