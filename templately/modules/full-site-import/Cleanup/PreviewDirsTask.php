<?php
/**
 * Reclaim orphaned AI preview directories.
 *
 * @package Templately
 */

namespace Templately\Modules\FullSiteImport\Cleanup;

use Templately\Modules\FullSiteImport\Utils\AIUtils;
use Templately\Modules\FullSiteImport\Utils\SessionData;

/**
 * `uploads/templately/preview/{process_id}/` — AI-generated page output kept for
 * the customizer preview. Same leak as the working directories, same fix: a
 * directory is reclaimable when no process record owns it, or when the owning
 * process has passed the retention window and its linked session is not active.
 *
 * Only the ownership question differs from {@see TmpDirsTask}, so that is the
 * only thing overridden.
 */
class PreviewDirsTask extends TmpDirsTask {

	const SUBDIR = 'preview';

	/**
	 * Ownership here is a process record, not a session one, so the parent's
	 * session snapshot would be a full-table read this task never consults.
	 */
	protected function known_session_ids(): array {
		return [];
	}

	public function descriptor(): array {
		return [
			'id'             => 'fsi-preview-dirs',
			'label'          => __( 'Abandoned AI preview files', 'templately' ),
			'group'          => 'imports',
			'scope'          => 'files',
			'retention_kind' => 'age',
			'destructive'    => true,
			'schedulable'    => true,
		];
	}

	/**
	 * @param string $entry     A process id.
	 * @param int    $threshold Unix time before which a record is expired.
	 * @param array  $known     Unused here — ownership is a PROCESS record, not a
	 *                          session one, and those are read individually.
	 */
	protected function is_reclaimable( string $entry, int $threshold, array $known = [] ): bool {
		$process = AIUtils::read_process( $entry );

		if ( ! is_array( $process ) || empty( $process ) ) {
			// No record owns this directory.
			return true;
		}

		$updated_at = isset( $process['_updated_at'] ) ? (int) $process['_updated_at'] : 0;
		if ( $updated_at >= $threshold && $updated_at > 0 ) {
			return false;
		}

		// A process whose session is still live must survive even if its own
		// row looks stale — the session is the thing actually running.
		if ( ! empty( $process['session_id'] ) && SessionData::is_active( (string) $process['session_id'], $threshold ) ) {
			return false;
		}

		return true;
	}
}
