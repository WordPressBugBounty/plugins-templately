<?php
/**
 * The live source: WPDeveloper's plugin-download service.
 *
 * Presents the site's stored Templately credential and asks for the pro plugin belonging
 * to a platform. Entitlement is decided entirely at the other end — nothing here reads a
 * plan, a tier, or a subscription value (spec 059 FR-003), and no answer is ever cached
 * (FR-019), so a plan upgraded between two imports takes effect on the second one.
 *
 * THE SHAPE IS NOW KNOWN (2026-09-08). The endpoint does not answer with the archive: it
 * answers 200 + JSON carrying a short-lived presigned S3 link, and a refusal is 403 +
 * `{"code":"unauthorized_access"}`. Acquisition is therefore TWO hops — see `pointer()`.
 * The first implementation streamed the reply straight to a temp file and validated it as
 * a ZIP, so an entitled user's 823-byte JSON pointer failed validation and was discarded:
 * the feature refused everyone, indistinguishably from a real refusal.
 *
 * FOUR RULES THIS CLASS MUST NOT BREAK — each of them is a defect someone hit before:
 *
 * 1. **Never route the response through Templately's response normalizer.**
 *    `Http::maybeErrors()` maps AUTH_EXPIRED / INVALID_API_KEY onto
 *    `Login::get_instance()->delete()`, which wipes the stored api_key and disconnects
 *    the site. This host is api.wpdeveloper.com, a DIFFERENT service with its own
 *    vocabulary: a 401 from it means "this token is not entitled to this download", not
 *    "your Templately session ended". Normalizing it would let a routine refusal log the
 *    user out of Templately in the middle of their import. Constitution IV's
 *    destructive-side-effects rule says exactly this — a code that destroys access must
 *    be reachable only by a positive match on a signal from the system that OWNS that
 *    access.
 *
 * 2. **Never throw, never echo.** The full-site path runs inside a live SSE stream. An
 *    exception escapes into the import as a failure; a stray byte corrupts the stream.
 *
 * 3. **Never log the credential, or any URL containing it.** It travels as a query
 *    parameter because the service's contract requires it, which makes the whole URL a
 *    secret. Log the slug and the outcome; never the request.
 *
 * 4. **Take the download link from `downloads[<platform>]`, never from `url`.** They can
 *    disagree, and `url` carries no indication of which product it is. The disagreement
 *    observed on 2026-09-08 — an Elementor request answering with `downloads` keyed
 *    `gutenberg` and a `url` pointing at Essential Blocks Pro — was ORIGINALLY BLAMED ON
 *    THE SERVICE, and that was wrong: the request spelled the parameter `platform`
 *    instead of `platforms`, so the service never saw a platform at all and answered with
 *    its default. Corrected 2026-09-21. The rule stands on its own merits regardless —
 *    reading the platform-keyed entry is what kept a mislabelled `url` from installing the
 *    wrong product into an Elementor import for the whole time the parameter was wrong —
 *    and the archive is checked against the catalog's own directory on arrival, so a
 *    mismatch is caught twice.
 *
 * @package Templately\Modules\ProPluginProvisioning
 */

namespace Templately\Modules\ProPluginProvisioning;

use Templately\Utils\Base;
use Templately\Utils\Helper;
use Templately\Utils\Installer;

class ProvisioningSource extends Base {

	const ENDPOINT = 'https://api.wpdeveloper.com/wp-json/wpdeveloper/v1/download-plugin';

	/**
	 * Acquire an archive for a provisioning request, or null.
	 *
	 * @param array $request plugin_file, slug, platform, credential.
	 * @return string|null Absolute path to a downloaded archive, or null when unavailable.
	 */
	public function fetch( array $request ) {
		$platform   = isset( $request['platform'] ) ? (string) $request['platform'] : '';
		$credential = isset( $request['credential'] ) ? (string) $request['credential'] : '';
		$slug       = isset( $request['slug'] ) ? (string) $request['slug'] : '';
		$expected   = isset( $request['plugin_file'] ) ? dirname( (string) $request['plugin_file'] ) : '';

		if ( '' === $platform || '' === $credential || '' === $slug || '' === $expected || '.' === $expected ) {
			self::note( 'incomplete request', $slug, $platform );

			return null;
		}

		// A plugin archive is a real multi-megabyte download, so the budget is far larger
		// than an ordinary cloud call's. Lifting PHP's own execution limit first is not
		// optional: without it PHP fatals at max_execution_time (30s on a great many
		// hosts) with no WP_Error to catch, WordPress emits its HTML "critical error"
		// page, and an SSE or JSON client renders that markup as content.
		Installer::raise_limits();

		$download = $this->pointer( $platform, $credential );

		if ( null === $download ) {
			// `pointer()` has already said why.
			return null;
		}

		$destination = $this->destination( $slug );

		if ( null === $destination ) {
			self::note( 'no writable temp file for the archive', $slug, $platform );

			return null;
		}

		$response = wp_remote_get( $download, [
			'timeout'  => Module::timeout(),
			'headers'  => [ 'Accept' => 'application/zip' ],
			'stream'   => true,
			'filename' => $destination,
		] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			self::note(
				is_wp_error( $response )
					? 'archive download failed: ' . $response->get_error_message()
					: 'archive download returned HTTP ' . (int) wp_remote_retrieve_response_code( $response ),
				$slug,
				$platform
			);
			Archive::discard( $destination );

			return null;
		}

		if ( ! Archive::is_installable_plugin( $destination, $expected ) ) {
			self::note(
				sprintf( 'downloaded file is not an installable "%s" plugin (%d bytes)', $expected, (int) @filesize( $destination ) ),
				$slug,
				$platform
			);

			// A JSON refusal, an HTML error page, a ZIP that is not a plugin, or a plugin
			// that is not the one we asked for. All of them are the same outcome to the
			// caller — see the class docblock.
			Archive::discard( $destination );

			return null;
		}

		self::note( 'archive acquired', $slug, $platform );

		return $destination;
	}

	/**
	 * Record an outcome.
	 *
	 * Rule 3 of this class's contract, made real: the slug, the platform and what
	 * happened — NEVER the request, because the credential travels in the URL and the
	 * whole URL is therefore a secret. Without this every refusal was a bare `return
	 * null`, so "not entitled", "service down", "wrong platform" and "disk full" reached
	 * the user as one indistinguishable "install it yourself".
	 *
	 * @param string $outcome  What happened. Must not contain a URL or the credential.
	 * @param string $slug
	 * @param string $platform
	 * @return void
	 */
	private static function note( string $outcome, string $slug, string $platform ) {
		Helper::log(
			sprintf( 'pro-provisioning[%s/%s]: %s', $platform, $slug, $outcome ),
			'pro_plugin_provisioning',
			'info'
		);
	}

	/**
	 * Ask the service where the archive is, and get back a URL or nothing.
	 *
	 * The endpoint does NOT answer with the archive. It answers with JSON carrying a
	 * short-lived presigned link:
	 *
	 *     { "url": "https://…/essential-blocks-pro.3.2.1.zip?X-Amz-…",
	 *       "downloads": { "gutenberg": "https://…" } }
	 *
	 * **Read `downloads[<platform>]`, never `url`.** Observed 2026-09-08: a request for
	 * `platform=elementor` came back 200 with `downloads` keyed `gutenberg` only, and `url`
	 * pointing at Essential Blocks Pro — the Gutenberg plugin. Taking `url` would have
	 * installed the wrong product into an Elementor import. The platform-keyed entry is the
	 * only field that answers the question we asked; its absence means "not for this
	 * platform", which is a refusal, not a reason to reach for the other field.
	 *
	 * @param string $platform   'elementor' | 'gutenberg'.
	 * @param string $credential The site's stored Templately credential.
	 * @return string|null Absolute https URL to an archive, or null.
	 */
	private function pointer( string $platform, string $credential ) {
		$response = wp_remote_get( $this->url( $platform, $credential ), [
			'timeout' => Module::timeout(),
			'headers' => [
				'Accept'               => 'application/json',
				'x-templately-url'     => home_url( '/' ),
				'x-templately-version' => defined( 'TEMPLATELY_VERSION' ) ? TEMPLATELY_VERSION : '1.0.0',
			],
		] );

		if ( is_wp_error( $response ) ) {
			self::note( 'entitlement check failed: ' . $response->get_error_message(), '(pointer)', $platform );

			return null;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			// 401/403 is the service saying this token is not entitled to this download —
			// an ordinary outcome, NOT a Templately session problem (see rule 1).
			self::note(
				sprintf(
					'entitlement check returned HTTP %d%s',
					$status,
					( 401 === $status || 403 === $status ) ? ' — not entitled to this download' : ''
				),
				'(pointer)',
				$platform
			);

			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['downloads'] ) || ! is_array( $body['downloads'] ) ) {
			self::note( 'entitlement check answered 200 with no `downloads` map', '(pointer)', $platform );

			return null;
		}

		$download = isset( $body['downloads'][ $platform ] ) ? $body['downloads'][ $platform ] : null;

		if ( ! is_string( $download ) || '' === $download ) {
			// Absence means "not for this platform" — the `url` field is deliberately NOT
			// consulted (rule 4). Name the keys that WERE offered; they are platform names,
			// not secrets, and a mismatch here is the one this rule exists to catch.
			self::note(
				sprintf( 'entitlement check offered no "%s" download (offered: %s)', $platform, implode( ', ', array_keys( $body['downloads'] ) ) ?: 'none' ),
				'(pointer)',
				$platform
			);

			return null;
		}

		// These bytes become executing PHP on the site. A plaintext hop is not an acceptable
		// way to acquire them, whatever the service hands back.
		if ( 'https' !== strtolower( (string) wp_parse_url( $download, PHP_URL_SCHEME ) ) ) {
			self::note( 'refused a non-https download link', '(pointer)', $platform );

			return null;
		}

		return $download;
	}

	/**
	 * The download URL for a platform.
	 *
	 * `dev=true` rides the plugin's existing dev-API switch, so the constant that points
	 * Templately at app.templately.dev also points provisioning at the development
	 * entitlement data (FR-005).
	 *
	 * The credential is taken from the REQUEST, not re-read from global state: the caller
	 * has already established that the site is connected and that the user may install, and
	 * a second read could disagree with the one those checks were made against. It is added
	 * LAST, and this method's return value must never be logged.
	 *
	 * @param string $platform   'elementor' | 'gutenberg'.
	 * @param string $credential The site's stored Templately credential.
	 * @return string
	 */
	private function url( string $platform, string $credential ): string {
		// `platforms`, PLURAL. The service ignores an unrecognised query key rather than
		// rejecting it, and then answers with a default — so sending `platform` returned
		// Essential Blocks Pro for every request, including `elementor`. It looked exactly
		// like the service disregarding the parameter, and was read that way for two weeks
		// (see the note on `pointer()`); it was this spelling all along. Verified against
		// the live endpoint 2026-09-21: `platforms=elementor` answers
		// `downloads: { elementor: essential-addons-elementor.7.0.4.zip }`.
		$args = [ 'platforms' => $platform ];

		if ( Helper::is_dev_api() ) {
			$args['dev'] = 'true';
		}

		$args['token'] = $credential;

		return add_query_arg( $args, self::ENDPOINT );
	}

	/**
	 * Where the archive is streamed to: a UNIQUE temp file per request.
	 *
	 * Streamed rather than buffered because these are multi-megabyte files and the FSI
	 * request is already holding a pack in memory. Unique rather than per-slug because
	 * the first draft used one deterministic path, and two concurrent requests (two
	 * admins, or an FSI retry overlapping the original) then truncated, validated and
	 * unlinked EACH OTHER's file — an entitled user got a refusal because another
	 * request had just discarded the archive under them. `wp_tempnam()` is what core's
	 * own downloader uses.
	 *
	 * @param string $slug
	 * @return string|null Null when no temp file can be created.
	 */
	private function destination( string $slug ) {
		if ( ! function_exists( 'wp_tempnam' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$path = wp_tempnam( 'templately-pro-' . sanitize_key( $slug ) . '.zip' );

		return is_string( $path ) && '' !== $path ? $path : null;
	}
}
