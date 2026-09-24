<?php
/**
 * The one-time activation gate.
 *
 * @package Templately
 */

namespace Templately\Modules\Utilities\Cleanup;

/**
 * Cleanup deletes NOTHING until an administrator confirms once per site.
 *
 * Why the gate exists: every site that has ever abandoned an import is carrying
 * leftovers today. Without this, the first scheduled run after upgrading would
 * reclaim a backlog — routinely several gigabytes — with no warning and no way
 * to look first. Until it is confirmed, every run is a dry run that reports.
 *
 * Accepted consequence, decided deliberately: a site whose administrator never
 * responds reclaims nothing, indefinitely. That is why the prompt lives OUTSIDE
 * the settings page and stays reachable after dismissal. There is no timed
 * self-activation — an unactivated site never deletes.
 *
 *   unactivated ──confirm──▶ activated ──cleanup disabled──▶ unactivated
 *        │                                                        ▲
 *        └──prompt dismissed──▶ unactivated (notice hidden)───────┘
 */
final class Activation {

	const OPTION = 'templately_cleanup_activation';

	public static function state(): array {
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}

		return [
			'activated'           => ! empty( $stored['activated'] ),
			'activated_at'        => isset( $stored['activated_at'] ) ? (int) $stored['activated_at'] : null,
			'activated_by'        => isset( $stored['activated_by'] ) ? (int) $stored['activated_by'] : null,
			'prompt_dismissed'    => ! empty( $stored['prompt_dismissed'] ),
			'last_estimate_bytes' => RunJournal::last_estimate_bytes(),
		];
	}

	public static function is_activated(): bool {
		$state = self::state();

		return $state['activated'];
	}

	/**
	 * The single confirmation. After this, cleanup runs automatically forever —
	 * including for data that accumulates long afterwards. No per-batch approval.
	 */
	public static function activate( int $user_id = 0 ): array {
		$state = self::state();

		$state['activated']    = true;
		$state['activated_at'] = time();
		$state['activated_by'] = $user_id ?: get_current_user_id();

		return self::save( $state );
	}

	/**
	 * Return the site to the unactivated state.
	 *
	 * Called when cleanup is switched off: re-enabling it later must ask again,
	 * because the backlog that accumulated while it was off is exactly the
	 * situation the gate exists for.
	 */
	public static function deactivate(): array {
		$state = self::state();

		$state['activated']    = false;
		$state['activated_at'] = null;
		$state['activated_by'] = null;
		// The prompt becomes relevant again, so un-dismiss it.
		$state['prompt_dismissed'] = false;

		return self::save( $state );
	}

	/**
	 * Hide the notice WITHOUT activating.
	 *
	 * Dismissal is not consent. The tab keeps showing the pending state, and the
	 * prompt must remain reachable — otherwise dismissing it once would silently
	 * strand the site in a state where nothing is ever reclaimed and nothing
	 * ever says so.
	 */
	public static function dismiss_prompt(): array {
		$state                     = self::state();
		$state['prompt_dismissed'] = true;

		return self::save( $state );
	}

	/**
	 * Whether the activation prompt should be shown right now.
	 */
	public static function should_prompt(): bool {
		if ( Settings::is_disabled() ) {
			return false;
		}

		$state = self::state();

		if ( $state['activated'] || $state['prompt_dismissed'] ) {
			return false;
		}

		// Nothing to reclaim yet — do not nag a site with a clean uploads dir.
		return $state['last_estimate_bytes'] > 0;
	}

	/**
	 * Whether a run must be forced into dry-run regardless of what the caller
	 * asked for.
	 */
	public static function forces_dry_run(): bool {
		return ! self::is_activated();
	}

	private static function save( array $state ): array {
		// `last_estimate_bytes` is derived from the journal, never stored — a
		// second copy would go stale the moment a run happened.
		unset( $state['last_estimate_bytes'] );

		update_option( self::OPTION, $state, false );

		return self::state();
	}

	public static function reset(): void {
		delete_option( self::OPTION );
	}
}
