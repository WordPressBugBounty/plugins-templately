<?php
/**
 * The cleanup contribution registry.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Cleanup;

use InvalidArgumentException;
use Templately\Utils\Helper;
use Throwable;

/**
 * Features contribute cleanup tasks here; this class never names one.
 *
 * That inversion is the point. A direct call list would make this module depend
 * on full-site-import, block-patterns, mcp-server and both developer modules —
 * so the shared cleaner could not boot on a site where any of them is disabled,
 * and every future feature producing disposable data would have to edit this
 * file. The same reasoning, and the same shape, as `modules/mcp-core/`.
 *
 * Collection is LAZY and happens once per registry lifetime. Two reasons:
 *  - `descriptor()` translates its label, and a `__()` call during
 *    `plugins_loaded` (before the textdomain loads) returns the untranslated
 *    string forever;
 *  - a request that never touches cleanup should not construct seven task
 *    objects.
 */
final class TaskRegistry {

	/**
	 * Contribute cleanup tasks.
	 *
	 * Fired once, the first time the registry is used — a cron fire, a REST
	 * listing, a developer screen. Handlers receive the registry and call
	 * `register_classes()` on it.
	 */
	const COLLECT_ACTION = 'templately_cleanup_register_tasks';

	/** @var self|null */
	private static $instance = null;

	/** @var string[] Class names awaiting resolution. */
	private $pending_classes = [];

	/** @var array<string, TaskDescriptor> */
	private $descriptors = [];

	/** @var array<string, CleanupTask> */
	private $tasks = [];

	/** @var bool */
	private $collected = false;

	/** @var bool */
	private $resolved = false;

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Test seam. The next use re-fires {@see self::COLLECT_ACTION}, so the
	 * rebuilt registry is identical to the booted one — resetting must never
	 * leave a registry that only one module has repopulated.
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * Queue task classes for lazy resolution.
	 *
	 * Takes CLASS NAMES, not instances: nothing is constructed and no
	 * `descriptor()` is called until the registry is actually used.
	 *
	 * @param string[] $classes
	 */
	public function register_classes( array $classes ): void {
		foreach ( $classes as $class ) {
			$this->pending_classes[] = $class;
		}

		$this->resolved = false;
	}

	/**
	 * @return array<string, TaskDescriptor> Keyed by task id.
	 */
	public function descriptors(): array {
		$this->resolve();

		return $this->descriptors;
	}

	/**
	 * Descriptors for tasks that may run unattended. Manual-only tasks are
	 * excluded from scheduled sweeps and from the run-everything action.
	 *
	 * @return array<string, TaskDescriptor>
	 */
	public function schedulable_descriptors(): array {
		return array_filter(
			$this->descriptors(),
			static function ( TaskDescriptor $descriptor ) {
				return $descriptor->schedulable;
			}
		);
	}

	public function get_descriptor( string $id ): ?TaskDescriptor {
		$descriptors = $this->descriptors();

		return $descriptors[ $id ] ?? null;
	}

	/**
	 * @return string[]
	 */
	public function schedulable_ids(): array {
		return array_keys( $this->schedulable_descriptors() );
	}

	/**
	 * What one task would remove, without removing it.
	 */
	public function estimate( string $id, Context $context ): TaskResult {
		$task = $this->task( $id );
		if ( null === $task ) {
			return TaskResult::empty();
		}

		try {
			return $task->estimate( $context->with_dry_run( true ) );
		} catch ( Throwable $e ) {
			return TaskResult::empty()->fail(
				sprintf( 'Estimate failed for "%s": %s', $id, $e->getMessage() )
			);
		}
	}

	/**
	 * Run the named tasks in order, one result each.
	 *
	 * A task that throws is recorded and the sweep continues — one bad task must
	 * not cost the others their run. A task that exhausts the budget ends the
	 * sweep at that boundary; the rest are left for the next run.
	 *
	 * @param string[] $ids
	 * @return array<string, TaskResult>
	 */
	public function run( array $ids, Context $context ): array {
		$results = [];

		foreach ( $ids as $id ) {
			$task = $this->task( $id );
			if ( null === $task ) {
				continue;
			}

			if ( ! $context->has_budget() ) {
				$results[ $id ] = TaskResult::empty()->stopped_on_budget();
				break;
			}

			try {
				$results[ $id ] = $task->run( $context );
			} catch ( Throwable $e ) {
				// Deliberately caught, not propagated: an unattended sweep that
				// aborts on the first failing task leaves every later task
				// permanently unrun, and the failure is invisible until someone
				// reads the journal.
				$results[ $id ] = TaskResult::empty()->fail(
					sprintf( 'Cleanup task "%s" failed: %s', $id, $e->getMessage() )
				);
				Helper::log( sprintf( 'Cleanup task "%s" threw: %s', $id, $e->getMessage() ), 'cleanup', 'error' );
			}
		}

		return $results;
	}

	/**
	 * Resolve a task instance, or null when the id is unknown.
	 */
	private function task( string $id ): ?CleanupTask {
		$this->resolve();

		return $this->tasks[ $id ] ?? null;
	}

	/**
	 * Ask contributors to declare themselves, then turn class names into
	 * descriptors. Idempotent.
	 */
	private function resolve(): void {
		// Once per registry lifetime, and BEFORE the pending list is read below —
		// handlers call register_classes(), which appends to it. `collected` is
		// set first so a handler that touches the registry cannot recurse.
		if ( ! $this->collected ) {
			$this->collected = true;

			/**
			 * Declare a module's cleanup tasks.
			 *
			 * @param TaskRegistry $registry Call `register_classes()` on it.
			 */
			do_action( self::COLLECT_ACTION, $this );
		}

		if ( $this->resolved ) {
			return;
		}

		// Set BEFORE the loop for the same reason.
		$this->resolved = true;

		$pending               = $this->pending_classes;
		$this->pending_classes = [];

		foreach ( $pending as $class ) {
			if ( ! is_string( $class ) || ! class_exists( $class ) ) {
				// A module that was disabled or removed between registering and
				// resolving simply contributes nothing. Not an error.
				continue;
			}

			try {
				$task = new $class();

				if ( ! $task instanceof CleanupTask ) {
					continue;
				}

				$descriptor = TaskDescriptor::from_array( $task->descriptor(), $class );
			} catch ( InvalidArgumentException $e ) {
				// A malformed descriptor is a bug in the contributing module.
				// Skip that one task and keep the rest of the registry usable —
				// taking the whole cleaner down over one bad contributor is
				// worse than running without it.
				Helper::log( $e->getMessage(), 'cleanup', 'error' );
				continue;
			} catch ( Throwable $e ) {
				Helper::log(
					sprintf( 'Cleanup task %s could not be constructed: %s', $class, $e->getMessage() ),
					'cleanup',
					'error'
				);
				continue;
			}

			if ( isset( $this->descriptors[ $descriptor->id ] ) ) {
				Helper::log(
					sprintf( 'Duplicate cleanup task id "%s" from %s — ignored.', $descriptor->id, $class ),
					'cleanup',
					'error'
				);
				continue;
			}

			$this->descriptors[ $descriptor->id ] = $descriptor;
			$this->tasks[ $descriptor->id ]       = $task;
		}
	}
}
