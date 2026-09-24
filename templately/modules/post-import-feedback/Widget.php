<?php
/**
 * Post-import feedback widget (spec 027-post-import-feedback).
 *
 * Extracted verbatim from includes/Core/Admin.php — behavior byte-identical (same
 * eligibility check, same enqueue handle/localize global-var name `templately` so
 * the widget's `window.templately` read is unaffected, same mount-point markup).
 * Two pieces were surgically lifted out of a larger class:
 *   - the `'index.php' === $hook` early-return branch inside Admin::scripts()
 *     (hooked here to admin_enqueue_scripts independently)
 *   - Admin::my_custom_footer_html() (hooked here to admin_footer independently)
 * Neither block referenced `$this->` state from Admin.php — confirmed via grep
 * before extraction, so this is a clean lift, not a rewrite. The enqueued filenames
 * changed (js/dashboard.js -> js/post-import-feedback.js, css/dashboard-style.css ->
 * css/post-import-feedback-style.css) to match the renamed webpack entry (spec 027's
 * own Build Artifacts note); the WordPress script/style HANDLE and the localized JS
 * global variable name both stay 'templately' — react-src's widget entry reads
 * `window.templately` directly, so renaming either would break data delivery.
 *
 * FR-005 rolling 30-day cooldown (2026-07-16): eligibility used to be a one-shot
 * `$is_complete !== 'done'` check (no expiry once dismissed). It is now
 * time-boxed via `is_eligible()` — see that method's docblock for the migration
 * handling of the old no-expiry `'done'` sentinel.
 *
 * @package Templately
 */

namespace Templately\Modules\PostImportFeedback;

use Templately\Utils\Options;

class Widget {

	/**
	 * User-meta key holding the Unix timestamp of the last time this user
	 * submitted or dismissed the feedback widget. Drives the FR-005 30-day
	 * rolling cooldown, replacing the old no-expiry `'done'` sentinel
	 * previously stored (and still legacy-read) in `templately_fsi_complete`.
	 */
	const LAST_SHOWN_META = 'templately_feedback_last_shown';

	/** FR-005 cooldown window: 30 days. */
	const COOLDOWN_SECONDS = 30 * DAY_IN_SECONDS;

	public function enqueue( $hook = '' ) {
		if ( 'index.php' !== $hook ) {
			return;
		}

		if ( ! self::is_eligible() || wp_is_mobile() ) {
			return;
		}

		$user  = Options::get_instance()->get( 'user' );
		$email = isset( $user['email'] ) ? $user['email'] : '';

		templately()->assets->enqueue( 'templately', 'css/post-import-feedback-style.css', [] );
		templately()->assets->enqueue( 'templately', 'js/post-import-feedback.js', [], true );
		templately()->assets->localize( 'templately', 'templately', [
			'email' => $email,
			'nonce' => wp_create_nonce( 'templately_nonce' ),
		] );
	}

	public function render_mount_point() {
		global $current_screen;

		if ( ! $current_screen ) {
			return false;
		}

		if ( $current_screen->id !== 'dashboard' ) {
			return false;
		}

		if ( self::is_eligible() && ! wp_is_mobile() ) :
			?>
				<div id="templately-fsi-feedback"></div>
			<?php
		endif;
	}

	/**
	 * Whether the feedback widget should be shown to the given (or current)
	 * user, per FR-005: suppressed while the user has submitted or skipped
	 * feedback within the past 30 days; eligible again once that window
	 * elapses. Requires `templately_fsi_complete` (an FSI has completed) to
	 * be truthy at all — an entirely fresh install/user is never eligible.
	 *
	 * Migration handling for the old no-expiry `'done'` sentinel: on
	 * installs from before this cooldown existed, `templately_fsi_complete`
	 * may already be `'done'` with no `LAST_SHOWN_META` recorded. The same
	 * shape also occurs going forward for a legitimate reason outside this
	 * module's scope — `FullSiteImport\Concerns\RunsImport` writes the
	 * `'done'` sentinel directly (not via this module) whenever the backend
	 * reports feedback was already given for the pack. Both cases are
	 * indistinguishable from here, so both are handled the same,
	 * conservatively: do NOT immediately re-prompt (a bare "value isn't a
	 * timestamp" read should never suddenly show the widget to someone who
	 * already dismissed it) — treat it as "just shown", backfilling
	 * `LAST_SHOWN_META` to now so the 30-day clock starts running instead of
	 * suppressing forever.
	 */
	public static function is_eligible( $user_id = null ): bool {
		$user_id = $user_id ?: get_current_user_id();

		$is_complete = get_user_meta( $user_id, 'templately_fsi_complete', true );
		if ( empty( $is_complete ) ) {
			return false;
		}

		$last_shown = get_user_meta( $user_id, self::LAST_SHOWN_META, true );

		if ( 'done' === $is_complete && empty( $last_shown ) ) {
			update_user_meta( $user_id, self::LAST_SHOWN_META, time() );
			return false;
		}

		if ( empty( $last_shown ) ) {
			return true;
		}

		return ( time() - (int) $last_shown ) >= self::COOLDOWN_SECONDS;
	}
}
