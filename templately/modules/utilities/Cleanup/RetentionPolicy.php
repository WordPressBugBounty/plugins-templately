<?php
/**
 * The retention rules in force for one run.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Cleanup;

/**
 * Resolved ONCE per run and threaded through, so two tasks in the same sweep
 * cannot see different values because an administrator saved settings midway.
 *
 * The age window is SHARED: every age-kind task reads this one number. They all
 * used 7 days already, so unifying them changes no behaviour — it removes the
 * possibility of drift. A per-task override exists for the case a global value
 * genuinely cannot serve, and is unused by default.
 */
final class RetentionPolicy {

	/** @var int */
	private $age_days;

	/** @var int */
	private $ceiling_bytes;

	/** @var bool */
	private $ceiling_enabled;

	/** @var int */
	private $log_keep;

	private function __construct( int $age_days, int $ceiling_bytes, bool $ceiling_enabled, int $log_keep ) {
		$this->age_days        = $age_days;
		$this->ceiling_bytes   = $ceiling_bytes;
		$this->ceiling_enabled = $ceiling_enabled;
		$this->log_keep        = $log_keep;
	}

	public static function from_settings(): self {
		$settings = Settings::get();

		return new self(
			$settings['age_days'],
			$settings['ceiling_bytes'],
			$settings['ceiling_enabled'],
			$settings['log_keep']
		);
	}

	/**
	 * Explicit construction, for tests and for callers that need a fixed policy.
	 */
	public static function create( int $age_days, int $ceiling_bytes, bool $ceiling_enabled, int $log_keep ): self {
		return new self( $age_days, $ceiling_bytes, $ceiling_enabled, $log_keep );
	}

	/**
	 * The shared age window, in days, for a given task.
	 *
	 * @param string $task_id Task the window is being read for; passed to the filter.
	 */
	public function age_days( string $task_id = '' ): int {
		/**
		 * Override the retention window for one task.
		 *
		 * @param int    $days
		 * @param string $task_id
		 */
		return (int) apply_filters( 'templately_cleanup_retention_days', $this->age_days, $task_id );
	}

	/**
	 * Unix timestamp before which age-kind data is expired.
	 */
	public function age_threshold( string $task_id = '' ): int {
		return time() - ( $this->age_days( $task_id ) * DAY_IN_SECONDS );
	}

	public function ceiling_enabled(): bool {
		return $this->ceiling_enabled;
	}

	/**
	 * The size ceiling in bytes, or 0 when disabled.
	 */
	public function ceiling_bytes( string $task_id = '' ): int {
		if ( ! $this->ceiling_enabled ) {
			return 0;
		}

		/**
		 * Override the storage ceiling for one task.
		 *
		 * @param int    $bytes
		 * @param string $task_id
		 */
		return (int) apply_filters( 'templately_cleanup_size_ceiling', $this->ceiling_bytes, $task_id );
	}

	/**
	 * How many count-kind items to retain.
	 */
	public function keep_count(): int {
		return $this->log_keep;
	}

	/**
	 * Whether the shared age window applies to a descriptor's retention kind.
	 *
	 * Count-kind and intrinsic-kind tasks MUST ignore it — see RetentionKind.
	 */
	public function applies_to( TaskDescriptor $descriptor ): bool {
		return RetentionKind::uses_age( $descriptor->retention_kind );
	}

	public function to_array(): array {
		return [
			'age_days'        => $this->age_days,
			'ceiling_bytes'   => $this->ceiling_bytes,
			'ceiling_enabled' => $this->ceiling_enabled,
			'log_keep'        => $this->log_keep,
		];
	}
}
