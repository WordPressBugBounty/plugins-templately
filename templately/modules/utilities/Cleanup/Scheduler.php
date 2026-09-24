<?php
/**
 * The plugin's ONE cleanup schedule.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Cleanup;

use Templately\Utils\Helper;

/**
 * Owns the recurring sweep, the run claim that stops two sweeps overlapping, and
 * the on-demand nudge used after an import finishes.
 *
 * It also RETIRES the schedules it supersedes. Two existed before this module:
 * the import feature's `templately_fsi_daily_cleanup` and the MCP server's
 * `templately_mcp_oauth_sweep`. Leaving either registered would mean two
 * cleaners running on their own cadences with no shared claim — which is the
 * situation this module exists to end.
 */
final class Scheduler {

	const EVENT = 'templately_utilities_daily_cleanup';

	/** One-off sweep, scheduled after an import finishes. */
	const EVENT_ONCE = 'templately_utilities_cleanup_once';

	const CLAIM_OPTION = 'templately_cleanup_claim';

	/**
	 * How long a run may hold the claim before it is considered abandoned.
	 *
	 * Comfortably longer than any legitimate run (the scan budget is seconds and
	 * a sweep stops at a task boundary), short enough that a run killed by a
	 * fatal or a host restart self-heals well before the next daily fire. A
	 * stuck claim would otherwise disable cleanup permanently and silently.
	 */
	const CLAIM_TTL = 900; // 15 minutes.

	/** Schedules this module supersedes; cleared once at boot. */
	const SUPERSEDED_EVENTS = [
		'templately_fsi_daily_cleanup',
		'templately_mcp_oauth_sweep',
	];

	/** Wall-clock budget for one sweep, in seconds. */
	const RUN_BUDGET = 20.0;

	public static function boot(): void {
		add_action( self::EVENT, [ self::class, 'run_scheduled' ] );
		// The one-off carries its trigger so the journal can tell a post-import
		// sweep from the daily one — "what triggered this" is half of what the
		// run history is for.
		add_action( self::EVENT_ONCE, [ self::class, 'run_once' ], 10, 1 );
		add_action( 'init', [ self::class, 'ensure_scheduled' ] );
	}

	/**
	 * Register the recurring event, and retire the ones this replaces.
	 *
	 * Idempotent — safe on every request.
	 */
	public static function ensure_scheduled(): void {
		self::retire_superseded();

		if ( Settings::is_disabled() ) {
			// Switched off: no schedule at all, not a schedule that no-ops.
			self::unschedule();

			return;
		}

		if ( ! wp_next_scheduled( self::EVENT ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::EVENT );
		}
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::EVENT );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::EVENT );
		}
	}

	/**
	 * Clear the schedules superseded by this module.
	 *
	 * An orphaned recurring event keeps firing at its own cadence forever, even
	 * after the code that registered it is gone — so removing the registration
	 * is not enough on a site that already has the event stored.
	 */
	private static function retire_superseded(): void {
		foreach ( self::SUPERSEDED_EVENTS as $event ) {
			$timestamp = wp_next_scheduled( $event );
			while ( $timestamp ) {
				wp_unschedule_event( $timestamp, $event );
				$timestamp = wp_next_scheduled( $event );
			}
		}
	}

	/**
	 * Schedule a sweep to run shortly.
	 *
	 * Replaces the import feature's `mt_rand(1,100) <= 5` orphan sweep: the
	 * sweep now always happens, asynchronously, instead of on one import in
	 * twenty — and it no longer depends on an in-flight pack id.
	 */
	public static function kick( string $trigger = Context::TRIGGER_POST_IMPORT ): void {
		if ( Settings::is_disabled() ) {
			return;
		}

		$args = [ $trigger ];

		if ( ! wp_next_scheduled( self::EVENT_ONCE, $args ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::EVENT_ONCE, $args );
		}
	}

	/**
	 * The one-off callback. Takes the trigger the kick recorded.
	 */
	public static function run_once( string $trigger = Context::TRIGGER_POST_IMPORT ): void {
		self::run( TaskRegistry::get_instance()->schedulable_ids(), $trigger );
	}

	/**
	 * The scheduled callback: run every schedulable task.
	 */
	public static function run_scheduled(): void {
		self::run( TaskRegistry::get_instance()->schedulable_ids(), Context::TRIGGER_SCHEDULE );
	}

	/**
	 * Run the given tasks under a claim, journalling the outcome.
	 *
	 * @param string[] $task_ids
	 * @param string   $trigger
	 * @param bool     $dry_run Caller's request; an unactivated site forces true.
	 * @return array{results: array<string,TaskResult>, dry_run: bool, entry: ?array, skipped: bool}
	 */
	public static function run( array $task_ids, string $trigger, bool $dry_run = false ): array {
		if ( Settings::is_disabled() ) {
			return [ 'results' => [], 'dry_run' => true, 'entry' => null, 'skipped' => true ];
		}

		if ( ! self::claim( $trigger ) ) {
			// Another sweep holds an unexpired claim. Skip rather than queue —
			// the next scheduled fire will pick the work up.
			return [ 'results' => [], 'dry_run' => $dry_run, 'entry' => null, 'skipped' => true ];
		}

		// The gate: nothing is removed on a site nobody has activated, whatever
		// the caller asked for.
		$dry_run = $dry_run || Activation::forces_dry_run();

		$context = Context::create( $trigger, RetentionPolicy::from_settings(), $dry_run, self::RUN_BUDGET );

		try {
			$results = TaskRegistry::get_instance()->run( $task_ids, $context );
			$entry   = RunJournal::record( $trigger, $results, $dry_run, $context->elapsed_ms() );
		} finally {
			self::release();
		}

		return [ 'results' => $results, 'dry_run' => $dry_run, 'entry' => $entry, 'skipped' => false ];
	}

	/**
	 * Take the run claim, unless a live one is held.
	 */
	public static function claim( string $trigger ): bool {
		$claim = get_option( self::CLAIM_OPTION, null );

		if ( is_array( $claim ) && isset( $claim['expires_at'] ) && (int) $claim['expires_at'] > time() ) {
			return false;
		}

		if ( is_array( $claim ) ) {
			// A claim that outlived its TTL means the run holding it died —
			// a fatal, a host restart, an execution timeout. Log it, because a
			// crashed sweep is worth knowing about even though it self-heals.
			Helper::log( 'Reclaiming an expired cleanup claim from a run that did not finish.', 'cleanup' );
		}

		update_option(
			self::CLAIM_OPTION,
			[
				'started_at' => time(),
				'expires_at' => time() + self::CLAIM_TTL,
				'trigger'    => $trigger,
			],
			false
		);

		return true;
	}

	public static function release(): void {
		delete_option( self::CLAIM_OPTION );
	}

	public static function is_running(): bool {
		$claim = get_option( self::CLAIM_OPTION, null );

		return is_array( $claim ) && isset( $claim['expires_at'] ) && (int) $claim['expires_at'] > time();
	}
}
