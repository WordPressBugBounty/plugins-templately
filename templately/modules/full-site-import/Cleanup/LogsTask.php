<?php
/**
 * Retain only the newest import logs.
 *
 * @package Templately
 */

namespace Templately\Modules\FullSiteImport\Cleanup;

use Templately\Modules\FullSiteImport\Utils\SessionData;
use Templately\Modules\Utilities\Cleanup\CleanupTask;
use Templately\Modules\Utilities\Cleanup\Context;
use Templately\Modules\Utilities\Cleanup\Remover;
use Templately\Modules\Utilities\Cleanup\RetentionKind;
use Templately\Modules\Utilities\Cleanup\Scanner;
use Templately\Modules\Utilities\Cleanup\TaskResult;
use WP_Error;

/**
 * COUNT-kind, not age-kind, and that is a deliberate choice rather than an
 * inconsistency: support wants the last N runs whatever their age. An age rule
 * would delete the log of a week-old failure someone is still asking about and
 * keep two hundred from one busy afternoon.
 *
 * Scoped to `fsi-*.log`. The log directory is SHARED — it also holds the
 * `.htaccess` and `index.php` guards that keep it from being served publicly,
 * the plugin's own hash-named diagnostic log and its rotated generation, and the
 * developer HTTP inspector's JSONL file. Enumerating the whole directory is what
 * caused the shipped defect where the guards, being the oldest files present,
 * were deleted first.
 *
 * The engine's guard denylist would now refuse those files anyway; matching only
 * our own logs means we never ask.
 */
class LogsTask implements CleanupTask {

	public function descriptor(): array {
		return [
			'id'             => 'fsi-logs',
			'label'          => __( 'Old import logs', 'templately' ),
			'group'          => 'imports',
			'scope'          => 'files',
			'retention_kind' => RetentionKind::COUNT,
			'destructive'    => true,
			'schedulable'    => true,
		];
	}

	public function estimate( Context $context ): TaskResult {
		return $this->sweep( $context->with_dry_run( true ) );
	}

	public function run( Context $context ): TaskResult {
		return $this->sweep( $context );
	}

	/**
	 * The log directory, resolved through the ENGINE's base.
	 *
	 * Deliberately not `LogHandler::get_log_dir()`, which reads `wp_upload_dir()`
	 * directly and so ignores `templately_cleanup_base_dir`. The two resolve to
	 * the same place in production, but in a test that redirects the base they
	 * diverge — and then this task hands real paths to a `Remover` whose
	 * containment check is scoped to the scratch directory, so every removal is
	 * refused as out-of-base and the task silently does nothing. A cleanup task
	 * that quietly no-ops under test is worse than one that fails.
	 */
	private function log_dir(): string {
		return Scanner::get_base_dir() . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR;
	}

	private function sweep( Context $context ): TaskResult {
		$result = TaskResult::empty();

		$logs = glob( $this->log_dir() . 'fsi-*.log' );
		if ( ! is_array( $logs ) ) {
			return $result;
		}

		$keep = $context->policy()->keep_count();
		if ( count( $logs ) <= $keep ) {
			return $result;
		}

		// The log of the session running right now is never a candidate, even if
		// it is somehow the oldest — it is being appended to.
		$current = SessionData::get_session_id();
		if ( $current ) {
			$live = $this->log_dir() . 'fsi-' . $current . '.log';
			Remover::protect( [ $live ] );
			$logs = array_values(
				array_filter(
					$logs,
					static function ( $path ) use ( $live ) {
						return $path !== $live;
					}
				)
			);
		}

		usort(
			$logs,
			static function ( $a, $b ) {
				return filemtime( $a ) <=> filemtime( $b );
			}
		);

		$surplus = array_slice( $logs, 0, max( 0, count( $logs ) - $keep ) );

		foreach ( $surplus as $path ) {
			if ( ! $context->has_budget( 0.5 ) ) {
				return $result->stopped_on_budget();
			}

			$outcome = Remover::delete_file( $path, $context );

			if ( $outcome instanceof WP_Error ) {
				$result->fail( sprintf( '%s: %s', basename( $path ), $outcome->get_error_message() ) );
				continue;
			}

			$result->merge( $outcome );
		}

		return $result;
	}
}
