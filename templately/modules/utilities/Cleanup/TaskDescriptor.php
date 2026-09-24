<?php
/**
 * What a contributed cleanup task declares about itself.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Cleanup;

use InvalidArgumentException;

/**
 * Normalized descriptor. Built by the registry from each task's `descriptor()`
 * array so the engine, the REST layer and the UI all read one validated shape
 * rather than trusting whatever a contributor returned.
 *
 * Validation is strict and throws: a malformed descriptor is a programming
 * error in the contributing module, and failing loudly at registration beats
 * discovering it when a scheduled sweep silently skips a task at 3am.
 */
final class TaskDescriptor {

	/** @var string Unique, kebab-case. The address the REST run route takes. */
	public $id;

	/** @var string Translated label. Resolved lazily — never at registration. */
	public $label;

	/** @var string UI grouping, e.g. `imports`, `cache`. */
	public $group;

	/** @var string `files` | `records` | `both`. */
	public $scope;

	/** @var string One of RetentionKind. */
	public $retention_kind;

	/** @var bool Destructive tasks require explicit confirmation. */
	public $destructive;

	/** @var bool False = manual-only; excluded from scheduled and batch runs. */
	public $schedulable;

	const SCOPE_FILES   = 'files';
	const SCOPE_RECORDS = 'records';
	const SCOPE_BOTH    = 'both';

	private function __construct() {}

	/**
	 * @param array  $fields Raw descriptor from a task.
	 * @param string $source Class name, for a diagnosable error message.
	 * @throws InvalidArgumentException When the descriptor is malformed.
	 */
	public static function from_array( array $fields, string $source = '' ): self {
		$where = $source ? sprintf( ' (declared by %s)', $source ) : '';

		$id = isset( $fields['id'] ) ? (string) $fields['id'] : '';
		if ( '' === $id || sanitize_key( $id ) !== $id ) {
			throw new InvalidArgumentException(
				sprintf( 'Cleanup task id must be a non-empty kebab-case key, got "%s"%s.', $id, $where )
			);
		}

		$scope = isset( $fields['scope'] ) ? (string) $fields['scope'] : self::SCOPE_BOTH;
		if ( ! in_array( $scope, [ self::SCOPE_FILES, self::SCOPE_RECORDS, self::SCOPE_BOTH ], true ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Cleanup task "%s" declared an unknown scope "%s"%s.', $id, $scope, $where )
			);
		}

		$kind = isset( $fields['retention_kind'] ) ? (string) $fields['retention_kind'] : '';
		if ( ! RetentionKind::is_valid( $kind ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'Cleanup task "%s" must declare a retention kind (%s), got "%s"%s.',
					$id,
					implode( ', ', RetentionKind::all() ),
					$kind,
					$where
				)
			);
		}

		$descriptor                 = new self();
		$descriptor->id             = $id;
		$descriptor->label          = isset( $fields['label'] ) ? (string) $fields['label'] : $id;
		$descriptor->group          = isset( $fields['group'] ) ? (string) $fields['group'] : 'general';
		$descriptor->scope          = $scope;
		$descriptor->retention_kind = $kind;
		$descriptor->destructive    = isset( $fields['destructive'] ) ? (bool) $fields['destructive'] : true;
		$descriptor->schedulable    = isset( $fields['schedulable'] ) ? (bool) $fields['schedulable'] : true;

		return $descriptor;
	}

	/**
	 * Whether this task touches the filesystem, and therefore whether an
	 * unwritable uploads directory makes it unavailable.
	 */
	public function touches_files(): bool {
		return self::SCOPE_RECORDS !== $this->scope;
	}

	/**
	 * @return array Wire shape consumed by the REST layer and the UI.
	 */
	public function to_array(): array {
		return [
			'id'             => $this->id,
			'label'          => $this->label,
			'group'          => $this->group,
			'scope'          => $this->scope,
			'retention_kind' => $this->retention_kind,
			'destructive'    => $this->destructive,
			'schedulable'    => $this->schedulable,
		];
	}
}
