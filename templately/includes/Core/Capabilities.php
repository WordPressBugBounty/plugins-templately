<?php
/**
 * Capabilities — the host-capability registry (spec 053).
 *
 * Answers "can this host do X?" once per request, consistently, for PHP and
 * (via the localized capability map) for every Templately JS bundle. Detector
 * first: a declared probe beats the version comparison, so backports and
 * features arriving early through the Gutenberg plugin light up without a
 * plugin release. The per-key filter `templately_capability_{$key}` has the
 * final word — site-wide kill switch and test hook in one.
 *
 * PHP 7.2 SYNTAX ONLY in this file (FR-038): the published readme floor is
 * the authoritative compatibility contract — closures, not arrow functions;
 * no typed properties.
 *
 * @package Templately
 */

namespace Templately\Core;

use Templately\Utils\Base;

class Capabilities extends Base {
	/**
	 * Registered declarations, keyed by capability key.
	 *
	 * @var array<string, array>
	 */
	private $declarations = [];

	/**
	 * Memoized answers for this request. Only cacheable answers land here —
	 * premature (stage not reached) and unregistered answers never do, so a
	 * later query can still resolve truthfully (FR-007 / FR-020).
	 *
	 * @var array<string, array> key => [ 'value' => bool, 'decided_by' => string ]
	 */
	private $answers = [];

	/**
	 * Register a capability. Metadata only — the probe is NOT executed here
	 * (FR-006); resolution is lazy, at first query.
	 *
	 * Duplicate keys: the first registration wins, deterministically, with a
	 * development-mode notice (FR-009).
	 *
	 * @param string $key Stable kebab-case capability key.
	 * @param array  $def {
	 *     @type callable|null $probe   Optional presence probe; truthy = available.
	 *     @type string|null   $wp      Optional minimum WordPress version.
	 *     @type string|null   $stage   Optional hook name the probe must wait for.
	 *     @type string        $posture 'degrade'|'polyfill'|'hard' (descriptive).
	 *     @type string        $note    Human-readable note for the dev console.
	 * }
	 * @return void
	 */
	public function register( $key, array $def ) {
		if ( ! $this->is_usable_key( $key ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html( sprintf( 'Templately capability key must be a string; %s given. Declaration ignored.', gettype( $key ) ) ),
				'3.8.0'
			);
			return;
		}

		if ( isset( $this->declarations[ $key ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html( sprintf( 'Capability "%s" is already registered; keeping the first declaration.', $key ) ),
				'3.8.0'
			);
			return;
		}

		$this->declarations[ $key ] = $def + [
			'probe'   => null,
			'wp'      => null,
			'stage'   => null,
			'posture' => 'degrade',
			'note'    => '',
		];
	}

	/**
	 * Whether a capability is available on this host.
	 *
	 * Unknown key: false, with a development-mode notice, never fatal (FR-008).
	 *
	 * @param string $key Capability key.
	 * @return bool
	 */
	public function has( $key ) {
		$answer = $this->resolve( $key );
		return $answer['value'];
	}

	/**
	 * The complete resolved map — the single source every JS surface receives
	 * (FR-010, FR-025).
	 *
	 * @return array<string, bool>
	 */
	public function get_map() {
		$map = [];
		foreach ( array_keys( $this->declarations ) as $key ) {
			$map[ $key ] = $this->has( $key );
		}
		return $map;
	}

	/**
	 * Explain one capability: value, which input decided it, posture, note (FR-011).
	 *
	 * @param string $key Capability key.
	 * @return array{key:string,value:bool,decided_by:string,posture:string,note:string}
	 */
	public function explain( $key ) {
		$answer      = $this->resolve( $key );
		$declaration = $this->is_registered( $key ) ? $this->declarations[ $key ] : [ 'posture' => '', 'note' => '' ];

		return [
			'key'        => $key,
			'value'      => $answer['value'],
			'decided_by' => $answer['decided_by'],
			'posture'    => $declaration['posture'],
			'note'       => $declaration['note'],
		];
	}

	/**
	 * Explain every registered capability — the dev-console feed (FR-022).
	 *
	 * @return array[]
	 */
	public function explain_all() {
		$rows = [];
		foreach ( array_keys( $this->declarations ) as $key ) {
			$rows[] = $this->explain( $key );
		}
		return $rows;
	}

	/**
	 * Whether a key has been registered (used by the gate layer to distinguish
	 * "unmet because capability missing on host" from "unmet because nobody
	 * registered the key" — FR-020).
	 *
	 * @param string $key Capability key.
	 * @return bool
	 */
	public function is_registered( $key ) {
		if ( ! $this->is_usable_key( $key ) ) {
			return false;
		}
		return isset( $this->declarations[ $key ] );
	}

	/**
	 * Whether a key can be used as an array offset at all.
	 *
	 * The registry is asked about keys that come from module declarations, and
	 * `get_capability_gates(): array` constrains the container, not its values —
	 * so an array or object can reach any of the offset lookups here and fatal
	 * ("Cannot access offset of type array in isset or empty" on PHP 8). Every
	 * offset read guards through this; only {@see resolve()} raises the notice,
	 * so one bad query produces one warning.
	 *
	 * @param mixed $key Candidate capability key.
	 * @return bool
	 */
	private function is_usable_key( $key ) {
		return is_string( $key ) || is_int( $key );
	}

	/**
	 * Resolve a capability: probe → version → override-final (FR-003/FR-004),
	 * memoized per request when cacheable (FR-005/FR-007).
	 *
	 * @param string $key Capability key.
	 * @return array{value:bool,decided_by:string}
	 */
	private function resolve( $key ) {
		// A non-scalar key would fatal on the array offsets below ("Cannot access
		// offset of type array in isset or empty", PHP 8). The registry's contract
		// is never-fatal (FR-008), and a malformed key reaches here whenever a
		// module's get_capability_gates() returns a non-string VALUE — the array
		// return type constrains the container, not its elements. Answer
		// unavailable, uncached, with a development-mode notice.
		if ( ! $this->is_usable_key( $key ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html( sprintf( 'Templately capability key must be a string; %s given. Answering unavailable.', gettype( $key ) ) ),
				'3.8.0'
			);
			return [
				'value'      => false,
				'decided_by' => 'invalid-key',
			];
		}

		if ( isset( $this->answers[ $key ] ) ) {
			return $this->answers[ $key ];
		}

		if ( ! isset( $this->declarations[ $key ] ) ) {
			_doing_it_wrong(
				__METHOD__,
				esc_html( sprintf( 'Unknown Templately capability "%s" queried; answering unavailable.', $key ) ),
				'3.8.0'
			);
			// Deliberately NOT cached: the key may be registered later (FR-020).
			return $this->finalize( $key, false, 'unregistered', false );
		}

		$declaration = $this->declarations[ $key ];

		// A probe that must wait for a lifecycle stage returns a NON-cached
		// unavailable before that stage — the truthful answer is still
		// produced by a later query (FR-007).
		//
		// Unconditionally unavailable, even when a `wp` minimum is also
		// declared: a capability carries a probe PRECISELY BECAUSE its version
		// minimum is not sufficient evidence, so falling back to the version
		// while the probe cannot run reintroduces the imprecision the probe
		// exists to remove. Observed with the one staged seed key — on WP 7.1,
		// `wp-knowledge-cpt` would answer available before `init` while
		// `post_type_exists('wp_knowledge')` is false, so a gate consulted at
		// `plugins_loaded` (module boot — exactly where gates are consulted)
		// would enable a path against a post type that does not exist.
		if ( null !== $declaration['probe'] ) {
			if ( null !== $declaration['stage'] && ! did_action( $declaration['stage'] ) ) {
				return $this->finalize( $key, false, 'premature', false );
			}

			if ( ! is_callable( $declaration['probe'] ) ) {
				return $this->finalize( $key, false, 'probe-error', true );
			}

			try {
				$result = call_user_func( $declaration['probe'] );
			} catch ( \Throwable $e ) {
				// A broken probe is an unavailable capability, never a fatal (FR-012).
				return $this->finalize( $key, false, 'probe-error', true );
			}

			return $this->finalize( $key, (bool) $result, 'probe', true );
		}

		if ( null !== $declaration['wp'] ) {
			return $this->finalize( $key, $this->version_satisfied( $declaration['wp'] ), 'version', true );
		}

		return $this->finalize( $key, false, 'undeclared', true );
	}

	/**
	 * Apply the per-key override filter (final word — FR-004), coerce, memoize.
	 *
	 * @param string $key        Capability key.
	 * @param bool   $value      Computed answer before the override.
	 * @param string $decided_by What produced the computed answer.
	 * @param bool   $cacheable  Whether the answer may be memoized.
	 * @return array{value:bool,decided_by:string}
	 */
	private function finalize( $key, $value, $decided_by, $cacheable ) {
		/**
		 * The final word on one capability — kill switch and test hook.
		 *
		 * @param bool  $value       Computed answer.
		 * @param array $declaration The registered declaration ([] when unregistered).
		 */
		$filtered = apply_filters(
			"templately_capability_{$key}",
			$value,
			isset( $this->declarations[ $key ] ) ? $this->declarations[ $key ] : []
		);

		// Anything ambiguous coerces toward unavailable (spec edge case).
		$final = is_bool( $filtered ) ? $filtered : (bool) $filtered;

		// Compare the COERCED answer, not the raw filter return: a filter that
		// hands back `1` for a value that was already `true` changes nothing and
		// must not be labelled an override (FR-011).
		if ( $final !== $value ) {
			$decided_by = 'override';
		}

		$answer = [
			'value'      => $final,
			'decided_by' => $decided_by,
		];

		if ( $cacheable ) {
			$this->answers[ $key ] = $answer;
		}

		return $answer;
	}

	/**
	 * Compare a minimum WordPress version against the running host. Unreadable
	 * host version resolves unavailable, never an error (FR-013). Mirrors
	 * Modules_Manager::check_requirements().
	 *
	 * PRE-RELEASE HOSTS SATISFY THE RELEASE THEY ARE A PRE-RELEASE OF (FR-048).
	 *
	 * `version_compare()` orders `7.1-beta4` and `7.1-RC1` BELOW plain `7.1`,
	 * which is correct for "is this newer than that" and wrong for the only
	 * question this registry asks: "does this host carry the API that landed in
	 * 7.1?" A beta or RC of 7.1 carries 7.1's APIs — that is what a release
	 * candidate IS — so a raw comparison makes every version-only capability
	 * resolve unavailable for the entire pre-release cycle, and the code behind
	 * it becomes untestable until the day of the final tag. The published
	 * requirement is the opposite: gated code must be exercisable on an RC.
	 *
	 * So the HOST version is normalized to its release core before comparing —
	 * everything from the first `-` onward is dropped:
	 *
	 *   7.1-beta4            => 7.1   satisfies 7.1, still fails 7.2
	 *   7.1-RC1              => 7.1   satisfies 7.1, still fails 7.2
	 *   7.2-alpha-12345-src  => 7.2   satisfies 7.2 (WordPress trunk: trunk is
	 *                                 where 7.2's APIs land first, and the
	 *                                 alpha/-src suffix is a build marker, not
	 *                                 a statement about which APIs are present)
	 *   7.0.5                => 7.0.5 satisfies 7.0 (unchanged; no suffix)
	 *   6.9                  => 6.9   still fails 7.1 (unchanged)
	 *
	 * The DECLARED MINIMUM is deliberately NOT normalized. A minimum is authored
	 * by us and is always a plain release number; normalizing it too would let a
	 * hypothetical `wp => '7.1-RC1'` silently widen to all of 7.1, and would make
	 * the two sides of the comparison lie in different ways. Only the host's own
	 * self-report — which we do not control — is coerced. (That asymmetry is a
	 * rule about authorship rather than an observable behaviour: normalizing a
	 * suffixed minimum M could only change the answer for a host whose release
	 * core falls in [M, M-without-suffix), and a normalized host core carries no
	 * suffix, so that interval is always empty. Keep the asymmetry anyway — it is
	 * what makes the code say what it means.)
	 *
	 * Consequence to accept knowingly: an EARLY 7.1 alpha that predates the API
	 * answers available. Version-only keys are a stopgap until the release's
	 * Field Guide confirms a probe symbol (see Capability_Seed); a probe, once
	 * declared, decides and this comparison stops mattering for that key. Being
	 * optimistic for a few alpha weeks is the price of being testable on the RC,
	 * and the `templately_capability_{$key}` filter forces either answer.
	 *
	 * @param string $minimum Minimum WordPress version.
	 * @return bool
	 */
	private function version_satisfied( $minimum ) {
		$wp_version = get_bloginfo( 'version' );
		if ( '' === $wp_version && isset( $GLOBALS['wp_version'] ) ) {
			$wp_version = $GLOBALS['wp_version'];
		}

		if ( ! is_string( $wp_version ) || '' === $wp_version ) {
			return false;
		}

		return version_compare( $this->release_core( $wp_version ), (string) $minimum, '>=' );
	}

	/**
	 * Strip a pre-release / build suffix from a host version string.
	 *
	 * WordPress reports `X.Y`, `X.Y.Z`, `X.Y-beta1`, `X.Y-RC1`, `X.Y-alpha-NNNNN-src`
	 * and `X.Y-src`. Every suffix shape starts at the first hyphen, so the release
	 * core is simply everything before it. A version with no hyphen is returned
	 * unchanged; a string that is nothing BUT a suffix (`-beta1`) would strip to
	 * empty, so it is returned untouched and left to fail the comparison.
	 *
	 * PHP 7.2 syntax only (FR-038).
	 *
	 * @param string $version Raw host version.
	 * @return string Release core.
	 */
	private function release_core( $version ) {
		$version = (string) $version;
		$dash    = strpos( $version, '-' );

		if ( false === $dash || 0 === $dash ) {
			return $version;
		}

		return substr( $version, 0, $dash );
	}

	/**
	 * Test-only: drop all declarations and memoized answers. The unit suite
	 * re-registers per test; production code never calls this.
	 *
	 * @internal
	 * @return void
	 */
	public function reset_for_tests() {
		$this->declarations = [];
		$this->answers      = [];
	}
}
