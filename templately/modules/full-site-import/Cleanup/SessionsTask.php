<?php
/**
 * Reclaim expired import sessions and AI process records.
 *
 * @package Templately
 */

namespace Templately\Modules\FullSiteImport\Cleanup;

use Templately\Modules\FullSiteImport\Utils\AIUtils;
use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Modules\Utilities\Cleanup\CleanupTask;
use Templately\Modules\Utilities\Cleanup\Context;
use Templately\Modules\Utilities\Cleanup\RetentionKind;
use Templately\Modules\Utilities\Cleanup\TaskResult;

/**
 * The database half of import cleanup: the `templately_session_*` rows and the
 * `templately_ai_process_*` rows (plus their per-page sub-keys).
 *
 * This does NOT reimplement what "expired" or "active" means — it delegates to
 * `SessionData::cleanup_expired()`, whose signature and return shape are frozen
 * (other callers and tests depend on them). Only this module knows that a
 * session with incomplete progress markers is still running, and that judgement
 * stays here rather than migrating into the shared engine.
 */
class SessionsTask implements CleanupTask {

	public function descriptor(): array {
		return [
			'id'             => 'fsi-sessions',
			'label'          => __( 'Expired import records', 'templately' ),
			'group'          => 'imports',
			// Rows only — an unwritable uploads directory must not stop this
			// from running.
			'scope'          => 'records',
			'retention_kind' => RetentionKind::AGE,
			'destructive'    => true,
			'schedulable'    => true,
		];
	}

	public function estimate( Context $context ): TaskResult {
		$threshold = $context->policy()->age_threshold( 'fsi-sessions' );
		$expired   = 0;

		foreach ( SessionData::get_all_data() as $session_id => $session_data ) {
			if ( SessionData::is_active( $session_id, $threshold ) ) {
				continue;
			}
			$expired++;
		}

		// Rows carry no meaningful byte figure; report the count so the UI can
		// say "12 records" rather than an implausible size.
		return TaskResult::empty()->add( $expired, 0 );
	}

	public function run( Context $context ): TaskResult {
		if ( $context->is_dry_run() ) {
			return $this->estimate( $context );
		}

		$days      = $context->policy()->age_days( 'fsi-sessions' );
		$outcome   = SessionData::cleanup_expired( $days );
		$processes = isset( $outcome['removed_process_ids'] ) ? count( (array) $outcome['removed_process_ids'] ) : 0;

		return TaskResult::empty()->add( (int) ( $outcome['removed_count'] ?? 0 ) + $processes, 0 );
	}
}
