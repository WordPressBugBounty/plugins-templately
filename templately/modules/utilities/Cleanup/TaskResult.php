<?php
/**
 * What one cleanup task reports from an estimate or a run.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Cleanup;

/**
 * One shape for both, deliberately: estimation and execution are the same code
 * path with `dry_run` flipped, so the number a user confirms against is produced
 * by the logic that would do the work.
 */
final class TaskResult {

	/** @var int Records and/or FILES affected — never directory nodes. */
	public $items = 0;

	/**
	 * @var int Directories removed.
	 *
	 * Counted apart from `items` deliberately: a user reading "12 items
	 * removed" means twelve files or records, not four files plus the eight
	 * folders that happened to contain them.
	 */
	public $dirs = 0;

	/** @var int Bytes reclaimed, or reclaimable when the run was a dry run. */
	public $bytes = 0;

	/** @var string[] Per-item failures. A non-empty list does NOT abort the run. */
	public $errors = [];

	/** @var bool True when the task stopped on budget with work left to do. */
	public $incomplete = false;

	/** @var bool True when the task could not run at all (e.g. uploads unwritable). */
	public $unavailable = false;

	public static function empty(): self {
		return new self();
	}

	/**
	 * A task that could not run. Distinct from a task that ran and found
	 * nothing — the UI must be able to explain the difference rather than
	 * showing "0 bytes" for both.
	 */
	public static function unavailable( string $reason ): self {
		$result              = new self();
		$result->unavailable = true;
		$result->errors[]    = $reason;

		return $result;
	}

	/**
	 * @param int $bytes
	 * @return $this
	 */
	public function add( int $items, int $bytes ): self {
		$this->items += $items;
		$this->bytes += $bytes;

		return $this;
	}

	/**
	 * @return $this
	 */
	public function add_dirs( int $dirs ): self {
		$this->dirs += $dirs;

		return $this;
	}

	/**
	 * @return $this
	 */
	public function fail( string $message ): self {
		$this->errors[] = $message;

		return $this;
	}

	/**
	 * @return $this
	 */
	public function stopped_on_budget(): self {
		$this->incomplete = true;

		return $this;
	}

	public function merge( TaskResult $other ): self {
		$this->items      += $other->items;
		$this->dirs       += $other->dirs;
		$this->bytes      += $other->bytes;
		$this->errors      = array_merge( $this->errors, $other->errors );
		$this->incomplete  = $this->incomplete || $other->incomplete;
		$this->unavailable = $this->unavailable || $other->unavailable;

		return $this;
	}

	public function to_array(): array {
		return [
			'items'       => $this->items,
			'dirs'        => $this->dirs,
			'bytes'       => $this->bytes,
			'errors'      => array_values( $this->errors ),
			'incomplete'  => $this->incomplete,
			'unavailable' => $this->unavailable,
		];
	}
}
