<?php
/**
 * `templately/revert-full-site-import` — roll back an import's changes (spec 042
 * US4, FR-010). Loopback `templately_pack_import_revert` (RevertController),
 * which restores the `__templately_*` backup options and deletes the imported
 * content list — the same revert the browser dashboard performs.
 *
 * @package Templately\Modules\FullSiteImport\Abilities
 */

namespace Templately\Modules\FullSiteImport\Abilities;

use Templately\Modules\McpCore\Registry\ToolDescriptor;
use Templately\Modules\McpCore\Support\AjaxLoopbackDispatcher;
use Templately\Modules\FullSiteImport\Abilities\Support\FsiActiveImportGuard;
use Templately\Modules\McpCore\Support\Permissions;
use WP_Error;

class RevertFullSiteImportAbility {

	const ID = 'templately/revert-full-site-import';

	public static function descriptor(): array {
		return [
			'id'                  => self::ID,
			'label'               => __( 'Revert Templately Full Site Import', 'templately' ),
			'description'         => __( 'Undo a full-site import, restoring the site to its pre-import state (backed-up options restored and imported content removed).', 'templately' ),
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
					'reverted' => [ 'type' => 'boolean' ],
					'restored' => [ 'type' => 'object' ],
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
		$handle = FullSiteImportStatusAbility::sanitize_handle( $input['handle'] ?? '' );
		if ( is_wp_error( $handle ) ) {
			return $handle;
		}

		$response = AjaxLoopbackDispatcher::dispatch_json( 'import_revert', [ 'session_id' => $handle ] );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Reverting abandons the import, so always release the one-active-import
		// guard — even when there was nothing to revert. Otherwise a handle from
		// an abnormally-terminated import could keep wedging future starts with
		// "already_running" until the 7-day session expiry.
		FsiActiveImportGuard::clear( get_current_user_id() );

		// RevertController answers with an error envelope when there were no backups.
		if ( null !== AjaxLoopbackDispatcher::envelope_error( $response, '' ) ) {
			return new WP_Error( 'nothing_to_revert', __( 'There is nothing to revert for this import.', 'templately' ) );
		}

		$data = $response['data'] ?? [];
		return [
			'handle'   => $handle,
			'reverted' => true,
			'restored' => [
				'options'               => ! empty( $data['options'] ),
				'imported_list_cleared' => ! empty( $data['imported_list'] ),
			],
		];
	}
}
