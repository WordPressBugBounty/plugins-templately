<?php
/**
 * Reclaim expired delegated-approval records.
 *
 * @package Templately
 */

namespace Templately\Modules\McpServer\Cleanup;

use Templately\Modules\McpServer\Auth\OAuth\RecordStore;
use Templately\Modules\Utilities\Cleanup\CleanupTask;
use Templately\Modules\Utilities\Cleanup\Context;
use Templately\Modules\Utilities\Cleanup\RetentionKind;
use Templately\Modules\Utilities\Cleanup\TaskRegistry;
use Templately\Modules\Utilities\Cleanup\TaskResult;

/**
 * This module used to run its OWN daily WP-Cron sweep
 * (`templately_mcp_oauth_sweep`). It now contributes to the one shared sweep
 * instead — two cleaners on separate cadences with no shared run claim is
 * exactly what spec 052 exists to end.
 *
 * INTRINSIC retention, not age: each record carries its own `expires_at`, and
 * expiry is enforced on read as well. Applying the shared age window here would
 * either be ignored or would reclaim credentials that are still valid.
 *
 * `RecordStore::sweep()` itself is unchanged and still lives in this module —
 * only the scheduling moved.
 */
class OAuthRecordsTask implements CleanupTask {

	public static function register(): void {
		add_action(
			TaskRegistry::COLLECT_ACTION,
			static function ( TaskRegistry $registry ) {
				$registry->register_classes( [ self::class ] );
			}
		);
	}

	public function descriptor(): array {
		return [
			'id'             => 'mcp-oauth-records',
			'label'          => __( 'Expired agent approvals', 'templately' ),
			'group'          => 'cache',
			'scope'          => 'records',
			'retention_kind' => RetentionKind::INTRINSIC,
			'destructive'    => true,
			'schedulable'    => true,
		];
	}

	public function estimate( Context $context ): TaskResult {
		return TaskResult::empty()->add( RecordStore::count_expired(), 0 );
	}

	public function run( Context $context ): TaskResult {
		$expired = RecordStore::count_expired();

		if ( $context->is_dry_run() ) {
			return TaskResult::empty()->add( $expired, 0 );
		}

		RecordStore::sweep();

		return TaskResult::empty()->add( $expired, 0 );
	}
}
