<?php
/**
 * A single agent capability declaration (spec 044-native-mcp-server, FR-009).
 *
 * This is the ONE place a capability is described. Three consumers read it:
 * the native MCP server (always), the Abilities API bridge (only when that API
 * exists), and the mcp-adapter bridge (only when the adapter exists). Nothing
 * may keep its own parallel list — see ToolRegistry.
 *
 * ## access_level vs annotations
 *
 * These are two DIFFERENT fields serving two DIFFERENT purposes, and conflating
 * them is a privilege bug:
 *
 *  - `access_level`  — the SECURITY control. Decides whether a read-only
 *                      credential may invoke this capability.
 *  - `annotations`   — advisory hints shown to agent clients (readonly /
 *                      destructive / idempotent). NEVER consulted for access.
 *
 * They may legitimately disagree, and where they do the access level wins.
 * `templately/auth-login-with-google` is the live example: it is annotated
 * `readonly => true` (it mutates no content) yet it *initiates an account-linking
 * flow*, so its access level is `full`. Gating on the annotation would let a
 * read-only credential start that flow.
 *
 * @package Templately\Modules\McpCore\Registry
 */

namespace Templately\Modules\McpCore\Registry;

class ToolDescriptor {

	/** Read-only capabilities: invokable by any credential. */
	const ACCESS_READ = 'read';

	/** Mutating/consequential capabilities: require a full-access credential. */
	const ACCESS_FULL = 'full';

	/** @var string Stable capability id, e.g. `templately/discover-templates`. */
	public $id;

	/** @var string */
	public $label;

	/** @var string */
	public $description;

	/** @var array JSON Schema. */
	public $input_schema;

	/** @var array JSON Schema. */
	public $output_schema;

	/** @var callable */
	public $execute_callback;

	/** @var callable */
	public $permission_callback;

	/** @var string self::ACCESS_READ|self::ACCESS_FULL */
	public $access_level;

	/** @var array Advisory client hints. Not a security control. */
	public $annotations;

	private function __construct( array $args ) {
		$this->id                  = $args['id'];
		$this->label               = $args['label'] ?? '';
		$this->description         = $args['description'] ?? '';
		$this->input_schema        = $args['input_schema'] ?? [ 'type' => 'object' ];
		$this->output_schema       = $args['output_schema'] ?? [ 'type' => 'object' ];
		$this->execute_callback    = $args['execute_callback'];
		$this->permission_callback = $args['permission_callback'];
		$this->annotations         = $args['annotations'] ?? [];

		// FR-026c — a capability that forgets to declare its access level is
		// treated as requiring FULL access. Fail closed, never open: the cost of
		// a wrong `full` is an over-restricted read-only token (visible, easily
		// fixed); the cost of a wrong `read` is a privilege escalation (silent).
		$level = $args['access_level'] ?? null;

		$this->access_level = ( self::ACCESS_READ === $level ) ? self::ACCESS_READ : self::ACCESS_FULL;
	}

	/**
	 * @param array $args
	 * @return self
	 * @throws \InvalidArgumentException When a required field is missing.
	 */
	public static function from_array( array $args ): self {
		foreach ( [ 'id', 'execute_callback', 'permission_callback' ] as $required ) {
			if ( empty( $args[ $required ] ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'ToolDescriptor is missing required field "%s".', $required )
				);
			}
		}

		return new self( $args );
	}

	/**
	 * True when this capability is invokable by a read-only credential.
	 *
	 * @return bool
	 */
	public function is_read_only(): bool {
		return self::ACCESS_READ === $this->access_level;
	}

	/**
	 * Whether the advisory `readonly` annotation disagrees with the declared
	 * access level. Purely diagnostic — callers MUST NOT reconcile the two
	 * (FR-026b); it exists so tests and docs can assert the divergence is
	 * intentional rather than drift.
	 *
	 * @return bool
	 */
	public function annotation_disagrees_with_access_level(): bool {
		if ( ! array_key_exists( 'readonly', $this->annotations ) ) {
			return false;
		}

		return (bool) $this->annotations['readonly'] !== $this->is_read_only();
	}

	/**
	 * Projection for a capability listing — the agent-facing shape.
	 * Deliberately omits callbacks and the access level.
	 *
	 * @return array
	 */
	public function to_tool_listing(): array {
		return [
			'name'        => $this->id,
			'description' => $this->description,
			'inputSchema' => $this->input_schema,
			'annotations' => $this->annotations,
		];
	}

	/**
	 * Projection for `wp_register_ability()`.
	 *
	 * @return array
	 */
	public function to_ability_args(): array {
		return [
			'label'               => $this->label,
			'description'         => $this->description,
			'category'            => 'templately',
			'input_schema'        => $this->input_schema,
			'output_schema'       => $this->output_schema,
			'execute_callback'    => $this->execute_callback,
			'permission_callback' => $this->permission_callback,
			'meta'                => [
				'show_in_rest' => true,
				'annotations'  => $this->annotations,
			],
		];
	}
}
