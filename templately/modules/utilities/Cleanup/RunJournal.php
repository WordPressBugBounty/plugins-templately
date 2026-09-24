<?php
/**
 * A rolling record of recent cleanup runs.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Cleanup;

/**
 * Enough for support to see recent behaviour; not an audit log. Bounded at
 * {@see self::DEPTH} entries so the option stays small — and non-autoloaded, so
 * a record nobody is reading costs nothing on an ordinary page load.
 *
 * Plain `update_option`, not `Templately\Utils\Options`: that helper switches to
 * user options on multisite, and this is per-site state.
 */
final class RunJournal {

	const OPTION = 'templately_cleanup_journal';

	/** Newest-first, capped. */
	const DEPTH = 20;

	/**
	 * @return array[] Newest first.
	 */
	public static function all(): array {
		$stored = get_option( self::OPTION, [] );

		return is_array( $stored ) ? $stored : [];
	}

	public static function latest(): ?array {
		$all = self::all();

		return $all[0] ?? null;
	}

	/**
	 * Record one run.
	 *
	 * @param string                  $trigger schedule | manual | post_import
	 * @param array<string,TaskResult> $results Per task.
	 * @param bool                    $dry_run
	 * @param int                     $duration_ms
	 * @return array The stored entry.
	 */
	public static function record( string $trigger, array $results, bool $dry_run, int $duration_ms ): array {
		$tasks       = [];
		$bytes_total = 0;
		$items_total = 0;

		foreach ( $results as $id => $result ) {
			$tasks[ $id ] = $result->to_array();
			$bytes_total += $result->bytes;
			$items_total += $result->items;
		}

		$entry = [
			'time'        => time(),
			'trigger'     => $trigger,
			'dry_run'     => $dry_run,
			'tasks'       => $tasks,
			'bytes_total' => $bytes_total,
			'items_total' => $items_total,
			'duration_ms' => $duration_ms,
		];

		$journal = self::all();
		array_unshift( $journal, $entry );
		$journal = array_slice( $journal, 0, self::DEPTH );

		update_option( self::OPTION, $journal, false );

		return $entry;
	}

	/**
	 * The most recent dry-run estimate of what could be reclaimed.
	 *
	 * This is what the activation prompt shows — an unactivated site journals
	 * dry runs, so there is always a current figure to state without scanning
	 * the disk again on an admin page load.
	 */
	public static function last_estimate_bytes(): int {
		foreach ( self::all() as $entry ) {
			if ( ! empty( $entry['dry_run'] ) ) {
				return (int) ( $entry['bytes_total'] ?? 0 );
			}
		}

		return 0;
	}

	public static function reset(): void {
		delete_option( self::OPTION );
	}
}
