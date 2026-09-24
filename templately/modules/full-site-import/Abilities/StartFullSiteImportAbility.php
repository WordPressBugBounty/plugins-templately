<?php
/**
 * `templately/start-full-site-import` — begin a Full Site Import and return a
 * durable handle immediately (spec 042 US1, FR-001/007/008/013/015).
 *
 * Fast by design: this only creates the FSI session (loopback
 * `templately_pack_import_settings`) and returns the `session_id` as the
 * handle. The bounded-but-slower pack download and the lengthy content import
 * happen across subsequent `full-site-import-status` slices (poll-driven,
 * research.md §3) so `start` never blocks on a download.
 *
 * Reuses 041 infra verbatim: `ImportRateLimiter`, `DependencyGateResolver`
 * (its `resolve_item` already covers the `packs` collection). Entitlement is
 * enforced by the backend during the download slice and surfaced by the status
 * ability with a purchase URL (ProGateResolver) — no site content is imported
 * for an unentitled pack.
 *
 * @package Templately\Modules\FullSiteImport\Abilities
 */

namespace Templately\Modules\FullSiteImport\Abilities;

use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\McpCore\Support\AjaxLoopbackDispatcher;
use Templately\Modules\McpCore\Support\DependencyGateResolver;
use Templately\Modules\FullSiteImport\Abilities\Support\FsiActiveImportGuard;
use Templately\Modules\McpCore\Support\ImportRateLimiter;
use Templately\Modules\McpCore\Support\Permissions;
use Templately\Utils\Options;
use WP_Error;

class StartFullSiteImportAbility {

	const ID = 'templately/start-full-site-import';

	public static function descriptor(): array {
		return [
			'id'                  => self::ID,
			'label'               => __( 'Start Templately Full Site Import', 'templately' ),
			'description'         => __( 'Begin importing a full-site Templately pack and return a tracking handle immediately — the import does NOT run on its own. Drive it to completion by repeatedly calling templately/full-site-import-status with advance=true until its status is "complete" or "failed"; the import only progresses while you poll. This poll loop can be automated by a background/monitor process that watches for the terminal status.', 'templately' ),
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'id'       => [ 'type' => 'integer', 'description' => __( 'Full-site pack id (from discover-templates type=pack).', 'templately' ) ],
					'platform' => [ 'type' => 'string', 'enum' => [ 'elementor', 'gutenberg' ], 'default' => 'elementor' ],
				],
				'required'             => [ 'id' ],
				'additionalProperties' => false,
			],
			'output_schema'       => [
				'type'       => 'object',
				'properties' => [
					'handle'          => [ 'type' => 'string' ],
					'status'          => [ 'type' => 'string' ],
					'already_running' => [ 'type' => 'boolean' ],
					'next'            => [ 'type' => 'string' ],
				],
			],
			'execute_callback'    => [ self::class, 'execute' ],
			'permission_callback' => [ Permissions::class, 'can_use_abilities' ],
			'access_level'        => ToolDescriptor::ACCESS_FULL,
			'annotations'         => [ 'readonly' => false, 'destructive' => true, 'idempotent' => false ],
		];
	}

	/**
	 * @param array $input
	 * @return array|WP_Error
	 */
	public static function execute( array $input ) {
		$id       = (int) ( $input['id'] ?? 0 );
		$platform = (string) ( $input['platform'] ?? 'elementor' );

		if ( empty( $id ) ) {
			return new WP_Error( 'pack_not_found', __( 'A full-site pack id is required.', 'templately' ) );
		}
		if ( ! in_array( $platform, [ 'elementor', 'gutenberg' ], true ) ) {
			$platform = 'elementor';
		}

		$not_connected = self::require_connection();
		if ( is_wp_error( $not_connected ) ) {
			return $not_connected;
		}

		$rate_limit_error = ImportRateLimiter::check();
		if ( $rate_limit_error ) {
			return $rate_limit_error;
		}

		// FR-015: one active import per user — return the in-flight handle.
		$user_id = get_current_user_id();
		$active  = FsiActiveImportGuard::get_active( $user_id );
		if ( $active ) {
			return [
				'handle'          => $active['session_id'],
				'status'          => 'running',
				'already_running' => true,
				'next'            => __( 'An import is already in progress. Poll templately/full-site-import-status with this handle.', 'templately' ),
			];
		}

		// FR-008: unmet plugin dependencies (reuses 041's packs-aware resolver).
		$dependency_check = DependencyGateResolver::check( $id, $platform );
		if ( is_wp_error( $dependency_check ) ) {
			return $dependency_check;
		}

		// Create the session (no download here) via the frozen AJAX contract.
		$response = AjaxLoopbackDispatcher::dispatch_json( 'import_settings', [
			'id'       => $id,
			'platform' => $platform,
		] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$failure = AjaxLoopbackDispatcher::envelope_error(
			$response,
			__( 'The import backend could not create a session.', 'templately' )
		);
		if ( null !== $failure ) {
			return $failure;
		}

		if ( empty( $response['data']['session_id'] ) ) {
			return new WP_Error(
				'start_failed',
				__( 'The import backend could not create a session.', 'templately' )
			);
		}

		$handle = (string) $response['data']['session_id'];
		FsiActiveImportGuard::set_active( $user_id, $handle );

		return [
			'handle'          => $handle,
			'status'          => 'running',
			'already_running' => false,
			'next'            => __( 'Call templately/full-site-import-status with this handle to advance and track the import.', 'templately' ),
		];
	}

	/**
	 * A connected, non-disconnected Templately account is a precondition
	 * (spec 042 Assumptions / Edge Cases); connecting is 041's job.
	 *
	 * @return true|WP_Error
	 */
	private static function require_connection() {
		$options = Options::get_instance();
		$user    = $options->get( 'user' );

		if ( empty( $options->get( 'api_key' ) ) || ( is_array( $user ) && ! empty( $user['is_disconnected'] ) ) ) {
			return new WP_Error(
				'not_connected',
				__( 'No connected Templately account. Connect first using the auth abilities, then retry.', 'templately' )
			);
		}

		return true;
	}
}
