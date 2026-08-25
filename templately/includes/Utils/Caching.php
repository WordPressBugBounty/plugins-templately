<?php

namespace Templately\Utils;

use WPDeveloper\PageCacheSafety\Detector;

/**
 * The caching solution Templately offers on the import dependency screen.
 *
 * Everything vendor-specific about the recommended plugin — its file, slug, display details,
 * option names and the settings it should come up with — is confined to the constants at the
 * top of this class. Nothing else in the plugin names it. Swapping the recommendation for a
 * different caching plugin is an edit to this block and its INITIAL_SETTINGS map; no caller
 * changes.
 *
 * Two jobs: decide whether to offer a caching solution at all, and — only once the user has
 * left the row ticked and it has actually installed — leave it configured the way the offer
 * implied.
 */
class Caching {

	/**
	 * The recommended plugin. The display fields are what the user sees on the dependency
	 * row and are deliberately the plugin's real name and listing, so the row says what it
	 * installs.
	 */
	const PLUGIN_FILE = 'xspeed/xspeed.php';
	const PLUGIN_SLUG = 'xspeed';
	const PLUGIN_NAME = 'xSpeed Cache';
	const PLUGIN_ICON = 'https://ps.w.org/xspeed/assets/icon-256x256.png';
	const PLUGIN_LINK = 'https://wordpress.org/plugins/xspeed/';

	/**
	 * Where the recommended plugin keeps its settings.
	 */
	const SETTINGS_OPTION = 'xspeed_options';
	const MODULE_PREFIX   = 'xspeed_module_';

	/**
	 * The option its activation sets to force a first-run setup wizard redirect. Written
	 * unconditionally on activation and consumed on the next admin_init.
	 */
	const WIZARD_REDIRECT_OPTION = 'xspeed_redirect_to_onboarding';

	/**
	 * When the suggestion was first put in front of this site, as a Unix timestamp.
	 *
	 * The offer is made once. A user who ticked it has the plugin; a user who unticked it
	 * said no, and asking again on their next import is nagging. Delete this option to offer
	 * it again — that is the supported reset, for support staff and for testing.
	 *
	 * Site-scoped rather than per-user: whether this site wants a page cache is a fact about
	 * the site, and a second administrator should not be re-asked a question the first one
	 * already answered.
	 */
	const OFFER_SHOWN_OPTION = 'templately_caching_offer_shown';

	/**
	 * How long after the first showing the row keeps appearing.
	 *
	 * Without this, "once" would mean once per HTTP request rather than once per user. The
	 * dependency step re-fetches whenever the wizard is reopened or the user steps back and
	 * forward, so a flag set on first render would make the row vanish underneath someone
	 * who was still deciding about it. Inside the window the answer is unchanged; after it,
	 * the offer is spent.
	 */
	const OFFER_GRACE = 1800;

	/**
	 * Version floors, pinned rather than read from plugins_api().
	 *
	 * The recommended plugin asks for more than Templately advertises (readme.txt:
	 * WordPress 5.0, PHP 7.2), and Installer::check_compatibility() turns a mismatch into a
	 * hard failure of the import's plugin step — so a pre-ticked row on an older site is a
	 * broken import, not a declined suggestion. Fetching the real numbers per request would
	 * mean a network call on a screen the user is already waiting on. The cost of pinning
	 * them is that a floor change needs a matching edit here.
	 */
	const REQUIRES_WP  = '6.0';
	const REQUIRES_PHP = '7.4';

	/**
	 * The settings a Templately-installed caching plugin should come up with.
	 *
	 * The whole desired end state, not a list of things to switch off, because pre-writing
	 * SETTINGS_OPTION suppresses whatever first-run path the plugin otherwise uses to seed
	 * these. Browser caching defaults to `false` in its own schema and was only ever on
	 * because of that path — so leaving it out here silently turned it off. Anything wanted
	 * on has to be said out loud.
	 *
	 * Page caching is the only thing Templately switches on. The row the user ticked offered
	 * a page cache, so that is what they get — browser caching, minification, lazy loading,
	 * resource hints and the rest all stay off, whatever the plugin would have enabled for
	 * itself.
	 *
	 * Every module is written explicitly, including the ones that would be off anyway.
	 * Measured against 1.2.0 with no rows written at all: lazy, resource-hints, fonts and
	 * preloader come up ON — their schema defaults are true — so writing those is what turns
	 * them off. gzip, minify and browser-cache come up off, but only because pre-writing
	 * SETTINGS_OPTION happens to suppress the plugin's first-run seeding; a normal install
	 * brings all three up ON. Leaning on that side effect is what silently re-enabled browser
	 * caching once already, so the redundant rows stay.
	 *
	 * Deliberately absent, and left exactly as the plugin sets them: its GDPR consent
	 * requirement, its Cloudflare purge behaviour, and its object-cache flag. None is an
	 * optimisation, and for the first of them off would be the wrong answer. A blanket
	 * "everything except browser caching" rule would have caught all three, and would
	 * silently swallow whatever module the plugin ships next. The cost of naming them is
	 * that a future opt-in-by-default optimisation has to be added here by hand.
	 *
	 * Keys are module slugs, values the fields written to MODULE_PREFIX . <slug>. Verified
	 * against the recommended plugin at 1.2.0.
	 */
	const INITIAL_SETTINGS = array(
		'browser-cache'  => array(
			'enabled' => false,
		),
		'gzip'           => array(
			'gzip_enabled' => false,
		),
		'minify'         => array(
			'minify_html' => false,
			'minify_css'  => false,
		),
		'lazy'           => array(
			'lazy_images'            => false,
			'lazy_iframes'           => false,
			'lazy_videos'            => false,
			'add_missing_dimensions' => false,
		),
		'resource-hints' => array(
			'enabled'     => false,
			'lcp_preload' => false,
			'preconnect'  => false,
		),
		'fonts'          => array(
			'font_display_swap' => false,
		),
		'preloader'      => array(
			'warm_on_publish' => false,
		),
	);

	/**
	 * Present on disk at all, active or not.
	 */
	public static function is_installed(): bool {
		return isset( Helper::get_plugins()[ self::PLUGIN_FILE ] );
	}

	public static function is_active(): bool {
		return Helper::is_plugin_active( self::PLUGIN_FILE );
	}

	/**
	 * Whether the suggestion has already had its turn, grace window elapsed.
	 */
	public static function has_been_offered(): bool {
		$shown = (int) get_option( self::OFFER_SHOWN_OPTION, 0 );

		return $shown > 0 && ( time() - $shown ) > self::OFFER_GRACE;
	}

	/**
	 * Record that the row went out. First writing wins, so the grace window is measured from
	 * the first showing rather than being pushed forward by every re-render.
	 */
	public static function mark_offered() {
		if ( ! get_option( self::OFFER_SHOWN_OPTION ) ) {
			update_option( self::OFFER_SHOWN_OPTION, time(), false );
		}
	}

	/**
	 * The dependency row, in the same shape as every other entry on that screen.
	 *
	 * `installed` is always false and not worth deriving: the offer is withheld outright
	 * when the plugin is on disk at all, so anything reaching here is a fresh install. false
	 * is also what keeps the checkbox enabled, which is the point of offering it. `mustHave`
	 * is omitted — this is a suggestion, not a requirement.
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
	 * Whether to offer a caching solution alongside whatever the pack itself asked for.
	 *
	 * Three conditions, cheapest first. The first two are Templately's own, and the shared
	 * detector cannot answer either:
	 *
	 * - Already offered once. See OFFER_SHOWN_OPTION.
	 * - Already on disk. We only ever offer to put it there. A site that has it — running or
	 *   not — has made its own decision about that plugin, and a row for something already
	 *   sitting in wp-content is noise.
	 * - Version floors, per the constants above.
	 *
	 * Only then the detector, which is the sole authority on whether anything owns the page
	 * cache. The order is load-bearing, not incidental: the detector deliberately does not
	 * catalogue the plugin we recommend — it answers "is anything *else* here", from a site
	 * where that plugin may not be installed at all. An active copy with page caching
	 * switched off leaves no drop-in, matches no catalogue entry, and classifies as
	 * `unclaimed`. Consult the detector first and you offer users a plugin they already run.
	 *
	 * Read-only throughout: deciding installs, activates, and configures nothing.
	 */
	public static function should_offer(): bool {
		if ( self::has_been_offered() ) {
			return false;
		}

		if ( self::is_installed() ) {
			return false;
		}

		global $wp_version;

		if ( version_compare( $wp_version, self::REQUIRES_WP, '<' )
			|| version_compare( PHP_VERSION, self::REQUIRES_PHP, '<' ) ) {
			return false;
		}

		self::load_detector();

		return Detector::is_field_clear();
	}

	/**
	 * Load the vendored page-cache detector.
	 *
	 * Copy-vendored from xSpeed Free: WPDevelopers/xspeed, branch
	 * feat/portable-page-cache-detector (PR #297), page-cache-safety/, at commit
	 * 2565b33fc90be8c3dae90aa1f0f1dd1f53339f14.
	 *
	 * That repo is the source of truth. Fixes go THERE and get re-copied here — never
	 * patched in place, or the parity test that keeps every copy honest stops meaning
	 * anything. Required at the point of use rather than at boot so it costs nothing on any
	 * other request; its own class_exists() wrapper makes it safe for another plugin on the
	 * same site to carry its own copy.
	 */
	public static function load_detector() {
		require_once TEMPLATELY_PATH . 'includes/Vendor/page-cache-safety/class-page-cache-safety.php';
	}

	/**
	 * Write the settings the plugin should come up with, BEFORE it is activated.
	 *
	 * This is the primary mechanism, and the ordering is the whole trick. Its activation
	 * reads what is already stored rather than stamping over it:
	 *
	 * - Each module seeds its option row only when one does not already exist, so a row we
	 *   wrote first survives activation untouched.
	 * - Its cache drop-in restore runs during activation and, finding the page-cache flag
	 *   already true, installs `advanced-cache.php` and writes the `WP_CACHE` constant
	 *   itself — through the plugin's own supported path, on its own schedule.
	 *
	 * Verified at 1.2.0: with only this pre-write and no post-install step at all, the site
	 * comes up with page caching live, browser caching on, and every other front-end
	 * optimisation off.
	 *
	 * Doing it this way sidesteps the trap the post-install approach fell into. The plugin's
	 * settings manager resolves modules through a registry that is empty for a plugin
	 * activated part-way through the request — it returns without writing and without
	 * complaining. These are plain update_option() calls that need none of its code to be
	 * loaded, because at this point none of it is.
	 *
	 * Called immediately before activation so a failed download never leaves rows behind; if
	 * activation itself fails, forget_settings() takes them back out.
	 */
	public static function prepare_settings() {
		update_option( self::SETTINGS_OPTION, array( 'cache_enabled' => true ) );

		foreach ( self::INITIAL_SETTINGS as $slug => $values ) {
			update_option( self::MODULE_PREFIX . $slug, $values );
		}
	}

	/**
	 * Undo prepare_settings() when the install did not survive to activation.
	 *
	 * Leaving these rows on a site that has no caching plugin is litter, and worse, a
	 * page-cache flag sitting there would tell a LATER hand-install to bring up caching the
	 * user never asked for.
	 */
	public static function forget_settings() {
		delete_option( self::SETTINGS_OPTION );

		foreach ( array_keys( self::INITIAL_SETTINGS ) as $slug ) {
			delete_option( self::MODULE_PREFIX . $slug );
		}
	}

	/**
	 * Finish the job after a successful, Templately-driven activation.
	 *
	 * Only for an install Templately performed. Deliberately not hooked to `activated_plugin`:
	 * that fires when the user activates the plugin themselves from the Plugins screen, and
	 * reconfiguring their site off the back of an action they took elsewhere would be exactly
	 * the overreach this feature is trying not to commit.
	 *
	 * Two jobs left, because pre-writing cannot cover either:
	 *
	 * - The setup-wizard redirect, which activation arms unconditionally.
	 * - A safety net for page caching. The pre-write should already have caused the plugin to
	 *   install its drop-in; if it did not, enable it the long way rather than leave the user
	 *   with a cache plugin that caches nothing.
	 *
	 * Nothing here may break the import. A caching plugin that installed but did not
	 * configure is a worse outcome than one that did, and a far better one than a failed
	 * import.
	 */
	public static function configure_after_install() {
		if ( ! self::is_active() ) {
			return;
		}

		self::suppress_setup_wizard();

		if ( ! self::page_cache_is_live() ) {
			self::enable_page_cache();
		}
	}

	/**
	 * Drop the one-time wizard redirect the activation hook just armed.
	 *
	 * The one thing pre-writing cannot prevent: the flag is set unconditionally on
	 * activation, so it has to be cleared afterwards.
	 *
	 * The user came here to import a template. Bouncing them into another plugin's setup
	 * wizard on their next wp-admin page load is not what they asked for. The wizard stays in
	 * that plugin's own menu and can be run whenever they like — this cancels only the forced
	 * redirect, and deliberately does not mark onboarding "complete", which would be a claim
	 * about something the user never did.
	 */
	private static function suppress_setup_wizard() {
		delete_option( self::WIZARD_REDIRECT_OPTION );
	}

	/**
	 * Whether page caching actually took effect, rather than merely being requested.
	 *
	 * The stored flag on its own proves nothing — it is the drop-in and the constant that
	 * make WordPress serve from cache, and either can be missing if the filesystem or
	 * wp-config.php refused the write.
	 */
	private static function page_cache_is_live(): bool {
		$options = get_option( self::SETTINGS_OPTION, array() );

		return ! empty( $options['cache_enabled'] )
			&& file_exists( WP_CONTENT_DIR . '/advanced-cache.php' )
			&& defined( 'WP_CACHE' ) && WP_CACHE;
	}

	/**
	 * Fallback: switch page caching on the long way.
	 *
	 * Only reached when the pre-write did not take — the drop-in restore declined, or the
	 * drop-in / wp-config.php write failed. The happy path never comes here.
	 *
	 * The page-cache flag cannot simply be written: the plugin's own settings manager rejects
	 * that key by name, because the flag is what drives the drop-in install and the
	 * wp-config.php edit. A bare write leaves a site claiming a cache it does not have —
	 * which the very detector that decided to offer it would then read as `unknown-occupied`.
	 * The toggle does the drop-in and the constant; the settings write after it persists the
	 * flag, mirroring the plugin's own REST handler.
	 *
	 * That REST route is its documented entry point and would be the tidier call, but it is
	 * unreachable here: the plugin was activated part-way through THIS request, so
	 * `rest_api_init` has already fired and its routes are not registered. These static calls
	 * are the same code that route runs.
	 */
	private static function enable_page_cache() {
		if ( ! class_exists( '\XSpeed\Cache' ) || ! class_exists( '\XSpeed\Settings' ) ) {
			return;
		}

		try {
			$state = \XSpeed\Cache::toggle( true );
			\XSpeed\Settings::update( array( 'cache_enabled' => ! empty( $state['enabled'] ) ) );
		} catch ( \Throwable $e ) {
			// A failed cache switch-on must never take the import down with it.
			Helper::log( 'Page cache could not be enabled: ' . $e->getMessage() );
			return;
		}

		// The site's cache state just changed under the detector's feet. Anything asking
		// again in this request must not get the pre-install answer back from its memo.
		if ( class_exists( '\WPDeveloper\PageCacheSafety\Detector' ) ) {
			Detector::invalidate();
		}
	}
}
