<?php
/**
 * GET templately/v1/subscription/pro-plugin-download?platform=elementor|gutenberg
 *
 * Hands the browser a short-lived download link for the Pro plugin a bundle
 * subscription includes, Essential Addons Pro (Elementor) or Essential Blocks
 * Pro (Gutenberg). The user then downloads the zip themselves and uploads it on
 * this site's own Upload Plugin screen. Templately NEVER installs it: WordPress.org
 * plugin guideline 8 forbids a plugin from installing software the user did not
 * fetch themselves, which is why the automatic install (spec 059) was removed.
 *
 * The link comes from WPDeveloper's plugin-download service, which decides
 * entitlement against the site's stored Templately credential. The browser never
 * sees that credential: the service wants it as a query parameter, so the whole
 * request URL is a secret and the ask is made here, server-side, and never logged.
 *
 * Two rules carried over from the retired provisioning module, both learnt the
 * hard way (2026-09):
 *
 * 1. **Never route the service's answer through Templately's response normalizer.**
 *    `Http::maybeErrors()` maps a 401/403 onto `Login::delete()` and disconnects the
 *    site. This is `api.wpdeveloper.com`, a different service with its own
 *    vocabulary, where 401 means "this credential is not entitled to this download"
 *    an ordinary outcome, not a session problem. Plain `wp_remote_get()` only.
 * 2. **Read `downloads[<platform>]`, never `url`.** The two can disagree, and `url`
 *    was observed naming the other platform's product. An absent platform key is a
 *    refusal. And the query key is `platforms`, PLURAL, the service ignores an
 *    unknown key and answers with a default product.
 *
 * @package Templately
 */

namespace Templately\Modules\Subscription\REST;

use Templately\API\API;
use Templately\Utils\Helper;

class ProPluginDownload extends API {

	const ENDPOINT = 'https://api.wpdeveloper.com/wp-json/wpdeveloper/v1/download-plugin';

	const PLATFORMS = [ 'elementor', 'gutenberg' ];

	/** Seconds the entitlement check may take. It answers JSON, not an archive. */
	const TIMEOUT = 20;

	/** The service said no: the plan does not cover this download. */
	const REFUSED = 'refused';

	/** The service answered 429: too many downloads in its hourly window. */
	const RATE_LIMITED = 'rate_limited';

	/** The request never got an answer: DNS, TLS, timeout. */
	const UNREACHABLE = 'unreachable';

	/** The service answered, but with a 5xx or a body we cannot read. */
	const SERVICE_ERROR = 'service_error';

	public function register_routes() {
		$this->get( 'subscription/pro-plugin-download', [ $this, 'download' ] );
	}

	/**
	 * Resolve the download link for one platform's Pro plugin.
	 *
	 * @return array|\WP_Error `{ url, platform }` or a WP_Error the envelope renders.
	 */
	public function download() {
		// A plan is held BY the account and the download is licensed to it, so the
		// gate is the same as the subscription purchase: administrators only. Not
		// `permission_error()`: that clears the stored credentials, and a refusal
		// here must never log the site out of Templately.
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error(
				'insufficient_permission',
				__( 'Only administrators can download the Pro plugins.', 'templately' ),
				'subscription/pro-plugin-download',
				rest_authorization_required_code()
			);
		}

		$platform = $this->get_param( 'platform', '', 'sanitize_key' );

		if ( ! in_array( $platform, self::PLATFORMS, true ) ) {
			return $this->error(
				'invalid_platform',
				__( 'Choose a platform: elementor or gutenberg.', 'templately' ),
				'subscription/pro-plugin-download',
				422
			);
		}

		$credential = is_string( $this->api_key ) ? trim( $this->api_key ) : '';

		if ( '' === $credential ) {
			return $this->error(
				'not_connected',
				__( 'Connect this site to your Templately account first.', 'templately' ),
				'subscription/pro-plugin-download',
				403
			);
		}

		$url = $this->pointer( $platform, $credential );

		// Temporary failures say nothing about the plan, so each names its real cause.
		// The client matches on the code; it keeps its own copy of each sentence.
		if ( self::RATE_LIMITED === $url ) {
			return $this->error(
				'pro_plugin_rate_limited',
				__( 'Download limit reached. The download service allows only a few downloads per hour. Try again later.', 'templately' ),
				'subscription/pro-plugin-download',
				429
			);
		}

		if ( self::UNREACHABLE === $url ) {
			return $this->error(
				'pro_plugin_unreachable',
				__( 'This site could not reach the download service. Check the site\'s internet connection and try again.', 'templately' ),
				'subscription/pro-plugin-download',
				502
			);
		}

		if ( self::SERVICE_ERROR === $url ) {
			return $this->error(
				'pro_plugin_service_error',
				__( 'The download service returned an error. Try again in a few minutes.', 'templately' ),
				'subscription/pro-plugin-download',
				502
			);
		}

		if ( self::REFUSED === $url ) {
			// The service decided this credential is not entitled to this download.
			return $this->error(
				'pro_plugin_not_available',
				__( 'This download is not available for your plan right now. If you think it should be, contact support.', 'templately' ),
				'subscription/pro-plugin-download',
				404
			);
		}

		return $this->envelope( [
			'url'      => $url,
			'platform' => $platform,
		] );
	}

	/**
	 * Ask the service where the archive is: the presigned https link, or why not.
	 *
	 * A refusal (401/403/404, no entry for this platform, a non-https link) is the
	 * service's answer about the plan. Anything else says nothing about the plan and
	 * is reported by its cause: RATE_LIMITED (429), UNREACHABLE (no answer at all) or
	 * SERVICE_ERROR (any other status, or a body we cannot read).
	 *
	 * @param string $platform   'elementor' | 'gutenberg'.
	 * @param string $credential The site's stored Templately credential. Never logged.
	 * @return string The https link, or one of the failure constants above.
	 */
	private function pointer( string $platform, string $credential ) {
		$response = wp_remote_get( $this->url( $platform, $credential ), [
			'timeout' => self::TIMEOUT,
			'headers' => [
				'Accept'               => 'application/json',
				'x-templately-url'     => home_url( '/' ),
				'x-templately-version' => defined( 'TEMPLATELY_VERSION' ) ? TEMPLATELY_VERSION : '1.0.0',
			],
		] );

		if ( is_wp_error( $response ) ) {
			self::note( 'entitlement check failed: ' . $response->get_error_message(), $platform );

			return self::UNREACHABLE;
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status ) {
			self::note( 'entitlement check answered HTTP ' . $status, $platform );

			if ( in_array( $status, [ 401, 403, 404 ], true ) ) {
				return self::REFUSED;
			}

			return 429 === $status ? self::RATE_LIMITED : self::SERVICE_ERROR;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['downloads'] ) || ! is_array( $body['downloads'] ) ) {
			self::note( 'entitlement check answered 200 with no `downloads` map', $platform );

			return self::SERVICE_ERROR;
		}

		$link = isset( $body['downloads'][ $platform ] ) ? $body['downloads'][ $platform ] : null;

		// Absence means "not for this platform". `url` is deliberately NOT consulted
		// (rule 2 above). The link must be an absolute https URL: the browser is about
		// to navigate a tab to it.
		if ( ! is_string( $link ) || 0 !== strpos( $link, 'https://' ) ) {
			self::note(
				sprintf( 'entitlement check offered no "%s" download (offered: %s)', $platform, implode( ', ', array_keys( $body['downloads'] ) ) ?: 'none' ),
				$platform
			);

			return self::REFUSED;
		}

		return $link;
	}

	/**
	 * The service URL. `platforms` is plural on purpose; `dev=true` accompanies a
	 * dev-API session so a staging subscriber is not checked against production
	 * data; the credential goes LAST and this value must never be logged.
	 */
	private function url( string $platform, string $credential ): string {
		$args = [ 'platforms' => $platform ];

		if ( Helper::is_dev_api() ) {
			$args['dev'] = 'true';
		}

		$args['token'] = $credential;

		return add_query_arg( $args, self::ENDPOINT );
	}

	/** Log the outcome, the platform and what happened, never the request. */
	private static function note( string $outcome, string $platform ) {
		Helper::log( sprintf( 'pro-plugin-download[%s]: %s', $platform, $outcome ), 'pro_plugin_download', 'info' );
	}
}
