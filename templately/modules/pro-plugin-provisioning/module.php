<?php
/**
 * Pro-plugin-provisioning module.
 *
 * Lets Templately install two paid plugins on a user's behalf during import —
 * Essential Addons Pro (Elementor) and Essential Blocks Pro (Gutenberg) — when the
 * user's Templately subscription entitles them to it.
 *
 * The module owns NO REST route and NO import logic. It answers one filter,
 * {@see self::ARCHIVE_FILTER}, with the path to a downloaded plugin archive, and
 * `Utils\Installer` does the rest. That is what lets a single change serve both the
 * full-site (pack) import and the single-item import, and what makes "behaves exactly
 * as before when this module is disabled" (spec 059 FR-025) free rather than a branch:
 * a disabled module never adds the filter, so `Installer` falls back to the `pro_plugin`
 * refusal it has always returned.
 *
 * Entitlement is decided REMOTELY. Nothing here reads a plan, a tier, or a subscription
 * value (FR-003) — the download service is the only authority, and its answer is never
 * cached (FR-019), so an upgraded plan takes effect on the next attempt.
 *
 * @package Templately
 */

namespace Templately\Modules\ProPluginProvisioning;

use Templately\Core\Module_Base;
use Templately\Utils\Options;

class Module extends Module_Base {

	/**
	 * Ask any registered source for an installable archive.
	 *
	 * `apply_filters( self::ARCHIVE_FILTER, null, array $request ): string|null`
	 *
	 * See specs/059-pro-plugin-provisioning/contracts/provisioning-source.md for the
	 * full contract. In short: return an absolute path to a readable file or the value
	 * you were given; never a URL, never a WP_Error, never an exception, never output.
	 */
	const ARCHIVE_FILTER = 'templately_pro_plugin_archive';

	/**
	 * Seconds a source may spend acquiring an archive, filterable per site.
	 *
	 * Deliberately far longer than an ordinary cloud call: this is a real plugin
	 * download of several megabytes, not an API response.
	 */
	const TIMEOUT_FILTER  = 'templately_pro_plugin_provisioning_timeout';

	/**
	 * 90, not the 120 first chosen. The full-site import client runs an SSE stall
	 * watchdog of exactly 120s (`useFullSiteImport.ts`), cleared only by a real frame —
	 * and no frame is emitted while `wp_remote_get()` blocks on the download. A budget
	 * equal to the watchdog let a slow host trip it: the client reconnected, took over
	 * the run lock, and re-ran the dependency step, racing a second download of the same
	 * archive against the first. 30s of headroom keeps the download inside one frame gap.
	 * Sites with genuinely slow links raise it through the filter — knowingly.
	 */
	const DEFAULT_TIMEOUT = 90;

	/**
	 * Reserved development credentials the dev-only mock source recognises.
	 *
	 * They live HERE, on the shipped module, rather than in the mock, so the mock, the
	 * tests and the E2E helper all read one declaration and cannot drift. They are inert
	 * in production: nothing in a production build reads them, because the only reader —
	 * `modules/developer/pro-provisioning-mock/` — is excluded from the zip by
	 * `.distignore`.
	 *
	 * Neither value authenticates against the Templately cloud. They are written into the
	 * site's stored `api_key` for the duration of a test or a manual run, then restored.
	 */
	const MOCK_CREDENTIAL_GRANTED = 'templately-mock-pro-granted';
	const MOCK_CREDENTIAL_DENIED  = 'templately-mock-pro-denied';

	public function get_name(): string {
		return 'pro-plugin-provisioning';
	}

	protected function init_hooks(): void {
		add_filter( self::ARCHIVE_FILTER, [ $this, 'provide_archive' ], 10, 2 );
	}

	/**
	 * The live WPDeveloper source.
	 *
	 * Registered at priority 10 so the dev mock (priority 5) can answer first for its two
	 * reserved credentials and fall through for every other one.
	 *
	 * @param string|null $archive_path Path supplied by an earlier source, or null.
	 * @param array       $request      plugin_file, slug, platform, credential.
	 * @return string|null
	 */
	public function provide_archive( $archive_path, $request ) {
		if ( is_string( $archive_path ) && '' !== $archive_path ) {
			// An earlier source already answered. Do not spend a request re-answering.
			return $archive_path;
		}

		return ProvisioningSource::get_instance()->fetch( is_array( $request ) ? $request : [] );
	}

	/**
	 * The site's stored Templately credential, or an empty string when not connected.
	 *
	 * Read through one accessor so the "no credential ⇒ no attempt" rule (FR-015) has a
	 * single definition, and so the value has exactly one place it can be logged from —
	 * which is nowhere.
	 */
	public static function credential(): string {
		$api_key = Options::get_instance()->get( 'api_key' );

		return is_string( $api_key ) ? trim( $api_key ) : '';
	}

	/**
	 * Whether anything can answer the archive filter right now.
	 *
	 * The honest "is this feature on" test. A disabled module never ran `init_hooks()`,
	 * so its listener is absent; the dev mock declares a dependency on this module, so it
	 * is skipped alongside. `class_exists()` cannot tell you this — the autoloader
	 * namespace is registered for inactive modules too.
	 */
	public static function is_live(): bool {
		return false !== has_filter( self::ARCHIVE_FILTER );
	}

	/**
	 * Seconds an acquisition attempt may take.
	 */
	public static function timeout(): int {
		$timeout = (int) apply_filters( self::TIMEOUT_FILTER, self::DEFAULT_TIMEOUT );

		return $timeout > 0 ? $timeout : self::DEFAULT_TIMEOUT;
	}
}
