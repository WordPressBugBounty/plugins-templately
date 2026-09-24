<?php
/**
 * Reclaim abandoned import working directories.
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
 * THE DISK LEAK. This is the headline defect spec 052 exists to close.
 *
 * `uploads/templately/tmp/{session_id}/` holds an extracted template pack —
 * routinely 100–500 MB of XML, JSON and media. Before this task, nothing ever
 * removed one unless an import FINISHED, and even then only on a 1-in-20
 * random chance. An import that failed or was abandoned left its pack on disk
 * permanently, and the scheduled cleanup made it worse by deleting the session
 * record, so nothing referenced the directory any more.
 *
 * A directory is reclaimable when it is EITHER orphaned (no session record at
 * all — the common case after the old record-only cleanup ran) OR its session
 * has passed the retention window. An active import is never touched, and that
 * is re-checked immediately before each removal rather than when the candidate
 * list was built.
 */
class TmpDirsTask implements CleanupTask {

	const SUBDIR = 'tmp';

	public function descriptor(): array {
		return [
			'id'             => 'fsi-tmp-dirs',
			'label'          => __( 'Abandoned import files', 'templately' ),
			'group'          => 'imports',
			'scope'          => 'files',
			'retention_kind' => RetentionKind::AGE,
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
	 * One pass over the working directories.
	 */
	protected function sweep( Context $context ): TaskResult {
		$result = TaskResult::empty();
		$root   = $this->root();

		if ( ! is_dir( $root ) ) {
			// Nothing has ever been imported on this site. Not an error.
			return $result;
		}

		$entries = @scandir( $root ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false === $entries ) {
			return $result->fail( 'Could not read the import working directory.' );
		}

		$threshold = $context->policy()->age_threshold( $this->descriptor()['id'] );

		// Collected ONCE. This only answers "does a record exist for this
		// directory"; whether that record is still ACTIVE is re-read per item
		// immediately before removal, so freshness is not traded away for it.
		// Re-querying every session row per directory turned a sweep of a few
		// hundred leftovers into a few hundred full-table reads.
		$known = $this->known_session_ids();

		foreach ( $entries as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}

			$path = $root . DIRECTORY_SEPARATOR . $entry;
			if ( ! is_dir( $path ) || is_link( $path ) ) {
				continue;
			}

			// Budget is checked per ITEM, not per task: one of these directories
			// can hold thousands of files, so a task that only checked once at
			// the start would overshoot and be killed mid-delete.
			if ( ! $context->has_budget( 2.0 ) ) {
				return $result->stopped_on_budget();
			}

			if ( ! $this->is_reclaimable( $entry, $threshold, $known ) ) {
				continue;
			}

			$outcome = Remover::delete_directory( $path, $context );

			if ( $outcome instanceof WP_Error ) {
				$result->fail( sprintf( '%s: %s', $entry, $outcome->get_error_message() ) );
				continue;
			}

			$result->merge( $outcome );
		}

		return $result;
	}

	/**
	 * Whether this directory may be removed.
	 *
	 * Re-reads the session state at call time — an import that started while the
	 * sweep was walking the directory must be exempt from that moment on.
	 */
	protected function is_reclaimable( string $entry, int $threshold, array $known = [] ): bool {
		if ( ! isset( $known[ $entry ] ) ) {
			// Orphaned: no record owns this directory. Reclaim it — this is the
			// state the old record-only cleanup left behind on every site.
			return true;
		}

		// Re-read at call time, NOT from the collected snapshot: an import that
		// started while this sweep was walking the directory must be exempt from
		// that moment on.
		return ! SessionData::is_active( $entry, $threshold );
	}

	/**
	 * Session ids that currently have a record, as a lookup map.
	 *
	 * @return array<string, true>
	 */
	protected function known_session_ids(): array {
		$known = [];

		foreach ( array_keys( SessionData::get_all_data() ) as $session_id ) {
			$known[ (string) $session_id ] = true;
		}

		return $known;
	}

	/**
	 * The working-directory root, resolved through the engine so the base-dir
	 * test seam redirects it. Reading `wp_upload_dir()` directly here would make
	 * every test in this suite run against the developer's real uploads folder.
	 */
	protected function root(): string {
		return Scanner::get_base_dir() . DIRECTORY_SEPARATOR . static::SUBDIR;
	}
}
