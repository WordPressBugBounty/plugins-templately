<?php
/**
 * The sole authority on which agent capabilities exist (spec 046, FR-010).
 *
 * Every consumer derives its capability set from here:
 *
 *   Ability classes ──descriptor()──► ToolRegistry ──► mcp-server        (always)
 *                                          ├────────► AbilitiesBridge   (iff Abilities API)
 *                                          └────────► McpAdapterBridge  (iff mcp-adapter)
 *
 * Before 046 the capability list was maintained by hand in TWO places in
 * mcp-abilities' MCP.php (`ABILITY_IDS` and `register_abilities()`), which had to
 * be edited in step. Adding the native server would have made three, and adding
 * the FSI capability module a fourth. FR-011 requires exactly one declaration;
 * test-RegistryAbilitiesParity.php fails CI if the sets ever diverge.
 *
 * Capability modules contribute by calling `register_classes()` from their own
 * `init_hooks()` — mcp-core never names them, so a new capability module is
 * additive and touches nothing here.
 *
 * @package Templately\Modules\McpCore\Registry
 */

namespace Templately\Modules\McpCore\Registry;

use Templately\Modules\McpCore\Activity\ActivityLog;
use WP_Error;

class ToolRegistry {

	/** @var self|null */
	private static $instance = null;

	/** @var ToolDescriptor[] Keyed by capability id, insertion-ordered. */
	private $tools = [];

	/**
	 * Ability class names awaiting descriptor resolution.
	 *
	 * Descriptors are built LAZILY, on first access — never at registration
	 * time. Every descriptor's label/description is wrapped in `__()`, and the
	 * bootstrap that supplies this list runs on `plugins_loaded`, which is
	 * BEFORE `init` and therefore before the plugin's textdomain is loaded
	 * (`Plugin::set_locale()` defers `load_plugin_textdomain` to `init`).
	 *
	 * Calling `__()` that early makes WordPress 6.7+ emit a
	 * `_load_textdomain_just_in_time was called incorrectly` notice. With
	 * WP_DEBUG_DISPLAY on, that notice is ECHOED during bootstrap — so headers
	 * are already sent by the time any REST response tries to set a status, and
	 * every error status silently collapses to 200 ("Cannot modify header
	 * information - headers already sent"). It breaks far more than this module.
	 *
	 * Deferring means descriptors are resolved on first use — REST dispatch,
	 * the abilities hook, the adapter hook — all of which run after `init`.
	 *
	 * @var string[]
	 */
	private $pending_classes = [];

	/** @var bool */
	private $resolved = true;

	/**
	 * Whether contributors have been asked to declare themselves this lifetime.
	 *
	 * @see self::COLLECT_ACTION
	 * @var bool
	 */
	private $collected = false;

	/**
	 * Fired once, on first registry use, so every capability module can declare
	 * its classes.
	 *
	 * Contribution is INVERTED on purpose: mcp-core names no contributor, so a
	 * module gains an agent surface by hooking this and nothing here changes.
	 * It also means the registry can always rebuild itself from scratch — which
	 * is what makes {@see self::reset()} a complete reset rather than one that
	 * silently drops whichever contributors happened to register imperatively.
	 * (That is not hypothetical: while the capability set lived in a single
	 * module, `reset()` followed by re-registering that one module's list looked
	 * like a full restore. The moment a second module started contributing, every
	 * test after a reset saw a partial registry.)
	 */
	const COLLECT_ACTION = 'templately_mcp_register_capabilities';

	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Reset — test seam only. The next use re-fires {@see self::COLLECT_ACTION},
	 * so the rebuilt registry is identical to the booted one.
	 */
	public static function reset(): void {
		self::$instance = null;
	}

	/**
	 * @param ToolDescriptor $descriptor
	 * @throws \InvalidArgumentException On duplicate id (a programming error).
	 */
	public function register( ToolDescriptor $descriptor ): void {
		if ( isset( $this->tools[ $descriptor->id ] ) ) {
			throw new \InvalidArgumentException(
				sprintf( 'Capability "%s" is already registered.', $descriptor->id )
			);
		}

		$this->tools[ $descriptor->id ] = $descriptor;
	}

	/**
	 * Queue ability classes for lazy descriptor resolution. Does NOT call
	 * `descriptor()` — see $pending_classes for why that must not happen at
	 * registration time.
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
	 * Resolve queued ability classes into descriptors. Idempotent.
	 */
	private function resolve(): void {
		// Ask contributors to declare themselves, once per registry lifetime, and
		// BEFORE the pending list is snapshotted below — their callbacks call
		// register_classes(), which appends to it. `$collected` is set first so a
		// callback that touches the registry cannot recurse.
		if ( ! $this->collected ) {
			$this->collected = true;

			/**
			 * Declare a module's agent capabilities.
			 *
			 * @param ToolRegistry $registry Call `register_classes()` on it.
			 */
			do_action( self::COLLECT_ACTION, $this );
		}

		if ( $this->resolved ) {
			return;
		}

		// Set BEFORE the loop: a descriptor that somehow re-entered the registry
		// would otherwise recurse forever.
		$this->resolved = true;

		$pending               = $this->pending_classes;
		$this->pending_classes = [];

		foreach ( $pending as $class ) {
			if ( ! method_exists( $class, 'descriptor' ) ) {
				continue;
			}

			$descriptor = ToolDescriptor::from_array( $class::descriptor() );

			if ( ! isset( $this->tools[ $descriptor->id ] ) ) {
				$this->tools[ $descriptor->id ] = $descriptor;
			}
		}
	}

	/**
	 * @return ToolDescriptor[]
	 */
	public function all(): array {
		$this->resolve();

		return $this->tools;
	}

	/**
	 * @return string[]
	 */
	public function ids(): array {
		$this->resolve();

		return array_keys( $this->tools );
	}

	/**
	 * @param string $id
	 * @return ToolDescriptor|null
	 */
	public function get( string $id ) {
		$this->resolve();

		return $this->tools[ $id ] ?? null;
	}

	/**
	 * Invoke a capability under a credential's access level.
	 *
	 * GATE ORDER IS LOAD-BEARING (data-model.md §2):
	 *
	 *   1. exists            → unknown capability
	 *   2. access level      → forbidden, naming the access level
	 *   3. permission        → forbidden
	 *   4. input schema      → invalid input
	 *   5. invoke
	 *   6. record activity   (full-access capabilities only)
	 *
	 * Access level is checked BEFORE the permission callback so a read-only
	 * credential is told it is read-only, rather than being told it lacks a
	 * capability it actually has. Reversing 2 and 3 turns a clear "your token is
	 * read-only" into a misleading permissions error.
	 *
	 * @param string      $id
	 * @param array       $input
	 * @param string      $access_level  Credential's level (ToolDescriptor::ACCESS_*).
	 * @param string|null $credential_id For the activity record; null when unknown.
	 * @return array|WP_Error
	 */
	public function execute( string $id, array $input, string $access_level, ?string $credential_id = null ) {
		$descriptor = $this->get( $id );

		if ( null === $descriptor ) {
			return new WP_Error(
				'unknown_capability',
				sprintf(
					/* translators: %s: capability name. */
					__( 'Unknown capability: %s', 'templately' ),
					$id
				),
				[ 'status' => 404 ]
			);
		}

		// 2 — access level (FR-026).
		if ( ToolDescriptor::ACCESS_READ === $access_level && ! $descriptor->is_read_only() ) {
			return new WP_Error(
				'forbidden_read_only',
				sprintf(
					/* translators: %s: capability name. */
					__( 'This connection is read-only and cannot use "%s". Reconnect with full access to use it.', 'templately' ),
					$id
				),
				[ 'status' => 403 ]
			);
		}

		// 3 — the capability's own permission rule, unchanged from 041/042 (FR-019).
		if ( ! call_user_func( $descriptor->permission_callback, $input ) ) {
			return new WP_Error(
				'forbidden',
				__( 'You do not have permission to use this capability.', 'templately' ),
				[ 'status' => 403 ]
			);
		}

		// 4 — input shape.
		$invalid = $this->validate_input( $descriptor, $input );

		if ( $invalid instanceof WP_Error ) {
			return $invalid;
		}

		// 5 — invoke.
		$result = call_user_func( $descriptor->execute_callback, $input );

		// 6 — record (FR-039d). Read-only invocations are not recorded, which
		// keeps the log meaningful: it answers "what did the agent CHANGE".
		if ( ! $descriptor->is_read_only() ) {
			ActivityLog::record(
				$credential_id,
				get_current_user_id(),
				$id,
				! ( $result instanceof WP_Error ),
				$result instanceof WP_Error ? $result->get_error_message() : null
			);
		}

		return $result;
	}

	/**
	 * Minimal JSON-Schema validation — required keys, declared types, enums, and
	 * `additionalProperties: false`. Deliberately not a full validator: the
	 * schemas here are hand-written and shallow, and every capability validates
	 * its own inputs again downstream.
	 *
	 * @param ToolDescriptor $descriptor
	 * @param array          $input
	 * @return true|WP_Error
	 */
	private function validate_input( ToolDescriptor $descriptor, array $input ) {
		$schema     = $descriptor->input_schema;
		$properties = (array) ( $schema['properties'] ?? [] );
		$required   = (array) ( $schema['required'] ?? [] );

		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $input ) || '' === $input[ $key ] ) {
				return new WP_Error(
					'invalid_input',
					sprintf(
						/* translators: %s: input field name. */
						__( 'Missing required input: %s', 'templately' ),
						$key
					),
					[ 'status' => 400 ]
				);
			}
		}

		if ( isset( $schema['additionalProperties'] ) && false === $schema['additionalProperties'] ) {
			$unknown = array_diff( array_keys( $input ), array_keys( $properties ) );

			if ( ! empty( $unknown ) ) {
				return new WP_Error(
					'invalid_input',
					sprintf(
						/* translators: %s: comma-separated list of field names. */
						__( 'Unknown input(s): %s', 'templately' ),
						implode( ', ', $unknown )
					),
					[ 'status' => 400 ]
				);
			}
		}

		foreach ( $input as $key => $value ) {
			$spec = $properties[ $key ] ?? null;

			if ( ! is_array( $spec ) ) {
				continue;
			}

			if ( isset( $spec['enum'] ) && is_array( $spec['enum'] ) && ! in_array( $value, $spec['enum'], true ) ) {
				return new WP_Error(
					'invalid_input',
					sprintf(
						/* translators: 1: input field name, 2: comma-separated allowed values. */
						__( 'Invalid value for "%1$s". Allowed: %2$s', 'templately' ),
						$key,
						implode( ', ', $spec['enum'] )
					),
					[ 'status' => 400 ]
				);
			}

			if ( isset( $spec['type'] ) && ! $this->matches_type( $value, $spec['type'] ) ) {
				return new WP_Error(
					'invalid_input',
					sprintf(
						/* translators: 1: input field name, 2: expected type. */
						__( 'Invalid type for "%1$s". Expected %2$s.', 'templately' ),
						$key,
						$spec['type']
					),
					[ 'status' => 400 ]
				);
			}
		}

		return true;
	}

	/**
	 * @param mixed  $value
	 * @param string $type
	 * @return bool
	 */
	private function matches_type( $value, string $type ): bool {
		switch ( $type ) {
			case 'integer':
				return is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) );
			case 'number':
				return is_numeric( $value );
			case 'string':
				return is_string( $value );
			case 'boolean':
				return is_bool( $value ) || in_array( $value, [ 0, 1, '0', '1' ], true );
			case 'array':
				return is_array( $value );
			case 'object':
				return is_array( $value ) || is_object( $value );
			default:
				return true;
		}
	}
}
