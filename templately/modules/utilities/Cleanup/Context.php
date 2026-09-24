<?php
/**
 * The conditions of a single cleanup run.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Cleanup;

/**
 * Immutable. `with_dry_run()` returns a copy, which is how a task's `estimate()`
 * reuses its `run()` path without mutating the context the rest of the sweep is
 * sharing.
 */
final class Context {

	const TRIGGER_SCHEDULE    = 'schedule';
	const TRIGGER_MANUAL      = 'manual';
	const TRIGGER_POST_IMPORT = 'post_import';

	/** @var bool */
	private $dry_run;

	/** @var string */
	private $trigger;

	/** @var RetentionPolicy */
	private $policy;

	/** @var float Seconds this run may spend in total. */
	private $budget;

	/** @var float */
	private $started_at;

	private function __construct( bool $dry_run, string $trigger, RetentionPolicy $policy, float $budget, float $started_at ) {
		$this->dry_run    = $dry_run;
		$this->trigger    = $trigger;
		$this->policy     = $policy;
		$this->budget     = $budget;
		$this->started_at = $started_at;
	}

	public static function create( string $trigger, RetentionPolicy $policy, bool $dry_run = false, float $budget = 20.0 ): self {
		return new self( $dry_run, $trigger, $policy, $budget, microtime( true ) );
	}

	public function with_dry_run( bool $dry_run ): self {
		$copy          = clone $this;
		$copy->dry_run = $dry_run;

		return $copy;
	}

	public function is_dry_run(): bool {
		return $this->dry_run;
	}

	public function trigger(): string {
		return $this->trigger;
	}

	public function policy(): RetentionPolicy {
		return $this->policy;
	}

	/**
	 * Seconds left before this run must stop at a task boundary.
	 */
	public function remaining(): float {
		return $this->budget - ( microtime( true ) - $this->started_at );
	}

	/**
	 * Check this at your OWN item boundary, not only between tasks. A task that
	 * runs to completion regardless gets killed mid-delete by the host, which is
	 * the one failure mode a resumable sweep exists to avoid.
	 *
	 * @param float $headroom Seconds a single item is expected to need.
	 */
	public function has_budget( float $headroom = 1.0 ): bool {
		return $this->remaining() > $headroom;
	}

	public function elapsed_ms(): int {
		return (int) round( ( microtime( true ) - $this->started_at ) * 1000 );
	}
}
