<?php

namespace Templately\Utils;

/**
 * The caching plugin Templately offers on the import dependency screen.
 *
 * Templately owns the OFFER — whether to put a row on the screen, and how often.
 * It does not own what the plugin comes up as. Claiming the install is one option
 * write immediately before activation:
 *
 *     update_option( 'xspeed_installed_by', 'templately' );
 *
 * xSpeed reads that on its own activation and picks its own profile — every Free
 * feature off, page caching on when nothing else owns it and refused when something
 * does — and skips its own setup wizard so it does not interrupt ours. No settings
 * writes from here, no enable call, no rollback path.
 *
 * A plain option rather than a constant or a filter because it is written at a moment
 * when none of xSpeed's code has loaded and none can be relied on to exist.
 *
 * See docs/guides/installing-from-another-plugin.md in the xSpeed repo. This class
 * replaced a copy-vendored detector that decided the cache question here and wrote
 * xSpeed's settings by hand; that directory is deleted upstream, not versioned up.
 */
class Caching {

	/**
	 * The plugin we offer, and the Pro build that ships beside it.
	 *
	 * Named here rather than read from xSpeed because every question below has to be
	 * answerable on a site where xSpeed does not exist. `XSpeed\Host` is the supported
	 * API for everything else, but it only exists once xSpeed is active.
	 */
	const PLUGIN_FILE     = 'xspeed/xspeed.php';
	const PRO_PLUGIN_FILE = 'xspeed-pro/xspeed-pro.php';
	const PLUGIN_SLUG     = 'xspeed';
	const PLUGIN_NAME     = 'xSpeed Cache';
	const PLUGIN_ICON     = 'https://ps.w.org/xspeed/assets/icon-256x256.png';
	const PLUGIN_LINK     = 'https://wordpress.org/plugins/xspeed/';

	/**
	 * The one-shot trigger that tells xSpeed a host installed it.
	 *
	 * A trigger, not a record: activation spends it, moving the value where
	 * Host::installed_by() reads it afterwards.
	 */
	const INSTALLED_BY_OPTION = 'xspeed_installed_by';

	/** Our slug, as xSpeed records it. */
	const INSTALLER_SLUG = 'templately';

	/**
	 * Where xSpeed keeps its settings. Read only to answer "has this site had xSpeed
	 * before" — an option row outlives plugin deletion, and it is still the user's
	 * answer about a plugin we would otherwise re-offer.
	 */
	const SETTINGS_OPTION = 'xspeed_options';

	/**
	 * What xSpeed needs to run. Templately's own floor is lower, and an install that
	 * cannot activate is worse than an offer never made.
	 */
	const REQUIRES_WP  = '6.0';
	const REQUIRES_PHP = '7.4';

	/**
	 * When the suggestion was first put in front of this site, as a Unix timestamp.
	 *
	 * Site-scoped rather than per-user: whether this site wants a page cache is a fact
	 * about the site, and a second administrator should not be re-asked a question the
	 * first one already answered. Delete this option to offer it again — that is the
	 * supported reset, for support staff and for testing.
	 */
	const OFFER_SHOWN_OPTION = 'templately_caching_offer_shown';

	/**
	 * How long after the first showing the row keeps appearing.
	 *
	 * The dependency step re-fetches whenever the wizard is reopened or the user steps
	 * back and forward, so a flag set on first render would make the row vanish
	 * underneath someone still deciding about it.
	 */
	const OFFER_GRACE = 1800;

	/**
	 * How long before a declined offer may be made again.
	 *
	 * The ONLY suppression that expires, and it only ever applies to a decline. Every
	 * other reason to withhold the row is permanent by construction and outlives this
	 * window: the plugin is on disk (free or Pro), its settings are here, or the site
	 * cannot run it. Accepting installs the plugin, so an accepted offer is never made
	 * twice either.
	 */
	const OFFER_COOLDOWN = MONTH_IN_SECONDS;

	/**
	 * The cross-plugin record of what this site already decided about xSpeed.
	 *
	 * Shared, not ours: EmbedPress, Essential Addons and Templately all offer the same
	 * plugin, and each one keeping its own answer means a user who says no three times
	 * has said no once as far as any of them can tell. Namespaced `wpdeveloper_` after
	 * `wpdeveloper_plugins_data`, the convention the shared notice library already
	 * uses, and deliberately OUTSIDE the `xspeed_` namespace — xSpeed's uninstall.php
	 * deletes every option it owns, so a decision stored there would be erased by the
	 * very act it is meant to remember.
	 *
	 * Shape, all keys optional to a reader:
	 *
	 *     [
	 *       'offered_by' => 'templately',   // slug of whoever last put the offer up
	 *       'offered_at' => 1757462400,     // when it went up
	 *       'outcome'    => 'offered',      // see below
	 *       'outcome_at' => 1757462400,
	 *     ]
	 *
	 * `outcome` is one of:
	 *
	 * - `offered`  — the row went out; nobody has answered yet.
	 * - `accepted` — a host installed it. Written immediately before activation.
	 * - `declined` — the user said no in a way that was meant to stick (a dismissed
	 *                promo, a "never show again"). Templately never writes it: its own
	 *                decline is the local timer above, which expires. A host with a
	 *                permanent opt-out control should write it there.
	 * - `removed`  — accepted, then taken off the site. NOT stored: it is derived, so
	 *                a deletion performed outside any of our code is still seen. The
	 *                name exists so a reader can talk about the state.
	 *
	 * Terminal outcomes are terminal. Nothing here re-offers past one.
	 *
	 * @see docs/guides/installing-from-another-plugin.md in the xSpeed repo.
	 */
	const OFFER_RECORD_OPTION = 'wpdeveloper_xspeed_offer';

	/**
	 * On disk at all, active or not — free or Pro.
	 *
	 * Presence, not activation. A site that has it has decided about it, including a
	 * user who installed it and switched it off, and re-offering that is nagging.
	 * Offering Free to a site running Pro would be worse still: a downgrade.
	 */
	public static function is_installed(): bool {
		$plugins = Helper::get_plugins();

		return isset( $plugins[ self::PLUGIN_FILE ] ) || isset( $plugins[ self::PRO_PLUGIN_FILE ] );
	}

	/**
	 * Has xSpeed ever run here? Its settings row survives deactivation and deletion.
	 */
	public static function has_settings(): bool {
		return false !== get_option( self::SETTINGS_OPTION, false );
	}

	public static function is_supported(): bool {
		global $wp_version;

		return version_compare( (string) $wp_version, self::REQUIRES_WP, '>=' )
			&& version_compare( PHP_VERSION, self::REQUIRES_PHP, '>=' );
	}

	/**
	 * Whether the suggestion has already had its turn.
	 *
	 * A window rather than a permanent flag. Inside OFFER_GRACE the row keeps showing.
	 * Past OFFER_COOLDOWN the answer has aged out and may be asked again. Between the
	 * two, it is spent.
	 */
	public static function has_been_offered(): bool {
		$shown = (int) get_option( self::OFFER_SHOWN_OPTION, 0 );

		if ( $shown <= 0 ) {
			return false;
		}

		$age = time() - $shown;

		return $age > self::OFFER_GRACE && $age < self::OFFER_COOLDOWN;
	}

	/**
	 * Record that the row went out.
	 *
	 * Re-arms only once the previous showing has aged out. Rewriting on every re-render
	 * would mean the offer never expires, and the wizard re-fetches this step often.
	 */
	public static function mark_offered() {
		$shown = (int) get_option( self::OFFER_SHOWN_OPTION, 0 );

		if ( $shown <= 0 || ( time() - $shown ) >= self::OFFER_COOLDOWN ) {
			update_option( self::OFFER_SHOWN_OPTION, time(), false );
			self::record_offer();

			return;
		}

		// The row is up but the clock is already running, so the shared record has
		// nothing new to learn — except on a site that was mid-window when this
		// release landed, where it does not exist yet. Writing it on every fetch
		// would stamp `offered_at` with the current second forever, and a sibling
		// pacing itself off that field would never see the offer age out.
		if ( '' === self::outcome() ) {
			self::record_offer();
		}
	}

	/**
	 * The shared record, always an array so callers can read it without guarding.
	 */
	public static function offer_record(): array {
		$record = get_option( self::OFFER_RECORD_OPTION, array() );

		return is_array( $record ) ? $record : array();
	}

	/**
	 * Note in the shared record that the row went out.
	 *
	 * Never downgrades an answer. A site that already accepted or declined has told us
	 * something; putting it back to `offered` because the row rendered again would lose
	 * that, and the row should not have rendered in the first place.
	 */
	public static function record_offer() {
		$record = self::offer_record();

		if ( in_array( self::outcome(), array( 'accepted', 'declined' ), true ) ) {
			return;
		}

		$record['offered_by'] = self::INSTALLER_SLUG;
		$record['offered_at'] = time();
		$record['outcome']    = 'offered';
		$record['outcome_at'] = time();

		update_option( self::OFFER_RECORD_OPTION, $record, false );
	}

	/**
	 * Write a terminal answer into the shared record.
	 *
	 * @param string $outcome `accepted` or `declined`.
	 */
	public static function record_outcome( string $outcome ) {
		if ( ! in_array( $outcome, array( 'accepted', 'declined' ), true ) ) {
			return;
		}

		$record = self::offer_record();

		if ( empty( $record['offered_by'] ) ) {
			$record['offered_by'] = self::INSTALLER_SLUG;
			$record['offered_at'] = time();
		}

		$record['outcome']    = $outcome;
		$record['outcome_at'] = time();

		update_option( self::OFFER_RECORD_OPTION, $record, false );
	}

	/**
	 * The recorded outcome, or '' when nobody has written a usable one.
	 *
	 * Scalar-guarded because three plugins write this row and only one of them is
	 * this file. A nested array would otherwise be cast to the string 'Array' — a
	 * PHP notice, which the test rig turns into an exception and WP_DEBUG_DISPLAY
	 * prints into the REST response.
	 */
	public static function outcome(): string {
		$record = self::offer_record();

		if ( ! isset( $record['outcome'] ) || ! is_scalar( $record['outcome'] ) ) {
			return '';
		}

		return (string) $record['outcome'];
	}

	/**
	 * Has this site already answered the question, whoever asked it?
	 *
	 * The check that makes the offer survive a deletion. `accepted` plus an absent
	 * plugin is a user who installed it and then took it off — the clearest "no" a
	 * user can give, and the one every other guard here misses, because deleting a
	 * plugin runs its uninstaller: `xspeed_options` goes with it, so has_settings()
	 * forgets, and OFFER_COOLDOWN then re-offers a month later. Derived rather than
	 * stored so a deletion done from the Plugins screen — with none of our code
	 * running — still counts.
	 */
	public static function was_answered(): bool {
		$outcome = self::outcome();

		if ( 'declined' === $outcome ) {
			return true;
		}

		if ( 'accepted' === $outcome ) {
			return ! self::is_installed();
		}

		return false;
	}

	/**
	 * The dependency row, in the same shape as every other entry on that screen.
	 *
	 * The name, icon and link are the real plugin's: the user is agreeing to install a
	 * specific thing and should be able to see and check what it is. `installed` is
	 * always false — the offer is withheld outright when the plugin is present — and
	 * false is what keeps the checkbox enabled. `mustHave` is omitted: a suggestion,
	 * not a requirement.
	 */
	public static function dependency_entry(): array {
		return array(
			'name'                 => self::PLUGIN_NAME,
			'icon'                 => self::PLUGIN_ICON,
			'plugin_file'          => self::PLUGIN_FILE,
			'plugin_original_slug' => self::PLUGIN_SLUG,
			'is_pro'               => false,
			'installed'            => false,
			'link'                 => self::PLUGIN_LINK,
		);
	}

	/**
	 * Whether to offer the caching plugin alongside whatever the pack itself asked for.
	 *
	 * Deliberately not conditional on anything already owning the page cache. xSpeed
	 * installs beside another cache plugin and stands down from the cache itself — that
	 * is its decision, made at its own activation, and asking it here would only be
	 * asking on an earlier request than the one that matters.
	 *
	 * Cheapest checks first, and the order is load-bearing:
	 *
	 * - Can this user even accept? The dependency screen is readable at `delete_posts`,
	 *   so a contributor can open the wizard; installing needs `install_plugins`.
	 *   Without this they would spend the site's one offer on themselves.
	 * - Already answered, by us or by any sibling plugin. Permanent, and checked ahead
	 *   of our own timer because it outranks it: OFFER_GRACE keeps the row up for half
	 *   an hour, which was long enough to install xSpeed, delete it, and be offered it
	 *   again in the same sitting.
	 * - Already offered, within the window.
	 * - Already present — free or Pro — or already carrying xSpeed's settings.
	 * - The site can run it.
	 */
	public static function should_offer(): bool {
		if ( ! Helper::current_user_can( 'install_plugins' ) ) {
			return false;
		}

		if ( self::was_answered() ) {
			return false;
		}

		if ( self::has_been_offered() ) {
			return false;
		}

		return ! self::is_installed()
			&& ! self::has_settings()
			&& self::is_supported();
	}

	/**
	 * Claim the install, so xSpeed comes up as a host install rather than a hand one.
	 *
	 * Call immediately before activating, and never speculatively. It is a one-shot
	 * trigger that changes what activation does, not a record of intent — an install
	 * that dies between this and the activation arms the NEXT activation on the site,
	 * whoever starts it.
	 *
	 * Also settles the shared record at `accepted`. Written here rather than after a
	 * successful activation on purpose: an install that got this far has been agreed
	 * to, and an activation that then fails still leaves files on disk. Recording the
	 * answer is what stops the site being asked again once those files are removed.
	 */
	public static function claim_install() {
		update_option( self::INSTALLED_BY_OPTION, self::INSTALLER_SLUG, false );

		self::record_outcome( 'accepted' );
	}

	/**
	 * What the install came up as, for reporting. Null when xSpeed is not active or is
	 * older than the release that introduced the API.
	 *
	 * `conflict-safe` is a success, not a failure: it means another plugin was already
	 * caching and xSpeed stood down, which is the designed outcome.
	 *
	 * @return array|null
	 */
	public static function install_status() {
		if ( ! class_exists( '\XSpeed\Host' ) ) {
			return null;
		}

		return \XSpeed\Host::status();
	}
}
