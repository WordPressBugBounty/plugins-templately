<?php
/**
 * Maps the raw signals of an FSI time-slice into the normalized MCP status
 * enum (spec 042 FR-002/FR-003/FR-004, research.md §4, data-model.md).
 *
 * PURE: takes already-fetched inputs (the SSE events returned by the latest
 * `import` slice, the SessionData row, and the log tail) and returns a plain
 * array. No I/O, so it is unit-testable in isolation (T005).
 *
 * The FSI pipeline (Constitution V exception taxonomy) emits exactly these
 * terminal / again signals, so the mapping is total:
 *   - action:'complete'            → complete (carries `results` summary)
 *   - action:'error' retry:true    → needs_retry (RetryableErrorException)
 *   - action:'error' retry:false   → failed (Non-retirable / Unknown)
 *   - action:'continue' / a slice ran with no terminal event → running
 *   - empty session row (7-day cleanup) or unknown handle → expired
 *
 * @package Templately\Modules\FullSiteImport\Abilities\Support
 */

namespace Templately\Modules\FullSiteImport\Abilities\Support;

class FsiStatusNormalizer {

	const STATUS_RUNNING     = 'running';
	const STATUS_NEEDS_RETRY = 'needs_retry';
	const STATUS_FAILED      = 'failed';
	const STATUS_COMPLETE    = 'complete';
	const STATUS_EXPIRED     = 'expired';

	/**
	 * @param array $sse_events   Parsed SSE event objects from the latest slice.
	 * @param mixed $session_data The SessionData row for the handle (array), or empty.
	 * @param array $log_tail     Recent log lines (for surfacing `log`).
	 * @return array {
	 *   status, progress (0-100), phase, message?, summary?
	 * }
	 */
	public static function normalize( array $sse_events, $session_data, array $log_tail = [] ): array {
		// Unknown / expired handle: no persisted session to speak of.
		if ( empty( $session_data ) || ! is_array( $session_data ) ) {
			return [
				'status'   => self::STATUS_EXPIRED,
				'progress' => 0,
				'phase'    => '',
			];
		}

		$terminal = self::find_terminal_event( $sse_events );
		$progress = self::derive_progress( $sse_events, $session_data );
		$phase    = self::derive_phase( $sse_events, $session_data );

		if ( $terminal && 'complete' === $terminal['action'] ) {
			return [
				'status'   => self::STATUS_COMPLETE,
				'progress' => 100,
				'phase'    => 'finalizer',
				'summary'  => $terminal['results'] ?? [],
			];
		}

		if ( $terminal && 'error' === $terminal['action'] ) {
			$retry = ! empty( $terminal['retry'] );
			return [
				'status'   => $retry ? self::STATUS_NEEDS_RETRY : self::STATUS_FAILED,
				'progress' => $progress,
				'phase'    => $phase,
				// Message + code only — never a stack trace (FR-006; the trace is
				// already stripped server-side in RunsImport::import()).
				'message'  => isset( $terminal['message'] ) ? (string) $terminal['message'] : '',
			];
		}

		// A slice ran (or is between chunks) with no terminal event: still running.
		return [
			'status'   => self::STATUS_RUNNING,
			'progress' => $progress,
			'phase'    => $phase,
		];
	}

	/**
	 * The last complete/error event in the slice, if any (terminal wins over
	 * intermediate `continue`/`updateLog` events).
	 *
	 * @param array $sse_events
	 * @return array|null
	 */
	private static function find_terminal_event( array $sse_events ): ?array {
		$found = null;
		foreach ( $sse_events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$action = $event['action'] ?? '';
			if ( 'complete' === $action || 'error' === $action ) {
				$found = $event; // keep the last one
			}
		}
		return $found;
	}

	/**
	 * Coarse 0-100 progress: prefer an explicit `progress` from the latest
	 * updateLog event; otherwise approximate from completed session steps.
	 *
	 * @param array $sse_events
	 * @param array $session_data
	 * @return int
	 */
	private static function derive_progress( array $sse_events, array $session_data ): int {
		$explicit = null;
		foreach ( $sse_events as $event ) {
			if ( is_array( $event ) && isset( $event['progress'] ) && is_numeric( $event['progress'] ) ) {
				$explicit = (int) $event['progress'];
			}
		}
		if ( null !== $explicit ) {
			return max( 0, min( 100, $explicit ) );
		}

		// Fallback: fraction of known early setup steps completed.
		$steps    = [ 'create_log_dir', 'check_writing_permission', 'download_zip' ];
		$progress = $session_data['progress'] ?? [];
		$done     = 0;
		foreach ( $steps as $step ) {
			if ( ! empty( $progress[ $step ] ) ) {
				$done++;
			}
		}
		// Cap the setup-only fallback well below 100 — content import follows.
		return (int) round( ( $done / count( $steps ) ) * 40 );
	}

	/**
	 * Current phase label from the latest informative event, else the last
	 * completed session step.
	 *
	 * @param array $sse_events
	 * @param array $session_data
	 * @return string
	 */
	private static function derive_phase( array $sse_events, array $session_data ): string {
		$phase = '';
		foreach ( $sse_events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			if ( ! empty( $event['info'] ) ) {
				$phase = (string) $event['info'];
			} elseif ( ! empty( $event['type'] ) && ! in_array( $event['type'], [ 'eventLog', 'updateLog', 'start' ], true ) ) {
				$phase = (string) $event['type'];
			}
		}

		if ( '' === $phase ) {
			$progress = $session_data['progress'] ?? [];
			if ( is_array( $progress ) && ! empty( $progress ) ) {
				$keys  = array_keys( array_filter( $progress ) );
				$phase = end( $keys ) ?: '';
			}
		}

		return $phase;
	}
}
